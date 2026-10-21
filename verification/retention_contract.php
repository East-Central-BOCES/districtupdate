<?php
// SPDX-License-Identifier: GPL-3.0-or-later
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
use local_districtupdate\audit_retention;
use local_districtupdate\service;
function retention_log(int $when, string $event = 'test', int $rosterid = 0): int {
 global $DB;
 return $DB->insert_record('local_districtupdate_audit', (object)['rosterid' => $rosterid, 'userid' => 20,
  'actorid' => 10, 'event' => $event, 'detail' => '{}', 'timecreated' => $when]);
}
test('audit retention defaults to 30 days even with missing or invalid settings', function() {
 global $CONFIG; check(audit_retention::days() === 30); $CONFIG['auditretentiondays'] = 'invalid'; check(audit_retention::days() === 30);
 $CONFIG['auditretentiondays'] = 0; check(audit_retention::days() === 30);
});
test('cleanup deletes strictly older logs and keeps cutoff recent and future entries', function() {
 global $DB; $now = 1800000000; $cutoff = $now - 30 * DAYSECS;
 $old = retention_log($cutoff - 1); $edge = retention_log($cutoff); $recent = retention_log($now); $future = retention_log($now + DAYSECS);
 check(audit_retention::purge($now) === 1);
 check(!$DB->record_exists('local_districtupdate_audit', ['id' => $old]));
 check(count($DB->tables['local_districtupdate_audit']) === 3);
 foreach ([$edge, $recent, $future] as $id) { check($DB->record_exists('local_districtupdate_audit', ['id' => $id])); }
 check(audit_retention::purge($now) === 0);
});
test('cleanup uses the configured retention period', function() {
 global $CONFIG; $CONFIG['auditretentiondays'] = '7'; $now = 1800000000;
 retention_log($now - 8 * DAYSECS); retention_log($now - 6 * DAYSECS);
 check(audit_retention::days() === 7 && audit_retention::purge($now) === 1);
});
test('cleanup leaves rosters responses and processing state intact', function() {
 global $DB; $r = $DB->tables['local_districtupdate_roster'][1]; $r->status = 'left'; $r->note = 'Keep response'; $r->processstate = 'done';
 $before = serialize([$DB->tables['local_districtupdate_roster'], $DB->tables['local_districtupdate_review']]);
 retention_log(time() - 31 * DAYSECS, 'verified', 1); check(audit_retention::purge() === 1);
 check($before === serialize([$DB->tables['local_districtupdate_roster'], $DB->tables['local_districtupdate_review']]));
});
test('manual district assignments survive after their audit entries expire', function() {
 global $DB, $MANAGER; $MANAGER = true;
 $DB->tables['local_districtupdate_roster'][1]->manualassignment = 1;
 $DB->tables['user'][20]->email = 'unknown@unknown.org'; $DB->tables['user'][20]->institution = '';
 retention_log(time() - 31 * DAYSECS, 'district_assigned', 1); check(audit_retention::purge() === 1);
 $stats = service::refresh_cycle(1); check($stats['preserved'] === 1 && $stats['moved'] === 0);
 check((int)$DB->tables['local_districtupdate_roster'][1]->reviewid === 1);
});
test('expired processing logs do not permit reopening processed reviews', function() {
 global $DB;
 $DB->tables['local_districtupdate_roster'][1]->processstate = 'failed';
 retention_log(time() - 31 * DAYSECS, 'processing_failed', 1); audit_retention::purge();
 expect_error(fn() => service::reopen(1), 'processing was attempted');
});
