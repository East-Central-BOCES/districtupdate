<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\service;
test('draft reviewer access has no submission expiration', function() {
 global $DB;
 check(service::reviewer_access_expires_at(service::access(1)) === 0);
 check(!service::reviewer_access_expired($DB->tables['local_districtupdate_review'][1], time() + 365 * DAYSECS));
});
test('reviewer access expires exactly 30 days after submission', function() {
 global $DB;
 $r = $DB->tables['local_districtupdate_review'][1]; $r->submittedat = 1000000;
 $expires = 1000000 + 30 * DAYSECS;
 check(!service::reviewer_access_expired($r, $expires - 1));
 check(service::reviewer_access_expired($r, $expires));
 check(service::reviewer_access_expired($r, $expires + 1));
});
test('assigned reviewer can read a recent submission', function() {
 global $DB;
 $DB->tables['local_districtupdate_review'][1]->submittedat = time() - 29 * DAYSECS;
 check(service::access(1)->id === 1);
});
test('expired direct links and saves are blocked without deleting roster data', function() {
 global $DB;
 $DB->tables['local_districtupdate_review'][1]->submittedat = time() - 31 * DAYSECS;
 expect_error(fn() => service::access(1), 'access expired');
 expect_error(fn() => service::save(1, 1, response('left'), false), 'access expired');
 check(count($DB->tables['local_districtupdate_roster']) === 1);
 check(count($DB->tables['local_districtupdate_access']) === 1);
});
test('administrator retains access to expired submitted reviews', function() {
 global $DB, $MANAGER;
 $MANAGER = true;
 $DB->tables['local_districtupdate_review'][1]->submittedat = time() - 31 * DAYSECS;
 check(service::access(1)->id === 1);
});
test('expiration of one annual review does not revoke another cycle', function() {
 global $DB;
 $DB->tables['local_districtupdate_review'][1]->submittedat = time() - 31 * DAYSECS;
 $next = clone $DB->tables['local_districtupdate_review'][1]; $next->id = 3; $next->cycleid = 2; $next->submittedat = 0;
 $DB->tables['local_districtupdate_review'][3] = $next;
 expect_error(fn() => service::access(1), 'access expired');
 check(service::access(3)->id === 3);
});
test('administrator reopening restores reviewer access and resubmission restarts expiry', function() {
 global $DB, $MANAGER;
 $DB->tables['local_districtupdate_review'][1]->submittedat = time() - 31 * DAYSECS;
 $MANAGER = true; service::reopen(1); $MANAGER = false;
 check(service::access(1)->submittedat === 0);
 service::save(1, 2, response(), true);
 check(service::reviewer_access_expires_at(service::access(1)) >= time() + 30 * DAYSECS - 1);
});
test('admin submission notification body contains only the protected approval link', function() {
 global $DB, $ADMINS, $EMAILS;
 $ADMINS = [(object)['id' => 99]];
 $DB->tables['local_districtupdate_review'][1]->submittedby = 10;
 $DB->tables['local_districtupdate_roster'][1]->status = 'left';
 service::notify_admins(1);
 check(count($EMAILS) === 1);
 check($EMAILS[0]['body'] === (new moodle_url('/local/districtupdate/approve.php', ['reviewid' => 1]))->out(false));
 check(!str_contains($EMAILS[0]['body'], 'Test Person') && !str_contains($EMAILS[0]['body'], 'person@north.org'));
 check($DB->tables['local_districtupdate_audit'][1]->event === 'admin_email_sent');
});
