<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Zoom S2S access. Fixed HTTPS endpoints; credentials/tokens never enter audits or previews. */
final class zoom_api {
 private ?string $token = null;
 private object $config;
 public function __construct(private $transport = null) {
  $this->config = get_config('local_districtupdate');
  foreach (['zoomaccountid', 'zoomclientid', 'zoomclientsecret'] as $key) {
   if (empty($this->config->$key)) { service::fail('Configure the Zoom Server-to-Server OAuth credentials first ('.$key.').'); }
  }
 }
 public static function fingerprint(): string {
  $c = get_config('local_districtupdate');
  return hash('sha256', json_encode([$c->zoomaccountid ?? '', $c->zoomclientid ?? '', $c->zoomclientsecret ?? '', !empty($c->zoomlive)]));
 }
 private function http(string $method, string $url, array $headers, string $body = ''): array {
  if ($this->transport) { return ($this->transport)($method, $url, $headers, $body); }
  global $CFG;
  require_once($CFG->libdir.'/filelib.php');
  $curl = new \curl(); $curl->setHeader($headers);
  $options = ['CURLOPT_CUSTOMREQUEST' => $method, 'CURLOPT_CONNECTTIMEOUT' => 10,
   'CURLOPT_TIMEOUT' => 30, 'CURLOPT_FOLLOWLOCATION' => false,
   'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_SSL_VERIFYHOST' => 2];
  $raw = $method === 'POST' ? $curl->post($url, $body, $options) : $curl->get($url, [], $options);
  if ($curl->get_errno()) { service::fail('Zoom connection failed or timed out. Use a fresh preview to check the outcome.'); }
  return ['status' => (int)($curl->get_info()['http_code'] ?? 0), 'data' => json_decode((string)$raw, true)];
 }
 private function request(string $method, string $path): array {
  if ($this->token === null) {
   $r = $this->http('POST', 'https://zoom.us/oauth/token',
    ['Authorization: Basic '.base64_encode($this->config->zoomclientid.':'.$this->config->zoomclientsecret),
     'Content-Type: application/x-www-form-urlencoded'],
    http_build_query(['grant_type' => 'account_credentials', 'account_id' => $this->config->zoomaccountid]));
   if ($r['status'] !== 200 || empty($r['data']['access_token']) || strtolower($r['data']['token_type'] ?? '') !== 'bearer') {
    service::fail('Zoom authentication failed. Check app activation, account credentials and scopes.');
   }
   $this->token = $r['data']['access_token'];
  }
  return $this->http($method, 'https://api.zoom.us/v2'.$path, ['Authorization: Bearer '.$this->token]);
 }
 public function user(string $identity): ?array {
  $r = $this->request('GET', '/users/'.rawurlencode($identity));
  // Only Zoom's documented user-not-found result means absence; permissions/errors never do.
  if (in_array($r['status'], [400, 404], true) && (int)($r['data']['code'] ?? 0) === 1001) { return null; }
  if ($r['status'] !== 200 || !is_array($r['data']) || empty($r['data']['id'])) { $this->failure($r); }
  return $r['data'];
 }
 public function validate(array $user, string $email, string $expectedid = ''): void {
  if (($user['account_id'] ?? '') !== $this->config->zoomaccountid || strcasecmp($user['email'] ?? '', $email) !== 0
    || ($expectedid !== '' && ($user['id'] ?? '') !== $expectedid)) { service::fail('Zoom account, email or user ID does not match the approved account.'); }
  // Standard Member role only. Owners, admins, custom roles and Zoom Rooms require manual handling.
  if ((string)($user['role_id'] ?? '') !== '2' || !in_array((int)($user['type'] ?? 0), [1, 2], true)
    || !in_array($user['status'] ?? '', ['active', 'inactive'], true)) {
   service::fail('Zoom owner, admin, custom-role, pending or special account: handle manually.');
  }
 }
 public function delete(string $id): void {
  if (!get_config('local_districtupdate', 'zoomlive')) { service::fail('Approved Zoom deletion is disabled.'); }
  // Explicit delete, never Zoom's default disassociate. No transfer parameters: delete associated data too.
  $r = $this->request('DELETE', '/users/'.rawurlencode($id).'?action=delete');
  if ($r['status'] !== 204) { $this->failure($r); }
  if ($this->user($id) !== null) { service::fail('Zoom deletion was accepted but account absence could not be verified. Check with a fresh preview.'); }
 }
 private function failure(array $response): never {
  service::fail('Zoom request failed (HTTP '.(int)$response['status'].', code '.(int)($response['data']['code'] ?? 0)
   .'). Check scopes and account restrictions. Rate limits and connection failures require a fresh preview.');
 }
}
