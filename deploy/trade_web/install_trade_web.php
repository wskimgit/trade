<?php
/**
 * Independent trade-web installer for Synology PHP 7.4+.
 *
 * This file is intentionally CLI-only. It installs the pinned source tree into
 * /volume1/web/trade and never touches /volume1/web or any other application.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

const TW_INSTALLER_VERSION = '1.0.0';
const TW_MANAGED_BY = 'codex/trade-web-installer';
const TW_DEFAULT_REPOSITORY = 'wskimgit/trade';
const TW_DEFAULT_BRANCH = 'main';
const TW_DEFAULT_WEB_ROOT = '/volume1/web/trade';

const TW_SOURCE_FILES = array(
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

const TW_RUNTIME_DIRS = array(
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

function tw_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function tw_bool_env(string $name, bool $default = false): bool
{
    $value = strtolower(tw_env($name, $default ? '1' : '0'));
    return in_array($value, array('1', 'true', 'yes', 'on'), true);
}

function tw_fail(string $code, string $detail = ''): void
{
    $message = $detail === '' ? $code : $code . ' ' . $detail;
    throw new RuntimeException($message);
}

function tw_json($value, bool $pretty = false): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    try {
        $encoded = json_encode($value, $flags | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        tw_fail('JSON_ENCODE_FAILED', $error->getMessage());
    }
    return (string)$encoded;
}

function tw_normalize_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = rtrim($path, '/');
    if ($path === '' || $path[0] !== '/') {
        tw_fail('ABSOLUTE_PATH_REQUIRED', $path);
    }
    if (preg_match('#(^|/)\.\.?(/|$)#', $path)) {
        tw_fail('PATH_TRAVERSAL_REJECTED', $path);
    }
    return $path;
}

function tw_allowed_parent(): string
{
    $parent = tw_normalize_path(tw_env('TRADE_ALLOWED_PARENT', '/volume1/web'));
    if ($parent === '/' || !is_dir($parent)) {
        tw_fail('ALLOWED_PARENT_DIRECTORY_INVALID', $parent);
    }
    return $parent;
}

function tw_web_root(string $requested = ''): string
{
    $raw = trim($requested) !== '' ? trim($requested) : tw_env('TRADE_WEB_ROOT', TW_DEFAULT_WEB_ROOT);
    $root = tw_normalize_path($raw);
    $blocked = array('/', '/volume1', '/volume1/web');
    if (in_array($root, $blocked, true)) {
        tw_fail('UNSAFE_WEB_ROOT', $root);
    }
    $parent = tw_allowed_parent();
    $prefix = rtrim($parent, '/') . '/';
    if ($root === $parent || strpos($root, $prefix) !== 0) {
        tw_fail('WEB_ROOT_OUTSIDE_ALLOWED_PARENT', $root);
    }
    if (!is_dir(dirname($root))) {
        tw_fail('WEB_PARENT_DIRECTORY_MISSING', dirname($root));
    }
    return $root;
}

function tw_ensure_dir(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }
    if (is_link($directory) || (!@mkdir($directory, 0775, true) && !is_dir($directory))) {
        tw_fail('DIRECTORY_CREATE_FAILED', $directory);
    }
}

function tw_atomic_write(string $file, string $content, int $mode = 0644): void
{
    $directory = dirname($file);
    tw_ensure_dir($directory);
    $temporary = @tempnam($directory, '.trade-write-');
    if ($temporary === false) {
        tw_fail('TEMP_FILE_CREATE_FAILED', $file);
    }
    @chmod($temporary, $mode);
    $written = @file_put_contents($temporary, $content, LOCK_EX);
    if ($written === false || $written !== strlen($content)) {
        @unlink($temporary);
        tw_fail('FILE_WRITE_FAILED', $file);
    }
    if (!@rename($temporary, $file)) {
        @unlink($temporary);
        tw_fail('FILE_RENAME_FAILED', $file);
    }
}

function tw_write_json(string $file, array $value, int $mode = 0640): void
{
    tw_atomic_write($file, tw_json($value, true) . PHP_EOL, $mode);
}

function tw_read_json(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        return null;
    }
    return is_array($decoded) ? $decoded : null;
}

function tw_repo_url(string $repository, string $path): string
{
    $parts = explode('/', trim($repository), 2);
    if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
        tw_fail('GITHUB_REPOSITORY_INVALID', $repository);
    }
    $segments = array();
    foreach (explode('/', trim($path, '/')) as $segment) {
        $segments[] = rawurlencode($segment);
    }
    return 'https://api.github.com/repos/' . rawurlencode($parts[0]) . '/'
        . rawurlencode($parts[1]) . '/' . implode('/', $segments);
}

function tw_github_json(string $url, string $token): array
{
    if (!function_exists('curl_init')) {
        tw_fail('CURL_EXTENSION_REQUIRED');
    }
    $headers = array(
        'Accept: application/vnd.github+json',
        'User-Agent: trade-web-installer/' . TW_INSTALLER_VERSION,
    );
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $handle = curl_init();
    if ($handle === false) {
        tw_fail('CURL_INIT_FAILED');
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
        tw_fail('GITHUB_REQUEST_FAILED', 'curl_errno=' . $errorNo);
    }
    if ($status < 200 || $status >= 300) {
        tw_fail('GITHUB_HTTP_FAILED', 'status=' . $status);
    }
    try {
        $decoded = json_decode((string)$body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        tw_fail('GITHUB_JSON_INVALID', $error->getMessage());
    }
    if (!is_array($decoded)) {
        tw_fail('GITHUB_RESPONSE_NOT_OBJECT');
    }
    return $decoded;
}

function tw_token(string $root): string
{
    $inline = tw_env('TRADE_GITHUB_TOKEN');
    $file = tw_env('TRADE_GITHUB_TOKEN_FILE');
    if ($inline !== '' && $file !== '') {
        tw_fail('TOKEN_SOURCE_AMBIGUOUS');
    }
    if ($inline !== '') {
        return $inline;
    }
    if ($file === '') {
        return '';
    }
    $file = tw_normalize_path($file);
    $rootPrefix = rtrim($root, '/') . '/';
    $allowedPrefix = rtrim(tw_allowed_parent(), '/') . '/';
    if ($file === $root || strpos($file, $rootPrefix) === 0
        || $file === tw_allowed_parent() || strpos($file, $allowedPrefix) === 0) {
        tw_fail('TOKEN_FILE_MUST_BE_OUTSIDE_WEB_ROOT');
    }
    if (!is_file($file) || !is_readable($file)) {
        tw_fail('TOKEN_FILE_NOT_READABLE', $file);
    }
    $value = trim((string)@file_get_contents($file));
    if ($value === '') {
        tw_fail('TOKEN_FILE_EMPTY');
    }
    return $value;
}

function tw_resolve_commit(string $repository, string $requested, string $token): array
{
    $requested = $requested !== '' ? $requested : tw_env('TRADE_GITHUB_BRANCH', TW_DEFAULT_BRANCH);
    $url = tw_repo_url($repository, 'commits/' . $requested);
    $payload = tw_github_json($url, $token);
    $sha = strtolower(trim((string)($payload['sha'] ?? '')));
    if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
        tw_fail('GITHUB_COMMIT_SHA_INVALID');
    }
    return array('requested_ref' => $requested, 'commit_sha' => $sha);
}

function tw_fetch_source(string $repository, string $commit, string $sourcePath, string $token): string
{
    $url = tw_repo_url($repository, 'contents/' . trim($sourcePath, '/'))
        . '?ref=' . rawurlencode($commit);
    $payload = tw_github_json($url, $token);
    if (($payload['type'] ?? '') !== 'file') {
        tw_fail('GITHUB_SOURCE_NOT_FILE', $sourcePath);
    }
    $encoded = preg_replace('/\s+/', '', (string)($payload['content'] ?? ''));
    if ($encoded === '') {
        tw_fail('GITHUB_SOURCE_CONTENT_MISSING', $sourcePath);
    }
    $decoded = base64_decode($encoded, true);
    if ($decoded === false) {
        tw_fail('GITHUB_SOURCE_BASE64_INVALID', $sourcePath);
    }
    return $decoded;
}

function tw_lint(string $file): void
{
    if (!function_exists('proc_open')) {
        tw_fail('PROC_OPEN_REQUIRED_FOR_LINT');
    }
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    $pipes = array();
    $process = @proc_open($command, array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    ), $pipes);
    if (!is_resource($process)) {
        tw_fail('PHP_LINT_PROCESS_FAILED', basename($file));
    }
    @fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]);
    @fclose($pipes[1]);
    @fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        $detail = trim($stderr !== '' ? $stderr : $stdout);
        tw_fail('PHP_LINT_FAILED', basename($file) . ' ' . $detail);
    }
}

function tw_remove_tree(string $directory): void
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
            tw_remove_tree($directory . '/' . $entry);
        }
    }
    @rmdir($directory);
}

function tw_target_entries(string $root): array
{
    if (!is_dir($root)) {
        return array();
    }
    $entries = @scandir($root);
    if (!is_array($entries)) {
        tw_fail('WEB_ROOT_SCAN_FAILED', $root);
    }
    return array_values(array_filter($entries, function ($entry) {
        return $entry !== '.' && $entry !== '..';
    }));
}

function tw_prepare_target(string $root, bool $allowReinstall = false): bool
{
    if (is_link($root) || (file_exists($root) && !is_dir($root))) {
        tw_fail('WEB_ROOT_NOT_DIRECTORY', $root);
    }
    if (!is_dir($root)) {
        tw_ensure_dir($root);
        return false;
    }

    $entries = tw_target_entries($root);
    foreach ($entries as $entry) {
        if (strpos($entry, '.trade-stage-') === 0) {
            tw_fail('STALE_STAGE_PRESENT', $root . '/' . $entry);
        }
    }
    $state = tw_read_json($root . '/trade_install_state.json');
    if ($state === null) {
        if (count($entries) !== 0) {
            tw_fail('WEB_ROOT_NOT_EMPTY', $root);
        }
        return false;
    }
    if (($state['schema'] ?? '') !== 'trade_web_install_v1'
        || ($state['managed_by'] ?? '') !== TW_MANAGED_BY) {
        tw_fail('EXISTING_TARGET_NOT_MANAGED');
    }
    if (!$allowReinstall) {
        tw_fail('REINSTALL_REQUIRES_EXPLICIT_WEB_CONFIRMATION');
    }
    $marker = tw_read_json($root . '/trade_phase3b_lite_v100/authority.json');
    if (!is_array($marker)
        || ($marker['schema'] ?? '') !== 'trade_authority_v1'
        || strtoupper((string)($marker['authority'] ?? '')) !== 'SINGLE_FILE_PAPER'
        || !empty($marker['real_order_allowed'])) {
        tw_fail('EXISTING_TARGET_NOT_SAFE_PAPER');
    }
    return true;
}

function tw_initial_marker(string $commit): array
{
    return array(
        'schema' => 'trade_authority_v1',
        'authority' => 'SINGLE_FILE_PAPER',
        'real_order_allowed' => false,
        'execution_mode' => 'PAPER',
        'managed_by' => TW_MANAGED_BY,
        'source_commit' => $commit,
        'created_at' => date('c'),
    );
}

function tw_initial_state(): array
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

function tw_commit_stage(string $root, string $stage, array $items, array $expectedHashes): void
{
    $backups = array();
    $moved = array();
    try {
        foreach ($items as $relative) {
            $source = $stage . '/' . $relative;
            if (!is_file($source)) {
                tw_fail('STAGED_FILE_MISSING', $relative);
            }
            $destination = $root . '/' . $relative;
            if (file_exists($destination) || is_link($destination)) {
                $backup = $stage . '/previous/' . $relative;
                tw_ensure_dir(dirname($backup));
                if (!@rename($destination, $backup)) {
                    tw_fail('BACKUP_RENAME_FAILED', $relative);
                }
                $backups[] = array($backup, $destination);
            }
        }

        foreach ($items as $relative) {
            $source = $stage . '/' . $relative;
            $destination = $root . '/' . $relative;
            tw_ensure_dir(dirname($destination));
            if (!@rename($source, $destination)) {
                tw_fail('STAGED_RENAME_FAILED', $relative);
            }
            $moved[] = $destination;
        }

        foreach ($expectedHashes as $relative => $expected) {
            $actual = is_file($root . '/' . $relative)
                ? hash_file('sha256', $root . '/' . $relative)
                : false;
            if (!is_string($actual) || !hash_equals($expected, $actual)) {
                tw_fail('POST_INSTALL_HASH_FAILED', $relative);
            }
        }
        tw_remove_tree($stage);
    } catch (Throwable $error) {
        for ($i = count($moved) - 1; $i >= 0; $i--) {
            if (is_file($moved[$i]) || is_link($moved[$i])) {
                @unlink($moved[$i]);
            }
        }
        for ($i = count($backups) - 1; $i >= 0; $i--) {
            if (file_exists($backups[$i][0]) || is_link($backups[$i][0])) {
                tw_ensure_dir(dirname($backups[$i][1]));
                @rename($backups[$i][0], $backups[$i][1]);
            }
        }
        tw_remove_tree($stage);
        throw new RuntimeException('INSTALL_ROLLED_BACK ' . $error->getMessage(), 0, $error);
    }
}

function tw_install(string $requestedRoot = '', bool $allowReinstall = false, string $requestedRef = ''): int
{
    $root = tw_web_root($requestedRoot);
    $isUpdate = tw_prepare_target($root, $allowReinstall);
    $repository = tw_env('TRADE_GITHUB_REPOSITORY', TW_DEFAULT_REPOSITORY);
    $token = tw_token($root);
    $requested = $requestedRef !== '' ? $requestedRef : tw_env('TRADE_GITHUB_REF');
    $resolved = tw_resolve_commit($repository, $requested, $token);
    $commit = $resolved['commit_sha'];

    $stage = $root . '/.trade-stage-' . substr($commit, 0, 16);
    if (file_exists($stage)) {
        tw_fail('STAGE_ALREADY_EXISTS', $stage);
    }
    tw_ensure_dir($stage);

    $bodies = array();
    $hashes = array();
    $metadata = array();
    $items = array();

    try {
        foreach (TW_SOURCE_FILES as $target => $sourcePath) {
            $body = tw_fetch_source($repository, $commit, $sourcePath, $token);
            if (substr($target, -4) === '.php' && strpos(ltrim($body), '<?php') !== 0) {
                tw_fail('PHP_SOURCE_HEADER_INVALID', $sourcePath);
            }
            $stageFile = $stage . '/' . $target;
            tw_atomic_write($stageFile, $body, 0644);
            tw_lint($stageFile);
            $bodies[$target] = $body;
            $hashes[$target] = hash('sha256', $body);
            $metadata[$target] = array(
                'github_path' => $sourcePath,
                'bytes' => strlen($body),
                'sha256' => $hashes[$target],
            );
            $items[] = $target;
        }

        foreach (TW_RUNTIME_DIRS as $directory) {
            tw_ensure_dir($root . '/' . $directory);
        }

        $markerTarget = $root . '/trade_phase3b_lite_v100/authority.json';
        if (!is_file($markerTarget)) {
            $markerRelative = 'trade_phase3b_lite_v100/authority.json';
            tw_write_json($stage . '/' . $markerRelative, tw_initial_marker($commit));
            $items[] = $markerRelative;
            $hashes[$markerRelative] = (string)hash_file('sha256', $stage . '/' . $markerRelative);
        }

        $stateTarget = $root . '/trade_runtime_single/trade_state.json';
        if (!is_file($stateTarget)) {
            $stateRelative = 'trade_runtime_single/trade_state.json';
            tw_write_json($stage . '/' . $stateRelative, tw_initial_state());
            $items[] = $stateRelative;
            $hashes[$stateRelative] = (string)hash_file('sha256', $stage . '/' . $stateRelative);
        }

        $installState = array(
            'schema' => 'trade_web_install_v1',
            'installer_version' => TW_INSTALLER_VERSION,
            'managed_by' => TW_MANAGED_BY,
            'repository' => $repository,
            'requested_ref' => $resolved['requested_ref'],
            'source_commit' => $commit,
            'installed_at' => date('c'),
            'target_root' => $root,
            'mode' => 'SINGLE_FILE_PAPER',
            'real_order_allowed' => false,
            'runtime_policy' => 'flat-root-preserved',
            'source_files' => $metadata,
            'runtime_directories' => TW_RUNTIME_DIRS,
        );
        tw_write_json($stage . '/trade_install_state.json', $installState);
        $items[] = 'trade_install_state.json';
        $hashes['trade_install_state.json'] = (string)hash_file('sha256', $stage . '/trade_install_state.json');

        tw_commit_stage($root, $stage, $items, $hashes);

        foreach (TW_SOURCE_FILES as $target => $sourcePath) {
            if (!is_file($root . '/' . $target)) {
                tw_fail('POST_INSTALL_FILE_MISSING', $target);
            }
        }
        if (!is_file($root . '/trade_phase3b_lite_v100/authority.json')
            || !is_file($root . '/trade_runtime_single/trade_state.json')) {
            tw_fail('POST_INSTALL_PAPER_STATE_MISSING');
        }

        echo ($isUpdate ? 'UPDATED' : 'INSTALLED') . ' ' . $repository . '@' . $commit . PHP_EOL;
        echo 'TARGET ' . $root . PHP_EOL;
        echo 'MODE SINGLE_FILE_PAPER REAL=false' . PHP_EOL;
        echo 'Run: php ' . $root . '/trade_runner.php health' . PHP_EOL;
        return 0;
    } catch (Throwable $error) {
        if (is_dir($stage)) {
            tw_remove_tree($stage);
        }
        throw $error;
    }
}

function tw_post_value(string $name): string
{
    $value = $_POST[$name] ?? '';
    return is_string($value) ? $value : '';
}

function tw_web_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    @session_name('trade_web_installer');
    @session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    if (!@session_start()) {
        tw_fail('WEB_SESSION_START_FAILED');
    }
    if (empty($_SESSION['trade_web_csrf'])) {
        $_SESSION['trade_web_csrf'] = bin2hex(random_bytes(32));
    }
}

function tw_web_csrf(): string
{
    $token = $_SESSION['trade_web_csrf'] ?? '';
    if (!is_string($token) || $token === '') {
        tw_fail('WEB_CSRF_NOT_READY');
    }
    return $token;
}

function tw_web_secret(): string
{
    $inline = tw_env('TRADE_WEB_INSTALL_SECRET');
    $file = tw_env('TRADE_WEB_INSTALL_SECRET_FILE');
    if ($inline !== '' && $file !== '') {
        tw_fail('WEB_SECRET_SOURCE_AMBIGUOUS');
    }
    if ($inline !== '') {
        return $inline;
    }
    if ($file === '') {
        tw_fail('WEB_INSTALL_SECRET_NOT_CONFIGURED');
    }
    $file = tw_normalize_path($file);
    $parent = tw_allowed_parent();
    $parentPrefix = rtrim($parent, '/') . '/';
    if ($file === $parent || strpos($file, $parentPrefix) === 0) {
        tw_fail('WEB_SECRET_FILE_MUST_BE_OUTSIDE_ALLOWED_PARENT');
    }
    if (!is_file($file) || !is_readable($file)) {
        tw_fail('WEB_SECRET_FILE_NOT_READABLE', $file);
    }
    $secret = trim((string)@file_get_contents($file));
    if ($secret === '') {
        tw_fail('WEB_INSTALL_SECRET_EMPTY');
    }
    return $secret;
}

function tw_web_secret_ready(): bool
{
    try {
        return tw_web_secret() !== '';
    } catch (Throwable $error) {
        return false;
    }
}

function tw_web_authenticate(): void
{
    $secret = tw_web_secret();
    $provided = tw_post_value('installer_key');
    if ($provided === '') {
        tw_fail('WEB_INSTALL_KEY_REQUIRED');
    }
    $expectedHash = hash('sha256', $secret);
    $providedHash = hash('sha256', $provided);
    if (!hash_equals($expectedHash, $providedHash)) {
        tw_fail('WEB_INSTALL_KEY_INVALID');
    }
}

function tw_web_ref(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        $value = tw_env('TRADE_GITHUB_REF', TW_DEFAULT_BRANCH);
    }
    if (strlen($value) > 200 || strpos($value, '..') !== false
        || !preg_match('/^[A-Za-z0-9._\/-]+$/', $value)) {
        tw_fail('GITHUB_REF_INVALID');
    }
    return $value;
}

function tw_web_target_status(string $root): array
{
    if (!is_dir($root)) {
        return array('label' => 'NEW_TARGET', 'commit' => '', 'mode' => '');
    }
    $state = tw_read_json($root . '/trade_install_state.json');
    if (!is_array($state)) {
        $entries = tw_target_entries($root);
        return array(
            'label' => count($entries) === 0 ? 'EMPTY_TARGET' : 'UNMANAGED_OR_NONEMPTY',
            'commit' => '',
            'mode' => '',
        );
    }
    return array(
        'label' => 'MANAGED_TARGET',
        'commit' => (string)($state['source_commit'] ?? ''),
        'mode' => (string)($state['mode'] ?? ''),
    );
}

function tw_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tw_web_render(array $form, string $message = '', string $error = ''): void
{
    $root = (string)($form['web_root'] ?? tw_env('TRADE_WEB_ROOT', TW_DEFAULT_WEB_ROOT));
    $ref = (string)($form['github_ref'] ?? tw_env('TRADE_GITHUB_REF', TW_DEFAULT_BRANCH));
    $confirm = (string)($form['confirm_target'] ?? '');
    $allowReinstall = !empty($form['allow_reinstall']);
    $action = tw_h((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $status = array('label' => 'UNKNOWN', 'commit' => '', 'mode' => '');
    try {
        $status = tw_web_target_status(tw_web_root($root));
    } catch (Throwable $statusError) {
        $status['label'] = 'INVALID_TARGET';
    }
    $secretReady = tw_web_secret_ready();
    $csrf = tw_h(tw_web_csrf());

    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta http-equiv="Cache-Control" content="no-store">';
    echo '<title>Trade Web Installer</title>';
    echo '<style>body{font-family:system-ui,-apple-system,sans-serif;max-width:760px;margin:32px auto;padding:0 16px;color:#202124;background:#f7f8fa}main{background:#fff;border:1px solid #d8dce3;border-radius:12px;padding:24px;box-shadow:0 2px 8px #0000000d}h1{font-size:22px;margin-top:0}label{display:block;font-weight:600;margin-top:16px}input[type=text],input[type=password]{box-sizing:border-box;width:100%;margin-top:6px;padding:10px;border:1px solid #b8bec8;border-radius:7px;font-size:14px}input[type=checkbox]{margin-right:8px}.hint{color:#5f6368;font-size:13px;line-height:1.5}.ok{background:#e6f4ea;color:#137333;padding:10px;border-radius:7px;margin:12px 0}.err{background:#fce8e6;color:#a50e0e;padding:10px;border-radius:7px;margin:12px 0}.warn{background:#fff4ce;color:#7a4f01;padding:10px;border-radius:7px;margin:12px 0}button{margin-top:22px;background:#1a73e8;color:#fff;border:0;border-radius:7px;padding:11px 16px;font-weight:600;cursor:pointer}.mono{font-family:ui-monospace,SFMono-Regular,monospace;word-break:break-all}</style>';
    echo '</head><body><main><h1>Trade 독립 웹 설치</h1>';
    echo '<p class="hint">기존 /web과 분리된 NAS 폴더에 GitHub 소스를 내려받아 저장합니다. 이 화면은 POST와 설치 키가 모두 필요합니다.</p>';
    if ($message !== '') {
        echo '<div class="ok"><pre>' . tw_h($message) . '</pre></div>';
    }
    if ($error !== '') {
        echo '<div class="err"><b>설치 중단</b><pre>' . tw_h($error) . '</pre></div>';
    }
    echo '<div class="warn">현재 권한: ' . ($secretReady ? '설치 키 설정됨' : '설치 키 미설정 — 서버 환경변수 또는 외부 secret 파일을 먼저 설정해야 함') . '</div>';
    echo '<p class="hint">현재 대상 상태: <b>' . tw_h((string)$status['label']) . '</b>';
    if ($status['commit'] !== '') {
        echo ' · commit <span class="mono">' . tw_h($status['commit']) . '</span>';
    }
    if ($status['mode'] !== '') {
        echo ' · mode ' . tw_h($status['mode']);
    }
    echo '</p>';
    echo '<form method="post" action="' . $action . '">';
    echo '<input type="hidden" name="csrf" value="' . $csrf . '">';
    echo '<label>저장할 NAS 폴더';
    echo '<input type="text" name="web_root" value="' . tw_h($root) . '" required>';
    echo '</label><p class="hint">허용 부모: ' . tw_h(tw_env('TRADE_ALLOWED_PARENT', '/volume1/web')) . ' 이하만 가능합니다. 기본값은 /volume1/web/trade 입니다.</p>';
    echo '<label>GitHub ref';
    echo '<input type="text" name="github_ref" value="' . tw_h($ref) . '" required>';
    echo '</label><p class="hint">ref는 설치 전에 commit SHA로 고정됩니다. 저장소는 서버 설정의 TRADE_GITHUB_REPOSITORY 값을 사용합니다.</p>';
    echo '<label>설치 키';
    echo '<input type="password" name="installer_key" autocomplete="new-password" required>';
    echo '</label><p class="hint">TRADE_WEB_INSTALL_SECRET 또는 웹 루트 밖의 TRADE_WEB_INSTALL_SECRET_FILE과 일치해야 합니다.</p>';
    echo '<label>대상 경로 확인';
    echo '<input type="text" name="confirm_target" value="' . tw_h($confirm) . '" placeholder="' . tw_h($root) . '" required>';
    echo '</label><p class="hint">실수 방지를 위해 위 저장 폴더의 전체 경로를 다시 입력하십시오.</p>';
    echo '<label><input type="checkbox" name="allow_reinstall" value="1"' . ($allowReinstall ? ' checked' : '') . '> 기존에 설치된 관리 대상이면 업그레이드 허용</label>';
    echo '<button type="submit">소스 다운로드 및 저장</button>';
    echo '</form>';
    echo '<p class="hint">초기화는 SINGLE_FILE_PAPER / REAL=false이며 scheduler는 자동 시작하지 않습니다. 설치 후 이 installer 파일을 이동하거나 삭제하고 Web Station 접근제어를 유지하십시오.</p>';
    echo '</main></body></html>';
}

function tw_web_main(): void
{
    if (PHP_SAPI === 'cli') {
        http_response_code(405);
        echo 'WEB_ONLY' . PHP_EOL;
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    $form = array(
        'web_root' => tw_env('TRADE_WEB_ROOT', TW_DEFAULT_WEB_ROOT),
        'github_ref' => tw_env('TRADE_GITHUB_REF', TW_DEFAULT_BRANCH),
        'confirm_target' => '',
        'allow_reinstall' => false,
    );
    $message = '';
    $error = '';
    try {
        tw_web_start_session();
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            if (!hash_equals(tw_web_csrf(), tw_post_value('csrf'))) {
                tw_fail('WEB_CSRF_INVALID');
            }
            tw_web_authenticate();
            $target = tw_web_root(tw_post_value('web_root'));
            $form['web_root'] = $target;
            $form['github_ref'] = tw_web_ref(tw_post_value('github_ref'));
            $form['confirm_target'] = tw_post_value('confirm_target');
            $form['allow_reinstall'] = tw_post_value('allow_reinstall') === '1';
            if (!hash_equals($target, trim($form['confirm_target']))) {
                tw_fail('TARGET_CONFIRMATION_MISMATCH');
            }
            ob_start();
            try {
                tw_install($target, $form['allow_reinstall'], $form['github_ref']);
                $message = trim((string)ob_get_contents());
                ob_end_clean();
            } catch (Throwable $installError) {
                ob_end_clean();
                throw $installError;
            }
        }
    } catch (Throwable $errorObject) {
        $error = $errorObject->getMessage();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        http_response_code(500);
        echo 'WEB_INSTALLER_SESSION_ERROR';
        return;
    }
    tw_web_render($form, $message, $error);
}

tw_web_main();
