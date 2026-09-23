<?php
/**
 * Trade Low-Load Runner Status v1.4.6
 * Read-only observability page for Runner v1.4.6.
 * Uses Runner execution truth instead of reimplementing an older Canonical-only gate.
 * PHP 7.4 compatible.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors','0');
error_reporting(E_ALL);

const RS_VERSION='v1.4.6';
const RS_REV='trade-low-load-runner-status-v146-execution-truth-20260923-r1';
const RS_EXECUTION_TRUTH_MARKER='RUNNER_STATUS_EXECUTION_TRUTH_V1';

define('TR_TEST_MODE',true);
require_once __DIR__.'/trade_runner.php';

function rs_h($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function rs_json(string $f,array $d=[]): array {
    if(!is_file($f)||!is_readable($f))return $d;
    $raw=@file_get_contents($f);$j=json_decode((string)$raw,true);return is_array($j)?$j:$d;
}
function rs_dt($epoch): string {$n=(int)$epoch;return $n>0?date('Y-m-d H:i:s',$n):'-';}
function rs_num($v,int $d=0): string {return is_numeric($v)?number_format((float)$v,$d):'-';}

$st=tr_status();
$paths=is_array($st['paths']??null)?$st['paths']:tr_paths();
$state=is_array($st['state']??null)?$st['state']:[];
$scheduler=is_array($st['scheduler']??null)?$st['scheduler']:[];
$jobs=is_array($state['jobs']??null)?$state['jobs']:[];
$gate=is_array($st['gate']??null)?$st['gate']:[];
$metrics=is_array($st['metrics']??null)?$st['metrics']:[];
$execution=is_array($st['broker_gate']??null)?$st['broker_gate']:[];
$canonical=is_array($st['canonical_gate']??null)?$st['canonical_gate']:[];
$transport=is_array($st['transport_gate']??null)?$st['transport_gate']:[];
$validation=is_array($st['validation_integrity']??null)?$st['validation_integrity']:[];
$hb=rs_json((string)($paths['daemon_heartbeat']??''));
$pid=(int)($hb['pid']??0);
$hbEpoch=(int)($hb['epoch']??0);
$hbAge=$hbEpoch>0?max(0,time()-$hbEpoch):null;
$daemonOk=$pid>0&&$hbAge!==null&&$hbAge<=45&&($hb['version']??'')===TR_VERSION;

$actionable=(int)($execution['actionable_order_count']??0);
$marketWait=(int)($execution['market_wait_order_count']??0);
$active=(int)($execution['active_order_count']??0);
$recheck=(int)($scheduler['market_wait_recheck_at']??0);
$priority=$actionable>0?'BROKER FIRST':($marketWait>0?'STRATEGY ALLOWED':'NORMAL');
$priorityNote=$actionable>0?'실행 가능한 주문을 Broker가 먼저 처리합니다.':($marketWait>0?'시장대기 주문만 있으므로 전략 실행을 허용합니다.':'활성 주문 병목이 없습니다.');
$orderGate=$actionable>0?('ACTIONABLE '.$actionable):($marketWait>0?('MARKET WAIT '.$marketWait):'CLEAR');

$load=$metrics['load1']??null;
$mem=$metrics['mem_available_mb']??null;
$cool=(int)($scheduler['strategy_cooldown_until']??0);
$coolRemain=max(0,$cool-time());

if(($_GET['mode']??'')==='json'){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'=>true,'version'=>RS_VERSION,'rev'=>RS_REV,'runner_version'=>TR_VERSION,'runner_rev'=>TR_REV,
        'daemon'=>$hb,'authority'=>$gate,'metrics'=>$metrics,'execution_order_gate'=>$execution,
        'canonical_gate'=>$canonical,'transport_gate'=>$transport,'validation_integrity'=>$validation,
        'scheduler'=>$scheduler,'jobs'=>$jobs
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit;
}
header('Content-Type:text/html; charset=utf-8');
header('Cache-Control:no-store, no-cache, must-revalidate, max-age=0');
?><!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Low-Load Runner Status <?=rs_h(RS_VERSION)?></title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f4f6f8;color:#1f2937;margin:0}main{max-width:1180px;margin:auto;padding:16px}
h1{font-size:22px;margin:0 0 6px}.links{margin:0 0 14px}.links a{color:#1864ab;text-decoration:none;margin-right:10px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}.card{background:#fff;border:1px solid #e5e7eb;border-radius:11px;padding:11px}.big{font-size:18px;font-weight:750;margin-top:4px}.small,small{font-size:11px;color:#6b7280}
.ok{color:#087f5b}.warn{color:#b26a00}.bad{color:#c92a2a}.info{color:#1864ab}
table{width:100%;border-collapse:collapse;background:#fff;margin-top:12px;border-radius:10px;overflow:hidden}th,td{padding:8px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:12px;vertical-align:top}
section{margin-top:14px;background:#fff;border:1px solid #e5e7eb;border-radius:11px;padding:12px}pre{white-space:pre-wrap;overflow:auto;font-size:11px;margin:6px 0 0}
.note{background:#eef6ff;border-left:4px solid #1864ab;padding:9px 11px;border-radius:7px;margin-top:12px;font-size:12px}
</style></head><body><main>
<h1>Trade Low-Load Runner Status <?=rs_h(RS_VERSION)?></h1>
<div class="small"><?=rs_h(RS_REV)?> · Runner <?=rs_h(TR_VERSION)?> / <?=rs_h(TR_REV)?></div>
<p class="links"><a href="/trade_runner_control.php">WEB Control</a><a href="/trade_runner_preflight.php">Preflight</a><a href="/trade_pipeline_diag.php">Pipeline Diagnostics</a><a href="?mode=json">JSON</a></p>

<div class="cards">
 <div class="card">PHP Daemon<div class="big <?=$daemonOk?'ok':'bad'?>"><?= $daemonOk?'RUNNING':'CHECK' ?></div><small>PID <?=rs_h($pid?:'-')?> · HB age <?=rs_h($hbAge===null?'-':$hbAge.'s')?></small></div>
 <div class="card">Authority<div class="big"><?=rs_h($gate['authority']??'-')?></div><small>REAL <?=empty($gate['real_order_allowed'])?'false':'true'?></small></div>
 <div class="card">Daemon action<div class="big"><?=rs_h($hb['action']??'-')?></div><small><?=rs_h($hb['job']??'')?> <?=rs_h($hb['cycle_action']??'')?></small></div>
 <div class="card">Load 1m<div class="big <?=(is_numeric($load)&&(float)$load>1.8)?'warn':'ok'?>"><?=rs_h(is_numeric($load)?number_format((float)$load,2):'-')?></div><small>전략 기준 1.25 / Broker 1.80</small></div>
 <div class="card">Mem Available<div class="big <?=(is_numeric($mem)&&(float)$mem<64)?'warn':'ok'?>"><?=rs_h(is_numeric($mem)?number_format((float)$mem,1).' MB':'-')?></div><small>전략 최소 64 MB</small></div>
 <div class="card">Order Gate<div class="big <?=$actionable>0?'warn':($marketWait>0?'info':'ok')?>"><?=rs_h($orderGate)?></div><small>execution truth · active <?=rs_h($active)?></small></div>
 <div class="card">Broker Priority<div class="big <?=$actionable>0?'warn':'ok'?>"><?=rs_h($priority)?></div><small><?=rs_h($priorityNote)?></small></div>
 <div class="card">Market Recheck<div class="big <?=$marketWait>0?'info':'ok'?>"><?=rs_h($marketWait>0&&$recheck>0?date('m-d H:i',$recheck):'-')?></div><small><?=rs_h($marketWait>0?'Broker WAIT_MARKET_OPEN 기준':'시장대기 주문 없음')?></small></div>
 <div class="card">Transport<div class="big <?=!empty($transport['ok'])?'ok':'bad'?>"><?=rs_h($transport['reason']??(!empty($transport['ok'])?'OK':'CHECK'))?></div><small>pending <?=rs_h($transport['pending_count']??'-')?></small></div>
 <div class="card">Validation<div class="big <?=!empty($validation['ok'])?'ok':'warn'?>"><?=rs_h($validation['status']??(!empty($validation['ok'])?'OK':'CHECK'))?></div><small><?=!empty($validation['ok'])?'정상':'통계 검증 경고 · 실행 hard block 아님'?></small></div>
 <div class="card">Strategy Cooling<div class="big <?=$coolRemain>0?'warn':'ok'?>"><?=rs_h($coolRemain>0?$coolRemain.'s':'READY')?></div><small><?=rs_h($cool>0?date('H:i:s',$cool):'-')?></small></div>
</div>

<div class="note">Order Gate는 stale Canonical 단독값이 아니라 <b>BROKER_EXECUTION_PLUS_CANONICAL_FALLBACK</b> 실행 진실을 표시합니다. MARKET_WAIT만 있으면 전략 실행을 막지 않습니다.</div>

<table><tr><th>Job</th><th>Next Due</th><th>Last End</th><th>Exit</th><th>Elapsed</th><th>Runs/Fail</th><th>Defer</th><th>Reason</th></tr>
<?php foreach($jobs as$k=>$j):$due=(int)($j['next_due_at']??0); ?>
<tr><td><?=rs_h($k)?></td><td><?=rs_h(rs_dt($due))?></td><td><?=rs_h($j['last_end_at']??'-')?></td><td><?=rs_h($j['last_exit_code']??'-')?></td><td><?=rs_h(isset($j['last_elapsed_sec'])?rs_num($j['last_elapsed_sec'],3).'s':'-')?></td><td><?=rs_h(($j['runs']??0).'/'.($j['failures']??0))?></td><td><?=rs_h($j['defer_count']??0)?></td><td><?=rs_h($j['last_reason']??'')?></td></tr>
<?php endforeach;?></table>

<section><h2>Execution order gate</h2><pre><?=rs_h(json_encode($execution,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></section>
<section><h2>Transport gate</h2><pre><?=rs_h(json_encode($transport,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></section>
<section><h2>Validation integrity</h2><pre><?=rs_h(json_encode($validation,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></section>
<section><h2>Last cycle</h2><pre><?=rs_h(json_encode($state['last_cycle']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></pre></section>
</main></body></html>
