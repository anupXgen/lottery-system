<?php
/**
 * Application configuration.
 */

define('APP_NAME', 'Diwali Lottery Draw');
define('APP_TIMEZONE', 'Asia/Kolkata');

// Total number of prizes. The draw runs from TOTAL_PRIZES down to 1.
define('TOTAL_PRIZES', 50);

// How many winners the LED display shows in its right-hand panel.
define('LED_WINNERS_LIMIT', 15);

// LED display polling interval in milliseconds.
define('LED_POLL_MS', 1000);

// Admin session idle timeout (seconds). The event can run for hours, so be generous.
define('SESSION_IDLE_TIMEOUT', 6 * 3600);

// Maximum upload size for ticket files (bytes).
define('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);

// Base URL path of the application, e.g. '/lottery-system'. Leave null to auto-detect.
define('BASE_URL_OVERRIDE', null);

// Show detailed errors. Set LOTTERY_APP_DEBUG in .env; never true on a live server.
define('APP_DEBUG', app_env_bool('LOTTERY_APP_DEBUG', false));

date_default_timezone_set(APP_TIMEZONE);
