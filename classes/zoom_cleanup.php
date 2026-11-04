<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Independent Zoom cleanup: never calls directory processing or alters AD processstate. */
final class zoom_cleanup {
 public static function label(string $state): string {
  return ['pending' => 'Not checked', 'processing' => 'Interrupted — check again', 'failed' => 'Needs attention — check again',
   'done' => 'Deleted and verified', 'absent' => 'Not found in Zoom'][$state] ?? 'Not checked';
 }
 public static function eligible(object $row): object {
  global $DB;
  $review = $DB->get_record('local_districtupdate_review', ['id' => $row->reviewid], '*', MUST_EXIST);
  if (!$review->submittedat || !$review->districtid || $row->status !== 'left' || ($row->zoomstate ?? 'pending') === 'done'
    || !service::district_active((int)$review->districtid)) { service::fail('Only submitted departures from active districts can be deleted from Zoom.'); }
  if (!empty($row->zoomid) && ($row->zoomaccount ?? '') !== (string)get_config('local_districtupdate', 'zoomaccountid')) {
   service::fail('The Zoom account configuration differs from the original deletion attempt. Check that account manually.');
  }
  $u = $DB->get_record('user', ['id' => $row->userid, 'deleted' => 0], '*', MUST_EXIST);
  if ($u->username !== $row->username || strcasecmp($u->email, $row->email) !== 0 || !validate_email($row->email)
    || is_siteadmin($u->id) || service::excluded($u->username)
    || $DB->record_exists('local_districtupdate_skip', ['cycleid' => 0, 'userid' => $u->id])) {
   service::fail('Moodle identity or exclusion status changed. Review this account manually.');
  }
  if ($DB->count_records_select('user', 'deleted = 0 AND LOWER(email) = :email', ['email' => strtolower($row->email)]) !== 1) {
   service::fail('More than one Moodle account uses this email. Resolve the identity manually.');
  }
  $cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $review->cycleid], '*', MUST_EXIST);
  if ($DB->record_exists_sql("SELECT r.id FROM {local_districtupdate_roster} r
   JOIN {local_districtupdate_review} v ON v.id = r.reviewid JOIN {local_districtupdate_cycle} c ON c.id = v.cycleid
   WHERE r.userid = :userid AND v.districtid > 0 AND c.reviewyear >= :year AND r.status <> :leftstatus",
   ['userid' => $u->id, 'year' => $cycle->reviewyear, 'leftstatus' => 'left'])) {
   service::fail('A current or later roster shows this person still employed or transferred. Resolve that review first.');
  }
  return $review;
 }
 public static function preview(array $ids, ?zoom_api $api = null): array {
  global $DB, $USER;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids || count($ids) > 25) { service::fail('Select between 1 and 25 departures per batch.'); }
  $api = $api ?? new zoom_api();
  $batch = ['actorid' => (int)$USER->id, 'created' => time(), 'config' => zoom_api::fingerprint(), 'rows' => []];
  $seen = [];
  foreach ($ids as $id) {
   $row = $DB->get_record('local_districtupdate_roster', ['id' => $id], '*', MUST_EXIST);
   $item = ['rowid' => $id, 'userid' => (int)$row->userid, 'fullname' => $row->fullname, 'email' => $row->email,
    'revision' => 0, 'zoom' => null, 'error' => ''];
   try {
    $review = self::eligible($row); $item['revision'] = (int)$review->revision;
    if (isset($seen[strtolower($row->email)])) { service::fail('Duplicate email in this batch; select only one roster entry for this person.'); }
    $seen[strtolower($row->email)] = true;
    $user = $api->user($row->zoomid ?: $row->email);
    if ($user) { $api->validate($user, $row->email, $row->zoomid ?: ''); }
    $item['zoom'] = $user ? ['id' => $user['id'], 'email' => $user['email'], 'status' => $user['status'], 'type' => $user['type']] : null;
   } catch (\Throwable $e) { $item['error'] = $e->getMessage(); }
   $batch['rows'][$id] = $item;
  }
  return $batch;
 }
 public static function process(array $batch, ?zoom_api $api = null): array {
  global $DB, $USER;
  require_capability('local/districtupdate:manage', \context_system::instance());
  if (!get_config('local_districtupdate', 'zoomlive') || (int)$batch['actorid'] !== (int)$USER->id
    || time() - $batch['created'] > 900 || time() < $batch['created'] || !hash_equals(zoom_api::fingerprint(), $batch['config'])) {
   service::fail('Zoom preview expired, belongs to another administrator, or settings changed. Generate a fresh preview.');
  }
  if (!$batch['rows'] || count($batch['rows']) > 25) { service::fail('Invalid Zoom batch.'); }
  $api = $api ?? new zoom_api(); $results = [];
  // Same order as annual management: freeze reviews while validating newer employment evidence and deleting.
  $annual = service::lock('annual'); $locks = [];
  try {
   foreach ($DB->get_records('local_districtupdate_review', null, 'id') as $r) { $locks[] = service::lock('review:'.$r->id); }
   foreach ($batch['rows'] as $id => $item) {
    if ($item['error']) { $results[$id] = 'Skipped: '.$item['error']; continue; }
    $attempted = false;
    try {
     $row = $DB->get_record('local_districtupdate_roster', ['id' => $id], '*', MUST_EXIST);
     if (($row->zoomstate ?? '') === 'done') { $results[$id] = 'Already deleted'; continue; }
     $review = self::eligible($row);
     if ((int)$review->revision !== $item['revision'] || (int)$row->userid !== $item['userid'] || $row->email !== $item['email']) {
      service::fail('Review or identity changed. Generate a fresh Zoom preview.');
     }
     $expected = $item['zoom']['id'] ?? ($row->zoomid ?: '');
     $current = $api->user($expected ?: $row->email);
     if ($item['zoom'] === null && $current !== null) { service::fail('A Zoom account appeared after preview. Generate a fresh preview.'); }
     if ($current) { $api->validate($current, $row->email, $expected); }
     if ($current) {
      $DB->update_record('local_districtupdate_roster', (object)['id' => $id, 'zoomstate' => 'processing', 'zoomid' => $expected,
       'zoomaccount' => (string)get_config('local_districtupdate', 'zoomaccountid'),
       'zoomactor' => $USER->id, 'zoomtime' => time()]);
      $attempted = true;
      service::audit($id, $row->userid, 'zoom_delete_started', ['email' => $row->email, 'zoomid' => $expected]);
      $api->delete($expected);
     }
     $state = $current || !empty($row->zoomid) ? 'done' : 'absent';
     $DB->update_record('local_districtupdate_roster', (object)['id' => $id, 'zoomstate' => $state, 'zoomactor' => $USER->id, 'zoomtime' => time()]);
     service::audit($id, $row->userid, $state === 'done' ? 'zoom_deleted' : 'zoom_not_found', ['email' => $row->email]);
     $results[$id] = self::label($state);
    } catch (\Throwable $e) {
     if ($attempted) { $DB->set_field('local_districtupdate_roster', 'zoomstate', 'failed', ['id' => $id]); }
     service::audit((int)$id, (int)$item['userid'], 'zoom_delete_failed', ['message' => $e->getMessage()]);
     $results[$id] = 'Not completed: '.$e->getMessage();
    }
   }
  } finally { foreach (array_reverse($locks) as $lock) { $lock->release(); } $annual->release(); }
  return $results;
 }
}
