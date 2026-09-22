<?php
/**
 * das.php
 * DAS v3.0.3 — CORE relative-strength ranker · market-timezone timestamps · daily opportunity
 * 3+1 Trading System v1.3 FROZEN
 * 다운로드/보관 파일명: das_v303.php
 * PHP 7.4 compatible
 *
 * Alpha only: market/sector relative strength and cross-sectional rank.
 * No MA-cross, stochastic-cross, support/target probability trigger.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');

const DAS_STRATEGY_VERSION='3.0.3';
const DAS_CODE_REV='das-v303-market-timezone-data-timestamp-20260912-r1';
const DAS_DAILY_MIN_BARS=260;
const DAS_MIN_SCORE=60.0;
const DAS_TOP_FRACTION=0.10;

function das_meta(): array
{
    return [
        'strategy_id'=>'DAS','strategy_version'=>DAS_STRATEGY_VERSION,'strategy_status'=>'CORE','alpha_type'=>'RELATIVE_STRENGTH','file'=>'das.php','code_rev'=>DAS_CODE_REV,'market_support'=>['KR','US','JP'],'role'=>'CROSS_SECTIONAL_RELATIVE_STRENGTH_RANKER',
        'required_engine_capabilities'=>['rank_callback','atomic_full_market_scan','completed_daily_bars','benchmark_daily_bars','sector_benchmark_daily_bars','entry_guard_callback','rank_based_entry_contract','allocation_only_sizing','strategy_contract_separation','three_plus_one_spec_v130','signal_before_selection'],
    ];
}
function das_strategy_spec(): array
{
    return te_strategy_spec([
        'app_key'=>'das','app_name'=>'DAS Relative Strength','app_ver'=>'v'.DAS_STRATEGY_VERSION.' CORE · RELATIVE_STRENGTH · 3+1-SPEC-v1.3','strategy_rev'=>DAS_CODE_REV,'strategy_version'=>DAS_STRATEGY_VERSION,'strategy_status'=>'CORE','alpha_type'=>'RELATIVE_STRENGTH',
        'strategy_parameters'=>['weights'=>['market_rs20'=>20,'market_rs60'=>15,'sector_rs'=>15,'momentum'=>15,'volume_expansion'=>10,'high_proximity'=>10,'low_defense'=>10,'volatility_quality'=>5],'minimum_score'=>DAS_MIN_SCORE,'top_fraction'=>DAS_TOP_FRACTION],
        'model_callback'=>'das_model','rank_callback'=>'das_rank_candidates','exit_callback'=>'das_exit_model','entry_guard_callback'=>'das_entry_guard','opportunity_callback'=>'das_opportunity_candidate','meta_callback'=>'das_meta','required_engine_capabilities'=>das_meta()['required_engine_capabilities'],
        'requirements'=>['1d'=>['range'=>'3y','ranges'=>['3y','5y'],'interval'=>'1d','min_bars'=>DAS_DAILY_MIN_BARS,'complete_only'=>true]],
        'entry_contract'=>'RANK_BASED','sizing_mode'=>'ALLOCATION_ONLY','min_entry_rr'=>0.0,'daily_opportunity_policy'=>['enabled'=>true,'target_buys_per_market'=>1,'allocation_scale'=>0.25,'max_soft_attempts_per_market'=>1],
        'validation'=>['enabled'=>true,'signals'=>['BUY','SELL'],'anchor_timeframe'=>'1d','primary_horizon'=>'10d','minimum_usable_samples'=>30,'register_ranked_candidates'=>true,'ranked_max_per_market'=>20,'horizons'=>[
            ['type'=>'TRADING_DAYS','value'=>5,'label'=>'5d'],['type'=>'TRADING_DAYS','value'=>10,'label'=>'10d'],['type'=>'TRADING_DAYS','value'=>20,'label'=>'20d'],
        ]],
        'risk_policy'=>['initial_stop'=>false,'hard_target'=>false,'loss_cut_ladder'=>false,'engine_trailing'=>false,'max_holding'=>false,'portfolio_daily_loss'=>true,'daily_loss_liquidate'=>false,'portfolio_drawdown'=>true,'portfolio_drawdown_liquidate'=>false],
    ]);
}
$engine=__DIR__.'/trade_engine.php';if(!is_file($engine)){if(!headers_sent())header('Content-Type: text/plain; charset=UTF-8');echo"trade_engine.php 파일이 필요합니다.\n";exit;}require_once$engine;if(!defined('DAS_TEST_MODE'))te_run(das_strategy_spec());

function das_model(array $ctx,array $state): array
{
    $ranked=is_array($ctx['ranked_result']??null)?$ctx['ranked_result']:null;
    if($ranked!==null){
        $ready=!empty($ranked['rank_selected'])&&!empty($ranked['score_pass'])&&!empty($ranked['structural_eligible']);
        $state['last_rank']=$ranked['rank']??null;$state['last_score']=(float)($ranked['final_score']??0);$state['updated_at']=date('c');
        if($ready)return das_signal('BUY','RELATIVE_STRENGTH_TOP_RANK',(float)($ranked['final_score']??0),(float)($ctx['quote']['price']??$ranked['entry_price']??0),'시장 횡단면 상대강도 상위 후보','',$state,$ranked);
        return das_signal('WATCH','RELATIVE_STRENGTH_RANK_WAIT',(float)($ranked['final_score']??0),(float)($ranked['entry_price']??0),'상대강도 순위 또는 최소점수 미충족','DAS_RANK_NOT_SELECTED',$state,$ranked);
    }
    $row=das_evaluate($ctx);if(empty($row['ok']))return das_signal('FILTERED',(string)$row['data_code'],0.0,0.0,(string)$row['reason'],(string)$row['data_code'],$state,$row);
    $state['last_raw_score']=(float)$row['final_score'];$state['updated_at']=date('c');
    return das_signal('WATCH','RELATIVE_STRENGTH_CANDIDATE',(float)$row['final_score'],(float)$row['entry_price'],'상대강도 횡단면 순위 계산 대기','FULL_MARKET_RANK_PENDING',$state,$row);
}

function das_evaluate(array $ctx): array
{
    $market=strtoupper((string)($ctx['market']??''));$symbol=strtoupper((string)($ctx['symbol']??''));$name=(string)($ctx['name']??$symbol);$bars=te_model_bars($ctx,'1d');$marketBars=is_array($ctx['market_bars']??null)?$ctx['market_bars']:[];$sectorBars=is_array($ctx['sector_bars']??null)?$ctx['sector_bars']:[];$quote=te_model_num($ctx['quote']['price']??0);
    $meta=is_array($ctx['meta']??null)?$ctx['meta']:[];$last=$bars?$bars[count($bars)-1]:[];$barTs=das_bar_ts($last,$market);$barTime=(string)($last['time']??$last['datetime']??$last['date']??'');
    $base=['ok'=>false,'market'=>$market,'symbol'=>$symbol,'name'=>$name,'entry_type'=>'RELATIVE_STRENGTH_TOP_RANK','entry_price'=>$quote,'stop_reference'=>0.0,'target_price'=>0.0,'structural_eligible'=>false,'score_pass'=>false,'final_score'=>0.0,'rule_score'=>0.0,'reason_codes'=>[],'data_code'=>(string)($ctx['data_code']??$meta['data_quality']??'OK'),'data_timestamp'=>$barTs>0?das_market_iso($barTs,$market):$barTime,'provider'=>(string)($meta['daily_source']??$meta['provider']??$meta['price_source']??''),'data_quality'=>(string)($ctx['data_code']??$meta['data_quality']??'OK'),'market_date'=>(string)($ctx['market_date']??''),'session_date'=>(string)($ctx['session_date']??'')];
    if($quote<=0||count($bars)<DAS_DAILY_MIN_BARS||count($marketBars)<61)return array_merge($base,['data_code'=>'DATA_SHORT','reason'=>'종목 또는 시장 일봉 부족']);
    $c=das_closes($bars);$mc=das_closes($marketBars);if(count($c)<DAS_DAILY_MIN_BARS||count($mc)<61)return array_merge($base,['data_code'=>'DATA_SHORT','reason'=>'종가 계열 부족']);
    $r20=das_return_n($c,20);$r60=das_return_n($c,60);$mr20=das_return_n($mc,20);$mr60=das_return_n($mc,60);$sr20=0.0;$sr60=0.0;$sectorAvailable=count($sectorBars)>=61;if($sectorAvailable){$sc=das_closes($sectorBars);$sr20=$r20-das_return_n($sc,20);$sr60=$r60-das_return_n($sc,60);} $marketRs20=$r20-$mr20;$marketRs60=$r60-$mr60;
    $volRatio=das_volume_ratio($bars,20);$highRatio=das_high_proximity($bars,252);$lowDefense=das_low_defense($bars,$marketBars,20);$volQuality=das_volatility_quality($bars,14);
    $scores=[
        'market_rs20'=>das_centered_score($marketRs20,2.5),'market_rs60'=>das_centered_score($marketRs60,1.5),
        'sector_rs'=>$sectorAvailable?das_centered_score(($sr20+$sr60)/2.0,2.0):50.0,
        'momentum'=>das_centered_score(($r20+$r60)/2.0,1.2),
        'volume_expansion'=>das_clamp(($volRatio-0.5)/1.5*100.0),
        'high_proximity'=>das_clamp(($highRatio-0.70)/0.30*100.0),
        'low_defense'=>das_centered_score($lowDefense,3.0),
        'volatility_quality'=>$volQuality,
    ];
    $final=$scores['market_rs20']*.20+$scores['market_rs60']*.15+$scores['sector_rs']*.15+$scores['momentum']*.15+$scores['volume_expansion']*.10+$scores['high_proximity']*.10+$scores['low_defense']*.10+$scores['volatility_quality']*.05;
    $base['ok']=true;$base['structural_eligible']=true;$base['final_score']=round($final,4);$base['rule_score']=$base['final_score'];$base['score_pass']=$final>=DAS_MIN_SCORE;$base['features']=['stock_return20_pct'=>$r20,'stock_return60_pct'=>$r60,'market_return20_pct'=>$mr20,'market_return60_pct'=>$mr60,'market_rs20_pct'=>$marketRs20,'market_rs60_pct'=>$marketRs60,'sector_rs20_pct'=>$sr20,'sector_rs60_pct'=>$sr60,'sector_available'=>$sectorAvailable,'volume_ratio20'=>$volRatio,'high_proximity_252'=>$highRatio,'low_defense_edge_pct'=>$lowDefense,'volatility_quality'=>$volQuality];$base['component_scores']=$scores;$base['reason_codes']=$base['score_pass']?['MIN_SCORE_PASS']:['MIN_SCORE_LOW'];$base['ranking_data_tier']=$sectorAvailable?'VERIFIED':'LIMITED';$base['reason']='상대강도 횡단면 점수 '.round($final,1);return$base;
}

function das_rank_candidates(array $context,array $candidates): array
{
    $rows=[];foreach($candidates as$r){if(!is_array($r)||empty($r['ok'])||empty($r['structural_eligible']))continue;$r['market']=strtoupper((string)($r['market']??$context['market']??''));$r['symbol']=strtoupper((string)($r['symbol']??''));if($r['symbol']==='')continue;$rows[]=$r;}
    usort($rows,static function(array $a,array $b): int{$x=(float)($a['final_score']??0);$y=(float)($b['final_score']??0);if(abs($x-$y)>1e-9)return$x>$y?-1:1;return strcmp((string)$a['symbol'],(string)$b['symbol']);});
    $n=count($rows);$top=max(1,(int)ceil($n*DAS_TOP_FRACTION));$oppTop=max(1,(int)ceil($n*0.20));foreach($rows as$i=>&$r){$r['universe_count']=$n;$r['opportunity_top_cutoff']=$oppTop;$rank=$i+1;$r['rank']=$rank;$r['top_fraction_cutoff']=$top;$r['score_pass']=((float)($r['final_score']??0)>=DAS_MIN_SCORE)&&$rank<=$top;$r['rank_selected']=false;$r['order_eligible']=false;if(!$r['score_pass'])$r['reason_codes']=array_values(array_unique(array_merge((array)($r['reason_codes']??[]),[$rank>$top?'OUTSIDE_TOP_10PCT':'MIN_SCORE_LOW'])));}unset($r);return$rows;
}
function das_opportunity_candidate(array $row,array $context=[]): array
{
    $data=strtoupper((string)($row['data_code']??$row['data_quality']??'OK'));if(!in_array($data,['OK','DATA_DELAYED'],true))return['eligible'=>false];
    if(empty($row['structural_eligible']))return['eligible'=>false];
    $score=(float)($row['final_score']??$row['rule_score']??0);$rank=(int)($row['rank']??999);$n=max(1,(int)($row['universe_count']??10));$cut=max(1,(int)($row['opportunity_top_cutoff']??ceil($n*0.20)));
    if($score<55.0||$rank>$cut)return['eligible'=>false];
    return['eligible'=>true,'score'=>$score,'signal_type'=>'DAS_DAILY_OPPORTUNITY_RELATIVE_STRENGTH','reason'=>'정상 DAS 선정이 없는 날의 상위 20%·점수 55 이상 상대강도 후보 소액 진입','metrics'=>['daily_opportunity'=>true,'ms7_stage'=>'M2','opportunity_basis'=>'TOP20_SCORE55_NEAR_MISS','opportunity_top_cutoff'=>$cut]];
}
function das_entry_guard(array $ctx,array $signal,array $candidate=[]): array
{
    $price=te_model_num($ctx['quote']['price']??0);if($price<=0)return['ok'=>false,'reason'=>'DAS_GUARD_PRICE_INVALID','signal'=>$signal];$m=is_array($signal['metrics']??null)?$signal['metrics']:$candidate;if(empty($m['structural_eligible']))return['ok'=>false,'reason'=>'DAS_GUARD_STRUCTURE_INVALID','signal'=>$signal];$opp=!empty($m['daily_opportunity'])||!empty($candidate['daily_opportunity']);if($opp){$rank=(int)($m['rank']??$candidate['rank']??999);$cut=(int)($m['opportunity_top_cutoff']??max(1,(int)ceil((float)($m['universe_count']??10)*0.20)));$score=(float)($m['final_score']??$m['rule_score']??0);if($score<55.0||$rank>$cut||empty($m['rank_selected']))return['ok'=>false,'reason'=>'DAS_OPPORTUNITY_GUARD_THRESHOLD','signal'=>$signal];}elseif(empty($m['score_pass'])||empty($m['rank_selected']))return['ok'=>false,'reason'=>'DAS_GUARD_RANK_NOT_SELECTED','signal'=>$signal];$dataCode=strtoupper((string)($m['data_code']??$ctx['data_code']??'OK'));if(!in_array($dataCode,['OK','DATA_DELAYED'],true))return['ok'=>false,'reason'=>'DAS_GUARD_DATA_INVALID_'.$dataCode,'signal'=>$signal];$signal['entry_price']=$price;$signal['stop_price']=0.0;$signal['target_price']=0.0;$signal['metrics']=array_merge($m,['entry_quote_price'=>$price,'entry_data_code'=>$dataCode]);return['ok'=>true,'reason'=>'OK','signal'=>$signal];
}
function das_exit_model(array $ctx,array $position,array $state): array
{
    $bars=te_model_bars($ctx,'1d');$market=is_array($ctx['market_bars']??null)?$ctx['market_bars']:[];$sector=is_array($ctx['sector_bars']??null)?$ctx['sector_bars']:[];if(count($bars)<61||count($market)<61)return['action'=>'HOLD','code'=>'DAS_EXIT_DATA_SHORT','reason'=>'상대강도 청산 자료 부족'];$c=das_closes($bars);$mc=das_closes($market);$rs20=das_return_n($c,20)-das_return_n($mc,20);$rs60=das_return_n($c,60)-das_return_n($mc,60);$sectorRs=null;if(count($sector)>=61){$sc=das_closes($sector);$sectorRs=das_return_n($c,20)-das_return_n($sc,20);} $break=($rs20<-3.0&&$rs60<0.0&&($sectorRs===null||$sectorRs<0.0));if($break)return['action'=>'SELL','code'=>'DAS_RELATIVE_STRENGTH_BREAKDOWN','reason'=>'시장/섹터 대비 상대강도 붕괴','urgent_exit'=>false,'rs20'=>$rs20,'rs60'=>$rs60,'sector_rs20'=>$sectorRs];return['action'=>'HOLD','code'=>'DAS_RELATIVE_STRENGTH_HOLD','reason'=>'상대강도 구조 유지','rs20'=>$rs20,'rs60'=>$rs60,'sector_rs20'=>$sectorRs];
}
function das_bar_ts(array $bar,string $market): int{$ts=(int)($bar['ts']??0);if($ts>0)return$ts;$raw=(string)($bar['time']??$bar['datetime']??$bar['date']??'');if($raw==='')return 0;try{$tz=function_exists('te_market_timezone')?te_market_timezone($market):($market==='US'?'America/New_York':($market==='JP'?'Asia/Tokyo':'Asia/Seoul'));return(new DateTime($raw,new DateTimeZone($tz)))->getTimestamp();}catch(Throwable $e){return 0;}}
function das_market_iso(int $ts,string $market): string{if($ts<=0)return'';if(function_exists('te_iso_tz'))return te_iso_tz($ts,$market);$d=new DateTime('@'.$ts);$d->setTimezone(new DateTimeZone($market==='US'?'America/New_York':($market==='JP'?'Asia/Tokyo':'Asia/Seoul')));return$d->format('c');}
function das_signal(string $status,string $type,float $score,float $entry,string $reason,string $block,array $state,array $metrics): array{return['status'=>$status,'type'=>$type,'score'=>$score,'entry_price'=>$entry,'stop_price'=>0.0,'target_price'=>0.0,'reason'=>$reason,'block_reason'=>$block,'state'=>$state,'metrics'=>$metrics];}
function das_clamp(float $v): float{return max(0.0,min(100.0,$v));}
function das_centered_score(float $edge,float $scale): float{return das_clamp(50.0+$edge*$scale);}
function das_closes(array $bars): array{$out=[];foreach($bars as$b)if(is_array($b)&&is_numeric($b['close']??null)&&(float)$b['close']>0)$out[]=(float)$b['close'];return$out;}
function das_return_n(array $closes,int $n): float{$c=count($closes);if($c<=$n||$closes[$c-$n-1]<=0)return 0.0;return($closes[$c-1]/$closes[$c-$n-1]-1.0)*100.0;}
function das_volume_ratio(array $bars,int $n): float{$count=count($bars);if($count<2)return 0.0;$last=(float)($bars[$count-1]['volume']??0);$sum=0.0;$k=0;for($i=max(0,$count-$n-1);$i<$count-1;$i++){$v=(float)($bars[$i]['volume']??0);if($v>=0){$sum+=$v;$k++;}}$avg=$k>0?$sum/$k:0.0;return$avg>0?$last/$avg:0.0;}
function das_high_proximity(array $bars,int $n): float{$slice=array_slice($bars,-$n);$high=0.0;$close=(float)($bars[count($bars)-1]['close']??0);foreach($slice as$b)$high=max($high,(float)($b['high']??$b['close']??0));return$high>0?$close/$high:0.0;}
function das_drawdown20(array $bars,int $n): float{$slice=array_slice($bars,-$n);$high=0.0;$close=(float)($bars[count($bars)-1]['close']??0);foreach($slice as$b)$high=max($high,(float)($b['high']??$b['close']??0));return$high>0?($close/$high-1.0)*100.0:0.0;}
function das_low_defense(array $bars,array $marketBars,int $n): float{return das_drawdown20($bars,$n)-das_drawdown20($marketBars,$n);}
function das_volatility_quality(array $bars,int $n): float{$slice=array_slice($bars,-$n);$sum=0.0;$k=0;$close=(float)($bars[count($bars)-1]['close']??0);if($close<=0)return 0.0;foreach($slice as$b){$h=(float)($b['high']??0);$l=(float)($b['low']??0);if($h>0&&$l>0&&$h>=$l){$sum+=($h-$l)/$close*100.0;$k++;}}$avg=$k>0?$sum/$k:99.0;return das_clamp(100.0-$avg*12.0);}