<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate\setting;
defined('MOODLE_INTERNAL') || die();
final class notification_emails extends \admin_setting_configtextarea {
 public function validate($data) {
  try { \local_districtupdate\notification_addresses::parse((string)$data); }
  catch (\InvalidArgumentException $e) { return $e->getMessage(); }
  return true;
 }
}
