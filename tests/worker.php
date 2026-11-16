<?php
/**
 * Test helper: performs one draw action in a separate PHP process so the test-suite
 * can fire truly simultaneous requests at the database.
 *   php worker.php <action> <startAtMicrotime> [prizeId ticketId]
 * Prints a JSON result line.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/includes/bootstrap.php';

$action = $argv[1] ?? '';
$startAt = (float) ($argv[2] ?? 0);
while (microtime(true) < $startAt) {
    usleep(200); // barrier: all workers start at the same moment
}

try {
    switch ($action) {
        case 'start':
            $r = draw_start(1);
            break;
        case 'reveal':
            $r = draw_reveal(1);
            break;
        case 'confirm':
            $r = draw_confirm(1, (int) $argv[3], (int) $argv[4]);
            break;
        case 'cancel':
            $r = draw_cancel(1);
            break;
        default:
            throw new InvalidArgumentException('unknown action');
    }
    echo json_encode(['ok' => true, 'result' => $r]);
} catch (DrawException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'PDO: ' . $e->getMessage()]);
}
