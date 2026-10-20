<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$reviewid = required_param('reviewid', PARAM_INT);
$review = \local_districtupdate\service::access($reviewid);
if (!$review->submittedat || !$review->districtid) { \local_districtupdate\service::fail('This district review has not been submitted.'); }
$url = new moodle_url('/local/districtupdate/approve.php', ['reviewid' => $reviewid]);
$batch = null; $token = ''; $results = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 require_sesskey();
 $action = required_param('action', PARAM_ALPHA);
 if ($action === 'preview') {
  $batch = \local_districtupdate\processor::preview($reviewid, optional_param_array('selected', [], PARAM_INT));
  // Keep only this administrator’s latest preview. A token is single use and expires after 15 minutes.
  $token = bin2hex(random_bytes(24));
  $SESSION->local_districtupdate_batch = ['token' => $token, 'batch' => $batch];
 } elseif ($action === 'process') {
  $stored = $SESSION->local_districtupdate_batch ?? null;
  if (!$stored || !hash_equals($stored['token'], required_param('token', PARAM_ALPHANUM))
      || (int)$stored['batch']['reviewid'] !== $reviewid) {
   \local_districtupdate\service::fail('No matching preview. Generate a fresh preview.');
  }
  unset($SESSION->local_districtupdate_batch);
  $processingbatch = $stored['batch'];
  // Release the session lock so staff can use Moodle while a confirmed batch is processed.
  \core\session\manager::write_close();
  \core_php_time_limit::raise(0);
  ignore_user_abort(true);
  $results = \local_districtupdate\processor::process($processingbatch);
 } else { \local_districtupdate\service::fail('Unknown action.'); }
}
$district = $DB->get_record('local_districtupdate_dist', ['id' => $review->districtid], '*', MUST_EXIST);
local_districtupdate_page('Approve directory changes — '.$district->name, $url);
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/districtupdate/manage.php'), 'Manage all reviews');
if ($results !== null) {
 $rows = [];
 foreach ($results as $id => $result) { $rows[] = [s($processingbatch['rows'][$id]['row']->fullname), s($result)]; }
 echo local_districtupdate_table(['Staff member', 'Processing result'], $rows);
}
if ($batch) {
 echo $OUTPUT->heading('Action preview', 3);
 echo $OUTPUT->notification($batch['live'] ? 'Current AD state was checked. Accounts with a blocked preview will be skipped. '
  .'Confirming applies the listed changes to the selected accounts. Partial failures are recorded; successful earlier actions remain applied.'
  : 'Preview only. AD is not connected and these actions cannot be executed until live processing is configured and enabled.', 'info');
 $rows = []; $valid = 0;
 foreach ($batch['rows'] as $item) {
  if (!$item['error']) { $valid++; }
  $state = $item['state'];
  $before = $state ? 'Office: '.$state['office'].'; Company: '.$state['company'].'; Direct groups: '
    .implode(', ', array_keys(array_filter($state['groups'], fn($g) => $g['direct']))) : 'Not checked in preview mode';
  $rows[] = [s($item['row']->fullname).'<br>'.s($item['row']->username), s($before),
   s(implode('; ', $item['actions'])), $item['error'] ? s('Blocked: '.$item['error']) : 'Ready'];
 }
 echo local_districtupdate_table(['Account', 'Current AD values', 'Approved response → proposed actions', 'Check'], $rows);
 if ($batch['live'] && $valid) {
  echo local_districtupdate_formstart($url).local_districtupdate_hidden('token', $token)
   .local_districtupdate_button('Confirm and process these accounts', 'action', 'process').'</form>';
 }
 echo html_writer::link($url, 'Return to account selection');
} else {
 echo $OUTPUT->notification('Changed accounts are selected by default. Uncheck anyone you want to exclude, '
  .'or select a single account. Preview comes before execution. Failed or interrupted accounts can be retried with a fresh preview.', 'info');
 echo local_districtupdate_formstart($url);
 $rows = [];
 foreach ($DB->get_records('local_districtupdate_roster', ['reviewid' => $reviewid], 'fullname') as $r) {
  if ($r->status === 'still') { continue; }
  $eligible = $r->processstate !== 'done';
  $checkbox = $eligible ? html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'selected[]', 'value' => $r->id,
    'checked' => 'checked', 'aria-label' => 'Include '.$r->fullname]) : 'Completed';
  $rows[] = [$checkbox, s($r->fullname).'<br>'.s($r->email), s(implode('; ', \local_districtupdate\rules::actions($r->status, $r->targetcompany))), s($r->processstate), s($r->note)];
 }
 echo local_districtupdate_table(['Include', 'Account', 'Proposed actions', 'Processing status', 'District notes'], $rows);
 echo local_districtupdate_button('Preview selected accounts', 'action', 'preview').'</form>';
}
echo $OUTPUT->heading('Review audit — latest 200 entries', 3);
$logs = $DB->get_records_sql('SELECT a.* FROM {local_districtupdate_audit} a JOIN {local_districtupdate_roster} r ON r.id = a.rosterid
 WHERE r.reviewid = :reviewid ORDER BY a.id DESC', ['reviewid' => $reviewid], 0, 200);
$rows = [];
foreach ($logs as $log) {
 $actor = $log->actorid ? $DB->get_record('user', ['id' => $log->actorid]) : null;
 $subject = $DB->get_record('local_districtupdate_roster', ['id' => $log->rosterid]);
 $rows[] = [userdate($log->timecreated), s($subject->fullname ?? ''), s($actor ? fullname($actor) : 'Scheduled task'), s($log->event), s($log->detail)];
}
echo local_districtupdate_table(['Time', 'Staff member', 'Actor', 'Event', 'Details'], $rows);
echo $OUTPUT->footer();
