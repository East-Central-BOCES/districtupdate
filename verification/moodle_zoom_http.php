<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/** Real Moodle HTTP/form regression. Dummy credentials and a loopback receiver only; no Zoom traffic. */
if (PHP_SAPI !== 'cli' || empty($argv[1]) || ($argv[2] ?? '') !== '--test-site') { exit("Usage: php moodle_zoom_http.php /path/to/config.php --test-site\n"); }
define('CLI_SCRIPT',true); require $argv[1];
$CFG->forced_plugin_settings['local_districtupdate']['zoomaccountid']='fixture-account';
$CFG->forced_plugin_settings['local_districtupdate']['zoomclientid']='fixture-client';
$CFG->forced_plugin_settings['local_districtupdate']['zoomclientsecret']='fixture-secret';
// These overrides live only for this CLI test, never in the site configuration.
$CFG->curlsecurityblockedhosts=''; $CFG->curlsecurityallowedport='';
$previous=ini_get('arg_separator.output'); ini_set('arg_separator.output','&amp;');
$dir=make_request_directory(); $router=$dir.'/zoom-router.php'; $log=$dir.'/receiver.log';
file_put_contents($router, <<<'ROUTER'
<?php
header('Content-Type: application/json');
$valid=$_SERVER['REQUEST_METHOD']==='POST'
 && ($_SERVER['HTTP_AUTHORIZATION']??'')==='Basic '.base64_encode('fixture-client:fixture-secret')
 && ($_POST['grant_type']??'')==='account_credentials' && ($_POST['account_id']??'')==='fixture-account';
http_response_code($valid?200:400);
echo json_encode($valid?['access_token'=>'fixture-token','token_type'=>'bearer']:['error'=>'invalid_request']);
ROUTER);
$port=18791;
$process=proc_open([PHP_BINARY,'-S','127.0.0.1:'.$port,$router],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes);
try {
 if(!is_resource($process)){throw new RuntimeException('Cannot start loopback receiver.');}
 $ready=false;
 for($i=0;$i<30;$i++){$s=@fsockopen('127.0.0.1',$port,$errno,$errstr,0.1);if($s){fclose($s);$ready=true;break;}usleep(100000);}
 if(!$ready){throw new RuntimeException('Loopback receiver did not start.');}
 $http=new ReflectionMethod(\local_districtupdate\zoom_api::class,'http'); $direct=new \local_districtupdate\zoom_api(); $sent=false;
 $api=new \local_districtupdate\zoom_api(function($method,$url,$headers,$body)use($http,$direct,$port,&$sent){
  if($method==='POST' && $url==='https://zoom.us/oauth/token'){
   $sent=true; return $http->invoke($direct,$method,'http://127.0.0.1:'.$port.'/token',$headers,$body);
  }
  if($method==='GET'){return ['status'=>404,'data'=>['code'=>1001]];}
  throw new RuntimeException('Unexpected request in read-only HTTP test.');
 });
 if($api->user('fixture@example.com')!==null||!$sent){throw new RuntimeException('OAuth exchange was not tested.');}
 echo "PASS: real Moodle HTTP client sends the correct Basic header and both OAuth form parameters with arg_separator.output=&amp;. Dummy credentials, loopback only; no Zoom requests or deletions.\n";
}finally{
 ini_set('arg_separator.output',$previous);
 if(is_resource($process)){proc_terminate($process);proc_close($process);}
}
