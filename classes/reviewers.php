<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
final class reviewers {
 public static function label(object $user): string {
  return fullname($user).' — '.$user->email.' ('.$user->username.')';
 }
 public static function validate_ids(array $ids): array {
  global $DB;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids || count($ids) > 100) { service::fail('Select between 1 and 100 reviewers.'); }
  foreach ($ids as $id) {
   $u = $DB->get_record('user', ['id' => $id, 'deleted' => 0, 'suspended' => 0]);
   if (!$u || isguestuser($u)) { service::fail('A selected reviewer is unavailable. Remove that selection and choose an active Moodle user.'); }
  }
  return $ids;
 }
 public static function search(string $query): array {
  global $DB;
  require_capability('local/districtupdate:manage', \context_system::instance());
  $query = trim($query);
  if (\core_text::strlen($query) < 2) { return []; }
  if (\core_text::strlen($query) > 100) { service::fail('Use a search of 100 characters or fewer.'); }
  $params = []; $clauses = [];
  // Each word may match a different name/email field, e.g. first name plus last name.
  foreach (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) as $i => $word) {
   $parts = [];
   foreach (['firstname', 'lastname', 'email', 'username'] as $field) {
    $key = 'search'.$i.$field;
    $parts[] = $DB->sql_like($field, ':'.$key, false);
    $params[$key] = '%'.$DB->sql_like_escape($word).'%';
   }
   $clauses[] = '('.implode(' OR ', $parts).')';
  }
  $users = $DB->get_records_select('user', 'deleted = 0 AND suspended = 0 AND id > 1 AND '.implode(' AND ', $clauses),
   $params, 'lastname, firstname, id', '*', 0, 25);
  $out = [];
  foreach ($users as $u) {
   if (!isguestuser($u)) { $out[] = ['id' => (int)$u->id, 'label' => self::label($u)]; }
  }
  return $out;
 }
}
