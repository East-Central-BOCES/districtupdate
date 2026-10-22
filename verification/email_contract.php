<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\email_templates;
test('edited invitation templates reach the assigned recipient with expanded metadata', function() {
 global $CONFIG, $EMAILS;
 $CONFIG['invitationsubject'] = '{district}: review for {year}';
 $CONFIG['invitationbody'] = "Please check {district} by {duedate}.\n{reviewurl}";
 \local_districtupdate\service::invite(1);
 check(count($EMAILS) === 1 && $EMAILS[0]['to']->id === 10);
 check($EMAILS[0]['subject'] === 'North: review for 2025');
 check(str_contains($EMAILS[0]['body'], 'Please check North by '));
 check(str_contains($EMAILS[0]['body'], 'https://example.invalid/local/districtupdate/'));
});
test('edited admin templates send metadata and link without roster contents', function() {
 global $CONFIG, $ADMINS, $EMAILS;
 $CONFIG['adminsubject'] = '{year}: {district} ready';
 $CONFIG['adminbody'] = "Review is ready.\n{reviewurl}";
 $ADMINS = [(object)['id' => 99]];
 \local_districtupdate\service::notify_admins(1);
 check($EMAILS[0]['subject'] === '2025: North ready');
 check($EMAILS[0]['body'] === "Review is ready.\nhttps://example.invalid/local/districtupdate/");
});
test('omitting the link appends it and blank templates use defaults', function() {
 global $DB, $CONFIG;
 $CONFIG['adminbody'] = 'Ready'; $CONFIG['adminsubject'] = '  ';
 $args = ['admin', $DB->tables['local_districtupdate_dist'][1], $DB->tables['local_districtupdate_cycle'][1], 'https://example.org/approve'];
 $m = email_templates::render(...$args);
 check($m['subject'] === 'District review submitted: North');
 check($m['body'] === "Ready\n\nhttps://example.org/approve");
 $CONFIG['adminbody'] = '';
 check(email_templates::render(...$args)['body'] === 'https://example.org/approve');
});
test('template substitution does not recursively expand district data or permit subject newlines', function() {
 global $DB, $CONFIG;
 $CONFIG['adminsubject'] = "{district}\r\nHeader: injected";
 $CONFIG['adminbody'] = '{district}';
 $d = clone $DB->tables['local_districtupdate_dist'][1]; $d->name = '{year}';
 $m = email_templates::render('admin', $d, $DB->tables['local_districtupdate_cycle'][1], 'https://example.org/approve');
 check($m['body'] === "{year}\n\nhttps://example.org/approve");
 check(!str_contains($m['subject'], "\n") && !str_contains($m['subject'], "\r"));
});
