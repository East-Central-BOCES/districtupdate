<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
final class notification_addresses {
 public static function parse(string $value): array {
  $addresses = [];
  foreach (preg_split('/[\r\n,;]+/', $value) as $part) {
   $email = trim($part);
   if ($email === '') { continue; }
   if (!validate_email($email)) { throw new \InvalidArgumentException('Enter valid email addresses only, one per line. Invalid address: '.$email); }
   $addresses[strtolower($email)] = $email;
  }
  return array_values($addresses);
 }
}
