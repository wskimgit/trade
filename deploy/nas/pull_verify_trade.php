<?php
/**
 * Trade NAS Pull & VERIFY deployer v1.0.0
 *
 * Save this one file on the NAS outside the web root and execute it locally
 * with the NAS PHP 7.4 runtime. It supports a locked-down mobile web control
 * screen and a local CLI fallback; it never uses SSH and pulls only from
 * GitHub over verified HTTPS.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

const PV_VERSION = '1.1.0';
const PV_MANAGED_BY = 'codex/trade-pull-verify';
const PV_DEFAULT_REPOSITORY = 'wskimgit/trade';
const PV_DEFAULT_REF = 'main';
const PV_DEFAULT_TARGET = '/volume1/web/trade';
const PV_DEFAULT_ALLOWED_PARENT = '/volume1/web';
const PV_DEFAULT_WEB_KEY_FILE = '/volume1/.trade_pull_web_key';
const PV_DEFAULT_WEB_LOCK_FILE = '/volume1/.trade_pull_verify_web.lock';
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
        'web_route' => true,
        'ssh' => false,
    ), true) . PHP_EOL;
    return 0;
}


function pv_web_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function pv_web_key_file(): string
{
    $file = pv_normalize_path(
        pv_env('TRADE_PULL_WEB_KEY_FILE', PV_DEFAULT_WEB_KEY_FILE)
    );
    $parent = pv_allowed_parent();
    $prefix = rtrim($parent, '/') . '/';
    if ($file === $parent || strpos($file, $prefix) === 0) {
        pv_fail('WEB_KEY_IN_WEB_SCOPE');
    }
    return $file;
}

function pv_web_key(): string
{
    $inline = pv_env('TRADE_PULL_WEB_KEY');
    $file = pv_web_key_file();
    if ($inline !== '' && is_file($file)) {
        pv_fail('WEB_KEY_SOURCE_AMBIGUOUS');
    }
    if ($inline !== '') {
        $key = $inline;
    } elseif (!is_file($file)) {
        return '';
    } else {
        if (!is_readable($file)) {
            pv_fail('WEB_KEY_FILE_NOT_READABLE');
        }
        $key = trim((string)@file_get_contents($file));
    }
    if (strlen($key) < 16) {
        pv_fail('WEB_KEY_TOO_SHORT');
    }
    return $key;
}

function pv_web_lock_file(): string
{
    $file = pv_normalize_path(
        pv_env('TRADE_PULL_LOCK_FILE', PV_DEFAULT_WEB_LOCK_FILE)
    );
    $parent = pv_allowed_parent();
    $prefix = rtrim($parent, '/') . '/';
    if ($file === $parent || strpos($file, $prefix) === 0) {
        pv_fail('WEB_LOCK_IN_WEB_SCOPE');
    }
    return $file;
}

function pv_web_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    @session_name('trade_pull_verify');
    @session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => pv_web_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    if (!@session_start()) {
        pv_fail('WEB_SESSION_FAILED');
    }
}

function pv_web_csrf(): string
{
    if (!isset($_SESSION['pv_web_csrf'])
        || !preg_match('/^[a-f0-9]{48}$/', (string)$_SESSION['pv_web_csrf'])) {
        try {
            $_SESSION['pv_web_csrf'] = bin2hex(random_bytes(24));
        } catch (Throwable $error) {
            pv_fail('WEB_RANDOM_FAILED');
        }
    }
    return (string)$_SESSION['pv_web_csrf'];
}

function pv_web_validate_csrf(): void
{
    $expected = pv_web_csrf();
    $provided = (string)($_POST['csrf'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        pv_fail('WEB_CSRF_INVALID');
    }
}

function pv_web_authenticated(): bool
{
    return !empty($_SESSION['pv_web_authenticated']);
}

function pv_web_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pv_web_status(): array
{
    $status = array(
        'target_root' => PV_DEFAULT_TARGET,
        'target_exists' => false,
        'target_entries' => 0,
        'source_commit' => '',
        'verified_at' => '',
        'authority' => '',
        'real_order_allowed' => false,
        'source_count' => count(PV_SOURCE_FILES),
        'status_error' => '',
    );
    try {
        $root = pv_target_root();
        $status['target_root'] = $root;
        $status['target_exists'] = is_dir($root);
        if (is_dir($root)) {
            $status['target_entries'] = count(pv_entries($root));
        }
        $state = pv_read_json($root . '/trade_pull_verify_state.json');
        if (is_array($state)) {
            $status['source_commit'] = (string)($state['source_commit'] ?? '');
            $status['verified_at'] = (string)($state['verified_at'] ?? '');
        }
        $marker = pv_read_json($root . '/trade_phase3b_lite_v100/authority.json');
        if (is_array($marker)) {
            $status['authority'] = (string)($marker['authority'] ?? '');
            $status['real_order_allowed'] = !empty($marker['real_order_allowed']);
        }
    } catch (Throwable $error) {
        $status['status_error'] = $error->getMessage();
    }
    return $status;
}

function pv_web_lock()
{
    $handle = @fopen(pv_web_lock_file(), 'c');
    if ($handle === false) {
        pv_fail('WEB_LOCK_OPEN_FAILED');
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        pv_fail('WEB_ACTION_IN_PROGRESS');
    }
    return $handle;
}

function pv_web_unlock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function pv_web_run_action(string $action): array
{
    $action = strtolower($action);
    ob_start();
    $started = microtime(true);
    try {
        if ($action === 'pull') {
            $exitCode = pv_pull(false);
        } elseif ($action === 'upgrade') {
            $exitCode = pv_pull(true);
        } elseif ($action === 'verify') {
            $exitCode = pv_verify_local();
        } elseif ($action === 'selftest') {
            $exitCode = pv_selftest();
        } else {
            pv_fail('WEB_ACTION_INVALID');
        }
        $output = (string)ob_get_clean();
        return array(
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => trim($output),
            'elapsed_seconds' => round(microtime(true) - $started, 2),
        );
    } catch (Throwable $error) {
        $output = (string)ob_get_clean();
        return array(
            'ok' => false,
            'exit_code' => 1,
            'stdout' => trim($output),
            'error' => $error->getMessage(),
            'elapsed_seconds' => round(microtime(true) - $started, 2),
        );
    }
}

function pv_web_render(string $message = '', ?array $result = null): void
{
    $keyReady = false;
    $keyPath = PV_DEFAULT_WEB_KEY_FILE;
    $keyError = '';
    try {
        $keyPath = pv_web_key_file();
        $keyReady = pv_web_key() !== '';
    } catch (Throwable $error) {
        $keyError = $error->getMessage();
    }
    $status = pv_web_status();
    $loggedIn = pv_web_authenticated();
    $csrf = pv_web_csrf();
    $https = pv_web_is_https();

    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Trade Pull &amp; VERIFY</title>';
    echo '<style>'
        . 'body{margin:0;background:#f4f6f8;color:#17212b;font-family:system-ui,-apple-system,'
        . 'BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:16px}'
        . 'main{max-width:720px;margin:0 auto;padding:18px 14px 40px}'
        . 'h1{font-size:23px;margin:4px 0 8px}'
        . 'h2{font-size:18px;margin:0 0 10px}'
        . '.card{background:#fff;border:1px solid #d9e0e7;border-radius:12px;'
        . 'padding:16px;margin:12px 0;box-shadow:0 1px 2px rgba(0,0,0,.04)}'
        . '.muted{color:#5d6a75;font-size:14px}'
        . '.ok{color:#0b6b3a}.warn{color:#9a4b00}.err{color:#a51d2d}'
        . 'label{display:block;font-weight:600;margin:12px 0 6px}'
        . 'input[type=password]{width:100%;box-sizing:border-box;padding:12px;border:1px solid #aeb8c2;'
        . 'border-radius:8px;font-size:16px}'
        . 'button{border:0;border-radius:8px;padding:12px 14px;margin:5px 4px 5px 0;'
        . 'font-size:16px;font-weight:700;background:#1769aa;color:#fff}'
        . 'button.secondary{background:#5f6b76}.danger{background:#9a2535!important}'
        . '.row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf0f2;'
        . 'padding:7px 0}.row:last-child{border-bottom:0}.value{text-align:right;word-break:break-word}'
        . 'pre{white-space:pre-wrap;word-break:break-word;background:#101820;color:#d9f1df;'
        . 'padding:12px;border-radius:8px;overflow:auto;font-size:13px}'
        . 'code{word-break:break-all}.notice{padding:10px;border-radius:8px;background:#fff4d6;margin:10px 0}'
        . '</style></head><body><main>';
    echo '<h1>Trade Pull &amp; VERIFY</h1>';
    echo '<p class="muted">브라우저 전용 · 독립 경로 <code>'
        . pv_web_h(PV_DEFAULT_TARGET) . '</code> · PAPER 전용</p>';

    if (!$https && !pv_bool('TRADE_PULL_ALLOW_HTTP', false)) {
        echo '<div class="notice warn">HTTPS 주소로 접속해야 웹 키와 작업 요청이 허용됩니다.</div>';
    }
    if ($message !== '') {
        echo '<div class="card err"><strong>메시지</strong><pre>'
            . pv_web_h($message) . '</pre></div>';
    }
    if ($result !== null) {
        $resultText = trim((string)($result['stdout'] ?? ''));
        if (!$result['ok']) {
            $errorText = (string)($result['error'] ?? 'WEB_ACTION_FAILED');
            $resultText = trim($resultText . ($resultText !== '' ? PHP_EOL : '') . $errorText);
        }
        if ($resultText === '') {
            $resultText = '(출력 없음)';
        }
        echo '<div class="card ' . ($result['ok'] ? 'ok' : 'err') . '"><h2>'
            . ($result['ok'] ? '완료' : '실패') . '</h2><pre>'
            . pv_web_h($resultText) . '</pre>';
        echo '<p class="muted">소요 시간: ' . pv_web_h($result['elapsed_seconds'] ?? '') . '초</p></div>';
    }

    echo '<section class="card"><h2>현재 상태</h2>';
    echo '<div class="row"><span>대상</span><span class="value"><code>'
        . pv_web_h($status['target_root']) . '</code></span></div>';
    echo '<div class="row"><span>대상 폴더</span><span class="value">'
        . ($status['target_exists'] ? '존재' : '없음') . '</span></div>';
    echo '<div class="row"><span>대상 항목 수</span><span class="value">'
        . pv_web_h($status['target_entries']) . '</span></div>';
    echo '<div class="row"><span>소스 파일 수</span><span class="value">'
        . pv_web_h($status['source_count']) . '</span></div>';
    echo '<div class="row"><span>마지막 커밋</span><span class="value"><code>'
        . pv_web_h($status['source_commit'] !== '' ? $status['source_commit'] : '미설치') . '</code></span></div>';
    echo '<div class="row"><span>권한</span><span class="value">'
        . pv_web_h($status['authority'] !== '' ? $status['authority'] : '미확인') . '</span></div>';
    echo '<div class="row"><span>실주문 허용</span><span class="value">'
        . ($status['real_order_allowed'] ? '<span class="err">true</span>' : '<span class="ok">false</span>')
        . '</span></div>';
    if ($status['verified_at'] !== '') {
        echo '<div class="row"><span>검증 시각</span><span class="value">'
            . pv_web_h($status['verified_at']) . '</span></div>';
    }
    if ($status['status_error'] !== '') {
        echo '<p class="err">상태 조회: ' . pv_web_h($status['status_error']) . '</p>';
    }
    echo '</section>';

    if (!$loggedIn) {
        echo '<section class="card"><h2>관리자 로그인</h2>';
        if (!$keyReady) {
            echo '<p class="warn">웹 키가 아직 설정되지 않았습니다.</p>';
            echo '<p>DSM File Station에서 웹 루트 바깥에 다음 파일을 만들고, 16자 이상의 비밀 문자열 한 줄만 저장하십시오.</p>';
            echo '<p><code>' . pv_web_h($keyPath) . '</code></p>';
            if ($keyError !== '') {
                echo '<pre>' . pv_web_h($keyError) . '</pre>';
            }
        } else {
            echo '<form method="post"><input type="hidden" name="action" value="login">';
            echo '<input type="hidden" name="csrf" value="' . pv_web_h($csrf) . '">';
            echo '<label for="web_key">웹 키</label>';
            echo '<input id="web_key" name="web_key" type="password" autocomplete="current-password" required>';
            echo '<button type="submit">로그인</button></form>';
        }
        echo '</section>';
    } else {
        echo '<section class="card"><h2>작업</h2>';
        echo '<form method="post">';
        echo '<input type="hidden" name="csrf" value="' . pv_web_h($csrf) . '">';
        echo '<button type="submit" name="action" value="verify">로컬 VERIFY</button>';
        echo '<button type="submit" name="action" value="pull">최초 PULL</button>';
        echo '<p><label><input type="checkbox" name="confirm_upgrade" value="1"> '
            . '기존 trade 상태를 보존한 채 GitHub 최신 검증본으로 교체하는 데 동의</label>';
        echo '<button class="danger" type="submit" name="action" value="upgrade">UPGRADE</button>';
        echo '</form>';
        echo '<p class="muted">PULL은 비어 있는 대상에서만 동작합니다. 이미 설치된 대상은 UPGRADE를 사용하십시오. '
            . '모든 작업은 GitHub 커밋 확인, allowlist, SHA-256, PHP 구문검사 후 원자적으로 활성화됩니다.</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . pv_web_h($csrf) . '">';
        echo '<button class="secondary" type="submit" name="action" value="logout">로그아웃</button></form>';
        echo '</section>';
    }

    echo '<p class="muted">버전 ' . pv_web_h(PV_VERSION)
        . ' · SINGLE_FILE_PAPER · real_order_allowed=false</p>';
    echo '</main></body></html>';
}

function pv_web_main(): int
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    try {
        pv_web_session_start();
    } catch (Throwable $error) {
        echo '<pre>PULL_VERIFY_WEB_SESSION_FAILED '
            . pv_web_h($error->getMessage()) . '</pre>';
        return 1;
    }

    $message = '';
    $result = null;
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        try {
            pv_web_validate_csrf();
            if ($action === 'login') {
                if (!pv_web_is_https() && !pv_bool('TRADE_PULL_ALLOW_HTTP', false)) {
                    pv_fail('HTTPS_REQUIRED');
                }
                $key = pv_web_key();
                $provided = trim((string)($_POST['web_key'] ?? ''));
                if ($key === '' || $provided === '' || !hash_equals($key, $provided)) {
                    usleep(250000);
                    pv_fail('WEB_LOGIN_FAILED');
                }
                @session_regenerate_id(true);
                $_SESSION['pv_web_authenticated'] = true;
                $message = '로그인되었습니다.';
            } elseif ($action === 'logout') {
                unset($_SESSION['pv_web_authenticated']);
                @session_regenerate_id(true);
                $message = '로그아웃되었습니다.';
            } elseif (!pv_web_authenticated()) {
                pv_fail('WEB_LOGIN_REQUIRED');
            } else {
                if (!pv_web_is_https() && !pv_bool('TRADE_PULL_ALLOW_HTTP', false)) {
                    pv_fail('HTTPS_REQUIRED');
                }
                if ($action === 'upgrade'
                    && (string)($_POST['confirm_upgrade'] ?? '') !== '1') {
                    pv_fail('UPGRADE_CONFIRM_REQUIRED');
                }
                if (!in_array($action, array('pull', 'upgrade', 'verify', 'selftest'), true)) {
                    pv_fail('WEB_ACTION_INVALID');
                }
                $lock = pv_web_lock();
                try {
                    $result = pv_web_run_action($action);
                } finally {
                    pv_web_unlock($lock);
                }
            }
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }
    }
    pv_web_render($message, $result);
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

if (PHP_SAPI !== 'cli') {
    exit(pv_web_main());
}
exit(pv_main($argv ?? array()));
