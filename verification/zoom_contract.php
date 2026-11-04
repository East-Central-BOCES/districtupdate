<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\zoom_api;
use local_districtupdate\zoom_cleanup;
function zoom_fixture(): void {
 global $DB, $MANAGER, $CONFIG, $ZU, $ZCALLS, $ZFAIL, $ZGETFAIL;
 $MANAGER = true; $CONFIG += ['zoomaccountid' => 'account-one', 'zoomclientid' => 'client-one', 'zoomclientsecret' => 'secret-one', 'zoomlive' => 1];
 $DB->tables['local_districtupdate_review'][1]->submittedat = time();
 $r = $DB->tables['local_districtupdate_roster'][1]; $r->status = 'left'; $r->processstate = 'done';
 $r->zoomstate = 'pending'; $r->zoomid = null; $r->zoomactor = 0; $r->zoomtime = 0;
 $ZU = ['id' => 'zoom-one', 'account_id' => 'account-one', 'email' => 'person@north.org', 'role_id' => '2', 'status' => 'active', 'type' => 2];
 $ZCALLS = []; $ZFAIL = false; $ZGETFAIL = false;
}
function zoom_fake(): zoom_api {
 return new zoom_api(function($method, $url, $headers, $body) {
  global $ZU, $ZCALLS, $ZFAIL, $ZGETFAIL;
  $ZCALLS[] = [$method, $url, $body];
  if ($method === 'POST') { return ['status' => 200, 'data' => ['access_token' => 'fake-token', 'token_type' => 'bearer']]; }
  if ($method === 'DELETE') {
   if ($ZFAIL) { throw new RuntimeException('Simulated uncertain timeout'); }
   $ZU = null; return ['status' => 204, 'data' => null];
  }
  if ($ZGETFAIL) { return ['status' => 403, 'data' => ['code' => 124, 'message' => 'DO NOT DISPLAY RAW API RESPONSE']]; }
  $identity = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));
  if ($ZU && in_array($identity, [$ZU['id'], $ZU['email']], true)) { return ['status' => 200, 'data' => $ZU]; }
  return ['status' => 404, 'data' => ['code' => 1001]];
 });
}
test('Zoom preview matches departed users after AD completion and never deletes', function() {
 global $ZCALLS; zoom_fixture(); $batch = zoom_cleanup::preview([1], zoom_fake());
 check($batch['rows'][1]['zoom']['id'] === 'zoom-one' && $batch['rows'][1]['error'] === '');
 check(!array_filter($ZCALLS, fn($c) => $c[0] === 'DELETE'));
 check(!str_contains(json_encode($batch), 'secret-one') && !str_contains(json_encode($batch), 'fake-token'));
});
test('Zoom cleanup permanently deletes without transfer and leaves AD status unchanged', function() {
 global $DB, $ZCALLS; zoom_fixture(); $api = zoom_fake(); $batch = zoom_cleanup::preview([1], $api);
 $result = zoom_cleanup::process($batch, $api); check($result[1] === 'Deleted and verified');
 check($DB->tables['local_districtupdate_roster'][1]->processstate === 'done');
 check($DB->tables['local_districtupdate_roster'][1]->zoomstate === 'done');
 $deletes = array_values(array_filter($ZCALLS, fn($c) => $c[0] === 'DELETE'));
 check(count($deletes) === 1 && str_ends_with($deletes[0][1], '/users/zoom-one?action=delete'));
 zoom_cleanup::process($batch, $api);
 check(count(array_filter($ZCALLS, fn($c) => $c[0] === 'DELETE')) === 1);
});
test('Zoom cleanup rejects nonmanagers disabled writes expired previews and changed settings', function() {
 global $MANAGER, $CONFIG; zoom_fixture(); $api = zoom_fake(); $batch = zoom_cleanup::preview([1], $api);
 $MANAGER = false; expect_error(fn() => zoom_cleanup::process($batch, $api), 'No permission'); $MANAGER = true;
 $CONFIG['zoomlive'] = 0; expect_error(fn() => zoom_cleanup::process($batch, $api), 'preview expired'); $CONFIG['zoomlive'] = 1;
 $old = $batch; $old['created'] = time()-901; expect_error(fn() => zoom_cleanup::process($old, $api), 'preview expired');
 $old = $batch; $old['actorid'] = 100; expect_error(fn() => zoom_cleanup::process($old, $api), 'preview expired');
 $CONFIG['zoomclientsecret'] = 'changed'; expect_error(fn() => zoom_cleanup::process($batch, $api), 'preview expired');
});
test('Zoom cleanup excludes transfers unsubmitted and archived district reviews', function() {
 global $DB; zoom_fixture(); $api = zoom_fake();
 $DB->tables['local_districtupdate_roster'][1]->status = 'moved'; check(zoom_cleanup::preview([1], $api)['rows'][1]['error'] !== '');
 $DB->tables['local_districtupdate_roster'][1]->status = 'left'; $DB->tables['local_districtupdate_review'][1]->submittedat = 0;
 check(zoom_cleanup::preview([1], $api)['rows'][1]['error'] !== '');
 $DB->tables['local_districtupdate_review'][1]->submittedat = time(); $DB->tables['local_districtupdate_dist'][1]->archived = 1;
 check(zoom_cleanup::preview([1], $api)['rows'][1]['error'] !== '');
});
test('Zoom preview blocks foreign accounts privileged roles rooms pending accounts and email mismatch', function() {
 global $ZU; zoom_fixture(); $original = $ZU;
 foreach (['account_id' => 'foreign', 'role_id' => '0', 'type' => 99, 'status' => 'pending', 'email' => 'other@example.com'] as $key => $value) {
  $ZU = $original; $ZU[$key] = $value;
  if ($key === 'email') { expect_error(fn() => zoom_fake()->validate($ZU, 'person@north.org'), 'does not match'); }
  else { check(zoom_cleanup::preview([1], zoom_fake())['rows'][1]['error'] !== ''); }
 }
});
test('Zoom absence is distinct from permission failures and never sends DELETE', function() {
 global $ZU, $ZCALLS, $ZGETFAIL; zoom_fixture(); $ZU = null; $api = zoom_fake();
 $batch = zoom_cleanup::preview([1], $api); check($batch['rows'][1]['error'] === '');
 check(zoom_cleanup::process($batch, $api)[1] === 'Not found in Zoom');
 check(!array_filter($ZCALLS, fn($c) => $c[0] === 'DELETE'));
 $ZGETFAIL = true; $batch = zoom_cleanup::preview([1], zoom_fake());
 check(str_contains($batch['rows'][1]['error'], 'HTTP 403') && !str_contains($batch['rows'][1]['error'], 'DO NOT DISPLAY'));
});
test('Zoom revalidates identity and role after preview before deletion', function() {
 global $ZU, $ZCALLS; zoom_fixture(); $api = zoom_fake(); $batch = zoom_cleanup::preview([1], $api);
 $ZU['role_id'] = '1'; $result = zoom_cleanup::process($batch, $api);
 check(str_contains($result[1], 'handle manually') && !array_filter($ZCALLS, fn($c) => $c[0] === 'DELETE'));
});
test('Zoom identity changes or new accounts after an absent preview require a new preview', function() {
 global $DB, $ZU, $ZCALLS; zoom_fixture(); $api = zoom_fake(); $batch = zoom_cleanup::preview([1], $api);
 $DB->tables['user'][20]->email = 'changed@example.com'; check(str_contains(zoom_cleanup::process($batch, $api)[1], 'identity'));
 $DB->tables['user'][20]->email = 'person@north.org'; $original = $ZU; $ZU = null;
 $batch = zoom_cleanup::preview([1], $api); $ZU = $original;
 check(str_contains(zoom_cleanup::process($batch, $api)[1], 'appeared after preview'));
 check(!array_filter($ZCALLS, fn($c) => $c[0] === 'DELETE'));
});
test('Zoom retry pins immutable user ID and never deletes a recreated account sharing the email', function() {
 global $DB, $ZU, $ZFAIL, $ZCALLS; zoom_fixture(); $api = zoom_fake(); $batch = zoom_cleanup::preview([1], $api);
 $ZFAIL = true; zoom_cleanup::process($batch, $api);
 check($DB->tables['local_districtupdate_roster'][1]->zoomstate === 'failed' && $DB->tables['local_districtupdate_roster'][1]->zoomid === 'zoom-one');
 $ZU['id'] = 'replacement'; $ZFAIL = false; $batch = zoom_cleanup::preview([1], $api);
 check($batch['rows'][1]['zoom'] === null); zoom_cleanup::process($batch, $api);
 check($ZU['id'] === 'replacement' && count(array_filter($ZCALLS, fn($c) => $c[0] === 'DELETE')) === 1);
});
test('Zoom cleanup blocks newer employment evidence duplicate emails and ignored accounts', function() {
 global $DB; zoom_fixture(); $api = zoom_fake();
 $copy = clone $DB->tables['user'][20]; $copy->id = 21; $DB->tables['user'][21] = $copy;
 check(str_contains(zoom_cleanup::preview([1], $api)['rows'][1]['error'], 'More than one'));
 unset($DB->tables['user'][21]);
 $other = clone $DB->tables['local_districtupdate_roster'][1]; $other->id = 2; $other->reviewid = 2; $other->status = 'still';
 $DB->tables['local_districtupdate_roster'][2] = $other;
 check(str_contains(zoom_cleanup::preview([1], $api)['rows'][1]['error'], 'still employed'));
 unset($DB->tables['local_districtupdate_roster'][2]);
 $DB->insert_record('local_districtupdate_skip', (object)['cycleid' => 0, 'userid' => 20]);
 check(str_contains(zoom_cleanup::preview([1], $api)['rows'][1]['error'], 'exclusion'));
});
test('Zoom processing prevents reopening or editing responses without changing AD completion', function() {
 global $DB; zoom_fixture();
 $DB->tables['local_districtupdate_roster'][1]->processstate = 'pending';
 $DB->tables['local_districtupdate_roster'][1]->zoomstate = 'failed';
 expect_error(fn() => \local_districtupdate\service::reopen(1), 'processing was attempted');
 check(!\local_districtupdate\roster_admin::can_reopen($DB->tables['local_districtupdate_review'][1]));
});
test('Zoom retries cannot silently switch the account in which a deletion was attempted', function() {
 global $CONFIG, $ZFAIL; zoom_fixture(); $api = zoom_fake(); $batch = zoom_cleanup::preview([1], $api);
 $ZFAIL = true; zoom_cleanup::process($batch, $api); $CONFIG['zoomaccountid'] = 'different-account';
 check(str_contains(zoom_cleanup::preview([1], zoom_fake())['rows'][1]['error'], 'differs from the original'));
});
test('Zoom token failures show safe error classes and guidance without returning credentials or raw messages', function() {
 zoom_fixture();
 foreach (['invalid_client' => 'Client ID / Client Secret pair', 'unsupported_grant_type' => 'Server-to-Server OAuth', 'invalid_grant' => 'Account ID', 'access_denied' => 'activated'] as $error => $guide) {
  $api = new zoom_api(fn() => ['status' => 401, 'data' => ['error' => $error, 'error_description' => 'secret-one fake-token NEVER RETURN THIS']]);
  try { $api->user('person@north.org'); throw new RuntimeException('Expected authentication failure'); }
  catch (moodle_exception $e) {
   check(str_contains($e->getMessage(), 'HTTP 401, '.$error) && str_contains($e->getMessage(), $guide));
   check(!str_contains($e->getMessage(), 'secret-one') && !str_contains($e->getMessage(), 'fake-token') && !str_contains($e->getMessage(), 'NEVER RETURN'));
  }
 }
});
test('Zoom unknown or malformed token responses never expose raw payloads', function() {
 zoom_fixture();
 foreach ([['error' => 'secret-one', 'code' => 'fake-token', 'message' => 'secret-one'], ['error' => ['bad'], 'code' => ['bad']], null] as $data) {
  $api = new zoom_api(fn() => ['status' => 502, 'data' => $data]);
  try { $api->user('person@north.org'); throw new RuntimeException('Expected authentication failure'); }
  catch (moodle_exception $e) { check(str_contains($e->getMessage(), 'HTTP 502, unrecognized response') && !str_contains($e->getMessage(), 'secret-one') && !str_contains($e->getMessage(), 'fake-token')); }
 }
 $api = new zoom_api(fn() => ['status' => 400, 'data' => ['code' => 4717, 'message' => 'private']]);
 expect_error(fn() => $api->user('person@north.org'), 'app is disabled');
});
test('Zoom whitespace credential mistakes are detected before sending any request', function() {
 global $CONFIG; zoom_fixture(); $CONFIG['zoomclientsecret'] = "secret-one\n";
 expect_error(fn() => new zoom_api(), 'zoomclientsecret contains extra whitespace');
});
