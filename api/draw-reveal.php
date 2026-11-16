<?php
/** POST: REVEAL WINNER - the server picks a random eligible ticket (not yet saved). */
require dirname(__DIR__) . '/includes/bootstrap.php';

api_draw_action(function (int $userId) {
    return draw_reveal($userId);
}, 'Winner revealed. Please verify and confirm.');
