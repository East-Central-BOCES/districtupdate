<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Pure district matching and employment rules, also used in executable verification. */
final class rules {
 public static function lines(string $value): array {
  return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', strtolower($value))))));
 }
 public static function district(string $email, string $institution, array $districts, string $profiledistrict = ''): int {
  $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
  $institution = \core_text::strtolower(trim($institution));
  $profiledistrict = \core_text::strtolower(trim($profiledistrict));
  $domains = []; $companies = []; $profiles = [];
  foreach ($districts as $d) {
   if ($domain !== '' && in_array($domain, self::lines($d->domains), true)) { $domains[] = (int)$d->id; }
   $aliases = array_merge([\core_text::strtolower(trim($d->name))], self::lines($d->aliases));
   if ($institution !== '' && in_array($institution, $aliases, true)) { $companies[] = (int)$d->id; }
   if ($profiledistrict !== '' && in_array($profiledistrict, $aliases, true)) { $profiles[] = (int)$d->id; }
  }
  // Ambiguity and conflicting evidence require an administrator to resolve the assignment.
  if (count($domains) > 1 || count($companies) > 1 || count($profiles) > 1) { return 0; }
  $matches = array_unique(array_merge($domains, $companies, $profiles));
  return count($matches) === 1 ? (int)reset($matches) : 0;
 }
 public static function actions(string $status, string $company = ''): array {
  if ($status === 'still') { return []; }
  if (!in_array($status, ['left', 'moved'], true) || ($status === 'moved' && trim($company) === '')) {
   throw new \InvalidArgumentException('Invalid employment response or missing destination district.');
  }
  $actions = ['Add to Basic', 'Remove from Zoom', 'Set Office to No Access'];
  if ($status === 'left') { $actions[] = 'Remove from ECBOCES_Members'; }
  else { $actions[] = 'Set Company to '.$company; }
  return $actions;
 }
}
