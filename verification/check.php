<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace core\lock {
 class lock { public function release(): void {} }
 class lock_config {
  public static function get_lock_factory($type): object { return new class { public function get_lock($key, $timeout): lock { return new lock(); } }; }
 }
}
namespace {
 if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
 define('MOODLE_INTERNAL', true); define('MUST_EXIST', 2); define('DAYSECS', 86400); define('CONTEXT_SYSTEM', 10); define('SQL_PARAMS_NAMED', 2);
 class core_text { public static function strtolower($s) { return mb_strtolower($s); } public static function strlen($s) { return mb_strlen($s); } }
 class moodle_exception extends RuntimeException { public function __construct($error, $component = '', $link = '', $a = null) { parent::__construct((string)$a); } }
 class context_system { public static function instance() { return (object)['id' => 1]; } }
 class MockRecords extends ArrayIterator { public function close() {} }
 class MockDB {
  public array $tables = [];
  public function get_record($table, $where, $fields = '*', $strict = 0) {
   foreach ($this->tables[$table] ?? [] as $row) {
    $match = true; foreach ($where as $k => $v) { if (($row->$k ?? null) != $v) { $match = false; } }
    if ($match) { return clone $row; }
   }
   if ($strict) { throw new RuntimeException('Record not found'); } return false;
  }
  public function record_exists($table, $where) { return (bool)$this->get_record($table, $where); }
  public function get_records($table, $where = null, ...$unused) {
   $out = []; foreach ($this->tables[$table] ?? [] as $id => $row) {
    $match = true; foreach ($where ?? [] as $k => $v) { if (($row->$k ?? null) != $v) { $match = false; } }
    if ($match) { $out[$id] = clone $row; }
   } return $out;
  }
  public function get_records_menu($table, $where, $sort, $fields) {
   [$key, $value] = array_map('trim', explode(',', $fields)); $out = [];
   foreach ($this->get_records($table, $where) as $row) { $out[$row->$key] = $row->$value; }
   return $out;
  }
  public function get_records_select($table, $sql, $params, ...$unused) {
   return array_filter($this->tables[$table] ?? [], fn($u) => !$u->deleted && !$u->suspended
    && (strtolower($u->username) === $params['reviewerusername'] || strtolower($u->email) === $params['revieweremail']));
  }
  public function insert_record($table, $row) {
   $id = $this->tables[$table] ? max(array_keys($this->tables[$table])) + 1 : 1;
   $row = clone $row; $row->id = $id; $this->tables[$table][$id] = $row; return $id;
  }
  public function update_record($table, $row) {
   foreach (get_object_vars($row) as $k => $v) { $this->tables[$table][$row->id]->$k = $v; }
  }
  public function set_field($table, $field, $value, $where) {
   foreach ($this->get_records($table, $where) as $id => $row) { $this->tables[$table][$id]->$field = $value; }
  }
  public function delete_records($table, $where) {
   foreach ($this->get_records($table, $where) as $id => $row) { unset($this->tables[$table][$id]); }
  }
  public function count_records_select($table, $sql, $params) {
   return count(array_filter($this->tables[$table] ?? [], fn($r) => $r->timecreated < $params['cutoff']));
  }
  public function delete_records_select($table, $sql, $params) {
   foreach ($this->tables[$table] ?? [] as $id => $row) { if ($row->timecreated < $params['cutoff']) { unset($this->tables[$table][$id]); } }
  }
  public function start_delegated_transaction() { return new class { public function allow_commit() {} }; }
  public function record_exists_select($table, $sql, $params) {
   foreach ($this->get_records($table, ['reviewid' => $params['id']]) as $r) { if ($r->processstate !== 'pending') { return true; } } return false;
  }
  public function get_in_or_equal($items, $type, $prefix) {
   $params = []; foreach (array_values($items) as $i => $value) { $params[$prefix.$i] = $value; }
   return ['IN ('.implode(',', array_map(fn($key) => ':'.$key, array_keys($params))).')', $params];
  }
  public function get_recordset_select($table, $sql, $params, ...$rest) {
   $auths = array_filter($params, fn($key) => str_starts_with($key, 'drauth'), ARRAY_FILTER_USE_KEY);
   return new MockRecords(array_filter($this->tables[$table] ?? [], fn($u) => in_array($u->auth, array_values($auths), true)
    && !$u->deleted && !$u->suspended && $u->id > 1
    && $this->record_exists('cohort_members', ['cohortid' => $params['drcohort'], 'userid' => $u->id])
    && !$this->record_exists('local_districtupdate_skip', ['cycleid' => 0, 'userid' => $u->id])
    && !$this->record_exists('local_districtupdate_skip', ['cycleid' => $params['drskipcycle'], 'userid' => $u->id])));
  }
 }
 function get_config($component, $key = null) { global $CONFIG; return $key === null ? (object)$CONFIG : ($CONFIG[$key] ?? false); }
 function has_capability(...$args) { global $MANAGER; return $MANAGER; }
 function require_capability(...$args) { if (!has_capability()) { throw new RuntimeException('No permission'); } }
 function is_siteadmin($id) { return $id === 99; }
 function isguestuser($u = null) { return ($u->id ?? 0) === 1; }
 function validate_email($email) { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
 function get_admins() { global $ADMINS; return $ADMINS; }
 class core_user {
  const NOREPLY_USER = -10;
  public static function get_noreply_user() { return (object)['id' => 0]; }
 }
 function email_to_user($to, $from, $subject, $body) { global $EMAILS; $EMAILS[] = compact('to', 'subject', 'body'); return true; }
 function fullname($u) { return $u->firstname.' '.$u->lastname; }
 function userdate($date) { return (string)$date; }
 class moodle_url { public function __construct(...$args) {} public function out($escaped = true) { return 'https://example.invalid/local/districtupdate/'; } }
 $base = dirname(__DIR__);
 require $base.'/classes/rules.php'; require $base.'/classes/email_templates.php'; require $base.'/classes/notification_addresses.php'; require $base.'/classes/service.php'; require $base.'/classes/reviewers.php'; require $base.'/classes/audit_display.php'; require $base.'/classes/roster_admin.php'; require $base.'/classes/directory.php'; require $base.'/classes/processor.php';
 $count = 0;
 function check($condition, string $message = 'Assertion failed'): void { if (!$condition) { throw new RuntimeException($message); } }
 function expect_error(callable $f, string $part): void {
  try { $f(); } catch (Throwable $e) { check(str_contains($e->getMessage(), $part), 'Wrong exception: '.$e->getMessage()); return; }
  throw new RuntimeException('Expected exception containing '.$part);
 }
 function test(string $name, callable $f): void { global $count; fixture(); $f(); $count++; echo "PASS $name\n"; }
 function fixture(): void {
  global $DB, $USER, $MANAGER, $CONFIG, $ADMINS, $EMAILS;
  $ADMINS = []; $EMAILS = [];
  $DB = new MockDB(); $USER = (object)['id' => 10]; $MANAGER = false; $CONFIG = ['livewrites' => 0, 'excluded' => 'service-account'];
  foreach (['local_districtupdate_dist','local_districtupdate_access','local_districtupdate_review','local_districtupdate_roster','local_districtupdate_audit','local_districtupdate_cycle','user','user_info_field','user_info_data','cohort','cohort_members','local_districtupdate_skip'] as $table) { $DB->tables[$table] = []; }
  $DB->tables['cohort'][1] = (object)['id' => 1, 'name' => 'ECBOCES_Members', 'idnumber' => 'ECBOCES_Members'];
  cohort_member_fixture(20);
  $DB->tables['local_districtupdate_dist'] = [1 => (object)['id' => 1, 'name' => 'North', 'domains' => 'north.org', 'aliases' => 'North Schools'],
   2 => (object)['id' => 2, 'name' => 'South', 'domains' => 'south.org', 'aliases' => 'South Schools']];
  $DB->tables['local_districtupdate_access'] = [1 => (object)['id' => 1, 'districtid' => 1, 'userid' => 10]];
  $DB->tables['local_districtupdate_review'] = [1 => (object)['id' => 1, 'cycleid' => 1, 'districtid' => 1, 'revision' => 1, 'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0],
   2 => (object)['id' => 2, 'cycleid' => 1, 'districtid' => 2, 'revision' => 1, 'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0]];
  $DB->tables['local_districtupdate_roster'] = [1 => (object)['id' => 1, 'reviewid' => 1, 'userid' => 20, 'username' => 'person', 'email' => 'person@north.org',
   'fullname' => 'Test Person', 'institution' => 'North', 'status' => 'still', 'destination' => 0, 'targetcompany' => '', 'note' => '', 'modifiedby' => 0, 'modifiedat' => 0, 'processstate' => 'pending']];
  $DB->tables['user'] = [20 => (object)['id' => 20, 'username' => 'person', 'email' => 'person@north.org', 'institution' => 'North',
   'auth' => 'ldap', 'deleted' => 0, 'suspended' => 0, 'firstname' => 'Test', 'lastname' => 'Person']];
  $DB->tables['user'][10] = (object)['id' => 10, 'username' => 'reviewer', 'email' => 'reviewer@north.org', 'institution' => 'North', 'auth' => 'manual', 'deleted' => 0, 'suspended' => 0, 'firstname' => 'District', 'lastname' => 'Reviewer'];
  $DB->tables['local_districtupdate_cycle'][1] = (object)['id' => 1, 'reviewyear' => 2025, 'duedate' => time()+DAYSECS, 'timecreated' => time()];
 }
 function response($status = 'still', $dest = 0) { return [1 => ['status' => $status, 'destination' => $dest, 'note' => '']]; }
 function cohort_member_fixture(int $userid): void {
  global $DB;
  $DB->insert_record('cohort_members', (object)['cohortid' => 1, 'userid' => $userid]);
 }
 use local_districtupdate\rules; use local_districtupdate\service; use local_districtupdate\processor; use local_districtupdate\directory;
 test('email domain matches case insensitively', function() { global $DB; check(rules::district('A@NORTH.ORG', '', $DB->tables['local_districtupdate_dist']) === 1); });
 test('Institution is the company fallback', function() { global $DB; check(rules::district('A@personal.org', 'South Schools', $DB->tables['local_districtupdate_dist']) === 2); });
 test('conflicting evidence stays unmatched', function() { global $DB; check(rules::district('A@north.org', 'South', $DB->tables['local_districtupdate_dist']) === 0); });
 test('ambiguous domain stays unmatched', function() { global $DB; $DB->tables['local_districtupdate_dist'][2]->domains = 'north.org'; check(rules::district('A@north.org', '', $DB->tables['local_districtupdate_dist']) === 0); });
 test('unknown users stay unmatched', function() { global $DB; check(rules::district('A@unknown.org', '', $DB->tables['local_districtupdate_dist']) === 0); });
 test('Still Employed causes zero directory actions', fn() => check(rules::actions('still') === []));
 test('left user gets Basic and loses both access groups', function() { $a = rules::actions('left'); check(count($a) === 4 && in_array('Remove from ECBOCES_Members', $a) && in_array('Add to Basic', $a)); });
 test('transfer retains ECBOCES membership and changes Company', function() { $a = rules::actions('moved', 'South'); check(count($a) === 4 && !in_array('Remove from ECBOCES_Members', $a) && in_array('Set Company to South', $a)); });
 test('missing transfer destination rejected', fn() => expect_error(fn() => rules::actions('moved'), 'missing destination'));
 test('cross-district access rejected', fn() => expect_error(fn() => service::access(2), 'not assigned'));
 test('stale simultaneous edit rejected', fn() => expect_error(fn() => service::save(1, 0, response(), false), 'Another reviewer'));
 test('truncated form cannot submit', fn() => expect_error(fn() => service::save(1, 1, [], true), 'incomplete'));
 test('same-district transfer rejected', fn() => expect_error(fn() => service::save(1, 1, response('moved', 1), false), 'different destination'));
 test('unknown district injection rejected', fn() => expect_error(fn() => service::save(1, 1, response('moved', 900), false), 'valid employment'));
 test('save records current reviewer and exact destination', function() { global $DB; service::save(1, 1, response('moved', 2), false); $r = $DB->tables['local_districtupdate_roster'][1]; check($r->modifiedby === 10 && $r->targetcompany === 'South'); check($DB->tables['local_districtupdate_review'][1]->submittedat === 0); });
 test('all-Still-Employed review can be explicitly submitted', function() { global $DB; service::save(1, 1, response(), true); check($DB->tables['local_districtupdate_review'][1]->submittedby === 10); check($DB->tables['local_districtupdate_roster'][1]->processstate === 'pending'); });
 test('submitted review is frozen', function() { global $DB; $DB->tables['local_districtupdate_review'][1]->submittedat = time(); expect_error(fn() => service::save(1, 1, response('left'), false), 'already submitted'); });
 test('review cannot reopen after partial directory processing', function() { global $DB; $DB->tables['local_districtupdate_roster'][1]->processstate = 'failed'; expect_error(fn() => service::reopen(1), 'processing was attempted'); });
 test('preview requires administrator capability', fn() => expect_error(fn() => processor::preview(1, [1]), 'No permission'));
 test('preview mode never connects to AD and cannot execute', function() { global $DB, $MANAGER; $MANAGER = true; $DB->tables['local_districtupdate_review'][1]->submittedat = time(); $DB->tables['local_districtupdate_roster'][1]->status = 'left'; $b = processor::preview(1, [1]); check($b['rows'][1]['state'] === null); expect_error(fn() => processor::process($b), 'Preview expired'); });
 test('selected account must belong to the submitted review', function() { global $DB, $MANAGER; $MANAGER = true; $DB->tables['local_districtupdate_review'][2]->submittedat = time(); expect_error(fn() => processor::preview(2, [1]), 'Record not found'); });
 test('annual snapshot defaults to Still Employed and excludes service accounts', function() { global $DB; $service = clone $DB->tables['user'][20]; $service->id = 21; $service->username = 'service-account'; $DB->tables['user'][21] = $service; cohort_member_fixture(21); $DB->tables['local_districtupdate_review'] = []; $DB->tables['local_districtupdate_roster'] = []; $cycle = service::create_cycle(2026, time() + DAYSECS); $r = array_values($DB->tables['local_districtupdate_roster']); check(count($r) === 1 && $r[0]->status === 'still'); expect_error(fn() => service::create_cycle(2026, time() + DAYSECS), 'already exists'); });
 require $base.'/classes/audit_retention.php';
 require __DIR__.'/retention_contract.php';
 require __DIR__.'/auth_contract.php';
 require __DIR__.'/profile_contract.php';
 require __DIR__.'/cohort_contract.php';
 require __DIR__.'/bulk_contract.php';
 require __DIR__.'/reviewer_contract.php';
 require __DIR__.'/picker_contract.php';
 require __DIR__.'/security_contract.php';
 require __DIR__.'/email_contract.php';
 require __DIR__.'/audit_display_contract.php';
 require __DIR__.'/refresh_contract.php';
 require __DIR__.'/directory_contract.php';
 echo "$count checks passed. Stubbed Moodle/AD contracts only; no live server was contacted.\n";
}
