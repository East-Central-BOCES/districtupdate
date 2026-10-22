<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\service;
test('reviewer lookup accepts mixed case email and username and deduplicates account IDs', function() {
 global $MANAGER; $MANAGER = true;
 check(service::reviewer_ids(" REVIEWER@NORTH.ORG\nreviewer\nperson@north.org") === [10, 20]);
});
test('unknown or blank reviewer entries fail before assignments change', function() {
 global $MANAGER, $DB; $MANAGER = true; $before = serialize($DB->tables['local_districtupdate_access']);
 expect_error(fn() => service::reviewer_ids("reviewer\nmissing@north.org"), 'No active Moodle account');
 expect_error(fn() => service::reviewer_ids(''), 'at least one');
 check(serialize($DB->tables['local_districtupdate_access']) === $before);
});
test('shared reviewer email requires a unique username', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['user'][20]->email = 'reviewer@north.org';
 expect_error(fn() => service::reviewer_ids('reviewer@north.org'), 'More than one');
 check(service::reviewer_ids('reviewer') === [10]);
});
test('email and username collision cannot grant another account access', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['user'][20]->username = 'reviewer@north.org';
 expect_error(fn() => service::reviewer_ids('reviewer@north.org'), 'More than one');
});
test('suspended deleted and guest users cannot be assigned as reviewers', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['user'][10]->suspended = 1;
 expect_error(fn() => service::reviewer_ids('reviewer'), 'No active');
 $DB->tables['user'][10]->suspended = 0; $DB->tables['user'][10]->deleted = 1;
 expect_error(fn() => service::reviewer_ids('reviewer'), 'No active');
 $DB->tables['user'][1] = clone $DB->tables['user'][20];
 $DB->tables['user'][1]->id = 1; $DB->tables['user'][1]->username = 'guest';
 expect_error(fn() => service::reviewer_ids('guest'), 'Guests');
});
test('reviewer lookup requires management permission', function() {
 expect_error(fn() => service::reviewer_ids('reviewer@north.org'), 'No permission');
});
