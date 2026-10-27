<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Real approval-page rendering checks; all fixtures roll back. No mail or AD operations. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') {
 fwrite(STDERR, "Usage: php moodle_audit_display.php /path/to/moodle/config.php --test-site\n"); exit(1);
}
define('CLI_SCRIPT', true);
require $argv[1];
core\session\manager::set_user(get_admin());
class districtupdate_display_rollback extends Exception {}
function du_display_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function du_render_approval(int $id): string {
 global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SESSION;
 $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = ['reviewid' => $id];
 $PAGE = new moodle_page(); ob_start();
 try { require $CFG->dirroot.'/local/districtupdate/approve.php'; } finally { $html = ob_get_clean(); }
 return $html;
}
$tables = ['local_districtupdate_dist', 'local_districtupdate_cycle', 'local_districtupdate_review', 'local_districtupdate_roster', 'local_districtupdate_audit'];
$before = [];
foreach ($tables as $table) { $before[$table] = $DB->count_records($table); }
$fixturetx = $DB->start_delegated_transaction(); $failure = null;
try {
 $tag = bin2hex(random_bytes(6));
 $districtid = $DB->insert_record('local_districtupdate_dist', (object)['name' => 'Display Test '.$tag, 'domains' => '', 'aliases' => '']);
 $year = 2100;
 while ($DB->record_exists('local_districtupdate_cycle', ['reviewyear' => $year])) { $year--; }
 $cycleid = $DB->insert_record('local_districtupdate_cycle', (object)['reviewyear' => $year, 'duedate' => time()+DAYSECS, 'timecreated' => time()]);
 $reviewid = $DB->insert_record('local_districtupdate_review', (object)['cycleid' => $cycleid, 'districtid' => $districtid,
  'revision' => 1, 'submittedby' => get_admin()->id, 'submittedat' => time(), 'lastsent' => 0]);
 $sourceid = $DB->insert_record('local_districtupdate_review', (object)['cycleid' => $cycleid, 'districtid' => 0,
  'revision' => 1, 'submittedby' => 0, 'submittedat' => 0, 'lastsent' => 0]);
 $rowid = $DB->insert_record('local_districtupdate_roster', (object)['reviewid' => $reviewid, 'userid' => get_admin()->id,
  'username' => 'display-fixture', 'fullname' => 'Display Fixture', 'email' => 'display@example.com', 'institution' => 'Other',
  'status' => 'still', 'destination' => 0, 'targetcompany' => '', 'note' => '', 'modifiedby' => 0, 'modifiedat' => 0, 'processstate' => 'pending']);
 $evidence = ['before' => ['reviewid' => $sourceid, 'institution' => 'Other', 'raw_snapshot_marker' => $tag],
  'after' => ['reviewid' => $reviewid, 'institution' => 'Other']];
 \local_districtupdate\service::audit($rowid, get_admin()->id, 'roster_account_refreshed', $evidence);
 $stored = $DB->get_records('local_districtupdate_audit', ['rosterid' => $rowid]); $log = reset($stored);
 $html = du_render_approval($reviewid);
 du_display_assert(str_contains($html, 'No directory changes required.'), 'No-change message missing.');
 du_display_assert(!str_contains($html, 'Preview selected accounts'), 'Empty review showed a preview button.');
 du_display_assert(str_contains($html, 'District: Unmatched accounts → Display Test '.$tag), 'Readable assignment summary missing.');
 du_display_assert(!str_contains($html, 'raw_snapshot_marker') && !str_contains($html, 'roster_account_refreshed'), 'Raw audit data leaked into presentation.');
 du_display_assert(str_contains($html, 'View submitted roster'), 'Submitted roster link missing.');
 echo "PASS: unchanged review shows a clear completion state, readable audit summary and a roster link without raw snapshots or an empty preview.\n";
 $DB->set_field('local_districtupdate_roster', 'status', 'left', ['id' => $rowid]);
 $DB->set_field('local_districtupdate_roster', 'note', '<script>display-test</script>', ['id' => $rowid]);
 $html = du_render_approval($reviewid);
 du_display_assert(str_contains($html, 'Preview selected accounts') && str_contains($html, 'Awaiting approval'), 'Pending changes cannot be selected.');
 du_display_assert(!str_contains($html, '<script>display-test</script>') && str_contains($html, '&lt;script&gt;display-test'), 'Notes were not escaped.');
 $DB->set_field('local_districtupdate_roster', 'processstate', 'done', ['id' => $rowid]);
 $html = du_render_approval($reviewid);
 du_display_assert(str_contains($html, 'All requested directory changes have been completed.') && !str_contains($html, 'Preview selected accounts'), 'Completed review showed a pending preview action.');
 du_display_assert($DB->get_field('local_districtupdate_audit', 'detail', ['id' => $log->id]) === $log->detail, 'Rendering altered stored evidence.');
 $DB->set_field('local_districtupdate_dist', 'archived', 1, ['id' => $districtid]);
 $archivedhtml = du_render_approval($reviewid);
 du_display_assert(str_contains($archivedhtml, 'removed from rostering') && !str_contains($archivedhtml, 'Preview selected accounts'), 'Archived district exposed processing controls.');
 $DB->set_field('local_districtupdate_dist', 'archived', 0, ['id' => $districtid]);
 $DB->delete_records('local_districtupdate_roster', ['id' => $rowid]);
 du_display_assert(str_contains(du_render_approval($reviewid), 'contains no staff accounts'), 'Empty roster was confused with all-Still-Employed.');
 echo "PASS: pending and completed changes render correctly; notes are escaped; raw evidence stays unchanged; empty roster is distinguished.\n";
} catch (Throwable $e) { $failure = $e; }
try { $fixturetx->rollback(new districtupdate_display_rollback()); } catch (districtupdate_display_rollback $expected) {}
if ($failure) { throw $failure; }
foreach ($tables as $table) { du_display_assert($DB->count_records($table) === $before[$table], 'Fixture remained in '.$table); }
echo "PASS: fixtures rolled back; no mail or directory changes.\n";
