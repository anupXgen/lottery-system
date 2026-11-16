<?php
/**
 * Session-based admin authentication.
 */

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('DIWALILOTTERY');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => base_url() === '' ? '/' : base_url() . '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) SESSION_IDLE_TIMEOUT);
    session_start();

    // Idle timeout
    if (isset($_SESSION['user_id'], $_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'][] = ['type' => 'warning', 'message' => 'Your session expired. Please log in again.'];
    }
    if (isset($_SESSION['user_id'])) {
        $_SESSION['last_seen'] = time();
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_username(): string
{
    return (string) ($_SESSION['username'] ?? '');
}

/** Guard for admin HTML pages. */
function require_login(): void
{
    start_secure_session();
    if (!is_logged_in()) {
        redirect('login.php');
    }
}

/** Guard for JSON API endpoints that change state: login + POST + CSRF. */
function require_admin_api(): void
{
    start_secure_session();
    if (!is_logged_in()) {
        json_error('Your admin session has expired. Please log in again.', 401);
    }
    if (!is_post()) {
        json_error('Method not allowed.', 405);
    }
    if (!csrf_valid()) {
        json_error('Security token mismatch. Please reload the page.', 419);
    }
    // Release the session lock early so polling requests are not blocked.
    session_write_close();
}

/** Too many failed logins from this IP in the last 15 minutes? */
function login_throttled(): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM audit_logs
                         WHERE action = 'LOGIN_FAILED' AND ip_address = ? AND created_at > ?");
    $st->execute([client_ip(), date('Y-m-d H:i:s', time() - 900)]);
    return (int) $st->fetchColumn() >= 10;
}

/**
 * Attempt to log in. Returns null on success or an error message.
 */
function attempt_login(string $username, string $password): ?string
{
    if (login_throttled()) {
        return 'Too many failed attempts. Please wait 15 minutes and try again.';
    }
    $st = db()->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $user = $st->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        audit_log('LOGIN_FAILED', ['username' => $username], null);
        return 'Invalid username or password.';
    }

    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
        $up = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
        $up->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['last_seen'] = time();
    unset($_SESSION['csrf_token']);

    db()->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $user['id']]);
    audit_log('LOGIN', [], (int) $user['id']);
    return null;
}

function logout_user(): void
{
    start_secure_session();
    if (is_logged_in()) {
        audit_log('LOGOUT');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** Verify the logged-in admin's password (used for dangerous operations). */
function verify_current_password(string $password): bool
{
    $st = db()->prepare('SELECT password FROM users WHERE id = ?');
    $st->execute([(int) ($_SESSION['user_id'] ?? 0)]);
    $hash = $st->fetchColumn();
    return $hash && password_verify($password, $hash);
}
