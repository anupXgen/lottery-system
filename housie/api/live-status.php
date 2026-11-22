<?php
/** GET (public, read-only): Housie LED / admin status. ?since=<token> returns {changed:false} when nothing changed. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

if (!housie_installed()) {
    json_response(['ok' => true, 'changed' => true, 'token' => 'not-installed', 'installed' => false, 'title' => 'DIWALI HOUSIE', 'game' => null]);
}
try {
    $since = isset($_GET['since']) && is_string($_GET['since']) ? substr($_GET['since'], 0, 40) : null;
    json_response(housie_live_status($since));
} catch (Throwable $e) {
    json_error(log_exception($e), 500);
}
