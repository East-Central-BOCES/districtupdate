<?php
// SPDX-License-Identifier: GPL-3.0-or-later
use local_districtupdate\audit_display;
test('roster activity describes district assignment without raw snapshots or unchanged fields', function() {
 $formatter = new audit_display([2 => 'Elbert'], [1 => 0, 21 => 2]);
 [$label, $lines] = $formatter->describe('roster_account_refreshed', json_encode([
  'before' => ['id' => 2754, 'reviewid' => 1, 'institution' => 'Other', 'email' => 'staff@example.com', 'userid' => 9592],
  'after' => ['id' => 2754, 'reviewid' => 21, 'institution' => 'Other', 'email' => 'staff@example.com']]));
 check($label === 'Roster updated' && $lines === ['District: Unmatched accounts → Elbert']);
});
test('roster activity shows only changed profile values', function() {
 $f = new audit_display();
 [, $lines] = $f->describe('roster_account_refreshed', json_encode(['before' => ['email' => 'old@example.com', 'institution' => 'North'],
  'after' => ['email' => 'new@example.com', 'institution' => 'North']]));
 check($lines === ['Email: old@example.com → new@example.com']);
});
test('response activity shows readable status destination and note change without dumping notes', function() {
 $f = new audit_display([2 => 'South']);
 [, $lines] = $f->describe('response_changed', json_encode(['before' => ['still', 0, ''], 'after' => ['moved', 2, 'Private old note']]));
 check($lines === ['Still Employed → Moved to another district', 'Destination: South', 'Reviewer note updated.']);
});
test('processing activity omits directory snapshots object GUIDs and raw timestamps', function() {
 $f = new audit_display();
 [, $lines] = $f->describe('processing_started', json_encode(['before' => ['guid' => 'hidden-guid', 'dn' => 'hidden-dn'],
  'previewcreated' => 1792700000, 'planned' => ['Add to Basic', 'Set Office to No Access']]));
 check($lines === ['Add to Basic', 'Set Office to No Access']);
 [, $lines] = $f->describe('action_failed', '{"action":"Add to Basic","ldapcode":50}');
 check($lines === ['Add to Basic', 'AD returned error code 50.']);
});
test('refresh summary uses clear labels and omits zero counts', function() {
 [, $lines] = (new audit_display())->describe('rosters_refreshed', '{"summary":{"added":4,"updated":0,"moved":2,"preserved":8}}');
 check($lines === ['Added: 4', 'Reassigned: 2', 'Responses preserved: 8']);
});
test('malformed and unknown audit data never expose raw payloads', function() {
 $f = new audit_display();
 [, $lines] = $f->describe('roster_account_refreshed', 'broken-json'); check($lines === ['Details unavailable.']);
 [$label, $lines] = $f->describe('unexpected_internal_key', '{"raw_internal_dump":"secret snapshot"}');
 check($label === 'Review activity' && $lines === ['Activity recorded.']);
 check(audit_display::processing_status('failed') === 'Needs attention');
});
