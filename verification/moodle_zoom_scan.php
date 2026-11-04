<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** All-page scanner: 600 departures / 24 pages, simulated HTTP, complete rollback. */
if(PHP_SAPI!=='cli'||empty($argv[1])||($argv[2]??'')!=='--test-site'){exit("Usage: php moodle_zoom_scan.php /path/to/config.php --test-site\n");}
define('CLI_SCRIPT',true);require $argv[1];core\session\manager::set_user(get_admin());
$CFG->forced_plugin_settings['local_districtupdate']=array_merge($CFG->forced_plugin_settings['local_districtupdate']??[],
 ['zoomaccountid'=>'scan-account','zoomclientid'=>'fixture-client','zoomclientsecret'=>'fixture-secret','zoomlive'=>0,'livewrites'=>0]);
class du_scan_rollback extends Exception{}
function du_scan_check(bool $ok,string $message):void{if(!$ok){throw new RuntimeException($message);}}
$tables=['user','local_districtupdate_dist','local_districtupdate_cycle','local_districtupdate_review','local_districtupdate_roster','local_districtupdate_audit'];
$before=[];foreach($tables as $t){$before[$t]=$DB->count_records($t);}
$tx=$DB->start_delegated_transaction();$failure=null;
try{
 $tag=bin2hex(random_bytes(5));$year=2096;while($DB->record_exists('local_districtupdate_cycle',['reviewyear'=>$year])){$year--;}
 $cid=$DB->insert_record('local_districtupdate_cycle',(object)['reviewyear'=>$year,'duedate'=>time()+DAYSECS,'timecreated'=>time()]);
 $did=$DB->insert_record('local_districtupdate_dist',(object)['name'=>'Scan fixture '.$tag,'domains'=>'','aliases'=>'']);
 $rid=$DB->insert_record('local_districtupdate_review',(object)['cycleid'=>$cid,'districtid'=>$did,'submittedat'=>time(),'submittedby'=>$USER->id]);
 $ids=[];
 for($i=1;$i<=602;$i++){
  $uid=$DB->insert_record('user',(object)['username'=>'scan-'.$tag.'-'.$i,'email'=>'scan-'.$tag.'-'.$i.'@example.com',
   'firstname'=>'Scan','lastname'=>'Fixture '.$i,'auth'=>'ldap','mnethostid'=>$CFG->mnet_localhost_id]);
  $ids[]=$DB->insert_record('local_districtupdate_roster',(object)['reviewid'=>$rid,'userid'=>$uid,'username'=>'scan-'.$tag.'-'.$i,
   'fullname'=>'Scan Fixture '.$i,'email'=>'scan-'.$tag.'-'.$i.'@example.com','institution'=>'','status'=>$i===601?'moved':'left',
   'destination'=>0,'targetcompany'=>'','note'=>'','processstate'=>'done','zoomstate'=>$i===602?'done':'pending']);
 }
 $calls=[];$api=new \local_districtupdate\zoom_api(function($method,$url,$headers,$body)use(&$calls){
  $calls[]=$method;
  if($method==='POST'){return ['status'=>200,'data'=>['access_token'=>'fixture-token','token_type'=>'bearer']];}
  if($method!=='GET'){throw new RuntimeException('Scanner attempted a non-read Zoom action.');}
  $email=rawurldecode(basename(parse_url($url,PHP_URL_PATH)));preg_match('/-(\d+)@/',$email,$match);$i=(int)($match[1]??0);
  if($i%3===0){return ['status'=>404,'data'=>['code'=>1001]];}
  return ['status'=>200,'data'=>['id'=>'zoom-scan-'.$i,'email'=>$email,'account_id'=>'scan-account','role_id'=>'2','status'=>'active','type'=>1]];
 });
 $SESSION->local_districtupdate_zoom=['token'=>'old-approval'];
 $status=\local_districtupdate\zoom_lookup::scan_start($rid,$cid);
 du_scan_check($status['total']===600&&!isset($SESSION->local_districtupdate_zoom),'Scan does not cover all 24 pages or retained an approval token.');
 $first=\local_districtupdate\zoom_lookup::scan_step($status['runid'],$rid,$cid,$api);
 du_scan_check($first['checked']===5&&!$first['done'],'Scan chunk is not bounded.');
 $status=\local_districtupdate\zoom_lookup::scan_status($rid,$cid);
 du_scan_check($status['checked']===5,'Progress cannot be resumed.');
 $steps=1;
 while(!$status['done']){$status=\local_districtupdate\zoom_lookup::scan_step($status['runid'],$rid,$cid,$api);$steps++;}
 du_scan_check($steps===120&&$status['checked']===600&&$status['found']===400&&$status['absent']===200&&$status['blocked']===0,'All-page totals are incorrect: '.json_encode($status));
 du_scan_check(count(array_filter($calls,fn($method)=>$method==='GET'))===600&&!in_array('DELETE',$calls,true),'Scan skipped accounts or attempted deletion.');
 $cached=\local_districtupdate\zoom_lookup::results();du_scan_check(count($cached)===600,'Cross-page filter did not retain all 600 checks.');
 foreach($DB->get_records('local_districtupdate_roster',['reviewid'=>$rid]) as $row){
  du_scan_check($row->processstate==='done'&&($row->zoomstate==='pending'||(int)$row->id===$ids[601]),'Scan changed processing status.');
 }
 du_scan_check($DB->count_records('local_districtupdate_audit')===$before['local_districtupdate_audit'],'Read-only scan changed audit or invoked processing.');
 echo "PASS: 600 departures across 24 pages checked in 120 resumable chunks; 400 found / 200 absent; transfers and completed deletions excluded. No deletion token, AD changes, audit mutations or real HTTP.\n";
}catch(Throwable $e){$failure=$e;}
try{$tx->rollback(new du_scan_rollback());}catch(du_scan_rollback $expected){}
if($failure){throw $failure;}
foreach($tables as $t){du_scan_check($DB->count_records($t)===$before[$t],'Fixture remained in '.$t);}
echo "PASS: all 602 temporary users and their review data rolled back.\n";
