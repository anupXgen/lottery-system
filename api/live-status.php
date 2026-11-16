<?php
/**
 * GET: public, read-only live status for the LED display (and the admin panel).
 * ?since=<version> returns a tiny "unchanged" payload when nothing has changed.
 * The selected winner is only included once it has been revealed.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

try {
    $since = isset($_GET['since']) && ctype_digit((string) $_GET['since']) ? (int) $_GET['since'] : null;
    json_response(live_status($since));
} catch (Throwable $e) {
    json_error(log_exception($e), 503);
}
