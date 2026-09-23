<?php
/**
 * trade_3plus1_preflight.php
 * 3+1 Trading System v1.4 FROZEN deployment preflight v1.3.18 · ERRC · DASHBOARD-VISUAL-CONTRACT · VALIDATION-CAPABILITY-GATE · SWING-RETIREMENT-GATE · RUNNER-V146-APPROVAL-ACTIONABLE-GATE
 * 다운로드/보관 파일명: trade_3plus1_preflight_v1312.php
 * SWING complete-removal contract. Read-only checks only. PHP 7.4 compatible.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');

const P3_VERSION='v1.3.18';
const P3_REV='trade-3plus1-preflight-v1318-dashboard-header-cleanup-20260923-r1';

function p3_read(string $f): string{return is_file($f)?(string)@file_get_contents($f):'';}
function p3_json(string $f,array $d=[]): array{if(!is_file($f))return$d;$x=json_decode((string)@file_get_contents($f),true);return is_array($x)?$x:$d;}
function p3_writable_or_parent(string $path): bool{if(is_dir($path))return is_writable($path);$p=dirname($path);while($p!==''&&!is_dir($p)){ $next=dirname($p); if($next===$p)break; $p=$next; }return is_dir($p)&&is_writable($p);}
function p3_active_position_rows(string $f): array{
    $root=p3_json($f,[]);$rows=is_array($root['positions']??null)?$root['positions']:$root;$out=[];
    foreach((array)$rows as$r){if(!is_array($r))continue;$qty=(int)($r['qty']??$r['quantity']??0);$status=strtoupper((string)($r['status']??'OPEN'));
        if($qty>0&&!in_array($status,['CLOSED','EXITED','SOLD'],true))$out[]=$r;}
    return$out;
}
function p3_active_swing_orders(string $f): array{
    $root=p3_json($f,[]);$rows=is_array($root['orders']??null)?$root['orders']:$root;$out=[];
    $terminal=['DONE','FILLED','CLOSED','CANCELLED','REJECTED','EXPIRED','PAPER_FILLED'];
    foreach((array)$rows as$r){if(!is_array($r))continue;$key=strtolower((string)($r['strategy_key']??$r['strategy']??''));$status=strtoupper((string)($r['status']??'PENDING'));
        if($key==='swing'&&!in_array($status,$terminal,true))$out[]=$r;}
    return$out;
}
function p3_check_source(string $root,string $file,string $rev,string $status='',string $alpha=''): array{
    $path=$root.'/'.$file;$src=p3_read($path);$ok=$src!==''&&strpos($src,$rev)!==false;
    if($status!=='')$ok=$ok&&strpos($src,$status)!==false;if($alpha!=='')$ok=$ok&&strpos($src,$alpha)!==false;
    return['name'=>$file,'ok'=>$ok,'detail'=>$src===''?'MISSING':$rev];
}
function p3_trade_list_counts(string $file): array{
    $v=p3_trade_list_validate($file);
    return['KR'=>(int)($v['counts']['KR']['total']??0),'US'=>(int)($v['counts']['US']['total']??0),'JP'=>(int)($v['counts']['JP']['total']??0),'MISSING'=>empty($v['loaded'])?1:0,'ENABLED'=>(int)($v['enabled_total']??0)];
}
function p3_trade_list_validate(string $file): array{
    $r=['ok'=>false,'loaded'=>false,'file'=>$file,'version'=>'','schema'=>'','contract'=>'','sha256'=>'','total'=>0,'enabled_total'=>0,'disabled_total'=>0,'counts'=>['KR'=>['total'=>0,'enabled'=>0,'disabled'=>0],'US'=>['total'=>0,'enabled'=>0,'disabled'=>0],'JP'=>['total'=>0,'enabled'=>0,'disabled'=>0]],'errors'=>[]];
    if(!is_file($file)){ $r['errors'][]='TRADE_LIST_MISSING'; return $r; }
    try{$data=require $file;}catch(Throwable $e){$r['errors'][]='TRADE_LIST_LOAD_EXCEPTION';return$r;}
    if(!is_array($data)){ $r['errors'][]='TRADE_LIST_INVALID_RETURN'; return $r; }
    $r['loaded']=true;$r['sha256']=(string)(@hash_file('sha256',$file)?:'');
    foreach(['meta','kr','us','jp']as$k)if(!array_key_exists($k,$data))$r['errors'][]='TOP_LEVEL_MISSING_'.strtoupper($k);
    $meta=is_array($data['meta']??null)?$data['meta']:[];
    if(!$meta)$r['errors'][]='META_INVALID';
    $r['version']=(string)($meta['version']??'');$r['schema']=(string)($meta['schema']??'');$r['contract']=(string)($meta['contract']??'');
    if($r['schema']!=='trade_list_v1_8')$r['errors'][]='SCHEMA_MISMATCH';
    if($r['contract']!=='trade_universe_contract_v2')$r['errors'][]='CONTRACT_MISMATCH';
    $strategyKeys=array_values(array_map('strtolower',array_map('strval',is_array($meta['strategy_keys']??null)?$meta['strategy_keys']:[])));
    if($strategyKeys!==['abc','dts','stc26','das'])$r['errors'][]='STRATEGY_KEYS_MISMATCH';
    $required=['symbol','code','name','market','exchange','currency','asset_type','enabled'];
    $declaredReq=is_array($meta['required_fields']??null)?array_values($meta['required_fields']):[];
    foreach($required as$f)if(!in_array($f,$declaredReq,true))$r['errors'][]='META_REQUIRED_FIELD_MISSING_'.strtoupper($f);
    $seen=[];
    foreach(['KR'=>'kr','US'=>'us','JP'=>'jp']as$market=>$key){
        $rows=$data[$key]??null;if(!is_array($rows)){ $r['errors'][]=$market.'_ARRAY_INVALID'; continue; }
        foreach(array_values($rows)as$i=>$row){
            $r['counts'][$market]['total']++;$r['total']++;
            if(!is_array($row)){ $r['errors'][]=$market.'_ROW_INVALID_'.$i; continue; }
            foreach($required as$f)if(!array_key_exists($f,$row))$r['errors'][]=$market.'_FIELD_MISSING_'.$i.'_'.strtoupper($f);
            $enabled=$row['enabled']??null;if(!is_bool($enabled))$r['errors'][]=$market.'_ENABLED_TYPE_'.$i;
            if($enabled===true){$r['counts'][$market]['enabled']++;$r['enabled_total']++;}else{$r['counts'][$market]['disabled']++;$r['disabled_total']++;}
            $symbol=strtoupper(trim((string)($row['symbol']??'')));$code=strtoupper(trim((string)($row['code']??'')));
            if($symbol===''||$code!==$symbol)$r['errors'][]=$market.'_SYMBOL_CODE_'.$i;
            if($market==='KR'&&!preg_match('/^[0-9]{6}$/',$symbol))$r['errors'][]=$market.'_SYMBOL_FORMAT_'.$i;
            if($market==='US'&&!preg_match('/^[A-Z0-9.\-^]{1,15}$/',$symbol))$r['errors'][]=$market.'_SYMBOL_FORMAT_'.$i;
            if($market==='JP'&&!preg_match('/^[0-9A-Z]{4,6}$/',$symbol))$r['errors'][]=$market.'_SYMBOL_FORMAT_'.$i;
            if(strtoupper(trim((string)($row['market']??'')))!==$market)$r['errors'][]=$market.'_MARKET_'.$i;
            $currency=strtoupper(trim((string)($row['currency']??'')));$want=$market==='KR'?'KRW':($market==='US'?'USD':'JPY');if($currency!==$want)$r['errors'][]=$market.'_CURRENCY_'.$i;
            $asset=strtoupper(trim((string)($row['asset_type']??'')));if(!in_array($asset,['STOCK','ETF'],true))$r['errors'][]=$market.'_ASSET_TYPE_'.$i;
            if(trim((string)($row['name']??''))==='')$r['errors'][]=$market.'_NAME_'.$i;
            $exchange=strtoupper(preg_replace('/[^A-Z0-9]/','',(string)($row['exchange']??'')));
            if($market==='KR'&&!in_array($exchange,['KOSPI','KOSDAQ','ETF'],true))$r['errors'][]=$market.'_EXCHANGE_'.$i;
            if($market==='US'&&!in_array($exchange,['NASD','NAS','NASDAQ','NMS','NGM','NYSE','NYS','NYQ','AMEX','AMS','ASE','ARCA','NYSEARCA'],true))$r['errors'][]=$market.'_EXCHANGE_'.$i;
            if($market==='JP'&&!in_array($exchange,['TKSE','TSE','TYO','TOKYO'],true))$r['errors'][]=$market.'_EXCHANGE_'.$i;
            $dk=$market.':'.$symbol;if($symbol!==''&&isset($seen[$dk]))$r['errors'][]='DUPLICATE_'.$dk;else$seen[$dk]=true;
        }
    }
    $metaChecks=['total_count'=>$r['total'],'enabled_count'=>$r['enabled_total'],'disabled_count'=>$r['disabled_total'],'kr_count'=>$r['counts']['KR']['total'],'us_count'=>$r['counts']['US']['total'],'jp_count'=>$r['counts']['JP']['total']];
    foreach($metaChecks as$k=>$actual){if(!array_key_exists($k,$meta)||(int)$meta[$k]!==$actual)$r['errors'][]='META_'.strtoupper($k).'_MISMATCH';}
    foreach(['kr_enabled_count'=>['KR','enabled'],'kr_disabled_count'=>['KR','disabled'],'us_enabled_count'=>['US','enabled'],'us_disabled_count'=>['US','disabled'],'jp_enabled_count'=>['JP','enabled'],'jp_disabled_count'=>['JP','disabled']]as$k=>$p){if(array_key_exists($k,$meta)&&(int)$meta[$k]!==$r['counts'][$p[0]][$p[1]])$r['errors'][]='META_'.strtoupper($k).'_MISMATCH';}
    $r['errors']=array_values(array_unique($r['errors']));$r['ok']=$r['loaded']&&count($r['errors'])===0;return$r;
}
function p3_has_operational_swing_reference(string $src): bool{
    if($src==='')return false;
    $patterns=[
        '/[\'"]swing[\'"]\s*=>/i',
        '/swing\.php/i',
        '/swing_runtime/i',
        '/RETIRED_STRATEGY/i',
        '/retired_strategy_guard/i',
        '/SWING Retired Exit/i'
    ];
    foreach($patterns as$p)if(preg_match($p,$src))return true;
    return false;
}

$root=__DIR__;$checks=[];$warnings=[];$ok=true;

// Resolve runtime authority before any live-runtime inspection.
// SINGLE_FILE_PAPER/REAL=false is fail-closed: live checks must never fall back to legacy /trade_runtime.
$authorityMarker=$root.'/trade_phase3b_lite_v100/authority.json';
$authorityRow=p3_json($authorityMarker,[]);
$authority=strtoupper(trim((string)($authorityRow['authority']??'')));
$realAllowed=!empty($authorityRow['real_order_allowed']);
$singlePaper=$authority==='SINGLE_FILE_PAPER'&&!$realAllowed;
$activeBrokerRuntime=$singlePaper?$root.'/trade_single_compat/trade_runtime':$root.'/trade_runtime';
$activeValidationRuntime=$singlePaper?$root.'/trade_single_compat/validation_runtime':$root.'/validation_runtime';
$legacyBrokerRuntime=$root.'/trade_runtime';
$legacyValidationRuntime=$root.'/validation_runtime';
$add=function(string $name,bool $pass,$detail='')use(&$checks,&$ok){$checks[]=['name'=>$name,'ok'=>$pass,'detail'=>$detail];if(!$pass)$ok=false;};
$engineSrc=p3_read($root.'/trade_engine.php');
$validationSrc=p3_read($root.'/trade_validation.php');
$add('Daily Opportunity soft-target engine contract',strpos($engineSrc,'te_daily_opportunity_pick')!==false&&strpos($engineSrc,'SOFT_TARGET_NOT_FORCED')!==false&&strpos($engineSrc,'daily_opportunity_state.json')!==false,'country-level daily BUY target is a soft opportunity goal, never mandatory');
$add('Daily Opportunity small-entry sizing',strpos($engineSrc,"allocation_scale")!==false&&strpos($engineSrc,"sig['metrics']['allocation_scale']")!==false,'M2 fallback uses reduced allocation');
$add('Daily Opportunity does not force SELL quota',strpos($engineSrc,'target_sells_per_market')===false,'SELL remains signal-based with zero minimum');
$add('Engine summary uses statistics payload contract',strpos($engineSrc,"payload['statistics']")!==false,'summary_by_strategy must read statistics, not stale stats alias');
$add('Validation export-time due_ts persistence',strpos($validationSrc,'tv_repair_pending_due_ts_persist')!==false,'repair + save + reload verify');
$add('Validation minimum-sample display guard',strpos($validationSrc,'INSUFFICIENT_SAMPLE')!==false&&strpos($validationSrc,'display_profit_factor')!==false,'raw metrics preserved; display metrics hidden below minimum sample');
$add('Validation checked JSON integrity',strpos($validationSrc,'tv_load_checked')!==false&&strpos($validationSrc,'SCHEMA_MISMATCH')!==false,'MISSING/OK/CORRUPT/SCHEMA_MISMATCH separated');
$add('Validation timestamped quarantine',strpos($validationSrc,'.corrupt.')!==false&&strpos($validationSrc,'TV_QUARANTINE_KEEP')!==false,'multiple corrupt snapshots retained');
$add('Validation commit manifest',strpos($validationSrc,'TV_COMMIT_SCHEMA')!==false&&strpos($validationSrc,'PARTIAL_COMMIT_DETECTED')!==false,'multi-file partial save detectable');
$add('Validation JP timezone',strpos($validationSrc,'$m===\'JP\'?\'Asia/Tokyo\'')!==false,'KR/US/JP market timezone');
$add('Validation horizon market propagation',strpos($validationSrc,'tv_horizons($c,$signalTs,$market)')!==false,'US/JP horizon due time uses signal market');
$add('Engine DTS hard-stale force refresh',strpos($engineSrc,'te_bars_force_refresh')!==false&&strpos($engineSrc,'te_strategy_exit_with_recovery')!==false&&strpos($engineSrc,'DTS_HARD_STALE_DEGRADED_HOLD')!==false,'1m force refresh -> exit recheck -> degraded hold');
$add('Engine active-position daily exit freshness',strpos($engineSrc,'te_exit_data_freshness')!==false&&strpos($engineSrc,'ACTIVE_EXIT_DATA_STALE_DEGRADED_HOLD')!==false,'stale daily exit data -> force refresh -> fail-closed hold');
$add('Engine daily cache content freshness',strpos($engineSrc,'te_daily_content_freshness')!==false&&strpos($engineSrc,'TE_DAILY_STALE_RETRY_SEC')!==false&&strpos($engineSrc,'content_refresh_attempt_at')!==false,'1d cache freshness is based on completed-session date, not saved_at alone');
$add('Engine KR stale Yahoo fallback',strpos($engineSrc,'te_fetch_daily_content_aware')!==false&&strpos($engineSrc,'te_naver_daily')!==false&&strpos($engineSrc,'te_choose_fresher_daily')!==false,'non-empty but stale Yahoo KR daily -> Naver/ KRX fallback -> fresher completed session wins');
$add('Engine active-position exit telemetry',strpos($engineSrc,'position_quote_fail')!==false&&strpos($engineSrc,'exit_decisions')!==false,'position quote/exit decision diagnostics persisted');
$add('Analysis evidence actual through-date',strpos($engineSrc,'requested_through_date')!==false&&strpos($engineSrc,'through_date_relation')!==false,'cached evidence reports actual last bar date');
$add('Engine market ISO helper',strpos($engineSrc,'function te_iso_tz')!==false,'market-local timestamp helper');
$dashSrc=p3_read($root.'/trade_dashboard.php');
$add('Dashboard installed Engine parser',strpos($dashSrc,'td_installed_engine_info')!==false&&strpos($dashSrc,'TE_VERSION')!==false&&strpos($dashSrc,'TE_REV')!==false,'trade_engine.php TE_VERSION/TE_REV visible');
$add('Dashboard per-strategy Engine matrix',strpos($dashSrc,'td_engine_runtime_matrix')!==false&&strpos($dashSrc,'ENGINE VERSION MISMATCH')!==false,'installed source vs DTS/ABC/DAS/STC26 runtime comparison');
$add('Dashboard runtime Engine state priority',strpos($dashSrc,"runtime_engine_version")!==false&&strpos($dashSrc,"engine_state.json")!==false&&strpos($dashSrc,"Evidence priority")!==false,'actual runtime engine_state version/rev has highest precedence');
$add('Dashboard Engine evidence label',strpos($dashSrc,'Engine 근거:')!==false&&strpos($dashSrc,"summary_by_strategy.runtime_version")!==false,'runtime evidence source visible and fallback chain present');
$add('Dashboard Engine badges',strpos($dashSrc,'Engine <?php echo td_h')!==false&&strpos($dashSrc,'CURRENT')!==false&&strpos($dashSrc,'STALE')!==false,'per-strategy Engine runtime badge');
$add('Dashboard smart visual command center',strpos($dashSrc,'4전략 한눈에')!==false&&strpos($dashSrc,'시장 레짐')!==false&&strpos($dashSrc,'strategy-visual-grid')!==false&&strpos($dashSrc,'주문 파이프라인')!==false,'compact graphic-first command center with market regime');
$add('Dashboard market-wait semantics',strpos($dashSrc,'market_wait_recheck_at')!==false&&strpos($dashSrc,'Broker 상태')!==false&&strpos($dashSrc,'시장대기')!==false,'scheduled market wait separated from broker failure age');
$add('Dashboard progressive disclosure',strpos($dashSrc,'검증 상세')!==false&&strpos($dashSrc,'성과 상세 분석')!==false&&strpos($dashSrc,'브로커 관리·진단')!==false,'detail-heavy information collapsed by default');
$add('Dashboard validation pending visibility',strpos($dashSrc,'판정 대기')!==false&&strpos($dashSrc,'validation-sub')!==false,'resolved progress and pending samples shown together');
$add('Dashboard validation single surface',strpos($dashSrc,'VALIDATION_SINGLE_SURFACE_V1')!==false&&strpos($dashSrc,'검증 진행 · 전체 요약')!==false&&strpos($dashSrc,'<span class="label">검증</span>')===false,'validation summary appears once in operations overview; strategy cards do not duplicate it');
$add('Dashboard order timeline',strpos($dashSrc,'order-timeline')!==false&&strpos($dashSrc,'최근 5건')!==false&&strpos($dashSrc,'사유 보기')!==false,'mobile-first recent order cards');
$add('Dashboard future-time label',strpos($dashSrc,'다음 확인')!==false&&strpos($dashSrc,"date('m/d H:i'")!==false,'future market-wait time explicitly labeled');
$add('Dashboard STC26 PAPER ONLY',strpos($dashSrc,'PAPER ONLY')!==false,'challenger safety role remains visible');
$add('Dashboard alert ACK state machine',strpos($dashSrc,'ALERT_ACK_STATE_MACHINE_V1')!==false&&strpos($dashSrc,'dashboard_action')!==false&&strpos($dashSrc,'ack_alert')!==false,'user acknowledgement is persisted separately from execution state');
$add('Dashboard alert ACK does not suppress monitoring',strpos($dashSrc,'td_alert_ack_prune')!==false&&strpos($dashSrc,'원인 감시는 계속')!==false,'acknowledgement records seen-state only; resolved alerts prune automatically');
$add('Dashboard tick escalation re-arm',strpos($dashSrc,'tick_90m')!==false&&strpos($dashSrc,'tick_180m')!==false&&strpos($dashSrc,'tick_360m')!==false,'acknowledged tick delay re-opens on escalation bands');
$add('Dashboard alert ACK token guard',strpos($dashSrc,'td_action_token_valid($TD_BASE_DIR,$ackAction,$ackToken)')!==false,'alert acknowledgement uses action token and active-alert key validation');
$add('Dashboard dynamic risk graphics',strpos($dashSrc,'visualSymbolLimit')!==false&&strpos($dashSrc,'위험 한도 초과 없음')!==false&&strpos($dashSrc,"invest_pct'] <=>")!==false,'dynamic symbol limit + percent-ranked concentration view');

$add('PHP >= 7.4',PHP_VERSION_ID>=70400,PHP_VERSION);

$expected=[
 ['dts.php','dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1','CORE','INTRADAY_TREND'],
 ['abc.php','abc-v542-market-timezone-bar-date-20260912-r1','CORE','PULLBACK_CONTINUATION'],
 ['das.php','das-v303-market-timezone-data-timestamp-20260912-r1','CORE','RELATIVE_STRENGTH'],
 ['stc26.php','stc26-v301-daily-opportunity-shadow-20260910-r1','CHALLENGER','OVERSOLD_REVERSAL'],
 ['trade_engine.php','trade-engine-v446-sell-exchange-provenance-20260923-r1','',''],
 ['trade_broker.php','trade-broker-v5910-jpx-builtin-calendar-parity-20260923-r1','',''],
 ['trade_dashboard.php','trade-dashboard-v3411-header-cleanup-20260923-r1','',''],
];
foreach($expected as$e){$r=p3_check_source($root,$e[0],$e[1],$e[2],$e[3]);$checks[]=$r;if(!$r['ok'])$ok=false;}

// Validation is gated by the same concrete capabilities verified above, not by formatting
// or one exact TV_VERSION/TV_REV declaration. This eliminates a duplicate false blocker when
// an operational copy is repacked while preserving the v1.2.6 ERRC behavior contract.
$validationRequiredMarkers=[
    'tv_repair_pending_due_ts_persist','INSUFFICIENT_SAMPLE','display_profit_factor',
    'tv_load_checked','SCHEMA_MISMATCH','.corrupt.','TV_QUARANTINE_KEEP',
    'TV_COMMIT_SCHEMA','PARTIAL_COMMIT_DETECTED','tv_market_timezone',
    'tv_horizons($c,$signalTs,$market)','tv_resolution_key','resolution_dedupe',
    'TV_STATE_INDEX_MAX','TV_EVENT_DEDUPE_MAX'
];
$validationMissingMarkers=[];
foreach($validationRequiredMarkers as$marker)if(strpos($validationSrc,$marker)===false)$validationMissingMarkers[]=$marker;
$validationIdentityOk=$validationSrc!==''&&count($validationMissingMarkers)===0;
$validationVersion='';$validationRev='';
if(preg_match("/define\s*\(\s*['\"]TV_VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i",$validationSrc,$m))$validationVersion=(string)$m[1];
if(preg_match("/define\s*\(\s*['\"]TV_REV['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i",$validationSrc,$m))$validationRev=(string)$m[1];
$validationDetail=$validationIdentityOk
    ?('capability verified'.($validationVersion!==''?' · '.$validationVersion:'').($validationRev!==''?' · '.$validationRev:''))
    :($validationSrc===''?'MISSING':('missing capability: '.implode(', ',$validationMissingMarkers)));
$add('trade_validation.php',$validationIdentityOk,$validationDetail);

$engine=p3_read($root.'/trade_engine.php');
$validation=p3_read($root.'/trade_validation.php');
$broker=p3_read($root.'/trade_broker.php');
$dash=p3_read($root.'/trade_dashboard.php');
$scheduler=p3_read($root.'/trade_scheduler.php');
$dts=p3_read($root.'/dts.php');
$abc=p3_read($root.'/abc.php');
$das=p3_read($root.'/das.php');
$stc26=p3_read($root.'/stc26.php');

$add('SWING file absent',!is_file($root.'/swing.php'),$root.'/swing.php');
$add('SWING runtime absent',!is_dir($root.'/swing_runtime'),$root.'/swing_runtime');

$legacySwingPositions=p3_active_position_rows($root.'/swing_runtime/positions.json');
$add('legacy SWING active position == 0',count($legacySwingPositions)===0,$legacySwingPositions?:'0');

$activeSwingOrders=p3_active_swing_orders($activeBrokerRuntime.'/broker_orders.json');
$add('active SWING broker order == 0',count($activeSwingOrders)===0,$activeSwingOrders?:'0');
if((is_file($root.'/swing.php')||is_dir($root.'/swing_runtime'))&&count($legacySwingPositions)===0&&count($activeSwingOrders)===0)$warnings[]=['swing_residue_safe_to_archive'=>true,'paths'=>array_values(array_filter([$root.'/swing.php',$root.'/swing_runtime'],static function($x){return is_file($x)||is_dir($x);})), 'note'=>'SWING은 운영 allowlist에서 제거되었고 활성 position/order가 0입니다. V1.4 FROZEN 완전 제거 계약을 만족하려면 백업 후 /volume1/web 밖으로 이동하십시오.'];
if($singlePaper){$legacySwingOrders=p3_active_swing_orders($legacyBrokerRuntime.'/broker_orders.json');if($legacySwingOrders)$warnings[]=['legacy_swing_order_residue'=>count($legacySwingOrders),'note'=>'Legacy /trade_runtime에 SWING 잔여 주문이 있으나 SINGLE_FILE_PAPER active runtime에는 영향이 없습니다.'];}

$add('Engine v1.4 capability',strpos($engine,'three_plus_one_spec_v140')!==false&&strpos($engine,'swing_absence_contract')!==false,'three_plus_one_spec_v140');
$add('Engine active allowlist',strpos($engine,"['dts','abc','das','stc26']")!==false&&strpos($engine,'STRATEGY_NOT_IN_V140_ALLOWLIST')!==false,'DTS/ABC/DAS/STC26');
$add('Engine has no operational SWING reference',!p3_has_operational_swing_reference($engine),'SWING_ABSENCE_CONTRACT');
$add('DTS soft-stale / hard-age policy',strpos($dts,'DTS_MAX_BAR_AGE_KR_SEC=300')!==false&&strpos($dts,'DTS_MAX_BAR_AGE_US_SEC=300')!==false&&strpos($dts,'DTS_MAX_BAR_AGE_JP_SEC=1200')!==false&&strpos($dts,'DTS_HARD_BAR_AGE_KR_SEC=1800')!==false&&strpos($dts,'DTS_HARD_BAR_AGE_US_SEC=1800')!==false&&strpos($dts,'DTS_HARD_BAR_AGE_JP_SEC=3600')!==false&&strpos($dts,'ONE_MINUTE_DATA_STALE_WARNING')!==false&&strpos($dts,'ONE_MINUTE_DATA_TOO_OLD')!==false,'KR/US: 5m warning, 30m hard block · JP: 20m warning, 60m hard block');
$add('Daily strategies have no DTS 1m stale gate',strpos($abc,'ONE_MINUTE_DATA_STALE')===false&&strpos($das,'ONE_MINUTE_DATA_STALE')===false&&strpos($stc26,'ONE_MINUTE_DATA_STALE')===false,'ABC/DAS/STC26 remain daily-bar strategies');
$add('Engine intent quote timestamp fail-closed',strpos($engine,"quote_timestamp']??0")!==false&&strpos($engine,"quote_timestamp']??time()")===false,'missing quote timestamp is never fabricated as now');
$add('DAS ranked provenance',strpos($das,"'data_timestamp'=>")!==false&&strpos($das,"'provider'=>")!==false&&strpos($das,"'data_quality'=>")!==false,'ranked validation retains source/timestamp/quality');
$add('DAS entry guard structural/data validation',strpos($das,'DAS_GUARD_STRUCTURE_INVALID')!==false&&strpos($das,'DAS_GUARD_DATA_INVALID_')!==false,'rank cannot bypass structural/data eligibility');
$add('Validation resolved-primary independence population',strpos($validation,'ERRC_RESOLVED_POPULATION_V6_INTEGRITY_COMMIT_MANIFEST')!==false&&strpos($validation,"'overlap_population'=>'RESOLVED_PRIMARY'")!==false&&strpos($validation,'tv_rebuild_resolved_pairs')!==false,'independence uses same resolved-primary population as strategy validation');
$add('Validation overlap rate bounded',strpos($validation,'function tv_rate_pct')!==false&&strpos($validation,'min(100.0')!==false,'rates cannot exceed 100%');
$add('Validation ranked provenance fail-closed',strpos($validation,"'reason'=>'TIMESTAMP_INVALID'")!==false&&strpos($validation,"['data_timestamp']")!==false&&strpos($validation,"['provider']")!==false&&strpos($validation,"quality==='UNKNOWN'")!==false,'ranked validation does not fabricate current timestamp/provider');
$add('Validation due_ts repair',strpos($validation,'tv_repair_sample_due_ts')!==false&&strpos($validation,'MARKET_WEEKDAY_ESTIMATE_RESOLVER_USES_ACTUAL_BARS')!==false,'legacy due_ts=0 repaired; actual-bar resolver remains authoritative');
$add('Validation exactly-once resolution ledger',strpos($validation,'resolution_dedupe')!==false&&strpos($validation,'tv_resolution_key')!==false,'signal_id+horizon summary update is idempotent');
$add('Validation corrupt-summary rebuild guard',strpos($validation,'tv_summary_is_corrupt')!==false&&strpos($validation,'PENDING_UNRESOLVED_AFTER_CORRUPTION')!==false,'invalid derived summary quarantined and safely rebuilt from pending unique samples');
$add('Validation bounded event log',strpos($validation,'TV_EVENTS_MAX_BYTES')!==false&&strpos($validation,'tv_rotate_file')!==false,'events.jsonl rotation enabled');
$add('Validation bounded derived indexes',strpos($validation,'TV_STATE_INDEX_MAX')!==false&&strpos($validation,'TV_EVENT_DEDUPE_MAX')!==false&&strpos($validation,'tv_prune_state')!==false,'state indexes bounded');
$add('Engine strategy-safe validation export',strpos($engine,'summary_strategy_match')!==false&&strpos($engine,"['strategy']??''")!==false,'requested strategy summary selected by strategy identity, not current config hash');
$add('Engine candidate-first ChatGPT export',strpos($engine,"'CANDIDATE') return 650")!==false,'CANDIDATE retained before WATCH under size limit');
$add('Engine current DAS relative-strength evidence',strpos($engine,'TOP_DAS_RELATIVE_STRENGTH_CANDIDATE')!==false&&strpos($engine,'component_scores')!==false,'current DAS v3.0.x RS candidates/evidence exported without obsolete support-probability dependency');
$add('Engine selection-state provenance',strpos($engine,'selection_at_order_time')!==false&&strpos($engine,'ORDER_EXISTS_PROVES_PRIOR_SELECTION')!==false,'BUY_PENDING current-vs-order-time selection semantics separated');
$add('Engine unified export identity',strpos($engine,"'export_type'=>'UNIFIED'")!==false&&strpos($engine,'chatgpt_trade_UNIFIED_')!==false,'unified download is explicit in payload and filename');
$add('Engine per-strategy ChatGPT route',strpos($engine,'download_chatgpt_strategy_analysis')!==false&&strpos($engine,"chatgpt_trade_'.strtoupper(te_safe(")!==false,'strategy pages export only their own strategy payload with strategy-specific filename');
$add('Strategy UI no longer exports unified payload',strpos($engine,'?mode=download_chatgpt_strategy_analysis')!==false&&strpos($engine,'개별 분석자료')!==false,'ABC/DTS/STC26/DAS page button is per-strategy');
$exportSrc=p3_read($root.'/trade_export.php');
$add('Dedicated Trade export endpoint',is_file($root.'/trade_export.php')&&strpos($exportSrc,'trade-export-v101-full-engine-config-mobile-safe-download-20260923-r1')!==false&&strpos($exportSrc,"Content-Disposition: attachment")!==false,'dedicated mobile-safe unified/per-strategy export endpoint installed with full engine config');
$add('Dashboard unified download uses dedicated export endpoint',strpos($dash,'trade_export.php?strategy=unified&mode=download')!==false&&strpos($dash,'trade_export.php?strategy=unified&mode=view')!==false,'download + inline JSON fallback available without routing through a strategy page');
$add('Engine unified summary by strategy',strpos($engine,'summary_by_strategy')!==false&&strpos($engine,'te_unified_strategy_summary')!==false,'top-level DTS/ABC/DAS/STC26 summary available before detailed payloads');
$add('Engine stale runtime explicit status',strpos($engine,'UNIFIED_PARTIAL_STALE')!==false&&strpos($engine,'stale_strategies')!==false,'mixed runtime snapshots are explicitly flagged, never silently treated as current');
$add('Dashboard Engine mismatch remediation UI',strpos($dash,'ENGINE VERSION MISMATCH')!==false&&strpos($dash,'engineVersionMatrix')!==false&&strpos($dash,'all_current')!==false&&strpos($dash,'runtime_short')!==false,'dashboard renders per-strategy mismatch/current state from runtime Engine matrix');
$add('Engine analysis max 8MiB',strpos($engine,'TE_ANALYSIS_MAX_BYTES = 8388608')!==false,'ChatGPT/download payload bounded');
$add('Engine analysis snapshot retention',strpos($engine,'TE_ANALYSIS_SNAPSHOT_KEEP_ALL = 12')!==false&&strpos($engine,'te_prune_analysis_snapshots')!==false,'saved analysis snapshots bounded by count');
$add('Engine bounded logs',strpos($engine,'TE_LOG_MAX_BYTES = 2097152')!==false&&strpos($engine,'te_rotate_file')!==false,'engine/error logs rotate');
$add('Broker PAPER stuck SELL recovery',strpos($broker,'TB_PAPER_STUCK_SELL_RECOVERY_SEC=900')!==false&&strpos($broker,'PAPER_STUCK_SELL_STALE_MARK_RECOVERY')!==false,'PAPER SELL cannot remain actionable forever for quote freshness alone');
$add('Broker auto-approval block telemetry',strpos($broker,'approval_block_reason')!==false&&strpos($broker,'approval_last_checked_at')!==false,'AUTO pending orders explain why validation prevented approval');
$add('Broker bounded logs/archive',strpos($broker,'TB_LOG_MAX_BYTES=2097152')!==false&&strpos($broker,'TB_ARCHIVE_MAX_BYTES=8388608')!==false&&strpos($broker,'tb_rotate_file')!==false,'broker logs/archive rotate');
$add('Engine SELL exchange provenance fallback',strpos($engine,'SELL_EXCHANGE_POSITION_FALLBACK')!==false&&strpos($engine,'te_sell_order_context')!==false,'blank SELL context venue is recovered from Broker-confirmed active position before intent signing');
$add('Broker operational exchange-map fallback',strpos($broker,'ORDER_EXCHANGE_OPERATIONAL_LIST_FALLBACK')!==false&&strpos($broker,'operationalTradeExchangeMap')!==false&&strpos($broker,'ORDER_EXCHANGE_NUMERIC_SYMBOL_KEY_PRESERVE')!==false&&strpos($broker,'array_replace($operationalTradeExchangeMap,$tradeExchangeMap,$configExchangeMap)')!==false,'stale TRADE_LIST_FILE override cannot erase venue metadata and numeric JP symbol keys are preserved');
$add('Broker JPX built-in calendar parity',strpos($broker,'BROKER_JPX_BUILTIN_CALENDAR_PARITY')!==false&&strpos($broker,"'2026-09-21','2026-09-22','2026-09-23'")!==false&&strpos($broker,"'2027-09-20','2027-09-23'")!==false,'Broker execution calendar has the same JPX 2026/2027 closure fallback as Runner');
$runnerControl=p3_read($root.'/trade_runner_control.php');
$add('Runner Control v1.4.6 aligned',strpos($runnerControl,"const RC_VERSION='v1.4.6'")!==false&&strpos($runnerControl,"const RC_EXPECTED_RUNNER_VERSION='v1.4.6'")!==false&&strpos($runnerControl,'trade-low-load-runner-web-control-v146-approval-actionable-gate-20260923-r1')!==false,'web control START gate matches Runner v1.4.6');
$runnerStatus=p3_read($root.'/trade_runner_status.php');
$add('Runner Status execution-truth view',strpos($runnerStatus,'trade-low-load-runner-status-v146-execution-truth-20260923-r1')!==false&&strpos($runnerStatus,'RUNNER_STATUS_EXECUTION_TRUTH_V1')!==false&&strpos($runnerStatus,'BROKER_EXECUTION_PLUS_CANONICAL_FALLBACK')!==false,'status page reports execution order gate and MARKET_WAIT semantics without stale Canonical-only priority');

$add('Dashboard provisional sample gate',strpos($dash,'VALIDATION_PROVISIONAL_GATE_V1')!==false&&strpos($dash,'TD_MIN_STAT_SAMPLE = 30')!==false&&strpos($dash,'운영 성과 잠정치')!==false,'<30 samples are exploratory, not CORE evidence');
$add('Dashboard bounded rate rendering',strpos($dash,'function td_clamp_rate')!==false&&strpos($dash,'function td_rate_text')!==false,'win/selection/overlap rates render within 0..100');
$add('Dashboard resolved-primary independence UI',strpos($dash,'RESOLVED_PRIMARY')!==false&&strpos($dash,'완료 primary 표본')!==false,'independence UI population matches validation');
$add('Dashboard performance semantics',strpos($dash,'전략 시장별 수익률')!==false&&strpos($dash,'capital.json')!==false&&strpos($dash,'capital_updated_at')!==false,'strategy account return is not mislabeled as market benchmark');
$add('Dashboard operational-vs-validation alerts',strpos($dash,'운영 경고 · 검증 안내')!==false&&strpos($dash,'운영 장애가 아닙니다')!==false,'sample-building info separated from operational faults');
$add('Validation has no RETIRED status',strpos($validation,"'RETIRED'")===false,'CORE/CHALLENGER/RESEARCH_ONLY');
$add('Broker active registry only',strpos($broker,"'stc26'=>'stc26.php'")!==false&&!p3_has_operational_swing_reference($broker),'DTS/ABC/DAS/STC26');
$add('Broker Challenger REAL hard denial',strpos($broker,'CHALLENGER_REAL_DENIED')!==false&&strpos($broker,'CHALLENGER_ACCOUNT_MODE_INVALID')!==false,'STC26 REAL forbidden');
$add('Broker ingest ACK telemetry',strpos($broker,'broker_ingest_state')!==false&&strpos($broker,'BROKER_SEEN')!==false&&strpos($broker,'broker_ingest_audit_v1')!==false,'INTENT_CREATED -> BROKER_SEEN');
$add('Broker durable spool merge',strpos($broker,'tb_spool_load_intents')!==false&&strpos($broker,'tb_merge_intent_sources')!==false&&strpos($broker,'new_from_spool')!==false,'shared order_intents + durable spool');
$add('Broker ACK file persistence',strpos($broker,'tb_write_ack')!==false&&strpos($broker,'broker_intent_ack_v1')!==false,'broker_seen ACK persisted after broker_orders save');
$add('Broker shared intent lock read',strpos($broker,'tb_intent_source_read')!==false&&strpos($broker,'LOCK_SH')!==false,'order_intents.lock shared read');
$add('JP PAPER KIS live requote',strpos($broker,'PAPER_JP_KIS_REQUOTE')!==false&&strpos($broker,'KIS_PAPER_JP_LIVE_REQUOTE')!==false,'JP delayed scan quote -> KIS quote-only requote before PAPER fill');
$add('Engine Broker 1m schedule contract',strpos($engine,'TE_BROKER_EXPECTED_CYCLE_SEC = 60')!==false&&strpos($engine,'TE_BROKER_STALE_SEC = 180')!==false,'Engine observes Broker every 60s; stale after 180s');
$add('Broker 1m schedule contract',strpos($broker,'TB_EXPECTED_CYCLE_SEC=60')!==false&&strpos($broker,'TB_CAUTION_CYCLE_SEC=120')!==false&&strpos($broker,'TB_STALE_CYCLE_SEC=180')!==false,'Broker expected 60s; caution 120s; stale 180s');
$add('Broker cycle elapsed telemetry',strpos($broker,'cycle_elapsed_sec')!==false&&strpos($dash,'브로커 cycle 실행시간 주의')!==false,'detect >60s cycle overlap risk');
$add('Broker PAPER fill timing preserved',strpos($broker,"PAPER_FILL_DELAY_SEC',300")!==false&&strpos($broker,"PAPER_FORCE_COMPLETE_SEC',900")!==false,'1m cycle does not shorten staged PAPER fill model');
$add('Strategy cadence preserved',strpos($broker,"'strategy_cycle_sec_default'=>1200")!==false&&strpos($broker,"'dts'=>60")!==false,'DTS 60s; ABC/DAS/STC26 1200s unchanged');
$add('JP requote timeout is retryable terminal',strpos($broker,'JP_LIVE_REQUOTE_TIMEOUT')!==false&&strpos($broker,'tb_is_jp_quote_terminal')!==false,'no EXPIRED cooldown for quote-only technical timeout');
$add('Engine JP requote cooldown exemption',strpos($engine,'JP_LIVE_REQUOTE_RETRY')!==false&&strpos($engine,'te_is_jp_quote_terminal')!==false,'JP quote technical terminal -> WAIT_SIGNAL');
$add('Engine missing-ingest diagnostics',strpos($engine,'BROKER_INGEST_MISSING')!==false&&strpos($engine,'BROKER_ORDER_MISSING_AFTER_INGEST')!==false,'missing broker stage remains observable');
$add('Engine durable intent spool',strpos($engine,'te_write_intent_spool')!==false&&strpos($engine,'te_ensure_intent_spool')!==false&&strpos($engine,'te_intent_spool_v1')!==false,'BUY handoff survives shared-file visibility failures');
$add('Engine ACK wait is non-terminal',strpos($engine,'BROKER_ACK_WAIT')!==false&&strpos($engine,'if($status===\'BROKER_ACK_WAIT\')return true')!==false,'unseen BUY not silently expired');
$add('Engine BUY TTL ingestion margin',strpos($engine,'TE_INTENT_TTL_AUTO = 2100')!==false,'35m default; DTS strategy override preserved');
$add('Dashboard no SWING operational UI',!p3_has_operational_swing_reference($dash),'CORE 3 + CHALLENGER 1 + LAB');
$add('Dashboard fixed 4-strategy validation roster',strpos($dash,'FIXED_VALIDATION_ROSTER_V140:DTS,ABC,DAS,STC26')!==false&&strpos($dash,'data-validation-strategy')!==false,'DTS/ABC/DAS/STC26 always visible');
$add('Dashboard intent handoff observability',strpos($dash,'Broker ACK 대기')!==false&&strpos($dash,'Engine→Broker handoff 대기')!==false,'spool/ACK backlog visible');
$add('Dashboard JP requote UI observability',strpos($dash,'JP_REQUOTE_UI_OBSERVABILITY_V1')!==false&&strpos($dash,'JP 실시간 재호가 대기')!==false&&strpos($dash,'KIS 재호가 성공')!==false,'market Gate / order wait / JP live requote states separated');
$add('Dashboard Runner scheduler authority',strpos($dash,'Low-Load Runner is the scheduler authority')!==false&&strpos($dash,'TD_RUNNER_HEARTBEAT_WARN_SEC = 45')!==false,'SINGLE_FILE_PAPER scheduling authority is Runner, not legacy 1-minute Broker cron');
$add('Dashboard active-order delay split',strpos($dash,'TD_ACTIVE_ORDER_WARN_SEC = 900')!==false&&strpos($dash,'$oldest > TD_ACTIVE_ORDER_WARN_SEC')!==false,'staged PAPER 300~900s wait separated from actionable delay');
$add('Dashboard Broker EVENT policy UI',strpos($dash,'Broker는 EVENT 정책에 따라 Low-Load Runner가 자동 실행합니다')!==false&&strpos($dash,'broker_active_interval_sec')!==false&&strpos($dash,'broker_idle_full_sweep_sec')!==false,'Runner quick poll / active Broker / idle safety policy visible');
$add('Dashboard JP wait excluded from generic order delay',strpos($dash,"td_jp_requote_code(\$order) === 'WAIT'")!==false&&strpos($dash,'jp_requote_wait_oldest_age_sec')!==false,'JP requote wait is not generic actionable delay');
$add('Dashboard shared seed regression',strpos($dash,'CORE_SHARED_ONE_SEED_PER_MARKET')!==false,'account/UI regression preserved');
$add('Dashboard LAB read-only research view',strpos($dash,'td_lab_research')!==false&&strpos($dash,'lab_runtime_v2/lab_state.json')!==false&&strpos($dash,'RESEARCH_ONLY')!==false,'LAB not executed by dashboard');

if($scheduler!==''){
    $warnings[]=['legacy_trade_scheduler_present'=>true,'note'=>'SINGLE_FILE_PAPER의 현재 실행 권한은 Low-Load Runner입니다. trade_scheduler.php가 존재하더라도 중복 daemon/cron으로 실행하지 마십시오.'];
}else{
    $warnings[]='trade_scheduler.php absent: 정상. 현재 SINGLE_FILE_PAPER는 Low-Load Runner가 Broker/전략 heavy job을 직렬 관리합니다.';
}

$add('trade_list.php exists',is_file($root.'/trade_list.php'),$root.'/trade_list.php');
$tradeListValidation=p3_trade_list_validate($root.'/trade_list.php');
$counts=p3_trade_list_counts($root.'/trade_list.php');
$add('trade_list dynamic contract valid',$tradeListValidation['ok'],['version'=>$tradeListValidation['version'],'schema'=>$tradeListValidation['schema'],'contract'=>$tradeListValidation['contract'],'sha256'=>$tradeListValidation['sha256'],'total'=>$tradeListValidation['total'],'enabled'=>$tradeListValidation['enabled_total'],'counts'=>$tradeListValidation['counts'],'errors'=>$tradeListValidation['errors']]);
$add('trade_list cardinality derived dynamically',$tradeListValidation['loaded']&&$tradeListValidation['total']>0&&$tradeListValidation['enabled_total']>0,'no fixed production cardinality gate; actual enabled='.$tradeListValidation['enabled_total']);

$runtimeWritable=[
    'active validation runtime'=>$activeValidationRuntime,
    'active broker runtime'=>$activeBrokerRuntime,
    'active broker intent spool'=>$activeBrokerRuntime.'/intent_spool',
    'active broker intent ack'=>$activeBrokerRuntime.'/intent_ack',
    'dts_runtime'=>$root.'/dts_runtime',
    'abc_runtime'=>$root.'/abc_runtime',
    'das_runtime'=>$root.'/das_runtime',
    'stc26_runtime'=>$root.'/stc26_runtime',
];
foreach($runtimeWritable as$label=>$path)$add($label.' writable/creatable',p3_writable_or_parent($path),$path);

if(is_file($activeValidationRuntime.'/events.jsonl')&&!is_readable($activeValidationRuntime.'/events.jsonl'))$warnings[]='active validation events.jsonl is not readable: '.$activeValidationRuntime;
foreach(['validation_state.json','validation_pending.jsonl','validation_resolved.jsonl','validation_summary.json']as$f)
    if(is_file($activeValidationRuntime.'/'.$f))$warnings[]='non-canonical validation file present in active runtime: '.$activeValidationRuntime.'/'.$f;
if($singlePaper&&is_dir($legacyValidationRuntime)&&realpath($legacyValidationRuntime)!==realpath($activeValidationRuntime))
    $warnings[]=['legacy_validation_runtime_present'=>true,'path'=>$legacyValidationRuntime,'note'=>'SINGLE_FILE_PAPER 검증 판정에는 사용하지 않습니다.'];

// Shared Engine -> Broker path and active CORE intent gap checks.
$vPending=p3_json($activeValidationRuntime.'/pending.json',[]);$vSummary=p3_json($activeValidationRuntime.'/strategy_summary.json',[]);$zeroDue=0;foreach((array)($vPending['samples']??[])as$sample)if(is_array($sample))foreach((array)($sample['horizons']??[])as$h)if(is_array($h)&&strtoupper((string)($h['status']??''))!=='RESOLVED'&&(int)($h['due_ts']??0)<=0)$zeroDue++;$summaryInvariant=true;foreach((array)($vSummary['strategies']??[])as$vs)if(is_array($vs)){if((int)($vs['resolved_primary']??0)>(int)($vs['signals']??0))$summaryInvariant=false;foreach((array)($vs['horizons']??[])as$vh)if(is_array($vh)&&(int)($vh['resolved']??0)>(int)($vs['signals']??0))$summaryInvariant=false;}$add('Runtime validation summary invariant',$summaryInvariant,$summaryInvariant?'resolved <= signals':'CORRUPT_DERIVED_SUMMARY');if($zeroDue>0)$warnings[]=['validation_zero_due_horizons'=>$zeroDue,'runtime'=>$activeValidationRuntime,'note'=>'Run validation resolver; legacy signal_time fallback should repair these values.'];

$intentFile=$activeBrokerRuntime.'/order_intents.json';$orderFile=$activeBrokerRuntime.'/broker_orders.json';
$intentRoot=p3_json($intentFile,[]);$intentRows=is_array($intentRoot['intents']??null)?$intentRoot['intents']:[];
$orderRoot=p3_json($orderFile,[]);$orderRows=is_array($orderRoot['orders']??null)?$orderRoot['orders']:[];
$brokerIds=[];foreach($orderRows as$o)if(is_array($o)&&($o['order_id']??'')!=='')$brokerIds[(string)$o['order_id']]=true;
$missingCore=[];$now=time();
foreach($intentRows as$i){
    if(!is_array($i))continue;$id=(string)($i['order_id']??'');$side=strtoupper((string)($i['side']??''));$st=strtoupper((string)($i['status']??'INTENT_CREATED'));$strategyStatus=strtoupper((string)($i['strategy_status']??'CORE'));$created=strtotime((string)($i['created_at']??''));
    if($id===''||$side!=='BUY'||$strategyStatus!=='CORE'||isset($brokerIds[$id])||in_array($st,['CANCELLED','REJECTED','EXPIRED','BROKER_INGEST_MISSING','BROKER_ORDER_MISSING_AFTER_INGEST','FILLED','PAPER_FILLED'],true))continue;
    $age=$created===false?0:max(0,$now-$created);if($age>180)$missingCore[]=['order_id'=>$id,'strategy'=>$i['strategy_key']??'','market'=>$i['market']??'','symbol'=>$i['symbol']??'','age_sec'=>$age];
}
$authorityTransportContract=strpos($engine,'te_runtime_authority_context')!==false&&strpos($broker,'tb_runtime_authority_context')!==false&&strpos($engine,'trade_single_compat')!==false&&strpos($broker,'trade_single_compat')!==false&&strpos($engine,'order_intents.json')!==false&&strpos($broker,'order_intents.json')!==false;
$add('Authority-aware shared intent runtime contract',$authorityTransportContract,['authority'=>$authority,'active_broker_runtime'=>$activeBrokerRuntime]);
$add('Active CORE BUY intent seen by Broker within 180s',count($missingCore)===0,$missingCore?:'0');


$spoolDir=$activeBrokerRuntime.'/intent_spool';$ackDir=$activeBrokerRuntime.'/intent_ack';
$spoolRows=[];$oldSpool=[];
foreach(glob($spoolDir.'/*.json')?:[]as$f){
    $x=p3_json($f,[]);$i=is_array($x['intent']??null)?$x['intent']:[];
    $id=(string)($i['order_id']??$x['order_id']??'');if($id==='')continue;
    $ack=$ackDir.'/'.preg_replace('/[^A-Za-z0-9_-]/','_',$id).'.json';if(is_file($ack))continue;
    $created=strtotime((string)($i['created_at']??$x['created_at']??''));$age=$created===false?0:max(0,$now-$created);
    $row=['order_id'=>$id,'strategy'=>$i['strategy_key']??'','market'=>$i['market']??'','symbol'=>$i['symbol']??'','age_sec'=>$age];
    $spoolRows[]=$row;if($age>180)$oldSpool[]=$row;
}
$add('Durable spool/ACK directories',p3_writable_or_parent($spoolDir)&&p3_writable_or_parent($ackDir),['spool'=>$spoolDir,'ack'=>$ackDir]);
$add('Active Broker ACK backlog within 180s',count($oldSpool)===0,$oldSpool?:'0');
if($oldSpool)$warnings[]=['broker_ack_wait_over_180s'=>$oldSpool,'runtime'=>$activeBrokerRuntime,'note'=>'Durable intent exists but active Broker ACK is delayed. Runner/Broker EVENT path must be checked.'];
$approvalRow=p3_json($activeBrokerRuntime.'/approval_mode.json',[]);
$approvalModeFile=strtolower(trim((string)($approvalRow['mode']??'')));
$oldestPendingAckAge=$spoolRows?max(array_column($spoolRows,'age_sec')):0;
$add('Active Broker pending ACK count observed',true,['pending_ack_count'=>count($spoolRows),'oldest_pending_ack_age_sec'=>$oldestPendingAckAge,'runtime'=>$activeBrokerRuntime]);


if($singlePaper){
    $add('Engine SINGLE_FILE_PAPER runtime authority',strpos($engine,'te_runtime_authority_context')!==false&&strpos($engine,"trade_single_compat")!==false,'Engine writes Broker transport to compat runtime');
    $add('Broker SINGLE_FILE_PAPER runtime authority',strpos($broker,'tb_runtime_authority_context')!==false&&strpos($broker,"trade_single_compat")!==false,'Broker reads/writes the same compat Broker runtime');
    $legacySpool=$legacyBrokerRuntime.'/intent_spool';$compatSpool=$activeBrokerRuntime.'/intent_spool';
    $legacyPending=0;
    foreach(glob($legacySpool.'/*.json')?:[] as$f){
        $x=p3_json($f,[]);$i=is_array($x['intent']??null)?$x['intent']:[];
        $id=(string)($i['order_id']??$x['order_id']??'');if($id==='')continue;
        $side=strtoupper((string)($i['side']??''));$exp=strtotime((string)($i['order_expires_at']??$i['expires_at']??''));
        if($side!=='SELL'&&$exp!==false&&$exp<time())continue;
        if(is_file($legacyBrokerRuntime.'/intent_ack/'.preg_replace('/[^A-Za-z0-9_-]/','_',$id).'.json'))continue;
        $legacyPending++;
    }
    if($legacyPending>0)$warnings[]=['legacy_transport_pending'=>$legacyPending,'source'=>$legacySpool,'target'=>$compatSpool,'note'=>'v4.4.5 strategy tick will safely migrate eligible unacked legacy spool intents into the active SINGLE_FILE_PAPER transport.'];
}

$runnerHb=p3_json($root.'/trade_runner_runtime/daemon_heartbeat.json',[]);
$runnerState=p3_json($root.'/trade_runner_runtime/runner_state.json',[]);
$hb=p3_json($activeBrokerRuntime.'/broker_heartbeat.json',[]);
$approvalModeHeartbeat=strtolower(trim((string)($hb['approval_mode']??'')));
$approvalMode=in_array($approvalModeFile,['auto','manual'],true)?$approvalModeFile:(in_array($approvalModeHeartbeat,['auto','manual'],true)?$approvalModeHeartbeat:'');
$approvalModeSource=in_array($approvalModeFile,['auto','manual'],true)?'approval_mode.json':(in_array($approvalModeHeartbeat,['auto','manual'],true)?'broker_heartbeat.json':'UNOBSERVED');
if($approvalMode==='')$warnings[]=['approval_mode_unobserved'=>true,'runtime'=>$activeBrokerRuntime,'note'=>'Active Broker approval mode was not observable from approval_mode.json or heartbeat. Broker config/default may still resolve it at execution time; this is telemetry warning, not a deployment blocker.'];
if($runnerHb){
    $rts=(int)($runnerHb['epoch']??0);$rage=$rts>0?max(0,time()-$rts):PHP_INT_MAX;
    if($rage>45)$warnings[]=['runner_heartbeat_stale'=>true,'age_sec'=>$rage,'at'=>$runnerHb['at']??null,'note'=>'Low-Load Runner daemon heartbeat가 45초를 초과했습니다. Broker cron이 아니라 Runner 상태를 확인하십시오.'];
}else{$warnings[]='trade_runner_runtime/daemon_heartbeat.json not found; SINGLE_FILE_PAPER에서는 Low-Load Runner daemon 상태를 확인하십시오.';}
if($hb){
    $last=(string)($hb['last_cycle_at']??'');$ts=$last!==''?strtotime($last):false;$age=$ts===false?PHP_INT_MAX:max(0,time()-$ts);
    if(!$runnerHb&&$age>3900)$warnings[]=['broker_idle_safety_overdue'=>true,'runtime'=>$activeBrokerRuntime,'last_cycle_at'=>$last,'age_sec'=>$age,'note'=>'Runner heartbeat도 없고 active Broker 마지막 cycle이 idle safety 3600초를 초과했습니다.'];
}

$runnerSrc=p3_read($root.'/trade_runner.php');
if($runnerSrc!==''){
    $add('Low-Load Runner v1.4.6 approval-actionable gate',strpos($runnerSrc,"const TR_VERSION='v1.4.6'")!==false&&strpos($runnerSrc,'BROKER_EXECUTION_PLUS_CANONICAL_FALLBACK')!==false&&strpos($runnerSrc,'broker_actionable_watchdog_sec')!==false&&strpos($runnerSrc,'STRATEGY_STALE_WATCHDOG')!==false&&strpos($runnerSrc,'APPROVAL_BLOCK_ACTIONABLE_GATE')!==false&&strpos($runnerSrc,'trade-low-load-runner-v146-approval-actionable-gate-20260923-r1')!==false,'blocked/unapproved PENDING remains ACTIONABLE even during closed session');
    $runnerTransportContract=strpos($runnerSrc,'tr_transport_gate')!==false&&strpos($runnerSrc,"candidateReason='TRANSPORT_PENDING'")!==false&&strpos($runnerSrc,'trade_single_compat/trade_runtime')!==false&&strpos($runnerSrc,'broker_idle_full_sweep_sec')!==false;
    $add('Runner auto Broker transport contract',$runnerTransportContract,'compat transport PENDING -> Broker EVENT candidate; idle safety remains bounded');
}
if($singlePaper){
    if($runnerState){
        $scheduler=is_array($runnerState['scheduler']??null)?$runnerState['scheduler']:[];
        $transport=is_array($scheduler['transport_gate']??null)?$scheduler['transport_gate']:[];
        $brokerJob=is_array($runnerState['jobs']['broker']??null)?$runnerState['jobs']['broker']:[];
        $transportPending=(int)($transport['pending_count']??0);
        $transportIntegrity=!empty($transport['integrity_error']);
        $transportTransient=!empty($transport['transient_error']);
        if($transportIntegrity)$warnings[]=['runner_transport_integrity_error'=>true,'reason'=>$transport['reason']??'','runtime'=>$activeBrokerRuntime,'note'=>'Runner transport truth is fail-closed; Broker heavy execution must not be forced until integrity recovers.'];
        if($transportTransient)$warnings[]=['runner_transport_transient_error'=>true,'reason'=>$transport['reason']??'','runtime'=>$activeBrokerRuntime,'note'=>'Runner transport gate is temporarily unavailable; bounded retry is expected.'];
        if(count($spoolRows)>0&&$transportPending<=0&&!$transportIntegrity&&!$transportTransient)$warnings[]=['runner_transport_visibility_mismatch'=>true,'spool_pending'=>count($spoolRows),'runner_pending_count'=>$transportPending,'runtime'=>$activeBrokerRuntime,'note'=>'Active spool has unacked intents but Runner transport gate reports no pending transport. Recheck Runner state after the next lightweight gate refresh.'];
    }else{
        $warnings[]=['runner_state_missing'=>true,'path'=>$root.'/trade_runner_runtime/runner_state.json','note'=>'Runner runtime telemetry is unavailable. This is not a source-code deployment blocker, but live EVENT scheduling cannot be confirmed from preflight.'];
    }
}

$active=[];
foreach(['dts','abc','das','stc26']as$key){
    foreach(p3_active_position_rows($root.'/'.$key.'_runtime/positions.json')as$r)
        $active[]=['strategy'=>$key,'market'=>$r['market']??'','symbol'=>$r['symbol']??'','qty'=>$r['qty']??0,'entry_strategy_rev'=>$r['entry_strategy_rev']??''];
}
if($active)$warnings[]=['open_positions_detected'=>$active,'note'=>'Active v1.4 positions detected; preserve their runtimes during deployment.'];

$blockingChecks=[];foreach($checks as $c){if(empty($c['ok']))$blockingChecks[]=$c;}

$result=[
 'ok'=>$ok,'version'=>P3_VERSION,'rev'=>P3_REV,'spec'=>'3+1 Trading System v1.4 FROZEN',
 'checked_at'=>date('c'),'root'=>$root,'blocking_check_count'=>count($blockingChecks),'blocking_checks'=>$blockingChecks,'authority'=>$authority,'single_file_paper'=>$singlePaper,'active_broker_runtime'=>$activeBrokerRuntime,'active_validation_runtime'=>$activeValidationRuntime,'runtime_telemetry'=>['approval_mode'=>$approvalMode,'approval_mode_source'=>$approvalModeSource,'pending_ack_count'=>count($spoolRows),'oldest_pending_ack_age_sec'=>$oldestPendingAckAge,'runner_transport_gate'=>is_array($runnerState['scheduler']['transport_gate']??null)?$runnerState['scheduler']['transport_gate']:[],'runner_broker_job'=>is_array($runnerState['jobs']['broker']??null)?$runnerState['jobs']['broker']:[]],'checks'=>$checks,'warnings'=>$warnings,
 'trade_list_counts'=>$counts,'trade_list_validation'=>$tradeListValidation,'deployment_status'=>$ok?'V140_PREFLIGHT_PASS':'V140_DEPLOYMENT_BLOCKED'
];
if(PHP_SAPI==='cli'){echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;exit($ok?0:2);}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);