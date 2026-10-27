<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Plain-text templates with a fixed allowlist of metadata, never roster contents. */
final class email_templates {
 public static function defaults(): array {
  return [
   'invitationsubject' => '{year} staff review: {district}',
   'invitationbody' => "Please review the {year} staff roster for {district}. Due: {duedate}\n\n"
    ."Everyone starts as Still Employed. Change anyone who has left or moved to another ECBOCES district. "
    ."You must sign into your assigned Moodle account. Save changes or submit the completed review.\n\n{reviewurl}",
   'adminsubject' => 'District review submitted: {district}',
   'adminbody' => '{reviewurl}',
  ];
 }
 public static function render(string $type, object $district, object $cycle, string $url, array $reviewers = []): array {
  if (!in_array($type, ['invitation', 'admin'], true)) { throw new \coding_exception('Unknown district email template type.'); }
  $reviewerlines = array_map(fn($user) => '- '.trim(preg_replace('/[\r\n]+/', ' ', fullname($user))).' ('.$user->email.')', $reviewers);
  $values = ['{district}' => $district->name, '{year}' => (string)$cycle->reviewyear,
   '{duedate}' => userdate($cycle->duedate), '{reviewurl}' => $url,
   '{reviewers}' => $reviewerlines ? implode("\n", $reviewerlines) : 'No active reviewers assigned.'];
  $defaults = self::defaults(); $rendered = [];
  foreach (['subject', 'body'] as $part) {
   $key = $type.$part;
   $configured = get_config('local_districtupdate', $key);
   $template = $configured === false || trim((string)$configured) === '' ? $defaults[$key] : (string)$configured;
   $rendered[$part] = strtr($template, $values);
  }
  // Keep metadata or edited templates from introducing additional email headers.
  $rendered['subject'] = trim(str_replace(["\r", "\n"], ' ', strip_tags($rendered['subject'])));
  // An edited message must still lead to its protected review/approval page.
  if (!str_contains($rendered['body'], $url)) { $rendered['body'] = rtrim($rendered['body'])."\n\n".$url; }
  return $rendered;
 }
}
