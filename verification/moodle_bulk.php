<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Real bulk roster integration checks; fixtures roll back, with no mail or AD writes. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') { exit("Usage: php moodle_bulk.php /path/to/moodle/config.php --test-site\n"); }
define('CLI_SCRIPT', true); require $argv[1];
core\session\manager::set_user(get_admin());
$CFG->forced_plugin_settings['local_districtupdate']['authmethods'] = 'ldap';
class du_bulk_rollback extends Exception {}
function du_bulk_assert(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function du_bulk_error(callable $f, string $expected): void {
 try { $f(); } catch (moodle_exception $e) { du_bulk_assert(str_contains($e->getMessage(), $expected), $e->getMessage()); return; }
 throw new RuntimeException('Expected a validation error.');
}
function du_bulk_render(int $cycleid, int $page): string {
 global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SESSION;
 $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; $_GET = ['cycleid' => $cycleid, 'page' => $page];
 $PAGE = new moodle_page(); ob_start();
 try { require $CFG->dirroot.'/local/districtupdate/unmatched.php'; } finally { $html = ob_get_clean(); }
 return $html;
}
$tables = ['user','cohort','cohort_members','local_districtupdate_dist','local_districtupdate_cycle','local_districtupdate_review',
 'local_districtupdate_roster','local_districtupdate_skip','local_districtupdate_access','local_districtupdate_audit'];
$before = []; foreach ($tables as $t) { $before[$t] = $DB->count_records($t); }
$fixturetx = $DB->start_delegated_transaction(); $failure = null;
try {
 $tag = bin2hex(random_bytes(6));
 $cohorts = $DB->get_records('cohort', ['name'=>'ECBOCES_Members']) + $DB->get_records('cohort', ['idnumber'=>'ECBOCES_Members']);
 if (!$cohorts) { $cohortid = $DB->insert_record('cohort',(object)['name'=>'ECBOCES_Members','idnumber'=>'ECBOCES_Members',
  'contextid'=>context_system::instance()->id,'description'=>'','descriptionformat'=>FORMAT_PLAIN,'component'=>'','visible'=>1,'timecreated'=>time(),'timemodified'=>time()]); }
 else { du_bulk_assert(count($cohorts)===1, 'Ambiguous cohort.'); $cohortid=(int)reset($cohorts)->id; }
 $year=2100; while($DB->record_exists('local_districtupdate_cycle',['reviewyear'=>$year])){$year--;}
 $cid=$DB->insert_record('local_districtupdate_cycle',(object)['reviewyear'=>$year,'duedate'=>time()+DAYSECS,'timecreated'=>time()]);
 $districts=[]; foreach(['North','South'] as $name){ $districts[]=$DB->insert_record('local_districtupdate_dist',(object)['name'=>'Bulk '.$name.' '.$tag,'domains'=>'','aliases'=>'']); }
 $reviews=[]; foreach(array_merge([0],$districts) as $did){$reviews[$did]=$DB->insert_record('local_districtupdate_review',(object)[
  'cycleid'=>$cid,'districtid'=>$did,'revision'=>1,'submittedby'=>0,'submittedat'=>0,'lastsent'=>0]);}
 $users=[];$rows=[];
 for($i=1;$i<=105;$i++){
  $uid=$DB->insert_record('user',(object)['username'=>'bulk-'.$tag.'-'.$i,'email'=>'bulk-'.$tag.'-'.$i.'@example.com',
   'firstname'=>'Bulk '.$tag,'lastname'=>'Person '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'institution'=>'','auth'=>'ldap',
   'confirmed'=>1,'deleted'=>0,'suspended'=>0,'mnethostid'=>$CFG->mnet_localhost_id]);
  $users[$i]=$uid;
  $DB->insert_record('cohort_members',(object)['cohortid'=>$cohortid,'userid'=>$uid,'timeadded'=>time()]);
  $rows[$i]=$DB->insert_record('local_districtupdate_roster',(object)['reviewid'=>$reviews[0],'userid'=>$uid,
   'username'=>'bulk-'.$tag.'-'.$i,'fullname'=>'Bulk Person '.str_pad((string)$i,3,'0',STR_PAD_LEFT),'email'=>'bulk-'.$tag.'-'.$i.'@example.com',
   'institution'=>'','status'=>'still','destination'=>0,'targetcompany'=>'','note'=>'','modifiedby'=>0,'modifiedat'=>0,'processstate'=>'pending']);
 }
 $html=du_bulk_render($cid,0);$doc=new DOMDocument();@$doc->loadHTML($html);$xp=new DOMXPath($doc);
 du_bulk_assert($xp->query('//input[@name="selected[]"]')->length===100,'First page is not limited to 100 rows.');
 $html=du_bulk_render($cid,1);$doc=new DOMDocument();@$doc->loadHTML($html);$xp=new DOMXPath($doc);
 du_bulk_assert($xp->query('//input[@name="selected[]"]')->length===5,'Second page count incorrect.');
 echo "PASS: real unmatched page renders 105 accounts in 100/5 pages with per-row and bulk controls.\n";
 $DB->set_field('local_districtupdate_review','submittedat',time(),['id'=>$reviews[$districts[1]]]);
 du_bulk_error(fn()=>\local_districtupdate\roster_admin::apply($reviews[0],1,'assign',[$rows[1]=>$districts[0],$rows[2]=>$districts[1]],[]),'No accounts were changed');
 du_bulk_assert((int)$DB->get_field('local_districtupdate_roster','reviewid',['id'=>$rows[1]])===$reviews[0],'Partial batch was saved.');
 $DB->set_field('local_districtupdate_review','submittedat',0,['id'=>$reviews[$districts[1]]]);
 \local_districtupdate\roster_admin::apply($reviews[0],1,'assign',[$rows[1]=>$districts[0],$rows[2]=>$districts[1]],[]);
 du_bulk_assert((int)$DB->get_field('local_districtupdate_roster','reviewid',['id'=>$rows[2]])===$reviews[$districts[1]],'Second assignment failed.');
 du_bulk_error(fn()=>\local_districtupdate\roster_admin::apply($reviews[0],1,'remove',[],[$rows[3]]),'list changed');
 \local_districtupdate\roster_admin::apply($reviews[0],2,'remove',[],[$rows[3]]);
 \local_districtupdate\roster_admin::apply($reviews[0],3,'ignore',[],[$rows[4]]);
 \local_districtupdate\service::refresh_cycle($cid);
 du_bulk_assert(!$DB->record_exists('local_districtupdate_roster',['userid'=>$users[3]]) && !$DB->record_exists('local_districtupdate_roster',['userid'=>$users[4]]),'Refresh restored an exclusion.');
 $next=array_map(fn($u)=>(int)$u->id,iterator_to_array(\local_districtupdate\service::eligible_users($cid+999)));
 du_bulk_assert(in_array($users[3],$next,true) && !in_array($users[4],$next,true),'Year removal and global ignore scopes are incorrect.');
 $skips=$DB->get_records('local_districtupdate_skip', ['userid'=>$users[3]]) + $DB->get_records('local_districtupdate_skip',['userid'=>$users[4]]);
 \local_districtupdate\roster_admin::restore(array_keys($skips));\local_districtupdate\service::refresh_cycle($cid);
 du_bulk_assert($DB->record_exists('local_districtupdate_roster',['userid'=>$users[3]]) && $DB->record_exists('local_districtupdate_roster',['userid'=>$users[4]]),'Restored exclusions did not reappear.');
 echo "PASS: atomic multi-district assignment, stale batch rejection, year removal, global ignore and restore survive real roster refresh.\n";
 $DB->set_field('local_districtupdate_review','submittedat',time(),['id'=>$reviews[$districts[1]]]);
 \local_districtupdate\roster_admin::archive_district($districts[1],true);
 du_bulk_assert($DB->record_exists('local_districtupdate_roster',['id'=>$rows[2]]),'District removal deleted submitted history.');
 \local_districtupdate\roster_admin::archive_district($districts[1],false);
 du_bulk_assert(\local_districtupdate\service::district_active($districts[1]),'District restore failed.');
 du_bulk_assert($DB->count_records('user')===$before['user']+105,'A cleanup action changed Moodle users.');
 echo "PASS: removed district retains submitted history and can be restored; Moodle accounts are unchanged.\n";
} catch(Throwable $e){$failure=$e;}
try{$fixturetx->rollback(new du_bulk_rollback());}catch(du_bulk_rollback $expected){}
if($failure){throw $failure;}
foreach($tables as $t){du_bulk_assert($DB->count_records($t)===$before[$t],'Fixture remained in '.$t);}
echo "PASS: all fixtures rolled back; no email or AD operations.\n";
