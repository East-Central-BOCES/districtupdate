<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance()); require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$cycleid = required_param('cycleid', PARAM_INT);
$cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $cycleid], '*', MUST_EXIST);
$source = $DB->get_record('local_districtupdate_review', ['cycleid' => $cycleid, 'districtid' => 0], '*', MUST_EXIST);
$q = optional_param('q', '', PARAM_RAW_TRIMMED); $auth = optional_param('auth', '', PARAM_ALPHANUMEXT);
$page = max(0, optional_param('page', 0, PARAM_INT)); $pagesize = 100;
$url = new moodle_url('/local/districtupdate/unmatched.php', ['cycleid' => $cycleid, 'q' => $q, 'auth' => $auth, 'page' => $page]);
local_districtupdate_page('Unmatched accounts — '.$cycle->reviewyear, $url);
$error = ''; $selected = []; $destinations = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 require_sesskey();
 $selected = optional_param_array('selected', [], PARAM_INT);
 $destinations = optional_param_array('destinations', [], PARAM_INT);
 $action = required_param('action', PARAM_ALPHA);
 try {
  if ($action === 'assignselected') {
   $target = required_param('bulk_district', PARAM_INT);
   if (!$target || !$selected) { \local_districtupdate\service::fail('Choose a district and select the accounts to assign.'); }
   $destinations = array_fill_keys($selected, $target); $action = 'assign';
  } elseif ($action === 'save') { $action = 'assign'; }
  $count = \local_districtupdate\roster_admin::apply((int)$source->id, required_param('revision', PARAM_INT), $action, $destinations, $selected);
  redirect($url, $count.' account(s) updated. No Moodle or AD accounts were changed.');
 } catch (moodle_exception $e) {
  if ($e->errorcode === 'redirecterrordetected') { throw $e; }
  $error = $e->getMessage();
 }
 $source = $DB->get_record('local_districtupdate_review', ['id' => $source->id]);
}
$params = ['reviewid' => $source->id];
$from = 'FROM {local_districtupdate_roster} r LEFT JOIN {user} u ON u.id = r.userid WHERE r.reviewid = :reviewid';
if ($q !== '') {
 $terms = [];
 foreach (['fullname', 'email', 'username', 'institution'] as $field) {
  $terms[] = $DB->sql_like('r.'.$field, ':q'.$field, false); $params['q'.$field] = '%'.$DB->sql_like_escape($q).'%';
 }
 $from .= ' AND ('.implode(' OR ', $terms).')';
}
if ($auth !== '') { $from .= ' AND u.auth = :auth'; $params['auth'] = $auth; }
$total = $DB->count_records_sql('SELECT COUNT(1) '.$from, $params);
$page = min($page, max(0, (int)ceil($total/$pagesize)-1));
$accounts = $DB->get_records_sql('SELECT r.*, u.auth '.$from.' ORDER BY r.fullname, r.id', $params, $page*$pagesize, $pagesize);
$allreviews = $DB->get_records('local_districtupdate_review', ['cycleid' => $cycleid]); $editable = [];
foreach ($allreviews as $r) { if ($r->districtid && \local_districtupdate\roster_admin::editable($r)) { $editable[$r->districtid] = true; } }
$districts = $DB->get_records('local_districtupdate_dist', ['archived' => 0], 'name'); $options = [0 => 'Choose district…'];
foreach ($districts as $d) { if (isset($editable[$d->id])) { $options[$d->id] = $d->name; } }
$authoptions = ['' => 'All authentication methods'];
foreach ($DB->get_records_sql('SELECT DISTINCT u.auth FROM {local_districtupdate_roster} r JOIN {user} u ON u.id=r.userid WHERE r.reviewid=:reviewid ORDER BY u.auth', ['reviewid' => $source->id]) as $u) { $authoptions[$u->auth] = $u->auth; }
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/districtupdate/manage.php'), 'Manage all reviews').' · '
 .html_writer::link(new moodle_url('/local/districtupdate/excluded.php', ['cycleid' => $cycleid]), 'Removed and ignored accounts');
echo html_writer::tag('p', 'Choose districts on several rows, then save assignments once. Or check accounts to assign them to one district, remove them from this year’s roster, or ignore them in future years. No Moodle or AD accounts are deleted.');
echo html_writer::tag('p', 'If users should match automatically, update the district email domains or aliases and refresh rosters from Moodle. The authentication column helps identify users from other directories. Submitted destinations cannot receive new accounts until reopened.');
if ($error) { echo $OUTPUT->notification(s($error), 'error'); }
echo html_writer::start_tag('form', ['method' => 'get', 'action' => (new moodle_url('/local/districtupdate/unmatched.php'))->out(false)])
 .local_districtupdate_hidden('cycleid', $cycleid)
 .html_writer::tag('label', 'Search name, email, username or Institution', ['for' => 'unmatched-search'])
 .local_districtupdate_input('q', $q, 'search', ['id' => 'unmatched-search'])
 .html_writer::tag('label', 'Authentication method', ['for' => 'auth-filter'])
 .html_writer::select($authoptions, 'auth', $auth, false, ['id' => 'auth-filter'])
 .local_districtupdate_button('Filter', 'filter', 'apply').'</form>';
echo html_writer::tag('p', $total.' unmatched account(s). Showing up to 100 per page.');
echo $OUTPUT->paging_bar($total, $page, $pagesize, $url);
if ($accounts) {
 echo local_districtupdate_formstart($url).local_districtupdate_hidden('revision', $source->revision);
 echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'select-page']).' Select all on this page');
 echo html_writer::tag('div', html_writer::select($options, 'bulk_district', 0, false, ['aria-label' => 'District for selected accounts'])
  .local_districtupdate_button('Assign selected to district', 'action', 'assignselected'), ['class' => 'my-2']);
 $profiles = \local_districtupdate\service::profile_districts(); $rows = [];
 foreach ($accounts as $r) {
  $checkbox = html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'selected[]', 'value' => $r->id,
   'class' => 'select-account', 'checked' => in_array((int)$r->id, $selected, true) ? 'checked' : null, 'aria-label' => 'Select '.$r->fullname]);
  $rows[] = [$checkbox, s($r->fullname).'<br>'.s($r->email).'<br>'.html_writer::tag('small', s($r->username)),
   s($r->auth ?? 'Unavailable'), local_districtupdate_summary(['Institution: '.$r->institution, 'District profile: '.($profiles[$r->userid] ?? '')]),
   html_writer::select($options, 'destinations['.$r->id.']', $destinations[$r->id] ?? 0, false, ['aria-label' => 'District for '.$r->fullname])];
 }
 echo local_districtupdate_table(['Select', 'Account', 'Authentication', 'District information', 'Assign district'], $rows);
 echo local_districtupdate_button('Save district assignments', 'action', 'save');
 echo html_writer::tag('button', 'Remove selected from this year', ['type' => 'submit', 'name' => 'action', 'value' => 'remove', 'class' => 'btn btn-outline-danger m-1',
  'data-confirm' => 'Remove selected entries from this year’s roster? This does not delete Moodle or AD accounts. You can restore them later.']);
 echo html_writer::tag('button', 'Ignore selected in all years', ['type' => 'submit', 'name' => 'action', 'value' => 'ignore', 'class' => 'btn btn-outline-secondary m-1',
  'data-confirm' => 'Ignore these accounts for current and future rosters? They remain in Moodle and AD. You can restore them later.']);
 echo '</form>';
 $PAGE->requires->js_init_code("document.getElementById('select-page').addEventListener('change', function(e) { document.querySelectorAll('.select-account').forEach(function(c) { c.checked=e.target.checked; }); }); document.querySelectorAll('[data-confirm]').forEach(function(b) { b.addEventListener('click', function(e) { if (!window.confirm(b.dataset.confirm)) { e.preventDefault(); } }); });");
}
echo $OUTPUT->paging_bar($total, $page, $pagesize, $url); echo $OUTPUT->footer();
