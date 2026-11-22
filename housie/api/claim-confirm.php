<?php
/** POST: confirm a verified claim (game_id, prize_id, ticket). Re-validated in a locked transaction. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    $ticketNo = housie_parse_ticket_ref(post_str('ticket', 30));
    if ($ticketNo === null) {
        throw new HousieException('Enter a Ticket ID.');
    }
    return housie_confirm_claim($userId, (int) ($_POST['game_id'] ?? 0), (int) ($_POST['prize_id'] ?? 0), $ticketNo);
}, 'Winner confirmed.');
