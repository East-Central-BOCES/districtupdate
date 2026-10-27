<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php'); require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance()); require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$cycleid = required_param('cycleid', PARAM_INT); $page = max(0, optional_param('page', 0, PARAM_INT));
$cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $cycleid], '*', MUST_EXIST);
$url = new moodle_url('/local/districtupdate/excluded.php', ['cycleid' => $cycleid, 'page' => $page]);
local_districtupdate_page('Removed and ignored accounts — '.$cycle->reviewyear, $url);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 require_sesskey(); \local_districtupdate\roster_admin::restore(optional_param_array('selected', [], PARAM_INT));
 redirect($url, 'Exclusions restored. Refresh the annual roster to include eligible users again.');
}
$where = 'FROM {local_districtupdate_skip} s LEFT JOIN {user} u ON u.id=s.userid WHERE s.cycleid=0 OR s.cycleid=:cycleid';
$total = $DB->count_records_sql('SELECT COUNT(1) '.$where, ['cycleid' => $cycleid]);
$page = min($page, max(0, (int)ceil($total/100)-1));
$records = $DB->get_records_sql('SELECT s.*, u.firstname, u.lastname, u.email '.$where.' ORDER BY s.id DESC', ['cycleid' => $cycleid], $page*100, 100);
echo $OUTPUT->header(); echo html_writer::link(new moodle_url('/local/districtupdate/unmatched.php', ['cycleid' => $cycleid]), 'Back to unmatched accounts');
echo html_writer::tag('p', 'Restoring removes the roster exclusion. Refresh rosters afterward; normal cohort, authentication, district and active-account checks still apply. Historical responses are not changed.');
echo $OUTPUT->paging_bar($total, $page, 100, $url);
if ($records) {
 echo local_districtupdate_formstart($url);
 echo html_writer::tag('label', html_writer::empty_tag('input', ['type' => 'checkbox', 'id' => 'select-excluded']).' Select all on this page');
 $rows = [];
 foreach ($records as $r) {
  $rows[] = [html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'selected[]', 'value' => $r->id, 'aria-label' => 'Restore '.($r->email ?? 'previous user')]),
   s(trim(($r->firstname ?? '').' '.($r->lastname ?? '')) ?: 'Previous Moodle user'), s($r->email ?? ''),
   $r->cycleid ? 'Removed from '.$cycle->reviewyear.' only' : 'Ignored in all years', userdate($r->timecreated)];
 }
 echo local_districtupdate_table(['Select', 'Name', 'Email', 'Exclusion', 'When'], $rows);
 echo local_districtupdate_button('Restore selected', 'action', 'restore').'</form>';
} else { echo html_writer::tag('p', 'No removed or ignored accounts for this review.'); }
$PAGE->requires->js_init_code("var all = document.getElementById('select-excluded'); if (all) { all.addEventListener('change', function() { document.querySelectorAll('input[name=\"selected[]\"]').forEach(function(c) { c.checked=all.checked; }); }); }");
echo $OUTPUT->paging_bar($total, $page, 100, $url); echo $OUTPUT->footer();
