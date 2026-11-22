<?php
/** POST: check a claim (read-only): prize_id + ticket. Nothing is saved. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    $game = housie_active_game();
    if (!$game) {
        throw new HousieException('No Housie game has been created yet.');
    }
    $check = housie_check_claim((int) $game['id'], (int) ($_POST['prize_id'] ?? 0), post_str('ticket', 30));
    audit_log('HOUSIE_CLAIM_CHECK', [
        'game_id' => (int) $game['id'], 'category' => $check['prize']['category'], 'ticket' => post_str('ticket', 30),
        'valid' => $check['valid'], 'can_confirm' => $check['can_confirm'], 'calls' => $check['calls_count'],
    ], $userId);
    return $check;
}, 'Claim checked.');
