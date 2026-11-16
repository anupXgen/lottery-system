<?php
/** POST: CONFIRM WINNER - permanently records the revealed winner (transactional). */
require dirname(__DIR__) . '/includes/bootstrap.php';

api_draw_action(function (int $userId) {
    $prizeId = (int) ($_POST['prize_id'] ?? 0);
    $ticketId = (int) ($_POST['ticket_id'] ?? 0);
    if ($prizeId <= 0 || $ticketId <= 0) {
        throw new DrawException('Missing draw details. Please reload the page.');
    }
    return draw_confirm($userId, $prizeId, $ticketId);
}, 'Winner confirmed and saved.');
