<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance());
require_login();
$id = required_param('id', PARAM_INT);
$review = \local_districtupdate\service::access($id);
$url = new moodle_url('/local/districtupdate/review.php', ['id' => $id]);
$district = $DB->get_record('local_districtupdate_dist', ['id' => $review->districtid]);
$cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $review->cycleid], '*', MUST_EXIST);
local_districtupdate_page(($district ? $district->name : 'Unmatched accounts').' — '.$cycle->reviewyear.' staff review', $url);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 require_sesskey();
 $responses = $_POST['rows'] ?? [];
 if (!is_array($responses)) { \local_districtupdate\service::fail('Invalid roster.'); }
 $responses = clean_param_array($responses, PARAM_RAW, true);
 \local_districtupdate\service::save($id, required_param('revision', PARAM_INT), $responses,
  required_param('action', PARAM_ALPHA) === 'submit');
 redirect($url, 'Review saved.');
}
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/districtupdate/index.php'), 'All my reviews');
if (!$district) { echo $OUTPUT->notification('Assign these accounts through Manage reviews.', 'warning'); echo $OUTPUT->footer(); exit; }
$readonly = $review->submittedat || !empty($district->archived);
if ($review->submittedat) {
 $submitter = $DB->get_record('user', ['id' => $review->submittedby]);
 echo $OUTPUT->notification('Submitted by '.($submitter ? fullname($submitter) : 'a previous reviewer').' on '.userdate($review->submittedat).'.', 'success');
 echo html_writer::tag('p', 'District reviewer access expires on '.userdate(\local_districtupdate\service::reviewer_access_expires_at($review)).'. Administrators retain access.');
} elseif (!empty($district->archived)) {
 echo $OUTPUT->notification('This district has been removed from rostering. Its history is read-only until an administrator restores it.', 'info');
} else {
 echo $OUTPUT->notification('Everyone starts as Still Employed. Change only people who left or moved. '
  .'For a transfer, select a different destination district. Save to share progress, or submit when the whole roster is reviewed. Due: '.userdate($cycle->duedate), 'info');
 echo local_districtupdate_formstart($url).local_districtupdate_hidden('revision', $review->revision);
}
$districts = $DB->get_records_menu('local_districtupdate_dist', ['archived' => 0], 'name', 'id, name');
unset($districts[$review->districtid]);
$destinations = [0 => 'Choose destination…'] + $districts;
$rows = [];
foreach ($DB->get_records('local_districtupdate_roster', ['reviewid' => $id], 'fullname') as $row) {
 $prefix = 'rows['.$row->id.']';
 $status = ['still' => 'Still Employed', 'left' => 'No longer employed with an ECBOCES district', 'moved' => 'Moved to another ECBOCES district'];
 if ($readonly) {
  $response = s($status[$row->status]); $destination = s($row->targetcompany); $notes = s($row->note);
 } else {
  $response = html_writer::select($status, $prefix.'[status]', $row->status, false, ['aria-label' => 'Employment status for '.$row->fullname, 'class' => 'status-choice', 'data-row' => $row->id]);
  $destination = html_writer::tag('span', html_writer::select($destinations, $prefix.'[destination]', $row->destination, false,
   ['aria-label' => 'Destination district for '.$row->fullname]), ['id' => 'destination-'.$row->id]);
  $notes = html_writer::tag('textarea', s($row->note), ['name' => $prefix.'[note]', 'maxlength' => 2000,
   'rows' => 2, 'aria-label' => 'Notes for '.$row->fullname, 'class' => 'form-control']);
 }
 $editor = $row->modifiedby ? $DB->get_record('user', ['id' => $row->modifiedby]) : null;
 $rows[] = [s($row->fullname).'<br>'.s($row->email), $response, $destination, $notes,
  $editor ? s(fullname($editor)).'<br>'.userdate($row->modifiedat) : 'Prefilled'];
}
echo local_districtupdate_table(['Staff member', 'Employment status', 'Destination district', 'Notes', 'Last changed by'], $rows);
if (!$readonly) {
 echo local_districtupdate_button('Save progress', 'action', 'save').local_districtupdate_button('Submit completed review', 'action', 'submit').html_writer::end_tag('form');
 // Inline enhancement only; the destination select remains usable without JavaScript.
 $PAGE->requires->js_init_code("document.querySelectorAll('.status-choice').forEach(function(s) { function toggle() { var d = document.getElementById('destination-' + s.dataset.row); d.hidden = s.value !== 'moved'; d.querySelector('select').required = s.value === 'moved'; } s.addEventListener('change', toggle); toggle(); });");
}
echo $OUTPUT->footer();
