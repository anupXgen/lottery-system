<?php
/**
 * Set (or create) an admin password from the server shell, so the default
 * admin/Admin@123 never has to be used on a public site.
 *
 *   read -rs -p "New password: " P && echo && printf '%s' "$P" | php bin/set-admin-password.php admin; unset P
 *
 * The password is read from STDIN (never from argv, which is visible in `ps`).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$username = $argv[1] ?? 'admin';
$password = rtrim((string) stream_get_contents(STDIN), "\r\n");

if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}
if ($password === 'Admin@123') {
    fwrite(STDERR, "Refusing to set the published default password.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$st = db()->prepare('SELECT id FROM users WHERE username = ?');
$st->execute([$username]);
$id = $st->fetchColumn();
if ($id) {
    db()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $id]);
    $userId = (int) $id;
    echo "Password updated for '$username'.\n";
} else {
    db()->prepare('INSERT INTO users (username, password) VALUES (?, ?)')->execute([$username, $hash]);
    $userId = (int) db()->lastInsertId();
    echo "Admin user '$username' created.\n";
}
audit_log('PASSWORD_CHANGE', ['via' => 'cli', 'username' => $username], $userId);
