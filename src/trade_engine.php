<?php
/**
 * trade_engine.php
 * Unified Trade Engine v4.4.4 — 3+1 v1.4 · daily cache content-freshness · active-position exit freshness/recovery · market-timezone
 * PHP 7.4 compatible
 *
 * 역할
 * - 시세/완성봉/전략 상태/포지션/자본/손익/통계의 유일한 관리자
 * - 모델의 BUY/SELL 판단을 주문 의도(order_intents.json)로 변환
 * - 브로커 체결(broker_orders.json)을 반영한 뒤에만 포지션 생성/청산
 *
 * 하지 않는 일
 * - KIS 주문 전송, 승인, 체결조회, 잔고조회
 * - 전략 조건 판단
 *
 * 다운로드/보관 파일명: trade_engine_v443.php
 * 운영 파일명: trade_engine.php
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors', '0');
error_reporting(E_ALL);
@ini_set('memory_limit', '192M');
@set_time_limit(0);

const TE_VERSION = 'v4.4.5 3+1-v1.4 · RUNTIME-AUTHORITY-UNIFIED · DAILY-CONTENT-FRESHNESS · EXIT-FRESHNESS-RECOVERY · KR-US-JP';
const TE_REV = 'trade-engine-v445-single-file-runtime-authority-20260922-r2';
const TE_SCHEMA = 'te_v25';
const TE_QUOTE_FRESH_MAX_AGE = 300;
const TE_QUOTE_SCAN_MAX_AGE_KR = 300;
const TE_QUOTE_SCAN_MAX_AGE_US = 300;
const TE_QUOTE_SCAN_MAX_AGE_JP = 1200;
const TE_ORDER_LIVE_QUOTE_MAX_AGE = 60;
const TE_HTTP_CONNECT_TIMEOUT = 5;
const TE_HTTP_TIMEOUT = 12;
const TE_QUOTE_TTL_OPEN = 20;
const TE_QUOTE_TTL_CLOSED = 300;
const TE_BARS_TTL_OPEN = 600;
const TE_BARS_TTL_1M_OPEN = 20;
const TE_BARS_TTL_CLOSED = 21600;
const TE_DAILY_STALE_RETRY_SEC = 300;
const TE_INTENT_TTL_AUTO = 2100;
const TE_INTENT_TTL_MANUAL = 7200;
const TE_SELL_INTENT_TTL_SEC = 2592000;
const TE_MIN_ENTRY_RR = 1.50;
const TE_SCAN_STALE_WARN_SEC = 7200;
const TE_KR_BUY_FEE = 0.00015;
const TE_KR_SELL_COST = 0.00195;
const TE_US_BUY_FEE = 0.00100;
const TE_US_SELL_COST = 0.00100;
const TE_JP_BUY_FEE = 0.00100;
const TE_JP_SELL_COST = 0.00100;
const TE_ANALYSIS_MAX_BYTES = 8388608;
const TE_LOG_MAX_BYTES = 2097152;
const TE_LOG_ROTATIONS = 3;
const TE_ANALYSIS_SNAPSHOT_KEEP_ALL = 12;
const TE_ANALYSIS_SNAPSHOT_KEEP_STRATEGY = 24;
const TE_BROKER_STALE_SEC = 180;
const TE_BROKER_EXPECTED_CYCLE_SEC = 60;
const TE_BROKER_INGEST_WARN_SEC = 180;
const TE_INTENT_SPOOL_SCHEMA = 'te_intent_spool_v1';
const TE_INTENT_ACK_SCHEMA = 'broker_intent_ack_v1';
const TE_STRATEGY_EXPECTED_CYCLE_SEC = 1200;
const TE_INTENT_CONTRACT_REV = 'intent_v5';
const TE_CORE_CONTRACT = 'core_contract_v500';
const TE_BENCHMARK_RETRY_COUNT = 2;
const TE_BENCHMARK_LAG_MAX_BUSINESS_DAYS = 1;
const TE_EXPIRED_COOLDOWN_SEC = 3600;
const TE_SCENARIO_COOLDOWN_SEC = 86400;
const TE_STOP_REENTRY_BUSINESS_DAYS = 1;
const TE_MAX_MARKET_INVEST_PCT = 0.80;
const TE_MIN_MARKET_CASH_PCT = 0.20;
const TE_DEFAULT_MAX_POSITIONS_PER_MARKET = 5;
const TE_DEFAULT_MAX_POSITIONS_TOTAL = 15;
const TE_DEFAULT_MAX_CORRELATION_POSITIONS_PER_STRATEGY = 2;
const TE_DEFAULT_MAX_CORRELATION_POSITIONS_GLOBAL = 2;
const TE_VALIDATION_SCHEMA = 'trade_validation_event_v2';
const TE_VALIDATION_MIN_USABLE_SAMPLES = 30;
const TE_VALIDATION_MATURE_SAMPLES = 100;
const TE_VALIDATION_MAX_RECORDS = 5000;
const TE_VALIDATION_MAX_RESOLVE_PER_TICK = 40;

$teValidationLib=__DIR__.'/trade_validation.php';
if(!is_file($teValidationLib)) throw new RuntimeException('trade_validation.php 파일이 필요합니다.');
require_once $teValidationLib;
const TE_VALIDATION_EXPIRE_DAYS = 120;
const TE_VALIDATION_RECENT_RESULTS = 100;


function te_run(array $spec): void
{
    $cfg = te_config($spec);
    te_dirs($cfg);
    te_register_shutdown_handler($cfg);

    if (PHP_SAPI === 'cli') {
        global $argv;
        $mode = strtolower((string)($argv[1] ?? 'status'));
        // Backward-compatible CLI alias: legacy/Task Scheduler entries often use `cron`.
        // Both `tick` and `cron` must execute a real strategy tick; unknown modes remain status-only.
        if ($mode === 'cron') $mode = 'tick';
        if ($mode === 'tick') {
            $r = te_tick($cfg);
            echo te_cli_summary($r) . PHP_EOL;if(empty($r['ok']))exit(2);
            return;
        }
        if (in_array($mode, ['live_tick','priority_tick'], true)) {
            $market = strtoupper((string)($argv[2] ?? ''));
            $symbol = strtoupper((string)($argv[3] ?? ''));
            $r=te_priority_tick($cfg,$market,$symbol);echo te_json($r,true).PHP_EOL;if(empty($r['ok']))exit(2);
            return;
        }
        if (in_array($mode, ['health','self_test','validate'], true)) {
            $r=te_self_test($cfg);echo te_json($r,true).PHP_EOL;if(empty($r['ok']))exit(2);
            return;
        }
        if (in_array($mode, ['validation','validation_sweep'], true)) {
            $r=te_validation_resolve_due_samples($cfg,te_market_status($cfg),true);echo te_json($r,true).PHP_EOL;if(empty($r['ok']))exit(2);
            return;
        }
        if (in_array($mode, ['save_analysis','analysis_snapshot','snapshot'], true)) {
            $r=te_save_analysis_snapshot($cfg,false);echo te_json($r,true).PHP_EOL;if(empty($r['ok']))exit(2);
            return;
        }
        if (in_array($mode, ['save_all_analysis','analysis_snapshot_all','snapshot_all'], true)) {
            $r=te_save_analysis_snapshot($cfg,true);echo te_json($r,true).PHP_EOL;if(empty($r['ok']))exit(2);
            return;
        }
        echo te_json(te_status($cfg), true) . PHP_EOL;
        return;
    }

    $mode = strtolower((string)($_GET['mode'] ?? ''));
    if ($mode === 'status_json') { te_json_response(te_status($cfg)); return; }
    if ($mode === 'health') { te_json_response(te_self_test($cfg)); return; }
    if ($mode === 'download_analysis') {
        te_download_analysis($cfg, (string)($_GET['strategy'] ?? $cfg['strategy_key']), false);
        return;
    }
    if ($mode === 'download_all_analysis') {
        te_download_analysis($cfg, '', true);
        return;
    }
    if ($mode === 'download_chatgpt_analysis') {
        // Unified export. This route must be invoked through a strategy entry point
        // (abc.php/dts.php/stc26.php/das.php), because trade_engine.php is a library.
        te_download_analysis($cfg, '', true, true);
        return;
    }
    if ($mode === 'download_chatgpt_strategy_analysis') {
        // Per-strategy ChatGPT export for the strategy page currently executing te_run().
        // Ignore a foreign strategy query parameter to prevent a page from silently exporting another strategy.
        te_download_analysis($cfg, (string)$cfg['strategy_key'], false, true);
        return;
    }
    if (in_array($mode, ['save_analysis','analysis_snapshot','snapshot','save_all_analysis','analysis_snapshot_all','snapshot_all'], true)) {
        http_response_code(405);
        te_json_response(['ok'=>false,'message'=>'분석 스냅샷 저장은 CLI 전용입니다.']);
        return;
    }
    if ($mode === 'tick' || isset($_GET['tick'])) {
        te_json_response(['ok'=>false,'message'=>'CRON ONLY: php '.basename((string)$cfg['strategy_file_id']).' tick']);
        return;
    }
    te_render($cfg);
}

function te_strategy_filename(string $key): string
{
    $files=['abc'=>'abc.php','dts'=>'dts.php','stc26'=>'stc26.php','das'=>'das.php'];
    return $files[$key]??($key.'.php');
}

function te_normalize_entry_contract(string $contract): string
{
    $contract=strtoupper(trim($contract));return in_array($contract,['STOP_BASED','CROSS_BASED','RANK_BASED','TIME_BASED'],true)?$contract:'STOP_BASED';
}
function te_normalize_sizing_mode(string $mode): string
{
    $mode=strtoupper(trim($mode));return in_array($mode,['RISK_STOP','ALLOCATION_ONLY'],true)?$mode:'RISK_STOP';
}


function te_validation_spec_normalize($raw,string $strategyKey): array
{
    $base=[
        'enabled'=>false,'signals'=>['BUY','SELL'],'horizons'=>[],'primary_horizon'=>'',
        'minimum_usable_samples'=>TE_VALIDATION_MIN_USABLE_SAMPLES,
        'register_ranked_candidates'=>false,'ranked_max_per_market'=>0,
        'include_risk_exits_in_stats'=>false,'anchor_timeframe'=>'',
        'max_records'=>TE_VALIDATION_MAX_RECORDS,'expire_days'=>TE_VALIDATION_EXPIRE_DAYS,
    ];
    if(!is_array($raw))return$base;
    $v=array_replace($base,$raw);$v['enabled']=!empty($v['enabled']);
    $signals=[];foreach(is_array($v['signals']??null)?$v['signals']:[]as$side){$side=strtoupper(trim((string)$side));if(in_array($side,['BUY','SELL'],true))$signals[]=$side;}$v['signals']=array_values(array_unique($signals?:['BUY','SELL']));
    $h=[];foreach(is_array($v['horizons']??null)?$v['horizons']:[]as$row){if(!is_array($row))continue;$type=strtoupper(trim((string)($row['type']??'')));$value=max(0,(int)($row['value']??0));if(!in_array($type,['MINUTES','TRADING_DAYS','SESSION_CLOSE'],true))continue;if($type!=='SESSION_CLOSE'&&$value<1)continue;$label=trim((string)($row['label']??''));if($label==='')$label=$type==='MINUTES'?$value.'m':($type==='TRADING_DAYS'?$value.'d':'close');$h[]=['type'=>$type,'value'=>$value,'label'=>$label];}
    $v['horizons']=$h;$labels=array_column($h,'label');$primary=trim((string)($v['primary_horizon']??''));$v['primary_horizon']=in_array($primary,$labels,true)?$primary:(string)($labels[0]??'');
    $v['minimum_usable_samples']=max(1,(int)($v['minimum_usable_samples']??TE_VALIDATION_MIN_USABLE_SAMPLES));
    $v['ranked_max_per_market']=max(0,min(50,(int)($v['ranked_max_per_market']??0)));$v['register_ranked_candidates']=!empty($v['register_ranked_candidates'])&&$v['ranked_max_per_market']>0;
    $v['include_risk_exits_in_stats']=!empty($v['include_risk_exits_in_stats']);$v['anchor_timeframe']=trim((string)($v['anchor_timeframe']??''));
    $v['max_records']=max(100,min(20000,(int)($v['max_records']??TE_VALIDATION_MAX_RECORDS)));$v['expire_days']=max(7,min(365,(int)($v['expire_days']??TE_VALIDATION_EXPIRE_DAYS)));
    if(!$h)$v['enabled']=false;return$v;
}


function te_runtime_authority_context(): array
{
    static $cache=null;
    if(is_array($cache))return $cache;
    $marker=__DIR__.'/trade_phase3b_lite_v100/authority.json';
    $row=[];
    if(is_file($marker)){
        $raw=@file_get_contents($marker);
        $j=json_decode((string)$raw,true);
        if(is_array($j))$row=$j;
    }
    $authority=strtoupper(trim((string)($row['authority']??'')));
    $realAllowed=!empty($row['real_order_allowed']);
    $singlePaper=$authority==='SINGLE_FILE_PAPER'&&!$realAllowed;
    $legacyBroker=__DIR__.'/trade_runtime';
    $legacyValidation=__DIR__.'/validation_runtime';
    $compatRoot=__DIR__.'/trade_single_compat';
    $cache=[
        'marker'=>$marker,
        'marker_readable'=>!empty($row),
        'authority'=>$authority!==''?$authority:'LEGACY_OR_UNMARKED',
        'real_order_allowed'=>$realAllowed,
        'single_file_paper'=>$singlePaper,
        'broker_runtime'=>$singlePaper?$compatRoot.'/trade_runtime':$legacyBroker,
        'validation_runtime'=>$singlePaper?$compatRoot.'/validation_runtime':$legacyValidation,
        'legacy_broker_runtime'=>$legacyBroker,
        'legacy_validation_runtime'=>$legacyValidation,
    ];
    return $cache;
}

function te_runtime_path_same(string $a,string $b): bool
{
    $ar=realpath($a);$br=realpath($b);
    if($ar!==false&&$br!==false)return $ar===$br;
    return rtrim(str_replace('\\','/',$a),'/')===rtrim(str_replace('\\','/',$b),'/');
}

function te_strategy_spec(array $specific): array
{
    $auth=te_runtime_authority_context();
    $key = strtolower((string)($specific['strategy_key'] ?? $specific['app_key'] ?? ''));
    if ($key === '' || !preg_match('/^[a-z0-9_-]+$/', $key)) throw new InvalidArgumentException('STRATEGY_KEY_INVALID');
    if (!in_array($key, ['dts','abc','das','stc26'], true)) throw new InvalidArgumentException('STRATEGY_NOT_IN_V140_ALLOWLIST');
    $base = [
        'app_key'=>$key,
        'strategy_key'=>$key,
        'strategy_label'=>strtoupper($key),
        'strategy_file_id'=>te_strategy_filename($key),
        'strategy_contract_version'=>TE_CORE_CONTRACT,
        'runtime_dir'=>__DIR__.'/'.$key.'_runtime',
        'broker_runtime_dir'=>$auth['broker_runtime'],
        'trade_list_file'=>__DIR__.'/trade_list.php',
        'shared_cache_dir'=>__DIR__.'/trade_cache',
        'seed_kr'=>10000000.0,'seed_us'=>6000.0,'seed_jp'=>1000000.0,
        'max_positions'=>['KR'=>5,'US'=>5,'JP'=>5],
        'max_positions_total'=>15,
        'max_correlation_positions_per_strategy'=>TE_DEFAULT_MAX_CORRELATION_POSITIONS_PER_STRATEGY,
        'max_correlation_positions_global'=>TE_DEFAULT_MAX_CORRELATION_POSITIONS_GLOBAL,
        'required_engine_capabilities'=>[TE_CORE_CONTRACT],
        'entry_contract'=>'STOP_BASED',
        'sizing_mode'=>'RISK_STOP',
        'expected_cycle_sec'=>TE_STRATEGY_EXPECTED_CYCLE_SEC,
        'data_date_timeframe'=>'1d',
        'scan_date_mode'=>'COMPLETED_DAILY',
        'benchmark_gate_required'=>true,
        'admission_mode'=>'FULL_SCAN',
        'candidate_basis'=>'FINAL_CLOSE',
        'validation'=>['enabled'=>false,'horizons'=>[]],
        'opportunity_callback'=>'','daily_opportunity_policy'=>['enabled'=>false,'target_buys_per_market'=>1,'allocation_scale'=>0.25,'max_soft_attempts_per_market'=>1],
        'validation_runtime_dir'=>__DIR__.'/validation_runtime',
        'strategy_status'=>'CORE','alpha_type'=>'UNKNOWN','strategy_version'=>'','strategy_parameters'=>[],'data_provider_revision'=>'engine-default',
    ];
    $spec=array_replace($base,$specific);if(!empty($auth['single_file_paper'])){$spec['broker_runtime_dir']=$auth['broker_runtime'];}$spec['entry_contract']=te_normalize_entry_contract((string)($spec['entry_contract']??'STOP_BASED'));$spec['sizing_mode']=te_normalize_sizing_mode((string)($spec['sizing_mode']??($spec['entry_contract']==='STOP_BASED'?'RISK_STOP':'ALLOCATION_ONLY')));
    $extra=is_array($specific['required_engine_capabilities']??null)?$specific['required_engine_capabilities']:[];
    $spec['required_engine_capabilities']=array_values(array_unique(array_merge([TE_CORE_CONTRACT],array_map('strval',$extra))));
    foreach(['app_name','app_ver','strategy_rev','model_callback','exit_callback','requirements'] as $field) {
        if (!array_key_exists($field,$spec) || $spec[$field]==='' || $spec[$field]===[]) throw new InvalidArgumentException('STRATEGY_SPEC_MISSING_'.$field);
    }
    return $spec;
}

function te_engine_capabilities(array $s,string $rankCallback): array
{
    $stable=[
        TE_CORE_CONTRACT,'atomic_full_market_scan','max_positions_by_market','strategy_market_slots_configurable',
        'completed_daily_bars','completed_60m_bars','benchmark_daily_bars','sector_benchmark_daily_bars','external_risk_overlay','strategy_owned_lots','market_calendar',
        'intent_lifecycle','persistent_sell_intent','broker_1min_cron','strategy_20min_cron','independent_scheduler',
        'hard_risk_gate_all_entry_paths','accurate_open_position_exposure','incremental_partial_fill_accounting',
        'shared_market_cache','currency_separated_statistics','hard_target_exit','daily_loss_entry_only',
        'minimum_entry_rr','progressive_loss_cut_ladder','initial_stop_width_guard','existing_position_stop_floor',
        'profit_exit_gate','delayed_trailing_arm','correlation_group_entry_guard','global_correlation_concentration_guard',
        'legacy_overlimit_hold_only','resilient_benchmark_gate','scenario_fingerprint','order_reissue_cooldown',
        'stop_reentry_cooldown','risk_group_exposure_metadata','complete_scan_entry_gate','ranked_data_status_preservation',
        'revision_based_position_cohort','analysis_v11','trade_list_v1_8','jp_market_support','jpy_capital','market_specific_scan_limit',
        'completed_1m_bars',
        'cross_based_entry_contract','rank_based_entry_contract','allocation_only_sizing','strategy_contract_separation',
        'normal_vs_urgent_exit','strategy_1min_cron','intraday_data_date_basis','strategy_scan_date_mode',
        'per_batch_admission','common_forward_validation','signal_pipeline_outcome_separation','shared_validation_runtime','current_mark_portfolio_guard','one_minute_cache_resilience','hard_stale_exit_refresh_recheck','fresh_quote_fallback','tick_cycle_telemetry',
        'three_plus_one_spec_v130','three_plus_one_spec_v140','audit_event_layer','strategy_hash','system_hash','challenger_shadow','swing_absence_contract','signal_before_selection','broker_ingest_missing_diagnostic','broker_order_missing_after_ingest','durable_intent_spool','broker_seen_ack_required_before_terminal_expiry','analysis_handoff_observability','daily_opportunity_soft_target','opportunity_small_entry','ms7_opportunity_stage','daily_cache_content_freshness','single_file_runtime_authority','legacy_transport_spool_recovery'
    ];
    $caps=array_fill_keys($stable,true);
    $caps['rank_callback']=$rankCallback!==''&&function_exists($rankCallback);
    $caps['entry_guard_callback']=((string)($s['entry_guard_callback']??''))!==''&&function_exists((string)$s['entry_guard_callback']);
    $caps['opportunity_callback']=((string)($s['opportunity_callback']??''))!==''&&function_exists((string)$s['opportunity_callback']);
    $caps['stateful_strategy']=true;
    return $caps;
}

function te_config(array $s): array
{
    $auth=te_runtime_authority_context();
    $app = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($s['app_key'] ?? 'trade'));
    $runtime = (string)($s['runtime_dir'] ?? (__DIR__.'/'.$app.'_runtime'));
    $broker = (string)($s['broker_runtime_dir'] ?? $auth['broker_runtime']);
    if(!empty($auth['single_file_paper']))$broker=(string)$auth['broker_runtime'];
    $strategyKey = strtolower((string)($s['strategy_key'] ?? $app));

    $defaults = [
        'KR' => [
            'min_price'=>1000.0,
            'min_avg_volume'=>30000.0,
            'min_avg_turnover'=>300000000.0,
            'max_gap_pct'=>8.0,
            'atr_low'=>0.40,
            'atr_ideal_low'=>1.00,
            'atr_ideal_high'=>4.50,
            'atr_high'=>9.00,
        ],
        'US' => [
            'min_price'=>3.0,
            'min_avg_volume'=>75000.0,
            'min_avg_turnover'=>3000000.0,
            'max_gap_pct'=>8.0,
            'atr_low'=>0.50,
            'atr_ideal_low'=>1.00,
            'atr_ideal_high'=>5.00,
            'atr_high'=>10.00,
        ],
        'JP' => [
            'min_price'=>100.0,
            'min_avg_volume'=>20000.0,
            'min_avg_turnover'=>100000000.0,
            'max_gap_pct'=>8.0,
            'atr_low'=>0.40,
            'atr_ideal_low'=>1.00,
            'atr_ideal_high'=>5.00,
            'atr_high'=>10.00,
        ],
    ];
    $overrides = is_array($s['strategy_settings'] ?? null) ? $s['strategy_settings'] : [];
    foreach (['KR','US','JP'] as $m) {
        if (isset($overrides[$m]) && is_array($overrides[$m])) {
            foreach ($overrides[$m] as $k=>$v) if (is_numeric($v)) $defaults[$m][$k]=(float)$v;
        }
    }
    $maxRaw = is_array($s['max_positions'] ?? null) ? $s['max_positions'] : [];
    $maxByMarket = [
        'KR'=>max(1,(int)($maxRaw['KR'] ?? $s['max_positions_kr'] ?? TE_DEFAULT_MAX_POSITIONS_PER_MARKET)),
        'US'=>max(1,(int)($maxRaw['US'] ?? $s['max_positions_us'] ?? TE_DEFAULT_MAX_POSITIONS_PER_MARKET)),
        'JP'=>max(1,(int)($maxRaw['JP'] ?? $s['max_positions_jp'] ?? TE_DEFAULT_MAX_POSITIONS_PER_MARKET)),
    ];
    $rankCallback=(string)($s['rank_callback'] ?? '');
    $sharedCache=(string)($s['shared_cache_dir'] ?? (__DIR__.'/trade_cache'));
    $scanLimit=max(1,min(30,(int)($s['scan_limit'] ?? 20)));
    $scanLimitRaw=is_array($s['scan_limit_by_market'] ?? null)?$s['scan_limit_by_market']:[];
    $scanLimitByMarket=[
        'KR'=>max(1,min(40,(int)($scanLimitRaw['KR'] ?? $scanLimit))),
        'US'=>max(1,min(40,(int)($scanLimitRaw['US'] ?? $scanLimit))),
        'JP'=>max(1,min(40,(int)($scanLimitRaw['JP'] ?? $scanLimit))),
    ];
    $entryContract=te_normalize_entry_contract((string)($s['entry_contract']??'STOP_BASED'));
    $sizingMode=te_normalize_sizing_mode((string)($s['sizing_mode']??($entryContract==='STOP_BASED'?'RISK_STOP':'ALLOCATION_ONLY')));
    $expectedCycle=max(60,(int)($s['expected_cycle_sec']??TE_STRATEGY_EXPECTED_CYCLE_SEC));
    $requirements=is_array($s['requirements']??null)?$s['requirements']:[];
    $dataDateTimeframe=(string)($s['data_date_timeframe']??'1d');
    if($dataDateTimeframe===''||!array_key_exists($dataDateTimeframe,$requirements))$dataDateTimeframe=(string)(array_key_first($requirements)??'1d');
    $scanDateMode=strtoupper((string)($s['scan_date_mode']??'COMPLETED_DAILY'));
    if(!in_array($scanDateMode,['COMPLETED_DAILY','SESSION_DATE','CONTEXT_DATE'],true))$scanDateMode='COMPLETED_DAILY';
    $benchmarkGateRequired=array_key_exists('benchmark_gate_required',$s)?(bool)$s['benchmark_gate_required']:true;
    $admissionMode=strtoupper((string)($s['admission_mode']??'FULL_SCAN'));
    if(!in_array($admissionMode,['FULL_SCAN','PER_BATCH'],true))$admissionMode='FULL_SCAN';
    $candidateBasis=trim((string)($s['candidate_basis']??($admissionMode==='PER_BATCH'?'INTRADAY_ROTATING':'FINAL_CLOSE')));
    if($candidateBasis==='')$candidateBasis=$admissionMode==='PER_BATCH'?'INTRADAY_ROTATING':'FINAL_CLOSE';
    $validation=te_validation_spec_normalize($s['validation']??null,$strategyKey);$validationRuntime=(string)($s['validation_runtime_dir']??(__DIR__.'/validation_runtime'));
    $strategyStatus=strtoupper(trim((string)($s['strategy_status']??'CORE')));if(!in_array($strategyStatus,['CORE','CHALLENGER'],true))throw new InvalidArgumentException('STRATEGY_STATUS_NOT_EXECUTABLE_V140');
    $alphaType=strtoupper(trim((string)($s['alpha_type']??'UNKNOWN')));
    $strategyVersion=(string)($s['strategy_version']??'');$strategyParameters=is_array($s['strategy_parameters']??null)?$s['strategy_parameters']:[];
    $opportunityCallback=(string)($s['opportunity_callback']??'');
    $opRaw=is_array($s['daily_opportunity_policy']??null)?$s['daily_opportunity_policy']:[];
    $dailyOpportunityPolicy=[
        'enabled'=>!empty($opRaw['enabled']),
        'target_buys_per_market'=>max(0,min(3,(int)($opRaw['target_buys_per_market']??1))),
        'allocation_scale'=>max(0.05,min(0.50,(float)($opRaw['allocation_scale']??0.25))),
        'max_soft_attempts_per_market'=>max(1,min(2,(int)($opRaw['max_soft_attempts_per_market']??1))),
    ];

    return [
        'app_key'=>$app,'app_name'=>(string)($s['app_name'] ?? 'Trade Engine'),'app_ver'=>(string)($s['app_ver'] ?? ''),
        'strategy_key'=>$strategyKey,
        'strategy_label'=>(string)($s['strategy_label'] ?? strtoupper((string)($s['strategy_key'] ?? $app))),
        'compare_models'=>te_compare_config($s['compare_models'] ?? null),
        'strategy_file_id'=>(string)($s['strategy_file_id'] ?? basename($_SERVER['SCRIPT_FILENAME'] ?? 'strategy.php')),
        'strategy_rev'=>(string)($s['strategy_rev'] ?? 'strategy-rev'),'strategy_version'=>$strategyVersion,'strategy_status'=>$strategyStatus,'alpha_type'=>$alphaType,'strategy_parameters'=>$strategyParameters,'data_provider_revision'=>(string)($s['data_provider_revision']??'engine-default'),'strategy_contract_version'=>(string)($s['strategy_contract_version'] ?? 'strategy_contract_v4'),'entry_contract'=>$entryContract,'sizing_mode'=>$sizingMode,'expected_cycle_sec'=>$expectedCycle,
        'runtime'=>$runtime,'cache'=>$sharedCache,'quote_cache'=>$sharedCache.'/quote','bars_cache'=>$sharedCache.'/bars',
        'runtime_authority'=>(string)$auth['authority'],'single_file_paper'=>!empty($auth['single_file_paper']),'runtime_authority_marker'=>(string)$auth['marker'],'legacy_broker_runtime'=>(string)$auth['legacy_broker_runtime'],'legacy_validation_runtime'=>(string)$auth['legacy_validation_runtime'],
        'state_file'=>$runtime.'/engine_state.json','model_state_file'=>$runtime.'/model_state.json',
        'positions_file'=>$runtime.'/positions.json','trades_file'=>$runtime.'/trades.json','candidates_file'=>$runtime.'/candidates.json',
        'capital_file'=>$runtime.'/capital.json','stats_file'=>$runtime.'/stats.json','fill_ledger_file'=>$runtime.'/fill_ledger.json',
        'regime_file'=>$runtime.'/market_regime.json','scan_progress_file'=>$runtime.'/scan_progress.json',
        'close_snapshot_file'=>$runtime.'/close_scan_snapshot.json','scan_stage_file'=>$runtime.'/scan_staging.json',
        'risk_state_file'=>$runtime.'/risk_state.json','daily_opportunity_state_file'=>$broker.'/daily_opportunity_state.json','risk_policy'=>is_array($s['risk_policy']??null)?$s['risk_policy']:[],'entry_guard_callback'=>(string)($s['entry_guard_callback']??''),'extension_config'=>is_array($s['extension_config']??null)?$s['extension_config']:[],'validation'=>$validation,'validation_runtime'=>$validationRuntime,'benchmark_state_file'=>$runtime.'/benchmark_source.json','admission_state_file'=>$runtime.'/admission_state.json','calendar_file'=>(string)($s['calendar_file']??(__DIR__.'/market_calendar.local.php')),'external_risk_file'=>(string)($s['external_risk_file']??(__DIR__.'/external_risk.local.json')),
        'trade_list_file'=>(string)($s['trade_list_file']??(__DIR__.'/trade_list.php')),
        'lock_file'=>$runtime.'/engine.lock','log_file'=>$runtime.'/engine.log','error_file'=>$runtime.'/error.log',
        'broker_runtime'=>$broker,'intents_file'=>$broker.'/order_intents.json','intents_archive_file'=>$broker.'/order_intents_archive.json','intents_lock'=>$broker.'/order_intents.lock','intent_spool_dir'=>$broker.'/intent_spool','intent_ack_dir'=>$broker.'/intent_ack',
        'broker_orders_file'=>$broker.'/broker_orders.json','broker_account_file'=>$broker.'/broker_account.json',
        'broker_heartbeat_file'=>$broker.'/broker_heartbeat.json','broker_lock_stats_file'=>$broker.'/broker_lock_stats.json','broker_journal_file'=>$broker.'/broker_journal.json','broker_approval_mode_file'=>$broker.'/approval_mode.json','broker_kill_file'=>$broker.'/broker_kill.flag',
        'seed_kr'=>(float)($s['seed_kr'] ?? 10000000.0),'seed_us'=>(float)($s['seed_us'] ?? 6000.0),'seed_jp'=>(float)($s['seed_jp'] ?? 1000000.0),
        'max_positions_total'=>max(1,(int)($s['max_positions_total'] ?? TE_DEFAULT_MAX_POSITIONS_TOTAL)),
        'max_positions_by_market'=>$maxByMarket,
        'max_correlation_positions_per_strategy'=>max(1,(int)($s['max_correlation_positions_per_strategy'] ?? TE_DEFAULT_MAX_CORRELATION_POSITIONS_PER_STRATEGY)),
        'max_correlation_positions_global'=>max(1,(int)($s['max_correlation_positions_global'] ?? TE_DEFAULT_MAX_CORRELATION_POSITIONS_GLOBAL)),
        'risk_budget_pct'=>max(0.001,(float)($s['risk_budget_pct'] ?? 0.0125)),
        'one_share_risk_cap_pct'=>max(0.0,(float)($s['one_share_risk_cap_pct'] ?? 0.0175)),
        'intent_ttl_auto'=>max(300,(int)($s['intent_ttl_auto'] ?? TE_INTENT_TTL_AUTO)),
        'intent_ttl_manual'=>max(900,(int)($s['intent_ttl_manual'] ?? TE_INTENT_TTL_MANUAL)),
        'sell_intent_ttl_sec'=>max(86400,(int)($s['sell_intent_ttl_sec'] ?? TE_SELL_INTENT_TTL_SEC)),
        'min_entry_rr'=>max(0.0,(float)($s['min_entry_rr'] ?? ($entryContract==='STOP_BASED'?TE_MIN_ENTRY_RR:0.0))),
        'scan_stale_warn_sec'=>max(900,(int)($s['scan_stale_warn_sec'] ?? TE_SCAN_STALE_WARN_SEC)),
        'deferred_admission_max_age_days'=>max(1,(int)($s['deferred_admission_max_age_days'] ?? 1)),
        'deferred_admission_retry_sec'=>max(60,(int)($s['deferred_admission_retry_sec'] ?? $expectedCycle)),
        'deferred_admission_max_attempts'=>max(1,(int)($s['deferred_admission_max_attempts'] ?? 2)),
        'deferred_admission_require_current_data'=>array_key_exists('deferred_admission_require_current_data',$s)?(bool)$s['deferred_admission_require_current_data']:true,
        'benchmark_retry_count'=>max(0,(int)($s['benchmark_retry_count'] ?? TE_BENCHMARK_RETRY_COUNT)),
        'benchmark_lag_max_business_days'=>max(0,(int)($s['benchmark_lag_max_business_days'] ?? TE_BENCHMARK_LAG_MAX_BUSINESS_DAYS)),
        'benchmark_degraded_entry_block'=>array_key_exists('benchmark_degraded_entry_block',$s)?(bool)$s['benchmark_degraded_entry_block']:true,
        'expired_cooldown_sec'=>max(0,(int)($s['expired_cooldown_sec'] ?? TE_EXPIRED_COOLDOWN_SEC)),
        'scenario_cooldown_sec'=>max(0,(int)($s['scenario_cooldown_sec'] ?? TE_SCENARIO_COOLDOWN_SEC)),
        'stop_reentry_business_days'=>max(0,(int)($s['stop_reentry_business_days'] ?? TE_STOP_REENTRY_BUSINESS_DAYS)),
        'order_gap_pct_by_market'=>[
            'KR'=>max(0.1,(float)($s['order_gap_kr_pct'] ?? 3.0)),
            'US'=>max(0.1,(float)($s['order_gap_us_pct'] ?? 4.0)),
            'JP'=>max(0.1,(float)($s['order_gap_jp_pct'] ?? 4.0)),
        ],
        'quote_fresh_age_by_market'=>[
            'KR'=>max(30,(int)($s['quote_fresh_age_kr'] ?? TE_QUOTE_FRESH_MAX_AGE)),
            'US'=>max(30,(int)($s['quote_fresh_age_us'] ?? TE_QUOTE_FRESH_MAX_AGE)),
            'JP'=>max(30,(int)($s['quote_fresh_age_jp'] ?? TE_QUOTE_FRESH_MAX_AGE)),
        ],
        'quote_scan_max_age_by_market'=>[
            'KR'=>max(30,(int)($s['quote_scan_max_age_kr'] ?? TE_QUOTE_SCAN_MAX_AGE_KR)),
            'US'=>max(30,(int)($s['quote_scan_max_age_us'] ?? TE_QUOTE_SCAN_MAX_AGE_US)),
            'JP'=>max(30,(int)($s['quote_scan_max_age_jp'] ?? TE_QUOTE_SCAN_MAX_AGE_JP)),
        ],
        'order_live_quote_max_age'=>max(10,(int)($s['order_live_quote_max_age'] ?? TE_ORDER_LIVE_QUOTE_MAX_AGE)),
        'require_live_requote_for_delayed'=>array_key_exists('require_live_requote_for_delayed',$s)?(bool)$s['require_live_requote_for_delayed']:true,
        'max_alloc_pct'=>max(0.01,min(1.0,(float)($s['max_alloc_pct'] ?? 0.45))),
        'max_market_invest_pct'=>max(0.10,min(1.0,(float)($s['max_market_invest_pct'] ?? TE_MAX_MARKET_INVEST_PCT))),
        'min_market_cash_pct'=>max(0.0,min(0.90,(float)($s['min_market_cash_pct'] ?? TE_MIN_MARKET_CASH_PCT))),
        'max_order_krw'=>(float)($s['max_order_krw'] ?? 5000000.0),'max_order_usd'=>(float)($s['max_order_usd'] ?? 1000.0),'max_order_jpy'=>(float)($s['max_order_jpy'] ?? 500000.0),
        'scan_limit'=>$scanLimit,'scan_limit_by_market'=>$scanLimitByMarket,
        'data_date_timeframe'=>$dataDateTimeframe,'scan_date_mode'=>$scanDateMode,'benchmark_gate_required'=>$benchmarkGateRequired,
        'admission_mode'=>$admissionMode,'candidate_basis'=>$candidateBasis,
        'trail_arm_pct'=>max(0.0,(float)($s['trail_arm_pct'] ?? 8.0)),
        'trail_stop_pct'=>max(0.1,(float)($s['trail_stop_pct'] ?? 5.0)),
        'daily_loss_limit_pct'=>max(0.1,(float)($s['daily_loss_limit_pct'] ?? 3.0)),
        'max_drawdown_pct'=>max(0.1,(float)($s['max_drawdown_pct'] ?? 15.0)),
        'max_holding_days'=>max(1,(int)($s['max_holding_days'] ?? 120)),
        'price_scale_max_multiple'=>max(1.5,(float)($s['price_scale_max_multiple'] ?? 3.0)),
        'intent_archive_limit'=>max(100,(int)($s['intent_archive_limit'] ?? 3000)),
        'risk_policy'=>te_risk_policy($s['risk_policy']??null),'model_callback'=>(string)($s['model_callback'] ?? ''),'rank_callback'=>$rankCallback,'exit_callback'=>(string)($s['exit_callback'] ?? ''),'opportunity_callback'=>$opportunityCallback,'daily_opportunity_policy'=>$dailyOpportunityPolicy,
        'meta_callback'=>(string)($s['meta_callback'] ?? ''),
        'required_engine_capabilities'=>is_array($s['required_engine_capabilities'] ?? null)?array_values(array_unique(array_map('strval',$s['required_engine_capabilities']))):[],
        'requirements'=>$requirements,
        'strategy_settings'=>$defaults,
        'engine_capabilities'=>te_engine_capabilities($s,$rankCallback),
    ];
}

function te_dirs(array $c): void
{
    foreach ([$c['runtime'],$c['cache'],$c['quote_cache'],$c['bars_cache'],$c['broker_runtime'],$c['intent_spool_dir'],$c['intent_ack_dir'],$c['validation_runtime']] as $d) if(!is_dir($d)&&!@mkdir($d,0775,true)&&!is_dir($d))throw new RuntimeException('DIR_CREATE_FAILED '.$d);
    foreach(array_keys($c['requirements']) as $tf){$d=$c['bars_cache'].'/'.te_safe($tf);if(!is_dir($d)&&!@mkdir($d,0775,true)&&!is_dir($d))throw new RuntimeException('DIR_CREATE_FAILED '.$d);}
    te_init_shared_intents($c);
}
function te_init_shared_intents(array $c): void
{
    $fp=@fopen($c['intents_lock'],'c+');if(!$fp)throw new RuntimeException('INTENTS_LOCK_OPEN_FAILED');
    try{if(!@flock($fp,LOCK_EX))throw new RuntimeException('INTENTS_LOCK_FAILED');if(!is_file($c['intents_file']))te_save($c['intents_file'],['schema'=>TE_SCHEMA,'owner'=>'engine','updated_at'=>te_now(),'intents'=>[]]);}
    finally{@flock($fp,LOCK_UN);@fclose($fp);}
}

function te_broker_runtime_summary(array $c, array $brokerOrders = []): array
{
    if (!$brokerOrders) $brokerOrders = te_broker_orders($c);
    $approval = te_load($c['broker_approval_mode_file'], []);
    $heartbeat = te_load($c['broker_heartbeat_file'], []);
    $lastCycle = (string)($heartbeat['last_cycle_at'] ?? '');
    $lastCycleTs = $lastCycle !== '' ? strtotime($lastCycle) : false;
    $age = $lastCycleTs === false ? null : max(0, time() - $lastCycleTs);
    $approvalMode = strtolower((string)($heartbeat['approval_mode'] ?? $approval['mode'] ?? 'manual'));
    if (!in_array($approvalMode, ['manual','auto'], true)) $approvalMode = 'manual';
    $executionMode = strtolower((string)($heartbeat['execution_mode'] ?? $heartbeat['broker_mode'] ?? 'unknown'));
    $pipeline = te_order_pipeline_counts_for_key([], $brokerOrders);
    return [
        'broker_version'=>(string)($heartbeat['version'] ?? ''),
        'broker_rev'=>(string)($heartbeat['rev'] ?? ''),
        'approval_mode'=>$approvalMode,
        'approval_label'=>$approvalMode === 'auto' ? '자동 승인' : '직접 승인',
        'execution_mode'=>$executionMode,
        'kill_flag'=>is_file($c['broker_kill_file']),
        'last_action'=>(string)($heartbeat['last_action'] ?? ''),
        'last_action_at'=>(string)($heartbeat['last_action_at'] ?? ''),
        'last_cycle_at'=>$lastCycle,
        'last_ingest_at'=>(string)($heartbeat['last_ingest_at'] ?? ''),
        'last_run_at'=>(string)($heartbeat['last_run_at'] ?? ''),
        'last_sync_at'=>(string)($heartbeat['last_sync_at'] ?? ''),
        'last_cancel_at'=>(string)($heartbeat['last_cancel_at'] ?? ''),
        'cycle_age_sec'=>$age,
        'cycle_stale'=>$age === null || $age > TE_BROKER_STALE_SEC,
        'recommended_cycle_sec'=>TE_BROKER_EXPECTED_CYCLE_SEC,
        'strategy_cycle_sec'=>(int)($c['expected_cycle_sec']??TE_STRATEGY_EXPECTED_CYCLE_SEC),
        'lock_stats'=>te_load((string)($c['broker_lock_stats_file']??''),[]),
        'pipeline'=>$pipeline,
        'latency'=>is_array($heartbeat['latency']??null)?$heartbeat['latency']:[],
        'last_result'=>is_array($heartbeat['last_result'] ?? null) ? $heartbeat['last_result'] : [],
        'ingest_audit'=>te_load((string)($c['broker_runtime']??'').'/broker_ingest_audit.json',[]),
        'intent_spool'=>te_intent_spool_health($c),
    ];
}

function te_benchmark_gate(array $c, string $market, string $selectedDate): array
{
    $state = te_load($c['benchmark_state_file'], []);
    $row = is_array($state['markets'][$market] ?? null) ? $state['markets'][$market] : [];
    $target = (string)($row['target_date'] ?? '');
    if ($target === '') {
        return ['ok'=>true,'status'=>'READY','target_date'=>'','selected_date'=>$selectedDate,'reason'=>'OK','entry_blocked'=>false,'lag_business_days'=>0];
    }
    if ($selectedDate !== '' && $selectedDate >= $target) {
        return ['ok'=>true,'status'=>'READY','target_date'=>$target,'selected_date'=>$selectedDate,'reason'=>'OK','entry_blocked'=>false,'lag_business_days'=>0];
    }
    $lag = te_business_day_gap($selectedDate, $target, $market, $c);
    $safe = $selectedDate !== '' && $lag <= (int)$c['benchmark_lag_max_business_days'];
    if ($safe) {
        return [
            'ok'=>true,'status'=>'BENCHMARK_DEGRADED','target_date'=>$target,'selected_date'=>$selectedDate,
            'reason'=>'BENCHMARK_LAG_SAFE '.$selectedDate.'<'.$target,
            'entry_blocked'=>!empty($c['benchmark_degraded_entry_block']),'lag_business_days'=>$lag,
        ];
    }
    return [
        'ok'=>false,'status'=>'BENCHMARK_WAIT','target_date'=>$target,'selected_date'=>$selectedDate,
        'reason'=>'BENCHMARK_WAIT '.$selectedDate.'<'.$target,
        'entry_blocked'=>true,'lag_business_days'=>$lag,
    ];
}

function te_tick(array $c): array
{
    if(!te_lock($c['lock_file'])){
        $lockError=te_lock_error();
        if($lockError!==''){te_log($c['error_file'],$lockError);return['ok'=>false,'message'=>$lockError];}
        return['ok'=>true,'message'=>'busy'];
    }
    try{
        $tickStarted=microtime(true);
        $tradeList=te_trade_list_load($c);
        if(empty($tradeList['ok'])){te_log($c['error_file'],'TRADE_LIST_FATAL '.(string)($tradeList['error']??'UNKNOWN').' '.(string)($tradeList['file']??''));return['ok'=>false,'message'=>(string)($tradeList['error']??'TRADE_LIST_INVALID'),'trade_list'=>$tradeList];}
        $status=te_market_status($c);$tick='TICK-'.date('Ymd-His').'-'.te_hex(3);$states=te_load($c['model_state_file'],[]);if(!is_array($states))$states=[];$positions=te_positions_normalize(te_load($c['positions_file'],[]));foreach($positions as$pk=>$pp){if(!is_array($pp))continue;$positions[$pk]['position_cohort']=te_position_cohort($c,$pp);}$trades=te_load($c['trades_file'],[]);if(!is_array($trades))$trades=[];foreach($trades as$tk=>$tt){if(!is_array($tt))continue;$trades[$tk]['position_cohort']=te_position_cohort($c,$tt);}$ledger=te_load($c['fill_ledger_file'],[]);if(!is_array($ledger))$ledger=[];$legacyTransport=te_migrate_legacy_transport($c);$brokerOrders=te_broker_orders($c);$candidateBook=te_candidate_book_load($c);
        $life=te_expire_engine_intents($c,$states,$candidateBook,$brokerOrders);$states=$life['states'];$candidateBook=$life['candidate_book'];
        $recon=te_reconcile($c,$positions,$trades,$states,$ledger,$brokerOrders);$positions=$recon['positions'];$trades=$recon['trades'];$states=$recon['states'];$ledger=$recon['ledger'];
        $diag=['scan'=>0,'quote_ok'=>0,'data_error'=>0,'data_short'=>0,'data_stale'=>0,'data_delayed'=>0,'buy'=>0,'watch'=>0,'filtered'=>0,'rank_runs'=>0,'direct_runs'=>0,'rank_selected'=>0,'sell_intent'=>0,'buy_intent'=>0,'fills'=>(int)$recon['fills'],'risk_exit'=>0,'scan_discarded'=>0,'intent_expired'=>(int)$life['expired'],'broker_ack_wait'=>(int)($life['broker_ack_wait']??0),'spool_repaired'=>(int)($life['spool_repaired']??0),'legacy_transport_migrated'=>(int)($legacyTransport['migrated']??0),'legacy_transport_archived'=>(int)($legacyTransport['archived']??0),'legacy_transport_invalid'=>(int)($legacyTransport['invalid']??0),'deferred_runs'=>0,'deferred_buy_intent'=>0,'dts_hard_stale_refresh_attempt'=>0,'dts_hard_stale_refresh_success'=>0,'dts_hard_stale_refresh_fail'=>0,'position_quote_attempt'=>0,'position_quote_ok'=>0,'position_quote_fail'=>0,'position_exit_checked'=>0,'position_exit_hold'=>0,'position_exit_sell'=>0,'position_exit_active_order_skip'=>0,'position_exit_context_invalid'=>0,'position_exit_data_refresh_attempt'=>0,'position_exit_data_refresh_success'=>0,'position_exit_data_refresh_fail'=>0];$stageBook=te_scan_stage_load($c);$scannedMarkets=[];$sellMarkets=[];$promoted=false;$reservedByMarket=['KR'=>te_pending_buy_count($c,$brokerOrders,'KR'),'US'=>te_pending_buy_count($c,$brokerOrders,'US'),'JP'=>te_pending_buy_count($c,$brokerOrders,'JP')];
        $positionRuntime=[];$exitDecisions=[];
        foreach($positions as$key=>$p){
            if(!te_active_position($p))continue;
            $diag['position_quote_attempt']++;
            $item=['market'=>(string)$p['market'],'symbol'=>(string)$p['symbol'],'name'=>(string)($p['name']??$p['symbol']),'exchange'=>(string)($p['exchange']??'')];
            $q=te_quote($c,$item,$status);
            if(empty($q['ok'])){
                $diag['position_quote_fail']++;
                $exitDecisions[$key]=['market'=>(string)$p['market'],'symbol'=>(string)$p['symbol'],'quote_ok'=>false,'action'=>'HOLD','code'=>'POSITION_QUOTE_UNAVAILABLE','reason'=>'활성 포지션 현재가 조회 실패로 청산판단 보류'];
                continue;
            }
            $diag['position_quote_ok']++;
            $price=(float)$q['price'];$qts=(int)($q['ts']??0);
            $p['current_price']=$price;$p['peak_price']=max((float)($p['peak_price']??0),$price);$p['quote_source']=(string)($q['market_source']??$q['source']??'');$p['quote_ts']=$qts;$p['quote_age_sec']=$qts>0?max(0,time()-$qts):null;$p['last_quote_ok_at']=te_now();$p['updated_at']=te_now();
            $positions[$key]=$p;
            $positionRuntime[$key]=['ctx'=>te_context($c,$item,$q,$status,$tick),'quote'=>$q,'item'=>$item];
        }
        $capital=te_capital($c,$positions,$trades,$brokerOrders);$riskState=te_risk_state_update($c,$capital);
        foreach($positions as$key=>$p){
            if(!te_active_position($p))continue;
            if(te_has_active_order($c,$brokerOrders,$key,'SELL')){
                $diag['position_exit_active_order_skip']++;
                $exitDecisions[$key]=array_merge(is_array($exitDecisions[$key]??null)?$exitDecisions[$key]:[],['market'=>(string)$p['market'],'symbol'=>(string)$p['symbol'],'action'=>'HOLD','code'=>'ACTIVE_SELL_ORDER_EXISTS','reason'=>'기존 활성 SELL 주문이 있어 중복 청산판단 생략']);
                continue;
            }
            $runtime=is_array($positionRuntime[$key]??null)?$positionRuntime[$key]:[];$q=is_array($runtime['quote']??null)?$runtime['quote']:[];
            if(empty($q['ok']))continue;
            $ctx=is_array($runtime['ctx']??null)?$runtime['ctx']:[];$exit=te_common_exit($c,$p,$q,$riskState);$er=[];$freshness=te_exit_data_freshness($c,$ctx,$status);
            if(strtoupper((string)($exit['action']??'HOLD'))!=='SELL'){
                if(!empty($ctx['data_ok'])){
                    $item=is_array($runtime['item']??null)?$runtime['item']:['market'=>(string)$p['market'],'symbol'=>(string)$p['symbol'],'name'=>(string)($p['name']??$p['symbol']),'exchange'=>(string)($p['exchange']??'')];
                    $er=te_strategy_exit_with_recovery($c,$ctx,$p,is_array($states[$key]??null)?$states[$key]:[],$item,$status,$tick);$exit=$er['exit'];$ctx=$er['ctx'];$freshness=is_array($er['freshness']??null)?$er['freshness']:$freshness;
                    if(!empty($er['freshness_recovery']['attempted'])){$diag['position_exit_data_refresh_attempt']++;if(!empty($er['freshness_recovery']['success']))$diag['position_exit_data_refresh_success']++;else$diag['position_exit_data_refresh_fail']++;}
                    if(!empty($er['recovery']['attempted'])){$diag['dts_hard_stale_refresh_attempt']++;if(!empty($er['recovery']['success']))$diag['dts_hard_stale_refresh_success']++;else$diag['dts_hard_stale_refresh_fail']++;}
                }else{
                    $diag['position_exit_context_invalid']++;
                    $exit=['action'=>'HOLD','code'=>'POSITION_CONTEXT_DATA_INVALID','reason'=>(string)($ctx['data_reason']??$ctx['data_code']??'활성 포지션 데이터 무효')];
                }
            }
            $exit=te_validate_exit_result($exit);$diag['position_exit_checked']++;
            if($exit['action']==='SELL'){
                $diag['position_exit_sell']++;
                $ctxForOrder=$ctx?:['market'=>$p['market'],'symbol'=>$p['symbol'],'name'=>$p['name']??$p['symbol'],'exchange'=>$p['exchange']??'','session_date'=>te_session_date((string)$p['market']),'meta'=>['price_source'=>'RISK','data_sources'=>[],'required_timeframes'=>[],'quote_timestamp'=>(int)($q['ts']??0),'tick_id'=>$tick]];
                $vr=te_validation_register_exit_signal($c,$ctxForOrder,$exit,$p);if(!empty($vr['id']))$ctxForOrder['validation_id']=(string)$vr['id'];
                $intent=te_make_intent($c,$ctxForOrder,'SELL',(int)$p['qty'],(float)$q['price'],0.0,0.0,(string)($exit['code']??'SELL'),(string)($exit['reason']??$exit['code']??'청산'),(string)($p['scenario_id']??''));
                if(te_append_intent($c,$intent)){$diag['sell_intent']++;if(!empty($exit['risk_exit']))$diag['risk_exit']++;$positions[$key]['pending_sell_order_id']=$intent['order_id'];$sellMarkets[(string)$p['market']]=true;}
            }else{$diag['position_exit_hold']++;}
            $exitDecisions[$key]=[
                'market'=>(string)$p['market'],'symbol'=>(string)$p['symbol'],'quote_ok'=>true,'quote_price'=>(float)($q['price']??0),'quote_source'=>(string)($q['market_source']??$q['source']??''),'quote_ts'=>(int)($q['ts']??0),
                'context_data_ok'=>!empty($ctx['data_ok']),'context_data_code'=>(string)($ctx['data_code']??''),'action'=>(string)$exit['action'],'code'=>(string)($exit['code']??''),'reason'=>(string)($exit['reason']??''),
                'freshness'=>$freshness,'freshness_recovery'=>is_array($er['freshness_recovery']??null)?$er['freshness_recovery']:['attempted'=>false,'success'=>false,'reason'=>'NOT_REQUIRED']
            ];
        }
        $universe=te_universe($c);$regimes=te_market_regimes($c,$status);te_save($c['regime_file'],$regimes);$scanProgress=te_scan_progress_init($c,$status,$universe,$tick,$candidateBook);
        foreach(['KR','US','JP'] as $market){$dq=te_deferred_admission_attempt($c,$market,$candidateBook,$positions,$capital,$states,$brokerOrders,$status,$tick,$universe,$regimes,!empty($sellMarkets[$market]),$reservedByMarket);if(!empty($dq['attempted'])){$diag['deferred_runs']++;$diag['deferred_buy_intent']+=(int)$dq['buy_intent'];$diag['rank_selected']+=(int)$dq['rank_selected'];$diag['buy_intent']+=(int)$dq['buy_intent'];$candidateBook=$dq['candidate_book'];$states=$dq['states'];$capital=$dq['capital'];$reservedByMarket=$dq['reserved_by_market'];}}
        foreach(te_scan_markets($status)as$market){$scannedMarkets[$market]=true;$rows=array_values(array_filter($universe,static function($r)use($market){return is_array($r)&&($r['market']??'')===$market;}));$regimeRow=is_array($regimes['markets'][$market]??null)?$regimes['markets'][$market]:[];$expectedDate=te_expected_completed_date($market,$status,$c);$selectedBenchmarkDate=(string)($regimeRow['date']??'');$scanDate=te_strategy_scan_date($c,$market,$status,$selectedBenchmarkDate);$gate=!empty($c['benchmark_gate_required'])?te_benchmark_gate($c,$market,$selectedBenchmarkDate):['ok'=>true,'status'=>'NOT_REQUIRED','target_date'=>'','selected_date'=>$selectedBenchmarkDate,'reason'=>'NOT_REQUIRED','entry_blocked'=>false,'lag_business_days'=>0];if(empty($gate['ok'])){$scanProgress['markets'][$market]=array_merge($scanProgress['markets'][$market]??[],['status'=>'BENCHMARK_WAIT','last_error'=>$gate['reason'],'benchmark_target_date'=>$gate['target_date'],'benchmark_selected_date'=>$gate['selected_date'],'benchmark_lag_business_days'=>$gate['lag_business_days']??null,'batch_complete'=>false,'cycle_complete'=>false,'scan_complete'=>false,'updated_at'=>te_now()]);continue;}$scanProgress['markets'][$market]=array_merge($scanProgress['markets'][$market]??[],['benchmark_status'=>$gate['status'],'benchmark_target_date'=>$gate['target_date'],'benchmark_selected_date'=>$gate['selected_date'],'benchmark_lag_business_days'=>$gate['lag_business_days']??0,'benchmark_entry_blocked'=>!empty($gate['entry_blocked'])]);$marketScanLimit=te_scan_limit_for_market($c,$market);$batch=te_scan_batch($c,$market,$rows,$marketScanLimit,$scanDate);$stageBook=te_scan_stage_prepare($stageBook,$market,$batch,$scanDate);$scanProgress=te_scan_progress_begin($c,$scanProgress,$market,$batch);$dateMismatch='';$actualMismatch='';$batchSymbols=[];
            foreach($batch['rows']as$batchIndex=>$item){
                $scanProgress=te_scan_progress_item($c,$scanProgress,$market,$batch,$batchIndex,$item);$diag['scan']++;$batchSymbols[]=(string)($item['symbol']??'');
                $q=te_quote($c,$item,$status);if(empty($q['ok'])){$diag['data_error']++;$stageBook=te_scan_stage_put($stageBook,$market,te_error_candidate($item,'QUOTE_ERROR',$batch,$scanDate));continue;}
                $diag['quote_ok']++;$ctx=te_context($c,$item,$q,$status,$tick);$ctx['market_regime']=(string)($regimeRow['code']??'UNKNOWN');$actualDate=te_context_data_date($ctx,$market,(string)$c['data_date_timeframe']);$dateRelation=te_scan_date_relation($actualDate,$scanDate);if((string)($ctx['data_code']??'')==='DATA_DELAYED')$diag['data_delayed']++;
                if($dateRelation==='AHEAD'){
                    $diag['data_error']++;$dateMismatch='BENCHMARK_DATE_MISMATCH '.$actualDate.'!='.$scanDate;if($actualMismatch===''||$actualDate>$actualMismatch)$actualMismatch=$actualDate;
                    te_mark_benchmark_target($c,$market,$actualDate);te_invalidate_market_benchmark_cache($c,$market);$err=te_error_candidate($item,$dateMismatch,$batch,$scanDate,$q);$err['metrics']['actual_data_date']=$actualDate;$err['metrics']['benchmark_date']=$selectedBenchmarkDate;$err['metrics']['scan_discard_required']=true;$stageBook=te_scan_stage_put($stageBook,$market,$err);break;
                }
                if($dateRelation==='STALE'){$diag['data_stale']++;$err=te_error_candidate($item,'DATA_STALE',$batch,$scanDate,$q);$err['metrics']['actual_data_date']=$actualDate;$err['metrics']['benchmark_date']=$scanDate;$stageBook=te_scan_stage_put($stageBook,$market,$err);continue;}
                if(empty($ctx['data_ok'])){$dataCode=(string)($ctx['data_code']??'RUNTIME_ERROR');if($dataCode==='DATA_SHORT')$diag['data_short']++;elseif($dataCode==='DATA_STALE')$diag['data_stale']++;else$diag['data_error']++;$err=te_error_candidate($item,(string)($ctx['data_reason']??$dataCode),$batch,$scanDate,$q);$err['metrics']['actual_data_date']=$actualDate;$err['metrics']['benchmark_date']=$scanDate;$stageBook=te_scan_stage_put($stageBook,$market,$err);continue;}
                if($dateRelation==='MISSING'){$diag['data_error']++;$err=te_error_candidate($item,'DATA_DATE_MISMATCH',$batch,$scanDate,$q);$err['metrics']['actual_data_date']=$actualDate;$err['metrics']['benchmark_date']=$scanDate;$stageBook=te_scan_stage_put($stageBook,$market,$err);continue;}
                $cb=$c['model_callback'];if($cb===''||!function_exists($cb))throw new RuntimeException('model_callback 없음');$key=$market.':'.(string)$item['symbol'];$preEntryState=is_array($states[$key]??null)?$states[$key]:[];try{$sig=$cb($ctx,$preEntryState);}catch(Throwable $e){te_log($c['error_file'],'MODEL '.$key.' '.$e->getMessage());$diag['data_error']++;$stageBook=te_scan_stage_put($stageBook,$market,te_error_candidate($item,'MODEL_EXCEPTION',$batch,$scanDate,$q));continue;}if(!is_array($sig)){$stageBook=te_scan_stage_put($stageBook,$market,te_error_candidate($item,'MODEL_INVALID',$batch,$scanDate,$q));continue;}$validated=te_validate_signal($sig,$c);if(empty($validated['ok'])){te_log($c['error_file'],'MODEL_CONTRACT '.$key.' '.implode(',',(array)$validated['errors']));$stageBook=te_scan_stage_put($stageBook,$market,te_error_candidate($item,'MODEL_CONTRACT_INVALID',$batch,$scanDate,$q));continue;}$sig=$validated['signal'];$modelBuy=strtoupper((string)($sig['status']??''))==='BUY';if($modelBuy){$vr=te_validation_register_model_signal($c,$ctx,$sig,'BUY','MODEL_SIGNAL');if(empty($vr['ok'])){$sig['status']='WATCH';$sig['block_reason']='AUDIT_WRITE_FAILED';$sig['reason']=trim((string)($sig['reason']??'').' · 감사기록 저장 실패',' ·');$modelBuy=false;}elseif(!empty($vr['id'])){$sig['metrics']=is_array($sig['metrics']??null)?$sig['metrics']:[];$sig['metrics']['validation_id']=(string)$vr['id'];$sig['metrics']['signal_id']=(string)$vr['id'];}}$states[$key]=$sig['state'];$row=te_candidate($item,$q,$sig,$ctx);if($modelBuy)$row['_entry_pre_state']=$preEntryState;$row['scan_cycle_id']=(string)$batch['cycle_id'];$row['scan_data_date']=$scanDate;$row['scan_session_date']=te_session_date($market);$stageBook=te_scan_stage_put($stageBook,$market,$row);$signalStatus=strtoupper((string)($sig['status']??''));if($signalStatus==='BUY')$diag['buy']++;elseif($signalStatus==='WATCH')$diag['watch']++;else$diag['filtered']++;
            }
            if($dateMismatch!==''){$diag['scan_discarded']++;te_log($c['error_file'],'SCAN_DISCARD '.$market.' '.$dateMismatch);$stageBook=te_scan_stage_clear($stageBook,$market);te_scan_reset_cursor($c,$market,$actualMismatch);$scanProgress['markets'][$market]=array_merge($scanProgress['markets'][$market]??[],['status'=>'BENCHMARK_WAIT','last_error'=>$dateMismatch,'benchmark_target_date'=>$actualMismatch,'benchmark_selected_date'=>$scanDate,'batch_complete'=>false,'cycle_complete'=>false,'scan_complete'=>false,'updated_at'=>te_now()]);continue;}
            te_scan_commit_batch($c,$market,$batch);$scanProgress=te_scan_progress_finish($c,$scanProgress,$market,$batch);$scanProgress=te_scan_progress_apply_counts($scanProgress,$market,$stageBook,!empty($batch['wrapped']),$c['rank_callback']!==''&&function_exists($c['rank_callback']));
            $perBatch=(string)($c['admission_mode']??'FULL_SCAN')==='PER_BATCH';
            if($perBatch&&$batchSymbols){
                $batchBook=te_candidate_book_subset($stageBook,$market,$batchSymbols);
                $queued=te_rank_market_and_queue($c,$market,$batch,$batchBook,$positions,$capital,$states,$brokerOrders,$status,$tick,$universe,$regimes,!empty($sellMarkets[$market]),$reservedByMarket);
                $stageBook=te_candidate_book_merge_market($stageBook,$queued['candidate_book'],$market);
                $states=$queued['states'];$reservedByMarket=$queued['reserved_by_market'];$capital=$queued['capital'];
                if($queued['route']==='RANKED')$diag['rank_runs']++;else$diag['direct_runs']++;
                $diag['rank_selected']+=(int)$queued['rank_selected'];$diag['buy_intent']+=(int)$queued['buy_intent'];
                $candidateBook=te_candidate_live_batch_update($c,$candidateBook,$stageBook,$market,$batchSymbols,$batch,$scanDate,$status);$promoted=true;
            }
            if(!empty($batch['wrapped'])){
                $valid=te_scan_stage_validate($stageBook,$market,$batch,$scanDate);
                if(!$valid['ok']){
                    $diag['scan_discarded']++;te_log($c['error_file'],'SCAN_DISCARD '.$market.' '.$valid['reason']);
                    if(strpos((string)$valid['reason'],'BENCHMARK_DATE_MISMATCH')===0){te_mark_benchmark_target($c,$market,(string)($valid['actual_date']??''));te_invalidate_market_benchmark_cache($c,$market);}
                    $stageBook=te_scan_stage_clear($stageBook,$market);te_scan_reset_cursor($c,$market,(string)($valid['actual_date']??''));
                    $scanProgress['markets'][$market]['status']='DISCARDED';$scanProgress['markets'][$market]['last_error']=$valid['reason'];$scanProgress['markets'][$market]['cycle_complete']=false;$scanProgress['markets'][$market]['scan_complete']=false;
                }else{
                    if(!$perBatch){
                        $queued=te_rank_market_and_queue($c,$market,$batch,$stageBook,$positions,$capital,$states,$brokerOrders,$status,$tick,$universe,$regimes,!empty($sellMarkets[$market]),$reservedByMarket);
                        $stageBook=$queued['candidate_book'];$states=$queued['states'];$reservedByMarket=$queued['reserved_by_market'];$capital=$queued['capital'];
                        if($queued['route']==='RANKED')$diag['rank_runs']++;else$diag['direct_runs']++;
                        $diag['rank_selected']+=(int)$queued['rank_selected'];$diag['buy_intent']+=(int)$queued['buy_intent'];
                    }
                    $scanProgress=te_scan_progress_apply_counts($scanProgress,$market,$stageBook,true,$c['rank_callback']!==''&&function_exists($c['rank_callback']));
                    $candidateBook=te_candidate_promote_market($c,$candidateBook,$stageBook,$market,$batch,$scanDate,$status);
                    $stageBook=te_scan_stage_clear($stageBook,$market);$promoted=true;
                }
            }
        }
        $scanProgress['status']=te_scan_progress_overall_status($scanProgress,$scannedMarkets);if($scanProgress['status']==='DONE')$scanProgress['completed_at']=te_now();$scanProgress['updated_at']=te_now();te_save($c['scan_progress_file'],$scanProgress);$candidateBook=te_candidate_book_refresh_basis($c,$candidateBook,$status,$regimes,$scanProgress);te_save($c['scan_stage_file'],$stageBook);te_save($c['candidates_file'],$candidateBook);if($promoted)te_save($c['close_snapshot_file'],te_candidate_close_snapshot($candidateBook));
        $brokerOrders=te_broker_orders($c);$validationSync=te_validation_sync_runtime($c,$candidateBook,$brokerOrders,$trades);$validationResolve=te_validation_resolve_due_samples($c,$status,false);$validationStatus=te_validation_status($c);$capital=te_capital($c,$positions,$trades,$brokerOrders);$riskState=te_risk_state_update($c,$capital);$ownOrders=te_strategy_orders($c,$brokerOrders);$stats=te_stats($positions,$trades,$ownOrders);$pipeline=te_order_pipeline_counts($c,$brokerOrders);$tickElapsed=round(microtime(true)-$tickStarted,3);$diag['tick_elapsed_sec']=$tickElapsed;$diag['expected_cycle_sec']=(int)$c['expected_cycle_sec'];$diag['cycle_target_met']=$tickElapsed<=(int)$c['expected_cycle_sec'];$state=['ok'=>true,'engine'=>'OK','message'=>'tick 정상','tick_id'=>$tick,'last_tick'=>te_now(),'version'=>TE_VERSION,'rev'=>TE_REV,'strategy_key'=>$c['strategy_key'],'strategy_label'=>$c['strategy_label'],'strategy_rev'=>$c['strategy_rev'],'tick_elapsed_sec'=>$tickElapsed,'expected_cycle_sec'=>(int)$c['expected_cycle_sec'],'cycle_target_met'=>$tickElapsed<=(int)$c['expected_cycle_sec'],'market'=>$status,'regimes'=>$regimes,'scan_progress'=>$scanProgress,'diag'=>$diag,'validation'=>['sync'=>$validationSync,'resolve'=>$validationResolve,'status'=>$validationStatus],'risk_state'=>$riskState,'correlation_exposure'=>te_correlation_summary($c,$positions,$brokerOrders),'positions_count'=>count(array_filter($positions,'te_active_position')),'capital'=>$capital,'stats'=>$stats,'order_pipeline'=>$pipeline,'broker_runtime'=>te_broker_runtime_summary($c,$brokerOrders),'broker_orders'=>te_broker_summary($ownOrders),'exit_decisions'=>array_values($exitDecisions)];
        te_save($c['model_state_file'],$states);te_save($c['positions_file'],$positions);te_save($c['trades_file'],$trades);te_save($c['fill_ledger_file'],$ledger);te_save($c['capital_file'],$capital);te_save($c['stats_file'],$stats);te_save($c['state_file'],$state);te_log($c['log_file'],'tick '.$tick.' scan='.$diag['scan'].' ranked='.$diag['rank_runs'].' direct='.$diag['direct_runs'].' selected='.$diag['rank_selected'].' buy_intent='.$diag['buy_intent'].' sell_intent='.$diag['sell_intent'].' expired='.$diag['intent_expired'].' fills='.$diag['fills']);return['ok'=>true,'message'=>'tick ok','state'=>$state];
    }catch(Throwable $e){te_log($c['error_file'],$e->getMessage().' @'.$e->getLine());$sp=te_load($c['scan_progress_file'],[]);if(is_array($sp)){$sp['status']='ERROR';$sp['error']=$e->getMessage();$sp['updated_at']=te_now();te_save($c['scan_progress_file'],$sp);}return['ok'=>false,'message'=>$e->getMessage()];}finally{te_unlock();}
}

function te_priority_tick(array $c, string $market, string $symbol): array
{
    if (!in_array($market, ['KR','US','JP'], true) || $symbol === '') {
        return ['ok'=>false, 'message'=>'market/symbol required'];
    }
    if (!te_lock($c['lock_file'])) {
        $lockError=te_lock_error();
        if($lockError!==''){te_log($c['error_file'],$lockError);return ['ok'=>false,'message'=>$lockError];}
        return ['ok'=>true, 'message'=>'busy'];
    }

    try {
        $list = te_trade_list_load($c);
        if (empty($list['ok'])) return ['ok'=>false, 'message'=>'TRADE_LIST_INVALID'];

        $item = null;
        foreach (te_universe($c) as $row) {
            if (!is_array($row)) continue;
            if (strtoupper((string)($row['market'] ?? '')) === $market && strtoupper((string)($row['symbol'] ?? '')) === $symbol) {
                $item = $row;
                break;
            }
        }
        if (!$item) return ['ok'=>false, 'message'=>'SYMBOL_NOT_FOUND'];

        $status = te_market_status($c);
        $tick = 'LIVE-'.date('Ymd-His').'-'.te_hex(3);
        $states = te_load($c['model_state_file'], []); if (!is_array($states)) $states = [];
        $positions = te_positions_normalize(te_load($c['positions_file'], []));
        $trades = te_load($c['trades_file'], []); if (!is_array($trades)) $trades = [];
        $ledger = te_load($c['fill_ledger_file'], []); if (!is_array($ledger)) $ledger = [];
        $orders = te_broker_orders($c);
        $book = te_candidate_book_load($c);

        $life = te_expire_engine_intents($c, $states, $book, $orders);
        $states = $life['states'];
        $book = $life['candidate_book'];
        $recon = te_reconcile($c, $positions, $trades, $states, $ledger, $orders);
        $positions = $recon['positions'];
        $trades = $recon['trades'];
        $states = $recon['states'];
        $ledger = $recon['ledger'];
        $key = $market.':'.$symbol;

        $q = te_quote($c, $item, $status);
        if (empty($q['ok'])) return ['ok'=>false, 'message'=>'LIVE_QUOTE_UNAVAILABLE', 'quote'=>$q];
        $ctx = te_context($c, $item, $q, $status, $tick);
        if (empty($ctx['data_ok'])) return ['ok'=>false, 'message'=>(string)($ctx['data_reason'] ?? 'DATA_INVALID')];

        $model = $c['model_callback'];
        if ($model === '' || !function_exists($model)) return ['ok'=>false, 'message'=>'MODEL_CALLBACK_MISSING'];
        $preEntryState=is_array($states[$key]??null)?$states[$key]:[];
        $rawSignal = $model($ctx, $preEntryState);
        $validated = te_validate_signal(is_array($rawSignal) ? $rawSignal : [],$c);
        if (empty($validated['ok'])) return ['ok'=>false, 'message'=>'MODEL_CONTRACT_INVALID', 'errors'=>$validated['errors'] ?? []];
        $sig = $validated['signal'];
        $states[$key] = $sig['state'];

        $row = te_candidate($item, $q, $sig, $ctx);
        $row['scan_cycle_id'] = $tick;
        $row['scan_data_date'] = te_context_data_date($ctx, $market, (string)$c['data_date_timeframe']);
        $row['scan_session_date'] = te_session_date($market);
        if (!isset($book['markets'][$market]) || !is_array($book['markets'][$market])) $book['markets'][$market] = te_candidate_market_empty($market);
        if (!isset($book['markets'][$market]['rows']) || !is_array($book['markets'][$market]['rows'])) $book['markets'][$market]['rows'] = [];
        $book['markets'][$market]['rows'][$symbol] = $row;
        $book['updated_at'] = te_now();
        

        $capital = te_capital($c, $positions, $trades, $orders);
        $riskState = te_risk_state_update($c, $capital);
        $intentCreated = false;
        $intentId = '';
        $action = 'NONE';

        if (isset($positions[$key]) && te_active_position($positions[$key])) {
            if (!te_has_active_order($c, $orders, $key, 'SELL')) {
                $exit = te_common_exit($c, $positions[$key], $q, $riskState);
                if (strtoupper((string)($exit['action'] ?? 'HOLD')) !== 'SELL') {
                    $er=te_strategy_exit_with_recovery($c,$ctx,$positions[$key],is_array($states[$key]??null)?$states[$key]:[],$item,$status,$tick);
                    $exit=$er['exit'];$ctx=$er['ctx'];
                }
                $exit = te_validate_exit_result($exit);
                if ($exit['action'] === 'SELL') {
                    $ctx['exit_urgent']=!empty($exit['urgent_exit'])||!empty($exit['risk_exit']);
                    $intent = te_make_intent(
                        $c, $ctx, 'SELL', (int)$positions[$key]['qty'], (float)$q['price'], 0.0, 0.0,
                        (string)($exit['code'] ?? 'SELL'), (string)($exit['reason'] ?? '청산'), (string)($positions[$key]['scenario_id'] ?? '')
                    );
                    if (te_append_intent($c, $intent)) {
                        $positions[$key]['pending_sell_order_id'] = $intent['order_id'];
                        $intentCreated = true;
                        $intentId = $intent['order_id'];
                        $action = 'SELL';
                    }
                }
            }
        } elseif (strtoupper((string)$sig['status']) === 'BUY') {
            $risk = is_array($riskState['markets'][$market] ?? null) ? $riskState['markets'][$market] : [];
            $block = '';
            if (!te_market_flag($status, $market, 'entry_open')) $block = 'ENTRY_WINDOW_CLOSED';
            elseif (!empty($risk['daily_loss_block']) || !empty($risk['drawdown_block'])) $block = 'PORTFOLIO_RISK_BLOCK';
            elseif (te_actual_symbol_held($c,$positions,$market,$symbol)) $block = 'DUPLICATE_SYMBOL';
            elseif (te_available_slots($c, $positions, $orders, $market) < 1) $block = 'NO_AVAILABLE_SLOT';
            elseif (empty(($correlationGuard=te_correlation_entry_guard($c,$positions,$orders,$item))['ok'])) $block = (string)$correlationGuard['reason'];
            elseif (te_has_active_order($c, $orders, $key, 'BUY')) $block = 'ORDER_PENDING';
            elseif (!te_reconciliation_ok($c, $positions, $market, $symbol)) $block = 'RECONCILIATION_DEFICIT';

            if ($block === '' && tv_strategy_status($c)==='CHALLENGER') {
                $sid=(string)($sig['metrics']['signal_id']??$sig['metrics']['validation_id']??'');
                if($sid!==''&&function_exists('tv_patch_pipeline'))tv_patch_pipeline($c,$sid,'ENGINE_SELECTED',['selected'=>true,'shadow_only'=>true,'block_reason'=>''],['market'=>$market,'symbol'=>$symbol,'shadow_only'=>true]);
                $block='CHALLENGER_SHADOW_ONLY';$action='SHADOW_PAPER';
            }
            if ($block === '') {
                $guard = te_entry_guard($c, $ctx, $sig, ['market'=>$market, 'symbol'=>$symbol]);
                if (empty($guard['ok'])) {
                    $block = (string)($guard['reason'] ?? 'ENTRY_GUARD_REJECTED');
                } else {
                    $sig = $guard['signal'];
                    $rebased = te_rebase_order_signal($c,$sig, (float)$q['price'], (float)($sig['entry_price'] ?? $q['price']), 0.0);
                    if (empty($rebased['ok'])) {
                        $block = (string)$rebased['reason'];
                    } else {
                        $sig = $rebased['signal'];
                        $audit = is_array($sig['metrics'] ?? null) ? $sig['metrics'] : [];
                        $ctx['ranked_result'] = [
                            'rule_score'=>(float)($sig['score'] ?? 0),
                            'final_score'=>(float)($sig['score'] ?? 0),
                            'audit'=>[
                                'support'=>is_array($audit['support'] ?? null) ? $audit['support'] : [],
                                'trigger'=>is_array($audit['trigger'] ?? null) ? $audit['trigger'] : [],
                                'phase'=>(string)($audit['phase'] ?? ''),
                            ],
                        ];
                        $pendingTotal = te_pending_buy_count($c, $orders, 'KR') + te_pending_buy_count($c, $orders, 'US') + te_pending_buy_count($c, $orders, 'JP');
                        $size = te_size($c, $capital, $positions, $sig, $market, te_pending_buy_count($c, $orders, $market), $pendingTotal);
                        if ($size['qty'] < 1) {
                            $block = 'POSITION_SIZE_ZERO: '.$size['reason'];
                        } else {
                            $intent = te_make_intent(
                                $c, $ctx, 'BUY', $size['qty'], (float)$sig['entry_price'], (float)$sig['stop_price'], (float)$sig['target_price'],
                                (string)$sig['type'], (string)$sig['reason'], (string)($sig['metrics']['scenario_id'] ?? '')
                            );
                            if (te_append_intent($c, $intent)) {
                                $states[$key]['phase'] = 'ENTRY_PENDING';
                                $states[$key]['pending_order_id'] = $intent['order_id'];
                                $intentCreated = true;
                                $intentId = $intent['order_id'];
                                $action = 'BUY';
                                $row['status'] = 'BUY_PENDING';
                                $row['order_id'] = $intentId;
                                $row['order_qty'] = $size['qty'];
                            } else {
                                $block = 'INTENT_APPEND_FAILED';
                            }
                        }
                    }
                }
            }
            $row['block_reason'] = $block;
            $book['markets'][$market]['rows'][$symbol] = $row;
        }

        $orders = te_broker_orders($c);
        $capital = te_capital($c, $positions, $trades, $orders);
        $stats = te_stats($positions, $trades, te_strategy_orders($c, $orders));
        te_save($c['model_state_file'], $states);
        te_save($c['positions_file'], $positions);
        te_save($c['trades_file'], $trades);
        te_save($c['fill_ledger_file'], $ledger);
        te_save($c['capital_file'], $capital);
        te_save($c['stats_file'], $stats);
        te_save($c['candidates_file'], $book);

        return [
            'ok'=>true, 'message'=>'priority tick complete', 'tick_id'=>$tick, 'market'=>$market, 'symbol'=>$symbol,
            'signal'=>[
                'status'=>$sig['status'], 'type'=>$sig['type'], 'score'=>$sig['score'], 'reason'=>$sig['reason'],
                'block_reason'=>(string)($row['block_reason'] ?? $sig['block_reason']),
            ],
            'intent_created'=>$intentCreated, 'intent_id'=>$intentId, 'action'=>$action,
        ];
    } catch (Throwable $e) {
        te_log($c['error_file'], 'PRIORITY_TICK '.$market.':'.$symbol.' '.$e->getMessage());
        return ['ok'=>false, 'message'=>$e->getMessage()];
    } finally {
        te_unlock();
    }
}

function te_reconcile(array $c,array $positions,array $trades,array $states,array $ledger,array $orders): array
{
    $fills=0;
    foreach($orders as $o){
        if(!is_array($o)||strtolower((string)($o['strategy_key']??''))!==$c['strategy_key'])continue;
        $id=(string)($o['order_id']??'');if($id==='')continue;
        $status=strtoupper((string)($o['status']??''));
        $filled=max(0,(int)($o['filled_qty']??0));$done=max(0,(int)($ledger[$id]['processed_qty']??0));
        $reportedAvg=max(0.0,(float)($o['avg_fill_price']??$o['price']??0));$reportedNotional=$filled*$reportedAvg;$doneNotional=max(0.0,(float)($ledger[$id]['processed_notional']??($done*$reportedAvg)));
        $delta=max(0,$filled-$done);$applied=0;
        $key=strtoupper((string)($o['market']??'')).':'.(string)($o['symbol']??'');

        if($delta>0){
            $fillPrice=$delta>0?($reportedNotional-$doneNotional)/$delta:$reportedAvg;if(!is_finite($fillPrice)||$fillPrice<=0)$fillPrice=$reportedAvg;$side=strtoupper((string)($o['side']??''));
            if($fillPrice<=0){te_log($c['error_file'],'RECONCILE_INVALID_PRICE '.$id);}
            elseif($side==='BUY'){
                $old=is_array($positions[$key]??null)?$positions[$key]:[];$oldQty=(int)($old['qty']??0);$newQty=$oldQty+$delta;
                $avg=$newQty>0?(($oldQty*(float)($old['entry_price']??0))+$delta*$fillPrice)/$newQty:$fillPrice;
                $market=strtoupper((string)$o['market']);$fillGross=$delta*$fillPrice;$fillCost=$fillGross+te_buy_fee($market,$fillGross);
                $positions[$key]=array_merge($old,[
                    'strategy_id'=>strtoupper($c['strategy_key']),'market'=>$market,'symbol'=>(string)$o['symbol'],'name'=>(string)($o['name']??$o['symbol']),'exchange'=>(string)($o['exchange']??''),
                    'qty'=>$newQty,'entry_price'=>round($avg,4),'buy_total'=>round((float)($old['buy_total']??0)+$fillCost,4),'current_price'=>$fillPrice,'peak_price'=>max($fillPrice,(float)($old['peak_price']??0)),
                    'stop_price'=>(float)($o['stop_price']??0),'target_price'=>(float)($o['target_price']??0),'entry_type'=>(string)($o['signal_type']??''),
                    'scenario_id'=>(string)($o['scenario_id']??''),'scenario_key'=>(string)($o['scenario_key']??''),'risk_group'=>(string)($o['risk_group']??''),'correlation_group'=>te_correlation_group((string)($o['risk_group']??''),$market,(string)$o['symbol'],(string)($o['name']??$o['symbol']),(string)($o['asset_type']??''),!empty($o['is_inverse']),!empty($o['is_leveraged'])),'asset_type'=>(string)($o['asset_type']??''),'is_inverse'=>!empty($o['is_inverse']),'is_leveraged'=>!empty($o['is_leveraged']),'position_cohort'=>te_position_cohort($c,array_merge($old,['entry_strategy_rev'=>(string)($old['entry_strategy_rev']??$o['strategy_rev']??$c['strategy_rev']),'entry_type'=>(string)($o['signal_type']??'')])), 'entry_time'=>(string)($old['entry_time']??$o['filled_at']??te_now()),'entry_market_date'=>(string)($old['entry_market_date']??$o['signal_market_date']??substr((string)($o['filled_at']??te_now()),0,10)),
                    'entry_strategy_rev'=>(string)($old['entry_strategy_rev']??$o['strategy_rev']??$c['strategy_rev']),'entry_validation_id'=>(string)($old['entry_validation_id']??$o['decision']['validation_id']??''),'entry_signal_id'=>(string)($old['entry_signal_id']??$o['signal_id']??$o['decision']['signal_id']??''),'primary_strategy'=>(string)($old['primary_strategy']??$o['primary_strategy']??strtoupper($c['strategy_key'])),'supporting_strategies'=>is_array($old['supporting_strategies']??null)?$old['supporting_strategies']:(is_array($o['supporting_strategies']??null)?$o['supporting_strategies']:[]),'alpha_type'=>(string)($old['alpha_type']??$o['alpha_type']??''),'strategy_hash'=>(string)($old['strategy_hash']??$o['strategy_hash']??''),'system_hash'=>(string)($old['system_hash']??$o['system_hash']??''),'entry_decision'=>is_array($old['entry_decision']??null)?$old['entry_decision']:(is_array($o['decision']??null)?$o['decision']:[]),
                    'execution_mode'=>(string)($o['execution_mode']??'PAPER'),'status'=>'OPEN','updated_at'=>te_now(),
                ]);
                
                if(isset($states[$key])){$states[$key]['phase']='IN_POSITION';unset($states[$key]['pending_order_id']);}
                $applied=$delta;$fills++;
            }elseif($side==='SELL'){
                if(!isset($positions[$key])){te_log($c['error_file'],'RECONCILE_SELL_WITHOUT_POSITION '.$id.' '.$key);}
                else{
                    $p=$positions[$key];$qty=min($delta,max(0,(int)($p['qty']??0)));
                    if($qty>0){
                        $entry=(float)($p['entry_price']??0);$oldQty=max(1,(int)$p['qty']);$buyTotal=(float)($p['buy_total']??($entry*$oldQty));$allocatedCost=$buyTotal*($qty/$oldQty);$sellGross=$fillPrice*$qty;$sellNet=$sellGross-te_sell_fee((string)$p['market'],$sellGross);$profit=$sellNet-$allocatedCost;$ret=$allocatedCost>0?$profit/$allocatedCost*100.0:0.0;
                        $trades[]=['strategy_id'=>strtoupper($c['strategy_key']),'strategy_rev'=>$c['strategy_rev'],'market'=>$p['market'],'symbol'=>$p['symbol'],'name'=>$p['name'],'qty'=>$qty,
                            'buy_time'=>(string)($p['entry_time']??''),'buy_price'=>$entry,'buy_total'=>round($allocatedCost,4),'sell_time'=>(string)($o['filled_at']??te_now()),'sell_price'=>$fillPrice,'sell_net'=>round($sellNet,4),
                            'profit'=>round($profit,4),'return_pct'=>round($ret,3),'entry_type'=>(string)($p['entry_type']??''),'entry_strategy_rev'=>(string)($p['entry_strategy_rev']??''),'position_cohort'=>(string)($p['position_cohort']??te_position_cohort($c,$p)),'entry_validation_id'=>(string)($p['entry_validation_id']??$p['entry_decision']['validation_id']??''),'signal_id'=>(string)($p['entry_signal_id']??$p['entry_decision']['signal_id']??''),'primary_strategy'=>(string)($p['primary_strategy']??strtoupper($c['strategy_key'])),'supporting_strategies'=>is_array($p['supporting_strategies']??null)?$p['supporting_strategies']:[],'alpha_type'=>(string)($p['alpha_type']??''),'strategy_hash'=>(string)($p['strategy_hash']??''),'system_hash'=>(string)($p['system_hash']??''),'validation_status'=>(string)(($p['entry_signal_id']??'')!==''?'VERIFIED_PIPELINE':'LEGACY_UNVERIFIED'),'risk_group'=>(string)($p['risk_group']??''),'correlation_group'=>(string)($p['correlation_group']??te_correlation_group((string)($p['risk_group']??''),(string)$p['market'],(string)$p['symbol'],(string)$p['name'],(string)($p['asset_type']??''),!empty($p['is_inverse']),!empty($p['is_leveraged']))),'sell_reason'=>(string)($o['reason']??''),'execution_mode'=>(string)($o['execution_mode']??'PAPER')];
                        $remain=(int)$p['qty']-$qty;if($remain<=0){$sellReason=(string)($o['reason']??'');unset($positions[$key]);if(!isset($states[$key])||!is_array($states[$key]))$states[$key]=[];if(te_is_stop_reason($sellReason)){$untilDate=te_add_business_days(te_session_date((string)$p['market']),(int)$c['stop_reentry_business_days'],(string)$p['market'],$c);$states[$key]['phase']='COOLDOWN';$states[$key]['cooldown_until']=$untilDate.' 23:59:59';$states[$key]['last_reason']='STOP_REENTRY_COOLDOWN';}else{$states[$key]['phase']='WAIT_SIGNAL';}unset($states[$key]['pending_order_id']);}
                        else{$positions[$key]['qty']=$remain;$positions[$key]['buy_total']=round($buyTotal-$allocatedCost,4);$positions[$key]['updated_at']=te_now();}
                        $applied=$qty;$fills++;
                    }
                }
            }else te_log($c['error_file'],'RECONCILE_INVALID_SIDE '.$id.' '.$side);
            if($applied>0)$ledger[$id]=['processed_qty'=>$done+$applied,'processed_notional'=>round($doneNotional+$applied*$fillPrice,8),'reported_filled_qty'=>$filled,'reported_avg_fill_price'=>$reportedAvg,'updated_at'=>te_now()];
            if($applied<$delta)te_log($c['error_file'],'RECONCILE_UNAPPLIED '.$id.' delta='.$delta.' applied='.$applied);
        }

        if(in_array($status,['REJECTED','CANCELLED','EXPIRED'],true)&&isset($states[$key])&&($states[$key]['phase']??'')==='ENTRY_PENDING'){
            $states[$key]['phase']='WAIT_SIGNAL';$states[$key]['last_reason']='ORDER_'.$status;unset($states[$key]['pending_order_id']);
        }
        if(in_array($status,['REJECTED','CANCELLED','EXPIRED','BROKER_INGEST_MISSING','BROKER_ORDER_MISSING_AFTER_INGEST','FILLED','PAPER_FILLED'],true)&&isset($positions[$key]))unset($positions[$key]['pending_sell_order_id']);
    }
    return compact('positions','trades','states','ledger','fills');
}



function te_context(array $c, array $item, array $quote, array $marketStatus, string $tick): array
{
    $market = (string)$item['market'];
    $bars = [];
    $sources = [];
    $missing = [];
    foreach ($c['requirements'] as $timeframe => $rawRequirement) {
        $requirement = is_array($rawRequirement) ? $rawRequirement : [];
        $interval = (string)($requirement['interval'] ?? $timeframe);
        $best = [];
        $ranges = is_array($requirement['ranges'] ?? null)
            ? $requirement['ranges']
            : [(string)($requirement['range'] ?? '1y')];
        foreach ($ranges as $range) {
            $rows = te_bars($c, $item, (string)$range, $interval, $marketStatus);
            if (count($rows) > count($best)) $best = $rows;
            if (count($best) >= (int)($requirement['min_bars'] ?? 30)) break;
        }
        if (!empty($requirement['complete_only'])) {
            $best = te_completed($best, $interval, $market, $marketStatus);
        }
        $bars[$timeframe] = $best;
        $sources[$timeframe] = te_bar_source($best, $market);
        $needed = (int)($requirement['min_bars'] ?? 30);
        if (empty($requirement['optional']) && count($best) < $needed) $missing[$timeframe] = ['have'=>count($best), 'need'=>$needed];
    }

    $priceSource = te_source_family((string)($quote['market_source'] ?? $quote['source'] ?? ''), $market);
    $sourceMismatch = [];
    foreach ($sources as $timeframe => $source) {
        $requirement=is_array($c['requirements'][$timeframe]??null)?$c['requirements'][$timeframe]:[];
        if(!empty($requirement['optional'])&&$source==='')continue;
        if ($source === '' || $source !== $priceSource) $sourceMismatch[$timeframe] = $source === '' ? 'EMPTY' : $source;
    }

    $quoteTimestamp = (int)($quote['ts'] ?? 0);
    $quoteFuture = $quoteTimestamp > time()+60;
    $quoteAge = $quoteTimestamp > 0 ? max(0, time() - $quoteTimestamp) : PHP_INT_MAX;
    $entryWindow = te_market_flag($marketStatus,$market,'entry_open');
    $freshLimit = max(30,(int)($c['quote_fresh_age_by_market'][$market] ?? TE_QUOTE_FRESH_MAX_AGE));
    $scanLimit = max($freshLimit,(int)($c['quote_scan_max_age_by_market'][$market] ?? $freshLimit));
    $quoteFreshness = 'STALE';
    if (!$entryWindow && te_is_weekend($market)) {
        $closedFresh = $quoteTimestamp > 0 && $quoteTimestamp >= te_last_trading_close_ts($market) && !empty($quote['ok']) && empty($quote['stale']);
        $quoteFreshness = $closedFresh ? 'CLOSED_FRESH' : 'STALE';
    } elseif ($quoteTimestamp > 0 && !$quoteFuture && !empty($quote['ok']) && empty($quote['stale'])) {
        if ($quoteAge <= $freshLimit) $quoteFreshness = 'FRESH';
        elseif ($entryWindow && $quoteAge <= $scanLimit) $quoteFreshness = 'DELAYED';
        elseif (!$entryWindow && $quoteAge <= 86400) $quoteFreshness = 'CLOSED_FRESH';
    }
    $analysisUsable = in_array($quoteFreshness,['FRESH','DELAYED','CLOSED_FRESH'],true);
    $requiresLiveRequote = $quoteFreshness === 'DELAYED' && !empty($c['require_live_requote_for_delayed']);

    $dataCode = 'OK';
    $dataDetail = [];
    if ($missing) {
        $dataCode = 'DATA_SHORT';
        foreach ($missing as $timeframe => $value) $dataDetail[] = $timeframe.'<'.$value['need'].' ('.$value['have'].')';
    } elseif ($priceSource === '' || strpos($priceSource, 'NXT') !== false || $sourceMismatch) {
        $dataCode = 'DATA_SOURCE_MISMATCH';
        foreach ($sourceMismatch as $timeframe => $source) $dataDetail[] = $timeframe.':'.$source;
    } elseif (!$analysisUsable) {
        $dataCode = 'DATA_STALE';
        $dataDetail[] = 'quote_age='.$quoteAge;
        $dataDetail[] = 'scan_limit='.$scanLimit;
        if($quoteFuture)$dataDetail[] = 'quote_timestamp_future';    } elseif ($quoteFreshness === 'DELAYED') {
        $dataCode = 'DATA_DELAYED';
        $dataDetail[] = 'quote_age='.$quoteAge;
        $dataDetail[] = 'fresh_limit='.$freshLimit;
        $dataDetail[] = 'scan_limit='.$scanLimit;
    }

    $marketBars = te_market_bars($c, $market, $marketStatus);
    $sectorBars = $c['strategy_key']==='das' ? te_sector_bars($c,$item,$market,$marketStatus) : [];
    $symbolData = array_merge($item,te_external_risk_overlay($c,$market,(string)$item['symbol']));
    $completedDaily = isset($bars['1d']) && is_array($bars['1d']) && !empty($bars['1d']);
    $context = [
        'market'=>$market,
        'symbol'=>(string)$item['symbol'],
        'name'=>(string)$item['name'],
        'exchange'=>(string)($item['exchange'] ?? ''),
        'currency'=>$market==='KR'?'KRW':($market==='JP'?'JPY':'USD'),
        'symbol_data'=>$symbolData,
        'session_date'=>te_session_date($market),
        'entry_window'=>$entryWindow,
        'market_status'=>$marketStatus,
        'quote'=>$quote,
        'bars'=>$bars,
        'market_bars'=>$marketBars,
        'benchmark_bars'=>$marketBars,
        'sector_bars'=>$sectorBars,
        'completed_daily'=>$completedDaily,
        'data_status'=>$completedDaily ? 'FINAL_CLOSE' : ($dataCode==='DATA_SHORT'?'DATA_SHORT':'LIVE_PREVIEW'),
        'data_code'=>$dataCode,
        'data_detail'=>$dataDetail,
        'strategy_settings'=>$c['strategy_settings'],
        'limits'=>$c['strategy_settings'],
        'engine_capabilities'=>$c['engine_capabilities'],
        'data_ok'=>in_array($dataCode,['OK','DATA_DELAYED'],true),
        'data_reason'=>$dataCode === 'OK' ? '' : $dataCode.($dataDetail ? ': '.implode(', ', $dataDetail) : ''),
        'quote_freshness'=>$quoteFreshness,
        'requires_live_requote'=>$requiresLiveRequote,
        'meta'=>[
            'tick_id'=>$tick,
            'price_source'=>$priceSource,
            'data_sources'=>$sources,
            'required_timeframes'=>array_keys($c['requirements']),
            'daily_source'=>(string)($sources['1d'] ?? ''),
            'm60_source'=>(string)($sources['60m'] ?? ''),
            'm30_source'=>(string)($sources['30m'] ?? ''),
            'm1_source'=>(string)($sources['1m'] ?? ''),
            'quote_timestamp'=>$quoteTimestamp,
            'data_timestamp'=>$quoteTimestamp>0?date('c',$quoteTimestamp):'',
            'provider'=>$priceSource,
            'data_quality'=>$dataCode,
            'quote_age_sec'=>$quoteAge,
            'quote_freshness'=>$quoteFreshness,
            'quote_fresh_limit_sec'=>$freshLimit,
            'quote_scan_limit_sec'=>$scanLimit,
            'requires_live_requote'=>$requiresLiveRequote,
            'order_live_quote_max_age_sec'=>(int)$c['order_live_quote_max_age'],
            'data_date_timeframe'=>(string)$c['data_date_timeframe'],
            'scan_date_mode'=>(string)$c['scan_date_mode'],
        ],
    ];
    $context['market_date'] = te_context_data_date($context, $market, (string)$c['data_date_timeframe']);
    $context['benchmark_date'] = te_last_bar_date($marketBars, $market);
    return $context;
}


function te_candidate(array $item, array $quote, array $signal, array $context = []): array
{
    $modelStatus = strtoupper((string)($signal['status'] ?? 'FILTERED'));
    $type = strtoupper((string)($signal['type'] ?? ''));
    $displayStatus = te_candidate_display_status($modelStatus, $type);
    return [
        'time'=>te_now(),
        'market'=>$item['market'],
        'symbol'=>$item['symbol'],
        'name'=>$item['name'],
        'status'=>$displayStatus,
        'model_status'=>$modelStatus,
        'type'=>(string)($signal['type'] ?? ''),
        'score'=>(int)round((float)($signal['score'] ?? 0)),
        'price'=>(float)($quote['price'] ?? 0),
        'current_quote_price'=>(float)($quote['price'] ?? 0),
        'analysis_reference_price'=>(float)($signal['metrics']['decision_price'] ?? $signal['metrics']['reference_price'] ?? $signal['entry_price'] ?? 0),
        'entry_price'=>(float)($signal['entry_price'] ?? 0),
        'stop_price'=>(float)($signal['stop_price'] ?? 0),
        'target_price'=>(float)($signal['target_price'] ?? 0),
        'reason'=>(string)($signal['reason'] ?? ''),
        'block_reason'=>(string)($signal['block_reason'] ?? ''),
        'signal_id'=>(string)($signal['metrics']['signal_id']??$signal['metrics']['validation_id']??''),
        'data_code'=>(string)($context['data_code'] ?? 'OK'),
        'quote_freshness'=>(string)($context['quote_freshness'] ?? ($context['meta']['quote_freshness'] ?? 'UNKNOWN')),
        'quote_age_sec'=>(int)($context['meta']['quote_age_sec'] ?? 0),
        'requires_live_requote'=>!empty($context['requires_live_requote']) || !empty($context['meta']['requires_live_requote']),
        'metrics'=>array_merge(is_array($signal['metrics'] ?? null) ? $signal['metrics'] : [],[
            'data_code'=>(string)($context['data_code'] ?? 'OK'),
            'quote_freshness'=>(string)($context['quote_freshness'] ?? ($context['meta']['quote_freshness'] ?? 'UNKNOWN')),
            'quote_age_sec'=>(int)($context['meta']['quote_age_sec'] ?? 0),
            'requires_live_requote'=>!empty($context['requires_live_requote']) || !empty($context['meta']['requires_live_requote']),
        ]),
    ];
}

function te_candidate_display_status(string $modelStatus, string $type): string
{
    if (preg_match('/^(DATA_SHORT|MA_SHORT|STOCH_SHORT|INDICATOR_SHORT|BENCHMARK_SHORT)/', $type)) return 'DATA_SHORT';
    if (preg_match('/^(DATA_STALE|BENCHMARK_STALE)/', $type)) return 'DATA_STALE';
    if (preg_match('/^DATA_DELAYED/', $type)) return $modelStatus==='BUY'?'BUY':($modelStatus==='WATCH'?'WATCH':'FILTERED');
    if (preg_match('/^(DATA_DATE_MISMATCH|SCAN_DATE_MIXED|BENCHMARK_DATE_MISMATCH)/', $type)) return 'DATA_DATE_MISMATCH';
    if (preg_match('/^(DATA_SOURCE_MISMATCH|QUOTE_ERROR|API_ERROR|PARSE_ERROR|MODEL_EXCEPTION|RUNTIME_ERROR|DATA_ERROR)/', $type)) return 'DATA_ERROR';
    return in_array($modelStatus, ['BUY','WATCH','FILTERED'], true) ? $modelStatus : 'FILTERED';
}

function te_ranked_data_status(array $row): string
{
    $codes=is_array($row['reason_codes']??null)?$row['reason_codes']:[];$metrics=is_array($row['metrics']??null)?$row['metrics']:[];
    $tokens=strtoupper(implode(' ',array_merge($codes,[(string)($row['data_status']??''),(string)($row['data_code']??''),(string)($metrics['data_status']??''),(string)($metrics['data_code']??'')])));
    if(preg_match('/DATA_DATE_MISMATCH|SCAN_DATE_MIXED|BENCHMARK_DATE_MISMATCH/',$tokens))return'DATA_DATE_MISMATCH';
    if(preg_match('/DATA_STALE|BENCHMARK_STALE|CARRIED_PREVIOUS_CLOSE|\bSTALE\b/',$tokens))return'DATA_STALE';
    if(preg_match('/DATA_SHORT|DATA_INSUFFICIENT|MA_SHORT|STOCH_SHORT|INDICATOR_SHORT|BENCHMARK_SHORT|NO_DATA/',$tokens))return'DATA_SHORT';
    if(preg_match('/DATA_SOURCE_MISMATCH|QUOTE_ERROR|API_ERROR|PARSE_ERROR|MODEL_EXCEPTION|MODEL_INVALID|RUNTIME_ERROR|DATA_ERROR/',$tokens))return'DATA_ERROR';
    return'';
}

function te_is_hard_data_status(string $status): bool
{
    return in_array(strtoupper($status),['DATA_SHORT','DATA_STALE','DATA_DATE_MISMATCH','DATA_ERROR'],true);
}

function te_ranked_display_status(array $row): string
{
    $dataStatus=te_ranked_data_status($row);if($dataStatus!=='')return$dataStatus;$struct=!empty($row['structural_eligible']);$pass=!empty($row['score_pass']);return$struct?($pass?'CANDIDATE':'WATCH'):'FILTERED';
}


function te_intent_ttl(array $c): int
{
    $approval=te_load($c['broker_approval_mode_file'],[]);
    $mode=strtolower((string)($approval['mode']??'manual'));
    return $mode==='auto'?(int)$c['intent_ttl_auto']:(int)$c['intent_ttl_manual'];
}

function te_intent_ttl_by_side(array $c, string $side, string $type = '', string $reason = ''): int
{
    if (strtoupper($side) === 'SELL') return max(86400, (int)($c['sell_intent_ttl_sec'] ?? TE_SELL_INTENT_TTL_SEC));
    return te_intent_ttl($c);
}

function te_make_intent(array $c,array $ctx,string $side,int $qty,float $price,float $stop,float $target,string $type,string $reason,string $scenario): array
{
    $market=(string)$ctx['market'];$symbol=(string)$ctx['symbol'];$id=strtoupper($c['strategy_key']).'-'.$market.'-'.$symbol.'-'.date('Ymd-His').'-'.te_hex(3);
    $signalId=(string)($ctx['signal_id']??$ctx['validation_id']??$ctx['ranked_result']['signal_id']??$ctx['ranked_result']['validation_id']??'');$strategyHash=tv_strategy_hash($c);$systemHash=tv_system_hash($c);$strategyStatus=tv_strategy_status($c);$alphaType=tv_alpha_type($c);$primary=strtoupper((string)$c['strategy_key']);$supports=function_exists('tv_supporting_strategies')?tv_supporting_strategies($c,$market,$symbol,(string)($ctx['market_date']??$ctx['session_date']??date('Y-m-d')),$primary):[];
    $accountMode=$strategyStatus==='CHALLENGER'?'CHALLENGER_PAPER':'CORE_SYSTEM';$ranked=is_array($ctx['ranked_result']??null)?$ctx['ranked_result']:[];$ai=is_array($ranked['ai']??null)?$ranked['ai']:[];$decision=['validation_id'=>(string)($ctx['validation_id']??$ranked['validation_id']??''),'signal_id'=>$signalId,'rank'=>$ranked['rank']??null,'rule_score'=>$ranked['rule_score']??null,'final_score'=>$ranked['final_score']??null,'reason_codes'=>is_array($ranked['reason_codes']??null)?array_values($ranked['reason_codes']):[],'audit'=>is_array($ranked['audit']??null)?$ranked['audit']:[],'quote_freshness'=>(string)($ctx['meta']['quote_freshness']??'UNKNOWN'),'quote_age_sec'=>(int)($ctx['meta']['quote_age_sec']??0),'requires_live_requote'=>!empty($ctx['meta']['requires_live_requote'])];
    $signalDate=(string)($ctx['market_date']??te_context_data_date($ctx,$market,(string)($c['data_date_timeframe']??'')));$validFrom=te_now();$expires=date('Y-m-d H:i:s',time()+te_intent_ttl_by_side($c,$side,$type,$reason));$symbolData=is_array($ctx['symbol_data']??null)?$ctx['symbol_data']:[];$assetType=(string)($symbolData['asset_type']??'');$riskGroup=(string)($symbolData['risk_group']??te_infer_risk_group($market,$symbol,(string)($ctx['name']??$symbol),$assetType,!empty($symbolData['is_inverse']),!empty($symbolData['is_leveraged'])));$scenarioKey=(string)($ctx['ranked_result']['audit']['scenario_key']??$ctx['ranked_result']['scenario_key']??te_scenario_key($c,$market,$symbol,$type,$signalDate,$scenario));
    $isSell=strtoupper($side)==='SELL';$explicitUrgent=!empty($ctx['exit_urgent']);$urgentPattern=(bool)preg_match('/EMERGENCY|ACCOUNT_RISK|TRADING_HALT|DELIST|DRAWDOWN|INITIAL_STOP|HARD_STOP|손절|거래정지|상장폐지/i',$type.' '.$reason);$urgentExit=$isSell&&($explicitUrgent||$urgentPattern);$priority=$isSell?($urgentExit?'EXIT_RISK':'EXIT_STRATEGY'):'ENTRY';$exitUrgency=$isSell?($urgentExit?'URGENT':'NORMAL'):'NONE';
    return ['schema'=>TE_SCHEMA,'intent_contract_rev'=>TE_INTENT_CONTRACT_REV,'owner'=>'engine','status'=>'INTENT_CREATED','order_id'=>$id,'created_at'=>te_now(),'order_valid_from'=>$validFrom,'expires_at'=>$expires,'order_expires_at'=>$expires,'time_in_force'=>$isSell?'GTC_EXIT':'SESSION_SIGNAL','sell_persistent'=>$isSell,'urgent_exit'=>$urgentExit,'exit_urgency'=>$exitUrgency,'order_priority'=>$priority,'entry_contract'=>(string)($c['entry_contract']??'STOP_BASED'),'sizing_mode'=>(string)($c['sizing_mode']??'RISK_STOP'),'strategy_key'=>$c['strategy_key'],'strategy_id'=>strtoupper($c['strategy_key']),'strategy_rev'=>$c['strategy_rev'],'strategy_version'=>(string)($c['strategy_version']??''),'strategy_status'=>$strategyStatus,'account_mode'=>$accountMode,'alpha_type'=>$alphaType,'strategy_hash'=>$strategyHash,'system_hash'=>$systemHash,'signal_id'=>$signalId,'primary_strategy'=>$primary,'supporting_strategies'=>$supports,'strategy_file_id'=>$c['strategy_file_id'],
        'market'=>$market,'currency'=>$market==='KR'?'KRW':($market==='JP'?'JPY':'USD'),'symbol'=>$symbol,'name'=>(string)$ctx['name'],'exchange'=>(string)($ctx['exchange']??''),'side'=>$side,'order_type'=>'LIMIT','price'=>round($price,4),'qty'=>$qty,'amount'=>round($price*$qty,4),'stop_price'=>round($stop,4),'target_price'=>round($target,4),
        'signal_type'=>$type,'reason'=>$reason,'scenario_id'=>$scenario,'scenario_key'=>$scenarioKey,'asset_type'=>$assetType,'risk_group'=>$riskGroup,'is_inverse'=>!empty($symbolData['is_inverse']),'is_leveraged'=>!empty($symbolData['is_leveraged']),'session_date'=>(string)($ctx['session_date']??te_session_date($market)),'signal_market_date'=>$signalDate,'price_source'=>(string)($ctx['meta']['price_source']??''),'data_sources'=>is_array($ctx['meta']['data_sources']??null)?$ctx['meta']['data_sources']:[],
        'required_timeframes'=>is_array($ctx['meta']['required_timeframes']??null)?$ctx['meta']['required_timeframes']:[],'daily_source'=>(string)($ctx['meta']['daily_source']??''),'m60_source'=>(string)($ctx['meta']['m60_source']??''),'m30_source'=>(string)($ctx['meta']['m30_source']??''),'quote_timestamp'=>(int)($ctx['meta']['quote_timestamp']??0),'quote_age_sec'=>(int)($ctx['meta']['quote_age_sec']??0),'quote_freshness'=>(string)($ctx['meta']['quote_freshness']??'UNKNOWN'),'quote_fresh_limit_sec'=>(int)($ctx['meta']['quote_fresh_limit_sec']??0),'quote_scan_limit_sec'=>(int)($ctx['meta']['quote_scan_limit_sec']??0),'requires_live_requote'=>!empty($ctx['meta']['requires_live_requote']),'order_live_quote_max_age_sec'=>(int)($ctx['meta']['order_live_quote_max_age_sec']??TE_ORDER_LIVE_QUOTE_MAX_AGE),'analysis_price'=>round($price,4),'tick_id'=>(string)($ctx['meta']['tick_id']??''),'decision'=>$decision,'ai'=>$ai];
}

function te_intent_signed_fields(): array
{
    return ['schema','intent_contract_rev','owner','order_id','created_at','order_valid_from','order_expires_at','time_in_force','sell_persistent','urgent_exit','exit_urgency','order_priority','entry_contract','sizing_mode','strategy_key','strategy_id','strategy_rev','strategy_version','strategy_status','account_mode','alpha_type','strategy_hash','system_hash','signal_id','primary_strategy','supporting_strategies','strategy_file_id','market','currency','symbol','name','exchange','side','order_type','price','qty','amount','stop_price','target_price','signal_type','reason','scenario_id','scenario_key','asset_type','risk_group','is_inverse','is_leveraged','session_date','signal_market_date','price_source','data_sources','required_timeframes','daily_source','m60_source','m30_source','quote_timestamp','quote_age_sec','quote_freshness','quote_fresh_limit_sec','quote_scan_limit_sec','requires_live_requote','order_live_quote_max_age_sec','analysis_price','tick_id','decision','ai'];
}
function te_canonical_value($value)
{
    if(!is_array($value))return$value;
    $keys=array_keys($value);$isList=$value===[]||$keys===range(0,count($value)-1);
    if($isList){$out=[];foreach($value as$item)$out[]=te_canonical_value($item);return$out;}
    ksort($value,SORT_STRING);foreach($value as$key=>$item)$value[$key]=te_canonical_value($item);return$value;
}
function te_intent_hash(array $intent): string
{
    $data=[];foreach(te_intent_signed_fields()as$key)$data[$key]=$intent[$key]??null;
    return hash('sha256',te_json(te_canonical_value($data),false));
}




function te_transport_order_ids_from_file(string $file): array
{
    $root=te_load($file,[]);$rows=is_array($root['orders']??null)?$root['orders']:$root;$out=[];
    foreach((array)$rows as$o)if(is_array($o)){ $id=(string)($o['order_id']??''); if($id!=='')$out[$id]=true; }
    return $out;
}
function te_transport_intent_safe_to_migrate(array $intent): bool
{
    $id=(string)($intent['order_id']??'');if($id==='')return false;
    if((string)($intent['owner']??'')!=='engine')return false;
    $status=strtoupper((string)($intent['status']??'INTENT_CREATED'));
    if(in_array($status,['CANCELLED','REJECTED','FILLED','PAPER_FILLED','BROKER_INGEST_MISSING','BROKER_ORDER_MISSING_AFTER_INGEST'],true))return false;
    $received=(string)($intent['intent_hash']??'');
    if($received===''||!hash_equals(te_intent_hash($intent),$received))return false;
    $side=strtoupper((string)($intent['side']??''));
    $exp=strtotime((string)($intent['order_expires_at']??$intent['expires_at']??''));
    if($side!=='SELL'&&$exp!==false&&$exp<time())return false;
    return true;
}
function te_transport_spool_same_intent(string $file,string $orderId,string $intentHash): bool
{
    if(!is_file($file))return false;
    $x=te_load($file,[]);$i=is_array($x['intent']??null)?$x['intent']:[];
    return (string)($i['order_id']??'')===$orderId
        &&$intentHash!==''
        &&hash_equals((string)($i['intent_hash']??''),$intentHash);
}
function te_migrate_legacy_transport(array $c): array
{
    $result=[
        'enabled'=>false,'migrated'=>0,'archived'=>0,'already_present'=>0,'skipped'=>0,'invalid'=>0,
        'legacy_broker_busy'=>false,'ids'=>[],'source'=>'','target'=>(string)($c['broker_runtime']??'')
    ];
    if(empty($c['single_file_paper']))return $result;
    $legacy=rtrim((string)($c['legacy_broker_runtime']??''),'/\\');
    $active=rtrim((string)($c['broker_runtime']??''),'/\\');
    if($legacy===''||$active===''||te_runtime_path_same($legacy,$active))return $result;
    $legacySpool=$legacy.'/intent_spool';$result['enabled']=true;$result['source']=$legacy;
    if(!is_dir($legacySpool))return $result;

    // Freeze any legacy Broker cycle while an eligible intent is transferred.
    $legacyLock=@fopen($legacy.'/broker.lock','c+');
    if(!$legacyLock||!@flock($legacyLock,LOCK_EX|LOCK_NB)){
        if(is_resource($legacyLock))@fclose($legacyLock);
        $result['legacy_broker_busy']=true;$result['error']='LEGACY_BROKER_BUSY';
        return $result;
    }

    $activeLock=null;
    try{
        $legacyOrders=te_transport_order_ids_from_file($legacy.'/broker_orders.json');
        $activeOrders=te_transport_order_ids_from_file($active.'/broker_orders.json');
        $activeAck=rtrim((string)$c['intent_ack_dir'],'/\\');
        $activeSpool=rtrim((string)$c['intent_spool_dir'],'/\\');
        $legacyAck=$legacy.'/intent_ack';
        $archiveDir=$legacy.'/intent_spool_migrated';
        foreach([$activeSpool,$activeAck,$archiveDir]as$d)if(!is_dir($d)&&!@mkdir($d,0775,true)&&!is_dir($d))throw new RuntimeException('DIR_CREATE_FAILED '.$d);

        $files=glob($legacySpool.'/*.json')?:[];sort($files,SORT_STRING);
        if(count($files)>200)$files=array_slice($files,-200);

        $activeLock=@fopen((string)$c['intents_lock'],'c+');
        if(!$activeLock||!@flock($activeLock,LOCK_EX)){
            $result['error']='ACTIVE_INTENT_LOCK_BUSY';return $result;
        }

        $root=te_load((string)$c['intents_file'],[]);
        $rows=is_array($root['intents']??null)?$root['intents']:[];
        $sharedIds=[];foreach($rows as$r)if(is_array($r)){ $rid=(string)($r['order_id']??''); if($rid!=='')$sharedIds[$rid]=true; }
        $sharedChanged=false;

        foreach($files as$f){
            $raw=@file_get_contents($f);$x=json_decode((string)$raw,true);
            $i=is_array($x['intent']??null)?$x['intent']:[];
            $id=(string)($i['order_id']??$x['order_id']??'');
            if($id===''){ $result['invalid']++; continue; }
            $safe=te_safe($id);$hash=(string)($i['intent_hash']??'');
            if(isset($legacyOrders[$id])||isset($activeOrders[$id])||is_file($legacyAck.'/'.$safe.'.json')||is_file($activeAck.'/'.$safe.'.json')){
                $result['skipped']++;continue;
            }
            if(!te_transport_intent_safe_to_migrate($i)){ $result['invalid']++;continue; }

            $target=$activeSpool.'/'.$safe.'.json';
            if(is_file($target)){
                if(!te_transport_spool_same_intent($target,$id,$hash)){ $result['invalid']++;continue; }
                $result['already_present']++;
            }else{
                $tmp=$target.'.tmp.'.getmypid().'.'.te_hex(2);
                if(@file_put_contents($tmp,(string)$raw,LOCK_EX)===false){@unlink($tmp);$result['invalid']++;continue;}
                $chk=@file_get_contents($tmp);
                if(!is_string($chk)||!hash_equals(hash('sha256',(string)$raw),hash('sha256',$chk))||!@rename($tmp,$target)){
                    @unlink($tmp);$result['invalid']++;continue;
                }
            }

            // Preserve exact legacy evidence, then remove it from the legacy ingest directory.
            $archive=$archiveDir.'/'.basename($f);
            if(!is_file($archive)){
                if(!@copy($f,$archive)){if($result['already_present']===0)@unlink($target);$result['invalid']++;continue;}
                $archRaw=@file_get_contents($archive);
                if(!is_string($archRaw)||!hash_equals(hash('sha256',(string)$raw),hash('sha256',$archRaw))){
                    @unlink($archive);if($result['already_present']===0)@unlink($target);$result['invalid']++;continue;
                }
            }
            if(!@unlink($f)){
                if($result['already_present']===0)@unlink($target);
                $result['invalid']++;continue;
            }
            $result['archived']++;

            if(!isset($sharedIds[$id])){$rows[]=$i;$sharedIds[$id]=true;$sharedChanged=true;}
            $result['migrated']++;$result['ids'][]=$id;
        }
        if($sharedChanged)te_save((string)$c['intents_file'],['schema'=>TE_SCHEMA,'owner'=>'engine','updated_at'=>te_now(),'intents'=>$rows]);
    }finally{
        if(is_resource($activeLock)){@flock($activeLock,LOCK_UN);@fclose($activeLock);}
        @flock($legacyLock,LOCK_UN);@fclose($legacyLock);
    }
    if($result['migrated']>0)te_log((string)$c['error_file'],'LEGACY_TRANSPORT_MIGRATED count='.$result['migrated'].' archived='.$result['archived'].' ids='.implode(',',array_slice($result['ids'],0,10)));
    return $result;
}

function te_intent_spool_path(array $c,string $orderId): string
{
    return rtrim((string)$c['intent_spool_dir'],'/\\').'/'.te_safe($orderId).'.json';
}
function te_intent_ack_path(array $c,string $orderId): string
{
    return rtrim((string)$c['intent_ack_dir'],'/\\').'/'.te_safe($orderId).'.json';
}
function te_intent_ack_load(array $c,string $orderId): array
{
    if($orderId==='')return[];
    $a=te_load(te_intent_ack_path($c,$orderId),[]);
    return is_array($a)?$a:[];
}
function te_write_intent_spool(array $c,array $intent): bool
{
    $id=(string)($intent['order_id']??'');if($id==='')return false;
    $path=te_intent_spool_path($c,$id);
    $row=['schema'=>TE_INTENT_SPOOL_SCHEMA,'owner'=>'engine','order_id'=>$id,'created_at'=>(string)($intent['created_at']??te_now()),'written_at'=>te_now(),'intent_hash'=>(string)($intent['intent_hash']??''),'intent'=>$intent];
    try{te_save($path,$row);return true;}catch(Throwable $e){te_log((string)$c['error_file'],'INTENT_SPOOL_WRITE_FAILED '.$id.' '.$e->getMessage());return false;}
}
function te_ensure_intent_spool(array $c,array $intent): bool
{
    $id=(string)($intent['order_id']??'');if($id==='')return false;
    $path=te_intent_spool_path($c,$id);if(is_file($path))return true;
    return te_write_intent_spool($c,$intent);
}
function te_intent_spool_health(array $c): array
{
    $rows=[];$oldest=null;$byMarket=['KR'=>0,'US'=>0,'JP'=>0];$byStrategy=[];
    foreach(glob(rtrim((string)$c['intent_spool_dir'],'/\\').'/*.json')?:[] as$f){
        $x=te_load($f,[]);$i=is_array($x['intent']??null)?$x['intent']:[];
        $id=(string)($i['order_id']??$x['order_id']??'');if($id==='')continue;
        $ack=te_intent_ack_load($c,$id);if($ack)continue;
        $created=(string)($i['created_at']??$x['created_at']??'');$ts=$created!==''?(strtotime($created)?:0):0;
        $age=$ts>0?max(0,time()-$ts):null;$m=strtoupper((string)($i['market']??''));$sk=strtolower((string)($i['strategy_key']??''));
        if(isset($byMarket[$m]))$byMarket[$m]++;if($sk!=='')$byStrategy[$sk]=(int)($byStrategy[$sk]??0)+1;
        if($age!==null&&($oldest===null||$age>$oldest))$oldest=$age;
        $rows[]=['order_id'=>$id,'strategy_key'=>$sk,'market'=>$m,'symbol'=>(string)($i['symbol']??''),'side'=>strtoupper((string)($i['side']??'')),'created_at'=>$created,'age_sec'=>$age,'status'=>(string)($i['status']??'INTENT_CREATED')];
    }
    usort($rows,static function($a,$b){return(int)($b['age_sec']??0)<=>(int)($a['age_sec']??0);});
    return['pending_ack_count'=>count($rows),'oldest_pending_ack_age_sec'=>$oldest,'by_market'=>$byMarket,'by_strategy'=>$byStrategy,'sample'=>array_slice($rows,0,20)];
}

function te_append_intent(array $c,array $intent): bool
{
    if(empty($intent['intent_hash']))$intent['intent_hash']=te_intent_hash($intent);$intent['intent_hash_alg']='sha256-canonical-v2';
    $fp=@fopen($c['intents_lock'],'c+');if(!$fp||!@flock($fp,LOCK_EX)){if(is_resource($fp))@fclose($fp);return false;}
    $saved=false;
    try{
        $root=te_load($c['intents_file'],[]);$rows=is_array($root['intents']??null)?$root['intents']:[];$broker=te_broker_orders($c);
        $wanted=te_order_identity($intent);
        foreach($rows as$r){if(!is_array($r))continue;if((string)($r['order_id']??'')===(string)$intent['order_id'])return true;if(te_order_identity($r)===$wanted&&te_intent_row_active($r,$broker))return false;}
        foreach($broker as$o)if(is_array($o)&&te_order_identity($o)===$wanted&&te_broker_order_active($o))return false;
        $before=$rows;$rows[]=$intent;
        te_save($c['intents_file'],['schema'=>TE_SCHEMA,'owner'=>'engine','updated_at'=>te_now(),'intents'=>$rows]);
        if(!te_write_intent_spool($c,$intent)){
            te_save($c['intents_file'],['schema'=>TE_SCHEMA,'owner'=>'engine','updated_at'=>te_now(),'intents'=>$before]);
            te_log((string)$c['error_file'],'INTENT_APPEND_ROLLBACK_SPOOL_FAILED '.(string)($intent['order_id']??''));
            return false;
        }
        $saved=true;
    }finally{@flock($fp,LOCK_UN);@fclose($fp);}
    return $saved;
}

function te_has_active_order(array $c,array $brokerOrders,string $key,string $side): bool
{
    $target=strtolower($c['strategy_key']).':'.strtoupper($key).':'.strtoupper($side);$root=te_load($c['intents_file'],[]);$intents=is_array($root['intents']??null)?$root['intents']:[];
    foreach($intents as$i){if(!is_array($i))continue;$id=strtolower((string)($i['strategy_key']??'')).':'.strtoupper((string)($i['market']??'').':'.(string)($i['symbol']??'')).':'.strtoupper((string)($i['side']??''));if($id===$target&&te_intent_row_active($i,$brokerOrders))return true;}
    foreach($brokerOrders as$o){if(!is_array($o))continue;$id=strtolower((string)($o['strategy_key']??'')).':'.strtoupper((string)($o['market']??'').':'.(string)($o['symbol']??'')).':'.strtoupper((string)($o['side']??''));if($id===$target&&te_broker_order_active($o))return true;}
    return false;
}

function te_rebase_order_signal(array $c,array $sig,float $current,float $signalPrice,float $revaluePct): array
{
    $stop=(float)($sig['stop_price']??0);$target=(float)($sig['target_price']??0);$contract=te_normalize_entry_contract((string)($c['entry_contract']??'STOP_BASED'));$sizing=te_normalize_sizing_mode((string)($c['sizing_mode']??($contract==='STOP_BASED'?'RISK_STOP':'ALLOCATION_ONLY')));
    if($current<=0)return['ok'=>false,'reason'=>'ORDER_PRICE_INVALID','signal'=>$sig];
    if($sizing==='RISK_STOP'&&($stop<=0||$stop>=$current))return['ok'=>false,'reason'=>'STOP_REVALIDATION_FAIL','signal'=>$sig];
    $originalEntry=$signalPrice>0?$signalPrice:(float)($sig['entry_price']??0);$rr=0.0;
    if($sizing==='RISK_STOP'&&$target>$originalEntry&&$originalEntry>$stop)$rr=($target-$originalEntry)/($originalEntry-$stop);
    $metrics=is_array($sig['metrics']??null)?$sig['metrics']:[];
    $metrics['signal_entry_price']=round($originalEntry,4);$metrics['order_entry_price']=round($current,4);$metrics['price_revalidation_pct']=round($revaluePct,4);
    $sig['entry_price']=$current;$sig['metrics']=$metrics;
    if($rr>0)$sig['target_price']=round($current+$rr*($current-$stop),4);
    return['ok'=>true,'reason'=>'OK','signal'=>$sig];
}

function te_size(array $c,array $capital,array $positions,array $sig,string $market,int $reservedMarket=0,int $reservedTotal=0): array
{
    $positions=te_scope_positions($c,$positions);$openTotal=count(array_filter($positions,'te_active_position'));
    if($openTotal+$reservedTotal>=$c['max_positions_total'])return['qty'=>0,'reason'=>'전체 최대 보유수'];
    $openMarket=0;foreach($positions as$p)if(te_active_position($p)&&($p['market']??'')===$market)$openMarket++;
    $marketMax=(int)($c['max_positions_by_market'][$market]??$c['max_positions_total']);
    if($openMarket+$reservedMarket>=$marketMax)return['qty'=>0,'reason'=>$market.' 최대 보유수'];
    $price=(float)($sig['entry_price']??0);$stop=(float)($sig['stop_price']??0);$mode=te_normalize_sizing_mode((string)($c['sizing_mode']??'RISK_STOP'));
    if($price<=0)return['qty'=>0,'reason'=>'entry_price 0'];
    if($mode==='RISK_STOP'&&($stop<=0||$stop>=$price))return['qty'=>0,'reason'=>'STOP_BASED 수량계산의 손절가 구조 오류'];
    $cap=is_array($capital[$market]??null)?$capital[$market]:[];$equity=(float)($cap['equity']??0);$cash=(float)($cap['cash']??0);$positionValue=max(0.0,(float)($cap['position_value']??0));$pendingAmounts=te_pending_buy_amounts($c,te_broker_orders($c));$pendingAmount=max(0.0,(float)($pendingAmounts[$market]??0));
    $scale=max(0.05,min(1.0,(float)($sig['metrics']['allocation_scale']??1.0)));$alloc=$equity*$c['max_alloc_pct']*$scale;$max=($market==='KR'?$c['max_order_krw']:($market==='JP'?$c['max_order_jpy']:$c['max_order_usd']))*$scale;$marketRoom=max(0.0,$equity*(float)$c['max_market_invest_pct']-$positionValue-$pendingAmount);$cashRoom=max(0.0,$cash-$equity*(float)$c['min_market_cash_pct']);
    $budget=max(0.0,min($cashRoom,$alloc,$max,$marketRoom));$feeRate=$market==='KR'?TE_KR_BUY_FEE:($market==='JP'?TE_JP_BUY_FEE:TE_US_BUY_FEE);$unitCost=$price*(1.0+$feeRate);$cashQty=$unitCost>0?(int)floor($budget/$unitCost):0;
    $risk=0.0;$riskBudget=0.0;$qty=$cashQty;
    if($mode==='RISK_STOP'){$risk=max($price-$stop,$price*0.015);$riskBudget=$equity*$c['risk_budget_pct'];$riskQty=(int)floor($riskBudget/$risk);$qty=min($cashQty,$riskQty);if($qty<1&&$cashQty>=1&&$equity>0){$oneRiskPct=$risk/$equity;if($oneRiskPct<=(float)$c['one_share_risk_cap_pct'])$qty=1;}}
    $reason=$qty>0?'OK':($marketRoom<=0?'MARKET_EXPOSURE_LIMIT':($cashRoom<=0?'MIN_CASH_RESERVE':'현금·배분한도 부족'));
    return['qty'=>max(0,$qty),'reason'=>$reason,'sizing_mode'=>$mode,'allocation_scale'=>$scale,'risk_per_share'=>round($risk,4),'risk_budget'=>round($riskBudget,4),'budget'=>round($budget,4)];
}

function te_strategy_scope_keys(array $c): array
{
    $status=tv_strategy_status($c);$current=strtolower((string)($c['strategy_key']??''));
    if($status==='CORE')return['dts','abc','das'];
    return$current!==''?[$current]:[];
}
function te_strategy_runtime_for_key(array $c,string $key): string
{
    $key=strtolower($key);if($key===strtolower((string)($c['strategy_key']??'')))return(string)$c['runtime'];
    foreach((array)($c['compare_models']??[])as$k=>$m)if(strtolower((string)$k)===$key&&is_array($m))return(string)($m['runtime']??(__DIR__.'/'.$key.'_runtime'));
    return __DIR__.'/'.$key.'_runtime';
}
function te_scope_positions(array $c,array $currentPositions): array
{
    $out=[];$current=strtolower((string)($c['strategy_key']??''));foreach(te_strategy_scope_keys($c)as$key){$rows=$key===$current?$currentPositions:te_positions_normalize(te_load(te_strategy_runtime_for_key($c,$key).'/positions.json',[]));foreach($rows as$rk=>$row){if(!is_array($row))continue;$id=$key.'|'.(string)($row['market']??'').'|'.(string)($row['symbol']??$rk);$row['_strategy_scope_key']=$key;$out[$id]=$row;}}return$out;
}
function te_scope_trades(array $c,array $currentTrades): array
{
    $out=[];$current=strtolower((string)($c['strategy_key']??''));foreach(te_strategy_scope_keys($c)as$key){$runtime=te_strategy_runtime_for_key($c,$key);$rows=$key===$current?$currentTrades:te_load($runtime.'/trades.json',[]);if(!is_array($rows))continue;foreach($rows as$rk=>$row){if(!is_array($row))continue;$id=(string)($row['trade_id']??$row['id']??'');if($id==='')$id=$key.'|'.$rk.'|'.(string)($row['sell_time']??$row['buy_time']??'');$out[$id]=$row;}}return array_values($out);
}
function te_scope_strategy_allowed(array $c,string $strategy): bool{return in_array(strtolower($strategy),te_strategy_scope_keys($c),true);}
function te_scope_symbol_held(array $c,array $currentPositions,string $market,string $symbol): bool
{
    $market=strtoupper($market);$symbol=strtoupper($symbol);
    foreach(te_scope_positions($c,$currentPositions) as $p){
        if(!is_array($p)||!te_active_position($p))continue;
        if(strtoupper((string)($p['market']??''))===$market&&strtoupper((string)($p['symbol']??''))===$symbol)return true;
    }
    return false;
}
function te_actual_symbol_held(array $c,array $currentPositions,string $market,string $symbol): bool
{
    // INV-06: one actual position per symbol across the four active v1.4 strategies.
    $market=strtoupper($market);$symbol=strtoupper($symbol);$current=strtolower((string)($c['strategy_key']??''));
    foreach(['dts','abc','das','stc26'] as $key){
        $rows=$key===$current?$currentPositions:te_positions_normalize(te_load(te_strategy_runtime_for_key($c,$key).'/positions.json',[]));
        foreach($rows as $p){
            if(!is_array($p)||!te_active_position($p))continue;
            if(strtoupper((string)($p['market']??''))===$market&&strtoupper((string)($p['symbol']??''))===$symbol)return true;
        }
    }
    return false;
}

function te_pending_buy_count(array $c,array $brokerOrders,string $market): int
{
    $keys=[];$root=te_load($c['intents_file'],[]);$rows=is_array($root['intents']??null)?$root['intents']:[];
    foreach($rows as$i){if(!is_array($i)||!te_scope_strategy_allowed($c,(string)($i['strategy_key']??''))||strtoupper((string)($i['market']??''))!==$market||strtoupper((string)($i['side']??''))!=='BUY')continue;if(te_intent_row_active($i,$brokerOrders))$keys[$market.':'.strtoupper((string)($i['symbol']??''))]=true;}
    foreach($brokerOrders as$o){if(!is_array($o)||!te_scope_strategy_allowed($c,(string)($o['strategy_key']??''))||strtoupper((string)($o['market']??''))!==$market||strtoupper((string)($o['side']??''))!=='BUY'||!te_broker_order_active($o))continue;$keys[$market.':'.strtoupper((string)($o['symbol']??''))]=true;}
    return count($keys);
}

function te_available_slots(array $c,array $positions,array $brokerOrders,string $market): int
{
    $scopePositions=te_scope_positions($c,$positions);$held=0;foreach($scopePositions as$p)if(te_active_position($p)&&($p['market']??'')===$market)$held++;
    $pending=te_pending_buy_count($c,$brokerOrders,$market);
    $max=(int)($c['max_positions_by_market'][$market]??TE_DEFAULT_MAX_POSITIONS_PER_MARKET);
    return max(0,$max-$held-$pending);
}

function te_slot_summary(array $c,array $positions,array $brokerOrders): array
{
    $out=[];
    foreach(['KR','US','JP'] as $market){
        $scopePositions=te_scope_positions($c,$positions);$held=0;foreach($scopePositions as $p)if(te_active_position($p)&&strtoupper((string)($p['market']??''))===$market)$held++;
        $pending=te_pending_buy_count($c,$brokerOrders,$market);
        $max=(int)($c['max_positions_by_market'][$market]??TE_DEFAULT_MAX_POSITIONS_PER_MARKET);
        $used=$held+$pending;$out[$market]=['held'=>$held,'pending'=>$pending,'used'=>$used,'max'=>$max,'available'=>max(0,$max-$used),'over_limit'=>$used>$max,'excess'=>max(0,$used-$max)];
    }
    $out['total']=['used'=>array_sum(array_column($out,'used')),'max'=>(int)$c['max_positions_total'],'available'=>max(0,(int)$c['max_positions_total']-array_sum(array_column($out,'used')))];
    return $out;
}


function te_strategy_regime_entry_block_reason(array $c, string $market, array $regime): string
{
    // 3+1 v1.4 invariant: Engine must not add strategy-specific Alpha/regime rules.
    // Market-wide tradability/benchmark safety is handled by the common Engine barriers.
    return '';
}


function te_market_session_date_now(string $market): string
{
    return (new DateTime('now',new DateTimeZone(te_market_timezone($market))))->format('Y-m-d');
}
function te_row_market_date(string $value,string $market): string
{
    $value=trim($value);if($value==='')return'';
    $ts=strtotime($value);if($ts===false)return substr($value,0,10);
    return te_date_tz($ts,$market);
}
function te_daily_buy_count(array $c,array $brokerOrders,string $market): int
{
    $market=strtoupper($market);$session=te_market_session_date_now($market);$seen=[];
    $roots=[
        te_load($c['intents_file'],[]),
        te_load($c['intents_archive_file'],[]),
        ['intents'=>$brokerOrders],
    ];
    foreach($roots as$root){
        $rows=is_array($root['intents']??null)?$root['intents']:[];
        foreach($rows as$r){
            if(!is_array($r)||strtoupper((string)($r['market']??''))!==$market||strtoupper((string)($r['side']??''))!=='BUY')continue;
            $when=(string)($r['created_at']??$r['received_at']??$r['filled_at']??$r['updated_at']??'');
            if(te_row_market_date($when,$market)!==$session)continue;
            $id=(string)($r['order_id']??'');if($id==='')$id=$market.'|'.(string)($r['symbol']??'').'|'.$when;
            $seen[$id]=true;
        }
    }
    return count($seen);
}
function te_daily_opportunity_state(array $c): array
{
    $x=te_load((string)$c['daily_opportunity_state_file'],[]);
    return is_array($x)?array_replace(['schema'=>'daily_opportunity_v1','markets'=>[],'updated_at'=>''],$x):['schema'=>'daily_opportunity_v1','markets'=>[],'updated_at'=>''];
}
function te_daily_opportunity_attempts(array $c,string $market): int
{
    $s=te_daily_opportunity_state($c);$session=te_market_session_date_now($market);$m=is_array($s['markets'][$market]??null)?$s['markets'][$market]:[];
    return ((string)($m['session_date']??'')===$session)?(int)($m['attempts']??0):0;
}
function te_daily_opportunity_record(array $c,string $market,string $symbol,string $strategy,string $result,string $reason=''): void
{
    $s=te_daily_opportunity_state($c);$session=te_market_session_date_now($market);$m=is_array($s['markets'][$market]??null)?$s['markets'][$market]:[];
    $attempts=((string)($m['session_date']??'')===$session)?(int)($m['attempts']??0):0;
    $s['markets'][$market]=['session_date'=>$session,'attempts'=>$attempts+1,'last_symbol'=>$symbol,'last_strategy'=>strtoupper($strategy),'last_result'=>$result,'last_reason'=>$reason,'last_attempt_at'=>te_now()];
    $s['updated_at']=te_now();te_save((string)$c['daily_opportunity_state_file'],$s);
}
function te_daily_opportunity_pick(array $c,string $market,array $sourceRows,array $context=[]): array
{
    $p=is_array($c['daily_opportunity_policy']??null)?$c['daily_opportunity_policy']:[];
    if(empty($p['enabled'])||(int)($p['target_buys_per_market']??0)<1)return[];
    if(te_daily_buy_count($c,te_broker_orders($c),$market)>=(int)$p['target_buys_per_market'])return[];
    if(te_daily_opportunity_attempts($c,$market)>=(int)($p['max_soft_attempts_per_market']??1))return[];
    $cb=(string)($c['opportunity_callback']??'');if($cb===''||!function_exists($cb))return[];
    $best=[];
    foreach($sourceRows as$row){
        if(!is_array($row))continue;
        $candidate=$row;
        if(isset($row['metrics'])&&is_array($row['metrics']))$candidate=array_merge($row['metrics'],$row);
        $data=te_ranked_data_status($candidate);if($data!=='')continue;
        try{$o=$cb($candidate,array_merge($context,['market'=>$market,'policy'=>$p]));}catch(Throwable $e){te_log($c['error_file'],'OPPORTUNITY '.$market.' '.$e->getMessage());continue;}
        if(!is_array($o)||empty($o['eligible']))continue;
        $score=(float)($o['score']??0);
        if(!$best||$score>(float)($best['_opportunity_score']??-INF)){
            $best=$candidate;$best['_opportunity_score']=$score;$best['_opportunity']=$o;
        }
    }
    if(!$best)return[];
    $o=$best['_opportunity'];unset($best['_opportunity']);
    $best['score_pass']=true;$best['structural_eligible']=true;$best['rank_selected']=false;$best['order_eligible']=false;
    $best['daily_opportunity']=true;$best['ms7_stage']='M2';$best['allocation_scale']=(float)($p['allocation_scale']??0.25);
    $best['entry_type']=(string)($o['signal_type']??('DAILY_OPPORTUNITY_'.strtoupper((string)$c['strategy_key'])));
    $best['reason']=(string)($o['reason']??'일일 기회목표 최상위 후보 소액 진입');
    $best['rule_score']=(float)($o['score']??$best['rule_score']??$best['final_score']??0);
    $best['final_score']=$best['rule_score'];
    $best['reason_codes']=array_values(array_unique(array_merge(te_clean_reason_codes((array)($best['reason_codes']??[])),['DAILY_OPPORTUNITY_SOFT_TARGET','MS7_M2_SMALL_ENTRY'])));
    $best['metrics']=array_merge(is_array($best['metrics']??null)?$best['metrics']:[],is_array($o['metrics']??null)?$o['metrics']:[],[
        'daily_opportunity'=>true,'ms7_stage'=>'M2','allocation_scale'=>$best['allocation_scale'],'opportunity_score'=>$best['rule_score'],
        'opportunity_policy'=>'SOFT_TARGET_NOT_FORCED'
    ]);
    return$best;
}

function te_rank_market_and_queue(array $c,string $market,array $batch,array $candidateBook,array $positions,array $capital,array $states,array $brokerOrders,array $status,string $tick,array $universe,array $regimes,bool $sellBlocked,array $reservedByMarket): array
{
    $result=['candidate_book'=>$candidateBook,'states'=>$states,'reserved_by_market'=>$reservedByMarket,'capital'=>$capital,'rank_selected'=>0,'buy_intent'=>0,'route'=>'DIRECT'];$cycle=(string)($batch['cycle_id']??'');if($cycle==='')return$result;
    $rows=is_array($candidateBook['markets'][$market]['rows']??null)?$candidateBook['markets'][$market]['rows']:[];$items=[];foreach($universe as$item)if(is_array($item)&&($item['market']??'')===$market)$items[(string)($item['symbol']??'')]=$item;
    $slots=te_available_slots($c,$positions,$brokerOrders,$market);$entryOpen=te_market_flag($status,$market,'entry_open');$rankCb=$c['rank_callback'];$ranked=[];
    if($rankCb!==''&&function_exists($rankCb)){
        $result['route']='RANKED';$raw=[];foreach($rows as$row){if(!is_array($row)||(string)($row['scan_cycle_id']??'')!==$cycle)continue;$entry=is_array($row['metrics']??null)?$row['metrics']:[];if(!$entry)continue;$entry['market']=$market;$entry['symbol']=(string)($row['symbol']??$entry['symbol']??'');$entry['name']=(string)($row['name']??$entry['name']??'');$raw[]=$entry;}
        if(!$raw)return$result;$benchmark=te_market_bars($c,$market,$status);$rankContext=['market'=>$market,'full_scan_complete'=>true,'scan_cycle_id'=>$cycle,'available_slots'=>[$market=>$slots],'strategy_settings'=>$c['strategy_settings'],'limits'=>$c['strategy_settings'],'benchmark_bars'=>$benchmark,'market_bars'=>$benchmark,'data_status'=>'FINAL_CLOSE','engine_capabilities'=>$c['engine_capabilities'],'extension_config'=>$c['extension_config']];
        try{$ranked=$rankCb($rankContext,$raw);}catch(Throwable $e){te_log($c['error_file'],'RANK '.$market.' '.$e->getMessage());return$result;}if(!is_array($ranked))return$result;
        usort($ranked,static function($a,$b){$ra=$a['rank']??PHP_INT_MAX;$rb=$b['rank']??PHP_INT_MAX;if($ra===$rb)return strcmp((string)($a['symbol']??''),(string)($b['symbol']??''));return$ra<$rb?-1:1;});
        $rankValidation=te_validation_register_ranked_signals($c,$market,$ranked,$batch);
        foreach($ranked as&$r){
            if(!is_array($r))continue;$r['reason_codes']=te_clean_reason_codes(is_array($r['reason_codes']??null)?$r['reason_codes']:[]);$r['rank_selected']=false;$r['order_eligible']=false;$symbol=(string)($r['symbol']??'');if($symbol==='')continue;if(isset($rankValidation[$symbol])){$r['validation_id']=(string)$rankValidation[$symbol];$r['signal_id']=(string)$rankValidation[$symbol];} 
            $struct=!empty($r['structural_eligible']);$pass=!empty($r['score_pass']);if($struct&&$pass&&empty($r['signal_id'])){$r['score_pass']=false;$r['reason_codes'][]='AUDIT_WRITE_FAILED';$pass=false;}$dataStatus=te_ranked_data_status($r);$label=te_ranked_display_status($r);$reason=$r['reason_codes']?implode(', ',$r['reason_codes']):'전략 순위 계산';$block=$dataStatus!==''?$reason:($pass?'':($struct?'RULE_SCORE_LOW':$reason));
            $candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>$label,'type'=>(string)($r['entry_type']??''),'score'=>(int)round((float)($r['final_score']??$r['rule_score']??0)),'reason'=>$reason,'block_reason'=>$block,'rank'=>$r['rank']??null,'rank_selected'=>false,'order_eligible'=>false,'reason_codes'=>$r['reason_codes'],'signal_id'=>(string)($r['signal_id']??$r['validation_id']??''),'metrics'=>$r,'ranked_at'=>te_now()]);
        }unset($r);
    }else{
        foreach($rows as$row){if(!is_array($row)||(string)($row['scan_cycle_id']??'')!==$cycle||strtoupper((string)($row['model_status']??$row['status']??''))!=='BUY')continue;$ranked[]=['market'=>$market,'symbol'=>(string)($row['symbol']??''),'name'=>(string)($row['name']??''),'rule_score'=>(float)($row['score']??0),'entry_price'=>(float)($row['entry_price']??0),'stop_reference'=>(float)($row['stop_price']??0),'target_price'=>(float)($row['target_price']??0),'entry_type'=>(string)($row['type']??''),'reason'=>(string)($row['reason']??''),'time'=>(string)($row['time']??''),'metrics'=>is_array($row['metrics']??null)?$row['metrics']:[],'validation_id'=>(string)($row['metrics']['validation_id']??''),'signal_id'=>(string)($row['metrics']['signal_id']??$row['metrics']['validation_id']??''),'_entry_pre_state'=>is_array($row['_entry_pre_state']??null)?$row['_entry_pre_state']:[],'score_pass'=>true,'structural_eligible'=>true];}
        usort($ranked,static function($a,$b){$sa=(float)($a['rule_score']??0);$sb=(float)($b['rule_score']??0);if($sa!==$sb)return$sa>$sb?-1:1;$t=strcmp((string)($a['time']??''),(string)($b['time']??''));return$t!==0?$t:strcmp((string)($a['symbol']??''),(string)($b['symbol']??''));});$n=0;foreach($ranked as&$r){$r['rank']=++$n;$symbol=(string)$r['symbol'];$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'CANDIDATE','rank'=>$n,'rank_selected'=>false,'order_eligible'=>false,'block_reason'=>'']);}unset($r);
    }
    $strategyStatus=tv_strategy_status($c);
    $normalEligible=false;foreach($ranked as$rr)if(is_array($rr)&&!empty($rr['score_pass'])&&!empty($rr['structural_eligible'])&&te_ranked_data_status($rr)===''){$normalEligible=true;break;}
    if(!$normalEligible){
        $opp=te_daily_opportunity_pick($c,$market,$result['route']==='RANKED'?$ranked:array_values($rows),['scan_cycle_id'=>$cycle,'route'=>$result['route']]);
        if($opp){
            $symbol=(string)($opp['symbol']??'');
            if($symbol!==''){
                if($result['route']==='DIRECT'){
                    $row=is_array($rows[$symbol]??null)?$rows[$symbol]:[];
                    $opp['market']=$market;$opp['symbol']=$symbol;$opp['name']=(string)($opp['name']??$row['name']??'');
                    $opp['entry_price']=(float)($opp['entry_price']??$row['entry_price']??$row['price']??0);
                    $opp['stop_reference']=0.0;$opp['target_price']=0.0;
                    $opp['_entry_pre_state']=is_array($row['_entry_pre_state']??null)?$row['_entry_pre_state']:[];
                    $ranked[]=$opp;
                }else{
                    foreach($ranked as&$rr)if(is_array($rr)&&(string)($rr['symbol']??'')===$symbol){$rr=array_merge($rr,$opp);break;}unset($rr);
                }
                $candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'CANDIDATE','type'=>(string)$opp['entry_type'],'score'=>(int)round((float)$opp['rule_score']),'reason'=>(string)$opp['reason'],'block_reason'=>'','rank_selected'=>false,'order_eligible'=>false,'reason_codes'=>$opp['reason_codes'],'metrics'=>array_merge(is_array($opp['metrics']??null)?$opp['metrics']:[],$opp)]);
                te_daily_opportunity_record($c,$market,$symbol,(string)$c['strategy_key'],'SELECTED','M2_SMALL_ENTRY_CANDIDATE');
            }
        }
    }
    $benchmarkRow=is_array($regimes['markets'][$market]??null)?$regimes['markets'][$market]:[];$benchmarkBlocked=!empty($benchmarkRow['entry_blocked']);$regimeBlock=te_strategy_regime_entry_block_reason($c,$market,$benchmarkRow);$riskBlock=te_entry_risk_block_reason($c,$market,$capital);if($strategyStatus==='CHALLENGER'){
        // Challenger is a shadow PAPER slot: select and evaluate signals, but do not consume CORE capital or create Broker BUY intents.
        foreach($ranked as$rr){
            if(!is_array($rr)||empty($rr['score_pass'])||empty($rr['structural_eligible'])||te_ranked_data_status($rr)!=='')continue;
            $ss=(string)($rr['symbol']??'');$sid=(string)($rr['signal_id']??$rr['validation_id']??'');if($ss==='')continue;
            $candidateBook=te_candidate_book_patch($candidateBook,$market,$ss,['status'=>'SHADOW_PAPER','block_reason'=>'','rank_selected'=>true,'order_eligible'=>false,'shadow_only'=>true]);
            if($sid!==''&&function_exists('tv_patch_pipeline'))tv_patch_pipeline($c,$sid,'ENGINE_SELECTED',['selected'=>true,'shadow_only'=>true,'block_reason'=>''],['market'=>$market,'symbol'=>$ss,'shadow_only'=>true]);
            $result['rank_selected']++;
        }
        if($result['route']==='DIRECT')$states=te_release_unbacked_pending_states($c,$states,$candidateBook,$market,$ranked,$brokerOrders,'CHALLENGER_SHADOW_ONLY');
        $result['candidate_book']=$candidateBook;$result['states']=$states;return$result;
    }
    if($sellBlocked||$slots<1||!$entryOpen||$benchmarkBlocked||$regimeBlock!==''||$riskBlock!==''){
        $barrier=$sellBlocked?'SELL_FIRST_BARRIER':($riskBlock!==''?$riskBlock:($slots<1?'NO_AVAILABLE_SLOT':($benchmarkBlocked?'BENCHMARK_LAG_SAFE':($regimeBlock!==''?$regimeBlock:'ENTRY_WINDOW_CLOSED'))));
        foreach($ranked as$rr){if(!is_array($rr)||empty($rr['score_pass'])||empty($rr['structural_eligible'])||te_ranked_data_status($rr)!=='')continue;$ss=(string)($rr['symbol']??'');if($ss!=='')$candidateBook=te_candidate_book_patch($candidateBook,$market,$ss,['status'=>'WATCH','block_reason'=>$barrier]);}
        if($result['route']==='DIRECT')$states=te_release_unbacked_pending_states($c,$states,$candidateBook,$market,$ranked,$brokerOrders,$barrier);$result['candidate_book']=$candidateBook;$result['states']=$states;return$result;
    }
    $approved=0;foreach($ranked as$r){if($approved>=$slots)break;if(empty($r['score_pass'])||empty($r['structural_eligible'])||te_ranked_data_status($r)!=='')continue;$symbol=(string)($r['symbol']??'');$key=$market.':'.$symbol;if($symbol===''||!isset($items[$symbol]))continue;
        if(te_actual_symbol_held($c,$positions,$market,$symbol)){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>'DUPLICATE_SYMBOL']);continue;}
        $preCooldown=te_entry_cooldown_guard($c,$market,$symbol,'BUY','',is_array($states[$key]??null)?$states[$key]:[]);if(empty($preCooldown['ok'])){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>$preCooldown['reason'],'cooldown_until'=>$preCooldown['until']??'']);continue;}
        if(te_has_active_order($c,$brokerOrders,$key,'BUY')){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'BUY_PENDING','block_reason'=>'ORDER_PENDING']);continue;}
        if(!te_reconciliation_ok($c,$positions,$market,$symbol)){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>'RECONCILIATION_DEFICIT']);continue;}
        $item=$items[$symbol];$correlationGuard=te_correlation_entry_guard($c,$positions,$brokerOrders,$item);if(empty($correlationGuard['ok'])){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>(string)$correlationGuard['reason'],'correlation_group'=>(string)($correlationGuard['correlation_group']??'')]);continue;}$q=te_quote($c,$item,$status);if(empty($q['ok'])){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'DATA_ERROR','block_reason'=>'QUOTE_ERROR']);continue;}$signalPrice=(float)($r['entry_price']??0);$current=(float)($q['price']??0);$maxGap=(float)($c['order_gap_pct_by_market'][$market]??0);$revalue=$signalPrice>0?abs($current/$signalPrice-1.0)*100.0:999.0;
        if($signalPrice<=0||$current<=0||$maxGap<=0||$revalue>$maxGap){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>'PRICE_REVALIDATION_FAIL','price_revalidation_pct'=>round($revalue,3)]);continue;}
        $ctx=te_context($c,$item,$q,$status,$tick);if(empty($ctx['data_ok'])){$dataCode=(string)($ctx['data_code']??'DATA_ERROR');$display=te_candidate_display_status('FILTERED',$dataCode);if(!te_is_hard_data_status($display))$display='DATA_ERROR';$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>$display,'block_reason'=>(string)($ctx['data_reason']??'DATA_REVALIDATION_FAIL')]);continue;}if(te_price_scale_suspect($c,$ctx,$signalPrice,$current)){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'DATA_ERROR','block_reason'=>'PRICE_SCALE_SUSPECT']);continue;}
        $queuePreState=is_array($states[$key]??null)?$states[$key]:[];
        if($result['route']==='RANKED'){
            $forOrder=$r;$forOrder['rank_selected']=true;$forOrder['order_eligible']=true;if(!empty($r['daily_opportunity'])){$forOrder['daily_opportunity']=true;$forOrder['ms7_stage']='M2';$forOrder['allocation_scale']=(float)($r['allocation_scale']??0.25);}$forOrder['reason_codes']=array_values(array_unique(array_merge(te_clean_reason_codes(is_array($r['reason_codes']??null)?$r['reason_codes']:[]),['TOP_RANK'])));$ctx['ranked_result']=$forOrder;
            $model=$c['model_callback'];try{$sig=$model($ctx,$queuePreState);}catch(Throwable $e){te_log($c['error_file'],'QUEUE_BUY '.$key.' '.$e->getMessage());continue;}if(!is_array($sig)){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>'MODEL_REVALIDATION_FAIL']);continue;}$validated=te_validate_signal($sig,$c);if(empty($validated['ok'])||$validated['signal']['status']!=='BUY'){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>'MODEL_REVALIDATION_FAIL']);continue;}$sig=$validated['signal'];
        }else{
            if(is_array($r['_entry_pre_state']??null))$queuePreState=$r['_entry_pre_state'];
            // 직접 판정형 모델은 전체 스캔에서 이미 확정한 BUY 결과를 사용한다.
            // 상태형 모델(STC26 등)을 두 번 호출하면 첫 BUY에서 변경된 상태 때문에 신호가 사라질 수 있으므로 재호출하지 않는다.
            $sig=['status'=>'BUY','type'=>(string)($r['entry_type']??''),'score'=>(float)($r['rule_score']??0),'entry_price'=>(float)($r['entry_price']??0),'stop_price'=>(float)($r['stop_reference']??0),'target_price'=>(float)($r['target_price']??0),'reason'=>(string)($r['reason']??''),'metrics'=>is_array($r['metrics']??null)?$r['metrics']:[]];
        }
        $guard=te_entry_guard($c,$ctx,$sig,$r);if(empty($guard['ok'])){$guardReason=(string)($guard['reason']??'ENTRY_GUARD_REJECTED');if(!empty($r['daily_opportunity']))te_daily_opportunity_record($c,$market,$symbol,(string)$c['strategy_key'],'BLOCKED',$guardReason);$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>$guardReason]);continue;}$sig=$guard['signal'];$validated=te_validate_signal($sig,$c);if(empty($validated['ok'])||$validated['signal']['status']!=='BUY'){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>'ENTRY_GUARD_SIGNAL_INVALID']);continue;}$sig=$validated['signal'];
        $rebased=te_rebase_order_signal($c,$sig,$current,$signalPrice,$revalue);if(empty($rebased['ok'])){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>(string)$rebased['reason']]);continue;}$sig=$rebased['signal'];
        $auditMetrics=is_array($sig['metrics']??null)?$sig['metrics']:[];$scenarioRaw=(string)($auditMetrics['scenario_id']??'');$scenarioKey=te_scenario_key($c,$market,$symbol,(string)($sig['type']??''),(string)($ctx['market_date']??te_context_data_date($ctx,$market,(string)($c['data_date_timeframe']??''))),$scenarioRaw);$cooldown=te_entry_cooldown_guard($c,$market,$symbol,'BUY',$scenarioKey,is_array($states[$key]??null)?$states[$key]:[]);if(empty($cooldown['ok'])){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>$cooldown['reason'],'cooldown_until'=>$cooldown['until']??'']);continue;}$sig['metrics']['scenario_key']=$scenarioKey;$ctx['ranked_result']=array_merge(is_array($ctx['ranked_result']??null)?$ctx['ranked_result']:[],['rule_score'=>(float)($sig['score']??0),'final_score'=>(float)($sig['score']??0),'audit'=>['support'=>is_array($auditMetrics['support']??null)?$auditMetrics['support']:[],'trigger'=>is_array($auditMetrics['trigger']??null)?$auditMetrics['trigger']:[],'phase'=>(string)($auditMetrics['phase']??'')]]);$size=te_size($c,$capital,$positions,$sig,$market,(int)($reservedByMarket[$market]??0),array_sum($reservedByMarket));if($size['qty']<1){$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'WATCH','block_reason'=>'POSITION_SIZE_ZERO: '.$size['reason']]);continue;}
        $ctx['validation_id']=(string)($r['validation_id']??$sig['metrics']['validation_id']??'');$ctx['signal_id']=(string)($r['signal_id']??$sig['metrics']['signal_id']??$ctx['validation_id']);$intent=te_make_intent($c,$ctx,'BUY',$size['qty'],(float)$sig['entry_price'],(float)$sig['stop_price'],(float)$sig['target_price'],(string)($sig['type']??''),(string)($sig['reason']??''),(string)($sig['metrics']['scenario_id']??''));
        if(te_append_intent($c,$intent)){$approved++;$result['buy_intent']++;$result['rank_selected']++;$reservedByMarket[$market]=(int)($reservedByMarket[$market]??0)+1;$capital[$market]['cash']=max(0.0,(float)($capital[$market]['cash']??0)-(float)$intent['amount']-te_buy_fee($market,(float)$intent['amount']));$states[$key]['phase']='ENTRY_PENDING';$states[$key]['pending_order_id']=$intent['order_id'];$codes=$result['route']==='RANKED'?array_values(array_unique(array_merge(te_clean_reason_codes(is_array($r['reason_codes']??null)?$r['reason_codes']:[]),['TOP_RANK']))):[];$candidateBook=te_candidate_book_patch($candidateBook,$market,$symbol,['status'=>'BUY_PENDING','block_reason'=>'','order_id'=>$intent['order_id'],'order_qty'=>$size['qty'],'order_price'=>(float)$intent['price'],'order_stop_price'=>(float)$intent['stop_price'],'order_target_price'=>(float)$intent['target_price'],'price_revalidation_pct'=>round($revalue,3),'rank_selected'=>true,'order_eligible'=>true,'reason_codes'=>$codes]);}
    }
    if($result['route']==='DIRECT')$states=te_release_unbacked_pending_states($c,$states,$candidateBook,$market,$ranked,$brokerOrders,'ORDER_NOT_CREATED');
    $result['candidate_book']=$candidateBook;$result['states']=$states;$result['reserved_by_market']=$reservedByMarket;$result['capital']=$capital;return$result;
}

function te_release_unbacked_pending_states(array $c,array $states,array $candidateBook,string $market,array $ranked,array $brokerOrders,string $fallbackReason): array
{
    foreach($ranked as$r){if(!is_array($r))continue;$symbol=(string)($r['symbol']??'');if($symbol==='')continue;$key=$market.':'.$symbol;if(!isset($states[$key])||!is_array($states[$key])||($states[$key]['phase']??'')!=='ENTRY_PENDING')continue;$pending=(string)($states[$key]['pending_order_id']??'');if($pending!==''||te_has_active_order($c,$brokerOrders,$key,'BUY'))continue;$row=is_array($candidateBook['markets'][$market]['rows'][$symbol]??null)?$candidateBook['markets'][$market]['rows'][$symbol]:[];if(strtoupper((string)($row['status']??''))==='BUY_PENDING'||!empty($row['order_id']))continue;$reason=(string)($row['block_reason']??'');$states[$key]['phase']='WAIT_SIGNAL';$states[$key]['last_reason']=$reason!==''?$reason:$fallbackReason;$states[$key]['updated_at']=te_now();}
    return$states;
}

function te_candidate_cycle_info(array $book,string $market): array
{
    $m=is_array($book['markets'][$market]??null)?$book['markets'][$market]:[];$rows=is_array($m['rows']??null)?$m['rows']:[];$cycle='';
    foreach($rows as$r){if(is_array($r)&&($r['scan_cycle_id']??'')!==''){$cycle=(string)$r['scan_cycle_id'];break;}}
    return['cycle_id'=>$cycle,'data_date'=>(string)($m['data_date']??''),'basis'=>(string)($m['basis']??''),'count'=>count($rows)];
}
function te_snapshot_admission_fresh(string $dataDate,int $maxDays): bool
{
    $ts=strtotime($dataDate.' 00:00:00');return$ts!==false&&(time()-$ts)<=($maxDays*86400+43200);
}
function te_deferred_retry_allowed(array $row,string $attemptKey,int $now,int $retrySec,int $maxAttempts): bool
{
    if((string)($row['attempt_key']??'')!==$attemptKey)return true;
    if((int)($row['attempts']??1)>=$maxAttempts)return false;
    $last=strtotime((string)($row['last_attempt_at']??$row['attempted_at']??''));
    return $last===false||($now-$last)>=$retrySec;
}
function te_deferred_admission_safe(array $c,string $market,array $candidateBook,array $info): bool
{
    $marketRow=is_array($candidateBook['markets'][$market]??null)?$candidateBook['markets'][$market]:[];
    if(strtoupper((string)($info['basis']??''))!=='FINAL_CLOSE')return false;
    $completed=is_array($marketRow['last_completed_scan']??null)?$marketRow['last_completed_scan']:[];
    if((string)($completed['cycle_id']??'')===''||(string)($completed['cycle_id']??'')!==(string)($info['cycle_id']??''))return false;
    if((string)($completed['data_date']??'')===''||(string)($completed['data_date']??'')!==(string)($info['data_date']??''))return false;
    $progress=te_load((string)($c['scan_progress_file']??''),[]);if(!is_array($progress)||empty($progress['status']))return false;
    $overall=strtoupper((string)$progress['status']);if(!in_array($overall,['DONE','CLOSE_HOLD'],true))return false;
    $marketProgress=is_array($progress['markets'][$market]??null)?$progress['markets'][$market]:[];$marketStatus=strtoupper((string)($marketProgress['status']??''));
    if(!in_array($marketStatus,['DONE','CLOSE_HOLD'],true))return false;
    return true;
}
function te_deferred_admission_attempt(array $c,string $market,array $candidateBook,array $positions,array $capital,array $states,array $brokerOrders,array $status,string $tick,array $universe,array $regimes,bool $sellBlocked,array $reservedByMarket): array
{
    $base=['attempted'=>false,'candidate_book'=>$candidateBook,'states'=>$states,'capital'=>$capital,'reserved_by_market'=>$reservedByMarket,'rank_selected'=>0,'buy_intent'=>0,'route'=>$c['rank_callback']!==''?'RANKED':'DIRECT'];
    $entryOpen=te_market_flag($status,$market,'entry_open');if(!$entryOpen||$sellBlocked)return$base;
    if(te_entry_risk_block_reason($c,$market,$capital)!=='')return$base;
    if(te_available_slots($c,$positions,$brokerOrders,$market)<1)return$base;
    $info=te_candidate_cycle_info($candidateBook,$market);if($info['cycle_id']===''||$info['data_date']===''||$info['count']<1||!te_snapshot_admission_fresh($info['data_date'],(int)$c['deferred_admission_max_age_days'])||!te_deferred_admission_safe($c,$market,$candidateBook,$info))return$base;
    if(!empty($c['deferred_admission_require_current_data'])){$expected=te_expected_completed_date($market,$status,$c);if($expected!==''&&$info['data_date']<$expected)return$base;}
    $session=te_session_date($market);$key=$session.'|'.$info['data_date'].'|'.$info['cycle_id'];$state=te_load($c['admission_state_file'],[]);$prev=is_array($state['markets'][$market]??null)?$state['markets'][$market]:[];
    if(!te_deferred_retry_allowed($prev,$key,time(),(int)$c['deferred_admission_retry_sec'],(int)$c['deferred_admission_max_attempts']))return$base;
    $batch=['cycle_id'=>$info['cycle_id'],'data_date'=>$info['data_date'],'wrapped'=>true];
    $q=te_rank_market_and_queue($c,$market,$batch,$candidateBook,$positions,$capital,$states,$brokerOrders,$status,$tick,$universe,$regimes,$sellBlocked,$reservedByMarket);
    $same=(string)($prev['attempt_key']??'')===$key;$attempts=$same?((int)($prev['attempts']??1)+1):1;
    $state['schema']='admission_state_v2';$state['updated_at']=te_now();$state['markets'][$market]=['attempt_key'=>$key,'session_date'=>$session,'data_date'=>$info['data_date'],'cycle_id'=>$info['cycle_id'],'attempts'=>$attempts,'max_attempts'=>(int)$c['deferred_admission_max_attempts'],'last_attempt_at'=>te_now(),'buy_intent'=>(int)($q['buy_intent']??0),'rank_selected'=>(int)($q['rank_selected']??0)];te_save($c['admission_state_file'],$state);
    return array_merge($base,$q,['attempted'=>true]);
}

function te_capital(array $c,array $positions,array $trades,array $orders=[]): array
{
    $positions=te_scope_positions($c,$positions);$trades=te_scope_trades($c,$trades);$snap=te_load($c['broker_account_file'],[]);$fresh=is_array($snap)&&isset($snap['updated_at'])&&strtotime((string)$snap['updated_at'])!==false&&(time()-strtotime((string)$snap['updated_at'])<=600);$reserved=te_pending_buy_amounts($c,$orders);$out=[];
    foreach(['KR','US','JP'] as $m){
        $seed=$m==='KR'?$c['seed_kr']:($m==='JP'?$c['seed_jp']:$c['seed_us']);$realized=0.0;$cost=0.0;$value=0.0;$openProfit=0.0;$count=0;
        foreach($trades as $t)if(is_array($t)&&($t['market']??'')===$m)$realized+=(float)($t['profit']??0);
        foreach($positions as $p)if(is_array($p)&&te_active_position($p)&&($p['market']??'')===$m){$count++;$qty=(int)$p['qty'];$entry=(float)$p['entry_price'];$cur=(float)($p['current_price']??$entry);$positionCost=(float)($p['buy_total']??($qty*$entry));$net=($qty*$cur)-te_sell_fee($m,$qty*$cur);$cost+=$positionCost;$value+=$net;$openProfit+=$net-$positionCost;}
        $reservedBuy=(float)($reserved[$m]??0);$grossCash=$seed+$realized-$cost;$cash=max(0.0,$grossCash-$reservedBuy);$equity=$grossCash+$value;$source=tv_strategy_status($c)==='CORE'?'CORE_SHARED_ENGINE_LEDGER':(tv_strategy_status($c)==='CHALLENGER'?'CHALLENGER_SHADOW_LEDGER':'ENGINE_LEDGER');$accountCash=null;$accountEquity=null;
        if($fresh&&isset($snap['markets'][$m])&&is_array($snap['markets'][$m])){$ss=$snap['markets'][$m];if(isset($ss['cash']))$accountCash=(float)$ss['cash'];if(isset($ss['equity']))$accountEquity=(float)$ss['equity'];}
        $profit=$equity-$seed;$out[$m]=['source'=>$source,'seed'=>$seed,'cash'=>round($cash,4),'gross_cash'=>round($grossCash,4),'reserved_buy'=>round($reservedBuy,4),'position_value'=>round($value,4),'equity'=>round($equity,4),'profit'=>round($profit,4),'profit_pct'=>$seed>0?round($profit/$seed*100,3):0.0,'realized'=>round($realized,4),'open_profit'=>round($openProfit,4),'open_count'=>$count,'account_cash'=>$accountCash,'account_equity'=>$accountEquity];
    }
    $out['updated_at']=te_now();return$out;
}

function te_stats(array $positions,array $trades,array $orders): array
{
    $wins=0;$losses=0;$returns=[];$byMarket=[];$cohorts=[];
    foreach(['KR','US','JP']as$m)$byMarket[$m]=['currency'=>$m==='KR'?'KRW':($m==='JP'?'JPY':'USD'),'closed'=>0,'wins'=>0,'losses'=>0,'gross_profit'=>0.0,'gross_loss'=>0.0,'realized_profit'=>0.0,'return_pct_sum'=>0.0];
    foreach($trades as$t){
        if(!is_array($t))continue;$m=strtoupper((string)($t['market']??''));if(!isset($byMarket[$m]))continue;$p=(float)($t['profit']??0);$r=(float)($t['return_pct']??0);$byMarket[$m]['closed']++;$byMarket[$m]['realized_profit']+=$p;$byMarket[$m]['return_pct_sum']+=$r;$returns[]=$r;
        if($p>0){$wins++;$byMarket[$m]['wins']++;$byMarket[$m]['gross_profit']+=$p;}elseif($p<0){$losses++;$byMarket[$m]['losses']++;$byMarket[$m]['gross_loss']+=abs($p);}
        $cohort=(string)($t['position_cohort']??'CURRENT');if(!isset($cohorts[$cohort]))$cohorts[$cohort]=['open'=>0,'closed'=>0,'wins'=>0,'losses'=>0,'realized_profit_by_market'=>['KR'=>0.0,'US'=>0.0,'JP'=>0.0]];$cohorts[$cohort]['closed']++;$cohorts[$cohort]['realized_profit_by_market'][$m]+=$p;if($p>0)$cohorts[$cohort]['wins']++;elseif($p<0)$cohorts[$cohort]['losses']++;
    }
    foreach($positions as$p){if(!te_active_position($p))continue;$cohort=(string)($p['position_cohort']??'CURRENT');if(!isset($cohorts[$cohort]))$cohorts[$cohort]=['open'=>0,'closed'=>0,'wins'=>0,'losses'=>0,'realized_profit_by_market'=>['KR'=>0.0,'US'=>0.0,'JP'=>0.0]];$cohorts[$cohort]['open']++;}
    foreach($cohorts as$k=>$row)foreach(['KR','US','JP']as$m)$cohorts[$k]['realized_profit_by_market'][$m]=round((float)$row['realized_profit_by_market'][$m],4);
    foreach($byMarket as$m=>$row){$closed=(int)$row['closed'];$byMarket[$m]['realized_profit']=round((float)$row['realized_profit'],4);$byMarket[$m]['gross_profit']=round((float)$row['gross_profit'],4);$byMarket[$m]['gross_loss']=round((float)$row['gross_loss'],4);$byMarket[$m]['win_rate']=$closed>0?round((int)$row['wins']/$closed*100,2):0.0;$byMarket[$m]['avg_return_pct']=$closed>0?round((float)$row['return_pct_sum']/$closed,4):0.0;$byMarket[$m]['profit_factor']=$row['gross_loss']>0?round((float)$row['gross_profit']/(float)$row['gross_loss'],4):($row['gross_profit']>0?null:0.0);unset($byMarket[$m]['return_pct_sum']);}
    $closed=$wins+$losses;$pending=0;$sent=0;$partial=0;$filled=0;$rejected=0;$expired=0;
    foreach($orders as$o){if(!is_array($o))continue;switch(strtoupper((string)($o['status']??''))){case'PENDING':case'APPROVED':$pending++;break;case'SENT':case'WORKING':$sent++;break;case'PARTIAL':$partial++;break;case'FILLED':case'PAPER_FILLED':case'DONE':$filled++;break;case'REJECTED':$rejected++;break;case'EXPIRED':$expired++;}}
    return['updated_at'=>te_now(),'open_positions'=>count(array_filter($positions,'te_active_position')),'closed_trades'=>count($trades),'wins'=>$wins,'losses'=>$losses,'win_rate'=>$closed>0?round($wins/$closed*100,2):0.0,'avg_return_pct'=>$returns?round(array_sum($returns)/count($returns),4):0.0,'realized_profit_by_market'=>['KR'=>$byMarket['KR']['realized_profit'],'US'=>$byMarket['US']['realized_profit'],'JP'=>$byMarket['JP']['realized_profit']],'by_market'=>$byMarket,'cohorts'=>$cohorts,'orders'=>['pending'=>$pending,'sent'=>$sent,'partial'=>$partial,'filled'=>$filled,'rejected'=>$rejected,'expired'=>$expired]];
}

function te_strategy_orders(array $c,array $rows): array{$out=[];foreach($rows as$r)if(is_array($r)&&strtolower((string)($r['strategy_key']??''))===$c['strategy_key'])$out[]=$r;return$out;}
function te_broker_orders(array $c): array{$r=te_load($c['broker_orders_file'],[]);return is_array($r['orders']??null)?$r['orders']:[];}
function te_broker_summary(array $rows): array{$m=[];foreach($rows as $r)if(is_array($r)){$s=strtoupper((string)($r['status']??'UNKNOWN'));$m[$s]=($m[$s]??0)+1;}return$m;}
function te_positions_normalize($rows): array
{
    if(!is_array($rows))return[];$out=[];
    foreach($rows as$key=>$p){if(!is_array($p))continue;$market=strtoupper((string)($p['market']??$p['nation']??''));$symbol=(string)($p['symbol']??$p['code']??'');$qty=(int)($p['qty']??$p['shares']??$p['quantity']??0);$entry=(float)($p['entry_price']??$p['buy_price']??$p['avg_price']??0);
        if(!in_array($market,['KR','US','JP'],true)||$symbol===''||$qty<1||$entry<=0)continue;$k=$market.':'.$symbol;$p['market']=$market;$p['symbol']=$symbol;$p['qty']=$qty;$p['entry_price']=$entry;$p['buy_total']=(float)($p['buy_total']??$p['cost']??($entry*$qty));$p['current_price']=(float)($p['current_price']??$p['price']??$entry);$p['peak_price']=max((float)($p['peak_price']??0),(float)$p['current_price'],$entry);$p['risk_group']=(string)($p['risk_group']??te_infer_risk_group($market,$symbol,(string)($p['name']??$symbol),(string)($p['asset_type']??''),!empty($p['is_inverse']),!empty($p['is_leveraged'])));$p['correlation_group']=(string)($p['correlation_group']??te_correlation_group((string)$p['risk_group'],$market,$symbol,(string)($p['name']??$symbol),(string)($p['asset_type']??''),!empty($p['is_inverse']),!empty($p['is_leveraged'])));$p['status']='OPEN';$out[$k]=$p;
    }return$out;
}
function te_active_position($p): bool{return is_array($p)&&(int)($p['qty']??0)>0&&strtoupper((string)($p['status']??'OPEN'))==='OPEN';}

function te_trade_list_load(array $c): array
{
    $file=(string)($c['trade_list_file']??(__DIR__.'/trade_list.php'));
    $empty=['ok'=>false,'file'=>$file,'error'=>'TRADE_LIST_MISSING','meta'=>[],'kr'=>[],'us'=>[],'jp'=>[],'errors'=>[]];
    if(!is_file($file))return$empty;
    $data=include $file;
    if(!is_array($data))return array_merge($empty,['error'=>'TRADE_LIST_INVALID']);
    $meta=is_array($data['meta']??null)?$data['meta']:[];$errors=[];$groups=[];
    foreach(['kr'=>'KR','us'=>'US','jp'=>'JP']as$key=>$market){
        $rows=is_array($data[$key]??null)?array_values($data[$key]):[];$groups[$key]=$rows;$seen=[];
        if(!$rows){$errors[]=$market.'_EMPTY';continue;}
        foreach($rows as$idx=>$row){
            if(!is_array($row)){$errors[]=$market.'_ROW_INVALID_'.$idx;continue;}
            $symbol=strtoupper(trim((string)($row['symbol']??$row['code']??'')));
            if($market==='KR'&&!preg_match('/^\d{6}$/',$symbol))$errors[]=$market.'_SYMBOL_'.$idx;
            if($market==='US'&&!preg_match('/^[A-Z0-9.\-^]{1,15}$/',$symbol))$errors[]=$market.'_SYMBOL_'.$idx;
            if($market==='JP'&&!preg_match('/^[0-9A-Z]{4,6}$/',$symbol))$errors[]=$market.'_SYMBOL_'.$idx;
            if($symbol!==''&&isset($seen[$symbol]))$errors[]=$market.'_DUP_'.$symbol;$seen[$symbol]=true;
            if(strtoupper(trim((string)($row['code']??'')))!==$symbol)$errors[]=$market.'_CODE_'.$idx;
            if(strtoupper(trim((string)($row['market']??'')))!==$market)$errors[]=$market.'_MARKET_'.$idx;
            $expectedCurrency=$market==='KR'?'KRW':($market==='JP'?'JPY':'USD');
            if(strtoupper(trim((string)($row['currency']??'')))!==$expectedCurrency)$errors[]=$market.'_CURRENCY_'.$idx;
            if(!in_array(strtoupper(trim((string)($row['asset_type']??''))),['STOCK','ETF'],true))$errors[]=$market.'_ASSET_TYPE_'.$idx;
            if(trim((string)($row['name']??''))==='')$errors[]=$market.'_NAME_'.$idx;
            if(!is_bool($row['enabled']??null))$errors[]=$market.'_ENABLED_'.$idx;
            $exchange=strtoupper((string)($row['exchange']??''));
            if($market==='KR'&&!in_array($exchange,['KOSPI','KOSDAQ','ETF'],true))$errors[]=$market.'_EXCHANGE_'.$idx;
            if($market==='US'&&te_us_exchange($exchange)==='')$errors[]=$market.'_EXCHANGE_'.$idx;
            if($market==='JP'&&te_jp_exchange($exchange)==='')$errors[]=$market.'_EXCHANGE_'.$idx;
        }
        $declared=(int)($meta[$key.'_count']??-1);if($declared!==count($rows))$errors[]=$market.'_COUNT_MISMATCH';
    }
    if($errors)return['ok'=>false,'file'=>$file,'error'=>'TRADE_LIST_VALIDATION_FAILED','meta'=>$meta,'kr'=>$groups['kr'],'us'=>$groups['us'],'jp'=>$groups['jp'],'errors'=>array_values(array_unique($errors))];
    return['ok'=>true,'file'=>$file,'error'=>'','meta'=>$meta,'kr'=>$groups['kr'],'us'=>$groups['us'],'jp'=>$groups['jp'],'errors'=>[]];
}

function te_universe(array $c): array
{
    $root=te_trade_list_load($c);if(empty($root['ok'])){te_log($c['error_file'],'TRADE_LIST '.$root['error'].' '.$root['file']);return[];}
    $out=[];$seen=[];$groups=['KR'=>$root['kr'],'US'=>$root['us'],'JP'=>$root['jp']];
    foreach($groups as$m=>$rows){foreach($rows as$r){
        if(!is_array($r)||(array_key_exists('enabled',$r)&&$r['enabled']!==true))continue;
        $symbol=(string)($r['symbol']??$r['code']??'');
        if($m==='KR'){$digits=preg_replace('/[^0-9]/','',$symbol);if($digits==='')continue;$symbol=str_pad(substr($digits,-6),6,'0',STR_PAD_LEFT);$exchange=strtoupper((string)($r['exchange']??$r['market']??''));if(!in_array($exchange,['KOSPI','KOSDAQ','ETF'],true))continue;}
        elseif($m==='US'){$symbol=strtoupper(trim($symbol));if(!preg_match('/^[A-Z0-9.\-^]{1,15}$/',$symbol))continue;$exchange=te_us_exchange((string)($r['exchange']??$r['exchange_code']??''));if($exchange===''){te_log($c['error_file'],'TRADE_LIST US_EXCHANGE_MISSING '.$symbol);continue;}}
        else{$symbol=strtoupper(trim($symbol));if(!preg_match('/^[0-9A-Z]{4,6}$/',$symbol))continue;$exchange=te_jp_exchange((string)($r['exchange']??$r['exchange_code']??$r['market']??''));if($exchange===''){te_log($c['error_file'],'TRADE_LIST JP_EXCHANGE_MISSING '.$symbol);continue;}}
        $key=$m.':'.$symbol;if(isset($seen[$key]))continue;$seen[$key]=true;
        $name=(string)($r['name']??$symbol);$assetType=strtoupper((string)($r['asset_type']??''));$inverse=array_key_exists('is_inverse',$r)?(bool)$r['is_inverse']:te_name_is_inverse($name);$leveraged=array_key_exists('is_leveraged',$r)?(bool)$r['is_leveraged']:te_name_is_leveraged($name);$riskGroup=(string)($r['risk_group']??te_infer_risk_group($m,$symbol,$name,$assetType,$inverse,$leveraged));
        $correlationGroup=te_correlation_group($riskGroup,$m,$symbol,$name,$assetType,$inverse,$leveraged);$out[]=['market'=>$m,'symbol'=>$symbol,'name'=>$name,'exchange'=>$exchange,'asset_type'=>$assetType,'risk_group'=>$riskGroup,'correlation_group'=>$correlationGroup,'is_inverse'=>$inverse,'is_leveraged'=>$leveraged,'enabled'=>true,'trading_halt'=>!empty($r['trading_halt'])||!empty($r['halted']),'risk_symbol'=>!empty($r['risk_symbol'])||!empty($r['managed'])||!empty($r['warning']),'in_trade_list'=>true,'lot_size'=>max(1,(int)($r['lot_size']??1))];
    }}
    usort($out,static function($a,$b){$m=strcmp((string)$a['market'],(string)$b['market']);return$m!==0?$m:strcmp((string)$a['symbol'],(string)$b['symbol']);});return$out;
}

function te_us_exchange(string $value): string
{
    $x=strtoupper(preg_replace('/[^A-Z0-9]/','',$value));
    if(in_array($x,['NASD','NAS','NASDAQ','NMS','NGM'],true))return'NASD';
    if(in_array($x,['NYSE','NYS','NYQ'],true))return'NYSE';
    if(in_array($x,['AMEX','AMS','ASE','ARCA','NYSEARCA'],true))return'AMEX';
    return'';
}
function te_jp_exchange(string $value): string
{
    $x=strtoupper(preg_replace('/[^A-Z0-9]/','',$value));
    return in_array($x,['TKSE','TSE','TYO','TOKYO'],true)?'TKSE':'';
}

function te_scan_limit_for_market(array $c,string $market): int
{
    $limits=is_array($c['scan_limit_by_market']??null)?$c['scan_limit_by_market']:[];
    return max(1,(int)($limits[$market]??$c['scan_limit']??20));
}

function te_scan_markets(array $s): array{$x=[];foreach(['KR','US','JP']as$m)if(te_market_flag($s,$m,'scan_open'))$x[]=$m;return$x;}
function te_scan_cursor_file(array $c,string $m): string{return$c['runtime'].'/'.strtolower($m).'_cursor.json';}
function te_scan_batch(array $c,string $m,array $rows,int $limit,string $dataDate=''): array
{
    $f=te_scan_cursor_file($c,$m);$cursor=te_load($f,[]);$total=count($rows);$session=te_session_date($m);
    if($total===0)return['rows'=>[],'start'=>0,'next'=>0,'total'=>0,'wrapped'=>false,'cycle_id'=>'','data_date'=>$dataDate,'cursor_file'=>$f];
    $same=(string)($cursor['session']??'')===$session&&(string)($cursor['data_date']??'')===$dataDate&&(int)($cursor['total']??$total)===$total;
    $start=$same?(int)($cursor['next']??0):0;if($start<0||$start>=$total)$start=0;$cycleId=$same?(string)($cursor['cycle_id']??''):'';if($start===0||$cycleId==='')$cycleId=$m.'-'.str_replace('-','',$dataDate?:$session).'-'.date('His').'-'.te_hex(2);
    $slice=array_slice($rows,$start,$limit);$next=$start+count($slice);$wrapped=$next>=$total;if($wrapped)$next=0;
    return['rows'=>$slice,'start'=>$start,'next'=>$next,'total'=>$total,'wrapped'=>$wrapped,'cycle_id'=>$cycleId,'session'=>$session,'data_date'=>$dataDate,'cursor_file'=>$f];
}

function te_scan_progress_init(array $c, array $marketStatus, array $universe, string $tick, array $candidateBook = []): array
{
    $previous = te_load($c['scan_progress_file'], []);
    if (!is_array($previous)) $previous = [];
    $root = ['tick_id'=>$tick, 'status'=>'RUNNING', 'started_at'=>te_now(), 'updated_at'=>te_now(), 'markets'=>[]];
    foreach (['KR','US','JP'] as $market) {
        $total = 0;
        foreach ($universe as $row) if (is_array($row) && ($row['market'] ?? '') === $market) $total++;
        $cursor = te_load(te_scan_cursor_file($c, $market), []);
        $scanOpen = te_market_flag($marketStatus,$market,'scan_open');
        $old = is_array($previous['markets'][$market] ?? null) ? $previous['markets'][$market] : [];
        $snapshotRows = is_array($candidateBook['markets'][$market]['rows'] ?? null) ? $candidateBook['markets'][$market]['rows'] : [];
        $hasSnapshot = count($snapshotRows) > 0;
        if (!$scanOpen) {
            $root['markets'][$market] = array_merge($old, [
                'market'=>$market,
                'scan_open'=>false,
                'status'=>$total < 1 ? 'EMPTY' : ($hasSnapshot ? 'CLOSE_HOLD' : 'NO_SNAPSHOT'),
                'total_count'=>$total,
                'total'=>$total,
                'holding_close'=>$hasSnapshot,
                'current_symbol'=>'',
                'current_name'=>'',
                'batch_total'=>0,
                'batch_processed'=>0,
                'batch_pct'=>0.0,
                'batch_complete'=>false,
                'cycle_complete'=>false,
                'scan_complete'=>false,
                'updated_at'=>te_now(),
            ]);
            continue;
        }
        $next = (int)($cursor['next'] ?? 0);
        $root['markets'][$market] = array_merge($old, [
            'market'=>$market,
            'scan_open'=>true,
            'status'=>$total < 1 ? 'EMPTY' : ($next > 0 ? 'RUNNING' : 'WAITING'),
            'total_count'=>$total,
            'total'=>$total,
            'batch_start'=>0,
            'batch_total'=>0,
            'batch_processed'=>0,
            'batch_pct'=>0.0,
            'batch_complete'=>false,
            'cycle_complete'=>false,
            'scan_complete'=>false,
            'cursor_next'=>$next,
            'cycle_position'=>$next,
            'cycle_pct'=>$total > 0 ? round(min(100, max(0, $next / $total * 100)), 1) : 0.0,
            'current_symbol'=>'',
            'current_name'=>'',
            'holding_close'=>$hasSnapshot,
            'updated_at'=>te_now(),
        ]);
    }
    te_save($c['scan_progress_file'], $root);
    return $root;
}

function te_scan_progress_begin(array $c,array $root,string $m,array $batch): array
{
    $root['markets'][$m]=array_merge(is_array($root['markets'][$m]??null)?$root['markets'][$m]:[],[
        'status'=>count($batch['rows'])>0?'RUNNING':'EMPTY','batch_start'=>(int)$batch['start'],'batch_total'=>count($batch['rows']),
        'batch_processed'=>0,'batch_pct'=>0.0,'batch_complete'=>false,'cycle_complete'=>false,'scan_complete'=>false,'cursor_next'=>(int)$batch['next'],'updated_at'=>te_now()]);
    $root['updated_at']=te_now();te_save($c['scan_progress_file'],$root);return$root;
}
function te_scan_progress_item(array $c,array $root,string $m,array $batch,int $index,array $item): array
{
    $done=$index+1;$batchTotal=max(1,count($batch['rows']));$total=max(0,(int)$batch['total']);$position=min($total,(int)$batch['start']+$done);
    $root['markets'][$m]=array_merge(is_array($root['markets'][$m]??null)?$root['markets'][$m]:[],[
        'status'=>'RUNNING','batch_processed'=>$done,'batch_pct'=>round($done/$batchTotal*100,1),'cycle_position'=>$position,
        'cycle_pct'=>$total>0?round($position/$total*100,1):0.0,'current_symbol'=>(string)($item['symbol']??''),'current_name'=>(string)($item['name']??''),'updated_at'=>te_now()]);
    $root['updated_at']=te_now();te_save($c['scan_progress_file'],$root);return$root;
}
function te_scan_progress_finish(array $c,array $root,string $m,array $batch): array
{
    $cur=is_array($root['markets'][$m]??null)?$root['markets'][$m]:[];$done=count($batch['rows']);$total=(int)$batch['total'];$position=min($total,(int)$batch['start']+$done);$wrapped=!empty($batch['wrapped']);
    $root['markets'][$m]=array_merge($cur,['status'=>$done<1?'EMPTY':($wrapped?'DONE':'RUNNING'),'batch_processed'=>$done,'batch_pct'=>$done>0?100.0:0.0,'batch_complete'=>$done===count($batch['rows']),
        'cursor_next'=>(int)$batch['next'],'cycle_position'=>$wrapped?$total:$position,'cycle_pct'=>$total>0?round(($wrapped?$total:$position)/$total*100,1):0.0,'cycle_complete'=>$wrapped,'scan_complete'=>$wrapped,
        'last_symbol'=>(string)($cur['current_symbol']??''),'last_name'=>(string)($cur['current_name']??''),'updated_at'=>te_now()]);
    if($wrapped)$root['markets'][$m]['scan_completed_at']=te_now();$root['updated_at']=te_now();te_save($c['scan_progress_file'],$root);return$root;
}


function te_candidate_book_load(array $c): array
{
    $raw=te_load($c['candidates_file'],[]);
    if(is_array($raw)&&isset($raw['markets'])&&is_array($raw['markets']))return$raw;
    $book=['schema'=>'candidate_book_v2','updated_at'=>te_now(),'markets'=>['KR'=>te_candidate_market_empty('KR'),'US'=>te_candidate_market_empty('US'),'JP'=>te_candidate_market_empty('JP')]];
    if(is_array($raw))foreach($raw as$row)if(is_array($row)){$m=strtoupper((string)($row['market']??''));$s=(string)($row['symbol']??'');if(isset($book['markets'][$m])&&$s!=='')$book['markets'][$m]['rows'][$s]=$row;}
    return$book;
}
function te_candidate_market_empty(string $market): array
{
    return [
        'market'=>$market,
        'active_session'=>'',
        'live_session'=>'',
        'data_date'=>'',
        'basis'=>'NO_SNAPSHOT',
        'frozen'=>true,
        'captured_at'=>'',
        'updated_at'=>'',
        'rows'=>[],
    ];
}

function te_candidate_book_patch(array $book,string $m,string $symbol,array $patch): array
{
    if(isset($book['markets'][$m]['rows'][$symbol])&&is_array($book['markets'][$m]['rows'][$symbol])){
        $row=array_merge($book['markets'][$m]['rows'][$symbol],$patch);$metrics=is_array($row['metrics']??null)?$row['metrics']:[];
        if(isset($metrics['strategy_id'])||array_key_exists('ranking_eligible',$metrics))foreach(['rank','rank_selected','order_eligible','order_id']as$k)if(array_key_exists($k,$patch))$metrics[$k]=$patch[$k];
        if(isset($patch['reason_codes']))$metrics['reason_codes']=$patch['reason_codes'];if($metrics)$row['metrics']=$metrics;$row['updated_at']=te_now();$book['markets'][$m]['rows'][$symbol]=$row;
    }
    $book['updated_at']=te_now();return$book;
}
function te_candidate_close_snapshot(array $book): array
{
    $out=['schema'=>'close_snapshot_v1','updated_at'=>te_now(),'markets'=>[]];
    foreach(['KR','US','JP']as$m){$x=is_array($book['markets'][$m]??null)?$book['markets'][$m]:te_candidate_market_empty($m);$out['markets'][$m]=['market'=>$m,'data_date'=>(string)($x['data_date']??''),'basis'=>(string)($x['basis']??''),'frozen'=>!empty($x['frozen']),'captured_at'=>(string)($x['captured_at']??''),'count'=>count(is_array($x['rows']??null)?$x['rows']:[]),'rows'=>is_array($x['rows']??null)?array_values($x['rows']):[]];}
    return$out;
}
function te_candidate_status_priority(string $status): int
{
    $status=strtoupper(trim($status));
    $priority=[
        'BUY_PENDING'=>100,
        'BUY_READY'=>95,
        'CANDIDATE'=>90,
        'ARMED'=>85,
        'TOUCHED'=>80,
        'BOUNCE_PENDING'=>75,
        'SUPPORT_NEAR'=>70,
        'WATCH'=>60,
        'FILTERED'=>30,
        'DATA_SHORT'=>10,
        'DATA_STALE'=>8,
        'DATA_ERROR'=>5,
        'RUNTIME_ERROR'=>0,
    ];
    return $priority[$status]??20;
}
function te_candidate_is_error_row(array $row): bool
{
    $status=strtoupper((string)($row['status']??''));
    $type=strtoupper((string)($row['type']??''));
    $metrics=is_array($row['metrics']??null)?$row['metrics']:[];
    $dataCode=strtoupper((string)($row['data_code']??($metrics['data_code']??'')));
    $codes=['DATA_SHORT','DATA_STALE','DATA_ERROR','DATA_SOURCE_MISMATCH','DATA_DATE_MISMATCH','BENCHMARK_DATE_MISMATCH','QUOTE_ERROR','MODEL_EXCEPTION','MODEL_INVALID','RUNTIME_ERROR'];
    return in_array($status,$codes,true)||in_array($type,$codes,true)||in_array($dataCode,$codes,true);
}
function te_candidate_rows(array $book): array
{
    $out=[];
    foreach(['KR','US','JP']as$m){
        $rows=is_array($book['markets'][$m]['rows']??null)?$book['markets'][$m]['rows']:[];
        foreach($rows as$r)if(is_array($r))$out[]=$r;    }
    usort($out,static function(array $a,array $b): int{
        // 데이터 오류 후보는 점수와 관계없이 정상 후보 아래에 둔다.
        $errorA=te_candidate_is_error_row($a)?1:0;
        $errorB=te_candidate_is_error_row($b)?1:0;
        if($errorA!==$errorB)return$errorA<=>$errorB;

        // 기본 정렬: 점수 높은 순.
        $scoreA=is_numeric($a['score']??null)?(float)$a['score']:0.0;
        $scoreB=is_numeric($b['score']??null)?(float)$b['score']:0.0;
        if($scoreA!==$scoreB)return$scoreA>$scoreB?-1:1;

        // 동점이면 실행 가능성이 높은 상태를 먼저 표시한다.
        $priorityA=te_candidate_status_priority((string)($a['status']??''));
        $priorityB=te_candidate_status_priority((string)($b['status']??''));
        if($priorityA!==$priorityB)return$priorityA>$priorityB?-1:1;

        // 상태까지 같으면 최근 갱신 후보를 먼저 표시한다.
        $timeA=strtotime((string)($a['updated_at']??$a['time']??''));
        $timeB=strtotime((string)($b['updated_at']??$b['time']??''));
        $timeA=$timeA===false?0:$timeA;
        $timeB=$timeB===false?0:$timeB;
        if($timeA!==$timeB)return$timeA>$timeB?-1:1;

        $marketCmp=strcmp((string)($a['market']??''),(string)($b['market']??''));
        if($marketCmp!==0)return$marketCmp;
        return strcmp((string)($a['symbol']??''),(string)($b['symbol']??''));
    });
    return$out;
}

function te_quote(array $c,array $item,array $status): array
{
    $m=(string)$item['market'];$open=te_market_flag($status,$m,'scan_open');$f=$c['quote_cache'].'/'.te_safe($m.'_'.(string)$item['symbol']).'.json';$cache=te_load($f,[]);$ttl=$open?TE_QUOTE_TTL_OPEN:TE_QUOTE_TTL_CLOSED;
    if(is_array($cache['quote']??null)&&(time()-(int)($cache['saved_at']??0))<$ttl)return$cache['quote'];
    $q=$m==='KR'?te_quote_kr($item):te_quote_yahoo($item);
    if($m==='KR'){
        $scanMax=max(60,(int)($c['quote_scan_max_age_by_market']['KR']??300));$qTs=(int)($q['ts']??0);$qAge=$qTs>0?max(0,time()-$qTs):PHP_INT_MAX;
        if(empty($q['ok'])||($open&&$qAge>$scanMax)){
            $alt=te_quote_yahoo($item);$altTs=(int)($alt['ts']??0);
            if(!empty($alt['ok'])&&(empty($q['ok'])||$altTs>$qTs))$q=$alt;
        }
    }
    if(!empty($q['ok']))te_save($f,['saved_at'=>time(),'quote'=>$q]);return$q;
}

function te_quote_kr(array $item): array
{
    $url='https://m.stock.naver.com/api/stock/'.rawurlencode((string)$item['symbol']).'/basic';$j=te_http_json($url);if(!$j)return['ok'=>false];
    $p=te_num($j['closePrice']??$j['now']??0);if($p<=0)return['ok'=>false];$o=te_num($j['openPrice']??0);$raw=(string)($j['localTradedAt']??'');$ts=$raw!==''?strtotime($raw):time();if($ts===false)$ts=time();
    return['ok'=>true,'price'=>$p,'open'=>$o,'volume'=>te_num($j['accumulatedTradingVolume']??0),'source'=>'naver_krx','market_source'=>'KRX','ts'=>$ts,'stale'=>false];
}
function te_quote_yahoo(array $item): array
{
    $sym=te_yahoo_symbol($item);$url='https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($sym).'?range=1d&interval=5m&includePrePost=false';$j=te_http_json($url);$r=$j['chart']['result'][0]??null;if(!is_array($r))return['ok'=>false];$meta=$r['meta']??[];$p=te_num($meta['regularMarketPrice']??0);if($p<=0)return['ok'=>false];$ts=(int)($meta['regularMarketTime']??time());
    return['ok'=>true,'price'=>$p,'open'=>te_num($meta['regularMarketOpen']??0),'volume'=>0.0,'source'=>'yahoo','market_source'=>te_source_family('yahoo',(string)$item['market']),'ts'=>$ts,'stale'=>false];
}

function te_daily_completed_last_date(array $bars,string $market,array $status): string
{
    if(!$bars)return'';
    $completed=te_completed($bars,'1d',$market,$status);
    return te_last_bar_date($completed,$market);
}
function te_daily_content_freshness(array $c,array $bars,string $market,array $status): array
{
    $market=strtoupper($market);$expected=te_expected_completed_date($market,$status,$c);$actual=te_daily_completed_last_date($bars,$market,$status);$relation=te_scan_date_relation($actual,$expected);
    return['ok'=>$relation==='MATCH','market'=>$market,'expected_date'=>$expected,'actual_date'=>$actual,'relation'=>$relation,'bar_count'=>count($bars)];
}
function te_choose_fresher_daily(array $a,array $b,string $market,array $status): array
{
    if(!$a)return$b;if(!$b)return$a;
    $ad=te_daily_completed_last_date($a,$market,$status);$bd=te_daily_completed_last_date($b,$market,$status);
    if($bd>$ad)return$b;if($ad>$bd)return$a;
    /* Equal completed-session freshness: prefer the newly fetched set (b). */
    return$b;
}
function te_fetch_daily_content_aware(array $c,array $item,string $range,array $status): array
{
    $m=strtoupper((string)($item['market']??''));$y=te_yahoo_bars($item,$range,'1d');
    if($m!=='KR')return$y;
    $ys=te_daily_content_freshness($c,$y,$m,$status);
    if(!empty($ys['ok']))return$y;
    /* Yahoo KR daily can be non-empty yet one completed session late.  Empty-only fallback
       leaves a recently-saved but stale cache stuck for hours.  Ask KRX/Naver when the
       CONTENT date is stale, then keep whichever provider has the newer completed session. */
    $n=te_naver_daily($item,$range);
    return te_choose_fresher_daily($y,$n,$m,$status);
}
function te_bars(array $c,array $item,string $range,string $interval,array $status): array
{
    $dir=$c['bars_cache'].'/'.te_safe($interval);if(!is_dir($dir))@mkdir($dir,0775,true);$f=$dir.'/'.te_safe((string)$item['market'].'_'.(string)$item['symbol'].'_'.$range).'.json';$cache=te_load($f,[]);$cachedBars=is_array($cache['bars']??null)?$cache['bars']:[];$m=(string)$item['market'];$scanOpen=te_market_flag($status,$m,'scan_open');$tradeOpen=te_market_flag($status,$m,'trade_open');$ttl=$scanOpen?($interval==='1m'?TE_BARS_TTL_1M_OPEN:TE_BARS_TTL_OPEN):TE_BARS_TTL_CLOSED;
    $contentState=$interval==='1d'?te_daily_content_freshness($c,$cachedBars,$m,$status):['ok'=>true];$contentFresh=!empty($contentState['ok']);
    $fresh=$cachedBars&&(time()-(int)($cache['saved_at']??0))<$ttl&&$contentFresh;$transitionStale=$interval==='1d'&&!$tradeOpen&&!empty($cache['trade_open']);
    if($fresh&&!$transitionStale)return$cachedBars;
    /* A stale daily cache must bypass the normal 6h TTL, but a provider outage must not
       make four strategies hammer the same symbol repeatedly.  Only v4.4.4 attempts set
       content_refresh_attempt_at; legacy stale caches therefore get one immediate repair try. */
    if($interval==='1d'&&$cachedBars&&!$contentFresh&&(int)($cache['content_refresh_attempt_at']??0)>0&&time()-(int)$cache['content_refresh_attempt_at']<TE_DAILY_STALE_RETRY_SEC)return$cachedBars;
    $x=$interval==='1d'?te_fetch_daily_content_aware($c,$item,$range,$status):te_yahoo_bars($item,$range,$interval);
    if(!$x&&$m==='KR'&&$interval==='1d')$x=te_naver_daily($item,$range);
    if($interval==='1d'&&$cachedBars&&$x)$x=te_choose_fresher_daily($cachedBars,$x,$m,$status);
    if($interval==='1m'&&$cachedBars&&$x)$x=te_merge_bars($cachedBars,$x);
    if($x){te_save($f,['saved_at'=>time(),'trade_open'=>$tradeOpen,'session_date'=>te_session_date($m),'bars'=>$x,'content_freshness'=>$interval==='1d'?te_daily_content_freshness($c,$x,$m,$status):null,'content_refresh_attempt_at'=>$interval==='1d'?time():null]);return$x;}
    return$cachedBars;
}

function te_bars_force_refresh(array $c,array $item,string $range,string $interval,array $status): array
{
    $dir=$c['bars_cache'].'/'.te_safe($interval);if(!is_dir($dir))@mkdir($dir,0775,true);
    $f=$dir.'/'.te_safe((string)$item['market'].'_'.(string)$item['symbol'].'_'.$range).'.json';
    $cache=te_load($f,[]);$cached=is_array($cache['bars']??null)?$cache['bars']:[];
    $m=(string)$item['market'];$tradeOpen=te_market_flag($status,$m,'trade_open');
    $x=$interval==='1d'?te_fetch_daily_content_aware($c,$item,$range,$status):te_yahoo_bars($item,$range,$interval);
    if(!$x&&$m==='KR'&&$interval==='1d')$x=te_naver_daily($item,$range);
    if($interval==='1d'&&$cached&&$x)$x=te_choose_fresher_daily($cached,$x,$m,$status);
    if($interval==='1m'&&$cached&&$x)$x=te_merge_bars($cached,$x);
    if($x){te_save($f,['saved_at'=>time(),'trade_open'=>$tradeOpen,'session_date'=>te_session_date($m),'bars'=>$x,'force_refresh'=>true,'content_freshness'=>$interval==='1d'?te_daily_content_freshness($c,$x,$m,$status):null,'content_refresh_attempt_at'=>$interval==='1d'?time():null]);return$x;}
    return$cached;
}
function te_refresh_context_timeframe(array $c,array $ctx,array $item,array $status,string $timeframe,string $tick): array
{
    $req=is_array($c['requirements'][$timeframe]??null)?$c['requirements'][$timeframe]:[];
    if(!$req)return['ok'=>false,'reason'=>'TIMEFRAME_NOT_REQUIRED','ctx'=>$ctx,'bars'=>0];
    $interval=(string)($req['interval']??$timeframe);$ranges=is_array($req['ranges']??null)?$req['ranges']:[(string)($req['range']??'1y')];$best=[];
    foreach($ranges as$range){$refreshCb=(string)($c['force_refresh_callback']??'');$rows=$refreshCb!==''&&function_exists($refreshCb)?(array)$refreshCb($c,$item,(string)$range,$interval,$status):te_bars_force_refresh($c,$item,(string)$range,$interval,$status);if(count($rows)>count($best))$best=$rows;if(count($best)>=(int)($req['min_bars']??30))break;}
    if(!empty($req['complete_only']))$best=te_completed($best,$interval,(string)$item['market'],$status);
    $ctx['bars'][$timeframe]=$best;$source=te_bar_source($best,(string)$item['market']);$ctx['meta']['data_sources'][$timeframe]=$source;$ctx['meta'][$timeframe==='1m'?'m1_source':($timeframe==='1d'?'daily_source':$timeframe.'_source')]=$source;$ctx['meta']['force_refresh_timeframe']=$timeframe;$ctx['meta']['force_refresh_at']=te_now();$ctx['meta']['tick_id']=$tick;$ctx['market_date']=te_context_data_date($ctx,(string)$item['market'],(string)$c['data_date_timeframe']);
    $need=(int)($req['min_bars']??30);return['ok'=>count($best)>=$need,'reason'=>count($best)>=$need?'OK':'DATA_SHORT_AFTER_FORCE_REFRESH','ctx'=>$ctx,'bars'=>count($best),'source'=>$source];
}
function te_exit_daily_timeframes(array $c): array
{
    $out=[];
    foreach((array)($c['requirements']??[])as$tf=>$raw){
        $req=is_array($raw)?$raw:[];if(!empty($req['optional']))continue;
        $interval=strtolower((string)($req['interval']??$tf));if($interval==='1d')$out[]=(string)$tf;
    }
    return array_values(array_unique($out));
}
function te_exit_data_freshness(array $c,array $ctx,array $status): array
{
    $market=strtoupper((string)($ctx['market']??''));$frames=te_exit_daily_timeframes($c);
    if(!$frames||!in_array($market,['KR','US','JP'],true))return['ok'=>true,'required'=>false,'market'=>$market,'expected_date'=>'','checks'=>[],'stale_timeframes'=>[]];
    $expected=te_expected_completed_date($market,$status,$c);$checks=[];$stale=[];
    foreach($frames as$tf){$bars=is_array($ctx['bars'][$tf]??null)?$ctx['bars'][$tf]:[];$actual=te_last_bar_date($bars,$market);$relation=te_scan_date_relation($actual,$expected);$ok=$relation==='MATCH';$checks[$tf]=['interval'=>(string)($c['requirements'][$tf]['interval']??$tf),'actual_date'=>$actual,'expected_date'=>$expected,'relation'=>$relation,'bar_count'=>count($bars),'ok'=>$ok];if(!$ok)$stale[]=$tf;}
    return['ok'=>!$stale,'required'=>true,'market'=>$market,'expected_date'=>$expected,'checks'=>$checks,'stale_timeframes'=>$stale];
}
function te_exit_refresh_stale_daily(array $c,array $ctx,array $position,array $item,array $status,string $tick): array
{
    $before=te_exit_data_freshness($c,$ctx,$status);
    if(!empty($before['ok']))return['attempted'=>false,'success'=>true,'reason'=>'ALREADY_FRESH','before'=>$before,'after'=>$before,'refreshes'=>[],'ctx'=>$ctx];
    $refreshes=[];
    foreach((array)($before['stale_timeframes']??[])as$tf){$rr=te_refresh_context_timeframe($c,$ctx,$item,$status,(string)$tf,$tick);$ctx=is_array($rr['ctx']??null)?$rr['ctx']:$ctx;$refreshes[(string)$tf]=['ok'=>!empty($rr['ok']),'reason'=>(string)($rr['reason']??''),'bars'=>(int)($rr['bars']??0),'source'=>(string)($rr['source']??'')];}
    $after=te_exit_data_freshness($c,$ctx,$status);$success=!empty($after['ok']);
    if(!$success)te_log($c['error_file'],'ACTIVE_EXIT_DATA_STALE '.strtoupper((string)($position['market']??$ctx['market']??'')).':'.(string)($position['symbol']??$ctx['symbol']??'').' expected='.(string)($after['expected_date']??'').' stale='.implode(',',(array)($after['stale_timeframes']??[])));
    return['attempted'=>true,'success'=>$success,'reason'=>$success?'REFRESHED_AND_FRESH':'REFRESH_FAILED_OR_STALE','before'=>$before,'after'=>$after,'refreshes'=>$refreshes,'ctx'=>$ctx];
}

function te_strategy_exit_with_recovery(array $c,array $ctx,array $position,array $state,array $item,array $status,string $tick): array
{
    $cb=(string)($c['exit_callback']??'');$exit=['action'=>'HOLD','code'=>'EXIT_CALLBACK_MISSING'];$recovery=['attempted'=>false,'success'=>false,'reason'=>'NOT_REQUIRED'];
    $freshness=te_exit_data_freshness($c,$ctx,$status);$freshnessRecovery=['attempted'=>false,'success'=>!empty($freshness['ok']),'reason'=>!empty($freshness['ok'])?'NOT_REQUIRED':'STALE_DETECTED'];
    if($cb===''||!function_exists($cb))return['exit'=>$exit,'ctx'=>$ctx,'recovery'=>$recovery,'freshness'=>$freshness,'freshness_recovery'=>$freshnessRecovery];
    if(empty($freshness['ok'])){
        $freshnessRecovery=te_exit_refresh_stale_daily($c,$ctx,$position,$item,$status,$tick);$ctx=is_array($freshnessRecovery['ctx']??null)?$freshnessRecovery['ctx']:$ctx;$freshness=is_array($freshnessRecovery['after']??null)?$freshnessRecovery['after']:$freshness;
        if(empty($freshnessRecovery['success']))return['exit'=>['action'=>'HOLD','code'=>'ACTIVE_EXIT_DATA_STALE_DEGRADED_HOLD','reason'=>'활성 포지션 청산용 완결 일봉이 기준 거래일까지 갱신되지 않아 stale 지표로 청산하지 않음','degraded_hold'=>true],'ctx'=>$ctx,'recovery'=>$recovery,'freshness'=>$freshness,'freshness_recovery'=>$freshnessRecovery];
    }
    try{$exit=$cb($ctx,$position,$state);}catch(Throwable $e){te_log($c['error_file'],'EXIT '.(string)($position['market']??'').':'.(string)($position['symbol']??'').' '.$e->getMessage());return['exit'=>['action'=>'HOLD','code'=>'EXIT_CALLBACK_EXCEPTION','reason'=>$e->getMessage()],'ctx'=>$ctx,'recovery'=>$recovery,'freshness'=>$freshness,'freshness_recovery'=>$freshnessRecovery];}
    $code=strtoupper((string)($exit['code']??''));
    if(strtolower((string)($c['strategy_key']??''))!=='dts'||$code!=='ONE_MINUTE_DATA_TOO_OLD')return['exit'=>$exit,'ctx'=>$ctx,'recovery'=>$recovery,'freshness'=>$freshness,'freshness_recovery'=>$freshnessRecovery];
    $recovery=['attempted'=>true,'success'=>false,'reason'=>'HARD_STALE_1M','before_code'=>$code];
    $rr=te_refresh_context_timeframe($c,$ctx,$item,$status,'1m',$tick);
    $ctx=$rr['ctx'];$recovery['refresh']=$rr;
    if(!empty($rr['ok'])){
        try{$retry=$cb($ctx,$position,$state);}catch(Throwable $e){$retry=['action'=>'HOLD','code'=>'EXIT_CALLBACK_EXCEPTION','reason'=>$e->getMessage()];}
        $after=strtoupper((string)($retry['code']??''));$recovery['after_code']=$after;
        if($after!=='ONE_MINUTE_DATA_TOO_OLD'){$recovery['success']=true;$recovery['reason']='REFRESHED_AND_RECHECKED';$ctx['meta']['dts_hard_stale_recovery']=$recovery;return['exit'=>$retry,'ctx'=>$ctx,'recovery'=>$recovery,'freshness'=>$freshness,'freshness_recovery'=>$freshnessRecovery];}
    }
    $recovery['reason']=!empty($rr['ok'])?'REFRESH_STILL_HARD_STALE':(string)($rr['reason']??'REFRESH_FAILED');
    $ctx['meta']['dts_hard_stale_recovery']=$recovery;
    te_log($c['error_file'],'CRITICAL DTS_HARD_STALE_REFRESH_FAILED '.strtoupper((string)($item['market']??'')).':'.strtoupper((string)($item['symbol']??'')).' '.(string)$recovery['reason']);
    return['exit'=>['action'=>'HOLD','code'=>'DTS_HARD_STALE_DEGRADED_HOLD','reason'=>'1분봉 hard-stale 자동 refresh 실패/미복구 · stale 기술지표로 강제청산하지 않음','degraded_hold'=>true],'ctx'=>$ctx,'recovery'=>$recovery,'freshness'=>$freshness,'freshness_recovery'=>$freshnessRecovery];
}

function te_merge_bars(array $older,array $newer): array
{
    $map=[];foreach(array_merge($older,$newer)as$row){if(!is_array($row))continue;$ts=(int)($row['ts']??0);if($ts<=0){$ts=strtotime((string)($row['time']??''));if($ts===false)$ts=0;}if($ts>0)$map[$ts]=$row;}
    ksort($map,SORT_NUMERIC);return array_values($map);
}
function te_yahoo_bars_parse(array $item,array $j): array
{
    $r=$j['chart']['result'][0]??null;if(!is_array($r))return[];$ts=$r['timestamp']??[];$q=$r['indicators']['quote'][0]??null;if(!is_array($q))return[];$out=[];$mkt=(string)($item['market']??'');$tsCount=count($ts);
    for($i=0;$i<$tsCount;$i++){$cl=te_num($q['close'][$i]??0);if($cl<=0)continue;$t=(int)$ts[$i];$dt=new DateTime('@'.$t);$dt->setTimezone(new DateTimeZone(te_market_timezone($mkt)));$out[]=['ts'=>$t,'time'=>$dt->format('Y-m-d H:i:s'),'open'=>te_num($q['open'][$i]??$cl),'high'=>te_num($q['high'][$i]??$cl),'low'=>te_num($q['low'][$i]??$cl),'close'=>$cl,'volume'=>te_num($q['volume'][$i]??0),'_market_source'=>te_source_family('yahoo',$mkt)];}return$out;
}
function te_yahoo_bars(array $item,string $range,string $interval): array
{
    $path='/v8/finance/chart/'.rawurlencode(te_yahoo_symbol($item)).'?range='.rawurlencode($range).'&interval='.rawurlencode($interval).'&includePrePost=false';
    $out=te_yahoo_bars_parse($item,te_http_json('https://query1.finance.yahoo.com'.$path));
    if($interval==='1m'&&$range==='5d'&&count($out)<245){$alt=te_yahoo_bars_parse($item,te_http_json('https://query2.finance.yahoo.com'.$path));if(count($alt)>count($out))$out=$alt;}
    return$out;
}
function te_naver_daily(array $item,string $range): array
{
    $count=$range==='3y'?780:300;$txt=te_http_get('https://fchart.stock.naver.com/sise.nhn?symbol='.rawurlencode((string)$item['symbol']).'&timeframe=day&count='.$count.'&requestType=0');if($txt==='')return[];preg_match_all('/<item data="([^"]+)"\s*\/?>/u',$txt,$m);$out=[];
    foreach($m[1]??[] as $row){$p=explode('|',$row);if(count($p)<6)continue;$d=$p[0];$ts=strtotime(substr($d,0,4).'-'.substr($d,4,2).'-'.substr($d,6,2).' 15:30:00');$cl=te_num($p[4]);if($cl<=0)continue;$out[]=['ts'=>$ts?:0,'time'=>date('Y-m-d H:i:s',$ts?:time()),'open'=>te_num($p[1]),'high'=>te_num($p[2]),'low'=>te_num($p[3]),'close'=>$cl,'volume'=>te_num($p[5]),'_market_source'=>'KRX'];}return$out;
}

function te_completed(array $bars,string $interval,string $market,array $status): array
{
    if($interval==='1d'){$session=te_session_date($market);$dayComplete=te_market_flag($status,$market,'day_complete');$out=[];foreach($bars as$b){$d=substr((string)($b['time']??''),0,10);if($d===''&&isset($b['ts']))$d=te_date_tz((int)$b['ts'],$market);if($d<$session||($d===$session&&$dayComplete))$out[]=$b;}return$out;}
    $sec=$interval==='60m'?3600:($interval==='30m'?1800:($interval==='1m'?60:0));if($sec<=0)return$bars;$out=[];foreach($bars as$b){$ts=(int)($b['ts']??0);if($ts>0&&$ts+$sec<=time())$out[]=$b;}return$out;
}
function te_bar_source(array $bars,string $market): string{if(!$bars)return'';$b=$bars[count($bars)-1];return te_source_family((string)($b['_market_source']??''),$market);}
function te_name_is_inverse(string $name): bool
{
    $u=strtoupper($name);return strpos($u,'인버스')!==false||strpos($u,'INVERSE')!==false||strpos($u,'BEAR')!==false;
}
function te_name_is_leveraged(string $name): bool
{
    $u=strtoupper($name);return strpos($u,'레버리지')!==false||strpos($u,'2X')!==false||strpos($u,'3X')!==false||strpos($u,'ULTRA')!==false;
}
function te_infer_risk_group(string $market,string $symbol,string $name,string $assetType,bool $inverse=false,bool $leveraged=false): string
{
    $u=strtoupper(preg_replace('/\s+/u','',$name));$assetType=strtoupper($assetType);
    if($inverse)return'INVERSE_'.$market;
    if($leveraged)return'LEVERAGED_'.$market;
    if($assetType==='ETF'){
        if(strpos($u,'S&P500')!==false||strpos($u,'SP500')!==false)return'INDEX_US_SP500';
        if(strpos($u,'나스닥100')!==false||strpos($u,'NASDAQ100')!==false)return'INDEX_US_NASDAQ100';
        if(strpos($u,'미국배당')!==false||strpos($u,'다우존스')!==false||strpos($u,'DOWJONES')!==false)return'INDEX_US_DIVIDEND';
        if(strpos($u,'코스닥150')!==false||strpos($u,'KOSDAQ150')!==false)return'INDEX_KR_KOSDAQ150';
        if(strpos($u,'200')!==false&&$market==='KR')return'INDEX_KR_KOSPI200';
        if(strpos($u,'금')!==false||strpos($u,'GOLD')!==false)return'COMMODITY_GOLD';
        return'ETF_'.$market.'_'.$symbol;
    }
    return'STOCK_'.$market.'_'.$symbol;
}
function te_correlation_group(string $riskGroup,string $market='',string $symbol='',string $name='',string $assetType='',bool $inverse=false,bool $leveraged=false): string
{
    $riskGroup=strtoupper(trim($riskGroup));$market=strtoupper($market);
    if($riskGroup==='')$riskGroup=te_infer_risk_group($market,$symbol,$name,$assetType,$inverse,$leveraged);
    if(strpos($riskGroup,'INDEX_US_')===0)return'THEME_US_EQUITY_INDEX';
    if(strpos($riskGroup,'INDEX_KR_')===0)return'THEME_KR_EQUITY_INDEX';
    if(strpos($riskGroup,'INDEX_JP_')===0)return'THEME_JP_EQUITY_INDEX';
    if(strpos($riskGroup,'INVERSE_')===0)return'THEME_'.$riskGroup;
    if(strpos($riskGroup,'LEVERAGED_')===0)return'THEME_'.$riskGroup;
    if(strpos($riskGroup,'COMMODITY_')===0)return$riskGroup;
    return$riskGroup;
}
function te_correlation_group_limited(string $group): bool
{
    $group=strtoupper(trim($group));return strpos($group,'THEME_')===0||strpos($group,'COMMODITY_')===0;
}
function te_correlation_exposure_book(array $c,array $positions,array $brokerOrders): array
{
    $book=[];$strategy=(string)$c['strategy_key'];
    $add=static function(array &$book,string $strategyKey,string $market,string $symbol,string $name,string $assetType,string $riskGroup,bool $inverse,bool $leveraged,string $status): void{
        $market=strtoupper($market);$symbol=strtoupper($symbol);$strategyKey=strtolower($strategyKey);if($strategyKey===''||$symbol===''||!in_array($market,['KR','US','JP'],true))return;
        $group=te_correlation_group($riskGroup,$market,$symbol,$name,$assetType,$inverse,$leveraged);if($group==='')return;$key=$strategyKey.':'.$market.':'.$symbol;
        $book[$key]=['key'=>$key,'strategy'=>$strategyKey,'market'=>$market,'symbol'=>$symbol,'name'=>$name,'risk_group'=>$riskGroup,'correlation_group'=>$group,'status'=>$status];
    };
    foreach(te_broker_open_position_book($c,$brokerOrders)as$p)$add($book,(string)($p['strategy']??''),(string)($p['market']??''),(string)($p['symbol']??''),(string)($p['name']??''),(string)($p['asset_type']??''),(string)($p['risk_group']??''),!empty($p['is_inverse']),!empty($p['is_leveraged']),'OPEN');
    foreach($positions as$p){if(!te_active_position($p))continue;$add($book,$strategy,(string)($p['market']??''),(string)($p['symbol']??''),(string)($p['name']??''),(string)($p['asset_type']??''),(string)($p['risk_group']??''),!empty($p['is_inverse']),!empty($p['is_leveraged']),'OPEN');}
    $root=te_load($c['intents_file'],[]);$intents=is_array($root['intents']??null)?$root['intents']:[];
    foreach($intents as$i){if(!is_array($i)||strtoupper((string)($i['side']??''))!=='BUY'||!te_intent_row_active($i,$brokerOrders))continue;$add($book,(string)($i['strategy_key']??''),(string)($i['market']??''),(string)($i['symbol']??''),(string)($i['name']??''),(string)($i['asset_type']??''),(string)($i['risk_group']??''),!empty($i['is_inverse']),!empty($i['is_leveraged']),'PENDING');}
    foreach($brokerOrders as$o){if(!is_array($o)||strtoupper((string)($o['side']??''))!=='BUY'||!te_broker_order_active($o))continue;$add($book,(string)($o['strategy_key']??''),(string)($o['market']??''),(string)($o['symbol']??''),(string)($o['name']??''),(string)($o['asset_type']??''),(string)($o['risk_group']??''),!empty($o['is_inverse']),!empty($o['is_leveraged']),'PENDING');}
    return array_values($book);
}
function te_correlation_entry_guard(array $c,array $positions,array $brokerOrders,array $item): array
{
    $market=strtoupper((string)($item['market']??''));$symbol=strtoupper((string)($item['symbol']??''));$name=(string)($item['name']??$symbol);$assetType=(string)($item['asset_type']??'');$riskGroup=(string)($item['risk_group']??'');$inverse=!empty($item['is_inverse']);$leveraged=!empty($item['is_leveraged']);
    $group=(string)($item['correlation_group']??te_correlation_group($riskGroup,$market,$symbol,$name,$assetType,$inverse,$leveraged));
    if($group===''||!te_correlation_group_limited($group))return['ok'=>true,'reason'=>'CORRELATION_NOT_LIMITED','correlation_group'=>$group,'strategy_count'=>0,'global_count'=>0];
    $strategy=(string)$c['strategy_key'];$candidateKey=$strategy.':'.$market.':'.$symbol;$strategySet=[];$globalSet=[];
    foreach(te_correlation_exposure_book($c,$positions,$brokerOrders)as$row){if(!is_array($row)||(string)($row['correlation_group']??'')!==$group)continue;$key=(string)($row['key']??'');if($key===''||$key===$candidateKey)continue;$globalSet[$key]=true;if((string)($row['strategy']??'')===$strategy)$strategySet[$key]=true;}
    $strategyCount=count($strategySet);$globalCount=count($globalSet);$strategyLimit=(int)$c['max_correlation_positions_per_strategy'];$globalLimit=(int)$c['max_correlation_positions_global'];
    if($strategyCount>=$strategyLimit)return['ok'=>false,'reason'=>'CORRELATION_GROUP_STRATEGY_LIMIT '.$group.' '.$strategyCount.'/'.$strategyLimit,'correlation_group'=>$group,'strategy_count'=>$strategyCount,'strategy_limit'=>$strategyLimit,'global_count'=>$globalCount,'global_limit'=>$globalLimit];
    if($globalCount>=$globalLimit)return['ok'=>false,'reason'=>'CORRELATION_GROUP_GLOBAL_LIMIT '.$group.' '.$globalCount.'/'.$globalLimit,'correlation_group'=>$group,'strategy_count'=>$strategyCount,'strategy_limit'=>$strategyLimit,'global_count'=>$globalCount,'global_limit'=>$globalLimit];
    return['ok'=>true,'reason'=>'OK','correlation_group'=>$group,'strategy_count'=>$strategyCount,'strategy_limit'=>$strategyLimit,'global_count'=>$globalCount,'global_limit'=>$globalLimit];
}
function te_correlation_summary(array $c,array $positions,array $brokerOrders): array
{
    $groups=[];foreach(te_correlation_exposure_book($c,$positions,$brokerOrders)as$row){if(!is_array($row))continue;$group=(string)($row['correlation_group']??'');if($group===''||!te_correlation_group_limited($group))continue;if(!isset($groups[$group]))$groups[$group]=['correlation_group'=>$group,'count'=>0,'strategies'=>[],'positions'=>[]];$groups[$group]['count']++;$groups[$group]['strategies'][(string)$row['strategy']]=true;$groups[$group]['positions'][]=(string)$row['strategy'].':'.(string)$row['market'].':'.(string)$row['symbol'];}
    foreach($groups as$g=>$row){$groups[$g]['strategies']=array_keys($row['strategies']);$groups[$g]['global_limit']=(int)$c['max_correlation_positions_global'];$groups[$g]['over_limit']=(int)$row['count']>(int)$c['max_correlation_positions_global'];}
    uasort($groups,static function($a,$b){$cmp=(int)$b['count']<=>(int)$a['count'];return$cmp!==0?$cmp:strcmp((string)$a['correlation_group'],(string)$b['correlation_group']);});
    return['policy'=>['strategy_limit'=>(int)$c['max_correlation_positions_per_strategy'],'global_limit'=>(int)$c['max_correlation_positions_global'],'new_entries_only'=>true],'groups'=>array_values($groups)];
}

function te_is_business_date(string $date,string $market,array $c): bool
{
    $d=DateTime::createFromFormat('!Y-m-d',$date,new DateTimeZone(te_market_timezone($market)));if(!$d)return false;$cal=te_market_calendar((string)($c['calendar_file']??''));return(int)$d->format('N')<=5&&!in_array($date,$cal[$market]['holidays']??[],true);
}
function te_previous_business_date(string $date,string $market,array $c): string
{
    $d=DateTime::createFromFormat('!Y-m-d',$date,new DateTimeZone(te_market_timezone($market)));if(!$d)return'';do{$d->modify('-1 day');$x=$d->format('Y-m-d');}while(!te_is_business_date($x,$market,$c));return$x;
}
function te_expected_completed_date(string $market,array $status,array $c): string
{
    $tz=new DateTimeZone(te_market_timezone($market));$now=new DateTime('now',$tz);$date=$now->format('Y-m-d');$business=te_is_business_date($date,$market,$c);$complete=te_market_flag($status,$market,'day_complete');if($business&&$complete)return$date;return te_previous_business_date($date,$market,$c);
}
function te_business_day_gap(string $from,string $to,string $market,array $c): int
{
    if($from===''||$to===''||$from>=$to)return0;$d=DateTime::createFromFormat('!Y-m-d',$from,new DateTimeZone(te_market_timezone($market)));$end=DateTime::createFromFormat('!Y-m-d',$to,new DateTimeZone(te_market_timezone($market)));if(!$d||!$end)return99;$n=0;while($d<$end){$d->modify('+1 day');if(te_is_business_date($d->format('Y-m-d'),$market,$c))$n++;if($n>30)break;}return$n;
}
function te_add_business_days(string $date,int $days,string $market,array $c): string
{
    if($days<=0)return$date;$d=DateTime::createFromFormat('!Y-m-d',$date,new DateTimeZone(te_market_timezone($market)));if(!$d)return$date;$n=0;while($n<$days){$d->modify('+1 day');if(te_is_business_date($d->format('Y-m-d'),$market,$c))$n++;}return$d->format('Y-m-d');
}
function te_position_cohort(array $c,array $position): string
{
    $strategy=strtolower((string)($c['strategy_key']??''));$rev=strtolower(trim((string)($position['entry_strategy_rev']??'')));$current=strtolower(trim((string)($c['strategy_rev']??'')));
    if($rev==='')return'LEGACY_UNKNOWN';
    if($current!==''&&hash_equals($current,$rev))return$strategy==='dts'?'DTS_SUPPORT_REVERSAL_60M':'CURRENT';
    if($strategy==='dts')return'LEGACY_DTS';
    return'LEGACY_REVISION';
}
function te_is_stop_reason(string $reason): bool
{
    return(bool)preg_match('/STOP|LOSS|BROKEN|BOUNCE_FAILED|INITIAL_STOP|EARLY_LOSS/i',$reason);
}
function te_scenario_key(array $c,string $market,string $symbol,string $type,string $signalDate,string $scenario): string
{
    $scenario=trim($scenario);if($scenario!=='')return strtolower($c['strategy_key']).':'.sha1($scenario);return strtolower($c['strategy_key']).':'.$market.':'.$symbol.':'.$signalDate.':'.strtoupper($type);
}

function te_jp_live_requote_reason(array $r): string
{
    if(strtoupper((string)($r['market']??''))!=='JP'||strtoupper((string)($r['side']??''))!=='BUY')return'';
    foreach(['terminal_reason','cancel_reason','expired_reason','reject_reason','approval_block_reason','approval_blocked_at','approval_last_checked_at','broker_message']as$k){$v=strtoupper((string)($r[$k]??''));if(strpos($v,'JP_LIVE_REQUOTE')!==false)return$v;}
    $status=strtoupper((string)($r['status']??''));$fresh=strtoupper((string)($r['quote_freshness']??''));$exp=strtoupper((string)($r['expired_reason']??$r['terminal_reason']??''));
    if($status==='EXPIRED'&&!empty($r['requires_live_requote'])&&$fresh==='DELAYED'&&in_array($exp,['ORDER_TTL_BEFORE_FILL','ARRIVED_AFTER_EXPIRY'],true))return'JP_LIVE_REQUOTE_LEGACY_EXPIRED';
    return'';
}
function te_is_jp_quote_terminal(array $r): bool{return te_jp_live_requote_reason($r)!=='';}
function te_entry_cooldown_guard(array $c,string $market,string $symbol,string $side,string $scenarioKey,array $state=[]): array
{
    $until=(string)($state['cooldown_until']??'');$ts=$until!==''?strtotime($until):false;$lastReason=strtoupper((string)($state['last_reason']??''));$jpLegacyStateExempt=$market==='JP'&&in_array($lastReason,['ORDER_EXPIRED','ORDER_EXPIRED_COOLDOWN','JP_LIVE_REQUOTE_RETRY'],true);if(!$jpLegacyStateExempt&&$ts!==false&&$ts>time())return['ok'=>false,'reason'=>(string)($state['last_reason']??'ENTRY_COOLDOWN'),'until'=>$until];
    static $cache=[];$cacheKey=$c['runtime'].'|'.$c['broker_runtime'];if(!isset($cache[$cacheKey])){$archive=te_load($c['intents_archive_file'],[]);$active=te_load($c['intents_file'],[]);$cache[$cacheKey]=['archive'=>is_array($archive['intents']??null)?$archive['intents']:[],'active'=>is_array($active['intents']??null)?$active['intents']:[],'orders'=>te_broker_orders($c),'trades'=>te_load($c['trades_file'],[])];}
    $rows=array_merge($cache[$cacheKey]['archive'],$cache[$cacheKey]['active'],$cache[$cacheKey]['orders']);$latestExpired=0;$latestScenario=0;
    foreach($rows as$r){if(!is_array($r)||strtolower((string)($r['strategy_key']??''))!==$c['strategy_key']||strtoupper((string)($r['market']??''))!==$market||strtoupper((string)($r['symbol']??''))!==strtoupper($symbol)||strtoupper((string)($r['side']??''))!==strtoupper($side))continue;if(te_is_jp_quote_terminal($r))continue;$closed=strtotime((string)($r['closed_at']??$r['processed_at']??$r['created_at']??''));if($closed===false)$closed=0;if(strtoupper((string)($r['status']??''))==='EXPIRED')$latestExpired=max($latestExpired,$closed);if($scenarioKey!==''&&(string)($r['scenario_key']??'')===$scenarioKey)$latestScenario=max($latestScenario,$closed);}
    if($latestExpired>0&&time()-$latestExpired<(int)$c['expired_cooldown_sec']){$u=date('Y-m-d H:i:s',$latestExpired+(int)$c['expired_cooldown_sec']);return['ok'=>false,'reason'=>'ORDER_EXPIRED_COOLDOWN','until'=>$u];}
    if($latestScenario>0&&time()-$latestScenario<(int)$c['scenario_cooldown_sec']){$u=date('Y-m-d H:i:s',$latestScenario+(int)$c['scenario_cooldown_sec']);return['ok'=>false,'reason'=>'SCENARIO_REISSUE_BLOCK','until'=>$u];}
    $trades=is_array($cache[$cacheKey]['trades'])?$cache[$cacheKey]['trades']:[];for($i=count($trades)-1;$i>=0;$i--){$t=$trades[$i]??null;if(!is_array($t)||strtoupper((string)($t['market']??''))!==$market||strtoupper((string)($t['symbol']??''))!==strtoupper($symbol)||!te_is_stop_reason((string)($t['sell_reason']??'')))continue;$sellDate=substr((string)($t['sell_time']??''),0,10);$untilDate=te_add_business_days($sellDate,(int)$c['stop_reentry_business_days'],$market,$c);if(te_session_date($market)<=$untilDate)return['ok'=>false,'reason'=>'STOP_REENTRY_COOLDOWN','until'=>$untilDate.' 23:59:59'];break;}
    return['ok'=>true,'reason'=>'OK','until'=>''];
}
function te_fetch_benchmark_candidate(array $c,array $item,string $market,array $status,string $target): array
{
    $best=[];$bestDate='';$attempts=max(1,(int)$c['benchmark_retry_count']+1);for($a=0;$a<$attempts;$a++){if($a>0){$prefix=te_safe($market.'_'.(string)$item['symbol']).'_';foreach(glob($c['bars_cache'].'/1d/'.$prefix.'*.json')?:[]as$f)@unlink($f);usleep(100000);}$bars=te_completed(te_bars($c,$item,'2y','1d',$status),'1d',$market,$status);$date=te_last_bar_date($bars,$market);if($date>$bestDate){$best=$bars;$bestDate=$date;}if($target===''||($date!==''&&$date>=$target))break;}return['bars'=>$best,'date'=>$bestDate];
}

function te_sector_bars(array $c,array $item,string $market,array $status): array
{
    $symbol=strtoupper(trim((string)($item['sector_benchmark_symbol']??'')));if($symbol==='')return[];
    $sector=[
        'market'=>$market,'symbol'=>$symbol,
        'name'=>(string)($item['sector_benchmark_name']??$symbol),
        'exchange'=>(string)($item['sector_benchmark_exchange']??($market==='US'?'AMEX':($market==='JP'?'TKSE':'KOSPI'))),
    ];
    $best=[];foreach(['3y','2y','1y']as$range){$rows=te_completed(te_bars($c,$sector,$range,'1d',$status),'1d',$market,$status);if(count($rows)>count($best))$best=$rows;if(count($best)>=30)break;}
    if($best)foreach($best as$i=>$bar)if(is_array($bar)){$best[$i]['_sector_benchmark_symbol']=$symbol;$best[$i]['_sector_benchmark_name']=$sector['name'];}
    return$best;
}

function te_external_risk_overlay(array $c,string $market,string $symbol): array
{
    $path=(string)($c['external_risk_file']??'');if($path===''||!is_file($path))return[];
    static$cache=[];if(!array_key_exists($path,$cache)){$loaded=te_load($path,[]);$cache[$path]=is_array($loaded)?$loaded:[];}$data=$cache[$path];$key=strtoupper($market).':'.strtoupper($symbol);$row=[];
    if(is_array($data['symbols'][$key]??null))$row=$data['symbols'][$key];
    elseif(is_array($data[$key]??null))$row=$data[$key];
    elseif(is_array($data[strtoupper($market)][strtoupper($symbol)]??null))$row=$data[strtoupper($market)][strtoupper($symbol)];
    $allowed=['trading_halt','risk_symbol','major_risk','accounting_issue','large_dilution','liquidity_crisis','regulatory_crisis','major_customer_loss','earnings_collapse','serious_debt_issue','external_information_reviewed','fundamental_risk_reviewed','external_information_reviewed_at','external_information_source'];$out=[];
    foreach($allowed as$field)if(array_key_exists($field,$row))$out[$field]=$row[$field];return$out;
}

function te_market_bars(array $c,string $market,array $status): array
{
    if($market==='KR'){$primary=['market'=>'KR','symbol'=>'^KS11','name'=>'KOSPI','exchange'=>'INDEX'];$proxy=['market'=>'KR','symbol'=>'069500','name'=>'KODEX 200','exchange'=>'KOSPI'];}
    elseif($market==='JP'){$primary=['market'=>'JP','symbol'=>'^N225','name'=>'Nikkei 225','exchange'=>'INDEX'];$proxy=['market'=>'JP','symbol'=>'1306','name'=>'NEXT FUNDS TOPIX ETF','exchange'=>'TKSE'];}
    else{$primary=['market'=>'US','symbol'=>'^GSPC','name'=>'S&P 500','exchange'=>'INDEX'];$proxy=['market'=>'US','symbol'=>'SPY','name'=>'SPDR S&P 500 ETF','exchange'=>'AMEX'];}
    $state=te_load($c['benchmark_state_file'],[]);$row=is_array($state['markets'][$market]??null)?$state['markets'][$market]:[];$expected=te_expected_completed_date($market,$status,$c);$oldTarget=(string)($row['target_date']??'');$target=$oldTarget>$expected?$oldTarget:$expected;
    $p=te_fetch_benchmark_candidate($c,$primary,$market,$status,$target);$x=te_fetch_benchmark_candidate($c,$proxy,$market,$status,$target);$selected=$primary;$source='PRIMARY';$bars=$p['bars'];$selectedDate=$p['date'];
    if($x['date']>$selectedDate){$selected=$proxy;$source='PROXY';$bars=$x['bars'];$selectedDate=$x['date'];}
    if($bars){foreach($bars as$k=>$b){if(!is_array($b))continue;$bars[$k]['_benchmark_symbol']=$selected['symbol'];$bars[$k]['_benchmark_label']=$selected['name'];$bars[$k]['_benchmark_source']=$source;}}
    $lag=te_business_day_gap($selectedDate,$target,$market,$c);$degraded=$target!==''&&($selectedDate===''||$selectedDate<$target);$state['schema']='benchmark_source_v3';$state['updated_at']=te_now();$state['markets'][$market]=['target_date'=>$degraded?$target:'','expected_date'=>$expected,'selected_symbol'=>$selected['symbol'],'selected_label'=>$selected['name'],'source'=>$source,'data_date'=>$selectedDate,'lag_business_days'=>$lag,'degraded'=>$degraded,'entry_blocked'=>$degraded&&!empty($c['benchmark_degraded_entry_block']),'updated_at'=>te_now()];te_save($c['benchmark_state_file'],$state);return$bars;
}
function te_yahoo_symbol(array $i): string{$m=strtoupper((string)($i['market']??''));$s=strtoupper((string)($i['symbol']??''));if($s!==''&&$s[0]==='^')return$s;if($m==='KR'){$ex=strtoupper((string)($i['exchange']??''));return$s.(strpos($ex,'KOSDAQ')!==false?'.KQ':'.KS');}if($m==='JP')return preg_match('/\.T$/',$s)?$s:$s.'.T';return$s;}
function te_source_family(string $s,string $m): string{$s=strtoupper(trim($s));if($s==='')return'';$m=strtoupper(trim($m));if(strpos($s,'NXT')!==false)return$m.'_NXT';if($m==='KR')return'KRX';if($m==='US')return'US_REGULAR';if($m==='JP')return'JP_TSE';return$s;}

function te_market_regime(string $m,array $bars,array $status): array
{
    $cl=[];
    foreach($bars as $b){
        if(!is_array($b))continue;
        $v=te_num($b['close']??0);
        if($v>0)$cl[]=$v;
    }
    $n=count($cl);
    $last=$bars?$bars[count($bars)-1]:[];
    $index=(string)($last['_benchmark_label']??($m==='KR'?'KOSPI':($m==='JP'?'Nikkei 225':'S&P 500')));
    $symbol=(string)($last['_benchmark_symbol']??($m==='KR'?'^KS11':($m==='JP'?'^N225':'^GSPC')));
    $benchmarkSource=(string)($last['_benchmark_source']??'PRIMARY');
    $localTime=(string)($status[strtolower($m).'_time']??'');
    $tradeOpen=te_market_flag($status,$m,'trade_open');
    $entryOpen=te_market_flag($status,$m,'entry_open');
    if($n<60){
        return[
            'market'=>$m,'index'=>$index,'symbol'=>$symbol,'benchmark_source'=>$benchmarkSource,
            'code'=>'UNKNOWN','label'=>'데이터 부족','score'=>0,
            'close'=>0,'ma20'=>0,'ma60'=>0,'ma140'=>0,'ma280'=>0,
            'ma20_slope'=>0,'ma60_slope'=>0,'bars'=>$n,'date'=>'',
            'local_time'=>$localTime,'trade_open'=>$tradeOpen,'entry_open'=>$entryOpen,
        ];
    }
    $close=$cl[$n-1];
    $ma20=te_sma_values($cl,20);$ma60=te_sma_values($cl,60);
    $ma140=te_sma_values($cl,140);$ma280=te_sma_values($cl,280);
    $p20=te_sma_values($cl,20,1);$p60=te_sma_values($cl,60,1);
    $s20=$p20>0?($ma20/$p20-1)*100:0.0;
    $s60=$p60>0?($ma60/$p60-1)*100:0.0;
    $score=50;
    $score+=$close>$ma20?12:-12;
    $score+=$ma20>$ma60?12:-12;
    $score+=$close>$ma60?10:-10;
    if($ma140>0&&$ma280>0)$score+=$ma140>$ma280?12:-12;
    $score+=$s20>=0?3:-3;
    $score+=$s60>=0?3:-3;
    $score=max(0,min(100,$score));
    if($close>$ma20&&$ma20>$ma60&&($ma280<=0||$ma140>$ma280)&&$s20>=0&&$s60>=0){$code='BULL';$label='강세 정배열';}
    elseif($close>$ma20&&$ma20>=$ma60&&($ma280<=0||$ma140>$ma280)){$code='UP';$label='상승 레짐';}
    elseif($close>$ma20&&$close>$ma60){$code='RECOVERY';$label='회복 레짐';}
    elseif($close>=$ma60*0.99){$code='NEUTRAL';$label='중립 레짐';}
    elseif($close<$ma60&&$ma140>0&&$ma280>0&&$ma140<$ma280){$code='BEAR';$label='약세 하락';}
    else{$code='WEAK';$label='조정 레짐';}
    $date=te_last_bar_date($bars,$m);
    return[
        'market'=>$m,'index'=>$index,'symbol'=>$symbol,'benchmark_source'=>$benchmarkSource,
        'code'=>$code,'label'=>$label,'score'=>$score,'close'=>round($close,4),
        'ma20'=>round($ma20,4),'ma60'=>round($ma60,4),'ma140'=>round($ma140,4),'ma280'=>round($ma280,4),
        'ma20_slope'=>round($s20,3),'ma60_slope'=>round($s60,3),'bars'=>$n,'date'=>$date,
        'local_time'=>$localTime,'trade_open'=>$tradeOpen,'entry_open'=>$entryOpen,
    ];
}

function te_market_regimes(array $c,array $status): array
{
    $prev=te_load($c['regime_file'],[]);
    if(!is_array($prev))$prev=[];
    $out=['updated_at'=>te_now(),'markets'=>[]];
    foreach(['KR','US','JP'] as $m){
        try{
            $bars=te_market_bars($c,$m,$status);
            $now=te_market_regime($m,$bars,$status);
        }catch(Throwable $e){
            $now=['market'=>$m,'code'=>'UNKNOWN','label'=>'확인 불가','score'=>0,'reason'=>$e->getMessage(),'bars'=>0,'date'=>''];
        }
        $old=is_array($prev['markets'][$m]??null)?$prev['markets'][$m]:[];
        if((string)($now['code']??'UNKNOWN')==='UNKNOWN'&&$old&&(string)($old['code']??'UNKNOWN')!=='UNKNOWN'){
            $now=array_merge($old,[
                'held'=>true,'hold_reason'=>'새 레짐 데이터 없음',
                'local_time'=>(string)($status[strtolower($m).'_time']??''),
                'trade_open'=>te_market_flag($status,$m,'trade_open'),
                'entry_open'=>te_market_flag($status,$m,'entry_open'),
            ]);
        }
        $benchmarkState=te_load($c['benchmark_state_file'],[]);$benchmarkRow=is_array($benchmarkState['markets'][$m]??null)?$benchmarkState['markets'][$m]:[];$now['target_date']=(string)($benchmarkRow['target_date']??'');$now['expected_date']=(string)($benchmarkRow['expected_date']??te_expected_completed_date($m,$status,$c));$now['benchmark_degraded']=!empty($benchmarkRow['degraded']);$now['entry_blocked']=!empty($benchmarkRow['entry_blocked']);$now['benchmark_lag_business_days']=(int)($benchmarkRow['lag_business_days']??0);
        $tradeOpen=te_market_flag($status,$m,'trade_open');
        $now['frozen']=!$tradeOpen;
        $now['basis']=$tradeOpen?'LIVE':'LAST_COMPLETED_CLOSE';
        $now['calculated_at']=te_now();
        $out['markets'][$m]=$now;
    }
    return$out;
}

function te_sma_values(array $values,int $period,int $offset=0): float
{
    $end=count($values)-$offset;if($period<1||$end<$period)return 0.0;$slice=array_slice($values,$end-$period,$period);return count($slice)===$period?array_sum($slice)/$period:0.0;
}


function te_pending_buy_amounts(array $c,array $brokerOrders): array
{
    $out=['KR'=>0.0,'US'=>0.0,'JP'=>0.0];$seen=[];$brokerMap=[];foreach($brokerOrders as$o)if(is_array($o))$brokerMap[(string)($o['order_id']??'')]=$o;
    $root=te_load($c['intents_file'],[]);$rows=is_array($root['intents']??null)?$root['intents']:[];
    foreach($rows as$i){if(!is_array($i)||!te_scope_strategy_allowed($c,(string)($i['strategy_key']??''))||strtoupper((string)($i['side']??''))!=='BUY')continue;$id=(string)($i['order_id']??'');if($id===''||isset($seen[$id])||!te_intent_row_active($i,$brokerOrders))continue;$seen[$id]=true;$market=strtoupper((string)($i['market']??''));if(!isset($out[$market]))continue;$qty=max(0,(int)($i['qty']??0));$price=max(0.0,(float)($i['price']??0));$filled=isset($brokerMap[$id])?max(0,(int)($brokerMap[$id]['filled_qty']??0)):0;$remain=max(0,$qty-$filled);$gross=$remain*$price;$out[$market]+=$gross+te_buy_fee($market,$gross);}
    foreach($brokerOrders as$o){if(!is_array($o)||!te_scope_strategy_allowed($c,(string)($o['strategy_key']??''))||strtoupper((string)($o['side']??''))!=='BUY'||!te_broker_order_active($o))continue;$id=(string)($o['order_id']??'');if($id===''||isset($seen[$id]))continue;$seen[$id]=true;$market=strtoupper((string)($o['market']??''));if(!isset($out[$market]))continue;$remain=max(0,(int)($o['qty']??0)-(int)($o['filled_qty']??0));$gross=$remain*max(0.0,(float)($o['price']??0));$out[$market]+=$gross+te_buy_fee($market,$gross);}
    return$out;
}

function te_risk_state_preview(array $c,array $capital): array
{
    $old=te_load($c['risk_state_file'],[]);if(!is_array($old))$old=[];$out=['updated_at'=>te_now(),'markets'=>[]];
    foreach(['KR','US','JP']as$m){
        $date=te_session_date($m);$seed=$m==='KR'?$c['seed_kr']:($m==='JP'?$c['seed_jp']:$c['seed_us']);$eq=(float)($capital[$m]['equity']??$seed);$prev=is_array($old['markets'][$m]??null)?$old['markets'][$m]:[];
        $sameDay=(string)($prev['date']??'')===$date;$dayStart=$sameDay?(float)($prev['day_start_equity']??$eq):$eq;$priorHigh=$sameDay?(float)($prev['high_water_equity']??$eq):$eq;$high=max($eq,$priorHigh);
        $daily=$dayStart>0?max(0.0,($dayStart-$eq)/$dayStart*100.0):0.0;$dd=$high>0?max(0.0,($high-$eq)/$high*100.0):0.0;
        $out['markets'][$m]=['date'=>$date,'equity'=>round($eq,4),'day_start_equity'=>round($dayStart,4),'high_water_equity'=>round($high,4),'daily_loss_pct'=>round($daily,4),'drawdown_pct'=>round($dd,4),'daily_loss_block'=>$daily>=(float)$c['daily_loss_limit_pct'],'drawdown_block'=>$dd>=(float)$c['max_drawdown_pct']];
    }
    return$out;
}

function te_risk_state_update(array $c,array $capital): array
{
    $out=te_risk_state_preview($c,$capital);te_save($c['risk_state_file'],$out);return$out;
}

function te_entry_risk_block_reason(array $c,string $market,array $capital): string
{
    $state=te_risk_state_preview($c,$capital);$risk=is_array($state['markets'][$market]??null)?$state['markets'][$market]:[];
    if(!empty($risk['daily_loss_block']))return'PORTFOLIO_DAILY_LOSS_BLOCK';
    if(!empty($risk['drawdown_block']))return'PORTFOLIO_DRAWDOWN_BLOCK';
    return'';
}

function te_common_exit(array $c,array $position,array $quote,array $riskState): array
{
    $market=(string)($position['market']??'');$price=(float)($quote['price']??0);$entry=(float)($position['entry_price']??0);$peak=max((float)($position['peak_price']??0),$price);$stop=(float)($position['stop_price']??0);$policy=$c['risk_policy'];
    if($price<=0||$entry<=0)return['action'=>'HOLD'];
    $risk=is_array($riskState['markets'][$market]??null)?$riskState['markets'][$market]:[];
    if(!empty($policy['portfolio_drawdown_liquidate'])&&!empty($risk['drawdown_block']))return['action'=>'SELL','code'=>'ENGINE_MAX_DRAWDOWN','reason'=>'ENGINE_MAX_DRAWDOWN','risk_exit'=>true,'urgent_exit'=>true];
    if(!empty($policy['daily_loss_liquidate'])&&!empty($risk['daily_loss_block']))return['action'=>'SELL','code'=>'ENGINE_DAILY_LOSS','reason'=>'ENGINE_DAILY_LOSS','risk_exit'=>true];
    if(!empty($policy['initial_stop'])){$stop=te_effective_initial_stop($entry,$stop,(float)($policy['max_initial_stop_pct']??0.0));if($price<=$stop)return['action'=>'SELL','code'=>'ENGINE_INITIAL_STOP','reason'=>'ENGINE_INITIAL_STOP','risk_exit'=>true,'effective_stop_price'=>round($stop,4)];}
    $target=(float)($position['target_price']??0);if(!empty($policy['hard_target'])&&$target>$entry&&($price>=$target||$peak>=$target))return['action'=>'SELL','code'=>'ENGINE_TARGET','reason'=>'ENGINE_TARGET','risk_exit'=>false];
    $entryDate=(string)($position['entry_market_date']??substr((string)($position['entry_time']??''),0,10));$today=te_session_date($market);$days=te_business_days_between($entryDate,$today,$market,$c);$ret=($price/$entry-1.0)*100.0;
    $ladder=te_progressive_loss_exit($policy,$days,$ret);if(($ladder['action']??'HOLD')==='SELL')return$ladder;
    if(!empty($policy['max_holding'])&&$days>=(int)$policy['max_holding_days'])return['action'=>'SELL','code'=>'ENGINE_MAX_HOLDING','reason'=>'ENGINE_MAX_HOLDING','risk_exit'=>true,'hold_days'=>$days,'return_pct'=>round($ret,3)];
    $peakRet=($peak/$entry-1.0)*100.0;$fromPeak=($price/$peak-1.0)*100.0;
    if(!empty($policy['engine_trailing'])&&$peakRet>=(float)$policy['trail_arm_pct']&&$fromPeak<=-(float)$policy['trail_stop_pct'])return['action'=>'SELL','code'=>'ENGINE_TRAILING_STOP','reason'=>'ENGINE_TRAILING_STOP','risk_exit'=>true];
    return['action'=>'HOLD'];
}

function te_effective_initial_stop(float $entry,float $storedStop,float $maxStopPct): float
{
    if($entry<=0)return 0.0;
    $fallback=$entry*0.95;$bounded=$maxStopPct>0?$entry*(1.0-$maxStopPct/100.0):$fallback;
    if($storedStop<=0||$storedStop>=$entry)return$bounded;
    return max($storedStop,$bounded);
}

function te_pct_text(float $value): string
{
    return rtrim(rtrim(number_format($value,2,'.',''),'0'),'.');
}

/** 진입가 대비 현재 수익률. 전략별 중복 계산을 제거한다. */
function te_profit_return_pct(float $entry,float $price): float
{
    return $entry>0&&$price>0?($price/$entry-1.0)*100.0:0.0;
}

/** 기술적 이익청산이 작은 수익을 너무 일찍 자르지 않도록 최소 수익을 확인한다. */
function te_profit_gate(float $entry,float $price,float $minimumPct): bool
{
    return $entry>0&&$price>0&&te_profit_return_pct($entry,$price)+1.0e-9>=max(0.0,$minimumPct);
}

/** 수익 추적손절의 공통 계산. arm 이전에는 어떤 하락도 청산하지 않는다. */
function te_trailing_exit_pct(float $entry,float $peak,float $price,float $armPct,float $dropPct,string $code,string $reason): array
{
    if($entry<=0||$peak<=0||$price<=0)return['action'=>'HOLD'];
    $peakRet=te_profit_return_pct($entry,$peak);
    $fromPeak=($price/$peak-1.0)*100.0;
    if($peakRet>=max(0.0,$armPct)&&$fromPeak<=-max(0.1,$dropPct)){
        return['action'=>'SELL','code'=>$code,'reason'=>$reason,'peak_return_pct'=>round($peakRet,3),'from_peak_pct'=>round($fromPeak,3)];
    }
    return['action'=>'HOLD','peak_return_pct'=>round($peakRet,3),'from_peak_pct'=>round($fromPeak,3)];
}

function te_progressive_loss_exit(array $policy,int $days,float $returnPct): array
{
    if(empty($policy['loss_cut_ladder']))return['action'=>'HOLD'];
    $earlyDays=(int)$policy['loss_cut_early_days'];$earlyPct=(float)$policy['loss_cut_early_pct'];
    if($days<=$earlyDays&&$returnPct<=-$earlyPct)return['action'=>'SELL','code'=>'ENGINE_EARLY_LOSS','reason'=>'ENGINE_EARLY_LOSS | 0~'.$earlyDays.'일차 -'.te_pct_text($earlyPct).'% 이하','risk_exit'=>true,'hold_days'=>$days,'return_pct'=>round($returnPct,3),'threshold_pct'=>-$earlyPct];
    $stalledFrom=(int)$policy['loss_cut_stalled_from_day'];$stalledTo=(int)$policy['loss_cut_stalled_to_day'];$stalledPct=(float)$policy['loss_cut_stalled_pct'];
    if($days>=$stalledFrom&&$days<=$stalledTo&&$returnPct<=-$stalledPct)return['action'=>'SELL','code'=>'ENGINE_STALLED_LOSS','reason'=>'ENGINE_STALLED_LOSS | '.$stalledFrom.'~'.$stalledTo.'일차 -'.te_pct_text($stalledPct).'% 이하','risk_exit'=>true,'hold_days'=>$days,'return_pct'=>round($returnPct,3),'threshold_pct'=>-$stalledPct];
    $noProgressDay=(int)$policy['loss_cut_no_progress_day'];$noProgressPct=(float)$policy['loss_cut_no_progress_pct'];
    if($days>=$noProgressDay&&$returnPct<-$noProgressPct)return['action'=>'SELL','code'=>'ENGINE_NO_PROGRESS','reason'=>'ENGINE_NO_PROGRESS | '.$noProgressDay.'일차 이후 수익 전환 실패','risk_exit'=>true,'hold_days'=>$days,'return_pct'=>round($returnPct,3),'threshold_pct'=>-$noProgressPct];
    return['action'=>'HOLD'];
}

function te_scan_commit_batch(array $c,string $m,array $batch): void
{
    $f=(string)($batch['cursor_file']??te_scan_cursor_file($c,$m));te_save($f,['next'=>(int)($batch['next']??0),'total'=>(int)($batch['total']??0),'session'=>(string)($batch['session']??te_session_date($m)),'data_date'=>(string)($batch['data_date']??''),'cycle_id'=>!empty($batch['wrapped'])?'':(string)($batch['cycle_id']??''),'updated_at'=>te_now()]);
}
function te_scan_reset_cursor(array $c,string $m,string $dataDate=''): void{te_save(te_scan_cursor_file($c,$m),['next'=>0,'total'=>0,'session'=>te_session_date($m),'data_date'=>$dataDate,'cycle_id'=>'','updated_at'=>te_now()]);}

function te_scan_stage_load(array $c): array
{
    $x=te_load($c['scan_stage_file'],[]);if(is_array($x)&&isset($x['markets'])&&is_array($x['markets']))return$x;return['schema'=>'scan_stage_v1','updated_at'=>te_now(),'markets'=>['KR'=>te_candidate_market_empty('KR'),'US'=>te_candidate_market_empty('US'),'JP'=>te_candidate_market_empty('JP')]];
}
function te_scan_stage_prepare(array $stage,string $m,array $batch,string $dataDate): array
{
    $cycle=(string)($batch['cycle_id']??'');$old=is_array($stage['markets'][$m]??null)?$stage['markets'][$m]:[];
    if((string)($old['scan_cycle_id']??'')!==$cycle||(string)($old['data_date']??'')!==$dataDate){$stage['markets'][$m]=array_merge(te_candidate_market_empty($m),['scan_cycle_id'=>$cycle,'data_date'=>$dataDate,'total'=>(int)($batch['total']??0),'rows'=>[],'updated_at'=>te_now()]);}
    return$stage;
}
function te_scan_stage_put(array $stage,string $m,array $row): array
{
    $symbol=(string)($row['symbol']??'');if($symbol==='')return$stage;if(!isset($stage['markets'][$m])||!is_array($stage['markets'][$m]))$stage['markets'][$m]=te_candidate_market_empty($m);$stage['markets'][$m]['rows'][$symbol]=$row;$stage['markets'][$m]['updated_at']=te_now();$stage['updated_at']=te_now();return$stage;
}
function te_scan_stage_validate(array $stage,string $m,array $batch,string $dataDate): array
{
    $x=is_array($stage['markets'][$m]??null)?$stage['markets'][$m]:[];$rows=is_array($x['rows']??null)?$x['rows']:[];$total=(int)($batch['total']??0);$cycle=(string)($batch['cycle_id']??'');
    if($dataDate==='')return['ok'=>false,'reason'=>'BENCHMARK_DATE_MISMATCH EMPTY'];if((string)($x['scan_cycle_id']??'')!==$cycle)return['ok'=>false,'reason'=>'CYCLE_MISMATCH'];if(count($rows)!==$total)return['ok'=>false,'reason'=>'SCAN_COUNT_MISMATCH '.count($rows).'/'.$total];
    $dates=[];$symbols=[];foreach($rows as$r){if(!is_array($r))return['ok'=>false,'reason'=>'INVALID_ROW'];if((string)($r['scan_cycle_id']??'')!==$cycle)return['ok'=>false,'reason'=>'ROW_CYCLE_MISMATCH'];$symbol=(string)($r['symbol']??'');if($symbol==='')return['ok'=>false,'reason'=>'INVALID_SYMBOL'];if(isset($symbols[$symbol]))return['ok'=>false,'reason'=>'DUPLICATE_SYMBOL '.$symbol];$symbols[$symbol]=true;$d=(string)($r['scan_data_date']??'');if($d==='')return['ok'=>false,'reason'=>'SCAN_DATE_EMPTY '.$symbol];$dates[$d]=true;}
    if(count($dates)!==1)return['ok'=>false,'reason'=>'SCAN_DATE_MIXED '.implode(',',array_keys($dates))];$actual=(string)array_key_first($dates);if($actual!==$dataDate)return['ok'=>false,'reason'=>'BENCHMARK_DATE_MISMATCH '.$actual.'!='.$dataDate,'actual_date'=>$actual,'benchmark_date'=>$dataDate];
    return['ok'=>true,'reason'=>'OK','actual_date'=>$actual,'benchmark_date'=>$dataDate];
}
function te_scan_stage_clear(array $stage,string $m): array{$stage['markets'][$m]=te_candidate_market_empty($m);$stage['updated_at']=te_now();return$stage;}

function te_error_candidate(array $item, string $reason, array $batch, string $dataDate, array $quote = []): array
{
    $code = strtoupper(trim((string)strtok($reason, ' :,')));
    if ($code === '') $code = 'RUNTIME_ERROR';
    $status = te_candidate_display_status('FILTERED', $code);
    $labels = [
        'DATA_SHORT'=>'데이터 부족',
        'DATA_STALE'=>'데이터 지연',
        'DATA_SOURCE_MISMATCH'=>'데이터 출처 불일치',
        'DATA_DATE_MISMATCH'=>'데이터 기준일 불일치',
        'BENCHMARK_DATE_MISMATCH'=>'기준지수 날짜 불일치',
        'QUOTE_ERROR'=>'시세 조회 오류',
        'MODEL_EXCEPTION'=>'전략 계산 오류',
        'MODEL_INVALID'=>'전략 반환 오류',
    ];
    return [
        'time'=>te_now(),
        'market'=>(string)($item['market'] ?? ''),
        'symbol'=>(string)($item['symbol'] ?? ''),
        'name'=>(string)($item['name'] ?? ''),
        'status'=>$status,
        'model_status'=>'FILTERED',
        'type'=>$code,
        'score'=>0,
        'price'=>(float)($quote['price'] ?? 0),
        'entry_price'=>0.0,
        'stop_price'=>0.0,
        'target_price'=>0.0,
        'reason'=>(string)($labels[$code] ?? '데이터 처리 오류'),
        'block_reason'=>$reason,
        'metrics'=>[
            'market'=>(string)($item['market'] ?? ''),
            'symbol'=>(string)($item['symbol'] ?? ''),
            'market_date'=>$dataDate,
            'data_status'=>$status,
            'data_code'=>$code,
            'ranking_eligible'=>false,
            'structural_eligible'=>false,
            'reason_codes'=>[$code],
            'data_detail'=>$reason,
        ],
        'scan_cycle_id'=>(string)($batch['cycle_id'] ?? ''),
        'scan_data_date'=>$dataDate,
        'scan_session_date'=>te_session_date((string)($item['market'] ?? '')),
        'updated_at'=>te_now(),
    ];
}


function te_candidate_book_subset(array $book,string $market,array $symbols): array
{
    $out=['schema'=>(string)($book['schema']??TE_SCHEMA),'updated_at'=>te_now(),'markets'=>[]];
    $src=is_array($book['markets'][$market]??null)?$book['markets'][$market]:te_candidate_market_empty($market);
    $out['markets'][$market]=array_merge($src,['rows'=>[]]);
    $wanted=array_fill_keys(array_values(array_filter(array_map('strval',$symbols),static function($v){return$v!=='';})),true);
    $rows=is_array($src['rows']??null)?$src['rows']:[];
    foreach($rows as$symbol=>$row)if(isset($wanted[(string)$symbol]))$out['markets'][$market]['rows'][(string)$symbol]=$row;
    return$out;
}
function te_candidate_book_merge_market(array $base,array $patch,string $market): array
{
    if(!isset($base['markets'][$market])||!is_array($base['markets'][$market]))$base['markets'][$market]=te_candidate_market_empty($market);
    $rows=is_array($patch['markets'][$market]['rows']??null)?$patch['markets'][$market]['rows']:[];
    foreach($rows as$symbol=>$row)$base['markets'][$market]['rows'][(string)$symbol]=$row;
    $base['markets'][$market]['updated_at']=te_now();$base['updated_at']=te_now();return$base;
}
function te_candidate_live_batch_update(array $c,array $display,array $stage,string $market,array $symbols,array $batch,string $dataDate,array $status): array
{
    if(!isset($display['markets'][$market])||!is_array($display['markets'][$market]))$display['markets'][$market]=te_candidate_market_empty($market);
    $src=is_array($stage['markets'][$market]['rows']??null)?$stage['markets'][$market]['rows']:[];
    foreach($symbols as$symbol)if($symbol!==''&&isset($src[$symbol]))$display['markets'][$market]['rows'][$symbol]=$src[$symbol];
    $display['markets'][$market]=array_merge($display['markets'][$market],[
        'market'=>$market,'data_date'=>$dataDate,'basis'=>(string)($c['candidate_basis']??'INTRADAY_ROTATING'),
        'frozen'=>false,'captured_at'=>te_now(),'scan_cycle_id'=>(string)($batch['cycle_id']??''),
        'last_batch'=>['start'=>(int)($batch['start']??0),'next'=>(int)($batch['next']??0),'count'=>count($symbols),'updated_at'=>te_now()],
        'updated_at'=>te_now(),
    ]);
    $display['updated_at']=te_now();return$display;
}

function te_candidate_promote_market(array $c,array $display,array $stage,string $m,array $batch,string $dataDate,array $status): array
{
    $x=is_array($stage['markets'][$m]??null)?$stage['markets'][$m]:te_candidate_market_empty($m);$completed=['cycle_id'=>(string)($batch['cycle_id']??''),'data_date'=>$dataDate,'total'=>(int)($batch['total']??0),'completed_at'=>te_now()];
    $display['markets'][$m]=array_merge($x,['market'=>$m,'data_date'=>$dataDate,'basis'=>(string)($c['candidate_basis']??'FINAL_CLOSE'),'frozen'=>false,'captured_at'=>te_now(),'promoted_session'=>te_session_date($m),'scan_cycle_id'=>(string)($batch['cycle_id']??''),'last_completed_scan'=>$completed,'updated_at'=>te_now()]);$display['updated_at']=te_now();return$display;
}
function te_candidate_book_refresh_basis(array $c,array $book, array $marketStatus, array $regimes, array $scanProgress = []): array
{
    foreach (['KR','US','JP'] as $market) {
        if (!isset($book['markets'][$market]) || !is_array($book['markets'][$market])) {
            $book['markets'][$market] = te_candidate_market_empty($market);
        }
        $rowCount = count(is_array($book['markets'][$market]['rows'] ?? null) ? $book['markets'][$market]['rows'] : []);
        $scanOpen = te_market_flag($marketStatus,$market,'scan_open');
        $tradeOpen = te_market_flag($marketStatus,$market,'trade_open');
        $progress = is_array($scanProgress['markets'][$market] ?? null) ? $scanProgress['markets'][$market] : [];
        if ($rowCount === 0) {
            $book['markets'][$market]['basis'] = $scanOpen ? 'NOT_INITIALIZED' : 'NO_SNAPSHOT';
            $book['markets'][$market]['frozen'] = true;
        } elseif (($c['admission_mode']??'FULL_SCAN')==='PER_BATCH') {
            $book['markets'][$market]['basis'] = $scanOpen ? (string)($c['candidate_basis']??'INTRADAY_ROTATING') : 'CARRIED_INTRADAY_SNAPSHOT';
            $book['markets'][$market]['frozen'] = !$tradeOpen;
        } elseif ($scanOpen && empty($progress['cycle_complete'])) {
            $book['markets'][$market]['basis'] = 'CARRIED_PREVIOUS_CLOSE';
            $book['markets'][$market]['frozen'] = true;
        } else {
            $book['markets'][$market]['basis'] = 'FINAL_CLOSE';
            $book['markets'][$market]['frozen'] = !$tradeOpen;
        }
        $book['markets'][$market]['updated_at'] = te_now();
    }
    $book['updated_at'] = te_now();
    return $book;
}



function te_scan_progress_apply_counts(array $progress, string $market, array $stage, bool $cycleComplete = false, bool $isRanked = false): array
{
    $rows = is_array($stage['markets'][$market]['rows'] ?? null) ? $stage['markets'][$market]['rows'] : [];
    $counts = [
        'processed_count'=>count($rows),
        'data_error_count'=>0,
        'data_short_count'=>0,
        'data_stale_count'=>0,
        'filtered_count'=>0,
        'watch_count'=>0,
        'buy_signal_count'=>0,
        'buy_intent_count'=>0,
        'sell_intent_count'=>(int)($progress['markets'][$market]['sell_intent_count'] ?? 0),
    ];
    $rankedCounts = ['ranking_eligible_count'=>0,'hard_filter_pass_count'=>0,'structural_candidate_count'=>0,'score_pass_count'=>0,'rank_selected_count'=>0,'final_candidate_count'=>0];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $status = strtoupper((string)($row['status'] ?? ''));
        $modelStatus = strtoupper((string)($row['model_status'] ?? $status));
        if ($status === 'DATA_SHORT') $counts['data_short_count']++;
        elseif ($status === 'DATA_STALE') $counts['data_stale_count']++;
        elseif (in_array($status, ['DATA_ERROR','DATA_DATE_MISMATCH'], true)) $counts['data_error_count']++;
        elseif ($modelStatus === 'BUY') $counts['buy_signal_count']++;
        elseif ($modelStatus === 'WATCH') $counts['watch_count']++;
        else $counts['filtered_count']++;
        if ($status === 'BUY_PENDING' || !empty($row['order_id'])) $counts['buy_intent_count']++;
        if ($isRanked) {
            $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
            if (!empty($metrics['ranking_eligible'])) $rankedCounts['ranking_eligible_count']++;
            if (!empty($metrics['ranking_eligible'])) $rankedCounts['hard_filter_pass_count']++;
            if (!empty($metrics['structural_eligible'])) $rankedCounts['structural_candidate_count']++;
            if (!empty($metrics['score_pass'])) $rankedCounts['score_pass_count']++;
            if (!empty($metrics['rank_selected']) || !empty($row['rank_selected'])) $rankedCounts['rank_selected_count']++;
        }
    }
    $counts['cycle_complete'] = $cycleComplete;
    $counts['scan_complete'] = $cycleComplete;
    if ($cycleComplete) $counts['scan_completed_at'] = te_now();
    if ($isRanked) {
        $rankedCounts['final_candidate_count'] = $counts['buy_intent_count'];
        $counts = array_merge($counts, $rankedCounts);
    }
    if (!isset($progress['markets'][$market]) || !is_array($progress['markets'][$market])) $progress['markets'][$market] = [];
    $progress['markets'][$market] = array_merge($progress['markets'][$market], $counts);
    $progress['updated_at'] = te_now();
    return $progress;
}



function te_validation_bar_identity(array $ctx,string $timeframe,string $market): string{$bars=is_array($ctx['bars'][$timeframe]??null)?$ctx['bars'][$timeframe]:[];if(!$bars)return(string)($ctx['market_date']??$ctx['session_date']??'');$b=$bars[count($bars)-1];if(isset($b['ts'])&&is_numeric($b['ts']))return(string)(int)$b['ts'];$time=trim((string)($b['time']??$b['datetime']??$b['date']??''));return$time!==''?$time:(string)($ctx['market_date']??'');}
function te_validation_register_model_signal(array $c,array $ctx,array $signal,string $side='BUY',string $origin='MODEL_SIGNAL'): array
{
    return tv_register_signal($c,$ctx,$signal,$side,$origin);
}

function te_validation_register_exit_signal(array $c,array $ctx,array $exit,array $position): array{$signal=['type'=>(string)($exit['code']??'SELL'),'entry_price'=>(float)($ctx['quote']['price']??$position['current_price']??0),'metrics'=>['decision_price'=>(float)($ctx['quote']['price']??$position['current_price']??0)]];return te_validation_register_model_signal($c,$ctx,$signal,'SELL',!empty($exit['risk_exit'])?'RISK_EXIT':'STRATEGY_EXIT');}
function te_validation_register_ranked_signals(array $c,string $market,array $rows,array $batch): array
{
    return tv_register_ranked($c,$market,$rows,$batch);
}

function te_validation_sync_runtime(array $c,array $candidateBook,array $orders,array $trades): array
{
    return tv_sync_runtime($c,$candidateBook,$orders,$trades);
}

function te_validation_bar_ts(array $bar,string $market): int{if(isset($bar['ts'])&&is_numeric($bar['ts']))return(int)$bar['ts'];$raw=(string)($bar['time']??$bar['datetime']??$bar['date']??'');if($raw==='')return 0;try{$tz=new DateTimeZone(te_market_timezone($market));$d=new DateTime($raw,$tz);return$d->getTimestamp();}catch(Throwable $e){return 0;}}
function te_validation_price_result(array $sample,string $label,float $price,float $high,float $low,string $resolvedOn): array{$signal=(float)$sample['signal_price'];$side=(string)$sample['side'];if($signal<=0||$price<=0)return[];$gross=$side==='SELL'?($signal/$price-1.0)*100.0:($price/$signal-1.0)*100.0;$mfe=$side==='SELL'?($low>0?($signal/$low-1.0)*100.0:0.0):($high/$signal-1.0)*100.0;$mae=$side==='SELL'?($high>0?($signal/$high-1.0)*100.0:0.0):($low/$signal-1.0)*100.0;$cost=te_validation_roundtrip_cost_pct((string)$sample['market']);$net=$gross-$cost;$hit=$gross>0;return['label'=>$label,'resolved_on'=>$resolvedOn,'price'=>round($price,6),'gross_return_pct'=>round($gross,6),'net_return_pct'=>round($net,6),'cost_1_5x_return_pct'=>round($gross-$cost*1.5,6),'cost_2x_return_pct'=>round($gross-$cost*2.0,6),'mfe_pct'=>round($mfe,6),'mae_pct'=>round($mae,6),'direction_hit'=>$hit,'roundtrip_cost_pct'=>$cost];}
function te_validation_roundtrip_cost_pct(string $market): float{if($market==='KR')return round((TE_KR_BUY_FEE+TE_KR_SELL_COST)*100.0,6);if($market==='JP')return round((TE_JP_BUY_FEE+TE_JP_SELL_COST)*100.0,6);return round((TE_US_BUY_FEE+TE_US_SELL_COST)*100.0,6);}
function te_validation_daily_close_ts(string $date,string $market): int
{
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))return 0;
    $closeTime=$market==='US'?'16:00:00':($market==='JP'?'15:30:00':'15:30:00');
    try{$tz=new DateTimeZone(te_market_timezone($market));$d=new DateTime($date.' '.$closeTime,$tz);return$d->getTimestamp();}catch(Throwable $e){return 0;}
}
function te_validation_daily_future_bars(array $bars,array $sample,string $market): array
{
    $signalTs=(int)($sample['signal_ts']??0);$signalDate=(string)($sample['signal_market_date']??$sample['signal_session_date']??'');$future=[];
    foreach($bars as$bar){
        if(!is_array($bar))continue;$d=te_last_bar_date([$bar],$market);if($d==='')continue;$closeTs=te_validation_daily_close_ts($d,$market);
        if($closeTs>0&&$signalTs>0){if($closeTs<=$signalTs)continue;}elseif($signalDate!==''&&$d<$signalDate)continue;
        $future[]=$bar;
    }
    return$future;
}
function te_validation_resolve_horizon(array $c,array $sample,array $h,array $status): ?array
{
    $market=(string)$sample['market'];
    $item=['market'=>$market,'symbol'=>(string)$sample['symbol'],'name'=>(string)($sample['name']??$sample['symbol']),'exchange'=>(string)($sample['exchange']??'')];
    $type=(string)$h['type'];$label=(string)$h['label'];
    if($type==='MINUTES'){
        $due=(int)$h['due_ts'];if(time()<$due)return null;
        $bars=te_completed(te_bars($c,$item,'5d','1m',$status),'1m',$market,$status);
        $future=[];foreach($bars as$bar){$ts=te_validation_bar_ts($bar,$market);if($ts>=(int)$sample['signal_ts']&&$ts<=$due+86400)$future[]=$bar;}
        if(!$future)return null;
        $target=null;foreach($future as$bar)if(te_validation_bar_ts($bar,$market)>=$due){$target=$bar;break;}
        if(!$target)return null;
        $targetTs=te_validation_bar_ts($target,$market);$window=[];foreach($future as$bar)if(te_validation_bar_ts($bar,$market)<=$targetTs)$window[]=$bar;
        if(!$window)return null;
        $highs=array_map(static function($b){return(float)($b['high']??$b['close']??0);},$window);
        $lows=array_values(array_filter(array_map(static function($b){return(float)($b['low']??$b['close']??0);},$window),static function($v){return$v>0;}));
        if(!$highs||!$lows)return null;
        return te_validation_price_result($sample,$label,(float)($target['close']??0),max($highs),min($lows),date('c',$targetTs));
    }
    $bars=te_completed(te_bars($c,$item,'3y','1d',$status),'1d',$market,$status);if(!$bars)return null;
    $future=te_validation_daily_future_bars($bars,$sample,$market);
    if(!$future)return null;
    $targetIndex=$type==='SESSION_CLOSE'?0:max(0,(int)$h['value']-1);if(!isset($future[$targetIndex]))return null;
    $window=array_slice($future,0,$targetIndex+1);$target=$future[$targetIndex];
    $highs=array_map(static function($b){return(float)($b['high']??$b['close']??0);},$window);
    $lows=array_values(array_filter(array_map(static function($b){return(float)($b['low']??$b['close']??0);},$window),static function($v){return$v>0;}));
    if(!$highs||!$lows)return null;
    return te_validation_price_result($sample,$label,(float)($target['close']??0),max($highs),min($lows),te_last_bar_date([$target],$market));
}
function te_validation_resolve_due_samples(array $c,array $status,bool $force=false): array
{
    return tv_resolve_due($c,$status,$force);
}

function te_validation_status(array $c): array
{
    return tv_status($c);
}

function te_entry_guard(array $c,array $ctx,array $signal,array $candidate=[]): array
{
    $callback=(string)($c['entry_guard_callback']??'');$result=['ok'=>true,'signal'=>$signal,'reason'=>'NO_ENTRY_GUARD'];
    if($callback!==''&&!function_exists($callback))return['ok'=>false,'signal'=>$signal,'reason'=>'ENTRY_GUARD_CALLBACK_MISSING'];
    if($callback!==''){
        try{$result=$callback($ctx,$signal,$candidate);if(!is_array($result))return['ok'=>false,'signal'=>$signal,'reason'=>'ENTRY_GUARD_INVALID_RESULT'];if(!array_key_exists('ok',$result))$result['ok']=false;if(!isset($result['signal'])||!is_array($result['signal']))$result['signal']=$signal;if(!isset($result['reason']))$result['reason']=$result['ok']?'OK':'ENTRY_GUARD_REJECTED';}
        catch(Throwable $e){te_log($c['error_file'],'ENTRY_GUARD '.$e->getMessage());return['ok'=>false,'signal'=>$signal,'reason'=>'ENTRY_GUARD_EXCEPTION'];}
    }
    if(empty($result['ok']))return$result;
    $guarded=is_array($result['signal']??null)?$result['signal']:$signal;$result['signal']=$guarded;
    if(strtoupper((string)($guarded['status']??''))==='BUY'&&te_normalize_sizing_mode((string)($c['sizing_mode']??'RISK_STOP'))==='RISK_STOP'){
        $stopPct=te_signal_stop_pct($guarded);$maxStopPct=(float)($c['risk_policy']['max_initial_stop_pct']??0.0);
        if($maxStopPct>0&&$stopPct>$maxStopPct)return['ok'=>false,'signal'=>$guarded,'reason'=>'INITIAL_STOP_TOO_WIDE '.round($stopPct,2).'>'.round($maxStopPct,2),'stop_pct'=>$stopPct,'max_stop_pct'=>$maxStopPct];
        $rr=te_signal_rr($guarded);if((float)$c['min_entry_rr']>0&&$rr<(float)$c['min_entry_rr'])return['ok'=>false,'signal'=>$guarded,'reason'=>'MIN_RR_NOT_MET '.round($rr,2),'rr'=>$rr];
    }
    return$result;
}

function te_signal_stop_pct(array $signal): float
{
    $entry=(float)($signal['entry_price']??0);$stop=(float)($signal['stop_price']??0);return$entry>0&&$stop>0&&$stop<$entry?($entry-$stop)/$entry*100.0:0.0;
}

function te_signal_rr(array $signal): float
{
    $entry=(float)($signal['entry_price']??0);$stop=(float)($signal['stop_price']??0);$target=(float)($signal['target_price']??0);$risk=$entry-$stop;$reward=$target-$entry;return$risk>0&&$reward>0?$reward/$risk:0.0;
}

function te_validate_signal(array $signal,array $c=[]): array
{
    $status=strtoupper((string)($signal['status']??''));$allowed=['BUY','WATCH','FILTERED','ARM','CANCEL','HOLD'];$errors=[];
    if(!in_array($status,$allowed,true))$errors[]='INVALID_STATUS';
    if(!isset($signal['state'])||!is_array($signal['state']))$signal['state']=[];if(!isset($signal['metrics'])||!is_array($signal['metrics']))$signal['metrics']=[];
    $signal['status']=$status;$signal['type']=(string)($signal['type']??'');$signal['score']=(float)($signal['score']??0);$signal['entry_price']=(float)($signal['entry_price']??0);$signal['stop_price']=(float)($signal['stop_price']??0);$signal['target_price']=(float)($signal['target_price']??0);$signal['reason']=(string)($signal['reason']??'');$signal['block_reason']=(string)($signal['block_reason']??'');
    $contract=te_normalize_entry_contract((string)($c['entry_contract']??$signal['metrics']['entry_contract']??'STOP_BASED'));$sizing=te_normalize_sizing_mode((string)($c['sizing_mode']??$signal['metrics']['sizing_mode']??($contract==='STOP_BASED'?'RISK_STOP':'ALLOCATION_ONLY')));
    if($status==='BUY'){
        if($signal['type']==='')$errors[]='BUY_TYPE_MISSING';if($signal['entry_price']<=0)$errors[]='BUY_ENTRY_INVALID';
        if($sizing==='RISK_STOP'&&($signal['stop_price']<=0||$signal['stop_price']>=$signal['entry_price']))$errors[]='BUY_STOP_INVALID';
        if($signal['target_price']>0&&$signal['target_price']<=$signal['entry_price'])$errors[]='BUY_TARGET_INVALID';
    }
    $signal['metrics']['entry_contract']=$contract;$signal['metrics']['sizing_mode']=$sizing;
    return['ok'=>!$errors,'signal'=>$signal,'errors'=>$errors];
}

function te_validate_exit_result($exit): array
{
    if(!is_array($exit))return['action'=>'HOLD','code'=>'EXIT_INVALID','reason'=>'청산 콜백 반환값 오류'];
    $action=strtoupper((string)($exit['action']??'HOLD'));
    if(!in_array($action,['HOLD','SELL'],true))return['action'=>'HOLD','code'=>'EXIT_INVALID_ACTION','reason'=>'청산 action 오류'];
    $exit['action']=$action;$exit['code']=(string)($exit['code']??($action==='SELL'?'STRATEGY_EXIT':'HOLD'));$exit['reason']=(string)($exit['reason']??$exit['code']);
    return$exit;
}

function te_risk_policy($raw): array
{
    $base=[
        'initial_stop'=>false,'max_initial_stop_pct'=>0.0,
        'portfolio_daily_loss'=>true,'daily_loss_liquidate'=>false,'portfolio_drawdown'=>true,'portfolio_drawdown_liquidate'=>false,'hard_target'=>false,
        'loss_cut_ladder'=>false,'loss_cut_early_days'=>3,'loss_cut_early_pct'=>3.0,
        'loss_cut_stalled_from_day'=>4,'loss_cut_stalled_to_day'=>5,'loss_cut_stalled_pct'=>1.5,
        'loss_cut_no_progress_day'=>6,'loss_cut_no_progress_pct'=>0.0,
        'engine_trailing'=>false,'trail_arm_pct'=>8.0,'trail_stop_pct'=>5.0,'max_holding'=>false,'max_holding_days'=>120,
    ];
    if(!is_array($raw))return$base;
    // v3.2 이전 설정을 최소한으로 호환하고 실제 판단은 하나의 ladder 로직만 사용한다.
    if(array_key_exists('early_loss_guard',$raw)&&!array_key_exists('loss_cut_ladder',$raw))$raw['loss_cut_ladder']=(bool)$raw['early_loss_guard'];
    if(array_key_exists('early_loss_days',$raw)&&!array_key_exists('loss_cut_early_days',$raw))$raw['loss_cut_early_days']=$raw['early_loss_days'];
    if(array_key_exists('early_loss_pct',$raw)&&!array_key_exists('loss_cut_early_pct',$raw))$raw['loss_cut_early_pct']=$raw['early_loss_pct'];
    foreach($base as$k=>$v)if(array_key_exists($k,$raw))$base[$k]=is_bool($v)?(bool)$raw[$k]:(is_int($v)?max(0,(int)$raw[$k]):max(0.0,(float)$raw[$k]));
    if($base['loss_cut_stalled_to_day']<$base['loss_cut_stalled_from_day'])$base['loss_cut_stalled_to_day']=$base['loss_cut_stalled_from_day'];
    if($base['loss_cut_no_progress_day']<=$base['loss_cut_stalled_to_day'])$base['loss_cut_no_progress_day']=$base['loss_cut_stalled_to_day']+1;
    return$base;
}

function te_business_days_between(string $from,string $to,string $market,array $c): int
{
    $a=DateTime::createFromFormat('!Y-m-d',$from,new DateTimeZone(te_market_timezone($market)));$b=DateTime::createFromFormat('!Y-m-d',$to,new DateTimeZone(te_market_timezone($market)));
    if(!$a||!$b||$b<$a)return 0;$calendar=te_market_calendar((string)($c['calendar_file']??''));$days=0;
    while($a<$b){$a->modify('+1 day');$date=$a->format('Y-m-d');if((int)$a->format('N')<=5&&!in_array($date,$calendar[$market]['holidays']??[],true))$days++;}
    return$days;
}

function te_reconciliation_ok(array $c,array $currentPositions,string $market,string $symbol): bool
{
    $virtual=0;
    foreach($c['compare_models']as$key=>$m){if(!is_array($m))continue;$runtime=(string)($m['runtime']??'');$rows=$runtime===$c['runtime']?$currentPositions:te_positions_normalize(te_load($runtime.'/positions.json',[]));$k=$market.':'.$symbol;if(isset($rows[$k])&&te_active_position($rows[$k])&&strtoupper((string)($rows[$k]['execution_mode']??'PAPER'))==='REAL')$virtual+=(int)($rows[$k]['qty']??0);}
    if($virtual<1)return true;
    $snap=te_load($c['broker_account_file'],[]);$ts=strtotime((string)($snap['updated_at']??''));if(!is_array($snap)||$ts===false||time()-$ts>900)return false;
    $holdings=$snap['markets'][$market]['holdings']??null;if(!is_array($holdings))return false;$actual=0;
    foreach($holdings as$h){if(!is_array($h))continue;if(strtoupper((string)($h['symbol']??''))===strtoupper($symbol)){$actual=(int)($h['available_qty']??$h['qty']??0);break;}}
    return$virtual<=$actual;
}


function te_order_identity(array $r): string{return strtolower((string)($r['strategy_key']??$r['strategy_id']??'')).':'.strtoupper((string)($r['market']??'')).':'.strtoupper((string)($r['symbol']??'')).':'.strtoupper((string)($r['side']??''));}
function te_broker_order_active(array $o): bool{return in_array(strtoupper((string)($o['status']??'')),['PENDING','APPROVED','SENT','PARTIAL','WORKING','CANCEL_REQUESTED','OPEN'],true);}
function te_intent_row_active(array $i,array $brokerOrders=[]): bool
{
    $status=strtoupper((string)($i['status']??'INTENT_CREATED'));if(in_array($status,['REJECTED','CANCELLED','EXPIRED','BROKER_INGEST_MISSING','BROKER_ORDER_MISSING_AFTER_INGEST','FILLED','PAPER_FILLED'],true))return false;if($status==='BROKER_ACK_WAIT')return true;$id=(string)($i['order_id']??'');foreach($brokerOrders as$o)if(is_array($o)&&(string)($o['order_id']??'')===$id)return te_broker_order_active($o);if(strtoupper((string)($i['side']??''))==='SELL')return true;$exp=strtotime((string)($i['order_expires_at']??$i['expires_at']??''));return$exp!==false&&$exp>=time();
}
function te_context_data_date(array $ctx,string $market,string $preferredTimeframe=''): string
{
    $all=is_array($ctx['bars']??null)?$ctx['bars']:[];
    $order=[];
    if($preferredTimeframe!=='')$order[]=$preferredTimeframe;
    if(!in_array('1d',$order,true))$order[]='1d';
    foreach(array_keys($all)as$tf)if(!in_array((string)$tf,$order,true))$order[]=(string)$tf;
    foreach($order as$tf){$bars=is_array($all[$tf]??null)?$all[$tf]:[];$date=te_last_bar_date($bars,$market);if($date!=='')return$date;}
    return'';
}
function te_strategy_scan_date(array $c,string $market,array $status,string $benchmarkDate=''): string
{
    $mode=strtoupper((string)($c['scan_date_mode']??'COMPLETED_DAILY'));
    if($mode==='SESSION_DATE')return te_session_date($market);
    if($mode==='CONTEXT_DATE')return $benchmarkDate!==''?$benchmarkDate:te_session_date($market);
    $expected=te_expected_completed_date($market,$status,$c);
    return$expected!==''?$expected:$benchmarkDate;
}
function te_scan_date_relation(string $actualDate,string $scanDate): string{if($actualDate===''||$scanDate==='')return'MISSING';if($actualDate>$scanDate)return'AHEAD';if($actualDate<$scanDate)return'STALE';return'MATCH';}
function te_last_bar_date(array $bars,string $market): string{if(!$bars)return'';$b=$bars[count($bars)-1];$d=substr((string)($b['time']??''),0,10);if($d===''&&isset($b['ts']))$d=te_date_tz((int)$b['ts'],$market);return preg_match('/^\d{4}-\d{2}-\d{2}$/',$d)?$d:'';}
function te_clean_reason_codes(array $codes): array{$drop=['SCAN_INCOMPLETE','ENGINE_RANK_CALLBACK_UNSUPPORTED'];$out=[];foreach($codes as$c){$c=(string)$c;if($c!==''&&!in_array($c,$drop,true)&&!in_array($c,$out,true))$out[]=$c;}return$out;}
function te_price_scale_suspect(array $c,array $ctx,float $signal,float $current): bool{$max=(float)($c['price_scale_max_multiple']??3.0);$values=[$signal,$current];$bars=is_array($ctx['bars']['1d']??null)?$ctx['bars']['1d']:[];if($bars){$last=$bars[count($bars)-1];$values[]=(float)($last['close']??0);}for($i=0;$i<count($values);$i++)for($j=$i+1;$j<count($values);$j++){if($values[$i]<=0||$values[$j]<=0)continue;$r=max($values[$i],$values[$j])/min($values[$i],$values[$j]);if($r>$max)return true;}return false;}
function te_mark_benchmark_target(array $c,string $market,string $date): void{if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))return;$s=te_load($c['benchmark_state_file'],[]);$s['schema']='benchmark_source_v1';$s['updated_at']=te_now();$old=(string)($s['markets'][$market]['target_date']??'');$s['markets'][$market]=array_merge(is_array($s['markets'][$market]??null)?$s['markets'][$market]:[],['target_date'=>$old===''||$date>$old?$date:$old,'updated_at'=>te_now()]);te_save($c['benchmark_state_file'],$s);}
function te_invalidate_market_benchmark_cache(array $c,string $market): void{$symbols=$market==='KR'?['^KS11','069500']:($market==='JP'?['^N225','1306']:['^GSPC','SPY']);foreach($symbols as$symbol){$prefix=te_safe($market.'_'.$symbol).'_';foreach(glob($c['bars_cache'].'/1d/'.$prefix.'*.json')?:[]as$f)@unlink($f);@unlink($c['quote_cache'].'/'.te_safe($market.'_'.$symbol).'.json');}}
function te_scan_progress_overall_status(array $progress,array $scannedMarkets): string{if(!$scannedMarkets)return'CLOSE_HOLD';$anyRunning=false;$anyError=false;foreach(array_keys($scannedMarkets)as$m){$p=is_array($progress['markets'][$m]??null)?$progress['markets'][$m]:[];$st=(string)($p['status']??'');if(in_array($st,['DATE_MISMATCH','DISCARDED','ERROR'],true))$anyError=true;if($st==='BENCHMARK_WAIT')$anyRunning=true;if(empty($p['cycle_complete']))$anyRunning=true;}if($anyError)return'PARTIAL_ERROR';return$anyRunning?'RUNNING':'DONE';}
function te_intent_broker_ingest_diag(array $c,array $intent): array
{
    $hb=te_load((string)($c['broker_heartbeat_file']??''),[]);
    $createdRaw=$intent['created_at']??0;$created=is_numeric($createdRaw)?(int)$createdRaw:(strtotime((string)$createdRaw)?:0);$ingestRaw=$hb['last_ingest_at']??0;$lastIngest=is_numeric($ingestRaw)?(int)$ingestRaw:(strtotime((string)$ingestRaw)?:0);$cycleRaw=$hb['last_cycle_at']??0;$lastCycle=is_numeric($cycleRaw)?(int)$cycleRaw:(strtotime((string)$cycleRaw)?:0);
    $afterCreated=$created>0&&$lastIngest>=$created;
    $age=$created>0?max(0,time()-$created):null;
    return[
        'broker_seen'=>false,
        'created_at'=>(string)($intent['created_at']??''),
        'intent_age_sec'=>$age,
        'broker_last_ingest_at'=>(string)($hb['last_ingest_at']??''),
        'broker_last_cycle_at'=>(string)($hb['last_cycle_at']??''),
        'broker_ingest_after_created'=>$afterCreated,
        'diagnostic_code'=>$afterCreated?'BROKER_ORDER_MISSING_AFTER_INGEST':'BROKER_INGEST_MISSING',
        'intents_file'=>(string)($c['intents_file']??''),
        'broker_orders_file'=>(string)($c['broker_orders_file']??''),
    ];
}

function te_expire_engine_intents(array $c,array $states,array $candidateBook,array $brokerOrders): array
{
    $expired=0;$ingestMissing=0;$orderMissing=0;$ackWait=0;$spoolRepaired=0;
    $fp=@fopen($c['intents_lock'],'c+');if(!$fp||!@flock($fp,LOCK_EX)){if(is_resource($fp))@fclose($fp);return['states'=>$states,'candidate_book'=>$candidateBook,'expired'=>0,'broker_ingest_missing'=>0,'broker_order_missing_after_ingest'=>0,'broker_ack_wait'=>0,'spool_repaired'=>0];}
    try{        $root=te_load($c['intents_file'],[]);$rows=is_array($root['intents']??null)?$root['intents']:[];
        $archiveRoot=te_load($c['intents_archive_file'],[]);$archive=is_array($archiveRoot['intents']??null)?$archiveRoot['intents']:[];
        $brokerMap=[];foreach($brokerOrders as$o)if(is_array($o))$brokerMap[(string)($o['order_id']??'')]=$o;$keep=[];
        foreach($rows as$i){
            if(!is_array($i)){continue;}
            $id=(string)($i['order_id']??'');$own=strtolower((string)($i['strategy_key']??''))===$c['strategy_key'];$terminal='';$diag=null;
            if(isset($brokerMap[$id])){
                $bs=strtoupper((string)($brokerMap[$id]['status']??''));
                if(in_array($bs,['REJECTED','CANCELLED','EXPIRED','BROKER_INGEST_MISSING','BROKER_ORDER_MISSING_AFTER_INGEST','FILLED','PAPER_FILLED'],true))$terminal=$bs;
            }
            if($terminal===''){
                $exp=strtotime((string)($i['order_expires_at']??$i['expires_at']??''));$side=strtoupper((string)($i['side']??''));
                if($side!=='SELL'&&$exp!==false&&$exp<time()&&!isset($brokerMap[$id])){
                    $ack=te_intent_ack_load($c,$id);
                    if($ack){
                        $diag=te_intent_broker_ingest_diag($c,$i);$diag['ack']=$ack;$terminal='BROKER_ORDER_MISSING_AFTER_INGEST';$orderMissing++;
                    }else{
                        if(!is_file(te_intent_spool_path($c,$id))&&te_ensure_intent_spool($c,$i))$spoolRepaired++;
                        $diag=te_intent_broker_ingest_diag($c,$i);$diag['diagnostic_code']='BROKER_ACK_WAIT';$diag['spool_path']=te_intent_spool_path($c,$id);$diag['spool_exists']=is_file(te_intent_spool_path($c,$id));$diag['ack_exists']=false;
                        $i['status']='BROKER_ACK_WAIT';$i['handoff_state']='WAIT_BROKER_ACK';$i['broker_ingest_diagnostic']=$diag;$i['terminal_reason']='';$i['updated_at']=te_now();$ackWait++;
                        $key=strtoupper((string)($i['market']??'')).':'.(string)($i['symbol']??'');
                        if(!isset($states[$key])||!is_array($states[$key]))$states[$key]=[];
                        $states[$key]['phase']='ENTRY_PENDING';$states[$key]['pending_order_id']=$id;$states[$key]['last_reason']='BROKER_ACK_WAIT';
                        $mm=(string)($i['market']??'');$ss=(string)($i['symbol']??'');
                        if(isset($candidateBook['markets'][$mm]['rows'][$ss])&&is_array($candidateBook['markets'][$mm]['rows'][$ss])){
                            $candidateBook['markets'][$mm]['rows'][$ss]['status']='BROKER_ACK_WAIT';
                            $candidateBook['markets'][$mm]['rows'][$ss]['block_reason']='BROKER_ACK_WAIT';
                        }
                        $keep[]=$i;continue;
                    }
                }
            }
            if($terminal!==''&&$own){
                $i['status']=$terminal;$i['closed_at']=te_now();
                if($terminal==='EXPIRED')$expired++;
                if(isset($brokerMap[$id])&&is_array($brokerMap[$id])){
                    $bo=$brokerMap[$id];$i['broker_ingest_state']=(string)($bo['broker_ingest_state']??'BROKER_SEEN');$i['broker_seen_at']=(string)($bo['broker_seen_at']??$bo['received_at']??'');$i['broker_seen_cycle_id']=(string)($bo['broker_seen_cycle_id']??'');$i['broker_ingest_source']=(string)($bo['broker_ingest_source']??'');$i['handoff_state']='BROKER_SEEN_TERMINAL';$i['terminal_reason']=(string)($bo['cancel_reason']??$bo['expired_reason']??$bo['reject_reason']??$terminal);
                }
                if(is_array($diag)){$i['broker_ingest_diagnostic']=$diag;$i['terminal_reason']=$terminal;}
                $jpQuoteRetry=te_is_jp_quote_terminal($i);
                if($terminal==='EXPIRED'&&$jpQuoteRetry)$expired=max(0,$expired-1);
                $key=strtoupper((string)($i['market']??'')).':'.(string)($i['symbol']??'');
                if(!isset($states[$key])||!is_array($states[$key]))$states[$key]=[];
                if((($states[$key]['pending_order_id']??'')===$id||($states[$key]['phase']??'')==='ENTRY_PENDING')){
                    if($terminal==='EXPIRED'&&!$jpQuoteRetry&&(int)$c['expired_cooldown_sec']>0){
                        $states[$key]['phase']='COOLDOWN';$states[$key]['cooldown_until']=date('Y-m-d H:i:s',time()+(int)$c['expired_cooldown_sec']);
                    }else{
                        $states[$key]['phase']='WAIT_SIGNAL';unset($states[$key]['cooldown_until']);
                    }
                    $states[$key]['last_reason']=$jpQuoteRetry?'JP_LIVE_REQUOTE_RETRY':'ORDER_'.$terminal;unset($states[$key]['pending_order_id']);
                }
                $candidateBook=te_clear_candidate_order($candidateBook,(string)($i['market']??''),(string)($i['symbol']??''),$id,$terminal,$jpQuoteRetry?'JP_LIVE_REQUOTE_RETRY':'');
                $archive[]=$i;@unlink(te_intent_ack_path($c,$id));continue;
            }
            $keep[]=$i;
        }
        if(count($archive)>(int)$c['intent_archive_limit'])$archive=array_slice($archive,-(int)$c['intent_archive_limit']);
        te_save($c['intents_file'],['schema'=>TE_SCHEMA,'owner'=>'engine','updated_at'=>te_now(),'intents'=>$keep]);
        te_save($c['intents_archive_file'],['schema'=>TE_SCHEMA,'owner'=>'engine','updated_at'=>te_now(),'intents'=>$archive]);
    }finally{@flock($fp,LOCK_UN);@fclose($fp);}
    if($ingestMissing>0||$orderMissing>0||$ackWait>0)te_log($c['error_file'],'BROKER_PIPELINE_DIAG ingest_missing='.$ingestMissing.' order_missing_after_ingest='.$orderMissing.' ack_wait='.$ackWait.' spool_repaired='.$spoolRepaired.' strategy='.$c['strategy_key']);
    return['states'=>$states,'candidate_book'=>$candidateBook,'expired'=>$expired,'broker_ingest_missing'=>$ingestMissing,'broker_order_missing_after_ingest'=>$orderMissing,'broker_ack_wait'=>$ackWait,'spool_repaired'=>$spoolRepaired];
}
function te_clear_candidate_order(array $book,string $market,string $symbol,string $orderId,string $terminal,string $blockReason=''): array{if(isset($book['markets'][$market]['rows'][$symbol])&&is_array($book['markets'][$market]['rows'][$symbol])){$r=$book['markets'][$market]['rows'][$symbol];if((string)($r['order_id']??'')===$orderId){unset($r['order_id'],$r['order_qty'],$r['order_price'],$r['order_stop_price'],$r['order_target_price'],$r['price_revalidation_pct']);$r['status']='WATCH';$r['order_eligible']=false;$r['rank_selected']=false;$r['block_reason']=$blockReason!==''?$blockReason:($terminal==='EXPIRED'?'ORDER_EXPIRED_COOLDOWN':'ORDER_'.$terminal);if($terminal==='EXPIRED'&&$blockReason==='')$r['cooldown_until']=date('Y-m-d H:i:s',time()+TE_EXPIRED_COOLDOWN_SEC);else unset($r['cooldown_until']);$m=is_array($r['metrics']??null)?$r['metrics']:[];if($m){$m['order_eligible']=false;$m['rank_selected']=false;unset($m['order_id']);$r['metrics']=$m;}$book['markets'][$market]['rows'][$symbol]=$r;}}return$book;}

function te_time_add_minutes(string $hhmm,int $minutes): string{$d=DateTime::createFromFormat('H:i',$hhmm);if(!$d)return$hhmm;$d->modify('+'.$minutes.' minutes');return$d->format('H:i');}
function te_market_calendar(string $file): array
{
    $base=['KR'=>['holidays'=>[],'early_closes'=>[]],'US'=>['holidays'=>[],'early_closes'=>[]],'JP'=>['holidays'=>[],'early_closes'=>[]]];
    if(!is_file($file))return$base;$x=include$file;if(!is_array($x))return$base;
    foreach(['KR','US','JP']as$m){if(!isset($x[$m])||!is_array($x[$m]))continue;$h=$x[$m]['holidays']??[];$e=$x[$m]['early_closes']??[];$base[$m]['holidays']=is_array($h)?array_values(array_unique(array_map('strval',$h))):[];$base[$m]['early_closes']=is_array($e)?$e:[];}return$base;
}
function te_market_status(array $c=[]): array
{
    $kr=new DateTime('now',new DateTimeZone('Asia/Seoul'));$us=new DateTime('now',new DateTimeZone('America/New_York'));$jp=new DateTime('now',new DateTimeZone('Asia/Tokyo'));$cal=te_market_calendar((string)($c['calendar_file']??''));$out=['now'=>te_now(),'calendar_source'=>is_file((string)($c['calendar_file']??''))?'LOCAL_FILE':'WEEKDAY_FALLBACK'];
    foreach(['KR'=>$kr,'US'=>$us,'JP'=>$jp]as$m=>$dt){$key=strtolower($m);$date=$dt->format('Y-m-d');$dow=(int)$dt->format('N');$time=$dt->format('H:i');$holiday=in_array($date,$cal[$m]['holidays'],true);$business=$dow<=5&&!$holiday;
        if($m==='KR'){$close=(string)($cal[$m]['early_closes'][$date]??'15:30');$trade=$business&&$time>='09:00'&&$time<$close;$entry=$trade&&$time>='09:05'&&$time<=($close<'15:20'?$close:'15:20');$scan=$business&&(($time>='08:00'&&$time<$close)||($time>=te_time_add_minutes($close,10)&&$time<='20:00'));}
        elseif($m==='US'){$close=(string)($cal[$m]['early_closes'][$date]??'16:00');$trade=$business&&$time>='09:30'&&$time<$close;$entry=$trade&&$time>='09:35'&&$time<=($close<'15:50'?$close:'15:50');$scan=$business&&(($time>='04:00'&&$time<$close)||($time>=te_time_add_minutes($close,10)&&$time<='20:00'));}
        else{$close=(string)($cal[$m]['early_closes'][$date]??'15:30');$morning=$time>='09:00'&&$time<'11:30';$afternoon=$time>='12:30'&&$time<$close;$trade=$business&&($morning||$afternoon);$entry=$trade&&(($time>='09:05'&&$time<='11:25')||($time>='12:35'&&$time<=($close<'15:20'?$close:'15:20')));$scan=$business&&(($time>='08:00'&&$time<'11:30')||($time>='12:30'&&$time<$close)||($time>=te_time_add_minutes($close,10)&&$time<='20:00'));}
        $settle=te_time_add_minutes($close,10);$out[$key.'_time']=$dt->format('Y-m-d H:i');$out[$key.'_trade_open']=$trade;$out[$key.'_entry_open']=$entry;$out[$key.'_scan_open']=$scan;$out[$key.'_day_complete']=$business&&$time>=$close;$out[$key.'_holiday']=$holiday;$out[$key.'_close_time']=$close;$out[$key.'_settle_time']=$settle;
    }return$out;
}
function te_market_timezone(string $m): string{$m=strtoupper($m);return$m==='US'?'America/New_York':($m==='JP'?'Asia/Tokyo':'Asia/Seoul');}
function te_market_flag(array $status,string $market,string $suffix): bool{return!empty($status[strtolower(strtoupper($market)).'_'.$suffix]);}
function te_session_date(string $m): string{return(new DateTime('now',new DateTimeZone(te_market_timezone($m))))->format('Y-m-d');}
function te_date_tz(int $ts,string $m): string{$d=new DateTime('@'.$ts);$d->setTimezone(new DateTimeZone(te_market_timezone($m)));return$d->format('Y-m-d');}
function te_iso_tz(int $ts,string $m): string{if($ts<=0)return'';$d=new DateTime('@'.$ts);$d->setTimezone(new DateTimeZone(te_market_timezone($m)));return$d->format('c');}
// [E2] 주말/휴장 판별 및 직전 거래일 마감 timestamp 계산
function te_is_weekend(string $market): bool{$tz=te_market_timezone($market);$dt=new DateTime('now',new DateTimeZone($tz));return(int)$dt->format('N')>=6;}
function te_last_trading_close_ts(string $market): int{$tz=te_market_timezone($market);$close=$market==='US'?'16:00':'15:30';$dt=new DateTime('now',new DateTimeZone($tz));$dow=(int)$dt->format('N');$back=$dow===6?1:($dow===7?2:0);if($back>0)$dt->modify("-{$back} days");$dt->setTime((int)substr($close,0,2),(int)substr($close,3,2),0);return(int)$dt->format('U');}

function te_http_json(string $url): array{$s=te_http_get($url);if($s==='')return[];$j=json_decode($s,true);return is_array($j)?$j:[];}
function te_http_get(string $url): string
{
    if(!function_exists('curl_init'))return'';$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>TE_HTTP_CONNECT_TIMEOUT,CURLOPT_TIMEOUT=>TE_HTTP_TIMEOUT,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'Mozilla/5.0 TradeEngine/'.TE_VERSION,CURLOPT_HTTPHEADER=>['Accept: application/json,text/plain,*/*','Connection: close']]);$x=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=(int)curl_errno($ch);curl_close($ch);return$err===0&&$code>=200&&$code<300&&is_string($x)?$x:'';
}


/**
 * 분석용 이력 스냅샷
 * - UI 다운로드는 파일을 수정하지 않는 읽기 전용 동작이다.
 * - CLI save_analysis/save_all_analysis만 서버에 별도 스냅샷을 저장한다.
 */
function te_order_pipeline_counts(array $c, array $brokerOrders): array
{
    $intentRoot = te_load($c['intents_file'], []);
    $intents = is_array($intentRoot['intents'] ?? null) ? te_strategy_orders($c, $intentRoot['intents']) : [];
    return te_order_pipeline_counts_for_key($intents, te_strategy_orders($c, $brokerOrders));
}

function te_order_pipeline_counts_for_key(array $intents, array $brokerOrders): array
{
    $counts = ['active_buy_intents'=>0,'active_buy_orders'=>0,'broker_pending'=>0,'working'=>0,'partial'=>0,'filled'=>0,'rejected'=>0,'expired'=>0];
    $seenBroker = [];
    foreach ($brokerOrders as $order) {
        if (!is_array($order)) continue;
        $id = (string)($order['order_id'] ?? '');
        if ($id !== '') $seenBroker[$id] = true;
        $status = strtoupper((string)($order['status'] ?? ''));
        $side = strtoupper((string)($order['side'] ?? ''));
        if ($status === 'PENDING' || $status === 'APPROVED') $counts['broker_pending']++;
        elseif ($status === 'WORKING' || $status === 'SENT' || $status === 'CANCEL_REQUESTED') $counts['working']++;
        elseif ($status === 'PARTIAL') $counts['partial']++;
        elseif ($status === 'FILLED' || $status === 'PAPER_FILLED') $counts['filled']++;
        elseif ($status === 'REJECTED' || $status === 'CANCELLED') $counts['rejected']++;
        elseif ($status === 'EXPIRED') $counts['expired']++;
        if ($side === 'BUY' && in_array($status, ['PENDING','APPROVED','SENT','WORKING','PARTIAL','CANCEL_REQUESTED'], true)) $counts['active_buy_orders']++;
    }
    foreach ($intents as $intent) {
        if (!is_array($intent) || strtoupper((string)($intent['side'] ?? '')) !== 'BUY') continue;
        $id = (string)($intent['order_id'] ?? '');
        if ($id !== '' && isset($seenBroker[$id])) continue;
        if (te_intent_row_active($intent, $brokerOrders)) $counts['active_buy_intents']++;
    }
    return $counts;
}

function te_user_log_summary(array $state, array $scanProgress): string
{
    $diag = is_array($state['diag'] ?? null) ? $state['diag'] : [];
    $parts = [
        '이번 tick '.(int)($diag['scan'] ?? 0).'종목',
        'BUY '.(int)($diag['buy'] ?? 0),
        'WATCH '.(int)($diag['watch'] ?? 0),
        '데이터 부족 '.(int)($diag['data_short'] ?? 0),
        '데이터 오류 '.(int)($diag['data_error'] ?? 0),
        '신규 BUY intent '.(int)($diag['buy_intent'] ?? 0),
        'SELL intent '.(int)($diag['sell_intent'] ?? 0),
    ];
    $markets = is_array($scanProgress['markets'] ?? null) ? $scanProgress['markets'] : [];
    foreach (['KR','US','JP'] as $market) {
        $row = is_array($markets[$market] ?? null) ? $markets[$market] : [];
        if (!$row) continue;
        $parts[] = $market.' '.(int)($row['cycle_position'] ?? 0).'/'.(int)($row['total'] ?? 0).' '.(string)($row['status'] ?? 'WAIT');
    }
    return implode(' · ', $parts);
}

function te_analysis_specs(array $c): array
{
    $out=[];
    foreach($c['compare_models'] as $key=>$m){
        if(!is_array($m))continue;
        $safe=strtolower(preg_replace('/[^A-Za-z0-9_-]/','',(string)$key));
        if($safe==='')continue;
        $out[$safe]=[
            'key'=>$safe,
            'label'=>(string)($m['label']??strtoupper($safe)),
            'file'=>(string)($m['file']??($safe.'.php')),
            'runtime'=>(string)($m['runtime']??(__DIR__.'/'.$safe.'_runtime')),'status'=>(string)($m['status']??'CORE'),'alpha_type'=>(string)($m['alpha_type']??''),
        ];
    }
    return$out;
}
function te_analysis_spec(array $c,string $strategy): ?array
{
    $key=strtolower(preg_replace('/[^A-Za-z0-9_-]/','',$strategy));
    $specs=te_analysis_specs($c);
    return isset($specs[$key])&&is_array($specs[$key])?$specs[$key]:null;
}
function te_analysis_load_first(array $files,$default=[])
{
    foreach($files as$f){if(is_string($f)&&is_file($f))return te_load($f,$default);}
    return$default;
}
function te_analysis_filter_orders(array $rows,string $key): array
{
    $out=[];
    foreach($rows as$r){
        if(!is_array($r))continue;
        $rk=strtolower((string)($r['strategy_key']??$r['strategy_id']??''));
        if($rk!==strtolower($key))continue;
        $out[]=te_analysis_scrub($r);
    }
    return$out;
}
function te_analysis_scrub($value)
{
    if(!is_array($value))return$value;
    $out=[];
    foreach($value as$k=>$v){
        $lk=strtolower((string)$k);
        if(preg_match('/(access[_-]?token|refresh[_-]?token|app[_-]?secret|app[_-]?key|password|passwd|authorization|hashkey)/',$lk)){
            $out[$k]='***';
            continue;
        }
        if(in_array($lk,['cano','account_no','account_number'],true)){
            $raw=(string)$v;$out[$k]=strlen($raw)>4?str_repeat('*',max(0,strlen($raw)-4)).substr($raw,-4):'***';
            continue;
        }
        $out[$k]=te_analysis_scrub($v);
    }
    return$out;
}
function te_analysis_limits(int $level = 0): array
{
    $levels = [
        0 => ['candidates_per_market'=>80,'trades'=>500,'orders'=>200,'archive'=>50,'logs'=>50,'model_states'=>100,'extension_rows'=>100,'evidence_symbols_per_market'=>3,'evidence_daily_bars'=>260,'evidence_benchmark_bars'=>30],
        1 => ['candidates_per_market'=>50,'trades'=>300,'orders'=>120,'archive'=>30,'logs'=>30,'model_states'=>60,'extension_rows'=>60,'evidence_symbols_per_market'=>2,'evidence_daily_bars'=>180,'evidence_benchmark_bars'=>20],
        2 => ['candidates_per_market'=>25,'trades'=>150,'orders'=>60,'archive'=>15,'logs'=>20,'model_states'=>30,'extension_rows'=>30,'evidence_symbols_per_market'=>1,'evidence_daily_bars'=>120,'evidence_benchmark_bars'=>10],
        3 => ['candidates_per_market'=>10,'trades'=>50,'orders'=>20,'archive'=>0,'logs'=>10,'model_states'=>10,'extension_rows'=>0,'evidence_symbols_per_market'=>1,'evidence_daily_bars'=>60,'evidence_benchmark_bars'=>5],
        4 => ['candidates_per_market'=>0,'trades'=>20,'orders'=>10,'archive'=>0,'logs'=>5,'model_states'=>0,'extension_rows'=>0,'evidence_symbols_per_market'=>0,'evidence_daily_bars'=>0,'evidence_benchmark_bars'=>0],
    ];
    return $levels[$level] ?? $levels[4];
}

function te_analysis_text($value, int $limit = 500): string
{
    $text = trim((string)$value);
    if ($limit < 1 || strlen($text) <= $limit) return $text;
    return substr($text, 0, $limit).'…';
}

function te_analysis_pick(array $source, array $keys): array
{
    $out = [];
    foreach ($keys as $key) if (array_key_exists($key, $source)) $out[$key] = $source[$key];
    return $out;
}

function te_analysis_compact_tick($state): array
{
    if (!is_array($state)) return [];
    return te_analysis_pick($state, [
        'ok','engine','message','tick_id','last_tick','version','rev','strategy_key','strategy_label','strategy_rev','diag','positions_count'
    ]);
}

function te_analysis_compact_scan($scan): array
{
    if (!is_array($scan)) return [];
    $out = te_analysis_pick($scan, ['tick_id','status','started_at','updated_at','completed_at']);
    $out['markets'] = [];
    foreach (['KR','US','JP'] as $market) {
        $row = is_array($scan['markets'][$market] ?? null) ? $scan['markets'][$market] : [];
        $out['markets'][$market] = te_analysis_pick($row, [
            'market','status','total','batch_start','batch_total','batch_processed','batch_pct','batch_complete',
            'cursor_next','cycle_position','cycle_pct','cycle_complete','current_symbol','current_name','last_symbol','last_name',
            'holding_close','data_error_count','data_short_count','filtered_count','watch_count','buy_signal_count','buy_intent_count',
            'hard_filter_pass_count','structural_candidate_count','score_pass_count','rank_selected_count','final_candidate_count',
            'scan_completed_at','updated_at'
        ]);
    }
    return $out;
}

function te_analysis_compact_regime($regime): array
{
    if (!is_array($regime)) return [];
    $out = ['updated_at'=>(string)($regime['updated_at'] ?? ''),'markets'=>[]];
    foreach (['KR','US','JP'] as $market) {
        $row = is_array($regime['markets'][$market] ?? null) ? $regime['markets'][$market] : [];
        $out['markets'][$market] = te_analysis_pick($row, [
            'market','index','symbol','code','label','score','close','ma20','ma60','ma140','ma280','ma20_slope','ma60_slope',
            'bars','date','basis','frozen','benchmark_symbol','benchmark_source','benchmark_is_proxy','calculated_at'
        ]);
    }
    return $out;
}

function te_analysis_candidate_priority(array $row): int
{
    $status = strtoupper((string)($row['status'] ?? ''));
    if (in_array($status, ['BUY_PENDING','BUY','ORDER_ELIGIBLE'], true)) return 700;
    if ($status === 'CANDIDATE') return 650;
    if (!empty($row['order_id']) || !empty($row['rank_selected']) || !empty($row['order_eligible'])) return 600;
    if ($status === 'WATCH') return 400;
    if (in_array($status, ['DATA_SHORT','DATA_STALE','DATA_ERROR'], true)) return 200;
    return 100;
}

function te_analysis_metric_value(array $outer, string $key)
{
    if (array_key_exists($key, $outer)) return $outer[$key];
    $inner = is_array($outer['metrics'] ?? null) ? $outer['metrics'] : [];
    if (array_key_exists($key, $inner)) return $inner[$key];
    $ai = is_array($outer['ai_features'] ?? null) ? $outer['ai_features'] : [];
    if (array_key_exists($key, $ai)) return $ai[$key];
    return null;
}

function te_analysis_compact_backtest($backtest): array
{
    if (!is_array($backtest)) return [];
    return te_analysis_pick($backtest, [
        'sample_count','touch_count','first_failure_count','ambiguous_count','counts','probabilities','horizon_days','sampling'
    ]);
}

function te_analysis_validation_state(array $c,string $strategy,int $limit): array
{
    $strategy=strtoupper($strategy);
    // Export is a read-mostly path, but Validation due_ts is derived state.
    // Repair/persist legacy zero due_ts once, then reload so exported JSON reflects disk truth.
    $repair=function_exists('tv_repair_pending_due_ts_persist')?tv_repair_pending_due_ts_persist($c):['ok'=>false,'reason'=>'REPAIR_FUNCTION_MISSING','repaired'=>0,'remaining_zero_due_horizons'=>null];
    $p=tv_paths($c);$summary=tv_load($p['strategy_summary'],tv_summary_default());$pending=tv_load($p['pending'],tv_pending_default());$strategySummary=[];$hash='';
    foreach((array)($summary['strategies']??[])as$h=>$row){if(!is_array($row)||strtoupper((string)($row['strategy']??''))!==$strategy)continue;if(!$strategySummary||(string)($row['last_registered_at']??'')>(string)($strategySummary['last_registered_at']??'')){$strategySummary=$row;$hash=(string)$h;}}
    $rows=[];foreach((array)($pending['samples']??[])as$sample){if(!is_array($sample)||strtoupper((string)($sample['strategy']??''))!==$strategy)continue;$rows[]=$sample;}
    $total=count($rows);$rows=te_analysis_recent_rows($rows,max(0,$limit),['updated_at','registered_at','signal_time']);$compact=[];foreach($rows as$row){$compact[]=te_analysis_scrub(te_analysis_pick($row,['id','schema','strategy','strategy_status','strategy_version','strategy_rev','strategy_hash','system_hash','alpha_type','market','symbol','name','side','signal_type','signal_origin','eligible_for_strategy_stats','validation_status','signal_price','signal_time','signal_ts','signal_market_date','signal_session_date','data_timestamp','provider','data_quality','primary_horizon','horizons','pipeline','status','registered_at','updated_at']));}
    return['available'=>is_file($p['events'])||is_file($p['strategy_summary'])||is_file($p['pending']),'schema'=>TV_SCHEMA,'runtime'=>$p['root'],'strategy_hash'=>$hash,'summary'=>$strategySummary,'summary_strategy_match'=>$strategySummary?strtoupper((string)($strategySummary['strategy']??''))===$strategy:true,'all_strategy_totals'=>(array)($summary['totals']??[]),'sample_total_count'=>$total,'sample_included_count'=>count($compact),'samples'=>$compact,'due_ts_repair'=>$repair,'source_files'=>['events'=>is_file($p['events']),'pending'=>is_file($p['pending']),'strategy_summary'=>is_file($p['strategy_summary']),'independence_summary'=>is_file($p['independence_summary']),'challenger_summary'=>is_file($p['challenger_summary'])]];
}

function te_analysis_compact_ohlcv(array $bars,int $limit,string $market,string $throughDate=''): array
{
    $out=[];foreach($bars as$bar){
        if(!is_array($bar))continue;$date=te_model_bar_date($bar,$market);if($date===''||($throughDate!==''&&$date>$throughDate))continue;$rawClose=te_model_num($bar['close']??0);if($rawClose<=0)continue;$adjClose=0.0;
        foreach(['adj_close','adjusted_close','adjclose']as$key)if(isset($bar[$key])&&is_numeric($bar[$key])&&(float)$bar[$key]>0){$adjClose=(float)$bar[$key];break;}
        $ratio=$adjClose>0?$adjClose/$rawClose:1.0;$row=['date'=>$date,'open'=>te_model_num($bar['open']??0),'high'=>te_model_num($bar['high']??0),'low'=>te_model_num($bar['low']??0),'close'=>$rawClose,'volume'=>te_model_num($bar['volume']??0),'_analysis'=>[]];
        foreach(['open','high','low','close']as$field){$adj=0.0;foreach(['adj_'.$field,'adjusted_'.$field]as$key)if(isset($bar[$key])&&is_numeric($bar[$key])&&(float)$bar[$key]>0){$adj=(float)$bar[$key];break;}$raw=te_model_num($bar[$field]??$rawClose);$row['_analysis'][$field]=$adj>0?$adj:$raw*$ratio;}
        if($adjClose>0)$row['adjusted_close']=$adjClose;$out[$date]=$row;
    }
    ksort($out,SORT_STRING);$out=$limit>0?array_slice(array_values($out),-$limit):[];if(!$out)return[];$last=$out[count($out)-1];$analysisLast=(float)($last['_analysis']['close']??0);$scale=$analysisLast>0?(float)$last['close']/$analysisLast:1.0;
    foreach($out as$i=>$row){foreach(['open','high','low','close']as$field)$out[$i]['analysis_'.$field]=round((float)($row['_analysis'][$field]??0)*$scale,8);unset($out[$i]['_analysis']);}
    return$out;
}

function te_analysis_cached_daily_bars(array $c,string $market,string $symbol,int $limit,string $throughDate=''): array
{
    if($limit<1||$market===''||$symbol==='')return[];$dir=(string)($c['bars_cache']??'').'/1d';$prefix=te_safe($market.'_'.$symbol).'_';$best=[];$bestSaved=0;
    foreach(glob($dir.'/'.$prefix.'*.json')?:[]as$file){$root=te_load($file,[]);$bars=is_array($root['bars']??null)?$root['bars']:[];$saved=(int)($root['saved_at']??(@filemtime($file)?:0));if(count($bars)>count($best)||(count($bars)===count($best)&&$saved>$bestSaved)){$best=$bars;$bestSaved=$saved;}}
    $rows=te_analysis_compact_ohlcv($best,$limit,$market,$throughDate);$adjusted=false;foreach($best as$bar)if(is_array($bar)&&(is_numeric($bar['adj_close']??null)||is_numeric($bar['adjusted_close']??null)||is_numeric($bar['adjclose']??null))){$adjusted=true;break;}$actualThrough=$rows?substr((string)($rows[count($rows)-1]['date']??''),0,10):'';$relation=$throughDate!==''?te_scan_date_relation($actualThrough,$throughDate):($actualThrough!==''?'UNSCOPED':'MISSING');return$rows?['market'=>$market,'symbol'=>$symbol,'through_date'=>$actualThrough,'requested_through_date'=>$throughDate,'through_date_relation'=>$relation,'through_date_match'=>$throughDate===''||$relation==='MATCH','saved_at'=>$bestSaved>0?date('c',$bestSaved):'','bar_count'=>count($rows),'analysis_price_basis'=>$adjusted?'ADJUSTED_NORMALIZED_TO_LAST_INCLUDED_ACTUAL_CLOSE':'RAW_SOURCE_UNCONFIRMED','bars'=>$rows]:[];
}

function te_analysis_das_market_evidence(array $c,array $candidateBook,array $positions,array $regime,array $limits): array
{
    $symbolLimit=max(0,(int)($limits['evidence_symbols_per_market']??0));$barLimit=max(0,(int)($limits['evidence_daily_bars']??0));$benchmarkLimit=max(0,(int)($limits['evidence_benchmark_bars']??0));if($symbolLimit<1||$barLimit<1)return['available'=>false,'reason'=>'OMITTED_BY_SIZE_POLICY','symbols'=>[],'market_benchmarks'=>[]];
    $selected=[];$through=[];
    foreach(['KR','US','JP']as$market){$marketRow=is_array($candidateBook['markets'][$market]??null)?$candidateBook['markets'][$market]:[];$rows=is_array($marketRow['rows']??null)?array_values($marketRow['rows']):[];$date=(string)($marketRow['data_date']??$marketRow['last_completed_scan']['data_date']??'');$through[$market]=$date;
        usort($rows,static function($a,$b){$ap=te_analysis_candidate_priority(is_array($a)?$a:[]);$bp=te_analysis_candidate_priority(is_array($b)?$b:[]);if($ap!==$bp)return$bp<=>$ap;$ar=$a['rank']??$a['metrics']['rank']??PHP_INT_MAX;$br=$b['rank']??$b['metrics']['rank']??PHP_INT_MAX;if($ar!==$br)return$ar<$br?-1:1;$av=(float)($a['metrics']['opportunity_value']??0);$bv=(float)($b['metrics']['opportunity_value']??0);return$av===$bv?strcmp((string)($a['symbol']??''),(string)($b['symbol']??'')):($av>$bv?-1:1);});$used=0;
        foreach($rows as$row){if($used>=$symbolLimit||!is_array($row))break;$metrics=is_array($row['metrics']??null)?$row['metrics']:[];$status=strtoupper((string)($row['status']??''));$rank=(int)($row['rank']??$metrics['rank']??0);$scorePass=!empty($metrics['score_pass']);if(!in_array($status,['BUY_PENDING','BUY','ORDER_ELIGIBLE','CANDIDATE'],true)&&!$scorePass&&$rank<=0)continue;$symbol=(string)($row['symbol']??$metrics['symbol']??'');if($symbol==='')continue;$key=$market.':'.$symbol;$selected[$key]=['market'=>$market,'symbol'=>$symbol,'name'=>(string)($row['name']??$symbol),'reason'=>'TOP_DAS_RELATIVE_STRENGTH_CANDIDATE','status'=>$status,'rank'=>$row['rank']??$metrics['rank']??null,'rule_score'=>$metrics['rule_score']??$row['score']??null,'through_date'=>$date];$used++;}
    }
    foreach($positions as$position)if(is_array($position)&&te_active_position($position)){$market=(string)($position['market']??'');$symbol=(string)($position['symbol']??'');if($market!==''&&$symbol!==''){$key=$market.':'.$symbol;if(!isset($selected[$key]))$selected[$key]=['market'=>$market,'symbol'=>$symbol,'name'=>(string)($position['name']??$symbol),'reason'=>'ACTIVE_DAS_POSITION','rank'=>null,'through_date'=>(string)($through[$market]??'')];else$selected[$key]['reason']='TOP_CANDIDATE_AND_ACTIVE_POSITION';}}
    $evidence=[];foreach($selected as$meta){$cached=te_analysis_cached_daily_bars($c,(string)$meta['market'],(string)$meta['symbol'],$barLimit,(string)$meta['through_date']);if($cached)$evidence[]=array_merge($meta,$cached);}
    $benchmarks=[];foreach(['KR','US','JP']as$market){$rr=is_array($regime['markets'][$market]??null)?$regime['markets'][$market]:[];$symbol=(string)($rr['benchmark_symbol']??$rr['symbol']??'');if($symbol==='')$symbol=$market==='KR'?'^KS11':($market==='JP'?'^N225':'^GSPC');$cached=te_analysis_cached_daily_bars($c,$market,$symbol,$benchmarkLimit,(string)($through[$market]??''));if($cached)$benchmarks[$market]=$cached;}
    return['available'=>!empty($evidence),'purpose'=>'ChatGPT가 상위 DAS 상대강도 후보·주문대기·보유종목의 최근 가격구조를 재확인하기 위한 제한된 완결 일봉','symbols'=>$evidence,'market_benchmarks'=>$benchmarks,'limitations'=>['전체 종목 원시자료와 500봉 백테스트 원시행은 포함하지 않음','업종 벤치마크 원시봉은 포함하지 않음','캐시에 자료가 없는 종목은 생략됨']];
}

function te_chatgpt_analysis_guide(): array
{
    return[
        'language'=>'ko','purpose'=>'자동매매 운영상태와 DAS 상대강도 횡단면 랭킹의 표본충분성·일관성·성과를 ChatGPT가 검토하기 위한 읽기 전용 자료',
        'recommended_prompt'=>'첨부 JSON을 분석하여 자료 누락, Validation 표본 충분성·중복 여부, DAS 상대강도 랭킹 일관성, 후보·주문 상태 모순, 주문·성과 위험을 중요도 순으로 제시하라. 확인할 수 없는 내용은 미확인으로 표시하라.',
        'analysis_order'=>[
            'size_policy와 omitted를 확인하여 자료 범위를 판정',
            'runtime_version_audit와 각 전략 version_audit 확인',
            'DAS candidate_snapshot의 상대강도 features·component_scores·rank·score_pass·선정시점 상태 확인',
            'validation_state가 있으면 예측 대기·확정 통계와 실제 발생률 확인',
            'DAS market_data_evidence에서 상위 상대강도 후보·주문대기·보유종목의 최근 완결 일봉과 시장 벤치마크를 재확인',
            'positions·trades·orders·portfolio_guard를 이용해 운영 위험 확인',
        ],
        'interpretation_notes'=>[
            'auto_validation은 가격순서·확률합계·현재가 대비 손절/목표/지지구간 관계의 구조 검증이며 미래 수익 검증이 아님',
            'validation_state가 NOT_AVAILABLE이면 미래 10·20거래일 누적검증 자료가 아직 생성되지 않은 것임',
            'validation_state 표본이 30건 미만이면 적중률과 Brier 점수는 탐색 자료로만 해석해야 함',
            'DAS v3.0.x는 상대강도 랭커이며 구형 direction/support 확률 모델을 사용하지 않음; 성과검증은 common validation의 고유 resolved 표본으로 판단',
            'market_data_evidence는 상위 후보·보유종목의 제한된 캐시 자료이며 전체 종목 백테스트 원시자료가 아님',
            'market_data_evidence의 기술적 가격 비교에는 analysis_open·analysis_high·analysis_low·analysis_close를 사용하고 raw OHLC는 실제 당시 거래가격 참고용으로만 사용',
            '분석파일은 비밀키·토큰·계좌번호를 제거하거나 마스킹하며 원시 API 응답은 포함하지 않음',
        ],
    ];
}

function te_analysis_compact_metrics($metrics): array
{
    if (!is_array($metrics)) return [];
    $keys = [
        'strategy_id','strategy_version','spec_revision','model_version','model_rev','data_status','data_code','quote_freshness','quote_age_sec','requires_live_requote','regime','market_date',
        'rule_score','score_pass','rank','rank_selected','order_eligible','rs20_percentile','rs60_percentile','turnover_percentile',
        'close','ma5','ma20','ma60','ma120','ma140','ma200','ma280','ma20_slope','ma60_slope','ma140_slope',
        'ma20_slope_5','ma60_slope_10','daily_ma20_slope_pct','daily_extension_pct','close_ma20_ratio','ma20_ma60_ratio',
        'stc_k','stc_d','stoch_k','stoch_d','stc_cross_age','disp20','disp60','rise60','pullback20',
        'volume_ratio','volume_ratio_20','avg_volume_20','avg_turnover_20','atr14','atr14_ratio','gap_pct','gap_rate',
        'gc_days','alignment_days','full_alignment','alignment_after_gc','convergence_pct','extension_pct','runup20_pct',
        'reset_streak_days','reset_streak_current_days','reset_streak_start_date','reset_min_days','ma5_ma20_cross_date','reformation_eligible','quote_above_ma5',
        'pullback','breakout','rerate','gc_early','market_gate','market_gate_reason','bar_date','price_source','daily_source','m60_source',
        'schema','strategy_rev','benchmark_date','reference_price','reference_price_type','reference_quote','adjustment_status',
        'material_status','material_status_reasons','benchmark_scope','sector_benchmark_status','sector_relative_strength_5d','probability_method','confidence','confidence_code','breakout_price','final_defense',
        'entry_lane','entry_type','entry_ready','grade','conditional_expected_value','opportunity_value','arrival_probability',
        'box_regime','relative_strength_5d','relative_strength_improving','preferred_wait_price','new_investor_action','existing_holder_action','one_line_conclusion'
    ];
    $out = [];
    foreach ($keys as $key) {
        $value = te_analysis_metric_value($metrics, $key);
        if ($value !== null && $value !== '' && !is_array($value)) $out[$key] = $value;
    }
    $reasons = te_analysis_metric_value($metrics, 'fail_reasons');
    if (is_array($reasons)) $out['fail_reasons'] = array_slice(array_values($reasons), 0, 8);
    foreach(['features','component_scores','reason_codes'] as $arrayKey){$v=te_analysis_metric_value($metrics,$arrayKey);if(is_array($v))$out[$arrayKey]=$arrayKey==='reason_codes'?array_slice(array_values($v),0,12):$v;}
    if ((string)te_analysis_metric_value($metrics,'schema') === 'das_direction_support_v2') {
        $direction=te_analysis_metric_value($metrics,'direction');
        if(is_array($direction)){
            $out['direction']=te_analysis_pick($direction,['label','horizon_days','up_probability','side_probability','down_probability','rule_scores','upper_boundary_type','lower_boundary_type']);
            $directionBacktest=te_analysis_compact_backtest($direction['backtest']??[]);if($directionBacktest)$out['direction']['backtest']=$directionBacktest;
        }
        $target=te_analysis_metric_value($metrics,'target_zone');if(is_array($target))$out['target_zone']=$target;
        $supports=te_analysis_metric_value($metrics,'supports');if(is_array($supports)){
            $out['supports']=[];foreach(array_slice($supports,0,2)as$s)if(is_array($s)){
                $support=te_analysis_pick($s,[
                    'number','zone_low','zone_high','midpoint','sources','status','arrival_probability','arrival_method',
                    'support_probability','failure_probability','side_probability','support_method','support_score','failure_basis','confidence','confidence_code',
                    'target_price','invalidation_price','reward_pct','risk_pct','rr','conditional_expected_value','opportunity_value','grade',
                    'conditional_arrival_probability','conditional_arrival_status'
                ]);
                foreach(['arrival_backtest','support_backtest','conditional_arrival_backtest']as$backtestKey){$backtest=te_analysis_compact_backtest($s[$backtestKey]??[]);if($backtest)$support[$backtestKey]=$backtest;}
                $out['supports'][]=$support;
            }
        }
        $selected=te_analysis_metric_value($metrics,'selected_support');if(is_array($selected))$out['selected_support']=te_analysis_pick($selected,[
            'number','zone_low','zone_high','midpoint','sources','status','arrival_probability','arrival_method',
            'support_probability','failure_probability','side_probability','confidence','confidence_code',
            'target_price','invalidation_price','reward_pct','risk_pct','rr','conditional_expected_value','opportunity_value','grade'
        ]);
        $validation=te_analysis_metric_value($metrics,'auto_validation');if(is_array($validation))$out['auto_validation']=te_analysis_pick($validation,['ok','passed','failed','failed_checks']);
        $currentValidation=te_analysis_metric_value($metrics,'current_price_revalidation');if(is_array($currentValidation))$out['current_price_revalidation']=te_analysis_pick($currentValidation,['applies','ok','lane','current_price','stop_reference','target_price','support_zone_low','support_zone_high','invalidation_price','passed','failed','failed_checks','reason_codes']);
        foreach(['breakout_plan','external_risk','liquidity','entry_confirmation']as$key){$value=te_analysis_metric_value($metrics,$key);if(is_array($value))$out[$key]=$value;}
    }
    return $out;
}

function te_analysis_compact_candidate(array $row, array $intents, array $brokerOrders): array
{
    $out = te_analysis_pick($row, [
        'time','status','score','market','symbol','name','price','type','entry_price','stop_price','target_price','rank','order_id','order_qty','order_price','order_stop_price','order_target_price','price_revalidation_pct',
        'scan_cycle_id','scan_data_date','scan_session_date','ranked_at'
    ]);
    $out['reason'] = te_analysis_text($row['reason'] ?? '', 700);
    $out['block_reason'] = te_analysis_text($row['block_reason'] ?? '', 500);
    $out['order_progress'] = te_candidate_order_progress($row, $intents, $brokerOrders);
    $out['selection_state']=['current_rank_selected'=>!empty($row['rank_selected'])||!empty($row['metrics']['rank_selected']),'current_order_eligible'=>!empty($row['order_eligible'])||!empty($row['metrics']['order_eligible']),'selection_at_order_time'=>!empty($row['order_id']),'order_id'=>(string)($row['order_id']??''),'basis'=>!empty($row['order_id'])?'ORDER_EXISTS_PROVES_PRIOR_SELECTION':'CURRENT_CANDIDATE_STATE'];
    $compactMetrics = te_analysis_compact_metrics($row['metrics'] ?? []);
    if ($compactMetrics) $out['metrics'] = $compactMetrics;
    return $out;
}

function te_analysis_reason_codes_from_candidate(array $row): array
{
    $metrics=is_array($row['metrics']??null)?$row['metrics']:[];$raw=[];
    $fail=te_analysis_metric_value($metrics,'fail_reasons');if(is_array($fail))$raw=array_merge($raw,$fail);
    if(!$raw){$text=(string)($row['block_reason']??'');if($text!=='')$raw=preg_split('/\s*[,;|]\s*/',$text)?:[];}
    $codes=[];foreach($raw as$code){$code=strtoupper(trim((string)$code));if($code===''||in_array($code,['READY','OK','NONE'],true))continue;$code=preg_replace('/\s+.*/','',$code);if($code!==''&&!in_array($code,$codes,true))$codes[]=$code;}
    return$codes;
}

function te_analysis_block_reason_summary(array $rows,int $limit=12): array
{
    $counts=[];$blockedRows=0;foreach($rows as$row){if(!is_array($row))continue;$codes=te_analysis_reason_codes_from_candidate($row);if(!$codes)continue;$blockedRows++;foreach($codes as$code)$counts[$code]=(int)($counts[$code]??0)+1;}
    arsort($counts,SORT_NUMERIC);$items=[];$total=max(1,count($rows));foreach(array_slice($counts,0,max(0,$limit),true)as$code=>$count)$items[]=['code'=>$code,'count'=>$count,'row_pct'=>round($count/$total*100.0,2)];
    return['source_rows'=>count($rows),'blocked_rows'=>$blockedRows,'unique_reasons'=>count($counts),'items'=>$items];
}

function te_analysis_candidate_summary(array $candidateBook, int $perMarketLimit, array $intents, array $brokerOrders): array
{
    $result = ['basis'=>te_analysis_snapshot_basis($candidateBook),'markets'=>[],'total_source_count'=>0,'included_count'=>0,'block_reason_summary'=>[]];
    $allRows=[];
    foreach (['KR','US','JP'] as $market) {
        $marketRow = is_array($candidateBook['markets'][$market] ?? null) ? $candidateBook['markets'][$market] : [];
        $rows = is_array($marketRow['rows'] ?? null) ? array_values($marketRow['rows']) : [];
        foreach($rows as$row)if(is_array($row))$allRows[]=$row;
        $statusCounts = [];
        foreach ($rows as $row) if (is_array($row)) {
            $status = strtoupper((string)($row['status'] ?? 'UNKNOWN'));
            $statusCounts[$status] = (int)($statusCounts[$status] ?? 0) + 1;
        }
        usort($rows, static function($a,$b){
            $pa=te_analysis_candidate_priority(is_array($a)?$a:[]);$pb=te_analysis_candidate_priority(is_array($b)?$b:[]);
            if($pa!==$pb)return$pb<=>$pa;
            $sa=(float)($a['score']??0);$sb=(float)($b['score']??0);if($sa!==$sb)return$sb<=>$sa;
            $ta=(string)($a['time']??'');$tb=(string)($b['time']??'');if($ta!==$tb)return strcmp($tb,$ta);
            return strcmp((string)($a['symbol']??''),(string)($b['symbol']??''));
        });
        $sourceCount = count($rows);
        $selected = $perMarketLimit > 0 ? array_slice($rows, 0, $perMarketLimit) : [];
        $compact = [];
        foreach ($selected as $row) if (is_array($row)) $compact[] = te_analysis_compact_candidate($row,$intents,$brokerOrders);
        $result['markets'][$market] = [
            'basis'=>(string)($marketRow['basis'] ?? 'NO_SNAPSHOT'),
            'data_date'=>(string)($marketRow['data_date'] ?? ''),
            'captured_at'=>(string)($marketRow['captured_at'] ?? ''),
            'frozen'=>!empty($marketRow['frozen']),
            'source_count'=>$sourceCount,
            'included_count'=>count($compact),
            'status_counts'=>$statusCounts,
            'block_reason_summary'=>te_analysis_block_reason_summary($rows),
            'rows'=>$compact,
        ];
        $result['total_source_count'] += $sourceCount;
        $result['included_count'] += count($compact);
    }
    $result['block_reason_summary']=te_analysis_block_reason_summary($allRows,20);
    return $result;
}

function te_analysis_compact_position(array $row): array
{
    return te_analysis_pick($row, [
        'strategy_id','market','symbol','name','exchange','qty','entry_price','buy_total','current_price','peak_price','stop_price',
        'target_price','entry_type','scenario_id','scenario_key','risk_group','correlation_group','asset_type','position_cohort','entry_strategy_rev','entry_time','execution_mode','status','updated_at','pending_sell_order_id','quote_source','quote_ts','quote_age_sec','last_quote_ok_at'
    ]);
}

function te_analysis_compact_trade(array $row): array
{
    return te_analysis_pick($row, [
        'strategy_id','strategy_rev','market','symbol','name','qty','buy_time','buy_price','buy_total','sell_time','sell_price',
        'sell_net','profit','return_pct','entry_type','sell_reason','execution_mode'
    ]);
}

function te_analysis_recent_rows(array $rows, int $limit, array $timeKeys): array
{
    usort($rows, static function($a,$b) use($timeKeys){
        $ta='';$tb='';foreach($timeKeys as$key){if($ta===''&&!empty($a[$key]))$ta=(string)$a[$key];if($tb===''&&!empty($b[$key]))$tb=(string)$b[$key];}
        return strcmp($tb,$ta);
    });
    return $limit > 0 ? array_slice($rows,0,$limit) : [];
}

function te_analysis_compact_orders(array $rows, int $limit): array
{
    $active=[];$closed=[];
    foreach($rows as$row){
        if(!is_array($row))continue;
        $compact=te_analysis_pick($row,[
            'order_id','strategy_key','strategy_id','market','symbol','name','exchange','side','qty','filled_qty','price','avg_fill_price',
            'stop_price','target_price','status','approved','execution_mode','signal_type','reason','block_reason','created_at','received_at',
            'approved_at','approval_origin','approval_mode_at_approval','was_approved','approval_completed_at','sent_at','filled_at','cancelled_at','expires_at','closed_at','updated_at','terminal_reason','handoff_state','broker_ingest_state','broker_seen_at','broker_seen_cycle_id','broker_ingest_source','broker_input_origin','broker_input_path','expired_reason','reject_reason','approval_block_reason','approval_blocked_at','approval_last_checked_at'
        ]);
        if(isset($compact['name']))$compact['name']=te_analysis_text($compact['name'],120);
        if(isset($compact['reason']))$compact['reason']=te_analysis_text($compact['reason'],500);
        if(isset($compact['block_reason']))$compact['block_reason']=te_analysis_text($compact['block_reason'],500);
        if(is_array($row['broker_ingest_diagnostic']??null))$compact['broker_ingest_diagnostic']=$row['broker_ingest_diagnostic'];
        $status=strtoupper((string)($row['status']??''));
        if(in_array($status,['PENDING','APPROVED','SENT','WORKING','PARTIAL','CANCEL_REQUESTED'],true))$active[]=$compact;else$closed[]=$compact;
    }
    $closed=te_analysis_recent_rows($closed,max(0,$limit-count($active)),['updated_at','filled_at','created_at']);
    return array_slice(array_merge($active,$closed),0,max($limit,count($active)));
}

function te_analysis_compact_model_state($states, int $limit): array
{
    if(!is_array($states))return[];
    $phaseCounts=[];$active=[];
    foreach($states as$key=>$row){
        if(!is_array($row))continue;
        $phase=(string)($row['phase']??'UNSET');$phaseCounts[$phase]=(int)($phaseCounts[$phase]??0)+1;
        if(in_array($phase,['ENTRY_PENDING','IN_POSITION','ARMED','BOX_ACTIVE'],true)){
            $active[]=['key'=>(string)$key]+te_analysis_pick($row,['phase','pending_order_id','last_reason','scenario_id','entry_type','armed_at','updated_at']);
        }
    }
    return['phase_counts'=>$phaseCounts,'active'=>array_slice($active,0,max(0,$limit))];
}

function te_analysis_compact_extension($shadow, int $limit): array
{
    if(!is_array($shadow)||$limit<1)return[];
    $records=is_array($shadow['records']??null)?$shadow['records']:null;
    $rows=$records!==null?array_values($records):(is_array($shadow['rows']??null)?array_values($shadow['rows']):[]);
    $rows=te_analysis_recent_rows($rows,$limit,['updated_at','labelled_at','filled_at','created_at']);
    $out=[];foreach($rows as$row)if(is_array($row)){$compact=te_analysis_pick($row,['market','symbol','entry_type','fill_price','stop_price','risk','label','labelled_at','filled_at','updated_at']);$features=te_analysis_compact_metrics(is_array($row['features']??null)?$row['features']:[]);if($features)$compact['features']=$features;$out[]=$compact;}
    return['type'=>(string)($shadow['extension_type']??''),'updated_at'=>(string)($shadow['updated_at']??''),'stats'=>is_array($shadow['stats']??null)?$shadow['stats']:[],'total_count'=>$records!==null?count($records):count($rows),'included_count'=>count($out),'rows'=>$out];
}

function te_analysis_payload(array $c, array $spec, array $limits = []): array
{
    if(!$limits)$limits=te_analysis_limits(0);
    $runtime=(string)$spec['runtime'];$key=(string)$spec['key'];
    $state=te_analysis_load_first([$runtime.'/engine_state.json',$runtime.'/state.json'],[]);
    $currentScan=te_load($runtime.'/scan_progress.json',[]);
    $candidateBook=te_load($runtime.'/candidates.json',[]);if(!is_array($candidateBook))$candidateBook=[];
    $capital=te_load($runtime.'/capital.json',[]);$statistics=te_load($runtime.'/stats.json',[]);
    $cohortConfig=array_merge($c,['strategy_key'=>$key,'strategy_rev'=>(string)($state['strategy_rev']??'')]);
    $positions=te_positions_normalize(te_load($runtime.'/positions.json',[]));foreach($positions as$pk=>$pp){if(!is_array($pp))continue;$positions[$pk]['position_cohort']=te_position_cohort($cohortConfig,$pp);}
    $trades=te_load($runtime.'/trades.json',[]);if(!is_array($trades))$trades=[];foreach($trades as$tk=>$tt){if(!is_array($tt))continue;$trades[$tk]['position_cohort']=te_position_cohort($cohortConfig,$tt);}
    $brokerRoot=te_load($c['broker_orders_file'],[]);$brokerRows=is_array($brokerRoot['orders']??null)?$brokerRoot['orders']:[];
    $intentRoot=te_load($c['intents_file'],[]);$intentRows=is_array($intentRoot['intents']??null)?$intentRoot['intents']:[];
    $archiveRoot=te_load($c['intents_archive_file']??'',[]);$archiveRows=is_array($archiveRoot['intents']??null)?$archiveRoot['intents']:[];
    $strategyIntents=te_analysis_filter_orders($intentRows,$key);$strategyBrokerOrders=te_analysis_filter_orders($brokerRows,$key);
    $activePositions=[];foreach($positions as$position)if(is_array($position)&&te_active_position($position))$activePositions[]=te_analysis_compact_position($position);
    $recentTrades=te_analysis_recent_rows($trades,(int)$limits['trades'],['sell_time','buy_time']);$compactTrades=[];foreach($recentTrades as$row)if(is_array($row))$compactTrades[]=te_analysis_compact_trade($row);
    $marketRegime=te_load($runtime.'/market_regime.json',[]);$marketEvidence=$key==='das'?te_analysis_das_market_evidence($c,$candidateBook,$positions,is_array($marketRegime)?$marketRegime:[],$limits):[];
    return[
        'snapshot_type'=>'strategy_analysis_history_compact','schema'=>'te_analysis_v11','created_at'=>te_now(),
        'strategy'=>['key'=>$key,'label'=>$spec['label'],'file'=>$spec['file'],'status'=>(string)($spec['status']??'CORE'),'alpha_type'=>(string)($spec['alpha_type']??''),'runtime_exists'=>is_dir($runtime),'strategy_version'=>(string)($state['strategy_rev']??'')],
        'engine'=>['version'=>TE_VERSION,'rev'=>TE_REV,'schema'=>TE_SCHEMA],
        'version_audit'=>['runtime_version'=>(string)($state['version']??''),'current_version'=>TE_VERSION,'match'=>(string)($state['version']??'')===TE_VERSION,'runtime_rev'=>(string)($state['rev']??''),'current_rev'=>TE_REV],
        'broker_summary'=>te_broker_runtime_summary($c,$brokerRows),
        'last_completed_tick'=>te_analysis_compact_tick($state),
        'current_scan'=>te_analysis_compact_scan($currentScan),
        'last_completed_scan'=>te_analysis_last_completed_scan($candidateBook),
        'candidate_snapshot'=>te_analysis_candidate_summary($candidateBook,(int)$limits['candidates_per_market'],$strategyIntents,$strategyBrokerOrders),
        'capital'=>$capital,'statistics'=>$statistics,
        'positions'=>['count'=>count($activePositions),'rows'=>$activePositions],
        'trades'=>['total_count'=>count($trades),'included_count'=>count($compactTrades),'rows'=>$compactTrades],
        'order_pipeline'=>te_order_pipeline_counts_for_key($strategyIntents,$strategyBrokerOrders),
        'order_intents'=>te_analysis_compact_orders($strategyIntents,(int)$limits['orders']),
        'archived_order_intents'=>te_analysis_compact_orders(te_analysis_filter_orders($archiveRows,$key),(int)$limits['archive']),
        'broker_orders'=>te_analysis_compact_orders($strategyBrokerOrders,(int)$limits['orders']),
        'market_regime'=>te_analysis_compact_regime($marketRegime),
        'market_data_evidence'=>$marketEvidence,
        'risk_state'=>te_load($runtime.'/risk_state.json',[]),
        'model_state'=>te_analysis_compact_model_state(te_load($runtime.'/model_state.json',[]),(int)$limits['model_states']),
        'extension_state'=>te_analysis_compact_extension(te_load($runtime.'/extension_state.json',[]),(int)$limits['extension_rows']),
        'validation_state'=>te_analysis_validation_state($c,$key,(int)$limits['model_states']),
        'logs'=>['user_summary'=>te_user_log_summary($state,$currentScan),'engine_tail'=>te_tail($runtime.'/engine.log',(int)$limits['logs']),'error_tail'=>te_tail($runtime.'/error.log',(int)$limits['logs'])],
        'omitted'=>['candidate_book_raw','close_snapshot_duplicate','fill_ledger_raw','full_model_state','full_logs','raw_api_responses','full_universe_ohlcv','sector_benchmark_raw_bars'],
    ];
}

function te_analysis_last_completed_scan(array $candidateBook): array
{
    $out=[];
    foreach(['KR','US','JP']as$market){
        $row=is_array($candidateBook['markets'][$market]??null)?$candidateBook['markets'][$market]:[];
        $last=is_array($row['last_completed_scan']??null)?$row['last_completed_scan']:[];
        $out[$market]=te_analysis_pick($last,['status','data_date','completed_at','processed_count','expected_count','data_error_count','data_short_count','filtered_count','watch_count','buy_signal_count','buy_intent_count']);
        if(!$out[$market])$out[$market]=['status'=>'NO_SNAPSHOT','data_date'=>(string)($row['data_date']??''),'completed_at'=>(string)($row['captured_at']??'')];
    }
    return$out;
}

function te_analysis_snapshot_basis(array $candidateBook): array
{
    $out=[];foreach(['KR','US','JP']as$market){$row=is_array($candidateBook['markets'][$market]??null)?$candidateBook['markets'][$market]:[];$out[$market]=['basis'=>(string)($row['basis']??'NO_SNAPSHOT'),'data_date'=>(string)($row['data_date']??''),'frozen'=>!empty($row['frozen']),'count'=>count(is_array($row['rows']??null)?$row['rows']:[])];}return$out;
}

function te_runtime_version_audit(array $strategies): array
{
    $rows=[];$all=true;$stale=[];
    foreach($strategies as$key=>$payload){
        $audit=is_array($payload['version_audit']??null)?$payload['version_audit']:[];
        $match=!empty($audit['match']);if(!$match){$all=false;$stale[]=(string)$key;}
        $rows[$key]=['match'=>$match,'runtime_version'=>(string)($audit['runtime_version']??''),'current_version'=>(string)($audit['current_version']??TE_VERSION),'runtime_rev'=>(string)($audit['runtime_rev']??''),'current_rev'=>(string)($audit['current_rev']??TE_REV)];
    }
    return['all_current'=>$all,'status'=>$all?'CURRENT':'UNIFIED_PARTIAL_STALE','stale_count'=>count($stale),'stale_strategies'=>$stale,'strategies'=>$rows];
}

function te_unified_strategy_summary(array $strategies): array
{
    $out=[];
    foreach($strategies as$key=>$payload){
        if(!is_array($payload))continue;
        $strategy=is_array($payload['strategy']??null)?$payload['strategy']:[];
        // analysis payload contract uses "statistics"; keep "stats" only as legacy fallback.
        $stats=is_array($payload['statistics']??null)?$payload['statistics']:(is_array($payload['stats']??null)?$payload['stats']:[]);
        $validation=is_array($payload['validation_state']??null)?$payload['validation_state']:[];
        $candidate=is_array($payload['candidate_snapshot']??null)?$payload['candidate_snapshot']:[];
        $markets=is_array($candidate['markets']??null)?$candidate['markets']:[];
        $candidateCount=0;$buyPending=0;$rowsExported=0;
        foreach(['KR','US','JP']as$m){
            $mr=is_array($markets[$m]??null)?$markets[$m]:[];
            $rows=is_array($mr['rows']??null)?$mr['rows']:[];$rowsExported+=count($rows);
            foreach($rows as$r){if(!is_array($r))continue;$st=strtoupper((string)($r['state']??$r['status']??''));if($st==='CANDIDATE')$candidateCount++;if(in_array($st,['BUY_PENDING','BUY','ORDER_ELIGIBLE'],true))$buyPending++;}
        }
        $va=is_array($payload['version_audit']??null)?$payload['version_audit']:[];
        $out[$key]=[
            'label'=>(string)($strategy['label']??strtoupper((string)$key)),
            'strategy_status'=>(string)($strategy['status']??''),
            'alpha_type'=>(string)($strategy['alpha_type']??''),
            'runtime_current'=>!empty($va['match']),
            'runtime_version'=>(string)($va['runtime_version']??''),
            'current_version'=>(string)($va['current_version']??TE_VERSION),
            'open_positions'=>(int)($stats['open_positions']??($payload['positions']['count']??$payload['positions_count']??0)),
            'closed_trades'=>(int)($stats['closed_trades']??0),
            'wins'=>(int)($stats['wins']??0),
            'losses'=>(int)($stats['losses']??0),
            'win_rate'=>isset($stats['win_rate'])?(float)$stats['win_rate']:null,
            'avg_return_pct'=>isset($stats['avg_return_pct'])?(float)$stats['avg_return_pct']:null,
            'current_cohort'=>is_array($stats['cohorts']['CURRENT']??null)?$stats['cohorts']['CURRENT']:[],
            'candidate_count_exported'=>$candidateCount,
            'buy_pending_count_exported'=>$buyPending,
            'candidate_rows_exported'=>$rowsExported,
            'validation_pending_count'=>(int)($validation['pending_count']??0),
            'validation_strategy_match'=>(bool)($validation['summary_strategy_match']??false),
        ];
    }
    return$out;
}

function te_broker_open_position_book(array $c,array $orders): array
{
    $rows=[];foreach($orders as$o)if(is_array($o)&&max(0,(int)($o['filled_qty']??0))>0)$rows[]=$o;
    usort($rows,static function($a,$b){$ta=strtotime((string)($a['filled_at']??$a['last_fill_at']??$a['processed_at']??$a['created_at']??''));$tb=strtotime((string)($b['filled_at']??$b['last_fill_at']??$b['processed_at']??$b['created_at']??''));$ta=$ta===false?0:$ta;$tb=$tb===false?0:$tb;if($ta===$tb)return strcmp((string)($a['order_id']??''),(string)($b['order_id']??''));return$ta<=>$tb;});
    $lots=[];
    foreach($rows as$o){
        $market=strtoupper((string)($o['market']??''));$symbol=strtoupper((string)($o['symbol']??''));$strategy=strtolower((string)($o['strategy_key']??''));$side=strtoupper((string)($o['side']??''));$filled=max(0,(int)($o['filled_qty']??0));$price=max(0.0,(float)($o['avg_fill_price']??$o['price']??0));
        if(!in_array($market,['KR','US','JP'],true)||$symbol===''||$strategy===''||$filled<1||$price<=0||!in_array($side,['BUY','SELL'],true))continue;
        $key=$strategy.':'.$market.':'.$symbol;$lot=is_array($lots[$key]??null)?$lots[$key]:['strategy'=>$strategy,'market'=>$market,'symbol'=>$symbol,'qty'=>0,'avg_cost'=>0.0,'mark_price'=>$price,'name'=>(string)($o['name']??$symbol),'risk_group'=>'','asset_type'=>(string)($o['asset_type']??''),'is_inverse'=>!empty($o['is_inverse']),'is_leveraged'=>!empty($o['is_leveraged'])];
        if($side==='BUY'){$oldQty=(int)$lot['qty'];$newQty=$oldQty+$filled;$lot['avg_cost']=$newQty>0?(($oldQty*(float)$lot['avg_cost'])+($filled*$price))/$newQty:0.0;$lot['qty']=$newQty;}else{$lot['qty']=max(0,(int)$lot['qty']-$filled);if($lot['qty']===0)$lot['avg_cost']=0.0;}
        $cache=te_load($c['quote_cache'].'/'.te_safe($market.'_'.$symbol).'.json',[]);
        $cachedQuote=is_array($cache['quote']??null)?$cache['quote']:[];
        $markPrice=max(0.0,(float)($cachedQuote['price']??0));
        $markTs=(int)($cachedQuote['ts']??$cache['saved_at']??0);
        $lot['mark_price']=$markPrice>0?$markPrice:$price;
        $lot['mark_source']=$markPrice>0?'CURRENT_QUOTE_CACHE':'LAST_FILL_FALLBACK';
        $lot['mark_age_sec']=$markTs>0?max(0,time()-$markTs):null;$lot['name']=(string)($o['name']??$lot['name']);$lot['asset_type']=(string)($o['asset_type']??$lot['asset_type']);$lot['is_inverse']=array_key_exists('is_inverse',$o)?(bool)$o['is_inverse']:(bool)$lot['is_inverse'];$lot['is_leveraged']=array_key_exists('is_leveraged',$o)?(bool)$o['is_leveraged']:(bool)$lot['is_leveraged'];$group=trim((string)($o['risk_group']??''));if($group!=='')$lot['risk_group']=$group;$lots[$key]=$lot;
    }
    $open=[];foreach($lots as$lot){if((int)$lot['qty']<1)continue;if((string)$lot['risk_group']==='')$lot['risk_group']=te_infer_risk_group((string)$lot['market'],(string)$lot['symbol'],(string)$lot['name'],(string)$lot['asset_type'],!empty($lot['is_inverse']),!empty($lot['is_leveraged']));$lot['amount']=round((int)$lot['qty']*(float)$lot['mark_price'],4);$lot['cost_basis']=round((int)$lot['qty']*(float)$lot['avg_cost'],4);$open[]=$lot;}
    return$open;
}

function te_global_portfolio_guard_summary(array $c): array
{
    $orders=te_broker_orders($c);$open=te_broker_open_position_book($c,$orders);$marketAmount=['KR'=>0.0,'US'=>0.0,'JP'=>0.0];$symbols=[];$groups=[];$correlations=[];$expired=[];
    foreach($open as$p){$market=(string)$p['market'];$symbol=(string)$p['symbol'];$amount=max(0.0,(float)$p['amount']);$key=$market.':'.$symbol;$marketAmount[$market]+=$amount;if(!isset($symbols[$key]))$symbols[$key]=['market'=>$market,'symbol'=>$symbol,'amount'=>0.0,'strategies'=>[],'qty'=>0];$symbols[$key]['amount']+=$amount;$symbols[$key]['qty']+=(int)$p['qty'];$symbols[$key]['strategies'][(string)$p['strategy']]=true;$group=(string)($p['risk_group']??'');if($group!=='')$groups[$market.':'.$group]=(float)($groups[$market.':'.$group]??0)+$amount;$corr=te_correlation_group($group,$market,$symbol,(string)($p['name']??$symbol),(string)($p['asset_type']??''),!empty($p['is_inverse']),!empty($p['is_leveraged']));if(te_correlation_group_limited($corr)){$ck=(string)$p['strategy'].':'.$market.':'.$symbol;if(!isset($correlations[$corr]))$correlations[$corr]=['correlation_group'=>$corr,'amount'=>0.0,'positions'=>[],'strategies'=>[]];$correlations[$corr]['amount']+=$amount;$correlations[$corr]['positions'][$ck]=true;$correlations[$corr]['strategies'][(string)$p['strategy']]=true;}}
    foreach($orders as$o){if(!is_array($o)||strtoupper((string)($o['status']??''))!=='EXPIRED')continue;$ek=strtolower((string)($o['strategy_key']??'')).':'.strtoupper((string)($o['market']??'')).':'.strtoupper((string)($o['symbol']??''));$expired[$ek]=(int)($expired[$ek]??0)+1;}
    $seeds=['KR'=>(float)$c['seed_kr'],'US'=>(float)$c['seed_us'],'JP'=>(float)$c['seed_jp']];
    $marketRows=[];foreach(['KR','US','JP']as$m){$amt=max(0.0,(float)$marketAmount[$m]);$seed=max(0.0001,(float)$seeds[$m]);$marketRows[$m]=['amount'=>round($amt,4),'aggregate_seed'=>round($seed,4),'invest_pct'=>round($amt/$seed*100,3),'limit_pct'=>round(TE_MAX_MARKET_INVEST_PCT*100,1),'over_limit'=>$amt/$seed>TE_MAX_MARKET_INVEST_PCT];}
    $symbolRows=[];foreach($symbols as$r){$set=array_keys($r['strategies']);$seed=max(0.0001,(float)$seeds[$r['market']]);$pct=(float)$r['amount']/$seed*100;$symbolRows[]=['market'=>$r['market'],'symbol'=>$r['symbol'],'qty'=>$r['qty'],'amount'=>round((float)$r['amount'],4),'strategy_count'=>count($set),'strategies'=>$set,'invest_pct'=>round($pct,3),'duplicate_strategy'=>count($set)>1,'over_limit'=>$pct>25.0||count($set)>1];}
    usort($symbolRows,static function($a,$b){$cmp=(float)$b['amount']<=>(float)$a['amount'];return$cmp!==0?$cmp:strcmp((string)$a['symbol'],(string)$b['symbol']);});
    $groupRows=[];foreach($groups as$key=>$value){$parts=explode(':',$key,2);$m=$parts[0]??'';$group=$parts[1]??'';$seed=max(0.0001,(float)($seeds[$m]??1));$groupRows[]=['market'=>$m,'risk_group'=>$group,'amount'=>round((float)$value,4),'invest_pct'=>round((float)$value/$seed*100,3),'limit_pct'=>35.0,'over_limit'=>(float)$value/$seed>0.35];}
    usort($groupRows,static function($a,$b){$cmp=(float)$b['amount']<=>(float)$a['amount'];return$cmp!==0?$cmp:strcmp((string)$a['risk_group'],(string)$b['risk_group']);});
    $correlationRows=[];foreach($correlations as$row){$count=count($row['positions']);$correlationRows[]=['correlation_group'=>$row['correlation_group'],'position_count'=>$count,'strategies'=>array_keys($row['strategies']),'symbols'=>array_keys($row['positions']),'amount'=>round((float)$row['amount'],4),'global_limit'=>(int)$c['max_correlation_positions_global'],'over_limit'=>$count>(int)$c['max_correlation_positions_global']];}usort($correlationRows,static function($a,$b){$cmp=(int)$b['position_count']<=>(int)$a['position_count'];return$cmp!==0?$cmp:strcmp((string)$a['correlation_group'],(string)$b['correlation_group']);});
    arsort($expired,SORT_NUMERIC);$expiredRows=[];foreach(array_slice($expired,0,20,true)as$key=>$count){$parts=explode(':',$key,3);$expiredRows[]=['strategy'=>$parts[0]??'','market'=>$parts[1]??'','symbol'=>$parts[2]??'','expired_count'=>$count,'repeat'=>$count>1];}
    return['policy'=>['market_limit_pct'=>80.0,'symbol_limit_pct'=>25.0,'risk_group_limit_pct'=>35.0,'max_strategies_per_symbol'=>1,'correlation_group_global_position_limit'=>(int)$c['max_correlation_positions_global'],'correlation_new_entries_only'=>true,'exposure_basis'=>'OPEN_QTY_X_CURRENT_MARK_WITH_LAST_FILL_FALLBACK'],'markets'=>$marketRows,'top_symbols'=>array_slice($symbolRows,0,20),'top_risk_groups'=>array_slice($groupRows,0,20),'top_correlation_groups'=>array_slice($correlationRows,0,20),'repeated_expired'=>$expiredRows,'violations'=>['market'=>count(array_filter($marketRows,static function($r){return!empty($r['over_limit']);})),'symbol'=>count(array_filter($symbolRows,static function($r){return!empty($r['over_limit']);})),'risk_group'=>count(array_filter($groupRows,static function($r){return!empty($r['over_limit']);})),'correlation_group'=>count(array_filter($correlationRows,static function($r){return!empty($r['over_limit']);})),'repeated_expired'=>count(array_filter($expiredRows,static function($r){return!empty($r['repeat']);}))]];
}

function te_all_analysis_payload(array $c, array $limits = []): array
{
    if(!$limits)$limits=te_analysis_limits(0);$strategies=[];
    foreach(te_analysis_specs($c)as$key=>$spec)$strategies[$key]=te_analysis_payload($c,$spec,$limits);
    $audit=te_runtime_version_audit($strategies);return['snapshot_type'=>'all_strategy_analysis_history_compact','schema'=>'te_analysis_v12_chatgpt','export_type'=>'UNIFIED','unified_status'=>(string)$audit['status'],'warning'=>!empty($audit['all_current'])?'':'UNIFIED_PARTIAL_STALE_RUNTIME_SNAPSHOT','included_strategies'=>array_values(array_keys($strategies)),'summary_by_strategy'=>te_unified_strategy_summary($strategies),'created_at'=>te_now(),'chatgpt_analysis_guide'=>te_chatgpt_analysis_guide(),'engine'=>['version'=>TE_VERSION,'rev'=>TE_REV,'schema'=>TE_SCHEMA],'broker_summary'=>te_broker_runtime_summary($c,te_broker_orders($c)),'portfolio_guard'=>te_global_portfolio_guard_summary($c),'runtime_version_audit'=>$audit,'comparison'=>te_compare_rows($c),'strategies'=>$strategies];
}

function te_analysis_build_json(array $c, string $strategy, bool $all): array
{
    for($level=0;$level<=4;$level++){
        $limits=te_analysis_limits($level);
        if($all)$payload=te_all_analysis_payload($c,$limits);else{$spec=te_analysis_spec($c,$strategy);if($spec===null)return['ok'=>false,'error'=>'UNKNOWN_STRATEGY','json'=>'','bytes'=>0];$payload=te_analysis_payload($c,$spec,$limits);}
        $payload['size_policy']=['max_bytes'=>TE_ANALYSIS_MAX_BYTES,'reduction_level'=>$level,'limits'=>$limits,'compact_json'=>true];
        $json=te_json($payload,false);$bytes=strlen($json);
        if($bytes<TE_ANALYSIS_MAX_BYTES)return['ok'=>true,'json'=>$json,'bytes'=>$bytes,'level'=>$level,'payload'=>$payload];
        unset($payload,$json);
    }
    $payload=['snapshot_type'=>$all?'all_strategy_analysis_summary':'strategy_analysis_summary','schema'=>'te_analysis_v12_chatgpt','created_at'=>te_now(),'chatgpt_analysis_guide'=>te_chatgpt_analysis_guide(),'engine'=>['version'=>TE_VERSION,'rev'=>TE_REV],'comparison'=>te_compare_rows($c),'size_policy'=>['max_bytes'=>TE_ANALYSIS_MAX_BYTES,'reduction_level'=>'summary_only','compact_json'=>true],'warning'=>'DETAIL_REMOVED_TO_ENFORCE_SIZE_LIMIT'];
    $json=te_json($payload,false);return['ok'=>strlen($json)<TE_ANALYSIS_MAX_BYTES,'json'=>$json,'bytes'=>strlen($json),'level'=>5,'payload'=>$payload];
}

function te_download_analysis(array $c,string $strategy,bool $all,bool $chatgpt=false): void
{
    $built=te_analysis_build_json($c,$strategy,$all);
    if(empty($built['ok'])){http_response_code(500);te_json_response(['ok'=>false,'message'=>'분석 파일 생성 실패']);return;}
    if($chatgpt){
        $name=$all
            ? 'chatgpt_trade_UNIFIED_'.date('Ymd_His').'.json'
            : 'chatgpt_trade_'.strtoupper(te_safe($strategy)).'_'.date('Ymd_His').'.json';
    }else{
        $name=$all?'all_models_analysis_'.date('Ymd_His').'.json':te_safe($strategy).'_analysis_'.date('Ymd_His').'.json';
    }
    if(headers_sent())return;header('Content-Type: application/json; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$name.'"');header('Content-Length: '.(int)$built['bytes']);header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');echo$built['json'];
}

function te_prune_analysis_snapshots(string $dir,string $prefix,int $keep): int
{
    $files=glob(rtrim($dir,'/').'/'.$prefix.'_analysis_*.json')?:[];usort($files,static function($a,$b){return((int)@filemtime($b))<=>((int)@filemtime($a));});$removed=0;foreach(array_slice($files,max(1,$keep))as$f)if(@unlink($f))$removed++;return$removed;
}
function te_save_analysis_snapshot(array $c,bool $all): array
{
    $built=te_analysis_build_json($c,$c['strategy_key'],$all);if(empty($built['ok']))return['ok'=>false,'message'=>'분석 파일 생성 실패','bytes'=>(int)($built['bytes']??0)];
    $dir=$all?$c['broker_runtime'].'/analysis_snapshots':$c['runtime'].'/analysis_snapshots';if(!is_dir($dir))@mkdir($dir,0775,true);
    $prefix=$all?'all_models':te_safe((string)$c['strategy_key']);$file=$dir.'/'.$prefix.'_analysis_'.date('Ymd_His').'.json';$tmp=$file.'.tmp.'.getmypid();$ok=@file_put_contents($tmp,$built['json'].PHP_EOL,LOCK_EX)!==false;if($ok)$ok=@rename($tmp,$file);else@unlink($tmp);$keep=$all?TE_ANALYSIS_SNAPSHOT_KEEP_ALL:TE_ANALYSIS_SNAPSHOT_KEEP_STRATEGY;$pruned=$ok?te_prune_analysis_snapshots($dir,$prefix,$keep):0;
    return['ok'=>$ok,'file'=>$file,'bytes'=>$ok?(int)@filesize($file):0,'max_bytes'=>TE_ANALYSIS_MAX_BYTES,'reduction_level'=>$built['level'],'snapshot_keep'=>$keep,'pruned'=>$pruned,'created_at'=>te_now(),'mode'=>$all?'all':'current'];
}

function te_compare_config($raw): array
{
    if(is_array($raw)&&$raw)return$raw;
    return [
        'dts'=>['label'=>'DTS','runtime'=>__DIR__.'/dts_runtime','file'=>'dts.php','status'=>'CORE','alpha_type'=>'INTRADAY_TREND'],
        'abc'=>['label'=>'ABC','runtime'=>__DIR__.'/abc_runtime','file'=>'abc.php','status'=>'CORE','alpha_type'=>'PULLBACK_CONTINUATION'],
        'das'=>['label'=>'DAS','runtime'=>__DIR__.'/das_runtime','file'=>'das.php','status'=>'CORE','alpha_type'=>'RELATIVE_STRENGTH'],
        'stc26'=>['label'=>'STC26','runtime'=>__DIR__.'/stc26_runtime','file'=>'stc26.php','status'=>'CHALLENGER','alpha_type'=>'OVERSOLD_REVERSAL'],
    ];
}
function te_compare_rows(array $c): array
{
    $out=[];
    foreach($c['compare_models'] as $key=>$m){
        if(!is_array($m))continue;$runtime=(string)($m['runtime']??(__DIR__.'/'.strtolower((string)$key).'_runtime'));
        $state=te_load($runtime.'/engine_state.json',[]);$cap=te_load($runtime.'/capital.json',[]);$stats=te_load($runtime.'/stats.json',[]);
        $out[]=['key'=>(string)$key,'label'=>(string)($m['label']??strtoupper((string)$key)),'file'=>(string)($m['file']??''),
            'status'=>(string)($state['engine']??'WAIT'),'last_tick'=>(string)($state['last_tick']??'-'),
            'kr_equity'=>(float)($cap['KR']['equity']??10000000.0),'kr_return_pct'=>(float)($cap['KR']['profit_pct']??0),
            'us_equity'=>(float)($cap['US']['equity']??6000.0),'us_return_pct'=>(float)($cap['US']['profit_pct']??0),
            'jp_equity'=>(float)($cap['JP']['equity']??1000000.0),'jp_return_pct'=>(float)($cap['JP']['profit_pct']??0),
            'open_positions'=>(int)($stats['open_positions']??0),'closed_trades'=>(int)($stats['closed_trades']??0),
            'wins'=>(int)($stats['wins']??0),'losses'=>(int)($stats['losses']??0),'win_rate'=>(float)($stats['win_rate']??0)];
    }
    return$out;
}
function te_render_comparison(array $c): void
{
    echo '<section><div class="section-head"><h2>전체 모델 비교</h2><a class="btn" href="trade_dashboard.php">통합 대시보드에서 보기</a></div><p class="muted">모델 간 레짐·수익률·승률 비교는 통합 대시보드에서 한 번만 표시합니다.</p></section>';
}

function te_status(array $c): array{$positions=array_values(te_positions_normalize(te_load($c['positions_file'],[])));$orders=te_broker_orders($c);return['ok'=>true,'engine'=>TE_VERSION,'rev'=>TE_REV,'strategy'=>$c['strategy_file_id'],'strategy_status'=>tv_strategy_status($c),'alpha_type'=>tv_alpha_type($c),'strategy_hash'=>tv_strategy_hash($c),'system_hash'=>tv_system_hash($c),'slot_policy'=>['scope'=>tv_strategy_status($c)==='CORE'?'CORE_SHARED_PER_MARKET':'PER_STRATEGY_PER_MARKET','limits'=>$c['max_positions_by_market'],'total'=>$c['max_positions_total'],'usage'=>te_slot_summary($c,$positions,$orders)],'correlation_policy'=>te_correlation_summary($c,$positions,$orders),'state'=>te_load($c['state_file'],[]),'capital'=>te_load($c['capital_file'],[]),'stats'=>te_load($c['stats_file'],[]),'regimes'=>te_load($c['regime_file'],[]),'scan_progress'=>te_load($c['scan_progress_file'],[]),'close_snapshot'=>te_load($c['close_snapshot_file'],[]),'validation'=>te_validation_status($c),'comparison'=>te_compare_rows($c)];}

function te_self_test(array $c): array
{
    $rankOk=$c['rank_callback']===''||function_exists($c['rank_callback']);
    $meta=[];$metaCallback=(string)($c['meta_callback']??'');
    if($metaCallback!==''&&function_exists($metaCallback)){$tmp=$metaCallback();if(is_array($tmp))$meta=$tmp;}
    $required=array_values(array_unique(array_merge($c['required_engine_capabilities']??[],is_array($meta['required_engine_capabilities']??null)?$meta['required_engine_capabilities']:[])));
    $missingCapabilities=[];foreach($required as$cap)if(empty($c['engine_capabilities'][(string)$cap]))$missingCapabilities[]=(string)$cap;
    $tradeList=te_trade_list_load($c);$universe=$tradeList['ok']?te_universe($c):[];$kr=0;$us=0;$jp=0;$usExchangeMissing=0;$jpExchangeMissing=0;
    foreach($universe as$row){if(($row['market']??'')==='KR')$kr++;elseif(($row['market']??'')==='US'){$us++;if(te_us_exchange((string)($row['exchange']??''))==='')$usExchangeMissing++;}elseif(($row['market']??'')==='JP'){$jp++;if(te_jp_exchange((string)($row['exchange']??''))==='')$jpExchangeMissing++;}}
    $listOk=!empty($tradeList['ok'])&&$kr>0&&$us>0&&$jp>0&&$usExchangeMissing===0&&$jpExchangeMissing===0;
    $guardOk=$c['entry_guard_callback']===''||function_exists($c['entry_guard_callback']);$callbacksOk=function_exists($c['model_callback'])&&function_exists($c['exit_callback'])&&$rankOk&&$guardOk;
    return['ok'=>PHP_VERSION_ID>=70400&&$callbacksOk&&$listOk&&!$missingCapabilities,
        'php'=>PHP_VERSION,'engine'=>TE_VERSION,
        'callbacks'=>['model'=>function_exists($c['model_callback']),'rank'=>$c['rank_callback']===''?null:function_exists($c['rank_callback']),'exit'=>function_exists($c['exit_callback']),'entry_guard'=>$c['entry_guard_callback']===''?null:function_exists($c['entry_guard_callback']),'meta'=>$metaCallback===''?null:function_exists($metaCallback)],
        'contract'=>['required_capabilities'=>$required,'missing_capabilities'=>$missingCapabilities,'ok'=>!$missingCapabilities],
        'requirements'=>array_keys($c['requirements']),'files'=>['runtime'=>is_dir($c['runtime']),'intents'=>is_file($c['intents_file']),'trade_list'=>is_file($c['trade_list_file'])],
        'trade_list'=>['ok'=>$listOk,'file'=>$c['trade_list_file'],'schema'=>(string)($tradeList['meta']['schema']??''),'version'=>(string)($tradeList['meta']['version']??''),'KR'=>$kr,'US'=>$us,'JP'=>$jp,'us_exchange_missing'=>$usExchangeMissing,'jp_exchange_missing'=>$jpExchangeMissing,'error'=>(string)($tradeList['error']??''),'errors'=>$tradeList['errors']??[]],
        'capital_seed'=>['KR'=>$c['seed_kr'],'US'=>$c['seed_us'],'JP'=>$c['seed_jp']],
        'slot_policy'=>['scope'=>tv_strategy_status($c)==='CORE'?'CORE_SHARED_PER_MARKET':'PER_STRATEGY_PER_MARKET','KR'=>$c['max_positions_by_market']['KR'],'US'=>$c['max_positions_by_market']['US'],'JP'=>$c['max_positions_by_market']['JP'],'total'=>$c['max_positions_total'],'max_correlation_positions_per_strategy'=>$c['max_correlation_positions_per_strategy'],'max_correlation_positions_global'=>$c['max_correlation_positions_global']],
        'max_positions'=>$c['max_positions_by_market'],'strategy_settings'=>$c['strategy_settings'],'engine_capabilities'=>array_merge($c['engine_capabilities'],['analysis_download'=>true,'all_model_analysis_download'=>true,'chatgpt_analysis_download'=>true,'das_backtest_export'=>true,'common_forward_validation'=>true,'shared_validation_runtime'=>true,'signal_pipeline_outcome_separation'=>true,'validation_state_export'=>true,'validation_maturity_gate_export'=>true,'candidate_block_reason_summary'=>true,'abc_reset_evidence_export'=>true,'das_current_price_revalidation_export'=>true,'broker_market_wait_latency_split'=>true,'chatgpt_market_evidence'=>true,'analysis_under_8mb'=>true,'validation_exactly_once_export'=>true,'das_relative_strength_evidence'=>true,'approval_block_telemetry'=>true,'unified_export_identity'=>true,'summary_statistics_source_corrected'=>true,'validation_export_due_ts_repair'=>true,'analysis_compact_only'=>true,'mobile_candidate_cards'=>true,'candidate_order_progress'=>true]),'validation'=>['enabled'=>!empty($c['validation']['enabled']),'schema'=>TE_VALIDATION_SCHEMA,'primary_horizon'=>(string)($c['validation']['primary_horizon']??''),'horizons'=>array_values(array_map(static function($h){return(string)($h['label']??'');},(array)($c['validation']['horizons']??[]))),'runtime'=>(string)$c['validation_runtime'],'runtime_writable'=>is_dir($c['validation_runtime'])&&is_writable($c['validation_runtime'])],'quote_policy'=>['fresh'=>$c['quote_fresh_age_by_market'],'scan_max'=>$c['quote_scan_max_age_by_market'],'live_requote_max_age'=>$c['order_live_quote_max_age']],'ssl_verify'=>true];
}

function te_cli_summary(array $r): string{if(($r['message']??'')==='busy')return'['.te_now().'] engine BUSY';if(empty($r['ok']))return'['.te_now().'] engine ERROR '.($r['message']??'');$d=$r['state']['diag']??[];return'['.te_now().'] tick OK scan='.(int)($d['scan']??0).' buy_intent='.(int)($d['buy_intent']??0).' sell_intent='.(int)($d['sell_intent']??0).' fills='.(int)($d['fills']??0);}

function te_render(array $c): void
{
    $state = te_load($c['state_file'], []);
    $capital = te_load($c['capital_file'], []);
    $stats = te_load($c['stats_file'], []);
    $positions = array_values(te_positions_normalize(te_load($c['positions_file'], [])));
    $trades = te_load($c['trades_file'], []);
    $candidateBook = te_candidate_book_load($c);
    $candidates = te_candidate_rows($candidateBook);
    $regimes = te_load($c['regime_file'], []);
    $progress = te_load($c['scan_progress_file'], []);
    $brokerOrders = te_broker_orders($c);
    $intentRoot = te_load($c['intents_file'], []);
    $strategyIntents = is_array($intentRoot['intents'] ?? null) ? te_strategy_orders($c, $intentRoot['intents']) : [];
    $strategyBrokerOrders = te_strategy_orders($c, $brokerOrders);
    $pipeline = te_order_pipeline_counts_for_key($strategyIntents, $strategyBrokerOrders);
    $brokerRuntime = te_broker_runtime_summary($c, $brokerOrders);
    $slotSummary = te_slot_summary($c, $positions, $brokerOrders);
    $diag = is_array($state['diag'] ?? null) ? $state['diag'] : [];

    $engineRaw = strtoupper((string)($state['engine'] ?? 'WAIT'));
    $engineLabels = ['OK'=>'정상','WAIT'=>'대기','BUSY'=>'실행 중','RUNNING'=>'실행 중','ERROR'=>'오류','FAILED'=>'오류'];
    $engineLabel = (string)($engineLabels[$engineRaw] ?? '확인 필요');
    $activeBuy = (int)$pipeline['active_buy_intents'] + (int)$pipeline['active_buy_orders'];
    $closedTrades = (int)($stats['closed_trades'] ?? 0);
    $candidateCount = max(0, (int)($diag['buy'] ?? 0) + (int)($diag['watch'] ?? 0));

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="60;url=?"><title>'.te_h($c['app_name']).'</title><style>'.te_css().'</style></head><body><main>';
    echo '<div class="engine-head"><div><h1>'.te_h($c['app_name']).'</h1><p class="muted">'.te_h($c['app_ver']).' · 모델별 독립 운용</p></div></div>';
    echo '<div class="toolbar"><a class="btn" href="trade_dashboard.php">통합 대시보드</a><a class="btn primary" href="?mode=download_chatgpt_strategy_analysis">'.te_h(strtoupper((string)$c['strategy_key'])).' 개별 분석자료</a></div>';

    echo '<section class="cards six">';
    te_card('모델 상태', $engineLabel, $engineRaw === 'OK' ? '정상 운용 중' : '시스템 점검 정보 확인');
    te_card('보유 종목', count($positions), '체결 기준');
    te_card('현재 후보', $candidateCount, '최근 선발 결과');
    te_card('활성 매수', $activeBuy, '주문 진행 중');
    te_card('승률', number_format((float)($stats['win_rate'] ?? 0), 1).'%', '완료 거래 '.$closedTrades);
    te_card('완료 거래', $closedTrades, '누적 결과');
    echo '</section>';

    if(!empty($brokerRuntime['cycle_stale'])&&$activeBuy>0) {
        echo '<section class="broker-warning"><b>주문 처리 지연</b><p>활성 매수 주문이 있으나 브로커 갱신이 지연되고 있습니다. 하단 시스템 점검 정보를 확인하십시오.</p></section>';
    }

    echo '<section><h2>자본·성과</h2><div class="scroll"><table><tr><th>시장</th><th>가용현금</th><th>예약매수</th><th>평가자본</th><th>손익</th><th>수익률</th></tr>';
    foreach (['KR','US','JP'] as $market) {
        $row = $capital[$market] ?? [];
        $seed = (float)($row['seed'] ?? ($market === 'KR' ? 10000000.0 : ($market === 'JP' ? 1000000.0 : 6000.0)));
        echo '<tr><td>'.$market.'</td><td>'.te_money((float)($row['cash'] ?? $seed),$market).'</td><td>'.te_money((float)($row['reserved_buy'] ?? 0),$market).'</td><td>'.te_money((float)($row['equity'] ?? $seed),$market).'</td><td>'.te_money((float)($row['profit'] ?? 0),$market).'</td><td>'.number_format((float)($row['profit_pct'] ?? 0),2).'%</td></tr>';
    }
    echo '</table></div></section>';

    te_table('현재 보유', $positions, ['market','symbol','name','qty','entry_price','current_price','entry_type'], '현재 보유 종목이 없습니다.');
    if((string)($c['strategy_key']??'')==='dts')te_render_dts_block_summary($candidates);
    te_render_candidate_table(array_slice($candidates, 0, 40), $progress, $candidateBook, $strategyIntents, $strategyBrokerOrders, $c);
    te_table('최근 거래', array_slice(array_reverse(is_array($trades)?$trades:[]),0,30), ['sell_time','market','symbol','name','qty','buy_price','sell_price','profit','return_pct','sell_reason'], '완료된 거래가 없습니다.');

    echo '<section class="system-check"><details><summary>시스템 점검 정보</summary>';
    echo '<div class="system-grid">';
    echo '<div><span>마지막 정상 실행</span><b>'.te_h((string)($state['last_tick'] ?? '-')).'</b></div>';
    echo '<div><span>데이터 상태</span><b>부족 '.(int)($diag['data_short'] ?? 0).' · 오류 '.(int)($diag['data_error'] ?? 0).' · 지연 '.(int)($diag['data_stale'] ?? 0).'</b></div>';
    echo '<div><span>브로커 상태</span><b>'.te_h(strtoupper((string)($brokerRuntime['execution_mode'] ?? 'unknown'))).' · '.(!empty($brokerRuntime['cycle_stale'])?'갱신 지연':'정상').'</b></div>';
    echo '<div><span>시장별 슬롯</span><b>KR '.(int)$slotSummary['KR']['used'].'/5 · US '.(int)$slotSummary['US']['used'].'/5 · JP '.(int)$slotSummary['JP']['used'].'/5</b></div>';
    echo '</div>';
    echo '<p>'.te_h(te_user_log_summary($state,$progress)).'</p>';
    echo '<div class="toolbar"><a class="btn small" href="?mode=status_json">상태 JSON</a><a class="btn small" href="?mode=health">상태 점검</a></div>';
    echo '<details><summary>디버그 로그</summary><pre>'.te_h(te_tail($c['log_file'],30)).'</pre></details>';
    echo '</details></section>';

    echo '<footer>'.TE_REV.'</footer></main></body></html>';
}

function te_render_market_scan(array $state, array $regimes, array $progress, array $candidateBook): void
{
    $marketStatus = is_array($state['market'] ?? null) ? $state['market'] : [];
    $regimeMarkets = is_array($regimes['markets'] ?? null) ? $regimes['markets'] : [];

    echo '<section><h2>현재 시장 레짐</h2><div class="market-grid">';
    foreach (['KR','US','JP'] as $market) {
        $regime = is_array($regimeMarkets[$market] ?? null) ? $regimeMarkets[$market] : [];
        $tradeOpen = te_market_flag($marketStatus,$market,'trade_open');
        $entryOpen = te_market_flag($marketStatus,$market,'entry_open');
        $code = (string)($regime['code'] ?? 'UNKNOWN');
        $label = (string)($regime['label'] ?? '확인 대기');
        $indexName = (string)($regime['index'] ?? ($market==='KR'?'KOSPI':($market==='JP'?'Nikkei 225':'S&P 500')));

        echo '<article class="market-box compact-market">';
        echo '<div class="market-head"><h3>'.$market.' · '.te_h($indexName).'</h3><span class="badge '.te_h(strtolower($code)).'">'.te_h($label).'</span></div>';
        echo '<div class="regime-score"><b>'.(int)($regime['score'] ?? 0).'</b><span>/100</span></div>';
        echo '<div class="regime-meta"><span>'.($tradeOpen?'장중':'장외').'</span><span>진입 '.($entryOpen?'허용':'대기').'</span><span>기준일 '.te_h((string)($regime['date'] ?? '-')).'</span></div>';
        echo '</article>';
    }
    echo '</div></section>';
}

function te_table(string $title, $rows, array $columns, string $emptyMessage = '표시할 자료가 없습니다.'): void
{
    if (!is_array($rows)) $rows = [];
    $labels = [
        'market'=>'시장','symbol'=>'종목코드','name'=>'종목명','qty'=>'수량',
        'entry_price'=>'매입가','current_price'=>'현재가','entry_type'=>'진입유형',
        'execution_mode'=>'실행방식','sell_time'=>'매도시각','buy_price'=>'매입가',
        'sell_price'=>'매도가','profit'=>'손익','return_pct'=>'수익률','sell_reason'=>'매도사유'
    ];
    echo '<section><h2>'.te_h($title).'</h2><div class="scroll"><table><tr>';
    foreach ($columns as $column) echo '<th>'.te_h((string)($labels[$column] ?? $column)).'</th>';
    echo '</tr>';
    if (!$rows) echo '<tr><td colspan="'.count($columns).'">'.te_h($emptyMessage).'</td></tr>';
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        echo '<tr>';
        foreach ($columns as $column) echo '<td>'.te_h(is_scalar($row[$column] ?? '') ? (string)($row[$column] ?? '') : te_json($row[$column] ?? [])).'</td>';
        echo '</tr>';
    }
    echo '</table></div></section>';
}

function te_render_dts_block_summary(array $rows): void
{
    $summary=te_analysis_block_reason_summary($rows,10);$items=is_array($summary['items']??null)?$summary['items']:[];
    echo'<section><div class="section-head"><h2>DTS 매입 차단 원인</h2><span class="muted">현재 후보 '.(int)($summary['source_rows']??0).'개 기준</span></div>';
    if(!$items){echo'<div class="empty-box">집계할 차단 사유가 없습니다.</div></section>';return;}
    echo'<div class="scroll"><table><tr><th>순위</th><th>차단 코드</th><th>발생 후보 수</th><th>전체 후보 대비</th></tr>';
    foreach($items as$i=>$item)echo'<tr><td>'.($i+1).'</td><td>'.te_h((string)($item['code']??'')).'</td><td>'.(int)($item['count']??0).'</td><td>'.number_format((float)($item['row_pct']??0),2).'%</td></tr>';
    echo'</table></div></section>';
}

function te_render_candidate_table(array $rows, array $progress, array $candidateBook, array $intents = [], array $brokerOrders = [], array $c = []): void
{
    $crossBased=strtoupper((string)($c['entry_contract']??''))==='CROSS_BASED';
    $heading=$crossBased?'최근 후보 · 신호 상태순':'최근 후보 · 점수순';
    $hint=$crossBased?'교차 신호와 상태 중심 · 동점은 최근 갱신 순':'점수 높은 순 · 동점은 상태와 최근 갱신 순';
    $scoreHeader=$crossBased?'신호':'점수';
    echo '<section class="candidate-section"><div class="section-head"><h2>'.te_h($heading).'</h2><span class="mobile-hint">'.te_h($hint).'</span></div>';

    // 데스크톱: 전체 정보를 표로 제공한다.
    echo '<div class="scroll candidate-desktop"><table class="candidate-table"><tr><th>시간</th><th>상태</th><th>'.te_h($scoreHeader).'</th><th>시장</th><th>종목</th><th>종목명</th><th>가격</th><th>진입유형</th><th>핵심사유</th><th>대표 차단사유</th><th>주문 진행</th></tr>';
    if (!$rows) {
        $message = te_candidate_empty_message($progress, $candidateBook);
        echo '<tr><td colspan="11">'.te_h($message).'</td></tr>';
    }
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $status = strtoupper((string)($row['status'] ?? 'FILTERED'));
        $block = (string)($row['block_reason'] ?? '');
        $reasonRaw = (string)($row['reason'] ?? '');
        $reason = te_reason_summary($reasonRaw);
        if ($block === $reasonRaw) $block = '';
        $order = te_candidate_order_progress($row, $intents, $brokerOrders);
        echo '<tr><td>'.te_h((string)($row['time'] ?? '')).'</td><td><span class="status '.te_h(strtolower($status)).'">'.te_h($status).'</span></td><td>'.te_h($crossBased?(strtoupper((string)($row['model_status']??''))==='BUY'?'발생':'대기'):(string)($row['score'] ?? 0)).'</td><td>'.te_h((string)($row['market'] ?? '')).'</td><td>'.te_h((string)($row['symbol'] ?? '')).'</td><td>'.te_h((string)($row['name'] ?? '')).'</td><td>'.te_h((string)($row['price'] ?? '')).'</td><td>'.te_h((string)($row['type'] ?? '')).'</td><td title="'.te_h($reasonRaw).'">'.te_h($reason).'</td><td>'.te_h(te_reason_summary($block)).'</td><td>'.te_order_progress_html($order, true).'</td></tr>';
    }
    echo '</table></div>';

    // 모바일: 가로 스크롤 대신 핵심 정보와 주문 단계를 카드로 표시한다.
    echo '<div class="candidate-mobile">';
    if (!$rows) echo '<div class="empty-box">'.te_h(te_candidate_empty_message($progress, $candidateBook)).'</div>';
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $status = strtoupper((string)($row['status'] ?? 'FILTERED'));        $reasonRaw = (string)($row['reason'] ?? '');
        $block = (string)($row['block_reason'] ?? '');
        if ($block === $reasonRaw) $block = '';
        $order = te_candidate_order_progress($row, $intents, $brokerOrders);
        $isPending = $status === 'BUY_PENDING' || !empty($row['order_id']);
        echo '<article class="candidate-card '.te_h(strtolower($status)).'">';
        $freshness=strtoupper((string)($row['quote_freshness']??''));$freshBadge=$freshness==='DELAYED'?'<span class="status data_delayed">지연시세</span>':'';
        echo '<div class="candidate-card-head"><div><span class="status '.te_h(strtolower($status)).'">'.te_h($status).'</span>'.$freshBadge.'<b>'.te_h((string)($row['name'] ?? $row['symbol'] ?? '')).'</b></div><strong>'.te_h($crossBased?(strtoupper((string)($row['model_status']??''))==='BUY'?'신호 발생':'대기'):(string)($row['score'] ?? 0).'점').'</strong></div>';
        echo '<div class="candidate-card-meta"><span>'.te_h((string)($row['market'] ?? '')).' · '.te_h((string)($row['symbol'] ?? '')).'</span><span>'.te_h((string)($row['time'] ?? '')).'</span></div>';
        echo '<div class="candidate-card-price"><span>현재가</span><b>'.te_h((string)($row['price'] ?? '-')).'</b><span>'.te_h((string)($row['type'] ?? '')).'</span></div>';
        if ($isPending) echo te_order_progress_html($order, false);
        elseif ($block !== '') echo '<p class="candidate-block">'.te_h(te_reason_summary($block, 80)).'</p>';
        else echo '<p class="candidate-reason">'.te_h(te_reason_summary($reasonRaw, 80)).'</p>';
        echo '<details class="candidate-detail"><summary>상세 보기</summary><dl>';
        te_candidate_detail_item('진입유형', (string)($row['type'] ?? '-'));
        te_candidate_detail_item('진입가', (string)($row['entry_price'] ?? '-'));
        te_candidate_detail_item('손절가', (string)($row['stop_price'] ?? '-'));
        te_candidate_detail_item('목표가', (string)($row['target_price'] ?? '-'));
        te_candidate_detail_item('핵심사유', $reasonRaw !== '' ? $reasonRaw : '-');
        te_candidate_detail_item('차단사유', $block !== '' ? $block : '-');
        te_candidate_detail_item('시세상태', (string)($row['quote_freshness'] ?? '-'));
        te_candidate_detail_item('시세지연', isset($row['quote_age_sec']) ? (string)$row['quote_age_sec'].'초' : '-');
        te_candidate_detail_item('실주문 재조회', !empty($row['requires_live_requote']) ? '필수' : '불필요');
        $metrics=is_array($row['metrics']??null)?$row['metrics']:[];
        if((string)($metrics['schema']??'')==='das_direction_support_v2'){
            $direction=is_array($metrics['direction']??null)?$metrics['direction']:[];
            te_candidate_detail_item('자료상태',(string)($metrics['material_status']??'-').' · '.(string)($metrics['probability_method']??'-').' · '.(string)($metrics['confidence']??'-'));
            $validation=is_array($metrics['auto_validation']??null)?$metrics['auto_validation']:[];
            te_candidate_detail_item('자동검증',!empty($validation['ok'])?'통과':'실패');
            te_candidate_detail_item('기준/참고',(string)($metrics['reference_price']??'-').' / '.(string)($metrics['reference_quote']??'-'));
            te_candidate_detail_item('방향',(string)($direction['label']??'-').' · 상승 '.(string)($direction['up_probability']??'-').'% · 횡보 '.(string)($direction['side_probability']??'-').'% · 하락 '.(string)($direction['down_probability']??'-').'%');
            $target=is_array($metrics['target_zone']??null)?$metrics['target_zone']:[];
            te_candidate_detail_item('상승목표',(string)($target['low']??'-').' ~ '.(string)($target['high']??'-'));
            $supports=is_array($metrics['supports']??null)?$metrics['supports']:[];
            foreach(array_slice($supports,0,2)as$s)if(is_array($s))te_candidate_detail_item((string)($s['number']??'').'차 지지',(string)($s['zone_low']??'-').' ~ '.(string)($s['zone_high']??'-').' · 도달 '.(string)($s['arrival_probability']??'-').'% · 지지 '.(string)($s['support_probability']??'-').'% · 목표/무효 '.(string)($s['target_price']??'-').' / '.(string)($s['invalidation_price']??'-').' · '.(string)($s['grade']??'-'));
            te_candidate_detail_item('기회값',(string)($metrics['opportunity_value']??'-').' · 신뢰도 '.(string)($metrics['confidence']??'-'));
            te_candidate_detail_item('최우선 대기',(string)($metrics['preferred_wait_price']??'없음'));
            te_candidate_detail_item('돌파가격',(string)($metrics['breakout_price']??'-'));
            te_candidate_detail_item('최종방어선',(string)($metrics['final_defense']??'-'));
            te_candidate_detail_item('신규 매입',(string)($metrics['new_investor_action']??'-'));
            te_candidate_detail_item('기존 보유',(string)($metrics['existing_holder_action']??'-'));
            te_candidate_detail_item('결론',(string)($metrics['one_line_conclusion']??'-'));
        }
        if ($isPending) {
            te_candidate_detail_item('주문번호', (string)($order['order_id'] ?? '-'));
            te_candidate_detail_item('주문수량', (string)($order['qty'] ?? 0));
            te_candidate_detail_item('체결수량', (string)($order['filled_qty'] ?? 0));
            te_candidate_detail_item('만료시각', (string)($order['expires_at'] ?? '-'));
            te_candidate_detail_item('브로커상태', (string)($order['broker_status'] ?? '-'));
        }
        echo '</dl></details></article>';
    }
    echo '</div></section>';
}

function te_candidate_detail_item(string $label, string $value): void
{
    echo '<dt>'.te_h($label).'</dt><dd>'.te_h($value).'</dd>';
}

function te_candidate_order_progress(array $row, array $intents, array $brokerOrders): array
{
    $orderId = (string)($row['order_id'] ?? '');
    $market = strtoupper((string)($row['market'] ?? ''));
    $symbol = (string)($row['symbol'] ?? '');
    $intent = null;
    $broker = null;

    $intentMatches=[];
    foreach ($intents as $candidate) {
        if (!is_array($candidate)) continue;
        $sameId = $orderId !== '' && (string)($candidate['order_id'] ?? '') === $orderId;
        $activeStatus=in_array(strtoupper((string)($candidate['status'] ?? 'INTENT_CREATED')),['INTENT_CREATED','PENDING'],true);
        $sameSymbol = $orderId === '' && $activeStatus && strtoupper((string)($candidate['market'] ?? '')) === $market && (string)($candidate['symbol'] ?? '') === $symbol && strtoupper((string)($candidate['side'] ?? '')) === 'BUY';
        if ($sameId || $sameSymbol) $intentMatches[]=$candidate;
    }
    if($intentMatches){usort($intentMatches,static function($a,$b){return strcmp((string)($b['created_at']??''),(string)($a['created_at']??''));});$intent=$intentMatches[0];$orderId=(string)($intent['order_id']??$orderId);}
    $brokerMatches=[];
    foreach ($brokerOrders as $candidate) {
        if (!is_array($candidate)) continue;
        $sameId = $orderId !== '' && (string)($candidate['order_id'] ?? '') === $orderId;
        $activeStatus=in_array(strtoupper((string)($candidate['status'] ?? '')),['PENDING','APPROVED','SENT','WORKING','PARTIAL','CANCEL_REQUESTED'],true);
        $sameSymbol = $orderId === '' && $activeStatus && strtoupper((string)($candidate['market'] ?? '')) === $market && (string)($candidate['symbol'] ?? '') === $symbol && strtoupper((string)($candidate['side'] ?? '')) === 'BUY';
        if ($sameId || $sameSymbol) $brokerMatches[]=$candidate;
    }
    if($brokerMatches){usort($brokerMatches,static function($a,$b){return strcmp((string)($b['received_at']??$b['created_at']??''),(string)($a['received_at']??$a['created_at']??''));});$broker=$brokerMatches[0];$orderId=(string)($broker['order_id']??$orderId);}

    $qty = max(0, (int)($broker['qty'] ?? $intent['qty'] ?? $row['order_qty'] ?? 0));
    $filled = max(0, (int)($broker['filled_qty'] ?? 0));
    $expires = (string)($broker['expires_at'] ?? $intent['expires_at'] ?? '');
    $brokerStatus = strtoupper((string)($broker['status'] ?? ''));
    $approved = !empty($broker['approved']) || in_array($brokerStatus, ['APPROVED','SENT','WORKING','PARTIAL','FILLED','PAPER_FILLED','CANCEL_REQUESTED'], true);

    $code = 'NONE'; $label = '주문 없음'; $class = 'none'; $percent = 0;
    if ($broker !== null) {
        if (in_array($brokerStatus, ['FILLED','PAPER_FILLED'], true)) { $code='FILLED';$label='체결 완료';$class='done';$percent=100; }
        elseif ($brokerStatus === 'PARTIAL') { $code='PARTIAL';$label='부분체결 '.$filled.'/'.$qty;$class='partial';$percent=$qty>0?min(95,max(70,(int)round($filled/$qty*100))):75; }
        elseif (in_array($brokerStatus, ['SENT','WORKING','CANCEL_REQUESTED'], true)) { $code='WORKING';$label=$brokerStatus==='CANCEL_REQUESTED'?'취소 요청 중':'주문 전송·체결 대기';$class='working';$percent=65; }
        elseif ($approved || $brokerStatus === 'APPROVED') { $code='APPROVED';$label='승인 완료·전송 대기';$class='approved';$percent=50; }
        elseif ($brokerStatus === 'PENDING' || $brokerStatus === '') { $code='BROKER_RECEIVED';$label='브로커 수신·승인 대기';$class='received';$percent=35; }
        elseif ($brokerStatus === 'REJECTED') { $code='REJECTED';$label='주문 거절';$class='failed';$percent=100; }
        elseif ($brokerStatus === 'EXPIRED') { $code='EXPIRED';$label='주문 만료';$class='failed';$percent=100; }
        elseif ($brokerStatus === 'CANCELLED') { $code='CANCELLED';$label='주문 취소';$class='failed';$percent=100; }
        else { $code=$brokerStatus;$label=$brokerStatus;$class='received';$percent=35; }
    } elseif ($intent !== null) {
        $intentStatus = strtoupper((string)($intent['status'] ?? 'PENDING'));
        if ($intentStatus === 'EXPIRED') { $code='EXPIRED';$label='엔진 의도 만료';$class='failed';$percent=100; }
        else { $code='INTENT_CREATED';$label='엔진 의도 생성·브로커 미수신';$class='intent';$percent=20; }
    } elseif (strtoupper((string)($row['status'] ?? '')) === 'BUY_PENDING') {
        $code='INTENT_MISSING';$label='BUY_PENDING이나 주문 의도 없음';$class='failed';$percent=100;
    }

    return [
        'code'=>$code,'label'=>$label,'class'=>$class,'percent'=>$percent,
        'order_id'=>$orderId,'qty'=>$qty,'filled_qty'=>$filled,'expires_at'=>$expires,
        'broker_status'=>$brokerStatus !== '' ? $brokerStatus : ($intent !== null ? 'NOT_RECEIVED' : ''),
        'approved'=>$approved,
    ];
}

function te_order_progress_html(array $order, bool $compact): string
{
    if (($order['code'] ?? 'NONE') === 'NONE') return '<span class="order-none">-</span>';
    $class = te_h((string)($order['class'] ?? 'none'));
    $label = te_h((string)($order['label'] ?? ''));
    $percent = max(0, min(100, (int)($order['percent'] ?? 0)));
    if ($compact) return '<div class="order-compact '.$class.'"><span>'.$label.'</span><small>'.te_h((string)($order['filled_qty'] ?? 0)).'/'.te_h((string)($order['qty'] ?? 0)).'</small></div>';
    return '<div class="order-flow '.$class.'"><div><b>'.$label.'</b><span>체결 '.te_h((string)($order['filled_qty'] ?? 0)).' / '.te_h((string)($order['qty'] ?? 0)).'</span></div><div class="order-progress"><i style="width:'.$percent.'%"></i></div><small>'.(($order['expires_at'] ?? '')!==''?'만료 '.te_h((string)$order['expires_at']):'주문 상태 확인 중').'</small></div>';
}

function te_candidate_empty_message(array $progress, array $candidateBook): string
{
    $hasSnapshot = false;
    foreach (['KR','US','JP'] as $market) if (count(is_array($candidateBook['markets'][$market]['rows'] ?? null) ? $candidateBook['markets'][$market]['rows'] : []) > 0) $hasSnapshot = true;
    if ($hasSnapshot) return '최근 완료 스캔에서 표시할 후보가 없습니다.';
    foreach (['KR','US','JP'] as $market) if (($progress['markets'][$market]['status'] ?? '') === 'RUNNING') return '현재 첫 전체 스캔이 진행 중입니다.';
    return '아직 완료된 분석 스냅샷이 없습니다.';
}

function te_reason_summary(string $reason, int $maxLength = 56): string
{
    $reason = trim(preg_replace('/\s+/', ' ', $reason));
    if ($reason === '') return '';
    if (function_exists('mb_strlen') && mb_strlen($reason, 'UTF-8') > $maxLength) return mb_substr($reason, 0, $maxLength - 1, 'UTF-8').'…';
    return strlen($reason) > $maxLength ? substr($reason, 0, $maxLength - 3).'...' : $reason;
}

function te_card(string $a,$b,$c=''): void{echo'<div class="card"><small>'.te_h($a).'</small><b>'.te_h((string)$b).'</b><small>'.te_h((string)$c).'</small></div>';}
function te_css(): string
{
    return 'body{margin:0;background:#f4f6f8;color:#111827;font-family:system-ui}.engine-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.engine-head h1{margin-bottom:4px}.engine-head p{margin-top:0}main{max-width:1220px;margin:auto;padding:18px}h1{margin-bottom:4px}.muted,small{color:#6b7280}section{background:#fff;border:1px solid #e5e7eb;border-radius:18px;padding:16px;margin:14px 0}.cards{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;background:none;border:0;padding:0}.cards.six{grid-template-columns:repeat(3,1fr)}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:16px}.card b{display:block;font-size:22px;margin:6px 0}.market-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}.market-box{border:1px solid #e5e7eb;border-radius:16px;padding:15px;background:#fafafa}.market-head,.scan-line{display:flex;align-items:center;justify-content:space-between;gap:10px}.market-head h3{margin:0}.market-score{margin:8px 0 12px}.market-box dl{display:grid;grid-template-columns:105px 1fr;margin:0 0 12px}.market-box dt,.market-box dd{padding:4px 0;margin:0}.market-box dt{color:#6b7280}.badge,.status{display:inline-block;padding:5px 9px;border-radius:999px;background:#e5e7eb;font-size:12px;font-weight:700}.badge.bull,.badge.up,.status.buy,.status.buy_pending{background:#dcfce7;color:#166534}.badge.recovery,.status.watch,.status.candidate{background:#dbeafe;color:#1d4ed8}.badge.bear,.badge.weak,.status.data_error,.status.data_date_mismatch{background:#fee2e2;color:#991b1b}.status.data_short,.status.data_stale,.status.data_delayed{background:#fef3c7;color:#92400e}.snapshot{font-size:12px;color:#475569;background:#f1f5f9;border-radius:10px;padding:8px;margin:8px 0}.progress{height:9px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin:8px 0}.progress i{display:block;height:100%;background:#2563eb}.scroll{overflow:auto;max-height:360px}table{border-collapse:collapse;width:100%;min-width:850px}th,td{padding:9px;border-bottom:1px solid #e5e7eb;white-space:nowrap;text-align:left}th{position:sticky;top:0;background:#f1f5f9}.candidate-table td:nth-child(9),.candidate-table td:nth-child(10){max-width:300px;overflow:hidden;text-overflow:ellipsis}.candidate-mobile{display:none}.mobile-hint{display:none;color:#64748b;font-size:12px}.candidate-card{border:1px solid #e5e7eb;border-radius:16px;padding:14px;background:#fff;box-shadow:0 1px 2px rgba(15,23,42,.04)}.candidate-card.buy_pending{border-color:#86efac;background:#f0fdf4}.candidate-card.watch,.candidate-card.candidate{border-color:#bfdbfe}.candidate-card.data_error,.candidate-card.data_short{border-color:#fecaca}.candidate-card-head,.candidate-card-head>div,.candidate-card-meta,.candidate-card-price,.order-flow>div:first-child{display:flex;align-items:center}.candidate-card-head,.candidate-card-meta,.candidate-card-price,.order-flow>div:first-child{justify-content:space-between;gap:10px}.candidate-card-head>div{gap:8px;min-width:0}.candidate-card-head b{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.candidate-card-head strong{font-size:18px}.candidate-card-meta{font-size:12px;color:#64748b;margin:9px 0}.candidate-card-price{padding:10px 0;border-top:1px solid #eef2f7;border-bottom:1px solid #eef2f7}.candidate-card-price b{font-size:18px}.candidate-card-price span:last-child{color:#475569;font-size:12px}.candidate-reason,.candidate-block{font-size:13px;line-height:1.45;margin:10px 0 0}.candidate-block{color:#991b1b}.candidate-detail{margin-top:10px}.candidate-detail summary{color:#1d4ed8}.candidate-detail dl{display:grid;grid-template-columns:82px 1fr;gap:0;margin:8px 0 0}.candidate-detail dt,.candidate-detail dd{padding:6px 0;border-bottom:1px solid #f1f5f9;margin:0;font-size:13px}.candidate-detail dt{color:#64748b}.candidate-detail dd{white-space:normal;word-break:break-word}.order-compact{display:flex;flex-direction:column;gap:2px;min-width:170px}.order-compact span{font-weight:700;font-size:12px}.order-compact small{font-size:11px}.order-compact.intent,.order-flow.intent{color:#1d4ed8}.order-compact.received,.order-flow.received{color:#7c3aed}.order-compact.approved,.order-flow.approved{color:#0369a1}.order-compact.working,.order-flow.working{color:#c2410c}.order-compact.partial,.order-flow.partial{color:#a16207}.order-compact.done,.order-flow.done{color:#166534}.order-compact.failed,.order-flow.failed{color:#991b1b}.order-flow{margin-top:10px;padding:10px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0}.order-flow b{font-size:13px}.order-flow span{font-size:12px}.order-progress{height:7px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin:8px 0 5px}.order-progress i{display:block;height:100%;background:currentColor}.order-flow small{font-size:11px}.empty-box{padding:18px;border:1px dashed #cbd5e1;border-radius:14px;color:#64748b;text-align:center}.broker-warning{background:#fff7ed;border-color:#fdba74;color:#9a3412}.broker-warning p{margin-bottom:0}pre{background:#0f172a;color:#e5e7eb;padding:12px;border-radius:12px;white-space:pre-wrap}details summary{cursor:pointer;font-weight:700;margin:8px 0}a{color:#1d4ed8}.toolbar,.section-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.section-head{justify-content:space-between}.section-head h2{margin:0}.btn{display:inline-block;padding:8px 11px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:#1d4ed8;text-decoration:none;font-weight:700;font-size:13px}.btn.primary{background:#1d4ed8;color:#fff;border-color:#1d4ed8}.btn.small{padding:6px 9px;font-size:12px;white-space:nowrap}.compact-market{min-height:150px}.regime-score{display:flex;align-items:baseline;gap:4px;margin:18px 0 12px}.regime-score b{font-size:30px}.regime-score span{color:#6b7280}.regime-meta{display:flex;gap:8px;flex-wrap:wrap;color:#475569;font-size:13px}.regime-meta span{background:#f1f5f9;border-radius:999px;padding:5px 8px}.system-check{background:#f8fafc}.system-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:12px 0}.system-grid div{border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:10px}.system-grid span{display:block;color:#64748b;font-size:12px}.system-grid b{display:block;margin-top:4px;font-size:14px}footer{color:#6b7280;margin:20px 0}@media(max-width:850px){main{padding:12px}.cards.six,.market-grid,.system-grid{grid-template-columns:1fr}.card b{font-size:20px}.market-box dl{grid-template-columns:95px 1fr}}@media(max-width:700px){.candidate-section{padding:14px}.candidate-desktop{display:none}.candidate-mobile{display:grid;gap:10px}.mobile-hint{display:inline}.candidate-card .status{font-size:11px;padding:4px 7px}.candidate-card-head strong{font-size:16px}.candidate-detail dl{grid-template-columns:76px 1fr}}';
}


/**
 * 전략 모델 공통 계산 도구.
 * 모델 파일별 중복 구현을 제거하고 계산 규칙을 통일한다.
 */
function te_model_num($value): float
{
    if (is_numeric($value)) { $number=(float)$value; return is_finite($number)?$number:0.0; }
    $normalized = str_replace([',','%',' ','원','$'], '', (string)$value);
    if (!is_numeric($normalized)) return 0.0; $number=(float)$normalized; return is_finite($number)?$number:0.0;
}

function te_model_nullable($value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) return null; $number=(float)$value; return is_finite($number)?$number:null;
}

function te_model_bars(array $context, string $timeframe): array
{
    return is_array($context['bars'][$timeframe] ?? null) ? $context['bars'][$timeframe] : [];
}

function te_model_field(array $bars, string $field): array
{
    $values = [];
    foreach ($bars as $bar) $values[] = te_model_num(is_array($bar) ? ($bar[$field] ?? 0) : 0);
    return $values;
}

function te_model_sma_values(array $values, int $period, int $offset = 0): float
{
    $end = count($values) - $offset;
    if ($period <= 0 || $end < $period) return 0.0;
    return array_sum(array_slice($values, $end - $period, $period)) / $period;
}

function te_model_sma_series(array $values, int $period): array
{
    $out = [];
    $queue = [];
    $sum = 0.0;
    foreach ($values as $value) {
        $number = te_model_num($value);
        $queue[] = $number;
        $sum += $number;
        if (count($queue) > $period) $sum -= array_shift($queue);
        $out[] = count($queue) === $period ? $sum / $period : null;
    }
    return $out;
}

function te_model_sma_bars(array $bars, int $period, int $offset = 0): float
{
    return te_model_sma_values(te_model_field($bars, 'close'), $period, $offset);
}

function te_model_sma_bars_at(array $bars, int $period, int $index): float
{
    if ($period <= 0 || $index < $period - 1 || $index >= count($bars)) return 0.0;
    $sum = 0.0;
    for ($i = $index - $period + 1; $i <= $index; $i++) $sum += te_model_num($bars[$i]['close'] ?? 0);
    return $sum / $period;
}

function te_model_stoch(array $bars, int $length, int $smoothK, int $lengthD): array
{
    $count = count($bars);
    $raw = array_fill(0, $count, null);
    $k = array_fill(0, $count, null);
    $d = array_fill(0, $count, null);
    for ($i = $length - 1; $i < $count; $i++) {
        $highest = 0.0;
        $lowest = 0.0;
        for ($j = $i - $length + 1; $j <= $i; $j++) {
            $high = te_model_num($bars[$j]['high'] ?? 0);
            $low = te_model_num($bars[$j]['low'] ?? 0);
            // [TE1] high=0인 봉 제외 (guard 패턴 적용)
            if ($high > 0) $highest = $highest > 0 ? max($highest, $high) : $high;
            if ($low > 0)  $lowest  = $lowest  > 0 ? min($lowest,  $low)  : $low;
        }
        $close = te_model_num($bars[$i]['close'] ?? 0);
        $raw[$i] = ($highest > $lowest && $lowest > 0) ? ($close - $lowest) / ($highest - $lowest) * 100.0 : 50.0;
    }
    for ($i = 0; $i < $count; $i++) {
        $window = [];
        for ($j = max(0, $i - $smoothK + 1); $j <= $i; $j++) if ($raw[$j] !== null) $window[] = $raw[$j];
        if (count($window) === $smoothK) $k[$i] = array_sum($window) / $smoothK;
    }
    for ($i = 0; $i < $count; $i++) {
        $window = [];
        for ($j = max(0, $i - $lengthD + 1); $j <= $i; $j++) if ($k[$j] !== null) $window[] = $k[$j];
        if (count($window) === $lengthD) $d[$i] = array_sum($window) / $lengthD;
    }
    $out = [];
    for ($i = 0; $i < $count; $i++) $out[$i] = ['k'=>$k[$i], 'd'=>$d[$i]];
    return $out;
}

function te_model_volume_ratio(array $bars, int $recent, int $base): float
{
    if ($recent <= 0 || $base <= 0 || count($bars) < $recent + $base) return 0.0;
    $recentRows = array_slice($bars, -$recent);
    $baseRows = array_slice($bars, -($recent + $base), $base);
    $recentSum = 0.0;
    $baseSum = 0.0;
    foreach ($recentRows as $row) $recentSum += te_model_num($row['volume'] ?? 0);
    foreach ($baseRows as $row) $baseSum += te_model_num($row['volume'] ?? 0);
    return $baseSum > 0 ? ($recentSum / $recent) / ($baseSum / $base) : 0.0;
}

function te_model_live_daily_bar(array $bars, array $context, float $price): array
{
    $date = (string)($context['session_date'] ?? date('Y-m-d'));
    $last = count($bars) - 1;
    $bar = [
        'ts'=>time(), 'time'=>$date.' 15:30:00',
        'open'=>te_model_num($context['quote']['open'] ?? $price),
        'high'=>$price, 'low'=>$price, 'close'=>$price,
        'volume'=>te_model_num($context['quote']['volume'] ?? 0),
    ];
    if ($last >= 0 && substr((string)($bars[$last]['time'] ?? ''), 0, 10) === $date) {
        $bar = array_merge($bars[$last], $bar);
        $bar['high'] = max(te_model_num($bars[$last]['high'] ?? $price), $price);
        $bar['low'] = min(te_model_num($bars[$last]['low'] ?? $price), $price);
        $bars[$last] = $bar;
    } else {
        $bars[] = $bar;
    }
    return $bars;
}

function te_model_low(array $bars, int $period, int $endIndex): float
{
    $value = 0.0;
    for ($i = max(0, $endIndex - $period + 1); $i <= $endIndex; $i++) {
        $current = te_model_num($bars[$i]['low'] ?? 0);
        if ($current > 0) $value = $value > 0 ? min($value, $current) : $current;
    }
    return $value;
}

function te_model_high(array $bars, int $period, int $endIndex): float
{
    $value = 0.0;
    for ($i = max(0, $endIndex - $period + 1); $i <= $endIndex; $i++) {
        $current = te_model_num($bars[$i]['high'] ?? 0);
        if ($current > 0) $value = max($value, $current);
    }
    return $value;
}

function te_model_bar_date(array $bar, string $market = 'KR'): string
{
    $time = (string)($bar['time'] ?? $bar['date'] ?? '');
    if ($time !== '') return substr($time, 0, 10);
    $timestamp = (int)($bar['ts'] ?? 0);
    return $timestamp > 0 ? te_date_tz($timestamp, $market) : '';
}

function te_register_shutdown_handler(array $c): void
{
    static $registered=false;
    if($registered)return;
    $registered=true;
    register_shutdown_function(static function() use ($c): void {
        $e=error_get_last();
        if(!is_array($e)||!in_array((int)($e['type']??0),[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true))return;
        $message='FATAL '.(string)($e['message']??'UNKNOWN').' @'.(string)($e['file']??'').':'.(int)($e['line']??0);
        te_log((string)$c['error_file'],$message);
    });
}
function te_lock(string $f): bool
{
    unset($GLOBALS['TE_LOCK_ERROR']);
    $dir=dirname($f);
    if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir)){$GLOBALS['TE_LOCK_ERROR']='LOCK_DIR_CREATE_FAILED '.$dir;return false;}
    $h=@fopen($f,'c+');
    if(!$h){$GLOBALS['TE_LOCK_ERROR']='LOCK_OPEN_FAILED '.$f;return false;}
    if(!@flock($h,LOCK_EX|LOCK_NB)){@fclose($h);return false;}
    $GLOBALS['TE_LOCK']=$h;
    return true;
}
function te_lock_error(): string{return(string)($GLOBALS['TE_LOCK_ERROR']??'');}
function te_unlock(): void{if(isset($GLOBALS['TE_LOCK'])){@flock($GLOBALS['TE_LOCK'],LOCK_UN);@fclose($GLOBALS['TE_LOCK']);unset($GLOBALS['TE_LOCK']);}unset($GLOBALS['TE_LOCK_ERROR']);}
function te_load(string $f,$d){if(!is_file($f))return$d;$x=@file_get_contents($f);$j=json_decode((string)$x,true);return is_array($j)?$j:$d;}
function te_save(string $f,$d): void{$dir=dirname($f);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('DIR_CREATE_FAILED '.$dir);$tmp=$f.'.tmp.'.getmypid().'.'.te_hex(2);$j=te_json($d,true);if($j===''&&$d!==''&&$d!==[])throw new RuntimeException('JSON_ENCODE_FAILED '.$f);if(@file_put_contents($tmp,$j.PHP_EOL,LOCK_EX)===false){@unlink($tmp);throw new RuntimeException('FILE_WRITE_FAILED '.$f);}if(!@rename($tmp,$f)){@unlink($tmp);throw new RuntimeException('FILE_RENAME_FAILED '.$f);}}
function te_rotate_file(string $f,int $maxBytes=TE_LOG_MAX_BYTES,int $rotations=TE_LOG_ROTATIONS): void
{
    if($maxBytes<1||!is_file($f))return;$size=@filesize($f);if($size===false||$size<$maxBytes)return;for($i=max(1,$rotations);$i>=1;$i--){$src=$i===1?$f:$f.'.'.($i-1);$dst=$f.'.'.$i;if(is_file($dst))@unlink($dst);if(is_file($src))@rename($src,$dst);}
}
function te_log(string $f,string $s): void
{
    $line='['.te_now().'] '.$s.PHP_EOL;$dir=dirname($f);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir)){@error_log('TRADE_ENGINE_LOG_DIR_FAILED '.$dir.' '.$s);return;}te_rotate_file($f);if(@file_put_contents($f,$line,FILE_APPEND|LOCK_EX)===false)@error_log('TRADE_ENGINE_LOG_WRITE_FAILED '.$f.' '.$s);
}
function te_tail(string $f,int $n): string{if(!is_file($f))return'';$a=@file($f,FILE_IGNORE_NEW_LINES);return$a?implode("\n",array_slice($a,-$n)):'';}
function te_json($d,bool $pretty=false): string{return(string)json_encode($d,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|($pretty?JSON_PRETTY_PRINT:0));}
function te_json_response($d): void{header('Content-Type: application/json; charset=utf-8');echo te_json($d,true);}
function te_h(string $s): string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function te_num($v): float{if(is_numeric($v))return(float)$v;$s=str_replace([',','원','$','%',' '],'',(string)$v);return is_numeric($s)?(float)$s:0.0;}
function te_money(float $v,string $m): string{return$m==='KR'?number_format($v,0):($m==='JP'?'¥'.number_format($v,0):'$'.number_format($v,2));}
function te_buy_fee(string $m,float $gross): float{return$m==='KR'?$gross*TE_KR_BUY_FEE:($m==='JP'?$gross*TE_JP_BUY_FEE:$gross*TE_US_BUY_FEE);}
function te_sell_fee(string $m,float $gross): float{return$m==='KR'?$gross*TE_KR_SELL_COST:($m==='JP'?$gross*TE_JP_SELL_COST:$gross*TE_US_SELL_COST);}
function te_safe(string $s): string{return preg_replace('/[^A-Za-z0-9_-]/','_',$s);}
function te_hex(int $n): string{try{return bin2hex(random_bytes($n));}catch(Throwable $e){return substr(md5(uniqid('',true)),0,$n*2);}}
function te_now(): string{return date('Y-m-d H:i:s');}