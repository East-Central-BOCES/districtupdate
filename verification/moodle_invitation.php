<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Exercises the real invitation POST against local Mailpit; DB fixtures are rolled back. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') {
 fwrite(STDERR, "Usage: php moodle_invitation.php /path/to/moodle/config.php --test-site\n"); exit(1);
}
define('CLI_SCRIPT', true);
require $argv[1];
if (!preg_match('/^(mailpit|moodle51-mailpit-1)(:1025)?$/', $CFG->smtphosts ?? '')) {
 throw new RuntimeException('This test requires the local Mailpit SMTP catcher; no email was sent.');
}
core\session\manager::set_user(get_admin());
$CFG->debug = DEBUG_DEVELOPER; $CFG->debugdisplay = true;
class districtupdate_invite_rollback extends Exception {}
function du_invite_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$tables = ['user','local_districtupdate_dist','local_districtupdate_access','local_districtupdate_cycle','local_districtupdate_review','local_districtupdate_audit'];
$before = [];
foreach ($tables as $table) { $before[$table] = $DB->count_records($table); }
$fixturetx = $DB->start_delegated_transaction(); $failure = null;
set_error_handler(function($level, $message, $file, $line) {
 if (($level & error_reporting()) && $level === E_USER_NOTICE) { throw new ErrorException($message, 0, $level, $file, $line); }
 return false;
});
try {
 $tag = bin2hex(random_bytes(6));
 $districtid = $DB->insert_record('local_districtupdate_dist', (object)['name' => 'Invitation Test '.$tag, 'domains' => '', 'aliases' => '']);
 $year = 2100;
 while ($year > 2080 && $DB->record_exists('local_districtupdate_cycle', ['reviewyear' => $year])) { $year--; }
 du_invite_assert($year > 2080, 'Need an unused future review year.');
 $cycleid = $DB->insert_record('local_districtupdate_cycle', (object)['reviewyear' => $year, 'duedate' => time()+DAYSECS, 'timecreated' => time()]);
 $reviewid = $DB->insert_record('local_districtupdate_review', (object)['cycleid' => $cycleid, 'districtid' => $districtid,
  'revision' => 1, 'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0]);
 $email = 'du-invite-'.$tag.'@example.com';
 $userid = $DB->insert_record('user', (object)['username' => 'du-invite-'.$tag, 'email' => $email,
  'firstname' => 'Invitation', 'lastname' => 'Fixture', 'auth' => 'manual', 'confirmed' => 1,
  'deleted' => 0, 'suspended' => 0, 'mnethostid' => $CFG->mnet_localhost_id]);
 $DB->insert_record('local_districtupdate_access', (object)['districtid' => $districtid, 'userid' => $userid]);
 du_invite_assert(\local_districtupdate\service::reviewer_ids(strtoupper($email)."\ndu-invite-".$tag) === [(int)$userid],
  'Email/username lookup did not resolve to one account.');
 $duplicate = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
 unset($duplicate->id); $duplicate->username .= '-duplicate';
 $duplicateid = $DB->insert_record('user', $duplicate);
 try {
  \local_districtupdate\service::reviewer_ids($email);
  throw new RuntimeException('Ambiguous email was accepted.');
 } catch (moodle_exception $e) {
  du_invite_assert(str_contains($e->getMessage(), 'More than one'), 'Wrong ambiguity error.');
 }
 $DB->set_field('user', 'suspended', 1, ['id' => $duplicateid]);
 $_SERVER['REQUEST_METHOD'] = 'POST';
 $_GET = []; $_POST = ['action' => 'district', 'districtid' => $districtid, 'name' => 'Invitation Test '.$tag,
  'domains' => '', 'aliases' => '', 'reviewerids' => [$userid, $userid],
  '_qf__local_districtupdate_form_district' => 1, 'sesskey' => sesskey()];
 $PAGE = new moodle_page(); ob_start();
 try {
  require $CFG->dirroot.'/local/districtupdate/manage.php';
  throw new RuntimeException('District POST did not reach its redirect.');
 } catch (moodle_exception $e) {
  if ($e->errorcode !== 'redirecterrordetected') { throw $e; }
 } finally { ob_end_clean(); }
 $assignments = $DB->get_records('local_districtupdate_access', ['districtid' => $districtid]);
 du_invite_assert(count($assignments) === 1 && (int)reset($assignments)->userid === (int)$userid, 'District email assignment failed or duplicated access.');
 echo "PASS: actual district-save POST accepts selected user IDs; legacy email resolution remains unambiguous.\n";
 $second = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST); unset($second->id);
 $second->username .= '-second'; $second->email = 'du-second-'.$tag.'@example.com'; $second->firstname = 'Second';
 $secondid = $DB->insert_record('user', $second);
 $DB->insert_record('local_districtupdate_access', (object)['districtid' => $districtid, 'userid' => $secondid]);
 $CFG->forced_plugin_settings['local_districtupdate']['invitationbody'] = "Assigned reviewers:\n{reviewers}\n{reviewurl}";
 $_SERVER['REQUEST_METHOD'] = 'POST';
 $_GET = []; $_POST = ['action' => 'invite', 'reviewid' => $reviewid, 'sesskey' => sesskey()];
 $PAGE = new moodle_page();
 ob_start();
 try {
  require $CFG->dirroot.'/local/districtupdate/manage.php';
  throw new RuntimeException('POST did not reach its redirect.');
 } catch (moodle_exception $e) {
  // Moodle intentionally rejects redirects in CLI. Reaching this point means the full action ran.
  if ($e->errorcode !== 'redirecterrordetected') { throw $e; }
 } finally { $context = $PAGE->context; $actionoutput = ob_get_clean(); }
 du_invite_assert($context->contextlevel === CONTEXT_SYSTEM, 'POST did not initialize its system context.');
 du_invite_assert(!str_contains($actionoutput, 'Coding problem'), 'Controller emitted a Moodle coding notice: '.strip_tags($actionoutput));
 du_invite_assert($DB->record_exists('local_districtupdate_audit', ['event' => 'invitation_sent', 'userid' => $userid]), 'Invitation was not sent successfully.');
 du_invite_assert((int)$DB->get_field('local_districtupdate_review', 'lastsent', ['id' => $reviewid]) > 0, 'Invitation timestamp was not recorded.');
 $receipt = file_get_contents('http://mailpit:8025/_/mail/api/v1/search?query='.rawurlencode('to:'.$email), false,
  stream_context_create(['http' => ['timeout' => 5]]));
 $receipt = json_decode($receipt, true, 512, JSON_THROW_ON_ERROR);
 du_invite_assert(($receipt['total'] ?? 0) > 0, 'No synthetic invitation was received by local Mailpit.');
 $message = json_decode(file_get_contents('http://mailpit:8025/_/mail/api/v1/message/'.rawurlencode($receipt['messages'][0]['ID'])), true, 512, JSON_THROW_ON_ERROR);
 $text = str_replace("\r\n", "\n", $message['Text']);
 du_invite_assert(str_contains($text, '- Invitation Fixture ('.$email.")\n- Second Fixture (".$second->email.')'), 'Reviewer list was not expanded one per line.');
 $other = json_decode(file_get_contents('http://mailpit:8025/_/mail/api/v1/search?query='.rawurlencode('to:'.$second->email)), true, 512, JSON_THROW_ON_ERROR);
 du_invite_assert(!empty($other['messages']), 'Second reviewer invitation missing.');
 $othermessage = json_decode(file_get_contents('http://mailpit:8025/_/mail/api/v1/message/'.rawurlencode($other['messages'][0]['ID'])), true, 512, JSON_THROW_ON_ERROR);
 du_invite_assert($othermessage['Text'] === $message['Text'], 'Recipients received different reviewer lists.');
 echo "PASS: both reviewer emails contain the same name/email list with one reviewer per line.\n";
 echo "PASS: real invitation POST completed with strict coding notices enabled; synthetic email sent only to local Mailpit.\n";
} catch (Throwable $e) { $failure = $e; }
restore_error_handler();
try { $fixturetx->rollback(new districtupdate_invite_rollback()); } catch (districtupdate_invite_rollback $expected) {}
if ($failure) { throw $failure; }
foreach ($tables as $table) { du_invite_assert($DB->count_records($table) === $before[$table], 'Fixture data remained in '.$table); }
echo "PASS: all database fixtures rolled back; no reviewer invitations or AD changes were made.\n";
