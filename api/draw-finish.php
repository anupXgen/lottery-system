<?php
/** POST: one-click draw - pick the winner and save it (REVEAL + CONFIRM) after START has rolled. */
require dirname(__DIR__) . '/includes/bootstrap.php';

api_draw_action(function (int $userId) {
    return draw_reveal_and_confirm($userId);
}, 'Winner drawn and saved.');
