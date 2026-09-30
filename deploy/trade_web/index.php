<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Location: trade_dashboard.php', true, 302);
    exit;
}

require __DIR__ . '/trade_dashboard.php';
