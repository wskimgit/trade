<?php
/**
 * Trade live NAS source mirror v1.1.0
 *
 * Browser-only helper. Save it outside /volume1/web/trade, open it over HTTPS,
 * authenticate with the same web key as the Pull & VERIFY control, and mirror
 * only the explicit trade source allowlist into a non-main GitHub branch.
 *
 * It never deletes or changes live trade files, runtime state, credentials, or
 * the existing /web application. It does not support CLI execution.
 */
declare(strict_types=1);

date_default_timezone_set('Asia/Seoul');
@ini_set('display_errors', '0');
error_reporting(E_ALL);

const TM_VERSION = '1.1.0';
const TM_DEFAULT_REPOSITORY = 'wskimgit/trade';
const TM_DEFAULT_BASE_REF = 'main';
const TM_DEFAULT_BRANCH = 'nas/live-trade-current';
const TM_DEFAULT_SOURCE_ROOT = '/volume1/web';
const TM_DEFAULT_KEY_FILE = '/volume1/web/.trade_pull_web_key';
const TM_DEFAULT_LOCK_FILE = '/volume1/.trade_live_source_mirror.lock';
const TM_DEFAULT_TOKEN_FILE = '/volume1/sis_private/github_token.txt';
const TM_DEFAULT_WEBROOT_SYNC_CONFIG = '/volume1/web/sis_private_sync_config.php';
const TM_DEFAULT_RUNTIME_SYNC_CONFIG = '/volume1/web/runtime/sis_private_sync_config.php';
const TM_MAX_SOURCE_BYTES = 1572864;
const TM_GITHUB_PREFIX = 'nas/live-trade-source';

const TM_REQUIRED_FILES = array(
    'trade_engine.php',
    'trade_broker.php',
    'dts.php',
    'abc.php',
    'das.php',
    'stc26.php',
    'trade_store_v103.php',
    'trade_runner.php',
    'trade_runner_config.php',
    'trade_runner_control.php',
    'trade_runner_status.php',
    'trade_runner_preflight.php',
    'trade_validation.php',
    'trade_validation_repair.php',
    'trade_pipeline_diag.php',
    'trade_dashboard.php',
    'trade_export.php',
    'trade_list.php',
);

function tm_env(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value === false || trim((string)$value) === '') {
        return $default;
    }
    return trim((string)$value);
}

function tm_fail(string $code, string $detail = ''): void
{
    throw new RuntimeException($detail === '' ? $code : $code . ' ' . $detail);
}

function tm_json($value, bool $pretty = false): string
{
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    try {
        return (string)json_encode($value, $flags | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        tm_fail('JSON_ENCODE_FAILED');
    }
}

function tm_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tm_path(string $path): string
{
    $path = rtrim(str_replace('\\', '/', trim($path)), '/');
    if ($path === '' || $path[0] !== '/'
        || preg_match('#(^|/)\\.\\.?(/|$)#', $path)) {
        tm_fail('ABSOLUTE_PATH_REQUIRED');
    }
    return $path;
}

function tm_is_https(): bool
{
    $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function tm_key_file(): string
{
    $file = tm_path(tm_env('TRADE_PULL_WEB_KEY_FILE', TM_DEFAULT_KEY_FILE));
    // Some DSM File Station setups expose only /volume1/web.
    // Keep the web-key requirement, but permit the explicitly named hidden
    // key file there when the NAS cannot create a file at /volume1.
    $webRoot = tm_path(tm_env('TRADE_MIRROR_SOURCE_ROOT', TM_DEFAULT_SOURCE_ROOT));
    $allowedWebKey = rtrim($webRoot, '/') . '/.trade_pull_web_key';
    $allowedWebKeyTxt = $allowedWebKey . '.txt';
    if ($file === $allowedWebKey && !is_file($file) && is_file($allowedWebKeyTxt)) {
        $file = $allowedWebKeyTxt;
    }
    if ($file === $allowedWebKey || $file === $allowedWebKeyTxt) {
        return $file;
    }
    $prefix = rtrim($webRoot, '/') . '/';
    if ($file === $webRoot || strpos($file, $prefix) === 0) {
        tm_fail('WEB_KEY_IN_WEB_ROOT');
    }
    return $file;
}

function tm_key(): string
{
    $inline = tm_env('TRADE_PULL_WEB_KEY');
    $file = tm_key_file();
    if ($inline !== '' && is_file($file)) {
        tm_fail('WEB_KEY_SOURCE_AMBIGUOUS');
    }
    if ($inline !== '') {
        $key = $inline;
    } elseif (!is_file($file)) {
        return '';
    } else {
        if (!is_readable($file)) {
            tm_fail('WEB_KEY_FILE_NOT_READABLE');
        }
        $key = trim((string)@file_get_contents($file));
    }
    if (strlen($key) < 16) {
        tm_fail('WEB_KEY_TOO_SHORT');
    }
    return $key;
}

function tm_source_root(): string
{
    return tm_path(tm_env('TRADE_MIRROR_SOURCE_ROOT', TM_DEFAULT_SOURCE_ROOT));
}

function tm_repository(): string
{
    $repo = tm_env('TRADE_MIRROR_REPOSITORY', TM_DEFAULT_REPOSITORY);
    if (!preg_match('/^[A-Za-z0-9_.-]+\\/[A-Za-z0-9_.-]+$/', $repo)) {
        tm_fail('REPOSITORY_INVALID');
    }
    return $repo;
}

function tm_branch(string $value): string
{
    $branch = trim($value) !== ''
        ? trim($value)
        : tm_env('TRADE_MIRROR_BRANCH', TM_DEFAULT_BRANCH);
    if ($branch === '' || strlen($branch) > 200
        || strpos($branch, '..') !== false
        || !preg_match('/^[A-Za-z0-9._\\/-]+$/', $branch)
        || strpos($branch, 'refs/') === 0) {
        tm_fail('BRANCH_INVALID');
    }
    return $branch;
}

function tm_token_usable(string $token): bool
{
    $token = trim($token);
    if ($token === '') {
        return false;
    }
    $upper = strtoupper($token);
    foreach (array('PASTE_', 'CHANGE_THIS', 'YOUR_TOKEN', 'TOKEN_HERE', '<TOKEN') as $prefix) {
        if (strpos($upper, $prefix) === 0) {
            return false;
        }
    }
    return true;
}

function tm_read_config_token(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $level = ob_get_level();
    @ob_start();
    $token = '';
    try {
        $config = @include $path;
        if (is_array($config) && array_key_exists('github_token', $config)) {
            $candidate = trim((string)$config['github_token']);
            if (tm_token_usable($candidate)) {
                $token = $candidate;
            }
        }
    } catch (Throwable $error) {
        $token = '';
    } finally {
        while (ob_get_level() > $level) {
            @ob_end_clean();
        }
    }
    return $token;
}

function tm_token(): array
{
    $envCandidates = array(
        array('source' => 'TRADE_MIRROR_TOKEN', 'value' => tm_env('TRADE_MIRROR_TOKEN')),
        array('source' => 'TRADE_PULL_TOKEN', 'value' => tm_env('TRADE_PULL_TOKEN')),
        array('source' => 'SIS_GITHUB_TOKEN', 'value' => tm_env('SIS_GITHUB_TOKEN')),
    );
    foreach ($envCandidates as $candidate) {
        if (tm_token_usable($candidate['value'])) {
            return array('source' => $candidate['source'], 'token' => $candidate['value']);
        }
    }

    $fileCandidates = array(
        array('source' => 'TRADE_MIRROR_TOKEN_FILE', 'path' => tm_env('TRADE_MIRROR_TOKEN_FILE')),
        array('source' => 'TRADE_PULL_TOKEN_FILE', 'path' => tm_env('TRADE_PULL_TOKEN_FILE')),
        array('source' => 'SIS_GITHUB_TOKEN_FILE', 'path' => tm_env('SIS_GITHUB_TOKEN_FILE')),
        array('source' => 'DEFAULT_PRIVATE_FILE', 'path' => TM_DEFAULT_TOKEN_FILE),
    );
    foreach ($fileCandidates as $candidate) {
        $path = trim((string)$candidate['path']);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            continue;
        }
        $token = trim((string)@file_get_contents($path));
        if (tm_token_usable($token)) {
            return array('source' => $candidate['source'], 'token' => $token);
        }
    }

    $configCandidates = array();
    $envConfig = tm_env('SIS_PRIVATE_SYNC_CONFIG');
    if ($envConfig !== '') {
        $configCandidates[] = array('source' => 'PRIVATE_SYNC_CONFIG_ENV', 'path' => $envConfig);
    }
    $configCandidates[] = array(
        'source' => 'PRIVATE_SYNC_CONFIG_WEBROOT',
        'path' => TM_DEFAULT_WEBROOT_SYNC_CONFIG,
    );
    $configCandidates[] = array(
        'source' => 'PRIVATE_SYNC_CONFIG_RUNTIME',
        'path' => TM_DEFAULT_RUNTIME_SYNC_CONFIG,
    );
    $seen = array();
    foreach ($configCandidates as $candidate) {
        $path = trim((string)$candidate['path']);
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        $seen[$path] = true;
        $token = tm_read_config_token($path);
        if ($token !== '') {
            return array('source' => $candidate['source'], 'token' => $token);
        }
    }

    return array('source' => 'NONE', 'token' => '');
}

function tm_github_url(string $repo, string $path = ''): string
{
    $parts = explode('/', $repo, 2);
    if (count($parts) !== 2) {
        tm_fail('REPOSITORY_INVALID');
    }
    $url = 'https://api.github.com/repos/' . rawurlencode($parts[0])
        . '/' . rawurlencode($parts[1]);
    if ($path !== '') {
        $segments = array();
        foreach (explode('/', trim($path, '/')) as $segment) {
            $segments[] = rawurlencode($segment);
        }
        $url .= '/' . implode('/', $segments);
    }
    return $url;
}

function tm_github_api(string $method, string $url, string $token, ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        tm_fail('CURL_REQUIRED');
    }
    $headers = array(
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $token,
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: trade-live-source-mirror/' . TM_VERSION,
    );
    $handle = curl_init();
    if ($handle === false) {
        tm_fail('CURL_INIT_FAILED');
    }
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($handle, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, tm_json($body));
    }
    $raw = curl_exec($handle);
    $errno = curl_errno($handle);
    $error = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($raw === false || $errno !== 0) {
        tm_fail('GITHUB_TRANSPORT_FAILED', $errno . ' ' . $error);
    }
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
        tm_fail('GITHUB_RESPONSE_INVALID', 'HTTP ' . $status);
    }
    return array('status' => $status, 'body' => $decoded);
}

function tm_file_inventory(): array
{
    $root = tm_source_root();

    $discovered = array();
    $glob = @glob(rtrim($root, '/') . '/trade*.php');
    if (is_array($glob)) {
        foreach ($glob as $path) {
            if (is_file($path)) {
                $discovered[] = basename($path);
            }
        }
    }
    $discovered = array_values(array_unique($discovered));
    $names = array_values(array_unique(array_merge(TM_REQUIRED_FILES, $discovered)));
    $inventory = array();

    foreach ($names as $name) {
        $file = $root . '/' . $name;
        $present = is_file($file) && is_readable($file);
        $row = array(
            'file' => $name,
            'path' => $file,
            'present' => $present,
            'required' => in_array($name, TM_REQUIRED_FILES, true),
            'bytes' => $present ? (int)@filesize($file) : 0,
            'sha256' => '',
        );
        if ($present) {
            if ($row['bytes'] > TM_MAX_SOURCE_BYTES) {
                tm_fail('SOURCE_TOO_LARGE', $name);
            }
            $hash = @hash_file('sha256', $file);
            if (!is_string($hash)) {
                tm_fail('SOURCE_HASH_FAILED', $name);
            }
            $row['sha256'] = $hash;
        }
        $inventory[] = $row;
    }

    $requiredPresent = 0;
    foreach ($inventory as $row) {
        if (!empty($row['required']) && !empty($row['present'])) {
            $requiredPresent++;
        }
    }
    $allow = array_fill_keys(TM_REQUIRED_FILES, true);
    $unlisted = array_values(array_diff($discovered, array_keys($allow)));

    return array(
        'root' => $root,
        'files' => $inventory,
        'all_trade_php' => $discovered,
        'unlisted_trade_php' => $unlisted,
        'required_count' => count(TM_REQUIRED_FILES),
        'required_present_count' => $requiredPresent,
        'present_count' => count(array_filter($inventory, function ($row) {
            return !empty($row['present']);
        })),
        'discovered_count' => count($discovered),
    );
}

function tm_manifest(array $inventory, string $repo, string $branch): array
{
    $files = array();
    foreach ($inventory['files'] as $row) {
        if (!empty($row['present'])) {
            $files[$row['file']] = array(
                'bytes' => $row['bytes'],
                'sha256' => $row['sha256'],
            );
        }
    }
    return array(
        'schema' => 'trade_live_source_manifest_v1',
        'mirror_version' => TM_VERSION,
        'repository' => $repo,
        'branch' => $branch,
        'source_root' => $inventory['root'],
        'source_prefix' => TM_GITHUB_PREFIX,
        'mirrored_at' => date('c'),
        'files' => $files,
        'all_trade_php' => $inventory['all_trade_php'],
        'unlisted_trade_php' => $inventory['unlisted_trade_php'],
    );
}

function tm_lock()
{
    $file = tm_path(tm_env('TRADE_MIRROR_LOCK_FILE', TM_DEFAULT_LOCK_FILE));
    $webRoot = tm_source_root();
    $prefix = rtrim($webRoot, '/') . '/';
    if ($file === $webRoot || strpos($file, $prefix) === 0) {
        tm_fail('LOCK_IN_WEB_ROOT');
    }
    $handle = @fopen($file, 'c');
    if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            @fclose($handle);
        }
        tm_fail('MIRROR_IN_PROGRESS');
    }
    return $handle;
}

function tm_unlock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function tm_ensure_branch(string $repo, string $base, string $branch, string $token): array
{
    $branchRef = tm_github_url($repo, 'git/ref/heads/' . $branch);
    $existing = tm_github_api('GET', $branchRef, $token);
    if ($existing['status'] >= 200 && $existing['status'] < 300) {
        $sha = (string)($existing['body']['object']['sha'] ?? '');
        if (!preg_match('/^[0-9a-f]{40}$/i', $sha)) {
            tm_fail('BRANCH_SHA_INVALID');
        }
        return array('created' => false, 'sha' => strtolower($sha));
    }
    if ($existing['status'] !== 404) {
        tm_fail('BRANCH_LOOKUP_FAILED', 'HTTP ' . $existing['status']);
    }
    $baseRef = tm_github_url($repo, 'git/ref/heads/' . $base);
    $baseResult = tm_github_api('GET', $baseRef, $token);
    if ($baseResult['status'] < 200 || $baseResult['status'] >= 300) {
        tm_fail('BASE_REF_LOOKUP_FAILED', 'HTTP ' . $baseResult['status']);
    }
    $baseSha = (string)($baseResult['body']['object']['sha'] ?? '');
    if (!preg_match('/^[0-9a-f]{40}$/i', $baseSha)) {
        tm_fail('BASE_SHA_INVALID');
    }
    $create = tm_github_api('POST', tm_github_url($repo, 'git/refs'), $token, array(
        'ref' => 'refs/heads/' . $branch,
        'sha' => strtolower($baseSha),
    ));
    if ($create['status'] < 200 || $create['status'] >= 300) {
        tm_fail('BRANCH_CREATE_FAILED', 'HTTP ' . $create['status']);
    }
    return array('created' => true, 'sha' => strtolower($baseSha));
}

function tm_put_file(string $repo, string $branch, string $path, string $bytes, string $token): array
{
    $url = tm_github_url($repo, 'contents/' . $path) . '?ref=' . rawurlencode($branch);
    $existing = tm_github_api('GET', $url, $token);
    $currentSha = null;
    if ($existing['status'] >= 200 && $existing['status'] < 300) {
        $currentSha = (string)($existing['body']['sha'] ?? '');
    } elseif ($existing['status'] !== 404) {
        tm_fail('REMOTE_FILE_LOOKUP_FAILED', $path . ' HTTP ' . $existing['status']);
    }
    $expected = sha1('blob ' . strlen($bytes) . "\0" . $bytes);
    if ($currentSha !== null && hash_equals($currentSha, $expected)) {
        return array('file' => $path, 'status' => 'UNCHANGED', 'sha256' => hash('sha256', $bytes));
    }
    $payload = array(
        'message' => 'Mirror live NAS trade source ' . basename($path),
        'content' => base64_encode($bytes),
        'branch' => $branch,
    );
    if ($currentSha !== null) {
        $payload['sha'] = $currentSha;
    }
    $written = tm_github_api('PUT', tm_github_url($repo, 'contents/' . $path), $token, $payload);
    if ($written['status'] < 200 || $written['status'] >= 300) {
        tm_fail('REMOTE_FILE_WRITE_FAILED', $path . ' HTTP ' . $written['status']);
    }
    return array(
        'file' => $path,
        'status' => 'UPDATED',
        'sha256' => hash('sha256', $bytes),
        'commit_sha' => (string)($written['body']['commit']['sha'] ?? ''),
    );
}

function tm_mirror(array $inventory): array
{
    $repo = tm_repository();
    $base = tm_env('TRADE_MIRROR_BASE_REF', TM_DEFAULT_BASE_REF);
    $branch = tm_branch('');
    if ($inventory['required_present_count'] !== count(TM_REQUIRED_FILES)) {
        tm_fail('REQUIRED_SOURCE_MISSING');
    }
    $tokenInfo = tm_token();
    if ($tokenInfo['token'] === '') {
        tm_fail('GITHUB_TOKEN_UNAVAILABLE');
    }
    $lock = tm_lock();
    try {
        $branchInfo = tm_ensure_branch($repo, $base, $branch, $tokenInfo['token']);
        $writes = array();
        foreach ($inventory['files'] as $row) {
            if (empty($row['present'])) {
                continue;
            }
            $bytes = @file_get_contents($row['path']);
            if (!is_string($bytes) || $bytes === '') {
                tm_fail('SOURCE_READ_FAILED', $row['file']);
            }
            $writes[] = tm_put_file(
                $repo,
                $branch,
                TM_GITHUB_PREFIX . '/' . $row['file'],
                $bytes,
                $tokenInfo['token']
            );
        }
        $manifest = tm_manifest($inventory, $repo, $branch);
        $manifestBytes = tm_json($manifest, true) . PHP_EOL;
        $writes[] = tm_put_file(
            $repo,
            $branch,
            TM_GITHUB_PREFIX . '/manifest.json',
            $manifestBytes,
            $tokenInfo['token']
        );
        return array(
            'ok' => true,
            'repository' => $repo,
            'branch' => $branch,
            'source_prefix' => TM_GITHUB_PREFIX,
            'branch_created' => $branchInfo['created'],
            'writes' => $writes,
            'manifest_sha256' => hash('sha256', $manifestBytes),
            'mirrored_at' => date('c'),
        );
    } finally {
        tm_unlock($lock);
    }
}

function tm_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    @session_name('trade_live_mirror');
    @session_set_cookie_params(array(
        'lifetime' => 0,
        'path' => '/',
        'secure' => tm_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    if (!@session_start()) {
        tm_fail('WEB_SESSION_FAILED');
    }
}

function tm_csrf(): string
{
    if (!isset($_SESSION['tm_csrf'])
        || !preg_match('/^[a-f0-9]{48}$/', (string)$_SESSION['tm_csrf'])) {
        $_SESSION['tm_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['tm_csrf'];
}

function tm_validate_csrf(): void
{
    $expected = tm_csrf();
    $provided = (string)($_POST['csrf'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        tm_fail('WEB_CSRF_INVALID');
    }
}

function tm_render(string $message = '', ?array $result = null): void
{
    $keyReady = false;
    $keyPath = TM_DEFAULT_KEY_FILE;
    $keyError = '';
    try {
        $keyPath = tm_key_file();
        $keyReady = tm_key() !== '';
    } catch (Throwable $error) {
        $keyError = $error->getMessage();
    }
    $tokenInfo = tm_token();
    $inventory = tm_file_inventory();
    $csrf = tm_csrf();
    $loggedIn = !empty($_SESSION['tm_authenticated']);
    $https = tm_is_https();

    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>Trade live source mirror</title><style>'
        . 'body{margin:0;background:#f4f6f8;color:#17212b;font-family:system-ui,sans-serif;font-size:16px}'
        . 'main{max-width:760px;margin:0 auto;padding:18px 14px 40px}'
        . '.card{background:#fff;border:1px solid #d9e0e7;border-radius:12px;padding:16px;margin:12px 0}'
        . 'h1{font-size:23px;margin:4px 0 8px}h2{font-size:18px}.muted{color:#5d6a75;font-size:14px}'
        . '.ok{color:#0b6b3a}.warn{color:#9a4b00}.err{color:#a51d2d}'
        . 'button{border:0;border-radius:8px;padding:12px 14px;margin:5px 4px 5px 0;font-size:16px;font-weight:700;background:#1769aa;color:#fff}'
        . 'button.danger{background:#9a2535}.row{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf0f2;padding:7px 0}'
        . '.row:last-child{border-bottom:0}.value{text-align:right;word-break:break-word}pre{white-space:pre-wrap;word-break:break-word;background:#101820;color:#d9f1df;padding:12px;border-radius:8px;font-size:13px}'
        . 'code{word-break:break-all}.notice{padding:10px;border-radius:8px;background:#fff4d6;margin:10px 0}'
        . '</style></head><body><main>';
    echo '<h1>Trade 최신 소스 미러</h1>';
    echo '<p class="muted">라이브 파일 읽기 전용 · GitHub 별도 브랜치 '
        . tm_h(tm_branch('')) . ' · 기존 trade/runtime 변경 없음</p>';
    if (!$https) {
        echo '<div class="notice warn">HTTPS에서만 로그인과 미러 작업을 허용합니다.</div>';
    }
    if ($message !== '') {
        echo '<div class="card err"><pre>' . tm_h($message) . '</pre></div>';
    }
    if ($result !== null) {
        echo '<div class="card ' . (!empty($result['ok']) ? 'ok' : 'err') . '"><h2>'
            . (!empty($result['ok']) ? '완료' : '실패') . '</h2><pre>'
            . tm_h(tm_json($result, true)) . '</pre></div>';
    }
    echo '<section class="card"><h2>소스 상태</h2>';
    echo '<div class="row"><span>GitHub</span><span class="value"><code>'
        . tm_h(tm_repository()) . '</code></span></div>';
    echo '<div class="row"><span>소스 경로</span><span class="value"><code>'
        . tm_h($inventory['root']) . '</code></span></div>';
    echo '<div class="row"><span>필수 파일</span><span class="value">'
        . tm_h($inventory['required_present_count'] . '/' . $inventory['required_count']) . '</span></div>';
    echo '<div class="row"><span>발견된 trade*.php</span><span class="value">'
        . tm_h($inventory['discovered_count']) . '개</span></div>';
    echo '<div class="row"><span>GitHub token</span><span class="value">'
        . tm_h($tokenInfo['source'] !== 'NONE' ? '설정됨' : '없음') . '</span></div>';
    if (!empty($inventory['unlisted_trade_php'])) {
        echo '<p class="ok">추가로 발견된 trade*.php도 전체 미러링 대상에 포함됩니다: <code>'
            . tm_h(implode(', ', $inventory['unlisted_trade_php'])) . '</code></p>';
    }
    echo '<pre>' . tm_h(tm_json($inventory['files'], true)) . '</pre></section>';

    if (empty($_SESSION['tm_authenticated'])) {
        echo '<section class="card"><h2>관리자 로그인</h2>';
        if (!$keyReady) {
            echo '<p class="warn">웹 키가 없습니다. DSM File Station에서 다음 파일을 만들고 '
                . '16자 이상의 비밀 문자열 한 줄을 저장하십시오.</p><p><code>'
                . tm_h($keyPath) . '</code></p>';
            if ($keyError !== '') {
                echo '<pre>' . tm_h($keyError) . '</pre>';
            }
        } else {
            echo '<form method="post"><input type="hidden" name="action" value="login">';
            echo '<input type="hidden" name="csrf" value="' . tm_h($csrf) . '">';
            echo '<input name="web_key" type="password" autocomplete="current-password" required>';
            echo '<button type="submit">로그인</button></form>';
        }
        echo '</section>';
    } else {
        echo '<section class="card"><h2>GitHub 미러 작업</h2>';
        echo '<p>현재 소스는 <code>' . tm_h(TM_GITHUB_PREFIX)
            . '/</code> 아래에만 기록됩니다. main은 변경하지 않습니다.</p>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . tm_h($csrf) . '">';
        echo '<input type="hidden" name="action" value="mirror">';
        echo '<label><input type="checkbox" name="confirm_mirror" value="1" required> '
            . '현재 NAS의 allowlist 파일을 GitHub 별도 브랜치에 미러링하는 것을 확인했습니다.</label>';
        echo '<button class="danger" type="submit">최신 소스 미러링</button></form>';
        echo '<form method="post"><input type="hidden" name="csrf" value="' . tm_h($csrf) . '">';
        echo '<input type="hidden" name="action" value="logout">';
        echo '<button type="submit">로그아웃</button></form></section>';
    }
    echo '<p class="muted">v' . tm_h(TM_VERSION)
        . ' · 소스 파일과 runtime/credential은 서로 분리됩니다.</p>';
    echo '</main></body></html>';
}

function tm_main(): int
{
    if (PHP_SAPI === 'cli') {
        echo "WEB_ONLY\n";
        return 1;
    }
    @set_time_limit(0);
    @ignore_user_abort(true);
    try {
        tm_session_start();
    } catch (Throwable $error) {
        echo '<pre>WEB_SESSION_FAILED ' . tm_h($error->getMessage()) . '</pre>';
        return 1;
    }
    $message = '';
    $result = null;
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        $action = strtolower(trim((string)($_POST['action'] ?? '')));
        try {
            tm_validate_csrf();
            if ($action === 'login') {
                if (!tm_is_https()) {
                    tm_fail('HTTPS_REQUIRED');
                }
                $key = tm_key();
                $provided = trim((string)($_POST['web_key'] ?? ''));
                if ($key === '' || $provided === '' || !hash_equals($key, $provided)) {
                    usleep(250000);
                    tm_fail('WEB_LOGIN_FAILED');
                }
                @session_regenerate_id(true);
                $_SESSION['tm_authenticated'] = true;
                $message = '로그인되었습니다.';
            } elseif ($action === 'logout') {
                unset($_SESSION['tm_authenticated']);
                @session_regenerate_id(true);
                $message = '로그아웃되었습니다.';
            } elseif (empty($_SESSION['tm_authenticated'])) {
                tm_fail('WEB_LOGIN_REQUIRED');
            } else {
                if (!tm_is_https()) {
                    tm_fail('HTTPS_REQUIRED');
                }
                if ($action !== 'mirror'
                    || (string)($_POST['confirm_mirror'] ?? '') !== '1') {
                    tm_fail('MIRROR_CONFIRM_REQUIRED');
                }
                $inventory = tm_file_inventory();
                $result = tm_mirror($inventory);
            }
        } catch (Throwable $error) {
            $message = $error->getMessage();
        }
    }
    tm_render($message, $result);
    return 0;
}

exit(tm_main());
