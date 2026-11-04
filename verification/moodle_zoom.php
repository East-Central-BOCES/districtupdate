<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Real Moodle schema/page/process checks. HTTP is simulated; all database fixtures roll back. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') { exit("Usage: php moodle_zoom.php /path/to/config.php --test-site\n"); }
define('CLI_SCRIPT', true); require $argv[1];
core\session\manager::set_user(get_admin());
$CFG->forced_plugin_settings['local_districtupdate'] = array_merge($CFG->forced_plugin_settings['local_districtupdate'] ?? [],
 ['zoomaccountid'=>'zoom-fixture-account','zoomclientid'=>'fixture-client','zoomclientsecret'=>'fixture-secret','zoomlive'=>1,'livewrites'=>0]);
class du_zoom_rollback extends Exception {}
function du_zoom_check(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function du_zoom_render(int $reviewid, string $availability = 'all'): string {
 global $CFG, $DB, $PAGE, $OUTPUT, $USER, $SESSION;
 $_SERVER['REQUEST_METHOD']='GET'; $_POST=[]; $_GET=['reviewid'=>$reviewid,'availability'=>$availability]; $PAGE=new moodle_page(); ob_start();
 try { require $CFG->dirroot.'/local/districtupdate/zoom.php'; } finally { $html=ob_get_clean(); }
 return $html;
}
$tables=['user','local_districtupdate_dist','local_districtupdate_cycle','local_districtupdate_review','local_districtupdate_roster','local_districtupdate_audit'];
$before=[];foreach($tables as $t){$before[$t]=$DB->count_records($t);}
$tx=$DB->start_delegated_transaction();$failure=null;
try {
 $tag=bin2hex(random_bytes(5));$year=2099;while($DB->record_exists('local_districtupdate_cycle',['reviewyear'=>$year])){$year--;}
 $cid=$DB->insert_record('local_districtupdate_cycle',(object)['reviewyear'=>$year,'duedate'=>time()+DAYSECS,'timecreated'=>time()]);
 $did=$DB->insert_record('local_districtupdate_dist',(object)['name'=>'Zoom fixture '.$tag,'domains'=>'','aliases'=>'']);
 $rid=$DB->insert_record('local_districtupdate_review',(object)['cycleid'=>$cid,'districtid'=>$did,'submittedat'=>time(),'submittedby'=>$USER->id]);
 $uid=$DB->insert_record('user',(object)['username'=>'zoomfixture-'.$tag,'email'=>'zoomfixture-'.$tag.'@example.com','firstname'=>'Zoom','lastname'=>'Fixture','auth'=>'ldap','confirmed'=>1,'mnethostid'=>$CFG->mnet_localhost_id]);
 $rowid=$DB->insert_record('local_districtupdate_roster',(object)['reviewid'=>$rid,'userid'=>$uid,'username'=>'zoomfixture-'.$tag,
  'fullname'=>'Zoom Fixture','email'=>'zoomfixture-'.$tag.'@example.com','institution'=>'','status'=>'left','destination'=>0,'targetcompany'=>'','note'=>'','processstate'=>'done']);
 $row=$DB->get_record('local_districtupdate_roster',['id'=>$rowid]);
 du_zoom_check($row->zoomstate==='pending' && $row->zoomid===null && !$row->zoomactor && !$row->zoomtime,'New schema defaults are incorrect.');
 $html=du_zoom_render($rid);
 du_zoom_check(str_contains($html,'Preview selected Zoom accounts') && str_contains($html,'Completed') && str_contains($html,'Not checked'),'Retroactive page does not show AD-completed departure.');
 $state=['id'=>'fixture-zoom-id','email'=>$row->email,'account_id'=>'zoom-fixture-account','role_id'=>'2','type'=>1,'status'=>'active'];$calls=[];
 $api=new \local_districtupdate\zoom_api(function($method,$url,$headers,$body)use(&$state,&$calls){
  $calls[]=[$method,$url];
  if($method==='POST'){return ['status'=>200,'data'=>['access_token'=>'fake-token','token_type'=>'bearer']];}
  if($method==='DELETE'){$state=null;return ['status'=>204,'data'=>null];}
  return $state ? ['status'=>200,'data'=>$state] : ['status'=>404,'data'=>['code'=>1001]];
 });
 $batch=\local_districtupdate\zoom_cleanup::preview([$rowid],$api);
 du_zoom_check(!$batch['rows'][$rowid]['error'],'Valid real-DB preview was blocked: '.$batch['rows'][$rowid]['error']);
 \local_districtupdate\zoom_lookup::remember($batch);
 $html=du_zoom_render($rid,'found');
 du_zoom_check(str_contains($html,'Zoom Fixture') && str_contains($html,'name="selected[]"') && str_contains($html,'Found in Zoom'),'Found filter lost a matched account.');
 $html=du_zoom_render($rid,'absent');
 du_zoom_check(!str_contains($html,'name="selected[]"'),'Not-found filter incorrectly included a found account.');
 $html=du_zoom_render($rid,'unknown');
 du_zoom_check(!str_contains($html,'name="selected[]"'),'Unknown filter included a checked account.');
 $DB->set_field('local_districtupdate_roster','zoomstate','done',['id'=>$rowid]);
 du_zoom_check(!str_contains(du_zoom_render($rid,'found'),'name="selected[]"'),'Completed account remained in found filter.');
 $DB->set_field('local_districtupdate_roster','zoomstate','pending',['id'=>$rowid]);
 echo "PASS: availability filters apply before rendering/pagination, preserve checked results and exclude completed accounts.\n";

 $DB->set_field('local_districtupdate_review','revision',2,['id'=>$rid]);
 $result=\local_districtupdate\zoom_cleanup::process($batch,$api);
 du_zoom_check(str_contains($result[$rowid],'Review or identity changed') && !array_filter($calls,fn($c)=>$c[0]==='DELETE'),'Stale real-DB preview was executed.');
 $DB->set_field('local_districtupdate_review','revision',1,['id'=>$rid]);
 // A second district response in the same annual cycle must block destructive historical cleanup.
 $other=$DB->insert_record('local_districtupdate_review',(object)['cycleid'=>$cid,'districtid'=>0]);
 $did2=$DB->insert_record('local_districtupdate_dist',(object)['name'=>'Zoom other '.$tag,'domains'=>'','aliases'=>'']);
 $DB->set_field('local_districtupdate_review','districtid',$did2,['id'=>$other]);
 $copy=clone $row;unset($copy->id);$copy->reviewid=$other;$copy->status='moved';$copy->destination=$did2;
 $ref=$DB->insert_record('local_districtupdate_roster',$copy);
 du_zoom_check(str_contains(\local_districtupdate\zoom_cleanup::preview([$rowid],$api)['rows'][$rowid]['error'],'still employed or transferred'),'Current transfer evidence did not block deletion.');
 $DB->delete_records('local_districtupdate_roster',['id'=>$ref]);
 $duplicate=$DB->insert_record('user',(object)['username'=>'zoomduplicate-'.$tag,'email'=>$row->email,'firstname'=>'Duplicate','lastname'=>'Fixture','auth'=>'manual','mnethostid'=>$CFG->mnet_localhost_id]);
 du_zoom_check(str_contains(\local_districtupdate\zoom_cleanup::preview([$rowid],$api)['rows'][$rowid]['error'],'More than one Moodle account'),'Duplicate email was not blocked.');
 $DB->delete_records('user',['id'=>$duplicate]);
 $batch=\local_districtupdate\zoom_cleanup::preview([$rowid],$api);$result=\local_districtupdate\zoom_cleanup::process($batch,$api);
 $after=$DB->get_record('local_districtupdate_roster',['id'=>$rowid]);
 du_zoom_check($result[$rowid]==='Deleted and verified' && $after->zoomstate==='done' && $after->zoomid==='fixture-zoom-id','Zoom deletion status was not persisted.');
 du_zoom_check($after->processstate==='done' && $DB->record_exists('user',['id'=>$uid]),'AD status or Moodle account changed.');
 du_zoom_check(count(array_filter($calls,fn($c)=>$c[0]==='DELETE' && str_ends_with($c[1],'?action=delete')))===1,'Permanent delete request was not exact.');
 du_zoom_check($DB->record_exists('local_districtupdate_audit',['rosterid'=>$rowid,'event'=>'zoom_deleted']),'Zoom audit missing.');
 $html=du_zoom_render($rid);du_zoom_check(str_contains($html,'Deleted and verified') && !str_contains($html,'name="selected[]"'),'Completed Zoom user remains selectable.');
 du_zoom_check(!\local_districtupdate\roster_admin::can_reopen($DB->get_record('local_districtupdate_review',['id'=>$rid])),'Processed review can be reopened.');
 echo "PASS: real schema defaults, retroactive page, stale preview, transfer/duplicate identity blocks and separate Zoom audit/status passed with simulated HTTP.\n";
} catch(Throwable $e){$failure=$e;}
try{$tx->rollback(new du_zoom_rollback());}catch(du_zoom_rollback $expected){}
if($failure){throw $failure;}
foreach($tables as $t){du_zoom_check($DB->count_records($t)===$before[$t],'Fixture remained in '.$t);}
echo "PASS: fixtures rolled back; no Zoom network requests, AD writes or emails.\n";
