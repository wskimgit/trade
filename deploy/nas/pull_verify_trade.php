<?php
/**
 * Trade NAS Pull & VERIFY deployer v1.0.0
 *
 * Save this one file on the NAS outside the web root and execute it locally
 * with the NAS PHP CLI or Task Scheduler. It has no web installation route
 * and does not use SSH. It pulls only from GitHub over verified HTTPS.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

const PV_VERSION = '1.0.0';
const PV_MANAGED_BY = 'codex/trade-pull-verify';
const PV_DEFAULT_REPOSITORY = 'wskimgit/trade';
const PV_DEFAULT_REF = 'main';
const PV_DEFAULT_TARGET = '/volume1/web/trade';
const PV_DEFAULT_ALLOWED_PARENT = '/volume1/web';
const PV_MAX_SOURCE_BYTES = 1572864;

const PV_SOURCE_FILES = array(
    'trade_engine.php' => 'src/trade_engine.php',
    'trade_broker.php' => 'src/trade_broker.php',
    'trade_runner.php' => 'src/trade_runner.php',
    'trade_runner_control.php' => 'src/trade_runner_control.php',
    'trade_dashboard.php' => 'src/trade_dashboard.php',
    'trade_validation.php' => 'src/trade_validation.php',
    'trade_3plus1_preflight.php' => 'src/trade_3plus1_preflight.php',
    'trade_list.php' => 'src/trade_list.php',
    'dts.php' => 'src/dts.php',
    'abc.php' => 'src/abc.php',
    'das.php' => 'src/das.php',
    'stc26.php' => 'src/stc26.php',
    'index.php' => 'deploy/trade_web/index.php',
    '.htaccess' => 'deploy/trade_web/.htaccess',
);

const PV_REQUIRED_MARKERS = array(
    'trade_engine.php' => array(
        "const TE_REV = 'trade-engine-v445-single-file-runtime-authority-20260922-r2'",
        'SINGLE_FILE_PAPER',
    ),
    'trade_broker.php' => array(
        "const TB_REV='trade-broker-v597-single-file-runtime-authority-20260922-r2'",
        'SINGLE_FILE_PAPER',
    ),
    'trade_runner.php' => array(
        "const TR_REV='trade-low-load-runner-v145-execution-truth-strategy-freshness-watchdog-20260922-r4'",
        "const TR_REQUIRED_AUTH='SINGLE_FILE_PAPER'",
    ),
    'trade_runner_control.php' => array(
        "const RC_REV='trade-low-load-runner-web-control-v145-startup-health-detail-20260922-r3'",
        "const RC_REQUIRED_AUTH='SINGLE_FILE_PAPER'",
    ),
    'trade_dashboard.php' => array(
        "const TD_REV = 'trade-dashboard-v348-alert-ack-state-machine-20260922-r1'",
        'SINGLE_FILE_PAPER',
    ),
    'trade_validation.php' => array(
        "define('TV_REV','trade-validation-v131-explicit-legacy-recovery-wal-20260921-r1')",
    ),
    'trade_3plus1_preflight.php' => array(
        "const P3_REV='trade-3plus1-preflight-v1313-dashboard-alert-ack-contract-20260922-r1'",
    ),
    'trade_list.php' => array(
        "'version'=>'1.9.0'",
        "'contract'=>'trade_universe_contract_v2'",
    ),
    'dts.php' => array(
        "const DTS_REV='dts-v254-market-timezone-hard-stale-recovery-contract-20260912-r1'",
    ),
    'abc.php' => array(
        "const ABC_REV='abc-v542-market-timezone-bar-date-20260912-r1'",
    ),
    'das.php' => array(
        "const DAS_CODE_REV='das-v303-market-timezone-data-timestamp-20260912-r1'",
    ),
    'stc26.php' => array(
        "const STC26_REV='stc26-v301-daily-opportunity-shadow-20260910-r1'",
    ),
);

const PV_RUNTIME_DIRS = array(
    'trade_phase3b_lite_v100',
    'trade_runtime_single',
    'trade_single_compat',
    'trade_single_compat/trade_runtime',
    'trade_single_compat/trade_runtime/intent_spool',
    'trade_single_compat/trade_runtime/intent_ack',
    'trade_single_compat/validation_runtime',
    'trade_runner_runtime',
    'trade_cache',
    'abc_runtime',
    'dts_runtime',
    'das_runtime',
    'stc26_runtime',
    'validation_runtime',
    'trade_runtime',
);

function pv_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function pv_bool(string $name, bool $default = false): bool
{
    $value = strtolower(pv_env($name, $default ? '1' : '0'));
    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

function pv_fail(string $code, string $detail = ''): void
{
    throw new RuntimeException($detail === '' ? $code : $code . ' ' . $detail);
}

function pv_json($value, bool $pretty = false): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    try {
        return (string)json_encode($value, $flags | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        pv_fail('JSON_ENCODE_FAILED', $error->getMessage());
    }
}

function pv_normalize_path(string $path): string
{
    $path = rtrim(str_replace('\\', '/', trim($path)), '/');
    if ($path === '' || $path[0] !== '/') {
        pv_fail('ABSOLUTE_PATH_REQUIRED', $path);
    }
    if (preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        pv_fail('PATH_TRAVERSAL_REJECTED', $path);
    }
    return $path;
}

function pv_allowed_parent(): string
{
    $parent = pv_normalize_path(pv_env('TRADE_PULL_ALLOWED_PARENT', PV_DEFAULT_ALLOWED_PARENT));
    if ($parent === '/' || !is_dir($parent)) {
        pv_fail('ALLOWED_PARENT_INVALID', $parent);
    }
    return $parent;
}

function pv_target_root(string $requested = ''): string
{
    $raw = trim($requested) !== '' ? trim($requested) : pv_env('TRADE_PULL_TARGET', PV_DEFAULT_TARGET);
    $root = pv_normalize_path($raw);
    $parent = pv_allowed_parent();
    $prefix = rtrim($parent, '/') . '/';
    if ($root === $parent || strpos($root, $prefix) !== 0) {
        pv_fail('TARGET_OUTSIDE_ALLOWED_PARENT', $root);
    }
    if (!is_dir(dirname($root))) {
        pv_fail('TARGET_PARENT_MISSING', dirname($root));
    }
    return $root;
}

function pv_mkdir(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }
    if (is_link($directory) || (!@mkdir($directory, 0775, true) && !is_dir($directory))) {
        pv_fail('DIRECTORY_CREATE_FAILED', $directory);
    }
}

function pv_atomic_write(string $file, string $content, int $mode = 0644): void
{
    pv_mkdir(dirname($file));
    $temporary = @tempnam(dirname($file), '.trade-pv-');
    if ($temporary === false) {
        pv_fail('TEMP_CREATE_FAILED', $file);
    }
    @chmod($temporary, $mode);
    $written = @file_put_contents($temporary, $content, LOCK_EX);
    if ($written === false || $written !== strlen($content)) {
        @unlink($temporary);
        pv_fail('WRITE_FAILED', $file);
    }
    if (!@rename($temporary, $file)) {
        @unlink($temporary);
        pv_fail('RENAME_FAILED', $file);
    }
}

function pv_write_json(string $file, array $value, int $mode = 0640): void
{
    pv_atomic_write($file, pv_json($value, true) . PHP_EOL, $mode);
}

function pv_read_json(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    try {
        $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        return null;
    }
    return is_array($value) ? $value : null;
}

function pv_repo_url(string $repository, string $path): string
{
    $parts = explode('/', trim($repository), 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        pv_fail('REPOSITORY_INVALID', $repository);
    }
    $segments = array();
    foreach (explode('/', trim($path, '/')) as $segment) {
        $segments[] = rawurlencode($segment);
    }
    return 'https://api.github.com/repos/' . rawurlencode($parts[0]) . '/'
        . rawurlencode($parts[1]) . '/' . implode('/', $segments);
}

function pv_github_json(string $url, string $token): array
{
    if (!function_exists('curl_init')) {
        pv_fail('CURL_REQUIRED');
    }
    $headers = array(
        'Accept: application/vnd.github+json',
        'User-Agent: trade-pull-verify/' . PV_VERSION,
    );
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $handle = curl_init();
    if ($handle === false) {
        pv_fail('CURL_INIT_FAILED');
    }
    curl_setopt_array($handle, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    $body = curl_exec($handle);
    $errorNo = curl_errno($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($body === false || $errorNo !== 0) {
        pv_fail('GITHUB_REQUEST_FAILED', 'curl_errno=' . $errorNo);
    }
    if ($status < 200 || $status >= 300) {
        pv_fail('GITHUB_HTTP_FAILED', 'status=' . $status);
    }
    try {
        $decoded = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        pv_fail('GITHUB_JSON_INVALID');
    }
    if (!is_array($decoded)) {
        pv_fail('GITHUB_RESPONSE_INVALID');
    }
    return $decoded;
}

function pv_token(string $root): string
{
    $inline = pv_env('TRADE_PULL_TOKEN', pv_env('TRADE_GITHUB_TOKEN'));
    $file = pv_env('TRADE_PULL_TOKEN_FILE', pv_env('TRADE_GITHUB_TOKEN_FILE'));
    if ($inline !== '' && $file !== '') {
        pv_fail('TOKEN_SOURCE_AMBIGUOUS');
    }
    if ($inline !== '') {
        return $inline;
    }
    if ($file === '') {
        return '';
    }
    $file = pv_normalize_path($file);
    $parent = pv_allowed_parent();
    $parentPrefix = rtrim($parent, '/') . '/';
    if ($file === $parent || strpos($file, $parentPrefix) === 0
        || strpos($file, rtrim($root, '/') . '/') === 0) {
        pv_fail('TOKEN_FILE_IN_WEB_SCOPE');
    }
    if (!is_file($file) || !is_readable($file)) {
        pv_fail('TOKEN_FILE_NOT_READABLE');
    }
    $token = trim((string)@file_get_contents($file));
    if ($token === '') {
        pv_fail('TOKEN_FILE_EMPTY');
    }
    return $token;
}

function pv_requested_ref(): string
{
    $ref = pv_env('TRADE_PULL_REF', PV_DEFAULT_REF);
    if (strlen($ref) > 200 || $ref === '' || strpos($ref, '..') !== false
        || !preg_match('/^[A-Za-z0-9._\/-]+$/', $ref)) {
        pv_fail('REF_INVALID');
    }
    return $ref;
}

function pv_resolve_commit(string $repository, string $ref, string $token): array
{
    $payload = pv_github_json(pv_repo_url($repository, 'commits/' . $ref), $token);
    $sha = strtolower(trim((string)($payload['sha'] ?? '')));
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
        pv_fail('COMMIT_SHA_INVALID');
    }
    $expected = strtolower(trim(pv_env('TRADE_PULL_EXPECTED_SHA')));
    if ($expected !== '') {
        if (!preg_match('/^[0-9a-f]{40}$/', $expected) || !hash_equals($expected, $sha)) {
            pv_fail('COMMIT_SHA_MISMATCH', $sha);
        }
    }
    return array('requested_ref' => $ref, 'commit_sha' => $sha);
}

function pv_fetch_source(string $repository, string $commit, string $sourcePath, string $token): string
{
    $payload = pv_github_json(
        pv_repo_url($repository, 'contents/' . trim($sourcePath, '/')) . '?ref=' . rawurlencode($commit),
        $token
    );
    if (($payload['type'] ?? '') !== 'file') {
        pv_fail('SOURCE_NOT_FILE', $sourcePath);
    }
    $size = (int)($payload['size'] ?? 0);
    if ($size > PV_MAX_SOURCE_BYTES) {
        pv_fail('SOURCE_TOO_LARGE', $sourcePath);
    }
    $encoded = preg_replace('/\s+/', '', (string)($payload['content'] ?? ''));
    if ($encoded === '') {
        pv_fail('SOURCE_CONTENT_MISSING', $sourcePath);
    }
    $decoded = base64_decode($encoded, true);
    if ($decoded === false || strlen($decoded) > PV_MAX_SOURCE_BYTES) {
        pv_fail('SOURCE_DECODE_FAILED', $sourcePath);
    }
    return $decoded;
}

function pv_verify_source(string $target, string $body): void
{
    if (substr($target, -4) === '.php' && strpos(ltrim($body), '<?php') !== 0) {
        pv_fail('PHP_HEADER_INVALID', $target);
    }
    foreach ((array)(PV_REQUIRED_MARKERS[$target] ?? array()) as $marker) {
        if (strpos($body, $marker) === false) {
            pv_fail('REQUIRED_MARKER_MISSING', $target);
        }
    }
}

function pv_lint(string $file): void
{
    if (!function_exists('proc_open')) {
        pv_fail('PROC_OPEN_REQUIRED');
    }
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    $pipes = array();
    $process = @proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    ), $pipes);
    if (!is_resource($process)) {
        pv_fail('PHP_LINT_PROCESS_FAILED', basename($file));
    }
    @fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        pv_fail('PHP_LINT_FAILED', basename($file) . ' ' . trim($stderr !== '' ? $stderr : $stdout));
    }
}

function pv_remove_tree(string $directory): void
{
    if (is_link($directory) || is_file($directory)) {
        @unlink($directory);
        return;
    }
    if (!is_dir($directory)) {
        return;
    }
    $entries = @scandir($directory);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            pv_remove_tree($directory . '/' . $entry);
        }
    }
    @rmdir($directory);
}

function pv_entries(string $root): array
{
    if (!is_dir($root)) {
        return array();
    }
    $entries = @scandir($root);
    if (!is_array($entries)) {
        pv_fail('TARGET_SCAN_FAILED');
    }
    return array_values(array_filter($entries, function ($entry) {
        return $entry !== '.' && $entry !== '..';
    }));
}

function pv_prepare_target(string $root, bool $allowUpgrade): bool
{
    if (is_link($root) || (file_exists($root) && !is_dir($root))) {
        pv_fail('TARGET_NOT_DIRECTORY');
    }
    if (!is_dir($root)) {
        pv_mkdir($root);
        return false;
    }
    foreach (pv_entries($root) as $entry) {
        if (strpos($entry, '.trade-pull-stage-') === 0) {
            pv_fail('STALE_STAGE_PRESENT', $entry);
        }
    }
    $state = pv_read_json($root . '/trade_pull_verify_state.json');
    if ($state === null) {
        if (count(pv_entries($root)) !== 0) {
            pv_fail('TARGET_NOT_EMPTY');
        }
        return false;
    }
    if (($state['schema'] ?? '') !== 'trade_pull_verify_v1'
        || ($state['managed_by'] ?? '') !== PV_MANAGED_BY) {
        pv_fail('TARGET_NOT_MANAGED');
    }
    if (!$allowUpgrade) {
        pv_fail('UPGRADE_REQUIRES_COMMAND_UPGRADE');
    }
    $marker = pv_read_json($root . '/trade_phase3b_lite_v100/authority.json');
    $canonical = pv_read_json($root . '/trade_runtime_single/trade_state.json');
    if (!is_array($marker)
        || ($marker['schema'] ?? '') !== 'trade_authority_v1'
        || strtoupper((string)($marker['authority'] ?? '')) !== 'SINGLE_FILE_PAPER'
        || !empty($marker['real_order_allowed'])) {
        pv_fail('TARGET_NOT_SAFE_PAPER');
    }
    if (!is_array($canonical) || ($canonical['schema'] ?? '') !== 'trade_state_v1') {
        pv_fail('CANONICAL_STATE_INVALID');
    }
    return true;
}

function pv_initial_marker(string $commit): array
{
    return array(
        'schema' => 'trade_authority_v1',
        'authority' => 'SINGLE_FILE_PAPER',
        'real_order_allowed' => false,
        'execution_mode' => 'PAPER',
        'managed_by' => PV_MANAGED_BY,
        'source_commit' => $commit,
        'created_at' => date('c'),
    );
}

function pv_initial_canonical(): array
{
    return array(
        'schema' => 'trade_state_v1',
        'authority' => 'SINGLE_FILE_PAPER',
        'real_order_allowed' => false,
        'updated_at' => date('c'),
        'orders' => array(),
        'positions' => array(),
    );
}

function pv_commit_stage(string $root, string $stage, array $items, array $expected): void
{
    $backups = array();
    $moved = array();
    try {
        foreach ($items as $relative) {
            $source = $stage . '/' . $relative;
            if (!is_file($source)) {
                pv_fail('STAGED_FILE_MISSING', $relative);
            }
            $destination = $root . '/' . $relative;
            if (file_exists($destination) || is_link($destination)) {
                $backup = $stage . '/previous/' . $relative;
                pv_mkdir(dirname($backup));
                if (!@rename($destination, $backup)) {
                    pv_fail('BACKUP_FAILED', $relative);
                }
                $backups[] = array($backup, $destination);
            }
        }
        foreach ($items as $relative) {
            $source = $stage . '/' . $relative;
            $destination = $root . '/' . $relative;
            pv_mkdir(dirname($destination));
            if (!@rename($source, $destination)) {
                pv_fail('ACTIVATION_FAILED', $relative);
            }
            $moved[] = $destination;
        }
        foreach ($expected as $relative => $hash) {
            $actual = is_file($root . '/' . $relative) ? hash_file('sha256', $root . '/' . $relative) : false;
            if (!is_string($actual) || !hash_equals((string)$hash, $actual)) {
                pv_fail('ACTIVATION_HASH_FAILED', $relative);
            }
        }
        pv_remove_tree($stage);
    } catch (Throwable $error) {
        for ($i = count($moved) - 1; $i >= 0; $i--) {
            if (is_file($moved[$i]) || is_link($moved[$i])) {
                @unlink($moved[$i]);
            }
        }
        for ($i = count($backups) - 1; $i >= 0; $i--) {
            if (file_exists($backups[$i][0]) || is_link($backups[$i][0])) {
                pv_mkdir(dirname($backups[$i][1]));
                @rename($backups[$i][0], $backups[$i][1]);
            }
        }
        pv_remove_tree($stage);
        throw new RuntimeException('PULL_VERIFY_ROLLED_BACK ' . $error->getMessage(), 0, $error);
    }
}

function pv_pull(bool $upgrade): int
{
    $root = pv_target_root();
    $isUpdate = pv_prepare_target($root, $upgrade);
    $repository = pv_env('TRADE_PULL_REPOSITORY', PV_DEFAULT_REPOSITORY);
    $token = pv_token($root);
    $ref = pv_requested_ref();
    $resolved = pv_resolve_commit($repository, $ref, $token);
    $commit = $resolved['commit_sha'];
    $stage = $root . '/.trade-pull-stage-' . substr($commit, 0, 16);
    if (file_exists($stage)) {
        pv_fail('STAGE_ALREADY_EXISTS');
    }
    pv_mkdir($stage);

    $metadata = array();
    $hashes = array();
    $items = array();

    try {
        foreach (PV_SOURCE_FILES as $target => $sourcePath) {
            $body = pv_fetch_source($repository, $commit, $sourcePath, $token);
            pv_verify_source($target, $body);
            $stageFile = $stage . '/' . $target;
            pv_atomic_write($stageFile, $body, 0644);
            if (substr($target, -4) === '.php') {
                pv_lint($stageFile);
            }
            $hash = hash('sha256', $body);
            $hashes[$target] = $hash;
            $metadata[$target] = array(
                'github_path' => $sourcePath,
                'bytes' => strlen($body),
                'sha256' => $hash,
            );
            $items[] = $target;
        }

        foreach (PV_RUNTIME_DIRS as $directory) {
            pv_mkdir($root . '/' . $directory);
        }

        $markerTarget = $root . '/trade_phase3b_lite_v100/authority.json';
        if (!is_file($markerTarget)) {
            $relative = 'trade_phase3b_lite_v100/authority.json';
            pv_write_json($stage . '/' . $relative, pv_initial_marker($commit));
            $items[] = $relative;
            $hashes[$relative] = (string)hash_file('sha256', $stage . '/' . $relative);
        }

        $canonicalTarget = $root . '/trade_runtime_single/trade_state.json';
        if (!is_file($canonicalTarget)) {
            $relative = 'trade_runtime_single/trade_state.json';
            pv_write_json($stage . '/' . $relative, pv_initial_canonical());
            $items[] = $relative;
            $hashes[$relative] = (string)hash_file('sha256', $stage . '/' . $relative);
        }

        $state = array(
            'schema' => 'trade_pull_verify_v1',
            'version' => PV_VERSION,
            'managed_by' => PV_MANAGED_BY,
            'method' => 'PULL_AND_VERIFY',
            'repository' => $repository,
            'requested_ref' => $resolved['requested_ref'],
            'source_commit' => $commit,
            'verified_at' => date('c'),
            'target_root' => $root,
            'authority' => 'SINGLE_FILE_PAPER',
            'real_order_allowed' => false,
            'source_files' => $metadata,
            'runtime_directories' => PV_RUNTIME_DIRS,
        );
        pv_write_json($stage . '/trade_pull_verify_state.json', $state);
        $items[] = 'trade_pull_verify_state.json';
        $hashes['trade_pull_verify_state.json'] = (string)hash_file('sha256', $stage . '/trade_pull_verify_state.json');

        pv_commit_stage($root, $stage, $items, $hashes);

        foreach (PV_SOURCE_FILES as $target => $sourcePath) {
            if (!is_file($root . '/' . $target)) {
                pv_fail('POST_INSTALL_FILE_MISSING', $target);
            }
        }
        echo pv_json(array(
            'ok' => true,
            'action' => $isUpdate ? 'UPGRADED' : 'INSTALLED',
            'method' => 'PULL_AND_VERIFY',
            'repository' => $repository,
            'requested_ref' => $resolved['requested_ref'],
            'source_commit' => $commit,
            'target_root' => $root,
            'authority' => 'SINGLE_FILE_PAPER',
            'real_order_allowed' => false,
            'runtime_preserved_on_upgrade' => $isUpdate,
            'source_count' => count(PV_SOURCE_FILES),
        ), true) . PHP_EOL;
        return 0;
    } catch (Throwable $error) {
        if (is_dir($stage)) {
            pv_remove_tree($stage);
        }
        throw $error;
    }
}

function pv_verify_local(): int
{
    $root = pv_target_root();
    $state = pv_read_json($root . '/trade_pull_verify_state.json');
    if (!is_array($state) || ($state['schema'] ?? '') !== 'trade_pull_verify_v1') {
        pv_fail('LOCAL_PULL_STATE_MISSING');
    }
    foreach ((array)($state['source_files'] ?? array()) as $target => $row) {
        $file = $root . '/' . $target;
        $expected = is_array($row) ? (string)($row['sha256'] ?? '') : '';
        if ($expected === '' || !is_file($file)) {
            pv_fail('LOCAL_SOURCE_MISSING', $target);
        }
        $actual = hash_file('sha256', $file);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            pv_fail('LOCAL_HASH_MISMATCH', $target);
        }
        if (substr($target, -4) === '.php') {
            pv_lint($file);
        }
    }
    $marker = pv_read_json($root . '/trade_phase3b_lite_v100/authority.json');
    $canonical = pv_read_json($root . '/trade_runtime_single/trade_state.json');
    if (!is_array($marker) || strtoupper((string)($marker['authority'] ?? '')) !== 'SINGLE_FILE_PAPER'
        || !empty($marker['real_order_allowed'])) {
        pv_fail('LOCAL_AUTHORITY_INVALID');
    }
    if (!is_array($canonical) || ($canonical['schema'] ?? '') !== 'trade_state_v1') {
        pv_fail('LOCAL_CANONICAL_INVALID');
    }
    echo pv_json(array(
        'ok' => true,
        'action' => 'VERIFIED_LOCAL',
        'method' => 'PULL_AND_VERIFY',
        'source_commit' => (string)($state['source_commit'] ?? ''),
        'target_root' => $root,
        'authority' => 'SINGLE_FILE_PAPER',
        'real_order_allowed' => false,
        'source_count' => count((array)($state['source_files'] ?? array())),
    ), true) . PHP_EOL;
    return 0;
}

function pv_selftest(): int
{
    echo pv_json(array(
        'ok' => true,
        'version' => PV_VERSION,
        'method' => 'PULL_AND_VERIFY',
        'repository' => PV_DEFAULT_REPOSITORY,
        'default_ref' => PV_DEFAULT_REF,
        'default_target' => PV_DEFAULT_TARGET,
        'source_count' => count(PV_SOURCE_FILES),
        'real_order_allowed' => false,
        'web_route' => false,
        'ssh' => false,
    ), true) . PHP_EOL;
    return 0;
}

function pv_main(array $argv): int
{
    if (PHP_SAPI !== 'cli') {
        echo 'PULL_VERIFY_CLI_ONLY' . PHP_EOL;
        return 1;
    }
    $command = strtolower((string)($argv[1] ?? 'pull'));
    if ($command === '--pull') {
        $command = 'pull';
    } elseif ($command === '--upgrade') {
        $command = 'upgrade';
    } elseif ($command === '--verify') {
        $command = 'verify';
    } elseif ($command === '--selftest') {
        $command = 'selftest';
    }
    try {
        if ($command === 'pull') {
            return pv_pull(false);
        }
        if ($command === 'upgrade') {
            return pv_pull(true);
        }
        if ($command === 'verify') {
            return pv_verify_local();
        }
        if ($command === 'selftest') {
            return pv_selftest();
        }
        fwrite(STDERR, "Usage: php pull_verify_trade.php [pull|upgrade|verify|selftest]" . PHP_EOL);
        return 2;
    } catch (Throwable $error) {
        fwrite(STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL);
        return 1;
    }
}

exit(pv_main($argv ?? array()));
