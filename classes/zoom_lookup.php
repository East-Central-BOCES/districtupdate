<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Short-lived display results only; never authorizes deletion or changes processing state. */
final class zoom_lookup {
 public static function results(): array {
  global $SESSION, $USER;
  $cache = $SESSION->local_districtupdate_zoomlookups ?? [];
  if (($cache['actorid'] ?? 0) !== (int)$USER->id || ($cache['config'] ?? '') !== zoom_api::fingerprint()) { return []; }
  return array_filter($cache['rows'] ?? [], fn($row) => $row['checked'] >= time() - 1800 && $row['checked'] <= time());
 }
 public static function remember(array $batch): void {
  global $SESSION, $USER;
  if ((int)($batch['actorid'] ?? 0) !== (int)$USER->id || ($batch['config'] ?? '') !== zoom_api::fingerprint()
    || ($batch['created'] ?? 0) < time() - 1800 || ($batch['created'] ?? 0) > time()) { return; }
  $rows = self::results();
  foreach ($batch['rows'] as $id => $item) {
   // Do not replace a newer observation when reusing a previously generated preview.
   if (($rows[$id]['checked'] ?? 0) > $batch['created']) { continue; }
   $rows[$id] = ['state' => $item['error'] ? 'blocked' : ($item['zoom'] ? 'found' : 'absent'), 'checked' => $batch['created']];
  }
  uasort($rows, fn($a, $b) => $a['checked'] <=> $b['checked']);
  $SESSION->local_districtupdate_zoomlookups = ['actorid' => (int)$USER->id, 'config' => zoom_api::fingerprint(),
   'rows' => array_slice($rows, -2000, null, true)];
 }
 public static function forget(array $ids): void {
  global $SESSION;
  foreach ($ids as $id) { unset($SESSION->local_districtupdate_zoomlookups['rows'][$id]); }
 }
 /** Create a bounded snapshot covering every page in the selected district/year, independent of the display filter. */
 public static function scan_start(int $reviewid = 0, int $cycleid = 0): array {
  global $DB, $SESSION, $USER;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $params = ['status' => 'left'];
  $sql = "SELECT r.id FROM {local_districtupdate_roster} r
   JOIN {local_districtupdate_review} v ON v.id = r.reviewid
   JOIN {local_districtupdate_dist} d ON d.id = v.districtid
   WHERE r.status = :status AND v.submittedat > 0 AND d.archived = 0 AND r.zoomstate <> 'done'";
  if ($reviewid) { $sql .= ' AND v.id = :reviewid'; $params['reviewid'] = $reviewid; }
  if ($cycleid) { $sql .= ' AND v.cycleid = :cycleid'; $params['cycleid'] = $cycleid; }
  $rows = $DB->get_records_sql($sql.' ORDER BY r.id', $params, 0, 2001);
  if (count($rows) > 2000) { service::fail('Choose a district or annual review to check at most 2000 departures per scan.'); }
  $ids = array_map('intval', array_keys($rows));
  self::forget($ids);
  unset($SESSION->local_districtupdate_zoom);
  $SESSION->local_districtupdate_zoomscan = ['id' => bin2hex(random_bytes(20)), 'actorid' => (int)$USER->id,
   'config' => zoom_api::fingerprint(), 'created' => time(), 'reviewid' => $reviewid, 'cycleid' => $cycleid,
   'ids' => $ids, 'offset' => 0, 'found' => 0, 'absent' => 0, 'blocked' => 0];
  return self::scan_status($reviewid, $cycleid);
 }
 public static function scan_status(int $reviewid = 0, int $cycleid = 0): ?array {
  global $SESSION, $USER;
  $run = $SESSION->local_districtupdate_zoomscan ?? null;
  if (!$run || $run['actorid'] !== (int)$USER->id || $run['config'] !== zoom_api::fingerprint()
    || $run['created'] < time() - 1800 || $run['created'] > time()
    || $run['reviewid'] !== $reviewid || $run['cycleid'] !== $cycleid) { return null; }
  return ['runid' => $run['id'], 'total' => count($run['ids']), 'checked' => $run['offset'],
   'found' => $run['found'], 'absent' => $run['absent'], 'blocked' => $run['blocked'], 'done' => $run['offset'] >= count($run['ids'])];
 }
 /** One short, read-only chunk; no deletion preview token or processing status is produced. */
 public static function scan_step(string $runid, int $reviewid = 0, int $cycleid = 0, ?zoom_api $api = null): array {
  global $DB, $SESSION;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $status = self::scan_status($reviewid, $cycleid);
  if (!$status || !hash_equals($status['runid'], $runid)) { service::fail('Zoom check expired or settings changed. Start a new check.'); }
  if ($status['done']) { return $status; }
  $run = $SESSION->local_districtupdate_zoomscan;
  $ids = array_slice($run['ids'], $run['offset'], 5);
  $batch = null; $message = ''; $processed = 0;
  foreach ($ids as $id) {
   if (!$DB->record_exists('local_districtupdate_roster', ['id' => $id])) { $run['blocked']++; $processed++; continue; }
   $api = $api ?? new zoom_api();
   // Individual previews avoid treating historical rows for the same person as duplicate deletion selections.
   $part = zoom_cleanup::preview([$id], $api);
   if ($batch === null) { $batch = $part; } else { $batch['rows'] += $part['rows']; }
   $item = $part['rows'][$id]; $processed++;
   $state = $item['error'] ? 'blocked' : ($item['zoom'] ? 'found' : 'absent'); $run[$state]++;
   if ($item['error'] && preg_match('/^Zoom (authentication|request|connection)/', $item['error'])) {
    $message = $item['error']; break;
   }
  }
  if ($batch) { self::remember($batch); }
  $run['offset'] += $processed;
  $SESSION->local_districtupdate_zoomscan = $run;
  $status = self::scan_status($reviewid, $cycleid);
  if ($message !== '') { $status['paused'] = true; $status['message'] = $message; }
  return $status;
 }
 public static function options(): array {
  return ['all' => 'All departed users', 'found' => 'Found in Zoom', 'absent' => 'Not found in Zoom',
   'unknown' => 'Not checked recently', 'blocked' => 'Check needs attention'];
 }
 public static function label(string $state): string {
  return ['found' => 'Found in Zoom', 'absent' => 'Not found in Zoom', 'blocked' => 'Check needs attention'][$state] ?? 'Not checked recently';
 }
 /** Database filtering is applied before pagination, including results checked on previous pages. */
 public static function condition(string $filter): array {
  global $DB;
  if ($filter === 'all') { return ['', []]; }
  if (!array_key_exists($filter, self::options())) { service::fail('Unknown Zoom availability filter.'); }
  $results = self::results();
  $ids = $filter === 'unknown' ? array_keys($results)
   : array_keys(array_filter($results, fn($row) => $row['state'] === $filter));
  if (!$ids) { return [$filter === 'unknown' ? '' : ' AND 1 = 0', []]; }
  [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'zoomlookup', $filter !== 'unknown');
  return [' AND r.id '.$in, $params];
 }
}
