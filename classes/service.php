<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
final class service {
 public static function fail(string $message): never {
  throw new \moodle_exception('error', 'local_districtupdate', '', $message);
 }
 public static function manager(): bool {
  return has_capability('local/districtupdate:manage', \context_system::instance());
 }
 /** Resolve emails/usernames to stable account IDs before replacing any assignments. */
 public static function reviewer_ids(string $entries): array {
  global $DB;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $ids = [];
  foreach (rules::lines($entries) as $entry) {
   $matches = $DB->get_records_select('user',
    'deleted = 0 AND suspended = 0 AND (LOWER(username) = :reviewerusername OR LOWER(email) = :revieweremail)',
    ['reviewerusername' => $entry, 'revieweremail' => $entry], 'id', '*', 0, 2);
   if (!$matches) { self::fail('No active Moodle account matches reviewer "'.$entry.'". Use an existing account email address or username.'); }
   if (count($matches) !== 1) { self::fail('More than one active Moodle account matches reviewer "'.$entry.'". Use a unique Moodle username instead.'); }
   $user = reset($matches);
   if (isguestuser($user)) { self::fail('Guests cannot be reviewers.'); }
   $ids[(int)$user->id] = (int)$user->id;
  }
  if (!$ids) { self::fail('Assign at least one active Moodle reviewer email address or username.'); }
  return array_values($ids);
 }
 public static function lock(string $key): \core\lock\lock {
  $lock = \core\lock\lock_config::get_lock_factory('local_districtupdate')->get_lock($key, 10);
  if (!$lock) { self::fail('Another person is updating this review. Please try again.'); }
  return $lock;
 }
 public static function access(int $reviewid): \stdClass {
  global $DB, $USER;
  $review = $DB->get_record('local_districtupdate_review', ['id' => $reviewid], '*', MUST_EXIST);
  if (!self::manager() && (!$review->districtid || !$DB->record_exists('local_districtupdate_access',
    ['districtid' => $review->districtid, 'userid' => $USER->id]))) {
   self::fail('You are not assigned to this district review.');
  }
  if (!self::manager() && self::reviewer_access_expired($review)) {
   self::fail('Reviewer access expired 30 days after submission. Contact an administrator if you need this review reopened.');
  }
  return $review;
 }
 /** Expiration is per review; district assignments remain available for the next annual cycle. */
 public static function reviewer_access_expires_at(object $review): int {
  return $review->submittedat ? (int)$review->submittedat + 30 * DAYSECS : 0;
 }
 public static function reviewer_access_expired(object $review, ?int $now = null): bool {
  $expires = self::reviewer_access_expires_at($review);
  return $expires !== 0 && ($now ?? time()) >= $expires;
 }
 public static function audit(int $rosterid, int $userid, string $event, array $detail = []): void {
  global $DB, $USER;
  $DB->insert_record('local_districtupdate_audit', (object)['rosterid' => $rosterid, 'userid' => $userid,
   'actorid' => $USER->id ?? 0, 'event' => $event, 'detail' => json_encode($detail, JSON_UNESCAPED_UNICODE),
   'timecreated' => time()]);
 }
 public static function excluded(string $username): bool {
  return in_array(strtolower($username), rules::lines((string)get_config('local_districtupdate', 'excluded')), true);
 }
 /** Names are Moodle authentication identifiers, not directory server addresses. */
 public static function auth_methods(): array {
  $configured = get_config('local_districtupdate', 'authmethods');
  if ($configured === false || $configured === null) { return ['ldap']; }
  if (is_array($configured)) { $configured = implode(',', $configured); }
  return array_values(array_filter(rules::lines((string)$configured), fn($name) => (bool)preg_match('/^[a-z][a-z0-9_]*$/D', $name)));
 }
 /** Resolve one cohort; never silently fall back to all users on a missing/ambiguous cohort. */
 public static function roster_cohort_id(): int {
  global $DB;
  $matches = $DB->get_records('cohort', ['idnumber' => 'ECBOCES_Members'])
   + $DB->get_records('cohort', ['name' => 'ECBOCES_Members']);
  if (count($matches) !== 1) {
   self::fail('Expected one Moodle cohort named or identified as ECBOCES_Members. Check cohorts before creating or refreshing rosters; no roster changes were made.');
  }
  return (int)reset($matches)->id;
 }
 /** Shared source for initial snapshots and refresh, restricted to the ECBOCES_Members Moodle cohort. */
 public static function eligible_users(): \Generator {
  global $DB;
  $methods = self::auth_methods();
  if (!$methods) { return; }
  $cohortid = self::roster_cohort_id();
  $profiledistricts = self::profile_districts();
  [$authsql, $params] = $DB->get_in_or_equal($methods, SQL_PARAMS_NAMED, 'drauth');
  $params['drcohort'] = $cohortid;
  $users = $DB->get_recordset_select('user', 'auth '.$authsql.' AND deleted = 0 AND suspended = 0 AND id > 1'
   .' AND id IN (SELECT userid FROM {cohort_members} WHERE cohortid = :drcohort)',
   $params, 'id', 'id, username, firstname, lastname, email, institution');
  try {
   foreach ($users as $user) {
    if (!is_siteadmin($user->id) && !self::excluded($user->username)) {
     $user->profiledistrict = (string)($profiledistricts[$user->id] ?? '');
     yield $user;
    }
   }
  } finally { $users->close(); }
 }
 /** Read one custom field in bulk; prefer its shortname, with a unique display-name fallback. */
 public static function profile_districts(): array {
  global $DB;
  $configured = get_config('local_districtupdate', 'districtprofilefield');
  $name = \core_text::strtolower(trim($configured === false ? 'district' : (string)$configured));
  if ($name === '') { return []; }
  $shortnames = []; $labels = [];
  foreach ($DB->get_records('user_info_field') as $field) {
   if (\core_text::strtolower(trim($field->shortname)) === $name) { $shortnames[] = $field->id; }
   if (\core_text::strtolower(trim($field->name)) === $name) { $labels[] = $field->id; }
  }
  $matches = $shortnames ?: $labels;
  if (count($matches) > 1) { self::fail('More than one custom profile field matches the district field setting. Set its unique shortname in plugin settings.'); }
  if (!$matches) { return []; }
  return $DB->get_records_menu('user_info_data', ['fieldid' => $matches[0]], '', 'userid, data');
 }
 public static function create_cycle(int $year, int $duedate): int {
  global $DB;
  $lock = self::lock('annual');
  try {
   if ($year < 2020 || $year > 2100 || $duedate <= time()) { self::fail('Choose a valid year and a future due date.'); }
   if ($DB->record_exists('local_districtupdate_cycle', ['reviewyear' => $year])) { self::fail('A review already exists for this year.'); }
   $districts = $DB->get_records('local_districtupdate_dist', null, 'name');
   if (!$districts) { self::fail('Add districts and reviewers first.'); }
   self::roster_cohort_id();
   $tx = $DB->start_delegated_transaction();
   $cycleid = $DB->insert_record('local_districtupdate_cycle', (object)['reviewyear' => $year, 'duedate' => $duedate, 'timecreated' => time()]);
   $reviews = [];
   foreach (array_merge([0], array_keys($districts)) as $districtid) {
    $reviews[$districtid] = $DB->insert_record('local_districtupdate_review', (object)['cycleid' => $cycleid,
     'districtid' => $districtid, 'revision' => 1, 'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0]);
   }
   foreach (self::eligible_users() as $user) {
     $districtid = rules::district($user->email, $user->institution, $districts, $user->profiledistrict);
     $DB->insert_record('local_districtupdate_roster', (object)['reviewid' => $reviews[$districtid], 'userid' => $user->id,
      'username' => $user->username, 'fullname' => $user->firstname.' '.$user->lastname, 'email' => $user->email,
      'institution' => $user->institution, 'status' => 'still', 'destination' => 0, 'targetcompany' => '',
      'note' => '', 'modifiedby' => 0, 'modifiedat' => 0, 'processstate' => 'pending']);
   }
   self::audit(0, 0, 'cycle_created', ['cycleid' => $cycleid, 'year' => $year]);
   $tx->allow_commit();
   return $cycleid;
  } finally { $lock->release(); }
 }
 /** Refresh draft rosters from the currently synced Moodle directory; never writes to AD or sends email. */
 public static function refresh_cycle(int $cycleid): array {
  global $DB;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $annuallock = self::lock('annual'); $locks = [];
  try {
   $DB->get_record('local_districtupdate_cycle', ['id' => $cycleid], '*', MUST_EXIST);
   $reviews = $DB->get_records('local_districtupdate_review', ['cycleid' => $cycleid], 'id');
   foreach ($reviews as $review) { $locks[] = self::lock('review:'.$review->id); }
   // Reload after locks: a reviewer or administrator may have submitted while we waited.
   $DB->get_record('local_districtupdate_cycle', ['id' => $cycleid], '*', MUST_EXIST);
   $reviews = $DB->get_records('local_districtupdate_review', ['cycleid' => $cycleid], 'id');
   $districts = $DB->get_records('local_districtupdate_dist', null, 'name');
   $bydistrict = []; $rows = []; $byuser = []; $frozen = []; $changed = [];
   $stats = ['added' => 0, 'updated' => 0, 'moved' => 0, 'removed' => 0, 'preserved' => 0, 'skipped' => 0, 'locked' => 0];
   foreach ($reviews as $review) {
    $bydistrict[(int)$review->districtid] = $review;
    $frozen[$review->id] = (bool)$review->submittedat;
    foreach ($DB->get_records('local_districtupdate_roster', ['reviewid' => $review->id], 'id') as $row) {
     if (isset($byuser[$row->userid])) { self::fail('A user appears twice in this annual roster. Resolve the duplicate before refreshing.'); }
     $rows[$row->id] = $row; $byuser[$row->userid] = $row;
     if ($row->processstate !== 'pending') { $frozen[$review->id] = true; }
    }
   }
   $stats['locked'] = count(array_filter($frozen));
   $active = [];
   foreach (self::eligible_users() as $user) { $active[$user->id] = $user; }
   $tx = $DB->start_delegated_transaction();
   // Districts added after the annual snapshot can now receive an open review, without invitations.
   foreach (array_merge([0], array_keys($districts)) as $districtid) {
    if (!isset($bydistrict[$districtid])) {
     $review = (object)['cycleid' => $cycleid, 'districtid' => $districtid, 'revision' => 1,
      'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0];
     $review->id = $DB->insert_record('local_districtupdate_review', $review);
     $reviews[$review->id] = $review; $bydistrict[$districtid] = $review; $frozen[$review->id] = false;
    }
   }
   foreach ($rows as $row) {
    if ($frozen[$row->reviewid]) { continue; }
    // Keep decisions, notes, attribution and administrator district overrides intact.
    if ($row->status !== 'still' || trim($row->note) !== '' || $row->modifiedby || !empty($row->manualassignment)) {
     $stats['preserved']++; continue;
    }
    if (!isset($active[$row->userid])) {
     self::audit(0, $row->userid, 'roster_account_removed', ['cycleid' => $cycleid, 'reviewid' => $row->reviewid,
      'snapshot' => $row, 'reason' => 'No longer an eligible active user in Moodle']);
     $DB->delete_records('local_districtupdate_roster', ['id' => $row->id]);
     $changed[$row->reviewid] = true; $stats['removed']++; continue;
    }
    $user = $active[$row->userid];
    $districtid = rules::district($user->email, $user->institution, $districts, $user->profiledistrict);
    $target = $bydistrict[$districtid];
    if ($frozen[$target->id]) { $stats['skipped']++; continue; }
    $update = (object)['id' => $row->id, 'reviewid' => $target->id, 'username' => $user->username,
     'fullname' => $user->firstname.' '.$user->lastname, 'email' => $user->email, 'institution' => $user->institution];
    $different = false; $profilechanged = false;
    foreach (['username', 'fullname', 'email', 'institution'] as $field) {
     if ($row->$field !== $update->$field) { $different = true; $profilechanged = true; }
    }
    if ((int)$row->reviewid !== (int)$target->id) { $different = true; $stats['moved']++; }
    if ($different) {
     $DB->update_record('local_districtupdate_roster', $update);
     self::audit($row->id, $row->userid, 'roster_account_refreshed', ['before' => $row, 'after' => $update]);
     $changed[$row->reviewid] = true; $changed[$target->id] = true;
     if ($profilechanged) { $stats['updated']++; }
    }
   }
   foreach ($active as $user) {
    // One roster entry per user per year, including submitted and already processed entries.
    if (isset($byuser[$user->id])) { continue; }
    $districtid = rules::district($user->email, $user->institution, $districts, $user->profiledistrict);
    $target = $bydistrict[$districtid];
    if ($frozen[$target->id]) { $stats['skipped']++; continue; }
    $row = (object)['reviewid' => $target->id, 'userid' => $user->id, 'username' => $user->username,
     'fullname' => $user->firstname.' '.$user->lastname, 'email' => $user->email, 'institution' => $user->institution,
     'status' => 'still', 'destination' => 0, 'targetcompany' => '', 'note' => '', 'modifiedby' => 0,
     'modifiedat' => 0, 'processstate' => 'pending'];
    $row->id = $DB->insert_record('local_districtupdate_roster', $row);
    self::audit($row->id, $user->id, 'roster_account_added', ['cycleid' => $cycleid, 'reviewid' => $target->id]);
    $changed[$target->id] = true; $stats['added']++;
   }
   foreach (array_keys($changed) as $id) {
    $DB->set_field('local_districtupdate_review', 'revision', $reviews[$id]->revision + 1, ['id' => $id]);
   }
   self::audit(0, 0, 'rosters_refreshed', ['cycleid' => $cycleid, 'summary' => $stats]);
   $tx->allow_commit();
   return $stats;
  } finally {
   foreach (array_reverse($locks) as $lock) { $lock->release(); }
   $annuallock->release();
  }
 }
 public static function save(int $reviewid, int $revision, array $responses, bool $submit): void {
  global $DB, $USER;
  $lock = self::lock('review:'.$reviewid);
  try {
   $review = self::access($reviewid);
   if ($review->submittedat) { self::fail('This review is already submitted. Ask an administrator to reopen it.'); }
   if ((int)$review->revision !== $revision) { self::fail('Another reviewer saved changes. Reload the page before editing again.'); }
   if (!$review->districtid) { self::fail('Assign unmatched accounts to a district before reviewing.'); }
   $roster = $DB->get_records('local_districtupdate_roster', ['reviewid' => $reviewid], 'fullname');
   if (count($responses) !== count($roster) || array_diff(array_keys($roster), array_keys($responses))) {
    self::fail('The roster was incomplete. Reload the page. For large rosters increase PHP max_input_vars.');
   }
   $districts = $DB->get_records('local_districtupdate_dist');
   $changes = [];
   foreach ($roster as $row) {
    $input = $responses[$row->id];
    $status = (string)($input['status'] ?? '');
    $dest = $status === 'moved' ? (int)($input['destination'] ?? 0) : 0;
    if (!in_array($status, ['still', 'left', 'moved'], true)
       || ($status === 'moved' && (!isset($districts[$dest]) || $dest === (int)$review->districtid))) {
     self::fail('Choose a valid employment status and a different destination district for each transfer.');
    }
    $company = $dest ? $districts[$dest]->name : '';
    $note = trim((string)($input['note'] ?? ''));
    if (\core_text::strlen($note) > 2000) { self::fail('Notes must be 2,000 characters or fewer.'); }
    if ($status !== $row->status || $dest !== (int)$row->destination || $note !== $row->note || $company !== $row->targetcompany) {
     $changes[] = [$row, $status, $dest, $company, $note];
    }
   }
   $tx = $DB->start_delegated_transaction();
   foreach ($changes as [$row, $status, $dest, $company, $note]) {
    self::audit($row->id, $row->userid, 'response_changed', ['before' => [$row->status, $row->destination, $row->note],
     'after' => [$status, $dest, $note]]);
    $DB->update_record('local_districtupdate_roster', (object)['id' => $row->id, 'status' => $status,
     'destination' => $dest, 'targetcompany' => $company, 'note' => $note,
     'modifiedby' => $USER->id, 'modifiedat' => time()]);
   }
   $review->revision++;
   if ($submit) {
    $review->submittedat = time(); $review->submittedby = $USER->id;
    self::audit(0, $USER->id, 'review_submitted', ['reviewid' => $reviewid]);
   }
   $DB->update_record('local_districtupdate_review', $review);
   $tx->allow_commit();
  } finally { $lock->release(); }
  if ($submit) { self::notify_admins($reviewid); }
 }
 public static function notify_admins(int $reviewid): void {
  global $DB;
  $review = $DB->get_record('local_districtupdate_review', ['id' => $reviewid], '*', MUST_EXIST);
  $district = $DB->get_record('local_districtupdate_dist', ['id' => $review->districtid], '*', MUST_EXIST);
  $cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $review->cycleid], '*', MUST_EXIST);
  $url = (new \moodle_url('/local/districtupdate/approve.php', ['reviewid' => $reviewid]))->out(false);
  $message = email_templates::render('admin', $district, $cycle, $url);
  foreach (get_admins() as $admin) {
   $sent = email_to_user($admin, \core_user::get_noreply_user(), $message['subject'], $message['body']);
   self::audit(0, $admin->id, $sent ? 'admin_email_sent' : 'admin_email_failed', ['reviewid' => $reviewid]);
  }
 }
 public static function invite(int $reviewid): void {
  global $DB;
  $review = $DB->get_record('local_districtupdate_review', ['id' => $reviewid], '*', MUST_EXIST);
  if (!$review->districtid || $review->submittedat) { return; }
  $district = $DB->get_record('local_districtupdate_dist', ['id' => $review->districtid], '*', MUST_EXIST);
  $cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $review->cycleid], '*', MUST_EXIST);
  $url = (new \moodle_url('/local/districtupdate/review.php', ['id' => $reviewid]))->out(false);
  $message = email_templates::render('invitation', $district, $cycle, $url);
  $success = false;
  foreach ($DB->get_records('local_districtupdate_access', ['districtid' => $review->districtid]) as $assignment) {
   $user = $DB->get_record('user', ['id' => $assignment->userid, 'deleted' => 0, 'suspended' => 0]);
   if (!$user) { continue; }
   $sent = email_to_user($user, \core_user::get_noreply_user(), $message['subject'], $message['body']);
   self::audit(0, $user->id, $sent ? 'invitation_sent' : 'invitation_failed', ['reviewid' => $reviewid]);
   $success = $success || $sent;
  }
  if ($success) { $DB->set_field('local_districtupdate_review', 'lastsent', time(), ['id' => $reviewid]); }
 }
 public static function reopen(int $reviewid): void {
  global $DB;
  $lock = self::lock('review:'.$reviewid);
  try {
   $review = self::access($reviewid);
   if ($DB->record_exists_select('local_districtupdate_roster', "reviewid = :id AND processstate <> 'pending'", ['id' => $reviewid])) {
    self::fail('Directory processing was attempted. This submitted review cannot be reopened.');
   }
   $review->submittedat = 0; $review->submittedby = 0; $review->revision++;
   $DB->update_record('local_districtupdate_review', $review);
   self::audit(0, 0, 'review_reopened', ['reviewid' => $reviewid]);
  } finally { $lock->release(); }
 }
 public static function assign(int $rowid, int $districtid): void {
  global $DB;
  $row = $DB->get_record('local_districtupdate_roster', ['id' => $rowid], '*', MUST_EXIST);
  $source = $DB->get_record('local_districtupdate_review', ['id' => $row->reviewid], '*', MUST_EXIST);
  if ($source->districtid) { self::fail('Only unmatched accounts can be assigned here.'); }
  $target = $DB->get_record('local_districtupdate_review', ['cycleid' => $source->cycleid, 'districtid' => $districtid], '*', MUST_EXIST);
  if (!$districtid) { self::fail('Select a district.'); }
  $lock = self::lock('review:'.$target->id);
  try {
   $target = $DB->get_record('local_districtupdate_review', ['id' => $target->id], '*', MUST_EXIST);
   $row = $DB->get_record('local_districtupdate_roster', ['id' => $rowid], '*', MUST_EXIST);
   if ($target->submittedat || (int)$row->reviewid !== (int)$source->id) { self::fail('Assignment changed or target review is submitted.'); }
   $tx = $DB->start_delegated_transaction();
   $DB->set_field('local_districtupdate_roster', 'reviewid', $target->id, ['id' => $rowid]);
   $DB->set_field('local_districtupdate_roster', 'manualassignment', 1, ['id' => $rowid]);
   $DB->set_field('local_districtupdate_review', 'revision', $target->revision + 1, ['id' => $target->id]);
   self::audit($rowid, $row->userid, 'district_assigned', ['districtid' => $districtid]);
   $tx->allow_commit();
  } finally { $lock->release(); }
 }
}
