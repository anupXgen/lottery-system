<?php
/**
 * Database migration runner (CLI only). Run by deploy/remote-deploy.sh on every deploy,
 * before the new release goes live; also safe to run by hand:
 *
 *   php bin/migrate.php            apply pending migrations from database/migrations/*.sql
 *   php bin/migrate.php --status   list applied / pending migrations
 *
 * Works with a remote database (e.g. AWS RDS): it connects with the same host/port/name
 * as the app. Creating tables needs more rights than the app's day-to-day DB user, so it
 * uses LOTTERY_DB_MIGRATE_USER / LOTTERY_DB_MIGRATE_PASS from .env when set, otherwise
 * the app user (LOTTERY_DB_USER / LOTTERY_DB_PASS).
 *
 * Applied migrations are recorded in `schema_migrations`. Migrations must be additive and
 * idempotent (CREATE TABLE IF NOT EXISTS, INSERT IGNORE), so re-running one is harmless.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$statusOnly = in_array('--status', $argv, true);
$user = app_env('LOTTERY_DB_MIGRATE_USER', '') ?: DB_USER;
$pass = app_env('LOTTERY_DB_MIGRATE_USER', '') ? (string) app_env('LOTTERY_DB_MIGRATE_PASS', '') : DB_PASS;
$dir = APP_ROOT . '/database/migrations';

function mig_out(string $line): void
{
    echo $line, "\n";
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 15,
    ]);
} catch (PDOException $e) {
    mig_out('[FAIL] cannot connect to ' . DB_HOST . '/' . DB_NAME . ' as ' . $user . ': ' . $e->getMessage());
    exit(1);
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_migrations` (
        `name`       VARCHAR(190) NOT NULL,
        `checksum`   CHAR(64) NOT NULL,
        `applied_at` DATETIME NOT NULL,
        PRIMARY KEY (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    mig_out('[FAIL] cannot create the schema_migrations table as ' . $user . ': ' . $e->getMessage());
    mig_out('       Give this user CREATE/ALTER/INDEX/REFERENCES rights, or set LOTTERY_DB_MIGRATE_USER / LOTTERY_DB_MIGRATE_PASS in .env.');
    exit(1);
}

$applied = [];
foreach ($pdo->query('SELECT name, checksum, applied_at FROM schema_migrations') as $row) {
    $applied[$row['name']] = $row;
}
$files = glob($dir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$pending = 0;
$ran = 0;
foreach ($files as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    $sum = hash('sha256', $sql);
    if (isset($applied[$name])) {
        $note = $applied[$name]['checksum'] !== $sum ? '  (file changed since it was applied - not re-run)' : '';
        mig_out("[ OK ] $name  applied " . $applied[$name]['applied_at'] . $note);
        continue;
    }
    $pending++;
    if ($statusOnly) {
        mig_out("[PEND] $name");
        continue;
    }
    mig_out("[RUN ] $name");
    $body = preg_replace('/^\s*--.*$/m', '', $sql);
    try {
        foreach (preg_split('/;\s*(\r?\n|$)/', $body) as $stmt) {
            if (trim($stmt) !== '') {
                $pdo->exec($stmt);
            }
        }
        $pdo->prepare('INSERT INTO schema_migrations (name, checksum, applied_at) VALUES (?, ?, ?)')
            ->execute([$name, $sum, date('Y-m-d H:i:s')]);
        $ran++;
        mig_out("[ OK ] $name  applied now");
    } catch (PDOException $e) {
        mig_out("[FAIL] $name: " . $e->getMessage());
        if (stripos($e->getMessage(), 'denied') !== false) {
            mig_out('       The DB user "' . $user . '" may not create tables. Set LOTTERY_DB_MIGRATE_USER / LOTTERY_DB_MIGRATE_PASS in .env');
            mig_out('       to a user with CREATE, ALTER, INDEX, REFERENCES, SELECT, INSERT on ' . DB_NAME . '.*');
        }
        exit(1);
    }
}

if ($statusOnly) {
    mig_out($pending ? "$pending pending migration(s)" : 'No pending migrations');
} else {
    mig_out($ran ? "Applied $ran migration(s)" : 'No pending migrations');
}
exit(0);
