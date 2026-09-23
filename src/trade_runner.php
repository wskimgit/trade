<?php
/**
 * Trade Low-Load Runner v1.4.6
 * Adaptive ultra-low-load serialized scheduler for Phase 3B-Lite SINGLE_FILE_PAPER.
 * PHP 7.4+
 *
 * Key v1.4.4 changes:
 * - Transport truth health has absolute priority over pending availability; integrity/transient errors can never authorize a heavy Broker cycle.
 * - Centralized transport classification/backoff removes duplicated branch-order logic that could regress into Broker hammering.
 * - Daemon honors wait/backoff hints instead of spinning every transport-pulse interval while Broker is intentionally deferred.
 * - Canonical INTENT presence is NOT Broker-ingest proof; only valid ACK, broker_orders, or explicit broker_seen metadata proves handoff.
 * - Corrupt/unreadable transport input is fail-closed without entering an endless heavy-Broker loop.
 * - Repeated no-progress Broker pulses use bounded exponential backoff.
 * - Transport-aware Broker interlock watches shared order_intents + durable intent_spool before canonical ingest.
 * - Newly created unseen intents force Broker priority immediately; strategies cannot outrun Broker ingest.
 * - Post-strategy transport pulse wakes the daemon within seconds when a strategy created an intent.
 * - Transport retry has a bounded lightweight backoff; one-heavy-child invariant remains intact.
 * - Validation integrity is surfaced read-only; repair/recovery is handled by Validation v1.3.1 transaction journal + explicit legacy-safe recovery.
 * - JPX 2026/2027 cash-market holiday wake hints prevent false Monday/holiday Broker rechecks.
 * - market_calendar.local.php remains additive and may override a built-in closure with open_dates.
 * - Market-wait gate accepts Broker WAIT_MARKET_OPEN and a conservative regular-session CLOSED hint when canonical broker_message is absent.
 * - This fallback is PAPER-only and never marks an order terminal; Broker remains the execution/calendar authority.
 * - ACTIONABLE order interlock: only actionable active orders block strategies and force Broker priority.
 * - MARKET_WAIT-only state permits strategies while avoiding repeated heavy Broker cycles.
 * - Idle daemon heartbeat refresh every 10s to avoid false STALE during the 60s scheduler sleep.
 * - Broker Quick Gate: no heavy broker cycle while canonical has no active orders.
 * - Idle broker safety sweep only once per hour.
 * - Earliest-due strategy fairness instead of fixed-priority starvation.
 * - Adaptive strategy cooling after every heavy child.
 * - Exponential defer while NAS load remains high.
 * - One heavy child maximum; broker gate checks are lightweight and do not consume it.
 * - Strategy freshness watchdog promotes a strategy whose last successful child is stale, even if persisted next_due_at drifted into the future.
 * - Stale-strategy fairness is oldest-success-first and still obeys the global heavy-child cooldown/resource gate.
 * - Existing runner_state is upgraded in place. Canonical trade_state remains authoritative.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors','0');
error_reporting(E_ALL);
@ini_set('memory_limit','64M');
@set_time_limit(0);

const TR_VERSION='v1.4.6';
const TR_REV='trade-low-load-runner-v146-approval-actionable-gate-20260923-r1';
const TR_MARKET_CALENDAR_REV='JPX_2026_2027_R1';
const TR_STATE_SCHEMA='trade_runner_state_v1';
const TR_REQUIRED_AUTH='SINGLE_FILE_PAPER';

function tr_now(): string { return date('Y-m-d H:i:s'); }
function tr_base(): string {
    $e=(string)getenv('TRADE_BASE_DIR');
    return rtrim($e!==''?$e:__DIR__,'/');
}
function tr_runtime(): string {
    $e=(string)getenv('TRADE_RUNNER_RUNTIME');
    return rtrim($e!==''?$e:(tr_base().'/trade_runner_runtime'),'/');
}
function tr_paths(): array {
    $r=tr_runtime();
    return [
        'runtime'=>$r,
        'state'=>$r.'/runner_state.json',
        'lock'=>$r.'/runner.lock',
        'log'=>$r.'/runner.log',
        'heartbeat'=>$r.'/heartbeat.json',
        'daemon_lock'=>$r.'/daemon.lock',
        'daemon_pid'=>$r.'/daemon.pid',
        'daemon_heartbeat'=>$r.'/daemon_heartbeat.json',
        'daemon_stop'=>$r.'/daemon.stop',
        'broker_request'=>$r.'/broker_manual_request.json',
        'broker_result'=>$r.'/broker_manual_result.json',
        'marker'=>tr_base().'/trade_phase3b_lite_v100/authority.json',
        'canonical'=>tr_base().'/trade_runtime_single/trade_state.json',
        'transport_runtime'=>tr_base().'/trade_single_compat/trade_runtime',
        'transport_intents'=>tr_base().'/trade_single_compat/trade_runtime/order_intents.json',
        'transport_lock'=>tr_base().'/trade_single_compat/trade_runtime/order_intents.lock',
        'transport_spool'=>tr_base().'/trade_single_compat/trade_runtime/intent_spool',
        'transport_ack'=>tr_base().'/trade_single_compat/trade_runtime/intent_ack',
        'transport_orders'=>tr_base().'/trade_single_compat/trade_runtime/broker_orders.json',
        'validation_runtime'=>tr_base().'/trade_single_compat/validation_runtime',
    ];
}
function tr_mkdir(string $d): void {
    if(!is_dir($d)&&!@mkdir($d,0775,true)&&!is_dir($d)) throw new RuntimeException('DIR_CREATE_FAILED '.$d);
}
function tr_json($v,bool $pretty=false): string {
    $f=JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|($pretty?JSON_PRETTY_PRINT:0);
    $s=json_encode($v,$f);
    if(!is_string($s)) throw new RuntimeException('JSON_ENCODE_FAILED '.json_last_error_msg());
    return $s;
}
function tr_load_json(string $f,array $d=[]): array {
    if(!is_file($f)) return $d;
    $raw=@file_get_contents($f);
    if(!is_string($raw)) return $d;
    $j=json_decode($raw,true);
    return is_array($j)?$j:$d;
}
function tr_atomic_json(string $f,array $v): void {
    tr_mkdir(dirname($f));
    $raw=tr_json($v,false).PHP_EOL;
    $tmp=$f.'.tmp.'.getmypid().'.'.substr(hash('sha256',microtime(true).mt_rand()),0,8);
    if(@file_put_contents($tmp,$raw,LOCK_EX)===false) throw new RuntimeException('WRITE_FAILED '.$f);
    $chk=@file_get_contents($tmp);
    if(!is_string($chk)||!hash_equals(hash('sha256',$raw),hash('sha256',$chk))){@unlink($tmp);throw new RuntimeException('VERIFY_FAILED '.$f);}
    if(!@rename($tmp,$f)){@unlink($tmp);throw new RuntimeException('RENAME_FAILED '.$f);}
}
function tr_default_config(): array {
    return [
        'poll_sec'=>60,'max_heavy_jobs_per_cycle'=>1,
        'jobs'=>[
            'broker'=>['script'=>'trade_broker.php','arg'=>'cycle','interval_sec'=>300,'initial_delay_sec'=>0,'priority'=>100,'timeout_sec'=>240,'kind'=>'broker'],
            'dts'=>['script'=>'dts.php','arg'=>'tick','interval_sec'=>1800,'initial_delay_sec'=>120,'priority'=>80,'timeout_sec'=>900,'kind'=>'strategy'],
            'abc'=>['script'=>'abc.php','arg'=>'tick','interval_sec'=>1800,'initial_delay_sec'=>540,'priority'=>70,'timeout_sec'=>600,'kind'=>'strategy'],
            'das'=>['script'=>'das.php','arg'=>'tick','interval_sec'=>1800,'initial_delay_sec'=>960,'priority'=>60,'timeout_sec'=>600,'kind'=>'strategy'],
            'stc26'=>['script'=>'stc26.php','arg'=>'tick','interval_sec'=>1800,'initial_delay_sec'=>1380,'priority'=>50,'timeout_sec'=>600,'kind'=>'strategy'],
        ],
        'broker_quick_poll_sec'=>60,'broker_active_interval_sec'=>300,'broker_actionable_watchdog_sec'=>300,'broker_idle_full_sweep_sec'=>3600,'broker_after_strategy_sec'=>15,
        'transport_gate_enabled'=>true,'transport_pulse_sec'=>5,'transport_broker_defer_sec'=>30,'transport_broker_retry_max_sec'=>300,'transport_gate_retry_sec'=>5,'transport_integrity_retry_sec'=>60,'transport_lock_wait_ms'=>250,'transport_sample_limit'=>8,
        'strategy_cooldown_base_sec'=>300,'strategy_cooldown_factor'=>1.50,'strategy_cooldown_max_sec'=>900,'strategy_stale_watchdog_factor'=>3.0,'strategy_stale_watchdog_min_sec'=>5400,'broker_cooldown_base_sec'=>180,
        'strategy_load_per_cpu'=>1.25,'broker_load_per_cpu'=>1.80,'strategy_min_mem_mb'=>64,'broker_min_mem_mb'=>48,
        'strategy_defer_sec'=>180,'strategy_defer_max_sec'=>900,'broker_defer_sec'=>60,'broker_defer_max_sec'=>300,
        'broker_market_wait_strategy_passthrough'=>true,'broker_market_wait_open_grace_sec'=>20,'broker_market_wait_fallback_sec'=>1800,
        'nice_level'=>15,'log_max_bytes'=>1048576,'log_rotations'=>3,'child_output_max_bytes'=>65536,'heartbeat_sec'=>300,'daemon_idle_heartbeat_sec'=>10,
    ];
}
function tr_config(): array {
    $c=tr_default_config();
    $f=tr_base().'/trade_runner_config.php';
    if(is_file($f)){
        $x=require $f;
        if(is_array($x)) $c=array_replace_recursive($c,$x);
    }
    return $c;
}
function tr_rotate_log(string $f,int $max,int $n): void {
    if(!is_file($f)||@filesize($f)<=$max) return;
    for($i=$n;$i>=1;$i--){$src=$i===1?$f:$f.'.'.($i-1);$dst=$f.'.'.$i;if(is_file($src)){if(is_file($dst))@unlink($dst);@rename($src,$dst);}}
}
function tr_log(string $event,array $data=[]): void {
    $p=tr_paths();$c=tr_config();tr_mkdir($p['runtime']);
    tr_rotate_log($p['log'],max(65536,(int)$c['log_max_bytes']),max(1,(int)$c['log_rotations']));
    $row=['at'=>tr_now(),'event'=>$event]+$data;
    @file_put_contents($p['log'],tr_json($row,false).PHP_EOL,FILE_APPEND|LOCK_EX);
}
function tr_authority_gate(): array {
    $p=tr_paths();$m=tr_load_json($p['marker'],[]);
    if(!$m) return ['ok'=>false,'reason'=>'AUTHORITY_MARKER_MISSING','file'=>$p['marker']];
    if((string)($m['schema']??'')!=='trade_authority_v1') return ['ok'=>false,'reason'=>'AUTHORITY_MARKER_SCHEMA','marker'=>$m];
    $a=strtoupper((string)($m['authority']??''));
    if($a!==TR_REQUIRED_AUTH) return ['ok'=>false,'reason'=>'AUTHORITY_NOT_SINGLE_FILE_PAPER','authority'=>$a];
    if(!empty($m['real_order_allowed'])) return ['ok'=>false,'reason'=>'REAL_ORDER_FLAG_TRUE'];
    if(!is_file($p['canonical'])) return ['ok'=>false,'reason'=>'CANONICAL_STATE_MISSING','file'=>$p['canonical']];
    return ['ok'=>true,'authority'=>$a,'real_order_allowed'=>false];
}
function tr_cpu_count(): int {
    $raw=@file_get_contents('/proc/cpuinfo');
    if(is_string($raw)){$n=preg_match_all('/^processor\s*:/m',$raw,$m);if($n>0)return $n;}
    return 1;
}
function tr_mem_available_mb(): float {
    $forced=(string)getenv('TRADE_RUNNER_FORCE_MEM_AVAILABLE_MB');
    if($forced!==''&&is_numeric($forced)) return (float)$forced;
    $raw=@file_get_contents('/proc/meminfo');
    if(!is_string($raw)) return 99999.0;
    $vals=[];foreach(preg_split('/\r?\n/',$raw) as $line){if(preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB/',$line,$m))$vals[$m[1]]=(int)$m[2];}
    $kb=(int)($vals['MemAvailable']??0);if($kb<=0)$kb=(int)($vals['MemFree']??0)+(int)($vals['Buffers']??0)+(int)($vals['Cached']??0);
    return $kb>0?$kb/1024.0:99999.0;
}
function tr_load1(): float {
    $forced=(string)getenv('TRADE_RUNNER_FORCE_LOAD_1M');
    if($forced!==''&&is_numeric($forced)) return (float)$forced;
    $raw=trim((string)@file_get_contents('/proc/loadavg'));if($raw==='')return 0.0;
    $a=preg_split('/\s+/',$raw);return (float)($a[0]??0.0);
}
function tr_metrics(): array {
    $cpu=tr_cpu_count();$load=tr_load1();return['load1'=>round($load,3),'cpu_count'=>$cpu,'load_per_cpu'=>round($load/max(1,$cpu),3),'mem_available_mb'=>round(tr_mem_available_mb(),1)];
}
function tr_terminal_status(string $status): bool {
    return in_array(strtoupper($status),['FILLED','PAPER_FILLED','REJECTED','CANCELLED','PARTIAL_CANCELLED','EXPIRED'],true);
}
function tr_market_wait_message(array $o): bool {
    return strtoupper(trim((string)($o['broker_message']??'')))==='WAIT_MARKET_OPEN';
}
function tr_market_wait_class(array $o,int $epoch): array {
    $msg=strtoupper(trim((string)($o['broker_message']??'')));
    $status=strtoupper(trim((string)($o['status']??'')));
    $approved=!empty($o['approved']);
    $approvalBlock=trim((string)($o['approval_block_reason']??''));

    // APPROVAL_BLOCK_ACTIONABLE_GATE
    // An order that Broker still needs to validate/approve is actionable even while its
    // market is closed. Otherwise SESSION_CLOSED_HINT can starve Broker repair for days.
    if($approvalBlock!=='')return['wait'=>false,'source'=>'APPROVAL_BLOCK_ACTIONABLE'];
    if($status==='PENDING'&&!$approved)return['wait'=>false,'source'=>'PENDING_APPROVAL_ACTIONABLE'];

    if($msg==='WAIT_MARKET_OPEN')return['wait'=>true,'source'=>'BROKER_MESSAGE'];
    $m=strtoupper((string)($o['market']??''));
    $brokerOwnedExecution=$approved||in_array($status,['APPROVED','SENT','WORKING','PARTIAL','CANCEL_REQUESTED'],true);
    // SESSION_CLOSED_HINT is only a wake hint after Broker-side approval/submission.
    // Canonical-only INTENT/PENDING rows remain fail-safe ACTIONABLE.
    if($msg===''&&$brokerOwnedExecution&&in_array($m,['KR','JP','US'],true)&&!tr_market_regular_open_now($m,$epoch))return['wait'=>true,'source'=>'SESSION_CLOSED_HINT'];
    return['wait'=>false,'source'=>''];
}
function tr_market_timezone(string $market): string {
    $m=strtoupper($market);if($m==='US')return'America/New_York';if($m==='JP')return'Asia/Tokyo';return'Asia/Seoul';
}
function tr_market_builtin_calendar_data(string $market): array {
    $m=strtoupper($market);
    // Runner wake hints only. JPX remains the source for exchange-closure dates and Broker remains the execution authority.
    // JPX 2026/2027 market holidays: https://www.jpx.co.jp/english/corporate/about-jpx/calendar/
    if($m==='JP')return['holidays'=>[
        '2026-01-01','2026-01-02','2026-01-03','2026-01-12','2026-02-11','2026-02-23','2026-03-20','2026-04-29','2026-05-03','2026-05-04','2026-05-05','2026-05-06','2026-07-20','2026-08-11','2026-09-21','2026-09-22','2026-09-23','2026-10-12','2026-11-03','2026-11-23','2026-12-31',
        '2027-01-01','2027-01-02','2027-01-03','2027-01-11','2027-02-11','2027-02-23','2027-03-21','2027-03-22','2027-04-29','2027-05-03','2027-05-04','2027-05-05','2027-07-19','2027-08-11','2027-09-20','2027-09-23','2027-10-11','2027-11-03','2027-11-23','2027-12-31'
    ],'early_closes'=>[],'source'=>'JPX_BUILTIN_2026_2027'];
    return['holidays'=>[],'early_closes'=>[],'source'=>''];
}
function tr_market_date_set(array $rows): array {
    $set=[];foreach($rows as$k=>$v){
        if(is_int($k)){if(is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$v))$set[$v]=true;}
        elseif(is_string($k)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$k)&&!empty($v))$set[$k]=true;
    }return$set;
}
function tr_market_calendar_data(string $market): array {
    static $all=null;if($all===null){$file=tr_base().'/market_calendar.local.php';$x=is_file($file)?@include $file:[];$all=is_array($x)?$x:[];}
    $m=strtoupper($market);$rows=$all[$m]??$all[strtolower($m)]??[];if(!is_array($rows))$rows=[];$built=tr_market_builtin_calendar_data($m);
    $set=tr_market_date_set((array)($built['holidays']??[]));foreach(tr_market_date_set((array)($rows['holidays']??[]))as$d=>$v)$set[$d]=true;
    // Optional local override if an exchange schedule is amended after this package was frozen.
    foreach(tr_market_date_set((array)($rows['open_dates']??[]))as$d=>$v)unset($set[$d]);$holidays=array_keys($set);sort($holidays,SORT_STRING);
    $early=array_merge((array)($built['early_closes']??[]),is_array($rows['early_closes']??null)?$rows['early_closes']:[]);
    $source=[];if(!empty($built['source']))$source[]=(string)$built['source'];if(!empty($rows))$source[]='market_calendar.local.php';
    return['holidays'=>$holidays,'early_closes'=>$early,'source'=>implode('+',$source)];
}
function tr_market_holiday_date(string $market,string $date): bool {
    $c=tr_market_calendar_data($market);return in_array($date,$c['holidays'],true)||!empty($c['holidays'][$date]);
}
function tr_market_regular_open_now(string $market,int $epoch): bool {
    $m=strtoupper($market);if(!in_array($m,['KR','JP','US'],true))return false;
    $dt=(new DateTimeImmutable('@'.$epoch))->setTimezone(new DateTimeZone(tr_market_timezone($m)));$date=$dt->format('Y-m-d');
    if((int)$dt->format('N')>=6||tr_market_holiday_date($m,$date))return false;$hm=$dt->format('H:i');$cal=tr_market_calendar_data($m);$close=(string)($cal['early_closes'][$date]??($m==='US'?'16:00':'15:30'));
    if($m==='KR')return$hm>='09:00'&&$hm<$close;
    if($m==='JP')return($hm>='09:00'&&$hm<'11:30')||($hm>='12:30'&&$hm<$close);
    return$hm>='09:30'&&$hm<$close;
}
function tr_next_regular_market_open_epoch(string $market,int $epoch,bool $skipCurrentDate=false): int {
    $m=strtoupper($market);if(!in_array($m,['KR','JP','US'],true))return 0;
    $tz=new DateTimeZone(tr_market_timezone($m));$local=(new DateTimeImmutable('@'.$epoch))->setTimezone($tz);
    if(!$skipCurrentDate&&tr_market_regular_open_now($m,$epoch))return$epoch;
    for($i=$skipCurrentDate?1:0;$i<=8;$i++){
        $d=$local->modify('+'.$i.' day');if((int)$d->format('N')>=6||tr_market_holiday_date($m,$d->format('Y-m-d')))continue;
        $base=$d->setTime(0,0,0);$opens=$m==='JP'?[[9,0],[12,30]]:($m==='US'?[[9,30]]:[[9,0]]);
        foreach($opens as$hm){$cand=$base->setTime($hm[0],$hm[1],0)->getTimestamp();if($cand>$epoch)return$cand;}
    }
    return 0;
}
function tr_market_wait_plan(array $gate,int $now,array $cfg,bool $afterBroker=false): array {
    $markets=array_keys((array)($gate['market_wait_markets']??[]));$grace=max(0,(int)($cfg['broker_market_wait_open_grace_sec']??20));$fallback=max(300,(int)($cfg['broker_market_wait_fallback_sec']??1800));$rows=[];$due=0;
    foreach($markets as$m){$m=strtoupper((string)$m);$regularOpen=tr_market_regular_open_now($m,$now);$strict=$afterBroker&&$regularOpen;$x=tr_next_regular_market_open_epoch($m,$now,$strict);if($x<=0)$x=$now+$fallback;elseif($x>$now)$x+=$grace;$cal=tr_market_calendar_data($m);$rows[$m]=['regular_open_now'=>$regularOpen,'strict_next_day'=>$strict,'due_epoch'=>$x,'due_at'=>date('Y-m-d H:i:s',$x),'calendar_source'=>(string)($cal['source']??''),'calendar_rev'=>TR_MARKET_CALENDAR_REV];if($due<=0||$x<$due)$due=$x;}
    if($due<=0)$due=$now+$fallback;
    return['due_epoch'=>$due,'due_at'=>date('Y-m-d H:i:s',$due),'after_broker'=>$afterBroker,'markets'=>$rows];
}
function tr_canonical_order_gate(bool $force=false): array {
    static $cache=[];
    $f=tr_paths()['canonical'];
    if(!is_file($f)||!is_readable($f)) return ['ok'=>false,'reason'=>'CANONICAL_UNREADABLE','file'=>$f];
    $mt=(int)@filemtime($f);$sz=(int)@filesize($f);$cacheTtl=max(0,(int)(tr_config()['canonical_gate_cache_ttl_sec']??2));$cacheAge=time()-(int)($cache['_cached_at_epoch']??0);
    // Canonical classification is time-sensitive (e.g. session-closed MARKET_WAIT). Never freeze it indefinitely
    // just because file metadata did not change. Cache only repeated reads inside a very short window.
    if(!$force&&$cache&&($cache['_mtime']??-1)===$mt&&($cache['_size']??-1)===$sz&&$cacheTtl>0&&$cacheAge<$cacheTtl){$r=$cache;$r['cached']=true;unset($r['_mtime'],$r['_size'],$r['_cached_at_epoch']);return$r;}
    $raw=@file_get_contents($f);if(!is_string($raw)||$raw==='')return['ok'=>false,'reason'=>'CANONICAL_READ_FAILED','file'=>$f];
    $s=json_decode($raw,true);if(!is_array($s)||($s['schema']??'')!=='trade_state_v1')return['ok'=>false,'reason'=>'CANONICAL_JSON_INVALID','json_error'=>json_last_error_msg()];
    $active=0;$actionable=0;$marketWait=0;$by=[];$byClass=['ACTIONABLE'=>0,'MARKET_WAIT'=>0];$mwMarkets=[];$samples=[];$sig=[];
    foreach((array)($s['orders']??[]) as $id=>$o){
        if(!is_array($o))continue;$st=strtoupper((string)($o['status']??''));if(tr_terminal_status($st))continue;
        $active++;$by[$st]=(int)($by[$st]??0)+1;$wc=tr_market_wait_class($o,time());$isWait=!empty($wc['wait']);$waitSource=(string)($wc['source']??'');$class=$isWait?'MARKET_WAIT':'ACTIONABLE';$byClass[$class]++;
        $market=strtoupper((string)($o['market']??''));if($isWait){$marketWait++;$mwMarkets[$market]=(int)($mwMarkets[$market]??0)+1;$sig[]=(string)($o['order_id']??$id).'|'.$market.'|'.$st.'|'.$waitSource.'|'.TR_MARKET_CALENDAR_REV;}else{$actionable++;}
        if(count($samples)<8)$samples[]=['order_id'=>(string)($o['order_id']??$id),'strategy'=>(string)($o['strategy']??$o['strategy_key']??''),'market'=>$market,'symbol'=>(string)($o['symbol']??''),'side'=>(string)($o['side']??''),'status'=>$st,'broker_message'=>(string)($o['broker_message']??''),'class'=>$class,'wait_source'=>$waitSource];
    }
    ksort($by);ksort($mwMarkets);sort($sig,SORT_STRING);$signature=$sig?hash('sha256',implode("\n",$sig)):'';
    $r=['ok'=>true,'checked_at'=>tr_now(),'state_revision'=>(int)($s['revision']??0),'active_order_count'=>$active,'actionable_order_count'=>$actionable,'market_wait_order_count'=>$marketWait,'by_status'=>$by,'by_class'=>$byClass,'market_wait_markets'=>$mwMarkets,'market_wait_signature'=>$signature,'samples'=>$samples,'bytes'=>$sz,'cached'=>false,'_mtime'=>$mt,'_size'=>$sz,'_cached_at_epoch'=>time()];
    $cache=$r;unset($r['_mtime'],$r['_size'],$r['_cached_at_epoch']);return$r;
}
function tr_safe_id(string $id): string { return preg_replace('/[^A-Za-z0-9_-]/','_',$id); }
function tr_read_json_locked(string $file,string $lockFile,int $waitMs=250): array {
    if(!is_file($file)) return ['ok'=>true,'root'=>[],'missing'=>true,'path'=>$file,'lock_path'=>$lockFile,'lock_wait_ms'=>0];
    $started=microtime(true);$fp=@fopen($lockFile,'c+');
    if(!$fp)return['ok'=>false,'reason'=>'TRANSPORT_LOCK_OPEN_FAILED','path'=>$file,'lock_path'=>$lockFile,'lock_wait_ms'=>0];
    $locked=false;$deadline=microtime(true)+max(0.05,$waitMs/1000.0);
    do{if(@flock($fp,LOCK_SH|LOCK_NB)){$locked=true;break;}usleep(20000);}while(microtime(true)<$deadline);
    if(!$locked){@fclose($fp);return['ok'=>false,'reason'=>'TRANSPORT_LOCK_BUSY','path'=>$file,'lock_path'=>$lockFile,'lock_wait_ms'=>(int)round((microtime(true)-$started)*1000)];}
    try{$raw=@file_get_contents($file);if(!is_string($raw))return['ok'=>false,'reason'=>'TRANSPORT_READ_FAILED','path'=>$file];$j=json_decode($raw,true);if(!is_array($j))return['ok'=>false,'reason'=>'TRANSPORT_JSON_INVALID','path'=>$file,'json_error'=>json_last_error_msg()];return['ok'=>true,'root'=>$j,'missing'=>false,'path'=>$file,'lock_path'=>$lockFile,'lock_wait_ms'=>(int)round((microtime(true)-$started)*1000)];}
    finally{@flock($fp,LOCK_UN);@fclose($fp);}
}
function tr_transport_intent_terminal(array $i): bool {
    $s=strtoupper(trim((string)($i['status']??$i['state']??'')));
    return in_array($s,['FILLED','PAPER_FILLED','REJECTED','CANCELLED','PARTIAL_CANCELLED','EXPIRED','ARCHIVED'],true);
}
function tr_json_checked(string $file): array {
    if(!is_file($file))return['ok'=>false,'reason'=>'MISSING','file'=>$file,'data'=>[]];
    $raw=@file_get_contents($file);if(!is_string($raw))return['ok'=>false,'reason'=>'READ_FAILED','file'=>$file,'data'=>[]];
    $j=json_decode($raw,true);if(!is_array($j))return['ok'=>false,'reason'=>'JSON_INVALID','file'=>$file,'json_error'=>json_last_error_msg(),'data'=>[]];
    return['ok'=>true,'reason'=>'OK','file'=>$file,'data'=>$j];
}
function tr_ack_proves_ingest(string $file,string $orderId): bool {
    if($orderId===''||!is_file($file))return false;$x=tr_json_checked($file);if(empty($x['ok']))return false;$a=(array)$x['data'];
    return($a['schema']??'')==='broker_intent_ack_v1'&&strtolower(trim((string)($a['owner']??'')))==='broker'&&(string)($a['order_id']??'')===$orderId&&trim((string)($a['broker_seen_at']??$a['written_at']??''))!=='';
}
function tr_canonical_proves_ingest(array $o): bool {
    $seen=strtoupper(trim((string)($o['broker_ingest_state']??'')));if($seen==='BROKER_SEEN')return true;
    if(trim((string)($o['broker_seen_at']??''))!=='')return true;
    return false;
}
function tr_transport_gate(bool $force=false): array {
    static $cache=[];$cfg=tr_config();if(empty($cfg['transport_gate_enabled']))return['ok'=>true,'enabled'=>false,'pending_count'=>0,'broker_ingest_required'=>false,'strategy_blocked'=>false,'checked_at'=>tr_now()];
    $p=tr_paths();$sharedM=is_file($p['transport_intents'])?(int)@filemtime($p['transport_intents']):0;$sharedS=is_file($p['transport_intents'])?(int)@filesize($p['transport_intents']):0;
    $spoolFiles=is_dir($p['transport_spool'])?(glob(rtrim($p['transport_spool'],'/\\').'/*.json')?:[]):[];sort($spoolFiles,SORT_STRING);$spoolSig='';foreach($spoolFiles as$f)$spoolSig.=basename($f).':'.((int)@filemtime($f)).':'.((int)@filesize($f)).';';
    $ackSig=is_dir($p['transport_ack'])?((int)@filemtime($p['transport_ack'])).':'.((int)@filectime($p['transport_ack'])):'0:0';
    $ordersM=is_file($p['transport_orders'])?(int)@filemtime($p['transport_orders']):0;$ordersS=is_file($p['transport_orders'])?(int)@filesize($p['transport_orders']):0;
    $canM=is_file($p['canonical'])?(int)@filemtime($p['canonical']):0;$canS=is_file($p['canonical'])?(int)@filesize($p['canonical']):0;
    $sig0=$sharedM.'|'.$sharedS.'|'.hash('sha256',$spoolSig).'|'.hash('sha256',$ackSig).'|'.$ordersM.'|'.$ordersS.'|'.$canM.'|'.$canS;
    // Metadata signatures are only a short-lived optimization. They must never make transport truth stale indefinitely
    // when another process rewrites a same-size file inside one filesystem timestamp tick.
    $cacheTtl=max(0,(int)($cfg['transport_gate_cache_ttl_sec']??2));$cacheAge=time()-(int)($cache['_cached_at_epoch']??0);
    if(!$force&&$cache&&($cache['_sig']??'')===$sig0&&$cacheTtl>0&&$cacheAge<$cacheTtl){$r=$cache;$r['cached']=true;unset($r['_sig'],$r['_cached_at_epoch']);return$r;}
    if(!is_dir($p['transport_runtime'])||!is_readable($p['transport_runtime']))return['ok'=>false,'enabled'=>true,'reason'=>'TRANSPORT_RUNTIME_UNREADABLE','runtime'=>$p['transport_runtime'],'pending_count'=>0,'broker_ingest_required'=>false,'strategy_blocked'=>true,'transient_error'=>false,'integrity_error'=>true,'checked_at'=>tr_now()];
    if(!is_dir($p['transport_spool'])||!is_readable($p['transport_spool']))return['ok'=>false,'enabled'=>true,'reason'=>'TRANSPORT_SPOOL_UNREADABLE','spool'=>$p['transport_spool'],'pending_count'=>0,'broker_ingest_required'=>false,'strategy_blocked'=>true,'transient_error'=>false,'integrity_error'=>true,'checked_at'=>tr_now()];
    if(!is_dir($p['transport_ack'])||!is_readable($p['transport_ack']))return['ok'=>false,'enabled'=>true,'reason'=>'TRANSPORT_ACK_UNREADABLE','ack'=>$p['transport_ack'],'pending_count'=>0,'broker_ingest_required'=>false,'strategy_blocked'=>true,'transient_error'=>false,'integrity_error'=>true,'checked_at'=>tr_now()];

    $shared=tr_read_json_locked($p['transport_intents'],$p['transport_lock'],max(50,(int)($cfg['transport_lock_wait_ms']??250)));
    $sharedRows=[];$sharedById=[];$sharedDegraded=false;$sharedIntegrity=false;if(!empty($shared['ok'])){
        $root=(array)($shared['root']??[]);$schema=(string)($root['schema']??'');$owner=strtolower(trim((string)($root['owner']??'')));
        if(!empty($shared['missing'])||!in_array($schema,['te_v25'],true)||($owner!==''&&$owner!=='engine')||!is_array($root['intents']??null)){$sharedDegraded=true;$sharedIntegrity=!empty($shared['missing'])?false:true;$shared['reason']=!empty($shared['missing'])?'TRANSPORT_SHARED_MISSING':'TRANSPORT_SHARED_CONTRACT_INVALID';}
        else{$sharedRows=(array)$root['intents'];foreach($sharedRows as$i){if(!is_array($i))continue;$id=(string)($i['order_id']??'');if($id!=='')$sharedById[$id]=$i;}
        }
    }else{$sharedDegraded=true;$rr=(string)($shared['reason']??'');$sharedIntegrity=in_array($rr,['TRANSPORT_JSON_INVALID'],true);}

    $brokerSeen=[];$boCheck=tr_json_checked($p['transport_orders']);$boValid=!empty($boCheck['ok'])&&in_array((string)($boCheck['data']['schema']??''),['te_v25'],true)&&is_array($boCheck['data']['orders']??null);if($boValid){foreach((array)$boCheck['data']['orders']as$id=>$o){if(!is_array($o))continue;$oid=(string)($o['order_id']??(is_string($id)?$id:''));if($oid!=='')$brokerSeen[$oid]='broker_order';}}
    $canonicalSeen=[];$canCheck=tr_json_checked($p['canonical']);$canValid=!empty($canCheck['ok'])&&($canCheck['data']['schema']??'')==='trade_state_v1'&&is_array($canCheck['data']['orders']??null);if($canValid){foreach((array)$canCheck['data']['orders']as$id=>$o){if(!is_array($o))continue;$oid=(string)($o['order_id']??(is_string($id)?$id:''));if($oid!==''&&tr_canonical_proves_ingest($o))$canonicalSeen[$oid]='canonical_broker_seen';}}

    $all=[];$origin=[];foreach($sharedById as$id=>$i){$all[$id]=$i;$origin[$id]='shared';}
    $badSpool=[];$unresolvedBad=[];$acknowledgedBad=[];
    foreach($spoolFiles as$f){$ck=tr_json_checked($f);$x=!empty($ck['ok'])?(array)$ck['data']:[];$i=is_array($x['intent']??null)?$x['intent']:[];$rootId=(string)($x['order_id']??'');$id=(string)($i['order_id']??$rootId);$fileHint=basename($f,'.json');if($id==='')$id=$fileHint;
        $valid=!empty($ck['ok'])&&($x['schema']??'')==='te_intent_spool_v1'&&!empty($i)&&(string)($i['order_id']??'')!==''&&($rootId===''||$rootId===(string)$i['order_id']);
        if(!$valid){$row=['file'=>basename($f),'order_id_hint'=>$id,'reason'=>!empty($ck['ok'])?'SPOOL_SCHEMA_OR_INTENT_INVALID':(string)($ck['reason']??'SPOOL_INVALID')];$badSpool[]=$row;$ackFile=$p['transport_ack'].'/'.tr_safe_id($id).'.json';$proved=$id!==''&&(tr_ack_proves_ingest($ackFile,$id)||isset($brokerSeen[$id])||isset($canonicalSeen[$id]));if($proved)$acknowledgedBad[]=$row;elseif($id!==''&&isset($sharedById[$id])){/* valid shared source still covers this intent */}else$unresolvedBad[]=$row;continue;}
        $id=(string)$i['order_id'];if(!isset($all[$id])){$all[$id]=$i;$origin[$id]='spool';}else$origin[$id]='shared+spool';
    }

    $pending=[];$now=time();$oldest=0;$minExpiry=null;$byMarket=[];$byStrategy=[];$byOrigin=[];$expiredUnseen=0;$proofCounts=['ack'=>0,'broker_order'=>0,'canonical_broker_seen'=>0];
    foreach($all as$id=>$i){if(tr_transport_intent_terminal($i))continue;$ackFile=$p['transport_ack'].'/'.tr_safe_id($id).'.json';$proof='';if(tr_ack_proves_ingest($ackFile,$id))$proof='ack';elseif(isset($brokerSeen[$id]))$proof='broker_order';elseif(isset($canonicalSeen[$id]))$proof='canonical_broker_seen';if($proof!==''){$proofCounts[$proof]++;continue;}
        $created=(string)($i['created_at']??$i['signal_at']??'');$cts=$created!==''?(strtotime($created)?:0):0;$age=$cts>0?max(0,$now-$cts):null;if($age!==null)$oldest=max($oldest,$age);$exp=(string)($i['expires_at']??'');$ets=$exp!==''?(strtotime($exp)?:0):0;$remain=$ets>0?$ets-$now:null;if($remain!==null&&($minExpiry===null||$remain<$minExpiry))$minExpiry=$remain;if($remain!==null&&$remain<0)$expiredUnseen++;
        $m=strtoupper((string)($i['market']??''));$sk=strtolower((string)($i['strategy_key']??$i['strategy']??''));$o=(string)($origin[$id]??'unknown');$byMarket[$m]=(int)($byMarket[$m]??0)+1;if($sk!=='')$byStrategy[$sk]=(int)($byStrategy[$sk]??0)+1;$byOrigin[$o]=(int)($byOrigin[$o]??0)+1;
        $pending[]=['order_id'=>$id,'strategy'=>$sk,'market'=>$m,'symbol'=>(string)($i['symbol']??''),'side'=>strtoupper((string)($i['side']??'')),'created_at'=>$created,'age_sec'=>$age,'expires_at'=>$exp,'expiry_remaining_sec'=>$remain,'origin'=>$o];
    }
    usort($pending,static function($a,$b){return(int)($b['age_sec']??0)<=>(int)($a['age_sec']??0);});$ids=array_map(static function($x){return(string)$x['order_id'];},$pending);sort($ids,SORT_STRING);$psig=$ids?hash('sha256',implode("\n",$ids)):'';$limit=max(1,(int)($cfg['transport_sample_limit']??8));ksort($byMarket);ksort($byStrategy);ksort($byOrigin);
    $pendingCount=count($pending);
    $transient=$sharedDegraded&&!$sharedIntegrity&&$pendingCount===0&&count($unresolvedBad)===0;
    $integrity=$sharedIntegrity||count($unresolvedBad)>0||!$boValid||!$canValid;
    $ok=!$transient&&!$integrity;
    if(!$canValid)$reason='CANONICAL_TRANSPORT_PROOF_INVALID';elseif(!$boValid)$reason='BROKER_ORDERS_TRANSPORT_INVALID';elseif($sharedIntegrity)$reason=(string)($shared['reason']??'TRANSPORT_SHARED_CONTRACT_INVALID');elseif(count($unresolvedBad)>0)$reason='TRANSPORT_SPOOL_INTEGRITY_UNRESOLVED';elseif($transient)$reason=(string)($shared['reason']??'TRANSPORT_SHARED_READ_DEGRADED');else$reason='OK';
    $r=['ok'=>$ok,'enabled'=>true,'reason'=>$reason,'checked_at'=>tr_now(),'pending_count'=>$pendingCount,'broker_ingest_required'=>$pendingCount>0&&$ok,'strategy_blocked'=>$pendingCount>0||$transient||$integrity,'transient_error'=>$transient,'integrity_error'=>$integrity,'oldest_pending_age_sec'=>$oldest,'min_expiry_remaining_sec'=>$minExpiry,'expired_unseen_count'=>$expiredUnseen,'by_market'=>$byMarket,'by_strategy'=>$byStrategy,'by_origin'=>$byOrigin,'samples'=>array_slice($pending,0,$limit),'signature'=>$psig,'shared_ok'=>!$sharedDegraded,'shared_reason'=>$sharedDegraded?(string)($shared['reason']??'SHARED_READ_DEGRADED'):'','shared_count'=>count($sharedRows),'spool_count'=>count($spoolFiles),'bad_spool'=>$badSpool,'unresolved_bad_spool'=>$unresolvedBad,'acknowledged_bad_spool'=>$acknowledgedBad,'proof_counts'=>$proofCounts,'broker_orders_ok'=>$boValid,'canonical_ok'=>$canValid,'cached'=>false,'_sig'=>$sig0,'_cached_at_epoch'=>time()];$cache=$r;unset($r['_sig'],$r['_cached_at_epoch']);return$r;
}

function tr_execution_order_gate(bool $force=false): array {
    // Broker execution truth owns the post-ingest lifecycle. Canonical state remains a fallback mirror only.
    // This prevents an ACKed Broker order from disappearing from Runner scheduling when canonical mirroring lags.
    static $cache=[];$p=tr_paths();$brokerFile=$p['transport_orders'];$canonicalFile=$p['canonical'];
    $bm=is_file($brokerFile)?(int)@filemtime($brokerFile):0;$bs=is_file($brokerFile)?(int)@filesize($brokerFile):0;
    $cm=is_file($canonicalFile)?(int)@filemtime($canonicalFile):0;$cs=is_file($canonicalFile)?(int)@filesize($canonicalFile):0;
    $ttl=max(0,(int)(tr_config()['canonical_gate_cache_ttl_sec']??2));$age=time()-(int)($cache['_cached_at_epoch']??0);
    if(!$force&&$cache&&($cache['_bm']??-1)===$bm&&($cache['_bs']??-1)===$bs&&($cache['_cm']??-1)===$cm&&($cache['_cs']??-1)===$cs&&$ttl>0&&$age<$ttl){$r=$cache;$r['cached']=true;unset($r['_bm'],$r['_bs'],$r['_cm'],$r['_cs'],$r['_cached_at_epoch']);return$r;}

    $bo=tr_json_checked($brokerFile);
    if(empty($bo['ok'])||!in_array((string)($bo['data']['schema']??''),['te_v25'],true)||!is_array($bo['data']['orders']??null)){
        return ['ok'=>false,'reason'=>'BROKER_EXECUTION_TRUTH_INVALID','file'=>$brokerFile,'detail'=>$bo];
    }
    $can=tr_json_checked($canonicalFile);
    if(empty($can['ok'])||($can['data']['schema']??'')!=='trade_state_v1'||!is_array($can['data']['orders']??null)){
        return ['ok'=>false,'reason'=>'CANONICAL_EXECUTION_MIRROR_INVALID','file'=>$canonicalFile,'detail'=>$can];
    }

    $brokerById=[];
    foreach((array)$bo['data']['orders'] as $id=>$o){if(!is_array($o))continue;$oid=(string)($o['order_id']??(is_string($id)?$id:''));if($oid!=='')$brokerById[$oid]=$o;}
    $rows=[];$source=[];
    foreach($brokerById as $id=>$o){$rows[$id]=$o;$source[$id]='broker_orders';}
    foreach((array)$can['data']['orders'] as $id=>$o){if(!is_array($o))continue;$oid=(string)($o['order_id']??(is_string($id)?$id:''));if($oid===''||array_key_exists($oid,$brokerById))continue;$rows[$oid]=$o;$source[$oid]='canonical_fallback';}

    $active=0;$actionable=0;$marketWait=0;$by=[];$byClass=['ACTIONABLE'=>0,'MARKET_WAIT'=>0];$mwMarkets=[];$samples=[];$sig=[];$sourceCounts=['broker_orders'=>0,'canonical_fallback'=>0];$now=time();$oldestActionable=0;$oldestMarketWait=0;
    foreach($rows as $id=>$o){
        $st=strtoupper((string)($o['status']??''));if(tr_terminal_status($st))continue;
        $active++;$src=(string)($source[$id]??'unknown');if(isset($sourceCounts[$src]))$sourceCounts[$src]++;
        $by[$st]=(int)($by[$st]??0)+1;$wc=tr_market_wait_class($o,$now);$isWait=!empty($wc['wait']);$waitSource=(string)($wc['source']??'');$class=$isWait?'MARKET_WAIT':'ACTIONABLE';$byClass[$class]++;
        $market=strtoupper((string)($o['market']??''));$ts=0;foreach(['received_at','created_at','sent_at','processed_at'] as $tk){$v=trim((string)($o[$tk]??''));if($v!==''){$x=strtotime($v);if($x!==false&&$x>0){$ts=(int)$x;break;}}}$rowAge=$ts>0?max(0,$now-$ts):0;
        if($isWait){$marketWait++;$oldestMarketWait=max($oldestMarketWait,$rowAge);$mwMarkets[$market]=(int)($mwMarkets[$market]??0)+1;$sig[]=$id.'|'.$market.'|'.$st.'|'.$waitSource.'|'.TR_MARKET_CALENDAR_REV;}
        else{$actionable++;$oldestActionable=max($oldestActionable,$rowAge);}
        if(count($samples)<8)$samples[]=['order_id'=>$id,'source'=>$src,'strategy'=>(string)($o['strategy']??$o['strategy_key']??''),'market'=>$market,'symbol'=>(string)($o['symbol']??''),'side'=>(string)($o['side']??''),'status'=>$st,'broker_message'=>(string)($o['broker_message']??''),'class'=>$class,'wait_source'=>$waitSource,'age_sec'=>$rowAge];
    }
    ksort($by);ksort($mwMarkets);sort($sig,SORT_STRING);$signature=$sig?hash('sha256',implode("\n",$sig)):'';
    $r=['ok'=>true,'checked_at'=>tr_now(),'truth'=>'BROKER_EXECUTION_PLUS_CANONICAL_FALLBACK','broker_orders_count'=>count($brokerById),'canonical_revision'=>(int)($can['data']['revision']??0),'active_order_count'=>$active,'actionable_order_count'=>$actionable,'market_wait_order_count'=>$marketWait,'oldest_actionable_age_sec'=>$oldestActionable,'oldest_market_wait_age_sec'=>$oldestMarketWait,'by_status'=>$by,'by_class'=>$byClass,'market_wait_markets'=>$mwMarkets,'market_wait_signature'=>$signature,'source_counts'=>$sourceCounts,'samples'=>$samples,'cached'=>false,'_bm'=>$bm,'_bs'=>$bs,'_cm'=>$cm,'_cs'=>$cs,'_cached_at_epoch'=>time()];
    $cache=$r;unset($r['_bm'],$r['_bs'],$r['_cm'],$r['_cs'],$r['_cached_at_epoch']);return$r;
}

function tr_validation_integrity(): array {
    $root=tr_paths()['validation_runtime'];$files=['pending'=>$root.'/pending.json','state'=>$root.'/state.json','strategy_summary'=>$root.'/strategy_summary.json','independence_summary'=>$root.'/independence_summary.json','challenger_summary'=>$root.'/challenger_summary.json'];$mf=$root.'/validation_commit.json';$tx=$root.'/validation_txn.json';
    if(!is_dir($root))return['ok'=>false,'status'=>'RUNTIME_MISSING','runtime'=>$root,'repair_supported'=>false,'transaction_present'=>false];
    if(is_file($tx)){$t=tr_json_checked($tx);$valid=!empty($t['ok'])&&($t['data']['schema']??'')==='trade_validation_txn_v1'&&trim((string)($t['data']['generation']??''))!=='';return['ok'=>false,'status'=>$valid?'TXN_PENDING_RECOVERY':'TXN_JOURNAL_INVALID','runtime'=>$root,'mismatches'=>['transaction'],'repair_supported'=>$valid,'transaction_present'=>true,'transaction'=>$valid?['generation'=>$t['data']['generation']??'','reason'=>$t['data']['reason']??'','state'=>$t['data']['state']??'']:$t];}
    if(!is_file($mf))return['ok'=>true,'status'=>'NO_MANIFEST','runtime'=>$root,'mismatches'=>[],'repair_supported'=>false,'transaction_present'=>false];$m=tr_load_json($mf,[]);if(($m['schema']??'')!=='trade_validation_commit_v1')return['ok'=>false,'status'=>'MANIFEST_INVALID','runtime'=>$root,'mismatches'=>['manifest'],'repair_supported'=>false,'transaction_present'=>false];$mm=[];foreach($files as$k=>$f){$want=(string)($m['hashes'][$k]??'');$have=is_file($f)?(string)(@hash_file('sha256',$f)?:''):'';if($want!==$have)$mm[$k]=['expected'=>$want,'actual'=>$have];}$keys=array_keys($mm);sort($keys,SORT_STRING);return['ok'=>!$mm,'status'=>$mm?'PARTIAL_COMMIT':'OK','runtime'=>$root,'mismatches'=>$mm,'mismatch_keys'=>$keys,'generation'=>(string)($m['generation']??''),'repair_supported'=>$keys===['pending'],'transaction_present'=>false];
}

function tr_state_init(array $cfg): array {
    $now=time();$jobs=[];
    foreach((array)$cfg['jobs'] as $k=>$j){$jobs[$k]=[
        'next_due_at'=>$now+max(0,(int)($j['initial_delay_sec']??0)),
        'last_start_at'=>'','last_end_at'=>'','last_exit_code'=>null,'last_elapsed_sec'=>null,
        'runs'=>0,'failures'=>0,'consecutive_failures'=>0,'defer_count'=>0,'last_reason'=>'','last_output_tail'=>'',
        'quick_checks'=>0,'idle_skips'=>0,'last_gate_at'=>'','last_gate_active'=>null,'last_gate_actionable'=>0,'last_gate_market_wait'=>0,'market_wait_recheck_at'=>0,'market_wait_signature'=>'','market_wait_plan'=>[],'last_full_run_epoch'=>0,
    ];}
    return ['schema'=>TR_STATE_SCHEMA,'version'=>TR_VERSION,'rev'=>TR_REV,'created_at'=>tr_now(),'updated_at'=>tr_now(),'sequence'=>0,'last_heartbeat_at'=>0,
        'scheduler'=>['strategy_cooldown_until'=>0,'last_heavy_job'=>'','last_heavy_end_at'=>'','last_heavy_elapsed_sec'=>0.0,'broker_gate'=>[],'transport_gate'=>[],'transport_signature'=>'','transport_next_retry_at'=>0,'transport_stuck_count'=>0,'transport_gate_fail_count'=>0,'transport_gate_retry_at'=>0,'manual_broker_last_run_epoch'=>0,'manual_broker_last_request_id'=>'','strategy_watchdog'=>[]],
        'jobs'=>$jobs,'last_cycle'=>[]];
}
function tr_state_load(array $cfg): array {
    $p=tr_paths();$s=tr_load_json($p['state'],[]);if(($s['schema']??'')!==TR_STATE_SCHEMA)$s=tr_state_init($cfg);
    if(!isset($s['jobs'])||!is_array($s['jobs']))$s['jobs']=[];
    $defaults=tr_state_init($cfg);
    foreach((array)$cfg['jobs'] as $k=>$j){if(!isset($s['jobs'][$k])||!is_array($s['jobs'][$k]))$s['jobs'][$k]=$defaults['jobs'][$k];else $s['jobs'][$k]=array_merge($defaults['jobs'][$k],$s['jobs'][$k]);}
    if(!isset($s['scheduler'])||!is_array($s['scheduler']))$s['scheduler']=$defaults['scheduler'];else $s['scheduler']=array_merge($defaults['scheduler'],$s['scheduler']);
    $s['version']=TR_VERSION;$s['rev']=TR_REV;return$s;
}
function tr_state_save(array $s): void {$s['updated_at']=tr_now();tr_atomic_json(tr_paths()['state'],$s);}
function tr_lock(){
    $p=tr_paths();tr_mkdir($p['runtime']);$h=@fopen($p['lock'],'c+');if(!$h)throw new RuntimeException('LOCK_OPEN_FAILED');
    if(!@flock($h,LOCK_EX|LOCK_NB)){@fclose($h);return null;}@ftruncate($h,0);@fwrite($h,(string)getmypid());@fflush($h);return$h;
}
function tr_unlock($h): void {if(is_resource($h)){@flock($h,LOCK_UN);@fclose($h);}}
function tr_strategy_success_epoch(array $st): int {
    $end=trim((string)($st['last_end_at']??''));$ts=$end!==''?(strtotime($end)?:0):0;$exit=$st['last_exit_code']??null;$reason=strtoupper(trim((string)($st['last_reason']??'')));
    if($ts>0&&((is_numeric($exit)&&(int)$exit===0)||$reason==='OK'))return(int)$ts;
    return 0;
}
function tr_strategy_watchdog_sec(array $job,array $cfg): int {
    $interval=max(300,(int)($job['interval_sec']??1800));$factor=max(1.0,(float)($cfg['strategy_stale_watchdog_factor']??3.0));$min=max($interval,(int)($cfg['strategy_stale_watchdog_min_sec']??5400));
    return max($min,(int)ceil($interval*$factor));
}
function tr_due_strategy_jobs(array $cfg,array $state,int $now): array {
    $rows=[];$created=(string)($state['created_at']??'');$createdTs=$created!==''?(strtotime($created)?:0):0;
    foreach((array)$cfg['jobs'] as $k=>$j){
        if((string)($j['kind']??'strategy')!=='strategy')continue;
        $st=(array)($state['jobs'][$k]??[]);$due=(int)($st['next_due_at']??0);$successTs=tr_strategy_success_epoch($st);$watchdog=tr_strategy_watchdog_sec($j,$cfg);$runs=(int)($st['runs']??0);$successAge=$successTs>0?max(0,$now-$successTs):null;$stale=false;
        if($successTs>0)$stale=$successAge>=$watchdog;
        elseif($runs<=0&&$createdTs>0){$firstGrace=$createdTs+max(0,(int)($j['initial_delay_sec']??0))+$watchdog;$stale=$now>=$firstGrace;}
        if($due<=$now||$stale)$rows[]=['key'=>$k,'priority'=>(int)($j['priority']??0),'due'=>$due,'cfg'=>$j,'stale_watchdog'=>$stale,'last_success_epoch'=>$successTs,'last_success_age_sec'=>$successAge,'watchdog_sec'=>$watchdog];
    }
    usort($rows,static function($a,$b){
        $as=!empty($a['stale_watchdog']);$bs=!empty($b['stale_watchdog']);if($as!==$bs)return$as?-1:1;
        if($as&&$bs){$aa=(int)($a['last_success_age_sec']??PHP_INT_MAX);$ba=(int)($b['last_success_age_sec']??PHP_INT_MAX);if($aa!==$ba)return$ba<=>$aa;}
        if((int)$a['due']===(int)$b['due'])return(int)$b['priority']<=>(int)$a['priority'];return(int)$a['due']<=>(int)$b['due'];
    });return$rows;
}
function tr_resource_gate(array $job,array $metrics,array $cfg): array {
    $kind=(string)($job['kind']??'strategy');$cpu=max(1,(int)$metrics['cpu_count']);$load=(float)$metrics['load1'];$mem=(float)$metrics['mem_available_mb'];
    if($kind==='broker'){$loadLim=max(0.5,(float)$cfg['broker_load_per_cpu']*$cpu);$memLim=(float)$cfg['broker_min_mem_mb'];}
    else{$loadLim=max(0.5,(float)$cfg['strategy_load_per_cpu']*$cpu);$memLim=(float)$cfg['strategy_min_mem_mb'];}
    if($load>$loadLim)return['ok'=>false,'reason'=>'LOAD_HIGH','load1'=>$load,'limit'=>$loadLim];
    if($mem<$memLim)return['ok'=>false,'reason'=>'MEMORY_LOW','available_mb'=>$mem,'limit_mb'=>$memLim];
    return['ok'=>true,'load_limit'=>$loadLim,'mem_limit_mb'=>$memLim];
}
function tr_defer_seconds(string $key,array $job,array $state,array $cfg): int {
    $n=max(0,(int)($state['jobs'][$key]['defer_count']??0));$kind=(string)($job['kind']??'strategy');
    $base=$kind==='broker'?max(30,(int)$cfg['broker_defer_sec']):max(60,(int)$cfg['strategy_defer_sec']);
    $max=$kind==='broker'?max($base,(int)$cfg['broker_defer_max_sec']):max($base,(int)$cfg['strategy_defer_max_sec']);
    $mult=1<<min(3,$n);return min($max,$base*$mult);
}
function tr_transport_class(array $tg): string {
    // Health always has higher authority than work availability. A broken truth source may contain pending rows,
    // but pending must never authorize a heavy Broker cycle until transport integrity is healthy again.
    if(empty($tg['ok'])) return !empty($tg['integrity_error'])?'INTEGRITY_ERROR':'TRANSIENT_ERROR';
    return (int)($tg['pending_count']??0)>0?'PENDING':'CLEAR';
}
function tr_transport_error_delay(array $tg,array $cfg): int {
    return tr_transport_class($tg)==='TRANSIENT_ERROR'?max(2,(int)($cfg['transport_gate_retry_sec']??5)):max(15,(int)($cfg['transport_integrity_retry_sec']??60));
}
function tr_transport_backoff_seconds(int $stuck,array $cfg): int {
    $base=max(10,(int)($cfg['transport_broker_defer_sec']??30));$max=max($base,(int)($cfg['transport_broker_retry_max_sec']??300));
    return min($max,$base*(1<<min(4,max(0,$stuck-1))));
}
function tr_daemon_cycle_sleep(array $result,array $cfg): int {
    $poll=max(15,(int)($cfg['poll_sec']??60));$wait=max(0,(int)($result['wait_sec']??0));
    if($wait>0)return max(2,min($poll,$wait));
    if(!empty($result['transport_pulse_required']))return max(2,(int)($cfg['transport_pulse_sec']??5));
    return $poll;
}
function tr_manual_broker_result(array $p,array $req,bool $ok,string $status,array $extra=[],bool $consume=true): void {
    $row=['schema'=>'trade_runner_broker_result_v1','request_id'=>(string)($req['request_id']??''),'ok'=>$ok,'status'=>$status,'updated_at'=>tr_now(),'updated_epoch'=>time()]+$extra;
    tr_atomic_json($p['broker_result'],$row);
    if($consume&&is_file($p['broker_request']))@unlink($p['broker_request']);
}
function tr_manual_broker_request_load(array $p,int $now): array {
    if(!is_file($p['broker_request']))return['active'=>false];
    $r=tr_load_json($p['broker_request'],[]);
    if(($r['schema']??'')!=='trade_runner_broker_request_v1'||trim((string)($r['request_id']??''))===''){
        tr_manual_broker_result($p,$r,false,'REQUEST_INVALID',['reason'=>'SCHEMA_OR_ID_INVALID'],true);return['active'=>false];
    }
    $exp=(int)($r['expires_epoch']??0);
    if($exp>0&&$exp<$now){tr_manual_broker_result($p,$r,false,'REQUEST_EXPIRED',['reason'=>'EXPIRED','expires_epoch'=>$exp],true);return['active'=>false];}
    $r['active']=true;return$r;
}
function tr_manual_broker_pending(array $p,array $req,string $status,array $extra=[]): void {
    tr_manual_broker_result($p,$req,true,$status,$extra,false);
}
function tr_cooldown_seconds(string $kind,float $elapsed,array $cfg): int {
    $factor=max(1.0,(float)$cfg['strategy_cooldown_factor']);$max=max(60,(int)$cfg['strategy_cooldown_max_sec']);
    if($kind==='broker')$base=max(60,(int)$cfg['broker_cooldown_base_sec']);else$base=max(60,(int)$cfg['strategy_cooldown_base_sec']);
    return min($max,max($base,(int)ceil($elapsed*$factor)));
}
function tr_tail_file(string $f,int $max): string {
    if(!is_file($f))return'';$size=(int)@filesize($f);$h=@fopen($f,'rb');if(!$h)return'';if($size>$max)@fseek($h,-$max,SEEK_END);$raw=(string)stream_get_contents($h);@fclose($h);return trim($raw);
}
function tr_nice_prefix(array $cfg): string {
    $level=max(0,min(19,(int)($cfg['nice_level']??15)));
    foreach(['/bin/nice','/usr/bin/nice'] as $n)if(is_executable($n))return escapeshellarg($n).' -n '.$level.' ';
    return '';
}
function tr_run_child(string $key,array $job,array $cfg,bool $daemonContext=false): array {
    if(!function_exists('proc_open'))return['ok'=>false,'reason'=>'PROC_OPEN_UNAVAILABLE','exit_code'=>127,'elapsed_sec'=>0.0,'output_tail'=>''];
    $script=tr_base().'/'.basename((string)$job['script']);if(!is_file($script))return['ok'=>false,'reason'=>'SCRIPT_MISSING','file'=>$script,'exit_code'=>127,'elapsed_sec'=>0.0,'output_tail'=>''];
    $php=PHP_BINARY;if($php===''||!is_executable($php))$php='/usr/local/bin/php74';
    $arg=(string)($job['arg']??'');$cmd='exec '.tr_nice_prefix($cfg).escapeshellarg($php).' '.escapeshellarg($script).($arg!==''?' '.escapeshellarg($arg):'');
    $out=tr_runtime().'/job_'.$key.'_last.log';@file_put_contents($out,'');
    $desc=[0=>['file','/dev/null','r'],1=>['file',$out,'a'],2=>['file',$out,'a']];
    $t=microtime(true);$proc=@proc_open($cmd,$desc,$pipes,tr_base());if(!is_resource($proc))return['ok'=>false,'reason'=>'PROC_OPEN_FAILED','exit_code'=>127,'elapsed_sec'=>0.0,'output_tail'=>''];
    $timeout=max(30,(int)($job['timeout_sec']??600));$timed=false;$exit=null;$lastDaemonBeat=0;
    while(true){
        $st=proc_get_status($proc);
        if(!$st['running']){$exit=(int)$st['exitcode'];break;}
        if($daemonContext&&time()-$lastDaemonBeat>=10){$lastDaemonBeat=time();$stopPending=is_file(tr_paths()['daemon_stop']);tr_daemon_write(['action'=>$stopPending?'STOP_PENDING_JOB_RUNNING':'JOB_RUNNING','job'=>$key,'job_pid'=>(int)($st['pid']??0),'job_elapsed_sec'=>round(microtime(true)-$t,1)]);}
        if((microtime(true)-$t)>$timeout){$timed=true;@proc_terminate($proc,15);$until=microtime(true)+5.0;do{usleep(200000);$st=proc_get_status($proc);}while($st['running']&&microtime(true)<$until);if($st['running'])@proc_terminate($proc,9);break;}
        usleep(250000);
    }
    $pc=@proc_close($proc);if($exit===null||$exit<0)$exit=is_int($pc)?$pc:1;if($timed)$exit=124;
    $elapsed=round(microtime(true)-$t,3);$tail=tr_tail_file($out,max(4096,(int)$cfg['child_output_max_bytes']));
    return['ok'=>!$timed&&$exit===0,'reason'=>$timed?'TIMEOUT':($exit===0?'OK':'CHILD_EXIT'),'exit_code'=>$exit,'elapsed_sec'=>$elapsed,'output_tail'=>$tail,'command'=>$script.' '.$arg];
}
function tr_heartbeat(array $state,array $extra=[]): void {
    $p=tr_paths();$row=['schema'=>'trade_runner_heartbeat_v1','version'=>TR_VERSION,'rev'=>TR_REV,'at'=>tr_now(),'pid'=>getmypid(),'sequence'=>(int)($state['sequence']??0)]+$extra;tr_atomic_json($p['heartbeat'],$row);
}
function tr_mark_cycle(array &$state,array $cycle,bool $heartbeat=true): void {
    $state['sequence']=(int)($state['sequence']??0)+1;$state['last_heartbeat_at']=time();$state['last_cycle']=$cycle;tr_state_save($state);if($heartbeat)tr_heartbeat($state,['action'=>(string)($cycle['action']??''),'job'=>(string)($cycle['job']??''),'metrics'=>$cycle['metrics']??[]]);
}
function tr_cycle(bool $daemonContext=false): array {
    $cfg=tr_config();$p=tr_paths();tr_mkdir($p['runtime']);$lock=tr_lock();if($lock===null)return['ok'=>true,'skipped'=>true,'reason'=>'RUNNER_LOCK_BUSY','version'=>TR_VERSION];
    try{
        $now=time();$manualReq=tr_manual_broker_request_load($p,$now);$manualActive=!empty($manualReq['active']);
        $auth=tr_authority_gate();if(empty($auth['ok'])){if($manualActive)tr_manual_broker_result($p,$manualReq,false,'AUTHORITY_BLOCK',['detail'=>$auth],true);tr_log('AUTHORITY_BLOCK',$auth);return['ok'=>false,'reason'=>'AUTHORITY_BLOCK','detail'=>$auth,'version'=>TR_VERSION];}
        $state=tr_state_load($cfg);$metrics=tr_metrics();$brokerLight=null;$candidate=null;$candidateReason='';
        $brokerCfg=(array)($cfg['jobs']['broker']??[]);$brokerState=&$state['jobs']['broker'];$prevActionable=(int)($brokerState['last_gate_actionable']??0);

        // v1.4.2: transport handoff is checked before canonical state.
        // Canonical INTENT existence never proves Broker ingest; Broker proof is ACK / broker_orders / explicit broker_seen metadata only.
        $tg=tr_transport_gate(false);$state['scheduler']['transport_gate']=$tg;$transportClass=tr_transport_class($tg);$transportPending=(int)($tg['pending_count']??0);$transportSig=(string)($tg['signature']??'');$storedTransportSig=(string)($state['scheduler']['transport_signature']??'');$transportRetry=(int)($state['scheduler']['transport_next_retry_at']??0);
        if($transportClass==='INTEGRITY_ERROR'||$transportClass==='TRANSIENT_ERROR'){
            // Fail-closed first. Pending rows inside an unhealthy truth source are evidence to preserve, never permission to run Broker.
            $state['scheduler']['transport_signature']='';$state['scheduler']['transport_next_retry_at']=0;$state['scheduler']['transport_stuck_count']=0;$fails=(int)($state['scheduler']['transport_gate_fail_count']??0)+1;$state['scheduler']['transport_gate_fail_count']=$fails;
            $delay=tr_transport_error_delay($tg,$cfg);$retry=$now+$delay;$state['scheduler']['transport_gate_retry_at']=$retry;
            $action=$transportClass==='TRANSIENT_ERROR'?'WAIT_TRANSPORT_GATE':'BLOCK_TRANSPORT_INTEGRITY';$cycle=['at'=>tr_now(),'action'=>$action,'job'=>'broker','reason'=>$tg['reason']??'TRANSPORT_GATE_ERROR','transport_class'=>$transportClass,'transport_gate'=>$tg,'retry_at'=>date('Y-m-d H:i:s',$retry),'retry_sec'=>$delay,'metrics'=>$metrics];tr_mark_cycle($state,$cycle);tr_log($action,['reason'=>$tg['reason']??'','transport_class'=>$transportClass,'retry_sec'=>$delay,'fail_count'=>$fails]);
            if($manualActive){tr_manual_broker_result($p,$manualReq,false,$action,['reason'=>$tg['reason']??'TRANSPORT_GATE_ERROR','retry_sec'=>$delay],true);$manualActive=false;}
            return['ok'=>$transportClass==='TRANSIENT_ERROR','action'=>$action,'job'=>'broker','reason'=>$tg['reason']??'TRANSPORT_GATE_ERROR','transport_class'=>$transportClass,'transport_gate'=>$tg,'wait_sec'=>$delay,'transport_pulse_required'=>false,'metrics'=>$metrics,'next_due'=>tr_next_due($state),'version'=>TR_VERSION];
        }elseif($transportClass==='PENDING'){
            $state['scheduler']['transport_gate_fail_count']=0;$state['scheduler']['transport_gate_retry_at']=0;
            if($transportSig!==$storedTransportSig||$transportRetry<=0){$transportRetry=$now;$state['scheduler']['transport_next_retry_at']=$transportRetry;$state['scheduler']['transport_signature']=$transportSig;$state['scheduler']['transport_stuck_count']=0;}
            if($transportRetry<=$now){$candidate=['key'=>'broker','cfg'=>$brokerCfg];$candidateReason='TRANSPORT_PENDING';}
            else{$wait=max(1,$transportRetry-$now);if($manualActive)tr_manual_broker_pending($p,$manualReq,'QUEUED_TRANSPORT_BACKOFF',['wait_sec'=>$wait,'retry_at'=>$transportRetry]);$cycle=['at'=>tr_now(),'action'=>'WAIT_TRANSPORT_BROKER','job'=>'broker','transport_gate'=>$tg,'wait_sec'=>$wait,'broker_due_at'=>date('Y-m-d H:i:s',$transportRetry),'metrics'=>$metrics];tr_mark_cycle($state,$cycle);return['ok'=>true,'action'=>'WAIT_TRANSPORT_BROKER','job'=>'broker','transport_gate'=>$tg,'wait_sec'=>$wait,'transport_pulse_required'=>false,'metrics'=>$metrics,'next_due'=>tr_next_due($state),'version'=>TR_VERSION];}
        }else{$state['scheduler']['transport_signature']='';$state['scheduler']['transport_next_retry_at']=0;$state['scheduler']['transport_stuck_count']=0;$state['scheduler']['transport_gate_fail_count']=0;$state['scheduler']['transport_gate_retry_at']=0;}

        // Dashboard manual Broker request: one-shot, Runner-owned execution. It never bypasses authority or transport integrity.
        if($manualActive){
            $lastManual=(int)($state['scheduler']['manual_broker_last_run_epoch']??0);$minGap=20;
            if($lastManual>0&&($now-$lastManual)<$minGap){
                $remain=$minGap-($now-$lastManual);tr_manual_broker_result($p,$manualReq,false,'MANUAL_BROKER_RATE_LIMIT',['retry_sec'=>$remain],true);$manualActive=false;
            }elseif($candidate===null){$candidate=['key'=>'broker','cfg'=>$brokerCfg];$candidateReason='MANUAL_BROKER_REQUEST';}
            elseif((string)($candidate['key']??'')==='broker'){$candidateReason='MANUAL_BROKER_REQUEST+'.($candidateReason!==''?$candidateReason:'BROKER_DUE');}
        }

        // Lightweight canonical gate runs on every scheduler wake. Missing/unknown broker_message is fail-safe ACTIONABLE.
        $bg=tr_execution_order_gate(false);
        if(empty($bg['ok'])){if($manualActive){tr_manual_broker_result($p,$manualReq,false,'CANONICAL_GATE_BLOCK',['detail'=>$bg],true);$manualActive=false;}tr_log('CANONICAL_GATE_BLOCK',$bg);$cycle=['at'=>tr_now(),'action'=>'BLOCK','job'=>'broker','reason'=>'CANONICAL_GATE','detail'=>$bg,'metrics'=>$metrics];tr_mark_cycle($state,$cycle);return['ok'=>false,'reason'=>'CANONICAL_GATE','detail'=>$bg,'version'=>TR_VERSION];}
        $brokerState['quick_checks']=(int)$brokerState['quick_checks']+1;$brokerState['last_gate_at']=tr_now();$brokerState['last_gate_active']=(int)$bg['active_order_count'];$brokerState['last_gate_actionable']=(int)$bg['actionable_order_count'];$brokerState['last_gate_market_wait']=(int)$bg['market_wait_order_count'];
        $lastFull=(int)($brokerState['last_full_run_epoch']??0);if($lastFull<=0){$x=strtotime((string)($brokerState['last_end_at']??''));$lastFull=$x===false?0:(int)$x;if($lastFull<=0)$lastFull=$now;$brokerState['last_full_run_epoch']=$lastFull;}
        $sweepDue=($now-$lastFull)>=max(900,(int)$cfg['broker_idle_full_sweep_sec']);$bg['idle_full_sweep_due']=$sweepDue;$state['scheduler']['broker_gate']=$bg;
        $activeNow=(int)$bg['active_order_count'];$actionableNow=(int)$bg['actionable_order_count'];$marketWaitNow=(int)$bg['market_wait_order_count'];

        if($candidate===null&&$actionableNow>0){
            // Actionable watchdog: once Broker owns an active order, a stale/future MARKET_WAIT due time may never suppress execution.
            $activeInterval=max(60,(int)($cfg['broker_active_interval_sec']??300));$watchdog=max(60,(int)($cfg['broker_actionable_watchdog_sec']??$activeInterval));
            $hardDue=$lastFull>0?$lastFull+$watchdog:$now;$scheduled=(int)($brokerState['next_due_at']??0);
            if($prevActionable<=0||$hardDue<=$now){$brokerState['next_due_at']=$now;}
            elseif($scheduled<=0||$scheduled>$hardDue){$brokerState['next_due_at']=$hardDue;}
            $brokerState['market_wait_recheck_at']=0;$brokerState['market_wait_signature']='';$brokerState['market_wait_plan']=[];
            $brokerDue=(int)($brokerState['next_due_at']??0)<=$now;
            if($brokerDue){$candidate=['key'=>'broker','cfg'=>$brokerCfg];$candidateReason='ACTIONABLE_ORDER';}
            else{$wait=max(1,(int)$brokerState['next_due_at']-$now);$brokerState['last_reason']='WAIT_ACTIONABLE_ORDER';$cycle=['at'=>tr_now(),'action'=>'WAIT_BROKER_ACTIONABLE','job'=>'broker','active_order_count'=>$activeNow,'actionable_order_count'=>$actionableNow,'market_wait_order_count'=>$marketWaitNow,'broker_due_at'=>date('Y-m-d H:i:s',(int)$brokerState['next_due_at']),'wait_sec'=>$wait,'watchdog_sec'=>$watchdog,'last_full_age_sec'=>$lastFull>0?max(0,$now-$lastFull):null,'metrics'=>$metrics,'broker_gate'=>$bg];tr_mark_cycle($state,$cycle);tr_log('WAIT_BROKER_ACTIONABLE',['actionable_order_count'=>$actionableNow,'wait_sec'=>$wait,'broker_due_at'=>$cycle['broker_due_at']]);return['ok'=>true,'action'=>'WAIT_BROKER_ACTIONABLE','job'=>'broker','actionable_order_count'=>$actionableNow,'wait_sec'=>$wait,'metrics'=>$metrics,'next_due'=>tr_next_due($state),'version'=>TR_VERSION];}
        }elseif($candidate===null&&$marketWaitNow>0){
            // WAIT_MARKET_OPEN is not actionable. Wake Broker around the next regular-session boundary; Broker remains the market-calendar truth.
            $sig=(string)($bg['market_wait_signature']??'');$storedSig=(string)($brokerState['market_wait_signature']??'');$recheck=(int)($brokerState['market_wait_recheck_at']??0);
            if($recheck<=0||$sig!==$storedSig){$plan=tr_market_wait_plan($bg,$now,$cfg,false);$recheck=(int)$plan['due_epoch'];$brokerState['market_wait_recheck_at']=$recheck;$brokerState['market_wait_signature']=$sig;$brokerState['market_wait_plan']=$plan;$brokerState['next_due_at']=$recheck;}
            $effective=max($recheck,(int)($brokerState['next_due_at']??0));$brokerState['next_due_at']=$effective;
            if($effective<=$now){$candidate=['key'=>'broker','cfg'=>$brokerCfg];$candidateReason='MARKET_WAIT_RECHECK';}
            else{$brokerState['last_reason']='MARKET_WAIT';$brokerLight=['action'=>'BROKER_MARKET_WAIT','gate'=>$bg,'recheck_at'=>date('Y-m-d H:i:s',$effective),'recheck_epoch'=>$effective];tr_log('BROKER_MARKET_WAIT',['market_wait_order_count'=>$marketWaitNow,'recheck_at'=>$brokerLight['recheck_at'],'markets'=>$bg['market_wait_markets']??[]]);}
        }elseif($candidate===null){
            $brokerState['market_wait_recheck_at']=0;$brokerState['market_wait_signature']='';$brokerState['market_wait_plan']=[];$brokerDue=(int)($brokerState['next_due_at']??0)<=$now;
            if($brokerDue){
                if($sweepDue){$candidate=['key'=>'broker','cfg'=>$brokerCfg];$candidateReason='IDLE_SAFETY_SWEEP';}
                else{$brokerState['idle_skips']=(int)$brokerState['idle_skips']+1;$brokerState['defer_count']=0;$brokerState['last_reason']='QUICK_IDLE';$brokerState['next_due_at']=$now+max(30,(int)$cfg['broker_quick_poll_sec']);$brokerLight=['action'=>'BROKER_IDLE_SKIP','gate'=>$bg];tr_log('BROKER_IDLE_SKIP',['active_order_count'=>0,'state_revision'=>$bg['state_revision']??0]);}
            }
        }

        // Strategies are blocked only by ACTIONABLE orders or a Broker heavy candidate. MARKET_WAIT-only orders may coexist with strategy evaluation.
        if($candidate===null){
            $allowStrategy=$transportPending===0&&!empty($tg['ok'])&&$actionableNow===0&&($marketWaitNow===0||!empty($cfg['broker_market_wait_strategy_passthrough']));
            if($allowStrategy){$due=tr_due_strategy_jobs($cfg,$state,$now);if($due){$pick=$due[0];$key=(string)$pick['key'];$job=(array)$pick['cfg'];$staleWatchdog=!empty($pick['stale_watchdog']);$state['scheduler']['strategy_watchdog']=['checked_at'=>tr_now(),'selected'=>$key,'forced'=>$staleWatchdog,'last_success_age_sec'=>$pick['last_success_age_sec']??null,'watchdog_sec'=>$pick['watchdog_sec']??null,'next_due_at'=>(int)($state['jobs'][$key]['next_due_at']??0)];$cool=(int)($state['scheduler']['strategy_cooldown_until']??0);if($cool>$now){$state['jobs'][$key]['next_due_at']=$cool;$state['jobs'][$key]['last_reason']=$staleWatchdog?'DEFER_COOLDOWN_STALE_WATCHDOG':'DEFER_COOLDOWN';$cycle=['at'=>tr_now(),'action'=>'DEFER_COOLDOWN','job'=>$key,'candidate_reason'=>$staleWatchdog?'STRATEGY_STALE_WATCHDOG':'STRATEGY_DUE','cooldown_until'=>$cool,'cooldown_at'=>date('Y-m-d H:i:s',$cool),'strategy_watchdog'=>$state['scheduler']['strategy_watchdog'],'metrics'=>$metrics,'broker_light'=>$brokerLight];tr_mark_cycle($state,$cycle);return['ok'=>true,'action'=>'DEFER_COOLDOWN','job'=>$key,'candidate_reason'=>$cycle['candidate_reason'],'cooldown_until'=>$cool,'metrics'=>$metrics,'next_due'=>tr_next_due($state),'version'=>TR_VERSION];}$candidate=['key'=>$key,'cfg'=>$job];$candidateReason=$staleWatchdog?'STRATEGY_STALE_WATCHDOG':($marketWaitNow>0?'STRATEGY_DUE_MARKET_WAIT_PASSTHROUGH':'STRATEGY_DUE');}}
        }

        if($candidate===null){
            if($brokerLight!==null){$a=(string)$brokerLight['action'];$cycle=['at'=>tr_now(),'action'=>$a,'job'=>'broker','metrics'=>$metrics,'broker_gate'=>$brokerLight['gate'],'recheck_at'=>$brokerLight['recheck_at']??null];tr_mark_cycle($state,$cycle);return['ok'=>true,'action'=>$a,'job'=>'broker','metrics'=>$metrics,'next_due'=>tr_next_due($state),'version'=>TR_VERSION];}
            $hb=(int)($state['last_heartbeat_at']??0);if($hb<=0||$now-$hb>=max(60,(int)$cfg['heartbeat_sec'])){$cycle=['at'=>tr_now(),'action'=>'IDLE','metrics'=>$metrics];tr_mark_cycle($state,$cycle);}return['ok'=>true,'action'=>'IDLE','version'=>TR_VERSION,'metrics'=>$metrics,'next_due'=>tr_next_due($state)];
        }

        $key=(string)$candidate['key'];$job=(array)$candidate['cfg'];$rg=tr_resource_gate($job,$metrics,$cfg);
        if(empty($rg['ok'])){$transportResourceDefer=($key==='broker')&&strpos($candidateReason,'TRANSPORT')===0;$defer=$transportResourceDefer?tr_transport_backoff_seconds(max(1,(int)($state['jobs'][$key]['defer_count']??0)+1),$cfg):tr_defer_seconds($key,$job,$state,$cfg);if($key==='broker'&&$manualActive)tr_manual_broker_pending($p,$manualReq,'QUEUED_RESOURCE_DEFER',['reason'=>$rg['reason']??'RESOURCE_GATE','retry_sec'=>$defer]);if($transportResourceDefer)$state['scheduler']['transport_next_retry_at']=$now+$defer;$js=&$state['jobs'][$key];$js['defer_count']=(int)$js['defer_count']+1;$js['next_due_at']=$now+$defer;$js['last_reason']='DEFER_'.$rg['reason'];$cycle=['at'=>tr_now(),'action'=>'DEFER','job'=>$key,'candidate_reason'=>$candidateReason,'defer_sec'=>$defer,'resource_gate'=>$rg,'metrics'=>$metrics,'broker_light'=>$brokerLight];tr_mark_cycle($state,$cycle);tr_log('JOB_DEFER',['job'=>$key,'candidate_reason'=>$candidateReason,'defer_sec'=>$defer,'detail'=>$rg,'metrics'=>$metrics]);return['ok'=>true,'action'=>'DEFER','job'=>$key,'reason'=>$rg['reason'],'defer_sec'=>$defer,'wait_sec'=>$defer,'transport_pulse_required'=>false,'metrics'=>$metrics,'next_due'=>tr_next_due($state),'version'=>TR_VERSION];}

        $state['jobs'][$key]['last_start_at']=tr_now();tr_state_save($state);tr_log('JOB_START',['job'=>$key,'candidate_reason'=>$candidateReason,'metrics'=>$metrics]);$res=tr_run_child($key,$job,$cfg,$daemonContext);$end=time();$js=&$state['jobs'][$key];$js['last_end_at']=tr_now();$js['last_exit_code']=$res['exit_code'];$js['last_elapsed_sec']=$res['elapsed_sec'];$js['last_reason']=$res['reason'];$js['last_output_tail']=$res['output_tail'];$js['runs']=(int)$js['runs']+1;
        if(!empty($res['ok'])){$js['consecutive_failures']=0;$js['defer_count']=0;}else{$js['failures']=(int)$js['failures']+1;$js['consecutive_failures']=(int)$js['consecutive_failures']+1;}

        $kind=(string)($job['kind']??'strategy');$coolSec=tr_cooldown_seconds($kind,(float)$res['elapsed_sec'],$cfg);$state['scheduler']['strategy_cooldown_until']=$end+$coolSec;$state['scheduler']['last_heavy_job']=$key;$state['scheduler']['last_heavy_end_at']=tr_now();$state['scheduler']['last_heavy_elapsed_sec']=(float)$res['elapsed_sec'];$postGate=null;$postTransport=null;$transportPulse=false;$transportWaitSec=0;
        if($kind==='broker'){
            if(!empty($res['ok'])){
                $js['last_full_run_epoch']=$end;$postTransport=tr_transport_gate(true);$state['scheduler']['transport_gate']=$postTransport;$postTransportClass=tr_transport_class($postTransport);if($postTransportClass==='INTEGRITY_ERROR'||$postTransportClass==='TRANSIENT_ERROR'){$state['scheduler']['transport_signature']='';$state['scheduler']['transport_stuck_count']=0;$delay=tr_transport_error_delay($postTransport,$cfg);$state['scheduler']['transport_next_retry_at']=$end+$delay;$state['scheduler']['transport_gate_retry_at']=$end+$delay;$js['next_due_at']=$end+$delay;$js['last_reason']=$postTransportClass==='TRANSIENT_ERROR'?'TRANSPORT_GATE_RETRY':'TRANSPORT_INTEGRITY_BLOCK';$transportWaitSec=$delay;$transportPulse=false;}elseif($postTransportClass==='PENDING'){$postSig=(string)($postTransport['signature']??'');$same=$candidateReason==='TRANSPORT_PENDING'&&$postSig!==''&&$postSig===$transportSig;$stuck=$same?((int)($state['scheduler']['transport_stuck_count']??0)+1):0;$state['scheduler']['transport_stuck_count']=$stuck;$retry=$same?tr_transport_backoff_seconds($stuck,$cfg):tr_transport_backoff_seconds(1,$cfg);$state['scheduler']['transport_signature']=$postSig;$state['scheduler']['transport_next_retry_at']=$end+$retry;$js['next_due_at']=$state['scheduler']['transport_next_retry_at'];$js['last_reason']=$same?'TRANSPORT_STUCK_RETRY':'TRANSPORT_PENDING';$transportWaitSec=$retry;$transportPulse=false;}else{$state['scheduler']['transport_signature']='';$state['scheduler']['transport_next_retry_at']=0;$state['scheduler']['transport_stuck_count']=0;}
                $postGate=tr_execution_order_gate(true);
                if(!empty($postGate['ok'])){
                    $state['scheduler']['broker_gate']=$postGate;$js['last_gate_at']=tr_now();$js['last_gate_active']=(int)$postGate['active_order_count'];$js['last_gate_actionable']=(int)$postGate['actionable_order_count'];$js['last_gate_market_wait']=(int)$postGate['market_wait_order_count'];
                    if($postTransportClass!=='CLEAR'){/* transport state owns Broker retry schedule until truth is healthy */}
                    elseif((int)$postGate['actionable_order_count']>0){$js['market_wait_recheck_at']=0;$js['market_wait_signature']='';$js['market_wait_plan']=[];$js['next_due_at']=$end+max(60,(int)$cfg['broker_active_interval_sec']);}
                    elseif((int)$postGate['market_wait_order_count']>0){$plan=tr_market_wait_plan($postGate,$end,$cfg,true);$js['market_wait_recheck_at']=(int)$plan['due_epoch'];$js['market_wait_signature']=(string)($postGate['market_wait_signature']??'');$js['market_wait_plan']=$plan;$js['next_due_at']=(int)$plan['due_epoch'];$js['last_reason']='MARKET_WAIT';}
                    else{$js['market_wait_recheck_at']=0;$js['market_wait_signature']='';$js['market_wait_plan']=[];$js['next_due_at']=$end+max(30,(int)$cfg['broker_quick_poll_sec']);}
                }else{$js['next_due_at']=$end+max(30,(int)$cfg['broker_defer_sec']);}
            }else{
                if($candidateReason==='TRANSPORT_PENDING'){
                    $stuck=(int)($state['scheduler']['transport_stuck_count']??0)+1;$state['scheduler']['transport_stuck_count']=$stuck;$retry=tr_transport_backoff_seconds($stuck,$cfg);$state['scheduler']['transport_signature']=$transportSig;$state['scheduler']['transport_next_retry_at']=$end+$retry;$js['next_due_at']=$end+$retry;$js['last_reason']='TRANSPORT_BROKER_FAILED_BACKOFF';$transportWaitSec=$retry;$transportPulse=false;
                }else{$js['next_due_at']=$end+max(30,(int)$cfg['broker_defer_sec']);}
            }
        }else{
            if(!empty($res['ok']))$js['next_due_at']=$end+max(300,(int)$job['interval_sec']);else$js['next_due_at']=$end+min(300,max(60,(int)$job['interval_sec']));
            $postTransport=tr_transport_gate(true);$state['scheduler']['transport_gate']=$postTransport;$postTransportClass=tr_transport_class($postTransport);if($postTransportClass==='INTEGRITY_ERROR'||$postTransportClass==='TRANSIENT_ERROR'){$state['scheduler']['transport_signature']='';$state['scheduler']['transport_stuck_count']=0;$transportWaitSec=tr_transport_error_delay($postTransport,$cfg);$state['scheduler']['transport_next_retry_at']=$end+$transportWaitSec;$state['scheduler']['transport_gate_retry_at']=$end+$transportWaitSec;$state['jobs']['broker']['next_due_at']=$end+$transportWaitSec;$state['jobs']['broker']['last_reason']=$postTransportClass==='TRANSIENT_ERROR'?'TRANSPORT_GATE_RETRY':'TRANSPORT_INTEGRITY_BLOCK';$transportPulse=false;}elseif($postTransportClass==='PENDING'){$state['scheduler']['transport_signature']=(string)($postTransport['signature']??'');$state['scheduler']['transport_next_retry_at']=$end;$state['scheduler']['transport_stuck_count']=0;$state['jobs']['broker']['next_due_at']=$end;$state['jobs']['broker']['market_wait_recheck_at']=0;$state['jobs']['broker']['market_wait_signature']='';$state['jobs']['broker']['market_wait_plan']=[];$transportPulse=true;}else{$state['scheduler']['transport_signature']='';$state['scheduler']['transport_next_retry_at']=0;$state['scheduler']['transport_stuck_count']=0;}
            $postGate=tr_execution_order_gate(true);if(!empty($postGate['ok'])){$state['scheduler']['broker_gate']=$postGate;if($postTransportClass==='CLEAR'&&!$transportPulse&&(int)$postGate['actionable_order_count']>0){$bd=(int)($state['jobs']['broker']['next_due_at']??PHP_INT_MAX);$soon=$end+max(0,(int)$cfg['broker_after_strategy_sec']);if($bd<=0||$bd>$soon)$state['jobs']['broker']['next_due_at']=$soon;$state['jobs']['broker']['market_wait_recheck_at']=0;$state['jobs']['broker']['market_wait_signature']='';$state['jobs']['broker']['market_wait_plan']=[];}}
        }        if($key==='broker'&&$manualActive){$state['scheduler']['manual_broker_last_run_epoch']=$end;$state['scheduler']['manual_broker_last_request_id']=(string)($manualReq['request_id']??'');tr_manual_broker_result($p,$manualReq,!empty($res['ok']),!empty($res['ok'])?'COMPLETED':'BROKER_CHILD_FAILED',['candidate_reason'=>$candidateReason,'exit_code'=>$res['exit_code'],'elapsed_sec'=>$res['elapsed_sec'],'child_reason'=>$res['reason'],'broker_last_end_at'=>tr_now()],true);$manualActive=false;}

        $cycle=['at'=>tr_now(),'action'=>'RUN','job'=>$key,'candidate_reason'=>$candidateReason,'result'=>['ok'=>$res['ok'],'reason'=>$res['reason'],'exit_code'=>$res['exit_code'],'elapsed_sec'=>$res['elapsed_sec']],'cooldown_sec'=>$coolSec,'cooldown_until'=>$state['scheduler']['strategy_cooldown_until'],'metrics'=>$metrics,'broker_light'=>$brokerLight,'post_gate'=>$postGate,'post_transport'=>$postTransport,'transport_pulse_required'=>$transportPulse,'wait_sec'=>$transportWaitSec>0?$transportWaitSec:null];tr_mark_cycle($state,$cycle);tr_log('JOB_END',['job'=>$key,'ok'=>$res['ok'],'reason'=>$res['reason'],'exit_code'=>$res['exit_code'],'elapsed_sec'=>$res['elapsed_sec'],'cooldown_sec'=>$coolSec,'transport_pulse'=>$transportPulse]);
        return['ok'=>!empty($res['ok']),'action'=>'RUN','job'=>$key,'candidate_reason'=>$candidateReason,'result'=>$res,'cooldown_sec'=>$coolSec,'transport_gate'=>$postTransport,'transport_pulse_required'=>$transportPulse,'wait_sec'=>$transportWaitSec>0?$transportWaitSec:null,'metrics'=>$metrics,'next_due'=>tr_next_due($state),'version'=>TR_VERSION];
    }catch(Throwable $e){tr_log('RUNNER_EXCEPTION',['error'=>$e->getMessage()]);return['ok'=>false,'reason'=>'EXCEPTION','error'=>$e->getMessage(),'version'=>TR_VERSION];}finally{tr_unlock($lock);}
}
function tr_next_due(array $state): array {$out=[];foreach((array)($state['jobs']??[])as$k=>$j)$out[$k]=['epoch'=>(int)($j['next_due_at']??0),'at'=>(int)($j['next_due_at']??0)>0?date('Y-m-d H:i:s',(int)$j['next_due_at']):''];return$out;}
function tr_health_finalize(array $checks): array {
    $pass=0;$hardFailures=[];$warnings=[];
    foreach($checks as$x){
        if(!empty($x['ok'])){$pass++;continue;}
        $row=['name'=>(string)($x['name']??'UNKNOWN'),'severity'=>(string)($x['severity']??'hard'),'detail'=>$x['detail']??null];
        if(($x['severity']??'hard')==='warning')$warnings[]=$row;else$hardFailures[]=$row;
    }
    $startupOk=count($hardFailures)===0;$allOk=$pass===count($checks);
    return[
        'ok'=>$startupOk,
        'startup_ok'=>$startupOk,
        'all_checks_ok'=>$allOk,
        'checks_pass'=>$pass,
        'checks_total'=>count($checks),
        'hard_failure_count'=>count($hardFailures),
        'warning_count'=>count($warnings),
        'hard_failures'=>$hardFailures,
        'warnings'=>$warnings,
        'checks'=>$checks,
    ];
}
function tr_health(bool $deep=true): array {
    $cfg=tr_config();$p=tr_paths();$gate=tr_authority_gate();$state=tr_state_load($cfg);$checks=[];
    $checks[]=['name'=>'Authority SINGLE_FILE_PAPER + REAL false','ok'=>!empty($gate['ok']),'severity'=>'hard','detail'=>$gate];
    $checks[]=['name'=>'Runner runtime writable','ok'=>(is_dir($p['runtime'])?is_writable($p['runtime']):is_writable(dirname($p['runtime']))),'severity'=>'hard','detail'=>$p['runtime']];
    $checks[]=['name'=>'proc_open available','ok'=>function_exists('proc_open'),'severity'=>'hard','detail'=>function_exists('proc_open')?'available':'disabled'];
    foreach((array)$cfg['jobs']as$k=>$j){$f=tr_base().'/'.basename((string)$j['script']);$checks[]=['name'=>'Job file '.$k,'ok'=>is_file($f)&&is_readable($f),'severity'=>'hard','detail'=>$f];}
    $canonical=tr_canonical_order_gate(true);$checks[]=['name'=>'Canonical quick gate readable','ok'=>!empty($canonical['ok']),'severity'=>'hard','detail'=>$canonical];
    $q=tr_execution_order_gate(true);$checks[]=['name'=>'Broker execution gate readable','ok'=>!empty($q['ok']),'severity'=>'hard','detail'=>$q];
    $tg=tr_transport_gate(true);$checks[]=['name'=>'Transport gate readable','ok'=>!empty($tg['ok']),'severity'=>'hard','detail'=>$tg];
    $checks[]=['name'=>'Strategy freshness watchdog installed','ok'=>strpos((string)@file_get_contents(__FILE__),'STRATEGY_STALE_WATCHDOG')!==false,'severity'=>'hard','detail'=>'oldest-success-first stale strategy recovery'];
    $vi=tr_validation_integrity();
    // Statistical validation evidence is important, but a stale/partial validation commit must not prevent
    // the daemon from starting and servicing already-ingested Broker orders. Keep it visible as a warning.
    $checks[]=['name'=>'Validation commit integrity','ok'=>!empty($vi['ok']),'severity'=>'warning','detail'=>$vi];
    if($deep&&is_file(tr_base().'/trade_single_authority_v100.php')){try{require_once tr_base().'/trade_single_authority_v100.php';$st=function_exists('tsa_status')?tsa_status():[];$checks[]=['name'=>'Canonical state valid PAPER','ok'=>!empty($st['ok'])&&!empty($st['state_valid'])&&empty($st['real_order_allowed'])&&strtoupper((string)($st['authority']??''))===TR_REQUIRED_AUTH,'severity'=>'hard','detail'=>$st];}catch(Throwable$e){$checks[]=['name'=>'Canonical state valid PAPER','ok'=>false,'severity'=>'hard','detail'=>$e->getMessage()];}}
    $summary=tr_health_finalize($checks);
    return array_merge($summary,['version'=>TR_VERSION,'rev'=>TR_REV,'metrics'=>tr_metrics(),'state'=>$state,'broker_gate'=>$q,'canonical_gate'=>$canonical,'transport_gate'=>$tg,'validation_integrity'=>$vi,'next_due'=>tr_next_due($state),'paths'=>$p]);
}
function tr_status(): array {$cfg=tr_config();$state=tr_state_load($cfg);return['ok'=>true,'version'=>TR_VERSION,'rev'=>TR_REV,'gate'=>tr_authority_gate(),'metrics'=>tr_metrics(),'broker_gate'=>tr_execution_order_gate(false),'canonical_gate'=>tr_canonical_order_gate(false),'transport_gate'=>tr_transport_gate(false),'validation_integrity'=>tr_validation_integrity(),'scheduler'=>$state['scheduler']??[],'state'=>$state,'next_due'=>tr_next_due($state),'paths'=>tr_paths()];}
function tr_reset_schedule(): array {$cfg=tr_config();$s=tr_state_init($cfg);tr_state_save($s);tr_heartbeat($s,['action'=>'RESET']);tr_log('SCHEDULE_RESET');return['ok'=>true,'version'=>TR_VERSION,'state'=>$s,'next_due'=>tr_next_due($s)];}
function tr_daemon_write(array $extra=[]): void {
    $p=tr_paths();$row=['schema'=>'trade_runner_daemon_heartbeat_v1','version'=>TR_VERSION,'rev'=>TR_REV,'at'=>tr_now(),'epoch'=>time(),'pid'=>getmypid()]+$extra;tr_atomic_json($p['daemon_heartbeat'],$row);@file_put_contents($p['daemon_pid'],(string)getmypid().PHP_EOL,LOCK_EX);
}
function tr_daemon(): int {
    $cfg=tr_config();$p=tr_paths();tr_mkdir($p['runtime']);$h=@fopen($p['daemon_lock'],'c+');if(!$h){tr_log('DAEMON_LOCK_OPEN_FAIL');return 2;}
    if(!@flock($h,LOCK_EX|LOCK_NB)){@fclose($h);tr_log('DAEMON_ALREADY_RUNNING');return 0;}
    @ftruncate($h,0);@fwrite($h,(string)getmypid());@fflush($h);@unlink($p['daemon_stop']);tr_daemon_write(['action'=>'START']);tr_log('DAEMON_START',['pid'=>getmypid()]);
    $sleep=max(15,(int)($cfg['poll_sec']??60));
    try{
        while(true){
            if(is_file($p['daemon_stop'])){@unlink($p['daemon_stop']);tr_daemon_write(['action'=>'STOP']);tr_log('DAEMON_STOP_REQUEST');break;}
            try{$r=tr_cycle(true);tr_daemon_write(['action'=>'LOOP','cycle_ok'=>!empty($r['ok']),'cycle_action'=>$r['action']??($r['reason']??''),'job'=>$r['job']??'']);}
            catch(Throwable $e){$r=['ok'=>false,'reason'=>'DAEMON_CYCLE_EXCEPTION','error'=>$e->getMessage()];tr_log('DAEMON_CYCLE_EXCEPTION',['error'=>$e->getMessage()]);tr_daemon_write(['action'=>'ERROR','error'=>$e->getMessage()]);}
            $cycleSleep=tr_daemon_cycle_sleep($r,$cfg);$remain=$cycleSleep;$idleBeat=max(5,min(20,(int)($cfg['daemon_idle_heartbeat_sec']??10)));$sinceBeat=0;while($remain>0){$step=min(5,$remain);sleep($step);$remain-=$step;$sinceBeat+=$step;if(is_file($p['daemon_stop']))break;if(is_file($p['broker_request'])){tr_daemon_write(['action'=>'MANUAL_BROKER_WAKE']);break;}if($sinceBeat>=$idleBeat){$sinceBeat=0;tr_daemon_write(['action'=>'LOOP_WAIT','cycle_ok'=>!empty($r['ok']),'cycle_action'=>$r['action']??($r['reason']??''),'job'=>'']);}}
        }
    }catch(Throwable $e){tr_log('DAEMON_FATAL',['error'=>$e->getMessage()]);tr_daemon_write(['action'=>'FATAL','error'=>$e->getMessage()]);}
    @unlink($p['daemon_pid']);@flock($h,LOCK_UN);@fclose($h);return 0;
}
function tr_main(array $argv): int {
    $cmd=strtolower((string)($argv[1]??'status'));
    if($cmd==='daemon')return tr_daemon();
    if($cmd==='cycle')$r=tr_cycle();elseif($cmd==='health')$r=tr_health(true);elseif($cmd==='status')$r=tr_status();elseif($cmd==='reset-schedule'&&in_array('--yes',$argv,true))$r=tr_reset_schedule();else{$r=['ok'=>false,'error'=>'UNKNOWN_OR_UNCONFIRMED_COMMAND','commands'=>['daemon','cycle','health','status','reset-schedule --yes'],'version'=>TR_VERSION];}
    echo tr_json($r,true).PHP_EOL;return!empty($r['ok'])?0:2;
}
if(PHP_SAPI==='cli'&&isset($_SERVER['SCRIPT_FILENAME'])&&realpath((string)$_SERVER['SCRIPT_FILENAME'])===__FILE__)exit(tr_main($argv??[]));