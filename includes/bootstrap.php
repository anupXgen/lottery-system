<?php
/**
 * Common bootstrap: loaded by every page and API endpoint.
 */

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/config/env.php';
require APP_ROOT . '/config/config.php';
require APP_ROOT . '/config/database.php';

if (is_file(APP_ROOT . '/vendor/autoload.php')) {
    require APP_ROOT . '/vendor/autoload.php';
}

require APP_ROOT . '/includes/functions.php';
require APP_ROOT . '/includes/auth.php';
require APP_ROOT . '/includes/draw.php';
require APP_ROOT . '/includes/tickets.php';

error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}
