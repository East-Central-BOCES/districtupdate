<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Real Moodle overview, audit and deletion checks. Fixtures roll back; no mail or AD. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') { exit("Usage: php moodle_overview.php /path/to/config.php --test-site\n"); }
define('CLI_SCRIPT', true); require $argv[1];
core\session\manager::set_user(get_admin());
class du_overview_rollback extends Exception {}
function du_overview_check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function du_overview_render(string $file, array $query = []): string {
 global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SESSION;
 $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = $query;
 $PAGE = new moodle_page(); ob_start();
 try { require $CFG->dirroot.'/local/districtupdate/'.$file; } finally { $html = ob_get_clean(); }
 return $html;
}
$tables = ['user','local_districtupdate_dist','local_districtupdate_access','local_districtupdate_cycle',
 'local_districtupdate_review','local_districtupdate_roster','local_districtupdate_audit'];
$before = []; foreach ($tables as $t) { $before[$t] = $DB->count_records($t); }
$tx = $DB->start_delegated_transaction(); $failure = null;
try {
 $name = 'Overview fixture '.bin2hex(random_bytes(5));
 $did = $DB->insert_record('local_districtupdate_dist', (object)['name'=>$name,'domains'=>'fixture.invalid','aliases'=>'']);
 $DB->insert_record('local_districtupdate_access', (object)['districtid'=>$did,'userid'=>$USER->id]);
 $year=2100; while($DB->record_exists('local_districtupdate_cycle',['reviewyear'=>$year])){$year--;}
 $cid=$DB->insert_record('local_districtupdate_cycle',(object)['reviewyear'=>$year,'duedate'=>time()+DAYSECS,'timecreated'=>time()]);
 $rid=$DB->insert_record('local_districtupdate_review',(object)['cycleid'=>$cid,'districtid'=>$did,'submittedat'=>time(),'submittedby'=>$USER->id]);
 $rowid=$DB->insert_record('local_districtupdate_roster',(object)['reviewid'=>$rid,'userid'=>$USER->id,'username'=>'overview-test',
  'fullname'=>'Overview Staff','email'=>'fixture@example.com','institution'=>$name,'status'=>'left','destination'=>0,'targetcompany'=>'','note'=>'','processstate'=>'pending']);
 \local_districtupdate\service::audit($rowid,$USER->id,'response_changed',['before'=>['still',0,''],'after'=>['left',0,'']]);
 $html=du_overview_render('manage.php'); $doc=new DOMDocument(); @$doc->loadHTML($html); $xp=new DOMXPath($doc);
 $cards=$xp->query('//section[@aria-label="'.$name.'"]');
 du_overview_check($cards->length===1,'District is not consolidated into one card.');
 du_overview_check($xp->query('.//*[contains(@class,"bg-warning")]', $cards->item(0))->length>0,'Submitted state is not yellow.');
 du_overview_check(!str_contains($html,'Recent notifications and configuration activity'),'Inline audit still on main page.');
 du_overview_check(str_contains($html,'Audit trail') && str_contains($cards->item(0)->textContent,'fixture.invalid'),'Missing audit link or combined matching data.');
 $DB->set_field('local_districtupdate_roster','processstate','done',['id'=>$rowid]);
 $html=du_overview_render('manage.php');
 du_overview_check(str_contains($html,'Changes pushed — 1 of 1 completed') && str_contains($html,'bg-success'),'Processed state not green.');
 $audit=du_overview_render('audit.php',['reviewid'=>$rid]);
 du_overview_check(str_contains($audit,'Reviewer response changed') && !str_contains($audit,'"before"'),'Audit page missing readable activity.');
 $confirm=du_overview_render('delete.php',['districtid'=>$did]);
 du_overview_check(str_contains($confirm,'1 annual reviews, 1 roster entries') && str_contains($confirm,'name="confirmation"'),'Confirmation does not show affected data and typed-name input.');
 echo "PASS: combined district card, yellow/green statuses, separate audit page and delete confirmation render in real Moodle.\n";
 $other=$DB->insert_record('local_districtupdate_review',(object)['cycleid'=>$cid,'districtid'=>0]);
 $ref=$DB->insert_record('local_districtupdate_roster',(object)['reviewid'=>$other,'userid'=>$USER->id,'username'=>'ref-test',
  'fullname'=>'Transfer Ref','email'=>'ref@example.com','institution'=>'','status'=>'moved','destination'=>$did,'targetcompany'=>$name,'note'=>'']);
 $blocked=false;
 try { \local_districtupdate\roster_admin::delete_district($did,$name); } catch(moodle_exception $e) { $blocked=str_contains($e->getMessage(),'transfer destination'); }
 du_overview_check($blocked && $DB->record_exists('local_districtupdate_dist',['id'=>$did]),'Transfer reference did not prevent deletion.');
 $DB->delete_records('local_districtupdate_roster',['id'=>$ref]);
 $counts=\local_districtupdate\roster_admin::delete_district($did,$name);
 du_overview_check($counts===['reviews'=>1,'staff'=>1],'Deletion counts incorrect.');
 foreach(['local_districtupdate_dist'=>['id'=>$did],'local_districtupdate_access'=>['districtid'=>$did],
  'local_districtupdate_review'=>['id'=>$rid],'local_districtupdate_roster'=>['id'=>$rowid],'local_districtupdate_audit'=>['rosterid'=>$rowid]] as $t=>$where){
  du_overview_check(!$DB->record_exists($t,$where),'Orphaned district history: '.$t);
 }
 du_overview_check($DB->record_exists('local_districtupdate_review',['id'=>$other]) && $DB->count_records('user')===$before['user'],'Unrelated review or users changed.');
 du_overview_check($DB->record_exists('local_districtupdate_audit',['event'=>'district_deleted']),'Deletion audit missing.');
 echo "PASS: transfer destination protection and permanent deletion cascade preserve unrelated reviews and Moodle users.\n";
} catch(Throwable $e){$failure=$e;}
try{$tx->rollback(new du_overview_rollback());}catch(du_overview_rollback $expected){}
if($failure){throw $failure;}
foreach($tables as $t){du_overview_check($DB->count_records($t)===$before[$t],'Fixture remained in '.$t);}
echo "PASS: fixtures rolled back; no mail or AD changes.\n";
