<?php
/** POST: CANCEL the active, unconfirmed draw. */
require dirname(__DIR__) . '/includes/bootstrap.php';

api_draw_action(function (int $userId) {
    return draw_cancel($userId);
}, 'Draw cancelled. Nothing was saved.');
