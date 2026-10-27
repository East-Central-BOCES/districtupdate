<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\service;
test('initial roster includes only ECBOCES_Members cohort users', function() {
 global $DB;
 $outsider = clone $DB->tables['user'][20]; $outsider->id = 30; $outsider->username = 'outsider';
 $DB->tables['user'][30] = $outsider;
 $DB->tables['local_districtupdate_review'] = []; $DB->tables['local_districtupdate_roster'] = [];
 service::create_cycle(2026, time()+DAYSECS);
 check(count($DB->tables['local_districtupdate_roster']) === 1);
 check(reset($DB->tables['local_districtupdate_roster'])->userid === 20);
});
test('cohort resolves by ID number or name without double counting the same cohort', function() {
 global $DB;
 check(service::roster_cohort_id() === 1);
 $DB->tables['cohort'][1]->name = 'Renamed display name'; check(service::roster_cohort_id() === 1);
 $DB->tables['cohort'][1]->idnumber = ''; $DB->tables['cohort'][1]->name = 'ECBOCES_Members';
 check(service::roster_cohort_id() === 1);
});
test('missing or ambiguous cohort blocks refresh before removing roster entries', function() {
 global $DB, $MANAGER; $MANAGER = true;
 $before = serialize($DB->tables['local_districtupdate_roster']);
 $DB->tables['cohort'] = [];
 expect_error(fn() => service::refresh_cycle(1), 'Expected one Moodle cohort');
 check(serialize($DB->tables['local_districtupdate_roster']) === $before);
 $DB->tables['cohort'][1] = (object)['id' => 1, 'name' => 'ECBOCES_Members', 'idnumber' => ''];
 $DB->tables['cohort'][2] = (object)['id' => 2, 'name' => 'Other', 'idnumber' => 'ECBOCES_Members'];
 expect_error(fn() => service::refresh_cycle(1), 'Expected one Moodle cohort');
 check(serialize($DB->tables['local_districtupdate_roster']) === $before);
});
test('refresh removes untouched nonmembers and adds newly joined cohort users', function() {
 global $DB, $MANAGER; $MANAGER = true;
 $DB->delete_records('cohort_members', ['userid' => 20]);
 check(service::refresh_cycle(1)['removed'] === 1);
 cohort_member_fixture(20);
 check(service::refresh_cycle(1)['added'] === 1);
});
test('cohort changes preserve recorded decisions and submitted review history', function() {
 global $DB, $MANAGER; $MANAGER = true;
 $DB->delete_records('cohort_members', ['userid' => 20]);
 $DB->tables['local_districtupdate_roster'][1]->status = 'left';
 check(service::refresh_cycle(1)['preserved'] === 1);
 $DB->tables['local_districtupdate_roster'][1]->status = 'still';
 $DB->tables['local_districtupdate_review'][1]->submittedat = time();
 check(service::refresh_cycle(1)['locked'] === 1);
 check(count($DB->tables['local_districtupdate_roster']) === 1);
});
