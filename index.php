<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance());
require_login();
if (isguestuser()) { throw new required_capability_exception(context_system::instance(), 'moodle/site:config', 'nopermissions', ''); }
local_districtupdate_page('District staff reviews', new moodle_url('/local/districtupdate/index.php'));
echo $OUTPUT->header();
if (\local_districtupdate\service::manager()) {
 echo html_writer::link(new moodle_url('/local/districtupdate/manage.php'), 'Manage reviews', ['class' => 'btn btn-primary']);
 $reviews = $DB->get_records('local_districtupdate_review', null, 'cycleid DESC, districtid');
} else {
 $reviews = $DB->get_records_sql('SELECT r.* FROM {local_districtupdate_review} r JOIN {local_districtupdate_access} a ON a.districtid = r.districtid
  WHERE a.userid = :userid ORDER BY r.cycleid DESC', ['userid' => $USER->id]);
}
$rows = [];
foreach ($reviews as $review) {
 if (!$review->districtid) { continue; }
 if (!\local_districtupdate\service::manager() && \local_districtupdate\service::reviewer_access_expired($review)) { continue; }
 $district = $DB->get_record('local_districtupdate_dist', ['id' => $review->districtid], '*', MUST_EXIST);
 $cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $review->cycleid], '*', MUST_EXIST);
 $rows[] = [$cycle->reviewyear, s($district->name), userdate($cycle->duedate), $review->submittedat ? 'Submitted' : 'Awaiting review',
  html_writer::link(new moodle_url('/local/districtupdate/review.php', ['id' => $review->id]), 'Open roster')];
}
echo $rows ? local_districtupdate_table(['Year', 'District', 'Due', 'Status', 'Review'], $rows) : $OUTPUT->notification('No district reviews are assigned to your account.', 'info');
echo $OUTPUT->footer();
