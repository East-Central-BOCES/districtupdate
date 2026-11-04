<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance());
require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$reviewid = optional_param('reviewid', 0, PARAM_INT);
$cycleid = optional_param('cycleid', 0, PARAM_INT);
$availability = optional_param('availability', 'all', PARAM_ALPHA);
if (!array_key_exists($availability, \local_districtupdate\zoom_lookup::options())) {
 \local_districtupdate\service::fail('Unknown Zoom availability filter.');
}
$page = max(0, optional_param('page', 0, PARAM_INT));
$url = new moodle_url('/local/districtupdate/zoom.php', ['reviewid' => $reviewid, 'cycleid' => $cycleid, 'page' => $page, 'availability' => $availability]);
local_districtupdate_page('Delete departed users from Zoom', $url);
$batch = null; $token = ''; $results = null;
// Preserve the latest preview made before this filter was installed, when still fresh.
if (!empty($SESSION->local_districtupdate_zoom['batch'])) {
 \local_districtupdate\zoom_lookup::remember($SESSION->local_districtupdate_zoom['batch']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 require_sesskey(); $action = required_param('action', PARAM_ALPHA);
 if ($action === 'preview') {
  $batch = \local_districtupdate\zoom_cleanup::preview(optional_param_array('selected', [], PARAM_INT));
  \local_districtupdate\zoom_lookup::remember($batch);
  $token = bin2hex(random_bytes(24));
  $SESSION->local_districtupdate_zoom = ['token' => $token, 'batch' => $batch];
 } elseif ($action === 'delete') {
  $stored = $SESSION->local_districtupdate_zoom ?? null;
  if (!$stored || !hash_equals($stored['token'], required_param('token', PARAM_ALPHANUM))) {
   \local_districtupdate\service::fail('No matching Zoom preview. Generate a fresh preview.');
  }
  if (!required_param('acknowledge', PARAM_BOOL)) { \local_districtupdate\service::fail('Confirm permanent user and data deletion before continuing.'); }
  \local_districtupdate\zoom_lookup::forget(array_keys($stored['batch']['rows']));
  unset($SESSION->local_districtupdate_zoom);
  $processingbatch = $stored['batch'];
  \core\session\manager::write_close(); \core_php_time_limit::raise(0); ignore_user_abort(true);
  $results = \local_districtupdate\zoom_cleanup::process($processingbatch);
 } else { \local_districtupdate\service::fail('Unknown Zoom action.'); }
}
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/districtupdate/manage.php'), 'Review all rosters').' · '
 .html_writer::link(new moodle_url('/admin/settings.php', ['section' => 'local_districtupdate']), 'Zoom settings').' · '
 .html_writer::link(new moodle_url('/local/districtupdate/audit.php'), 'Audit trail');
echo $OUTPUT->notification('This permanently deletes selected Zoom users and their associated data, including meetings and recordings, without transferring data. '
 .'Only submitted No longer employed responses are eligible. Transfers are excluded. This separate action works even when AD changes were already processed.', 'warning');
echo html_writer::tag('p', get_config('local_districtupdate', 'zoomlive') ? 'Approved Zoom deletion is enabled. A fresh account preview and confirmation are required.'
 : 'Zoom deletion is disabled. Account previews are read-only. Configure the OAuth app in Plugin settings before previewing.');
if ($results !== null) {
 $rows = []; foreach ($results as $id => $result) { $rows[] = [s($processingbatch['rows'][$id]['fullname']), s($result)]; }
 echo local_districtupdate_table(['Staff member', 'Zoom result'], $rows);
 echo html_writer::link($url, 'Return to departed users');
} elseif ($batch !== null) {
 $rows = []; $valid = 0;
 foreach ($batch['rows'] as $item) {
  if (!$item['error']) { $valid++; }
  $match = $item['zoom'] ? $item['zoom']['email'].' · '.$item['zoom']['status'].' · Zoom ID: '.$item['zoom']['id'] : 'No account found';
  $rows[] = [s($item['fullname']).'<br>'.s($item['email']), s($item['error'] ? 'Not verified' : $match),
   s($item['error'] ? 'Blocked: '.$item['error'] : ($item['zoom'] ? 'Delete user and associated data permanently' : 'Record verified absence; no deletion request'))];
 }
 echo local_districtupdate_table(['Staff member', 'Zoom match', 'Action'], $rows);
 if ($valid && get_config('local_districtupdate', 'zoomlive')) {
  echo local_districtupdate_formstart($url).local_districtupdate_hidden('token', $token);
  echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'acknowledge', 'value' => 1, 'required' => 'required'])
   .' I approve permanent deletion of these Zoom users and all associated data. No data will be transferred.');
  echo html_writer::tag('button', 'Confirm Zoom cleanup', ['type' => 'submit', 'name' => 'action', 'value' => 'delete', 'class' => 'btn btn-danger m-2']).'</form>';
 }
 echo html_writer::link($url, 'Return to selection').' · '.html_writer::link(new moodle_url('/local/districtupdate/zoom.php',
  ['reviewid' => $reviewid, 'cycleid' => $cycleid, 'availability' => 'found']), 'Show found accounts only');
} else {
 $params = ['leftstatus' => 'left'];
 $where = ' WHERE r.status = :leftstatus AND v.submittedat > 0 AND v.districtid > 0 AND d.archived = 0';
 if ($reviewid) { $where .= ' AND v.id = :reviewid'; $params['reviewid'] = $reviewid; }
 if ($cycleid) { $where .= ' AND v.cycleid = :cycleid'; $params['cycleid'] = $cycleid; }
 [$lookupwhere, $lookupparams] = \local_districtupdate\zoom_lookup::condition($availability);
 $where .= $lookupwhere; $params += $lookupparams;
 if ($availability !== 'all') { $where .= " AND r.zoomstate <> 'done'"; }
 $from = ' FROM {local_districtupdate_roster} r JOIN {local_districtupdate_review} v ON v.id = r.reviewid
  JOIN {local_districtupdate_dist} d ON d.id = v.districtid JOIN {local_districtupdate_cycle} c ON c.id = v.cycleid';
 $total = $DB->count_records_sql('SELECT COUNT(*)'.$from.$where, $params);
 $roster = $DB->get_records_sql('SELECT r.*, d.name AS districtname, c.reviewyear'.$from.$where.' ORDER BY c.reviewyear DESC, d.name, r.fullname, r.id', $params, $page * 25, 25);
 echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/districtupdate/zoom.php'))->out(false), 'class' => 'my-3']);
 echo local_districtupdate_hidden('reviewid', $reviewid).local_districtupdate_hidden('cycleid', $cycleid);
 echo html_writer::tag('label', 'Zoom availability', ['for' => 'zoom-availability', 'class' => 'me-2']);
 echo html_writer::select(\local_districtupdate\zoom_lookup::options(), 'availability', $availability, false,
  ['id' => 'zoom-availability', 'class' => 'form-select d-inline-block w-auto']);
 echo html_writer::tag('button', 'Apply filter', ['type' => 'submit', 'class' => 'btn btn-secondary m-2']).'</form>';
 echo html_writer::tag('p', 'Found / not found uses account previews from the last 30 minutes in your current login. '
  .'Preview accounts from All departed users or Not checked recently to populate these filters. Errors are shown separately. Deletion always requires a fresh preview.');
 $lookups = \local_districtupdate\zoom_lookup::results();
 echo html_writer::tag('p', 'Showing up to 25 entries per page. Select accounts to check against Zoom, then preview. Previously deleted entries stay visible as completed. Not-found entries can be checked again.');
 echo $OUTPUT->paging_bar($total, $page, 25, $url);
 if ($roster) {
  echo local_districtupdate_formstart($url); $rows = [];
  foreach ($roster as $row) {
   $state = $row->zoomstate;
   $attributes = ['type' => 'checkbox', 'name' => 'selected[]', 'value' => $row->id, 'aria-label' => 'Check '.$row->fullname];
   if (in_array($state, ['pending', 'failed', 'processing'], true)) { $attributes['checked'] = 'checked'; }
   $lookup = $lookups[$row->id] ?? null;
   $availabilitylabel = $state === 'done' ? 'Deleted' : \local_districtupdate\zoom_lookup::label($lookup['state'] ?? '');
   $checkbox = $state === 'done' ? 'Completed' : html_writer::empty_tag('input', $attributes);
   $rows[] = [$checkbox, s($row->fullname).'<br>'.s($row->email), s($row->districtname).' · '.s($row->reviewyear),
    s(\local_districtupdate\audit_display::processing_status($row->processstate)), s($availabilitylabel)
    .($lookup ? '<br><small>Checked '.userdate($lookup['checked']).'</small>' : ''), s(\local_districtupdate\zoom_cleanup::label($state))
    .($row->zoomtime ? '<br><small>'.userdate($row->zoomtime).'</small>' : '')];
  }
  echo local_districtupdate_table(['Include', 'Staff member', 'District / year', 'AD status', 'Zoom availability', 'Zoom processing'], $rows);
  echo local_districtupdate_button('Preview selected Zoom accounts', 'action', 'preview').'</form>';
 } else { echo html_writer::tag('p', 'No departed users match this filter. Choose All departed users or preview unchecked accounts to update availability.'); }
 echo $OUTPUT->paging_bar($total, $page, 25, $url);
}
echo $OUTPUT->footer();
