<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance());
require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$id = required_param('districtid', PARAM_INT);
$district = $DB->get_record('local_districtupdate_dist', ['id' => $id], '*', MUST_EXIST);
$url = new moodle_url('/local/districtupdate/delete.php', ['districtid' => $id]);
$back = new moodle_url('/local/districtupdate/manage.php');
local_districtupdate_page('Delete district — '.$district->name, $url);
$error = ''; $confirmation = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 require_sesskey();
 $confirmation = required_param('confirmation', PARAM_RAW);
 try {
  \local_districtupdate\roster_admin::delete_district($id, $confirmation);
  redirect($back, 'District permanently deleted. Moodle and AD accounts were not changed.');
 } catch (moodle_exception $e) { $error = $e->getMessage(); }
}
$reviews = $DB->count_records('local_districtupdate_review', ['districtid' => $id]);
$staff = $DB->count_records_sql('SELECT COUNT(*) FROM {local_districtupdate_roster} r
 JOIN {local_districtupdate_review} v ON v.id = r.reviewid WHERE v.districtid = :id', ['id' => $id]);
echo $OUTPUT->header();
echo html_writer::link($back, 'Cancel and return to all rosters');
echo $OUTPUT->notification('Permanently delete '.$district->name.'? This deletes its reviewer assignments, '.$reviews.' annual reviews, '.$staff
 .' roster entries, recorded responses, processing history and their account audit entries. This cannot be undone. Moodle and AD accounts are unchanged, and previously applied AD changes are not reversed. Configuration and notification audit entries remain until normal retention expires.', 'warning');
echo html_writer::tag('p', 'Eligible staff may appear as unmatched when rosters are refreshed. Another review referencing this district as a transfer destination must be resolved before deletion.');
if ($error) { echo $OUTPUT->notification(s($error), 'error'); }
echo local_districtupdate_formstart($url).local_districtupdate_hidden('districtid', $id);
echo html_writer::tag('label', 'Type the district name exactly: '.s($district->name), ['for' => 'district-delete-name']);
echo local_districtupdate_input('confirmation', $confirmation, 'text', ['id' => 'district-delete-name', 'required' => 'required', 'autocomplete' => 'off']);
echo html_writer::tag('button', 'Permanently delete district and review history', ['type' => 'submit', 'class' => 'btn btn-danger my-3']).'</form>';
echo $OUTPUT->footer();
