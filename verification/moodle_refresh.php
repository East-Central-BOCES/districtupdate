<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Real Moodle DB integration check. All fixture data and changes are rolled back. No mail or AD operations. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') {
 fwrite(STDERR, "Usage: php moodle_refresh.php /path/to/moodle/config.php --test-site\n"); exit(1);
}
define('CLI_SCRIPT', true);
require $argv[1];
core\session\manager::set_user(get_admin());
$CFG->forced_plugin_settings['local_districtupdate']['authmethods'] = 'ldap,ldapdistrict';
class districtupdate_test_rollback extends Exception {}
function du_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$tables = ['user','cohort','cohort_members','user_info_category','user_info_field','user_info_data','local_districtupdate_dist','local_districtupdate_cycle','local_districtupdate_review','local_districtupdate_roster','local_districtupdate_audit'];
$before = [];
foreach ($tables as $table) { $before[$table] = $DB->count_records($table); }
$tx = $DB->start_delegated_transaction(); $failure = null;
try {
 $tag = bin2hex(random_bytes(6));
 $cohorts = $DB->get_records('cohort', ['name' => 'ECBOCES_Members']) + $DB->get_records('cohort', ['idnumber' => 'ECBOCES_Members']);
 if (!$cohorts) {
  $cohortid = $DB->insert_record('cohort', (object)['name' => 'ECBOCES_Members', 'idnumber' => 'ECBOCES_Members',
   'contextid' => context_system::instance()->id, 'description' => '', 'descriptionformat' => FORMAT_PLAIN,
   'component' => '', 'visible' => 1, 'timecreated' => time(), 'timemodified' => time()]);
 } else { du_assert(count($cohorts) === 1, 'Resolve ambiguous ECBOCES_Members cohorts first.'); $cohortid = (int)reset($cohorts)->id; }
 $districtname = 'District Update Test '.$tag; $domain = 'du-refresh-'.$tag.'.invalid';
 $districtid = $DB->insert_record('local_districtupdate_dist', (object)['name' => $districtname, 'domains' => $domain, 'aliases' => '']);
 $year = 2100;
 while ($year > 2080 && $DB->record_exists('local_districtupdate_cycle', ['reviewyear' => $year])) { $year--; }
 du_assert($year > 2080, 'Need an unused future year in this test site.');
 $cycleid = $DB->insert_record('local_districtupdate_cycle', (object)['reviewyear' => $year, 'duedate' => time()+DAYSECS, 'timecreated' => time()]);
 $reviewid = $DB->insert_record('local_districtupdate_review', (object)['cycleid' => $cycleid, 'districtid' => $districtid,
  'revision' => 1, 'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0]);
 $user = (object)['username' => 'du-refresh-'.$tag, 'email' => 'first@'.$domain, 'institution' => $districtname,
  'firstname' => 'Refresh', 'lastname' => 'Fixture', 'auth' => 'ldap', 'confirmed' => 1,
  'deleted' => 0, 'suspended' => 0, 'mnethostid' => $CFG->mnet_localhost_id];
 $user->auth = 'ldapdistrict';
 $userid = $DB->insert_record('user', $user);
 $DB->insert_record('cohort_members', (object)['cohortid' => $cohortid, 'userid' => $userid, 'timeadded' => time()]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 $row = $DB->get_record('local_districtupdate_roster', ['reviewid' => $reviewid, 'userid' => $userid], '*', MUST_EXIST);
 du_assert($row->status === 'still', 'New user was not prefilled as Still Employed.');
 echo "PASS: real DB refresh adds a user from a selected LDAP method with an alternate name.\n";
 $row->status = 'left'; $row->note = 'Preserve decision'; $row->modifiedby = get_admin()->id;
 $DB->update_record('local_districtupdate_roster', $row);
 $user->username .= '-second'; $user->email = 'second@'.$domain;
 $secondid = $DB->insert_record('user', $user);
 $DB->insert_record('cohort_members', (object)['cohortid' => $cohortid, 'userid' => $secondid, 'timeadded' => time()]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 $saved = $DB->get_record('local_districtupdate_roster', ['id' => $row->id], '*', MUST_EXIST);
 du_assert($saved->status === 'left' && $saved->note === 'Preserve decision', 'Response was overwritten.');
 du_assert($DB->record_exists('local_districtupdate_roster', ['reviewid' => $reviewid, 'userid' => $secondid]), 'Second user missing.');
 echo "PASS: real DB refresh preserves responses while adding another user.\n";
 $DB->set_field('local_districtupdate_review', 'submittedat', time(), ['id' => $reviewid]);
 $user->username .= '-third'; $user->email = 'third@'.$domain;
 $thirdid = $DB->insert_record('user', $user);
 $DB->insert_record('cohort_members', (object)['cohortid' => $cohortid, 'userid' => $thirdid, 'timeadded' => time()]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 du_assert(!$DB->record_exists('local_districtupdate_roster', ['userid' => $thirdid]), 'Submitted review was changed.');
 \local_districtupdate\service::reopen($reviewid);
 \local_districtupdate\service::refresh_cycle($cycleid);
 du_assert($DB->record_exists('local_districtupdate_roster', ['reviewid' => $reviewid, 'userid' => $thirdid]), 'Reopened review did not refresh.');
 echo "PASS: submission freezes refresh; reopening enables it.\n";
 $DB->set_field('user', 'suspended', 1, ['id' => $thirdid]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 du_assert(!$DB->record_exists('local_districtupdate_roster', ['userid' => $thirdid]), 'Inactive untouched row was not removed.');
 $s = \local_districtupdate\service::refresh_cycle($cycleid);
 du_assert($s['added'] === 0 && $s['updated'] === 0 && $s['moved'] === 0 && $s['removed'] === 0, 'Repeated refresh changed unchanged rows.');
 echo "PASS: inactive untouched removal and repeat refresh work in real DB.\n";
 $secondrow = $DB->get_record('local_districtupdate_roster', ['reviewid' => $reviewid, 'userid' => $secondid], '*', MUST_EXIST);
 $DB->set_field('local_districtupdate_roster', 'manualassignment', 1, ['id' => $secondrow->id]);
 $DB->set_field('user', 'email', 'changed@unmatched.invalid', ['id' => $secondid]);
 $DB->set_field('user', 'institution', '', ['id' => $secondid]);
 $now = time(); $cutoff = $now - 30 * DAYSECS;
 $log = (object)['rosterid' => $secondrow->id, 'userid' => $secondid, 'actorid' => get_admin()->id,
  'event' => 'district_assigned', 'detail' => '{}', 'timecreated' => $cutoff - 1];
 $expiredid = $DB->insert_record('local_districtupdate_audit', $log);
 $log->event = 'retention_boundary'; $log->timecreated = $cutoff;
 $boundaryid = $DB->insert_record('local_districtupdate_audit', $log);
 $beforepurge = $DB->get_record('local_districtupdate_roster', ['id' => $secondrow->id], '*', MUST_EXIST);
 \local_districtupdate\audit_retention::purge($now);
 du_assert(!$DB->record_exists('local_districtupdate_audit', ['id' => $expiredid]), 'Expired log remained.');
 du_assert($DB->record_exists('local_districtupdate_audit', ['id' => $boundaryid]), 'Exact cutoff log was deleted.');
 du_assert(serialize($beforepurge) === serialize($DB->get_record('local_districtupdate_roster', ['id' => $secondrow->id])), 'Cleanup changed roster state.');
 \local_districtupdate\service::refresh_cycle($cycleid);
 $afterrefresh = $DB->get_record('local_districtupdate_roster', ['id' => $secondrow->id], '*', MUST_EXIST);
 du_assert((int)$afterrefresh->reviewid === (int)$reviewid && (int)$afterrefresh->manualassignment === 1, 'Expired log affected manual assignment.');
 echo "PASS: real DB cleanup respects the 30-day boundary and preserves manual assignments and roster state.\n";
 $categoryid = $DB->insert_record('user_info_category', (object)['name' => 'District test '.$tag, 'sortorder' => 999]);
 $shortname = 'district_test_'.$tag;
 $fieldid = $DB->insert_record('user_info_field', (object)['shortname' => $shortname, 'name' => 'District',
  'datatype' => 'text', 'description' => '', 'descriptionformat' => FORMAT_PLAIN, 'categoryid' => $categoryid,
  'sortorder' => 999, 'required' => 0, 'locked' => 1, 'visible' => 0, 'forceunique' => 0, 'signup' => 0,
  'defaultdata' => '', 'defaultdataformat' => FORMAT_PLAIN, 'param1' => '30', 'param2' => '255',
  'param3' => '0', 'param4' => '', 'param5' => '']);
 $CFG->forced_plugin_settings['local_districtupdate']['districtprofilefield'] = $shortname;
 $user->username = 'du-profile-'.$tag; $user->email = 'du-profile@unknown.invalid'; $user->institution = '';
 $profileuserid = $DB->insert_record('user', $user);
 $DB->insert_record('cohort_members', (object)['cohortid' => $cohortid, 'userid' => $profileuserid, 'timeadded' => time()]);
 $dataid = $DB->insert_record('user_info_data', (object)['userid' => $profileuserid, 'fieldid' => $fieldid,
  'data' => $districtname, 'dataformat' => FORMAT_PLAIN]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 $profilerow = $DB->get_record('local_districtupdate_roster', ['userid' => $profileuserid, 'reviewid' => $reviewid], '*', MUST_EXIST);
 du_assert($profilerow->institution === '', 'Custom District was copied over Institution.');
 $DB->set_field('user_info_data', 'data', 'Unknown district', ['id' => $dataid]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 $profilerow = $DB->get_record('local_districtupdate_roster', ['id' => $profilerow->id], '*', MUST_EXIST);
 $unmatched = $DB->get_record('local_districtupdate_review', ['id' => $profilerow->reviewid], '*', MUST_EXIST);
 du_assert((int)$unmatched->districtid === 0, 'Changed custom District did not rematch the untouched roster.');
 echo "PASS: real custom District profile data matches users with no email/Institution match and refresh rematches changed values.\n";
 $user->username = 'du-nonmember-'.$tag; $user->email = 'nonmember@'.$domain; $user->institution = $districtname;
 $outsiderid = $DB->insert_record('user', $user);
 \local_districtupdate\service::refresh_cycle($cycleid);
 du_assert(!$DB->record_exists('local_districtupdate_roster', ['userid' => $outsiderid]), 'Non-cohort account was included.');
 $DB->insert_record('cohort_members', (object)['cohortid' => $cohortid, 'userid' => $outsiderid, 'timeadded' => time()]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 du_assert($DB->record_exists('local_districtupdate_roster', ['userid' => $outsiderid]), 'New cohort member was not included.');
 $DB->delete_records('cohort_members', ['cohortid' => $cohortid, 'userid' => $outsiderid]);
 \local_districtupdate\service::refresh_cycle($cycleid);
 du_assert(!$DB->record_exists('local_districtupdate_roster', ['userid' => $outsiderid]), 'Untouched departed cohort member remained.');
 echo "PASS: real Moodle cohort membership excludes nonmembers, includes new members, and removes untouched users after leaving the cohort.\n";
} catch (Throwable $e) { $failure = $e; }
try { $tx->rollback(new districtupdate_test_rollback()); } catch (districtupdate_test_rollback $expected) {}
if ($failure) { throw $failure; }
foreach ($tables as $table) { du_assert($DB->count_records($table) === $before[$table], 'Fixture data remained in '.$table); }
echo "PASS: all fixture data rolled back; no persistent site data changed.\n";
