<?php
/**
 * stc26.php
 * STC26 v3.0.1 — CHALLENGER oversold reversal · Slow Stochastic 26/5/5
 * 3+1 Trading System v1.3 FROZEN
 * PHP 7.4 compatible
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');
const STC26_VERSION='v3.0.1 CHALLENGER · OVERSOLD_REVERSAL · DAILY-OPPORTUNITY-SHADOW';
const STC26_REV='stc26-v301-daily-opportunity-shadow-20260910-r1';
const STC26_K_LEN=26;
const STC26_K_SMOOTH=5;
const STC26_D_SMOOTH=5;
const STC26_OVERSOLD_MAX=30.0;
const STC26_OVERSOLD_LOOKBACK=5;

function stc26_meta(): array
{
    return ['strategy_id'=>'STC26','strategy_version'=>'3.0.1','strategy_status'=>'CHALLENGER','alpha_type'=>'OVERSOLD_REVERSAL','file'=>'stc26.php','code_rev'=>STC26_REV,'market_support'=>['KR','US','JP'],'entry_contract'=>'CROSS_BASED','exit_contract'=>'CROSS_BASED','required_engine_capabilities'=>['completed_daily_bars','entry_guard_callback','cross_based_entry_contract','allocation_only_sizing','three_plus_one_spec_v130','signal_before_selection']];
}
function stc26_strategy_spec(): array
{
    return te_strategy_spec([
        'app_key'=>'stc26','app_name'=>'STC26 Oversold Reversal Challenger','app_ver'=>STC26_VERSION,'strategy_rev'=>STC26_REV,'strategy_version'=>'3.0.1','strategy_status'=>'CHALLENGER','alpha_type'=>'OVERSOLD_REVERSAL',
        'strategy_parameters'=>['stochastic'=>[26,5,5],'oversold_max'=>STC26_OVERSOLD_MAX,'oversold_lookback'=>STC26_OVERSOLD_LOOKBACK,'close_confirmation'=>'CLOSE_GT_PREV_CLOSE'],
        'model_callback'=>'stc26_model','exit_callback'=>'stc26_exit_model','entry_guard_callback'=>'stc26_entry_guard','opportunity_callback'=>'stc26_opportunity_candidate','meta_callback'=>'stc26_meta','required_engine_capabilities'=>stc26_meta()['required_engine_capabilities'],
        'requirements'=>['1d'=>['range'=>'1y','interval'=>'1d','min_bars'=>60,'complete_only'=>true]],
        'entry_contract'=>'CROSS_BASED','sizing_mode'=>'ALLOCATION_ONLY','min_entry_rr'=>0.0,'daily_opportunity_policy'=>['enabled'=>true,'target_buys_per_market'=>1,'allocation_scale'=>0.20,'max_soft_attempts_per_market'=>1],
        'validation'=>['enabled'=>true,'signals'=>['BUY','SELL'],'anchor_timeframe'=>'1d','primary_horizon'=>'5d','minimum_usable_samples'=>30,'horizons'=>[
            ['type'=>'TRADING_DAYS','value'=>2,'label'=>'2d'],['type'=>'TRADING_DAYS','value'=>3,'label'=>'3d'],['type'=>'TRADING_DAYS','value'=>5,'label'=>'5d'],['type'=>'TRADING_DAYS','value'=>10,'label'=>'10d'],
        ]],
        'risk_policy'=>['initial_stop'=>false,'hard_target'=>false,'loss_cut_ladder'=>false,'engine_trailing'=>false,'max_holding'=>false,'portfolio_daily_loss'=>true,'daily_loss_liquidate'=>false,'portfolio_drawdown'=>true,'portfolio_drawdown_liquidate'=>false],
    ]);
}
$engine=__DIR__.'/trade_engine.php';if(!is_file($engine)){if(!headers_sent())header('Content-Type: text/plain; charset=UTF-8');echo"trade_engine.php 파일이 필요합니다.\n";exit;}require_once$engine;if(!defined('STC26_TEST_MODE'))te_run(stc26_strategy_spec());

function stc26_model(array $ctx,array $state): array
{
    $quote=te_model_num($ctx['quote']['price']??0);$bars=te_model_bars($ctx,'1d');if($quote<=0||count($bars)<60)return stc26_signal('FILTERED','DATA_SHORT',0,$quote,'현재가 또는 완결 일봉 부족','DATA_SHORT',$state,['bars'=>count($bars)]);
    $s=stc26_snapshot($bars);if(empty($s['ok']))return stc26_signal('FILTERED','STOCH_SHORT',0,$quote,'Slow Stochastic 계산 부족','STOCH_SHORT',$state,$s);
    $cl=te_model_field($bars,'close');$i=count($cl)-1;$close=te_model_num($cl[$i]??0);$prevClose=te_model_num($cl[$i-1]??0);$gold=$s['prev_k']<=$s['prev_d']&&$s['k']>$s['d'];$priceConfirm=$close>$prevClose;$oversold=stc26_recent_oversold($bars,STC26_OVERSOLD_LOOKBACK,STC26_OVERSOLD_MAX);
    $phase=$gold&&$oversold&&$priceConfirm?'REVERSAL_SIGNAL':($oversold?'OVERSOLD_WATCH':'NEUTRAL');$state['phase']=$phase;$state['updated_at']=date('c');
    $metrics=array_merge($s,['alpha_type'=>'OVERSOLD_REVERSAL','phase'=>$phase,'recent_oversold'=>$oversold,'oversold_max'=>STC26_OVERSOLD_MAX,'oversold_lookback'=>STC26_OVERSOLD_LOOKBACK,'gold_cross'=>$gold,'close'=>$close,'previous_close'=>$prevClose,'price_confirmation'=>$priceConfirm,'decision_bar'=>'COMPLETED_DAILY_CLOSE','decision_price'=>$close,'strategy_label'=>'STC26']);
    if($gold&&$oversold&&$priceConfirm)return stc26_signal('BUY','OVERSOLD_REVERSAL_GOLD_CROSS',100,$quote,'최근 과매도권에서 Slow %K>%D 상향돌파 + 종가 상승 확인','',$state,$metrics);
    if($gold&&!$oversold)return stc26_signal('FILTERED','GOLD_CROSS_OUTSIDE_OVERSOLD',0,$quote,'과매도권 밖 골든크로스는 CHALLENGER 신호에서 제외','STC26_NOT_OVERSOLD',$state,$metrics);
    if($gold&&!$priceConfirm)return stc26_signal('WATCH','REVERSAL_PRICE_NOT_CONFIRMED',0,$quote,'과매도 골든크로스이나 종가 상승 확인 전','STC26_PRICE_CONFIRM_PENDING',$state,$metrics);
    if($oversold)return stc26_signal('WATCH','OVERSOLD_WATCH',0,$quote,'과매도 상태에서 반전 골든크로스 대기','STC26_CROSS_PENDING',$state,$metrics);
    return stc26_signal('FILTERED','NO_OVERSOLD_REVERSAL',0,$quote,'과매도 반전 조건 미충족','OVERSOLD_REVERSAL_INACTIVE',$state,$metrics);
}
function stc26_opportunity_candidate(array $row,array $context=[]): array
{
    $m=is_array($row['metrics']??null)?array_merge($row['metrics'],$row):$row;
    $data=strtoupper((string)($m['data_code']??'OK'));if(!in_array($data,['OK','DATA_DELAYED'],true))return['eligible'=>false];
    if(empty($m['recent_oversold']))return['eligible'=>false];
    $k=(float)($m['k']??0);$d=(float)($m['d']??0);$gold=!empty($m['gold_cross']);$price=!empty($m['price_confirmation']);
    if(!$gold&&$k<=$d)return['eligible'=>false];
    $score=$gold?90.0:75.0;if($price)$score+=5.0;
    return['eligible'=>true,'score'=>min(95.0,$score),'signal_type'=>'STC26_DAILY_OPPORTUNITY_SHADOW','reason'=>'과매도 반전 근접 후보를 M2 소액진입 후보로 평가하되 CHALLENGER는 SHADOW PAPER만 수행','metrics'=>['daily_opportunity'=>true,'ms7_stage'=>'M2','opportunity_basis'=>'OVERSOLD_NEAR_REVERSAL']];
}
function stc26_entry_guard(array $ctx,array $signal,array $candidate=[]): array
{
    $quote=te_model_num($ctx['quote']['price']??0);$bars=te_model_bars($ctx,'1d');if($quote<=0||count($bars)<60)return['ok'=>false,'reason'=>'STC26_GUARD_DATA_SHORT','signal'=>$signal];
    $s=stc26_snapshot($bars);$cl=te_model_field($bars,'close');$i=count($cl)-1;$close=te_model_num($cl[$i]??0);$prev=te_model_num($cl[$i-1]??0);
    if(empty($s['ok'])||!($s['prev_k']<=$s['prev_d']&&$s['k']>$s['d']))return['ok'=>false,'reason'=>'STC26_GUARD_NO_GOLD_CROSS','signal'=>$signal];
    if(!stc26_recent_oversold($bars,STC26_OVERSOLD_LOOKBACK,STC26_OVERSOLD_MAX))return['ok'=>false,'reason'=>'STC26_GUARD_NOT_OVERSOLD','signal'=>$signal];
    if($close<=$prev)return['ok'=>false,'reason'=>'STC26_GUARD_PRICE_NOT_CONFIRMED','signal'=>$signal];
    $signal['entry_price']=$quote;$signal['stop_price']=0.0;$signal['target_price']=0.0;return['ok'=>true,'reason'=>'OK','signal'=>$signal];
}
function stc26_exit_model(array $ctx,array $position,array $state): array
{
    $bars=te_model_bars($ctx,'1d');if(count($bars)<60)return['action'=>'HOLD','code'=>'DATA_SHORT','reason'=>'완결 일봉 부족'];$s=stc26_snapshot($bars);if(empty($s['ok']))return['action'=>'HOLD','code'=>'STOCH_SHORT','reason'=>'Slow Stochastic 계산 부족'];
    if($s['k']<$s['d'])return['action'=>'SELL','code'=>'OVERSOLD_REVERSAL_DEAD_CROSS','reason'=>'Slow %K가 Slow %D 아래로 재이탈','urgent_exit'=>false]+$s;
    return['action'=>'HOLD','code'=>'REVERSAL_ACTIVE','reason'=>'반전 모멘텀 유지']+$s;
}
function stc26_snapshot(array $bars): array
{
    $st=te_model_stoch($bars,STC26_K_LEN,STC26_K_SMOOTH,STC26_D_SMOOTH);$i=count($bars)-1;$k=te_model_nullable($st[$i]['k']??null);$d=te_model_nullable($st[$i]['d']??null);$pk=te_model_nullable($st[$i-1]['k']??null);$pd=te_model_nullable($st[$i-1]['d']??null);if($k===null||$d===null||$pk===null||$pd===null)return['ok'=>false];return['ok'=>true,'k'=>$k,'d'=>$d,'prev_k'=>$pk,'prev_d'=>$pd];
}
function stc26_recent_oversold(array $bars,int $lookback,float $threshold): bool
{
    $st=te_model_stoch($bars,STC26_K_LEN,STC26_K_SMOOTH,STC26_D_SMOOTH);$n=count($st);for($i=max(0,$n-$lookback);$i<$n;$i++){$k=te_model_nullable($st[$i]['k']??null);$d=te_model_nullable($st[$i]['d']??null);if(($k!==null&&$k<=$threshold)||($d!==null&&$d<=$threshold))return true;}return false;
}
function stc26_signal(string $status,string $type,int $score,float $entry,string $reason,string $block,array $state,array $metrics): array{return['status'=>$status,'type'=>$type,'score'=>$score,'entry_price'=>$entry,'stop_price'=>0.0,'target_price'=>0.0,'reason'=>$reason,'block_reason'=>$block,'state'=>$state,'metrics'=>$metrics];}