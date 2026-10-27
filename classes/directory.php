<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Delegated AD access over verified LDAPS. No changes to Moodle authentication settings. */
final class directory {
 private $connection;
 private object $config;
 private array $groups = [];
 private string $domainbase;
 private array $userbases;
 public static function user_bases(string $value): array {
  $bases = [];
  // Commas and semicolons can occur inside DNs; only line breaks separate bases.
  foreach (preg_split('/\r\n|\r|\n/', $value) as $line) {
   $dn = trim($line);
   if ($dn !== '') { $bases[strtolower($dn)] = $dn; }
  }
  if (!$bases) { service::fail('Enter at least one AD user search base DN.'); }
  return array_values($bases);
 }
 public function __construct() {
  $this->config = get_config('local_districtupdate');
  if (!extension_loaded('ldap')) { service::fail('The Moodle server needs the PHP LDAP extension.'); }
  $c = $this->config;
  $this->userbases = self::user_bases((string)($c->userbase ?? ''));
  foreach (['ldapurl', 'userbase', 'binddn', 'bindpassword', 'membersdn', 'zoomdn', 'basicdn'] as $key) {
   if (empty($c->$key)) { service::fail('LDAP configuration is incomplete: '.$key); }
  }
  $url = parse_url($c->ldapurl);
  if (!$url || ($url['scheme'] ?? '') !== 'ldaps' || empty($url['host'])
      || isset($url['user']) || isset($url['pass']) || !empty($url['path']) || isset($url['query']) || isset($url['fragment'])) {
   service::fail('Use a single ldaps://host:port URL without a path, credentials or query.');
  }
  $attr = $c->identityattribute ?? 'samaccountname';
  if (!in_array($attr, ['samaccountname', 'userprincipalname'], true)) { service::fail('Unsupported username attribute.'); }
  $dns = array_map(fn($v) => strtolower(trim($v)), [$c->membersdn, $c->zoomdn, $c->basicdn]);
  if (count(array_unique($dns)) !== 3) { service::fail('The three AD groups must have different DNs.'); }
  // Certificate verification must never be disabled to make a connection succeed.
  if (!ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND)) { service::fail('Cannot enforce LDAPS certificate verification.'); }
  $this->connection = ldap_connect($c->ldapurl);
  if (!$this->connection) { service::fail('Cannot initialize LDAPS.'); }
  ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);
  ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);
  ldap_set_option($this->connection, LDAP_OPT_NETWORK_TIMEOUT, 8);
  ldap_set_option($this->connection, LDAP_OPT_TIMELIMIT, 8);
  if (!@ldap_bind($this->connection, $c->binddn, $c->bindpassword)) {
   service::fail('LDAPS bind failed. Check server reachability, certificate trust and the delegated service account.');
  }
  $root = $this->one(@ldap_read($this->connection, '', '(objectClass=*)', ['defaultNamingContext']));
  $this->domainbase = $root['defaultnamingcontext'][0] ?? '';
  if ($this->domainbase === '') { service::fail('The server did not return an AD defaultNamingContext.'); }
  foreach (['members', 'zoom', 'basic'] as $key) {
   $dn = $c->{$key.'dn'};
   $entry = $this->one(@ldap_read($this->connection, $dn, '(objectClass=group)', ['objectGUID', 'objectSid', 'groupType']));
   $sid = $entry['objectsid'][0] ?? '';
   if (strlen($sid) < 4 || empty($entry['objectguid'][0])) { service::fail('An AD group cannot be uniquely identified: '.$key); }
   $this->groups[$key] = ['dn' => $entry['dn'], 'guid' => bin2hex($entry['objectguid'][0]),
    'rid' => unpack('V', substr($sid, -4))[1]];
  }
 }
 public function __destruct() {
  if ($this->connection) { @ldap_unbind($this->connection); }
 }
 private function one($result): array {
  if (!$result) { service::fail('AD search failed or exceeded its limit.'); }
  $entries = ldap_get_entries($this->connection, $result);
  if (!$entries || $entries['count'] !== 1) { service::fail('Expected exactly one AD account or group; no changes were made for this user.'); }
  return $entries[0];
 }
 private function compare(string $dn, string $attribute, string $value): bool {
  $result = @ldap_compare($this->connection, $dn, $attribute, $value);
  if ($result === -1 || ($result === false && !in_array(ldap_errno($this->connection), [0, 5, 16], true))) {
   service::fail('Unable to verify AD group membership.');
  }
  return $result === true;
 }
 public function state(object $row): array {
  global $DB;
  $user = $DB->get_record('user', ['id' => $row->userid, 'deleted' => 0], '*', MUST_EXIST);
  if (!in_array($user->auth, service::auth_methods(), true) || $user->username !== $row->username || strcasecmp($user->email, $row->email) !== 0
      || is_siteadmin($user->id) || service::excluded($user->username)) {
   service::fail('Moodle account identity, authentication or exclusion status changed. Review this account manually.');
  }
  $attr = $this->config->identityattribute ?? 'samaccountname';
  $filter = '(&(objectCategory=person)(objectClass=user)('.$attr.'='.ldap_escape($row->username, '', LDAP_ESCAPE_FILTER).'))';
  $matches = [];
  foreach ($this->userbases as $base) {
   $result = @ldap_search($this->connection, $base, $filter,
    ['objectGUID', $attr, 'mail', 'company', 'physicalDeliveryOfficeName', 'primaryGroupID', 'objectSid', 'adminCount'], 0, 2, 8);
   $code = 0;
   if (!$result || !ldap_parse_result($this->connection, $result, $code) || $code !== 0) {
    service::fail('AD user search failed or was incomplete for base: '.$base.'. Check the DN and read permissions.');
   }
   $entries = ldap_get_entries($this->connection, $result);
   if (!$entries || $entries['count'] > 1) { service::fail('AD user search did not return a unique account for base: '.$base); }
   if ($entries['count'] === 0) { continue; }
   $candidate = $entries[0];
   if (empty($candidate['objectguid'][0])) { service::fail('An AD user search result is missing its object GUID.'); }
   $guid = bin2hex($candidate['objectguid'][0]);
   if (isset($matches[$guid]) && $matches[$guid] !== $candidate) {
    service::fail('AD account changed during the search. Generate a fresh preview.');
   }
   $matches[$guid] = $candidate;
  }
  if (count($matches) !== 1) { service::fail('Expected exactly one AD account across all configured user search bases. Review the username and search bases.'); }
  $entry = reset($matches);
  if (empty($entry['objectguid'][0]) || empty($entry['mail'][0]) || strcasecmp($entry['mail'][0], $row->email) !== 0
      || strcasecmp($entry[$attr][0] ?? '', $row->username) !== 0) {
   service::fail('The AD identity or email does not match the annual roster. Review this account manually.');
  }
  if (($entry['admincount'][0] ?? '0') === '1') { service::fail('Protected AD accounts require manual handling.'); }
  $state = ['dn' => $entry['dn'], 'guid' => bin2hex($entry['objectguid'][0]), 'email' => $entry['mail'][0],
   'company' => $entry['company'][0] ?? '', 'office' => $entry['physicaldeliveryofficename'][0] ?? '', 'groups' => []];
  foreach ($this->groups as $name => $group) {
   $direct = $this->compare($group['dn'], 'member', $state['dn']);
   $membership = '(memberOf:1.2.840.113556.1.4.1941:='.ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER).')';
   $result = @ldap_read($this->connection, $state['dn'], $membership, ['objectGUID']);
   if (!$result) { service::fail('Cannot verify inherited AD group membership.'); }
   $primary = (int)($entry['primarygroupid'][0] ?? 0) === (int)$group['rid'];
   $inheritedfilter = '(&(objectClass=group)(member='.ldap_escape($state['dn'], '', LDAP_ESCAPE_FILTER).')'
    .'(!(distinguishedName='.ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER).'))'
    .'(memberOf:1.2.840.113556.1.4.1941:='.ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER).'))';
   $inheritedresult = @ldap_search($this->connection, $this->domainbase, $inheritedfilter, ['objectGUID'], 0, 0, 8);
   if (!$inheritedresult) { service::fail('Cannot verify alternate AD membership paths.'); }
   $inherited = ldap_count_entries($this->connection, $inheritedresult) > 0;
   $sid = $entry['objectsid'][0] ?? '';
   if (strlen($sid) < 4 || empty($entry['primarygroupid'][0])) { service::fail('Cannot verify the AD primary group.'); }
   $primarysid = substr($sid, 0, -4).pack('V', (int)$entry['primarygroupid'][0]);
   $primaryfilter = '(&(objectClass=group)(objectSid='.ldap_escape($primarysid, '', LDAP_ESCAPE_FILTER).')'
    .'(|(distinguishedName='.ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER).')'
    .'(memberOf:1.2.840.113556.1.4.1941:='.ldap_escape($group['dn'], '', LDAP_ESCAPE_FILTER).')))';
   $primaryresult = @ldap_search($this->connection, $this->domainbase, $primaryfilter, ['objectGUID'], 0, 0, 8);
   if (!$primaryresult) { service::fail('Cannot verify inherited primary-group access.'); }
   $primarypath = ldap_count_entries($this->connection, $primaryresult) > 0;
   $state['groups'][$name] = ['guid' => $group['guid'], 'direct' => $direct, 'inherited' => $inherited,
    'effective' => $primarypath || $primary || ldap_count_entries($this->connection, $result) > 0,
    'primary' => $primary || $primarypath];
  }
  return $state;
 }
 public static function fingerprint(array $state): string { return hash('sha256', json_encode($state)); }
 public static function config_fingerprint(): string {
  $c = get_config('local_districtupdate'); $values = [];
  foreach (['ldapurl', 'userbase', 'binddn', 'bindpassword', 'membersdn', 'zoomdn', 'basicdn', 'identityattribute', 'livewrites', 'excluded', 'authmethods'] as $key) {
   $values[$key] = $c->$key ?? '';
  }
  return hash('sha256', json_encode($values));
 }
 public function validate(object $row, array $state): void {
  rules::actions($row->status, $row->targetcompany);
  foreach ($row->status === 'left' ? ['zoom', 'members'] : ['zoom'] as $name) {
   $group = $state['groups'][$name];
   if ($group['primary'] || $group['inherited'] || ($group['effective'] && !$group['direct'])) {
    service::fail('The '.$name.' membership is inherited or primary; resolve it manually rather than changing a parent group.');
   }
  }
  // A completed transfer may have changed Company during a previous partial attempt.
  $allowed = [\core_text::strtolower(trim($row->institution))];
  if ($row->status === 'moved') { $allowed[] = \core_text::strtolower(trim($row->targetcompany)); }
  if (!in_array(\core_text::strtolower(trim($state['company'])), $allowed, true)) {
   service::fail('AD Company changed since this roster was created. Review this account manually.');
  }
 }
 public function apply(object $row, array $expected): void {
  if (!get_config('local_districtupdate', 'livewrites')) { service::fail('AD writes are disabled.'); }
  $state = $this->state($row);
  if (!hash_equals(self::fingerprint($expected), self::fingerprint($state))) {
   service::fail('AD state changed since the preview. Generate a fresh preview.');
  }
  $this->validate($row, $state);
  // Calls span multiple AD entries. They are logged individually and are safely retryable, not a transaction.
  $ops = [];
  if (!$state['groups']['basic']['direct']) {
   $ops[] = ['Add to Basic', fn() => @ldap_mod_add($this->connection, $this->groups['basic']['dn'], ['member' => $state['dn']])];
  }
  if ($state['groups']['zoom']['direct']) {
   $ops[] = ['Remove from Zoom', fn() => @ldap_mod_del($this->connection, $this->groups['zoom']['dn'], ['member' => $state['dn']])];
  }
  $attrs = ['physicalDeliveryOfficeName' => 'No Access'];
  if ($row->status === 'moved') { $attrs['company'] = $row->targetcompany; }
  $ops[] = ['Set Office'.($row->status === 'moved' ? ' and Company' : ''),
   fn() => @ldap_mod_replace($this->connection, $state['dn'], $attrs)];
  if ($row->status === 'left' && $state['groups']['members']['direct']) {
   $ops[] = ['Remove from ECBOCES_Members', fn() => @ldap_mod_del($this->connection, $this->groups['members']['dn'], ['member' => $state['dn']])];
  }
  foreach ($ops as [$label, $call]) {
   service::audit($row->id, $row->userid, 'action_started', ['action' => $label]);
   if (!$call()) {
    service::audit($row->id, $row->userid, 'action_failed', ['action' => $label, 'ldapcode' => ldap_errno($this->connection)]);
    service::fail('AD action failed: '.$label.'. Earlier successful actions remain applied; inspect the audit and retry with a fresh preview.');
   }
   service::audit($row->id, $row->userid, 'action_succeeded', ['action' => $label]);
  }
  $after = $this->state($row);
  if (!$after['groups']['basic']['direct'] || $after['groups']['zoom']['effective']
      || $after['office'] !== 'No Access'
      || ($row->status === 'left' && $after['groups']['members']['effective'])
      || ($row->status === 'moved' && $after['company'] !== $row->targetcompany)) {
   service::fail('Post-change verification failed. Inspect the audit and directory before retrying.');
  }
  service::audit($row->id, $row->userid, 'verified', ['accountguid' => $after['guid'], 'actions' => rules::actions($row->status, $row->targetcompany)]);
 }
}
