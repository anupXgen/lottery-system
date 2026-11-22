<?php
/** POST: START HOUSIE GAME - stores a securely shuffled 1-90 sequence. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    return housie_start_game($userId);
}, 'Housie game started. Press CALL NEXT NUMBER.');
