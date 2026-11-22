<?php
/** POST: RESUME GAME. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    return housie_resume_game($userId);
}, 'Game resumed.');
