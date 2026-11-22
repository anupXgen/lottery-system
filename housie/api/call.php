<?php
/** POST: CALL NEXT NUMBER. Optional expected_count guards against double clicks / two screens. */
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

housie_api_action(function (int $userId) {
    $expected = isset($_POST['expected_count']) && $_POST['expected_count'] !== '' ? (int) $_POST['expected_count'] : null;
    return housie_call_next($userId, $expected);
}, 'Number called.');
