<?php
/**
 * dts.php
 * DTS v25.4 — market-timezone 1m freshness · hard-stale auto-refresh contract
 * 다운로드/보관 파일명: dts_v254.php
 * PHP 7.4 compatible
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
const DTS_VERSION='v25.4 CORE · INTRADAY_TREND · HARD-STALE-AUTO-RECOVERY · MARKET-TIMEZONE';
const DTS_REV='dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1';
const DTS_MIN_BARS=245;
const DTS_MAX_BAR_AGE_SEC=180;
const DTS_MAX_BAR_AGE_KR_SEC=300;   // soft warning: 5m
const DTS_MAX_BAR_AGE_US_SEC=300;   // soft warning: 5m
const DTS_MAX_BAR_AGE_JP_SEC=1200;  // soft warning: 20m
const DTS_HARD_BAR_AGE_KR_SEC=1800; // hard block: 30m
const DTS_HARD_BAR_AGE_US_SEC=1800; // hard block: 30m
const DTS_HARD_BAR_AGE_JP_SEC=3600; // hard block: 60m
const DTS_PRE_DEAD_GAP_PCT=0.05;

function dts_meta(): array{return['strategy_id'=>'DTS','strategy_version'=>'25.4','strategy_status'=>'CORE','alpha_type'=>'INTRADAY_TREND','file'=>'dts.php','code_rev'=>DTS_REV,'market_support'=>['KR','US','JP'],'cron_seconds'=>60,'entry_contract'=>'CROSS_BASED','exit_contract'=>'CROSS_BASED','required_engine_capabilities'=>['completed_1m_bars','entry_guard_callback','cross_based_entry_contract','allocation_only_sizing','strategy_1min_cron','intraday_data_date_basis','strategy_scan_date_mode','per_batch_admission','one_minute_cache_resilience','hard_stale_exit_refresh_recheck','fresh_quote_fallback','tick_cycle_telemetry','common_forward_validation']];}
function dts_strategy_spec(): array{return te_strategy_spec([
    'app_key'=>'dts','app_name'=>'DTS Intraday Trend','app_ver'=>DTS_VERSION,'strategy_rev'=>DTS_REV,'strategy_version'=>'25.4','strategy_status'=>'CORE','alpha_type'=>'INTRADAY_TREND','strategy_parameters'=>['ma_fast'=>5,'ma_slow'=>240,'soft_bar_age_by_market'=>['KR'=>DTS_MAX_BAR_AGE_KR_SEC,'US'=>DTS_MAX_BAR_AGE_US_SEC,'JP'=>DTS_MAX_BAR_AGE_JP_SEC],'hard_bar_age_by_market'=>['KR'=>DTS_HARD_BAR_AGE_KR_SEC,'US'=>DTS_HARD_BAR_AGE_US_SEC,'JP'=>DTS_HARD_BAR_AGE_JP_SEC],'stale_policy'=>'SOFT_WARN_HARD_BLOCK','pre_dead_gap_pct'=>DTS_PRE_DEAD_GAP_PCT],
    'model_callback'=>'dts_model','exit_callback'=>'dts_exit_model','entry_guard_callback'=>'dts_entry_guard','opportunity_callback'=>'dts_opportunity_candidate','meta_callback'=>'dts_meta',
    'required_engine_capabilities'=>dts_meta()['required_engine_capabilities'],'scan_limit'=>5,'scan_limit_by_market'=>['KR'=>5,'US'=>5,'JP'=>5],
    'requirements'=>['1m'=>['range'=>'5d','ranges'=>['1d','5d'],'interval'=>'1m','min_bars'=>DTS_MIN_BARS,'complete_only'=>true]],
    'entry_contract'=>'CROSS_BASED','sizing_mode'=>'ALLOCATION_ONLY','expected_cycle_sec'=>60,'min_entry_rr'=>0.0,'intent_ttl_auto'=>600,'daily_opportunity_policy'=>['enabled'=>true,'target_buys_per_market'=>1,'allocation_scale'=>0.20,'max_soft_attempts_per_market'=>1],
    'validation'=>[
        'enabled'=>true,'signals'=>['BUY','SELL'],'anchor_timeframe'=>'1m','primary_horizon'=>'60m','minimum_usable_samples'=>30,
        'horizons'=>[
            ['type'=>'MINUTES','value'=>30,'label'=>'30m'],
            ['type'=>'MINUTES','value'=>60,'label'=>'60m'],
            ['type'=>'SESSION_CLOSE','value'=>0,'label'=>'close'],
        ],
    ],
    'data_date_timeframe'=>'1m','scan_date_mode'=>'SESSION_DATE','benchmark_gate_required'=>false,
    'admission_mode'=>'PER_BATCH','candidate_basis'=>'INTRADAY_ROTATING_1M',
    'risk_policy'=>['initial_stop'=>false,'hard_target'=>false,'loss_cut_ladder'=>false,'engine_trailing'=>false,'max_holding'=>false,'portfolio_daily_loss'=>true,'daily_loss_liquidate'=>false,'portfolio_drawdown'=>true,'portfolio_drawdown_liquidate'=>false],
]);}
$engine=__DIR__.'/trade_engine.php';if(!is_file($engine)){if(!headers_sent())header('Content-Type: text/plain; charset=UTF-8');echo"trade_engine.php 파일이 필요합니다.\n";exit;}require_once$engine;if(!defined('DTS_TEST_MODE'))te_run(dts_strategy_spec());

function dts_model(array $ctx,array $state): array
{
    $quote=te_model_num($ctx['quote']['price']??0);$bars=te_model_bars($ctx,'1m');
    if($quote<=0||count($bars)<DTS_MIN_BARS)return dts_signal('FILTERED','DATA_SHORT',0,$quote,'현재가 또는 완결 1분봉 245개 미만','DATA_SHORT',$state,['bars'=>count($bars)]);
    $snap=dts_snapshot($bars);if(empty($snap['ok']))return dts_signal('FILTERED','MA_SHORT',0,$quote,'1분봉 MA5/240 계산 부족','MA_SHORT',$state,$snap);
    $fresh=dts_bar_fresh($bars,dts_bar_max_age($ctx),dts_bar_hard_max_age($ctx),(string)($ctx['market']??'KR'));$gold=$snap['prev_ma5']<=$snap['prev_ma240']&&$snap['ma5']>$snap['ma240'];$quoteAbove=$quote>$snap['ma5'];
    $metrics=array_merge($snap,['gold_cross'=>$gold,'quote_above_ma5'=>$quoteAbove,'bar_fresh'=>$fresh['fresh'],'bar_soft_stale'=>$fresh['soft_stale'],'bar_hard_stale'=>$fresh['hard_stale'],'bar_freshness_state'=>$fresh['state'],'bar_age_sec'=>$fresh['age_sec'],'bar_time'=>$fresh['time'],'bar_age_limit_sec'=>$fresh['soft_max_age_sec'],'bar_hard_age_limit_sec'=>$fresh['hard_max_age_sec'],'bar_freshness_policy'=>$fresh['policy'],'decision_bar'=>'COMPLETED_1M_CLOSE','entry_contract'=>'CROSS_BASED','sizing_mode'=>'ALLOCATION_ONLY','expected_cycle_sec'=>60,'strategy_label'=>'DTS']);
    if($fresh['hard_stale'])return dts_signal('FILTERED','ONE_MINUTE_DATA_TOO_OLD',0,$quote,'최근 완결 1분봉이 허용 상한을 초과함','ONE_MINUTE_DATA_TOO_OLD',$state,$metrics);
    if($gold&&$quoteAbove){$sig=dts_signal('BUY','MA5_MA240_GOLD_CROSS',100,$quote,'완결 1분봉 MA5가 MA240 상향 돌파','',$state,$metrics);if($fresh['soft_stale']){$sig['warning_code']='ONE_MINUTE_DATA_STALE_WARNING';$sig['warning_reason']='1분봉이 권고 신선도보다 늦지만 허용 상한 이내이므로 신호 판단 계속';}return $sig;}
    if($gold){$sig=dts_signal('WATCH','GOLD_CROSS_QUOTE_BELOW_MA5',0,$quote,'골든크로스 발생했으나 현재가가 1분봉 MA5 이하','ENTRY_BELOW_OR_EQUAL_MA5',$state,$metrics);if($fresh['soft_stale']){$sig['warning_code']='ONE_MINUTE_DATA_STALE_WARNING';$sig['warning_reason']='1분봉 지연은 비차단 경고';}return $sig;}
    if($snap['ma5']>$snap['ma240']){$sig=dts_signal('WATCH','MA5_ABOVE_MA240_CONTINUES',0,$quote,'1분봉 MA5>MA240 유지 · 신규 골든크로스 아님','NO_NEW_GOLD_CROSS',$state,$metrics);if($fresh['soft_stale']){$sig['warning_code']='ONE_MINUTE_DATA_STALE_WARNING';$sig['warning_reason']='1분봉 지연은 비차단 경고';}return $sig;}
    return dts_signal('FILTERED','MA5_BELOW_MA240',0,$quote,'1분봉 MA5≤MA240','FAST_TREND_INACTIVE',$state,$metrics);
}
function dts_entry_guard(array $ctx,array $signal,array $candidate=[]): array
{
    $quote=te_model_num($ctx['quote']['price']??0);$bars=te_model_bars($ctx,'1m');if($quote<=0||count($bars)<DTS_MIN_BARS)return['ok'=>false,'reason'=>'DTS_GUARD_DATA_SHORT','signal'=>$signal];
    $fresh=dts_bar_fresh($bars,dts_bar_max_age($ctx),dts_bar_hard_max_age($ctx),(string)($ctx['market']??'KR'));if($fresh['hard_stale'])return['ok'=>false,'reason'=>'DTS_GUARD_1M_TOO_OLD','signal'=>$signal];
    $s=dts_snapshot($bars);
    $opp=!empty($signal['metrics']['daily_opportunity'])||!empty($candidate['daily_opportunity'])||!empty($candidate['metrics']['daily_opportunity']);
    if($opp){
        if(empty($s['ok'])||!($s['ma5']>$s['ma240']))return['ok'=>false,'reason'=>'DTS_OPPORTUNITY_FAST_TREND_INACTIVE','signal'=>$signal];
        if($quote<=$s['ma5'])return['ok'=>false,'reason'=>'DTS_OPPORTUNITY_QUOTE_BELOW_MA5','signal'=>$signal];
        $signal['status']='BUY';$signal['type']='DTS_DAILY_OPPORTUNITY_TREND_ENTRY';$signal['entry_price']=$quote;$signal['stop_price']=0.0;$signal['target_price']=0.0;
        $signal['metrics']=array_merge(is_array($signal['metrics']??null)?$signal['metrics']:[],$s,['daily_opportunity'=>true,'ms7_stage'=>'M2','allocation_scale'=>(float)($signal['metrics']['allocation_scale']??0.20),'bar_freshness_state'=>$fresh['state']]);
        return['ok'=>true,'reason'=>'OK_DAILY_OPPORTUNITY','signal'=>$signal];
    }
    if(empty($s['ok'])||!($s['prev_ma5']<=$s['prev_ma240']&&$s['ma5']>$s['ma240']))return['ok'=>false,'reason'=>'DTS_GUARD_NO_GOLD_CROSS','signal'=>$signal];
    if($quote<=$s['ma5'])return['ok'=>false,'reason'=>'ENTRY_BELOW_OR_EQUAL_MA5','signal'=>$signal];
    $signal['entry_price']=$quote;$signal['stop_price']=0.0;$signal['target_price']=0.0;$signal['metrics']=array_merge(is_array($signal['metrics']??null)?$signal['metrics']:[],$s,['entry_quote_price'=>$quote,'bar_age_sec'=>$fresh['age_sec'],'bar_age_limit_sec'=>$fresh['soft_max_age_sec'],'bar_hard_age_limit_sec'=>$fresh['hard_max_age_sec'],'bar_freshness_state'=>$fresh['state'],'bar_freshness_policy'=>$fresh['policy']]);if($fresh['soft_stale']){$signal['warning_code']='ONE_MINUTE_DATA_STALE_WARNING';$signal['warning_reason']='1분봉 지연은 비차단 경고 · 주문가격 신선도는 공통 Engine/Broker 검증에 위임';}
    return['ok'=>true,'reason'=>'OK','signal'=>$signal];
}
function dts_opportunity_candidate(array $row,array $context=[]): array
{
    $m=is_array($row['metrics']??null)?array_merge($row['metrics'],$row):$row;
    if(!empty($m['bar_hard_stale']))return['eligible'=>false];
    $data=strtoupper((string)($m['data_code']??'OK'));if(!in_array($data,['OK','DATA_DELAYED'],true))return['eligible'=>false];
    $ma5=(float)($m['ma5']??0);$ma240=(float)($m['ma240']??0);$price=(float)($row['price']??$row['entry_price']??0);
    if($ma5<=0||$ma240<=0||$ma5<=$ma240||$price<=$ma5)return['eligible'=>false];
    $gap=abs((float)($m['gap_pct']??0));$score=max(60.0,min(92.0,82.0-$gap*8.0));
    return['eligible'=>true,'score'=>$score,'signal_type'=>'DTS_DAILY_OPPORTUNITY_TREND_ENTRY','reason'=>'신규 골든크로스는 없지만 MA5>MA240 추세가 유지되는 최상위 후보 소액 진입','metrics'=>['daily_opportunity'=>true,'ms7_stage'=>'M2','opportunity_basis'=>'TREND_CONTINUATION_NO_NEW_CROSS']];
}
function dts_close_exit_window(array $ctx): bool
{
    $market=strtoupper((string)($ctx['market']??''));$status=is_array($ctx['market_status']??null)?$ctx['market_status']:[];$k=strtolower($market);$now=(string)($status[$k.'_time']??'');$close=(string)($status[$k.'_close_time']??'');
    if($now===''||$close==='')return false;$hm=substr($now,-5);if(!preg_match('/^\d{2}:\d{2}$/',$hm)||!preg_match('/^\d{2}:\d{2}$/',$close))return false;
    $toMin=static function(string $v): int{$p=explode(':',$v);return((int)$p[0])*60+(int)$p[1];};$n=$toMin($hm);$c=$toMin($close);return$n>=($c-10)&&$n<$c;
}
function dts_exit_model(array $ctx,array $position,array $state): array
{
    if(dts_close_exit_window($ctx))return['action'=>'SELL','code'=>'SESSION_TIME_EXIT','reason'=>'장 마감 10분 전 당일 포지션 시간종료','urgent_exit'=>false];
    $bars=te_model_bars($ctx,'1m');if(count($bars)<DTS_MIN_BARS)return['action'=>'HOLD','code'=>'DATA_SHORT','reason'=>'완결 1분봉 부족'];
    $fresh=dts_bar_fresh($bars,dts_bar_max_age($ctx),dts_bar_hard_max_age($ctx),(string)($ctx['market']??'KR'));if($fresh['hard_stale'])return['action'=>'HOLD','code'=>'ONE_MINUTE_DATA_TOO_OLD','reason'=>'1분봉이 허용 상한을 초과하여 기술적 매도 판단 보류'];
    $s=dts_snapshot($bars);if(empty($s['ok']))return['action'=>'HOLD','code'=>'MA_SHORT','reason'=>'MA5/240 계산 부족'];
    if($s['ma5']<$s['ma240'])return['action'=>'SELL','code'=>'MA5_MA240_DEAD_CROSS','reason'=>'완결 1분봉 MA5가 MA240 아래','urgent_exit'=>false]+$s;
    if(dts_pre_dead($s))return['action'=>'SELL','code'=>'MA5_MA240_PRE_DEAD','reason'=>'MA5·MA240 이격 0.05% 이하 · 3봉 연속 축소 · MA5 하락','urgent_exit'=>false]+$s;
    return['action'=>'HOLD','code'=>'MA5_ABOVE_MA240','reason'=>'MA5가 MA240 위']+$s;
}
function dts_snapshot(array $bars): array
{
    $cl=te_model_field($bars,'close');$s5=te_model_sma_series($cl,5);$s240=te_model_sma_series($cl,240);$i=count($cl)-1;if($i<2)return['ok'=>false];
    foreach([$i,$i-1,$i-2,$i-3]as$j)if(!is_numeric($s5[$j]??null)||!is_numeric($s240[$j]??null))return['ok'=>false];
    $gap=[];foreach([$i,$i-1,$i-2,$i-3]as$j)$gap[]=abs(((float)$s5[$j]/(float)$s240[$j]-1.0)*100.0);
    return['ok'=>true,'ma5'=>(float)$s5[$i],'ma240'=>(float)$s240[$i],'prev_ma5'=>(float)$s5[$i-1],'prev_ma240'=>(float)$s240[$i-1],'prev2_ma5'=>(float)$s5[$i-2],'prev2_ma240'=>(float)$s240[$i-2],'gap_pct'=>$gap[0],'gap_prev1_pct'=>$gap[1],'gap_prev2_pct'=>$gap[2],'gap_prev3_pct'=>$gap[3],'ma5_slope_down'=>(float)$s5[$i]<(float)$s5[$i-1]];
}
function dts_pre_dead(array $s): bool{return($s['ma5']??0)>($s['ma240']??0)&&($s['gap_pct']??999)<=DTS_PRE_DEAD_GAP_PCT&&($s['gap_pct']??999)<($s['gap_prev1_pct']??-1)&&($s['gap_prev1_pct']??999)<($s['gap_prev2_pct']??-1)&&!empty($s['ma5_slope_down']);}
function dts_bar_max_age(array $ctx): int
{
    $market=strtoupper((string)($ctx['market']??''));
    $defaults=['KR'=>DTS_MAX_BAR_AGE_KR_SEC,'US'=>DTS_MAX_BAR_AGE_US_SEC,'JP'=>DTS_MAX_BAR_AGE_JP_SEC];
    $limit=(int)($defaults[$market]??DTS_MAX_BAR_AGE_SEC);

    // trade_engine v4.3.4 already classifies delayed quotes by market.
    // Keep DTS 1m-bar freshness inside the same scan window so JP's normal
    // ~15 minute delayed analysis feed reaches the broker live-requote stage
    // instead of being discarded prematurely as ONE_MINUTE_DATA_STALE.
    $engineScan=(int)($ctx['meta']['quote_scan_limit_sec']??0);
    if($engineScan>0)$limit=min($limit,max(DTS_MAX_BAR_AGE_SEC,$engineScan));
    return max(DTS_MAX_BAR_AGE_SEC,$limit);
}
function dts_bar_hard_max_age(array $ctx): int
{
    $market=strtoupper((string)($ctx['market']??''));
    $limits=['KR'=>DTS_HARD_BAR_AGE_KR_SEC,'US'=>DTS_HARD_BAR_AGE_US_SEC,'JP'=>DTS_HARD_BAR_AGE_JP_SEC];
    return (int)($limits[$market]??DTS_HARD_BAR_AGE_KR_SEC);
}
function dts_bar_ts(array $bar,string $market): int
{
    $ts=(int)($bar['ts']??0);if($ts>0)return$ts;$raw=(string)($bar['time']??$bar['datetime']??'');if($raw==='')return 0;
    try{$tz=function_exists('te_market_timezone')?te_market_timezone($market):($market==='US'?'America/New_York':($market==='JP'?'Asia/Tokyo':'Asia/Seoul'));$d=new DateTime($raw,new DateTimeZone($tz));return$d->getTimestamp();}catch(Throwable $e){return 0;}
}
function dts_bar_fresh(array $bars,int $maxAge,int $hardMaxAge=0,string $market='KR'): array
{
    $b=$bars[count($bars)-1]??[];$ts=dts_bar_ts($b,strtoupper($market));
    $age=$ts>0?max(0,time()-$ts):PHP_INT_MAX;
    if($hardMaxAge<=0)$hardMaxAge=max($maxAge,DTS_HARD_BAR_AGE_KR_SEC);
    $fresh=$ts>0&&$age<=$maxAge;
    $hardStale=$ts<=0||$age>$hardMaxAge;
    $softStale=!$fresh&&!$hardStale;
    $state=$hardStale?'HARD_STALE':($softStale?'SOFT_STALE_WARNING':'FRESH');
    return['fresh'=>$fresh,'soft_stale'=>$softStale,'hard_stale'=>$hardStale,'state'=>$state,'age_sec'=>$age,'time'=>(string)($b['time']??''),'soft_max_age_sec'=>$maxAge,'hard_max_age_sec'=>$hardMaxAge,'max_age_sec'=>$maxAge,'policy'=>'SOFT_WARN_HARD_BLOCK'];
}
function dts_signal(string $status,string $type,int $score,float $entry,string $reason,string $block,array $state,array $metrics): array{return['status'=>$status,'type'=>$type,'score'=>$score,'entry_price'=>$entry,'stop_price'=>0.0,'target_price'=>0.0,'reason'=>$reason,'block_reason'=>$block,'state'=>$state,'metrics'=>$metrics];}