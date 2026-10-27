<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance());
require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$reviewid = required_param('reviewid', PARAM_INT);
$review = \local_districtupdate\service::access($reviewid);
if (!$review->submittedat || !$review->districtid) { \local_districtupdate\service::fail('This district review has not been submitted.'); }
$url = new moodle_url('/local/districtupdate/approve.php', ['reviewid' => $reviewid]);
$district = $DB->get_record('local_districtupdate_dist', ['id' => $review->districtid], '*', MUST_EXIST);
local_districtupdate_page('Approve directory changes — '.$district->name, $url);
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
$roster = $DB->get_records('local_districtupdate_roster', ['reviewid' => $reviewid], 'fullname');
$counts = ['still' => 0, 'left' => 0, 'moved' => 0]; $pending = 0;
foreach ($roster as $r) {
 $counts[$r->status]++;
 if ($r->status !== 'still' && $r->processstate !== 'done') { $pending++; }
}
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/districtupdate/manage.php'), 'Manage all reviews');
echo ' · '.html_writer::link(new moodle_url('/local/districtupdate/review.php', ['id' => $reviewid]), 'View submitted roster');
$submitter = $DB->get_record('user', ['id' => $review->submittedby]);
echo html_writer::tag('p', 'Submitted by '.s($submitter ? fullname($submitter) : 'a previous reviewer').' on '
 .userdate($review->submittedat).'.', ['class' => 'mt-3']);
echo html_writer::tag('p', count($roster).' staff · '.$counts['still'].' still employed · '.$counts['left'].' departures · '
 .$counts['moved'].' transfers', ['class' => 'mb-3']);

if ($results !== null) {
 $rows = [];
 foreach ($results as $id => $result) { $rows[] = [s($processingbatch['rows'][$id]['row']->fullname), s($result)]; }
 echo local_districtupdate_table(['Staff member', 'Processing result'], $rows);
}
if (!empty($district->archived)) {
 echo $OUTPUT->notification('This district has been removed from rostering. Review history is retained. Restore the district before processing any directory changes.', 'info');
} elseif ($batch) {
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
   local_districtupdate_summary($item['actions']), $item['error'] ? s('Blocked: '.$item['error']) : 'Ready'];
 }
 echo local_districtupdate_table(['Account', 'Current AD values', 'Approved response → proposed actions', 'Check'], $rows);
 if ($batch['live'] && $valid) {
  echo local_districtupdate_formstart($url).local_districtupdate_hidden('token', $token)
   .local_districtupdate_button('Confirm and process these accounts', 'action', 'process').'</form>';
 }
 echo html_writer::link($url, 'Return to account selection');
} else {
 if (!$counts['left'] && !$counts['moved']) {
  echo $OUTPUT->notification($roster ? 'No directory changes required. All staff in this review were marked Still Employed.'
   : 'This submitted review contains no staff accounts. No directory changes to process.', 'success');
 } else {
  echo $OUTPUT->notification($pending ? 'Changed accounts are selected below. Uncheck anyone you want to exclude, then preview before processing. Failed or interrupted accounts need a fresh preview.'
   : 'All requested directory changes have been completed.', $pending ? 'info' : 'success');
  if ($pending) { echo local_districtupdate_formstart($url); }
  $rows = [];
  foreach ($roster as $r) {
   if ($r->status === 'still') { continue; }
   $eligible = $r->processstate !== 'done';
   $checkbox = $eligible ? html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'selected[]', 'value' => $r->id,
     'checked' => 'checked', 'aria-label' => 'Include '.$r->fullname]) : 'Completed';
   $rows[] = [$checkbox, s($r->fullname).'<br>'.s($r->email),
    local_districtupdate_summary(\local_districtupdate\rules::actions($r->status, $r->targetcompany)),
    s(\local_districtupdate\audit_display::processing_status($r->processstate)), s($r->note)];
  }
  echo local_districtupdate_table(['Include', 'Account', 'Proposed actions', 'Status', 'District notes'], $rows);
  if ($pending) { echo local_districtupdate_button('Preview selected accounts', 'action', 'preview').'</form>'; }
 }
}
echo $OUTPUT->heading('Review activity', 3);
echo html_writer::tag('p', 'Showing the latest 200 entries. Activity history expires after '.\local_districtupdate\audit_retention::days().' days. Review responses and processing status are retained.');
$logs = $DB->get_records_sql('SELECT a.* FROM {local_districtupdate_audit} a JOIN {local_districtupdate_roster} r ON r.id = a.rosterid
 WHERE r.reviewid = :reviewid ORDER BY a.id DESC', ['reviewid' => $reviewid], 0, 200);
$rows = []; $formatter = local_districtupdate_audit_formatter();
foreach ($logs as $log) {
 $actor = $log->actorid ? $DB->get_record('user', ['id' => $log->actorid]) : null;
 $subject = $DB->get_record('local_districtupdate_roster', ['id' => $log->rosterid]);
 [$activity, $summary] = $formatter->describe($log->event, $log->detail);
 $rows[] = [userdate($log->timecreated, get_string('strftimedatetimeshort', 'langconfig')), s($subject->fullname ?? ''),
  s($actor ? fullname($actor) : 'Scheduled task'), s($activity), local_districtupdate_summary($summary)];
}
echo $rows ? local_districtupdate_table(['When', 'Staff member', 'Updated by', 'Activity', 'Summary'], $rows)
 : html_writer::tag('p', 'No recent activity recorded for this review.');
echo $OUTPUT->footer();
