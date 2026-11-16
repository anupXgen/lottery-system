<?php
/**
 * Database connection settings.
 * Values come from APP_ROOT/.env (see .env.example) or real environment variables
 * (LOTTERY_DB_*), which take precedence. No credentials are stored in code.
 */

define('DB_HOST', app_env('LOTTERY_DB_HOST', '127.0.0.1'));
define('DB_PORT', app_env('LOTTERY_DB_PORT', '3306'));
define('DB_NAME', app_env('LOTTERY_DB_NAME', 'diwali_lottery'));
define('DB_USER', app_env('LOTTERY_DB_USER', 'root'));
define('DB_PASS', app_env('LOTTERY_DB_PASS', ''));

/**
 * Shared PDO connection (lazy singleton).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    // Keep MySQL timestamps in the same timezone as PHP.
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    // Fail fast instead of hanging if two requests fight over the draw row.
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');

    return $pdo;
}
