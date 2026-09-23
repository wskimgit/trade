<?php
/**
 * Trade Low-Load Runner Web Control v1.4.6
 * START/STOP/STATUS from browser only. No .sh file and no recurring cron required.
 * PHP 7.4+
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors','0');
error_reporting(E_ALL);

const RC_VERSION='v1.4.6';
const RC_REV='trade-low-load-runner-web-control-v146-approval-actionable-gate-20260923-r1';
const RC_REQUIRED_AUTH='SINGLE_FILE_PAPER';
const RC_EXPECTED_RUNNER_VERSION='v1.4.6';

function rc_base(): string {
    $e=(string)getenv('TRADE_BASE_DIR');
    return rtrim($e!==''?$e:__DIR__,'/');
}
function rc_runtime(): string { return rc_base().'/trade_runner_runtime'; }
function rc_paths(): array {
    $r=rc_runtime();$b=rc_base();
    return [
        'runtime'=>$r,
        'runner'=>$b.'/trade_runner.php',
        'config'=>$b.'/trade_runner_config.php',
        'marker'=>$b.'/trade_phase3b_lite_v100/authority.json',
        'canonical'=>$b.'/trade_runtime_single/trade_state.json',
        'pid'=>$r.'/daemon.pid',
        'hb'=>$r.'/daemon_heartbeat.json',
        'stop'=>$r.'/daemon.stop',
        'lock'=>$r.'/daemon.lock',
        'stdout'=>$r.'/daemon_stdout.log',
        'control_log'=>$r.'/control.log',
    ];
}
function rc_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function rc_json_load(string $f): array {
    if(!is_file($f))return[];$raw=@file_get_contents($f);$j=json_decode((string)$raw,true);return is_array($j)?$j:[];
}
function rc_json($v,bool $pretty=false): string {
    $s=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|($pretty?JSON_PRETTY_PRINT:0));return is_string($s)?$s:'{}';
}
function rc_mkdir(string $d): bool { return is_dir($d)||(@mkdir($d,0775,true)||is_dir($d)); }
function rc_tail(string $f,int $max=16384): string {
    if(!is_file($f))return'';$sz=(int)@filesize($f);$h=@fopen($f,'rb');if(!$h)return'';if($sz>$max)@fseek($h,-$max,SEEK_END);$r=(string)stream_get_contents($h);@fclose($h);return trim($r);
}

function rc_installed_runner_info(): array {
    $p=rc_paths();$f=$p['runner'];
    if(!is_file($f))return['ok'=>false,'path'=>$f,'reason'=>'RUNNER_MISSING'];
    $raw=@file_get_contents($f);
    if(!is_string($raw))return['ok'=>false,'path'=>$f,'reason'=>'RUNNER_UNREADABLE'];
    $ver='';$rev='';
    if(preg_match("/const\s+TR_VERSION\s*=\s*'([^']+)'/",$raw,$m)===1)$ver=(string)$m[1];
    if(preg_match("/const\s+TR_REV\s*=\s*'([^']+)'/",$raw,$m)===1)$rev=(string)$m[1];
    $sha=@hash_file('sha256',$f);
    $mtime=@filemtime($f);
    return[
        'ok'=>$ver!=='',
        'path'=>$f,
        'version'=>$ver,
        'rev'=>$rev,
        'sha256'=>is_string($sha)?$sha:'',
        'size_bytes'=>(int)@filesize($f),
        'mtime_epoch'=>is_int($mtime)?$mtime:0,
        'mtime'=>is_int($mtime)&&$mtime>0?date('Y-m-d H:i:s',$mtime):'',
        'expected_version'=>RC_EXPECTED_RUNNER_VERSION,
        'version_match'=>$ver===RC_EXPECTED_RUNNER_VERSION,
    ];
}

function rc_csrf_secret(): string {
    $f=rc_runtime().'/control_secret';if(!rc_mkdir(rc_runtime()))return'';$s=trim((string)@file_get_contents($f));if(strlen($s)>=32)return$s;
    try{$s=bin2hex(random_bytes(32));}catch(Throwable $e){$s=hash('sha256',microtime(true).getmypid().mt_rand());}
    if(@file_put_contents($f,$s."\n",LOCK_EX)===false)return'';@chmod($f,0600);return$s;
}
function rc_csrf_token(string $secret,int $slot=0): string {if($slot===0)$slot=(int)floor(time()/1800);return hash_hmac('sha256','trade_runner_control_v144|'.$slot,$secret);}
function rc_csrf_valid(string $got,string $secret): bool {if($got===''||$secret==='')return false;$slot=(int)floor(time()/1800);return hash_equals(rc_csrf_token($secret,$slot),$got)||hash_equals(rc_csrf_token($secret,$slot-1),$got);}

function rc_log(string $event,array $data=[]): void {
    $p=rc_paths();if(!rc_mkdir($p['runtime']))return;$row=['at'=>date('Y-m-d H:i:s'),'event'=>$event]+$data;@file_put_contents($p['control_log'],rc_json($row).PHP_EOL,FILE_APPEND|LOCK_EX);
}
function rc_authority_gate(): array {
    $p=rc_paths();$m=rc_json_load($p['marker']);
    if(!$m)return['ok'=>false,'reason'=>'AUTHORITY_MARKER_MISSING','file'=>$p['marker']];
    $a=strtoupper((string)($m['authority']??''));
    if($a!==RC_REQUIRED_AUTH)return['ok'=>false,'reason'=>'AUTHORITY_NOT_SINGLE_FILE_PAPER','authority'=>$a];
    if(!empty($m['real_order_allowed']))return['ok'=>false,'reason'=>'REAL_FLAG_TRUE'];
    if(!is_file($p['canonical']))return['ok'=>false,'reason'=>'CANONICAL_MISSING'];
    return['ok'=>true,'authority'=>$a,'real_order_allowed'=>false];
}
function rc_php_bin(): string {
    $c=['/usr/local/bin/php74','/usr/bin/php74','/usr/local/bin/php','/usr/bin/php'];
    foreach($c as$f)if(is_file($f)&&is_executable($f))return$f;
    $b=(string)PHP_BINARY;if($b!==''&&is_file($b)&&is_executable($b)&&stripos(basename($b),'fpm')===false)return$b;
    return'';
}
function rc_shell_bin(): string { foreach(['/bin/sh','/usr/bin/sh']as$f)if(is_file($f)&&is_executable($f))return$f;return''; }
function rc_detach_prefix(): array {
    foreach(['/usr/bin/nohup','/bin/nohup','/usr/local/bin/nohup']as$f)if(is_file($f)&&is_executable($f))return['method'=>'nohup','prefix'=>escapeshellarg($f).' '];
    foreach(['/usr/bin/setsid','/bin/setsid']as$f)if(is_file($f)&&is_executable($f))return['method'=>'setsid','prefix'=>escapeshellarg($f).' '];
    return['method'=>'background','prefix'=>''];
}
function rc_pid_info(): array {
    $p=rc_paths();$raw=trim((string)@file_get_contents($p['pid']));$pid=(ctype_digit($raw)?(int)$raw:0);$exists=$pid>1&&is_dir('/proc/'.$pid);$cmd='';$match=false;
    if($exists){$x=@file_get_contents('/proc/'.$pid.'/cmdline');if(is_string($x)){$cmd=trim(str_replace("\0",' ',$x));$match=strpos($cmd,'trade_runner.php')!==false&&preg_match('/(?:^|\s)daemon(?:\s|$)/',$cmd)===1;}}
    return['pid'=>$pid,'pid_file'=>$raw,'process_exists'=>$exists,'identity_match'=>$match,'cmdline'=>$cmd];
}
function rc_status(): array {
    $p=rc_paths();$pi=rc_pid_info();$hb=rc_json_load($p['hb']);$epoch=(int)($hb['epoch']??0);$age=$epoch>0?max(0,time()-$epoch):null;$fresh=$age!==null&&$age<=45;$stop=is_file($p['stop']);
    $state='STOPPED';
    if($pi['process_exists']&&$pi['identity_match']){
        if($stop)$state='STOP_PENDING';
        elseif($fresh)$state='RUNNING';
        else $state='PROCESS_ALIVE_HEARTBEAT_STALE';
    }elseif($pi['process_exists']&&!$pi['identity_match'])$state='PID_REUSED_OR_FOREIGN';
    elseif($pi['pid']>0)$state='STALE_PID_FILE';
    $installed=rc_installed_runner_info();$daemonVersion=(string)($hb['version']??'');$daemonVersionMatch=$daemonVersion!==''&&$daemonVersion===RC_EXPECTED_RUNNER_VERSION;
    return['ok'=>true,'version'=>RC_VERSION,'rev'=>RC_REV,'expected_runner_version'=>RC_EXPECTED_RUNNER_VERSION,'state'=>$state,'installed_runner'=>$installed,'process'=>$pi,'heartbeat'=>$hb,'heartbeat_age_sec'=>$age,'heartbeat_fresh'=>$fresh,'daemon_version_match'=>$daemonVersionMatch,'stop_file'=>$stop,'authority'=>rc_authority_gate(),'web_euid'=>function_exists('posix_geteuid')?posix_geteuid():null,'php_bin'=>rc_php_bin(),'shell_bin'=>rc_shell_bin(),'detach'=>rc_detach_prefix()];
}
function rc_proc_wait($proc,float $timeout): array {
    $t=microtime(true);$exit=null;$timed=false;
    while(true){$st=proc_get_status($proc);if(!$st['running']){$exit=(int)$st['exitcode'];break;}if(microtime(true)-$t>$timeout){$timed=true;@proc_terminate($proc,15);break;}usleep(100000);}
    $pc=@proc_close($proc);if($exit===null||$exit<0)$exit=is_int($pc)?$pc:1;if($timed)$exit=124;return['exit_code'=>$exit,'timed_out'=>$timed];
}
function rc_health_probe(): array {
    $p=rc_paths();$php=rc_php_bin();if($php==='')return['ok'=>false,'reason'=>'PHP_CLI_NOT_FOUND'];if(!is_file($p['runner']))return['ok'=>false,'reason'=>'RUNNER_MISSING'];if(!function_exists('proc_open'))return['ok'=>false,'reason'=>'PROC_OPEN_UNAVAILABLE'];
    if(!rc_mkdir($p['runtime']))return['ok'=>false,'reason'=>'RUNTIME_CREATE_FAILED'];$out=$p['runtime'].'/control_health_probe.json';$err=$p['runtime'].'/control_health_probe.err';@unlink($out);@unlink($err);
    $cmd=escapeshellarg($php).' '.escapeshellarg($p['runner']).' health';$desc=[0=>['file','/dev/null','r'],1=>['file',$out,'w'],2=>['file',$err,'w']];$proc=@proc_open($cmd,$desc,$pipes,rc_base());if(!is_resource($proc))return['ok'=>false,'reason'=>'HEALTH_PROC_OPEN_FAILED'];$w=rc_proc_wait($proc,15.0);$raw=(string)@file_get_contents($out);$j=json_decode($raw,true);
    if(!is_array($j))return['ok'=>false,'reason'=>'HEALTH_JSON_INVALID','exit_code'=>$w['exit_code'],'raw_tail'=>substr($raw,-4096),'stderr'=>rc_tail($err,4096)];
    $failed=[];foreach((array)($j['checks']??[])as$ck)if(is_array($ck)&&empty($ck['ok']))$failed[]=['name'=>(string)($ck['name']??'UNKNOWN'),'severity'=>(string)($ck['severity']??'hard'),'detail'=>$ck['detail']??null];
    $startupOk=array_key_exists('startup_ok',$j)?!empty($j['startup_ok']):!empty($j['ok']);
    $ok=$startupOk&&($w['exit_code']===0);
    return['ok'=>$ok,'exit_code'=>$w['exit_code'],'runner_health'=>['ok'=>$j['ok']??false,'startup_ok'=>$startupOk,'all_checks_ok'=>$j['all_checks_ok']??($j['ok']??false),'version'=>$j['version']??'','rev'=>$j['rev']??'','checks_pass'=>$j['checks_pass']??null,'checks_total'=>$j['checks_total']??null,'hard_failure_count'=>$j['hard_failure_count']??null,'warning_count'=>$j['warning_count']??null,'failed_checks'=>$failed,'hard_failures'=>$j['hard_failures']??[],'warnings'=>$j['warnings']??[]],'stderr'=>rc_tail($err,4096)];
}
function rc_start(): array {
    $p=rc_paths();$gate=rc_authority_gate();if(empty($gate['ok']))return['ok'=>false,'reason'=>'AUTHORITY_GATE','detail'=>$gate];
    $installed=rc_installed_runner_info();if(empty($installed['ok']))return['ok'=>false,'reason'=>'INSTALLED_RUNNER_UNREADABLE','installed_runner'=>$installed];
    if(empty($installed['version_match']))return['ok'=>false,'reason'=>'INSTALLED_RUNNER_VERSION_MISMATCH','expected'=>RC_EXPECTED_RUNNER_VERSION,'installed_runner'=>$installed];
    $st=rc_status();if(!empty($st['process']['process_exists'])&&!empty($st['process']['identity_match']))return['ok'=>false,'reason'=>'DAEMON_PROCESS_ALREADY_EXISTS','status'=>$st];
    if(!function_exists('proc_open'))return['ok'=>false,'reason'=>'PROC_OPEN_UNAVAILABLE'];$php=rc_php_bin();$sh=rc_shell_bin();if($php===''||$sh==='')return['ok'=>false,'reason'=>'CLI_OR_SHELL_MISSING','php'=>$php,'shell'=>$sh];if(!rc_mkdir($p['runtime'])||!is_writable($p['runtime']))return['ok'=>false,'reason'=>'RUNTIME_NOT_WRITABLE','runtime'=>$p['runtime']];
    $health=rc_health_probe();if(empty($health['ok']))return['ok'=>false,'reason'=>'RUNNER_HEALTH_BLOCK','failed_checks'=>$health['runner_health']['failed_checks']??[],'health'=>$health];
    $healthVersion=(string)($health['runner_health']['version']??'');if($healthVersion!==RC_EXPECTED_RUNNER_VERSION)return['ok'=>false,'reason'=>'RUNNER_HEALTH_VERSION_MISMATCH','expected'=>RC_EXPECTED_RUNNER_VERSION,'health'=>$health,'installed_runner'=>$installed];
    @unlink($p['stop']);$d=rc_detach_prefix();$inner='cd '.escapeshellarg(rc_base()).' && '.$d['prefix'].escapeshellarg($php).' '.escapeshellarg($p['runner']).' daemon >> '.escapeshellarg($p['stdout']).' 2>&1 < /dev/null & echo $!';$cmd=escapeshellarg($sh).' -c '.escapeshellarg($inner);
    $desc=[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']];$proc=@proc_open($cmd,$desc,$pipes,rc_base());if(!is_resource($proc))return['ok'=>false,'reason'=>'START_PROC_OPEN_FAILED'];$out=(string)stream_get_contents($pipes[1]);$err=(string)stream_get_contents($pipes[2]);@fclose($pipes[1]);@fclose($pipes[2]);$pc=@proc_close($proc);$spawnPid=(int)trim($out);rc_log('START_REQUEST',['method'=>$d['method'],'spawn_pid'=>$spawnPid,'shell_exit'=>$pc,'stderr'=>trim($err)]);
    $deadline=microtime(true)+8.0;$last=[];do{usleep(200000);$last=rc_status();$procPid=(int)($last['process']['pid']??0);$hbPid=(int)($last['heartbeat']['pid']??0);$hbVer=(string)($last['heartbeat']['version']??'');if(!empty($last['process']['identity_match'])&&!empty($last['heartbeat_fresh'])&&$procPid>1&&$hbPid===$procPid&&$hbVer===RC_EXPECTED_RUNNER_VERSION)return['ok'=>true,'reason'=>'STARTED_VERSION_VERIFIED','method'=>$d['method'],'spawn_pid'=>$spawnPid,'status'=>$last,'health'=>$health,'installed_runner'=>$installed];}while(microtime(true)<$deadline);
    return['ok'=>false,'reason'=>'START_NOT_VERSION_VERIFIED','expected_runner_version'=>RC_EXPECTED_RUNNER_VERSION,'method'=>$d['method'],'spawn_pid'=>$spawnPid,'shell_exit'=>$pc,'shell_stderr'=>trim($err),'status'=>$last,'installed_runner'=>$installed,'daemon_log_tail'=>rc_tail($p['stdout'],8192)];
}
function rc_stop(): array {
    $p=rc_paths();$st=rc_status();$pi=$st['process'];if(empty($pi['process_exists'])||empty($pi['identity_match'])){if(!empty($pi['pid'])&&!$pi['process_exists'])@unlink($p['pid']);@unlink($p['stop']);return['ok'=>true,'reason'=>'ALREADY_STOPPED','status'=>rc_status()];}
    if(!rc_mkdir($p['runtime'])||@file_put_contents($p['stop'],date('c')."\n",LOCK_EX)===false)return['ok'=>false,'reason'=>'STOP_REQUEST_WRITE_FAILED'];rc_log('STOP_REQUEST',['pid'=>$pi['pid']]);$deadline=microtime(true)+7.0;$last=[];do{usleep(250000);$last=rc_status();if(empty($last['process']['process_exists'])||empty($last['process']['identity_match']))return['ok'=>true,'reason'=>'STOPPED','status'=>$last];}while(microtime(true)<$deadline);
    return['ok'=>true,'reason'=>'STOP_REQUESTED_WAITING_FOR_CURRENT_JOB','status'=>$last,'note'=>'현재 child job이 실행 중이면 job 종료 후 daemon이 안전하게 정지합니다.'];
}
function rc_handle_web(): void {
    if(!headers_sent()){header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');header('X-Trade-Runner-Control-Revision: '.RC_REV);}
    $secret=rc_csrf_secret();$token=rc_csrf_token($secret);$result=null;
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){$got=(string)($_POST['csrf']??'');if(!rc_csrf_valid($got,$secret))$result=['ok'=>false,'reason'=>'CSRF'];else{$a=(string)($_POST['action']??'');if($a==='start')$result=rc_start();elseif($a==='stop')$result=rc_stop();else$result=['ok'=>false,'reason'=>'UNKNOWN_ACTION'];}}
    $s=rc_status();$gate=$s['authority'];$installed=$s['installed_runner']??[];$canStart=!empty($gate['ok'])&&empty($s['process']['process_exists'])&&!empty($installed['version_match']);$canStop=!empty($s['process']['process_exists'])&&!empty($s['process']['identity_match']);$cls=in_array($s['state'],['RUNNING'],true)?'ok':(in_array($s['state'],['STOP_PENDING','PROCESS_ALIVE_HEARTBEAT_STALE'],true)?'warn':'bad');
    header('Content-Type:text/html; charset=utf-8');?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Trade Runner Control</title><style>body{font-family:system-ui;background:#f4f6f8;margin:0;color:#222}main{max-width:980px;margin:auto;padding:16px}.box,.card,pre{background:#fff;border:1px solid #ddd;border-radius:9px;padding:14px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:9px}.big{font-size:21px;font-weight:800}.ok{color:#087f5b}.bad{color:#c92a2a}.warn{color:#e67700}button{font-size:17px;padding:11px 20px;margin:4px;border:0;border-radius:7px;cursor:pointer}.start{background:#087f5b;color:#fff}.stop{background:#c92a2a;color:#fff}button:disabled{opacity:.35;cursor:not-allowed}pre{white-space:pre-wrap;overflow:auto;font-size:11px}a{color:#1864ab}</style></head><body><main><h1>Trade Low-Load Runner WEB Control <?=rc_h(RC_VERSION)?></h1><div class="cards"><div class="card">Daemon<div class="big <?=$cls?>"><?=rc_h($s['state'])?></div><small>PID <?=rc_h($s['process']['pid']?:'-')?> · HB <?=rc_h($s['heartbeat_age_sec']===null?'-':$s['heartbeat_age_sec'].'s')?></small></div><div class="card">설치된 Runner<div class="big <?=!empty($installed['version_match'])?'ok':'bad'?>"><?=rc_h($installed['version']??'-')?></div><small>기대 <?=rc_h(RC_EXPECTED_RUNNER_VERSION)?> · SHA <?=rc_h(substr((string)($installed['sha256']??''),0,12))?></small></div><div class="card">실행 daemon 버전<div class="big <?=!empty($s['daemon_version_match'])?'ok':($s['state']==='STOPPED'?'':'bad')?>"><?=rc_h($s['heartbeat']['version']??'-')?></div><small><?=rc_h($s['heartbeat']['rev']??'')?></small></div><div class="card">Authority<div class="big"><?=rc_h($gate['authority']??($gate['reason']??'-'))?></div></div><div class="card">REAL<div class="big <?=!empty($gate['ok'])?'ok':'bad'?>"><?=!empty($gate['real_order_allowed'])?'true':'false'?></div></div><div class="card">Heartbeat action<div class="big"><?=rc_h($s['heartbeat']['action']??'-')?></div><small><?=rc_h($s['heartbeat']['job']??'')?></small></div></div><div class="box" style="margin-top:10px"><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=rc_h($token)?>"><input type="hidden" name="action" value="start"><button class="start" <?=$canStart?'':'disabled'?>>START</button></form><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=rc_h($token)?>"><input type="hidden" name="action" value="stop"><button class="stop" <?=$canStop?'':'disabled'?>>STOP</button></form> <a href="/trade_runner_control.php">STATUS 새로고침</a> · <a href="/trade_runner_status.php">상세 Status</a> · <a href="/trade_runner_preflight.php">Preflight</a><p>START는 SINGLE_FILE_PAPER + REAL=false이고 <b>설치된 Runner가 <?=rc_h(RC_EXPECTED_RUNNER_VERSION)?>로 확인될 때만</b> 허용됩니다. START 성공은 새 daemon heartbeat도 <?=rc_h(RC_EXPECTED_RUNNER_VERSION)?>일 때만 인정합니다. STOP은 현재 작업을 강제 종료하지 않고 안전한 정지를 요청합니다.</p></div><?php if($result!==null):?><h2>실행 결과</h2><pre><?=rc_h(rc_json($result,true))?></pre><?php endif;?><h2>현재 상태</h2><pre><?=rc_h(rc_json($s,true))?></pre></main></body></html><?php
}
if(isset($_SERVER['SCRIPT_FILENAME'])&&realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__){if(PHP_SAPI==='cli'){echo rc_json(rc_status(),true).PHP_EOL;exit(0);}rc_handle_web();}