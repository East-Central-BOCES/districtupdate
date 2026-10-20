<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate\privacy;
defined('MOODLE_INTERNAL') || die();
use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
final class provider implements \core_privacy\local\metadata\provider,
 \core_privacy\local\request\plugin\provider, \core_privacy\local\request\core_userlist_provider {
 private const FIELDS = [
  'local_districtupdate_access' => ['userid'],
  'local_districtupdate_review' => ['submittedby'],
  'local_districtupdate_roster' => ['userid', 'modifiedby'],
  'local_districtupdate_audit' => ['userid', 'actorid'],
 ];
 public static function get_metadata(collection $collection): collection {
  $fields = [
   'local_districtupdate_access' => ['districtid','userid'],
   'local_districtupdate_review' => ['cycleid','districtid','submittedby','submittedat','lastsent'],
   'local_districtupdate_roster' => ['reviewid','userid','username','fullname','email','institution','status','destination','targetcompany','note','modifiedby','modifiedat','processstate'],
   'local_districtupdate_audit' => ['rosterid','userid','actorid','event','detail','timecreated'],
  ];
  foreach ($fields as $table => $names) { $collection->add_database_table($table, array_fill_keys($names, 'privacy:field'), 'privacy:metadata'); }
  $collection->add_external_location_link('activedirectory', ['username' => 'privacy:field',
   'company' => 'privacy:field', 'office' => 'privacy:field', 'groups' => 'privacy:field'], 'privacy:ldap');
  return $collection;
 }
 public static function get_contexts_for_userid(int $userid): contextlist {
  global $DB;
  $list = new contextlist();
  foreach (self::FIELDS as $table => $fields) {
   foreach ($fields as $field) {
    if ($DB->record_exists($table, [$field => $userid])) { $list->add_system_context(); return $list; }
   }
  }
  return $list;
 }
 public static function get_users_in_context(userlist $userlist): void {
  if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) { return; }
  foreach (self::FIELDS as $table => $fields) {
   foreach ($fields as $field) { $userlist->add_from_sql('userid', 'SELECT '.$field.' AS userid FROM {'.$table.'} WHERE '.$field.' > 0', []); }
  }
 }
 public static function export_user_data(approved_contextlist $contextlist): void {
  global $DB;
  if (!in_array(\context_system::instance()->id, $contextlist->get_contextids())) { return; }
  $userid = $contextlist->get_user()->id;
  foreach (self::FIELDS as $table => $fields) {
   $conditions = implode(' OR ', array_map(fn($f) => $f.' = ?', $fields));
   $rows = array_values($DB->get_records_select($table, $conditions, array_fill(0, count($fields), $userid)));
   writer::with_context(\context_system::instance())->export_data(['District staff review', $table], (object)['records' => $rows]);
  }
 }
 public static function delete_data_for_all_users_in_context(\context $context): void {
  global $DB;
  if ($context->contextlevel !== CONTEXT_SYSTEM) { return; }
  $locks = [];
  try {
   foreach ($DB->get_records('local_districtupdate_review', null, 'id') as $review) { $locks[] = \local_districtupdate\service::lock('review:'.$review->id); }
   $tx = $DB->start_delegated_transaction();
   foreach (['local_districtupdate_audit', 'local_districtupdate_roster', 'local_districtupdate_access', 'local_districtupdate_review', 'local_districtupdate_cycle', 'local_districtupdate_dist'] as $table) { $DB->delete_records($table); }
   $tx->allow_commit();
  } finally { foreach ($locks as $lock) { $lock->release(); } }
 }
 public static function delete_data_for_user(approved_contextlist $contextlist): void {
  if (in_array(\context_system::instance()->id, $contextlist->get_contextids())) { self::erase((int)$contextlist->get_user()->id); }
 }
 public static function delete_data_for_users(approved_userlist $userlist): void {
  if ($userlist->get_context()->contextlevel === CONTEXT_SYSTEM) { foreach ($userlist->get_userids() as $id) { self::erase((int)$id); } }
 }
 private static function erase(int $userid): void {
  global $DB;
  $locks = [];
  try {
   // Match the same locks used by reviewer saves and directory processing, and invalidate frozen previews.
   foreach ($DB->get_records('local_districtupdate_review', null, 'id') as $review) { $locks[] = \local_districtupdate\service::lock('review:'.$review->id); }
   $tx = $DB->start_delegated_transaction();
   $DB->delete_records('local_districtupdate_access', ['userid' => $userid]);
   $DB->delete_records('local_districtupdate_audit', ['userid' => $userid]);
   $DB->set_field('local_districtupdate_audit', 'detail', '{}', ['actorid' => $userid]);
   $DB->set_field('local_districtupdate_audit', 'actorid', 0, ['actorid' => $userid]);
   $DB->delete_records('local_districtupdate_roster', ['userid' => $userid]);
   $DB->set_field('local_districtupdate_roster', 'note', '', ['modifiedby' => $userid]);
   $DB->set_field('local_districtupdate_roster', 'modifiedby', 0, ['modifiedby' => $userid]);
   $DB->set_field('local_districtupdate_review', 'submittedby', 0, ['submittedby' => $userid]);
   foreach ($DB->get_records('local_districtupdate_review') as $review) {
    $DB->set_field('local_districtupdate_review', 'revision', $review->revision + 1, ['id' => $review->id]);
   }
   $tx->allow_commit();
  } finally { foreach ($locks as $lock) { $lock->release(); } }
 }
}
