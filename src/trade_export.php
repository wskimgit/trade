<?php
/**
 * trade_export.php
 * Dedicated Trade analysis export endpoint v1.0.0
 * Purpose: reliable mobile/browser download without running a strategy page route.
 * PHP 7.4 compatible.
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Seoul');

const TX_VERSION='v1.0.1';
const TX_REV='trade-export-v101-full-engine-config-mobile-safe-download-20260923-r1';

if(PHP_SAPI==='cli'){
    fwrite(STDERR,"WEB ONLY\n");
    exit(2);
}

@ini_set('display_errors','0');
@ini_set('zlib.output_compression','0');
error_reporting(E_ALL);

if(!ob_get_level()) ob_start();

$strategy=strtolower(trim((string)($_GET['strategy']??'dts')));
$mode=strtolower(trim((string)($_GET['mode']??'download')));
$unified=($strategy===''||$strategy==='all'||$strategy==='unified');
if($unified)$strategy='dts';

$map=[
    'dts'=>['file'=>'dts.php','test'=>'DTS_TEST_MODE','spec'=>'dts_strategy_spec'],
    'abc'=>['file'=>'abc.php','test'=>'ABC_TEST_MODE','spec'=>'abc_strategy_spec'],
    'das'=>['file'=>'das.php','test'=>'DAS_TEST_MODE','spec'=>'das_strategy_spec'],
    'stc26'=>['file'=>'stc26.php','test'=>'STC26_TEST_MODE','spec'=>'stc26_strategy_spec'],
];

if(!isset($map[$strategy])){
    while(ob_get_level()>0)@ob_end_clean();
    http_response_code(400);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'message'=>'지원하지 않는 strategy'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$entry=__DIR__.'/'.$map[$strategy]['file'];
if(!is_file($entry)){
    while(ob_get_level()>0)@ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'message'=>'전략 파일 없음','file'=>basename($entry)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

define($map[$strategy]['test'],true);
require_once $entry;

$spec=$map[$strategy]['spec'];
if(!function_exists($spec)||!function_exists('te_analysis_build_json')){
    while(ob_get_level()>0)@ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'message'=>'분석 export 초기화 실패'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$rawSpec=$spec();
$cfg=te_config($rawSpec);
te_dirs($cfg);
$built=te_analysis_build_json($cfg,$unified?'':$strategy,$unified);
if(empty($built['ok'])||!isset($built['json'])){
    while(ob_get_level()>0)@ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'message'=>'분석 파일 생성 실패','bytes'=>(int)($built['bytes']??0)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

$json=(string)$built['json'];
$name=$unified
    ? 'chatgpt_trade_UNIFIED_'.date('Ymd_His').'.json'
    : 'chatgpt_trade_'.strtoupper(preg_replace('/[^A-Za-z0-9_-]/','_',$strategy)).'_'.date('Ymd_His').'.json';

while(ob_get_level()>0)@ob_end_clean();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Trade-Export-Revision: '.TX_REV);

if($mode==='view'){
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: inline; filename="'.$name.'"');
}else{
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="'.$name.'"');
    header('Content-Transfer-Encoding: binary');
}
echo $json;
exit;
