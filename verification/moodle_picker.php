<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Real form/SQL checks. No emails or AD operations; all fixture data rolls back. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') {
 fwrite(STDERR, "Usage: php moodle_picker.php /path/to/moodle/config.php --test-site\n"); exit(1);
}
define('CLI_SCRIPT', true);
require $argv[1];
core\session\manager::set_user(get_admin());
class districtupdate_picker_rollback extends Exception {}
function du_picker_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
$tables = ['user', 'local_districtupdate_dist', 'local_districtupdate_access', 'local_districtupdate_audit'];
$before = [];
foreach ($tables as $table) { $before[$table] = $DB->count_records($table); }
$fixturetx = $DB->start_delegated_transaction(); $failure = null;
try {
 $tag = bin2hex(random_bytes(6));
 $u = (object)['username' => 'picker-'.$tag, 'email' => 'picker-'.$tag.'@example.com',
  'firstname' => 'First'.$tag, 'lastname' => 'Last'.$tag, 'auth' => 'manual', 'confirmed' => 1,
  'deleted' => 0, 'suspended' => 0, 'mnethostid' => $CFG->mnet_localhost_id];
 $userid = $DB->insert_record('user', $u);
 $u->username .= '-second'; $u->firstname = 'Other'.$tag;
 $secondid = $DB->insert_record('user', $u);
 $u->username .= '-suspended'; $u->suspended = 1;
 $suspendedid = $DB->insert_record('user', $u);
 $matches = \local_districtupdate\reviewers::search('First'.$tag.' Last'.$tag);
 du_picker_assert(count($matches) === 1 && $matches[0]['id'] === (int)$userid, 'Name token search failed.');
 $matches = \local_districtupdate\reviewers::search('PICKER-'.$tag.'@EXAMPLE.COM');
 du_picker_assert(count($matches) === 2, 'Email search did not distinguish shared addresses or included suspended users.');
 du_picker_assert(\local_districtupdate\reviewers::search('%'.$tag) === [], 'SQL wildcard was not escaped.');
 echo "PASS: real SQL search matches name/email, distinguishes shared emails and excludes suspended users; literal wildcards are escaped.\n";
 $_SERVER['REQUEST_METHOD'] = 'POST'; $_GET = [];
 $_POST = ['action' => 'district', 'districtid' => 0, 'name' => str_repeat('X', 65),
  'domains' => 'keep-'.$tag.'.invalid', 'aliases' => 'Keep alias '.$tag, 'reviewerids' => [$userid],
  '_qf__local_districtupdate_form_district' => 1, 'sesskey' => sesskey()];
 $PAGE = new moodle_page(); ob_start();
 try { require $CFG->dirroot.'/local/districtupdate/manage.php'; } finally { $html = ob_get_clean(); }
 du_picker_assert(str_contains($html, 'Use a district name of 1'), 'Inline validation error missing.');
 du_picker_assert(str_contains($html, 'keep-'.$tag.'.invalid') && str_contains($html, 'Keep alias '.$tag), 'Form values lost after error.');
 $dom = new DOMDocument(); @$dom->loadHTML($html); $xpath = new DOMXPath($dom);
 du_picker_assert($xpath->query('//select[@name="reviewerids[]"]/option[@selected and @value="'.$userid.'"]')->length === 1,
  'Reviewer selection lost after error.');
 du_picker_assert($DB->count_records('local_districtupdate_dist') === $before['local_districtupdate_dist'], 'Invalid form saved a district.');
 echo "PASS: invalid district form preserves domains, aliases and selected reviewer with an inline error.\n";
 $_POST['name'] = 'Picker Test '.$tag; $_POST['reviewerids'] = [$userid, $secondid];
 $PAGE = new moodle_page(); ob_start();
 try {
  require $CFG->dirroot.'/local/districtupdate/manage.php';
  throw new RuntimeException('Valid district form did not save.');
 } catch (moodle_exception $e) {
  if ($e->errorcode !== 'redirecterrordetected') { throw $e; }
 } finally { ob_end_clean(); }
 $district = $DB->get_record('local_districtupdate_dist', ['name' => 'Picker Test '.$tag], '*', MUST_EXIST);
 du_picker_assert($DB->count_records('local_districtupdate_access', ['districtid' => $district->id]) === 2, 'Multiple reviewer selection did not save.');
 $PAGE = new moodle_page(); ob_start();
 try { require $CFG->dirroot.'/local/districtupdate/manage.php'; } finally { $duplicatehtml = ob_get_clean(); }
 du_picker_assert(str_contains($duplicatehtml, 'A district with this name already exists.'), 'Duplicate district name was not shown inline.');
 du_picker_assert(str_contains($duplicatehtml, 'keep-'.$tag.'.invalid'), 'Duplicate-name validation lost form values.');
 $ordinary = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
 core\session\manager::set_user($ordinary);
 try {
  \local_districtupdate\reviewers::search($tag);
  throw new RuntimeException('Ordinary user could search directory.');
 } catch (required_capability_exception $expected) {}
 echo "PASS: valid form saves multiple selected accounts; ordinary users cannot search reviewers.\n";
} catch (Throwable $e) { $failure = $e; }
core\session\manager::set_user(get_admin());
try { $fixturetx->rollback(new districtupdate_picker_rollback()); } catch (districtupdate_picker_rollback $expected) {}
if ($failure) { throw $failure; }
foreach ($tables as $table) { du_picker_assert($DB->count_records($table) === $before[$table], 'Fixture remained in '.$table); }
echo "PASS: all fixtures rolled back; no emails or AD changes.\n";
