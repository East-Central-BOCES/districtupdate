<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Atomic roster-only administration; never changes Moodle accounts or AD. */
final class roster_admin {
 public static function editable(object $review): bool {
  global $DB;
  return !$review->submittedat && !$DB->record_exists_select('local_districtupdate_roster',
   "reviewid = :id AND processstate <> 'pending'", ['id' => $review->id]);
 }
 private static function untouched(object $row): bool {
  return $row->status === 'still' && $row->processstate === 'pending' && !$row->modifiedby && trim($row->note) === '' && empty($row->manualassignment);
 }
 public static function apply(int $sourceid, int $revision, string $action, array $assignments, array $selected): int {
  global $DB, $USER;
  require_capability('local/districtupdate:manage', \context_system::instance());
  if (!in_array($action, ['assign', 'remove', 'ignore'], true)) { service::fail('Unknown roster action.'); }
  $annual = service::lock('annual'); $locks = [];
  try {
   $source = $DB->get_record('local_districtupdate_review', ['id' => $sourceid], '*', MUST_EXIST);
   if ($source->districtid) { service::fail('Only unmatched accounts can be managed here.'); }
   $reviews = $DB->get_records('local_districtupdate_review', ['cycleid' => $source->cycleid], 'id');
   foreach ($reviews as $r) { $locks[] = service::lock('review:'.$r->id); }
   $source = $DB->get_record('local_districtupdate_review', ['id' => $sourceid], '*', MUST_EXIST);
   if ((int)$source->revision !== $revision || !self::editable($source)) { service::fail('The unmatched list changed. Reload it before applying this batch.'); }
   $reviews = $DB->get_records('local_districtupdate_review', ['cycleid' => $source->cycleid], 'id');
   $targets = []; foreach ($reviews as $r) { $targets[(int)$r->districtid] = $DB->get_record('local_districtupdate_review', ['id' => $r->id]); }
   $ids = $action === 'assign' ? array_keys(array_filter($assignments, fn($v) => (int)$v > 0)) : $selected;
   $ids = array_values(array_unique(array_map('intval', $ids)));
   if (!$ids || count($ids) > 100) { service::fail('Choose between 1 and 100 accounts on this page.'); }
   $rows = [];
   foreach ($ids as $id) {
    $row = $DB->get_record('local_districtupdate_roster', ['id' => $id, 'reviewid' => $sourceid], '*', MUST_EXIST);
    if (!self::untouched($row)) { service::fail('A selected account has a recorded response or processing attempt. No accounts were changed.'); }
    if ($action === 'assign') {
     $districtid = (int)$assignments[$id];
     $district = $DB->get_record('local_districtupdate_dist', ['id' => $districtid], '*', MUST_EXIST);
     if (!empty($district->archived) || !isset($targets[$districtid]) || !self::editable($targets[$districtid])) {
      service::fail('A destination district is removed, submitted or already processed. No accounts were changed.');
     }
    }
    $rows[$id] = $row;
   }
   $tx = $DB->start_delegated_transaction(); $changed = [$sourceid => true];
   foreach ($rows as $id => $row) {
    if ($action === 'assign') {
     $districtid = (int)$assignments[$id]; $target = $targets[$districtid];
     $DB->set_field('local_districtupdate_roster', 'reviewid', $target->id, ['id' => $id]);
     $DB->set_field('local_districtupdate_roster', 'manualassignment', 1, ['id' => $id]);
     $changed[$target->id] = true;
     service::audit($id, $row->userid, 'district_assigned', ['districtid' => $districtid]);
    } else {
     $scope = $action === 'ignore' ? 0 : (int)$source->cycleid;
     if (!$DB->record_exists('local_districtupdate_skip', ['cycleid' => $scope, 'userid' => $row->userid])) {
      $DB->insert_record('local_districtupdate_skip', (object)['cycleid' => $scope, 'userid' => $row->userid,
       'actorid' => $USER->id, 'timecreated' => time()]);
     }
     service::audit(0, $row->userid, $action === 'ignore' ? 'roster_user_ignored' : 'roster_user_removed',
      ['cycleid' => $source->cycleid, 'snapshot' => $row]);
     $DB->delete_records('local_districtupdate_roster', ['id' => $id]);
    }
   }
   foreach (array_keys($changed) as $id) { $DB->set_field('local_districtupdate_review', 'revision', $reviews[$id]->revision + 1, ['id' => $id]); }
   $tx->allow_commit(); return count($rows);
  } finally { foreach (array_reverse($locks) as $lock) { $lock->release(); } $annual->release(); }
 }
 public static function restore(array $ids): int {
  global $DB;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids || count($ids) > 100) { service::fail('Select between 1 and 100 exclusions to restore.'); }
  $lock = service::lock('annual');
  try {
   $rows = []; foreach ($ids as $id) { $rows[] = $DB->get_record('local_districtupdate_skip', ['id' => $id], '*', MUST_EXIST); }
   $tx = $DB->start_delegated_transaction();
   foreach ($rows as $row) {
    $DB->delete_records('local_districtupdate_skip', ['id' => $row->id]);
    service::audit(0, $row->userid, 'roster_user_restored', ['cycleid' => $row->cycleid]);
   }
   $tx->allow_commit(); return count($rows);
  } finally { $lock->release(); }
 }
 public static function can_reopen(object $review): bool {
  global $DB;
  return !$DB->record_exists_select('local_districtupdate_roster', "reviewid = :id AND processstate <> 'pending'", ['id' => $review->id]);
 }
 /** Delete plugin records only, with all review locks held to exclude concurrent processing and transfers. */
 public static function delete_district(int $id, string $confirmation): array {
  global $DB;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $annual = service::lock('annual'); $locks = [];
  try {
   $district = $DB->get_record('local_districtupdate_dist', ['id' => $id], '*', MUST_EXIST);
   if ($confirmation !== $district->name) { service::fail('Type the district name exactly to confirm permanent deletion.'); }
   $reviews = $DB->get_records('local_districtupdate_review', null, 'id');
   foreach ($reviews as $r) { $locks[] = service::lock('review:'.$r->id); }
   $own = []; foreach ($reviews as $r) { if ((int)$r->districtid === $id) { $own[$r->id] = true; } }
   foreach ($DB->get_records('local_districtupdate_roster', ['destination' => $id]) as $row) {
    if (!isset($own[$row->reviewid])) { service::fail('Another district review references this district as a transfer destination. Resolve those transfers before deleting it.'); }
   }
   $tx = $DB->start_delegated_transaction(); $counts = ['reviews' => count($own), 'staff' => 0];
   foreach (array_keys($own) as $reviewid) {
    foreach ($DB->get_records('local_districtupdate_roster', ['reviewid' => $reviewid]) as $row) {
     $DB->delete_records('local_districtupdate_audit', ['rosterid' => $row->id]); $counts['staff']++;
    }
    $DB->delete_records('local_districtupdate_roster', ['reviewid' => $reviewid]);
    $DB->delete_records('local_districtupdate_review', ['id' => $reviewid]);
   }
   $DB->delete_records('local_districtupdate_access', ['districtid' => $id]);
   $DB->delete_records('local_districtupdate_dist', ['id' => $id]);
   service::audit(0, 0, 'district_deleted', ['name' => $district->name, 'summary' => $counts]);
   $tx->allow_commit(); return $counts;
  } finally { foreach (array_reverse($locks) as $lock) { $lock->release(); } $annual->release(); }
 }
 public static function archive_district(int $id, bool $archive): array {
  global $DB;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $annual = service::lock('annual'); $locks = [];
  try {
   $district = $DB->get_record('local_districtupdate_dist', ['id' => $id], '*', MUST_EXIST);
   $reviews = $DB->get_records('local_districtupdate_review', ['districtid' => $id], 'id');
   foreach ($reviews as $r) { $locks[] = service::lock('review:'.$r->id); }
   $tx = $DB->start_delegated_transaction(); $stats = ['removed' => 0, 'preserved' => 0];
   $DB->set_field('local_districtupdate_dist', 'archived', (int)$archive, ['id' => $id]);
   foreach ($reviews as $r) {
    $r = $DB->get_record('local_districtupdate_review', ['id' => $r->id]);
    $editable = self::editable($r);
    foreach ($DB->get_records('local_districtupdate_roster', ['reviewid' => $r->id]) as $row) {
     if ($archive && $editable && self::untouched($row)) {
      service::audit(0, $row->userid, 'roster_account_removed', ['snapshot' => $row, 'reason' => 'District removed from rostering']);
      $DB->delete_records('local_districtupdate_roster', ['id' => $row->id]); $stats['removed']++;
     } elseif ($archive) { $stats['preserved']++; }
    }
    $DB->set_field('local_districtupdate_review', 'revision', $r->revision + 1, ['id' => $r->id]);
   }
   service::audit(0, 0, $archive ? 'district_archived' : 'district_restored', ['districtid' => $id, 'summary' => $stats]);
   $tx->allow_commit(); return $stats;
  } finally { foreach (array_reverse($locks) as $lock) { $lock->release(); } $annual->release(); }
 }
}
