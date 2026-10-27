<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance());
require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$url = new moodle_url('/local/districtupdate/manage.php');
local_districtupdate_page('Manage district staff reviews', $url);
$action = optional_param('action', '', PARAM_ALPHA);
$edit = optional_param('edit', 0, PARAM_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'district') { $edit = optional_param('districtid', 0, PARAM_INT); }
$districtdata = $edit ? $DB->get_record('local_districtupdate_dist', ['id' => $edit], '*', MUST_EXIST)
 : (object)['name' => '', 'domains' => '', 'aliases' => ''];
$reviewerids = $edit ? array_values($DB->get_records_menu('local_districtupdate_access', ['districtid' => $edit], '', 'id, userid')) : [];
$districtform = new \local_districtupdate\form\district($url, ['reviewerids' => $reviewerids, 'editing' => (bool)$edit]);
$districtform->set_data((object)['districtid' => $edit, 'name' => $districtdata->name, 'domains' => $districtdata->domains,
 'aliases' => $districtdata->aliases, 'reviewerids' => $reviewerids]);
if ($action === 'district' && $districtform->is_cancelled()) { redirect($url); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 require_sesskey();
 if ($action === 'district') {
  if ($data = $districtform->get_data()) {
   $id = (int)$data->districtid;
   $userids = \local_districtupdate\reviewers::validate_ids($data->reviewerids);
   $district = (object)['name' => trim($data->name), 'domains' => $data->domains, 'aliases' => $data->aliases];
   $tx = $DB->start_delegated_transaction();
   if ($id) { $DB->get_record('local_districtupdate_dist', ['id' => $id], '*', MUST_EXIST); $district->id = $id; $DB->update_record('local_districtupdate_dist', $district); }
   else { $id = $DB->insert_record('local_districtupdate_dist', $district); }
   $DB->delete_records('local_districtupdate_access', ['districtid' => $id]);
   foreach ($userids as $userid) { $DB->insert_record('local_districtupdate_access', (object)['districtid' => $id, 'userid' => $userid]); }
   \local_districtupdate\service::audit(0, 0, 'district_configured', ['districtid' => $id, 'reviewercount' => count($userids)]);
   $tx->allow_commit(); redirect($url, 'District saved. Refresh an existing annual roster to include new districts or matching changes.');
  }
 } elseif ($action === 'create') {
  $date = required_param('due', PARAM_RAW_TRIMMED);
  $due = DateTimeImmutable::createFromFormat('!Y-m-d', $date, core_date::get_server_timezone_object());
  if (!$due || $due->format('Y-m-d') !== $date) { \local_districtupdate\service::fail('Choose a valid due date.'); }
  \local_districtupdate\service::create_cycle(required_param('year', PARAM_INT), $due->setTime(23,59,59)->getTimestamp());
  redirect($url, 'Annual roster created. Review unmatched accounts before sending invitations.');
 } elseif ($action === 'refresh') {
  $result = \local_districtupdate\service::refresh_cycle(required_param('cycleid', PARAM_INT));
  redirect($url, 'Rosters refreshed from Moodle. Added: '.$result['added'].'; updated: '.$result['updated']
   .'; reassigned: '.$result['moved'].'; removed inactive/excluded untouched entries: '.$result['removed']
   .'; reviewed entries preserved: '.$result['preserved'].'; submitted/processed reviews left unchanged: '.$result['locked']
   .'; accounts skipped because a review is locked: '.$result['skipped'].'. No emails or AD changes were made.');
 } elseif ($action === 'invite') {
  $reviewid = required_param('reviewid', PARAM_INT);
  \local_districtupdate\service::invite($reviewid); redirect($url, 'Invitation attempted. See the audit for delivery results.');
 } elseif ($action === 'inviteall') {
  foreach ($DB->get_records('local_districtupdate_review', ['cycleid' => required_param('cycleid', PARAM_INT)]) as $r) {
   \local_districtupdate\service::invite($r->id);
  }
  redirect($url, 'Invitations attempted. See the audit for delivery results.');
 } elseif ($action === 'reopen') {
  \local_districtupdate\service::reopen(required_param('reviewid', PARAM_INT)); redirect($url, 'Review reopened.');
 } elseif ($action === 'assign') {
  \local_districtupdate\service::assign(required_param('rowid', PARAM_INT), required_param('destination', PARAM_INT)); redirect($url, 'Account assigned.');
 } elseif ($action === 'archive' || $action === 'restore') {
  $stats = \local_districtupdate\roster_admin::archive_district(required_param('districtid', PARAM_INT), $action === 'archive');
  redirect($url, $action === 'archive' ? 'District removed from rostering. Draft entries removed: '.$stats['removed'].'; recorded or submitted entries preserved: '.$stats['preserved'].'.' : 'District restored. Refresh rosters to include eligible users again.');
 } elseif ($action === 'testldap') {
  $directory = new \local_districtupdate\directory(); unset($directory);
  redirect($url, 'LDAPS bind succeeded and all three groups were identified. No accounts were changed.');
 } else { \local_districtupdate\service::fail('Unknown action.'); }
}
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/districtupdate/index.php'), 'Review dashboard').' · '
 .html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_districtupdate']), 'Plugin settings');
echo $OUTPUT->notification(get_config('local_districtupdate', 'livewrites')
 ? 'Live AD processing is enabled. Each batch still requires an administrator preview and confirmation.'
 : 'Preview mode: AD writes are disabled.', 'info');
echo local_districtupdate_formstart($url).local_districtupdate_button('Test LDAPS connection and group DNs', 'action', 'testldap').'</form>';
$districts = $DB->get_records('local_districtupdate_dist', null, 'name');
$rows = [];
foreach ($districts as $d) {
 $names = [];
 foreach ($DB->get_records('local_districtupdate_access', ['districtid' => $d->id]) as $a) {
  $u = $DB->get_record('user', ['id' => $a->userid]); if ($u) { $names[] = fullname($u).' ('.$u->email.'; '.$u->username.')'; }
 }
 $archivebutton = local_districtupdate_formstart($url).local_districtupdate_hidden('districtid', $d->id)
  .html_writer::tag('button', !empty($d->archived) ? 'Restore district' : 'Remove from rostering', ['type' => 'submit', 'name' => 'action', 'value' => !empty($d->archived) ? 'restore' : 'archive',
   'class' => 'btn btn-outline-secondary btn-sm', 'data-confirm' => !empty($d->archived) ? 'Restore this district for rostering?' : 'Remove this district from rostering? Untouched draft entries will be removed; recorded responses and submitted history are preserved. Moodle and AD accounts are unchanged.']).'</form>';
 $rows[] = [s($d->name).(!empty($d->archived) ? '<br><strong>Removed from rostering</strong>' : ''), s($d->domains), s($d->aliases), s(implode('; ', $names)),
  html_writer::link(new moodle_url($url, ['edit' => $d->id]), 'Edit').$archivebutton];
}
echo $OUTPUT->heading('Districts and reviewers', 3);
echo local_districtupdate_table(['District / AD Company', 'Email domains', 'Institution / District aliases', 'Reviewers', ''], $rows);
echo $OUTPUT->heading($edit ? 'Edit district' : 'Add one district', 4);
$districtform->display();
$PAGE->requires->js_init_code("document.querySelectorAll('[data-confirm]').forEach(function(b) { b.addEventListener('click', function(e) { if (!window.confirm(b.dataset.confirm)) { e.preventDefault(); } }); }); document.getElementById('id_name').addEventListener('keydown', function(event) { if (event.key === 'Enter' && !event.isComposing) { event.preventDefault(); document.getElementById('id_domains').focus(); } });");
echo $OUTPUT->heading('Start an annual review', 3);
echo local_districtupdate_formstart($url);
echo '<label>Review year '.local_districtupdate_input('year', userdate(time(), '%Y'), 'number', ['min' => 2020, 'max' => 2100, 'required' => 'required']).'</label> ';
echo '<label>Due date '.local_districtupdate_input('due', userdate(time() + 30 * DAYSECS, '%Y-%m-%d'), 'date', ['required' => 'required']).'</label> ';
echo local_districtupdate_button('Create annual roster', 'action', 'create').'</form>';
foreach ($DB->get_records('local_districtupdate_cycle', null, 'reviewyear DESC') as $cycle) {
 echo $OUTPUT->heading($cycle->reviewyear.' review — due '.userdate($cycle->duedate), 3);
 echo local_districtupdate_formstart($url).local_districtupdate_hidden('cycleid', $cycle->id)
  .local_districtupdate_button('Refresh rosters from Moodle', 'action', 'refresh').'</form>';
 echo html_writer::tag('p', 'Refresh includes only active users in the Moodle cohort ECBOCES_Members with a selected authentication method. It adds newly eligible users and newly configured districts, updates untouched entries, and removes untouched entries that are no longer eligible. '
  .'District responses and manual assignments are preserved. Submitted or processed reviews stay unchanged; reopen an unprocessed submitted review first if needed. '
  .'This uses the users already synced into Moodle and does not run an LDAP sync or send email.');
 echo local_districtupdate_formstart($url).local_districtupdate_hidden('cycleid', $cycle->id).local_districtupdate_button('Send invitations to all outstanding districts', 'action', 'inviteall').'</form>';
 $rows = [];
 foreach ($DB->get_records('local_districtupdate_review', ['cycleid' => $cycle->id], 'districtid') as $r) {
  if (!$r->districtid) { continue; }
  $count = $DB->count_records('local_districtupdate_roster', ['reviewid' => $r->id]);
  $archived = !empty($districts[$r->districtid]->archived);
  if ($archived && !$r->submittedat) { continue; }
  $controls = html_writer::link(new moodle_url('/local/districtupdate/review.php', ['id' => $r->id]), 'Roster');
  if ($r->submittedat) {
   if (!$archived) { $controls .= ' · '.html_writer::link(new moodle_url('/local/districtupdate/approve.php', ['reviewid' => $r->id]), 'Preview / process / audit');
   $controls .= local_districtupdate_formstart($url).local_districtupdate_hidden('reviewid', $r->id).local_districtupdate_button('Reopen review', 'action', 'reopen').'</form>'; } else { $controls .= ' · Historical review (district removed)'; }
   $submitter = $DB->get_record('user', ['id' => $r->submittedby]);
   $status = 'Submitted by '.($submitter ? fullname($submitter) : 'previous reviewer').' on '.userdate($r->submittedat);
  } else {
   $controls .= local_districtupdate_formstart($url).local_districtupdate_hidden('reviewid', $r->id).local_districtupdate_button('Send review link', 'action', 'invite').'</form>';
   $status = 'Awaiting review';
  }
  $rows[] = [s($districts[$r->districtid]->name ?? 'Previous district'), $count, s($status), $r->lastsent ? userdate($r->lastsent) : 'Not sent', $controls];
 }
 echo local_districtupdate_table(['District', 'Staff', 'Status', 'Last invitation', 'Actions'], $rows);
 $unmatched = $DB->get_record('local_districtupdate_review', ['cycleid' => $cycle->id, 'districtid' => 0]);
 if ($unmatched) {
  $unmatchedcount = $DB->count_records('local_districtupdate_roster', ['reviewid' => $unmatched->id]);
  echo $OUTPUT->heading('Unmatched accounts — '.$unmatchedcount, 4);
  echo html_writer::link(new moodle_url('/local/districtupdate/unmatched.php', ['cycleid' => $cycle->id]),
   'Manage unmatched accounts in bulk', ['class' => 'btn btn-secondary']).' · '
   .html_writer::link(new moodle_url('/local/districtupdate/excluded.php', ['cycleid' => $cycle->id]), 'Removed and ignored accounts');
 }

}
echo $OUTPUT->heading('Recent notifications and configuration activity', 3);
echo html_writer::tag('p', 'Audit entries expire after '.\local_districtupdate\audit_retention::days().' days and are removed by Moodle’s daily cleanup task. Rosters and review responses are retained.');
$rows = []; $formatter = local_districtupdate_audit_formatter();
foreach ($DB->get_records('local_districtupdate_audit', ['rosterid' => 0], 'id DESC', '*', 0, 100) as $log) {
 $actor = $log->actorid ? $DB->get_record('user', ['id' => $log->actorid]) : null;
 $recipient = $log->userid ? $DB->get_record('user', ['id' => $log->userid]) : null;
 [$activity, $summary] = $formatter->describe($log->event, $log->detail);
 $rows[] = [userdate($log->timecreated, get_string('strftimedatetimeshort', 'langconfig')), s($activity),
  s($recipient ? fullname($recipient) : ''), s($actor ? fullname($actor) : 'Scheduled task'), local_districtupdate_summary($summary)];
}
echo $rows ? local_districtupdate_table(['When', 'Activity', 'Account / recipient', 'Updated by', 'Summary'], $rows)
 : html_writer::tag('p', 'No recent notifications or configuration activity.');
echo $OUTPUT->footer();
