<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate\task;
defined('MOODLE_INTERNAL') || die();
final class cleanup_audit extends \core\task\scheduled_task {
 public function get_name(): string { return get_string('cleanuptaskname', 'local_districtupdate'); }
 public function execute(): void {
  $removed = \local_districtupdate\audit_retention::purge();
  mtrace('Removed '.$removed.' expired district-update audit entries; retention '
   .\local_districtupdate\audit_retention::days().' days.');
 }
}
