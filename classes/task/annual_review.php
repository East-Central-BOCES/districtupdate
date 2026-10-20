<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate\task;
defined('MOODLE_INTERNAL') || die();
final class annual_review extends \core\task\scheduled_task {
 public function get_name(): string { return get_string('taskname', 'local_districtupdate'); }
 public function execute(): void {
  global $DB;
  $c = get_config('local_districtupdate');
  $today = new \DateTimeImmutable('now', \core_date::get_server_timezone_object());
  $year = (int)$today->format('Y');
  $start = $today->setDate($year, (int)($c->startmonth ?? 8), (int)($c->startday ?? 1))->setTime(0, 0);
  if (!empty($c->automatic) && $today >= $start && !$DB->record_exists('local_districtupdate_cycle', ['reviewyear' => $year])) {
   try {
    \local_districtupdate\service::create_cycle($year, time() + max(1, (int)($c->duedays ?? 30)) * DAYSECS);
   } catch (\Throwable $e) { mtrace($e->getMessage()); }
  }
  $interval = max(0, (int)($c->reminddays ?? 7)) * DAYSECS;
  foreach ($DB->get_records_select('local_districtupdate_review', 'districtid > 0 AND submittedat = 0') as $review) {
   $cycle = $DB->get_record('local_districtupdate_cycle', ['id' => $review->cycleid], '*', MUST_EXIST);
   // Past years never generate repeated reminders. Manual invitation remains available to administrators.
   if ((int)$cycle->reviewyear !== $year) { continue; }
   $initial = !empty($c->automatic) && !$review->lastsent;
   $remind = $interval && $review->lastsent && $review->lastsent <= time() - $interval;
   if ($initial || $remind) { \local_districtupdate\service::invite($review->id); }
  }
 }
}
