<?php
/**
 * trade_broker.php
 * Trade Broker v5.9.7 — 3+1 v1.4 · SINGLE_FILE_PAPER runtime authority · auto-approval telemetry · PAPER recovery
 * PHP 7.4 compatible
 *
 * 역할
 * - 엔진의 order_intents.json을 읽어 broker_orders.json으로 수신
 * - 승인, 안전검증, PAPER/KIS 주문 전송, 체결조회, 원시 계좌 스냅샷 저장
 *
 * 하지 않는 일
 * - 전략 판단, 포지션/손익/자본/승률 계산
 * - 엔진 파일 수정
 *
 * 다운로드/보관 파일명: trade_broker_v596.php
 * 운영 파일명: trade_broker.php
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors','0');
error_reporting(E_ALL);
@ini_set('memory_limit','128M');
@set_time_limit(0);

const TB_VERSION='v5.9.7 3PLUS1-v1.4 · RUNTIME-AUTHORITY-UNIFIED · AUTO-APPROVAL-TELEMETRY · STUCK-SELL-RECOVERY';
const TB_REV='trade-broker-v597-single-file-runtime-authority-20260922-r2';
const TB_SCHEMA='te_v25';
const TB_HTTP_CONNECT_TIMEOUT=5;
const TB_HTTP_TIMEOUT=15;
const TB_DEFAULT_SIGNAL_AGE=2400;
const TB_DEFAULT_MANUAL_SIGNAL_AGE=7200;
const TB_DEFAULT_SELL_SIGNAL_AGE=2592000;
const TB_DEFAULT_PRICE_GAP=3.0;
const TB_DEFAULT_FRESH_QUOTE_AGE=300;
const TB_DEFAULT_ANALYSIS_QUOTE_AGE_JP=1200;
const TB_DEFAULT_LIVE_RISK_MAX=10.0;
const TB_DEFAULT_LIVE_RISK_EXPANSION=1.0;
const TB_DEFAULT_EXPIRED_COOLDOWN_SEC=3600;
const TB_DEFAULT_SCENARIO_COOLDOWN_SEC=86400;
const TB_DEFAULT_STOP_REENTRY_COOLDOWN_SEC=86400;
const TB_DEFAULT_MAX_MARKET_INVEST_PCT=0.80;
const TB_DEFAULT_MAX_SYMBOL_INVEST_PCT=0.25;
const TB_DEFAULT_MAX_RISK_GROUP_INVEST_PCT=0.35;
const TB_EXPECTED_CYCLE_SEC=60;
const TB_CAUTION_CYCLE_SEC=120;
const TB_STALE_CYCLE_SEC=180;
const TB_STUCK_EXPIRE_THRESHOLD=3;
const TB_PAPER_STUCK_SELL_RECOVERY_SEC=900;
const TB_LOG_MAX_BYTES=2097152;
const TB_ARCHIVE_MAX_BYTES=8388608;
const TB_FILE_ROTATIONS=3;
const TB_DEFAULT_MAX_CORRELATION_POSITIONS_GLOBAL=2;
const TB_DEFAULT_MAX_CORRELATION_POSITIONS_PER_STRATEGY=2;
const TB_INTENT_SPOOL_SCHEMA='te_intent_spool_v1';
const TB_INTENT_ACK_SCHEMA='broker_intent_ack_v1';


function tb_main(): void
{
    $c=tb_config();tb_dirs($c);
    if(PHP_SAPI==='cli'){
        global $argv;$mode=strtolower((string)($argv[1]??'status'));$id=(string)($argv[2]??'');
        $r=tb_locked($c,static function()use($c,$mode,$id){return tb_dispatch($c,$mode,$id,'cli');});
        echo tb_json($r,true).PHP_EOL;if(empty($r['ok']))exit(2);return;
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        $action=strtolower((string)($_POST['action']??'status'));$id=(string)($_POST['id']??'');
        $r=tb_locked($c,static function()use($c,$action,$id){return tb_dispatch($c,$action,$id,'web');});
        header('Location: '.strtok((string)$_SERVER['REQUEST_URI'],'?'),true,303);return;
    }
    if(($_GET['mode']??'')==='status_json'){header('Content-Type: application/json; charset=utf-8');echo tb_json(tb_status($c),true);return;}
    tb_render($c);
}


function tb_runtime_authority_context(): array
{
    static $cache=null;
    if(is_array($cache))return $cache;
    $marker=__DIR__.'/trade_phase3b_lite_v100/authority.json';$row=[];
    if(is_file($marker)){
        $raw=@file_get_contents($marker);$j=json_decode((string)$raw,true);
        if(is_array($j))$row=$j;
    }
    $authority=strtoupper(trim((string)($row['authority']??'')));
    $realAllowed=!empty($row['real_order_allowed']);
    $singlePaper=$authority==='SINGLE_FILE_PAPER'&&!$realAllowed;
    $legacy=__DIR__.'/trade_runtime';$compat=__DIR__.'/trade_single_compat/trade_runtime';
    $cache=[
        'marker'=>$marker,'marker_readable'=>!empty($row),
        'authority'=>$authority!==''?$authority:'LEGACY_OR_UNMARKED',
        'real_order_allowed'=>$realAllowed,'single_file_paper'=>$singlePaper,
        'runtime'=>$singlePaper?$compat:$legacy,'legacy_runtime'=>$legacy,'compat_runtime'=>$compat,
    ];
    return $cache;
}

function tb_config(): array
{
    $auth=tb_runtime_authority_context();
    $runtime=(string)$auth['runtime'];$configSource='';
    $configCandidates=[$runtime.'/broker_config.local.php'];
    if(!empty($auth['single_file_paper']))$configCandidates[]=(string)$auth['legacy_runtime'].'/broker_config.local.php';
    $configCandidates[]=__DIR__.'/broker_config.local.php';
    foreach(array_values(array_unique($configCandidates))as$cf)if(is_file($cf)){@include_once$cf;$configSource=$cf;break;}
    $configuredRuntime=(string)tb_const('BROKER_RUNTIME_DIR',$runtime);
    $runtime=!empty($auth['single_file_paper'])?(string)$auth['runtime']:$configuredRuntime;
    $runtimeOverrideIgnored=!empty($auth['single_file_paper'])&&rtrim(str_replace('\\','/',$configuredRuntime),'/')!==rtrim(str_replace('\\','/',$runtime),'/');
    $approvalFile=$runtime.'/approval_mode.json';
    $approvalDefault=tb_bool(tb_const('AUTO_APPROVE_VALID',false))?'auto':'manual';
    $approval=tb_approval_mode_load($approvalFile,$approvalDefault);
    $tradeListFile=(string)tb_const('TRADE_LIST_FILE',__DIR__.'/trade_list.php');
    $tradeExchangeMap=tb_trade_list_exchange_map($tradeListFile);
    $configExchangeMap=array_merge(tb_exchange_map(tb_const('US_EXCHANGE_MAP',[])),tb_exchange_map(tb_const('JP_EXCHANGE_MAP',[])));
    $strategyRegistry=tb_strategy_registry(tb_const('KIS_STRATEGY_REGISTRY',[]));$strategyRuntimeMap=[];foreach(array_keys($strategyRegistry)as$rk)$strategyRuntimeMap[$rk]=__DIR__.'/'.$rk.'_runtime';
    $defaultKeys=implode(',',array_keys($strategyRegistry));$legacyFiles=[];foreach(array_keys($strategyRegistry)as$key)$legacyFiles[]=$key.'.php';$defaultFiles=implode(',',array_merge(array_values($strategyRegistry),$legacyFiles));
    $requestedSymbolStrategyCount=max(1,(int)tb_const('MAX_SYMBOL_STRATEGY_COUNT',1));
    $paperFillMode=strtoupper(trim((string)tb_const('PAPER_FILL_MODE','IMMEDIATE_CURRENT_MARK')));if(!in_array($paperFillMode,['IMMEDIATE_CURRENT_MARK','STAGED'],true))$paperFillMode='IMMEDIATE_CURRENT_MARK';
    return[
        'runtime'=>$runtime,'runtime_authority'=>(string)$auth['authority'],'single_file_paper'=>!empty($auth['single_file_paper']),'runtime_authority_marker'=>(string)$auth['marker'],'legacy_runtime'=>(string)$auth['legacy_runtime'],'broker_config_source'=>$configSource,'runtime_override_ignored'=>$runtimeOverrideIgnored,'shared_quote_cache'=>(string)tb_const('SHARED_QUOTE_CACHE_DIR',__DIR__.'/trade_cache/quote'),'intents_file'=>$runtime.'/order_intents.json','intents_lock'=>$runtime.'/order_intents.lock','intent_spool_dir'=>$runtime.'/intent_spool','intent_ack_dir'=>$runtime.'/intent_ack','ingest_audit_file'=>$runtime.'/broker_ingest_audit.json','orders_file'=>$runtime.'/broker_orders.json','orders_archive_file'=>$runtime.'/broker_orders_archive.jsonl','account_file'=>$runtime.'/broker_account.json','lock_stats_file'=>$runtime.'/broker_lock_stats.json',
        'journal_file'=>$runtime.'/broker_journal.json','heartbeat_file'=>$runtime.'/broker_heartbeat.json','token_file'=>$runtime.'/broker_token.json','log_file'=>$runtime.'/broker.log','lock_file'=>$runtime.'/broker.lock','kill_file'=>$runtime.'/broker_kill.flag','approval_mode_file'=>$approvalFile,
        'mode'=>strtolower((string)tb_const('BROKER_MODE','paper')),'auto'=>tb_bool(tb_const('AUTO_TRADE_ENABLED',false)),'approval_mode'=>$approval['mode'],'approval_mode_source'=>$approval['source'],'approval_mode_updated_at'=>$approval['updated_at'],'auto_approve'=>$approval['mode']==='auto','auto_approve_default'=>$approvalDefault==='auto','allow_buy'=>tb_bool(tb_const('ALLOW_BUY',false)),'allow_sell'=>tb_bool(tb_const('ALLOW_SELL',false)),
        'max_per_run'=>max(1,(int)tb_const('MAX_ORDER_PER_RUN',50)),'max_per_cycle'=>max(1,(int)tb_const('MAX_ORDER_PER_CYCLE',50)),'max_signal_age_auto'=>max(60,(int)tb_const('MAX_SIGNAL_AGE_AUTO_SEC',TB_DEFAULT_SIGNAL_AGE)),'max_signal_age_manual'=>max(900,(int)tb_const('MAX_SIGNAL_AGE_MANUAL_SEC',TB_DEFAULT_MANUAL_SIGNAL_AGE)),'max_signal_age'=>$approval['mode']==='auto'?max(60,(int)tb_const('MAX_SIGNAL_AGE_AUTO_SEC',TB_DEFAULT_SIGNAL_AGE)):max(900,(int)tb_const('MAX_SIGNAL_AGE_MANUAL_SEC',TB_DEFAULT_MANUAL_SIGNAL_AGE)),'max_signal_age_sell'=>max(86400,(int)tb_const('MAX_SELL_SIGNAL_AGE_SEC',TB_DEFAULT_SELL_SIGNAL_AGE)),
        'max_gap'=>max(0.1,(float)tb_const('MAX_PRICE_GAP_PCT',TB_DEFAULT_PRICE_GAP)),'max_gap_kr'=>max(0.1,(float)tb_const('MAX_PRICE_GAP_KR_PCT',3.0)),'max_gap_us'=>max(0.1,(float)tb_const('MAX_PRICE_GAP_US_PCT',4.0)),'max_gap_jp'=>max(0.1,(float)tb_const('MAX_PRICE_GAP_JP_PCT',4.0)),'max_order_kr'=>(float)tb_const('MAX_BUY_PER_ORDER_KRW',5000000.0),'max_order_us'=>(float)tb_const('MAX_BUY_PER_ORDER_USD',1000.0),'max_order_jp'=>(float)tb_const('MAX_BUY_PER_ORDER_JPY',500000.0),
        'fresh_quote_age_kr'=>max(30,(int)tb_const('FRESH_QUOTE_MAX_AGE_KR_SEC',TB_DEFAULT_FRESH_QUOTE_AGE)),'fresh_quote_age_us'=>max(30,(int)tb_const('FRESH_QUOTE_MAX_AGE_US_SEC',TB_DEFAULT_FRESH_QUOTE_AGE)),'fresh_quote_age_jp'=>max(30,(int)tb_const('FRESH_QUOTE_MAX_AGE_JP_SEC',TB_DEFAULT_FRESH_QUOTE_AGE)),
        'analysis_quote_age_kr'=>max(30,(int)tb_const('ANALYSIS_QUOTE_MAX_AGE_KR_SEC',TB_DEFAULT_FRESH_QUOTE_AGE)),'analysis_quote_age_us'=>max(30,(int)tb_const('ANALYSIS_QUOTE_MAX_AGE_US_SEC',TB_DEFAULT_FRESH_QUOTE_AGE)),'analysis_quote_age_jp'=>max(30,(int)tb_const('ANALYSIS_QUOTE_MAX_AGE_JP_SEC',TB_DEFAULT_ANALYSIS_QUOTE_AGE_JP)),
        'live_quote_max_age'=>max(10,(int)tb_const('LIVE_QUOTE_MAX_AGE_SEC',60)),'max_live_risk_pct'=>max(0.1,(float)tb_const('MAX_LIVE_RISK_PCT',TB_DEFAULT_LIVE_RISK_MAX)),'max_live_risk_expansion_pct'=>max(0.0,(float)tb_const('MAX_LIVE_RISK_EXPANSION_PCT',TB_DEFAULT_LIVE_RISK_EXPANSION)),
        'cancel_after_sec'=>max(300,(int)tb_const('AUTO_CANCEL_STALE_SEC',1800)),'auto_cancel'=>tb_bool(tb_const('AUTO_CANCEL_STALE',true)),
        'expired_cooldown_sec'=>max(0,(int)tb_const('EXPIRED_REENTRY_COOLDOWN_SEC',TB_DEFAULT_EXPIRED_COOLDOWN_SEC)),'scenario_cooldown_sec'=>max(0,(int)tb_const('SCENARIO_REISSUE_COOLDOWN_SEC',TB_DEFAULT_SCENARIO_COOLDOWN_SEC)),'stop_reentry_cooldown_sec'=>max(0,(int)tb_const('STOP_REENTRY_COOLDOWN_SEC',TB_DEFAULT_STOP_REENTRY_COOLDOWN_SEC)),
        'block_inverse'=>tb_bool(tb_const('BLOCK_INVERSE_BUY',true)),'block_leveraged'=>tb_bool(tb_const('BLOCK_LEVERAGED_BUY',true)),
        'apply_exposure_paper'=>tb_bool(tb_const('APPLY_EXPOSURE_LIMITS_IN_PAPER',true)),'paper_market_hours_gate'=>tb_bool(tb_const('PAPER_MARKET_HOURS_GATE',true)),'paper_require_fresh_quote'=>tb_bool(tb_const('PAPER_REQUIRE_FRESH_QUOTE',true)),'paper_jp_live_requote'=>tb_bool(tb_const('PAPER_JP_KIS_REQUOTE',true)),'paper_quote_max_age_sec'=>max(30,(int)tb_const('PAPER_QUOTE_MAX_AGE_SEC',300)),'paper_fill_mode'=>$paperFillMode,'paper_fill_delay_sec'=>max(0,(int)tb_const('PAPER_FILL_DELAY_SEC',300)),'paper_force_complete_sec'=>max(0,(int)tb_const('PAPER_FORCE_COMPLETE_SEC',900)),'paper_urgent_sell_immediate'=>tb_bool(tb_const('PAPER_URGENT_SELL_IMMEDIATE',true)),'paper_first_fill_ratio'=>max(0.05,min(1.0,(float)tb_const('PAPER_FIRST_FILL_RATIO',0.65))),'paper_next_fill_ratio'=>max(0.05,min(1.0,(float)tb_const('PAPER_NEXT_FILL_RATIO',0.80))),'paper_slippage_bps_kr'=>max(0.0,(float)tb_const('PAPER_SLIPPAGE_BPS_KR',5.0)),'paper_slippage_bps_us'=>max(0.0,(float)tb_const('PAPER_SLIPPAGE_BPS_US',8.0)),'paper_slippage_bps_jp'=>max(0.0,(float)tb_const('PAPER_SLIPPAGE_BPS_JP',8.0)),'seed_kr'=>(float)tb_const('STRATEGY_SEED_KRW',10000000.0),'seed_us'=>(float)tb_const('STRATEGY_SEED_USD',6000.0),'seed_jp'=>(float)tb_const('STRATEGY_SEED_JPY',1000000.0),'max_market_invest_pct'=>max(0.10,min(1.0,(float)tb_const('MAX_MARKET_INVEST_PCT',TB_DEFAULT_MAX_MARKET_INVEST_PCT))),'max_symbol_invest_pct'=>max(0.01,min(1.0,(float)tb_const('MAX_SYMBOL_INVEST_PCT',TB_DEFAULT_MAX_SYMBOL_INVEST_PCT))),'max_risk_group_invest_pct'=>max(0.01,min(1.0,(float)tb_const('MAX_RISK_GROUP_INVEST_PCT',TB_DEFAULT_MAX_RISK_GROUP_INVEST_PCT))),'max_correlation_positions_global'=>max(1,(int)tb_const('MAX_CORRELATION_POSITIONS_GLOBAL',TB_DEFAULT_MAX_CORRELATION_POSITIONS_GLOBAL)),'max_correlation_positions_default'=>max(1,(int)tb_const('MAX_CORRELATION_POSITIONS_PER_STRATEGY',TB_DEFAULT_MAX_CORRELATION_POSITIONS_PER_STRATEGY)),'max_correlation_positions_by_strategy'=>tb_correlation_limits_by_strategy(tb_const('CORRELATION_POSITION_LIMITS_BY_STRATEGY',[])),'max_symbol_total_kr'=>(float)tb_const('MAX_SYMBOL_TOTAL_KRW',10000000.0),'max_symbol_total_us'=>(float)tb_const('MAX_SYMBOL_TOTAL_USD',1500.0),'max_symbol_total_jp'=>(float)tb_const('MAX_SYMBOL_TOTAL_JPY',1000000.0),'max_symbol_strategy_count'=>1,'max_symbol_strategy_count_requested'=>$requestedSymbolStrategyCount,'single_strategy_override_ignored'=>$requestedSymbolStrategyCount!==1,'max_pending_total_kr'=>(float)tb_const('MAX_PENDING_TOTAL_KRW',20000000.0),'max_pending_total_us'=>(float)tb_const('MAX_PENDING_TOTAL_USD',6000.0),'max_pending_total_jp'=>(float)tb_const('MAX_PENDING_TOTAL_JPY',3000000.0),
        'normalize_price_tick'=>tb_bool(tb_const('NORMALIZE_ORDER_PRICE_TICK',true)),'kr_tick_table'=>tb_tick_table(tb_const('KR_PRICE_TICK_TABLE',[])),
        'strategy_registry'=>$strategyRegistry,'strategy_runtime_map'=>$strategyRuntimeMap,'allowed_keys'=>tb_list(tb_const('KIS_ALLOWED_STRATEGY_KEYS',$defaultKeys)),'allowed_files'=>tb_list(tb_const('KIS_ALLOWED_STRATEGY_FILES',$defaultFiles)),
        'base_url'=>rtrim((string)tb_const('KIS_BASE_URL',''),'/'),'app_key'=>(string)tb_const('KIS_APP_KEY',''),'app_secret'=>(string)tb_const('KIS_APP_SECRET',''),
        'cano'=>(string)tb_const('KIS_CANO',''),'product'=>(string)tb_const('KIS_ACNT_PRDT_CD','01'),'mock'=>tb_bool(tb_const('KIS_MOCK',false)),
        'trade_list_file'=>$tradeListFile,'trade_list_exchange_map'=>$tradeExchangeMap,'exchange_map'=>array_merge($tradeExchangeMap,$configExchangeMap),'us_exchange_map'=>array_merge($tradeExchangeMap,$configExchangeMap),'calendar_file'=>(string)tb_const('MARKET_CALENDAR_FILE',__DIR__.'/market_calendar.local.php'),
    ];
}
function tb_const(string $n,$d){return defined($n)?constant($n):$d;}
function tb_correlation_limits_by_strategy($raw): array
{
    $out=['abc'=>2,'dts'=>2,'stc26'=>2,'das'=>2];if(!is_array($raw))return$out;foreach($raw as$k=>$v){$key=strtolower(trim((string)$k));if($key!==''&&is_numeric($v))$out[$key]=max(1,(int)$v);}return$out;
}
function tb_strategy_correlation_limit(array $c,string $strategy): int
{
    $strategy=strtolower($strategy);$map=is_array($c['max_correlation_positions_by_strategy']??null)?$c['max_correlation_positions_by_strategy']:[];return max(1,(int)($map[$strategy]??$c['max_correlation_positions_default']??TB_DEFAULT_MAX_CORRELATION_POSITIONS_PER_STRATEGY));
}

function tb_approval_mode_load(string $file,string $default='manual'): array
{
    $default=strtolower($default)==='auto'?'auto':'manual';
    $row=tb_load($file,[]);$mode=strtolower((string)($row['mode']??''));
    if(!in_array($mode,['manual','auto'],true))return['mode'=>$default,'source'=>'DEFAULT','updated_at'=>''];
    return['mode'=>$mode,'source'=>'RUNTIME','updated_at'=>(string)($row['updated_at']??'')];
}
function tb_approval_origin(string $source): string{return strpos(strtolower($source),'auto:')===0?'AUTO':'MANUAL';}
function tb_approval_mode_status(array $c): array
{
    return['mode'=>(string)$c['approval_mode'],'label'=>$c['approval_mode']==='auto'?'자동 승인':'직접 승인','source'=>(string)$c['approval_mode_source'],'updated_at'=>(string)$c['approval_mode_updated_at'],'auto_approve'=>$c['approval_mode']==='auto'];
}
function tb_set_approval_mode(array $c,string $mode,string $source): array
{
    $mode=strtolower(trim($mode));if(!in_array($mode,['manual','auto'],true))return['ok'=>false,'message'=>'승인 모드는 manual 또는 auto만 허용'];
    $old=(string)$c['approval_mode'];$revoked=0;$approved=0;
    if($mode==='manual'){
        $orders=tb_orders($c);$changed=false;
        foreach($orders as$k=>$o){if(!is_array($o)||strtoupper((string)($o['status']??''))!=='PENDING'||empty($o['approved']))continue;$orders[$k]['approved']=false;$orders[$k]['approval_revoked_at']=tb_now();$orders[$k]['approval_revoked_reason']='APPROVAL_MODE_MANUAL';unset($orders[$k]['approved_at'],$orders[$k]['approved_by'],$orders[$k]['approval_origin'],$orders[$k]['approval_mode_at_approval']);$revoked++;$changed=true;}
        if($changed)tb_save_orders($c,$orders,'approval_mode_manual_revoke');
    }
    $row=['schema'=>TB_SCHEMA,'mode'=>$mode,'updated_at'=>tb_now(),'updated_by'=>$source];tb_save($c['approval_mode_file'],$row);
    $next=$c;$next['approval_mode']=$mode;$next['approval_mode_source']='RUNTIME';$next['approval_mode_updated_at']=$row['updated_at'];$next['auto_approve']=$mode==='auto';
    if($mode==='auto'&&!is_file($c['kill_file'])){$r=tb_approve_valid($next,'auto:mode-switch:'.$source);$approved=(int)($r['count']??0);}
    tb_log($c,'APPROVAL_MODE '.$old.'->'.$mode.' source='.$source.' approved='.$approved.' revoked='.$revoked);
    tb_journal($c,['time'=>tb_now(),'action'=>'set_approval_mode','old_mode'=>$old,'new_mode'=>$mode,'source'=>$source,'approved'=>$approved,'revoked'=>$revoked]);
    return['ok'=>true,'message'=>($mode==='auto'?'자동 승인':'직접 승인').' 모드로 변경','old_mode'=>$old,'mode'=>$mode,'approved'=>$approved,'revoked'=>$revoked];
}
function tb_dirs(array $c): void{foreach([$c['runtime'],$c['intent_spool_dir'],$c['intent_ack_dir']]as$d)if(!is_dir($d)&&!@mkdir($d,0775,true)&&!is_dir($d))throw new RuntimeException('DIR_CREATE_FAILED '.$d);if(!is_file($c['orders_file']))tb_save($c['orders_file'],['schema'=>TB_SCHEMA,'owner'=>'broker','updated_at'=>tb_now(),'orders'=>[]]);if(!is_file($c['heartbeat_file']))tb_save($c['heartbeat_file'],['schema'=>'broker_heartbeat_v1','version'=>TB_VERSION,'rev'=>TB_REV,'execution_mode'=>$c['mode'],'approval_mode'=>$c['approval_mode'],'created_at'=>tb_now(),'last_action'=>'init','last_action_at'=>tb_now()]);}

function tb_locked(array $c,callable $fn): array
{
    $f=@fopen($c['lock_file'],'c+');
    if(!$f){tb_log($c,'LOCK_OPEN_FAILED '.(string)$c['lock_file']);return['ok'=>false,'code'=>'LOCK_OPEN_FAILED','message'=>'broker lock 파일을 열 수 없음'];}
    if(!@flock($f,LOCK_EX|LOCK_NB)){
        @fclose($f);tb_lock_busy_mark($c);
        return['ok'=>true,'skipped'=>true,'code'=>'LOCK_BUSY','message'=>'broker busy: 기존 cycle 유지'];
    }
    try{return$fn();}catch(Throwable $e){tb_log($c,'ERROR '.$e->getMessage().' @'.$e->getLine());return['ok'=>false,'message'=>$e->getMessage()];}
    finally{@flock($f,LOCK_UN);@fclose($f);}
}
function tb_lock_busy_mark(array $c): void
{
    $file=(string)($c['lock_stats_file']??'');$now=tb_now();
    try{$row=tb_load($file,[]);if(!is_array($row))$row=[];$row['count']=(int)($row['count']??0)+1;$row['last_at']=$now;$row['last_pid']=getmypid();$row['expected_cycle_sec']=TB_EXPECTED_CYCLE_SEC;if($file!=='')tb_save($file,$row);}catch(Throwable $e){tb_log($c,'LOCK_BUSY_STATS_FAILED '.$e->getMessage());}
    tb_log($c,'LOCK_BUSY broker.lock 획득 실패 · 기존 프로세스 실행 중 · pid='.getmypid());
}

function tb_dispatch(array $c,string $mode,string $id,string $source): array
{
    if($mode==='health'||$mode==='self_test'||$mode==='validate_system')$r=tb_self_test($c);
    elseif($mode==='set_approval_mode')$r=tb_set_approval_mode($c,$id,$source);
    elseif($mode==='ingest')$r=tb_ingest($c,$source);
    elseif($mode==='validate')$r=tb_validate_action($c);
    elseif($mode==='approve_one')$r=tb_approve_one($c,'manual:'.$source);
    elseif($mode==='approve')$r=tb_approve($c,$id,true,'manual:'.$source);
    elseif($mode==='approve_run')$r=tb_approve_and_run($c,$id,'manual:'.$source);
    elseif($mode==='unapprove')$r=tb_approve($c,$id,false,'manual:'.$source);
    elseif($mode==='approve_valid')$r=tb_approve_valid($c,'manual:'.$source);
    elseif($mode==='expire')$r=tb_expire($c);
    elseif($mode==='cleanup_expired')$r=tb_cleanup_expired($c,$source);
    elseif($mode==='cleanup_terminal')$r=tb_cleanup_terminal($c,$source);
    elseif($mode==='process')$r=tb_process_one($c,$id,$source);
    elseif($mode==='run')$r=tb_run($c,$c['mode'],$source);
    elseif($mode==='cycle')$r=tb_cycle($c,$source);
    elseif($mode==='sync')$r=tb_sync($c,$source);
    elseif($mode==='cancel')$r=tb_cancel_action($c,$id,$source);
    elseif($mode==='cancel_stale')$r=tb_cancel_stale($c,$source);
    else$r=tb_status($c);
    tb_heartbeat_mark($c,$mode,$r,$source);
    return$r;
}

/**
 * Shared order_intents.json read contract.
 * Engine writes under order_intents.lock(LOCK_EX); Broker reads under LOCK_SH.
 * This prevents observing a half-transition and makes the actual input path auditable.
 */

function tb_spool_files(array $c): array
{
    $dir=(string)($c['intent_spool_dir']??'');if($dir===''||!is_dir($dir))return[];
    $files=glob(rtrim($dir,'/\\').'/*.json')?:[];sort($files,SORT_STRING);return$files;
}
function tb_spool_load_intents(array $c): array
{
    $rows=[];$bad=[];$files=tb_spool_files($c);
    foreach($files as$f){
        $x=tb_load($f,[]);$i=is_array($x['intent']??null)?$x['intent']:[];
        $id=(string)($i['order_id']??$x['order_id']??'');
        if($id===''||!$i){$bad[]=$f;continue;}
        $rows[$id]=['intent'=>$i,'file'=>$f,'written_at'=>(string)($x['written_at']??''),'schema'=>(string)($x['schema']??'')];
    }
    return['rows'=>$rows,'files'=>count($files),'bad'=>$bad];
}
function tb_ack_path(array $c,string $orderId): string
{
    return rtrim((string)$c['intent_ack_dir'],'/\\').'/'.preg_replace('/[^A-Za-z0-9_-]/','_',$orderId).'.json';
}
function tb_write_ack(array $c,array $order,string $cycleId,string $source,string $spoolFile=''): bool
{
    $id=(string)($order['order_id']??'');if($id==='')return false;
    $ack=['schema'=>TB_INTENT_ACK_SCHEMA,'owner'=>'broker','order_id'=>$id,'broker_version'=>TB_VERSION,'broker_rev'=>TB_REV,'broker_seen_at'=>(string)($order['broker_seen_at']??tb_now()),'broker_seen_cycle_id'=>$cycleId,'broker_status'=>(string)($order['status']??''),'source'=>$source,'spool_file'=>$spoolFile!==''?basename($spoolFile):'','written_at'=>tb_now()];
    try{tb_save(tb_ack_path($c,$id),$ack);return true;}catch(Throwable $e){tb_log($c,'ACK_WRITE_FAILED '.$id.' '.$e->getMessage());return false;}
}
function tb_merge_intent_sources(array $shared,array $spool): array
{
    $merged=[];$sourceById=[];$spoolById=[];
    foreach($shared as$i){if(!is_array($i))continue;$id=(string)($i['order_id']??'');if($id==='')continue;$merged[$id]=$i;$sourceById[$id]='shared';}
    foreach(($spool['rows']??[])as$id=>$row){if(!is_array($row)||!is_array($row['intent']??null))continue;if(!isset($merged[$id])){$merged[$id]=$row['intent'];$sourceById[$id]='spool';}$spoolById[$id]=(string)($row['file']??'');}
    return['intents'=>array_values($merged),'source_by_id'=>$sourceById,'spool_by_id'=>$spoolById];
}

function tb_intent_source_read(array $c,int $waitMs=2000): array
{
    $lockPath=(string)($c['intents_lock']??(dirname((string)$c['intents_file']).'/order_intents.lock'));
    $started=microtime(true);$fp=@fopen($lockPath,'c+');
    if(!$fp)return['ok'=>false,'error'=>'INTENT_LOCK_OPEN_FAILED','path'=>(string)$c['intents_file'],'lock_path'=>$lockPath,'lock_wait_ms'=>0];
    $locked=false;$deadline=microtime(true)+max(0.1,$waitMs/1000);
    do{
        if(@flock($fp,LOCK_SH|LOCK_NB)){$locked=true;break;}
        usleep(20000);
    }while(microtime(true)<$deadline);
    if(!$locked){@fclose($fp);return['ok'=>false,'error'=>'INTENT_LOCK_BUSY','path'=>(string)$c['intents_file'],'lock_path'=>$lockPath,'lock_wait_ms'=>(int)round((microtime(true)-$started)*1000)];}
    try{
        clearstatcache(true,(string)$c['intents_file']);
        $root=tb_load((string)$c['intents_file'],[]);
        $mtime=is_file((string)$c['intents_file'])?(int)@filemtime((string)$c['intents_file']):0;
        return[
            'ok'=>true,'root'=>$root,
            'path'=>(string)(realpath((string)$c['intents_file'])?:$c['intents_file']),
            'lock_path'=>(string)(realpath($lockPath)?:$lockPath),
            'source_schema'=>(string)($root['schema']??''),
            'source_owner'=>(string)($root['owner']??''),
            'source_updated_at'=>(string)($root['updated_at']??''),
            'source_mtime'=>$mtime,
            'lock_wait_ms'=>(int)round((microtime(true)-$started)*1000),
        ];
    }finally{@flock($fp,LOCK_UN);@fclose($fp);}
}
function tb_ingest_audit(array $c,array $row): void
{
    $row=array_merge([
        'schema'=>'broker_ingest_audit_v1','broker_version'=>TB_VERSION,'broker_rev'=>TB_REV,'checked_at'=>tb_now(),
        'input_path'=>(string)(realpath((string)($c['intents_file']??''))?:($c['intents_file']??'')),
        'orders_path'=>(string)(realpath((string)($c['orders_file']??''))?:($c['orders_file']??'')),
    ],$row);
    tb_save((string)$c['ingest_audit_file'],$row);
}

function tb_ingest(array $c,string $source): array
{
    $src=tb_intent_source_read($c);$spool=tb_spool_load_intents($c);$sharedError='';
    if(empty($src['ok'])){
        $sharedError=(string)($src['error']??'INTENT_SOURCE_READ_FAILED');
        if((int)($spool['files']??0)<1){
            tb_ingest_audit($c,['ok'=>false,'error'=>$sharedError,'source'=>$source,'lock_wait_ms'=>(int)($src['lock_wait_ms']??0),'spool_file_count'=>0]);
            tb_log($c,'INGEST_FAIL source='.$source.' reason='.$sharedError.' spool=0');
            return['ok'=>false,'message'=>'주문 의도 입력 읽기 실패','error'=>$sharedError,'count'=>0,'source_path'=>(string)($src['path']??$c['intents_file']),'lock_wait_ms'=>(int)($src['lock_wait_ms']??0)];
        }
        tb_log($c,'INGEST_SHARED_SOURCE_DEGRADED source='.$source.' reason='.$sharedError.' spool='.(int)$spool['files']);
        $src=array_merge(['ok'=>true,'root'=>[],'path'=>(string)$c['intents_file'],'source_schema'=>'','source_owner'=>'','source_updated_at'=>'','source_mtime'=>0,'lock_wait_ms'=>(int)($src['lock_wait_ms']??0)],$src,['ok'=>true,'root'=>[]]);
    }
    $root=is_array($src['root']??null)?$src['root']:[];
    $shared=is_array($root['intents']??null)?$root['intents']:[];
    $merged=tb_merge_intent_sources($shared,$spool);$intents=$merged['intents'];$sourceById=$merged['source_by_id'];$spoolById=$merged['spool_by_id'];
    $orders=tb_orders($c);$seen=[];foreach($orders as$o)if(is_array($o))$seen[(string)($o['order_id']??'')]=true;
    $cycleId='BI-'.date('Ymd-His').'-'.substr(hash('sha256',$source.'|'.microtime(true)),0,8);
    $n=0;$skipped=0;$rejected=0;$expiredOnArrival=0;$newByMarket=['KR'=>0,'US'=>0,'JP'=>0];$newByStrategy=[];$newFromSpool=0;$ackPending=[];
    foreach($intents as$i){
        if(!is_array($i))continue;$id=(string)($i['order_id']??'');$status=strtoupper((string)($i['status']??'INTENT_CREATED'));$side=strtoupper((string)($i['side']??''));if($id===''||isset($seen[$id]))continue;if(in_array($status,['CANCELLED','REJECTED','FILLED','PAPER_FILLED','BROKER_INGEST_MISSING','BROKER_ORDER_MISSING_AFTER_INGEST'],true)){$skipped++;continue;}
        $snapshot=tb_intent_snapshot($i);$expected=tb_intent_hash_from_snapshot($snapshot);$received=(string)($i['intent_hash']??'');$contract=(string)($i['intent_contract_rev']??'intent_v2');$schema=(string)($i['schema']??'');
        $verified=$received!==''&&hash_equals($expected,$received)&&in_array($schema,[TB_SCHEMA,'te_v24','te_v23','te_v22'],true)&&in_array($contract,['intent_v2','intent_v3','intent_v4','intent_v5'],true)&&($i['owner']??'')==='engine';
        $o=$i;$seenAt=tb_now();$origin=(string)($sourceById[$id]??'shared');$spoolFile=(string)($spoolById[$id]??'');
        $o['intent_owner']=(string)($i['owner']??'');$o['intent_schema']=$schema;$o['engine_intent']=$snapshot;$o['engine_intent_hash']=$received;$o['engine_intent_hash_alg']=(string)($i['intent_hash_alg']??'sha256-canonical-v2');$o['intent_verified']=$verified;$o['owner']='broker';
        $o['broker_ingest_state']='BROKER_SEEN';$o['broker_seen_at']=$seenAt;$o['broker_seen_cycle_id']=$cycleId;$o['broker_ingest_source']=$source;$o['broker_input_origin']=$origin;$o['broker_input_path']=$origin==='spool'?$spoolFile:(string)$src['path'];$o['broker_input_updated_at']=(string)($src['source_updated_at']??'');$o['broker_input_mtime']=(int)($src['source_mtime']??0);$o['broker_input_schema']=$origin==='spool'?TB_INTENT_SPOOL_SCHEMA:(string)($src['source_schema']??'');$o['broker_input_owner']='engine';
        $o['requested_price']=(float)($i['price']??0);$o['requested_amount']=(float)($i['amount']??0);$o['received_at']=$seenAt;$created=tb_ts($i['created_at']??0);$o['created_to_received_sec']=$created>0?max(0,time()-$created):null;$o['approved']=false;$o['filled_qty']=0;$o['avg_fill_price']=0.0;$o['execution_mode']='';
        $exp=tb_ts($i['order_expires_at']??$i['expires_at']??0);$expired=$side!=='SELL'&&$exp>0&&$exp<time();
        if(!$verified){$o['status']='REJECTED';$o['reject_reason']='ENGINE_INTENT_INTEGRITY_FAILED';$o['processed_at']=tb_now();$rejected++;}
        elseif($expired){$o['status']='EXPIRED';$o['expired_reason']='ARRIVED_AFTER_EXPIRY';$o['processed_at']=tb_now();$expiredOnArrival++;}
        else{$o['status']='PENDING';}
        $orders[]=$o;$seen[$id]=true;$n++;if($origin==='spool')$newFromSpool++;
        $m=strtoupper((string)($i['market']??''));if(isset($newByMarket[$m]))$newByMarket[$m]++;
        $sk=strtolower((string)($i['strategy_key']??'unknown'));$newByStrategy[$sk]=(int)($newByStrategy[$sk]??0)+1;
        $ackPending[]=['order'=>$o,'spool_file'=>$spoolFile];
    }
    tb_save_orders($c,$orders,'ingest');
    $acked=0;$ackFailed=0;$spoolDeleted=0;
    foreach($ackPending as$a){
        $o=$a['order'];$sf=(string)$a['spool_file'];
        if(tb_write_ack($c,$o,$cycleId,$source,$sf)){$acked++;if($sf!==''&&is_file($sf)&&@unlink($sf))$spoolDeleted++;}else$ackFailed++;
    }
    $audit=['ok'=>true,'source'=>$source,'cycle_id'=>$cycleId,'shared_input_count'=>count($shared),'spool_file_count'=>(int)($spool['files']??0),'merged_input_count'=>count($intents),'new_count'=>$n,'new_from_spool'=>$newFromSpool,'acked'=>$acked,'ack_failed'=>$ackFailed,'spool_deleted'=>$spoolDeleted,'expired_on_arrival'=>$expiredOnArrival,'rejected'=>$rejected,'skipped'=>$skipped,'new_by_market'=>$newByMarket,'new_by_strategy'=>$newByStrategy,'source_schema'=>(string)($src['source_schema']??''),'source_owner'=>(string)($src['source_owner']??''),'source_updated_at'=>(string)($src['source_updated_at']??''),'source_mtime'=>(int)($src['source_mtime']??0),'lock_wait_ms'=>(int)($src['lock_wait_ms']??0),'bad_spool_files'=>array_values($spool['bad']??[]),'shared_source_error'=>$sharedError];
    tb_ingest_audit($c,$audit);
    tb_log($c,'INGEST source='.$source.' cycle='.$cycleId.' shared='.count($shared).' spool='.(int)($spool['files']??0).' merged='.count($intents).' new='.$n.' from_spool='.$newFromSpool.' acked='.$acked.' JP='.(int)$newByMarket['JP'].' KR='.(int)$newByMarket['KR'].' US='.(int)$newByMarket['US'].' expired_on_arrival='.$expiredOnArrival.' skipped='.$skipped.' rejected='.$rejected);
    return['ok'=>true,'message'=>'주문 의도 수신 '.$n.'건 · spool 복구 '.$newFromSpool.'건 · ACK '.$acked.'건 · JP '.(int)$newByMarket['JP'].'건 · 도착 전 만료 '.$expiredOnArrival.'건 · 거부 '.$rejected.'건','count'=>$n,'shared_input_count'=>count($shared),'spool_file_count'=>(int)($spool['files']??0),'merged_input_count'=>count($intents),'new_from_spool'=>$newFromSpool,'acked'=>$acked,'ack_failed'=>$ackFailed,'spool_deleted'=>$spoolDeleted,'cycle_id'=>$cycleId,'new_by_market'=>$newByMarket,'new_by_strategy'=>$newByStrategy,'expired_on_arrival'=>$expiredOnArrival,'rejected'=>$rejected,'skipped'=>$skipped,'source_path'=>(string)$src['path'],'source_updated_at'=>(string)($src['source_updated_at']??''),'lock_wait_ms'=>(int)($src['lock_wait_ms']??0),'shared_source_error'=>$sharedError];
}
function tb_validate_action(array $c): array{$orders=tb_orders($c);$v=tb_validate_all($c,$orders);return['ok'=>$v['invalid']===0,'message'=>'검증 완료','validation'=>$v];}

function tb_approve_one(array $c,string $source): array
{
    $orders=tb_orders($c);$v=tb_validate_all($c,$orders);
    foreach($orders as $k=>$o){if(!is_array($o)||strtoupper((string)($o['status']??''))!=='PENDING'||!empty($o['approved']))continue;$id=(string)$o['order_id'];if(!empty($v['by_id'][$id]))continue;$orders[$k]['approved']=true;$orders[$k]['approved_at']=tb_now();$rt=tb_ts($orders[$k]['received_at']??0);$orders[$k]['received_to_approved_sec']=$rt>0?max(0,time()-$rt):null;$orders[$k]['approved_by']=$source;$orders[$k]['approval_origin']=tb_approval_origin($source);$orders[$k]['approval_mode_at_approval']=strtoupper((string)$c['approval_mode']);tb_save_orders($c,$orders,'approve_one');return['ok'=>true,'message'=>'승인: '.$id];}
    return['ok'=>false,'message'=>'승인 가능한 주문 없음'];
}
function tb_approve(array $c,string $id,bool $yes,string $source): array
{
    $orders=tb_orders($c);$v=tb_validate_all($c,$orders);$changed=false;
    foreach($orders as $k=>$o){if((string)($o['order_id']??'')!==$id||strtoupper((string)($o['status']??''))!=='PENDING')continue;if($yes&&!empty($v['by_id'][$id]))return['ok'=>false,'message'=>'검증 실패: '.implode(', ',$v['by_id'][$id])];$orders[$k]['approved']=$yes;if($yes){$orders[$k]['approved_at']=tb_now();$rt=tb_ts($orders[$k]['received_at']??0);$orders[$k]['received_to_approved_sec']=$rt>0?max(0,time()-$rt):null;$orders[$k]['approved_by']=$source;$orders[$k]['approval_origin']=tb_approval_origin($source);$orders[$k]['approval_mode_at_approval']=strtoupper((string)$c['approval_mode']);}else{unset($orders[$k]['approved_at'],$orders[$k]['approved_by'],$orders[$k]['approval_origin'],$orders[$k]['approval_mode_at_approval']);}$changed=true;break;}
    if($changed)tb_save_orders($c,$orders,$yes?'approve':'unapprove');return['ok'=>$changed,'message'=>$changed?($yes?'승인 완료':'승인 해제'):'대상 주문 없음'];
}


function tb_approve_and_run(array $c,string $id,string $source): array
{
    if($id===''){
        $orders=tb_orders($c);$v=tb_validate_all($c,$orders);
        foreach($orders as$o){if(!is_array($o)||strtoupper((string)($o['status']??''))!=='PENDING'||!empty($o['approved']))continue;$oid=(string)($o['order_id']??'');if($oid!==''&&empty($v['by_id'][$oid])){$id=$oid;break;}}
    }
    if($id==='')return['ok'=>false,'message'=>'승인 후 실행 가능한 주문 없음'];
    $approve=tb_approve($c,$id,true,$source);
    if(empty($approve['ok']))return['ok'=>false,'message'=>'승인 실패: '.(string)($approve['message']??''),'approve'=>$approve];
    $run=tb_run($c,$c['mode'],$source,1,$id);
    return['ok'=>!empty($run['ok']),'message'=>!empty($run['ok'])?'승인 후 실행 완료':'승인 후 실행 실패','order_id'=>$id,'approve'=>$approve,'run'=>$run];
}

function tb_approve_valid(array $c,string $source): array
{
    $orders=tb_orders($c);$v=tb_validate_all($c,$orders);$n=0;$blocked=0;$changed=false;$origin=tb_approval_origin($source);$now=tb_now();
    foreach($orders as$k=>$o){if(!is_array($o)||strtoupper((string)($o['status']??''))!=='PENDING'||!empty($o['approved']))continue;$id=(string)($o['order_id']??'');if($id==='')continue;$reasons=is_array($v['by_id'][$id]??null)?array_values(array_unique(array_map('strval',$v['by_id'][$id]))):[];$orders[$k]['approval_last_checked_at']=$now;
        if($reasons){$reason=implode(' | ',array_slice($reasons,0,12));if((string)($orders[$k]['approval_block_reason']??'')!==$reason){$orders[$k]['approval_block_reason']=$reason;$orders[$k]['approval_blocked_at']=$now;$changed=true;}$blocked++;continue;}
        if(isset($orders[$k]['approval_block_reason'])||isset($orders[$k]['approval_blocked_at'])){unset($orders[$k]['approval_block_reason'],$orders[$k]['approval_blocked_at']);$changed=true;}
        $orders[$k]['approved']=true;$orders[$k]['approved_at']=$now;$rt=tb_ts($orders[$k]['received_at']??0);$orders[$k]['received_to_approved_sec']=$rt>0?max(0,time()-$rt):null;$orders[$k]['approved_by']=$source;$orders[$k]['approval_origin']=$origin;$orders[$k]['approval_mode_at_approval']=strtoupper((string)$c['approval_mode']);$n++;$changed=true;
    }
    if($changed)tb_save_orders($c,$orders,$origin==='AUTO'?'auto_approve_valid':'manual_approve_valid');
    return['ok'=>true,'message'=>($origin==='AUTO'?'자동':'직접').' 승인 '.$n.'건 · 차단 '.$blocked.'건','count'=>$n,'blocked_count'=>$blocked,'origin'=>$origin];
}
function tb_process_one(array $c,string $id,string $source): array
{
    if($id==='')return['ok'=>false,'message'=>'order_id 필요'];
    $run=tb_run($c,$c['mode'],'process:'.$source,1,$id);$orders=tb_orders($c);$found=null;foreach($orders as$o)if(is_array($o)&&(string)($o['order_id']??'')===$id){$found=$o;break;}
    $status=is_array($found)?strtoupper((string)($found['status']??'')):'NOT_FOUND';$ok=!empty($run['ok'])&&$status!=='REJECTED';
    return['ok'=>$ok,'message'=>'주문 즉시 처리 '.$id.' · '.$status,'order_id'=>$id,'status'=>$status,'run'=>$run];
}

function tb_cycle(array $c, string $source): array
{
    $cycleStarted = microtime(true);
    $run = tb_run($c, $c['mode'], $source, (int)$c['max_per_cycle']);
    $sync = ['ok'=>true, 'message'=>'PAPER 모드: 별도 KIS 동기화 없음'];
    if ($c['mode'] === 'real') $sync = tb_sync($c, $source);
    $ttlCancel = $c['mode'] === 'real' ? tb_cancel_expired_buys($c,$source) : ['ok'=>true,'message'=>'PAPER 만료는 체결 전 종료'];
    $cancel = $c['auto_cancel'] ? tb_cancel_stale($c, $source) : ['ok'=>true, 'message'=>'자동 취소 OFF'];
    $ok = !empty($run['ok']) && !empty($sync['ok']) && !empty($ttlCancel['ok']) && !empty($cancel['ok']);$latency=tb_latency_summary(tb_orders($c));
    return [
        'ok'=>$ok,
        'message'=>$ok ? 'broker cycle 완료' : 'broker cycle 일부 실패',
        'run'=>$run,
        'ingest'=>is_array($run['ingest']??null)?$run['ingest']:[],
        'sync'=>$sync,
        'ttl_cancel'=>$ttlCancel,
        'cancel'=>$cancel,
        'pipeline'=>tb_pipeline_summary(tb_orders($c)),'latency'=>$latency,'cycle_elapsed_sec'=>round(microtime(true)-$cycleStarted,3),'schedule'=>['broker_cycle_sec'=>TB_EXPECTED_CYCLE_SEC,'strategy_cycle_sec_default'=>1200,'strategy_cycle_sec_by_model'=>['dts'=>60,'abc'=>1200,'das'=>1200,'stc26'=>1200]],
    ];
}


function tb_expire(array $c): array
{
    $orders=tb_orders($c);$n=0;$cancelRequired=0;$keptSell=0;$jpRequoteTimeout=0;$expiredKeys=[];
    foreach($orders as $k=>$o){
        if(!is_array($o))continue;$status=strtoupper((string)($o['status']??''));if(!tb_order_active_status($status))continue;
        $side=strtoupper((string)($o['side']??''));$exp=tb_expiry_ts($o);if($exp<=0||$exp>=time())continue;
        if($side==='SELL'){$orders[$k]['persistent_sell_kept_at']=tb_now();$keptSell++;continue;}
        $submitted=tb_order_submitted_status($status);$execution=strtoupper((string)($o['execution_mode']??''));
        if(tb_jp_live_requote_required($o)&&max(0,(int)($o['filled_qty']??0))===0){$orders[$k]=tb_cancel_jp_live_requote($o,'JP_LIVE_REQUOTE_TIMEOUT');$jpRequoteTimeout++;continue;}
        if($submitted&&$execution!=='PAPER'){
            if(empty($orders[$k]['expiry_cancel_required']))$cancelRequired++;$orders[$k]['expiry_cancel_required']=true;$orders[$k]['expiry_detected_at']=$orders[$k]['expiry_detected_at']??tb_now();$orders[$k]['approved']=false;continue;
        }
        $orders[$k]=tb_mark_buy_expired($o);$n++;
        $key=tb_stuck_key($o);if($key!=='')$expiredKeys[$key]=true;
    }
    tb_save_orders($c,$orders,'expire');$stuck=tb_stuck_scenarios($orders,TB_STUCK_EXPIRE_THRESHOLD);
    if($n>0)foreach($stuck as$row)if(isset($expiredKeys[(string)($row['key']??'')]))tb_log($c,'STUCK_ORDER_WARNING '.(string)$row['key'].' expired='.(int)$row['count']);
    return['ok'=>true,'message'=>'BUY 만료 '.$n.'건 · JP 재호가 타임아웃 취소 '.$jpRequoteTimeout.'건 · 실주문 취소필요 '.$cancelRequired.'건 · SELL 유지 '.$keptSell.'건','expired'=>$n,'jp_live_requote_timeout'=>$jpRequoteTimeout,'cancel_required'=>$cancelRequired,'persistent_sell_kept'=>$keptSell,'stuck_scenarios'=>$stuck];
}
function tb_stuck_key(array $o): string
{
    $strategy=strtolower(trim((string)($o['strategy_key']??'')));$market=strtoupper(trim((string)($o['market']??'')));$symbol=strtoupper(trim((string)($o['symbol']??'')));$side=strtoupper(trim((string)($o['side']??'')));
    return $strategy!==''&&$market!==''&&$symbol!==''&&$side!==''?$strategy.':'.$market.':'.$symbol.':'.$side:'';
}
function tb_stuck_scenarios(array $orders,int $threshold=TB_STUCK_EXPIRE_THRESHOLD): array
{
    $groups=[];
    foreach($orders as$o){if(!is_array($o)||strtoupper((string)($o['status']??''))!=='EXPIRED')continue;$key=tb_stuck_key($o);if($key==='')continue;$ts=tb_ts($o['processed_at']??$o['received_at']??$o['created_at']??0);if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'strategy_key'=>(string)($o['strategy_key']??''),'market'=>(string)($o['market']??''),'symbol'=>(string)($o['symbol']??''),'name'=>(string)($o['name']??''),'side'=>(string)($o['side']??''),'count'=>0,'first_at'=>'','last_at'=>'','reasons'=>[]];$groups[$key]['count']++;$at=$ts>0?date('Y-m-d H:i:s',$ts):'';if($at!==''&&($groups[$key]['first_at']===''||$at<$groups[$key]['first_at']))$groups[$key]['first_at']=$at;if($at!==''&&($groups[$key]['last_at']===''||$at>$groups[$key]['last_at']))$groups[$key]['last_at']=$at;$reason=(string)($o['expired_reason']??$o['reject_reason']??'EXPIRED');if($reason!=='')$groups[$key]['reasons'][$reason]=true;}
    $out=[];foreach($groups as$row)if((int)$row['count']>=$threshold){$row['reasons']=array_keys($row['reasons']);$out[]=$row;}
    usort($out,static function($a,$b){$c=((int)($b['count']??0))<=>((int)($a['count']??0));return$c!==0?$c:strcmp((string)($b['last_at']??''),(string)($a['last_at']??''));});return array_slice($out,0,20);
}


function tb_cleanup_expired(array $c,string $source): array
{
    return tb_cleanup_orders($c,['EXPIRED'],'cleanup_expired',$source,'N EXPIRED 정리');
}

function tb_cleanup_terminal(array $c,string $source): array
{
    return tb_cleanup_orders($c,['EXPIRED','REJECTED','CANCELLED'],'cleanup_terminal',$source,'종료 오류 정리');
}

function tb_cleanup_orders(array $c,array $statuses,string $action,string $source,string $label): array
{
    $orders=tb_orders($c);$keep=[];$archive=[];$counts=[];$now=tb_now();
    $allowed=[];foreach($statuses as$st)$allowed[strtoupper((string)$st)]=true;
    foreach($orders as$o){
        if(!is_array($o)){continue;}
        $status=strtoupper((string)($o['status']??''));$approved=!empty($o['approved']);
        $active=in_array($status,['PENDING','APPROVED','SENT','PARTIAL','WORKING','CANCEL_REQUESTED'],true);
        $remove=isset($allowed[$status])&&!$approved&&!$active;
        if($remove){
            $o['archived_at']=$now;$o['archive_action']=$action;$o['archive_source']=$source;
            $archive[]=$o;$counts[$status]=($counts[$status]??0)+1;
        }else{$keep[]=$o;}
    }
    if($archive){
        tb_append_archive($c,$archive,$action,$source);
        tb_save_orders($c,$keep,$action);
    }
    $total=count($archive);$parts=[];foreach($counts as$k=>$v)$parts[]=$k.' '.$v.'건';
    return['ok'=>true,'message'=>$label.' 완료: '.$total.'건'.($parts?' · '.implode(' · ',$parts):''),'removed'=>$total,'counts'=>$counts,'archive_file'=>(string)($c['orders_archive_file']??'')];
}

function tb_rotate_file(string $file,int $maxBytes,int $rotations=TB_FILE_ROTATIONS): void{if($maxBytes<1||!is_file($file))return;$size=@filesize($file);if($size===false||$size<$maxBytes)return;for($i=max(1,$rotations);$i>=1;$i--){$src=$i===1?$file:$file.'.'.($i-1);$dst=$file.'.'.$i;if(is_file($dst))@unlink($dst);if(is_file($src))@rename($src,$dst);}}
function tb_append_archive(array $c,array $rows,string $action,string $source): void
{
    $file=(string)($c['orders_archive_file']??'');if($file==='')return;$dir=dirname($file);if(!is_dir($dir))@mkdir($dir,0775,true);tb_rotate_file($file,TB_ARCHIVE_MAX_BYTES);
    $fp=@fopen($file,'ab');if(!$fp)throw new RuntimeException('ARCHIVE_OPEN_FAILED '.$file);
    if(flock($fp,LOCK_EX)){
        foreach($rows as$row){$line=['schema'=>TB_SCHEMA,'broker_version'=>TB_VERSION,'broker_rev'=>TB_REV,'archived_at'=>tb_now(),'archive_action'=>$action,'archive_source'=>$source,'order'=>$row];fwrite($fp,tb_json($line,false).PHP_EOL);}flock($fp,LOCK_UN);
    }
    fclose($fp);
}

function tb_order_priority(array $o): array
{
    $side=strtoupper((string)($o['side']??''));$status=strtoupper((string)($o['status']??''));$reason=(string)($o['reason']??$o['signal_type']??'');$risk=$side==='SELL'&&(tb_is_stop_reason($reason)||preg_match('/MAX_HOLDING|EXIT|SELL|청산/i',$reason));$created=tb_ts($o['created_at']??0);$working=in_array($status,['WORKING','PARTIAL'],true)?0:1;$approval=($status==='PENDING'&&empty($o['approved']))?1:0;
    return[$risk?0:($side==='SELL'?1:2),$working,$approval,$created>0?$created:PHP_INT_MAX];
}

function tb_order_pre_submit_status(string $status): bool
{
    return in_array(strtoupper($status),['PENDING','APPROVED'],true);
}

function tb_order_submitted_status(string $status): bool
{
    return in_array(strtoupper($status),['SENT','WORKING','PARTIAL','CANCEL_REQUESTED'],true);
}

function tb_order_active_status(string $status): bool
{
    return tb_order_pre_submit_status($status)||tb_order_submitted_status($status);
}

function tb_expiry_ts(array $order): int
{
    return tb_ts($order['order_expires_at']??$order['expires_at']??0);
}

function tb_buy_expired(array $order,?int $now=null): bool
{
    if(strtoupper((string)($order['side']??''))!=='BUY')return false;$exp=tb_expiry_ts($order);return$exp>0&&$exp<($now??time());
}

function tb_mark_buy_expired(array $order,string $reason=''): array
{
    $qty=max(0,(int)($order['qty']??0));$filled=max(0,(int)($order['filled_qty']??0));$order['status']='EXPIRED';$order['approved']=false;$order['processed_at']=tb_now();$order['expired_at']=tb_now();$order['remaining_cancelled_qty']=max(0,$qty-$filled);$order['expired_reason']=$reason!==''?$reason:($filled>0?'ORDER_TTL_REMAINDER':'ORDER_TTL_BEFORE_FILL');$order['broker_message']=$filled>0?'BUY_TTL_EXPIRED_PARTIAL_REMAINDER_CANCELLED':'BUY_TTL_EXPIRED_BEFORE_FILL';return$order;
}

function tb_jp_live_requote_reason(array $o): string
{
    if(strtoupper((string)($o['market']??''))!=='JP'||strtoupper((string)($o['side']??''))!=='BUY')return'';
    foreach(['cancel_reason','expired_reason','reject_reason','terminal_reason','broker_message']as$k){$v=strtoupper((string)($o[$k]??''));if(strpos($v,'JP_LIVE_REQUOTE')!==false)return$v;}
    $status=strtoupper((string)($o['status']??''));$fresh=strtoupper((string)($o['quote_freshness']??''));$exp=strtoupper((string)($o['expired_reason']??''));
    if($status==='EXPIRED'&&!empty($o['requires_live_requote'])&&$fresh==='DELAYED'&&in_array($exp,['ORDER_TTL_BEFORE_FILL','ARRIVED_AFTER_EXPIRY'],true))return'JP_LIVE_REQUOTE_LEGACY_EXPIRED';
    return'';
}
function tb_is_jp_quote_terminal(array $o): bool{return tb_jp_live_requote_reason($o)!=='';}
function tb_jp_live_requote_required(array $o): bool{return strtoupper((string)($o['market']??''))==='JP'&&strtoupper((string)($o['side']??''))==='BUY'&&!empty($o['requires_live_requote']);}
function tb_mark_jp_live_requote_wait(array $o,array $basis): array
{
    $o['jp_live_requote_pending']=true;$o['jp_live_requote_wait_count']=max(0,(int)($o['jp_live_requote_wait_count']??0))+1;$o['jp_live_requote_last_attempt_at']=tb_now();$o['jp_live_requote_last_error']=(string)($basis['message']??'KIS 현재가 조회 실패');$o['broker_message']='JP_LIVE_REQUOTE_WAIT';return$o;
}
function tb_cancel_jp_live_requote(array $o,string $reason): array
{
    $qty=max(0,(int)($o['qty']??0));$filled=max(0,(int)($o['filled_qty']??0));$o['status']='CANCELLED';$o['approved']=false;$o['cancelled_at']=tb_now();$o['processed_at']=tb_now();$o['remaining_cancelled_qty']=max(0,$qty-$filled);$o['cancel_reason']=$reason;$o['broker_message']=$reason;$o['jp_live_requote_pending']=false;return$o;
}

function tb_active_owner_maps(array $orders): array
{
    $identity=[];$scenario=[];$rank=[];$scenarioRank=[];
    foreach($orders as$o){
        if(!is_array($o))continue;$status=strtoupper((string)($o['status']??''));if(!tb_order_active_status($status))continue;
        $id=(string)($o['order_id']??'');if($id==='')continue;$strategy=strtolower((string)($o['strategy_key']??''));$market=strtoupper((string)($o['market']??''));$symbol=strtoupper(trim((string)($o['symbol']??'')));$side=strtoupper((string)($o['side']??''));
        $submitted=tb_order_submitted_status($status)?0:1;$created=tb_ts($o['created_at']??0);$candidate=[$submitted,$created>0?$created:PHP_INT_MAX,$id];$key=$strategy.':'.$market.':'.$symbol.':'.$side;
        if(!isset($rank[$key])||$candidate<$rank[$key]){$rank[$key]=$candidate;$identity[$key]=$id;}
        $scenarioId=trim((string)($o['scenario_id']??''));if($side==='BUY'&&$scenarioId!==''){$scenarioKey=$strategy.':'.$scenarioId;if(!isset($scenarioRank[$scenarioKey])||$candidate<$scenarioRank[$scenarioKey]){$scenarioRank[$scenarioKey]=$candidate;$scenario[$scenarioKey]=$id;}}
    }
    return['identity'=>$identity,'scenario'=>$scenario];
}

function tb_cancel_submitted_paper(array $o,string $reason): array
{
    $o['status']='CANCELLED';$o['approved']=false;$o['cancelled_at']=tb_now();$o['processed_at']=tb_now();$o['broker_message']='PAPER_CANCELLED_VALIDATION';$o['cancel_reason']=$reason;return$o;
}

function tb_paper_slippage_bps(array $c,string $market): float
{
    $market=strtoupper($market);return$market==='KR'?(float)$c['paper_slippage_bps_kr']:($market==='JP'?(float)$c['paper_slippage_bps_jp']:(float)$c['paper_slippage_bps_us']);
}

function tb_round_fill_price(array $c,string $market,string $side,float $price): float
{
    if($price<=0)return 0.0;$market=strtoupper($market);$side=strtoupper($side);$tick=$market==='KR'?tb_kr_tick($c,$price):($market==='JP'?1.0:($price>=1.0?0.01:0.0001));$units=$price/$tick;$rounded=$side==='BUY'?ceil($units-1.0E-10)*$tick:floor($units+1.0E-10)*$tick;return in_array($market,['KR','JP'],true)?round($rounded,0):round($rounded,$price>=1.0?2:4);
}

function tb_quote_cache_key(string $market,string $symbol): string{return preg_replace('/[^A-Za-z0-9_.-]/','_',strtoupper($market).'_'.strtoupper($symbol));}
function tb_cached_quote(array $c,string $market,string $symbol): array
{
    $file=rtrim((string)($c['shared_quote_cache']??''),'/').'/'.tb_quote_cache_key($market,$symbol).'.json';$root=tb_load($file,[]);$q=is_array($root['quote']??null)?$root['quote']:[];$saved=(int)($root['saved_at']??0);$price=(float)($q['price']??0);$ts=(int)($q['ts']??$saved);$age=$ts>0?max(0,time()-$ts):PHP_INT_MAX;return['ok'=>$price>0,'price'=>$price,'age_sec'=>$age,'source'=>(string)($q['source']??'ENGINE_QUOTE_CACHE'),'file'=>$file];
}
function tb_paper_fill_basis(array $c,array $o): array
{
    $market=strtoupper((string)($o['market']??''));$symbol=strtoupper((string)($o['symbol']??''));$cached=tb_cached_quote($c,$market,$symbol);$maxAge=(int)($c['paper_quote_max_age_sec']??300);
    if(!empty($cached['ok'])&&(int)$cached['age_sec']<=$maxAge)return$cached;
    if($market==='JP'&&tb_jp_live_requote_required($o)&&!empty($c['paper_jp_live_requote'])){
        $live=tb_kis_quote($c,$o);
        if(empty($live['ok']))return['ok'=>false,'price'=>0.0,'age_sec'=>$cached['age_sec']??PHP_INT_MAX,'source'=>'JP_LIVE_REQUOTE_UNAVAILABLE','retryable'=>true,'message'=>(string)($live['message']??$live['error']??'KIS 현재가 조회 실패'),'live_quote'=>$live];
        $prepared=tb_prepare_live_order($c,$o,$live);
        if(empty($prepared['ok']))return['ok'=>false,'price'=>0.0,'age_sec'=>0,'source'=>'JP_LIVE_REQUOTE_REVALIDATION_FAILED','retryable'=>false,'message'=>(string)($prepared['message']??'실시간 재검증 실패'),'live_quote'=>$live];
        $exec=(array)$prepared['order'];return['ok'=>true,'price'=>(float)($exec['price']??$live['price']??0),'age_sec'=>0,'source'=>'KIS_PAPER_JP_LIVE_REQUOTE','quoted_at'=>(string)($live['quoted_at']??tb_now()),'live_requote'=>true,'prepared_order'=>$exec,'live_quote'=>$live];
    }
    $side=strtoupper((string)($o['side']??''));$approvedTs=tb_ts($o['approved_at']??$o['received_at']??$o['created_at']??0);$stuckAge=$approvedTs>0?max(0,time()-$approvedTs):0;
    if($side==='SELL'&&$stuckAge>=TB_PAPER_STUCK_SELL_RECOVERY_SEC){if(!empty($cached['ok']))return['ok'=>true,'price'=>(float)$cached['price'],'age_sec'=>(int)$cached['age_sec'],'source'=>'PAPER_STUCK_SELL_STALE_MARK_RECOVERY','stuck_recovery'=>true];$fallback=max(0.0,(float)($o['live_quote_price']??$o['current_quote_price']??$o['analysis_price']??$o['price']??0));if($fallback>0)return['ok'=>true,'price'=>$fallback,'age_sec'=>PHP_INT_MAX,'source'=>'PAPER_STUCK_SELL_ORDER_MARK_RECOVERY','stuck_recovery'=>true];}
    if(!empty($c['paper_require_fresh_quote']))return['ok'=>false,'price'=>0.0,'age_sec'=>$cached['age_sec']??PHP_INT_MAX,'source'=>'NO_FRESH_QUOTE'];
    $fallback=max(0.0,(float)($o['live_quote_price']??$o['current_quote_price']??$o['analysis_price']??$o['price']??0));return['ok'=>$fallback>0,'price'=>$fallback,'age_sec'=>PHP_INT_MAX,'source'=>'ORDER_PRICE_FALLBACK'];
}
function tb_paper_fill_price(array $c,array $o,array $basis=[]): float
{
    if(!$basis)$basis=tb_paper_fill_basis($c,$o);if(empty($basis['ok']))return 0.0;$market=strtoupper((string)($o['market']??''));$side=strtoupper((string)($o['side']??''));$base=(float)$basis['price'];$bps=tb_paper_slippage_bps($c,$market);$factor=$side==='SELL'?(1.0-$bps/10000.0):(1.0+$bps/10000.0);return tb_round_fill_price($c,$market,$side,max(0.0,$base*$factor));
}

function tb_paper_fill_immediate(array $c,array $o,string $message='PAPER_CURRENT_MARK_FILLED'): array
{
    $qty=max(1,(int)($o['qty']??0));$basis=tb_paper_fill_basis($c,$o);$fillPrice=tb_paper_fill_price($c,$o,$basis);
    if(empty($basis['ok'])||$fillPrice<=0){
        if(tb_jp_live_requote_required($o)&&strpos((string)($basis['source']??''),'JP_LIVE_REQUOTE')===0){if(!empty($basis['retryable'])){$o=tb_mark_jp_live_requote_wait($o,$basis);return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false,'retryable'=>true];}$o=tb_cancel_jp_live_requote($o,'JP_LIVE_REQUOTE_REVALIDATION_FAILED');$o['jp_live_requote_last_error']=(string)($basis['message']??'실시간 재검증 실패');return['order'=>$o,'processed'=>true,'filled_qty'=>0,'completed'=>false,'terminal'=>true];}
        $o['broker_message']='PAPER_FRESH_QUOTE_REQUIRED';return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false];
    }
    if(!empty($basis['live_requote'])){$o['jp_live_requote_pending']=false;$o['jp_live_requote_last_success_at']=(string)($basis['quoted_at']??tb_now());$o['live_quote_price']=(float)$basis['price'];$o['live_quote_at']=(string)($basis['quoted_at']??tb_now());}
    $o['status']='PAPER_FILLED';$o['execution_mode']='PAPER';$o['filled_qty']=$qty;$o['avg_fill_price']=$fillPrice;
    $o['paper_submitted_at']=$o['paper_submitted_at']??tb_now();$o['last_fill_at']=tb_now();$o['filled_at']=tb_now();$o['processed_at']=tb_now();
    $o['was_approved']=true;$o['approved']=false;$o['approval_completed_at']=$o['approval_completed_at']??tb_now();
    $o['paper_fill_events']=[['time'=>tb_now(),'qty'=>$qty,'price'=>$fillPrice,'cumulative_qty'=>$qty]];
    $o['paper_mark_price']=(float)($basis['price']??0);$o['paper_mark_source']=(string)($basis['source']??'');if(!empty($basis['stuck_recovery'])){$o['stuck_recovery_at']=tb_now();$o['stuck_recovery_reason']='PAPER_SELL_NO_FRESH_QUOTE_BOUNDED_RECOVERY';}$o['paper_mark_age_sec']=(int)($basis['age_sec']??0);
    $o['paper_slippage_bps']=tb_paper_slippage_bps($c,(string)($o['market']??''));$o['paper_fill_model']='IMMEDIATE_CURRENT_MARK';$o['broker_message']=$message;
    return['order'=>$o,'processed'=>true,'filled_qty'=>$qty,'completed'=>true];
}

function tb_paper_advance(array $c,array $o): array
{
    $now=time();$status=strtoupper((string)($o['status']??''));$qty=max(1,(int)($o['qty']??0));$filled=max(0,(int)($o['filled_qty']??0));
    if(tb_buy_expired($o,$now)){if(tb_jp_live_requote_required($o)&&max(0,(int)($o['filled_qty']??0))===0){$o=tb_cancel_jp_live_requote($o,'JP_LIVE_REQUOTE_TIMEOUT');return['order'=>$o,'processed'=>true,'filled_qty'=>0,'completed'=>false,'terminal'=>true];}$o=tb_mark_buy_expired($o);return['order'=>$o,'processed'=>true,'filled_qty'=>0,'completed'=>false,'terminal'=>true];}
    if($status==='PENDING'){
        if(!empty($c['paper_market_hours_gate'])&&!tb_market_open($c,$o)){$o=tb_mark_market_wait($o);return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false];}
        $o=tb_release_market_wait($o);
        $urgent=strtoupper((string)($o['side']??''))==='SELL'&&(!empty($o['urgent_exit'])||tb_is_stop_reason((string)($o['reason']??$o['signal_type']??''))||preg_match('/EMERGENCY|ACCOUNT_RISK|TRADING_HALT|DELIST|DRAWDOWN|INITIAL_STOP|HARD_STOP|손절|거래정지|상장폐지/i',(string)($o['reason']??'')));
        if($urgent&&!empty($c['paper_urgent_sell_immediate']))return tb_paper_fill_immediate($c,$o,'PAPER_URGENT_EXIT_FILLED');
        if(strtoupper((string)($c['paper_fill_mode']??'IMMEDIATE_CURRENT_MARK'))==='IMMEDIATE_CURRENT_MARK')return tb_paper_fill_immediate($c,$o,'PAPER_CURRENT_MARK_FILLED');
        $o['status']='WORKING';$o['execution_mode']='PAPER';$o['paper_submitted_at']=tb_now();$o['market_order_date']=tb_market_date((string)($o['market']??''));$o['was_approved']=true;$o['approved']=false;$o['approval_completed_at']=tb_now();$o['processed_at']=tb_now();$o['paper_quote_freshness']=(string)($o['quote_freshness']??'UNKNOWN');$o['broker_message']='PAPER_WORKING_NEXT_CYCLE';return['order'=>$o,'processed'=>true,'filled_qty'=>0,'completed'=>false];
    }
    if(!in_array($status,['WORKING','PARTIAL'],true)||strtoupper((string)($o['execution_mode']??''))!=='PAPER')return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false];
    if(!empty($c['paper_market_hours_gate'])&&!tb_market_open($c,$o)){$o=tb_mark_market_wait($o);return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false];}
    $o=tb_release_market_wait($o);
    $submitted=tb_ts($o['paper_submitted_at']??$o['processed_at']??0);if($submitted<=0){$o['paper_submitted_at']=tb_now();return['order'=>$o,'processed'=>true,'filled_qty'=>0,'completed'=>false];}
    $age=max(0,$now-$submitted);$lastFill=tb_ts($o['last_fill_at']??0);$sinceLast=$lastFill>0?max(0,$now-$lastFill):$age;if($sinceLast<(int)$c['paper_fill_delay_sec'])return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false];
    $remaining=max(0,$qty-$filled);if($remaining<1){$o['status']='PAPER_FILLED';$o['filled_at']=$o['filled_at']??tb_now();return['order'=>$o,'processed'=>true,'filled_qty'=>0,'completed'=>true];}
    $ratio=$filled>0?(float)$c['paper_next_fill_ratio']:(float)$c['paper_first_fill_ratio'];$fillQty=$age>=(int)$c['paper_force_complete_sec']?$remaining:max(1,(int)floor($remaining*$ratio));$fillQty=min($remaining,$fillQty);$basis=tb_paper_fill_basis($c,$o);if(empty($basis['ok'])){if(tb_jp_live_requote_required($o)&&strpos((string)($basis['source']??''),'JP_LIVE_REQUOTE')===0){if(!empty($basis['retryable'])){$o=tb_mark_jp_live_requote_wait($o,$basis);return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false,'retryable'=>true];}$o=tb_cancel_jp_live_requote($o,'JP_LIVE_REQUOTE_REVALIDATION_FAILED');$o['jp_live_requote_last_error']=(string)($basis['message']??'실시간 재검증 실패');return['order'=>$o,'processed'=>true,'filled_qty'=>0,'completed'=>false,'terminal'=>true];}$o['broker_message']='PAPER_FRESH_QUOTE_REQUIRED';return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false];}$fillPrice=tb_paper_fill_price($c,$o,$basis);if($fillPrice<=0){$o['broker_message']='PAPER_FILL_PRICE_INVALID';return['order'=>$o,'processed'=>false,'filled_qty'=>0,'completed'=>false];}if(!empty($basis['live_requote'])){$o['jp_live_requote_pending']=false;$o['jp_live_requote_last_success_at']=(string)($basis['quoted_at']??tb_now());$o['live_quote_price']=(float)$basis['price'];$o['live_quote_at']=(string)($basis['quoted_at']??tb_now());}
    $newFilled=$filled+$fillQty;$oldAvg=max(0.0,(float)($o['avg_fill_price']??0));$newAvg=$newFilled>0?(($filled*$oldAvg)+($fillQty*$fillPrice))/$newFilled:0.0;$events=is_array($o['paper_fill_events']??null)?$o['paper_fill_events']:[];$events[]=['time'=>tb_now(),'qty'=>$fillQty,'price'=>$fillPrice,'cumulative_qty'=>$newFilled];if(count($events)>20)$events=array_slice($events,-20);
    $o['filled_qty']=$newFilled;$o['avg_fill_price']=round($newAvg,4);$o['last_fill_at']=tb_now();$o['paper_fill_events']=$events;$o['paper_slippage_bps']=tb_paper_slippage_bps($c,(string)($o['market']??''));$o['paper_mark_price']=(float)($basis['price']??0);$o['paper_mark_source']=(string)($basis['source']??'');if(!empty($basis['stuck_recovery'])){$o['stuck_recovery_at']=tb_now();$o['stuck_recovery_reason']='PAPER_SELL_NO_FRESH_QUOTE_BOUNDED_RECOVERY';}$o['paper_mark_age_sec']=(int)($basis['age_sec']??0);$o['paper_fill_model']='DELAYED_PARTIAL_CURRENT_MARK';$o['processed_at']=tb_now();
    if($newFilled>=$qty){$o['status']='PAPER_FILLED';$o['filled_at']=tb_now();$o['broker_message']='PAPER_FILLED_STAGED';return['order'=>$o,'processed'=>true,'filled_qty'=>$fillQty,'completed'=>true];}
    $o['status']='PARTIAL';$o['broker_message']='PAPER_PARTIAL '.$newFilled.'/'.$qty;return['order'=>$o,'processed'=>true,'filled_qty'=>$fillQty,'completed'=>false];
}

function tb_run(array $c,string $mode,string $source,int $limitOverride=0,string $targetId=''): array
{
    $ingest=tb_ingest($c,$source);$expire=tb_expire($c);$approval=['ok'=>true,'message'=>'직접 승인 모드','count'=>0,'origin'=>'MANUAL'];
    if(is_file($c['kill_file']))return['ok'=>false,'message'=>'broker_kill.flag로 차단','approval'=>$approval];
    if($c['approval_mode']==='auto')$approval=tb_approve_valid($c,'auto:run:'.$source);
    $mode=strtolower($mode)==='real'?'real':'paper';$orders=tb_orders($c);
    if($c['normalize_price_tick'])foreach($orders as$k=>$row){if(!is_array($row)||strtoupper((string)($row['status']??''))!=='PENDING')continue;$orders[$k]['requested_price']=$orders[$k]['requested_price']??$row['price']??0;$orders[$k]['requested_amount']=$orders[$k]['requested_amount']??$row['amount']??0;$orders[$k]=tb_normalize_order_price($c,$orders[$k]);}
    $validation=tb_validate_all($c,$orders);$processed=0;$filledOrders=0;$fillQtyTotal=0;$sent=0;$rejected=0;$limit=$limitOverride>0?$limitOverride:(int)$c['max_per_run'];
    $orderKeys=array_keys($orders);usort($orderKeys,static function($a,$b)use($orders){return tb_order_priority(is_array($orders[$a]??null)?$orders[$a]:[])<=>tb_order_priority(is_array($orders[$b]??null)?$orders[$b]:[]);});
    foreach($orderKeys as$k){
        if($processed>=$limit)break;$o=$orders[$k]??null;if(!is_array($o))continue;$status=strtoupper((string)($o['status']??''));$isPaperActive=$mode==='paper'&&(($status==='PENDING'&&!empty($o['approved']))||(in_array($status,['WORKING','PARTIAL'],true)&&strtoupper((string)($o['execution_mode']??''))==='PAPER'));$isRealPending=$mode==='real'&&$status==='PENDING'&&!empty($o['approved']);if(!$isPaperActive&&!$isRealPending)continue;
        if($targetId!==''&&(string)($o['order_id']??'')!==$targetId)continue;$id=(string)($o['order_id']??'');
        if(!empty($validation['by_id'][$id])){$reason=implode('; ',$validation['by_id'][$id]);if($mode==='paper'&&tb_order_submitted_status($status)){$orders[$k]=tb_cancel_submitted_paper($o,$reason);$processed++;continue;}$orders[$k]=tb_reject($o,$reason,strtoupper($mode));$processed++;$rejected++;continue;}
        if($mode==='paper'){$step=tb_paper_advance($c,$o);$orders[$k]=$step['order'];if(!empty($step['processed']))$processed++;$fillQtyTotal+=(int)$step['filled_qty'];if(!empty($step['completed']))$filledOrders++;continue;}
        $safe=tb_real_allowed($c,$o);if(!$safe['ok']){$orders[$k]=tb_reject($o,$safe['message'],'REAL');$processed++;$rejected++;continue;}
        $live=tb_kis_quote($c,$o);if(empty($live['ok'])){$orders[$k]=tb_reject($o,'KIS 현재가 조회 실패','REAL');$processed++;$rejected++;continue;}
        $prepared=tb_prepare_live_order($c,$o,$live);if(empty($prepared['ok'])){$orders[$k]=tb_reject($o,(string)($prepared['message']??'실시간 재검증 실패'),'REAL');$orders[$k]['live_quote']=$live;$processed++;$rejected++;continue;}
        $exec=$prepared['order'];$liveOrders=$orders;$liveOrders[$k]=$exec;$liveValidation=tb_validate_all($c,$liveOrders);if(!empty($liveValidation['by_id'][$id])){$orders[$k]=tb_reject($o,'실시간 가격 재산정 검증 실패: '.implode('; ',$liveValidation['by_id'][$id]),'REAL');$orders[$k]['live_quote']=$live;$processed++;$rejected++;continue;}
        if(strtoupper((string)$exec['side'])==='SELL'){$own=tb_real_sell_qty_check($c,$exec,$orders);if(!$own['ok']){$orders[$k]=tb_reject($o,$own['message'],'REAL');$orders[$k]['live_quote']=$live;$processed++;$rejected++;continue;}}else{$fund=tb_real_buy_cash_check($c,$exec,$orders);if(!$fund['ok']){$orders[$k]=tb_reject($o,$fund['message'],'REAL');$orders[$k]['live_quote']=$live;$processed++;$rejected++;continue;}}
        if(tb_buy_expired($exec)){$orders[$k]=tb_mark_buy_expired($exec,'ORDER_TTL_BEFORE_SEND');$orders[$k]['live_quote']=$live;$processed++;continue;}
        $send=tb_kis_order($c,$exec);$orders[$k]=array_merge($orders[$k],['price'=>$exec['price'],'amount'=>$exec['amount'],'live_quote_price'=>$exec['live_quote_price'],'live_quote_at'=>$exec['live_quote_at'],'execution_price_rebased'=>$exec['execution_price_rebased'],'live_gap_pct'=>$exec['live_gap_pct'],'live_risk_pct'=>$exec['live_risk_pct']??null,'processed_at'=>tb_now(),'broker_response'=>$send,'was_approved'=>true,'approved'=>false,'approval_completed_at'=>tb_now(),'execution_mode'=>'REAL']);
        if(!empty($send['ok'])){$orders[$k]['status']='SENT';$orders[$k]['sent_at']=tb_now();$orders[$k]['market_order_date']=tb_market_date((string)($o['market']??''));$orders[$k]['kis_order_no']=(string)($send['order_no']??'');$orders[$k]['kis_org_no']=(string)($send['org_no']??'');$orders[$k]['broker_message']=(string)($send['message']??'KIS 전송');$sent++;}else{$orders[$k]['status']='REJECTED';$orders[$k]['reject_reason']=(string)($send['message']??'KIS 주문 실패');$rejected++;}$processed++;
    }
    tb_save_orders($c,$orders,$mode.'_run');tb_journal($c,['time'=>tb_now(),'action'=>$mode.'_run','approval_mode'=>$c['approval_mode'],'processed'=>$processed,'filled_orders'=>$filledOrders,'filled_qty'=>$fillQtyTotal,'sent'=>$sent,'rejected'=>$rejected,'source'=>$source,'approval'=>$approval,'ingest'=>$ingest,'expire'=>$expire]);
    $runOk=!empty($ingest['ok']);return['ok'=>$runOk,'message'=>$runOk?strtoupper($mode).' 처리 '.$processed.'건':'INGEST 실패 · 기존 broker order 처리 '.$processed.'건','approval_mode'=>$c['approval_mode'],'approval'=>$approval,'processed'=>$processed,'filled'=>$filledOrders,'filled_qty'=>$fillQtyTotal,'sent'=>$sent,'rejected'=>$rejected,'ingest'=>$ingest,'expire'=>$expire];
}

function tb_sync(array $c,string $source): array
{
    if($c['mode']!=='real'&&!$c['mock'])return['ok'=>false,'message'=>'실전 또는 모의 KIS 설정에서 sync 가능'];
    $orders=tb_orders($c);$dom=tb_kis_domestic_fills($c);$ovs=tb_kis_overseas_fills($c);$rows=array_merge($dom['rows']??[],$ovs['rows']??[]);$updated=0;
    foreach($orders as $k=>$o){
        if(!is_array($o)||!in_array(strtoupper((string)($o['status']??'')),['SENT','PARTIAL','WORKING','CANCEL_REQUESTED'],true))continue;
        $no=(string)($o['kis_order_no']??'');if($no==='')continue;
        foreach($rows as $r){
            if((string)($r['order_no']??'')!==$no||strtoupper((string)($r['market']??''))!==strtoupper((string)($o['market']??'')))continue;$sentDate=preg_replace('/[^0-9]/','',(string)($o['market_order_date']??''));if($sentDate==='')$sentDate=preg_replace('/[^0-9]/','',substr((string)($o['sent_at']??''),0,10));$rowDate=preg_replace('/[^0-9]/','',(string)($r['order_date']??''));if($sentDate!==''&&$rowDate!==''&&$sentDate!==$rowDate)continue;
            $fq=max(0,(int)($r['filled_qty']??0));$oq=max(1,(int)($o['qty']??1));
            $orderDate=preg_replace('/[^0-9]/','',(string)($r['order_date']??''));
            $orders[$k]['filled_qty']=$fq;$orders[$k]['avg_fill_price']=(float)($r['avg_fill_price']??$o['price']);$orders[$k]['last_sync_at']=tb_now();
            if($fq>=$oq){$orders[$k]['status']='FILLED';$orders[$k]['filled_at']=(string)($r['filled_at']??tb_now());}
            elseif($orderDate!==''&&$orderDate<str_replace('-','',tb_market_date((string)($o['market']??'')))){$orders[$k]['status']='CANCELLED';$orders[$k]['cancelled_at']=tb_now();}
            elseif($fq>0)$orders[$k]['status']='PARTIAL';
            else$orders[$k]['status']='WORKING';
            $updated++;break;
        }
    }
    tb_save_orders($c,$orders,'sync');$account=tb_kis_account_raw($c);if(!empty($account['ok']))tb_save($c['account_file'],$account['snapshot']);$cancel=['requested'=>0,'failed'=>0];if($c['auto_cancel'])$cancel=tb_cancel_stale($c,$source);tb_journal($c,['time'=>tb_now(),'action'=>'sync','updated'=>$updated,'source'=>$source,'cancel'=>$cancel]);return['ok'=>true,'message'=>'체결 동기화 '.$updated.'건','updated'=>$updated,'cancel'=>$cancel,'domestic_ok'=>!empty($dom['ok']),'overseas_ok'=>!empty($ovs['ok']),'account_ok'=>!empty($account['ok'])];
}

function tb_open_position_book(array $c,array $orders,string $executionMode=''): array
{
    $rows=[];$executionMode=strtoupper($executionMode);foreach($orders as$o){if(!is_array($o)||max(0,(int)($o['filled_qty']??0))<1)continue;$mode=strtoupper((string)($o['execution_mode']??''));if($executionMode!==''&&$mode!==''&&$mode!==$executionMode)continue;$rows[]=$o;}
    usort($rows,static function($a,$b){$ta=tb_ts($a['filled_at']??$a['last_fill_at']??$a['processed_at']??$a['created_at']??0);$tb=tb_ts($b['filled_at']??$b['last_fill_at']??$b['processed_at']??$b['created_at']??0);if($ta===$tb)return strcmp((string)($a['order_id']??''),(string)($b['order_id']??''));return$ta<=>$tb;});
    $lots=[];
    foreach($rows as$o){
        $market=strtoupper((string)($o['market']??''));$symbol=strtoupper((string)($o['symbol']??''));$strategy=strtolower((string)($o['strategy_key']??''));$side=strtoupper((string)($o['side']??''));$filled=max(0,(int)($o['filled_qty']??0));$price=max(0.0,(float)($o['avg_fill_price']??$o['price']??0));
        if(!in_array($market,['KR','US','JP'],true)||$symbol===''||$strategy===''||$filled<1||$price<=0||!in_array($side,['BUY','SELL'],true))continue;
        $key=$strategy.':'.$market.':'.$symbol;$lot=is_array($lots[$key]??null)?$lots[$key]:['strategy'=>$strategy,'market'=>$market,'symbol'=>$symbol,'qty'=>0,'avg_cost'=>0.0,'mark_price'=>$price,'name'=>(string)($o['name']??$symbol),'risk_group'=>'','asset_type'=>(string)($o['asset_type']??''),'is_inverse'=>!empty($o['is_inverse']),'is_leveraged'=>!empty($o['is_leveraged'])];
        if($side==='BUY'){$oldQty=(int)$lot['qty'];$newQty=$oldQty+$filled;$lot['avg_cost']=$newQty>0?(($oldQty*(float)$lot['avg_cost'])+($filled*$price))/$newQty:0.0;$lot['qty']=$newQty;}else{$lot['qty']=max(0,(int)$lot['qty']-$filled);if((int)$lot['qty']===0)$lot['avg_cost']=0.0;}
        $mark=tb_cached_quote($c,$market,$symbol);$lot['mark_price']=!empty($mark['ok'])?(float)$mark['price']:$price;$lot['mark_source']=!empty($mark['ok'])?'CURRENT_QUOTE_CACHE':'LAST_FILL_FALLBACK';$lot['name']=(string)($o['name']??$lot['name']);$lot['asset_type']=(string)($o['asset_type']??$lot['asset_type']);$lot['is_inverse']=array_key_exists('is_inverse',$o)?(bool)$o['is_inverse']:(bool)$lot['is_inverse'];$lot['is_leveraged']=array_key_exists('is_leveraged',$o)?(bool)$o['is_leveraged']:(bool)$lot['is_leveraged'];$group=trim((string)($o['risk_group']??''));if($group!=='')$lot['risk_group']=$group;$lots[$key]=$lot;
    }
    $open=[];foreach($lots as$lot){if((int)$lot['qty']<1)continue;if((string)$lot['risk_group']==='')$lot['risk_group']=tb_infer_risk_group((string)$lot['market'],(string)$lot['symbol'],(string)$lot['name'],(string)$lot['asset_type'],!empty($lot['is_inverse']),!empty($lot['is_leveraged']));$lot['amount']=round((int)$lot['qty']*(float)$lot['mark_price'],4);$open[]=$lot;}
    return$open;
}

function tb_existing_position_map(array $c,array $orders,string $executionMode=''): array
{
    $net=[];$amount=[];$marketAmount=['KR'=>0.0,'US'=>0.0,'JP'=>0.0];$groupAmount=[];$correlationPositions=[];$correlationByStrategy=[];$activeStrategies=[];$strategyQty=[];
    foreach(tb_open_position_book($c,$orders,$executionMode)as$p){$market=(string)$p['market'];$symbol=(string)$p['symbol'];$strategy=(string)$p['strategy'];$key=$market.':'.$symbol;$sk=$strategy.':'.$market.':'.$symbol;$qty=(int)$p['qty'];$value=(float)$p['amount'];$net[$key]=(int)($net[$key]??0)+$qty;$amount[$key]=(float)($amount[$key]??0)+$value;$marketAmount[$market]=(float)($marketAmount[$market]??0)+$value;$activeStrategies[$key][$strategy]=true;$strategyQty[$sk]=$qty;$group=(string)($p['risk_group']??'');if($group!=='')$groupAmount[$market.':'.$group]=(float)($groupAmount[$market.':'.$group]??0)+$value;$corr=tb_correlation_group($group,$market,$symbol,(string)($p['name']??$symbol),(string)($p['asset_type']??''),!empty($p['is_inverse']),!empty($p['is_leveraged']));if(tb_correlation_group_limited($corr)){$positionKey=$strategy.':'.$market.':'.$symbol;$correlationPositions[$corr][$positionKey]=true;$correlationByStrategy[$strategy.':'.$corr][$positionKey]=true;}}
    return['qty'=>$net,'amount'=>$amount,'market_amount'=>$marketAmount,'risk_group_amount'=>$groupAmount,'correlation_positions'=>$correlationPositions,'correlation_by_strategy'=>$correlationByStrategy,'strategies'=>$activeStrategies,'strategy_qty'=>$strategyQty,'basis'=>'OPEN_QTY_X_CURRENT_MARK_WITH_LAST_FILL_FALLBACK'];
}

function tb_name_is_inverse(string $name): bool{$u=strtoupper($name);return strpos($u,'인버스')!==false||strpos($u,'INVERSE')!==false||strpos($u,'BEAR')!==false||strpos($u,'SHORT')!==false;}
function tb_name_is_leveraged(string $name): bool{$u=strtoupper($name);return strpos($u,'레버리지')!==false||strpos($u,'2X')!==false||strpos($u,'3X')!==false||strpos($u,'ULTRA')!==false;}
function tb_infer_risk_group(string $market,string $symbol,string $name,string $assetType,bool $inverse=false,bool $leveraged=false): string{$u=strtoupper(preg_replace('/\s+/u','',$name));$assetType=strtoupper($assetType);if($inverse)return'INVERSE_'.$market;if($leveraged)return'LEVERAGED_'.$market;if($assetType==='ETF'||strpos($u,'KODEX')!==false||strpos($u,'TIGER')!==false||strpos($u,'ACE')!==false){if(strpos($u,'S&P500')!==false||strpos($u,'SP500')!==false)return'INDEX_US_SP500';if(strpos($u,'나스닥100')!==false||strpos($u,'NASDAQ100')!==false)return'INDEX_US_NASDAQ100';if(strpos($u,'미국배당')!==false||strpos($u,'다우존스')!==false||strpos($u,'DOWJONES')!==false)return'INDEX_US_DIVIDEND';if(strpos($u,'코스닥150')!==false||strpos($u,'KOSDAQ150')!==false)return'INDEX_KR_KOSDAQ150';if(strpos($u,'200')!==false&&$market==='KR')return'INDEX_KR_KOSPI200';if(strpos($u,'금')!==false||strpos($u,'GOLD')!==false)return'COMMODITY_GOLD';return'ETF_'.$market.'_'.$symbol;}return'STOCK_'.$market.'_'.$symbol;}
function tb_correlation_group(string $riskGroup,string $market='',string $symbol='',string $name='',string $assetType='',bool $inverse=false,bool $leveraged=false): string
{
    $riskGroup=strtoupper(trim($riskGroup));$market=strtoupper($market);if($riskGroup==='')$riskGroup=tb_infer_risk_group($market,$symbol,$name,$assetType,$inverse,$leveraged);
    if(strpos($riskGroup,'INDEX_US_')===0)return'THEME_US_EQUITY_INDEX';if(strpos($riskGroup,'INDEX_KR_')===0)return'THEME_KR_EQUITY_INDEX';if(strpos($riskGroup,'INDEX_JP_')===0)return'THEME_JP_EQUITY_INDEX';if(strpos($riskGroup,'INVERSE_')===0)return'THEME_'.$riskGroup;if(strpos($riskGroup,'LEVERAGED_')===0)return'THEME_'.$riskGroup;if(strpos($riskGroup,'COMMODITY_')===0)return$riskGroup;return$riskGroup;
}
function tb_correlation_group_limited(string $group): bool{$group=strtoupper(trim($group));return strpos($group,'THEME_')===0||strpos($group,'COMMODITY_')===0;}

function tb_is_stop_reason(string $reason): bool{return(bool)preg_match('/STOP|LOSS|NO_PROGRESS|BROKEN|BOUNCE_FAILED|INITIAL_STOP|손절/i',$reason);}

function tb_market_gap_limit(array $c,string $market): float{$m=strtoupper($market);return$m==='KR'?(float)$c['max_gap_kr']:($m==='JP'?(float)$c['max_gap_jp']:(float)$c['max_gap_us']);}
function tb_apply_exposure_limits(array $c): bool{return $c['mode']==='real'||!empty($c['apply_exposure_paper']);}

function tb_strategy_status_for_key(string $key): string
{
    $key=strtolower(trim($key));
    if(in_array($key,['dts','abc','das'],true))return 'CORE';
    if($key==='stc26')return 'CHALLENGER';
    return 'UNKNOWN';
}
function tb_current_market_equity(array $c,string $market,string $strategyStatus='CORE',string $strategyKey=''): float
{
    $market=strtoupper($market);$strategyStatus=strtoupper(trim($strategyStatus));$strategyKey=strtolower(trim($strategyKey));
    $seed=$market==='KR'?(float)$c['seed_kr']:($market==='JP'?(float)$c['seed_jp']:(float)$c['seed_us']);
    if($strategyStatus==='CHALLENGER'){
        $key=$strategyKey!==''?$strategyKey:'stc26';$runtime=(string)($c['strategy_runtime_map'][$key]??(__DIR__.'/'.$key.'_runtime'));$cap=tb_load($runtime.'/capital.json',[]);$row=is_array($cap[$market]??null)?$cap[$market]:(is_array($cap['markets'][$market]??null)?$cap['markets'][$market]:[]);$eq=(float)($row['equity']??0);return$eq>0?$eq:$seed;
    }
    // CORE strategies share one market capital pool. Never sum the same shared equity three times.
    $bestEq=0.0;$bestTs=0;
    foreach(['dts','abc','das'] as$key){
        if(!isset($c['strategy_registry'][$key]))continue;$runtime=(string)($c['strategy_runtime_map'][$key]??(__DIR__.'/'.$key.'_runtime'));$cap=tb_load($runtime.'/capital.json',[]);$row=is_array($cap[$market]??null)?$cap[$market]:(is_array($cap['markets'][$market]??null)?$cap['markets'][$market]:[]);$eq=(float)($row['equity']??0);if($eq<=0)continue;
        $st=tb_load($runtime.'/state.json',[]);$ts=tb_ts($st['last_tick']??$st['updated_at']??0);if($bestEq<=0||$ts>$bestTs){$bestEq=$eq;$bestTs=$ts;}
    }
    return$bestEq>0?$bestEq:$seed;
}
function tb_exposure_limits(array $c,string $market,string $strategyStatus='CORE',string $strategyKey=''): array
{
    $equity=tb_current_market_equity($c,$market,$strategyStatus,$strategyKey);$absolute=$market==='KR'?(float)$c['max_symbol_total_kr']:($market==='JP'?(float)$c['max_symbol_total_jp']:(float)$c['max_symbol_total_us']);$pctLimit=$equity*(float)$c['max_symbol_invest_pct'];$symbolAmount=$absolute>0?min($absolute,$pctLimit):$pctLimit;
    $basis=strtoupper($strategyStatus)==='CHALLENGER'?'CHALLENGER_SHADOW_EQUITY':'CORE_SHARED_CURRENT_EQUITY';
    return['aggregate_seed'=>$equity,'equity_basis'=>$basis,'symbol_amount'=>$symbolAmount,'market_amount'=>$equity*(float)$c['max_market_invest_pct'],'risk_group_amount'=>$equity*(float)$c['max_risk_group_invest_pct'],'pending_total'=>$market==='KR'?(float)$c['max_pending_total_kr']:($market==='JP'?(float)$c['max_pending_total_jp']:(float)$c['max_pending_total_us']),'strategy_count'=>(int)$c['max_symbol_strategy_count']];
}

function tb_validate_all(array $c,array $orders): array
{
    $by=[];$warnings=[];$positions=tb_existing_position_map($c,$orders,strtoupper((string)$c['mode']));
    $symbolActiveAmount=[];$symbolActiveStrategies=[];$marketPending=['KR'=>0.0,'US'=>0.0,'JP'=>0.0];$marketActiveAmount=['KR'=>0.0,'US'=>0.0,'JP'=>0.0];$groupActiveAmount=[];$correlationActive=[];$correlationActiveByStrategy=[];$sellReserved=[];
    $recentExpired=[];$recentScenario=[];$recentStop=[];$owners=tb_active_owner_maps($orders);

    foreach($orders as$history){
        if(!is_array($history))continue;
        $st=strtoupper((string)($history['status']??''));$closed=tb_ts($history['filled_at']??$history['processed_at']??$history['closed_at']??$history['created_at']??0);
        $strategyKey=strtolower((string)($history['strategy_key']??''));$historyMarket=strtoupper((string)($history['market']??''));$historySymbol=strtoupper((string)($history['symbol']??''));$historySide=strtoupper((string)($history['side']??''));$historyKey=$strategyKey.':'.$historyMarket.':'.$historySymbol.':'.$historySide;
        $jpQuoteTechnical=tb_is_jp_quote_terminal($history);
        if(!$jpQuoteTechnical&&$st==='EXPIRED'&&$closed>0)$recentExpired[$historyKey]=max((int)($recentExpired[$historyKey]??0),$closed);
        $scenarioKey=(string)($history['scenario_key']??'');
        if(!$jpQuoteTechnical&&$scenarioKey!==''&&$closed>0&&in_array($st,['EXPIRED','CANCELLED','REJECTED','FILLED','PAPER_FILLED'],true))$recentScenario[$scenarioKey]=max((int)($recentScenario[$scenarioKey]??0),$closed);
        if(in_array($st,['FILLED','PAPER_FILLED'],true)&&$historySide==='SELL'&&$closed>0&&tb_is_stop_reason((string)($history['reason']??$history['signal_type']??''))){
            $stopKey=$strategyKey.':'.$historyMarket.':'.$historySymbol;$recentStop[$stopKey]=max((int)($recentStop[$stopKey]??0),$closed);
        }
    }

    // Submitted orders are commitments, not fresh candidates. Reserve only their
    // unfilled quantity here so later PENDING/APPROVED orders cannot overbook it.
    foreach($orders as$o){
        if(!is_array($o))continue;$status=strtoupper((string)($o['status']??''));
        if(!tb_order_submitted_status($status))continue;
        $market=strtoupper((string)($o['market']??''));$side=strtoupper((string)($o['side']??''));$symbol=strtoupper(trim((string)($o['symbol']??'')));$strategyKey=strtolower((string)($o['strategy_key']??''));
        if(!in_array($market,['KR','US','JP'],true)||$symbol===''||!in_array($side,['BUY','SELL'],true))continue;
        $remaining=max(0,(int)($o['qty']??0)-(int)($o['filled_qty']??0));if($remaining<1)continue;
        if($side==='SELL'){$key=$strategyKey.':'.$market.':'.$symbol;$sellReserved[$key]=($sellReserved[$key]??0)+$remaining;continue;}
        $price=max(0.0,(float)($o['price']??0));$remainingAmount=$remaining*$price;$symbolKey=$market.':'.$symbol;
        $symbolActiveAmount[$symbolKey]=($symbolActiveAmount[$symbolKey]??0.0)+$remainingAmount;$symbolActiveStrategies[$symbolKey][$strategyKey]=true;$marketPending[$market]+=$remainingAmount;$marketActiveAmount[$market]+=$remainingAmount;
        $riskGroup=trim((string)($o['risk_group']??''));
        if($riskGroup==='')$riskGroup=tb_infer_risk_group($market,$symbol,(string)($o['name']??$symbol),(string)($o['asset_type']??''),array_key_exists('is_inverse',$o)?(bool)$o['is_inverse']:tb_name_is_inverse((string)($o['name']??'')),array_key_exists('is_leveraged',$o)?(bool)$o['is_leveraged']:tb_name_is_leveraged((string)($o['name']??'')));
        if($riskGroup!=='')$groupActiveAmount[$market.':'.$riskGroup]=($groupActiveAmount[$market.':'.$riskGroup]??0.0)+$remainingAmount;$corr=tb_correlation_group($riskGroup,$market,$symbol,(string)($o['name']??$symbol),(string)($o['asset_type']??''),!empty($o['is_inverse']),!empty($o['is_leveraged']));if(tb_correlation_group_limited($corr)){$positionKey=$strategyKey.':'.$market.':'.$symbol;$correlationActive[$corr][$positionKey]=true;$correlationActiveByStrategy[$strategyKey.':'.$corr][$positionKey]=true;}
    }

    foreach($orders as$o){
        if(!is_array($o))continue;
        $id=(string)($o['order_id']??'NO_ID');$bad=[];$status=strtoupper((string)($o['status']??''));
        if(!tb_order_active_status($status))continue;
        $preSubmit=tb_order_pre_submit_status($status);$market=strtoupper((string)($o['market']??''));$side=strtoupper((string)($o['side']??''));$price=(float)($o['price']??0);$qty=(int)($o['qty']??0);$amount=(float)($o['amount']??0);$symbol=strtoupper(trim((string)($o['symbol']??'')));$strategyKey=strtolower((string)($o['strategy_key']??''));if($side==='BUY'&&tb_buy_expired($o))$bad[]='주문 의도 만료';

        if($id===''||$id==='NO_ID')$bad[]='order_id 없음';
        if(!in_array((string)($o['intent_schema']??''),[TB_SCHEMA,'te_v24','te_v23','te_v22'],true)||empty($o['intent_verified']))$bad[]='엔진 의도 계약 검증 실패';
        $integrity=tb_order_integrity($o);if(empty($integrity['ok']))$bad[]='주문 의도 변조·손상 '.$integrity['reason'];
        if(!tb_strategy_allowed($c,$o))$bad[]='전략키·파일 조합 불일치';
        $strategyStatus=strtoupper(trim((string)($o['strategy_status']??'')));$contractRev=(string)($o['intent_contract_rev']??'');$accountMode=strtoupper(trim((string)($o['account_mode']??'')));
        if($contractRev==='intent_v5'&&!in_array($strategyStatus,['CORE','CHALLENGER'],true))$bad[]='strategy_status 오류';
        if($side==='BUY'&&$strategyStatus==='CHALLENGER'&&strtolower((string)$c['mode'])==='real')$bad[]='CHALLENGER_REAL_DENIED';
        if($contractRev==='intent_v5'&&$strategyStatus==='CHALLENGER'&&$accountMode!=='CHALLENGER_PAPER')$bad[]='CHALLENGER_ACCOUNT_MODE_INVALID';
        if(!in_array($market,['KR','US','JP'],true))$bad[]='market 오류';
        if(!in_array($side,['BUY','SELL'],true))$bad[]='side 오류';
        if(strtoupper((string)($o['order_type']??''))!=='LIMIT')$bad[]='LIMIT만 허용';
        if($price<=0||$qty<1||$amount<=0)$bad[]='가격·수량·금액 오류';
        $expectedAmount=round($price*$qty,4);if($price>0&&$qty>0&&abs($amount-$expectedAmount)>max(0.01,$expectedAmount*0.000001))$bad[]='금액 불일치';
        if($market==='KR'&&!preg_match('/^\d{6}$/',$symbol))$bad[]='KR 종목코드 오류';
        if($market==='US'&&!preg_match('/^[A-Z0-9.\-^]{1,15}$/',$symbol))$bad[]='US 종목코드 오류';
        if($market==='JP'&&!preg_match('/^[0-9A-Z]{4,6}$/',$symbol))$bad[]='JP 종목코드 오류';
        if(in_array($market,['US','JP'],true)&&tb_exchange($c,$o)==='')$bad[]=$market.' 거래소 코드 없음';

        $identity=$strategyKey.':'.$market.':'.$symbol.':'.$side;
        if(isset($owners['identity'][$identity])&&$owners['identity'][$identity]!==$id)$bad[]='활성 중복 주문 '.$owners['identity'][$identity];
        $scenarioId=trim((string)($o['scenario_id']??''));
        if($scenarioId!==''&&$side==='BUY'){$scenarioIdentity=$strategyKey.':'.$scenarioId;if(isset($owners['scenario'][$scenarioIdentity])&&$owners['scenario'][$scenarioIdentity]!==$id)$bad[]='scenario 중복 '.$owners['scenario'][$scenarioIdentity];}

        if($preSubmit){
            if($side==='BUY'&&$amount>($market==='KR'?$c['max_order_kr']:($market==='JP'?$c['max_order_jp']:$c['max_order_us'])))$bad[]='1회 매입 한도 초과';
            $exp=strtotime((string)($o['order_expires_at']??$o['expires_at']??''));if($side!=='SELL'&&$exp===false)$bad[]='주문 의도 만료시각 없음';
            $quoteTs=tb_ts($o['quote_timestamp']??0);$created=tb_ts($o['created_at']??0);$orderAge=$created>0?max(0,time()-$created):PHP_INT_MAX;$decisionQuoteAge=max(0,(int)($o['quote_age_sec']??($created>0&&$quoteTs>0?$created-$quoteTs:PHP_INT_MAX)));$freshness=strtoupper((string)($o['quote_freshness']??'UNKNOWN'));$analysisLimit=tb_analysis_quote_age_limit($c,$market);$freshLimit=tb_fresh_quote_age_limit($c,$market);$requiresLive=!empty($o['requires_live_requote']);
            $maxSignalAge=$side==='SELL'?(int)$c['max_signal_age_sell']:(int)$c['max_signal_age'];if($created<=0||$orderAge>$maxSignalAge)$bad[]='신호 생성시각 초과';
            if($side!=='SELL'){
                if($quoteTs<=0)$bad[]='시세 timestamp 없음';if($quoteTs>time()+60)$bad[]='시세 timestamp 미래값';if($decisionQuoteAge>$analysisLimit)$bad[]='분석 시세 지연 한도 초과 '.$decisionQuoteAge.'>'.$analysisLimit;if($freshness==='DELAYED'&&!$requiresLive)$bad[]='지연시세 실시간 재조회 표시 누락';if($freshness==='FRESH'&&$decisionQuoteAge>$freshLimit)$bad[]='시세상태와 지연시간 불일치';if(!in_array($freshness,['FRESH','DELAYED','CLOSED_FRESH'],true))$bad[]='시세상태 오류';
                $priceSource=strtoupper((string)($o['price_source']??''));$marketUpper=strtoupper($market);$sources=is_array($o['data_sources']??null)?$o['data_sources']:[];if(!$sources){if((string)($o['daily_source']??'')!=='')$sources['1d']=$o['daily_source'];if((string)($o['m60_source']??'')!=='')$sources['60m']=$o['m60_source'];if((string)($o['m30_source']??'')!=='')$sources['30m']=$o['m30_source'];}
                $sourceFamily=strpos($priceSource,'NXT')!==false?$marketUpper.'_NXT':$priceSource;if($sourceFamily==='')$bad[]='현재가 출처 오류';foreach($sources as$tf=>$src){$srcUpper=strtoupper((string)$src);$srcFamily=strpos($srcUpper,'NXT')!==false?$marketUpper.'_NXT':$srcUpper;if($srcFamily===''||$srcFamily!==$sourceFamily)$bad[]='데이터 출처 불일치 '.$tf;}
                $required=is_array($o['required_timeframes']??null)?$o['required_timeframes']:[];foreach($required as$tf)if(!array_key_exists((string)$tf,$sources))$bad[]='필수 데이터 출처 누락 '.$tf;
            }

            if($side==='BUY'){
                $type=trim((string)($o['signal_type']??''));if($type==='')$bad[]='BUY 유형 없음';$stop=(float)($o['stop_price']??0);$target=(float)($o['target_price']??0);$sizing=strtoupper((string)($o['sizing_mode']??'RISK_STOP'));if($sizing==='RISK_STOP'&&($stop<=0||$stop>=$price))$bad[]='손절 구조 오류';if($target>0&&$target<=$price)$bad[]='목표가 구조 오류';
                $orderName=(string)($o['name']??$symbol);$orderInverse=array_key_exists('is_inverse',$o)?(bool)$o['is_inverse']:tb_name_is_inverse($orderName);$orderLeveraged=array_key_exists('is_leveraged',$o)?(bool)$o['is_leveraged']:tb_name_is_leveraged($orderName);if(!empty($c['block_inverse'])&&$orderInverse)$bad[]='인버스 신규매입 차단';if(!empty($c['block_leveraged'])&&$orderLeveraged)$bad[]='레버리지 신규매입 차단';
                $historyKey=$strategyKey.':'.$market.':'.$symbol.':BUY';$expiredAt=(int)($recentExpired[$historyKey]??0);if($expiredAt>0&&$created>$expiredAt&&$created-$expiredAt<(int)$c['expired_cooldown_sec'])$bad[]='만료 후 재신호 대기시간 미준수';$scenarioKey=(string)($o['scenario_key']??'');$scenarioAt=$scenarioKey!==''?(int)($recentScenario[$scenarioKey]??0):0;if($scenarioAt>0&&$created>$scenarioAt&&$created-$scenarioAt<(int)$c['scenario_cooldown_sec'])$bad[]='동일 시나리오 재발행 대기시간 미준수';$stopAt=(int)($recentStop[$strategyKey.':'.$market.':'.$symbol]??0);if($stopAt>0&&$created>$stopAt&&$created-$stopAt<(int)$c['stop_reentry_cooldown_sec'])$bad[]='손절 후 재진입 대기시간 미준수';

                if(in_array($market,['KR','US','JP'],true)&&tb_apply_exposure_limits($c)){
                    $symbolKey=$market.':'.$symbol;$remaining=max(0,$qty-(int)($o['filled_qty']??0));$remainingAmount=$remaining*$price;$limits=tb_exposure_limits($c,$market,$strategyStatus,$strategyKey);$baseAmount=max(0.0,(float)($positions['amount'][$symbolKey]??0));$projected=$baseAmount+($symbolActiveAmount[$symbolKey]??0.0)+$remainingAmount;
                    $existingStrategies=is_array($positions['strategies'][$symbolKey]??null)?$positions['strategies'][$symbolKey]:[];$activeStrategies=is_array($symbolActiveStrategies[$symbolKey]??null)?$symbolActiveStrategies[$symbolKey]:[];$strategySet=$existingStrategies+$activeStrategies;
                    if($strategyKey!==''&&!isset($strategySet[$strategyKey])&&count($strategySet)>=$limits['strategy_count'])$bad[]='동일 종목 전략 수 한도 초과';if($limits['symbol_amount']>0&&$projected>$limits['symbol_amount'])$bad[]='동일 종목 총 노출 한도 초과';
                    $marketProjected=max(0.0,(float)($positions['market_amount'][$market]??0))+($marketActiveAmount[$market]??0.0)+$remainingAmount;if($limits['market_amount']>0&&$marketProjected>$limits['market_amount'])$bad[]='시장 총 투자비중 한도 초과';
                    $riskGroup=trim((string)($o['risk_group']??''));if($riskGroup==='')$riskGroup=tb_infer_risk_group($market,$symbol,(string)($o['name']??$symbol),(string)($o['asset_type']??''),array_key_exists('is_inverse',$o)?(bool)$o['is_inverse']:tb_name_is_inverse((string)($o['name']??'')),array_key_exists('is_leveraged',$o)?(bool)$o['is_leveraged']:tb_name_is_leveraged((string)($o['name']??'')));
                    if($riskGroup!==''){$groupKey=$market.':'.$riskGroup;$groupProjected=max(0.0,(float)($positions['risk_group_amount'][$groupKey]??0))+($groupActiveAmount[$groupKey]??0.0)+$remainingAmount;if($limits['risk_group_amount']>0&&$groupProjected>$limits['risk_group_amount'])$bad[]='동일 위험그룹 총 노출 한도 초과 '.$riskGroup;}
                    $correlationGroup=tb_correlation_group($riskGroup,$market,$symbol,(string)($o['name']??$symbol),(string)($o['asset_type']??''),$orderInverse,$orderLeveraged);if(tb_correlation_group_limited($correlationGroup)){$positionKey=$strategyKey.':'.$market.':'.$symbol;$globalSet=(is_array($positions['correlation_positions'][$correlationGroup]??null)?$positions['correlation_positions'][$correlationGroup]:[])+(is_array($correlationActive[$correlationGroup]??null)?$correlationActive[$correlationGroup]:[]);$strategyMapKey=$strategyKey.':'.$correlationGroup;$strategySet=(is_array($positions['correlation_by_strategy'][$strategyMapKey]??null)?$positions['correlation_by_strategy'][$strategyMapKey]:[])+(is_array($correlationActiveByStrategy[$strategyMapKey]??null)?$correlationActiveByStrategy[$strategyMapKey]:[]);unset($globalSet[$positionKey],$strategySet[$positionKey]);$strategyLimit=tb_strategy_correlation_limit($c,$strategyKey);$globalLimit=(int)$c['max_correlation_positions_global'];if(count($strategySet)>=$strategyLimit)$bad[]='상관그룹 전략 내 포지션 한도 초과 '.$correlationGroup;if(count($globalSet)>=$globalLimit)$bad[]='상관그룹 전체 포지션 한도 초과 '.$correlationGroup;}
                    if($limits['pending_total']>0&&($marketPending[$market]+$remainingAmount)>$limits['pending_total'])$bad[]='시장 전체 미체결 한도 초과';
                    if(!$bad){$symbolActiveAmount[$symbolKey]=($symbolActiveAmount[$symbolKey]??0.0)+$remainingAmount;$symbolActiveStrategies[$symbolKey][$strategyKey]=true;$marketPending[$market]+=$remainingAmount;$marketActiveAmount[$market]+=$remainingAmount;if($riskGroup!=='')$groupActiveAmount[$market.':'.$riskGroup]=($groupActiveAmount[$market.':'.$riskGroup]??0.0)+$remainingAmount;if(tb_correlation_group_limited($correlationGroup)){$positionKey=$strategyKey.':'.$market.':'.$symbol;$correlationActive[$correlationGroup][$positionKey]=true;$correlationActiveByStrategy[$strategyKey.':'.$correlationGroup][$positionKey]=true;}}
                }
            }elseif($side==='SELL'&&in_array($market,['KR','US','JP'],true)){
                $positionKey=$strategyKey.':'.$market.':'.$symbol;$available=max(0,(int)($positions['strategy_qty'][$positionKey]??0));$remaining=max(0,$qty-(int)($o['filled_qty']??0));$already=max(0,(int)($sellReserved[$positionKey]??0));if($remaining+$already>$available)$bad[]='전략 보유수량 초과 매도 '.($remaining+$already).'>'.$available;if(!$bad)$sellReserved[$positionKey]=$already+$remaining;
            }
        }

        if($bad){$by[$id]=array_values(array_unique($bad));$warnings[]=$id.': '.implode(', ',$by[$id]);}
    }
    return['invalid'=>count($by),'by_id'=>$by,'warnings'=>$warnings,'exposure_basis'=>(string)($positions['basis']??'')];
}
function tb_strategy_allowed(array $c,array $o): bool
{
    $key=strtolower(trim((string)($o['strategy_key']??'')));$file=strtolower(basename((string)($o['strategy_file_id']??'')));$registry=is_array($c['strategy_registry']??null)?$c['strategy_registry']:[];
    if($key===''||$file===''||!isset($registry[$key])||!in_array($key,$c['allowed_keys'],true))return false;
    $valid=[strtolower((string)$registry[$key]),$key.'.php'];
    return in_array($file,$valid,true)&&in_array($file,$c['allowed_files'],true);
}

function tb_real_allowed(array $c,array $o): array
{
    if($c['mode']!=='real')return['ok'=>false,'message'=>'BROKER_MODE real 아님'];
    if(!$c['auto'])return['ok'=>false,'message'=>'AUTO_TRADE_ENABLED OFF'];
    $side=strtoupper((string)($o['side']??''));$strategyStatus=strtoupper(trim((string)($o['strategy_status']??'')));
    if($side==='BUY'&&$strategyStatus==='CHALLENGER')return['ok'=>false,'message'=>'CHALLENGER_REAL_DENIED'];
    if($side==='BUY'&&!$c['allow_buy'])return['ok'=>false,'message'=>'ALLOW_BUY OFF'];
    if($side==='SELL'&&!$c['allow_sell'])return['ok'=>false,'message'=>'ALLOW_SELL OFF'];
    foreach(['base_url','app_key','app_secret','cano','product']as$k)if((string)$c[$k]==='')return['ok'=>false,'message'=>'KIS 설정 누락 '.$k];
    return['ok'=>true,'message'=>'OK'];
}

function tb_real_sell_qty_check(array $c,array $o,array $orders=[]): array
{
    $account=tb_kis_account_raw($c);
    if(empty($account['ok'])||!is_array($account['snapshot']??null))return['ok'=>false,'message'=>'실계좌 잔고조회 실패'];
    tb_save($c['account_file'],$account['snapshot']);
    $market=strtoupper((string)($o['market']??''));$symbol=strtoupper((string)($o['symbol']??''));$need=max(1,(int)($o['qty']??0));
    $available=tb_account_qty($account['snapshot'],$market,$symbol);$reserved=tb_active_reserved_qty($c,$o,'SELL',$orders);$free=max(0,$available-$reserved);
    if($free<$need)return['ok'=>false,'message'=>'실계좌 매도가능수량 부족 '.$free.'<'.$need.' (다른 주문 예약 '.$reserved.')'];
    return['ok'=>true,'message'=>'OK','available_qty'=>$free];
}

function tb_real_buy_cash_check(array $c,array $o,array $orders=[]): array
{
    $account=tb_kis_account_raw($c);if(empty($account['ok'])||!is_array($account['snapshot']??null))return['ok'=>false,'message'=>'실계좌 예수금조회 실패'];tb_save($c['account_file'],$account['snapshot']);
    $market=strtoupper((string)($o['market']??''));$cash=(float)($account['snapshot']['markets'][$market]['cash']??0);$reserved=tb_active_reserved_amount($c,$o,'BUY',$orders);$need=(float)($o['amount']??((float)($o['price']??0)*(int)($o['qty']??0)));$free=max(0.0,$cash-$reserved);
    if($free+1.0E-8<$need)return['ok'=>false,'message'=>'실계좌 주문가능현금 부족 '.round($free,4).'<'.round($need,4).' (다른 주문 예약 '.round($reserved,4).')'];return['ok'=>true,'message'=>'OK','available_cash'=>$free];
}

function tb_active_reserved_qty(array $c,array $current,string $side,array $orders=[]): int
{
    $sum=0;$id=(string)($current['order_id']??'');$market=strtoupper((string)($current['market']??''));$symbol=strtoupper((string)($current['symbol']??''));
    $rows=$orders?:tb_orders($c);foreach($rows as$o){if(!is_array($o)||(string)($o['order_id']??'')===$id||strtoupper((string)($o['side']??''))!==$side||strtoupper((string)($o['market']??''))!==$market||strtoupper((string)($o['symbol']??''))!==$symbol)continue;if(!in_array(strtoupper((string)($o['status']??'')),['SENT','PARTIAL','WORKING','CANCEL_REQUESTED'],true))continue;$sum+=max(0,(int)($o['qty']??0)-(int)($o['filled_qty']??0));}
    return$sum;
}
function tb_active_reserved_amount(array $c,array $current,string $side,array $orders=[]): float
{
    $sum=0.0;$id=(string)($current['order_id']??'');$market=strtoupper((string)($current['market']??''));
    $rows=$orders?:tb_orders($c);foreach($rows as$o){if(!is_array($o)||(string)($o['order_id']??'')===$id||strtoupper((string)($o['side']??''))!==$side||strtoupper((string)($o['market']??''))!==$market)continue;if(!in_array(strtoupper((string)($o['status']??'')),['SENT','PARTIAL','WORKING','CANCEL_REQUESTED'],true))continue;$remain=max(0,(int)($o['qty']??0)-(int)($o['filled_qty']??0));$sum+=$remain*(float)($o['price']??0);}
    return$sum;
}

function tb_account_qty(array $snapshot,string $market,string $symbol): int
{
    $rows=$snapshot['markets'][$market]['holdings']??[];if(!is_array($rows))return 0;
    foreach($rows as$h){if(!is_array($h))continue;if(strtoupper((string)($h['symbol']??''))===strtoupper($symbol))return max(0,(int)($h['available_qty']??$h['qty']??0));}
    return 0;
}
function tb_reject(array $o,string $reason,string $mode): array{$o['status']='REJECTED';$o['approved']=false;$o['reject_reason']=$reason;$o['execution_mode']=$mode;$o['processed_at']=tb_now();return$o;}

function tb_fresh_quote_age_limit(array $c,string $market): int
{
    $m=strtoupper($market);return$m==='KR'?(int)$c['fresh_quote_age_kr']:($m==='JP'?(int)$c['fresh_quote_age_jp']:(int)$c['fresh_quote_age_us']);
}
function tb_analysis_quote_age_limit(array $c,string $market): int
{
    $m=strtoupper($market);return$m==='KR'?(int)$c['analysis_quote_age_kr']:($m==='JP'?(int)$c['analysis_quote_age_jp']:(int)$c['analysis_quote_age_us']);
}
function tb_prepare_live_order(array $c,array $o,array $live): array
{
    $market=strtoupper((string)($o['market']??''));$side=strtoupper((string)($o['side']??''));$requested=(float)($o['requested_price']??$o['price']??0);$livePrice=(float)($live['price']??0);
    if($requested<=0||$livePrice<=0)return['ok'=>false,'message'=>'실시간 가격 오류'];
    $gap=abs(($livePrice/$requested-1.0)*100.0);$limit=tb_market_gap_limit($c,$market);if($side==='BUY'&&$gap>$limit)return['ok'=>false,'message'=>'주문가 대비 KIS 현재가 차이 '.round($gap,2).'% 초과'];
    $exec=$o;$exec['analysis_price']=$requested;$exec['live_quote_price']=$livePrice;$exec['live_quote_at']=tb_now();$exec['live_gap_pct']=round($gap,6);$exec['price']=$livePrice;$exec=tb_normalize_order_price($c,$exec);$exec['execution_price_rebased']=abs((float)$exec['price']-$requested)>1.0E-9;
    if($side==='BUY'){
        $stop=(float)($exec['stop_price']??0);$target=(float)($exec['target_price']??0);$price=(float)$exec['price'];$sizing=strtoupper((string)($exec['sizing_mode']??'RISK_STOP'));if($sizing==='RISK_STOP'&&($stop<=0||$stop>=$price))return['ok'=>false,'message'=>'실시간 가격 기준 손절 구조 오류'];if($target>0&&$target<=$price)return['ok'=>false,'message'=>'실시간 가격이 목표가 이상'];
        if($sizing==='RISK_STOP'){$origRisk=max(0.0,($requested-$stop)/$requested*100.0);$liveRisk=max(0.0,($price-$stop)/$price*100.0);$exec['original_risk_pct']=round($origRisk,6);$exec['live_risk_pct']=round($liveRisk,6);if($liveRisk>(float)$c['max_live_risk_pct'])return['ok'=>false,'message'=>'실시간 손절위험 '.round($liveRisk,2).'% 한도 초과'];if($liveRisk>$origRisk+(float)$c['max_live_risk_expansion_pct'])return['ok'=>false,'message'=>'실시간 손절위험 확대 '.round($liveRisk-$origRisk,2).'%p 초과'];}
        $max=$market==='KR'?(float)$c['max_order_kr']:($market==='JP'?(float)$c['max_order_jp']:(float)$c['max_order_us']);if((float)$exec['amount']>$max)return['ok'=>false,'message'=>'실시간 가격 재산정 후 1회 매입 한도 초과'];
    }
    return['ok'=>true,'message'=>'OK','order'=>$exec];
}

function tb_kis_order(array $c,array $o): array
{
    $tok=tb_token($c);if(empty($tok['ok']))return$tok;$market=strtoupper((string)$o['market']);$side=strtoupper((string)$o['side']);
    if($market==='KR'){$path='/uapi/domestic-stock/v1/trading/order-cash';$tr=$side==='BUY'?($c['mock']?'VTTC0802U':'TTTC0802U'):($c['mock']?'VTTC0801U':'TTTC0801U');$body=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'PDNO'=>(string)$o['symbol'],'ORD_DVSN'=>'00','ORD_QTY'=>(string)(int)$o['qty'],'ORD_UNPR'=>(string)(int)round((float)$o['price'])];}
    else{$path='/uapi/overseas-stock/v1/trading/order';if($market==='JP')$tr=$side==='BUY'?($c['mock']?'VTTS0308U':'TTTS0308U'):($c['mock']?'VTTS0307U':'TTTS0307U');else$tr=$side==='BUY'?($c['mock']?'VTTT1002U':'TTTT1002U'):($c['mock']?'VTTT1006U':'TTTT1006U');$body=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'OVRS_EXCG_CD'=>tb_exchange($c,$o),'PDNO'=>(string)$o['symbol'],'ORD_QTY'=>(string)(int)$o['qty'],'OVRS_ORD_UNPR'=>tb_price((float)$o['price'],$market),'CTAC_TLNO'=>'','MGCO_APTM_ODNO'=>'','SLL_TYPE'=>$side==='SELL'?'00':'','ORD_SVR_DVSN_CD'=>'0','ORD_DVSN'=>'00'];}
    $hash=tb_hashkey($c,$body,$tok['access_token']);$h=tb_headers($c,$tok['access_token'],$tr);if($hash!=='')$h[]='hashkey: '.$hash;$r=tb_request('POST',$c['base_url'].$path,$h,$body);$ok=!empty($r['ok'])&&(string)($r['json']['rt_cd']??'')==='0';$out=is_array($r['json']['output']??null)?$r['json']['output']:[];
    return['ok'=>$ok,'message'=>(string)($r['json']['msg1']??$r['error']??''),'order_no'=>(string)($out['ODNO']??$out['odno']??''),'org_no'=>(string)($out['KRX_FWDG_ORD_ORGNO']??$out['krx_fwdg_ord_orgno']??''),'http'=>$r['http'],'raw'=>$r['json']];
}
function tb_kis_quote(array $c,array $o): array
{
    $tok=tb_token($c);if(empty($tok['ok']))return$tok;$m=strtoupper((string)$o['market']);
    if($m==='KR'){$url=$c['base_url'].'/uapi/domestic-stock/v1/quotations/inquire-price?'.http_build_query(['FID_COND_MRKT_DIV_CODE'=>'J','FID_INPUT_ISCD'=>(string)$o['symbol']]);$r=tb_request('GET',$url,tb_headers($c,$tok['access_token'],'FHKST01010100'));$p=tb_num($r['json']['output']['stck_prpr']??0);}
    else{$ex=tb_quote_exchange(tb_exchange($c,$o));$url=$c['base_url'].'/uapi/overseas-price/v1/quotations/price?'.http_build_query(['AUTH'=>'','EXCD'=>$ex,'SYMB'=>(string)$o['symbol']]);$r=tb_request('GET',$url,tb_headers($c,$tok['access_token'],'HHDFS00000300'));$p=tb_num($r['json']['output']['last']??$r['json']['output']['base']??0);}
    return['ok'=>!empty($r['ok'])&&$p>0,'price'=>$p,'quoted_at'=>tb_now(),'source'=>'KIS','raw'=>$r['json']??[]];
}

function tb_kis_domestic_fills(array $c): array
{
    $tok=tb_token($c);if(empty($tok['ok']))return['ok'=>false,'rows'=>[]];$d=date('Ymd');$start=date('Ymd',time()-3*86400);$q=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'INQR_STRT_DT'=>$start,'INQR_END_DT'=>$d,'SLL_BUY_DVSN_CD'=>'00','INQR_DVSN'=>'00','PDNO'=>'','CCLD_DVSN'=>'00','ORD_GNO_BRNO'=>'','ODNO'=>'','INQR_DVSN_3'=>'00','INQR_DVSN_1'=>'','CTX_AREA_FK100'=>'','CTX_AREA_NK100'=>''];
    $r=tb_request('GET',$c['base_url'].'/uapi/domestic-stock/v1/trading/inquire-daily-ccld?'.http_build_query($q),tb_headers($c,$tok['access_token'],$c['mock']?'VTTC8001R':'TTTC8001R'));$out=[];foreach($r['json']['output1']??[] as $x){if(!is_array($x))continue;$out[]=['market'=>'KR','order_no'=>(string)($x['odno']??$x['ODNO']??''),'filled_qty'=>(int)tb_num($x['tot_ccld_qty']??$x['TOT_CCLD_QTY']??0),'avg_fill_price'=>tb_num($x['avg_prvs']??$x['avg_ccld_unpr']??0),'order_date'=>(string)($x['ord_dt']??$x['ORD_DT']??$d),'filled_at'=>tb_now()];}return['ok'=>!empty($r['ok']),'rows'=>$out];
}
function tb_kis_overseas_fills(array $c): array
{
    $tok=tb_token($c);if(empty($tok['ok']))return['ok'=>false,'rows'=>[]];$d=date('Ymd');$start=date('Ymd',time()-3*86400);$q=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'PDNO'=>'','ORD_STRT_DT'=>$start,'ORD_END_DT'=>$d,'SLL_BUY_DVSN'=>'00','CCLD_NCCS_DVSN'=>'00','OVRS_EXCG_CD'=>'','SORT_SQN'=>'DS','ORD_DT'=>'','ORD_GNO_BRNO'=>'','ODNO'=>'','CTX_AREA_NK200'=>'','CTX_AREA_FK200'=>''];
    $r=tb_request('GET',$c['base_url'].'/uapi/overseas-stock/v1/trading/inquire-ccnl?'.http_build_query($q),tb_headers($c,$tok['access_token'],$c['mock']?'VTTS3035R':'TTTS3035R'));$rowsRaw=$r['json']['output']??$r['json']['output1']??[];$out=[];
    foreach($rowsRaw as $x){if(!is_array($x))continue;$exchange=tb_norm_exchange((string)($x['ovrs_excg_cd']??$x['OVRS_EXCG_CD']??$x['excg_cd']??''));$market=$exchange==='TKSE'?'JP':'US';$out[]=['market'=>$market,'exchange'=>$exchange,'order_no'=>(string)($x['odno']??$x['ODNO']??''),'filled_qty'=>(int)tb_num($x['ft_ccld_qty']??$x['tot_ccld_qty']??0),'avg_fill_price'=>tb_num($x['ft_ccld_unpr3']??$x['avg_ccld_unpr']??0),'order_date'=>(string)($x['ord_dt']??$x['ORD_DT']??$d),'filled_at'=>tb_now()];}
    return['ok'=>!empty($r['ok']),'rows'=>$out];
}
function tb_kis_account_raw(array $c): array
{
    $tok=tb_token($c);if(empty($tok['ok']))return['ok'=>false,'message'=>'KIS 인증 실패'];
    $markets=[];$raw=[];
    $q=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'AFHR_FLPR_YN'=>'N','OFL_YN'=>'','INQR_DVSN'=>'02','UNPR_DVSN'=>'01','FUND_STTL_ICLD_YN'=>'N','FNCG_AMT_AUTO_RDPT_YN'=>'N','PRCS_DVSN'=>'01','CTX_AREA_FK100'=>'','CTX_AREA_NK100'=>''];
    $r=tb_request('GET',$c['base_url'].'/uapi/domestic-stock/v1/trading/inquire-balance?'.http_build_query($q),tb_headers($c,$tok['access_token'],$c['mock']?'VTTC8434R':'TTTC8434R'));
    $raw['KR']=$r['json']??[];$summary=is_array($r['json']['output2'][0]??null)?$r['json']['output2'][0]:[];$hold=[];
    foreach($r['json']['output1']??[] as$x){if(!is_array($x))continue;$symbol=preg_replace('/[^0-9]/','',(string)($x['pdno']??$x['PDNO']??''));if($symbol==='')continue;$symbol=str_pad(substr($symbol,-6),6,'0',STR_PAD_LEFT);$qty=(int)tb_num($x['hldg_qty']??$x['HLDG_QTY']??0);$available=(int)tb_num($x['ord_psbl_qty']??$x['ORD_PSBL_QTY']??$qty);if($qty<1&&$available<1)continue;$hold[]=['symbol'=>$symbol,'name'=>(string)($x['prdt_name']??$x['PRDT_NAME']??$symbol),'qty'=>$qty,'available_qty'=>$available,'avg_price'=>tb_num($x['pchs_avg_pric']??$x['PCHS_AVG_PRIC']??0),'current_price'=>tb_num($x['prpr']??$x['stck_prpr']??0)];}
    $cash=tb_num($summary['dnca_tot_amt']??$summary['DNCA_TOT_AMT']??0);$eq=tb_num($summary['tot_evlu_amt']??$summary['TOT_EVLU_AMT']??0);if(!empty($r['ok']))$markets['KR']=['currency'=>'KRW','cash'=>$cash,'equity'=>$eq,'holdings'=>$hold];

    $groups=['US'=>['exchanges'=>['NASD','NYSE','AMEX'],'currency'=>'USD'],'JP'=>['exchanges'=>['TKSE'],'currency'=>'JPY']];
    foreach($groups as$market=>$group){$holdingMap=[];$marketCash=0.0;$marketEquity=0.0;$anyOk=false;foreach($group['exchanges']as$exchange){$oq=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'OVRS_EXCG_CD'=>$exchange,'TR_CRCY_CD'=>$group['currency'],'CTX_AREA_FK200'=>'','CTX_AREA_NK200'=>''];$u=tb_request('GET',$c['base_url'].'/uapi/overseas-stock/v1/trading/inquire-balance?'.http_build_query($oq),tb_headers($c,$tok['access_token'],$c['mock']?'VTTS3012R':'TTTS3012R'));$raw[$market][$exchange]=$u['json']??[];if(!empty($u['ok']))$anyOk=true;$sum=is_array($u['json']['output2'][0]??null)?$u['json']['output2'][0]:(is_array($u['json']['output2']??null)?$u['json']['output2']:[]);$marketCash=max($marketCash,tb_num($sum['frcr_dncl_amt_2']??$sum['frcr_dncl_amt']??0));$marketEquity=max($marketEquity,tb_num($sum['tot_asst_amt']??$sum['tot_evlu_pfls_amt']??0));foreach($u['json']['output1']??$u['json']['output']??[] as$x){if(!is_array($x))continue;$symbol=strtoupper(trim((string)($x['ovrs_pdno']??$x['pdno']??$x['OVRS_PDNO']??'')));if($symbol==='')continue;$qty=(int)tb_num($x['ovrs_cblc_qty']??$x['hldg_qty']??$x['OVRS_CBLC_QTY']??0);$available=(int)tb_num($x['ord_psbl_qty']??$x['ovrs_ord_psbl_qty']??$qty);if($qty<1&&$available<1)continue;$row=['symbol'=>$symbol,'name'=>(string)($x['ovrs_item_name']??$x['prdt_name']??$symbol),'exchange'=>$exchange,'qty'=>$qty,'available_qty'=>$available,'avg_price'=>tb_num($x['pchs_avg_pric']??$x['pchs_avg_pric2']??0),'current_price'=>tb_num($x['now_pric2']??$x['ovrs_now_pric1']??0)];if(!isset($holdingMap[$symbol])||$available>(int)($holdingMap[$symbol]['available_qty']??0))$holdingMap[$symbol]=$row;}}if($anyOk)$markets[$market]=['currency'=>$group['currency'],'cash'=>$marketCash,'equity'=>$marketEquity,'holdings'=>array_values($holdingMap)];}
    $ok=isset($markets['KR'])||isset($markets['US'])||isset($markets['JP']);return['ok'=>$ok,'snapshot'=>['schema'=>TB_SCHEMA,'owner'=>'broker_raw','mode'=>$c['mock']?'MOCK':'REAL','updated_at'=>tb_now(),'markets'=>$markets,'raw'=>$raw]];
}

function tb_cancel_action(array $c,string $id,string $source): array
{
    if($id==='')return['ok'=>false,'message'=>'order_id 없음'];$orders=tb_orders($c);$found=false;
    foreach($orders as$k=>$o){if(!is_array($o)||(string)($o['order_id']??'')!==$id)continue;$found=true;$status=strtoupper((string)($o['status']??''));if(!in_array($status,['SENT','PARTIAL','WORKING'],true))return['ok'=>false,'message'=>'취소 가능한 상태 아님'];
        if(strtoupper((string)($o['execution_mode']??''))!=='REAL'){$orders[$k]['status']='CANCELLED';$orders[$k]['cancelled_at']=tb_now();$orders[$k]['cancel_source']=$source;tb_save_orders($c,$orders,'cancel');return['ok'=>true,'message'=>'PAPER 주문 취소 완료'];}
        $res=tb_kis_cancel_order($c,$o);$orders[$k]['cancel_response']=$res;$orders[$k]['cancel_requested_at']=tb_now();$orders[$k]['cancel_source']=$source;
        if(!empty($res['ok'])){$orders[$k]['status']='CANCEL_REQUESTED';$orders[$k]['approved']=false;$orders[$k]['broker_message']=(string)($res['message']??'취소 요청 접수');tb_save_orders($c,$orders,'cancel_requested');return['ok'=>true,'message'=>'KIS 취소 요청 접수'];}
        $orders[$k]['cancel_error']=(string)($res['message']??'취소 실패');tb_save_orders($c,$orders,'cancel_failed');return['ok'=>false,'message'=>$orders[$k]['cancel_error']];}
    return['ok'=>false,'message'=>$found?'취소 실패':'주문 없음'];
}

function tb_cancel_expired_buys(array $c,string $source): array
{
    $orders=tb_orders($c);$ids=[];$now=time();
    foreach($orders as$o){if(!is_array($o)||!in_array(strtoupper((string)($o['status']??'')),['SENT','PARTIAL','WORKING'],true)||strtoupper((string)($o['execution_mode']??''))!=='REAL'||!tb_buy_expired($o,$now))continue;$id=(string)($o['order_id']??'');if($id!=='')$ids[]=$id;}    $ok=0;$fail=0;foreach(array_values(array_unique($ids))as$id){$r=tb_cancel_action($c,$id,'ttl:'.$source);if(!empty($r['ok']))$ok++;else$fail++;}
    return['ok'=>$fail===0,'message'=>'TTL 만료 실매수 취소 요청 '.$ok.'건, 실패 '.$fail.'건','requested'=>$ok,'failed'=>$fail];
}

function tb_cancel_stale(array $c,string $source): array
{
    if(!$c['auto_cancel'])return['ok'=>false,'message'=>'AUTO_CANCEL_STALE OFF'];$orders=tb_orders($c);$ids=[];$now=time();
    foreach($orders as$o){if(!is_array($o)||!in_array(strtoupper((string)($o['status']??'')),['SENT','PARTIAL','WORKING'],true))continue;$sent=tb_ts($o['sent_at']??$o['processed_at']??0);if($sent>0&&$now-$sent>=$c['cancel_after_sec'])$ids[]=(string)($o['order_id']??'');}
    $ok=0;$fail=0;foreach($ids as$id){$r=tb_cancel_action($c,$id,$source);if(!empty($r['ok']))$ok++;else$fail++;}
    return['ok'=>$fail===0,'message'=>'장기 미체결 취소 요청 '.$ok.'건, 실패 '.$fail.'건','requested'=>$ok,'failed'=>$fail];
}

function tb_kis_domestic_cancel_info(array $c,string $orderNo): array
{
    $tok=tb_token($c);if(empty($tok['ok']))return['ok'=>false];$q=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'INQR_DVSN_1'=>'0','INQR_DVSN_2'=>'0','CTX_AREA_FK100'=>'','CTX_AREA_NK100'=>''];
    $r=tb_request('GET',$c['base_url'].'/uapi/domestic-stock/v1/trading/inquire-psbl-rvsecncl?'.http_build_query($q),tb_headers($c,$tok['access_token'],$c['mock']?'VTTC0084R':'TTTC0084R'));
    $rows=$r['json']['output']??$r['json']['output1']??[];foreach($rows as$x){if(!is_array($x))continue;$no=(string)($x['odno']??$x['ODNO']??$x['orgn_odno']??'');if($no!==$orderNo)continue;return['ok'=>true,'org_no'=>(string)($x['krx_fwdg_ord_orgno']??$x['KRX_FWDG_ORD_ORGNO']??$x['ord_gno_brno']??''),'possible_qty'=>(int)tb_num($x['psbl_qty']??$x['PSBL_QTY']??$x['rmn_qty']??0)];}
    return['ok'=>false];
}

function tb_kis_cancel_order(array $c,array $o): array
{
    $tok=tb_token($c);if(empty($tok['ok']))return$tok;$market=strtoupper((string)($o['market']??''));$orderNo=(string)($o['kis_order_no']??'');$ordered=max(1,(int)($o['qty']??0));$filled=max(0,(int)($o['filled_qty']??0));$remain=max(0,$ordered-$filled);if($orderNo===''||$remain<1)return['ok'=>false,'message'=>'취소할 원주문번호 또는 잔량 없음'];
    if($market==='KR'){$org=(string)($o['kis_org_no']??'');$info=tb_kis_domestic_cancel_info($c,$orderNo);if(!empty($info['ok'])){$org=(string)($info['org_no']??$org);$remain=min($remain,max(0,(int)($info['possible_qty']??$remain)));}if($org===''||$remain<1)return['ok'=>false,'message'=>'국내 정정취소 가능수량 또는 주문조직번호 확인 실패'];$path='/uapi/domestic-stock/v1/trading/order-rvsecncl';$tr=$c['mock']?'VTTC0013U':'TTTC0013U';$body=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'KRX_FWDG_ORD_ORGNO'=>$org,'ORGN_ODNO'=>$orderNo,'ORD_DVSN'=>'00','RVSE_CNCL_DVSN_CD'=>'02','ORD_QTY'=>(string)$remain,'ORD_UNPR'=>'0','QTY_ALL_ORD_YN'=>'Y','EXCG_ID_DVSN_CD'=>'KRX'];}
    else{$path='/uapi/overseas-stock/v1/trading/order-rvsecncl';$tr=$market==='JP'?($c['mock']?'VTTS0309U':'TTTS0309U'):($c['mock']?'VTTT1004U':'TTTT1004U');$body=['CANO'=>$c['cano'],'ACNT_PRDT_CD'=>$c['product'],'OVRS_EXCG_CD'=>tb_exchange($c,$o),'PDNO'=>(string)$o['symbol'],'ORGN_ODNO'=>$orderNo,'RVSE_CNCL_DVSN_CD'=>'02','ORD_QTY'=>(string)$remain,'OVRS_ORD_UNPR'=>'0','MGCO_APTM_ODNO'=>'','ORD_SVR_DVSN_CD'=>'0'];}
    $hash=tb_hashkey($c,$body,$tok['access_token']);$headers=tb_headers($c,$tok['access_token'],$tr);if($hash!=='')$headers[]='hashkey: '.$hash;$r=tb_request('POST',$c['base_url'].$path,$headers,$body);$ok=!empty($r['ok'])&&(string)($r['json']['rt_cd']??'')==='0';return['ok'=>$ok,'message'=>(string)($r['json']['msg1']??$r['error']??''),'http'=>$r['http'],'raw'=>$r['json']];
}

function tb_token(array $c): array
{
    $x=tb_load($c['token_file'],[]);if(is_array($x)&&!empty($x['access_token'])&&(int)($x['expires_at']??0)>time()+120)return['ok'=>true,'access_token'=>$x['access_token']];
    if($c['base_url']===''||$c['app_key']===''||$c['app_secret']==='')return['ok'=>false,'message'=>'KIS 인증 설정 누락'];$r=tb_request('POST',$c['base_url'].'/oauth2/tokenP',['content-type: application/json'],['grant_type'=>'client_credentials','appkey'=>$c['app_key'],'appsecret'=>$c['app_secret']]);$t=(string)($r['json']['access_token']??'');if($t==='')return['ok'=>false,'message'=>'토큰 발급 실패 '.(string)($r['json']['msg1']??$r['error']??'')];$exp=(int)($r['json']['expires_in']??86400);tb_save($c['token_file'],['access_token'=>$t,'expires_at'=>time()+max(300,$exp-300)]);return['ok'=>true,'access_token'=>$t];
}
function tb_hashkey(array $c,array $body,string $token): string{$r=tb_request('POST',$c['base_url'].'/uapi/hashkey',['content-type: application/json','authorization: Bearer '.$token,'appkey: '.$c['app_key'],'appsecret: '.$c['app_secret']],$body);return(string)($r['json']['HASH']??'');}
function tb_headers(array $c,string $token,string $tr): array{return['content-type: application/json; charset=utf-8','authorization: Bearer '.$token,'appkey: '.$c['app_key'],'appsecret: '.$c['app_secret'],'tr_id: '.$tr,'custtype: P'];}
function tb_request(string $method,string $url,array $headers,?array $body=null): array
{
    if(!function_exists('curl_init'))return['ok'=>false,'http'=>0,'error'=>'curl 없음','json'=>[]];$ch=curl_init($url);$opt=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>TB_HTTP_CONNECT_TIMEOUT,CURLOPT_TIMEOUT=>TB_HTTP_TIMEOUT,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>'TradeBroker/'.TB_VERSION];if(strtoupper($method)==='POST'){$opt[CURLOPT_POST]=true;$opt[CURLOPT_POSTFIELDS]=tb_json($body);}curl_setopt_array($ch,$opt);$raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=(string)curl_error($ch);$eno=(int)curl_errno($ch);curl_close($ch);$j=json_decode(is_string($raw)?$raw:'',true);return['ok'=>$eno===0&&$http>=200&&$http<300,'http'=>$http,'error'=>$err,'json'=>is_array($j)?$j:[]];
}

function tb_heartbeat_mark(array $c,string $action,array $result,string $source): void
{
    $row=tb_load($c['heartbeat_file'],[]);if(!is_array($row))$row=[];$now=tb_now();$latency=is_array($result['latency']??null)?$result['latency']:tb_latency_summary(tb_orders($c));$row=array_merge($row,['schema'=>'broker_heartbeat_v1','version'=>TB_VERSION,'rev'=>TB_REV,'expected_cycle_sec'=>TB_EXPECTED_CYCLE_SEC,'caution_cycle_sec'=>TB_CAUTION_CYCLE_SEC,'stale_cycle_sec'=>TB_STALE_CYCLE_SEC,'execution_mode'=>$c['mode'],'approval_mode'=>$c['approval_mode'],'kill_flag'=>is_file($c['kill_file']),'last_action'=>$action,'last_action_at'=>$now,'last_source'=>$source,'latency'=>$latency,'last_result'=>['ok'=>!empty($result['ok']),'message'=>(string)($result['message']??''),'processed'=>(int)($result['processed']??$result['run']['processed']??0),'filled'=>(int)($result['filled']??$result['run']['filled']??0),'sent'=>(int)($result['sent']??$result['run']['sent']??0),'rejected'=>(int)($result['rejected']??$result['run']['rejected']??0),'ingested'=>(int)($result['ingest']['count']??$result['run']['ingest']['count']??0),'ingest_error'=>(string)($result['ingest']['error']??$result['run']['ingest']['error']??''),'cycle_elapsed_sec'=>isset($result['cycle_elapsed_sec'])?(float)$result['cycle_elapsed_sec']:null,'latency'=>$latency]]);
    $map=['cycle'=>'last_cycle_at','ingest'=>'last_ingest_at','run'=>'last_run_at','approve_run'=>'last_run_at','process'=>'last_run_at','sync'=>'last_sync_at','cancel'=>'last_cancel_at','cancel_stale'=>'last_cancel_at','expire'=>'last_expire_at','cleanup_expired'=>'last_cleanup_at','cleanup_terminal'=>'last_cleanup_at'];if(isset($map[$action]))$row[$map[$action]]=$now;if($action==='cycle'){$row['last_ingest_at']=$now;$row['last_run_at']=$now;if($c['mode']==='real')$row['last_sync_at']=$now;if($c['auto_cancel'])$row['last_cancel_at']=$now;}$row['pipeline']=tb_pipeline_summary(tb_orders($c));tb_save($c['heartbeat_file'],$row);
}
function tb_heartbeat(array $c): array{$row=tb_load($c['heartbeat_file'],[]);return is_array($row)?$row:[];}

function tb_mark_market_wait(array $order): array
{
    if(tb_ts($order['market_wait_started_at']??0)<=0){
        $now=tb_now();$order['market_wait_started_at']=$now;$order['market_wait_last_started_at']=$now;
        $order['wait_market_open_at']=$order['wait_market_open_at']??$now;
        $order['market_wait_count']=(int)($order['market_wait_count']??0)+1;
    }
    $order['broker_message']='WAIT_MARKET_OPEN';return$order;
}

function tb_release_market_wait(array $order): array
{
    $start=tb_ts($order['market_wait_started_at']??0);if($start<=0)return$order;
    $now=time();$elapsed=max(0,$now-$start);$order['market_wait_total_sec']=(int)($order['market_wait_total_sec']??0)+$elapsed;
    $order['market_wait_ended_at']=tb_now();$order['market_wait_released_at']=$order['market_wait_ended_at'];unset($order['market_wait_started_at']);
    return$order;
}

function tb_order_waiting_market_open(array $order): bool
{
    if(!tb_order_active_status((string)($order['status']??'')))return false;
    return tb_ts($order['market_wait_started_at']??0)>0||strtoupper(trim((string)($order['broker_message']??'')))==='WAIT_MARKET_OPEN';
}

function tb_order_market_wait_seconds(array $order,int $throughTs=0): array
{
    if($throughTs<=0)$throughTs=time();$tracked=array_key_exists('market_wait_total_sec',$order)||array_key_exists('market_wait_started_at',$order)||array_key_exists('market_wait_count',$order);
    $total=max(0,(int)($order['market_wait_total_sec']??0));$active=tb_ts($order['market_wait_started_at']??0);if($active>0)$total+=max(0,$throughTs-$active);
    if(!$tracked){$legacy=tb_ts($order['wait_market_open_at']??0);$processed=tb_ts($order['processed_at']??$order['sent_at']??$order['filled_at']??0);if($legacy>0&&$processed>=$legacy){$total=max(0,$processed-$legacy);$tracked=true;}}
    return['seconds'=>$total,'tracked'=>$tracked];
}

function tb_latency_summary(array $orders): array
{
    $groups=[];$activeOldest=0;$actionableOldest=0;$marketWaitOldest=0;$marketWaitCount=0;$waitTracked=0;$waitUntracked=0;
    foreach($orders as$o){
        if(!is_array($o))continue;
        $strategy=strtolower((string)($o['strategy_key']??'unknown'));$side=strtoupper((string)($o['side']??'NA'));$key=$strategy.':'.$side;
        $created=tb_ts($o['created_at']??0);$received=tb_ts($o['received_at']??0);$approved=tb_ts($o['approved_at']??0);$processed=tb_ts($o['processed_at']??$o['sent_at']??$o['filled_at']??0);
        if($created>0&&$received>0)$groups[$key]['created_to_received'][]=max(0,$received-$created);
        if($received>0&&$approved>0)$groups[$key]['received_to_approved'][]=max(0,$approved-$received);
        if($approved>0&&$processed>0){
            $raw=max(0,$processed-$approved);$waitInfo=tb_order_market_wait_seconds($o,$processed);$wait=min($raw,max(0,(int)$waitInfo['seconds']));
            $groups[$key]['approved_to_processed'][]=$raw;
            $groups[$key]['approved_to_processed_excluding_market_wait'][]=max(0,$raw-$wait);
            if($wait>0)$groups[$key]['market_wait_duration'][]=$wait;
            if(!empty($waitInfo['tracked']))$waitTracked++;else$waitUntracked++;
        }
        if(!tb_order_active_status((string)($o['status']??''))||$created<=0)continue;
        $age=max(0,time()-$created);$activeOldest=max($activeOldest,$age);
        if(tb_order_waiting_market_open($o)){$marketWaitCount++;$marketWaitOldest=max($marketWaitOldest,$age);}
        else $actionableOldest=max($actionableOldest,$age);
    }
    $out=[];foreach($groups as$key=>$metrics){foreach($metrics as$name=>$values)$out[$key][$name]=tb_latency_stats($values);}
    return[
        'recommended_cycle_sec'=>TB_EXPECTED_CYCLE_SEC,
        'caution_cycle_sec'=>TB_CAUTION_CYCLE_SEC,
        'stale_cycle_sec'=>TB_STALE_CYCLE_SEC,
        'oldest_active_age_sec'=>$activeOldest,
        'oldest_actionable_active_age_sec'=>$actionableOldest,
        'market_wait_count'=>$marketWaitCount,
        'oldest_market_wait_age_sec'=>$marketWaitOldest,
        'market_wait_measurement'=>['tracked_completed_orders'=>$waitTracked,'untracked_completed_orders'=>$waitUntracked,'legacy_estimation_supported'=>true],
        'definitions'=>[
            'approved_to_processed'=>'승인부터 처리까지의 실제 경과시간으로 시장 개장 대기를 포함',
            'approved_to_processed_excluding_market_wait'=>'시장 개장 대기시간을 제외한 브로커 처리 경과시간',
            'market_wait_duration'=>'주문이 시장 개장을 기다린 시간',
        ],
        'by_strategy_side'=>$out,
    ];
}

function tb_latency_stats(array $values): array
{
    $values=array_values(array_map('intval',$values));sort($values,SORT_NUMERIC);$n=count($values);if($n===0)return['count'=>0,'avg'=>0,'p50'=>0,'p95'=>0,'max'=>0];$sum=array_sum($values);$p50=$values[(int)floor(($n-1)*0.50)];$p95=$values[(int)floor(($n-1)*0.95)];return['count'=>$n,'avg'=>round($sum/$n,2),'p50'=>$p50,'p95'=>$p95,'max'=>$values[$n-1]];
}

function tb_status(array $c): array{$orders=tb_orders($c);$counts=tb_trade_list_exchange_counts($c['trade_list_file']);return['ok'=>true,'version'=>TB_VERSION,'rev'=>TB_REV,'mode'=>$c['mode'],'schedule'=>['broker_cycle_sec'=>TB_EXPECTED_CYCLE_SEC,'strategy_cycle_sec_default'=>1200,'strategy_cycle_sec_by_model'=>['dts'=>60,'abc'=>1200,'stc26'=>1200,'das'=>1200]],'lock_stats'=>tb_load((string)($c['lock_stats_file']??''),[]),'approval_mode'=>tb_approval_mode_status($c),'heartbeat'=>tb_heartbeat($c),'ingest_audit'=>tb_load((string)$c['ingest_audit_file'],[]),'settings'=>tb_safe_settings($c),'trade_list'=>['file'=>$c['trade_list_file'],'exists'=>is_file($c['trade_list_file']),'exchange_counts'=>$counts],'summary'=>tb_summary($orders),'pipeline'=>tb_pipeline_summary($orders),'latency'=>tb_latency_summary($orders),'validation'=>tb_validate_all($c,$orders),'orders'=>$orders,'account_raw'=>tb_load($c['account_file'],[])];}
function tb_orders(array $c): array{$r=tb_load($c['orders_file'],[]);return is_array($r['orders']??null)?$r['orders']:[];}
function tb_save_orders(array $c,array $orders,string $action): void{tb_save($c['orders_file'],['schema'=>TB_SCHEMA,'owner'=>'broker','updated_at'=>tb_now(),'action'=>$action,'orders'=>array_values($orders)]);}
function tb_summary(array $orders): array
{
    $summary = ['total'=>0,'pending'=>0,'waiting_approval'=>0,'approved'=>0,'sent'=>0,'partial'=>0,'filled'=>0,'rejected'=>0,'expired'=>0,'cancelled'=>0];
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $summary['total']++;
        $status = strtoupper((string)($order['status'] ?? ''));
        if ($status === 'PENDING') {$summary['pending']++;if(empty($order['approved']))$summary['waiting_approval']++;}
        if ($status === 'PENDING'&&!empty($order['approved'])) $summary['approved']++;
        if (in_array($status, ['SENT','WORKING','CANCEL_REQUESTED'], true)) $summary['sent']++;
        if ($status === 'PARTIAL') $summary['partial']++;
        if (in_array($status, ['FILLED','PAPER_FILLED'], true)) $summary['filled']++;
        if ($status === 'REJECTED') $summary['rejected']++;
        if ($status === 'EXPIRED') $summary['expired']++;
        if ($status === 'CANCELLED') $summary['cancelled']++;
    }
    return $summary;
}

function tb_pipeline_summary(array $orders): array
{
    $summary = tb_summary($orders);
    $stuck=tb_stuck_scenarios($orders,TB_STUCK_EXPIRE_THRESHOLD);
    return [
        'waiting_approval'=>$summary['waiting_approval'],
        'approved'=>$summary['approved'],
        'working'=>$summary['sent'],
        'partial'=>$summary['partial'],
        'filled'=>$summary['filled'],
        'expired'=>$summary['expired'],
        'rejected'=>$summary['rejected'],
        'terminal_error'=>$summary['rejected'] + $summary['expired'] + $summary['cancelled'],
        'market_wait'=>count(array_filter($orders,'tb_order_waiting_market_open')),
        'stuck_count'=>count($stuck),
        'stuck_scenarios'=>$stuck,
    ];
}


function tb_order_status_label(string $status): string
{
    $status=strtoupper(trim($status));
    $map=[
        'PENDING'=>'처리대기','APPROVED'=>'승인완료','SENT'=>'주문전송','WORKING'=>'미체결진행','PARTIAL'=>'부분체결','CANCEL_REQUESTED'=>'취소요청',
        'PAPER_FILLED'=>'모의체결완료','FILLED'=>'실체결완료','EXPIRED'=>'만료','REJECTED'=>'거절','CANCELLED'=>'취소'
    ];
    return $map[$status]??($status!==''?$status:'상태없음');
}
function tb_order_status_class(string $status): string
{
    $status=strtoupper(trim($status));
    if(in_array($status,['PAPER_FILLED','FILLED'],true))return'filled';
    if(in_array($status,['SENT','WORKING','PARTIAL','CANCEL_REQUESTED'],true))return'active';
    if(in_array($status,['PENDING','APPROVED'],true))return'pending';
    if(in_array($status,['EXPIRED','REJECTED','CANCELLED'],true))return'terminal';
    return'neutral';
}
function tb_approval_label(array $o): array
{
    $status=strtoupper((string)($o['status']??''));
    $approved=!empty($o['approved']);
    $origin=strtoupper((string)($o['approval_origin']??''));
    if($origin===''){
        $by=(string)($o['approved_by']??'');
        if(strpos(strtolower($by),'auto:')===0)$origin='AUTO';
        elseif($by!=='')$origin='MANUAL';
    }
    $mode=$origin==='AUTO'?'자동승인':($origin==='MANUAL'?'직접승인':'승인정보 없음');
    if(in_array($status,['PAPER_FILLED','FILLED'],true))return['label'=>'완료','detail'=>$mode,'class'=>'filled'];
    if(in_array($status,['EXPIRED','REJECTED','CANCELLED'],true))return['label'=>'종료','detail'=>$approved?'승인 후 종료':'미승인 종료','class'=>'terminal'];
    if($approved)return['label'=>'승인완료','detail'=>$mode,'class'=>'approved'];
    return['label'=>'승인대기','detail'=>'미승인','class'=>'pending'];
}
function tb_execution_label(array $o,array $c): string
{
    $status=strtoupper((string)($o['status']??''));
    $exec=strtoupper((string)($o['execution_mode']??''));
    if($exec==='')$exec=$status==='PAPER_FILLED'?'PAPER':strtoupper((string)$c['mode']);
    $origin=strtoupper((string)($o['approval_origin']??''));
    $approval=$origin==='AUTO'?'자동':($origin==='MANUAL'?'직접':'-');
    if($status==='PAPER_FILLED')return $approval.' · PAPER';
    if($status==='FILLED')return $approval.' · REAL';
    if(in_array($status,['PENDING','APPROVED'],true))return $approval.' · 대기';
    return $approval.' · '.$exec;
}
function tb_badge(string $label,string $class='neutral',string $detail=''): string
{
    return'<span class="badge '.$class.'">'.tb_h($label).'</span>'.($detail!==''?'<br><small>'.tb_h($detail).'</small>':'');
}
function tb_order_row_class(array $o): string
{
    $status=strtoupper((string)($o['status']??''));
    $side=strtoupper((string)($o['side']??''));
    if($side==='SELL'&&in_array($status,['PENDING','APPROVED','SENT','WORKING','PARTIAL','CANCEL_REQUESTED'],true))return'order-sell-active';
    return'';
}

function tb_safe_settings(array $c): array{return['BROKER_MODE'=>$c['mode'],'APPROVAL_MODE'=>strtoupper((string)$c['approval_mode']),'APPROVAL_MODE_SOURCE'=>$c['approval_mode_source'],'AUTO_APPROVE_VALID_DEFAULT'=>$c['auto_approve_default']?'ON':'OFF','AUTO_TRADE_ENABLED'=>$c['auto']?'ON':'OFF','ALLOW_BUY'=>$c['allow_buy']?'ON':'OFF','ALLOW_SELL'=>$c['allow_sell']?'ON':'OFF','MAX_ORDER_KR'=>$c['max_order_kr'],'MAX_ORDER_US'=>$c['max_order_us'],'MAX_ORDER_JP'=>$c['max_order_jp'],'MAX_ORDER_PER_CYCLE'=>$c['max_per_cycle'],'BROKER_CRON_SEC'=>TB_EXPECTED_CYCLE_SEC,'BROKER_CRON_CAUTION_SEC'=>TB_CAUTION_CYCLE_SEC,'BROKER_CRON_STALE_SEC'=>TB_STALE_CYCLE_SEC,'STRATEGY_CRON_SEC'=>['default'=>1200,'dts'=>60],'MAX_SIGNAL_AGE_AUTO_SEC'=>$c['max_signal_age_auto'],'MAX_SIGNAL_AGE_MANUAL_SEC'=>$c['max_signal_age_manual'],'MAX_SYMBOL_TOTAL_KRW'=>$c['max_symbol_total_kr'],'MAX_SYMBOL_TOTAL_USD'=>$c['max_symbol_total_us'],'MAX_SYMBOL_TOTAL_JPY'=>$c['max_symbol_total_jp'],'MAX_SYMBOL_STRATEGY_COUNT'=>$c['max_symbol_strategy_count'],'MAX_SYMBOL_STRATEGY_COUNT_REQUESTED'=>$c['max_symbol_strategy_count_requested'],'SINGLE_STRATEGY_OVERRIDE_IGNORED'=>$c['single_strategy_override_ignored']?'YES':'NO','MAX_MARKET_INVEST_PCT'=>$c['max_market_invest_pct'],'MAX_SYMBOL_INVEST_PCT'=>$c['max_symbol_invest_pct'],'MAX_RISK_GROUP_INVEST_PCT'=>$c['max_risk_group_invest_pct'],'MAX_CORRELATION_POSITIONS_GLOBAL'=>$c['max_correlation_positions_global'],'CORRELATION_POSITION_LIMITS_BY_STRATEGY'=>$c['max_correlation_positions_by_strategy'],'EXPIRED_REENTRY_COOLDOWN_SEC'=>$c['expired_cooldown_sec'],'SCENARIO_REISSUE_COOLDOWN_SEC'=>$c['scenario_cooldown_sec'],'STOP_REENTRY_COOLDOWN_SEC'=>$c['stop_reentry_cooldown_sec'],'BLOCK_INVERSE_BUY'=>$c['block_inverse']?'ON':'OFF','BLOCK_LEVERAGED_BUY'=>$c['block_leveraged']?'ON':'OFF','MAX_PENDING_TOTAL_KRW'=>$c['max_pending_total_kr'],'MAX_PENDING_TOTAL_USD'=>$c['max_pending_total_us'],'MAX_PENDING_TOTAL_JPY'=>$c['max_pending_total_jp'],'MAX_GAP_KR_PCT'=>$c['max_gap_kr'],'MAX_GAP_US_PCT'=>$c['max_gap_us'],'MAX_GAP_JP_PCT'=>$c['max_gap_jp'],'ANALYSIS_QUOTE_MAX_AGE_KR_SEC'=>$c['analysis_quote_age_kr'],'ANALYSIS_QUOTE_MAX_AGE_US_SEC'=>$c['analysis_quote_age_us'],'ANALYSIS_QUOTE_MAX_AGE_JP_SEC'=>$c['analysis_quote_age_jp'],'MAX_LIVE_RISK_PCT'=>$c['max_live_risk_pct'],'MAX_LIVE_RISK_EXPANSION_PCT'=>$c['max_live_risk_expansion_pct'],'APPLY_EXPOSURE_LIMITS_IN_PAPER'=>$c['apply_exposure_paper']?'ON':'OFF','AUTO_CANCEL_STALE'=>$c['auto_cancel']?'ON':'OFF','AUTO_CANCEL_STALE_SEC'=>$c['cancel_after_sec'],'MAX_SELL_SIGNAL_AGE_SEC'=>$c['max_signal_age_sell'],'NORMALIZE_PRICE_TICK'=>$c['normalize_price_tick']?'ON':'OFF','BROKER_ORDERS_ARCHIVE'=>$c['orders_archive_file'],'TRADE_LIST_FILE'=>$c['trade_list_file'],'TRADE_LIST_EXCHANGES'=>tb_trade_list_exchange_counts($c['trade_list_file']),'PAPER_MARKET_HOURS_GATE'=>$c['paper_market_hours_gate']?'ON':'OFF','PAPER_JP_KIS_REQUOTE'=>$c['paper_jp_live_requote']?'ON':'OFF','PAPER_FILL_MODE'=>$c['paper_fill_mode'],'PAPER_FILL_DELAY_SEC'=>$c['paper_fill_delay_sec'],'PAPER_FORCE_COMPLETE_SEC'=>$c['paper_force_complete_sec'],'PAPER_URGENT_SELL_IMMEDIATE'=>$c['paper_urgent_sell_immediate']?'ON':'OFF','PAPER_FIRST_FILL_RATIO'=>$c['paper_first_fill_ratio'],'PAPER_NEXT_FILL_RATIO'=>$c['paper_next_fill_ratio'],'PAPER_SLIPPAGE_BPS'=>['KR'=>$c['paper_slippage_bps_kr'],'US'=>$c['paper_slippage_bps_us'],'JP'=>$c['paper_slippage_bps_jp']],'EXPOSURE_BASIS'=>'CORE_SHARED_OR_CHALLENGER_SHADOW_CURRENT_EQUITY + OPEN_QTY_X_CURRENT_MARK','SSL_VERIFY'=>'ON'];}

function tb_latency_worst_metric_p95(array $latency,string $metric): int
{
    $worst=0;$groups=is_array($latency['by_strategy_side']??null)?$latency['by_strategy_side']:[];foreach($groups as$row){if(!is_array($row))continue;$v=(int)($row[$metric]['p95']??0);if($v>$worst)$worst=$v;}return$worst;
}

function tb_latency_worst_p95(array $latency): int
{
    $worst=0;$groups=is_array($latency['by_strategy_side']??null)?$latency['by_strategy_side']:[];foreach($groups as$row){if(!is_array($row))continue;$v=(int)($row['created_to_received']['p95']??0);if($v>$worst)$worst=$v;}return$worst;
}
function tb_render(array $c): void
{
    $s=tb_status($c);$manual=$c['approval_mode']==='manual';$heartbeat=is_array($s['heartbeat']??null)?$s['heartbeat']:[];
    header('Content-Type: text/html; charset=utf-8');echo'<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="60;url=?"><title>Trade Broker</title><style>'.tb_css().'</style></head><body><main><div class="broker-head"><div><h1>Trade Broker</h1><p class="muted">'.TB_VERSION.' · 실행 전용 · 자본/통계 없음</p></div></div>';
    echo'<section class="approval-mode"><div><h2>주문 승인 방식</h2><p class="muted">직접 승인은 주문별 확인 후 실행하며, 자동 승인은 검증을 통과한 주문을 CYCLE에서 자동 승인합니다.</p></div><div class="mode-buttons">'.tb_mode_form('manual','직접 승인',$manual,false).tb_mode_form('auto','자동 승인',!$manual,true).'</div><div class="mode-note '.($manual?'manual':'auto').'">현재 '.($manual?'직접 승인 모드 — 사용자가 승인한 주문만 실행됩니다.':'자동 승인 모드 — 유효 주문은 자동 승인되지만 모든 안전검사는 유지됩니다.').'</div></section>';
    echo'<section class="buttons">';$actions=[['cycle','통합 CYCLE'],['ingest','주문 수신'],['validate','검증']];if($manual){$actions[]=['approve_valid','유효 주문 일괄 승인'];$actions[]=['approve_one','1건 승인'];$actions[]=['approve_run','1건 승인 후 실행'];}$runLabel=$c['mode']==='real'?'REAL RUN':'PAPER RUN';$actions=array_merge($actions,[['run',$runLabel,$c['mode']==='real'],['sync','체결·잔고 동기화'],['cancel_stale','장기 미체결 취소'],['expire','만료 처리'],['cleanup_expired','N EXPIRED 정리',true],['cleanup_terminal','종료 오류 정리',true]]);foreach($actions as$a)echo tb_form($a[0],$a[1],!empty($a[2]));echo'</section>';
    $x=$s['summary'];$lat=is_array($s['latency']??null)?$s['latency']:[];$worstP95=tb_latency_worst_p95($lat);$actionP95=tb_latency_worst_metric_p95($lat,'approved_to_processed_excluding_market_wait');echo'<section class="cards">';tb_card('실행 모드',strtoupper($c['mode']),'AUTO TRADE '.($c['auto']?'ON':'OFF'));tb_card('승인 방식',$manual?'MANUAL':'AUTO',$manual?'직접 승인':'검증 후 자동 승인');tb_card('승인 대기',$x['waiting_approval'],'승인 완료 '.$x['approved']);tb_card('주문 진행',$x['sent'],'부분 '.$x['partial'].' · 체결 '.$x['filled']);tb_card('종료 오류',$x['expired'],'거절 '.$x['rejected'].' · 취소 '.$x['cancelled']);tb_card('마지막 CYCLE',(string)($heartbeat['last_cycle_at']??'-'),'회당 최대 '.$c['max_per_cycle'].'건');tb_card('처리 필요 활성 주문',(int)($lat['oldest_actionable_active_age_sec']??$lat['oldest_active_age_sec']??0).'초','시장 개장 대기 제외');tb_card('시장 개장 대기',(int)($lat['market_wait_count']??0).'건','최장 '.(int)($lat['oldest_market_wait_age_sec']??0).'초');tb_card('생성→수신 최악 p95',$worstP95.'초','권장 60초 이하');tb_card('시장대기 제외 처리 p95',$actionP95.'초','승인→처리 · 개장대기 제외');tb_card('CYCLE 기준',TB_EXPECTED_CYCLE_SEC.'초','주의 '.TB_CAUTION_CYCLE_SEC.'초 · 경고 '.TB_STALE_CYCLE_SEC.'초');echo'</section>';
    echo'<section><h2>안전 설정</h2><pre>'.tb_h(tb_json($s['settings'],true)).'</pre></section><section><h2>브로커 주문</h2><p class="muted">Y/N 원시 표시 대신 승인상태·처리방식·주문상태를 한글 라벨로 표시합니다. 원시 상태값은 작은 글씨로 함께 보존합니다.</p><div class="legend"><span class="badge pending">처리대기</span><span class="badge active">진행중</span><span class="badge filled">체결완료</span><span class="badge terminal">종료/정리대상</span></div><div class="scroll"><table><tr><th>승인상태</th><th>처리방식</th><th>주문상태</th><th>시장</th><th>종목</th><th>방향</th><th>가격</th><th>수량</th><th>유형</th><th>사유</th><th>작업</th></tr>';
    if(!$s['orders'])echo'<tr><td colspan="11">없음</td></tr>';foreach(array_reverse($s['orders'])as$o){if(!is_array($o))continue;$id=(string)$o['order_id'];$status=strtoupper((string)($o['status']??''));$approval=tb_approval_label($o);$rowClass=tb_order_row_class($o);echo'<tr'.($rowClass!==''?' class="'.$rowClass.'"':'').'><td>'.tb_badge($approval['label'],$approval['class'],$approval['detail']).'</td><td>'.tb_h(tb_execution_label($o,$c)).'</td><td>'.tb_badge(tb_order_status_label($status),tb_order_status_class($status),$status).'</td><td>'.tb_h((string)$o['market']).'</td><td>'.tb_h((string)$o['name']).'<br><small>'.tb_h((string)$o['symbol']).'</small></td><td>'.tb_badge(tb_h((string)$o['side']),strtoupper((string)$o['side'])==='SELL'?'terminal':'active').'</td><td>'.tb_h((string)$o['price']).'</td><td>'.(int)$o['qty'].'</td><td>'.tb_h((string)($o['signal_type']??'')).'</td><td>'.tb_h((string)($o['reject_reason']??$o['broker_message']??$o['reason']??'')).'</td><td>';if($status==='PENDING'&&$manual){echo tb_form(!empty($o['approved'])?'unapprove':'approve',!empty($o['approved'])?'승인 해제':'직접 승인',false,$id);if(empty($o['approved']))echo tb_form('approve_run','승인 후 실행',false,$id);}elseif($status==='PENDING'&&!$manual)echo'<small>자동 승인 대상</small>';elseif(in_array($status,['SENT','PARTIAL','WORKING'],true))echo tb_form('cancel','취소',true,$id);echo'</td></tr>';}echo'</table></div></section>';
    if(!empty($s['validation']['warnings']))echo'<section><h2>검증 경고</h2><pre>'.tb_h(implode("\n",$s['validation']['warnings'])).'</pre></section>';echo'<section><h2>최근 로그</h2><pre>'.tb_h(tb_tail($c['log_file'],20)).'</pre></section><footer>'.TB_REV.'</footer></main></body></html>';
}
function tb_form(string $action,string $label,bool $danger=false,string $id=''): string{return'<form method="post" class="inline"><input type="hidden" name="action" value="'.tb_h($action).'"><input type="hidden" name="id" value="'.tb_h($id).'"><button'.($danger?' class="danger"':'').'>'.tb_h($label).'</button></form>';}
function tb_mode_form(string $mode,string $label,bool $active,bool $confirm): string{return'<form method="post" class="inline"><input type="hidden" name="action" value="set_approval_mode"><input type="hidden" name="id" value="'.tb_h($mode).'"><button class="mode-btn '.($active?'active ':'').($mode==='auto'?'auto':'manual').'"'.($confirm?' onclick="return confirm(\'유효 주문을 자동 승인하도록 변경합니까? 실제 주문 모드와 매수·매도 허용 설정을 반드시 확인하십시오.\')"':'').'>'.tb_h($label).'</button></form>';}
function tb_card(string $a,$b,$c=''): void{echo'<div class="card"><small>'.tb_h($a).'</small><b>'.tb_h((string)$b).'</b><small>'.tb_h((string)$c).'</small></div>';}
function tb_css(): string{return'body{margin:0;background:#f4f6f8;color:#111827;font-family:system-ui}.broker-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.broker-head h1{margin-bottom:4px}.broker-head p{margin-top:0}main{max-width:1240px;margin:auto;padding:18px}.muted,small{color:#6b7280}.buttons,.mode-buttons,.legend{display:flex;gap:8px;flex-wrap:wrap;background:none;border:0;padding:0}.legend{margin:8px 0 12px}.inline{display:inline}button{padding:10px 14px;border:1px solid #d1d5db;border-radius:10px;background:white;font-weight:700}button.danger{background:#fff1f2;color:#9f1239}.approval-mode{display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center}.approval-mode h2{margin:0 0 6px}.mode-note{grid-column:1/-1;padding:12px;border-radius:12px;font-weight:700}.mode-note.manual{background:#eff6ff;color:#1d4ed8}.mode-note.auto{background:#fff7ed;color:#c2410c}.mode-btn.active.manual{background:#2563eb;color:#fff;border-color:#2563eb}.mode-btn.active.auto{background:#ea580c;color:#fff;border-color:#ea580c}section{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:16px;margin:14px 0}.cards{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;background:none;border:0;padding:0}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px}.card b{display:block;font-size:24px;margin:6px 0}.scroll{overflow:auto;max-height:420px}table{border-collapse:collapse;width:100%;min-width:1100px}th,td{padding:10px;border-bottom:1px solid #e5e7eb;white-space:nowrap;text-align:left;vertical-align:top}th{position:sticky;top:0;background:#f1f5f9}.badge{display:inline-block;padding:4px 9px;border-radius:999px;font-weight:800;font-size:12px;border:1px solid transparent}.badge.pending{background:#fff7ed;color:#c2410c;border-color:#fed7aa}.badge.approved,.badge.active{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}.badge.filled{background:#ecfdf5;color:#047857;border-color:#bbf7d0}.badge.terminal{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.badge.neutral{background:#f3f4f6;color:#374151;border-color:#e5e7eb}tr.order-sell-active td{background:#fff7ed}pre{background:#0f172a;color:#e5e7eb;padding:12px;border-radius:12px;white-space:pre-wrap}footer{color:#6b7280;margin:20px 0}@media(max-width:700px){main{padding:10px}.approval-mode{grid-template-columns:1fr}.mode-buttons{width:100%}.mode-buttons form,.mode-buttons button{flex:1;width:100%}.cards{grid-template-columns:1fr 1fr}.card{padding:12px}.card b{font-size:20px}}';}

function tb_strategy_registry($raw): array
{
    $default=['dts'=>'dts.php','abc'=>'abc.php','das'=>'das.php','stc26'=>'stc26.php'];if(!is_array($raw)||!$raw)return$default;$out=[];foreach($raw as$key=>$file){$key=strtolower(trim((string)$key));if(!array_key_exists($key,$default))continue;$file=strtolower(basename((string)$file));if($file==='')continue;$out[$key]=$file;}return$out?:$default;
}
function tb_intent_signed_fields(string $contract='intent_v2',string $schema=''): array
{
    $base=['schema','owner','order_id','created_at','order_valid_from','order_expires_at','strategy_key','strategy_id','strategy_rev','strategy_file_id','market','currency','symbol','name','exchange','side','order_type','price','qty','amount','stop_price','target_price','signal_type','reason','scenario_id','session_date','signal_market_date','price_source','data_sources','required_timeframes','daily_source','m60_source','m30_source','quote_timestamp','quote_age_sec','quote_freshness','quote_fresh_limit_sec','quote_scan_limit_sec','requires_live_requote','order_live_quote_max_age_sec','analysis_price','tick_id','decision','ai'];
    if(in_array($contract,['intent_v3','intent_v4','intent_v5'],true)){
        $after=array_search('schema',$base,true);array_splice($base,$after+1,0,['intent_contract_rev']);
        $ttlPos=array_search('order_expires_at',$base,true);$extra=['time_in_force','sell_persistent','urgent_exit'];if(in_array($contract,['intent_v4','intent_v5'],true))$extra=array_merge($extra,['exit_urgency']);$extra=array_merge($extra,['order_priority']);if(in_array($contract,['intent_v4','intent_v5'],true))$extra=array_merge($extra,['entry_contract','sizing_mode']);array_splice($base,$ttlPos+1,0,$extra);
        $pos=array_search('scenario_id',$base,true);array_splice($base,$pos+1,0,['scenario_key','asset_type','risk_group','is_inverse','is_leveraged']);
        if($contract==='intent_v5'){$p=array_search('strategy_rev',$base,true);array_splice($base,$p+1,0,['strategy_version','strategy_status','account_mode','alpha_type','strategy_hash','system_hash','signal_id','primary_strategy','supporting_strategies']);}
    }
    return$base;
}
function tb_canonical_value($value)
{
    if(!is_array($value))return$value;
    $keys=array_keys($value);$isList=$value===[]||$keys===range(0,count($value)-1);
    if($isList){$out=[];foreach($value as$item)$out[]=tb_canonical_value($item);return$out;}
    ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=tb_canonical_value($item);return$value;
}
function tb_intent_snapshot(array $intent): array
{
    $contract=(string)($intent['intent_contract_rev']??'intent_v2');$schema=(string)($intent['schema']??'');$out=[];foreach(tb_intent_signed_fields($contract,$schema)as$key)$out[$key]=$intent[$key]??null;return$out;
}
function tb_intent_hash_from_snapshot(array $snapshot): string{return hash('sha256',tb_json(tb_canonical_value($snapshot),false));}
function tb_integrity_equal($left,$right): bool{return tb_json(tb_canonical_value($left),false)===tb_json(tb_canonical_value($right),false);}
function tb_order_integrity(array $order): array
{
    $snap=is_array($order['engine_intent']??null)?$order['engine_intent']:[];$hash=(string)($order['engine_intent_hash']??'');if(!$snap||$hash===''||!hash_equals(tb_intent_hash_from_snapshot($snap),$hash))return['ok'=>false,'reason'=>'HASH_MISMATCH'];
    $contract=(string)($snap['intent_contract_rev']??'intent_v2');$schema=(string)($snap['schema']??'');foreach(tb_intent_signed_fields($contract,$schema)as$key){
        if($key==='owner'){$actual=$order['intent_owner']??null;$expected=$snap[$key]??null;}
        elseif($key==='price'){$actual=$order['requested_price']??($order['price']??null);$expected=$snap[$key]??null;}
        elseif($key==='amount'){$actual=$order['requested_amount']??($order['amount']??null);$expected=$snap[$key]??null;}
        else{$actual=$order[$key]??null;$expected=$snap[$key]??null;}
        if(!tb_integrity_equal($actual,$expected))return['ok'=>false,'reason'=>'FIELD_'.$key];
    }
    return['ok'=>true,'reason'=>'OK'];
}
function tb_self_test(array $c): array
{
    $counts=tb_trade_list_exchange_counts((string)$c['trade_list_file']);$registry=is_array($c['strategy_registry']??null)?$c['strategy_registry']:[];$files=[];foreach($registry as$key=>$file)$files[$key]=['file'=>$file,'exists'=>is_file(__DIR__.'/'.$file)];$ok=PHP_VERSION_ID>=70400&&is_file($c['trade_list_file'])&&$counts['MISSING']===0&&!array_filter($files,static function($r){return empty($r['exists']);});return['ok'=>$ok,'broker'=>TB_VERSION,'rev'=>TB_REV,'schema'=>TB_SCHEMA,'intent_contracts'=>['intent_v2','intent_v3','intent_v4','intent_v5'],'capabilities'=>['persistent_sell_orders'=>true,'sell_priority_queue'=>true,'side_aware_signal_age'=>true,'accurate_open_position_exposure'=>true,'incremental_partial_fill_reporting'=>true,'submitted_order_lifecycle_protection'=>true,'buy_ttl_before_fill'=>true,'buy_ttl_before_real_send'=>true,'expired_real_buy_cancel'=>true,'staged_paper_fills'=>true,'adverse_paper_slippage'=>true,'fresh_quote_paper_fill'=>true,'jp_paper_live_requote'=>true,'jp_requote_retry_without_expired_cooldown'=>true,'immediate_current_mark_paper_fill'=>true,'hard_single_strategy_symbol'=>true,'current_mark_exposure'=>true,'current_equity_limits'=>true,'single_strategy_per_symbol_default'=>true,'normal_vs_urgent_exit'=>true,'intent_v4'=>true,'intent_v5'=>true,'three_plus_one_spec_v130'=>true,'three_plus_one_spec_v140'=>true,'swing_absence_contract'=>true,'challenger_real_guard'=>true,'attribution_preserved'=>true,'independent_1min_cron'=>true,'lock_busy_telemetry'=>true,'stuck_scenario_detection'=>true,'auto_approval_block_telemetry'=>true,'order_latency_metrics'=>true,'market_open_wait_observability'=>true,'market_wait_duration_tracking'=>true,'market_wait_latency_split'=>true,'correlation_group_guard'=>true,'global_correlation_concentration_guard'=>true,'broker_ingest_ack_telemetry'=>true,'shared_intent_lock_read'=>true,'broker_input_path_audit'=>true,'durable_intent_spool_merge'=>true,'broker_seen_ack_files'=>true,'single_file_runtime_authority'=>true],'mode'=>$c['mode'],'approval_mode'=>$c['approval_mode'],'runtime_writable'=>is_dir($c['runtime'])&&is_writable($c['runtime']),'trade_list_exchange_counts'=>$counts,'strategy_registry'=>$files,'validation'=>tb_validate_all($c,tb_orders($c))];
}

function tb_tick_table($raw): array
{
    if(is_array($raw)&&$raw)return$raw;
    return[[2000,1],[5000,5],[20000,10],[50000,50],[200000,100],[500000,500],[PHP_INT_MAX,1000]];
}
function tb_kr_tick(array $c,float $price): float
{
    foreach($c['kr_tick_table']as$row){if(!is_array($row)||count($row)<2)continue;if($price<(float)$row[0])return max(1.0,(float)$row[1]);}return 1.0;
}
function tb_normalize_order_price(array $c,array $o): array
{
    $market=strtoupper((string)($o['market']??''));$side=strtoupper((string)($o['side']??''));$price=max(0.0,(float)($o['price']??0));$qty=max(0,(int)($o['qty']??0));if($price<=0)return$o;
    $tick=$market==='KR'?tb_kr_tick($c,$price):($market==='JP'?1.0:($price>=1.0?0.01:0.0001));$units=$price/$tick;$normalized=$side==='SELL'?ceil($units-1.0E-10)*$tick:floor($units+1.0E-10)*$tick;
    if(in_array($market,['KR','JP'],true))$normalized=round($normalized,0);else$normalized=round($normalized,$price>=1.0?2:4);$o['price']=$normalized;$o['amount']=round($normalized*$qty,4);return$o;
}

function tb_trade_list_exchange_map(string $file): array
{
    if(!is_file($file))return[];$data=include $file;if(!is_array($data))return[];$out=[];
    foreach(['us','jp']as$key){foreach($data[$key]??[]as$row){if(!is_array($row)||(array_key_exists('enabled',$row)&&$row['enabled']!==true))continue;$symbol=strtoupper(trim((string)($row['symbol']??$row['code']??'')));$exchange=tb_norm_exchange((string)($row['exchange']??$row['exchange_code']??$row['market']??''));if($symbol!==''&&$exchange!=='')$out[$symbol]=$exchange;}}
    return$out;
}
function tb_trade_list_exchange_counts(string $file): array
{
    $counts=['US'=>0,'JP'=>0,'MISSING'=>0];if(!is_file($file))return$counts;$data=include $file;if(!is_array($data))return$counts;foreach(['us'=>'US','jp'=>'JP']as$key=>$market){foreach($data[$key]??[]as$row){if(!is_array($row)||(array_key_exists('enabled',$row)&&$row['enabled']!==true))continue;$e=tb_norm_exchange((string)($row['exchange']??$row['exchange_code']??$row['market']??''));if($e==='')$counts['MISSING']++;else$counts[$market]++;}}return$counts;
}
function tb_market_calendar_data(array $c,string $market): array
{
    $file=(string)($c['calendar_file']??'');if($file===''||!is_file($file))return['holidays'=>[],'early_closes'=>[]];$calendar=include $file;if(!is_array($calendar))return['holidays'=>[],'early_closes'=>[]];$m=strtoupper($market);$rows=$calendar[$m]??$calendar[strtolower($m)]??[];if(!is_array($rows))return['holidays'=>[],'early_closes'=>[]];return['holidays'=>is_array($rows['holidays']??null)?$rows['holidays']:[],'early_closes'=>is_array($rows['early_closes']??null)?$rows['early_closes']:[]];
}
function tb_calendar_holiday(array $c,string $market,string $date): bool{$d=tb_market_calendar_data($c,$market);return in_array($date,$d['holidays'],true)||!empty($d['holidays'][$date]);}
function tb_market_date(string $market): string{$tz=strtoupper($market)==='US'?'America/New_York':(strtoupper($market)==='JP'?'Asia/Tokyo':'Asia/Seoul');return(new DateTime('now',new DateTimeZone($tz)))->format('Y-m-d');}
function tb_market_open(array $c,array $o): bool{$market=strtoupper((string)($o['market']??''));$tz=$market==='US'?'America/New_York':($market==='JP'?'Asia/Tokyo':'Asia/Seoul');return tb_market_open_at($c,$o,new DateTime('now',new DateTimeZone($tz)));}
function tb_market_open_at(array $c,array $o,DateTimeInterface $dt): bool
{
    $market=strtoupper((string)($o['market']??''));$tz=$market==='US'?'America/New_York':($market==='JP'?'Asia/Tokyo':'Asia/Seoul');$local=(new DateTimeImmutable('@'.$dt->getTimestamp()))->setTimezone(new DateTimeZone($tz));$date=$local->format('Y-m-d');if((int)$local->format('N')>=6||tb_calendar_holiday($c,$market,$date))return false;$hm=$local->format('H:i');$cal=tb_market_calendar_data($c,$market);$close=(string)($cal['early_closes'][$date]??($market==='US'?'16:00':'15:30'));
    if($market==='KR')return$hm>='09:00'&&$hm<$close;if($market==='JP')return($hm>='09:00'&&$hm<'11:30')||($hm>='12:30'&&$hm<$close);if($market==='US')return$hm>='09:30'&&$hm<$close;return false;
}

function tb_exchange(array $c,array $o): string{$x=tb_norm_exchange((string)($o['exchange_code']??$o['exchange']??''));if($x!=='')return$x;$s=strtoupper((string)($o['symbol']??''));return(string)($c['exchange_map'][$s]??$c['us_exchange_map'][$s]??'');}
function tb_norm_exchange(string $x): string{$x=strtoupper(preg_replace('/[^A-Z0-9]/','',$x));if(in_array($x,['NASD','NAS','NASDAQ','NMS','NGM'],true))return'NASD';if(in_array($x,['NYSE','NYS','NYQ'],true))return'NYSE';if(in_array($x,['AMEX','AMS','ASE','ARCA','NYSEARCA'],true))return'AMEX';if(in_array($x,['TKSE','TSE','TYO','TOKYO'],true))return'TKSE';return'';}
function tb_quote_exchange(string $x): string{return$x==='NASD'?'NAS':($x==='NYSE'?'NYS':($x==='AMEX'?'AMS':($x==='TKSE'?'TSE':'')));}
function tb_exchange_map($v): array{if(!is_array($v)){$j=json_decode((string)$v,true);$v=is_array($j)?$j:[];}$o=[];foreach($v as$k=>$x){$e=tb_norm_exchange((string)$x);if($e!=='')$o[strtoupper((string)$k)]=$e;}return$o;}
function tb_list($v): array{$a=is_array($v)?$v:preg_split('/[,;|\s]+/',(string)$v);$o=[];foreach($a as$x){$x=strtolower(trim((string)$x));if($x!=='')$o[$x]=true;}return array_keys($o);}
function tb_bool($v): bool{if(is_bool($v))return$v;if(is_numeric($v))return(int)$v!==0;return in_array(strtolower(trim((string)$v)),['1','true','yes','on','y'],true);}
function tb_ts($v): int{if(is_numeric($v))return(int)$v;$t=strtotime((string)$v);return$t===false?0:$t;}
function tb_num($v): float{if(is_numeric($v))return(float)$v;$x=str_replace([',','$','원','%',' '],'',(string)$v);return is_numeric($x)?(float)$x:0.0;}
function tb_price(float $v,string $market='US'): string{if(strtoupper($market)==='JP')return(string)(int)round($v);$s=number_format($v,4,'.','');return rtrim(rtrim($s,'0'),'.');}
function tb_journal(array $c, array $row): void
{
    $root = tb_load($c['journal_file'], []);
    $rows = is_array($root['rows'] ?? null) ? $root['rows'] : [];
    $rows[] = $row;
    if (count($rows) > 5000) $rows = array_slice($rows, -5000);
    tb_save($c['journal_file'], ['schema'=>TB_SCHEMA, 'owner'=>'broker', 'updated_at'=>tb_now(), 'rows'=>$rows]);
}

function tb_load(string $f,$d){if(!is_file($f))return$d;$x=@file_get_contents($f);$j=json_decode((string)$x,true);return is_array($j)?$j:$d;}
function tb_save(string $f,$d): void{$dir=dirname($f);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('DIR_CREATE_FAILED '.$dir);$tmp=$f.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(2));$json=tb_json($d,true);if($json===''&&$d!==''&&$d!==[])throw new RuntimeException('JSON_ENCODE_FAILED '.$f);if(@file_put_contents($tmp,$json.PHP_EOL,LOCK_EX)===false){@unlink($tmp);throw new RuntimeException('FILE_WRITE_FAILED '.$f);}if(!@rename($tmp,$f)){@unlink($tmp);throw new RuntimeException('FILE_RENAME_FAILED '.$f);}}
function tb_log(array $c,string $s): void{tb_rotate_file((string)$c['log_file'],TB_LOG_MAX_BYTES);@file_put_contents($c['log_file'],'['.tb_now().'] '.$s.PHP_EOL,FILE_APPEND|LOCK_EX);}
function tb_tail(string $f,int $n): string{if(!is_file($f))return'';$a=@file($f,FILE_IGNORE_NEW_LINES);return$a?implode("\n",array_slice($a,-$n)):'';}
function tb_json($d,bool $p=false): string{return(string)json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|($p?JSON_PRETTY_PRINT:0));}
function tb_h(string $s): string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function tb_now(): string{return date('Y-m-d H:i:s');}

if(!defined('TB_TEST_MODE'))tb_main();