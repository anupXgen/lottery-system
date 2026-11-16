<?php
/** POST: START DRAW for the next pending prize (rolling animation begins). */
require dirname(__DIR__) . '/includes/bootstrap.php';

api_draw_action(function (int $userId) {
    return draw_start($userId);
}, 'Draw started. The LED screen is rolling numbers.');
