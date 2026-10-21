<?php
// SPDX-License-Identifier: GPL-3.0-or-later
defined('MOODLE_INTERNAL') || die();
function xmldb_local_districtupdate_upgrade(int $oldversion): bool {
 global $DB;
 if ($oldversion < 2026102004) {
  $dbman = $DB->get_manager();
  $table = new xmldb_table('local_districtupdate_roster');
  $field = new xmldb_field('manualassignment', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'processstate');
  if (!$dbman->field_exists($table, $field)) { $dbman->add_field($table, $field); }
  // Preserve existing administrator assignments before any audit entries can expire.
  $DB->execute("UPDATE {local_districtupdate_roster} SET manualassignment = 1
   WHERE id IN (SELECT rosterid FROM {local_districtupdate_audit} WHERE event = :event)", ['event' => 'district_assigned']);
  $table = new xmldb_table('local_districtupdate_audit');
  $index = new xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
  if (!$dbman->index_exists($table, $index)) { $dbman->add_index($table, $index); }
  upgrade_plugin_savepoint(true, 2026102004, 'local', 'districtupdate');
 }
 return true;
}
