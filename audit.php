<?php
// SPDX-License-Identifier: GPL-3.0-or-later
require_once(__DIR__.'/../../config.php');
require_once(__DIR__.'/locallib.php');
$PAGE->set_context(context_system::instance());
require_login();
require_capability('local/districtupdate:manage', context_system::instance());
$reviewid = optional_param('reviewid', 0, PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$url = new moodle_url('/local/districtupdate/audit.php', ['reviewid' => $reviewid]);
$title = 'Audit trail'; $params = []; $where = '';
if ($reviewid) {
 $review = $DB->get_record('local_districtupdate_review', ['id' => $reviewid], '*', MUST_EXIST);
 $district = $DB->get_record('local_districtupdate_dist', ['id' => $review->districtid]);
 $title .= ' — '.($district->name ?? 'Unmatched accounts');
 $where = ' WHERE a.rosterid IN (SELECT r.id FROM {local_districtupdate_roster} r WHERE r.reviewid = :reviewid)';
 $params['reviewid'] = $reviewid;
}
local_districtupdate_page($title, $url);
echo $OUTPUT->header();
echo html_writer::link(new moodle_url('/local/districtupdate/manage.php'), 'Review all rosters');
if ($reviewid) { echo ' · '.html_writer::link(new moodle_url('/local/districtupdate/audit.php'), 'All audit activity'); }
echo html_writer::tag('p', 'Activity expires after '.\local_districtupdate\audit_retention::days().' days. Review responses and processing status are retained unless their district is permanently deleted.');
$total = $DB->count_records_sql('SELECT COUNT(*) FROM {local_districtupdate_audit} a'.$where, $params);
$logs = $DB->get_records_sql('SELECT a.* FROM {local_districtupdate_audit} a'.$where.' ORDER BY a.id DESC', $params, $page * 100, 100);
$rows = []; $formatter = local_districtupdate_audit_formatter();
foreach ($logs as $log) {
 $actor = $log->actorid ? $DB->get_record('user', ['id' => $log->actorid]) : null;
 $subject = $log->userid ? $DB->get_record('user', ['id' => $log->userid]) : null;
 [$activity, $summary] = $formatter->describe($log->event, $log->detail);
 $rows[] = [userdate($log->timecreated, get_string('strftimedatetimeshort', 'langconfig')), s($activity),
  s($subject ? fullname($subject) : ''), s($actor ? fullname($actor) : 'Scheduled task'), local_districtupdate_summary($summary)];
}
echo $OUTPUT->paging_bar($total, $page, 100, $url);
echo $rows ? local_districtupdate_table(['When', 'Activity', 'Staff / recipient', 'Updated by', 'Summary'], $rows) : html_writer::tag('p', 'No recent activity.');
echo $OUTPUT->paging_bar($total, $page, 100, $url);
echo $OUTPUT->footer();
