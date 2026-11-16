<?php
/**
 * Release health check (CLI only). Used by the deploy workflow BEFORE the
 * `current` symlink is switched, and handy for manual checks on the server:
 *
 *   php bin/healthcheck.php          # exit 0 = healthy, 1 = problem
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$fail = 0;
function hc(bool $ok, string $msg): void
{
    global $fail;
    echo ($ok ? '[ OK ] ' : '[FAIL] ') . $msg . "\n";
    if (!$ok) {
        $fail++;
    }
}

hc(PHP_VERSION_ID >= 70400, 'PHP ' . PHP_VERSION);
foreach (['pdo_mysql', 'mbstring', 'dom', 'xml', 'xmlreader', 'zip', 'gd', 'fileinfo', 'iconv'] as $ext) {
    hc(extension_loaded($ext), "extension $ext");
}
hc(is_file(APP_ROOT . '/vendor/autoload.php'), 'vendor/autoload.php present');
hc(is_file(APP_ROOT . '/.env') && is_readable(APP_ROOT . '/.env'), '.env present and readable');
hc(APP_DEBUG === false, 'APP_DEBUG is off');

try {
    $pdo = db();
    hc(true, 'database connection (' . DB_NAME . ')');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['users', 'lottery_tickets', 'prizes', 'lottery_draws', 'draw_session', 'settings', 'audit_logs'] as $t) {
        hc(in_array($t, $tables, true), "table $t");
    }
    hc((int) $pdo->query('SELECT COUNT(*) FROM prizes')->fetchColumn() === TOTAL_PRIZES, TOTAL_PRIZES . ' prizes configured');
    hc((bool) $pdo->query('SELECT 1 FROM draw_session WHERE id = 1')->fetchColumn(), 'draw_session row present');
} catch (Throwable $e) {
    hc(false, 'database: ' . $e->getMessage());
}

foreach (['uploads', 'exports'] as $dir) {
    hc(is_dir(APP_ROOT . "/$dir") && is_writable(APP_ROOT . "/$dir"), "$dir/ writable");
}

echo $fail ? "UNHEALTHY ($fail problem(s))\n" : "HEALTHY\n";
exit($fail ? 1 : 0);
