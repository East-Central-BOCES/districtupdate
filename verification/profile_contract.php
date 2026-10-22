<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\rules;
use local_districtupdate\service;
test('District profile field matches names and aliases without email or Institution', function() {
 global $DB;
 check(rules::district('a@unknown.org', '', $DB->tables['local_districtupdate_dist'], ' NORTH ') === 1);
 check(rules::district('a@unknown.org', '', $DB->tables['local_districtupdate_dist'], 'South Schools') === 2);
});
test('District profile conflicts and ambiguous aliases remain unmatched', function() {
 global $DB;
 check(rules::district('a@north.org', '', $DB->tables['local_districtupdate_dist'], 'South') === 0);
 check(rules::district('a@unknown.org', 'North', $DB->tables['local_districtupdate_dist'], 'South') === 0);
 $DB->tables['local_districtupdate_dist'][2]->aliases = 'North Schools';
 check(rules::district('a@unknown.org', '', $DB->tables['local_districtupdate_dist'], 'North Schools') === 0);
});
test('missing or unrecognized District profile values retain existing matching', function() {
 global $DB;
 check(rules::district('a@north.org', '', $DB->tables['local_districtupdate_dist'], 'Unknown') === 1);
 check(rules::district('a@north.org', 'North', $DB->tables['local_districtupdate_dist'], 'North') === 1);
 check(service::profile_districts() === []);
});
function profile_fixture(): void {
 global $DB;
 $DB->tables['user_info_field'][1] = (object)['id' => 1, 'shortname' => 'District', 'name' => 'District'];
 $DB->tables['user_info_data'][1] = (object)['id' => 1, 'fieldid' => 1, 'userid' => 20, 'data' => 'South'];
 $DB->tables['user'][20]->email = 'person@unknown.org'; $DB->tables['user'][20]->institution = '';
}
test('initial annual roster reads the District custom profile field', function() {
 global $DB; profile_fixture();
 $DB->tables['local_districtupdate_review'] = []; $DB->tables['local_districtupdate_roster'] = [];
 service::create_cycle(2026, time()+DAYSECS);
 $row = array_values($DB->tables['local_districtupdate_roster'])[0];
 check($DB->tables['local_districtupdate_review'][$row->reviewid]->districtid === 2);
 check($row->institution === '');
});
test('refresh rematches untouched users from District and preserves manual assignments', function() {
 global $DB, $MANAGER; profile_fixture(); $MANAGER = true;
 service::refresh_cycle(1);
 check($DB->tables['local_districtupdate_roster'][1]->reviewid === 2);
 $DB->tables['local_districtupdate_roster'][1]->manualassignment = 1;
 $DB->tables['user_info_data'][1]->data = 'North';
 service::refresh_cycle(1);
 check($DB->tables['local_districtupdate_roster'][1]->reviewid === 2);
});
test('profile field configuration supports display-name fallback and disabling', function() {
 global $DB, $CONFIG; profile_fixture();
 $DB->tables['user_info_field'][1]->shortname = 'staffdistrict';
 check(service::profile_districts()[20] === 'South');
 $CONFIG['districtprofilefield'] = 'staffdistrict';
 check(service::profile_districts()[20] === 'South');
 $CONFIG['districtprofilefield'] = '';
 check(service::profile_districts() === []);
});
test('ambiguous profile field labels require a unique shortname', function() {
 global $DB; profile_fixture();
 $DB->tables['user_info_field'][1]->shortname = 'firstdistrict';
 $DB->tables['user_info_field'][2] = (object)['id' => 2, 'shortname' => 'seconddistrict', 'name' => 'District'];
 expect_error(fn() => service::profile_districts(), 'unique shortname');
});
