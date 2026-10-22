<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
use local_districtupdate\service;
test('roster authentication methods retain LDAP as the default', function() {
 check(service::auth_methods() === ['ldap']);
});
test('multiple authentication identifiers are parsed and deduplicated', function() {
 global $CONFIG; $CONFIG['authmethods'] = "ldap,ldapdistrict\nldapdistrict";
 check(service::auth_methods() === ['ldap', 'ldapdistrict']);
});
test('an explicitly empty authentication selection includes no accounts', function() {
 global $CONFIG; $CONFIG['authmethods'] = ''; check(iterator_to_array(service::eligible_users()) === []);
});
test('initial rosters include a selected LDAP method with another name', function() {
 global $CONFIG, $DB; $CONFIG['authmethods'] = 'ldap,ldapdistrict'; $DB->tables['user'][20]->auth = 'ldapdistrict';
 $DB->tables['local_districtupdate_review'] = []; $DB->tables['local_districtupdate_roster'] = [];
 service::create_cycle(2026, time()+DAYSECS); $rows = array_values($DB->tables['local_districtupdate_roster']);
 check(count($rows) === 1 && $rows[0]->userid === 20);
});
test('refresh includes selected alternate methods and excludes others', function() {
 global $CONFIG, $DB, $MANAGER; $MANAGER = true; $CONFIG['authmethods'] = 'ldap,ldapdistrict';
 $u = clone $DB->tables['user'][20]; $u->id = 21; $u->username = 'otherldap'; $u->auth = 'ldapdistrict';
 $DB->tables['user'][21] = $u;
 $u = clone $u; $u->id = 22; $u->username = 'unselected'; $u->auth = 'otherauth'; $DB->tables['user'][22] = $u;
 $stats = service::refresh_cycle(1); check($stats['added'] === 1);
 check($DB->record_exists('local_districtupdate_roster', ['userid' => 21]));
 check(!$DB->record_exists('local_districtupdate_roster', ['userid' => 22]));
});
