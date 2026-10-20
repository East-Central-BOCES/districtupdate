<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
final class processor {
 public static function preview(int $reviewid, array $ids): array {
  global $DB, $USER;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $review = service::access($reviewid);
  if (!$review->submittedat || !$review->districtid) { service::fail('Only submitted district reviews can be processed.'); }
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) { service::fail('Select at least one account.'); }
  $live = (bool)get_config('local_districtupdate', 'livewrites');
  $directory = $live ? new directory() : null;
  $batch = ['actorid' => $USER->id, 'reviewid' => $reviewid, 'revision' => $review->revision,
   'created' => time(), 'live' => $live, 'config' => directory::config_fingerprint(), 'rows' => []];
  foreach ($ids as $id) {
   $row = $DB->get_record('local_districtupdate_roster', ['id' => $id, 'reviewid' => $reviewid], '*', MUST_EXIST);
   if ($row->status === 'still' || $row->processstate === 'done') { service::fail('An ineligible account was selected. Refresh the list.'); }
   $item = ['row' => $row, 'actions' => rules::actions($row->status, $row->targetcompany), 'state' => null, 'error' => ''];
   if ($directory) {
    try { $item['state'] = $directory->state($row); $directory->validate($row, $item['state']); }
    catch (\Throwable $e) { $item['error'] = $e->getMessage(); }
   }
   $batch['rows'][$id] = $item;
  }
  return $batch;
 }
 public static function process(array $batch): array {
  global $DB, $USER;
  require_capability('local/districtupdate:manage', \context_system::instance());
  if (!$batch['live'] || !get_config('local_districtupdate', 'livewrites') || (int)$batch['actorid'] !== (int)$USER->id
     || time() - $batch['created'] > 900 || !hash_equals($batch['config'], directory::config_fingerprint())) {
   service::fail('Preview expired, belongs to another administrator, or settings changed. Generate a fresh preview.');
  }
  $lock = service::lock('review:'.$batch['reviewid']);
  try {
   $review = service::access($batch['reviewid']);
   if (!$review->submittedat || (int)$review->revision !== (int)$batch['revision']) { service::fail('Review changed. Generate a fresh preview.'); }
   $directory = new directory(); $results = [];
   foreach ($batch['rows'] as $id => $item) {
    if ($item['error'] || !$item['state']) { $results[$id] = 'Skipped: '.$item['error']; continue; }
    $row = $DB->get_record('local_districtupdate_roster', ['id' => $id, 'reviewid' => $review->id], '*', MUST_EXIST);
    if ($row->processstate === 'done') { $results[$id] = 'Already completed'; continue; }
    if ($row->status !== $item['row']->status || $row->userid !== $item['row']->userid
       || $row->targetcompany !== $item['row']->targetcompany || $row->status === 'still') {
     $results[$id] = 'Skipped: response changed'; continue;
    }
    $DB->set_field('local_districtupdate_roster', 'processstate', 'processing', ['id' => $id]);
    service::audit($id, $row->userid, 'processing_started', ['previewcreated' => $batch['created'], 'before' => $item['state'], 'planned' => $item['actions']]);
    try {
     $directory->apply($row, $item['state']);
     $DB->set_field('local_districtupdate_roster', 'processstate', 'done', ['id' => $id]);
     $results[$id] = 'Completed and verified';
    } catch (\Throwable $e) {
     $DB->set_field('local_districtupdate_roster', 'processstate', 'failed', ['id' => $id]);
     service::audit($id, $row->userid, 'processing_failed', ['message' => $e->getMessage()]);
     $results[$id] = $e->getMessage();
    }
   }
   return $results;
  } finally { $lock->release(); }
 }
}
