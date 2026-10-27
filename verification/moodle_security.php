<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Local Mailpit-only security integration checks; database fixtures are rolled back. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') {
 fwrite(STDERR, "Usage: php moodle_security.php /path/to/moodle/config.php --test-site\n"); exit(1);
}
define('CLI_SCRIPT', true);
require $argv[1];
if (!preg_match('/^(mailpit|moodle51-mailpit-1)(:1025)?$/', $CFG->smtphosts ?? '')) {
 throw new RuntimeException('This test requires the local Mailpit SMTP catcher.');
}
class districtupdate_security_rollback extends Exception {}
function du_security_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$admin = get_admin();
core\session\manager::set_user($admin);
$tables = ['user', 'local_districtupdate_dist', 'local_districtupdate_access', 'local_districtupdate_cycle',
 'local_districtupdate_review', 'local_districtupdate_roster', 'local_districtupdate_audit'];
$before = [];
foreach ($tables as $table) { $before[$table] = $DB->count_records($table); }
$tx = $DB->start_delegated_transaction(); $failure = null;
try {
 $tag = bin2hex(random_bytes(6)); $name = 'Security Test '.$tag;
 $districtid = $DB->insert_record('local_districtupdate_dist', (object)['name' => $name, 'domains' => '', 'aliases' => '']);
 $year = 2100;
 while ($year > 2080 && $DB->record_exists('local_districtupdate_cycle', ['reviewyear' => $year])) { $year--; }
 du_security_assert($year > 2080, 'Need an unused future review year.');
 $cycleid = $DB->insert_record('local_districtupdate_cycle', (object)['reviewyear' => $year, 'duedate' => time()+DAYSECS, 'timecreated' => time()]);
 $userid = $DB->insert_record('user', (object)['username' => 'du-security-'.$tag, 'email' => 'du-security-'.$tag.'@example.com',
  'firstname' => 'Security', 'lastname' => 'Fixture', 'auth' => 'manual', 'confirmed' => 1,
  'deleted' => 0, 'suspended' => 0, 'mnethostid' => $CFG->mnet_localhost_id]);
 $reviewer = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
 $DB->insert_record('local_districtupdate_access', (object)['districtid' => $districtid, 'userid' => $userid]);
 $reviewid = $DB->insert_record('local_districtupdate_review', (object)['cycleid' => $cycleid, 'districtid' => $districtid,
  'revision' => 1, 'submittedby' => $userid, 'submittedat' => time()-29*DAYSECS, 'lastsent' => 0]);
 $rowid = $DB->insert_record('local_districtupdate_roster', (object)['reviewid' => $reviewid, 'userid' => $userid,
  'username' => $reviewer->username, 'fullname' => 'PRIVATE STAFF '.$tag, 'email' => $reviewer->email,
  'institution' => $name, 'status' => 'left', 'destination' => 0, 'targetcompany' => '', 'note' => 'PRIVATE NOTE '.$tag,
  'modifiedby' => $userid, 'modifiedat' => time(), 'processstate' => 'pending']);
 core\session\manager::set_user($reviewer);
 du_security_assert((int)\local_districtupdate\service::access($reviewid)->id === $reviewid, 'Recent submission was denied.');
 $DB->set_field('local_districtupdate_review', 'submittedat', time()-31*DAYSECS, ['id' => $reviewid]);
 try {
  \local_districtupdate\service::access($reviewid);
  throw new RuntimeException('Expired direct access was allowed.');
 } catch (moodle_exception $e) {
  du_security_assert(str_contains($e->getMessage(), 'access expired'), 'Wrong denial.');
 }
 $PAGE = new moodle_page(); ob_start();
 try { require $CFG->dirroot.'/local/districtupdate/index.php'; } finally { $html = ob_get_clean(); }
 du_security_assert(!str_contains($html, $name), 'Expired review was exposed on dashboard.');
 du_security_assert($DB->record_exists('local_districtupdate_roster', ['id' => $rowid]), 'Expiration deleted retained data.');
 echo "PASS: recent reviewer access allowed; expired direct link and dashboard access denied; roster retained.\n";
 core\session\manager::set_user($admin);
 du_security_assert((int)\local_districtupdate\service::access($reviewid)->id === $reviewid, 'Administrator lost access.');
 require_once($CFG->libdir.'/adminlib.php');
 $settingspage = admin_get_root(false, true)->locate('local_districtupdate');
 $notificationsetting = $settingspage->settings->local_districtupdatenotificationemails;
 du_security_assert($notificationsetting->write_setting('invalid-address') !== '', 'Invalid email setting was accepted.');
 $notificationemail = 'du-adminnotify-'.$tag.'@example.com';
 du_security_assert($notificationsetting->write_setting($notificationemail) === '', 'Notification address setting could not save.');
 \local_districtupdate\service::notify_admins($reviewid);
 $context = stream_context_create(['http' => ['timeout' => 5]]);
 $mail = json_decode(file_get_contents('http://mailpit:8025/_/mail/api/v1/search?query='.rawurlencode('subject:'.$tag), false, $context), true, 512, JSON_THROW_ON_ERROR);
 du_security_assert(!empty($mail['messages']), 'Admin notification missing from Mailpit.');
 $id = $mail['messages'][0]['ID'];
 $message = json_decode(file_get_contents('http://mailpit:8025/_/mail/api/v1/message/'.rawurlencode($id), false, $context), true, 512, JSON_THROW_ON_ERROR);
 du_security_assert(count($message['To']) === 1 && $message['To'][0]['Address'] === $notificationemail, 'Notification was not routed to the entered address.');
 $expected = (new moodle_url('/local/districtupdate/approve.php', ['reviewid' => $reviewid]))->out(false);
 du_security_assert(trim($message['Text'] ?? '') === $expected, 'Notification body was not link-only.');
 echo "PASS: entered notification address validates and receives the link-only email in local Mailpit; administrator retains access.\n";
 \local_districtupdate\service::reopen($reviewid);
 core\session\manager::set_user($reviewer);
 du_security_assert(!(int)\local_districtupdate\service::access($reviewid)->submittedat, 'Reopening did not restore access.');
 echo "PASS: administrator reopening restores reviewer access.\n";
 core\session\manager::set_user($admin);
 require_once($CFG->libdir.'/adminlib.php');
 $settingspage = admin_get_root(false, true)->locate('local_districtupdate');
 foreach (['admin', 'invitation'] as $type) {
  $subjectsetting = $settingspage->settings->{'local_districtupdate'.$type.'subject'};
  $bodysetting = $settingspage->settings->{'local_districtupdate'.$type.'body'};
  du_security_assert($subjectsetting->write_setting('Custom '.$type.' '.$tag.' {district}') === '', 'Subject setting could not save.');
  du_security_assert($bodysetting->write_setting("Custom {district} {year}\n{reviewurl}") === '', 'Body setting could not save.');
  if ($type === 'admin') { \local_districtupdate\service::notify_admins($reviewid); }
  else { \local_districtupdate\service::invite($reviewid); }
  $mail = json_decode(file_get_contents('http://mailpit:8025/_/mail/api/v1/search?query='.rawurlencode('subject:"Custom '.$type.' '.$tag.'"'), false, $context), true, 512, JSON_THROW_ON_ERROR);
  du_security_assert(!empty($mail['messages']), 'Custom '.$type.' notification missing from Mailpit.');
  $message = json_decode(file_get_contents('http://mailpit:8025/_/mail/api/v1/message/'.rawurlencode($mail['messages'][0]['ID']), false, $context), true, 512, JSON_THROW_ON_ERROR);
  $link = (new moodle_url('/local/districtupdate/'.($type === 'admin' ? 'approve.php' : 'review.php'),
   [$type === 'admin' ? 'reviewid' : 'id' => $reviewid]))->out(false);
  du_security_assert(trim(str_replace("\r\n", "\n", $message['Text'] ?? '')) === "Custom ".$name.' '.$year."\n".$link, 'Custom body did not expand correctly.');
  du_security_assert($message['Subject'] === 'Custom '.$type.' '.$tag.' '.$name, 'Custom subject did not expand correctly.');
 }
 echo "PASS: actual Moodle settings save customized admin and reviewer emails; expanded subjects and bodies received in local Mailpit.\n";
} catch (Throwable $e) { $failure = $e; }
core\session\manager::set_user($admin);
try { $tx->rollback(new districtupdate_security_rollback()); } catch (districtupdate_security_rollback $expected) {}
cache_helper::purge_by_definition('core', 'config');
if ($failure) { throw $failure; }
foreach ($tables as $table) { du_security_assert($DB->count_records($table) === $before[$table], 'Fixture remained in '.$table); }
echo "PASS: all database fixtures rolled back; no AD operations or external email delivery.\n";
