<?php
// SPDX-License-Identifier: GPL-3.0-or-later
// Namespace shims exercise the actual directory adapter without opening any LDAP connection.
namespace local_districtupdate {
 function extension_loaded($name) { return true; }
 function ldap_set_option(...$args) { return true; }
 function ldap_connect(...$args) { return (object)[]; }
 function ldap_bind(...$args) { return true; }
 function ldap_unbind(...$args) { return true; }
 function ldap_errno(...$args) { global $AD; return $AD['error'] ?? 0; }
 function ldap_escape($value, ...$args) { return addcslashes($value, '\\()*'); }
 function ldap_get_entries($connection, $result) { return $result; }
 function ldap_count_entries($connection, $result) { return $result['count']; }
 function ldap_read($connection, $dn, $filter, $attributes) {
  global $AD;
  if ($dn === '') { return ['count' => 1, 0 => ['defaultnamingcontext' => ['DC=test,DC=invalid']]]; }
  foreach (['members','zoom','basic'] as $key) {
   if ($dn === $key) { return ['count' => 1, 0 => ['dn' => $key, 'objectguid' => [$key.'guid'], 'objectsid' => ['SID'.pack('V', $key === 'members' ? 1001 : ($key === 'zoom' ? 1002 : 1003))]]]; }
  }
  foreach (['members','zoom','basic'] as $key) {
   if (str_contains($filter, ':='.$key.')')) { return ['count' => (int)($AD['groups'][$key]['direct'] || $AD['groups'][$key]['inherited'] || $AD['groups'][$key]['primary'])]; }
  }
  throw new \RuntimeException('Unexpected LDAP read');
 }
 function ldap_search($connection, $base, $filter, $attributes, ...$rest) {
  global $AD;
  if (str_contains($filter, 'objectCategory=person')) {
   return ['count' => 1, 0 => ['dn' => 'CN=Test Person,DC=test,DC=invalid', 'objectguid' => [$AD['guid']], 'samaccountname' => ['person'],
    'mail' => [$AD['email']], 'company' => [$AD['company']], 'physicaldeliveryofficename' => [$AD['office']],
    'primarygroupid' => ['513'], 'objectsid' => ['SID'.pack('V', 2000)], 'admincount' => [$AD['admincount']]]];
  }
  foreach (['members','zoom','basic'] as $key) {
   if (str_contains($filter, ':='.$key.')')) {
    return ['count' => (int)$AD['groups'][$key][str_contains($filter, 'objectSid=') ? 'primary' : 'inherited']];
   }
  }
  throw new \RuntimeException('Unexpected LDAP search');
 }
 function ldap_compare($connection, $dn, $attribute, $value) { global $AD; $AD['error'] = 0; return $AD['groups'][$dn]['direct']; }
 function ldap_mod_add($connection, $dn, $attrs) { global $AD; $AD['writes']++; $AD['groups'][$dn]['direct'] = true; return true; }
 function ldap_mod_del($connection, $dn, $attrs) { global $AD; $AD['writes']++; $AD['groups'][$dn]['direct'] = false; return true; }
 function ldap_mod_replace($connection, $dn, $attrs) {
  global $AD; $AD['writes']++;
  if ($AD['failoffice']) { $AD['error'] = 50; return false; }
  $AD['office'] = $attrs['physicalDeliveryOfficeName'];
  if (isset($attrs['company'])) { $AD['company'] = $attrs['company']; } return true;
 }
}
namespace {
 if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
 foreach (['LDAP_OPT_X_TLS_REQUIRE_CERT','LDAP_OPT_X_TLS_DEMAND','LDAP_OPT_PROTOCOL_VERSION','LDAP_OPT_REFERRALS','LDAP_OPT_NETWORK_TIMEOUT','LDAP_OPT_TIMELIMIT','LDAP_ESCAPE_FILTER'] as $key) {
  if (!defined($key)) { define($key, 1); }
 }
 function ad_fixture(string $status = 'left'): object {
  global $CONFIG, $AD, $DB;
  $CONFIG = array_merge($CONFIG, ['livewrites' => 1, 'ldapurl' => 'ldaps://dc.test.invalid:636', 'userbase' => 'DC=test,DC=invalid',
   'binddn' => 'svc', 'bindpassword' => 'FAKE_TEST_SECRET', 'membersdn' => 'members', 'zoomdn' => 'zoom', 'basicdn' => 'basic']);
  $AD = ['guid' => 'immutable-guid', 'email' => 'person@north.org', 'company' => 'North', 'office' => 'Old Office', 'admincount' => '0',
   'writes' => 0, 'failoffice' => false, 'groups' => []];
  foreach (['members','zoom','basic'] as $key) { $AD['groups'][$key] = ['direct' => $key !== 'basic', 'inherited' => false, 'primary' => false]; }
  $r = clone $DB->tables['local_districtupdate_roster'][1]; $r->status = $status; $r->targetcompany = $status === 'moved' ? 'South' : ''; return $r;
 }
 test('left user receives all four verified AD changes', function() {
  global $AD; $r = ad_fixture(); $d = new \local_districtupdate\directory(); $s = $d->state($r); $d->apply($r, $s);
  check($AD['groups']['basic']['direct'] && !$AD['groups']['zoom']['direct'] && !$AD['groups']['members']['direct']);
  check($AD['office'] === 'No Access' && $AD['company'] === 'North');
 });
 test('transfer changes Company, retains membership, switches license groups', function() {
  global $AD; $r = ad_fixture('moved'); $d = new \local_districtupdate\directory(); $d->apply($r, $d->state($r));
  check($AD['groups']['members']['direct'] && $AD['groups']['basic']['direct'] && !$AD['groups']['zoom']['direct']);
  check($AD['office'] === 'No Access' && $AD['company'] === 'South');
 });
 test('simultaneous direct and inherited Zoom membership blocks all writes', function() {
  global $AD; $r = ad_fixture(); $AD['groups']['zoom']['inherited'] = true; $d = new \local_districtupdate\directory();
  expect_error(fn() => $d->apply($r, $d->state($r)), 'inherited or primary'); check($AD['writes'] === 0);
 });
 test('primary-group access blocks removal', function() {
  global $AD; $r = ad_fixture(); $AD['groups']['members']['primary'] = true; $d = new \local_districtupdate\directory();
  expect_error(fn() => $d->apply($r, $d->state($r)), 'inherited or primary'); check($AD['writes'] === 0);
 });
 test('AD email mismatch blocks account identity', function() {
  global $AD; $r = ad_fixture(); $AD['email'] = 'other@north.org'; $d = new \local_districtupdate\directory();
  expect_error(fn() => $d->state($r), 'does not match'); check($AD['writes'] === 0);
 });
 test('protected AD account blocked', function() {
  global $AD; $r = ad_fixture(); $AD['admincount'] = '1'; $d = new \local_districtupdate\directory(); expect_error(fn() => $d->state($r), 'Protected');
 });
 test('changed object GUID invalidates the approved preview', function() {
  global $AD; $r = ad_fixture(); $d = new \local_districtupdate\directory(); $s = $d->state($r); $AD['guid'] = 'replacement-guid';
  expect_error(fn() => $d->apply($r, $s), 'changed since'); check($AD['writes'] === 0);
 });
 test('partial failure preserves audit and is safely retryable', function() {
  global $AD, $DB; $r = ad_fixture(); $d = new \local_districtupdate\directory(); $s = $d->state($r); $AD['failoffice'] = true;
  expect_error(fn() => $d->apply($r, $s), 'Earlier successful');
  check($AD['groups']['basic']['direct'] && !$AD['groups']['zoom']['direct'] && $AD['groups']['members']['direct']);
  check(count(array_filter($DB->tables['local_districtupdate_audit'], fn($a) => $a->event === 'action_failed')) === 1);
  $AD['failoffice'] = false; $d->apply($r, $d->state($r)); check($AD['office'] === 'No Access' && !$AD['groups']['members']['direct']);
 });
 test('unexpected company change blocks processing', function() {
  global $AD; $r = ad_fixture(); $AD['company'] = 'Unexpected District'; $d = new \local_districtupdate\directory();
  expect_error(fn() => $d->apply($r, $d->state($r)), 'Company changed'); check($AD['writes'] === 0);
 });
 test('expired preview cannot write', function() {
  global $MANAGER, $DB; $MANAGER = true; ad_fixture(); $DB->tables['local_districtupdate_review'][1]->submittedat = time(); $DB->tables['local_districtupdate_roster'][1]->status = 'left';
  $b = \local_districtupdate\processor::preview(1, [1]); $b['created'] -= 901;
  expect_error(fn() => \local_districtupdate\processor::process($b), 'Preview expired');
 });
 test('changed connection settings invalidate a preview', function() {
  global $MANAGER, $DB, $CONFIG; $MANAGER = true; ad_fixture(); $DB->tables['local_districtupdate_review'][1]->submittedat = time(); $DB->tables['local_districtupdate_roster'][1]->status = 'left';
  $b = \local_districtupdate\processor::preview(1, [1]); $CONFIG['zoomdn'] = 'another-group';
  expect_error(fn() => \local_districtupdate\processor::process($b), 'settings changed');
 });
 test('batch records the approving user and completed state', function() {
  global $MANAGER, $DB, $AD; $MANAGER = true; ad_fixture(); $DB->tables['local_districtupdate_review'][1]->submittedat = time(); $DB->tables['local_districtupdate_roster'][1]->status = 'left';
  $b = \local_districtupdate\processor::preview(1, [1]); $results = \local_districtupdate\processor::process($b);
  check($DB->tables['local_districtupdate_roster'][1]->processstate === 'done' && $results[1] === 'Completed and verified');
  check(count(array_filter($DB->tables['local_districtupdate_audit'], fn($a) => $a->event === 'verified' && $a->actorid === 10)) === 1);
  check(!str_contains(json_encode($DB->tables['local_districtupdate_audit']), 'FAKE_TEST_SECRET'));
  $writes = $AD['writes']; \local_districtupdate\processor::process($b); check($AD['writes'] === $writes);
 });

 test('selected alternate authentication method is verified against the same AD connection', function() {
  global $CONFIG, $DB; $r = ad_fixture(); $CONFIG['authmethods'] = 'ldap,ldapdistrict';
  $DB->tables['user'][20]->auth = 'ldapdistrict'; $d = new \local_districtupdate\directory();
  $state = $d->state($r); check($state['email'] === 'person@north.org');
 });
 test('unselected authentication method cannot be processed in AD', function() {
  global $DB, $AD; $r = ad_fixture(); $DB->tables['user'][20]->auth = 'ldapdistrict';
  $d = new \local_districtupdate\directory(); expect_error(fn() => $d->state($r), 'authentication or exclusion');
  check($AD['writes'] === 0);
 });
 test('changing authentication selection invalidates an existing AD preview', function() {
  global $CONFIG, $DB, $MANAGER; $MANAGER = true; ad_fixture();
  $DB->tables['local_districtupdate_review'][1]->submittedat = time(); $DB->tables['local_districtupdate_roster'][1]->status = 'left';
  $b = \local_districtupdate\processor::preview(1, [1]); $CONFIG['authmethods'] = 'ldap,ldapdistrict';
  expect_error(fn() => \local_districtupdate\processor::process($b), 'settings changed');
 });
}
