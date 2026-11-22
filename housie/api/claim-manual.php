<?php
/** POST: record a winner verified by hand in the room (physical ticket): game_id, prize_id, ticket, name, note. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    return housie_record_manual_winner($userId, (int) ($_POST['game_id'] ?? 0), (int) ($_POST['prize_id'] ?? 0),
        post_str('ticket', 40), post_str('name', 120), post_str('note', 200));
}, 'Winner recorded and shown on the LED screen.');
