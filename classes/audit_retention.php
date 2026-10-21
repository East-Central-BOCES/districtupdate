<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Retention applies only to audit entries; operational review state is stored separately. */
final class audit_retention {
 public static function days(): int {
  $days = (int)get_config('local_districtupdate', 'auditretentiondays');
  return in_array($days, [7, 14, 30, 60, 90, 180, 365], true) ? $days : 30;
 }
 public static function purge(?int $now = null): int {
  global $DB;
  $params = ['cutoff' => ($now ?? time()) - self::days() * DAYSECS];
  $count = $DB->count_records_select('local_districtupdate_audit', 'timecreated < :cutoff', $params);
  if ($count) { $DB->delete_records_select('local_districtupdate_audit', 'timecreated < :cutoff', $params); }
  return (int)$count;
 }
}
