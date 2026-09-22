<?php
/**
 * trade_validation.php
 * 3+1 Trading System Validation & Performance Layer v1.3.1 · ERRC · TRANSACTION-JOURNAL · CRASH-RECOVERY · MARKET-TIMEZONE
 * Spec: 3+1 Trading System v1.4 FROZEN
 * PHP 7.4 compatible
 *
 * 운영 파일명: trade_validation.php
 * Single audit source: validation_runtime/events.jsonl
 * Mutable derived state: pending.json, state.json, strategy_summary.json,
 * independence_summary.json, challenger_summary.json
 */
declare(strict_types=1);

if(!defined('TV_VERSION')) define('TV_VERSION','v1.3.1');
if(!defined('TV_REV')) define('TV_REV','trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1');
if(!defined('TV_SCHEMA')) define('TV_SCHEMA','trade_validation_event_v2');
if(!defined('TV_PENDING_SCHEMA')) define('TV_PENDING_SCHEMA','trade_validation_pending_v2');
if(!defined('TV_STATE_SCHEMA')) define('TV_STATE_SCHEMA','trade_validation_state_v2');
if(!defined('TV_SUMMARY_SCHEMA')) define('TV_SUMMARY_SCHEMA','trade_strategy_summary_v2');
if(!defined('TV_MAX_RESOLVE_PER_TICK')) define('TV_MAX_RESOLVE_PER_TICK',40);
if(!defined('TV_MAX_RETURN_SAMPLES')) define('TV_MAX_RETURN_SAMPLES',1000);
if(!defined('TV_MIN_USABLE_SAMPLES')) define('TV_MIN_USABLE_SAMPLES',30);
if(!defined('TV_ERRC_MARKER')) define('TV_ERRC_MARKER','ERRC_RESOLVED_POPULATION_V6_INTEGRITY_COMMIT_MANIFEST');
if(!defined('TV_EVENTS_MAX_BYTES')) define('TV_EVENTS_MAX_BYTES',8388608);
if(!defined('TV_EVENTS_ROTATIONS')) define('TV_EVENTS_ROTATIONS',3);
if(!defined('TV_ERROR_MAX_BYTES')) define('TV_ERROR_MAX_BYTES',2097152);
if(!defined('TV_STATE_INDEX_MAX')) define('TV_STATE_INDEX_MAX',10000);
if(!defined('TV_EVENT_DEDUPE_MAX')) define('TV_EVENT_DEDUPE_MAX',20000);
if(!defined('TV_QUARANTINE_KEEP')) define('TV_QUARANTINE_KEEP',8);
if(!defined('TV_COMMIT_SCHEMA')) define('TV_COMMIT_SCHEMA','trade_validation_commit_v1');
if(!defined('TV_RECOVERY_MAX_BYTES')) define('TV_RECOVERY_MAX_BYTES',2097152);
if(!defined('TV_TXN_SCHEMA')) define('TV_TXN_SCHEMA','trade_validation_txn_v1');
if(!defined('TV_TXN_STAGE_KEEP_SEC')) define('TV_TXN_STAGE_KEEP_SEC',86400);


function tv_rotate_file(string $file,int $maxBytes,int $rotations=3): void
{
    if($maxBytes<1||!is_file($file))return;$size=@filesize($file);if($size===false||$size<$maxBytes)return;$rotations=max(1,$rotations);
    for($i=$rotations;$i>=1;$i--){$src=$i===1?$file:$file.'.'.($i-1);$dst=$file.'.'.$i;if(is_file($dst))@unlink($dst);if(is_file($src))@rename($src,$dst);}
}
function tv_market_timezone(string $market): string{$m=strtoupper($market);return$m==='US'?'America/New_York':($m==='JP'?'Asia/Tokyo':'Asia/Seoul');}
function tv_market_close_time(string $market): string{return strtoupper($market)==='US'?'16:00:00':'15:30:00';}
function tv_next_weekday_date(string $date,int $days,string $tzName): string
{
    try{$d=new DateTime($date.' 12:00:00',new DateTimeZone($tzName));}catch(Throwable $e){return$date;}$n=0;while($n<$days){$d->modify('+1 day');$w=(int)$d->format('N');if($w<=5)$n++;}return$d->format('Y-m-d');
}
function tv_due_ts_for_horizon(string $market,int $signalTs,string $type,int $value): int
{
    if($signalTs<=0)return 0;$type=strtoupper($type);if($type==='MINUTES')return$signalTs+max(0,$value)*60;
    $tzName=tv_market_timezone($market);try{$tz=new DateTimeZone($tzName);$signal=(new DateTime('@'.$signalTs));$signal->setTimezone($tz);$date=$signal->format('Y-m-d');$close=new DateTime($date.' '.tv_market_close_time($market),$tz);}catch(Throwable $e){return 0;}
    if($type==='SESSION_CLOSE'){$target=$signalTs<$close->getTimestamp()?$date:tv_next_weekday_date($date,1,$tzName);}else{$target=tv_next_weekday_date($date,max(1,$value),$tzName);}
    try{return(new DateTime($target.' '.tv_market_close_time($market),$tz))->getTimestamp();}catch(Throwable $e){return 0;}
}
function tv_sample_signal_ts(array $sample): int
{
    $ts=(int)($sample['signal_ts']??0);if($ts>0)return$ts;
    foreach(['signal_time','data_timestamp','registered_at'] as $key){$v=(string)($sample[$key]??'');if($v==='')continue;$x=strtotime($v);if($x!==false&&$x>0)return(int)$x;}
    return 0;
}
function tv_repair_sample_due_ts(array &$sample): int
{
    $changed=0;$market=(string)($sample['market']??'');$signalTs=tv_sample_signal_ts($sample);if($signalTs>0&&(int)($sample['signal_ts']??0)<=0){$sample['signal_ts']=$signalTs;$changed++;}if(!is_array($sample['horizons']??null))$sample['horizons']=[];foreach($sample['horizons']as$label=>&$h){if(!is_array($h))continue;if((int)($h['due_ts']??0)>0)continue;$due=tv_due_ts_for_horizon($market,$signalTs,(string)($h['type']??''),(int)($h['value']??0));if($due>0){$h['due_ts']=$due;$h['due_basis']=strtoupper((string)($h['type']??''))==='MINUTES'?'EXACT_MINUTES':'MARKET_WEEKDAY_ESTIMATE_RESOLVER_USES_ACTUAL_BARS';$changed++;}}unset($h);if($changed>0)$sample['updated_at']=tv_now();return$changed;
}
function tv_resolution_key(array $sample,string $label): string{return (string)($sample['id']??'').'|'.$label;}
function tv_summary_is_corrupt(array $summary): bool
{
    foreach((array)($summary['strategies']??[]) as $s){if(!is_array($s))continue;$signals=max(0,(int)($s['signals']??0));$rp=max(0,(int)($s['resolved_primary']??0));if($rp>$signals)return true;foreach((array)($s['horizons']??[])as$h)if(is_array($h)&&(int)($h['resolved']??0)>$signals)return true;}
    return false;
}
function tv_rebuild_summary_from_pending(array $pending): array
{
    $summary=tv_summary_default();$seen=[];
    foreach((array)($pending['samples']??[]) as $sample){if(!is_array($sample)||empty($sample['eligible_for_strategy_stats']))continue;$id=(string)($sample['id']??'');if($id===''||isset($seen[$id]))continue;$seen[$id]=1;$hash=(string)($sample['strategy_hash']??'');if($hash==='')continue;if(!isset($summary['strategies'][$hash]))$summary['strategies'][$hash]=tv_summary_strategy_default($sample);$s=&$summary['strategies'][$hash];$s['signals']++;if(strtoupper((string)($sample['side']??''))==='BUY')$s['buy_signals']++;elseif(strtoupper((string)($sample['side']??''))==='SELL')$s['sell_signals']++;$s['last_registered_at']=(string)($sample['registered_at']??tv_now());tv_summary_finalize_strategy($s);unset($s);$summary['totals']['signals']++;}
    $summary['updated_at']=tv_now();$summary['rebuild_basis']='PENDING_UNRESOLVED_AFTER_CORRUPTION';$summary['historical_validation_reset']=true;return$summary;
}
function tv_load_checked(string $file,array $default=[],string $expectedSchema=''): array
{
    if(!is_file($file))return['status'=>'MISSING','data'=>$default,'file'=>$file,'sha256'=>''];
    $raw=@file_get_contents($file);
    if(!is_string($raw))return['status'=>'READ_FAILED','data'=>$default,'file'=>$file,'sha256'=>''];
    $data=json_decode($raw,true);
    if(!is_array($data))return['status'=>'CORRUPT','data'=>$default,'file'=>$file,'sha256'=>hash('sha256',$raw),'json_error'=>json_last_error_msg()];
    $schema=(string)($data['schema']??'');
    if($expectedSchema!==''&&$schema!==$expectedSchema)return['status'=>'SCHEMA_MISMATCH','data'=>$data,'file'=>$file,'sha256'=>hash('sha256',$raw),'schema'=>$schema,'expected_schema'=>$expectedSchema];
    return['status'=>'OK','data'=>$data,'file'=>$file,'sha256'=>hash('sha256',$raw),'schema'=>$schema];
}
function tv_quarantine_corrupt_file(string $file,string $label=''): string
{
    if(!is_file($file))return'';
    $raw=(string)@file_get_contents($file);$stamp=date('Ymd-His').'-'.sprintf('%06d',(int)((microtime(true)-floor(microtime(true)))*1000000));
    $hash=substr(hash('sha256',$raw),0,8);$bak=$file.'.corrupt.'.$stamp.'.'.$hash;
    if(!@copy($file,$bak))return'';
    $glob=glob($file.'.corrupt.*')?:[];usort($glob,static function($a,$b){return(@filemtime($b)?:0)<=> (@filemtime($a)?:0);});
    foreach(array_slice($glob,TV_QUARANTINE_KEEP)as$old)@unlink($old);
    return$bak;
}
function tv_quarantine_corrupt_summary(string $file): void { tv_quarantine_corrupt_file($file,'summary'); }
function tv_recovery_log(array $c,string $event,array $detail=[]): void
{
    $p=tv_paths($c);tv_rotate_file($p['recovery'],TV_RECOVERY_MAX_BYTES,TV_EVENTS_ROTATIONS);
    $row=['at'=>tv_now(),'event'=>$event,'detail'=>$detail];
    @file_put_contents($p['recovery'],tv_json($row,false).PHP_EOL,FILE_APPEND|LOCK_EX);
}
function tv_guarded_load(array $c,string $file,array $default,string $expectedSchema,string $label,bool $allowDefaultRecovery=false): array
{
    $r=tv_load_checked($file,$default,$expectedSchema);
    if($r['status']==='MISSING'||$r['status']==='OK')return array_replace($default,(array)$r['data']);
    $bak=tv_quarantine_corrupt_file($file,$label);
    $msg='INTEGRITY_'.$label.'_'.$r['status'].' backup='.$bak;
    tv_error($c,$msg);tv_recovery_log($c,'INTEGRITY_FAILURE',['label'=>$label,'status'=>$r['status'],'file'=>$file,'backup'=>$bak]);
    if($allowDefaultRecovery)return $default;
    throw new RuntimeException($msg);
}
function tv_commit_files(array $p): array
{
    return['pending'=>$p['pending'],'state'=>$p['state'],'strategy_summary'=>$p['strategy_summary'],'independence_summary'=>$p['independence_summary'],'challenger_summary'=>$p['challenger_summary']];
}
function tv_write_commit_manifest(array $c,array $p,string $reason=''): bool
{
    $hashes=[];foreach(tv_commit_files($p)as$k=>$f)$hashes[$k]=is_file($f)?(string)(@hash_file('sha256',$f)?:''):'';
    $m=['schema'=>TV_COMMIT_SCHEMA,'generation'=>date('YmdHis').'-'.sprintf('%06d',(int)((microtime(true)-floor(microtime(true)))*1000000)).'-'.substr(hash('sha256',uniqid('',true)),0,6),'committed_at'=>tv_now(),'reason'=>$reason,'hashes'=>$hashes,'complete'=>true];
    $ok=tv_save($p['commit'],$m);if(!$ok)tv_error($c,'COMMIT_MANIFEST_WRITE_FAILED '.$reason);return$ok;
}
function tv_verify_commit_manifest(array $c,array $p,bool $log=true): array
{
    $r=tv_load_checked($p['commit'],[],TV_COMMIT_SCHEMA);
    if($r['status']==='MISSING')return['ok'=>true,'status'=>'NO_MANIFEST','mismatches'=>[]];
    if($r['status']!=='OK'){if($log)tv_error($c,'COMMIT_MANIFEST_'.$r['status']);return['ok'=>false,'status'=>$r['status'],'mismatches'=>['manifest']];}
    $m=(array)$r['data'];$mismatch=[];foreach(tv_commit_files($p)as$k=>$f){$want=(string)($m['hashes'][$k]??'');$have=is_file($f)?(string)(@hash_file('sha256',$f)?:''):'';if($want!==$have)$mismatch[$k]=['expected'=>$want,'actual'=>$have];}
    if($mismatch&&$log){tv_error($c,'PARTIAL_COMMIT_DETECTED '.implode(',',array_keys($mismatch)));tv_recovery_log($c,'PARTIAL_COMMIT_DETECTED',['mismatches'=>$mismatch,'generation'=>$m['generation']??'']);}
    return['ok'=>!$mismatch,'status'=>$mismatch?'PARTIAL_COMMIT':'OK','mismatches'=>$mismatch,'generation'=>(string)($m['generation']??'')];
}

function tv_file_sha(string $file): string { return is_file($file)?(string)(@hash_file('sha256',$file)?:''):''; }
function tv_write_commit_manifest_hashes(array $c,array $p,array $hashes,string $reason,string $generation=''): bool
{
    $all=[];foreach(tv_commit_files($p)as$k=>$f)$all[$k]=(string)($hashes[$k]??tv_file_sha($f));
    if($generation==='')$generation=date('YmdHis').'-'.sprintf('%06d',(int)((microtime(true)-floor(microtime(true)))*1000000)).'-'.substr(hash('sha256',uniqid('',true)),0,6);
    $m=['schema'=>TV_COMMIT_SCHEMA,'generation'=>$generation,'committed_at'=>tv_now(),'reason'=>$reason,'hashes'=>$all,'complete'=>true];
    $ok=tv_save($p['commit'],$m);if(!$ok)tv_error($c,'COMMIT_MANIFEST_WRITE_FAILED '.$reason);return$ok;
}
function tv_txn_generation(): string
{
    return date('YmdHis').'-'.sprintf('%06d',(int)((microtime(true)-floor(microtime(true)))*1000000)).'-'.substr(hash('sha256',uniqid('',true)),0,8);
}
function tv_txn_cleanup_orphans(array $p,string $keepGeneration=''): void
{
    $root=(string)($p['txn_stage']??'');if($root===''||!is_dir($root))return;$now=time();
    foreach((glob(rtrim($root,'/\\').'/*')?:[])as$d){if(!is_dir($d))continue;if($keepGeneration!==''&&basename($d)===$keepGeneration)continue;$mt=(int)@filemtime($d);if($mt>0&&$now-$mt<TV_TXN_STAGE_KEEP_SEC)continue;foreach((glob(rtrim($d,'/\\').'/*')?:[])as$f)if(is_file($f))@unlink($f);@rmdir($d);}
}
function tv_txn_validate(array $txn,array $p): array
{
    if(($txn['schema']??'')!==TV_TXN_SCHEMA)return['ok'=>false,'reason'=>'TXN_SCHEMA_INVALID'];$gen=(string)($txn['generation']??'');if($gen==='')return['ok'=>false,'reason'=>'TXN_GENERATION_MISSING'];
    $commitFiles=tv_commit_files($p);$writes=is_array($txn['writes']??null)?$txn['writes']:[];$final=is_array($txn['final_hashes']??null)?$txn['final_hashes']:[];if(!$writes||!$final)return['ok'=>false,'reason'=>'TXN_CONTENT_MISSING'];
    $expectedStageDir=rtrim((string)$p['txn_stage'],'/\\').'/'.$gen;if((string)($txn['stage_dir']??'')!==$expectedStageDir)return['ok'=>false,'reason'=>'TXN_STAGE_DIR_MISMATCH'];
    foreach($writes as$k=>$e){if(!isset($commitFiles[$k])||!is_array($e))return['ok'=>false,'reason'=>'TXN_WRITE_KEY_INVALID','key'=>$k];if((string)($e['target']??'')!==$commitFiles[$k])return['ok'=>false,'reason'=>'TXN_TARGET_MISMATCH','key'=>$k];$stage=(string)($e['stage']??'');if($stage!==$expectedStageDir.'/'.$k.'.json')return['ok'=>false,'reason'=>'TXN_STAGE_PATH_MISMATCH','key'=>$k];$sha=(string)($e['sha256']??'');if(!preg_match('/^[a-f0-9]{64}$/',$sha))return['ok'=>false,'reason'=>'TXN_HASH_INVALID','key'=>$k];}
    foreach($commitFiles as$k=>$f){if(!array_key_exists($k,$final))return['ok'=>false,'reason'=>'TXN_FINAL_HASH_MISSING','key'=>$k];$h=(string)$final[$k];if($h!==''&&!preg_match('/^[a-f0-9]{64}$/',$h))return['ok'=>false,'reason'=>'TXN_FINAL_HASH_INVALID','key'=>$k];}
    return['ok'=>true,'generation'=>$gen,'writes'=>count($writes)];
}
function tv_recover_bundle_transaction_locked(array $c,array $p): array
{
    if(!is_file($p['txn'])){tv_txn_cleanup_orphans($p);return['ok'=>true,'status'=>'NO_TXN','recovered'=>false];}
    $ck=tv_load_checked($p['txn'],[],TV_TXN_SCHEMA);if(($ck['status']??'')!=='OK'){tv_error($c,'TXN_JOURNAL_'.($ck['status']??'INVALID'));return['ok'=>false,'status'=>'TXN_JOURNAL_INVALID','detail'=>$ck];}
    $txn=(array)$ck['data'];$valid=tv_txn_validate($txn,$p);if(empty($valid['ok'])){tv_error($c,'TXN_INVALID '.($valid['reason']??''));return['ok'=>false,'status'=>'TXN_INVALID','detail'=>$valid];}
    $gen=(string)$txn['generation'];$writes=(array)$txn['writes'];$steps=[];
    foreach($writes as$k=>$e){$target=(string)$e['target'];$stage=(string)($e['stage']??'');$want=(string)$e['sha256'];$have=tv_file_sha($target);
        if($have!==''&&hash_equals($want,$have)){$steps[$k]='TARGET_ALREADY_COMMITTED';continue;}
        $stageHash=tv_file_sha($stage);if($stageHash===''||!hash_equals($want,$stageHash)){tv_error($c,'TXN_STAGE_MISSING_OR_HASH_MISMATCH '.$gen.' '.$k);return['ok'=>false,'status'=>'TXN_UNRECOVERABLE','generation'=>$gen,'file_key'=>$k,'target_hash'=>$have,'stage_hash'=>$stageHash,'expected'=>$want,'steps'=>$steps];}
        if(!@rename($stage,$target)){tv_error($c,'TXN_RENAME_FAILED '.$gen.' '.$k);return['ok'=>false,'status'=>'TXN_RENAME_FAILED','generation'=>$gen,'file_key'=>$k,'steps'=>$steps];}
        $after=tv_file_sha($target);if($after===''||!hash_equals($want,$after)){tv_error($c,'TXN_TARGET_VERIFY_FAILED '.$gen.' '.$k);return['ok'=>false,'status'=>'TXN_TARGET_VERIFY_FAILED','generation'=>$gen,'file_key'=>$k,'actual'=>$after,'expected'=>$want,'steps'=>$steps];}$steps[$k]='RECOVERED_FROM_STAGE';
    }
    $final=(array)$txn['final_hashes'];foreach(tv_commit_files($p)as$k=>$f){$have=tv_file_sha($f);$want=(string)($final[$k]??'');if($have!==$want){tv_error($c,'TXN_FINAL_SET_MISMATCH '.$gen.' '.$k);return['ok'=>false,'status'=>'TXN_FINAL_SET_MISMATCH','generation'=>$gen,'file_key'=>$k,'actual'=>$have,'expected'=>$want,'steps'=>$steps];}}
    if(!tv_write_commit_manifest_hashes($c,$p,$final,'TXN_RECOVERY '.(string)($txn['reason']??''),$gen)){return['ok'=>false,'status'=>'TXN_MANIFEST_WRITE_FAILED','generation'=>$gen,'steps'=>$steps];}
    $verify=tv_verify_commit_manifest($c,$p,true);if(empty($verify['ok']))return['ok'=>false,'status'=>'TXN_RECOVERY_VERIFY_FAILED','generation'=>$gen,'verify'=>$verify,'steps'=>$steps];
    @unlink($p['txn']);$stageDir=(string)($txn['stage_dir']??'');if($stageDir!==''&&is_dir($stageDir)){foreach((glob(rtrim($stageDir,'/\\').'/*')?:[])as$f)if(is_file($f))@unlink($f);@rmdir($stageDir);}tv_txn_cleanup_orphans($p);
    tv_recovery_log($c,'TXN_FORWARD_RECOVERED',['generation'=>$gen,'reason'=>$txn['reason']??'','steps'=>$steps]);return['ok'=>true,'status'=>'TXN_RECOVERED','recovered'=>true,'generation'=>$gen,'steps'=>$steps,'verify'=>$verify];
}
function tv_commit_bundle_transaction_locked(array $c,array $p,array $writes,string $reason): bool
{
    $pre=tv_recover_bundle_transaction_locked($c,$p);if(empty($pre['ok']))return false;$commitFiles=tv_commit_files($p);$keyByPath=[];foreach($commitFiles as$k=>$f)$keyByPath[$f]=$k;
    $gen=tv_txn_generation();$stageRoot=(string)$p['txn_stage'];$stageDir=rtrim($stageRoot,'/\\').'/'.$gen;if(!is_dir($stageDir)&&!@mkdir($stageDir,0775,true)&&!is_dir($stageDir)){tv_error($c,'TXN_STAGE_DIR_CREATE_FAILED '.$gen);return false;}
    $entries=[];$final=[];foreach($commitFiles as$k=>$f)$final[$k]=tv_file_sha($f);
    foreach($writes as$path=>$data){$path=(string)$path;if(!isset($keyByPath[$path])){tv_error($c,'TXN_UNKNOWN_COMMIT_PATH '.$reason.' '.$path);return false;}$k=$keyByPath[$path];$stage=$stageDir.'/'.$k.'.json';if(!tv_save($stage,(array)$data)){tv_error($c,'TXN_STAGE_WRITE_FAILED '.$reason.' '.$k);return false;}$sha=tv_file_sha($stage);if($sha===''){tv_error($c,'TXN_STAGE_HASH_FAILED '.$reason.' '.$k);return false;}$entries[$k]=['target'=>$path,'stage'=>$stage,'sha256'=>$sha];$final[$k]=$sha;}
    if(!$entries)return tv_write_commit_manifest($c,$p,$reason);
    $txn=['schema'=>TV_TXN_SCHEMA,'generation'=>$gen,'created_at'=>tv_now(),'reason'=>$reason,'stage_dir'=>$stageDir,'writes'=>$entries,'final_hashes'=>$final,'state'=>'PREPARED'];if(!tv_save($p['txn'],$txn)){tv_error($c,'TXN_JOURNAL_WRITE_FAILED '.$reason);return false;}
    $r=tv_recover_bundle_transaction_locked($c,$p);return!empty($r['ok']);
}

/**
 * Legacy conservative recovery for a single-file pending.json partial commit (pre-v1.3.x WAL state).
 * The commit manifest is metadata only. We never rewrite pending/state/summary here.
 * Recovery is allowed only when:
 *   - the ONLY hash mismatch is pending.json,
 *   - every committed JSON file has the expected schema,
 *   - every pending sample id is already present in state.dedupe.
 * This covers the observed crash/interruption window where pending.json was durably
 * written but the manifest refresh did not complete, without blessing an unknown
 * multi-file inconsistency. Any other mismatch remains fail-closed.
 */
function tv_pending_only_recovery_evidence(array $c,array $p,array $commitCheck): array
{
    $keys=array_keys((array)($commitCheck['mismatches']??[]));sort($keys,SORT_STRING);
    if($keys!==['pending'])return['ok'=>false,'reason'=>'RECOVERY_NOT_PENDING_ONLY','mismatch_keys'=>$keys];
    $checks=[
        'pending'=>tv_load_checked($p['pending'],tv_pending_default(),TV_PENDING_SCHEMA),
        'state'=>tv_load_checked($p['state'],tv_state_default(),TV_STATE_SCHEMA),
        'strategy_summary'=>tv_load_checked($p['strategy_summary'],tv_summary_default(),TV_SUMMARY_SCHEMA),
        'independence_summary'=>tv_load_checked($p['independence_summary'],tv_independence_default(),'trade_independence_v1'),
    ];
    foreach($checks as$k=>$r)if(($r['status']??'')!=='OK')return['ok'=>false,'reason'=>'RECOVERY_SOURCE_INVALID','file_key'=>$k,'status'=>$r['status']??'UNKNOWN'];
    $challenger=tv_load_checked($p['challenger_summary'],[],'trade_challenger_v1');
    if(($challenger['status']??'')!=='OK'&&($challenger['status']??'')!=='MISSING')return['ok'=>false,'reason'=>'RECOVERY_SOURCE_INVALID','file_key'=>'challenger_summary','status'=>$challenger['status']??'UNKNOWN'];
    $manifest=tv_load_checked($p['commit'],[],TV_COMMIT_SCHEMA);if(($manifest['status']??'')!=='OK')return['ok'=>false,'reason'=>'RECOVERY_MANIFEST_INVALID','status'=>$manifest['status']??'UNKNOWN'];
    $m=(array)$manifest['data'];$pending=(array)$checks['pending']['data'];$state=(array)$checks['state']['data'];$dedupe=is_array($state['dedupe']??null)?$state['dedupe']:[];$missing=[];$bad=[];$count=0;
    $committedAt=strtotime((string)($m['committed_at']??''));$pendingUpdated=strtotime((string)($pending['updated_at']??''));$mfM=(int)(@filemtime($p['commit'])?:0);$pM=(int)(@filemtime($p['pending'])?:0);
    if($committedAt!==false&&$pendingUpdated!==false&&$pendingUpdated<$committedAt)return['ok'=>false,'reason'=>'RECOVERY_PENDING_OLDER_THAN_COMMIT','pending_updated_at'=>$pending['updated_at']??'','commit_at'=>$m['committed_at']??''];
    if($mfM>0&&$pM>0&&$pM<$mfM)return['ok'=>false,'reason'=>'RECOVERY_PENDING_FILE_OLDER_THAN_MANIFEST','pending_mtime'=>$pM,'manifest_mtime'=>$mfM];
    foreach((array)($m['hashes']??[])as$k=>$h)if(!preg_match('/^[a-f0-9]{64}$/',(string)$h))return['ok'=>false,'reason'=>'RECOVERY_MANIFEST_HASH_INVALID','file_key'=>(string)$k];
    foreach((array)($pending['samples']??[])as$id=>$sample){
        if(!is_array($sample)){if(count($bad)<10)$bad[]=(string)$id;continue;}
        $sid=(string)($sample['id']??$id);$count++;
        if($sid===''||$sid!==(string)$id){if(count($bad)<10)$bad[]=(string)$id;continue;}
        if(!isset($dedupe[$sid])&&count($missing)<10)$missing[]=$sid;
    }
    if($bad)return['ok'=>false,'reason'=>'RECOVERY_PENDING_ID_INVALID','bad_samples'=>$bad,'pending_count'=>$count];
    if($missing)return['ok'=>false,'reason'=>'RECOVERY_PENDING_NOT_IN_STATE_DEDUPE','missing_dedupe'=>$missing,'pending_count'=>$count];
    return['ok'=>true,'reason'=>'PENDING_ONLY_SUPPORTED_BY_STATE_DEDUPE_AND_TIME_ORDER','pending_count'=>$count,'state_dedupe_count'=>count($dedupe),'pending_updated_at'=>$pending['updated_at']??'','commit_at'=>$m['committed_at']??''];
}
function tv_recover_pending_only_commit_locked(array $c,array $p,array $commitCheck): array
{
    $e=tv_pending_only_recovery_evidence($c,$p,$commitCheck);if(empty($e['ok']))return['ok'=>false,'repaired'=>false,'reason'=>$e['reason']??'RECOVERY_EVIDENCE_FAILED','evidence'=>$e,'commit'=>$commitCheck];
    $backup='';if(is_file($p['commit'])){$stamp=date('Ymd-His').'-'.substr(hash('sha256',microtime(true).'|'.getmypid()),0,8);$backup=$p['commit'].'.pre_recovery.'.$stamp;@copy($p['commit'],$backup);}
    tv_recovery_log($c,'PENDING_ONLY_PARTIAL_COMMIT_RECOVERY_BEGIN',['commit'=>$commitCheck,'evidence'=>$e,'manifest_backup'=>$backup]);
    if(!tv_write_commit_manifest($c,$p,'RECOVER_PENDING_ONLY_PARTIAL_COMMIT'))return['ok'=>false,'repaired'=>false,'reason'=>'RECOVERY_MANIFEST_WRITE_FAILED','evidence'=>$e,'manifest_backup'=>$backup];
    $verify=tv_verify_commit_manifest($c,$p,true);$ok=!empty($verify['ok']);
    tv_recovery_log($c,$ok?'PENDING_ONLY_PARTIAL_COMMIT_RECOVERED':'PENDING_ONLY_PARTIAL_COMMIT_RECOVERY_VERIFY_FAILED',['verify'=>$verify,'evidence'=>$e,'manifest_backup'=>$backup]);
    return['ok'=>$ok,'repaired'=>$ok,'reason'=>$ok?'RECOVERED':'RECOVERY_VERIFY_FAILED','evidence'=>$e,'verify'=>$verify,'manifest_backup'=>$backup];
}
function tv_commit_guard_locked(array $c,array $p,bool $allowRecovery=true): array
{
    // WAL-backed transactions are cryptographically forward-recoverable and may be recovered automatically.
    // Legacy hash-only PARTIAL_COMMIT is NEVER silently blessed in normal runtime. It requires the explicit
    // browser recovery flow while the Runner is STOPPED, SINGLE_FILE_PAPER, REAL=false.
    $txn=tv_recover_bundle_transaction_locked($c,$p);if(empty($txn['ok']))return['ok'=>false,'status'=>'TXN_RECOVERY_FAILED','mismatches'=>['txn'],'transaction'=>$txn];
    $check=tv_verify_commit_manifest($c,$p,true);if(!empty($check['ok'])){$check['transaction']=$txn;return$check;}
    $check['transaction']=$txn;$check['recovery']=['ok'=>false,'repaired'=>false,'reason'=>'EXPLICIT_LEGACY_RECOVERY_REQUIRED'];return$check;
}
function tv_recover_partial_commit(array $c): array
{
    $fp=tv_lock($c);if(!$fp)return['ok'=>false,'repaired'=>false,'reason'=>'VALIDATION_LOCK_FAILED'];
    try{$p=tv_paths($c);$txn=tv_recover_bundle_transaction_locked($c,$p);if(empty($txn['ok']))return['ok'=>false,'repaired'=>false,'reason'=>'TXN_RECOVERY_FAILED','transaction'=>$txn];$check=tv_verify_commit_manifest($c,$p,true);if(!empty($check['ok']))return['ok'=>true,'repaired'=>!empty($txn['recovered']),'reason'=>!empty($txn['recovered'])?'TXN_RECOVERED':'ALREADY_CONSISTENT','commit'=>$check,'transaction'=>$txn];$legacy=tv_recover_pending_only_commit_locked($c,$p,$check);$legacy['transaction']=$txn;return$legacy;}finally{tv_unlock($fp);}
}

function tv_save_bundle(array $c,array $p,array $writes,string $reason): bool
{
    return tv_commit_bundle_transaction_locked($c,$p,$writes,$reason);
}
function tv_prune_state(array &$state): void
{
    foreach(['signal_index','resolved_index','resolution_dedupe']as$key){if(!is_array($state[$key]??null)){$state[$key]=[];continue;}if(count($state[$key])>TV_STATE_INDEX_MAX)$state[$key]=array_slice($state[$key],-TV_STATE_INDEX_MAX,null,true);}
    if(!is_array($state['event_dedupe']??null))$state['event_dedupe']=[];if(count($state['event_dedupe'])>TV_EVENT_DEDUPE_MAX)$state['event_dedupe']=array_slice($state['event_dedupe'],-TV_EVENT_DEDUPE_MAX,null,true);
}

function tv_now(): string { return date('Y-m-d H:i:s'); }
function tv_iso(int $ts=0): string { if($ts<=0)$ts=time(); return date('c',$ts); }
function tv_json($v,bool $pretty=false): string { $j=json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|($pretty?JSON_PRETTY_PRINT:0)); return $j===false?'{}':$j; }
function tv_canonical($v){ if(!is_array($v))return $v; $keys=array_keys($v); $list=$v===[]||$keys===range(0,count($v)-1); if($list){$o=[];foreach($v as$x)$o[]=tv_canonical($x);return$o;} ksort($v,SORT_STRING);foreach($v as$k=>$x)$v[$k]=tv_canonical($x);return$v; }
function tv_hash(array $v): string { return hash('sha256',tv_json(tv_canonical($v),false)); }

function tv_paths(array $c): array
{
    $root=(string)($c['validation_runtime']??(__DIR__.'/validation_runtime'));
    // v1.3 FROZEN canonical runtime name.
    if(basename($root)==='trade_validation_runtime')$root=dirname($root).'/validation_runtime';
    return [
        'root'=>$root,'events'=>$root.'/events.jsonl','pending'=>$root.'/pending.json','state'=>$root.'/state.json',
        'strategy_summary'=>$root.'/strategy_summary.json','independence_summary'=>$root.'/independence_summary.json',
        'challenger_summary'=>$root.'/challenger_summary.json','commit'=>$root.'/validation_commit.json','txn'=>$root.'/validation_txn.json','txn_stage'=>$root.'/validation_txn_stage','recovery'=>$root.'/validation_recovery.jsonl','lock'=>$root.'/validation.lock','error'=>$root.'/validation_error.log',
    ];
}
function tv_dirs(array $c): array
{
    $p=tv_paths($c);if(!is_dir($p['root'])&&!@mkdir($p['root'],0775,true)&&!is_dir($p['root']))throw new RuntimeException('VALIDATION_DIR_CREATE_FAILED '.$p['root']);
    if(!is_writable($p['root']))throw new RuntimeException('VALIDATION_DIR_NOT_WRITABLE '.$p['root']);if(!is_dir($p['txn_stage'])&&!@mkdir($p['txn_stage'],0775,true)&&!is_dir($p['txn_stage']))throw new RuntimeException('VALIDATION_TXN_STAGE_CREATE_FAILED '.$p['txn_stage']);return$p;
}
function tv_load(string $file,array $default=[]): array { if(!is_file($file))return$default;$raw=@file_get_contents($file);$d=json_decode((string)$raw,true);return is_array($d)?$d:$default; }
function tv_save(string $file,array $data): bool
{
    $dir=dirname($file);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return false;
    $tmp=$file.'.tmp.'.getmypid().'.'.substr(hash('sha256',uniqid('',true)),0,8);
    if(@file_put_contents($tmp,tv_json($data,true).PHP_EOL,LOCK_EX)===false){@unlink($tmp);return false;}
    if(!@rename($tmp,$file)){@unlink($tmp);return false;}return true;
}
function tv_lock(array $c)
{
    try{$p=tv_dirs($c);}catch(Throwable $e){return false;}$fp=@fopen($p['lock'],'c+');if(!$fp)return false;if(!@flock($fp,LOCK_EX)){@fclose($fp);return false;}return$fp;
}
function tv_unlock($fp): void { if(is_resource($fp)){@flock($fp,LOCK_UN);@fclose($fp);} }
function tv_error(array $c,string $message): void { $p=tv_paths($c);tv_rotate_file($p['error'],TV_ERROR_MAX_BYTES,TV_EVENTS_ROTATIONS);@file_put_contents($p['error'],'['.tv_now().'] '.$message.PHP_EOL,FILE_APPEND|LOCK_EX); }

function tv_state_default(): array
{
    return ['schema'=>TV_STATE_SCHEMA,'updated_at'=>tv_now(),'dedupe'=>[],'order_to_signal'=>[],'signal_index'=>[],'day_index'=>[],'symbol_last'=>[],'resolved_index'=>[],'resolved_pair_index'=>[],'resolution_dedupe'=>[],'event_dedupe'=>[],'summary_rebuild'=>[]];
}
function tv_pending_default(): array { return ['schema'=>TV_PENDING_SCHEMA,'updated_at'=>tv_now(),'samples'=>[]]; }
function tv_summary_default(): array { return ['schema'=>TV_SUMMARY_SCHEMA,'updated_at'=>tv_now(),'strategies'=>[],'totals'=>['signals'=>0,'resolved_primary'=>0]]; }
function tv_independence_default(): array { return ['schema'=>'trade_independence_v1','updated_at'=>tv_now(),'pairs'=>[]]; }

function tv_strategy_status(array $c): string
{
    $s=strtoupper(trim((string)($c['strategy_status']??'CORE')));return in_array($s,['CORE','CHALLENGER','RESEARCH_ONLY'],true)?$s:'CORE';
}
function tv_alpha_type(array $c): string { return strtoupper(trim((string)($c['alpha_type']??'UNKNOWN'))); }
function tv_strategy_hash(array $c): string
{
    $params=is_array($c['strategy_parameters']??null)?$c['strategy_parameters']:[];
    return tv_hash(['strategy'=>strtoupper((string)($c['strategy_key']??'')),'strategy_version'=>(string)($c['strategy_version']??''),'algorithm_revision'=>(string)($c['strategy_rev']??''),'alpha_type'=>tv_alpha_type($c),'parameters'=>$params]);
}
function tv_source_const(string $file,string $name): string
{
    if(!is_file($file))return'';$raw=@file_get_contents($file);if(!is_string($raw)||$raw==='')return'';
    $pattern='/const\s+'.preg_quote($name,'/').'\s*=\s*[\'\"]([^\'\"]+)[\'\"]\s*;/';
    return preg_match($pattern,$raw,$m)?(string)$m[1]:'';
}
function tv_system_hash(array $c): string
{
    $brokerFile=__DIR__.'/trade_broker.php';$brokerHash=is_file($brokerFile)?@hash_file('sha256',$brokerFile):'';$brokerRev=tv_source_const($brokerFile,'TB_REV');
    return tv_hash([
        'engine_revision'=>defined('TE_REV')?TE_REV:'','broker_revision'=>$brokerRev,'broker_file_sha256'=>$brokerHash,
        'risk_policy'=>$c['risk_policy']??[],'capital_policy'=>['seed_kr'=>$c['seed_kr']??null,'seed_us'=>$c['seed_us']??null,'seed_jp'=>$c['seed_jp']??null,'max_positions_total'=>$c['max_positions_total']??null,'max_positions_by_market'=>$c['max_positions_by_market']??[]],
        'cost_model'=>['KR'=>[defined('TE_KR_BUY_FEE')?TE_KR_BUY_FEE:null,defined('TE_KR_SELL_COST')?TE_KR_SELL_COST:null],'US'=>[defined('TE_US_BUY_FEE')?TE_US_BUY_FEE:null,defined('TE_US_SELL_COST')?TE_US_SELL_COST:null],'JP'=>[defined('TE_JP_BUY_FEE')?TE_JP_BUY_FEE:null,defined('TE_JP_SELL_COST')?TE_JP_SELL_COST:null]],
        'data_provider_revision'=>(string)($c['data_provider_revision']??'engine-default'),'universe_revision'=>is_file((string)($c['trade_list_file']??''))?@hash_file('sha256',(string)$c['trade_list_file']):'',
    ]);
}
function tv_signal_anchor(array $c,array $ctx): string
{
    $tf=(string)($c['validation']['anchor_timeframe']??$c['data_date_timeframe']??'1d');
    if(function_exists('te_validation_bar_identity'))return te_validation_bar_identity($ctx,$tf,(string)($ctx['market']??''));
    return (string)($ctx['market_date']??$ctx['session_date']??date('Y-m-d'));
}
function tv_supporting_strategies(array $c,string $market,string $symbol,string $date,string $primary): array
{
    $p=tv_paths($c);$state=array_replace(tv_state_default(),tv_load($p['state'],[]));$idx=strtoupper($market).'|'.strtoupper($symbol).'|'.$date;$rows=is_array($state['signal_index'][$idx]??null)?$state['signal_index'][$idx]:[];$out=[];foreach($rows as$s){$s=strtoupper((string)$s);if($s!==''&&$s!==strtoupper($primary)&&!in_array($s,$out,true))$out[]=$s;}sort($out,SORT_STRING);return$out;
}
function tv_signal_id(array $c,array $ctx,array $signal,string $side): string
{
    $market=strtoupper((string)($ctx['market']??''));$symbol=strtoupper((string)($ctx['symbol']??''));$type=strtoupper((string)($signal['type']??'SIGNAL'));
    $key=[strtoupper((string)($c['strategy_key']??'')),tv_strategy_hash($c),$market,$symbol,strtoupper($side),$type,tv_signal_anchor($c,$ctx)];
    return 'SIG-'.strtoupper((string)($c['strategy_key']??'X')).'-'.$market.'-'.$symbol.'-'.substr(hash('sha256',implode('|',$key)),0,18);
}
function tv_horizons(array $c,int $signalTs,string $market=''): array
{
    $market=strtoupper($market!==''?$market:(string)($c['market']??$c['current_market']??''));$out=[];foreach((array)($c['validation']['horizons']??[]) as $h){if(!is_array($h))continue;$label=(string)($h['label']??'');$type=strtoupper((string)($h['type']??''));$value=max(0,(int)($h['value']??0));if($label===''||!in_array($type,['MINUTES','TRADING_DAYS','SESSION_CLOSE'],true))continue;$due=tv_due_ts_for_horizon($market,$signalTs,$type,$value);$out[$label]=['label'=>$label,'type'=>$type,'value'=>$value,'status'=>'PENDING','due_ts'=>$due,'due_basis'=>$type==='MINUTES'?'EXACT_MINUTES':'MARKET_WEEKDAY_ESTIMATE_RESOLVER_USES_ACTUAL_BARS','result'=>null];}return$out;
}
function tv_event_id(string $type,string $signalId,array $data=[]): string
{
    $key=$type.'|'.$signalId.'|'.($data['order_id']??'').'|'.($data['trade_id']??'').'|'.($data['horizon']??'').'|'.($data['status']??'');return 'EVT-'.substr(hash('sha256',$key),0,24);
}
function tv_event_seen_in_log(string $file,string $eventId): bool
{
    if($eventId===''||!is_file($file))return false;$size=@filesize($file);if($size===false||$size<=0)return false;$max=1048576;$offset=max(0,(int)$size-$max);$fp=@fopen($file,'rb');if(!$fp)return false;try{if($offset>0)@fseek($fp,$offset);$raw=@stream_get_contents($fp);return is_string($raw)&&strpos($raw,'"event_id":"'.$eventId.'"')!==false;}finally{@fclose($fp);}
}
function tv_append_event_locked(array $c,array &$state,string $type,string $signalId,array $data=[]): bool
{
    $p=tv_paths($c);$eventId=tv_event_id($type,$signalId,$data);if(isset($state['event_dedupe'][$eventId]))return true;
    // If the audit append succeeded but a later derived-state save failed, a retry must not duplicate the canonical event.
    if(tv_event_seen_in_log($p['events'],$eventId)){$state['event_dedupe'][$eventId]=1;return true;}
    $row=['schema'=>TV_SCHEMA,'event_id'=>$eventId,'event_type'=>$type,'event_at'=>tv_now(),'signal_id'=>$signalId]+$data;
    tv_rotate_file($p['events'],TV_EVENTS_MAX_BYTES,TV_EVENTS_ROTATIONS);
    $ok=@file_put_contents($p['events'],tv_json($row,false).PHP_EOL,FILE_APPEND|LOCK_EX)!==false;if($ok)$state['event_dedupe'][$eventId]=1;return$ok;
}
function tv_append_event(array $c,string $type,string $signalId,array $data=[]): bool
{
    $fp=tv_lock($c);if(!$fp)return false;try{$p=tv_paths($c);$commitCheck=tv_commit_guard_locked($c,$p,true);if(!$commitCheck['ok'])return false;$state=tv_guarded_load($c,$p['state'],tv_state_default(),TV_STATE_SCHEMA,'STATE',false);if(!is_array($state['event_dedupe']??null))$state['event_dedupe']=[];$ok=tv_append_event_locked($c,$state,$type,$signalId,$data);$state['updated_at']=tv_now();if(!$ok||!tv_save($p['state'],$state))return false;return tv_write_commit_manifest($c,$p,'APPEND_EVENT');}catch(Throwable $e){tv_error($c,'APPEND_EVENT_INTEGRITY_ERROR '.$e->getMessage());return false;}finally{tv_unlock($fp);}
}

function tv_summary_strategy_default(array $sample): array
{
    return [
        'strategy'=>(string)$sample['strategy'],'strategy_status'=>(string)$sample['strategy_status'],'alpha_type'=>(string)$sample['alpha_type'],'strategy_hash'=>(string)$sample['strategy_hash'],
        'strategy_version'=>(string)($sample['strategy_version']??''),'strategy_rev'=>(string)($sample['strategy_rev']??''),'first_registered_at'=>(string)($sample['registered_at']??tv_now()),'last_registered_at'=>(string)($sample['registered_at']??tv_now()),
        'signals'=>0,'buy_signals'=>0,'sell_signals'=>0,'pipeline'=>['selected'=>0,'rejected'=>0,'intents'=>0,'orders'=>0,'filled'=>0,'closed'=>0,'selection_rate_pct'=>null,'fill_rate_pct'=>null,'actual_return_sum'=>0.0,'actual_closed_count'=>0,'actual_avg_return_pct'=>null],
        'horizons'=>[],'primary_horizon'=>(string)$sample['primary_horizon'],'resolved_primary'=>0,'minimum_usable_samples'=>TV_MIN_USABLE_SAMPLES,'remaining_samples_to_usable'=>TV_MIN_USABLE_SAMPLES,'metrics_sample_status'=>'EMPTY','metrics_usable'=>false,'display_expectancy_pct'=>null,'display_net_expectancy_pct'=>null,'display_profit_factor'=>null,'primary_returns'=>[],'primary_return_sum'=>0.0,'primary_net_return_sum'=>0.0,'primary_cost15_return_sum'=>0.0,'primary_cost20_return_sum'=>0.0,'primary_positive_sum'=>0.0,'primary_negative_abs_sum'=>0.0,'primary_wins'=>0,'primary_losses'=>0,'expectancy_pct'=>null,'net_expectancy_pct'=>null,'cost_1_5x_expectancy_pct'=>null,'cost_2x_expectancy_pct'=>null,'median_return_pct'=>null,'profit_factor'=>null,'mdd_pct'=>0.0,'equity_index'=>1.0,'peak_equity_index'=>1.0,'current_loss_streak'=>0,'max_consecutive_losses'=>0,'solo_count'=>0,'solo_return_sum'=>0.0,'overlap_count'=>0,'overlap_return_sum'=>0.0,'solo_expectancy_pct'=>null,'overlap_expectancy_pct'=>null,'incremental_expectancy'=>null,'symbol_counts'=>[],'market_counts'=>[],'sector_counts'=>[],'regime_stats'=>[],'symbol_concentration_pct'=>null,'market_concentration_pct'=>null,'sector_concentration_pct'=>null,
    ];
}
function tv_summary_ensure(array &$summary,array $sample): array
{
    $k=(string)$sample['strategy_hash'];if(!isset($summary['strategies'][$k])||!is_array($summary['strategies'][$k]))$summary['strategies'][$k]=tv_summary_strategy_default($sample);
    $s=&$summary['strategies'][$k];$s['strategy_version']=(string)($sample['strategy_version']??($s['strategy_version']??''));$s['strategy_rev']=(string)($sample['strategy_rev']??($s['strategy_rev']??''));if(empty($s['first_registered_at']))$s['first_registered_at']=(string)($sample['registered_at']??tv_now());$s['last_registered_at']=(string)($sample['registered_at']??tv_now());unset($s);return$summary['strategies'][$k];
}
function tv_summary_finalize_strategy(array &$s): void
{
    $signals=max(1,(int)$s['signals']);$s['pipeline']['selection_rate_pct']=round(((int)$s['pipeline']['selected'])/$signals*100.0,2);$s['pipeline']['fill_rate_pct']=round(((int)$s['pipeline']['filled'])/$signals*100.0,2);
    $n=(int)$s['resolved_primary'];if($n>0){
        $s['expectancy_pct']=round((float)$s['primary_return_sum']/$n,6);$s['net_expectancy_pct']=round((float)$s['primary_net_return_sum']/$n,6);$s['cost_1_5x_expectancy_pct']=round((float)$s['primary_cost15_return_sum']/$n,6);$s['cost_2x_expectancy_pct']=round((float)$s['primary_cost20_return_sum']/$n,6);
        $vals=is_array($s['primary_returns']??null)?$s['primary_returns']:[];sort($vals,SORT_NUMERIC);$m=count($vals);if($m)$s['median_return_pct']=$m%2?$vals[intdiv($m,2)]:round(($vals[$m/2-1]+$vals[$m/2])/2,6);$s['profit_factor']=(float)$s['primary_negative_abs_sum']>0?round((float)$s['primary_positive_sum']/(float)$s['primary_negative_abs_sum'],6):((float)$s['primary_positive_sum']>0?999.0:null);
        $s['solo_expectancy_pct']=(int)$s['solo_count']>0?round((float)$s['solo_return_sum']/(int)$s['solo_count'],6):null;$s['overlap_expectancy_pct']=(int)$s['overlap_count']>0?round((float)$s['overlap_return_sum']/(int)$s['overlap_count'],6):null;$s['incremental_expectancy']=$s['solo_expectancy_pct']!==null&&$s['overlap_expectancy_pct']!==null?round((float)$s['solo_expectancy_pct']-(float)$s['overlap_expectancy_pct'],6):$s['solo_expectancy_pct'];
        foreach(['symbol_counts'=>'symbol_concentration_pct','market_counts'=>'market_concentration_pct','sector_counts'=>'sector_concentration_pct'] as$src=>$dst){$counts=is_array($s[$src]??null)?$s[$src]:[];$s[$dst]=$counts?round(max($counts)/max(1,array_sum($counts))*100.0,2):null;}
    }
    $s['minimum_usable_samples']=TV_MIN_USABLE_SAMPLES;
    $s['remaining_samples_to_usable']=max(0,TV_MIN_USABLE_SAMPLES-$n);
    $s['metrics_usable']=$n>=TV_MIN_USABLE_SAMPLES;
    $s['metrics_sample_status']=$n>=TV_MIN_USABLE_SAMPLES?'USABLE':($n>0?'INSUFFICIENT_SAMPLE':'EMPTY');
    // Preserve raw numeric fields for backward compatibility, but expose display-safe fields.
    $s['display_expectancy_pct']=$s['metrics_usable']?($s['expectancy_pct']??null):null;
    $s['display_net_expectancy_pct']=$s['metrics_usable']?($s['net_expectancy_pct']??null):null;
    $s['display_profit_factor']=$s['metrics_usable']?($s['profit_factor']??null):null;
    if((int)$s['pipeline']['actual_closed_count']>0)$s['pipeline']['actual_avg_return_pct']=round((float)$s['pipeline']['actual_return_sum']/(int)$s['pipeline']['actual_closed_count'],6);
}

function tv_pair_key(string $a,string $b): string { $x=[$a,$b];sort($x,SORT_STRING);return implode('|',$x); }
function tv_pair_default(string $a,string $b): array { return ['a'=>$a,'b'=>$b,'same_symbol_same_day'=>0,'same_symbol'=>0,'same_day'=>0,'lead_lag_pairs'=>0,'lead_lag_sum_days'=>0.0,'return_pairs'=>0,'sum_x'=>0.0,'sum_y'=>0.0,'sum_x2'=>0.0,'sum_y2'=>0.0,'sum_xy'=>0.0,'correlation'=>null,'overlap_rate_pct'=>null,'same_symbol_rate_pct'=>null,'same_day_rate_pct'=>null,'lead_lag_days'=>null,'incremental_expectancy'=>null,'overlap_population'=>'RESOLVED_PRIMARY','overlap_numerator'=>0,'overlap_denominator'=>0,'minimum_usable_samples'=>TV_MIN_USABLE_SAMPLES,'remaining_samples_to_usable'=>TV_MIN_USABLE_SAMPLES,'sample_status'=>'INSUFFICIENT','counter_anomaly'=>false,'paired_sample_count'=>0,'metrics_sample_n'=>0]; }
function tv_rate_pct($num,$den): ?float
{
    $den=(int)$den;if($den<=0)return null;$v=(float)$num/$den*100.0;return round(max(0.0,min(100.0,$v)),2);
}
function tv_pair_finalize(array &$pair,array $summary): void
{
    $aResolved=0;$bResolved=0;$aSolo=[];$bSolo=[];
    foreach((array)($summary['strategies']??[])as$s){
        if(($s['strategy']??'')===$pair['a']){$aResolved+=(int)($s['resolved_primary']??0);if((int)($s['solo_count']??0)>0)$aSolo[]=(float)$s['solo_return_sum']/(int)$s['solo_count'];}
        if(($s['strategy']??'')===$pair['b']){$bResolved+=(int)($s['resolved_primary']??0);if((int)($s['solo_count']??0)>0)$bSolo[]=(float)$s['solo_return_sum']/(int)$s['solo_count'];}
    }
    // ERRC v2: overlap rate denominator and statistical paired-sample N are different concepts.
    $den=min($aResolved,$bResolved);$rawPairs=max(0,(int)($pair['return_pairs']??0));$num=$den>0?min($rawPairs,$den):0;
    $pair['overlap_population']='RESOLVED_PRIMARY';$pair['overlap_numerator']=$num;$pair['overlap_denominator']=$den;$pair['counter_anomaly']=$rawPairs>$den&&$den>=0;
    $pair['overlap_rate_pct']=tv_rate_pct($num,$den);$pair['paired_sample_count']=$rawPairs;$pair['metrics_sample_n']=$rawPairs;
    $pair['same_symbol_rate_pct']=null;$pair['same_day_rate_pct']=null;
    $pair['lead_lag_days']=(int)($pair['lead_lag_pairs']??0)>0?round((float)$pair['lead_lag_sum_days']/(int)$pair['lead_lag_pairs'],3):null;
    $n=$rawPairs;$pair['correlation']=null;if($n>=2){$sx=(float)$pair['sum_x'];$sy=(float)$pair['sum_y'];$numCorr=$n*(float)$pair['sum_xy']-$sx*$sy;$dx=$n*(float)$pair['sum_x2']-$sx*$sx;$dy=$n*(float)$pair['sum_y2']-$sy*$sy;$pair['correlation']=($dx>0&&$dy>0)?max(-1.0,min(1.0,round($numCorr/sqrt($dx*$dy),6))):null;}
    // Pair-specific overlap expectancy: use returns observed on this exact pair, not overlap aggregates from unrelated strategies.
    $solo=array_merge($aSolo,$bSolo);$soloAvg=$solo?array_sum($solo)/count($solo):null;$pairOverlapAvg=$n>0?(((float)$pair['sum_x']+(float)$pair['sum_y'])/(2.0*$n)):null;
    $pair['incremental_expectancy']=$soloAvg!==null&&$pairOverlapAvg!==null?round($soloAvg-$pairOverlapAvg,6):null;
    $pair['minimum_usable_samples']=TV_MIN_USABLE_SAMPLES;$pair['remaining_samples_to_usable']=max(0,TV_MIN_USABLE_SAMPLES-$n);$pair['sample_status']=$n>=TV_MIN_USABLE_SAMPLES?'USABLE':($n>0?'INSUFFICIENT':'EMPTY');
}
function tv_rebuild_resolved_pairs(array &$ind,array &$state,array $summary): void
{
    $old=is_array($ind['pairs']??null)?$ind['pairs']:[];$rebuilt=[];$seen=[];
    foreach((array)($state['resolved_index']??[])as$idx=>$resolved){if(!is_array($resolved))continue;$vals=[];foreach($resolved as$strategy=>$ret){$strategy=strtoupper((string)$strategy);if($strategy!==''&&is_numeric($ret))$vals[$strategy]=(float)$ret;} $keys=array_keys($vals);sort($keys,SORT_STRING);for($i=0,$n=count($keys);$i<$n;$i++)for($j=$i+1;$j<$n;$j++){$a=$keys[$i];$b=$keys[$j];$pk=tv_pair_key($a,$b);if(isset($seen[$pk.'|'.$idx]))continue;$seen[$pk.'|'.$idx]=1;if(!isset($rebuilt[$pk])){$rebuilt[$pk]=tv_pair_default($a,$b);if(isset($old[$pk])&&is_array($old[$pk]))foreach(['same_symbol_same_day','same_symbol','same_day','lead_lag_pairs','lead_lag_sum_days']as$f)$rebuilt[$pk][$f]=$old[$pk][$f]??$rebuilt[$pk][$f];}$x=$vals[$a];$y=$vals[$b];$p=&$rebuilt[$pk];$p['return_pairs']++;$p['sum_x']+=$x;$p['sum_y']+=$y;$p['sum_x2']+=$x*$x;$p['sum_y2']+=$y*$y;$p['sum_xy']+=$x*$y;unset($p);}}
    foreach($old as$pk=>$p)if(!isset($rebuilt[$pk])&&is_array($p)){$parts=explode('|',(string)$pk,2);if(count($parts)===2){$rebuilt[$pk]=tv_pair_default($parts[0],$parts[1]);foreach(['same_symbol_same_day','same_symbol','same_day','lead_lag_pairs','lead_lag_sum_days']as$f)$rebuilt[$pk][$f]=$p[$f]??$rebuilt[$pk][$f];}}
    foreach($rebuilt as&$pair)tv_pair_finalize($pair,$summary);unset($pair);$ind['pairs']=$rebuilt;$ind['population']='RESOLVED_PRIMARY';$ind['errc_marker']=TV_ERRC_MARKER;$state['resolved_pair_index']=$seen;
}


function tv_current_strategy_summary(array $summary,string $strategy): ?array
{
    $strategy=strtoupper($strategy);$best=null;$bestScore=null;
    foreach((array)($summary['strategies']??[])as$s){if(!is_array($s)||strtoupper((string)($s['strategy']??''))!==$strategy)continue;$ts=strtotime((string)($s['last_registered_at']??''));if($ts===false)$ts=0;$score=[$ts,(int)($s['signals']??0),(int)($s['resolved_primary']??0)];if($best===null||$score>$bestScore){$best=$s;$bestScore=$score;}}
    return$best;
}
function tv_update_challenger(array $summary): array
{
    $out=['schema'=>'trade_challenger_v1','updated_at'=>tv_now(),'strategy'=>'STC26','shadow_mode'=>'SIGNAL_PAPER','stage'=>'OBSERVATION','resolved_primary'=>0,'core_review_eligible'=>false,'auto_promotion'=>false,'reason'=>''];
    $best=tv_current_strategy_summary($summary,'STC26');if(!$best)return$out;$n=(int)($best['resolved_primary']??0);$out['resolved_primary']=$n;$out['expectancy_pct']=$best['expectancy_pct']??null;$out['net_expectancy_pct']=$best['net_expectancy_pct']??null;$out['cost_1_5x_expectancy_pct']=$best['cost_1_5x_expectancy_pct']??null;$out['cost_2x_expectancy_pct']=$best['cost_2x_expectancy_pct']??null;$out['profit_factor']=$best['profit_factor']??null;$out['mdd_pct']=$best['mdd_pct']??null;$out['max_consecutive_losses']=$best['max_consecutive_losses']??0;$out['symbol_concentration_pct']=$best['symbol_concentration_pct']??null;$out['market_concentration_pct']=$best['market_concentration_pct']??null;$out['shadow_equity_index']=$best['equity_index']??1.0;$out['stage']=$n>=200?'CORE_REVIEW_ELIGIBLE':($n>=100?'VALIDATION':($n>=30?'PRELIMINARY':'OBSERVATION'));$out['core_review_eligible']=$n>=200;$out['reason']=$n>=200?'표본수는 검토 자격만 충족. 비용·MDD·집중도·독립성·표본외 검증 후 수동 심사.':'표본 축적 중';return$out;
}

function tv_register_signal(array $c,array $ctx,array $signal,string $side='BUY',string $origin='MODEL_SIGNAL'): array
{
    $v=is_array($c['validation']??null)?$c['validation']:[];$side=strtoupper($side);if(empty($v['enabled'])||!in_array($side,(array)($v['signals']??[]),true))return['ok'=>true,'registered'=>false,'id'=>''];
    $market=strtoupper((string)($ctx['market']??''));$symbol=strtoupper((string)($ctx['symbol']??''));if(!in_array($market,['KR','US','JP'],true)||$symbol==='')return['ok'=>false,'registered'=>false,'id'=>'','reason'=>'IDENTITY_INVALID'];
    $metrics=is_array($signal['metrics']??null)?$signal['metrics']:[];$price=function_exists('te_model_num')?te_model_num($ctx['quote']['price']??$signal['entry_price']??$metrics['decision_price']??0):(float)($ctx['quote']['price']??$signal['entry_price']??0);if($price<=0)return['ok'=>false,'registered'=>false,'id'=>'','reason'=>'PRICE_INVALID'];
    $dataTimestamp=(string)($ctx['data_timestamp']??$ctx['meta']['data_timestamp']??$ctx['meta']['quote_time']??$ctx['meta']['quote_timestamp']??'');$signalTs=(int)($ctx['meta']['quote_timestamp']??0);if($signalTs<=0&&$dataTimestamp!==''){$parsed=strtotime($dataTimestamp);if($parsed!==false)$signalTs=$parsed;}if($signalTs<=0||$signalTs>time()+60)return['ok'=>false,'registered'=>false,'id'=>'','reason'=>'TIMESTAMP_INVALID'];$id=tv_signal_id($c,$ctx,$signal,$side);$hash=tv_strategy_hash($c);$systemHash=tv_system_hash($c);
    $eligible=strtoupper($origin)!=='RISK_EXIT';if($dataTimestamp==='')$dataTimestamp=tv_iso($signalTs);$dataQuality=strtoupper((string)($ctx['data_quality']??$ctx['meta']['data_quality']??$ctx['meta']['quote_freshness']??'UNKNOWN'));$provider=(string)($ctx['provider']??$ctx['meta']['provider']??$ctx['meta']['quote_source']??$c['data_provider_revision']??'engine-default');
    $sample=['id'=>$id,'schema'=>TV_PENDING_SCHEMA,'strategy'=>strtoupper((string)$c['strategy_key']),'strategy_status'=>tv_strategy_status($c),'strategy_version'=>(string)($c['strategy_version']??''),'strategy_rev'=>(string)($c['strategy_rev']??''),'strategy_hash'=>$hash,'system_hash'=>$systemHash,'alpha_type'=>tv_alpha_type($c),'market'=>$market,'symbol'=>$symbol,'name'=>(string)($ctx['name']??$symbol),'exchange'=>(string)($ctx['exchange']??''),'sector'=>(string)($ctx['symbol_data']['sector']??$ctx['sector']??'UNKNOWN'),'market_regime'=>(string)($ctx['market_regime']??$ctx['regime']??'UNKNOWN'),'side'=>$side,'signal_type'=>strtoupper((string)($signal['type']??'SIGNAL')),'signal_origin'=>$origin,'eligible_for_strategy_stats'=>$eligible,'validation_status'=>$eligible?'ELIGIBLE':'EXCLUDED_RISK_EXIT','signal_score'=>(float)($signal['score']??0),'signal_price'=>round($price,6),'signal_time'=>tv_iso($signalTs),'signal_ts'=>$signalTs,'signal_market_date'=>(string)($ctx['market_date']??$ctx['session_date']??date('Y-m-d')),'signal_session_date'=>(string)($ctx['session_date']??date('Y-m-d')),'data_timestamp'=>$dataTimestamp,'provider'=>$provider,'data_quality'=>$dataQuality,'anchor'=>tv_signal_anchor($c,$ctx),'primary_horizon'=>(string)($v['primary_horizon']??''),'horizons'=>tv_horizons($c,$signalTs,$market),'pipeline'=>['selected'=>false,'rejected'=>false,'block_reason'=>'','intent_id'=>'','order_status'=>'','filled'=>false,'closed'=>false,'actual_return_pct'=>null],'status'=>'PENDING','registered_at'=>tv_now(),'updated_at'=>tv_now()];tv_repair_sample_due_ts($sample);
    $fp=tv_lock($c);if(!$fp)return['ok'=>false,'registered'=>false,'id'=>'','reason'=>'AUDIT_WRITE_FAILED'];
    try{$p=tv_paths($c);$commitCheck=tv_commit_guard_locked($c,$p,true);if(!$commitCheck['ok'])return['ok'=>false,'registered'=>false,'id'=>'','reason'=>'VALIDATION_PARTIAL_COMMIT'];$state=tv_guarded_load($c,$p['state'],tv_state_default(),TV_STATE_SCHEMA,'STATE',false);$pending=tv_guarded_load($c,$p['pending'],tv_pending_default(),TV_PENDING_SCHEMA,'PENDING',false);$summary=tv_guarded_load($c,$p['strategy_summary'],tv_summary_default(),TV_SUMMARY_SCHEMA,'SUMMARY',true);$ind=tv_guarded_load($c,$p['independence_summary'],tv_independence_default(),'trade_independence_v1','INDEPENDENCE',true);if(isset($state['dedupe'][$id]))return['ok'=>true,'registered'=>false,'id'=>$id,'reason'=>'DUPLICATE'];
        // Audit event is the first durable write. If this fails, no new intent may be created.
        $eventData=['strategy'=>$sample['strategy'],'strategy_status'=>$sample['strategy_status'],'alpha_type'=>$sample['alpha_type'],'strategy_hash'=>$hash,'system_hash'=>$systemHash,'market'=>$market,'symbol'=>$symbol,'side'=>$side,'signal_type'=>$sample['signal_type'],'signal_origin'=>$origin,'eligible_for_strategy_stats'=>$eligible,'signal_price'=>$sample['signal_price'],'signal_time'=>$sample['signal_time'],'signal_market_date'=>$sample['signal_market_date'],'data_timestamp'=>$dataTimestamp,'provider'=>$provider,'data_quality'=>$dataQuality,'primary_horizon'=>$sample['primary_horizon']];
        if(!tv_append_event_locked($c,$state,'SIGNAL',$id,$eventData)){tv_error($c,'AUDIT_WRITE_FAILED '.$id);return['ok'=>false,'registered'=>false,'id'=>'','reason'=>'AUDIT_WRITE_FAILED'];}
        $pending['samples'][$id]=$sample;$state['dedupe'][$id]=1;$state['updated_at']=tv_now();$pending['updated_at']=tv_now();
        if($eligible){$s=&$summary['strategies'][$hash];if(!is_array($s??null))$s=tv_summary_strategy_default($sample);$s['strategy_version']=(string)$sample['strategy_version'];$s['strategy_rev']=(string)$sample['strategy_rev'];if(empty($s['first_registered_at']))$s['first_registered_at']=(string)$sample['registered_at'];$s['last_registered_at']=(string)$sample['registered_at'];$s['signals']++;if($side==='BUY')$s['buy_signals']++;else$s['sell_signals']++;tv_summary_finalize_strategy($s);unset($s);$summary['totals']['signals']=(int)($summary['totals']['signals']??0)+1;
        $idx=$market.'|'.$symbol.'|'.$sample['signal_market_date'];$existing=is_array($state['signal_index'][$idx]??null)?$state['signal_index'][$idx]:[];foreach($existing as$other){$other=(string)$other;if($other===''||$other===$sample['strategy'])continue;$pk=tv_pair_key($sample['strategy'],$other);if(!isset($ind['pairs'][$pk])){$parts=explode('|',$pk,2);$ind['pairs'][$pk]=tv_pair_default($parts[0],$parts[1]);}$ind['pairs'][$pk]['same_symbol_same_day']++;}
        $dayKey=$market.'|'.$sample['signal_market_date'];$dayStrategies=is_array($state['day_index'][$dayKey]??null)?$state['day_index'][$dayKey]:[];foreach($dayStrategies as$other){$other=(string)$other;if($other===''||$other===$sample['strategy'])continue;$pk=tv_pair_key($sample['strategy'],$other);if(!isset($ind['pairs'][$pk])){$parts=explode('|',$pk,2);$ind['pairs'][$pk]=tv_pair_default($parts[0],$parts[1]);}$ind['pairs'][$pk]['same_day']++;}$dayStrategies[]=$sample['strategy'];$state['day_index'][$dayKey]=array_values(array_unique($dayStrategies));
        $symKey=$market.'|'.$symbol;$lasts=is_array($state['symbol_last'][$symKey]??null)?$state['symbol_last'][$symKey]:[];foreach($lasts as$other=>$otherDate){if($other===$sample['strategy']||$otherDate==='')continue;$pk=tv_pair_key($sample['strategy'],(string)$other);if(!isset($ind['pairs'][$pk])){$parts=explode('|',$pk,2);$ind['pairs'][$pk]=tv_pair_default($parts[0],$parts[1]);}$ind['pairs'][$pk]['same_symbol']++;$a=strtotime((string)$otherDate);$b=strtotime((string)$sample['signal_market_date']);if($a!==false&&$b!==false){$ind['pairs'][$pk]['lead_lag_pairs']++;$ind['pairs'][$pk]['lead_lag_sum_days']+=abs($b-$a)/86400.0;}}$lasts[$sample['strategy']]=$sample['signal_market_date'];$state['symbol_last'][$symKey]=$lasts;
        $existing[]=$sample['strategy'];$state['signal_index'][$idx]=array_values(array_unique($existing));tv_rebuild_resolved_pairs($ind,$state,$summary);$ind['updated_at']=tv_now();}$summary['updated_at']=tv_now();
        $ok=tv_save_bundle($c,$p,[$p['pending']=>$pending,$p['strategy_summary']=>$summary,$p['independence_summary']=>$ind,$p['challenger_summary']=>tv_update_challenger($summary),$p['state']=>$state],'REGISTER_SIGNAL');if(!$ok){tv_error($c,'DERIVED_STATE_WRITE_FAILED '.$id);return['ok'=>false,'registered'=>true,'id'=>$id,'reason'=>'DERIVED_STATE_WRITE_FAILED'];}return['ok'=>true,'registered'=>true,'id'=>$id];
    }catch(Throwable $e){tv_error($c,'REGISTER_SIGNAL_INTEGRITY_ERROR '.$e->getMessage());return['ok'=>false,'registered'=>false,'id'=>$id,'reason'=>'VALIDATION_INTEGRITY_ERROR'];}finally{tv_unlock($fp);}
}

function tv_register_ranked(array $c,string $market,array $rows,array $batch): array
{
    $v=(array)($c['validation']??[]);if(empty($v['enabled'])||empty($v['register_ranked_candidates']))return[];$max=max(1,(int)($v['ranked_max_per_market']??10));$out=[];$n=0;
    foreach($rows as$row){if($n>=$max)break;if(!is_array($row)||empty($row['structural_eligible'])||empty($row['score_pass']))continue;$symbol=(string)($row['symbol']??'');if($symbol==='')continue;
        $dataTs=(string)($row['data_timestamp']??$row['quote_timestamp']??$batch['data_timestamp']??'');$quoteTs=(int)($row['quote_timestamp']??0);if($quoteTs<=0&&$dataTs!==''){$parsed=strtotime($dataTs);if($parsed!==false)$quoteTs=$parsed;}if($quoteTs<=0)continue;
        $provider=trim((string)($row['provider']??$batch['provider']??''));$quality=strtoupper(trim((string)($row['data_quality']??$batch['data_quality']??'UNKNOWN')));if($provider===''||$quality===''||$quality==='UNKNOWN')continue;
        $ctx=['market'=>$market,'symbol'=>$symbol,'name'=>(string)($row['name']??$symbol),'exchange'=>(string)($row['exchange']??''),'quote'=>['price'=>(float)($row['current_quote_price']??$row['entry_price']??0)],'market_date'=>(string)($batch['data_date']??$row['market_date']??date('Y-m-d')),'session_date'=>(string)($batch['session_date']??date('Y-m-d')),'data_timestamp'=>$dataTs!==''?$dataTs:tv_iso($quoteTs),'provider'=>$provider,'data_quality'=>$quality,'meta'=>['quote_timestamp'=>$quoteTs,'data_timestamp'=>$dataTs!==''?$dataTs:tv_iso($quoteTs),'provider'=>$provider,'data_quality'=>$quality],'bars'=>[]];
        $signal=['type'=>(string)($row['entry_type']??'DAS_RANK_SIGNAL'),'score'=>(float)($row['final_score']??$row['rule_score']??0),'entry_price'=>(float)($row['current_quote_price']??$row['entry_price']??0),'metrics'=>$row];$r=tv_register_signal($c,$ctx,$signal,'BUY','RANKED_CANDIDATE');if(!empty($r['id'])){$out[$symbol]=$r['id'];$n++;}}
    return$out;
}

function tv_patch_pipeline(array $c,string $signalId,string $eventType,array $patch,array $eventData=[]): bool
{
    if($signalId==='')return false;$fp=tv_lock($c);if(!$fp)return false;try{$p=tv_paths($c);$commitCheck=tv_commit_guard_locked($c,$p,true);if(!$commitCheck['ok'])return false;$state=tv_guarded_load($c,$p['state'],tv_state_default(),TV_STATE_SCHEMA,'STATE',false);$pending=tv_guarded_load($c,$p['pending'],tv_pending_default(),TV_PENDING_SCHEMA,'PENDING',false);$summary=tv_guarded_load($c,$p['strategy_summary'],tv_summary_default(),TV_SUMMARY_SCHEMA,'SUMMARY',true);if(!isset($pending['samples'][$signalId])||!is_array($pending['samples'][$signalId])){$ev=tv_append_event_locked($c,$state,$eventType,$signalId,$eventData);if(!$ev)return false;return tv_save_bundle($c,$p,[$p['state']=>$state],'PATCH_PIPELINE_EVENT_ONLY');} $sample=&$pending['samples'][$signalId];$before=$sample['pipeline'];$sample['pipeline']=array_replace($sample['pipeline'],$patch);$sample['updated_at']=tv_now();$changed=$before!==$sample['pipeline'];if($changed&&!tv_append_event_locked($c,$state,$eventType,$signalId,$eventData))return false;
        $hash=(string)$sample['strategy_hash'];if(!empty($sample['eligible_for_strategy_stats'])){$s=&$summary['strategies'][$hash];if(!is_array($s??null))$s=tv_summary_strategy_default($sample);if($changed){if($eventType==='ENGINE_SELECTED')$s['pipeline']['selected']++;elseif($eventType==='ENGINE_REJECTED')$s['pipeline']['rejected']++;elseif($eventType==='INTENT_CREATED')$s['pipeline']['intents']++;elseif($eventType==='ORDER_CREATED')$s['pipeline']['orders']++;elseif($eventType==='FILLED')$s['pipeline']['filled']++;elseif($eventType==='POSITION_CLOSED'){$s['pipeline']['closed']++;if(is_numeric($patch['actual_return_pct']??null)){$s['pipeline']['actual_return_sum']+=(float)$patch['actual_return_pct'];$s['pipeline']['actual_closed_count']++;}}tv_summary_finalize_strategy($s);}unset($s);}$pending['updated_at']=tv_now();$summary['updated_at']=tv_now();$state['updated_at']=tv_now();return tv_save_bundle($c,$p,[$p['pending']=>$pending,$p['strategy_summary']=>$summary,$p['challenger_summary']=>tv_update_challenger($summary),$p['state']=>$state],'PATCH_PIPELINE');
    }catch(Throwable $e){tv_error($c,'PATCH_PIPELINE_INTEGRITY_ERROR '.$e->getMessage());return false;}finally{tv_unlock($fp);}
}
function tv_sync_runtime(array $c,array $candidateBook,array $orders,array $trades): array
{
    $updates=0;foreach((array)($candidateBook['markets']??[])as$market=>$m){foreach((array)($m['rows']??[])as$row){if(!is_array($row))continue;$id=(string)($row['signal_id']??$row['metrics']['signal_id']??'');if($id==='')continue;$selected=!empty($row['rank_selected'])||!empty($row['order_eligible'])||!empty($row['order_id'])||strtoupper((string)($row['status']??''))==='BUY_PENDING';$block=(string)($row['block_reason']??'');if($selected){if(tv_patch_pipeline($c,$id,'ENGINE_SELECTED',['selected'=>true,'block_reason'=>''],['market'=>$market,'symbol'=>$row['symbol']??'']))$updates++;}elseif($block!==''){if(tv_patch_pipeline($c,$id,'ENGINE_REJECTED',['rejected'=>true,'block_reason'=>$block],['block_reason'=>$block,'market'=>$market,'symbol'=>$row['symbol']??'']))$updates++;}if(!empty($row['order_id'])){if(tv_patch_pipeline($c,$id,'INTENT_CREATED',['intent_id'=>(string)$row['order_id']],['order_id'=>(string)$row['order_id']]))$updates++;}}
    }
    foreach($orders as$o){if(!is_array($o))continue;$id=(string)($o['signal_id']??$o['decision']['signal_id']??'');if($id==='')continue;$status=strtoupper((string)($o['status']??''));$oid=(string)($o['order_id']??'');if($oid!=='')tv_patch_pipeline($c,$id,'ORDER_CREATED',['intent_id'=>$oid,'order_status'=>$status],['order_id'=>$oid,'status'=>$status]);$filled=(int)($o['filled_qty']??0)>0||in_array($status,['FILLED','PAPER_FILLED'],true);if($filled)tv_patch_pipeline($c,$id,'FILLED',['filled'=>true,'order_status'=>$status],['order_id'=>$oid,'status'=>$status,'fill_price'=>(float)($o['avg_fill_price']??0),'filled_qty'=>(int)($o['filled_qty']??0)]);
    }
    foreach($trades as$t){if(!is_array($t))continue;$id=(string)($t['signal_id']??$t['entry_signal_id']??$t['entry_decision']['signal_id']??'');if($id==='')continue;tv_patch_pipeline($c,$id,'POSITION_CLOSED',['closed'=>true,'actual_return_pct'=>is_numeric($t['return_pct']??null)?(float)$t['return_pct']:null],['trade_id'=>(string)($t['trade_id']??$t['id']??''),'actual_return_pct'=>$t['return_pct']??null]);}
    return['ok'=>true,'updates'=>$updates];
}

function tv_update_summary_for_resolution(array &$summary,array &$ind,array &$state,array $sample,string $label,array $result): void
{
    if(empty($sample['eligible_for_strategy_stats']))return;
    $hash=(string)$sample['strategy_hash'];$s=&$summary['strategies'][$hash];if(!is_array($s??null))$s=tv_summary_strategy_default($sample);if(!isset($s['horizons'][$label]))$s['horizons'][$label]=['resolved'=>0,'wins'=>0,'return_sum'=>0.0,'net_return_sum'=>0.0,'mfe_sum'=>0.0,'mae_sum'=>0.0,'avg_return_pct'=>null,'avg_net_return_pct'=>null,'hit_rate_pct'=>null];$h=&$s['horizons'][$label];$h['resolved']++;$ret=(float)($result['gross_return_pct']??0);$h['return_sum']+=$ret;$h['net_return_sum']+=(float)($result['net_return_pct']??0);$h['mfe_sum']+=(float)($result['mfe_pct']??0);$h['mae_sum']+=(float)($result['mae_pct']??0);if(!empty($result['direction_hit']))$h['wins']++;$n=max(1,(int)$h['resolved']);$h['avg_return_pct']=round((float)$h['return_sum']/$n,6);$h['avg_net_return_pct']=round((float)$h['net_return_sum']/$n,6);$h['hit_rate_pct']=round((int)$h['wins']/$n*100.0,2);unset($h);
    if($label===(string)$sample['primary_horizon']){$s['resolved_primary']++;$s['primary_return_sum']+=$ret;$net=(float)($result['net_return_pct']??$ret);$cost=(float)($result['roundtrip_cost_pct']??0);$s['primary_net_return_sum']+=$net;$s['primary_cost15_return_sum']+=($ret-$cost*1.5);$s['primary_cost20_return_sum']+=($ret-$cost*2.0);if($ret>0){$s['primary_wins']++;$s['primary_positive_sum']+=$ret;$s['current_loss_streak']=0;}elseif($ret<0){$s['primary_losses']++;$s['primary_negative_abs_sum']+=abs($ret);$s['current_loss_streak']++;$s['max_consecutive_losses']=max((int)$s['max_consecutive_losses'],(int)$s['current_loss_streak']);} $s['primary_returns'][]=$ret;$sym=(string)($sample['symbol']??'UNKNOWN');$mkt=(string)($sample['market']??'UNKNOWN');$sec=(string)($sample['sector']??'UNKNOWN');$reg=(string)($sample['market_regime']??'UNKNOWN');$s['symbol_counts'][$sym]=(int)($s['symbol_counts'][$sym]??0)+1;$s['market_counts'][$mkt]=(int)($s['market_counts'][$mkt]??0)+1;$s['sector_counts'][$sec]=(int)($s['sector_counts'][$sec]??0)+1;if(!isset($s['regime_stats'][$reg]))$s['regime_stats'][$reg]=['count'=>0,'return_sum'=>0.0,'expectancy_pct'=>null];$s['regime_stats'][$reg]['count']++;$s['regime_stats'][$reg]['return_sum']+=$ret;$s['regime_stats'][$reg]['expectancy_pct']=round($s['regime_stats'][$reg]['return_sum']/max(1,$s['regime_stats'][$reg]['count']),6);if(count($s['primary_returns'])>TV_MAX_RETURN_SAMPLES)$s['primary_returns']=array_slice($s['primary_returns'],-TV_MAX_RETURN_SAMPLES);$s['equity_index']*=max(0.000001,1.0+$ret/100.0);$s['peak_equity_index']=max((float)$s['peak_equity_index'],(float)$s['equity_index']);$dd=$s['peak_equity_index']>0?(($s['equity_index']/$s['peak_equity_index'])-1.0)*100.0:0.0;$s['mdd_pct']=min((float)$s['mdd_pct'],$dd);
        $idx=$sample['market'].'|'.$sample['symbol'].'|'.$sample['signal_market_date'];$strategies=is_array($state['signal_index'][$idx]??null)?$state['signal_index'][$idx]:[];$overlap=count(array_unique($strategies))>1;if($overlap){$s['overlap_count']++;$s['overlap_return_sum']+=$ret;}else{$s['solo_count']++;$s['solo_return_sum']+=$ret;}
        $resolved=is_array($state['resolved_index'][$idx]??null)?$state['resolved_index'][$idx]:[];$resolved[(string)$sample['strategy']]=$ret;$state['resolved_index'][$idx]=$resolved;
    }
    tv_summary_finalize_strategy($s);unset($s);tv_rebuild_resolved_pairs($ind,$state,$summary);
}
function tv_resolve_due(array $c,array $status,bool $force=false): array
{
    if(empty($c['validation']['enabled']))return['ok'=>true,'processed'=>0,'resolved_horizons'=>0,'completed_samples'=>0];$fp=tv_lock($c);if(!$fp)return['ok'=>false,'reason'=>'VALIDATION_LOCK_FAILED'];try{$p=tv_paths($c);$commitCheck=tv_commit_guard_locked($c,$p,true);if(!$commitCheck['ok'])return['ok'=>false,'reason'=>'VALIDATION_PARTIAL_COMMIT','commit'=>$commitCheck];$state=tv_guarded_load($c,$p['state'],tv_state_default(),TV_STATE_SCHEMA,'STATE',false);$pending=tv_guarded_load($c,$p['pending'],tv_pending_default(),TV_PENDING_SCHEMA,'PENDING',false);$summary=tv_guarded_load($c,$p['strategy_summary'],tv_summary_default(),TV_SUMMARY_SCHEMA,'SUMMARY',true);$ind=tv_guarded_load($c,$p['independence_summary'],tv_independence_default(),'trade_independence_v1','INDEPENDENCE',true);$summaryRebuilt=false;if(tv_summary_is_corrupt($summary)){ $bak=tv_quarantine_corrupt_file($p['strategy_summary'],'SUMMARY_LOGICAL');tv_error($c,'CORRUPT_DERIVED_SUMMARY backup='.$bak);tv_recovery_log($c,'SUMMARY_REBUILD',['backup'=>$bak]);$summary=tv_rebuild_summary_from_pending($pending);$ind=tv_independence_default();$state['resolved_index']=[];$state['resolved_pair_index']=[];$state['resolution_dedupe']=[];$state['summary_rebuild']=['at'=>tv_now(),'reason'=>'CORRUPT_DERIVED_SUMMARY','basis'=>'PENDING_UNRESOLVED_AFTER_CORRUPTION'];$summaryRebuilt=true;}$processed=0;$resolved=0;$completed=0;$dueRepaired=0;$duplicateSkipped=0;$limit=$force?500:TV_MAX_RESOLVE_PER_TICK;
        foreach($pending['samples']as$id=>&$sample){if($processed>=$limit)break;if(!is_array($sample)||($sample['status']??'PENDING')!=='PENDING')continue;$dueRepaired+=tv_repair_sample_due_ts($sample);$processed++;$all=true;foreach((array)$sample['horizons']as$label=>&$h){if(($h['status']??'')==='RESOLVED')continue;$all=false;if(!function_exists('te_validation_resolve_horizon'))continue;$r=te_validation_resolve_horizon($c,$sample,$h,$status);if($r===null)continue;$h['status']='RESOLVED';$h['result']=$r;$h['resolved_at']=tv_now();$resolved++;$resolutionKey=tv_resolution_key($sample,(string)$label);$already=!empty($state['resolution_dedupe'][$resolutionKey]);tv_append_event_locked($c,$state,'VALIDATION_RESOLVED',(string)$id,['strategy'=>$sample['strategy'],'strategy_hash'=>$sample['strategy_hash'],'market'=>$sample['market'],'symbol'=>$sample['symbol'],'horizon'=>$label,'result'=>$r]);if(!$already){tv_update_summary_for_resolution($summary,$ind,$state,$sample,(string)$label,$r);$state['resolution_dedupe'][$resolutionKey]=['resolved_at'=>tv_now(),'strategy'=>(string)$sample['strategy']];}else{$duplicateSkipped++;}}unset($h);$all=true;foreach((array)$sample['horizons']as$h)if(($h['status']??'')!=='RESOLVED'){$all=false;break;}if($all){$sample['status']='RESOLVED';$completed++;}}
        unset($sample);foreach(array_keys($pending['samples'])as$id)if(($pending['samples'][$id]['status']??'')==='RESOLVED')unset($pending['samples'][$id]);$pending['updated_at']=tv_now();tv_prune_state($state);$state['updated_at']=tv_now();$summary['updated_at']=tv_now();$summary['totals']['resolved_primary']=0;foreach((array)$summary['strategies']as$s)$summary['totals']['resolved_primary']+=(int)($s['resolved_primary']??0);tv_rebuild_resolved_pairs($ind,$state,$summary);$ind['updated_at']=tv_now();$ok=tv_save_bundle($c,$p,[$p['pending']=>$pending,$p['state']=>$state,$p['strategy_summary']=>$summary,$p['independence_summary']=>$ind,$p['challenger_summary']=>tv_update_challenger($summary)],'RESOLVE_DUE');return['ok'=>$ok,'processed'=>$processed,'resolved_horizons'=>$resolved,'completed_samples'=>$completed,'due_ts_repaired'=>$dueRepaired,'duplicate_summary_updates_skipped'=>$duplicateSkipped,'summary_rebuilt'=>$summaryRebuilt];
    }catch(Throwable $e){tv_error($c,'RESOLVE_DUE_INTEGRITY_ERROR '.$e->getMessage());return['ok'=>false,'reason'=>'VALIDATION_INTEGRITY_ERROR','message'=>$e->getMessage(),'processed'=>0,'resolved_horizons'=>0,'completed_samples'=>0];}finally{tv_unlock($fp);}
}

function tv_repair_pending_due_ts_persist(array $c): array
{
    $fp=tv_lock($c);
    if(!$fp)return['ok'=>false,'reason'=>'VALIDATION_LOCK_FAILED','repaired'=>0,'remaining_zero_due_horizons'=>null];
    try{
        $p=tv_paths($c);
        $commitCheck=tv_commit_guard_locked($c,$p,true);if(!$commitCheck['ok'])return['ok'=>false,'reason'=>'VALIDATION_PARTIAL_COMMIT','repaired'=>0,'remaining_zero_due_horizons'=>null,'commit'=>$commitCheck];$pending=tv_guarded_load($c,$p['pending'],tv_pending_default(),TV_PENDING_SCHEMA,'PENDING',false);
        $repaired=0;$samplesChanged=0;
        foreach($pending['samples'] as &$sample){
            if(!is_array($sample)||($sample['status']??'PENDING')!=='PENDING')continue;
            $n=tv_repair_sample_due_ts($sample);
            if($n>0){$repaired+=$n;$samplesChanged++;}
        }
        unset($sample);
        if($samplesChanged>0){
            $pending['updated_at']=tv_now();
            if(!tv_save_bundle($c,$p,[$p['pending']=>$pending],'PENDING_DUE_REPAIR'))return['ok'=>false,'reason'=>'PENDING_SAVE_FAILED','repaired'=>$repaired,'remaining_zero_due_horizons'=>null];
        }
        // Re-read from disk: exported state must match persisted state, not only in-memory repair.
        $verify=array_replace(tv_pending_default(),tv_load($p['pending'],[]));
        $zero=0;
        foreach((array)($verify['samples']??[]) as $sample){
            if(!is_array($sample)||($sample['status']??'PENDING')!=='PENDING')continue;
            foreach((array)($sample['horizons']??[]) as $h){
                if(is_array($h)&&($h['status']??'')!=='RESOLVED'&&(int)($h['due_ts']??0)<=0)$zero++;
            }
        }
        return['ok'=>$zero===0,'reason'=>$zero===0?'OK':'UNREPAIRABLE_ZERO_DUE_TS','repaired'=>$repaired,'samples_changed'=>$samplesChanged,'remaining_zero_due_horizons'=>$zero,'persist_verified'=>true];
    }catch(Throwable $e){tv_error($c,'PENDING_REPAIR_INTEGRITY_ERROR '.$e->getMessage());return['ok'=>false,'reason'=>'VALIDATION_INTEGRITY_ERROR','message'=>$e->getMessage(),'repaired'=>0,'remaining_zero_due_horizons'=>null];}finally{tv_unlock($fp);}
}

function tv_status(array $c): array
{
    $repair=tv_repair_pending_due_ts_persist($c);
    $p=tv_paths($c);$commit=tv_verify_commit_manifest($c,$p,false);$summary=tv_load($p['strategy_summary'],tv_summary_default());$ind=tv_load($p['independence_summary'],tv_independence_default());$challenger=tv_load($p['challenger_summary'],tv_update_challenger($summary));$pending=tv_load($p['pending'],tv_pending_default());$hash=tv_strategy_hash($c);$zeroDue=0;foreach((array)($pending['samples']??[])as$sample)if(is_array($sample))foreach((array)($sample['horizons']??[])as$h)if(is_array($h)&&($h['status']??'')!=='RESOLVED'&&(int)($h['due_ts']??0)<=0)$zeroDue++;return['enabled'=>!empty($c['validation']['enabled']),'schema'=>TV_SCHEMA,'version'=>TV_VERSION,'rev'=>TV_REV,'runtime'=>$p['root'],'events_file'=>$p['events'],'pending_count'=>count((array)($pending['samples']??[])),'pending_zero_due_horizons'=>$zeroDue,'due_ts_repair'=>$repair,'commit_integrity'=>$commit,'summary_corrupt'=>tv_summary_is_corrupt($summary),'strategy_hash'=>$hash,'system_hash'=>tv_system_hash($c),'strategy'=>$summary['strategies'][$hash]??null,'summary'=>$summary,'independence'=>$ind,'challenger'=>$challenger];
}