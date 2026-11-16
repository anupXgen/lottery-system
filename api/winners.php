<?php
/**
 * GET: public list of confirmed winners.
 * ?order=latest (default, newest first) or ?order=prize (50 -> 1); ?limit=N (default all).
 */
require dirname(__DIR__) . '/includes/bootstrap.php';

try {
    $order = ($_GET['order'] ?? 'latest') === 'prize' ? 'prize_desc' : 'latest';
    $limit = isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : null;
    $rows = array_map(function ($w) {
        return [
            'prize_number'   => (int) $w['prize_number'],
            'prize_name'     => $w['prize_name'],
            'lottery_number' => $w['winning_lottery_number'],
            'sold_by'        => $w['sold_by'],
            'sold_to'        => (string) $w['sold_to'],
            'confirmed_at'   => $w['confirmed_at'],
        ];
    }, all_winners($order, $limit));
    json_response(['ok' => true, 'count' => count($rows), 'winners' => $rows]);
} catch (Throwable $e) {
    json_error(log_exception($e), 503);
}
