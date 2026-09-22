<?php
declare(strict_types=1);

$root = dirname(__DIR__) . '/src';

$expected = [
    'trade_engine.php' => 'trade-engine-v445-single-file-runtime-authority-20260922-r2',
    'trade_broker.php' => 'trade-broker-v597-single-file-runtime-authority-20260922-r2',
    'trade_runner.php' => 'trade-low-load-runner-v145-execution-truth-strategy-freshness-watchdog-20260922-r4',
    'trade_runner_control.php' => 'trade-low-load-runner-web-control-v145-startup-health-detail-20260922-r3',
    'trade_dashboard.php' => 'trade-dashboard-v348-alert-ack-state-machine-20260922-r1',
    'trade_3plus1_preflight.php' => 'trade-3plus1-preflight-v1313-dashboard-alert-ack-contract-20260922-r1',
    'dts.php' => 'dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1',
    'abc.php' => 'abc-v542-market-timezone-bar-date-20260912-r1',
    'das.php' => 'das-v303-market-timezone-data-timestamp-20260912-r1',
    'stc26.php' => 'stc26-v301-daily-opportunity-shadow-20260910-r1',
];

$errors = [];
foreach ($expected as $file => $marker) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $errors[] = $file . ': MISSING';
        continue;
    }
    $src = file_get_contents($path);
    if (!is_string($src) || strpos($src, $marker) === false) {
        $errors[] = $file . ': MARKER_MISMATCH';
    }
}

$list = $root . '/trade_list.php';
if (!is_file($list)) {
    $errors[] = 'trade_list.php: MISSING';
} else {
    $src = file_get_contents($list);
    foreach (['trade_universe_contract_v2', "'version'=>'1.9.0'", "'schema'=>'trade_list_v1_8'"] as $marker) {
        if (!is_string($src) || strpos($src, $marker) === false) {
            $errors[] = 'trade_list.php: MARKER_MISMATCH ' . $marker;
        }
    }
}

$validation = $root . '/trade_validation.php';
if (!is_file($validation)) {
    fwrite(STDOUT, "INFO trade_validation.php pending exact v1.3.1 import\n");
} else {
    $src = file_get_contents($validation);
    if (!is_string($src) || strpos($src, 'trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1') === false) {
        $errors[] = 'trade_validation.php: WRONG VERSION';
    }
}

if ($errors) {
    foreach ($errors as $e) fwrite(STDERR, "FAIL $e\n");
    exit(1);
}

fwrite(STDOUT, "PASS frozen markers for imported baseline sources\n");
exit(0);
