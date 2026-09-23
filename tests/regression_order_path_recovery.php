<?php
declare(strict_types=1);

define('TB_TEST_MODE', true);
require_once dirname(__DIR__).'/src/trade_runner.php';
require_once dirname(__DIR__).'/src/trade_broker.php';

$failures=[];
$pass=0;
$check=function(string $name,bool $ok,$detail='')use(&$failures,&$pass): void {
    if($ok){$pass++;fwrite(STDOUT,"PASS ".$name."\n");return;}
    $failures[]=$name.($detail!==''?' :: '.(is_scalar($detail)?(string)$detail:json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)):'');
    fwrite(STDERR,"FAIL ".$name."\n");
};

$closed=(new DateTimeImmutable('2026-09-24 08:00:00',new DateTimeZone('Asia/Tokyo')))->getTimestamp();

$r=tr_market_wait_class(['market'=>'JP','status'=>'PENDING','approved'=>false,'broker_message'=>''],$closed);
$check('unapproved PENDING is ACTIONABLE while market closed',empty($r['wait'])&&($r['source']??'')==='PENDING_APPROVAL_ACTIONABLE',$r);

$r=tr_market_wait_class(['market'=>'JP','status'=>'PENDING','approved'=>false,'approval_block_reason'=>'JP 거래소 코드 없음','broker_message'=>''],$closed);
$check('approval-blocked PENDING is ACTIONABLE while market closed',empty($r['wait'])&&($r['source']??'')==='APPROVAL_BLOCK_ACTIONABLE',$r);

$r=tr_market_wait_class(['market'=>'JP','status'=>'PENDING','approved'=>true,'broker_message'=>''],$closed);
$check('approved PENDING may use closed-session MARKET_WAIT hint',!empty($r['wait'])&&($r['source']??'')==='SESSION_CLOSED_HINT',$r);

$r=tr_market_wait_class(['market'=>'JP','status'=>'PENDING','approved'=>true,'broker_message'=>'WAIT_MARKET_OPEN'],$closed);
$check('explicit Broker WAIT_MARKET_OPEN remains MARKET_WAIT',!empty($r['wait'])&&($r['source']??'')==='BROKER_MESSAGE',$r);

$r=tr_market_wait_class(['market'=>'JP','status'=>'INTENT','broker_message'=>''],$closed);
$check('canonical-only INTENT stays fail-safe ACTIONABLE',empty($r['wait']),$r);

$listFile=dirname(__DIR__).'/src/trade_list.php';
$map=tb_trade_list_exchange_map($listFile);
$check('operational trade list resolves JP 5803 venue',($map['5803']??'')==='TKSE',$map['5803']??'MISSING');

$c=['exchange_map'=>$map,'us_exchange_map'=>$map];
$resolved=tb_exchange($c,['market'=>'JP','symbol'=>'5803','exchange'=>'']);
$check('Broker resolves blank JP 5803 order exchange from direct map',$resolved==='TKSE',$resolved);

$liveCfg=tb_config();
$check('Broker merged config preserves numeric JP 5803 key',isset($liveCfg['exchange_map']['5803'])&&$liveCfg['exchange_map']['5803']==='TKSE',$liveCfg['exchange_map']['5803']??'MISSING');
$resolvedLive=tb_exchange($liveCfg,['market'=>'JP','symbol'=>'5803','exchange'=>'']);
$check('Broker live config resolves blank JP 5803 order exchange',$resolvedLive==='TKSE',$resolvedLive);

$jpOrder=['market'=>'JP','symbol'=>'5803'];
$jpTz=new DateTimeZone('Asia/Tokyo');
$jpClosed=[
    new DateTimeImmutable('2026-09-21 10:00:00',$jpTz),
    new DateTimeImmutable('2026-09-22 10:00:00',$jpTz),
    new DateTimeImmutable('2026-09-23 13:00:00',$jpTz),
];
$closedOk=true;foreach($jpClosed as $dt)if(tb_market_open_at($liveCfg,$jpOrder,$dt))$closedOk=false;
$check('Broker JPX builtin closes 2026-09-21/22/23',$closedOk);
$jpOpen=new DateTimeImmutable('2026-09-24 09:00:00',$jpTz);
$check('Broker JPX next regular session opens 2026-09-24 09:00',tb_market_open_at($liveCfg,$jpOrder,$jpOpen));

$engine=(string)file_get_contents(dirname(__DIR__).'/src/trade_engine.php');
$check('Engine SELL exchange provenance marker installed',strpos($engine,'SELL_EXCHANGE_POSITION_FALLBACK')!==false);
$check('Engine normal SELL path uses position exchange recovery',strpos($engine,'te_sell_order_context($ctx,$p,$q,$tick)')!==false);
$check('Engine priority SELL path uses position exchange recovery',strpos($engine,'$sellCtx=te_sell_order_context($ctx,$positions[$key],$q,$tick)')!==false);

$broker=(string)file_get_contents(dirname(__DIR__).'/src/trade_broker.php');
$check('Broker operational list fallback marker installed',strpos($broker,'ORDER_EXCHANGE_OPERATIONAL_LIST_FALLBACK')!==false);
$check('Broker merge preserves numeric JP symbol keys',strpos($broker,'ORDER_EXCHANGE_NUMERIC_SYMBOL_KEY_PRESERVE')!==false&&strpos($broker,'array_replace($operationalTradeExchangeMap,$tradeExchangeMap,$configExchangeMap)')!==false);

if($failures){
    fwrite(STDERR,"\n".count($failures)." failure(s):\n - ".implode("\n - ",$failures)."\n");
    exit(1);
}
fwrite(STDOUT,"\nALL ".$pass." ORDER-PATH RECOVERY CHECKS PASS\n");
exit(0);
