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
