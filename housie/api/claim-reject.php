<?php
/** POST: record a rejected claim (game_id, prize_id, ticket, optional note). */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    return housie_reject_claim($userId, (int) ($_POST['game_id'] ?? 0), (int) ($_POST['prize_id'] ?? 0),
        post_str('ticket', 30), post_str('note', 200));
}, 'Claim rejected and recorded.');
