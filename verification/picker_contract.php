<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\reviewers;
test('picker saves stable reviewer IDs and deduplicates repeated selections', function() {
 global $MANAGER; $MANAGER = true;
 check(reviewers::validate_ids([10, 20, 10]) === [10, 20]);
});
test('picker distinguishes accounts sharing an email by account ID', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['user'][20]->email = $DB->tables['user'][10]->email;
 check(reviewers::validate_ids([10, 20]) === [10, 20]);
 check(reviewers::label($DB->tables['user'][10]) !== reviewers::label($DB->tables['user'][20]));
});
test('picker rejects deleted suspended guest or nonexistent selected users', function() {
 global $MANAGER, $DB; $MANAGER = true;
 $DB->tables['user'][10]->deleted = 1;
 expect_error(fn() => reviewers::validate_ids([10]), 'unavailable');
 $DB->tables['user'][10]->deleted = 0; $DB->tables['user'][10]->suspended = 1;
 expect_error(fn() => reviewers::validate_ids([10]), 'unavailable');
 expect_error(fn() => reviewers::validate_ids([123456]), 'unavailable');
 $DB->tables['user'][1] = clone $DB->tables['user'][20]; $DB->tables['user'][1]->id = 1;
 expect_error(fn() => reviewers::validate_ids([1]), 'unavailable');
});
test('picker requires reviewers and limits oversized selections', function() {
 global $MANAGER; $MANAGER = true;
 expect_error(fn() => reviewers::validate_ids([]), 'between 1 and 100');
 expect_error(fn() => reviewers::validate_ids(range(1, 101)), 'between 1 and 100');
});
test('picker search and selection require management permission', function() {
 expect_error(fn() => reviewers::search('Person'), 'No permission');
 expect_error(fn() => reviewers::validate_ids([10]), 'No permission');
});
test('picker search rejects excessive queries and skips short queries', function() {
 global $MANAGER; $MANAGER = true;
 check(reviewers::search('a') === []);
 expect_error(fn() => reviewers::search(str_repeat('a', 101)), '100 characters');
});
