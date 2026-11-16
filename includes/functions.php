<?php
/**
 * Generic helpers: escaping, URLs, flash messages, CSRF, JSON, settings, audit log.
 */

/** HTML-escape a value for output. */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Base URL path of the application (no trailing slash), e.g. "/lottery-system" or "".
 * Derived from the executing script so it works in sub-folders and aliases.
 */
function base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    if (BASE_URL_OVERRIDE !== null) {
        return $base = rtrim(BASE_URL_OVERRIDE, '/');
    }
    $base = '';
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
    $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? realpath($_SERVER['SCRIPT_FILENAME']) : false;
    $root = realpath(APP_ROOT);
    if ($scriptName !== '' && $scriptFile && $root) {
        $scriptFile = str_replace('\\', '/', $scriptFile);
        $root = str_replace('\\', '/', $root);
        if (stripos($scriptFile, $root) === 0) {
            $relative = substr($scriptFile, strlen($root)); // e.g. "/admin/tickets.php"
            if ($relative !== '' && substr($scriptName, -strlen($relative)) === $relative) {
                $base = substr($scriptName, 0, -strlen($relative));
            }
        }
    }
    return $base = rtrim($base, '/');
}

/** Build an absolute URL path inside the app. */
function url(string $path = ''): string
{
    return base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** Read a trimmed string from POST. */
function post_str(string $key, int $maxLen = 500): string
{
    $v = isset($_POST[$key]) && is_scalar($_POST[$key]) ? (string) $_POST[$key] : '';
    return mb_substr(clean_text($v), 0, $maxLen);
}

/** Read a trimmed string from GET. */
function get_str(string $key, int $maxLen = 200): string
{
    $v = isset($_GET[$key]) && is_scalar($_GET[$key]) ? (string) $_GET[$key] : '';
    return mb_substr(clean_text($v), 0, $maxLen);
}

/** Normalise user text: strip control chars, collapse whitespace, trim. */
function clean_text(string $v): string
{
    if (!mb_check_encoding($v, 'UTF-8')) {
        $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }
    $v = preg_replace('/[\x00-\x1F\x7F\x{FEFF}\x{00A0}]/u', ' ', $v);
    $v = preg_replace('/\s+/u', ' ', $v);
    return trim($v);
}

/* ------------------------------------------------------------------ */
/* Flash messages                                                      */
/* ------------------------------------------------------------------ */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------------ */
/* CSRF                                                                */
/* ------------------------------------------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $sent);
}

/** For form posts: abort with a friendly message when the token is wrong. */
function require_csrf(): void
{
    if (!csrf_valid()) {
        flash('danger', 'Your session form token expired. Please try again.');
        $back = $_SERVER['HTTP_REFERER'] ?? url('admin/dashboard.php');
        header('Location: ' . $back);
        exit;
    }
}

/* ------------------------------------------------------------------ */
/* JSON responses                                                      */
/* ------------------------------------------------------------------ */

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(['ok' => false, 'error' => $message] + $extra, $status);
}

/* ------------------------------------------------------------------ */
/* Settings                                                            */
/* ------------------------------------------------------------------ */

function settings_all(): array
{
    $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return $rows + ['event_name' => APP_NAME, 'event_date' => date('Y-m-d'), 'event_venue' => ''];
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return isset($all[$key]) && $all[$key] !== null ? (string) $all[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $st->execute([$key, $value]);
}

/* ------------------------------------------------------------------ */
/* Audit log                                                           */
/* ------------------------------------------------------------------ */

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

function audit_log(string $action, array $details = [], ?int $userId = null): void
{
    if ($userId === null && isset($_SESSION['user_id'])) {
        $userId = (int) $_SESSION['user_id'];
    }
    $st = db()->prepare('INSERT INTO audit_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?)');
    $st->execute([
        $userId,
        $action,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        client_ip(),
        date('Y-m-d H:i:s'),
    ]);
}

/** Format a date (Y-m-d) as e.g. "12 Nov 2026". */
function nice_date(?string $date): string
{
    if (!$date) {
        return '';
    }
    $ts = strtotime($date);
    return $ts ? date('d M Y', $ts) : $date;
}

function nice_datetime(?string $dt): string
{
    if (!$dt) {
        return '';
    }
    $ts = strtotime($dt);
    return $ts ? date('d M Y, h:i:s A', $ts) : $dt;
}

/** Log an unexpected exception and return a safe message. */
function log_exception(Throwable $e): string
{
    error_log('[lottery] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return APP_DEBUG ? $e->getMessage() : 'An unexpected server error occurred. Please try again.';
}
