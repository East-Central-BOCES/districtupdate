<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\roster_admin;
use local_districtupdate\service;
function bulk_fixture(): void {
 global $DB, $MANAGER;
 $MANAGER = true;
 $DB->tables['local_districtupdate_review'][3] = (object)['id' => 3, 'cycleid' => 1, 'districtid' => 0,
  'revision' => 1, 'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0];
 $DB->tables['local_districtupdate_roster'][1]->reviewid = 3;
 $u = clone $DB->tables['user'][20]; $u->id = 21; $u->username = 'second'; $u->email = 'second@north.org';
 $DB->tables['user'][21] = $u; cohort_member_fixture(21);
 $r = clone $DB->tables['local_districtupdate_roster'][1]; $r->id = 2; $r->userid = 21; $r->username = 'second';
 $DB->tables['local_districtupdate_roster'][2] = $r;
}
test('bulk assignments move different rows to different districts with stable manual overrides', function() {
 global $DB; bulk_fixture();
 check(roster_admin::apply(3, 1, 'assign', [1 => 1, 2 => 2], []) === 2);
 check($DB->tables['local_districtupdate_roster'][1]->reviewid === 1);
 check($DB->tables['local_districtupdate_roster'][2]->reviewid === 2);
 check($DB->tables['local_districtupdate_roster'][2]->manualassignment === 1);
 check($DB->tables['local_districtupdate_review'][3]->revision === 2 && $DB->tables['local_districtupdate_review'][1]->revision === 2);
});
test('bulk assignments validate every destination before changing any row', function() {
 global $DB; bulk_fixture(); $DB->tables['local_districtupdate_review'][2]->submittedat = time();
 expect_error(fn() => roster_admin::apply(3, 1, 'assign', [1 => 1, 2 => 2], []), 'No accounts were changed');
 check($DB->tables['local_districtupdate_roster'][1]->reviewid === 3 && $DB->tables['local_districtupdate_roster'][2]->reviewid === 3);
});
test('stale and cross-review bulk selections are rejected', function() {
 global $DB; bulk_fixture();
 expect_error(fn() => roster_admin::apply(3, 0, 'remove', [], [1]), 'list changed');
 $DB->tables['local_districtupdate_roster'][2]->reviewid = 2;
 expect_error(fn() => roster_admin::apply(3, 1, 'remove', [], [1,2]), 'Record not found');
 check(count($DB->tables['local_districtupdate_roster']) === 2);
});
test('remove from year persists through refresh but does not suppress future cycles', function() {
 global $DB; bulk_fixture(); roster_admin::apply(3, 1, 'remove', [], [1]);
 service::refresh_cycle(1);
 check(!$DB->record_exists('local_districtupdate_roster', ['userid' => 20]));
 check(!in_array(20, array_map(fn($u) => $u->id, iterator_to_array(service::eligible_users(1))), true));
 check(in_array(20, array_map(fn($u) => $u->id, iterator_to_array(service::eligible_users(2))), true));
});
test('ignored users stay out of every year and can be restored independently of audit logs', function() {
 global $DB; bulk_fixture(); roster_admin::apply(3, 1, 'ignore', [], [1,2]);
 check(iterator_to_array(service::eligible_users(99)) === []);
 $DB->tables['local_districtupdate_audit'] = [];
 $ids = array_keys($DB->tables['local_districtupdate_skip']); roster_admin::restore($ids);
 check(count(iterator_to_array(service::eligible_users(99))) === 2);
 check(count($DB->tables['user']) === 3);
});
test('bulk cleanup cannot remove recorded responses', function() {
 global $DB; bulk_fixture(); $DB->tables['local_districtupdate_roster'][2]->note = 'Reviewed';
 expect_error(fn() => roster_admin::apply(3, 1, 'ignore', [], [1,2]), 'recorded response');
 check(count($DB->tables['local_districtupdate_roster']) === 2 && !$DB->tables['local_districtupdate_skip']);
});
test('district removal excludes future roster matching and preserves submitted history', function() {
 global $DB, $MANAGER; $MANAGER = true;
 $DB->tables['local_districtupdate_review'][1]->submittedat = time();
 $stats = roster_admin::archive_district(1, true);
 check($stats['preserved'] === 1 && $DB->tables['local_districtupdate_dist'][1]->archived === 1);
 check(count($DB->tables['local_districtupdate_roster']) === 1);
 check(iterator_to_array(service::eligible_users(2)) === []);
 $MANAGER = false; expect_error(fn() => service::access(1), 'removed from rostering');
 $MANAGER = true; roster_admin::archive_district(1, false);
 check(count(iterator_to_array(service::eligible_users(2))) === 1);
});
test('district removal deletes untouched drafts but preserves manual assignments', function() {
 global $DB, $MANAGER; $MANAGER = true;
 check(roster_admin::archive_district(1, true)['removed'] === 1);
 check(!$DB->tables['local_districtupdate_roster']);
 roster_admin::archive_district(1, false);
 $DB->tables['local_districtupdate_roster'][1] = (object)['id'=>1,'reviewid'=>1,'userid'=>20,'status'=>'still','processstate'=>'pending','modifiedby'=>0,'note'=>'','manualassignment'=>1];
 check(roster_admin::archive_district(1, true)['preserved'] === 1);
});
test('bulk actions require management permission', function() {
 expect_error(fn() => roster_admin::apply(1, 1, 'remove', [], [1]), 'No permission');
 expect_error(fn() => roster_admin::archive_district(1, true), 'No permission');
 expect_error(fn() => roster_admin::restore([1]), 'No permission');
});
test('permanent district deletion requires permission and exact confirmation', function() {
 global $DB, $MANAGER;
 expect_error(fn() => roster_admin::delete_district(1, 'North'), 'No permission');
 $MANAGER = true;
 expect_error(fn() => roster_admin::delete_district(1, 'north'), 'exactly');
 check(count($DB->tables['local_districtupdate_dist']) === 2);
});
test('permanent deletion removes district plugin history and access but preserves users and other districts', function() {
 global $DB, $MANAGER; $MANAGER = true;
 $DB->tables['local_districtupdate_review'][1]->submittedat = time();
 $DB->tables['local_districtupdate_roster'][1]->processstate = 'done';
 service::audit(1, 20, 'verified', ['actions' => []]);
 $counts = roster_admin::delete_district(1, 'North');
 check($counts === ['reviews' => 1, 'staff' => 1]);
 check(!$DB->record_exists('local_districtupdate_dist', ['id' => 1]));
 check(!$DB->record_exists('local_districtupdate_review', ['id' => 1]));
 check(!$DB->tables['local_districtupdate_roster'] && !$DB->tables['local_districtupdate_access']);
 check(!$DB->record_exists('local_districtupdate_audit', ['rosterid' => 1]));
 check($DB->record_exists('local_districtupdate_audit', ['event' => 'district_deleted']));
 check($DB->record_exists('local_districtupdate_review', ['id' => 2]) && count($DB->tables['user']) === 2);
});
test('external transfer references block permanent deletion before any mutation', function() {
 global $DB, $MANAGER; $MANAGER = true;
 $row = clone $DB->tables['local_districtupdate_roster'][1]; $row->id = 2; $row->reviewid = 2; $row->destination = 1;
 $DB->tables['local_districtupdate_roster'][2] = $row;
 expect_error(fn() => roster_admin::delete_district(1, 'North'), 'transfer destination');
 check(count($DB->tables['local_districtupdate_dist']) === 2 && count($DB->tables['local_districtupdate_roster']) === 2);
});
test('management status distinguishes no review submission no changes and verified processing', function() {
 $state = fn($review, $stats) => \local_districtupdate\review_summary::state($review, $stats);
 check($state(null, null)[1] === 'light');
 check($state((object)['submittedat' => 0], null)[1] === 'light');
 $review = (object)['submittedat' => time()];
 check($state($review, null)[0] === 'Submitted — empty roster');
 check($state($review, (object)['staff' => 3, 'changed' => 0, 'completed' => 0])[0] === 'Submitted — no changes needed');
 check($state($review, (object)['staff' => 3, 'changed' => 2, 'completed' => 1])[1] === 'warning');
 check($state($review, (object)['staff' => 3, 'changed' => 2, 'completed' => 2])[1] === 'success');
});
