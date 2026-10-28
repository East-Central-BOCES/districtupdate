<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Shared management status; green requires at least one verified directory change. */
final class review_summary {
 public static function state(?object $review, ?object $stats): array {
  if (!$review) { return ['No roster yet', 'light']; }
  if (!$review->submittedat) { return ['Awaiting district review', 'light']; }
  if (!(int)($stats->staff ?? 0)) { return ['Submitted — empty roster', 'warning']; }
  $changed = (int)($stats->changed ?? 0); $done = (int)($stats->completed ?? 0);
  if (!$changed) { return ['Submitted — no changes needed', 'warning']; }
  if ($done === $changed) { return ['Changes pushed — '.$done.' of '.$changed.' completed', 'success']; }
  return ['Submitted — '.$done.' of '.$changed.' changes completed', 'warning'];
 }
}
