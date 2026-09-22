<?php
/**
 * abc.php
 * ABC v54.2 — market-timezone bar-date · 5-day reset · MA5/20 dead-cross exit
 * 다운로드/보관 파일명: abc_v542.php
 * PHP 7.4 compatible
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');

const ABC_VERSION='v54.2 CORE · PULLBACK_CONTINUATION · MARKET-TIMEZONE · DAILY-OPPORTUNITY';
const ABC_REV='abc-v542-market-timezone-bar-date-20260912-r1';
const ABC_RESET_MIN_DAYS=5;
const ABC_RESET_LOOKBACK_MAX=60;

function abc_meta(): array
{
    return [
        'strategy_id'=>'ABC','strategy_version'=>'54.2','strategy_status'=>'CORE','alpha_type'=>'PULLBACK_CONTINUATION','file'=>'abc.php','code_rev'=>ABC_REV,
        'market_support'=>['KR','US','JP'],'entry_contract'=>'CROSS_BASED','exit_contract'=>'CROSS_BASED',
        'entry_rule'=>'LONG_ALIGNMENT_AND_MA5_LE_MA20_FOR_5D_THEN_FULL_ALIGNMENT_REFORMATION',
        'exit_rule'=>'COMPLETED_DAILY_MA5_LT_MA20',
        'reset_min_days'=>ABC_RESET_MIN_DAYS,
        'required_engine_capabilities'=>['completed_daily_bars','entry_guard_callback','cross_based_entry_contract','allocation_only_sizing','common_forward_validation'],
    ];
}
function abc_strategy_spec(): array
{
    return te_strategy_spec([
        'app_key'=>'abc','app_name'=>'ABC Pullback Continuation','app_ver'=>ABC_VERSION,'strategy_rev'=>ABC_REV,'strategy_version'=>'54.2','strategy_status'=>'CORE','alpha_type'=>'PULLBACK_CONTINUATION','strategy_parameters'=>['trend_ma'=>[20,60,120,200],'pullback_fast_ma'=>5,'pullback_min_days'=>ABC_RESET_MIN_DAYS],
        'model_callback'=>'abc_model','exit_callback'=>'abc_exit_model','entry_guard_callback'=>'abc_entry_guard','opportunity_callback'=>'abc_opportunity_candidate','meta_callback'=>'abc_meta',
        'required_engine_capabilities'=>abc_meta()['required_engine_capabilities'],
        'requirements'=>['1d'=>['range'=>'3y','interval'=>'1d','min_bars'=>205,'complete_only'=>true]],
        'entry_contract'=>'CROSS_BASED','sizing_mode'=>'ALLOCATION_ONLY','min_entry_rr'=>0.0,'daily_opportunity_policy'=>['enabled'=>true,'target_buys_per_market'=>1,'allocation_scale'=>0.25,'max_soft_attempts_per_market'=>1],
        'validation'=>[
            'enabled'=>true,'signals'=>['BUY','SELL'],'anchor_timeframe'=>'1d','primary_horizon'=>'5d','minimum_usable_samples'=>30,
            'horizons'=>[
                ['type'=>'TRADING_DAYS','value'=>3,'label'=>'3d'],
                ['type'=>'TRADING_DAYS','value'=>5,'label'=>'5d'],
                ['type'=>'TRADING_DAYS','value'=>10,'label'=>'10d'],
                ['type'=>'TRADING_DAYS','value'=>20,'label'=>'20d'],
            ],
        ],
        'risk_policy'=>[
            'initial_stop'=>false,'max_initial_stop_pct'=>0.0,'hard_target'=>false,'loss_cut_ladder'=>false,
            'engine_trailing'=>false,'max_holding'=>false,'portfolio_daily_loss'=>true,'daily_loss_liquidate'=>false,
            'portfolio_drawdown'=>true,'portfolio_drawdown_liquidate'=>false,
        ],
    ]);
}
$engine=__DIR__.'/trade_engine.php';
if(!is_file($engine)){if(!headers_sent())header('Content-Type: text/plain; charset=UTF-8');echo"trade_engine.php 파일이 필요합니다.\n";exit;}
require_once $engine;
if(!defined('ABC_TEST_MODE'))te_run(abc_strategy_spec());

function abc_model(array $ctx,array $state): array
{
    $quote=te_model_num($ctx['quote']['price']??0);$bars=te_model_bars($ctx,'1d');
    if($quote<=0||count($bars)<205)return abc_signal('FILTERED','DATA_SHORT',0,$quote,'현재가 또는 완결 일봉 205개 미만','DATA_SHORT',$state,['bars'=>count($bars)]);
    $cl=te_model_field($bars,'close');$i=count($cl)-1;$pi=$i-1;$decision=te_model_num($cl[$i]??0);
    $series=[];foreach([5,20,60,120,200]as$p)$series[$p]=te_model_sma_series($cl,$p);
    $ma=abc_ma_snapshot($series,$i);$prev=abc_ma_snapshot($series,$pi);
    if(!$ma||!$prev||$decision<=0)return abc_signal('FILTERED','MA_SHORT',0,$decision,'이동평균 계산 부족','MA_SHORT',$state,[]);
    $long=abc_long_alignment($ma);$full=abc_full_alignment($ma);$crossUp=$ma[5]>$ma[20]&&$prev[5]<=$prev[20];
    $resetBefore=abc_reset_days_ending_at($series,$pi);$resetCurrent=abc_reset_days_ending_at($series,$i);$qualified=$full&&$crossUp&&$resetBefore>=ABC_RESET_MIN_DAYS;
    $phase='TREND_OFF';if($long&&$ma[5]<=$ma[20])$phase=$resetCurrent>=ABC_RESET_MIN_DAYS?'PULLBACK_MATURE':'PULLBACK';elseif($qualified)$phase='RESTART_SIGNAL';elseif($full)$phase='TREND_OK';
    $state['phase']=$phase;$state['pullback_days']=$resetCurrent;$state['updated_at']=date('c');
    $startIndex=$resetBefore>0?max(0,$pi-$resetBefore+1):-1;
    $metrics=['alpha_type'=>'PULLBACK_CONTINUATION','phase'=>$phase,'ma5'=>$ma[5],'ma20'=>$ma[20],'ma60'=>$ma[60],'ma120'=>$ma[120],'ma200'=>$ma[200],'previous_ma5'=>$prev[5],'previous_ma20'=>$prev[20],'long_alignment'=>$long,'full_alignment'=>$full,'ma5_ma20_cross_up'=>$crossUp,'reset_streak_days'=>$resetBefore,'reset_streak_current_days'=>$resetCurrent,'reset_streak_start_date'=>$startIndex>=0?abc_bar_date($bars[$startIndex]??[],(string)($ctx['market']??'KR')):'','reset_min_days'=>ABC_RESET_MIN_DAYS,'decision_bar'=>'COMPLETED_DAILY_CLOSE','decision_price'=>$decision,'entry_contract'=>'CROSS_BASED','sizing_mode'=>'ALLOCATION_ONLY','strategy_label'=>'ABC'];
    if($qualified)return abc_signal('BUY','PULLBACK_RESTART_SIGNAL',100,$quote,'장기 상승구조 속 MA5≤MA20 눌림 '.$resetBefore.'일 후 MA5>MA20 재돌파','',$state,$metrics);
    if($full&&$crossUp&&$resetBefore<ABC_RESET_MIN_DAYS)return abc_signal('WATCH','PULLBACK_TOO_SHORT',0,$decision,'눌림 '.$resetBefore.'일 · 최소 5일 필요','ABC_PULLBACK_TOO_SHORT',$state,$metrics);
    if($long&&$ma[5]<=$ma[20])return abc_signal('WATCH',$phase,0,$decision,'상승추세 눌림 누적 '.$resetCurrent.'일','ABC_PULLBACK_BUILDING',$state,$metrics);
    if($full)return abc_signal('WATCH','TREND_OK',0,$decision,'상승추세 유지 · 신규 재출발 신호 아님','NO_NEW_RESTART',$state,$metrics);
    return abc_signal('FILTERED','TREND_OFF',0,$decision,'장기 상승구조 MA20>MA60>MA120>MA200 미충족','TREND_OFF',$state,$metrics);
}

function abc_entry_guard(array $ctx,array $signal,array $candidate=[]): array
{
    $price=te_model_num($ctx['quote']['price']??0);$bars=te_model_bars($ctx,'1d');
    if($price<=0||count($bars)<205)return['ok'=>false,'reason'=>'ABC_GUARD_DATA_SHORT','signal'=>$signal];
    $cl=te_model_field($bars,'close');$i=count($cl)-1;$pi=$i-1;$series=[];foreach([5,20,60,120,200]as$p)$series[$p]=te_model_sma_series($cl,$p);
    $ma=abc_ma_snapshot($series,$i);$prev=abc_ma_snapshot($series,$pi);if(!$ma||!$prev)return['ok'=>false,'reason'=>'ABC_GUARD_MA_SHORT','signal'=>$signal];
    $cross=$ma[5]>$ma[20]&&$prev[5]<=$prev[20];$reset=abc_reset_days_ending_at($series,$pi);
    $opp=!empty($signal['metrics']['daily_opportunity'])||!empty($candidate['daily_opportunity'])||!empty($candidate['metrics']['daily_opportunity']);
    if($opp){
        if(!abc_full_alignment($ma))return['ok'=>false,'reason'=>'ABC_OPPORTUNITY_GUARD_ALIGNMENT_FAIL','signal'=>$signal];
        $signal['status']='BUY';$signal['type']='ABC_DAILY_OPPORTUNITY_TREND_ENTRY';$signal['entry_price']=$price;$signal['stop_price']=0.0;$signal['target_price']=0.0;
        $signal['metrics']=array_merge(is_array($signal['metrics']??null)?$signal['metrics']:[],['daily_opportunity'=>true,'ms7_stage'=>'M2','allocation_scale'=>(float)($signal['metrics']['allocation_scale']??0.25),'entry_quote_price'=>$price,'entry_ma5'=>$ma[5],'opportunity_guard'=>'FULL_ALIGNMENT']);
        return['ok'=>true,'reason'=>'OK_DAILY_OPPORTUNITY','signal'=>$signal];
    }
    if(!abc_full_alignment($ma)||!$cross)return['ok'=>false,'reason'=>'ABC_GUARD_NO_RESTART','signal'=>$signal];
    if($reset<ABC_RESET_MIN_DAYS)return['ok'=>false,'reason'=>'ABC_GUARD_PULLBACK_TOO_SHORT','signal'=>$signal];
    $signal['entry_price']=$price;$signal['stop_price']=0.0;$signal['target_price']=0.0;
    $signal['metrics']=array_merge(is_array($signal['metrics']??null)?$signal['metrics']:[],['entry_quote_price'=>$price,'entry_ma5'=>$ma[5],'pullback_days'=>$reset,'restart_eligible'=>true]);
    return['ok'=>true,'reason'=>'OK','signal'=>$signal];
}

function abc_opportunity_candidate(array $row,array $context=[]): array
{
    $m=is_array($row['metrics']??null)?array_merge($row['metrics'],$row):$row;
    $data=strtoupper((string)($m['data_code']??'OK'));if(!in_array($data,['OK','DATA_DELAYED'],true))return['eligible'=>false];
    if(empty($m['full_alignment']))return['eligible'=>false];
    $phase=strtoupper((string)($m['phase']??''));$reset=max((int)($m['reset_streak_days']??0),(int)($m['reset_streak_current_days']??0));
    $score=72.0+min(18.0,$reset*2.0);if($phase==='TREND_OK')$score+=5.0;
    return['eligible'=>true,'score'=>min(95.0,$score),'signal_type'=>'ABC_DAILY_OPPORTUNITY_TREND_ENTRY','reason'=>'정상 재출발 신호가 없는 날의 장기 정배열 최상위 후보 소액 진입','metrics'=>['daily_opportunity'=>true,'ms7_stage'=>'M2','opportunity_basis'=>'FULL_ALIGNMENT_NEAR_MISS']];
}
function abc_exit_model(array $ctx,array $position,array $state): array
{
    $bars=te_model_bars($ctx,'1d');if(count($bars)<20)return['action'=>'HOLD','code'=>'DATA_SHORT','reason'=>'완결 일봉 부족'];
    $cl=te_model_field($bars,'close');$ma5=te_model_sma_values($cl,5);$ma20=te_model_sma_values($cl,20);
    if($ma5<=0||$ma20<=0)return['action'=>'HOLD','code'=>'MA_SHORT','reason'=>'MA5/20 계산 부족'];
    if($ma5<$ma20)return['action'=>'SELL','code'=>'MA5_MA20_DEAD_CROSS','reason'=>'완결 일봉 MA5가 MA20 아래','urgent_exit'=>false,'ma5'=>$ma5,'ma20'=>$ma20];
    return['action'=>'HOLD','code'=>'MA5_ABOVE_MA20','reason'=>'MA5가 MA20 위','ma5'=>$ma5,'ma20'=>$ma20];
}

function abc_ma_snapshot(array $series,int $i): array{if($i<0)return[];$out=[];foreach([5,20,60,120,200]as$p){$v=$series[$p][$i]??null;if(!is_numeric($v)||(float)$v<=0)return[];$out[$p]=(float)$v;}return$out;}
function abc_long_alignment(array $ma): bool{return isset($ma[20],$ma[60],$ma[120],$ma[200])&&$ma[20]>$ma[60]&&$ma[60]>$ma[120]&&$ma[120]>$ma[200];}
function abc_full_alignment(array $ma): bool{return isset($ma[5],$ma[20],$ma[60],$ma[120],$ma[200])&&$ma[5]>$ma[20]&&abc_long_alignment($ma);}
function abc_reset_days_ending_at(array $series,int $end): int{$count=0;$floor=max(0,$end-ABC_RESET_LOOKBACK_MAX+1);for($i=$end;$i>=$floor;$i--){$ma=abc_ma_snapshot($series,$i);if(!$ma||!abc_long_alignment($ma)||$ma[5]>$ma[20])break;$count++;}return$count;}
function abc_bar_date(array $bar,string $market='KR'): string{$v=(string)($bar['time']??'');if($v!=='')return substr($v,0,10);$ts=(int)($bar['ts']??0);return$ts>0?(function_exists('te_date_tz')?te_date_tz($ts,$market):date('Y-m-d',$ts)):'';}
function abc_signal(string $status,string $type,int $score,float $entry,string $reason,string $block,array $state,array $metrics): array{return['status'=>$status,'type'=>$type,'score'=>$score,'entry_price'=>$entry,'stop_price'=>0.0,'target_price'=>0.0,'reason'=>$reason,'block_reason'=>$block,'state'=>$state,'metrics'=>$metrics];}