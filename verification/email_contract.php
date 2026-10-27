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
test('reviewers placeholder lists the same intended recipients in every invitation', function() {
 global $DB, $CONFIG, $EMAILS;
 $second = clone $DB->tables['user'][10]; $second->id = 11; $second->firstname = 'Second';
 $second->email = 'second@north.org'; $DB->tables['user'][11] = $second;
 $DB->tables['local_districtupdate_access'][2] = (object)['id' => 2, 'districtid' => 1, 'userid' => 11];
 $CONFIG['invitationbody'] = "Other assigned reviewers:\n{reviewers}\n{reviewurl}";
 \local_districtupdate\service::invite(1);
 check(count($EMAILS) === 2 && $EMAILS[0]['body'] === $EMAILS[1]['body']);
 check(str_contains($EMAILS[0]['body'], "- District Reviewer (reviewer@north.org)\n- Second Reviewer (second@north.org)"));
});
test('reviewer list excludes unavailable accounts disabled email and invalid addresses', function() {
 global $DB;
 foreach (range(11, 15) as $id) {
  $u = clone $DB->tables['user'][10]; $u->id = $id; $u->email = 'test'.$id.'@north.org';
  if ($id === 11) { $u->suspended = 1; }
  if ($id === 12) { $u->deleted = 1; }
  if ($id === 13) { $u->emailstop = 1; }
  if ($id === 14) { $u->email = 'invalid'; }
  if ($id !== 15) { $DB->tables['user'][$id] = $u; }
  $DB->tables['local_districtupdate_access'][$id] = (object)['id' => $id, 'districtid' => 1, 'userid' => $id];
 }
 check(array_keys(\local_districtupdate\service::invitation_reviewers(1)) === [10]);
});
test('admin template can reference district reviewers while its default remains link-only', function() {
 global $CONFIG, $ADMINS, $EMAILS;
 $ADMINS = [(object)['id' => 99]];
 $CONFIG['adminbody'] = "District reviewers:\n{reviewers}\n{reviewurl}";
 \local_districtupdate\service::notify_admins(1);
 check(str_contains($EMAILS[0]['body'], '- District Reviewer (reviewer@north.org)'));
 check(!str_contains($EMAILS[0]['body'], 'Test Person'));
 unset($CONFIG['adminbody']); $EMAILS = [];
 \local_districtupdate\service::notify_admins(1);
 check($EMAILS[0]['body'] === 'https://example.invalid/local/districtupdate/');
});
test('configured notification mailboxes replace all site administrator recipients', function() {
 global $CONFIG, $ADMINS, $EMAILS, $DB;
 $ADMINS = [(object)['id' => 99, 'email' => 'admin@example.com']];
 $CONFIG['notificationemails'] = "reviewdesk@example.com\notherdesk@example.com\nREVIEWDESK@example.com";
 \local_districtupdate\service::notify_admins(1);
 check(count($EMAILS) === 2);
 check($EMAILS[0]['to']->email === 'REVIEWDESK@example.com' && $EMAILS[1]['to']->email === 'otherdesk@example.com');
 check(!in_array('admin@example.com', array_map(fn($m) => $m['to']->email, $EMAILS), true));
 check($DB->tables['local_districtupdate_audit'][1]->userid === 0);
 check(str_contains($DB->tables['local_districtupdate_audit'][1]->detail, 'REVIEWDESK@example.com'));
});
test('blank notification setting preserves the site administrator fallback', function() {
 global $CONFIG, $ADMINS, $EMAILS;
 $CONFIG['notificationemails'] = ''; $ADMINS = [(object)['id' => 99, 'email' => 'admin@example.com']];
 \local_districtupdate\service::notify_admins(1);
 check(count($EMAILS) === 1 && $EMAILS[0]['to']->id === 99);
});
test('invalid configured notification addresses neither send nor fall back to administrators', function() {
 global $CONFIG, $ADMINS, $EMAILS, $DB;
 $CONFIG['notificationemails'] = "valid@example.com\nnot-an-email";
 $ADMINS = [(object)['id' => 99, 'email' => 'admin@example.com']];
 \local_districtupdate\service::notify_admins(1);
 check(!$EMAILS && $DB->tables['local_districtupdate_audit'][1]->event === 'admin_email_failed');
 expect_error(fn() => \local_districtupdate\notification_addresses::parse('Bad Name <name@example.com>'), 'valid email');
});
