<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
use local_districtupdate\service;
function refresh_user(int $id = 21): void {
 global $DB;
 $u = clone $DB->tables['user'][20]; $u->id = $id; $u->username = 'newperson'.$id;
 $u->email = 'newperson'.$id.'@north.org'; $DB->tables['user'][$id] = $u; cohort_member_fixture($id);
}
test('refresh is restricted to administrators', function() {
 expect_error(fn() => service::refresh_cycle(1), 'No permission');
});
test('refresh adds new users and invalidates open forms', function() {
 global $MANAGER, $DB; $MANAGER = true; refresh_user();
 $s = service::refresh_cycle(1); check($s['added'] === 1);
 $rows = $DB->get_records('local_districtupdate_roster', ['userid' => 21]);
 check(count($rows) === 1 && reset($rows)->status === 'still' && reset($rows)->modifiedby === 0);
 expect_error(fn() => service::save(1, 1, response(), false), 'Another reviewer');
});
test('refresh updates untouched profile data', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['user'][20]->lastname = 'Updated'; $DB->tables['user'][20]->email = 'updated@north.org';
 $s = service::refresh_cycle(1); $r = $DB->tables['local_districtupdate_roster'][1];
 check($s['updated'] === 1 && $r->email === 'updated@north.org' && $r->fullname === 'Test Updated');
});
test('refresh preserves responses notes attribution and reviewed identity', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $r = $DB->tables['local_districtupdate_roster'][1]; $r->status = 'moved'; $r->destination = 2;
 $r->targetcompany = 'South'; $r->note = 'Confirmed transfer'; $r->modifiedby = 10;
 $DB->tables['user'][20]->email = 'new@unknown.org';
 $s = service::refresh_cycle(1); $r = $DB->tables['local_districtupdate_roster'][1];
 check($s['preserved'] === 1 && $r->status === 'moved' && $r->destination === 2 && $r->note === 'Confirmed transfer');
 check($r->modifiedby === 10 && $r->email === 'person@north.org');
});
test('refresh removes inactive untouched entries and keeps a local audit snapshot', function() {
 global $MANAGER, $DB; $MANAGER = true; $DB->tables['user'][20]->suspended = 1;
 $s = service::refresh_cycle(1); check($s['removed'] === 1 && !$DB->tables['local_districtupdate_roster']);
 $logs = $DB->get_records('local_districtupdate_audit', ['event' => 'roster_account_removed']);
 $a = reset($logs); check($a->userid === 20 && $a->rosterid === 0 && str_contains($a->detail, 'person@north.org'));
});
test('inactive users with district decisions are retained', function() {
 global $MANAGER, $DB; $MANAGER = true; $DB->tables['user'][20]->deleted = 1;
 $DB->tables['local_districtupdate_roster'][1]->status = 'left';
 $s = service::refresh_cycle(1); check($s['removed'] === 0 && $s['preserved'] === 1);
 check($DB->tables['local_districtupdate_roster'][1]->status === 'left');
});
test('submitted reviews are frozen and receive no new users', function() {
 global $MANAGER, $DB; $MANAGER = true; refresh_user();
 $DB->tables['local_districtupdate_review'][1]->submittedat = time();
 $DB->tables['user'][20]->lastname = 'Changed';
 $s = service::refresh_cycle(1);
 check($s['locked'] === 1 && $s['added'] === 0 && $s['updated'] === 0 && $s['skipped'] === 1);
 check($DB->tables['local_districtupdate_roster'][1]->fullname === 'Test Person');
});
test('any attempted AD processing freezes the entire review', function() {
 global $MANAGER, $DB; $MANAGER = true; refresh_user();
 $DB->tables['local_districtupdate_roster'][1]->processstate = 'failed';
 $s = service::refresh_cycle(1); check($s['locked'] === 1 && $s['added'] === 0);
 check($DB->tables['local_districtupdate_roster'][1]->processstate === 'failed');
});
test('refresh includes newly configured districts and reassigns untouched accounts', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['local_districtupdate_dist'][3] = (object)['id' => 3, 'name' => 'East', 'domains' => 'east.org', 'aliases' => ''];
 $DB->tables['user'][20]->email = 'person@east.org'; $DB->tables['user'][20]->institution = 'East';
 $s = service::refresh_cycle(1); $r = $DB->tables['local_districtupdate_roster'][1];
 $target = $DB->get_record('local_districtupdate_review', ['id' => $r->reviewid]);
 check($s['moved'] === 1 && (int)$target->districtid === 3 && $r->userid === 20);
 check(count($DB->tables['local_districtupdate_roster']) === 1);
});
test('administrator district overrides survive refresh', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['local_districtupdate_roster'][1]->manualassignment = 1;
 $DB->tables['user'][20]->email = 'unknown@unknown.org'; $DB->tables['user'][20]->institution = '';
 $s = service::refresh_cycle(1); check($s['preserved'] === 1 && $s['moved'] === 0);
 check((int)$DB->tables['local_districtupdate_roster'][1]->reviewid === 1);
});
test('district reassignment invalidates both affected drafts', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['user'][20]->email = 'person@south.org'; $DB->tables['user'][20]->institution = 'South';
 $s = service::refresh_cycle(1); check($s['moved'] === 1);
 check((int)$DB->tables['local_districtupdate_roster'][1]->reviewid === 2);
 check($DB->tables['local_districtupdate_review'][1]->revision === 2 && $DB->tables['local_districtupdate_review'][2]->revision === 2);
});
test('refresh never moves a user into an already submitted review', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['local_districtupdate_review'][2]->submittedat = time();
 $DB->tables['user'][20]->email = 'person@south.org'; $DB->tables['user'][20]->institution = 'South';
 $s = service::refresh_cycle(1); check($s['skipped'] === 1 && $s['moved'] === 0);
 check((int)$DB->tables['local_districtupdate_roster'][1]->reviewid === 1);
});
test('repeated refresh is idempotent and does not invalidate an unchanged draft', function() {
 global $MANAGER, $DB; $MANAGER = true; refresh_user(); service::refresh_cycle(1);
 $revision = $DB->tables['local_districtupdate_review'][1]->revision;
 $s = service::refresh_cycle(1); check($s['added'] === 0 && $s['moved'] === 0 && $s['removed'] === 0 && $s['updated'] === 0);
 check(count($DB->tables['local_districtupdate_roster']) === 2 && $DB->tables['local_districtupdate_review'][1]->revision === $revision);
});
test('reopening an unprocessed submission allows adding newly synced users', function() {
 global $MANAGER, $DB; $MANAGER = true; refresh_user();
 $DB->tables['local_districtupdate_review'][1]->submittedat = time(); service::reopen(1);
 $s = service::refresh_cycle(1); check($s['added'] === 1 && $s['locked'] === 0);
});
