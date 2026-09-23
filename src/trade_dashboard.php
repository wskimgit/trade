<?php
/**
 * TRADE DASHBOARD v3.4.9 · ERRC
 * 3+1 v1.4 unified dashboard: CORE DTS/ABC/DAS + CHALLENGER STC26 + Validation/Broker. SWING removed.
 * Target: Synology DS213 Air / PHP 7.4 compatible.
 *
 * Distribution filename: trade_dashboard.php
 * Operational filename: trade_dashboard.php
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');

// 운영 교체 직후 모바일 브라우저·프록시가 구형 대시보드를 재사용하지 않도록 한다.
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
    header('X-Trade-Dashboard-Revision: trade-dashboard-v349-dedicated-export-route-20260923-r1');
}

const TD_VERSION = 'v3.4.9 3+1-v1.4 · EXPORT-FIX · ALERT-ACK · SMART-VISUAL · LOW-LOAD · ERRC';
const TD_REV = 'trade-dashboard-v349-dedicated-export-route-20260923-r1';
const TD_EXPECTED_OPERATIONAL_FILENAME = 'trade_dashboard.php';
const TD_REFRESH_SEC = 0; // 0 = no auto refresh. Add ?refresh=60 for optional refresh.
const TD_STRATEGY_TICK_WARN_SEC = 5400; // 30분 cron 3회(90분) 이상 미실행 시 경고.
const TD_ENGINE_LOG_TAIL_BYTES = 65536; // 저사양 NAS에서 마지막 로그 일부만 읽는다.
const TD_VALIDATION_ROSTER_MARKER = 'FIXED_VALIDATION_ROSTER_V140:DTS,ABC,DAS,STC26';
const TD_BROKER_CRON_EXPECTED_SEC = 60;
const TD_BROKER_CRON_CAUTION_SEC = 120;
const TD_BROKER_CRON_WARN_SEC = 180;
const TD_RUNNER_HEARTBEAT_WARN_SEC = 45; // Low-Load Runner daemon heartbeat freshness.
const TD_RUNNER_DEFAULT_IDLE_BROKER_SWEEP_SEC = 3600;
const TD_RUNNER_DEFAULT_ACTIVE_BROKER_SEC = 300;
const TD_ACTIVE_ORDER_WARN_SEC = 900; // staged PAPER fill(300~900s) 정상 대기를 cron 지연과 분리
const TD_JP_REQUOTE_UI_MARKER = 'JP_REQUOTE_UI_OBSERVABILITY_V1';
const TD_ERRC_MARKER = 'ERRC_STAT_SEMANTICS_V1';
const TD_VALIDATION_PROVISIONAL_MARKER = 'VALIDATION_PROVISIONAL_GATE_V1';
const TD_MIN_STAT_SAMPLE = 30;
const TD_ALERT_ACK_SCHEMA = 'trade_dashboard_alert_ack_v1';
const TD_ALERT_ACK_MARKER = 'ALERT_ACK_STATE_MACHINE_V1';
const TD_ALERT_ACK_REARM_TICK_2X_SEC = 10800; // 180m
const TD_ALERT_ACK_REARM_TICK_4X_SEC = 21600; // 360m

$TD_BASE_DIR = __DIR__;
$TD_STRATEGIES = array(
    'dts' => array('label' => 'DTS', 'file' => 'dts.php', 'runtime' => 'dts_runtime', 'status'=>'CORE', 'alpha'=>'INTRADAY_TREND'),
    'abc' => array('label' => 'ABC', 'file' => 'abc.php', 'runtime' => 'abc_runtime', 'status'=>'CORE', 'alpha'=>'PULLBACK_CONTINUATION'),
    'das' => array('label' => 'DAS', 'file' => 'das.php', 'runtime' => 'das_runtime', 'status'=>'CORE', 'alpha'=>'RELATIVE_STRENGTH'),
    'stc26' => array('label' => 'STC26', 'file' => 'stc26.php', 'runtime' => 'stc26_runtime', 'status'=>'CHALLENGER', 'alpha'=>'OVERSOLD_REVERSAL'),
);

function td_load_symbol_map(string $baseDir): array {
    $file = rtrim($baseDir, '/\\') . '/trade_list.php';
    if (!is_file($file) || !is_readable($file)) return array();
    $raw = @include $file;
    if (!is_array($raw)) return array();
    $map = array();
    foreach (array('kr'=>'KR','us'=>'US','jp'=>'JP') as $bucket=>$market) {
        $rows = isset($raw[$bucket]) && is_array($raw[$bucket]) ? $raw[$bucket] : array();
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $symbol = strtoupper(trim((string)($row['symbol'] ?? $row['code'] ?? '')));
            if ($symbol === '') continue;
            $name = trim((string)($row['name'] ?? $row['name_ko'] ?? $row['name_en'] ?? $symbol));
            $map[$market . ':' . $symbol] = $name !== '' ? $name : $symbol;
        }
    }
    return $map;
}

function td_symbol_name(string $market, string $symbol, string $fallback = ''): string {
    global $TD_SYMBOL_MAP;
    $market = strtoupper(trim($market));
    $symbol = strtoupper(trim($symbol));
    $name = trim($fallback);
    if ($name === '' || strtoupper($name) === $symbol) {
        $key = $market . ':' . $symbol;
        if (isset($TD_SYMBOL_MAP[$key]) && trim((string)$TD_SYMBOL_MAP[$key]) !== '') $name = trim((string)$TD_SYMBOL_MAP[$key]);
    }
    return $name !== '' ? $name : $symbol;
}

function td_stock_label(string $market, string $symbol, string $fallback = ''): string {
    $symbol = strtoupper(trim($symbol));
    $name = td_symbol_name($market, $symbol, $fallback);
    if ($symbol === '') return $name !== '' ? $name : '-';
    if ($name === '' || strtoupper($name) === $symbol) return $symbol;
    return $name . ' (' . $symbol . ')';
}

function td_position_key_label(string $key): string {
    $parts = explode(':', $key);
    $n = count($parts);
    if ($n < 2) return $key;
    $symbol = strtoupper((string)$parts[$n - 1]);
    $market = strtoupper((string)$parts[$n - 2]);
    return td_stock_label($market, $symbol);
}

$TD_SYMBOL_MAP = td_load_symbol_map($TD_BASE_DIR);

function td_h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function td_clamp_rate($v): ?float {
    if (!is_numeric($v)) return null;
    return max(0.0, min(100.0, (float)$v));
}
function td_rate_text($v, int $decimals = 2, bool $provisional = false): string {
    if (!is_numeric($v)) return '-';
    $raw=(float)$v;if($raw<0.0||$raw>100.0)return '오류값';
    $r = td_clamp_rate($raw);
    return ($provisional ? '잠정 ' : '') . number_format((float)$r, $decimals) . '%';
}

function td_stat_sample_state(int $n, int $min = TD_MIN_STAT_SAMPLE): array {
    if ($n <= 0) return array('EMPTY','표본 없음','info',max(0,$min));
    if ($n < $min) return array('EXPLORATORY','잠정치','warn',max(0,$min-$n));
    if ($n < 200) return array('USABLE','검증 가능','info',0);
    return array('MATURE','안정 표본','ok',0);
}
function td_source_time(array $root, string $path): string {
    // Some runtime JSON preserves a domain timestamp while the file itself is refreshed.
    // For display-only freshness use the newer of embedded timestamp and file mtime.
    // Health decisions never rely on this helper.
    $raw = (string)($root['updated_at'] ?? $root['calculated_at'] ?? $root['timestamp'] ?? '');
    $rawTs = $raw !== '' ? (strtotime($raw) ?: 0) : 0;
    $fileTs = is_file($path) ? (int)(@filemtime($path) ?: 0) : 0;
    $ts = max($rawTs, $fileTs);
    return $ts > 0 ? date('Y-m-d H:i:s', $ts) : '';
}


function td_read_json_file(string $path) {
    if (!is_file($path) || !is_readable($path)) return null;
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}


/**
 * Resolve the one runtime authority used by Broker/Validation observability.
 * SINGLE_FILE_PAPER is fail-closed: never fall back to stale legacy trade_runtime.
 */
function td_runtime_context(string $baseDir): array {
    static $cache = array();
    $baseDir = rtrim($baseDir, '/\\');
    if (isset($cache[$baseDir])) return $cache[$baseDir];

    $markerPath = $baseDir . '/trade_phase3b_lite_v100/authority.json';
    $marker = td_read_json_file($markerPath);
    $authority = strtoupper(trim((string)(is_array($marker) ? ($marker['authority'] ?? '') : '')));
    $realAllowed = is_array($marker) ? !empty($marker['real_order_allowed']) : false;
    $singlePaper = ($authority === 'SINGLE_FILE_PAPER' && !$realAllowed);

    $brokerRuntime = $singlePaper
        ? $baseDir . '/trade_single_compat/trade_runtime'
        : $baseDir . '/trade_runtime';
    $validationRuntime = $singlePaper
        ? $baseDir . '/trade_single_compat/validation_runtime'
        : $baseDir . '/validation_runtime';

    $ctx = array(
        'authority'=>$authority !== '' ? $authority : 'LEGACY_OR_UNMARKED',
        'single_file_paper'=>$singlePaper,
        'real_order_allowed'=>$realAllowed,
        'marker_path'=>$markerPath,
        'marker_readable'=>is_array($marker),
        'broker_runtime'=>$brokerRuntime,
        'broker_runtime_exists'=>is_dir($brokerRuntime),
        'validation_runtime'=>$validationRuntime,
        'validation_runtime_exists'=>is_dir($validationRuntime),
        'legacy_broker_runtime'=>$baseDir . '/trade_runtime',
        'runner_runtime'=>$baseDir . '/trade_runner_runtime',
        'runner_config'=>$baseDir . '/trade_runner_config.php',
    );
    $cache[$baseDir] = $ctx;
    return $ctx;
}

function td_broker_runtime(string $baseDir): string {
    $ctx = td_runtime_context($baseDir);
    return (string)$ctx['broker_runtime'];
}

function td_validation_runtime(string $baseDir): string {
    $ctx = td_runtime_context($baseDir);
    return (string)$ctx['validation_runtime'];
}


function td_runner_state(string $baseDir): array {
    $ctx = td_runtime_context($baseDir);
    if (empty($ctx['single_file_paper'])) return array();
    $path = rtrim((string)$ctx['runner_runtime'], '/\\') . '/runner_state.json';
    $state = td_read_json_file($path);
    return is_array($state) ? $state : array();
}

/**
 * Runner completion is the scheduling/execution truth in SINGLE_FILE_PAPER.
 * A stale legacy engine.log must not downgrade a successful Runner child.
 * We only accept completed successful children (runs>0 + exit 0 / OK).
 */
function td_runner_strategy_evidence(string $baseDir, string $key): array {
    $ctx = td_runtime_context($baseDir);
    $path = rtrim((string)$ctx['runner_runtime'], '/\\') . '/runner_state.json';
    if (empty($ctx['single_file_paper'])) return array(
        'available'=>false,'ok'=>false,'source'=>'legacy','path'=>$path,'last_tick'=>'','last_tick_ts'=>0
    );
    $state = td_read_json_file($path);
    $job = is_array($state['jobs'][$key] ?? null) ? $state['jobs'][$key] : array();
    $runs = (int)($job['runs'] ?? 0);
    $end = trim((string)($job['last_end_at'] ?? ''));
    $ts = $end !== '' ? (strtotime($end) ?: 0) : 0;
    $exit = $job['last_exit_code'] ?? null;
    $reason = strtoupper(trim((string)($job['last_reason'] ?? '')));
    $ok = $runs > 0 && $ts > 0 && ((is_numeric($exit) && (int)$exit === 0) || $reason === 'OK');
    return array(
        'available'=>is_array($state) && !empty($state),
        'ok'=>$ok,
        'source'=>'runner_state',
        'path'=>$path,
        'last_tick'=>$end,
        'last_tick_ts'=>$ts,
        'age_sec'=>$ts > 0 ? max(0, time() - $ts) : null,
        'last_start_at'=>(string)($job['last_start_at'] ?? ''),
        'last_end_at'=>$end,
        'last_exit_code'=>$exit,
        'last_elapsed_sec'=>$job['last_elapsed_sec'] ?? null,
        'last_reason'=>(string)($job['last_reason'] ?? ''),
        'runs'=>$runs,
        'failures'=>(int)($job['failures'] ?? 0),
        'consecutive_failures'=>(int)($job['consecutive_failures'] ?? 0),
        'next_due_at'=>(int)($job['next_due_at'] ?? 0),
    );
}

function td_validation_sample_label(string $status): array {
    $status = strtoupper($status);
    if ($status === 'MATURE') return array('안정 표본', 'ok');
    if ($status === 'USABLE') return array('검증 가능', 'info');
    if ($status === 'EMPTY') return array('표본 없음', 'info');
    return array('표본 구축 중', 'warn');
}

function td_common_validation(string $baseDir, array $strategyKeys): array {
    $runtime = td_validation_runtime($baseDir);
    $summaryPath = $runtime . '/strategy_summary.json';
    $indPath = $runtime . '/independence_summary.json';
    $challengerPath = $runtime . '/challenger_summary.json';
    $pendingPath = $runtime . '/pending.json';
    $root = td_read_json_file($summaryPath);
    $ind = td_read_json_file($indPath);
    $challenger = td_read_json_file($challengerPath);
    $pendingRoot = td_read_json_file($pendingPath);
    $valid = is_array($root) && (string)($root['schema'] ?? '') === 'trade_strategy_summary_v2';
    $source = $valid && is_array($root['strategies'] ?? null) ? $root['strategies'] : array();

    // v1.4 UI invariant: active strategy roster is fixed even when a model has zero signals.
    $activeMeta = array();
    foreach ($strategyKeys as $key=>$meta) {
        $meta = is_array($meta) ? $meta : array();
        $label = strtoupper(trim((string)($meta['label'] ?? $key)));
        if ($label === '') continue;
        $activeMeta[$label] = array(
            'status'=>strtoupper((string)($meta['status'] ?? ($label==='STC26'?'CHALLENGER':'CORE'))),
            'alpha'=>(string)($meta['alpha'] ?? ''),
        );
    }

    $pendingByStrategy = array();$pendingByHash=array();$latestPendingByHash=array();
    foreach (is_array($pendingRoot['samples'] ?? null) ? $pendingRoot['samples'] : array() as $sample) {
        if (!is_array($sample)) continue;
        $st = strtoupper((string)($sample['strategy'] ?? ''));$sh=(string)($sample['strategy_hash']??'');
        if ($st !== '' && isset($activeMeta[$st])) {$pendingByStrategy[$st]=(int)($pendingByStrategy[$st]??0)+1;if($sh!==''){$pendingByHash[$sh]=(int)($pendingByHash[$sh]??0)+1;$ts=strtotime((string)($sample['registered_at']??$sample['signal_time']??''));if($ts===false)$ts=0;$latestPendingByHash[$sh]=max((int)($latestPendingByHash[$sh]??0),$ts);}}
    }

    $rowsByStrategy = array();
    foreach ($activeMeta as $strategy=>$meta) $rowsByStrategy[$strategy] = array();

    foreach ($source as $hash => $row) {
        if (!is_array($row)) continue;
        $strategy = strtoupper((string)($row['strategy'] ?? ''));
        if ($strategy === '' || !isset($activeMeta[$strategy])) continue;
        $primary = (string)($row['primary_horizon'] ?? '');
        $horizons = is_array($row['horizons'] ?? null) ? $row['horizons'] : array();
        if ($primary === '' && $horizons) { $keys = array_keys($horizons); $primary = (string)($keys[0] ?? ''); }
        $primaryRow = is_array($horizons[$primary] ?? null) ? $horizons[$primary] : array();
        $resolved = (int)($row['resolved_primary'] ?? 0);
        $signals = (int)($row['signals'] ?? 0);
        $stage = $signals <= 0 && $resolved <= 0 ? 'EMPTY' : ($resolved >= 200 ? 'MATURE' : ($resolved >= 30 ? 'USABLE' : 'INSUFFICIENT'));
        $pipe = is_array($row['pipeline'] ?? null) ? $row['pipeline'] : array();
        $pipe['selection_rate_pct'] = $signals > 0 ? td_clamp_rate(round((int)($pipe['selected'] ?? 0) / $signals * 100, 2)) : null;
        $pipe['order_rate_pct'] = $signals > 0 ? td_clamp_rate(round((int)($pipe['orders'] ?? $pipe['intents'] ?? 0) / $signals * 100, 2)) : null;
        $pipe['fill_rate_pct'] = $signals > 0 ? td_clamp_rate(round((int)($pipe['filled'] ?? 0) / $signals * 100, 2)) : null;
        $rowsByStrategy[$strategy][] = array(
            'strategy'=>$strategy,
            'strategy_status'=>(string)($row['strategy_status'] ?? $activeMeta[$strategy]['status']),
            'alpha_type'=>(string)($row['alpha_type'] ?? $activeMeta[$strategy]['alpha']),
            'strategy_hash'=>(string)$hash,
            'strategy_version'=>(string)($row['strategy_version']??''),'strategy_rev'=>(string)($row['strategy_rev']??''),'last_registered_at'=>(string)($row['last_registered_at']??''),'latest_pending_ts'=>(int)($latestPendingByHash[(string)$hash]??0),
            'registered'=>$signals,
            'pending'=>(int)($pendingByHash[(string)$hash] ?? 0),
            'resolved'=>$resolved,
            'expired'=>0,
            'primary_horizon'=>$primary,
            'minimum_usable_samples'=>30,
            'remaining_samples_to_usable'=>max(0,30-$resolved),
            'sample_status'=>$stage,
            'metrics_provisional'=>$resolved>0 && $resolved<TD_MIN_STAT_SAMPLE,
            'metrics_ready'=>$resolved>=TD_MIN_STAT_SAMPLE,
            'no_sample'=>$stage==='EMPTY',
            'primary'=>$primaryRow,
            'pipeline'=>$pipe,
            'expectancy_pct'=>$row['expectancy_pct'] ?? null,
            'median_return_pct'=>$row['median_return_pct'] ?? null,
            'profit_factor'=>$row['profit_factor'] ?? null,
            'mdd_pct'=>$row['mdd_pct'] ?? null,
        );
    }

    // ERRC v2: show exactly one current validation row per active strategy. Historical hashes remain in files, not duplicated in the operational table.
    $rows=array();$currentRows=array();$historyCounts=array();
    foreach($activeMeta as$strategy=>$meta){
        $candidates=$rowsByStrategy[$strategy]??array();$historyCounts[$strategy]=max(0,count($candidates)-1);
        if(!$candidates){$currentRows[$strategy]=array('strategy'=>$strategy,'strategy_status'=>$meta['status'],'alpha_type'=>$meta['alpha'],'strategy_hash'=>'','registered'=>0,'pending'=>(int)($pendingByStrategy[$strategy]??0),'resolved'=>0,'expired'=>0,'primary_horizon'=>'','minimum_usable_samples'=>30,'remaining_samples_to_usable'=>30,'sample_status'=>'EMPTY','metrics_provisional'=>false,'metrics_ready'=>false,'no_sample'=>true,'primary'=>array(),'pipeline'=>array('selection_rate_pct'=>null,'order_rate_pct'=>null,'fill_rate_pct'=>null),'expectancy_pct'=>null,'median_return_pct'=>null,'profit_factor'=>null,'mdd_pct'=>null,'history_versions'=>0);}
        else{usort($candidates,static function(array$a,array$b):int{$ap=(int)($a['latest_pending_ts']??0);$bp=(int)($b['latest_pending_ts']??0);if($ap!==$bp)return$ap>$bp?-1:1;$at=strtotime((string)($a['last_registered_at']??''));if($at===false)$at=0;$bt=strtotime((string)($b['last_registered_at']??''));if($bt===false)$bt=0;if($at!==$bt)return$at>$bt?-1:1;$as=(int)($a['registered']??0);$bs=(int)($b['registered']??0);if($as!==$bs)return$as>$bs?-1:1;return strcmp((string)($b['strategy_hash']??''),(string)($a['strategy_hash']??''));});$currentRows[$strategy]=$candidates[0];$currentRows[$strategy]['history_versions']=$historyCounts[$strategy];}
        $r=$currentRows[$strategy];$hash=(string)($r['strategy_hash']??'');$rows[$strategy.'@'.($hash!==''?substr($hash,0,8):'NO_SAMPLE')]=$r;
    }

    // Independence UI also uses a fixed six-pair roster so absence of ABC samples is explicit, not invisible.
    $indRoot = is_array($ind) ? $ind : array();
    $sourcePairs = is_array($indRoot['pairs'] ?? null) ? $indRoot['pairs'] : array();
    $normalizedPairs = array();
    $labels = array_keys($activeMeta);
    for ($i=0,$n=count($labels);$i<$n;$i++) {
        for ($j=$i+1;$j<$n;$j++) {
            $a=$labels[$i];$b=$labels[$j];$parts=array($a,$b);sort($parts,SORT_STRING);$pk=implode('|',$parts);
            $pair=is_array($sourcePairs[$pk] ?? null)?$sourcePairs[$pk]:array();
            $aResolved=(int)($currentRows[$parts[0]]['resolved']??0);$bResolved=(int)($currentRows[$parts[1]]['resolved']??0);$pairDen=min($aResolved,$bResolved);$pairN=(int)($pair['paired_sample_count']??$pair['metrics_sample_n']??$pair['return_pairs']??0);
            $normalizedPairs[$pk]=array_replace(array(
                'a'=>$parts[0],'b'=>$parts[1],
                'same_symbol_same_day'=>0,'same_symbol'=>0,'same_day'=>0,
                'return_pairs'=>0,'correlation'=>null,'overlap_rate_pct'=>null,
                'incremental_expectancy'=>null,'no_sample'=>true,'overlap_population'=>'RESOLVED_PRIMARY',
                'overlap_denominator'=>$pairDen,'paired_sample_count'=>$pairN,'metrics_sample_n'=>$pairN,'minimum_usable_samples'=>TD_MIN_STAT_SAMPLE,
                'remaining_samples_to_usable'=>max(0,TD_MIN_STAT_SAMPLE-$pairN),
                'sample_status'=>$pairN>=TD_MIN_STAT_SAMPLE?'USABLE':($pairN>0?'INSUFFICIENT':'EMPTY'),
            ),$pair);
            $normalizedPairs[$pk]['overlap_rate_pct']=td_clamp_rate($normalizedPairs[$pk]['overlap_rate_pct']??null);
            $normalizedPairs[$pk]['overlap_denominator']=$pairDen;
            $normalizedPairs[$pk]['paired_sample_count']=$pairN;$normalizedPairs[$pk]['metrics_sample_n']=$pairN;
            $normalizedPairs[$pk]['remaining_samples_to_usable']=max(0,TD_MIN_STAT_SAMPLE-$pairN);
            $normalizedPairs[$pk]['sample_status']=$pairN>=TD_MIN_STAT_SAMPLE?'USABLE':($pairN>0?'INSUFFICIENT':'EMPTY');
            $normalizedPairs[$pk]['metrics_provisional']=$pairN>0&&$pairN<TD_MIN_STAT_SAMPLE;
            $normalizedPairs[$pk]['no_sample']=$pairN===0;
        }
    }
    $indRoot['pairs']=$normalizedPairs;

    $signals = $valid ? (int)($root['totals']['signals'] ?? 0) : 0;
    $resolvedTotal = $valid ? (int)($root['totals']['resolved_primary'] ?? 0) : 0;
    $pendingTotal=0;foreach($pendingByStrategy as $n)$pendingTotal+=(int)$n;
    $allUsable=true;$has=false;
    foreach($activeMeta as $strategy=>$meta){$r=$currentRows[$strategy]??array();$curResolved=(int)($r['resolved']??0);$modelHasSignals=(int)($r['registered']??0)>0;$has=$has||$modelHasSignals;if(!$modelHasSignals||$curResolved<30)$allUsable=false;}
    return array(
        'available'=>$valid,'runtime'=>$runtime,'path'=>$summaryPath,'updated_at'=>$valid?(string)($root['updated_at']??''):'',
        'totals'=>array('registered'=>$signals,'pending'=>$pendingTotal,'resolved'=>$resolvedTotal,'expired'=>0),
        'strategies'=>$rows,'recent_results'=>array(),'sample_status'=>$has&&$allUsable?'USABLE':'INSUFFICIENT',
        'independence'=>$indRoot,'challenger'=>is_array($challenger)?$challenger:array(),
    );
}

function td_lab_research(string $baseDir): array {
    $base = rtrim($baseDir, '/\\');
    $paths = array(
        $base . '/lab_runtime_v2/lab_state.json',
        $base . '/lab_runtime/lab_state.json',
        $base . '/lab_runtime/state.json',
    );
    $path = '';
    $state = null;
    foreach ($paths as $candidate) {
        $row = td_read_json_file($candidate);
        if (is_array($row)) { $path = $candidate; $state = $row; break; }
    }
    if (!is_array($state)) return array('available'=>false,'path'=>'','schema'=>'','metrics'=>array(),'legacy'=>array(),'updated_at'=>'');
    $metrics = array();
    foreach (is_array($state['metrics'] ?? null) ? $state['metrics'] : array() as $hash=>$m) {
        if (!is_array($m)) continue;
        $metrics[] = array(
            'experiment_hash'=>(string)($m['experiment_hash'] ?? (is_string($hash)?$hash:'')),
            'setup'=>(string)($m['setup'] ?? ''),
            'setup_version'=>(string)($m['setup_version'] ?? ''),
            'closed_trades'=>(int)($m['closed_trades'] ?? 0),
            'unverified_trades'=>(int)($m['unverified_trades'] ?? 0),
            'expectancy_pct'=>$m['expectancy_pct'] ?? null,
            'cost_2x_expectancy_pct'=>$m['cost_2x_expectancy_pct'] ?? null,
            'oos_count'=>(int)($m['oos_count'] ?? 0),
            'oos_expectancy_pct'=>$m['oos_expectancy_pct'] ?? null,
            'non_overlap_count'=>(int)($m['non_overlap_count'] ?? 0),
            'non_overlap_expectancy_pct'=>$m['non_overlap_expectancy_pct'] ?? null,
            'max_drawdown_pct'=>$m['max_drawdown_pct'] ?? null,
            'promotion_stage'=>(string)($m['promotion_stage'] ?? '탐색'),
            'updated_at'=>(string)($m['updated_at'] ?? ''),
        );
    }
    usort($metrics, static function(array $a,array $b): int {
        $na=(int)$a['closed_trades'];$nb=(int)$b['closed_trades'];
        if($na===$nb)return ((float)($b['expectancy_pct']??0)) <=> ((float)($a['expectancy_pct']??0));
        return $nb <=> $na;
    });
    $legacy=is_array($state['legacy']??null)?$state['legacy']:array();
    $updated='';
    foreach($metrics as $m){$t=(string)($m['updated_at']??'');if($t>$updated)$updated=$t;}
    return array('available'=>true,'path'=>$path,'schema'=>(string)($state['schema_version']??$state['schema']??''),'metrics'=>$metrics,'legacy'=>$legacy,'updated_at'=>$updated);
}

function td_find_latest_analysis(string $baseDir): ?string {
    $ctx = td_runtime_context($baseDir);
    $patterns = array();

    // Under SINGLE_FILE_PAPER, look at the authoritative compatibility runtime first.
    if (!empty($ctx['single_file_paper'])) {
        $compat = (string)$ctx['broker_runtime'];
        $patterns[] = $compat . '/analysis_snapshots/all_models_analysis_*.json';
        $patterns[] = $baseDir . '/trade_single_compat/*_runtime/analysis_snapshots/all_models_analysis_*.json';
    }

    // Strategy/root snapshots remain useful as additional evidence; newest readable file wins.
    $patterns[] = $baseDir . '/trade_runtime/analysis_snapshots/all_models_analysis_*.json';
    $patterns[] = $baseDir . '/*_runtime/analysis_snapshots/all_models_analysis_*.json';
    $patterns[] = $baseDir . '/all_models_analysis_*.json';

    $files = array();
    foreach ($patterns as $p) {
        $g = glob($p);
        if (!is_array($g)) continue;
        foreach ($g as $f) if (is_file($f) && is_readable($f)) $files[$f] = true;
    }
    $files = array_keys($files);
    if (!$files) return null;
    usort($files, function($a, $b) {
        $ta = filemtime($a) ?: 0;
        $tb = filemtime($b) ?: 0;
        if ($ta === $tb) return strcmp($b, $a);
        return $tb <=> $ta;
    });
    return $files[0];
}

function td_first_existing(array $paths): ?string {
    foreach ($paths as $p) {
        if (is_file($p) && is_readable($p)) return $p;
    }
    return null;
}

function td_normalize_order_list($raw): array {
    if (!is_array($raw)) return array();
    if (isset($raw['orders']) && is_array($raw['orders'])) return array_values($raw['orders']);
    if (isset($raw['items']) && is_array($raw['items'])) return array_values($raw['items']);
    $isList = array_keys($raw) === range(0, count($raw) - 1);
    if ($isList) return array_values($raw);
    $orders = array();
    foreach ($raw as $v) if (is_array($v)) $orders[] = $v;
    return $orders;
}

function td_load_broker_orders(string $baseDir): array {
    $ctx = td_runtime_context($baseDir);
    $runtime = (string)$ctx['broker_runtime'];
    $paths = array($runtime . '/broker_orders.json', $runtime . '/orders.json');
    // Historical root-level fallback is allowed only outside SINGLE_FILE_PAPER.
    if (empty($ctx['single_file_paper'])) $paths[] = $baseDir . '/broker_orders.json';
    $path = td_first_existing($paths);
    $raw = $path ? td_read_json_file($path) : null;
    $orders = td_normalize_order_list($raw);
    usort($orders, function($a, $b) {
        $ta = strtotime((string)($a['created_at'] ?? $a['time'] ?? $a['updated_at'] ?? '')) ?: 0;
        $tb = strtotime((string)($b['created_at'] ?? $b['time'] ?? $b['updated_at'] ?? '')) ?: 0;
        return $tb <=> $ta;
    });
    return array('path'=>$path,'orders'=>$orders,'runtime'=>$runtime,'runtime_context'=>$ctx);
}


function td_is_active_status(string $status): bool {
    $s = strtoupper($status);
    return in_array($s, array('PENDING','SENT','WORKING','PARTIAL','CANCEL_REQUESTED'), true);
}

function td_order_waiting_market_open(array $order): bool {
    $status = strtoupper((string)($order['status'] ?? $order['broker_status'] ?? ''));
    if (!td_is_active_status($status)) return false;
    return strtoupper(trim((string)($order['broker_message'] ?? ''))) === 'WAIT_MARKET_OPEN';
}

function td_order_created_ts(array $order): int {
    $t = strtotime((string)($order['created_at'] ?? $order['time'] ?? $order['updated_at'] ?? ''));
    return $t === false ? 0 : $t;
}

function td_jp_requote_code(array $order): string {
    if (strtoupper((string)($order['market'] ?? '')) !== 'JP') return '';
    $status = strtoupper((string)($order['status'] ?? $order['broker_status'] ?? ''));
    $signals = array();
    foreach (array('broker_message','cancel_reason','terminal_reason','expired_reason','reject_reason','paper_mark_source') as $key) {
        $v = strtoupper(trim((string)($order[$key] ?? '')));
        if ($v !== '') $signals[] = $v;
    }
    $joined = implode(' | ', $signals);
    if (strpos($joined, 'JP_LIVE_REQUOTE_TIMEOUT') !== false) return 'TIMEOUT';
    if (strpos($joined, 'JP_LIVE_REQUOTE_REVALIDATION_FAILED') !== false) return 'REVALIDATION_FAILED';
    if (strpos($joined, 'KIS_PAPER_JP_LIVE_REQUOTE') !== false) return 'SUCCESS';
    if (strpos($joined, 'JP_LIVE_REQUOTE_WAIT') !== false || !empty($order['jp_live_requote_pending'])) return 'WAIT';
    $requires = !empty($order['requires_live_requote']);
    $fresh = strtoupper((string)($order['quote_freshness'] ?? ''));
    if ($requires && $fresh === 'DELAYED' && td_is_active_status($status)) return 'WAIT';
    if ($requires && $fresh === 'DELAYED') return 'REQUIRED';
    return '';
}

function td_order_display_status(array $order): array {
    if (td_order_waiting_market_open($order)) return array('시장 개장 대기', 'info');
    $jp = td_jp_requote_code($order);
    $status = strtoupper((string)($order['status'] ?? $order['broker_status'] ?? ''));
    if ($jp === 'WAIT') return array('JP 실시간 재호가 대기', 'info');
    if ($jp === 'TIMEOUT') return array('JP 재호가 시간초과', 'warn');
    if ($jp === 'REVALIDATION_FAILED') return array('JP 재호가 검증실패', 'bad');
    if ($jp === 'SUCCESS' && in_array($status, array('PAPER_FILLED','FILLED'), true)) return array('체결완료 · JP 재호가', 'ok');
    return td_status_label($status);
}

function td_jp_requote_label(array $order): array {
    $code = td_jp_requote_code($order);
    if ($code === 'WAIT') return array('KIS 재호가 대기', 'info');
    if ($code === 'SUCCESS') return array('KIS 재호가 성공', 'ok');
    if ($code === 'TIMEOUT') return array('재호가 시간초과', 'warn');
    if ($code === 'REVALIDATION_FAILED') return array('재호가 검증실패', 'bad');
    if ($code === 'REQUIRED') return array('KIS 재호가 필요', 'warn');
    return array('-', 'muted');
}

function td_jp_requote_summary(array $orders): array {
    $out = array('waiting'=>0,'success'=>0,'timeout'=>0,'failed'=>0,'waiting_oldest_age_sec'=>0,'recent_timeout_6h'=>0,'recent_failed_6h'=>0);
    $now = time();
    foreach ($orders as $order) {
        if (!is_array($order) || strtoupper((string)($order['market'] ?? '')) !== 'JP') continue;
        $code = td_jp_requote_code($order);
        $status = strtoupper((string)($order['status'] ?? $order['broker_status'] ?? ''));
        $created = td_order_created_ts($order); $age = $created > 0 ? max(0, $now - $created) : 0;
        $terminalTs = strtotime((string)($order['processed_at'] ?? $order['cancelled_at'] ?? $order['filled_at'] ?? $order['updated_at'] ?? $order['created_at'] ?? ''));
        $terminalAge = $terminalTs === false ? PHP_INT_MAX : max(0, $now - $terminalTs);
        if ($code === 'WAIT' && td_is_active_status($status)) { $out['waiting']++; $out['waiting_oldest_age_sec'] = max($out['waiting_oldest_age_sec'], $age); }
        elseif ($code === 'SUCCESS') $out['success']++;
        elseif ($code === 'TIMEOUT') { $out['timeout']++; if ($terminalAge <= 21600) $out['recent_timeout_6h']++; }
        elseif ($code === 'REVALIDATION_FAILED') { $out['failed']++; if ($terminalAge <= 21600) $out['recent_failed_6h']++; }
    }
    return $out;
}

function td_strategy_market_execution_state(array $orders, string $strategy, string $market, array $regime = array()): array {
    $strategy = strtolower(trim($strategy)); $market = strtoupper(trim($market));
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        if (strtolower((string)($order['strategy_key'] ?? $order['strategy'] ?? '')) !== $strategy) continue;
        if (strtoupper((string)($order['market'] ?? '')) !== $market) continue;
        $status = strtoupper((string)($order['status'] ?? $order['broker_status'] ?? ''));
        if (!td_is_active_status($status)) continue;
        if (td_order_waiting_market_open($order)) return array('시장 개장 대기', 'info');
        if ($market === 'JP' && td_jp_requote_code($order) === 'WAIT') return array('실시간 재호가 대기', 'info');
        $label = td_status_label($status); return array($label[0], $label[1]);
    }
    if (!empty($regime['entry_blocked'])) return array('시장 Gate 차단', 'warn');
    $label = trim((string)($regime['label'] ?? ''));
    $code = strtoupper((string)($regime['code'] ?? 'UNKNOWN'));
    if (strpos($label, '대기') !== false || in_array($code, array('WEAK','BEAR'), true)) return array('시장 Gate 대기', 'warn');
    return array('주문 없음', 'muted');
}

function td_active_order_health(array $orders): array {
    $out = array(
        'actionable_oldest_age_sec'=>0,
        'market_wait_oldest_age_sec'=>0,
        'market_wait_count'=>0,
        'market_wait_sell_count'=>0,
        'jp_requote_wait_oldest_age_sec'=>0,
        'jp_requote_wait_count'=>0,
        'oldest_actionable_sell'=>null,
        'first_market_wait_sell'=>null,
    );
    foreach ($orders as $order) {
        if (!is_array($order)) continue;
        $status = strtoupper((string)($order['status'] ?? $order['broker_status'] ?? ''));
        if (!td_is_active_status($status)) continue;
        $created = td_order_created_ts($order);
        $age = $created > 0 ? max(0, time() - $created) : 0;
        $side = strtoupper((string)($order['side'] ?? ''));
        if (td_order_waiting_market_open($order)) {
            $out['market_wait_count']++;
            $out['market_wait_oldest_age_sec'] = max($out['market_wait_oldest_age_sec'], $age);
            if ($side === 'SELL') {
                $out['market_wait_sell_count']++;
                if ($out['first_market_wait_sell'] === null) $out['first_market_wait_sell'] = $order;
            }
            continue;
        }
        if (td_jp_requote_code($order) === 'WAIT') {
            $out['jp_requote_wait_count']++;
            $out['jp_requote_wait_oldest_age_sec'] = max($out['jp_requote_wait_oldest_age_sec'], $age);
            continue;
        }
        $out['actionable_oldest_age_sec'] = max($out['actionable_oldest_age_sec'], $age);
        if ($side === 'SELL' && $age > TD_BROKER_CRON_WARN_SEC) {
            $current = $out['oldest_actionable_sell'];
            if (!is_array($current) || $age > (int)($current['_age_sec'] ?? 0)) {
                $order['_age_sec'] = $age;
                $out['oldest_actionable_sell'] = $order;
            }
        }
    }
    return $out;
}

function td_status_label(string $status): array {
    $s = strtoupper(trim($status));
    $map = array(
        'PENDING' => array('주문 처리 대기', 'warn'),
        'SENT' => array('주문전송', 'info'),
        'WORKING' => array('미체결진행', 'info'),
        'PARTIAL' => array('부분체결', 'info'),
        'PAPER_FILLED' => array('모의체결완료', 'ok'),
        'FILLED' => array('실체결완료', 'ok'),
        'EXPIRED' => array('만료', 'bad'),
        'REJECTED' => array('거절', 'bad'),
        'CANCELLED' => array('취소', 'muted'),
        'CANCELED' => array('취소', 'muted'),
        'NONE' => array('주문 없음', 'muted'),
        '' => array('-', 'muted'),
    );
    return $map[$s] ?? array($s, 'muted');
}

function td_approval_label($order): array {
    $status = strtoupper((string)($order['status'] ?? $order['broker_status'] ?? ''));
    if (in_array($status, array('PAPER_FILLED','FILLED'), true)) return array('완료', 'ok');
    if (in_array($status, array('EXPIRED','REJECTED','CANCELLED','CANCELED'), true)) return array('종료', 'muted');
    $approved = $order['approved'] ?? null;
    if ($approved === true || $approved === 'Y' || $approved === 1 || $approved === '1') return array('승인완료', 'ok');
    return array('미승인', 'warn');
}

function td_mode_label($order): string {
    $mode = strtoupper((string)($order['approval_mode'] ?? $order['approval'] ?? $order['mode'] ?? 'AUTO'));
    $exec = strtoupper((string)($order['execution_mode'] ?? $order['paper_real'] ?? ''));
    if ($exec === '' && strtoupper((string)($order['status'] ?? '')) === 'PAPER_FILLED') $exec = 'PAPER';
    if ($mode === 'AUTO') $mode = '자동';
    elseif ($mode === 'MANUAL') $mode = '수동';
    return $exec !== '' ? ($mode . ' · ' . $exec) : $mode;
}

function td_side_label($side): array {
    $s = strtoupper((string)$side);
    if ($s === 'BUY') return array('매수', 'buy');
    if ($s === 'SELL') return array('매도', 'sell');
    return array($s ?: '-', 'muted');
}

function td_age_label($dt): string {
    $t = is_numeric($dt) ? (int)$dt : (strtotime((string)$dt) ?: 0);
    if ($t <= 0) return '-';
    $sec = max(0, time() - $t);
    if ($sec < 60) return $sec . '초 전';
    if ($sec < 3600) return floor($sec / 60) . '분 전';
    if ($sec < 86400) return floor($sec / 3600) . '시간 전';
    return floor($sec / 86400) . '일 전';
}

function td_num($v, int $dec = 2): string {
    if (!is_numeric($v)) return '-';
    $n = (float)$v;
    if ($dec <= 0) return number_format($n, 0);
    return number_format($n, $dec);
}

function td_pct($v): string {
    if (!is_numeric($v)) return '-';
    $n = (float)$v;
    $prefix = $n > 0 ? '+' : '';
    return $prefix . number_format($n, 2) . '%';
}

function td_ret_class($v): string {
    if (!is_numeric($v)) return 'muted';
    $n = (float)$v;
    if ($n > 0.05) return 'pos';
    if ($n < -0.05) return 'neg';
    return 'flat';
}

function td_get($arr, array $path, $default = null) {
    $cur = $arr;
    foreach ($path as $p) {
        if (!is_array($cur) || !array_key_exists($p, $cur)) return $default;
        $cur = $cur[$p];
    }
    return $cur;
}

function td_normalize_rows($raw, string $preferredKey = ''): array {
    if (!is_array($raw)) return array();
    if ($preferredKey !== '' && isset($raw[$preferredKey]) && is_array($raw[$preferredKey])) return array_values($raw[$preferredKey]);
    foreach (array('positions','trades','rows','items','data') as $k) {
        if (isset($raw[$k]) && is_array($raw[$k])) return array_values($raw[$k]);
    }
    if (array_keys($raw) === range(0, count($raw) - 1)) return array_values($raw);
    $out = array();
    foreach ($raw as $v) if (is_array($v)) $out[] = $v;
    return $out;
}

function td_trade_return_pct(array $trade): ?float {
    foreach (array('return_pct','profit_pct','pnl_pct','rate_pct') as $key) {
        if (isset($trade[$key]) && is_numeric($trade[$key])) return (float)$trade[$key];
    }
    $profit = $trade['profit'] ?? $trade['pnl'] ?? null;
    $cost = $trade['buy_total'] ?? $trade['cost'] ?? $trade['invested'] ?? null;
    if (is_numeric($profit) && is_numeric($cost) && abs((float)$cost) > 0.0000001) return (float)$profit / (float)$cost * 100.0;
    $buy = $trade['buy_price'] ?? $trade['entry_price'] ?? null;
    $sell = $trade['sell_price'] ?? $trade['exit_price'] ?? null;
    if (is_numeric($buy) && is_numeric($sell) && (float)$buy > 0) return ((float)$sell / (float)$buy - 1.0) * 100.0;
    return null;
}

function td_reason_label(string $reason): string {
    $reason = trim($reason);
    if ($reason === '') return '사유 미기록';
    $upper = strtoupper($reason);
    $map = array(
        'ENGINE_TARGET'=>'목표가 도달',
        'ENGINE_INITIAL_STOP'=>'초기 손절',
        'ENGINE_EARLY_LOSS'=>'초기 손실 조기정리',
        'ENGINE_STALLED_LOSS'=>'정체 손실 조기정리',
        'ENGINE_NO_PROGRESS'=>'무진전 손실 정리',
        'ENGINE_MAX_HOLDING'=>'최대 보유기간 종료',
        'ENGINE_DAILY_LOSS'=>'일일 손실 제한',
        'ENGINE_MAX_DRAWDOWN'=>'최대낙폭 방어',
        'ENGINE_TRAILING'=>'추적손절',
        'TRAILING_STOP'=>'추적손절',
        'DAS_EXIT_STC_PROFIT'=>'DAS STC 수익보호',
        'DTS_PROFIT_LOCK'=>'DTS 이익보호',
        'DTS_EXIT_PROFIT_LOCK'=>'DTS 이익보호',
        'ABC_TRAILING_STOP'=>'ABC 추적손절',
        'CLOSE_BELOW_MA20_2D'=>'MA20 이탈 2일',
        'MA60_BREAK'=>'MA60 이탈',
        'MA20_BREAK'=>'MA20 이탈',
    );
    foreach ($map as $code=>$label) if (strpos($upper, $code) !== false) return $label;
    return $reason;
}

function td_reason_top(array $counts, int $limit = 2): string {
    if (!$counts) return '없음';
    arsort($counts, SORT_NUMERIC);
    $out = array();
    foreach (array_slice($counts, 0, max(1, $limit), true) as $reason=>$count) {
        $out[] = td_reason_label((string)$reason) . ' ' . (int)$count . '건';
    }
    return implode(' · ', $out);
}

function td_market_return_from_capital(array $capital, string $market): ?float {
    $value = td_get($capital, array($market,'profit_pct'), null);
    if (is_numeric($value)) return (float)$value;
    $equity = td_get($capital, array($market,'equity'), null);
    $seed = td_get($capital, array($market,'seed'), null);
    if (is_numeric($equity) && is_numeric($seed) && (float)$seed > 0) return ((float)$equity / (float)$seed - 1.0) * 100.0;
    return null;
}

function td_strategy_performance(string $baseDir, string $key, array $meta): array {
    $runtime = td_runtime_dir($baseDir, $meta, $key);
    $capitalPath = $runtime . '/capital.json';
    $capital = td_read_json_file($capitalPath) ?: array();
    $capitalUpdatedAt = td_source_time($capital, $capitalPath);
    $positions = td_normalize_rows(td_read_json_file($runtime . '/positions.json') ?: array(), 'positions');
    $trades = td_normalize_rows(td_read_json_file($runtime . '/trades.json') ?: array(), 'trades');
    $returns = array(); $winReasons = array(); $lossReasons = array();
    $wins = 0; $losses = 0; $flats = 0; $sumWin = 0.0; $sumLoss = 0.0;
    foreach ($trades as $trade) {
        if (!is_array($trade)) continue;
        $ret = td_trade_return_pct($trade);
        if ($ret === null) continue;
        $returns[] = $ret;
        $reason = trim((string)($trade['sell_reason'] ?? $trade['exit_reason'] ?? $trade['reason'] ?? '사유 미기록'));
        if ($ret > 0.000001) { $wins++; $sumWin += $ret; $winReasons[$reason] = (int)($winReasons[$reason] ?? 0) + 1; }
        elseif ($ret < -0.000001) { $losses++; $sumLoss += $ret; $lossReasons[$reason] = (int)($lossReasons[$reason] ?? 0) + 1; }
        else $flats++;
    }
    $closed = count($returns);
    $avgWin = $wins > 0 ? $sumWin / $wins : 0.0;
    $avgLoss = $losses > 0 ? $sumLoss / $losses : 0.0;
    $validClosed = $wins + $losses + $flats;
    $closed = $validClosed; // ERRC: only trades with a valid return belong to the performance population.
    $winRate = $closed > 0 ? td_clamp_rate($wins / $closed * 100.0) : null;
    list($perfSampleStatus,$perfSampleLabel,$perfSampleClass,$perfRemaining)=td_stat_sample_state($closed);
    $payoff = ($wins > 0 && $losses > 0 && abs($avgLoss) > 0.000001) ? $avgWin / abs($avgLoss) : null;
    $returnFactor = ($sumWin > 0 && $sumLoss < 0) ? $sumWin / abs($sumLoss) : ($sumWin > 0 ? INF : 0.0);

    $marketReturns = array();
    foreach (array('KR','US','JP') as $market) $marketReturns[$market] = td_market_return_from_capital($capital, $market);
    $validMarkets = array_values(array_filter($marketReturns, function($v){ return $v !== null; }));
    $averageMarketReturn = $validMarkets ? array_sum($validMarkets) / count($validMarkets) : null;
    $bestMarket = ''; $worstMarket = ''; $bestReturn = null; $worstReturn = null;
    foreach ($marketReturns as $market=>$ret) {
        if ($ret === null) continue;
        if ($bestReturn === null || $ret > $bestReturn) { $bestReturn = $ret; $bestMarket = $market; }
        if ($worstReturn === null || $ret < $worstReturn) { $worstReturn = $ret; $worstMarket = $market; }
    }

    $openCount = 0; $openWin = 0; $openLoss = 0; $openReturnSum = 0.0;
    foreach ($positions as $position) {
        if (!is_array($position) || !td_position_active($position)) continue;
        $entry = $position['entry_price'] ?? $position['avg_price'] ?? $position['buy_price'] ?? null;
        $mark = $position['current_price'] ?? $position['mark_price'] ?? $position['price'] ?? null;
        if (!is_numeric($entry) || !is_numeric($mark) || (float)$entry <= 0) continue;
        $ret = ((float)$mark / (float)$entry - 1.0) * 100.0;
        $openCount++; $openReturnSum += $ret;
        if ($ret > 0.000001) $openWin++; elseif ($ret < -0.000001) $openLoss++;
    }
    $openAvg = $openCount > 0 ? $openReturnSum / $openCount : null;

    $success = array();
    if ($bestReturn !== null && $bestReturn > 0.05) $success[] = $bestMarket . ' ' . td_pct($bestReturn);
    if ($wins > 0) $success[] = '수익청산 ' . td_reason_top($winReasons, 2);
    if ($payoff !== null && $payoff >= 1.2) $success[] = '손익비 ' . number_format($payoff, 2);
    if ($openWin > 0) $success[] = '보유 수익 ' . $openWin . '건';
    if (!$success) $success[] = $closed < 3 ? '완료 거래 부족' : '뚜렷한 성공요인 미확인';

    $failure = array();
    if ($worstReturn !== null && $worstReturn < -0.05) $failure[] = $worstMarket . ' ' . td_pct($worstReturn);
    if ($losses > 0) $failure[] = '손실청산 ' . td_reason_top($lossReasons, 2);
    if ($closed >= 3 && $winRate < 40.0) $failure[] = '승률 낮음 ' . number_format($winRate, 1) . '%';
    if ($wins > 0 && $losses > 0 && $avgWin <= abs($avgLoss)) $failure[] = '평균손실이 평균이익보다 큼';
    if (is_finite($returnFactor) && $closed >= 3 && $returnFactor < 1.0) $failure[] = '손실합계 우세';
    if ($openLoss > 0) $failure[] = '보유 손실 ' . $openLoss . '건';
    if (!$failure) $failure[] = $closed < 3 ? '판단 자료 부족' : '뚜렷한 실패요인 없음';

    return array(
        'key'=>$key,'label'=>(string)($meta['label'] ?? strtoupper($key)),'runtime'=>$runtime,
        'market_returns'=>$marketReturns,'average_market_return_pct'=>$averageMarketReturn,
        'closed_trades'=>$closed,'wins'=>$wins,'losses'=>$losses,'flats'=>$flats,'win_rate'=>$winRate,
        'stat_sample_status'=>$perfSampleStatus,'stat_sample_label'=>$perfSampleLabel,'stat_sample_class'=>$perfSampleClass,'remaining_to_usable'=>$perfRemaining,'metrics_provisional'=>$closed>0&&$closed<TD_MIN_STAT_SAMPLE,
        'avg_win_pct'=>$avgWin,'avg_loss_pct'=>$avgLoss,'payoff_ratio'=>$payoff,'return_factor'=>$returnFactor,
        'open_count'=>$openCount,'open_wins'=>$openWin,'open_losses'=>$openLoss,'open_avg_return_pct'=>$openAvg,
        'top_win_reasons'=>td_reason_top($winReasons,2),'top_loss_reasons'=>td_reason_top($lossReasons,2),
        'success_summary'=>implode(' · ', $success),'failure_summary'=>implode(' · ', $failure),
        'capital_source'=>'capital.json','capital_updated_at'=>$capitalUpdatedAt,
        'source_available'=>is_file($runtime . '/trades.json') || is_file($runtime . '/capital.json') || is_file($runtime . '/positions.json'),
    );
}

function td_strategy_performance_all(string $baseDir, array $strategies): array {
    $out = array();
    foreach ($strategies as $key=>$meta) $out[$key] = td_strategy_performance($baseDir, (string)$key, $meta);
    return $out;
}

/**
 * 각 모델의 독립 runtime에서 현재 시장 레짐과 버전/자료 상태를 읽는다.
 * 계산은 각 모델 엔진이 담당하며 대시보드는 읽기와 비교만 수행한다.
 */
function td_strategy_runtime_overview(string $baseDir, string $key, array $meta): array {
    $runtime = td_runtime_dir($baseDir, $meta, $key);
    $regimeRoot = td_read_json_file($runtime . '/market_regime.json') ?: array();
    $state = td_read_json_file($runtime . '/engine_state.json');
    if (!is_array($state)) $state = td_read_json_file($runtime . '/state.json');
    if (!is_array($state)) $state = array();

    $markets = array();
    $regimeMarkets = is_array($regimeRoot['markets'] ?? null) ? $regimeRoot['markets'] : array();
    foreach (array('KR','US','JP') as $market) {
        $row = is_array($regimeMarkets[$market] ?? null) ? $regimeMarkets[$market] : array();
        $markets[$market] = array(
            'code'=>strtoupper((string)($row['code'] ?? 'UNKNOWN')),
            'label'=>(string)($row['label'] ?? '자료 없음'),
            'score'=>is_numeric($row['score'] ?? null) ? (int)$row['score'] : null,
            'date'=>(string)($row['date'] ?? ''),
            'held'=>!empty($row['held']),
            'entry_blocked'=>!empty($row['entry_blocked']),
        );
    }

    $required = array('capital.json','positions.json','trades.json');
    $present = 0;
    foreach ($required as $file) if (is_file($runtime . '/' . $file)) $present++;
    $runtimeExists = is_dir($runtime);
    if (!$runtimeExists) {
        $independentCode = 'MISSING'; $independentLabel = 'runtime 없음'; $independentClass = 'bad';
    } elseif ($present === 0) {
        $independentCode = 'INITIAL'; $independentLabel = '초기 자료 대기'; $independentClass = 'warn';
    } elseif ($present < count($required)) {
        $independentCode = 'PARTIAL'; $independentLabel = '일부 자료'; $independentClass = 'warn';
    } else {
        $independentCode = 'READY'; $independentLabel = '독립 정상'; $independentClass = 'ok';
    }

    $version = trim((string)($state['strategy_rev'] ?? $state['strategy_version'] ?? ''));
    if ($version === '') $version = '-';
    // Engine tick writes its actual runtime TE_VERSION / TE_REV to engine_state.json.
    // This is stronger runtime evidence than an analysis export generated later by another request.
    $engineVersion = trim((string)($state['version'] ?? $state['engine_version'] ?? ''));
    $engineRev = trim((string)($state['rev'] ?? $state['engine_rev'] ?? ''));
    $engineStateAt = (string)($state['last_tick'] ?? $state['updated_at'] ?? $state['last_run_at'] ?? '');
    $updatedAt = (string)($regimeRoot['updated_at'] ?? $state['last_tick'] ?? $state['updated_at'] ?? '');

    return array(
        'key'=>$key,'label'=>(string)($meta['label'] ?? strtoupper($key)),'runtime'=>$runtime,
        'runtime_exists'=>$runtimeExists,'strategy_version'=>$version,'updated_at'=>$updatedAt,
        'runtime_engine_version'=>$engineVersion,'runtime_engine_rev'=>$engineRev,'runtime_engine_state_at'=>$engineStateAt,
        'markets'=>$markets,'independent_code'=>$independentCode,'independent_label'=>$independentLabel,
        'independent_class'=>$independentClass,'required_files_present'=>$present,'required_files_total'=>count($required),
    );
}

function td_strategy_runtime_overview_all(string $baseDir, array $strategies): array {
    $out = array();
    foreach ($strategies as $key=>$meta) $out[$key] = td_strategy_runtime_overview($baseDir, (string)$key, $meta);
    return $out;
}

function td_regime_badge_class(string $code): string {
    $code = strtoupper($code);
    if (in_array($code, array('BULL','UP'), true)) return 'ok';
    if ($code === 'RECOVERY') return 'info';
    if ($code === 'WEAK') return 'warn';
    if ($code === 'BEAR') return 'bad';
    return 'muted';
}

function td_regime_text(array $row): string {
    $label = trim((string)($row['label'] ?? '자료 없음'));
    $score = $row['score'] ?? null;
    if (is_numeric($score)) $label .= ' · 점수 ' . (int)$score;
    if (!empty($row['entry_blocked'])) $label .= ' · 진입차단';
    elseif (!empty($row['held'])) $label .= ' · 이전값';
    return $label;
}

function td_position_active(array $p): bool {
    $status = strtoupper((string)($p['status'] ?? $p['state'] ?? 'OPEN'));
    if (in_array($status, array('CLOSED','SOLD','EXITED','FILLED_SELL','PAPER_SOLD','STOPPED'), true)) return false;
    if (isset($p['closed_at']) || isset($p['sell_time']) || isset($p['sell_price'])) return false;
    $qty = $p['qty'] ?? $p['quantity'] ?? 1;
    return !is_numeric($qty) || (float)$qty > 0;
}

function td_runtime_dir(string $baseDir, array $meta, string $key): string {
    $runtime = (string)($meta['runtime'] ?? ($key . '_runtime'));
    if ($runtime !== '' && ($runtime[0] === '/' || $runtime[0] === '\\' || preg_match('~^[A-Za-z]:[\\/]~', $runtime))) return $runtime;
    return $baseDir . '/' . $runtime;
}

function td_tail_text(string $path, int $maxBytes = TD_ENGINE_LOG_TAIL_BYTES): string {
    if (!is_file($path) || !is_readable($path)) return '';
    $size = @filesize($path);
    if (!is_int($size) || $size < 0) $size = 0;
    $fp = @fopen($path, 'rb');
    if (!$fp) return '';
    try {
        $start = max(0, $size - max(4096, $maxBytes));
        if ($start > 0) {
            if (@fseek($fp, $start) !== 0) return '';
            @fgets($fp); // 중간에서 잘린 첫 줄은 버린다.
        }
        $raw = stream_get_contents($fp);
        return is_string($raw) ? $raw : '';
    } finally {
        @fclose($fp);
    }
}

function td_engine_log_tick(string $runtime): array {
    $path = rtrim($runtime, '/\\') . '/engine.log';
    $result = array(
        'path'=>$path,
        'exists'=>is_file($path),
        'readable'=>is_readable($path),
        'last_tick'=>'',
        'last_tick_ts'=>0,
        'age_sec'=>null,
        'tick_id'=>'',
        'metrics'=>array(),        'line'=>'',
        'mtime'=>is_file($path) ? ((int)(@filemtime($path) ?: 0)) : 0,
    );
    $tail = td_tail_text($path);
    if ($tail === '') return $result;
    $lines = preg_split('/\R/', $tail) ?: array();
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        $line = trim((string)$lines[$i]);
        if ($line === '' || stripos($line, 'tick ') === false) continue;
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+tick\s+([^\s]+)(?:\s+(.*))?$/', $line, $m)) continue;
        $ts = strtotime($m[1]) ?: 0;
        if ($ts <= 0) continue;
        $metrics = array();
        $rest = (string)($m[3] ?? '');
        if ($rest !== '' && preg_match_all('/\b([a-z_]+)=(-?\d+(?:\.\d+)?)\b/i', $rest, $pairs, PREG_SET_ORDER)) {
            foreach ($pairs as $pair) $metrics[strtolower($pair[1])] = strpos($pair[2], '.') !== false ? (float)$pair[2] : (int)$pair[2];
        }
        $result['last_tick'] = $m[1];
        $result['last_tick_ts'] = $ts;
        $result['age_sec'] = max(0, time() - $ts);
        $result['tick_id'] = (string)$m[2];
        $result['metrics'] = $metrics;
        $result['line'] = $line;
        break;
    }
    return $result;
}

function td_strategy_log_statuses(string $baseDir, array $strategies): array {
    $out = array();
    foreach ($strategies as $key => $meta) {
        $runtime = td_runtime_dir($baseDir, $meta, (string)$key);
        $row = td_engine_log_tick($runtime);
        $row['runtime'] = $runtime;
        $row['raw_engine_log_tick_ts'] = (int)($row['last_tick_ts'] ?? 0);
        $row['raw_engine_log_tick'] = (string)($row['last_tick'] ?? '');

        $runner = td_runner_strategy_evidence($baseDir, (string)$key);
        $row['runner_evidence'] = $runner;
        $runnerTs = !empty($runner['ok']) ? (int)($runner['last_tick_ts'] ?? 0) : 0;
        if ($runnerTs > (int)($row['last_tick_ts'] ?? 0)) {
            $row['last_tick_ts'] = $runnerTs;
            $row['last_tick'] = (string)($runner['last_tick'] ?? '');
            $row['age_sec'] = max(0, time() - $runnerTs);
            $row['effective_last_tick_ts'] = $runnerTs;
            $row['effective_last_tick'] = (string)($runner['last_tick'] ?? '');
            $row['effective_age_sec'] = max(0, time() - $runnerTs);
            $row['effective_source'] = 'runner_state';
        }
        $out[$key] = $row;
    }
    return $out;
}

function td_apply_strategy_log_statuses(array $comparison, array $strategyAnalysis, array $logs): array {
    $byKey = array();
    foreach ($comparison as $row) if (is_array($row) && isset($row['key'])) $byKey[(string)$row['key']] = $row;

    foreach ($logs as $key => $log) {
        if (!isset($byKey[$key])) $byKey[$key] = array('key'=>$key,'label'=>strtoupper((string)$key));

        $bestTs = (int)($log['last_tick_ts'] ?? 0);
        $bestTick = $bestTs > 0 ? (string)($log['last_tick'] ?? '') : '';
        $bestSource = (string)($log['effective_source'] ?? ($bestTs > 0 ? 'engine.log' : ''));

        $candidates = array();
        $cmpTick = (string)($byKey[$key]['last_tick'] ?? '');
        if ($cmpTick !== '') $candidates[] = array('comparison', $cmpTick);
        $analysisTick = (string)td_get($strategyAnalysis, array($key,'last_completed_tick','last_tick'), '');
        if ($analysisTick !== '') $candidates[] = array('analysis.last_completed_tick', $analysisTick);
        foreach ($candidates as $candidate) {
            $ts = strtotime((string)$candidate[1]);
            if ($ts !== false && $ts > $bestTs) {
                $bestTs = (int)$ts;
                $bestTick = (string)$candidate[1];
                $bestSource = (string)$candidate[0];
            }
        }

        if ($bestTs > 0) {
            $age = max(0, time() - $bestTs);
            $byKey[$key]['last_tick'] = $bestTick;
            $byKey[$key]['status'] = $age <= TD_STRATEGY_TICK_WARN_SEC ? 'OK' : 'DELAY';
            $log['effective_last_tick_ts'] = $bestTs;
            $log['effective_last_tick'] = $bestTick;
            $log['effective_age_sec'] = $age;
            $log['effective_source'] = $bestSource;
            // UI/alerts consume these fields. Freshest evidence wins; stale engine.log cannot downgrade it.
            $log['last_tick_ts'] = $bestTs;
            $log['last_tick'] = $bestTick;
            $log['age_sec'] = $age;
        } elseif (!empty($log['exists'])) {
            $byKey[$key]['status'] = 'NO_TICK';
        } else {
            $byKey[$key]['status'] = 'NO_LOG';
        }

        if (!isset($strategyAnalysis[$key]) || !is_array($strategyAnalysis[$key])) $strategyAnalysis[$key] = array();
        if (!isset($strategyAnalysis[$key]['last_completed_tick']) || !is_array($strategyAnalysis[$key]['last_completed_tick'])) $strategyAnalysis[$key]['last_completed_tick'] = array();
        if ($bestTs > 0) $strategyAnalysis[$key]['last_completed_tick']['last_tick'] = $bestTick;
        $strategyAnalysis[$key]['runtime_log'] = $log;
        $strategyAnalysis[$key]['tick_source'] = $bestSource !== '' ? $bestSource : 'unavailable';
        $logs[$key] = $log;
    }
    return array(array_values($byKey), $strategyAnalysis, $logs);
}


function td_read_latest_strategy_analysis(string $runtime, string $key): array {
    $patterns = array(
        $runtime . '/analysis_snapshots/' . $key . '_analysis_*.json',
        $runtime . '/analysis_snapshots/*_analysis_*.json',
        $runtime . '/' . $key . '_analysis_*.json',
    );
    $files = array();
    foreach ($patterns as $p) {
        $g = glob($p);
        if (is_array($g)) foreach ($g as $f) if (is_file($f) && is_readable($f)) $files[$f] = true;
    }
    $files = array_keys($files);
    if (!$files) return array();
    usort($files, function($a, $b) { return ((@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0)); });
    $j = td_read_json_file($files[0]);
    return is_array($j) ? $j : array();
}

function td_count_active_buy_orders_for_strategy(array $orders, string $strategy, string $market): int {
    $n = 0;
    foreach ($orders as $o) {
        if (!is_array($o)) continue;
        $st = strtoupper((string)($o['status'] ?? $o['broker_status'] ?? ''));
        $side = strtoupper((string)($o['side'] ?? ''));
        $sk = strtolower((string)($o['strategy'] ?? $o['strategy_key'] ?? $o['strategy_id'] ?? ''));
        $mk = strtoupper((string)($o['market'] ?? ''));
        if ($sk === strtolower($strategy) && $mk === strtoupper($market) && $side === 'BUY' && td_is_active_status($st)) $n++;
    }
    return $n;
}

function td_strategy_fallback(string $baseDir, string $key, array $meta, array $orders): array {
    $runtime = td_runtime_dir($baseDir, $meta, $key);
    $state = td_read_json_file($runtime . '/engine_state.json') ?: (td_read_json_file($runtime . '/state.json') ?: array());
    $cap = td_read_json_file($runtime . '/capital.json') ?: array();
    $stats = td_read_json_file($runtime . '/stats.json') ?: array();
    $positions = td_normalize_rows(td_read_json_file($runtime . '/positions.json') ?: array(), 'positions');
    $trades = td_normalize_rows(td_read_json_file($runtime . '/trades.json') ?: array(), 'trades');

    $active = array(); $marketUsed = array('KR'=>0,'US'=>0,'JP'=>0);
    foreach ($positions as $p) {
        if (!is_array($p) || !td_position_active($p)) continue;
        $active[] = $p;
        $m = strtoupper((string)($p['market'] ?? ''));
        if (isset($marketUsed[$m])) $marketUsed[$m]++;
    }
    foreach (array('KR','US','JP') as $m) $marketUsed[$m] += td_count_active_buy_orders_for_strategy($orders, $key, $m);

    $wins = 0; $losses = 0;
    foreach ($trades as $t) {
        if (!is_array($t)) continue;
        $p = $t['profit'] ?? $t['pnl'] ?? $t['return_pct'] ?? null;
        if (is_numeric($p)) { if ((float)$p > 0) $wins++; elseif ((float)$p < 0) $losses++; }
    }
    $closed = $wins + $losses;
    $winRate = $closed > 0 ? td_clamp_rate(round(($wins / max(1, $closed)) * 100, 2)) : null;

    $logTick = td_engine_log_tick($runtime);
    $stateLastTick = (string)($state['last_tick'] ?? $state['last_run_at'] ?? $state['updated_at'] ?? '-');
    $lastTick = (int)($logTick['last_tick_ts'] ?? 0) > 0 ? (string)$logTick['last_tick'] : $stateLastTick;
    $status = (string)($state['engine'] ?? $state['status'] ?? '');
    if ((int)($logTick['last_tick_ts'] ?? 0) > 0) {
        $status = (is_numeric($logTick['age_sec'] ?? null) && (int)$logTick['age_sec'] <= TD_STRATEGY_TICK_WARN_SEC) ? 'OK' : 'DELAY';
    } elseif ($status === '' || $status === '-') {
        $status = ($lastTick !== '-' && $lastTick !== '' ? 'OK' : 'UNKNOWN');
    }

    $slotStatus = array();
    foreach (array('KR','US','JP') as $m) $slotStatus[$m] = array('used'=>$marketUsed[$m], 'max'=>5);

    return array(
        'comparison' => array(
            'key'=>$key,
            'label'=>(string)($meta['label'] ?? strtoupper($key)),
            'file'=>(string)($meta['file'] ?? ''),
            'status'=>$status,
            'last_tick'=>$lastTick,
            'kr_equity'=>(float)td_get($cap, array('KR','equity'), 0),
            'kr_return_pct'=>(float)td_get($cap, array('KR','profit_pct'), 0),
            'us_equity'=>(float)td_get($cap, array('US','equity'), 0),
            'us_return_pct'=>(float)td_get($cap, array('US','profit_pct'), 0),
            'jp_equity'=>(float)td_get($cap, array('JP','equity'), 0),
            'jp_return_pct'=>(float)td_get($cap, array('JP','profit_pct'), 0),
            'open_positions'=>count($active),
            'closed_trades'=>$closed,
            'wins'=>$wins,
            'losses'=>$losses,
            'win_rate'=>$winRate,
        ),
        'analysis' => array(
            'strategy'=>array('key'=>$key,'label'=>(string)($meta['label'] ?? strtoupper($key)),'file'=>(string)($meta['file'] ?? ''),'runtime_exists'=>is_dir($runtime)),
            'last_completed_tick'=>array('last_tick'=>$lastTick,'engine'=>$status),
            'slot_status'=>$slotStatus,
            'runtime_fallback'=>true,
            'runtime_path'=>$runtime,
            'runtime_log'=>$logTick,
            'tick_source'=>(int)($logTick['last_tick_ts'] ?? 0) > 0 ? 'engine.log' : 'engine_state.json',
        ),
    );
}

function td_merge_runtime_fallbacks(string $baseDir, array $strategyMeta, array $orders, array $comparison, array $strategyAnalysis): array {
    $byKey = array();
    foreach ($comparison as $row) if (is_array($row) && isset($row['key'])) $byKey[(string)$row['key']] = $row;
    foreach ($strategyMeta as $key => $meta) {
        $need = !isset($byKey[$key]) || strtoupper((string)($byKey[$key]['status'] ?? 'UNKNOWN')) === 'UNKNOWN';
        if (!$need && isset($strategyAnalysis[$key]) && is_array($strategyAnalysis[$key])) continue;
        $latest = td_read_latest_strategy_analysis(td_runtime_dir($baseDir, $meta, $key), $key);
        if ($latest) {
            if (!isset($strategyAnalysis[$key]) || !is_array($strategyAnalysis[$key]) || !$strategyAnalysis[$key]) $strategyAnalysis[$key] = $latest;
            if (!isset($byKey[$key])) {
                $state = td_get($latest, array('last_completed_tick'), array());
                $cap = array();
                // individual analysis does not always include compare row, so fall through if missing.
            }
        }
        $fb = td_strategy_fallback($baseDir, $key, $meta, $orders);
        if ($need || !isset($byKey[$key])) $byKey[$key] = $fb['comparison'];
        if (!isset($strategyAnalysis[$key]) || !is_array($strategyAnalysis[$key]) || !$strategyAnalysis[$key]) $strategyAnalysis[$key] = $fb['analysis'];
    }
    return array(array_values($byKey), $strategyAnalysis);
}


function td_bool_value($value): bool {
    if (is_bool($value)) return $value;
    if (is_int($value) || is_float($value)) return (float)$value != 0.0;
    $s = strtolower(trim((string)$value));
    return in_array($s, array('1','true','yes','y','on'), true);
}

function td_name_is_inverse(string $name): bool {
    $u = strtoupper($name);
    return strpos($u, '인버스') !== false || strpos($u, 'INVERSE') !== false || strpos($u, 'BEAR') !== false;
}

function td_name_is_leveraged(string $name): bool {
    $u = strtoupper($name);
    return strpos($u, '레버리지') !== false || strpos($u, '2X') !== false || strpos($u, '3X') !== false || strpos($u, 'ULTRA') !== false;
}

function td_guess_asset_type(string $market, string $symbol, string $name, string $assetType): string {
    $assetType = strtoupper(trim($assetType));
    if ($assetType !== '') return $assetType;
    $u = strtoupper(preg_replace('/\s+/u', '', $name));
    if (preg_match('/(TIGER|KODEX|ACE|RISE|SOL|HANARO|KOSEF|PLUS|TIMEFOLIO|ARIRANG|ETF)/u', $u)) return 'ETF';
    if ($market === 'US' && in_array(strtoupper($symbol), array('SPY','VOO','IVV','SPLG','QQQ','QQQM','DIA','IWM','VTI','SCHD','VIG','DVY'), true)) return 'ETF';
    return '';
}

function td_infer_risk_group(string $market, string $symbol, string $name, string $assetType, bool $inverse = false, bool $leveraged = false): string {
    $market = strtoupper($market);
    $symbol = strtoupper($symbol);
    $assetType = td_guess_asset_type($market, $symbol, $name, $assetType);
    $u = strtoupper(preg_replace('/\s+/u', '', $name));
    if ($inverse) return 'INVERSE_' . $market;
    if ($leveraged) return 'LEVERAGED_' . $market;
    if ($assetType === 'ETF') {
        if (strpos($u, 'S&P500') !== false || strpos($u, 'SP500') !== false || in_array($symbol, array('SPY','VOO','IVV','SPLG'), true)) return 'INDEX_US_SP500';
        if (strpos($u, '나스닥100') !== false || strpos($u, 'NASDAQ100') !== false || in_array($symbol, array('QQQ','QQQM'), true)) return 'INDEX_US_NASDAQ100';
        if (strpos($u, '미국배당') !== false || strpos($u, '다우존스') !== false || strpos($u, 'DOWJONES') !== false || in_array($symbol, array('SCHD','VIG','DVY'), true)) return 'INDEX_US_DIVIDEND';
        if (strpos($u, '코스닥150') !== false || strpos($u, 'KOSDAQ150') !== false) return 'INDEX_KR_KOSDAQ150';
        if (strpos($u, '200') !== false && $market === 'KR') return 'INDEX_KR_KOSPI200';
        if (strpos($u, '금') !== false || strpos($u, 'GOLD') !== false) return 'COMMODITY_GOLD';
        return 'ETF_' . $market . '_' . $symbol;
    }
    return 'STOCK_' . $market . '_' . $symbol;
}

function td_correlation_group(string $riskGroup, string $market, string $symbol, string $name, string $assetType, bool $inverse = false, bool $leveraged = false): string {
    $riskGroup = strtoupper(trim($riskGroup));
    if ($riskGroup === '') $riskGroup = td_infer_risk_group($market, $symbol, $name, $assetType, $inverse, $leveraged);
    if (strpos($riskGroup, 'INDEX_US_') === 0) return 'THEME_US_EQUITY_INDEX';
    if (strpos($riskGroup, 'INDEX_KR_') === 0) return 'THEME_KR_EQUITY_INDEX';
    if (strpos($riskGroup, 'INDEX_JP_') === 0) return 'THEME_JP_EQUITY_INDEX';
    if (strpos($riskGroup, 'INVERSE_') === 0) return 'THEME_' . $riskGroup;
    if (strpos($riskGroup, 'LEVERAGED_') === 0) return 'THEME_' . $riskGroup;
    if (strpos($riskGroup, 'COMMODITY_') === 0) return $riskGroup;
    return $riskGroup;
}

function td_correlation_group_limited(string $group): bool {
    $group = strtoupper(trim($group));
    return strpos($group, 'THEME_') === 0 || strpos($group, 'COMMODITY_') === 0;
}

function td_order_event_ts(array $order): int {
    foreach (array('filled_at','last_fill_at','processed_at','created_at','updated_at','time') as $key) {
        $raw = (string)($order[$key] ?? '');
        if ($raw === '') continue;
        $ts = strtotime($raw);
        if ($ts !== false) return $ts;
    }
    return 0;
}

function td_broker_position_book(array $orders): array {
    $rows = array();
    foreach ($orders as $order) {
        if (!is_array($order) || max(0, (int)($order['filled_qty'] ?? 0)) < 1) continue;
        $rows[] = $order;
    }
    usort($rows, function($a, $b) {
        $ta = td_order_event_ts($a); $tb = td_order_event_ts($b);
        if ($ta === $tb) return strcmp((string)($a['order_id'] ?? ''), (string)($b['order_id'] ?? ''));
        return $ta <=> $tb;
    });
    $lots = array(); $seen = array();
    foreach ($rows as $order) {
        $market = strtoupper((string)($order['market'] ?? ''));
        $symbol = strtoupper((string)($order['symbol'] ?? ''));
        $strategy = strtolower((string)($order['strategy_key'] ?? $order['strategy'] ?? $order['strategy_id'] ?? ''));
        $side = strtoupper((string)($order['side'] ?? ''));
        $filled = max(0, (int)($order['filled_qty'] ?? 0));
        $price = max(0.0, (float)($order['avg_fill_price'] ?? $order['price'] ?? 0));
        if (!in_array($market, array('KR','US','JP'), true) || $symbol === '' || $strategy === '' || $filled < 1 || $price <= 0 || !in_array($side, array('BUY','SELL'), true)) continue;
        $key = $strategy . ':' . $market . ':' . $symbol;
        $seen[$key] = true;
        $lot = isset($lots[$key]) && is_array($lots[$key]) ? $lots[$key] : array(
            'key'=>$key,'strategy'=>$strategy,'market'=>$market,'symbol'=>$symbol,'qty'=>0,'avg_cost'=>0.0,'mark_price'=>$price,
            'name'=>(string)($order['name'] ?? $symbol),'risk_group'=>'','correlation_group'=>'','asset_type'=>(string)($order['asset_type'] ?? ''),
            'is_inverse'=>td_bool_value($order['is_inverse'] ?? false),'is_leveraged'=>td_bool_value($order['is_leveraged'] ?? false),'source'=>'broker_orders'
        );
        if ($side === 'BUY') {
            $oldQty = (int)$lot['qty']; $newQty = $oldQty + $filled;
            $lot['avg_cost'] = $newQty > 0 ? (($oldQty * (float)$lot['avg_cost']) + ($filled * $price)) / $newQty : 0.0;
            $lot['qty'] = $newQty;
        } else {
            $lot['qty'] = max(0, (int)$lot['qty'] - $filled);
            if ((int)$lot['qty'] === 0) $lot['avg_cost'] = 0.0;
        }
        $lot['mark_price'] = $price;
        $lot['name'] = (string)($order['name'] ?? $lot['name']);
        $lot['asset_type'] = (string)($order['asset_type'] ?? $lot['asset_type']);
        if (array_key_exists('is_inverse', $order)) $lot['is_inverse'] = td_bool_value($order['is_inverse']);
        if (array_key_exists('is_leveraged', $order)) $lot['is_leveraged'] = td_bool_value($order['is_leveraged']);
        $risk = trim((string)($order['risk_group'] ?? '')); if ($risk !== '') $lot['risk_group'] = $risk;
        $corr = trim((string)($order['correlation_group'] ?? '')); if ($corr !== '') $lot['correlation_group'] = $corr;
        $lots[$key] = $lot;
    }
    return array('lots'=>$lots,'seen'=>$seen);
}

function td_runtime_position_book(string $baseDir, array $strategies): array {
    $book = array(); $readableFiles = 0; $existingFiles = 0;
    foreach ($strategies as $strategy => $meta) {
        $runtime = td_runtime_dir($baseDir, $meta, (string)$strategy);
        $path = $runtime . '/positions.json';
        if (is_file($path)) $existingFiles++;
        $raw = td_read_json_file($path);
        if (!is_array($raw)) continue;
        $readableFiles++;
        foreach (td_normalize_rows($raw, 'positions') as $position) {
            if (!is_array($position) || !td_position_active($position)) continue;
            $market = strtoupper((string)($position['market'] ?? ''));
            $symbol = strtoupper((string)($position['symbol'] ?? ''));
            $qty = max(0, (int)($position['qty'] ?? $position['quantity'] ?? 0));
            if (!in_array($market, array('KR','US','JP'), true) || $symbol === '' || $qty < 1) continue;
            $key = strtolower((string)$strategy) . ':' . $market . ':' . $symbol;
            $entry = max(0.0, (float)($position['entry_price'] ?? $position['avg_cost'] ?? $position['price'] ?? 0));
            $mark = max(0.0, (float)($position['current_price'] ?? $position['mark_price'] ?? $position['price'] ?? $entry));
            $name = (string)($position['name'] ?? $symbol);
            $assetType = (string)($position['asset_type'] ?? '');
            $inverse = array_key_exists('is_inverse', $position) ? td_bool_value($position['is_inverse']) : td_name_is_inverse($name);
            $leveraged = array_key_exists('is_leveraged', $position) ? td_bool_value($position['is_leveraged']) : td_name_is_leveraged($name);
            $risk = trim((string)($position['risk_group'] ?? ''));
            if ($risk === '') $risk = td_infer_risk_group($market, $symbol, $name, $assetType, $inverse, $leveraged);
            $corr = trim((string)($position['correlation_group'] ?? ''));
            if ($corr === '') $corr = td_correlation_group($risk, $market, $symbol, $name, $assetType, $inverse, $leveraged);
            $book[$key] = array(
                'key'=>$key,'strategy'=>strtolower((string)$strategy),'market'=>$market,'symbol'=>$symbol,'qty'=>$qty,'avg_cost'=>$entry,
                'mark_price'=>$mark > 0 ? $mark : $entry,'name'=>$name,'risk_group'=>$risk,'correlation_group'=>$corr,'asset_type'=>$assetType,
                'is_inverse'=>$inverse,'is_leveraged'=>$leveraged,'source'=>'positions.json'
            );
        }
    }
    return array('book'=>$book,'existing_files'=>$existingFiles,'readable_files'=>$readableFiles);
}

function td_runtime_aggregate_seeds(string $baseDir, array $strategies): array {
    // v1.4 FROZEN: DTS/ABC/DAS share one market capital pool. Do not multiply the seed by strategy count.
    $defaults = array('KR'=>10000000.0,'US'=>6000.0,'JP'=>1000000.0);
    $seeds = $defaults; $readable = 0; $sources = array('KR'=>'DEFAULT','US'=>'DEFAULT','JP'=>'DEFAULT');
    foreach (array('dts','abc','das') as $key) {
        if (!isset($strategies[$key])) continue;
        $runtime = td_runtime_dir($baseDir, $strategies[$key], $key);
        $path = $runtime . '/capital.json'; $capital = td_read_json_file($path);
        if (!is_array($capital)) continue; $readable++;
        foreach (array('KR','US','JP') as $market) {
            $seed = td_get($capital, array($market,'seed'), null);
            if (is_numeric($seed) && (float)$seed > 0) { $seeds[$market] = (float)$seed; $sources[$market] = $key; }
        }
    }
    return array('seeds'=>$seeds,'readable_files'=>$readable,'sources'=>$sources,'policy'=>'CORE_SHARED_ONE_SEED_PER_MARKET');
}

function td_live_portfolio_guard(string $baseDir, array $strategies, array $orders, ?string $ordersPath): array {
    $broker = td_broker_position_book($orders);
    $runtime = td_runtime_position_book($baseDir, $strategies);
    $positions = array();
    foreach ($broker['lots'] as $key => $lot) {
        if (!is_array($lot) || (int)($lot['qty'] ?? 0) < 1) continue;
        $runtimeLot = is_array($runtime['book'][$key] ?? null) ? $runtime['book'][$key] : array();
        if ($runtimeLot) {
            foreach (array('mark_price','name','risk_group','correlation_group','asset_type','is_inverse','is_leveraged') as $field) {
                if (array_key_exists($field, $runtimeLot) && $runtimeLot[$field] !== '' && $runtimeLot[$field] !== null) $lot[$field] = $runtimeLot[$field];
            }
            $lot['source'] = 'broker_orders+positions.json';
        }
        $positions[$key] = $lot;
    }
    foreach ($runtime['book'] as $key => $lot) {
        if (isset($positions[$key]) || isset($broker['seen'][$key])) continue;
        $positions[$key] = $lot;
    }
    $available = ($ordersPath !== null && is_file($ordersPath) && is_readable($ordersPath)) || (int)$runtime['readable_files'] > 0;
    $seedInfo = td_runtime_aggregate_seeds($baseDir, $strategies);
    $seeds = $seedInfo['seeds'];
    $marketAmount = array('KR'=>0.0,'US'=>0.0,'JP'=>0.0); $symbols = array(); $groups = array(); $correlations = array(); $expired = array();
    foreach ($positions as $position) {
        if (!is_array($position)) continue;
        $market = strtoupper((string)($position['market'] ?? '')); $symbol = strtoupper((string)($position['symbol'] ?? ''));
        $strategy = strtolower((string)($position['strategy'] ?? '')); $qty = max(0, (int)($position['qty'] ?? 0));
        $mark = max(0.0, (float)($position['mark_price'] ?? $position['avg_cost'] ?? 0));
        if (!isset($marketAmount[$market]) || $symbol === '' || $strategy === '' || $qty < 1 || $mark <= 0) continue;
        $name = (string)($position['name'] ?? $symbol); $assetType = (string)($position['asset_type'] ?? '');
        $inverse = td_bool_value($position['is_inverse'] ?? false); $leveraged = td_bool_value($position['is_leveraged'] ?? false);
        $risk = trim((string)($position['risk_group'] ?? ''));
        if ($risk === '') $risk = td_infer_risk_group($market, $symbol, $name, $assetType, $inverse, $leveraged);
        $corr = trim((string)($position['correlation_group'] ?? ''));
        if ($corr === '') $corr = td_correlation_group($risk, $market, $symbol, $name, $assetType, $inverse, $leveraged);
        $amount = $qty * $mark; $marketAmount[$market] += $amount;
        $symbolKey = $market . ':' . $symbol;
        if (!isset($symbols[$symbolKey])) $symbols[$symbolKey] = array('market'=>$market,'symbol'=>$symbol,'name'=>$name,'amount'=>0.0,'qty'=>0,'strategies'=>array());
        $symbols[$symbolKey]['amount'] += $amount; $symbols[$symbolKey]['qty'] += $qty; $symbols[$symbolKey]['strategies'][$strategy] = true;
        if ($risk !== '') {
            $groupKey = $market . ':' . $risk;
            if (!isset($groups[$groupKey])) $groups[$groupKey] = array('market'=>$market,'risk_group'=>$risk,'amount'=>0.0,'positions'=>array());
            $groups[$groupKey]['amount'] += $amount;
            $groups[$groupKey]['positions'][$strategy . ':' . $market . ':' . $symbol] = true;
        }
        if (td_correlation_group_limited($corr)) {
            if (!isset($correlations[$corr])) $correlations[$corr] = array('correlation_group'=>$corr,'amount'=>0.0,'positions'=>array(),'strategies'=>array());
            $positionKey = $strategy . ':' . $market . ':' . $symbol;
            $correlations[$corr]['amount'] += $amount; $correlations[$corr]['positions'][$positionKey] = true; $correlations[$corr]['strategies'][$strategy] = true;
        }
    }
    foreach ($orders as $order) {
        if (!is_array($order) || strtoupper((string)($order['status'] ?? '')) !== 'EXPIRED') continue;
        $key = strtolower((string)($order['strategy_key'] ?? $order['strategy'] ?? '')) . ':' . strtoupper((string)($order['market'] ?? '')) . ':' . strtoupper((string)($order['symbol'] ?? ''));
        if ($key === '::') continue;
        $expired[$key] = (int)($expired[$key] ?? 0) + 1;
    }
    $marketRows = array();
    foreach (array('KR','US','JP') as $market) {
        $seed = max(0.0001, (float)($seeds[$market] ?? 1)); $amount = max(0.0, (float)$marketAmount[$market]); $pct = $amount / $seed * 100.0;
        $marketRows[$market] = array('amount'=>round($amount,4),'aggregate_seed'=>round($seed,4),'invest_pct'=>round($pct,3),'limit_pct'=>80.0,'over_limit'=>$pct>80.0);
    }
    $symbolRows = array();
    foreach ($symbols as $row) {
        $strategyNames = array_keys($row['strategies']); $seed = max(0.0001, (float)($seeds[$row['market']] ?? 1)); $pct = (float)$row['amount'] / $seed * 100.0;
        $symbolRows[] = array('market'=>$row['market'],'symbol'=>$row['symbol'],'name'=>$row['name'],'qty'=>$row['qty'],'amount'=>round((float)$row['amount'],4),'strategy_count'=>count($strategyNames),'strategies'=>$strategyNames,'invest_pct'=>round($pct,3),'duplicate_strategy'=>count($strategyNames)>1,'over_limit'=>$pct>25.0||count($strategyNames)>1);
    }
    usort($symbolRows, function($a, $b) { $cmp = (float)$b['invest_pct'] <=> (float)$a['invest_pct']; return $cmp !== 0 ? $cmp : strcmp((string)$a['symbol'], (string)$b['symbol']); });
    $groupRows = array();
    foreach ($groups as $row) {
        $seed = max(0.0001, (float)($seeds[$row['market']] ?? 1)); $pct = (float)$row['amount'] / $seed * 100.0;
        $groupRows[] = array('market'=>$row['market'],'risk_group'=>$row['risk_group'],'amount'=>round((float)$row['amount'],4),'invest_pct'=>round($pct,3),'limit_pct'=>35.0,'over_limit'=>$pct>35.0,'symbols'=>array_keys((array)($row['positions'] ?? array())));
    }
    usort($groupRows, function($a, $b) { $cmp = (float)$b['amount'] <=> (float)$a['amount']; return $cmp !== 0 ? $cmp : strcmp((string)$a['risk_group'], (string)$b['risk_group']); });
    $correlationRows = array(); $globalLimit = 2;
    foreach ($correlations as $row) {
        $count = count($row['positions']);
        $correlationRows[] = array('correlation_group'=>$row['correlation_group'],'position_count'=>$count,'strategies'=>array_keys($row['strategies']),'symbols'=>array_keys($row['positions']),'amount'=>round((float)$row['amount'],4),'global_limit'=>$globalLimit,'over_limit'=>$count>$globalLimit);
    }
    usort($correlationRows, function($a, $b) { $cmp = (int)$b['position_count'] <=> (int)$a['position_count']; return $cmp !== 0 ? $cmp : strcmp((string)$a['correlation_group'], (string)$b['correlation_group']); });
    arsort($expired, SORT_NUMERIC); $expiredRows = array();
    foreach (array_slice($expired, 0, 20, true) as $key => $count) {
        $parts = explode(':', $key, 3); $expiredRows[] = array('strategy'=>$parts[0] ?? '','market'=>$parts[1] ?? '','symbol'=>$parts[2] ?? '','expired_count'=>$count,'repeat'=>$count>1);
    }
    return array(
        'available'=>$available,'source'=>'runtime','calculated_at'=>date('Y-m-d H:i:s'),'open_position_count'=>count($positions),
        'runtime_files'=>array('broker_orders'=>$ordersPath,'positions_readable'=>(int)$runtime['readable_files'],'capital_readable'=>(int)$seedInfo['readable_files']),
        'policy'=>array('market_limit_pct'=>80.0,'symbol_limit_pct'=>25.0,'risk_group_limit_pct'=>35.0,'max_strategies_per_symbol'=>1,'correlation_group_global_position_limit'=>$globalLimit,'correlation_new_entries_only'=>true,'exposure_basis'=>'OPEN_QTY_X_RUNTIME_MARK_PRICE'),
        'markets'=>$marketRows,'top_symbols'=>array_slice($symbolRows,0,20),'top_risk_groups'=>array_slice($groupRows,0,20),'top_correlation_groups'=>array_slice($correlationRows,0,20),'repeated_expired'=>$expiredRows,
        'violations'=>array(
            'market'=>count(array_filter($marketRows, function($r){return !empty($r['over_limit']);})),
            'symbol'=>count(array_filter($symbolRows, function($r){return !empty($r['over_limit']);})),
            'risk_group'=>count(array_filter($groupRows, function($r){return !empty($r['over_limit']);})),
            'correlation_group'=>count(array_filter($correlationRows, function($r){return !empty($r['over_limit']);})),
            'repeated_expired'=>count(array_filter($expiredRows, function($r){return !empty($r['repeat']);}))
        )
    );
}


function td_slot_summary($strategyData, string $key): string {
    $slots = td_get($strategyData, array('slot_status'), null);
    if (!is_array($slots)) $slots = td_get($strategyData, array('risk','slots'), null);
    if (!is_array($slots)) return 'KR -/5 · US -/5 · JP -/5';
    $parts = array();
    foreach (array('KR','US','JP') as $m) {
        $used = td_get($slots, array($m, 'used'), null);
        $max = td_get($slots, array($m, 'max'), 5);
        if ($used === null) $used = td_get($slots, array($m, 'count'), '-');
        $parts[] = $m . ' ' . $used . '/' . $max;
    }
    return implode(' · ', $parts);
}

function td_collect_order_counts(array $orders): array {
    $counts = array('total'=>0,'pending'=>0,'sell_pending'=>0,'buy_pending'=>0,'expired'=>0,'rejected'=>0,'filled'=>0,'paper_filled'=>0,'active'=>0);
    foreach ($orders as $o) {
        $counts['total']++;
        $status = strtoupper((string)($o['status'] ?? $o['broker_status'] ?? ''));
        $side = strtoupper((string)($o['side'] ?? ''));
        if ($status === 'PENDING') {
            $counts['pending']++;
            if ($side === 'SELL') $counts['sell_pending']++;
            if ($side === 'BUY') $counts['buy_pending']++;
        }
        if (td_is_active_status($status)) $counts['active']++;
        if ($status === 'EXPIRED') $counts['expired']++;
        if ($status === 'REJECTED') $counts['rejected']++;
        if ($status === 'FILLED') $counts['filled']++;
        if ($status === 'PAPER_FILLED') $counts['paper_filled']++;
    }
    return $counts;
}

function td_action_secret(string $baseDir): string {
    $authority = $baseDir . '/trade_phase3b_lite_v100/authority.json';
    $material = is_file($authority) ? (string)@file_get_contents($authority) : '';
    $material .= '|' . __FILE__ . '|' . (string)(@filemtime(__FILE__) ?: 0);
    return hash('sha256', $material);
}
function td_action_token(string $baseDir, string $action, ?int $bucket=null): string {
    if ($bucket === null) $bucket = (int)floor(time() / 900);
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash_hmac('sha256', $action . '|' . $bucket . '|' . $ua, td_action_secret($baseDir));
}
function td_action_token_valid(string $baseDir, string $action, string $token): bool {
    if ($action === '' || $token === '') return false;
    $bucket = (int)floor(time() / 900);
    foreach (array($bucket, $bucket - 1) as $b) {
        $expected = td_action_token($baseDir, $action, $b);
        if (function_exists('hash_equals') ? hash_equals($expected, $token) : $expected === $token) return true;
    }
    return false;
}


function td_atomic_json_file(string $file,array $row): bool {
    $dir=dirname($file);if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))return false;
    $raw=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if(!is_string($raw))return false;$tmp=$file.'.tmp.'.getmypid();
    if(@file_put_contents($tmp,$raw.PHP_EOL,LOCK_EX)===false)return false;
    if(!@rename($tmp,$file)){@unlink($tmp);return false;}return true;
}

function td_alert_ack_path(string $baseDir): string {
    return rtrim($baseDir,'/\\').'/trade_dashboard_runtime/alert_ack.json';
}
function td_alert_ack_state(string $baseDir): array {
    $row=td_read_json_file(td_alert_ack_path($baseDir));
    if(!is_array($row)||($row['schema']??'')!==TD_ALERT_ACK_SCHEMA)$row=array('schema'=>TD_ALERT_ACK_SCHEMA,'updated_at'=>'','entries'=>array());
    if(!isset($row['entries'])||!is_array($row['entries']))$row['entries']=array();
    return $row;
}
function td_alert_base_id(array $alert): string {
    return hash('sha256',strtolower(trim((string)($alert[0]??''))).'|'.trim((string)($alert[1]??'')));
}
function td_alert_tick_band(array $alert,array $strategyLogs): string {
    $title=trim((string)($alert[1]??''));
    if(!preg_match('/^([A-Z0-9_]+)\s+tick 지연$/u',$title,$m))return 'base';
    $key=strtolower((string)$m[1]);
    $log=is_array($strategyLogs[$key]??null)?$strategyLogs[$key]:array();
    $ts=(int)($log['last_tick_ts']??0);
    if($ts<=0)return 'tick_unknown';
    $age=max(0,time()-$ts);
    if($age>=TD_ALERT_ACK_REARM_TICK_4X_SEC)return 'tick_360m';
    if($age>=TD_ALERT_ACK_REARM_TICK_2X_SEC)return 'tick_180m';
    return 'tick_90m';
}
function td_alert_identity(array $alert,array $strategyLogs): array {
    $base=td_alert_base_id($alert);$band=td_alert_tick_band($alert,$strategyLogs);
    return array(
        'base_id'=>$base,
        'band'=>$band,
        'key'=>hash('sha256',$base.'|'.$band),
        'class'=>(string)($alert[0]??'warn'),
        'title'=>(string)($alert[1]??'경고'),
        'detail'=>(string)($alert[2]??''),
    );
}
function td_alert_ack_prune(string $baseDir,array $state,array $activeKeys): array {
    $keep=array_fill_keys($activeKeys,true);$changed=false;$entries=array();
    foreach((array)($state['entries']??array()) as $key=>$row){
        if(isset($keep[(string)$key]))$entries[(string)$key]=$row;else $changed=true;
    }
    if($changed){
        $state['entries']=$entries;$state['updated_at']=date('Y-m-d H:i:s');
        td_atomic_json_file(td_alert_ack_path($baseDir),$state);
    }
    return $state;
}
function td_alert_ack_write(string $baseDir,array $state,array $identity): bool {
    $key=(string)($identity['key']??'');if($key==='')return false;
    if(!isset($state['entries'])||!is_array($state['entries']))$state['entries']=array();
    $state['entries'][$key]=array(
        'base_id'=>(string)($identity['base_id']??''),
        'band'=>(string)($identity['band']??'base'),
        'class'=>(string)($identity['class']??'warn'),
        'title'=>(string)($identity['title']??'경고'),
        'acked_at'=>date('Y-m-d H:i:s'),
        'acked_epoch'=>time(),
    );
    $state['schema']=TD_ALERT_ACK_SCHEMA;$state['updated_at']=date('Y-m-d H:i:s');
    return td_atomic_json_file(td_alert_ack_path($baseDir),$state);
}

function td_runner_manual_paths(string $baseDir): array {
    $r=$baseDir.'/trade_runner_runtime';
    return array('runtime'=>$r,'request'=>$r.'/broker_manual_request.json','result'=>$r.'/broker_manual_result.json','daemon_hb'=>$r.'/daemon_heartbeat.json');
}
function td_runner_daemon_age(string $baseDir): ?int {
    $p=td_runner_manual_paths($baseDir);$hb=td_read_json_file($p['daemon_hb']);$ts=0;
    if(is_array($hb)){$ts=(int)($hb['epoch']??0);if($ts<=0&&trim((string)($hb['at']??''))!=='')$ts=(int)(strtotime((string)$hb['at'])?:0);}
    return $ts>0?max(0,time()-$ts):null;
}
function td_runner_daemon_snapshot(string $baseDir): array {
    $p=td_runner_manual_paths($baseDir);$hb=td_read_json_file($p['daemon_hb']);
    if(!is_array($hb))$hb=array();
    $age=td_runner_daemon_age($baseDir);
    return array(
        'age_sec'=>$age,
        'version'=>(string)($hb['version']??''),
        'action'=>strtoupper(trim((string)($hb['action']??''))),
        'job'=>strtolower(trim((string)($hb['job']??''))),
        'job_elapsed_sec'=>isset($hb['job_elapsed_sec'])&&is_numeric($hb['job_elapsed_sec'])?(float)$hb['job_elapsed_sec']:null,
        'at'=>(string)($hb['at']??''),
    );
}
function td_broker_request_wait_text(string $baseDir,array $manual): string {
    $d=td_runner_daemon_snapshot($baseDir);$action=(string)$d['action'];$job=strtoupper((string)$d['job']);
    $elapsed=$d['job_elapsed_sec'];
    $age=$d['age_sec'];$remain=max(0,(int)(($manual['request']['expires_epoch']??0)-time()));
    if(strpos($action,'JOB_RUNNING')!==false&&$job!==''&&$job!=='BROKER'){
        $msg='요청 접수 완료 · 현재 '.$job.' 실행 중';
        if($elapsed!==null)$msg.=' · '.number_format((float)$elapsed,1).'초 경과';
        $msg.=' · 현재 작업 종료 후 Broker를 자동 실행합니다.';
    }elseif(strpos($action,'JOB_RUNNING')!==false&&$job==='BROKER'){
        $msg='요청 접수 완료 · Broker가 현재 실행 중입니다.';
    }elseif($age!==null&&$age<=TD_RUNNER_HEARTBEAT_WARN_SEC){
        $msg='요청 접수 완료 · Runner가 대기 중이며 다음 wake에서 Broker 실행을 시도합니다.';
    }else{
        $msg='요청은 존재하지만 Runner heartbeat가 오래되었습니다. Runner 상태를 확인하십시오.';
    }
    if($remain>0)$msg.=' · 요청 유효 '.(int)ceil($remain/60).'분';
    return $msg;
}
function td_queue_broker_request(string $baseDir): array {
    $ctx=td_runtime_context($baseDir);if(empty($ctx['single_file_paper']))return array(false,'SINGLE_FILE_PAPER 상태에서만 수동 Broker 진단 실행을 요청할 수 있습니다.');
    $p=td_runner_manual_paths($baseDir);$age=td_runner_daemon_age($baseDir);
    if($age===null||$age>TD_RUNNER_HEARTBEAT_WARN_SEC)return array(false,'Runner daemon heartbeat가 오래되었거나 없습니다. 먼저 Runner 상태를 확인하십시오.');
    $old=td_read_json_file($p['request']);
    if(is_array($old)&&($old['schema']??'')==='trade_runner_broker_request_v1'&&(int)($old['expires_epoch']??0)>=time()){
        return array(true,'수동 Broker 진단 요청이 이미 접수되어 있습니다.\n요청 ID: '.(string)($old['request_id']??'-').'\nRunner가 처리할 때까지 중복 호출하지 않습니다.');
    }
    $id='MBR-'.date('Ymd-His').'-'.substr(hash('sha256',microtime(true).'|'.getmypid().'|'.mt_rand()),0,8);
    $row=array('schema'=>'trade_runner_broker_request_v1','request_id'=>$id,'requested_at'=>date('Y-m-d H:i:s'),'requested_epoch'=>time(),'expires_epoch'=>time()+1800,'source'=>'trade_dashboard','authority'=>(string)$ctx['authority']);
    if(!td_atomic_json_file($p['request'],$row))return array(false,'Runner 요청 파일을 기록하지 못했습니다: '.$p['request']);
    return array(true,'수동 Broker 진단 요청을 Runner에 접수했습니다.\n요청 ID: '.$id.'\n현재 heavy job이 없으면 수초 내 감지하고, 작업 중이면 그 job 종료 후 자동 실행합니다.');
}
function td_broker_request_status(string $baseDir): array {
    $p=td_runner_manual_paths($baseDir);$req=td_read_json_file($p['request']);$res=td_read_json_file($p['result']);
    $active=is_array($req)&&($req['schema']??'')==='trade_runner_broker_request_v1'&&(int)($req['expires_epoch']??0)>=time();
    return array('active'=>$active,'request'=>$active?$req:array(),'result'=>is_array($res)?$res:array(),'paths'=>$p,'daemon'=>td_runner_daemon_snapshot($baseDir));
}
function td_broker_status_text(string $baseDir): string {
    $ctx=td_runtime_context($baseDir);$runtime=(string)$ctx['broker_runtime'];$hb=td_read_json_file($runtime.'/broker_heartbeat.json');$manual=td_broker_request_status($baseDir);$age=td_runner_daemon_age($baseDir);
    $lines=array('Authority: '.(string)$ctx['authority'],'Broker runtime: '.$runtime,'Runner heartbeat: '.($age===null?'미확인':$age.'초'));
    $lines[]='Broker last_cycle_at: '.(string)($hb['last_cycle_at']??$hb['last_run_at']??'-');
    if(!empty($manual['active']))$lines[]='수동 진단 요청: 대기 중 · '.(string)($manual['request']['request_id']??'-');
    elseif(!empty($manual['result']))$lines[]='최근 수동 진단: '.(string)($manual['result']['status']??'-').' · '.(string)($manual['result']['updated_at']??'-');
    else $lines[]='수동 진단 요청: 없음';
    return implode("\n",$lines);
}

$actionResult = null;
$actionQueued = false;
$brokerActionName = '';
if ((isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'GET') {
    $action = isset($_GET['broker_action']) ? (string)$_GET['broker_action'] : '';
    $brokerActionName = $action;
    if ($action !== '') {
        $token = isset($_GET['action_token']) ? (string)$_GET['action_token'] : '';
        if (!td_action_token_valid($TD_BASE_DIR, $action, $token)) {
            $actionResult = array(false, '브로커 작업 토큰이 유효하지 않습니다. 대시보드를 새로고침한 뒤 다시 실행하십시오.');
        } elseif ($action === 'broker_request') {
            $actionResult = td_queue_broker_request($TD_BASE_DIR);$actionQueued=!empty($actionResult[0]);
        } elseif ($action === 'broker_status') {
            $actionResult = array(true,td_broker_status_text($TD_BASE_DIR));
        } else {
            $actionResult = array(false,'지원하지 않는 대시보드 작업입니다.');
        }
    }
}
$manualBrokerStatus = td_broker_request_status($TD_BASE_DIR);


function td_live_broker_summary(string $baseDir): array {
    $ctx = td_runtime_context($baseDir);
    $runtime = (string)$ctx['broker_runtime'];
    $heartbeat = td_read_json_file($runtime . '/broker_heartbeat.json');
    $settings = td_read_json_file($runtime . '/approval_mode.json');
    $out = array(
        'runtime_path'=>$runtime,
        'runtime_authority'=>(string)$ctx['authority'],
        'runtime_available'=>is_dir($runtime),
        'runtime_single_file_paper'=>!empty($ctx['single_file_paper']),
        'legacy_runtime_ignored'=>!empty($ctx['single_file_paper']) && is_dir((string)$ctx['legacy_broker_runtime']),
        // Explicit nulls prevent stale analysis snapshots from masquerading as live telemetry.
        'cycle_age_sec'=>null,
        'cycle_stale'=>true,
    );

    if (is_array($heartbeat)) {
        $out = array_replace($out, $heartbeat);
        $out['runtime_path'] = $runtime;
        $out['runtime_authority'] = (string)$ctx['authority'];
        $out['runtime_available'] = true;
        $out['runtime_single_file_paper'] = !empty($ctx['single_file_paper']);
        $out['legacy_runtime_ignored'] = !empty($ctx['single_file_paper']) && is_dir((string)$ctx['legacy_broker_runtime']);
        $out['broker_version'] = (string)($heartbeat['version'] ?? $out['broker_version'] ?? '');
        $out['broker_rev'] = (string)($heartbeat['rev'] ?? $out['broker_rev'] ?? '');
        $last = (string)($heartbeat['last_cycle_at'] ?? $heartbeat['last_run_at'] ?? '');
        $ts = $last !== '' ? strtotime($last) : false;
        $out['cycle_age_sec'] = $ts ? max(0, time() - $ts) : null;
        $out['cycle_stale'] = is_numeric($out['cycle_age_sec']) ? ((int)$out['cycle_age_sec'] > TD_BROKER_CRON_WARN_SEC) : true;
        $out['recommended_cycle_sec'] = (int)($heartbeat['expected_cycle_sec'] ?? TD_BROKER_CRON_EXPECTED_SEC);
    } else {
        $out['runtime_error'] = !is_dir($runtime) ? 'BROKER_RUNTIME_MISSING' : 'BROKER_HEARTBEAT_MISSING_OR_INVALID';
    }

    if (is_array($settings)) $out['approval_mode'] = strtolower((string)($settings['mode'] ?? $out['approval_mode'] ?? 'manual'));
    $lockStats=td_read_json_file($runtime . '/broker_lock_stats.json');if(is_array($lockStats))$out['lock_stats']=$lockStats;
    $ingestAudit=td_read_json_file($runtime . '/broker_ingest_audit.json');if(is_array($ingestAudit))$out['ingest_audit']=$ingestAudit;
    $spoolFiles=is_dir($runtime.'/intent_spool')?(glob($runtime.'/intent_spool/*.json')?:array()):array();$oldestSpool=null;
    foreach($spoolFiles as$sf){$sx=td_read_json_file($sf);$created=is_array($sx)?(string)td_get($sx,array('intent','created_at'),td_get($sx,array('created_at'),'')):'';$sts=$created!==''?strtotime($created):false;if($sts!==false){$sa=max(0,time()-$sts);if($oldestSpool===null||$sa>$oldestSpool)$oldestSpool=$sa;}}
    $out['intent_spool']=array('pending_ack_count'=>count($spoolFiles),'oldest_pending_ack_age_sec'=>$oldestSpool,'sample'=>array_slice(array_map('basename',$spoolFiles),0,10));

    // In SINGLE_FILE_PAPER the Low-Load Runner, not a 1-minute Broker cron, is the scheduler authority.
    if (!empty($ctx['single_file_paper'])) {
        $runnerRuntime = (string)$ctx['runner_runtime'];
        $daemonHb = td_read_json_file($runnerRuntime . '/daemon_heartbeat.json');
        $runnerState = td_read_json_file($runnerRuntime . '/runner_state.json');
        $runnerCfg = array();
        $cfgFile = (string)$ctx['runner_config'];
        if (is_file($cfgFile) && is_readable($cfgFile)) {
            $loaded = @include $cfgFile;
            if (is_array($loaded)) $runnerCfg = $loaded;
        }
        $epoch = is_array($daemonHb) && is_numeric($daemonHb['epoch'] ?? null) ? (int)$daemonHb['epoch'] : 0;
        if ($epoch <= 0 && is_array($daemonHb)) {
            $ts = strtotime((string)($daemonHb['at'] ?? ''));
            $epoch = $ts !== false ? (int)$ts : 0;
        }
        $runnerAge = $epoch > 0 ? max(0, time() - $epoch) : null;
        $scheduler = is_array($runnerState['scheduler'] ?? null) ? $runnerState['scheduler'] : array();
        $transport = is_array($scheduler['transport_gate'] ?? null) ? $scheduler['transport_gate'] : array();
        $brokerJob = is_array($runnerState['jobs']['broker'] ?? null) ? $runnerState['jobs']['broker'] : array();
        $out['runner'] = array(
            'runtime'=>$runnerRuntime,
            'daemon_heartbeat_age_sec'=>$runnerAge,
            'daemon_healthy'=>is_numeric($runnerAge) && (int)$runnerAge <= TD_RUNNER_HEARTBEAT_WARN_SEC,
            'daemon_action'=>(string)(is_array($daemonHb) ? ($daemonHb['action'] ?? '') : ''),
            'daemon_cycle_action'=>(string)(is_array($daemonHb) ? ($daemonHb['cycle_action'] ?? '') : ''),
            'daemon_job'=>(string)(is_array($daemonHb) ? ($daemonHb['job'] ?? '') : ''),
            'transport_gate'=>$transport,
            'transport_ok'=>!empty($transport['ok']),
            'transport_pending_count'=>(int)($transport['pending_count'] ?? 0),
            'transport_integrity_error'=>!empty($transport['integrity_error']),
            'transport_transient_error'=>!empty($transport['transient_error']),
            'transport_reason'=>(string)($transport['reason'] ?? ''),
            'broker_next_due_at'=>(int)($brokerJob['next_due_at'] ?? 0),
            'broker_last_reason'=>(string)($brokerJob['last_reason'] ?? ''),
            'broker_last_full_run_epoch'=>(int)($brokerJob['last_full_run_epoch'] ?? 0),
            'broker_idle_full_sweep_sec'=>max(900,(int)($runnerCfg['broker_idle_full_sweep_sec'] ?? TD_RUNNER_DEFAULT_IDLE_BROKER_SWEEP_SEC)),
            'broker_active_interval_sec'=>max(60,(int)($runnerCfg['broker_active_interval_sec'] ?? TD_RUNNER_DEFAULT_ACTIVE_BROKER_SEC)),
            'broker_quick_poll_sec'=>max(30,(int)($runnerCfg['broker_quick_poll_sec'] ?? TD_BROKER_CRON_EXPECTED_SEC)),
            'last_gate_active'=>(int)($brokerJob['last_gate_active'] ?? 0),
            'last_gate_actionable'=>(int)($brokerJob['last_gate_actionable'] ?? 0),
            'last_gate_market_wait'=>(int)($brokerJob['last_gate_market_wait'] ?? 0),
            'market_wait_recheck_at'=>(int)($brokerJob['market_wait_recheck_at'] ?? 0),
            'market_wait_plan'=>is_array($brokerJob['market_wait_plan'] ?? null) ? $brokerJob['market_wait_plan'] : array(),
        );
    }
    return $out;
}



function td_php_const_string(string $file, string $name): string {
    if (!is_file($file) || !is_readable($file)) return '';
    $raw = @file_get_contents($file, false, null, 0, 131072);
    if (!is_string($raw) || $raw === '') return '';
    $n = preg_quote($name, '~');
    if (preg_match("~\\bconst\\s+".$n."\\s*=\\s*(['\\\"])(.*?)\\1\\s*;~s", $raw, $m)) return trim((string)$m[2]);
    return '';
}
function td_engine_short_version(string $version): string {
    if (preg_match('~\\bv\\d+\\.\\d+\\.\\d+\\b~i', $version, $m)) return (string)$m[0];
    return $version !== '' ? $version : '-';
}
function td_installed_engine_info(string $baseDir): array {
    $file = rtrim($baseDir, '/\\\\') . '/trade_engine.php';
    $version = td_php_const_string($file, 'TE_VERSION');
    $rev = td_php_const_string($file, 'TE_REV');
    return array(
        'file'=>$file,
        'exists'=>is_file($file),
        'version'=>$version,
        'short'=>td_engine_short_version($version),
        'rev'=>$rev,
    );
}
function td_engine_runtime_matrix(array $strategyMeta, array $analysis, array $strategyAnalysis, array $runtimeOverview, array $installed): array {
    $globalAudit = is_array($analysis['runtime_version_audit']['strategies'] ?? null)
        ? $analysis['runtime_version_audit']['strategies'] : array();
    $summary = is_array($analysis['summary_by_strategy'] ?? null)
        ? $analysis['summary_by_strategy'] : array();

    $rows = array(); $mismatch = array(); $unknown = array(); $current = array();
    $installedVersion = trim((string)($installed['version'] ?? ''));
    $installedRev = trim((string)($installed['rev'] ?? ''));

    foreach ($strategyMeta as $key=>$meta) {
        $sa = is_array($strategyAnalysis[$key] ?? null) ? $strategyAnalysis[$key] : array();
        $ov = is_array($runtimeOverview[$key] ?? null) ? $runtimeOverview[$key] : array();
        $localAudit = is_array($sa['version_audit'] ?? null) ? $sa['version_audit'] : array();
        $audit = is_array($globalAudit[$key] ?? null) ? $globalAudit[$key] : $localAudit;
        $sum = is_array($summary[$key] ?? null) ? $summary[$key] : array();

        // Evidence priority:
        // 1) strategy runtime engine_state.json/state.json: actual Engine used by last completed tick
        // 2) unified/per-strategy runtime_version_audit
        // 3) unified summary_by_strategy.runtime_version
        // 4) last_completed_tick explicit engine fields
        $runtimeVersion = trim((string)($ov['runtime_engine_version'] ?? ''));
        $runtimeRev = trim((string)($ov['runtime_engine_rev'] ?? ''));
        $runtimeAt = trim((string)($ov['runtime_engine_state_at'] ?? ''));
        $evidence = ($runtimeVersion !== '' || $runtimeRev !== '') ? 'engine_state.json' : '';

        if ($runtimeVersion === '') {
            $runtimeVersion = trim((string)($audit['runtime_version'] ?? ''));
            if ($runtimeVersion !== '') $evidence = 'runtime_version_audit';
        }
        if ($runtimeRev === '') {
            $runtimeRev = trim((string)($audit['runtime_rev'] ?? ''));
            if ($runtimeRev !== '' && $evidence === '') $evidence = 'runtime_version_audit';
        }

        if ($runtimeVersion === '') {
            $runtimeVersion = trim((string)($sum['runtime_version'] ?? ''));
            if ($runtimeVersion !== '') $evidence = 'summary_by_strategy.runtime_version';
        }
        if ($runtimeRev === '') {
            $runtimeRev = trim((string)($sum['runtime_rev'] ?? ''));
            if ($runtimeRev !== '' && $evidence === '') $evidence = 'summary_by_strategy.runtime_rev';
        }

        if ($runtimeVersion === '') {
            $runtimeVersion = trim((string)td_get($sa, array('last_completed_tick','engine_version'), ''));
            if ($runtimeVersion !== '') $evidence = 'last_completed_tick.engine_version';
        }
        if ($runtimeRev === '') {
            $runtimeRev = trim((string)td_get($sa, array('last_completed_tick','engine_rev'), ''));
            if ($runtimeRev !== '' && $evidence === '') $evidence = 'last_completed_tick.engine_rev';
        }

        // For observability only. Do not treat analysis generator Engine as runtime evidence.
        $analysisEngineVersion = trim((string)td_get($sa, array('engine','version'), ''));
        $analysisEngineRev = trim((string)td_get($sa, array('engine','rev'), ''));

        $match = null;
        if ($runtimeRev !== '' && $installedRev !== '') $match = hash_equals($installedRev, $runtimeRev);
        elseif ($runtimeVersion !== '' && $installedVersion !== '') $match = hash_equals($installedVersion, $runtimeVersion);

        if ($match === true) $current[] = (string)$key;
        elseif ($match === false) $mismatch[] = (string)$key;
        else $unknown[] = (string)$key;

        $rows[$key] = array(
            'key'=>(string)$key,
            'label'=>(string)($meta['label'] ?? strtoupper((string)$key)),
            'runtime_version'=>$runtimeVersion,
            'runtime_short'=>td_engine_short_version($runtimeVersion),
            'runtime_rev'=>$runtimeRev,
            'runtime_at'=>$runtimeAt,
            'installed_version'=>$installedVersion,
            'installed_short'=>td_engine_short_version($installedVersion),
            'installed_rev'=>$installedRev,
            'analysis_engine_version'=>$analysisEngineVersion,
            'analysis_engine_rev'=>$analysisEngineRev,
            'match'=>$match,
            'evidence'=>$evidence !== '' ? $evidence : 'unavailable',
        );
    }
    return array(
        'installed'=>$installed,
        'strategies'=>$rows,
        'mismatch'=>$mismatch,
        'unknown'=>$unknown,
        'current'=>$current,
        'all_current'=>count($rows)>0 && count($mismatch)===0 && count($unknown)===0,
    );
}
function td_engine_match_badge(array $row): array {
    if (($row['match'] ?? null) === true) return array('CURRENT','ok');
    if (($row['match'] ?? null) === false) return array('STALE','bad');
    return array('UNKNOWN','warn');
}
function td_visual_width($value, float $max=100.0): float {
    if (!is_numeric($value) || $max <= 0) return 0.0;
    return max(0.0, min(100.0, ((float)$value / $max) * 100.0));
}
function td_visual_return_geometry($value, float $cap=15.0): array {
    if (!is_numeric($value) || $cap <= 0) return array('left'=>50.0,'width'=>0.0,'class'=>'flat');
    $v=max(-$cap,min($cap,(float)$value));
    $width=abs($v)/$cap*50.0;
    return array('left'=>$v>=0?50.0:50.0-$width,'width'=>$width,'class'=>$v>0?'posbar':($v<0?'negbar':'flatbar'));
}
function td_visual_due_label($epoch): string {
    if (!is_numeric($epoch) || (int)$epoch <= 0) return '-';
    return date('m/d H:i',(int)$epoch);
}


$analysisPath = td_find_latest_analysis($TD_BASE_DIR);
$analysis = $analysisPath ? (td_read_json_file($analysisPath) ?: array()) : array();
$brokerSummary = is_array(td_get($analysis, array('broker_summary'), array())) ? td_get($analysis, array('broker_summary'), array()) : array();
$liveBrokerSummary = td_live_broker_summary($TD_BASE_DIR);
if ($liveBrokerSummary) $brokerSummary = array_replace($brokerSummary, $liveBrokerSummary);
$analysisPortfolioGuard = is_array(td_get($analysis, array('portfolio_guard'), array())) ? td_get($analysis, array('portfolio_guard'), array()) : array();
$comparison = is_array(td_get($analysis, array('comparison'), array())) ? td_get($analysis, array('comparison'), array()) : array();
$strategyAnalysis = is_array(td_get($analysis, array('strategies'), array())) ? td_get($analysis, array('strategies'), array()) : array();
$ordersBundle = td_load_broker_orders($TD_BASE_DIR);
$orders = $ordersBundle['orders'];
$jpRequoteSummary = td_jp_requote_summary($orders);
$orderCounts = td_collect_order_counts($orders);
$livePortfolioGuard = td_live_portfolio_guard($TD_BASE_DIR, $TD_STRATEGIES, $orders, $ordersBundle['path']);
$portfolioGuard = !empty($livePortfolioGuard['available']) ? $livePortfolioGuard : $analysisPortfolioGuard;
$pipeline = is_array(td_get($brokerSummary, array('pipeline'), array())) ? td_get($brokerSummary, array('pipeline'), array()) : array();
list($comparison, $strategyAnalysis) = td_merge_runtime_fallbacks($TD_BASE_DIR, $TD_STRATEGIES, $orders, $comparison, $strategyAnalysis);
$strategyLogs = td_strategy_log_statuses($TD_BASE_DIR, $TD_STRATEGIES);
list($comparison, $strategyAnalysis, $strategyLogs) = td_apply_strategy_log_statuses($comparison, $strategyAnalysis, $strategyLogs);
$strategyPerformance = td_strategy_performance_all($TD_BASE_DIR, $TD_STRATEGIES);
$strategyRuntimeOverview = td_strategy_runtime_overview_all($TD_BASE_DIR, $TD_STRATEGIES);
$installedEngine = td_installed_engine_info($TD_BASE_DIR);
$engineVersionMatrix = td_engine_runtime_matrix($TD_STRATEGIES, $analysis, $strategyAnalysis, $strategyRuntimeOverview, $installedEngine);
$commonValidation = td_common_validation($TD_BASE_DIR, $TD_STRATEGIES);
$labResearch = td_lab_research($TD_BASE_DIR);

function td_build_alerts($brokerSummary, $portfolioGuard, $orders, $strategies, $strategyLogs, $commonValidation): array {
    $alerts = array();
    $servedFilename = basename(__FILE__);
    if ($servedFilename !== TD_EXPECTED_OPERATIONAL_FILENAME) {
        $alerts[] = array('info', '대시보드 임시 파일 실행 중', '현재 ' . $servedFilename . ' 파일을 열었습니다. 정상 운영 URL은 ' . TD_EXPECTED_OPERATIONAL_FILENAME . '이며, 이 파일로 교체한 뒤 해당 URL을 새로 여십시오.');
    }
    $runtimeError = (string)td_get($brokerSummary, array('runtime_error'), '');
    $runtimePath = (string)td_get($brokerSummary, array('runtime_path'), '');
    $cycleAge = td_get($brokerSummary, array('cycle_age_sec'), null);
    $runnerManaged = !empty($brokerSummary['runtime_single_file_paper']);
    $orderHealth = td_active_order_health(is_array($orders) ? $orders : array());

    if ($runtimeError !== '') {
        $alerts[] = array('bad','브로커 runtime 미확인',$runtimeError . ($runtimePath !== '' ? ' · ' . $runtimePath : '') . ' · SINGLE_FILE_PAPER에서는 구 trade_runtime으로 자동 후퇴하지 않습니다.');
    } elseif ($runnerManaged) {
        // Low-Load Runner is the scheduler authority. Broker full-cycle age alone is NOT a cron failure.
        $runnerAge = td_get($brokerSummary,array('runner','daemon_heartbeat_age_sec'),null);
        $runnerHealthy = !empty(td_get($brokerSummary,array('runner','daemon_healthy'),false));
        $transportPending = (int)td_get($brokerSummary,array('runner','transport_pending_count'),0);
        $transportIntegrity = !empty(td_get($brokerSummary,array('runner','transport_integrity_error'),false));
        $transportTransient = !empty(td_get($brokerSummary,array('runner','transport_transient_error'),false));
        $transportReason = (string)td_get($brokerSummary,array('runner','transport_reason'),'');
        $actionableOldest = (int)($orderHealth['actionable_oldest_age_sec'] ?? 0);
        $activeInterval = (int)td_get($brokerSummary,array('runner','broker_active_interval_sec'),TD_RUNNER_DEFAULT_ACTIVE_BROKER_SEC);

        if (!$runnerHealthy) {
            $alerts[] = array('bad','Low-Load Runner heartbeat 지연','Runner daemon heartbeat가 '.(is_numeric($runnerAge)?(int)$runnerAge.'초 전':'확인되지 않음').'입니다. SINGLE_FILE_PAPER에서는 Broker cron이 아니라 Runner daemon 상태를 먼저 확인하십시오.');
        }        if ($transportIntegrity) {
            $alerts[] = array('bad','Broker transport 무결성 차단',$transportReason !== '' ? $transportReason : 'Transport truth가 불명확하여 fail-closed 상태입니다.');
        } elseif ($transportTransient) {
            $alerts[] = array('warn','Broker transport 재확인 대기',$transportReason !== '' ? $transportReason : '일시적 transport read 오류를 Runner가 재확인 중입니다.');
        } elseif ($transportPending > 0) {
            $alerts[] = array('warn','Broker handoff 대기','Broker ingest 증거가 없는 transport intent '.$transportPending.'건을 Runner가 우선 처리 중입니다.');
        } elseif ($actionableOldest > max(600,$activeInterval*2)) {
            $cycleText=is_numeric($cycleAge)?' · 마지막 Broker full cycle '.(int)$cycleAge.'초 전':'';
            $alerts[] = array('bad','활성 주문 처리 정체','시장 개장 대기가 아닌 활성 주문의 최장 대기시간이 '.$actionableOldest.'초입니다'.$cycleText.'. Runner actionable watchdog / Broker 실행 상태를 확인하십시오.');
        }
        // Idle full-cycle age is deliberately informational. Runner quick gate runs every ~60s,
        // while a no-work Broker full cycle is intentionally sparse (default hourly safety sweep).
    } else {
        // Legacy/unmarked deployments retain the historical independent 1-minute Broker cron contract.
        if (!is_numeric($cycleAge)) {
            $alerts[] = array('bad','브로커 cycle 미확인','선택된 Broker runtime에서 heartbeat를 확인할 수 없습니다.');
        } elseif ((int)$cycleAge >= TD_BROKER_CRON_WARN_SEC) {
            $alerts[] = array('bad','브로커 cron 지연','마지막 broker cycle이 '.(int)$cycleAge.'초 전입니다. 정상 '.TD_BROKER_CRON_EXPECTED_SEC.'초 · 주의 '.TD_BROKER_CRON_CAUTION_SEC.'초 · 경고 '.TD_BROKER_CRON_WARN_SEC.'초 기준을 초과했습니다.');
        } elseif ((int)$cycleAge >= TD_BROKER_CRON_CAUTION_SEC) {
            $alerts[] = array('warn','브로커 cron 주의','마지막 broker cycle이 '.(int)$cycleAge.'초 전입니다. 1분 호출 기준으로 2회 이상 지연되었습니다. '.TD_BROKER_CRON_WARN_SEC.'초를 넘으면 장애 경고로 전환됩니다.');
        }
    }
    $lockLast=td_get($brokerSummary,array('lock_stats','last_at'),'');$lockTs=$lockLast!==''?(strtotime((string)$lockLast)?:0):0;if($lockTs>0&&time()-$lockTs<TD_BROKER_CRON_WARN_SEC)$alerts[]=array('warn','브로커 LOCK_BUSY','최근 3분 안에 broker.lock 충돌이 있었습니다. Broker heavy 실행이 겹치지 않는지 확인하십시오.');
    $cycleElapsed=td_get($brokerSummary,array('last_result','cycle_elapsed_sec'),null);
    if(is_numeric($cycleElapsed)&&(float)$cycleElapsed>=TD_BROKER_CRON_EXPECTED_SEC)$alerts[]=array('warn','브로커 cycle 실행시간 주의','최근 broker cycle 실행시간이 '.td_num($cycleElapsed,1).'초입니다. 장시간 heavy 실행이면 Runner의 다음 작업을 지연시킬 수 있습니다.');
    $oldest = (int)($orderHealth['actionable_oldest_age_sec'] ?? 0);
    if ($oldest <= 0) {
        $oldest = (int)td_get($brokerSummary, array('last_result','latency','oldest_actionable_active_age_sec'), td_get($brokerSummary,array('latency','oldest_actionable_active_age_sec'),0));
    }
    if (!$runnerManaged && $oldest > TD_ACTIVE_ORDER_WARN_SEC) {
        $alerts[] = array('bad','활성 주문 처리 지연','시장 개장 대기 주문을 제외한 활성 주문이 '.$oldest.'초 동안 진행되지 않았습니다. 승인·검증·체결 상태를 확인하십시오.');
    }
    $spoolPending=td_get($brokerSummary,array('intent_spool','pending_ack_count'),0);
    if(is_numeric($spoolPending)&&(int)$spoolPending>0){
        $spoolAge=td_get($brokerSummary,array('intent_spool','oldest_pending_ack_age_sec'),null);
        $detail='Broker ACK를 기다리는 BUY intent spool이 '.(int)$spoolPending.'건 있습니다.';
        if(is_numeric($spoolAge))$detail.=' 가장 오래된 대기 '.(int)$spoolAge.'초.';
        $alerts[]=array(((int)$spoolPending>3?'bad':'warn'),'Engine→Broker handoff 대기',$detail);
    }
    $jpRq = td_jp_requote_summary(is_array($orders) ? $orders : array());
    if ((int)($jpRq['waiting'] ?? 0) > 0) {
        $mwDue=(int)td_get($brokerSummary,array('runner','market_wait_recheck_at'),0);
        $mwText=$mwDue>0?' · 다음 Runner 확인 '.date('Y-m-d H:i',$mwDue):'';
        $alerts[] = array('info','JP 실시간 재호가 대기','JP PAPER 주문 '.(int)$jpRq['waiting'].'건이 KIS 현재가 재조회를 기다리고 있습니다. 정상 시장대기는 일반 주문 지연과 분리하며 TTL 내에서는 EXPIRED 경고로 보지 않습니다'.$mwText.'. 누적 대기 '.(int)($jpRq['waiting_oldest_age_sec'] ?? 0).'초.');
    }
    if ((int)($jpRq['recent_timeout_6h'] ?? 0) > 0) {
        $alerts[] = array('warn','JP 재호가 시간초과','최근 6시간 내 KIS 재호가 시간초과 '.(int)$jpRq['recent_timeout_6h'].'건이 있습니다. 기술적 종료이므로 재진입 쿨다운은 적용하지 않습니다.');
    }
    if ((int)($jpRq['recent_failed_6h'] ?? 0) > 0) {
        $alerts[] = array('bad','JP 재호가 재검증 실패','최근 6시간 내 실시간 가격 재검증 실패 '.(int)$jpRq['recent_failed_6h'].'건이 있습니다. 가격 괴리·손절/목표 구조를 확인하십시오.');
    }
    $ingestAudit=td_get($brokerSummary,array('ingest_audit'),array());
    $ackFailed=td_get($ingestAudit,array('ack_failed'),0);
    if(is_numeric($ackFailed)&&(int)$ackFailed>0)$alerts[]=array('bad','Broker ACK 기록 실패','최근 ingest에서 ACK 파일 기록 실패 '.(int)$ackFailed.'건이 감지되었습니다.');
    $pipe = td_get($brokerSummary, array('pipeline'), array());
    $expired = td_get($pipe, array('expired'), 0);
    if (is_numeric($expired) && (int)$expired > 0) {
        $alerts[] = array('warn', 'EXPIRED 누적', '만료 주문 누적 ' . number_format((int)$expired) . '건입니다. 정리 버튼으로 화면 잡음을 줄일 수 있습니다.');
    }
    $repeats = td_get($portfolioGuard, array('violations','repeated_expired'), 0);
    if (is_numeric($repeats) && (int)$repeats > 0) {
        $alerts[] = array('bad', '반복 만료', '반복 만료 종목 ' . (int)$repeats . '건이 감지되었습니다.');
    }
    $correlationViolations = td_get($portfolioGuard, array('violations','correlation_group'), 0);
    if (is_numeric($correlationViolations) && (int)$correlationViolations > 0) {
        $first = td_get($portfolioGuard, array('top_correlation_groups',0,'correlation_group'), '');
        $alerts[] = array('bad', '상관그룹 집중 초과', '동일 방향 자산군이 허용 수를 초과했습니다'.($first !== '' ? ' · '.$first : '').'. 기존 보유는 유지되지만 신규 매수는 차단됩니다.');
    }
    $stuckCount=td_get($pipe,array('stuck_count'),0);
    if(is_numeric($stuckCount)&&(int)$stuckCount>0){$first=td_get($pipe,array('stuck_scenarios',0,'key'),'');$alerts[]=array('bad','고착 주문 감지','동일 주문 반복 만료 '.(int)$stuckCount.'개 시나리오'.($first!==''?' · '.$first:''));}
    $sellDelayed = $orderHealth['oldest_actionable_sell'] ?? null;
    if (is_array($sellDelayed)) {
        $age = (int)($sellDelayed['_age_sec'] ?? 0);
        $alerts[] = array('bad', 'SELL 주문 장기 대기', strtoupper((string)($sellDelayed['strategy'] ?? $sellDelayed['strategy_key'] ?? '-')) . ' ' . ($sellDelayed['market'] ?? '-') . ' ' . td_stock_label((string)($sellDelayed['market'] ?? ''), (string)($sellDelayed['symbol'] ?? ''), (string)($sellDelayed['name'] ?? '')) . ' 매도 주문이 시장 개장 대기가 아닌 상태에서 '.$age.'초 동안 진행되지 않았습니다.');
    }
    $waitSell = $orderHealth['first_market_wait_sell'] ?? null;
    if (is_array($waitSell)) {
        $count = (int)($orderHealth['market_wait_sell_count'] ?? 0);
        $alerts[] = array('info', 'SELL 시장 개장 대기', strtoupper((string)($waitSell['strategy'] ?? $waitSell['strategy_key'] ?? '-')) . ' ' . ($waitSell['market'] ?? '-') . ' ' . td_stock_label((string)($waitSell['market'] ?? ''), (string)($waitSell['symbol'] ?? ''), (string)($waitSell['name'] ?? '')) . ' 매도 주문'.($count > 1 ? ' 외 '.($count-1).'건이' : '이').' 시장 개장을 기다리고 있습니다. 브로커는 정상적으로 재확인하며, 개장 후 15분 이상 진행되지 않을 때만 장기 대기로 경고합니다.');
    }
    $markets = td_get($portfolioGuard, array('markets'), array());
    if (is_array($markets)) {
        foreach ($markets as $m => $row) {
            if (td_get($row, array('over_limit'), false)) {
                $alerts[] = array('bad', '시장 한도 초과', $m . ' 투자비중이 한도를 초과했습니다.');
            }
        }
    }
    foreach ($strategies as $key => $meta) {
        $log = is_array($strategyLogs[$key] ?? null) ? $strategyLogs[$key] : array();
        $path = (string)($log['path'] ?? td_runtime_dir(__DIR__, $meta, (string)$key) . '/engine.log');
        $t = (int)($log['last_tick_ts'] ?? 0);
        $source = (string)($log['effective_source'] ?? ($t > 0 ? 'engine.log' : ''));
        $runnerEvidence = is_array($log['runner_evidence'] ?? null) ? $log['runner_evidence'] : array();
        if ($t > 0) {
            if (time() - $t > TD_STRATEGY_TICK_WARN_SEC) {
                $detail = $source . ' 마지막 정상 tick: ' . (string)$log['last_tick'] . ' · ' . td_age_label($t);
                if (!empty($runnerEvidence['available']) && empty($runnerEvidence['ok']) && (int)($runnerEvidence['runs'] ?? 0) > 0) {
                    $detail .= ' · Runner 최근 종료=' . (string)($runnerEvidence['last_end_at'] ?? '-') .
                        ' exit=' . (string)($runnerEvidence['last_exit_code'] ?? '-') .
                        ' reason=' . (string)($runnerEvidence['last_reason'] ?? '-');
                }
                $alerts[] = array('warn', strtoupper((string)$key) . ' tick 지연', $detail);
            }
        } elseif (empty($log['exists'])) {
            $alerts[] = array('bad', strtoupper((string)$key) . ' tick 증거 없음', $path . ' 및 최신 분석 스냅샷에서 정상 tick을 찾지 못했습니다.');
        } elseif (empty($log['readable'])) {
            $alerts[] = array('bad', strtoupper((string)$key) . ' engine.log 읽기 실패', $path . ' 파일 읽기 권한을 확인하십시오.');
        } else {
            $alerts[] = array('bad', strtoupper((string)$key) . ' 정상 tick 미확인', $path . '와 최신 분석 스냅샷에서 성공한 tick 기록을 찾지 못했습니다.');
        }
    }
    if (empty($commonValidation['available'])) {
        $alerts[] = array('info', '검증 안내 · 기록 대기', '운영 장애가 아닙니다. 전체 전략 공통 검증기는 첫 전략 신호부터 표본을 등록하며 검증 전용 cron은 필요하지 않습니다.');
    } else {
        $building = array();
        foreach (is_array($commonValidation['strategies'] ?? null) ? $commonValidation['strategies'] : array() as $strategy => $row) {
            $registered = (int)($row['registered'] ?? 0);
            $remaining = (int)($row['remaining_samples_to_usable'] ?? 0);
            $name=strtoupper((string)($row['strategy']??$strategy));
            if ($registered > 0 && $remaining > 0) $building[$name] = isset($building[$name]) ? min((int)$building[$name],$remaining) : $remaining;
        }
        $buildingText=array();foreach($building as$name=>$remaining)$buildingText[]=$name.' '.$remaining.'건 남음';
        if ($buildingText) $alerts[] = array('info', '검증 안내 · 표본 축적 중', implode(' · ', $buildingText) . '. 운영 장애가 아닙니다. 완료 표본 30건 전 성과값은 잠정치이며 CORE 유지·퇴출 판단에 사용하지 않습니다.');
    }
    if (!$alerts) $alerts[] = array('ok', '정상', '현재 통합 대시보드 기준 치명 경고가 없습니다.');
    $hasOperationalAlert=false;
    foreach($alerts as $a){$cls=(string)($a[0]??'');if($cls==='bad'||$cls==='warn'){$hasOperationalAlert=true;break;}}
    if(!$hasOperationalAlert){array_unshift($alerts,array('ok','운영 정상','Runner · Broker transport · 활성 주문 처리 · 전략 tick에서 경고 조건이 감지되지 않았습니다.'));}
    return $alerts;
}
$alerts = td_build_alerts($brokerSummary, $portfolioGuard, $orders, $TD_STRATEGIES, $strategyLogs, $commonValidation);

// v3.4.8: operator acknowledgement is isolated from trading execution state.
// ACK means "seen", not "fixed". Monitoring continues. Resolved alerts are automatically
// pruned, while tick-delay escalation (90m -> 180m -> 360m) creates a new unacknowledged key.
$tdOperationalAlertIdentities=array();
foreach($alerts as $ta){
    $tc=(string)($ta[0]??'');
    if($tc!=='bad'&&$tc!=='warn')continue;
    $tid=td_alert_identity($ta,$strategyLogs);
    $tdOperationalAlertIdentities[(string)$tid['key']]=$tid;
}
$tdAlertAckState=td_alert_ack_state($TD_BASE_DIR);
$tdAlertAckState=td_alert_ack_prune($TD_BASE_DIR,$tdAlertAckState,array_keys($tdOperationalAlertIdentities));

if((isset($_SERVER['REQUEST_METHOD'])?(string)$_SERVER['REQUEST_METHOD']:'GET')==='POST'
   &&(string)($_POST['dashboard_action']??'')==='ack_alert'){
    $ackKey=trim((string)($_POST['alert_key']??''));
    $ackAction='ack_alert|'.$ackKey;
    $ackToken=(string)($_POST['action_token']??'');
    if($ackKey===''||!isset($tdOperationalAlertIdentities[$ackKey])){
        $actionResult=array(false,'현재 활성 경고를 찾을 수 없습니다. 화면을 새로고침한 뒤 다시 확인하십시오.');
    }elseif(!td_action_token_valid($TD_BASE_DIR,$ackAction,$ackToken)){
        $actionResult=array(false,'경고 확인 토큰이 유효하지 않습니다. 대시보드를 새로고침한 뒤 다시 시도하십시오.');
    }else{
        $ackIdentity=$tdOperationalAlertIdentities[$ackKey];
        if(td_alert_ack_write($TD_BASE_DIR,$tdAlertAckState,$ackIdentity)){
            if(PHP_SAPI!=='cli'&&!headers_sent()){
                header('Location: '.TD_EXPECTED_OPERATIONAL_FILENAME.'#alerts');
                exit;
            }
            $tdAlertAckState=td_alert_ack_state($TD_BASE_DIR);
            $actionResult=array(true,'경고를 확인 처리했습니다. 원인 감시는 계속됩니다.');
        }else{
            $actionResult=array(false,'경고 확인 상태를 기록하지 못했습니다. trade_dashboard_runtime 쓰기 권한을 확인하십시오.');
        }
    }
}
$tdUnackedOperationalAlerts=array();$tdAckedOperationalAlerts=array();
foreach($tdOperationalAlertIdentities as $key=>$tid){
    $entry=$tdAlertAckState['entries'][$key]??null;
    if(is_array($entry)){
        $tid['acked_at']=(string)($entry['acked_at']??'');
        $tid['acked_epoch']=(int)($entry['acked_epoch']??0);
        $tdAckedOperationalAlerts[]=$tid;
    }else{
        $tdUnackedOperationalAlerts[]=$tid;
    }
}

// v3.4.5 visual overview: snapshot-only, no extra market scan or network call.
$visualRunnerManaged=!empty($brokerSummary['runtime_single_file_paper']);
$visualRunnerHealthy=!empty(td_get($brokerSummary,array('runner','daemon_healthy'),false));
$visualRunnerAge=td_get($brokerSummary,array('runner','daemon_heartbeat_age_sec'),null);
$visualTransportPending=(int)td_get($brokerSummary,array('runner','transport_pending_count'),0);
$visualTransportBad=!empty(td_get($brokerSummary,array('runner','transport_integrity_error'),false));
$visualTransportTransient=!empty(td_get($brokerSummary,array('runner','transport_transient_error'),false));
$visualGateActionable=(int)td_get($brokerSummary,array('runner','last_gate_actionable'),0);
$visualGateMarketWait=(int)td_get($brokerSummary,array('runner','last_gate_market_wait'),0);
$visualMarketWaitDue=(int)td_get($brokerSummary,array('runner','market_wait_recheck_at'),0);
$visualOrderHealth=td_active_order_health($orders);
$visualEngineCurrent=count((array)($engineVersionMatrix['current']??array()));
$visualEngineTotal=count((array)($engineVersionMatrix['strategies']??array()));
$visualTickStale=0;$visualTickUnknown=0;
foreach($strategyLogs as $vk=>$vl){$vt=strtotime((string)($vl['last_tick']??''));if($vt===false||$vt<=0){$visualTickUnknown++;continue;}if(time()-$vt>TD_STRATEGY_TICK_WARN_SEC)$visualTickStale++;}
$visualOperationalIssues=0;foreach($alerts as$va){$vc=(string)($va[0]??'');if($vc==='bad'||$vc==='warn')$visualOperationalIssues++;}
$visualMarkets=is_array(td_get($portfolioGuard,array('markets'),array()))?td_get($portfolioGuard,array('markets'),array()):array();
$visualValidationByStrategy=array();
foreach((array)($commonValidation['strategies']??array()) as $vrow){if(!is_array($vrow))continue;$vs=strtoupper((string)($vrow['strategy']??''));if($vs==='')continue;$visualValidationByStrategy[$vs]=$vrow;}

// v3.4.7: market-regime consensus snapshot from already-loaded strategy runtimes.
// No new scan/network call. If strategy views differ, show a MIXED label instead of hiding it.
$visualRegimes=array();
foreach(array('KR','US','JP') as $vmkt){
    $scores=array();$labels=array();$codes=array();$blocked=0;$seen=0;
    foreach($strategyRuntimeOverview as $vov){
        if(!is_array($vov))continue;
        $vrg=is_array($vov['markets'][$vmkt]??null)?$vov['markets'][$vmkt]:array();
        $code=strtoupper((string)($vrg['code']??'UNKNOWN'));
        $label=trim((string)($vrg['label']??''));
        $score=$vrg['score']??null;
        if($code==='UNKNOWN'&&$label===''&&!is_numeric($score))continue;
        $seen++;
        if(is_numeric($score))$scores[]=(float)$score;
        if($label!=='')$labels[$label]=true;
        if($code!=='')$codes[$code]=true;
        if(!empty($vrg['entry_blocked']))$blocked++;
    }
    $score=$scores?array_sum($scores)/count($scores):null;
    $label=count($labels)===1?(string)array_key_first($labels):(count($labels)>1?'전략별 혼합':'자료 없음');
    $code=count($codes)===1?(string)array_key_first($codes):(count($codes)>1?'MIXED':'UNKNOWN');
    $class=$blocked>0?'warn':td_regime_badge_class($code);
    $visualRegimes[$vmkt]=array('score'=>$score,'label'=>$label,'code'=>$code,'class'=>$class,'blocked'=>$blocked,'seen'=>$seen);
}

$comparisonByKey = array();
foreach ($comparison as $cc) if (is_array($cc) && isset($cc['key'])) $comparisonByKey[(string)$cc['key']] = $cc;
$visibleOperationalAlerts=$tdUnackedOperationalAlerts;
$acknowledgedOperationalAlerts=$tdAckedOperationalAlerts;
$visualTopSymbols=is_array(td_get($portfolioGuard,array('top_symbols'),array()))?array_slice(td_get($portfolioGuard,array('top_symbols'),array()),0,6):array();
$visualRiskGroups=is_array(td_get($portfolioGuard,array('top_risk_groups'),array()))?td_get($portfolioGuard,array('top_risk_groups'),array()):array();
$visualCorrGroups=is_array(td_get($portfolioGuard,array('top_correlation_groups'),array()))?td_get($portfolioGuard,array('top_correlation_groups'),array()):array();
$visualRepeatedExpired=is_array(td_get($portfolioGuard,array('repeated_expired'),array()))?td_get($portfolioGuard,array('repeated_expired'),array()):array();
$visualViolations=is_array(td_get($portfolioGuard,array('violations'),array()))?td_get($portfolioGuard,array('violations'),array()):array();
$visualRiskViolationTotal=0;
foreach(array('market','symbol','risk_group','correlation_group','repeated_expired') as $vk)$visualRiskViolationTotal+=(int)($visualViolations[$vk]??0);
$visualSymbolLimit=(float)td_get($portfolioGuard,array('policy','symbol_limit_pct'),25.0);
if($visualSymbolLimit<=0)$visualSymbolLimit=25.0;
$visualValidationTotals=is_array($commonValidation['totals']??null)?$commonValidation['totals']:array();
$visualOverallClass=(count($visibleOperationalAlerts)>0||count($acknowledgedOperationalAlerts)>0)?'warn':'ok';
$visualOverallText=count($visibleOperationalAlerts)>0
    ?('확인 필요 '.count($visibleOperationalAlerts).'건')
    :(count($acknowledgedOperationalAlerts)>0?('경고 확인됨 '.count($acknowledgedOperationalAlerts).'건 · 감시 중'):'운영 정상');
$refresh = isset($_GET['refresh']) ? max(0, min(300, (int)$_GET['refresh'])) : TD_REFRESH_SEC;
?><!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Dashboard</title>
<?php if ($refresh > 0): ?><meta http-equiv="refresh" content="<?php echo (int)$refresh; ?>"><?php endif; ?>
<style>
:root{--bg:#f4f6f8;--card:#fff;--text:#111827;--muted:#6b7280;--line:#e5e7eb;--ok:#0f766e;--warn:#b45309;--bad:#b91c1c;--info:#2563eb;--buy:#2563eb;--sell:#b91c1c;--shadow:0 2px 8px rgba(0,0,0,.06);--radius:14px}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:14px}.wrap{max-width:1480px;margin:0 auto;padding:18px}.top{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:14px}.title h1{margin:0 0 4px;font-size:24px}.sub{color:var(--muted);font-size:13px}.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:12px}.card{background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:14px}.span12{grid-column:span 12}.span8{grid-column:span 8}.span6{grid-column:span 6}.span4{grid-column:span 4}.span3{grid-column:span 3}.card h2{margin:0 0 10px;font-size:16px}.card h3{margin:0 0 8px;font-size:15px}.bad{color:var(--bad)}.warn{color:var(--warn)}.ok{color:var(--ok)}.info{color:var(--info)}.muted{color:var(--muted)}.pos{color:#047857;font-weight:700}.neg{color:#b91c1c;font-weight:700}.flat{color:#4b5563}.badge{display:inline-flex;align-items:center;border-radius:999px;padding:3px 8px;font-size:12px;font-weight:700;border:1px solid var(--line);white-space:nowrap}.badge.ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.badge.warn{background:#fffbeb;color:#b45309;border-color:#fde68a}.badge.bad{background:#fef2f2;color:#b91c1c;border-color:#fecaca}.badge.info{background:#eff6ff;color:#2563eb;border-color:#bfdbfe}.badge.muted{background:#f3f4f6;color:#6b7280}.badge.buy{background:#eff6ff;color:#2563eb}.badge.sell{background:#fef2f2;color:#b91c1c}.kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.kpi div{background:#f9fafb;border:1px solid var(--line);border-radius:10px;padding:10px}.kpi b{display:block;font-size:18px;margin-top:3px}.row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.btn{display:inline-block;border:1px solid var(--line);background:#111827;color:#fff;border-radius:10px;padding:8px 10px;text-decoration:none;font-weight:700;cursor:pointer}.btn.secondary{background:#fff;color:#111827}.btn.warn{background:#b45309;color:#fff}.btn.bad{background:#b91c1c;color:#fff}button.btn{font:inherit}.model{display:flex;flex-direction:column;gap:8px;min-height:205px}.regime-row{gap:5px}.regime-row .badge{font-size:11px;padding:3px 6px}.model-head{display:flex;justify-content:space-between;gap:8px}.returns{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}.returns div{background:#f9fafb;border:1px solid var(--line);border-radius:10px;padding:8px}.small{font-size:12px;color:var(--muted)}table{width:100%;border-collapse:collapse}th,td{padding:8px 7px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{font-size:12px;color:#4b5563;background:#f9fafb}tr:hover td{background:#fcfcfd}.scroll{overflow:auto}.alert{border-radius:12px;border:1px solid var(--line);padding:10px;margin:7px 0;background:#f9fafb}.alert.bad{background:#fef2f2;border-color:#fecaca}.alert.warn{background:#fffbeb;border-color:#fde68a}.alert.info{background:#eff6ff;border-color:#bfdbfe}.alert.ok{background:#ecfdf5;border-color:#a7f3d0}.progress{height:8px;border-radius:999px;background:#e5e7eb;overflow:hidden}.progress span{display:block;height:100%;background:#2563eb}.foot{margin:16px 0;color:var(--muted);font-size:12px}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace}.nowrap{white-space:nowrap}.compact-line{display:flex;align-items:center;gap:6px;margin:3px 0}.compact-line>b{width:22px}.model-compare{min-width:900px}.top-actions{justify-content:flex-end}.top-actions form,.action-row form{margin:0}.top-actions form .btn,.action-row form .btn{width:100%}.download-menu{position:relative}.download-menu>summary{list-style:none}.download-menu>summary::-webkit-details-marker{display:none}.download-panel{position:absolute;z-index:30;right:0;top:calc(100% + 6px);min-width:190px;padding:8px;background:#fff;border:1px solid var(--line);border-radius:12px;box-shadow:0 10px 28px rgba(0,0,0,.14);display:grid;gap:6px}.download-panel .btn{width:100%;white-space:nowrap;text-align:left}.action-row{gap:8px}.advanced-actions{margin-top:12px;border-top:1px solid var(--line);padding-top:10px}.advanced-actions summary{cursor:pointer;font-weight:700;color:#4b5563}.path-line,.sub,.mono{overflow-wrap:anywhere;word-break:break-word}.kpi .small,.kpi b{overflow-wrap:anywhere;word-break:break-word}.visual-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.visual-box{border:1px solid var(--line);border-radius:12px;padding:11px;background:#f9fafb}.visual-box h3{font-size:13px;margin:0 0 8px}.health-strip{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-bottom:12px}.health-tile{border:1px solid var(--line);border-radius:12px;padding:10px;background:#fff}.health-line{display:flex;align-items:center;gap:7px;font-weight:800}.health-dot{width:10px;height:10px;border-radius:50%;background:#9ca3af;box-shadow:0 0 0 3px #f3f4f6}.health-tile.ok .health-dot{background:#059669;box-shadow:0 0 0 3px #d1fae5}.health-tile.warn .health-dot{background:#d97706;box-shadow:0 0 0 3px #fef3c7}.health-tile.bad .health-dot{background:#dc2626;box-shadow:0 0 0 3px #fee2e2}.health-tile.info .health-dot{background:#2563eb;box-shadow:0 0 0 3px #dbeafe}.vrow{display:grid;grid-template-columns:58px 1fr 72px;gap:8px;align-items:center;margin:8px 0}.vrow .name{font-weight:800}.vbar{height:10px;background:#e5e7eb;border-radius:999px;overflow:hidden;position:relative}.vfill{display:block;height:100%;border-radius:999px;background:#2563eb}.vfill.ok{background:#059669}.vfill.warn{background:#d97706}.vfill.bad{background:#dc2626}.diverge{height:12px;background:#f3f4f6;border-radius:999px;position:relative;overflow:hidden}.diverge:after{content:"";position:absolute;left:50%;top:0;bottom:0;width:1px;background:#9ca3af}.dbar{position:absolute;top:1px;bottom:1px;border-radius:999px}.dbar.posbar{background:#059669}.dbar.negbar{background:#dc2626}.dbar.flatbar{background:#9ca3af}.flow{display:flex;gap:5px;align-items:center;flex-wrap:wrap}.flow-step{flex:1;min-width:90px;border:1px solid var(--line);border-radius:10px;padding:8px;text-align:center;background:#fff}.flow-step b{display:block;font-size:17px;margin-top:2px}.flow-arrow{color:#9ca3af;font-weight:900}.visual-note{margin-top:8px;padding-top:8px;border-top:1px dashed var(--line);font-size:12px;color:var(--muted)}@media(max-width:900px){.span8,.span6,.span4,.span3{grid-column:span 12}.visual-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.health-strip{grid-template-columns:repeat(2,minmax(0,1fr))}.kpi{grid-template-columns:repeat(2,minmax(0,1fr))}.top{flex-direction:column}.returns{grid-template-columns:1fr}.top-actions{width:100%;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.top-actions .btn{text-align:center;padding:10px 8px}}@media(max-width:560px){.wrap{padding:12px}.visual-grid,.health-strip{grid-template-columns:1fr}.vrow{grid-template-columns:52px 1fr 64px}.flow-arrow{display:none}.title h1{font-size:22px}.top-actions{grid-template-columns:1fr 1fr}.download-menu{min-width:0}.download-menu>summary{width:100%;text-align:center}.download-panel{position:absolute;left:0;right:auto;min-width:min(240px,86vw);max-width:86vw}.kpi{grid-template-columns:1fr}.btn{min-height:44px;display:inline-flex;align-items:center;justify-content:center}.download-panel .btn{justify-content:flex-start}.card{padding:12px}.model-compare{min-width:680px}}
</style>
<style id="td-clean-visual-v346">
.command-card{padding:14px}.strategy-visual-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.strategy-visual{border:1px solid var(--line);border-radius:13px;background:#fff;padding:12px;min-width:0}.strategy-visual .sv-head{display:flex;justify-content:space-between;gap:8px;align-items:center}.strategy-visual .sv-ret{font-size:24px;font-weight:850;margin:10px 0 4px}.metric-line{display:grid;grid-template-columns:72px 1fr 58px;gap:8px;align-items:center;margin:8px 0}.metric-line .label{font-size:12px;color:var(--muted)}.metric-line .value{text-align:right;font-weight:750}.risk-grid{display:grid;grid-template-columns:1fr 1.25fr;gap:10px}.risk-mini-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:6px;margin-top:10px}.risk-mini{border:1px solid var(--line);border-radius:9px;padding:7px;text-align:center;background:#fff}.risk-mini b{display:block;font-size:16px}.detail-card{grid-column:span 12;background:var(--card);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:0;overflow:hidden}.detail-card>summary{list-style:none;cursor:pointer;padding:13px 14px;font-weight:800;display:flex;align-items:center;justify-content:space-between;gap:10px}.detail-card>summary::-webkit-details-marker{display:none}.detail-card>summary:after{content:'＋';color:var(--muted);font-size:18px}.detail-card[open]>summary:after{content:'−'}.detail-body{border-top:1px solid var(--line);padding:14px}.detail-body .card{box-shadow:none}.top-status{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.header-meta{display:flex;gap:7px;align-items:center;flex-wrap:wrap;color:var(--muted);font-size:12px}.header-meta .strong{color:var(--text);font-weight:800}.recent-orders td{padding:7px 6px}.quiet{color:var(--muted);font-size:12px}.clean-section-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px}.clean-section-title h2{margin:0}.compact-kpi{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:7px}.compact-kpi>div{border:1px solid var(--line);border-radius:10px;padding:8px;background:#f9fafb}.compact-kpi b{display:block;font-size:16px;margin-top:2px}.system-info{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.system-info>div{border:1px solid var(--line);border-radius:10px;padding:9px;background:#f9fafb}.status-ribbon{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:8px}.status-ribbon .badge{padding:4px 9px}
@media(max-width:1050px){.strategy-visual-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.risk-grid{grid-template-columns:1fr}.compact-kpi{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media(max-width:560px){.strategy-visual-grid{grid-template-columns:1fr}.risk-mini-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.compact-kpi{grid-template-columns:repeat(2,minmax(0,1fr))}.system-info{grid-template-columns:1fr}.header-meta{line-height:1.5}.strategy-visual .sv-ret{font-size:22px}}

<style id="td-smart-visual-v347">
/* UI-only layer: no extra data source, network call, or chart library. */
.health-strip{grid-template-columns:repeat(5,minmax(0,1fr))}
.health-tile.gate-tile .small{font-weight:650}
.regime-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px}
.regime-card{border:1px solid var(--line);border-radius:11px;padding:10px;background:#fff}
.regime-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:7px}
.regime-score{font-size:21px;font-weight:850}
.regime-label{font-size:12px;color:var(--muted);margin-top:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gauge-wrap{position:relative}
.gauge-wrap .vbar{height:12px}
.gauge-limit-label{font-size:11px;color:var(--muted);margin-top:3px;text-align:right}
.validation-sub{font-size:11px;color:var(--muted);margin:-3px 0 7px 66px}
.flow-kpi{display:flex;align-items:center;justify-content:space-between;margin-top:9px;padding:8px 10px;border:1px solid var(--line);border-radius:10px;background:#fff}
.flow-kpi b{font-size:18px}
.strategy-visual .sv-caption{font-size:11px;color:var(--muted);margin-top:-1px;margin-bottom:6px}
.strategy-visual .paper-only{font-size:10px;color:#b45309;font-weight:800;margin-left:4px}
.risk-status-ok{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #a7f3d0;border-radius:11px;background:#ecfdf5;color:#047857;font-weight:800;margin-bottom:10px}
.risk-status-bad{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #fecaca;border-radius:11px;background:#fef2f2;color:#b91c1c;font-weight:800;margin-bottom:10px}
.exposure-grid{display:grid;grid-template-columns:1fr;gap:2px}
.order-timeline{display:grid;gap:8px}
.order-item{border:1px solid var(--line);border-radius:12px;background:#fff;padding:10px}
.order-top{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.order-time{font-weight:800;margin-right:auto}
.order-main{margin-top:7px;font-weight:750;line-height:1.45}
.order-sub{margin-top:4px;color:var(--muted);font-size:12px}
.order-reason{margin-top:6px}
.order-reason>summary{cursor:pointer;color:var(--muted);font-size:12px;font-weight:700}
.more-orders{margin-top:10px;border-top:1px dashed var(--line);padding-top:8px}
.more-orders>summary{cursor:pointer;font-weight:800;color:#4b5563}
@media(max-width:720px){
  .regime-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
  .regime-card{padding:8px}
  .regime-score{font-size:18px}
}
@media(max-width:560px){
  .health-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
  .health-tile.gate-tile{grid-column:span 2}
  .regime-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
  .regime-label{font-size:10px}
  .validation-sub{margin-left:60px}
  .strategy-visual .sv-ret{font-size:22px}
}
</style>
<style id="td-alert-ack-v348">
.alert-card{border:1px solid var(--line);border-left-width:5px;border-radius:13px;padding:12px;margin:9px 0;background:#fff}
.alert-card.warn{border-left-color:#d97706;background:#fffbeb}.alert-card.bad{border-left-color:#dc2626;background:#fef2f2}
.alert-card .alert-head{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.alert-card .alert-title{font-weight:850}.alert-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:9px}
.alert-actions form{margin:0}.alert-actions .btn{min-height:36px;padding:7px 11px}
.alert-detail{margin-top:8px}.alert-detail>summary{cursor:pointer;font-weight:750;color:var(--muted);font-size:13px}
.alert-detail .detail-text{margin-top:7px;line-height:1.5;color:#4b5563}
.ack-strip{border:1px solid #fde68a;background:#fffbeb;border-radius:11px;padding:9px 11px;margin-top:8px}
.ack-strip .ack-row{display:flex;justify-content:space-between;gap:8px;align-items:center;flex-wrap:wrap}
.ack-strip .ack-meta{font-size:12px;color:var(--muted)}.alert-help{font-size:12px;color:var(--muted);margin-top:4px}
@media(max-width:560px){.alert-actions .btn{width:auto;min-width:88px}.alert-card{padding:11px}}
</style>
</head>
<body>
<div class="wrap">
  <div class="top">
    <div class="title">
      <h1>통합 자동매매 대시보드</h1>
      <div class="header-meta">
        <span class="strong">v3.4.9</span>
        <span><?php echo td_h(strtoupper((string)td_get($brokerSummary,array('execution_mode'),'PAPER'))); ?></span>
        <span class="<?php echo td_h($visualOverallClass); ?>">● <?php echo td_h($visualOverallText); ?></span>
        <span><?php echo td_h(date('m-d H:i:s')); ?></span>
      </div>
    </div>
    <div class="row top-actions">
      <a class="btn" href="trade_export.php?strategy=unified&mode=download" target="_blank" rel="noopener">통합 분석자료</a>\n      <a class="btn secondary" href="trade_export.php?strategy=unified&mode=view" target="_blank" rel="noopener">JSON 보기</a>
      <details class="download-menu">
        <summary class="btn secondary">개별 분석자료 ▾</summary>
        <div class="download-panel" role="menu" aria-label="개별 분석자료 다운로드">
          <a class="btn secondary" role="menuitem" href="trade_export.php?strategy=dts&mode=download" target="_blank" rel="noopener">DTS 다운로드</a>
          <a class="btn secondary" role="menuitem" href="trade_export.php?strategy=abc&mode=download" target="_blank" rel="noopener">ABC 다운로드</a>
          <a class="btn secondary" role="menuitem" href="trade_export.php?strategy=das&mode=download" target="_blank" rel="noopener">DAS 다운로드</a>
          <a class="btn secondary" role="menuitem" href="trade_export.php?strategy=stc26&mode=download" target="_blank" rel="noopener">STC26 다운로드</a>
        </div>
      </details>
      <a class="btn secondary" href="#broker-admin">브로커 관리</a>
    </div>
  </div>

  <?php if ($actionResult !== null && $brokerActionName !== ''): ?>
    <script>try{if(window.history&&history.replaceState){history.replaceState(null,'',location.pathname+'#broker-work');}var bw=document.getElementById('broker-work');if(bw&&bw.scrollIntoView)bw.scrollIntoView({block:'start'});}catch(e){}<?php if($actionQueued): ?>setTimeout(function(){location.href=location.pathname+'#broker-work';},6000);<?php endif; ?></script>
  <?php elseif($actionResult !== null): ?>
    <div class="alert <?php echo $actionResult[0]?'ok':'bad'; ?>" style="margin:0 0 12px"><b><?php echo $actionResult[0]?'경고 확인':'경고 확인 실패'; ?></b><br><?php echo td_h((string)$actionResult[1]); ?></div>
  <?php endif; ?>

  <?php
    $evInstalled = is_array($engineVersionMatrix['installed'] ?? null) ? $engineVersionMatrix['installed'] : array();
    $evMismatch = is_array($engineVersionMatrix['mismatch'] ?? null) ? $engineVersionMatrix['mismatch'] : array();
    $evUnknown = is_array($engineVersionMatrix['unknown'] ?? null) ? $engineVersionMatrix['unknown'] : array();
    $evRows = is_array($engineVersionMatrix['strategies'] ?? null) ? $engineVersionMatrix['strategies'] : array();
  ?>
  <?php if (empty($evInstalled['version'])): ?>
    <div class="alert bad"><b>Engine 설치 버전 확인 실패</b> · <span class="mono"><?php echo td_h((string)($evInstalled['file'] ?? 'trade_engine.php')); ?></span>에서 TE_VERSION / TE_REV를 읽지 못했습니다.</div>
  <?php elseif ($evMismatch): ?>
    <div class="alert bad"><b>ENGINE VERSION MISMATCH</b> · 설치본 <span class="mono"><?php echo td_h((string)($evInstalled['short'] ?? '-')); ?></span>과 아직 다른 runtime 전략: <span class="mono"><?php echo td_h(implode(', ',array_map('strtoupper',$evMismatch))); ?></span><br>
      <span class="small"><?php foreach($evRows as $ek=>$er): if(($er['match']??null)!==false) continue; ?><?php echo td_h(strtoupper((string)$ek).' '.(string)($er['runtime_short']??'-')); ?> → <?php echo td_h((string)($er['installed_short']??'-')); ?> · <?php endforeach; ?>해당 전략 child가 새 Engine으로 한 번 완료되면 CURRENT로 전환됩니다.</span>
    </div>
  <?php elseif ($evUnknown): ?>
    <div class="alert warn"><b>Engine runtime 버전 일부 미확인</b> · 설치본 <span class="mono"><?php echo td_h((string)($evInstalled['short'] ?? '-')); ?></span> · 확인 불가: <span class="mono"><?php echo td_h(implode(', ',array_map('strtoupper',$evUnknown))); ?></span></div>
  <?php endif; ?>

  <div class="grid">
    <section class="card span12 command-card">
      <div class="clean-section-title">
        <h2>운영 현황</h2>
        <span class="quiet">핵심 실행 상태</span>
      </div>
      <div class="health-strip">
        <div class="health-tile <?php echo $visualRunnerHealthy?'ok':'bad'; ?>"><div class="health-line"><span class="health-dot"></span>Runner</div><div class="small"><?php echo $visualRunnerHealthy?'정상':'지연'; ?> · <?php echo is_numeric($visualRunnerAge)?td_num($visualRunnerAge,0).'초':'미확인'; ?></div></div>
        <div class="health-tile <?php echo $visualTransportBad?'bad':($visualTransportTransient||$visualTransportPending>0?'warn':'ok'); ?>"><div class="health-line"><span class="health-dot"></span>Transport</div><div class="small"><?php echo $visualTransportBad?'무결성 차단':($visualTransportPending>0?'대기 '.$visualTransportPending.'건':($visualTransportTransient?'재확인 중':'정상')); ?></div></div>
        <div class="health-tile <?php echo ($visualEngineTotal>0&&$visualEngineCurrent===$visualEngineTotal)?'ok':'warn'; ?>"><div class="health-line"><span class="health-dot"></span>Engine</div><div class="small"><?php echo td_num($visualEngineCurrent,0); ?>/<?php echo td_num($visualEngineTotal,0); ?> CURRENT</div></div>
        <div class="health-tile <?php echo ($visualTickStale>0||$visualTickUnknown>0)?'warn':'ok'; ?>"><div class="health-line"><span class="health-dot"></span>Strategy tick</div><div class="small"><?php echo ($visualTickStale===0&&$visualTickUnknown===0)?'정상':('지연 '.$visualTickStale.' · 미확인 '.$visualTickUnknown); ?></div></div>
        <div class="health-tile gate-tile <?php echo $visualGateActionable>0?'warn':($visualGateMarketWait>0?'info':'ok'); ?>"><div class="health-line"><span class="health-dot"></span>Order Gate</div><div class="small"><?php echo $visualGateActionable>0?('처리대상 '.$visualGateActionable.'건'):($visualGateMarketWait>0?(((int)($jpRequoteSummary['waiting']??0)>0?'JP ':'').'시장대기 '.$visualGateMarketWait.'건'):'대기 없음'); ?><?php if($visualMarketWaitDue>0&&$visualGateMarketWait>0): ?> · 다음 확인 <?php echo td_h(date('m/d H:i',$visualMarketWaitDue)); ?><?php endif; ?></div></div>
      </div>
      <div class="visual-grid">
        <div class="visual-box">
          <h3>시장 레짐</h3>
          <div class="regime-grid">
          <?php foreach(array('KR','US','JP') as $vmkt): $rg=is_array($visualRegimes[$vmkt]??null)?$visualRegimes[$vmkt]:array();$rscore=$rg['score']??null;$rclass=(string)($rg['class']??'muted'); ?>
            <div class="regime-card">
              <div class="regime-head"><b><?php echo td_h($vmkt); ?></b><span class="badge <?php echo td_h($rclass); ?>"><?php echo !empty($rg['blocked'])?'ENTRY BLOCK':td_h((string)($rg['code']??'UNKNOWN')); ?></span></div>
              <div class="regime-score"><?php echo is_numeric($rscore)?td_num($rscore,0):'-'; ?></div>
              <div class="vbar"><span class="vfill <?php echo td_h($rclass); ?>" style="width:<?php echo number_format(td_visual_width((float)($rscore??0),100.0),2,'.',''); ?>%"></span></div>
              <div class="regime-label"><?php echo td_h((string)($rg['label']??'자료 없음')); ?></div>
            </div>
          <?php endforeach; ?>
          </div>
        </div>

        <div class="visual-box">
          <h3>검증 진행</h3>
          <?php foreach($TD_STRATEGIES as $vk=>$vm): $vname=strtoupper((string)($vm['label']??$vk));$vr=$visualValidationByStrategy[$vname]??array();$vres=(int)($vr['resolved']??0);$vpend=(int)($vr['pending']??0);$vprog=td_visual_width($vres,TD_MIN_STAT_SAMPLE); ?>
            <div class="vrow"><span class="name"><?php echo td_h($vname); ?></span><div class="vbar"><span class="vfill <?php echo $vres>=TD_MIN_STAT_SAMPLE?'ok':'info'; ?>" style="width:<?php echo number_format($vprog,2,'.',''); ?>%"></span></div><b><?php echo td_num($vres,0); ?>/30</b></div>
            <div class="validation-sub">판정 대기 <?php echo td_num($vpend,0); ?>건</div>
          <?php endforeach; ?>
        </div>

        <div class="visual-box">
          <h3>시장 투자율 / 한도</h3>
          <?php foreach(array('KR','US','JP') as $vmkt): $vr=is_array($visualMarkets[$vmkt]??null)?$visualMarkets[$vmkt]:array();$vpct=(float)($vr['invest_pct']??0);$vlimit=(float)($vr['limit_pct']??80);$vw=td_visual_width($vpct,max(1.0,$vlimit)); ?>
            <div class="vrow"><span class="name"><?php echo td_h($vmkt); ?></span><div class="gauge-wrap"><div class="vbar"><span class="vfill <?php echo $vpct>$vlimit?'bad':($vpct>$vlimit*.85?'warn':'ok'); ?>" style="width:<?php echo number_format($vw,2,'.',''); ?>%"></span></div></div><b><?php echo td_num($vpct,1); ?>/<?php echo td_num($vlimit,0); ?>%</b></div>
          <?php endforeach; ?>
          <div class="visual-note">막대 끝 = 해당 시장 설정 한도</div>
        </div>

        <div class="visual-box">
          <h3>주문 파이프라인</h3>
          <div class="flow">
            <div class="flow-step"><span class="small">ACK 대기</span><b><?php echo td_num(td_get($brokerSummary,array('intent_spool','pending_ack_count'),0),0); ?></b></div><span class="flow-arrow">›</span>
            <div class="flow-step"><span class="small">Actionable</span><b><?php echo td_num($visualGateActionable,0); ?></b></div><span class="flow-arrow">›</span>
            <div class="flow-step"><span class="small">시장대기</span><b class="<?php echo $visualGateMarketWait>0?'info':''; ?>"><?php echo td_num(max($visualGateMarketWait,(int)($jpRequoteSummary['waiting']??0)),0); ?></b></div>
          </div>
          <div class="flow-kpi"><span>누적 체결</span><b class="ok"><?php echo td_num(td_get($pipeline,array('filled'),$orderCounts['filled']+$orderCounts['paper_filled']),0); ?></b></div>
          <div class="visual-note"><?php if($visualGateMarketWait>0&&$visualMarketWaitDue>0): ?>JP 시장대기 · <b>다음 확인</b> <?php echo td_h(date('m/d H:i',$visualMarketWaitDue)); ?><?php elseif($visualGateActionable>0): ?>처리 대상 주문이 있습니다.<?php else: ?>주문 정체 없음<?php endif; ?></div>
        </div>
      </div>
    </section>

    <section class="card span12">
      <div class="clean-section-title"><h2>4전략 한눈에</h2><span class="quiet">CORE 3 · CHALLENGER 1</span></div>
      <div class="strategy-visual-grid">
      <?php foreach($TD_STRATEGIES as $key=>$meta):
        $pf=is_array($strategyPerformance[$key]??null)?$strategyPerformance[$key]:array();
        $c=is_array($comparisonByKey[$key]??null)?$comparisonByKey[$key]:array();
        $ev=is_array($engineVersionMatrix['strategies'][$key]??null)?$engineVersionMatrix['strategies'][$key]:array();
        $eb=td_engine_match_badge($ev);$ret=$pf['average_market_return_pct']??null;$geo=td_visual_return_geometry($ret,15.0);
        $vname=strtoupper((string)($meta['label']??$key));$vr=$visualValidationByStrategy[$vname]??array();$vres=(int)($vr['resolved']??0);$vpend=(int)($vr['pending']??0);$vprog=td_visual_width($vres,TD_MIN_STAT_SAMPLE);
        $closed=(int)($pf['closed_trades']??$c['closed_trades']??0);$win=$pf['win_rate']??$c['win_rate']??null;
        $role=strtoupper((string)($meta['role']??($key==='stc26'?'CHALLENGER':'CORE')));
      ?>
        <div class="strategy-visual">
          <div class="sv-head"><div><b><?php echo td_h((string)$meta['label']); ?></b> <span class="small"><?php echo td_h($role); ?></span><?php if($key==='stc26'): ?><span class="paper-only">PAPER ONLY</span><?php endif; ?></div><span class="badge <?php echo td_h((string)$eb[1]); ?>"><?php echo td_h((string)$eb[0]); ?></span></div>
          <div class="sv-ret <?php echo td_ret_class($ret); ?>"><?php echo td_pct($ret); ?></div>
          <div class="sv-caption">시장계좌 평균 · 시장지수 수익률 아님</div>
          <div class="diverge"><span class="dbar <?php echo td_h($geo['class']); ?>" style="left:<?php echo number_format((float)$geo['left'],2,'.',''); ?>%;width:<?php echo number_format((float)$geo['width'],2,'.',''); ?>%"></span></div>
          <div class="metric-line"><span class="label">보유</span><div class="vbar"><span class="vfill info" style="width:<?php echo number_format(td_visual_width((float)($c['open_positions']??0),10.0),2,'.',''); ?>%"></span></div><span class="value"><?php echo td_num($c['open_positions']??0,0); ?></span></div>
          <div class="metric-line"><span class="label">완료 거래</span><div class="vbar"><span class="vfill <?php echo $closed>=30?'ok':'info'; ?>" style="width:<?php echo number_format(td_visual_width((float)$closed,30.0),2,'.',''); ?>%"></span></div><span class="value"><?php echo td_num($closed,0); ?></span></div>
          <div class="metric-line"><span class="label">승률</span><div class="vbar"><span class="vfill <?php echo is_numeric($win)&&$win>=50?'ok':'warn'; ?>" style="width:<?php echo number_format(td_visual_width((float)($win??0),100.0),2,'.',''); ?>%"></span></div><span class="value"><?php echo td_h(td_rate_text($win,1,$closed>0&&$closed<30)); ?></span></div>
          <div class="metric-line"><span class="label">검증</span><div class="vbar"><span class="vfill <?php echo $vres>=30?'ok':'info'; ?>" style="width:<?php echo number_format($vprog,2,'.',''); ?>%"></span></div><span class="value"><?php echo td_num($vres,0); ?>/30</span></div>
          <div class="validation-sub" style="margin-left:0">판정 대기 <?php echo td_num($vpend,0); ?>건</div>
        </div>
      <?php endforeach; ?>
      </div>
    </section>

    <section class="card span12">
      <div class="clean-section-title"><h2>위험·집중도</h2><span class="quiet">시장 사용률은 위 게이지에서 확인</span></div>
      <?php if($visualRiskViolationTotal===0): ?>
        <div class="risk-status-ok">✓ 위험 한도 초과 없음</div>
      <?php else: ?>
        <div class="risk-status-bad">⚠ 확인 필요한 위험 항목 <?php echo td_num($visualRiskViolationTotal,0); ?>건</div>
        <div class="risk-mini-grid">
          <?php if((int)($visualViolations['market']??0)>0): ?><div class="risk-mini"><span class="small">시장초과</span><b class="bad"><?php echo td_num($visualViolations['market'],0); ?></b></div><?php endif; ?>
          <?php if((int)($visualViolations['symbol']??0)>0): ?><div class="risk-mini"><span class="small">종목초과</span><b class="bad"><?php echo td_num($visualViolations['symbol'],0); ?></b></div><?php endif; ?>
          <?php if((int)($visualViolations['risk_group']??0)>0): ?><div class="risk-mini"><span class="small">위험그룹</span><b class="bad"><?php echo td_num($visualViolations['risk_group'],0); ?></b></div><?php endif; ?>
          <?php if((int)($visualViolations['correlation_group']??0)>0): ?><div class="risk-mini"><span class="small">상관초과</span><b class="bad"><?php echo td_num($visualViolations['correlation_group'],0); ?></b></div><?php endif; ?>
          <?php if((int)($visualViolations['repeated_expired']??0)>0): ?><div class="risk-mini"><span class="small">반복만료</span><b class="warn"><?php echo td_num($visualViolations['repeated_expired'],0); ?></b></div><?php endif; ?>
        </div>
      <?php endif; ?>

      <div class="visual-box">
        <h3>종목 노출 상위 · 한도 <?php echo td_num($visualSymbolLimit,0); ?>%</h3>
        <div class="exposure-grid">
        <?php if($visualTopSymbols): foreach(array_slice($visualTopSymbols,0,5) as $r): $p=(float)($r['invest_pct']??0); ?>
          <div class="metric-line"><span class="label"><?php echo td_h((string)($r['market']??'-')); ?> · <?php echo td_h(td_stock_label((string)($r['market']??''),(string)($r['symbol']??''),(string)($r['name']??''))); ?></span><div class="vbar"><span class="vfill <?php echo !empty($r['over_limit'])?'bad':($p>$visualSymbolLimit*.8?'warn':'ok'); ?>" style="width:<?php echo number_format(td_visual_width($p,$visualSymbolLimit),2,'.',''); ?>%"></span></div><span class="value"><?php echo td_pct($p); ?></span></div>
        <?php endforeach; else: ?><div class="quiet">보유 포지션 없음</div><?php endif; ?>
        </div>
      </div>
    </section>

    <?php if($visibleOperationalAlerts||$acknowledgedOperationalAlerts): ?>
    <section class="card span12" id="alerts">
      <?php if($visibleOperationalAlerts): ?>
      <div class="clean-section-title"><h2>확인 필요한 경고</h2><span class="badge warn"><?php echo td_num(count($visibleOperationalAlerts),0); ?>건</span></div>
      <div class="alert-help">확인은 경고를 삭제하지 않습니다. 사용자가 보았다는 상태만 기록하며 원인 감시는 계속됩니다.</div>
      <?php foreach($visibleOperationalAlerts as $a): ?>
        <div class="alert-card <?php echo td_h((string)($a['class']??'warn')); ?>">
          <div class="alert-head"><span class="alert-title"><?php echo td_h((string)($a['title']??'경고')); ?></span><span class="badge <?php echo td_h((string)($a['class']??'warn')); ?>">미확인</span></div>
          <details class="alert-detail"><summary>상세 보기</summary><div class="detail-text"><?php echo td_h((string)($a['detail']??'')); ?></div></details>
          <div class="alert-actions">
            <form method="post" action="#alerts">
              <input type="hidden" name="dashboard_action" value="ack_alert">
              <input type="hidden" name="alert_key" value="<?php echo td_h((string)($a['key']??'')); ?>">
              <input type="hidden" name="action_token" value="<?php echo td_h(td_action_token($TD_BASE_DIR,'ack_alert|'.(string)($a['key']??''))); ?>">
              <button class="btn warn" type="submit">확인</button>
            </form>
            <?php if(preg_match('/tick 지연$/u',(string)($a['title']??''))): ?><a class="btn secondary" href="trade_runner_control.php">Runner 상태</a><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>

      <?php if($acknowledgedOperationalAlerts): ?>
        <details class="more-orders"<?php echo $visibleOperationalAlerts?'':' open'; ?>>
          <summary>확인된 경고 <?php echo td_num(count($acknowledgedOperationalAlerts),0); ?>건 · 원인 감시 중</summary>
          <?php foreach($acknowledgedOperationalAlerts as $a): ?>
            <div class="ack-strip">
              <div class="ack-row"><b><?php echo td_h((string)($a['title']??'경고')); ?></b><span class="badge info">확인됨</span></div>
              <div class="ack-meta"><?php echo td_h((string)($a['acked_at']??'')); ?> · 정상화되면 자동 제거<?php echo strpos((string)($a['band']??''),'tick_')===0?' · 지연 단계가 악화되면 다시 경고':''; ?></div>
              <details class="alert-detail"><summary>상세 보기</summary><div class="detail-text"><?php echo td_h((string)($a['detail']??'')); ?></div></details>
            </div>
          <?php endforeach; ?>
        </details>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="card span12">
      <div class="clean-section-title"><h2>최근 주문</h2><span class="quiet">최근 5건 · 이전 주문 접기</span></div>
      <?php if($orders): ?>
      <div class="order-timeline">
      <?php foreach(array_slice($orders,0,5) as $o): $st=td_order_display_status($o);$sd=td_side_label($o['side']??'');$ots=strtotime((string)($o['created_at']??$o['time']??$o['updated_at']??''));$otxt=$ots?date('m/d H:i',$ots):(string)($o['created_at']??$o['time']??'-'); ?>
        <div class="order-item">
          <div class="order-top"><span class="order-time"><?php echo td_h($otxt); ?></span><span class="badge <?php echo td_h($st[1]); ?>"><?php echo td_h($st[0]); ?></span><span class="badge <?php echo td_h($sd[1]); ?>"><?php echo td_h($sd[0]); ?></span></div>
          <div class="order-main"><?php echo td_h(strtoupper((string)($o['strategy']??$o['strategy_key']??'-'))); ?> · <?php echo td_h((string)($o['market']??'-')); ?> · <?php echo td_h(td_stock_label((string)($o['market']??''),(string)($o['symbol']??''),(string)($o['name']??''))); ?></div>
          <div class="order-sub">수량 <?php echo td_num($o['qty']??$o['quantity']??null,0); ?> · 가격 <?php echo td_num($o['price']??$o['limit_price']??null,2); ?></div>
          <details class="order-reason"><summary>사유 보기</summary><div class="small" style="margin-top:5px"><?php echo td_h($o['reason']??$o['sell_reason']??$o['buy_reason']??'-'); ?></div></details>
        </div>
      <?php endforeach; ?>
      </div>
      <?php if(count($orders)>5): ?>
      <details class="more-orders">
        <summary>이전 주문 <?php echo td_num(min(7,max(0,count($orders)-5)),0); ?>건 보기</summary>
        <div class="order-timeline" style="margin-top:8px">
        <?php foreach(array_slice($orders,5,7) as $o): $st=td_order_display_status($o);$sd=td_side_label($o['side']??'');$ots=strtotime((string)($o['created_at']??$o['time']??$o['updated_at']??''));$otxt=$ots?date('m/d H:i',$ots):(string)($o['created_at']??$o['time']??'-'); ?>
          <div class="order-item">
            <div class="order-top"><span class="order-time"><?php echo td_h($otxt); ?></span><span class="badge <?php echo td_h($st[1]); ?>"><?php echo td_h($st[0]); ?></span><span class="badge <?php echo td_h($sd[1]); ?>"><?php echo td_h($sd[0]); ?></span></div>
            <div class="order-main"><?php echo td_h(strtoupper((string)($o['strategy']??$o['strategy_key']??'-'))); ?> · <?php echo td_h((string)($o['market']??'-')); ?> · <?php echo td_h(td_stock_label((string)($o['market']??''),(string)($o['symbol']??''),(string)($o['name']??''))); ?></div>
            <div class="order-sub">수량 <?php echo td_num($o['qty']??$o['quantity']??null,0); ?> · 가격 <?php echo td_num($o['price']??$o['limit_price']??null,2); ?></div>
            <details class="order-reason"><summary>사유 보기</summary><div class="small" style="margin-top:5px"><?php echo td_h($o['reason']??$o['sell_reason']??$o['buy_reason']??'-'); ?></div></details>
          </div>
        <?php endforeach; ?>
        </div>
      </details>
      <?php endif; ?>
      <?php else: ?><div class="quiet">주문 없음</div><?php endif; ?>
    </section>

    <details class="detail-card" id="validation-detail">
      <summary><span>검증 상세</span><span class="quiet">전체 버전 등록 <?php echo td_num($visualValidationTotals['registered']??0,0); ?> · 완료 <?php echo td_num($visualValidationTotals['resolved']??0,0); ?></span></summary>
      <div class="detail-body">
        <div class="scroll"><table><thead><tr><th>전략</th><th>현재 버전 등록/대기/완료</th><th>기준</th><th>방향 적중률</th><th>기대값</th><th>PF / MDD</th><th>선택/주문/체결</th></tr></thead><tbody>
        <?php foreach($TD_STRATEGIES as $key=>$meta): $name=strtoupper((string)$meta['label']);$row=is_array($visualValidationByStrategy[$name]??null)?$visualValidationByStrategy[$name]:array();$primary=is_array($row['primary']??null)?$row['primary']:array();$vp=is_array($row['pipeline']??null)?$row['pipeline']:array();$prov=!empty($row['metrics_provisional']); ?>
          <tr><td><b><?php echo td_h($name); ?></b></td><td><?php echo td_num($row['registered']??0,0); ?> / <?php echo td_num($row['pending']??0,0); ?> / <?php echo td_num($row['resolved']??0,0); ?></td><td><?php echo td_h((string)($row['primary_horizon']??'-')); ?></td><td><?php echo td_h(td_rate_text($primary['hit_rate_pct']??null,2,$prov)); ?></td><td><?php echo td_num($row['expectancy_pct']??$primary['avg_return_pct']??null,2); ?><?php echo is_numeric($row['expectancy_pct']??$primary['avg_return_pct']??null)?'%':''; ?></td><td><?php echo td_num($row['profit_factor']??null,2); ?> / <?php echo td_num($row['mdd_pct']??null,2); ?>%</td><td><?php echo td_h(td_rate_text($vp['selection_rate_pct']??null,1,false)); ?> / <?php echo td_h(td_rate_text($vp['order_rate_pct']??null,1,false)); ?> / <?php echo td_h(td_rate_text($vp['fill_rate_pct']??null,1,false)); ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?php $pairs=is_array($commonValidation['independence']['pairs']??null)?$commonValidation['independence']['pairs']:array(); if($pairs): ?>
        <details class="advanced-actions"><summary>전략 독립성</summary><div class="scroll" style="margin-top:10px"><table><thead><tr><th>전략쌍</th><th>표본</th><th>중복률</th><th>수익상관</th><th>Incremental Expectancy</th></tr></thead><tbody><?php foreach($pairs as $pair): if(!is_array($pair))continue;$pairN=(int)($pair['paired_sample_count']??$pair['return_pairs']??0);$pairProv=!empty($pair['metrics_provisional']); ?><tr><td><?php echo td_h((string)($pair['a']??'-').' ↔ '.(string)($pair['b']??'-')); ?></td><td><?php echo td_num($pairN,0); ?>/30</td><td><?php echo td_h(td_rate_text($pair['overlap_rate_pct']??null,2,$pairProv)); ?></td><td><?php echo td_num($pair['correlation']??null,3); ?></td><td><?php echo td_num($pair['incremental_expectancy']??null,3); ?></td></tr><?php endforeach; ?></tbody></table></div></details>
        <?php endif; ?>
        <?php if(!empty($labResearch['available'])&&!empty($labResearch['metrics'])): ?><details class="advanced-actions"><summary>LAB Research</summary><div class="quiet" style="margin-top:8px">RESEARCH_ONLY · Broker 직접 연결 없음 · 실험 <?php echo td_num(count($labResearch['metrics']),0); ?>건</div></details><?php endif; ?>
      </div>
    </details>

    <details class="detail-card" id="performance-detail">
      <summary><span>성과 상세 분석</span><span class="quiet">성공·실패 원인 / 평균 이익·손실</span></summary>
      <div class="detail-body"><div class="scroll"><table><thead><tr><th>전략</th><th>시장 평균</th><th>완료/승률</th><th>평균 이익/손실</th><th>손익비</th><th>성공 요인</th><th>실패 요인</th></tr></thead><tbody>
      <?php foreach($TD_STRATEGIES as $key=>$meta): $pf=is_array($strategyPerformance[$key]??null)?$strategyPerformance[$key]:array(); ?>
        <tr><td><b><?php echo td_h((string)$meta['label']); ?></b></td><td class="<?php echo td_ret_class($pf['average_market_return_pct']??null); ?>"><?php echo td_pct($pf['average_market_return_pct']??null); ?></td><td><?php echo td_num($pf['closed_trades']??0,0); ?> / <?php echo td_h(td_rate_text($pf['win_rate']??null,1,!empty($pf['metrics_provisional']))); ?></td><td><span class="pos"><?php echo td_pct($pf['avg_win_pct']??null); ?></span> / <span class="neg"><?php echo td_pct($pf['avg_loss_pct']??null); ?></span></td><td><?php echo is_numeric($pf['payoff_ratio']??null)?number_format((float)$pf['payoff_ratio'],2):'-'; ?></td><td><?php echo td_h((string)($pf['success_summary']??'자료 부족')); ?></td><td><?php echo td_h((string)($pf['failure_summary']??'자료 부족')); ?></td></tr>
      <?php endforeach; ?></tbody></table></div></div>
    </details>

    <details class="detail-card" id="broker-admin">
      <summary><span>브로커 관리·진단</span><span class="quiet">EVENT · ACK <?php echo td_num(td_get($brokerSummary,array('intent_spool','pending_ack_count'),0),0); ?> · 활성 <?php echo td_num(td_get($pipeline,array('active_buy_orders'),$orderCounts['active']),0); ?></span></summary>
      <div class="detail-body" id="broker-work">
        <?php
          $expiredCleanupUi=(int)td_get($pipeline,array('expired'),$orderCounts['expired']);
          $rejectedCleanupUi=(int)td_get($pipeline,array('rejected'),$orderCounts['rejected']);
          $cancelledCleanupUi=(int)td_get($pipeline,array('cancelled'),($orderCounts['cancelled']??0));
          $terminalCleanupUi=max(0,$expiredCleanupUi+$rejectedCleanupUi+$cancelledCleanupUi);
          $runnerAgeUi=td_get($brokerSummary,array('runner','daemon_heartbeat_age_sec'),null);
          $cycleAgeUi=td_get($brokerSummary,array('cycle_age_sec'),null);
        ?>
        <div class="compact-kpi">
          <div><span class="small">모드</span><b><?php echo td_h(strtoupper((string)td_get($brokerSummary,array('execution_mode'),'unknown'))); ?></b></div>
          <div><span class="small">Runner</span><b class="<?php echo $visualRunnerHealthy?'ok':'bad'; ?>"><?php echo is_numeric($runnerAgeUi)?td_num($runnerAgeUi,0).'초':'-'; ?></b></div>
          <div><span class="small">Broker</span><b class="<?php echo $visualGateMarketWait>0?'info':'ok'; ?>"><?php echo $visualGateMarketWait>0?'시장대기':(is_numeric($cycleAgeUi)?td_num($cycleAgeUi,0).'초':'-'); ?></b></div>
          <div><span class="small">ACK</span><b><?php echo td_num(td_get($brokerSummary,array('intent_spool','pending_ack_count'),0),0); ?></b></div>
          <div><span class="small">활성주문</span><b><?php echo td_num(td_get($pipeline,array('active_buy_orders'),$orderCounts['active']),0); ?></b></div>
          <div><span class="small">정리대상</span><b class="<?php echo $terminalCleanupUi>0?'warn':'ok'; ?>"><?php echo td_num($terminalCleanupUi,0); ?></b></div>
        </div>
        <?php if($actionResult!==null&&$brokerActionName==='broker_status'): ?><div class="alert <?php echo $actionResult[0]?'ok':'bad'; ?>"><b>상태 점검 결과</b><br><pre class="mono" style="white-space:pre-wrap;margin:6px 0 0"><?php echo td_h($actionResult[1]); ?></pre></div><?php endif; ?>
        <div class="row action-row" style="margin-top:10px"><form method="get" action=""><input type="hidden" name="broker_action" value="broker_status"><input type="hidden" name="action_token" value="<?php echo td_h(td_action_token($TD_BASE_DIR,'broker_status')); ?>"><button class="btn secondary" type="submit">상태 점검</button></form>
          <form method="post" action="trade_broker.php" target="td_broker_cleanup_sink" onsubmit="if(!confirm('EXPIRED 종료자료 <?php echo td_num($expiredCleanupUi,0); ?>건을 archive로 정리하시겠습니까?'))return false;"><input type="hidden" name="action" value="cleanup_expired"><button class="btn warn" type="submit"<?php echo $expiredCleanupUi<=0?' disabled':''; ?>>EXPIRED 정리 (<?php echo td_num($expiredCleanupUi,0); ?>)</button></form>
          <form method="post" action="trade_broker.php" target="td_broker_cleanup_sink" onsubmit="if(!confirm('종료 오류자료 <?php echo td_num($terminalCleanupUi,0); ?>건을 archive로 정리하시겠습니까?'))return false;"><input type="hidden" name="action" value="cleanup_terminal"><button class="btn bad" type="submit"<?php echo $terminalCleanupUi<=0?' disabled':''; ?>>종료 오류 정리 (<?php echo td_num($terminalCleanupUi,0); ?>)</button></form>
        </div><iframe name="td_broker_cleanup_sink" title="broker cleanup sink" style="display:none"></iframe>
        <div class="quiet" style="margin-top:10px">Runtime <span class="mono"><?php echo td_h((string)td_get($brokerSummary,array('runtime_path'),td_broker_runtime($TD_BASE_DIR))); ?></span> · Authority <?php echo td_h((string)td_get($brokerSummary,array('runtime_authority'),td_runtime_context($TD_BASE_DIR)['authority'])); ?> · Broker <?php echo td_h((string)td_get($brokerSummary,array('broker_version'),'-')); ?></div>
      </div>
    </details>

    <details class="detail-card" id="system-info">
      <summary><span>시스템 정보</span><span class="quiet">버전 / runtime / 동결 기준</span></summary>
      <div class="detail-body"><div class="system-info">
        <div><span class="small">Dashboard</span><br><b>v3.4.9</b><br><span class="mono small"><?php echo td_h(TD_REV); ?></span></div>
        <div><span class="small">Engine</span><br><b><?php echo td_h((string)($engineVersionMatrix['installed']['short']??'-')); ?></b><br><span class="mono small"><?php echo td_h((string)($engineVersionMatrix['installed']['rev']??'-')); ?></span></div>
        <div><span class="small">Authority</span><br><b><?php echo td_h((string)td_get($brokerSummary,array('runtime_authority'),'-')); ?></b><br><span class="small">REAL=false · Low-Load Runner</span></div>
        <div><span class="small">Broker runtime</span><br><span class="mono small"><?php echo td_h((string)td_get($brokerSummary,array('runtime_path'),'-')); ?></span></div>
      </div></div>
    </details>
  </div>

  <div class="foot">
    <p>저부하 통합 관제판 · 기존 runtime 스냅샷만 시각화 · 추가 종목 스캔/외부 호출 없음</p>
    <p class="mono"><?php echo td_h(TD_REV); ?></p>
  </div>
</div>
</body>
</html>