<?php
/** POST: END GAME. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    return housie_end_game($userId);
}, 'Game ended.');
