<?php
/** POST: END LOTTERY (action=end) or reopen it (action=resume). */
require dirname(__DIR__) . '/includes/bootstrap.php';

$action = ($_POST['action'] ?? 'end') === 'resume' ? 'resume' : 'end';

api_draw_action(function (int $userId) use ($action) {
    return $action === 'resume' ? draw_resume($userId) : draw_end($userId);
}, $action === 'resume' ? 'Lottery resumed.' : 'Lottery ended.');
