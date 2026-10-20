<?php
// SPDX-License-Identifier: GPL-3.0-or-later
 defined('MOODLE_INTERNAL') || die();
function local_districtupdate_extend_navigation(global_navigation $navigation): void {
 global $USER, $DB;
 if (isloggedin() && !isguestuser() && (has_capability('local/districtupdate:manage', context_system::instance())
   || $DB->record_exists('local_districtupdate_access', ['userid' => $USER->id]))) {
  $navigation->add(get_string('pluginname', 'local_districtupdate'), new moodle_url('/local/districtupdate/index.php'),
   navigation_node::TYPE_CUSTOM);
 }
}
