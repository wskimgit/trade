<?php
/**
 * trade_runner_preflight.php
 * Low-Load Runner WEB Preflight v1.4.6
 * Current-contract verifier for Runner v1.4.6 / Engine v4.4.6 / Broker v5.9.8.
 * PHP 7.4 compatible.
 *
 * This replaces the retired v1.4.2 exact-hash preflight. It does not mutate
 * orders, positions, strategy state, validation state, or scheduler state.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors','0');
error_reporting(E_ALL);

const PF_VERSION='v1.4.6';
const PF_REV='trade-low-load-runner-web-preflight-v146-order-path-recovery-20260923-r2';

function pf_add(array &$checks,string $name,bool $ok,$detail='',string $severity='hard'): void {
    $checks[]=['name'=>$name,'ok'=>$ok,'severity'=>$severity,'detail'=>$detail];
}
function pf_sha(string $f): string {return is_file($f)?(string)(@hash_file('sha256',$f)?:''):'';}
function pf_json(string $f,array $d=[]): array {
    if(!is_file($f))return$d;$raw=@file_get_contents($f);$j=json_decode((string)$raw,true);return is_array($j)?$j:$d;
}
function pf_source_has(string $f,string $marker): bool {
    if(!is_file($f))return false;$s=@file_get_contents($f);return is_string($s)&&strpos($s,$marker)!==false;
}
function pf_php_bin(): string {
    foreach(['/usr/local/bin/php74','/usr/local/bin/php','/usr/bin/php','/bin/php']as$p)if(is_file($p)&&is_executable($p))return$p;
    return '';
}
function pf_lint(string $php,string $file): array {
    if($php===''||!is_file($file))return['ok'=>false,'reason'=>'MISSING'];
    $cmd=escapeshellarg($php).' -l '.escapeshellarg($file).' 2>&1';
    $out=[];$rc=1;@exec($cmd,$out,$rc);return['ok'=>$rc===0,'exit_code'=>$rc,'output'=>implode("\n",$out)];
}
function pf_spawn_probe(string $php,string $base): array {
    if($php===''||!function_exists('proc_open'))return['ok'=>false,'reason'=>'UNAVAILABLE'];
    $out=$base.'/trade_runner_runtime/.preflight_spawn_'.getmypid().'.out';
    $err=$base.'/trade_runner_runtime/.preflight_spawn_'.getmypid().'.err';
    @unlink($out);@unlink($err);
    $cmd=escapeshellarg($php).' -r '.escapeshellarg('echo "WEB_SPAWN_OK";');
    $desc=[0=>['file','/dev/null','r'],1=>['file',$out,'w'],2=>['file',$err,'w']];
    $p=@proc_open($cmd,$desc,$pipes,$base);
    if(!is_resource($p))return['ok'=>false,'reason'=>'PROC_OPEN_FAILED'];
    $t=microtime(true);$exit=null;
    while(true){$st=proc_get_status($p);if(!$st['running']){$exit=(int)$st['exitcode'];break;}if(microtime(true)-$t>5){@proc_terminate($p,15);$exit=124;break;}usleep(100000);}
    $pc=@proc_close($p);if($exit===null||$exit<0)$exit=is_int($pc)?$pc:1;
    $stdout=trim((string)@file_get_contents($out));$stderr=trim((string)@file_get_contents($err));@unlink($out);@unlink($err);
    return['ok'=>$exit===0&&$stdout==='WEB_SPAWN_OK','exit_code'=>$exit,'stdout'=>$stdout,'stderr'=>$stderr,'php'=>$php];
}
function pf_h($s): string {return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}

$base=__DIR__;
$checks=[];

pf_add($checks,'PHP >= 7.4',PHP_VERSION_ID>=70400,PHP_VERSION);

$exact=[
 'trade_engine.php'=>'d8f51e0f958b02f10077b707ee1f86451b5716858317ddfbf695c29ddd4a0289',
 'trade_broker.php'=>'50911aae3d120eb0e61c2b814e0838c10d059374fcff4c36dde8d3a9fba03f06',
 'dts.php'=>'fe226ac726485cec5df0fd324d6ae306e1e3fdfcb344ed604282079d0d574a99',
 'abc.php'=>'be44d87292a836c393b1c2b76b2c9a0381cca93996326220028c93bfa8d8ead5',
 'das.php'=>'7f314fdda15581516899223f5dbf1472aab6c08104ca9e8e0e2c5687fc066fda',
 'stc26.php'=>'270c3e0a02471b8279e177898c2c4f08329b69fec9428d107f8ea4221ea06414',
 'trade_store_v103.php'=>'ec3fc8168607683f022b8ffb40a5ecc8c66cd2d82b604a08e69a1cc006d729e6',
 'trade_runner.php'=>'41b0700a277a10127bf481fde49f290cb5f934ef87ff64ec04c2238005feaefb',
 'trade_runner_config.php'=>'2e12b834b80c7066333080201268cb7c255d9c62ec3481ce36964b71f9bfa268',
 'trade_runner_control.php'=>'65b9b5357d5bdb90370bb61abda711d1d5e0d5f33ad023133ec09ede02b530c8',
 'trade_runner_status.php'=>'6e9c749e7f5c08ae2e6e42bf74b4ced3665a2e89453ccd3f858a88f2a9304b28',
 'trade_validation.php'=>'94fcbd660fcd722c9a28be82df821e07157b36b7af622af70a09cd1798f652d8',
 'trade_validation_repair.php'=>'194d9e526f36e941f51463d09b14d22fc66922df3958a06c0acb3acecfa044b4',
 'trade_pipeline_diag.php'=>'c00379e416df899057864ebd97b47000a743c69a51b956e6b483cd50e18ea801',
];
foreach($exact as$f=>$want){$have=pf_sha($base.'/'.$f);pf_add($checks,'Exact '.$f,$have===$want,['expected'=>$want,'actual'=>$have,'file'=>$base.'/'.$f]);}

pf_add($checks,'Engine v4.4.6 identity',pf_source_has($base.'/trade_engine.php','trade-engine-v446-sell-exchange-provenance-20260923-r1'),'SELL exchange provenance recovery');
pf_add($checks,'Broker v5.9.9 identity',pf_source_has($base.'/trade_broker.php','trade-broker-v599-numeric-exchange-key-preserve-20260923-r1')&&pf_source_has($base.'/trade_broker.php','ORDER_EXCHANGE_NUMERIC_SYMBOL_KEY_PRESERVE'),'numeric JP symbol exchange-map key preservation');
pf_add($checks,'Runner v1.4.6 identity',pf_source_has($base.'/trade_runner.php','trade-low-load-runner-v146-approval-actionable-gate-20260923-r1'),'approval/actionable gate');
pf_add($checks,'Runner Control v1.4.6 identity',pf_source_has($base.'/trade_runner_control.php','trade-low-load-runner-web-control-v146-approval-actionable-gate-20260923-r1'),'control version gate');
pf_add($checks,'3+1 Preflight v1.3.16 identity',pf_source_has($base.'/trade_3plus1_preflight.php','trade-3plus1-preflight-v1316-order-path-recovery-contract-20260923-r1'),'order-path recovery contract');

$auth=pf_json($base.'/trade_phase3b_lite_v100/authority.json');
pf_add($checks,'Authority SINGLE_FILE_PAPER',strtoupper((string)($auth['authority']??''))==='SINGLE_FILE_PAPER',$auth);
pf_add($checks,'REAL false',empty($auth['real_order_allowed']),$auth['real_order_allowed']??null);

$canonicalFile=$base.'/trade_runtime_single/trade_state.json';
$canonical=pf_json($canonicalFile);
pf_add($checks,'Canonical state readable',($canonical['schema']??'')==='trade_state_v1',['revision'=>$canonical['revision']??null,'bytes'=>is_file($canonicalFile)?filesize($canonicalFile):0]);
$canonicalMode=strtoupper(trim((string)($canonical['mode']??$canonical['execution_mode']??'')));
$authorityPaper=strtoupper((string)($auth['authority']??''))==='SINGLE_FILE_PAPER'&&empty($auth['real_order_allowed']);
$canonicalPaperOk=$canonicalMode===''?$authorityPaper:($canonicalMode==='PAPER');
pf_add($checks,'Canonical PAPER authority contract',$canonicalPaperOk,['canonical_mode'=>$canonicalMode!==''?$canonicalMode:'NOT_STORED','basis'=>$canonicalMode!==''?'CANONICAL_FIELD':'SINGLE_FILE_PAPER_AUTHORITY','real_order_allowed'=>$auth['real_order_allowed']??null]);
pf_add($checks,'Canonical state < 2 MiB soft limit',is_file($canonicalFile)&&filesize($canonicalFile)<2097152,is_file($canonicalFile)?filesize($canonicalFile):0,'warning');

$php=pf_php_bin();
$lint=pf_lint($php,$base.'/trade_runner.php');
pf_add($checks,'Runner PHP 7.4 lint',$lint['ok'],$lint);

$runnerLoaded=false;$loadError='';
if(!empty($lint['ok'])){
    try{require_once $base.'/trade_runner.php';$runnerLoaded=defined('TR_VERSION')&&function_exists('tr_transport_class');}
    catch(Throwable $e){$loadError=$e->getMessage();}
}
pf_add($checks,'Runner v1.4.6 loadable',$runnerLoaded&&TR_VERSION==='v1.4.6',['version'=>$runnerLoaded?TR_VERSION:'NOT_LOADED','rev'=>$runnerLoaded&&defined('TR_REV')?TR_REV:'','error'=>$loadError]);

if($runnerLoaded){
    $cfg=tr_config();
    $classes=[
      tr_transport_class(['ok'=>false,'integrity_error'=>true,'pending_count'=>1]),
      tr_transport_class(['ok'=>false,'integrity_error'=>false,'pending_count'=>1]),
      tr_transport_class(['ok'=>true,'pending_count'=>1]),
      tr_transport_class(['ok'=>true,'pending_count'=>0]),
    ];
    pf_add($checks,'Transport health outranks pending',$classes===['INTEGRITY_ERROR','TRANSIENT_ERROR','PENDING','CLEAR'],$classes);

    $seq=[];foreach([1,2,3,4,5,6]as$n)$seq[]=tr_transport_backoff_seconds($n,$cfg);
    pf_add($checks,'Transport backoff exact bounded sequence',$seq===[30,60,120,240,300,300],$seq);

    $sleep=[
      tr_daemon_cycle_sleep(['wait_sec'=>300,'transport_pulse_required'=>true],$cfg),
      tr_daemon_cycle_sleep(['wait_sec'=>30,'transport_pulse_required'=>true],$cfg),
      tr_daemon_cycle_sleep(['wait_sec'=>0,'transport_pulse_required'=>true],$cfg),
    ];
    pf_add($checks,'Daemon honors wait/backoff before pulse',$sleep===[60,30,5],$sleep);

    pf_add($checks,'Canonical INTENT is NOT Broker proof',!tr_canonical_proves_ingest(['status'=>'INTENT']),false);
    pf_add($checks,'Explicit broker_seen IS Broker proof',tr_canonical_proves_ingest(['broker_ingest_state'=>'BROKER_SEEN']),true);

    $tmp=@tempnam(sys_get_temp_dir(),'trpf_ack_');$validAck=false;$badAck=false;
    if(is_string($tmp)&&$tmp!==''){
        @file_put_contents($tmp,json_encode(['schema'=>'broker_intent_ack_v1','owner'=>'broker','order_id'=>'PF-1','broker_seen_at'=>date('c')]));
        $validAck=tr_ack_proves_ingest($tmp,'PF-1');
        @file_put_contents($tmp,json_encode(['schema'=>'broker_intent_ack_v1','owner'=>'engine','order_id'=>'PF-1','broker_seen_at'=>date('c')]));
        $badAck=!tr_ack_proves_ingest($tmp,'PF-1');@unlink($tmp);
    }
    pf_add($checks,'Valid broker-owned ACK proves ingest',$validAck,$validAck);
    pf_add($checks,'ACK without broker owner rejected',$badAck,$badAck);

    $closedTs=(new DateTimeImmutable('2026-09-24 08:00:00',new DateTimeZone('Asia/Tokyo')))->getTimestamp();
    $wc1=tr_market_wait_class(['market'=>'JP','status'=>'PENDING','approved'=>false,'broker_message'=>''],$closedTs);
    $wc2=tr_market_wait_class(['market'=>'JP','status'=>'PENDING','approved'=>false,'approval_block_reason'=>'JP 거래소 코드 없음'],$closedTs);
    $wc3=tr_market_wait_class(['market'=>'JP','status'=>'PENDING','approved'=>true,'broker_message'=>''],$closedTs);
    pf_add($checks,'Unapproved PENDING remains ACTIONABLE when closed',empty($wc1['wait'])&&($wc1['source']??'')==='PENDING_APPROVAL_ACTIONABLE',$wc1);
    pf_add($checks,'Approval-blocked PENDING remains ACTIONABLE when closed',empty($wc2['wait'])&&($wc2['source']??'')==='APPROVAL_BLOCK_ACTIONABLE',$wc2);
    pf_add($checks,'Approved PENDING may become MARKET_WAIT when closed',!empty($wc3['wait'])&&($wc3['source']??'')==='SESSION_CLOSED_HINT',$wc3);

    $hol=[tr_market_holiday_date('JP','2026-09-21'),tr_market_holiday_date('JP','2026-09-22'),tr_market_holiday_date('JP','2026-09-23')];
    pf_add($checks,'JPX 2026-09-21/22/23 cash-market closures',$hol===[true,true,true],$hol);
    $from=(new DateTimeImmutable('2026-09-18 15:31:00',new DateTimeZone('Asia/Tokyo')))->getTimestamp();
    $next=tr_next_regular_market_open_epoch('JP',$from,false);
    $nextText=$next>0?(new DateTimeImmutable('@'.$next))->setTimezone(new DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i'):'';
    pf_add($checks,'JP next cash-session hint after 2026-09-18 close',$nextText==='2026-09-24 09:00',['expected'=>'2026-09-24 09:00','actual'=>$nextText]);

    pf_add($checks,'Transport gate enabled',!empty($cfg['transport_gate_enabled']),$cfg['transport_gate_enabled']??null);
    pf_add($checks,'Canonical truth cache <= 5s',(int)($cfg['canonical_gate_cache_ttl_sec']??999)<=5,$cfg['canonical_gate_cache_ttl_sec']??null);
    pf_add($checks,'Transport truth cache <= 5s',(int)($cfg['transport_gate_cache_ttl_sec']??999)<=5,$cfg['transport_gate_cache_ttl_sec']??null);
    pf_add($checks,'Transport pulse 2..10s',(int)($cfg['transport_pulse_sec']??0)>=2&&(int)($cfg['transport_pulse_sec']??0)<=10,$cfg['transport_pulse_sec']??null);
    pf_add($checks,'Transport Broker retry bounded',(int)($cfg['transport_broker_defer_sec']??0)===30&&(int)($cfg['transport_broker_retry_max_sec']??0)===300,['base'=>$cfg['transport_broker_defer_sec']??null,'max'=>$cfg['transport_broker_retry_max_sec']??null]);
    pf_add($checks,'Transport lightweight retry bounded',(int)($cfg['transport_gate_retry_sec']??0)===5&&(int)($cfg['transport_integrity_retry_sec']??0)===60,['gate_retry'=>$cfg['transport_gate_retry_sec']??null,'integrity_retry'=>$cfg['transport_integrity_retry_sec']??null]);
    pf_add($checks,'One heavy child maximum',(int)($cfg['max_heavy_jobs_per_cycle']??0)===1,$cfg['max_heavy_jobs_per_cycle']??null);
    pf_add($checks,'Adaptive strategy cooling config',(int)($cfg['strategy_cooldown_base_sec']??0)>=180&&(float)($cfg['strategy_cooldown_factor']??0)>=1.0&&((int)($cfg['strategy_cooldown_max_sec']??0)>=300),['base_sec'=>$cfg['strategy_cooldown_base_sec']??null,'factor'=>$cfg['strategy_cooldown_factor']??null,'max_sec'=>$cfg['strategy_cooldown_max_sec']??null]);
    pf_add($checks,'Idle daemon heartbeat <= 15s',(int)($cfg['daemon_idle_heartbeat_sec']??999)<=15,$cfg['daemon_idle_heartbeat_sec']??null);

    $paths=tr_paths();
    pf_add($checks,'Transport runtime readable',is_dir($paths['transport_runtime'])&&is_readable($paths['transport_runtime']),$paths['transport_runtime']);
    $ints=tr_json_checked($paths['transport_intents']);
    pf_add($checks,'Transport order_intents contract',!empty($ints['ok'])&&($ints['data']['schema']??'')==='te_v25'&&($ints['data']['owner']??'')==='engine',['schema'=>$ints['data']['schema']??'','owner'=>$ints['data']['owner']??'','count'=>is_array($ints['data']['intents']??null)?count($ints['data']['intents']):null]);
    pf_add($checks,'Transport spool readable',is_dir($paths['transport_spool'])&&is_readable($paths['transport_spool']),$paths['transport_spool']);
    pf_add($checks,'Transport ACK readable',is_dir($paths['transport_ack'])&&is_readable($paths['transport_ack']),$paths['transport_ack']);
    $orders=tr_json_checked($paths['transport_orders']);
    pf_add($checks,'Broker transport orders contract',!empty($orders['ok'])&&($orders['data']['schema']??'')==='te_v25',['schema'=>$orders['data']['schema']??'','count'=>is_array($orders['data']['orders']??null)?count($orders['data']['orders']):null]);

    $eg=tr_execution_order_gate(true);
    pf_add($checks,'Execution order gate readable',!empty($eg['ok']),$eg);
    $rsNow=tr_state_load($cfg);
    $brokerJob=is_array($rsNow['jobs']['broker']??null)?$rsNow['jobs']['broker']:[];
    $nowEpoch=time();$nextBrokerDue=(int)($brokerJob['next_due_at']??0);$watchdog=max(60,(int)($cfg['broker_actionable_watchdog_sec']??300));
    $bounded=((int)($eg['actionable_order_count']??0)<=0)||($nextBrokerDue>0&&$nextBrokerDue<=($nowEpoch+$watchdog));
    pf_add($checks,'Actionable Broker schedule bounded',$bounded,[
        'actionable_order_count'=>(int)($eg['actionable_order_count']??0),
        'next_due_at'=>$nextBrokerDue>0?date('Y-m-d H:i:s',$nextBrokerDue):'',
        'wait_sec'=>$nextBrokerDue>0?max(0,$nextBrokerDue-$nowEpoch):null,
        'watchdog_sec'=>$watchdog,
        'last_full_run_epoch'=>(int)($brokerJob['last_full_run_epoch']??0),
        'last_end_at'=>$brokerJob['last_end_at']??'',
        'last_reason'=>$brokerJob['last_reason']??''
    ]);

    $exchangeMap=[];
    $tradeListFile=$base.'/trade_list.php';
    if(is_file($tradeListFile)){
        $list=@include $tradeListFile;
        if(is_array($list)){
            foreach(['us','jp'] as $mk){
                foreach((array)($list[$mk]??[]) as $row){
                    if(!is_array($row)||(array_key_exists('enabled',$row)&&$row['enabled']!==true))continue;
                    $sym=strtoupper(trim((string)($row['symbol']??$row['code']??'')));
                    $ex=strtoupper(trim((string)($row['exchange']??$row['exchange_code']??'')));
                    if($sym!==''&&$ex!=='')$exchangeMap[$sym]=$ex;
                }
            }
        }
    }
    $coverage=[];$coverageOk=true;
    foreach((array)($orders['data']['orders']??[]) as $oid=>$o){
        if(!is_array($o))continue;
        $st=strtoupper((string)($o['status']??''));if(in_array($st,['FILLED','PAPER_FILLED','REJECTED','CANCELLED','PARTIAL_CANCELLED','EXPIRED'],true))continue;
        $market=strtoupper((string)($o['market']??''));if(!in_array($market,['US','JP'],true))continue;
        $sym=strtoupper(trim((string)($o['symbol']??'')));$own=strtoupper(trim((string)($o['exchange_code']??$o['exchange']??'')));
        if($own!=='')continue;
        $mapped=(string)($exchangeMap[$sym]??'');$ok=$mapped!=='';
        if(!$ok)$coverageOk=false;
        $coverage[]=['order_id'=>(string)($o['order_id']??$oid),'market'=>$market,'symbol'=>$sym,'mapped_exchange'=>$mapped,'approval_block_reason'=>$o['approval_block_reason']??''];
    }
    pf_add($checks,'Active US/JP exchange fallback coverage',$coverageOk,$coverage);
    $tg=tr_transport_gate(true);
    pf_add($checks,'Transport gate integrity/parse',!empty($tg['ok']),$tg);

    $vi=tr_validation_integrity();
    pf_add($checks,'Validation commit integrity',!empty($vi['ok']),$vi,'warning');
    pf_add($checks,'No unfinished Validation transaction',empty($vi['transaction_present']),$vi['transaction']??[]);
    pf_add($checks,'Validation runtime readable/writable',is_dir($paths['validation_runtime'])&&is_readable($paths['validation_runtime'])&&is_writable($paths['validation_runtime']),$paths['validation_runtime']);

    $rsFile=$paths['state'];$rs=pf_json($rsFile);
    pf_add($checks,'Existing runner_state compatible',!is_file($rsFile)||(($rs['schema']??'')==='trade_runner_state_v1'),is_file($rsFile)?['schema'=>$rs['schema']??'','version'=>$rs['version']??'']:'no prior state');

    $hb=pf_json($paths['daemon_heartbeat']);$hbEpoch=(int)($hb['epoch']??0);$hbAge=$hbEpoch>0?max(0,time()-$hbEpoch):PHP_INT_MAX;
    pf_add($checks,'Daemon heartbeat v1.4.6',($hb['version']??'')==='v1.4.6'&&($hb['rev']??'')==='trade-low-load-runner-v146-approval-actionable-gate-20260923-r1',['version'=>$hb['version']??'','rev'=>$hb['rev']??'','action'=>$hb['action']??'','cycle_action'=>$hb['cycle_action']??'','job'=>$hb['job']??'']);
    pf_add($checks,'Daemon heartbeat fresh',$hbAge<=45,['age_sec'=>$hbAge,'at'=>$hb['at']??'']);
}

pf_add($checks,'proc_open available',function_exists('proc_open'),function_exists('proc_open')?'available':'disabled');
pf_add($checks,'proc_get_status available',function_exists('proc_get_status'),function_exists('proc_get_status')?'available':'disabled');
pf_add($checks,'proc_terminate available',function_exists('proc_terminate'),function_exists('proc_terminate')?'available':'disabled');
pf_add($checks,'/proc load/memory/process readable',is_readable('/proc/loadavg')&&is_readable('/proc/meminfo')&&is_dir('/proc'),['loadavg'=>is_readable('/proc/loadavg'),'meminfo'=>is_readable('/proc/meminfo'),'proc'=>is_dir('/proc')]);
$runtime=$base.'/trade_runner_runtime';$parent=is_dir($runtime)?$runtime:$base;
pf_add($checks,'Runner runtime writable',is_writable($parent),$parent);
pf_add($checks,'CLI PHP executable',$php!=='',$php?:'NOT_FOUND');
$probe=pf_spawn_probe($php,$base);pf_add($checks,'Web PHP can spawn CLI PHP',!empty($probe['ok']),$probe);

$hardFailures=[];$warnings=[];$pass=0;
foreach($checks as$x){
    if($x['ok']){$pass++;continue;}
    if(($x['severity']??'hard')==='warning')$warnings[]=$x;else$hardFailures[]=$x;
}
$ok=count($hardFailures)===0;
$status=$ok?(count($warnings)?'PASS_WITH_WARN':'PASS'):'BLOCKED';
$result=[
 'ok'=>$ok,'version'=>PF_VERSION,'rev'=>PF_REV,'checked_at'=>date('c'),
 'checks_pass'=>$pass,'checks_total'=>count($checks),
 'hard_failure_count'=>count($hardFailures),'warning_count'=>count($warnings),
 'hard_failures'=>$hardFailures,'warnings'=>$warnings,'checks'=>$checks
];

if(($_GET['mode']??'')==='json'||PHP_SAPI==='cli'){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    if(PHP_SAPI==='cli')exit($ok?0:2);
    exit;
}

header('Content-Type:text/html; charset=utf-8');
?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Low-Load Runner WEB Preflight <?=pf_h(PF_VERSION)?></title><style>
body{font-family:system-ui;background:#f4f6f8;margin:0;color:#1f2937}main{max-width:1200px;margin:auto;padding:16px}table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden}th,td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}.ok{color:#087f5b;font-weight:700}.bad{color:#c92a2a;font-weight:700}.warn{color:#b26a00;font-weight:700}pre{white-space:pre-wrap;font-size:11px;margin:0}a{color:#1864ab}.summary{background:#fff;padding:12px;border-radius:10px;margin:10px 0}.muted{color:#6b7280}
</style></head><body><main>
<h1>Low-Load Runner WEB Preflight <?=pf_h(PF_VERSION)?> — <span class="<?=$ok?(count($warnings)?'warn':'ok'):'bad'?>"><?=pf_h($pass.'/'.count($checks).' '.$status)?></span></h1>
<p><a href="/trade_runner_control.php">WEB Control</a> · <a href="/trade_runner_status.php">Status</a> · <a href="/trade_pipeline_diag.php">Pipeline Diagnostics</a> · <a href="/trade_runner_preflight.php?mode=json">JSON</a></p>
<div class="summary"><b>Hard failures:</b> <?=count($hardFailures)?> · <b>Warnings:</b> <?=count($warnings)?> <span class="muted">· <?=pf_h(PF_REV)?></span></div>
<table><tr><th>#</th><th>결과</th><th>검사</th><th>상세</th></tr>
<?php foreach($checks as$i=>$x):$cls=$x['ok']?'ok':(($x['severity']??'hard')==='warning'?'warn':'bad');$label=$x['ok']?'PASS':(($x['severity']??'hard')==='warning'?'WARN':'BLOCK');?>
<tr><td><?=$i+1?></td><td class="<?=$cls?>"><?=$label?></td><td><?=pf_h($x['name'])?></td><td><pre><?=pf_h(is_scalar($x['detail'])?(string)$x['detail']:json_encode($x['detail'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT))?></pre></td></tr>
<?php endforeach;?></table>
</main></body></html>
