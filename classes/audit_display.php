<?php
// SPDX-License-Identifier: GPL-3.0-or-later
namespace local_districtupdate;
defined('MOODLE_INTERNAL') || die();
/** Review-focused text summaries; the stored audit evidence is never modified. */
final class audit_display {
 public function __construct(private array $districts = [], private array $reviews = []) {}
 private function district(int $id): string { return $id ? ($this->districts[$id] ?? 'Previous district') : 'Unmatched accounts'; }
 private function review_district(int $id): string {
  return array_key_exists($id, $this->reviews) ? $this->district((int)$this->reviews[$id]) : 'Previous district';
 }
 public static function status(string $value): string {
  return ['still' => 'Still Employed', 'left' => 'No longer employed', 'moved' => 'Moved to another district'][$value] ?? 'Not recorded';
 }
 public static function processing_status(string $value): string {
  return ['pending' => 'Awaiting approval', 'processing' => 'Processing started', 'failed' => 'Needs attention', 'done' => 'Completed'][$value] ?? 'Not recorded';
 }
 public function describe(string $event, string $detail): array {
  $labels = ['cycle_created' => 'Annual review created', 'district_configured' => 'District settings saved',
   'rosters_refreshed' => 'Rosters refreshed', 'roster_account_added' => 'Added to roster',
   'roster_account_removed' => 'Removed from draft roster', 'roster_account_refreshed' => 'Roster updated',
   'response_changed' => 'Reviewer response changed', 'review_submitted' => 'Review submitted',
   'review_reopened' => 'Review reopened', 'district_assigned' => 'District assigned',
   'invitation_sent' => 'Invitation sent', 'invitation_failed' => 'Invitation failed',
   'admin_email_sent' => 'Admin notification sent', 'admin_email_failed' => 'Admin notification failed',
   'processing_started' => 'Directory processing started', 'processing_failed' => 'Directory processing failed',
   'action_started' => 'Directory action started', 'action_succeeded' => 'Directory action completed',
   'action_failed' => 'Directory action failed', 'roster_user_removed' => 'Removed from this year', 'roster_user_ignored' => 'Ignored for rostering',
   'roster_user_restored' => 'Roster exclusion restored', 'district_archived' => 'District removed from rostering',
   'district_restored' => 'District restored', 'verified' => 'Changes verified'];
  $label = $labels[$event] ?? 'Review activity';
  $d = json_decode($detail, true);
  if (!is_array($d)) { return [$label, ['Details unavailable.']]; }
  $lines = [];
  switch ($event) {
   case 'roster_account_refreshed':
    $before = $d['before'] ?? []; $after = $d['after'] ?? [];
    if (isset($after['reviewid']) && (int)($before['reviewid'] ?? 0) !== (int)$after['reviewid']) {
     $lines[] = 'District: '.$this->review_district((int)($before['reviewid'] ?? 0)).' → '.$this->review_district((int)$after['reviewid']);
    }
    foreach (['fullname' => 'Name', 'email' => 'Email', 'username' => 'Username', 'institution' => 'Institution'] as $key => $name) {
     if (array_key_exists($key, $after) && ($before[$key] ?? '') !== $after[$key]) {
      $lines[] = $name.': '.((string)($before[$key] ?? '') ?: '(blank)').' → '.((string)$after[$key] ?: '(blank)');
     }
    }
    break;
   case 'response_changed':
    $before = $d['before'] ?? []; $after = $d['after'] ?? [];
    if (($before[0] ?? '') !== ($after[0] ?? '')) { $lines[] = self::status($before[0] ?? '').' → '.self::status($after[0] ?? ''); }
    if (($after[0] ?? '') === 'moved') { $lines[] = 'Destination: '.$this->district((int)($after[1] ?? 0)); }
    if (($before[2] ?? '') !== ($after[2] ?? '')) { $lines[] = empty($after[2]) ? 'Reviewer note removed.' : 'Reviewer note updated.'; }
    break;
   case 'district_assigned': $lines[] = 'Assigned to '.$this->district((int)($d['districtid'] ?? 0)).'.'; break;
   case 'roster_account_added': $lines[] = 'Added to '.$this->review_district((int)($d['reviewid'] ?? 0)).' as Still Employed.'; break;
   case 'roster_account_removed': $lines[] = $d['reason'] ?? 'No longer eligible; removed before any reviewer response.'; break;
   case 'roster_user_removed': $lines[] = 'Excluded from this annual roster. Moodle and AD accounts unchanged.'; break;
   case 'roster_user_ignored': $lines[] = 'Excluded from current and future rosters until restored.'; break;
   case 'roster_user_restored': $lines[] = 'Exclusion removed. Refresh rosters to include eligible users again.'; break;
   case 'district_archived': case 'district_restored':
    $lines[] = $this->district((int)($d['districtid'] ?? 0));
    if ($event === 'district_archived') { $lines[] = 'Untouched draft entries removed: '.(int)($d['summary']['removed'] ?? 0).'; historical responses preserved: '.(int)($d['summary']['preserved'] ?? 0).'.'; }
    break;
   case 'district_configured':
    $lines[] = $this->district((int)($d['districtid'] ?? 0)).' — '.(int)($d['reviewercount'] ?? 0).' assigned reviewer(s).'; break;
   case 'cycle_created': $lines[] = 'Staff review for '.(int)($d['year'] ?? 0).'.'; break;
   case 'rosters_refreshed':
    foreach (['added' => 'Added', 'updated' => 'Updated', 'moved' => 'Reassigned', 'removed' => 'Removed',
     'preserved' => 'Responses preserved', 'locked' => 'Reviews left unchanged', 'skipped' => 'Accounts skipped'] as $key => $name) {
     if (!empty($d['summary'][$key])) { $lines[] = $name.': '.(int)$d['summary'][$key]; }
    }
    if (!$lines) { $lines[] = 'No roster changes.'; } break;
   case 'processing_started': $lines = array_values(array_filter($d['planned'] ?? [], 'is_string')); break;
   case 'verified': $lines = array_values(array_filter($d['actions'] ?? [], 'is_string')); break;
   case 'action_started': case 'action_succeeded': case 'action_failed':
    if (!empty($d['action'])) { $lines[] = (string)$d['action']; }
    if ($event === 'action_failed' && isset($d['ldapcode'])) { $lines[] = 'AD returned error code '.(int)$d['ldapcode'].'.'; }
    break;
   case 'processing_failed': $lines[] = (string)($d['message'] ?? 'Check the directory before trying again.'); break;
   case 'review_submitted': $lines[] = 'Submitted for administrator review.'; break;
   case 'review_reopened': $lines[] = 'Reviewer editing restored.'; break;
   case 'invitation_sent': case 'admin_email_sent':
    if (!empty($d['recipient'])) { $lines[] = 'Recipient: '.$d['recipient']; }
    $lines[] = 'Email accepted by the configured mail service.'; break;
   case 'invitation_failed': case 'admin_email_failed':
    if (!empty($d['recipient'])) { $lines[] = 'Recipient: '.$d['recipient']; }
    $lines[] = $d['message'] ?? 'Email could not be sent. Check Moodle mail configuration.'; break;
  }
  return [$label, $lines ?: ['Activity recorded.']];
 }
}
