<?php
/**
 * Housie number draw engine.
 *
 * START HOUSIE GAME stores a securely shuffled sequence of 1-90 (Fisher-Yates with
 * random_int) on the game row. CALL NEXT NUMBER takes the next unused entry of that
 * sequence inside a transaction that locks the game row, so simultaneous calls are
 * serialised; UNIQUE(game_id, number) and UNIQUE(game_id, seq) make a repeat impossible.
 * The browser never chooses or sees a number before it is called.
 *
 * States: SETUP -> RUNNING <-> PAUSED -> ENDED
 */

/** Uniformly random permutation of 1..90 (Fisher-Yates, cryptographically secure). */
function housie_secure_shuffle(): array
{
    $n = range(1, 90);
    for ($i = 89; $i > 0; $i--) {
        $j = random_int(0, $i);
        $tmp = $n[$i];
        $n[$i] = $n[$j];
        $n[$j] = $tmp;
    }
    return $n;
}

/** Lock and return the active game, or fail with a friendly message. */
function housie_lock_active_game(): array
{
    $game = housie_active_game(true);
    if (!$game) {
        throw new HousieException('No Housie game has been created yet.');
    }
    return $game;
}

function housie_start_game(int $userId): array
{
    return with_transaction(function (PDO $pdo) use ($userId) {
        $game = housie_lock_active_game();
        if ($game['status'] !== 'SETUP') {
            throw new HousieException('This game has already been started.');
        }
        $gameId = (int) $game['id'];
        // Tickets in the system are optional: physical tickets printed elsewhere are fine
        // (winners are then entered manually after checking them in the room).
        $st = $pdo->prepare('SELECT COUNT(*) FROM housie_prizes WHERE game_id = ? AND enabled = 1');
        $st->execute([$gameId]);
        if ((int) $st->fetchColumn() === 0) {
            throw new HousieException('Enable at least one winning category before starting.');
        }
        $sequence = housie_secure_shuffle();
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE housie_games SET status = 'RUNNING', number_sequence = ?, calls_count = 0, current_number = NULL,
                              started_at = ?, version = version + 1 WHERE id = ?")
            ->execute([implode(',', $sequence), $now, $gameId]);
        audit_log('HOUSIE_GAME_START', ['game_id' => $gameId], $userId);
        return ['game_id' => $gameId];
    });
}

/**
 * Call the next number. $expectedCount is how many numbers the admin's screen had seen;
 * if another click/tab already called a number in between, this call is refused instead
 * of calling two numbers.
 */
function housie_call_next(int $userId, ?int $expectedCount = null): array
{
    return with_transaction(function (PDO $pdo) use ($userId, $expectedCount) {
        $game = housie_lock_active_game();
        if ($game['status'] === 'PAUSED') {
            throw new HousieException('The game is paused. Press RESUME GAME first.');
        }
        if ($game['status'] !== 'RUNNING') {
            throw new HousieException($game['status'] === 'SETUP' ? 'Press START HOUSIE GAME first.' : 'This game has ended.');
        }
        $count = (int) $game['calls_count'];
        if ($expectedCount !== null && $expectedCount !== $count) {
            throw new HousieException('A number was just called from another screen. Showing the latest number.');
        }
        if ($count >= 90) {
            throw new HousieException('All 90 numbers have been called.');
        }
        $sequence = array_map('intval', explode(',', (string) $game['number_sequence']));
        if (count($sequence) !== 90) {
            throw new RuntimeException('Housie number sequence is corrupt for game ' . $game['id']);
        }
        $number = $sequence[$count];
        $seq = $count + 1;
        $now = date('Y-m-d H:i:s');
        $gameId = (int) $game['id'];
        $pdo->prepare('INSERT INTO housie_draw_numbers (game_id, seq, number, called_at, called_by) VALUES (?, ?, ?, ?, ?)')
            ->execute([$gameId, $seq, $number, $now, $userId ?: null]);
        $pdo->prepare('UPDATE housie_games SET calls_count = ?, current_number = ?, last_called_at = ?, version = version + 1
                        WHERE id = ? AND calls_count = ?')
            ->execute([$seq, $number, $now, $gameId, $count]);
        audit_log('HOUSIE_CALL', ['game_id' => $gameId, 'seq' => $seq, 'number' => $number], $userId);
        return ['number' => $number, 'seq' => $seq, 'remaining' => 90 - $seq];
    });
}

function housie_set_status(int $userId, string $from, string $to, string $error, string $auditAction): array
{
    return with_transaction(function (PDO $pdo) use ($userId, $from, $to, $error, $auditAction) {
        $game = housie_lock_active_game();
        if (!in_array($game['status'], explode('|', $from), true)) {
            throw new HousieException($error);
        }
        $extra = $to === 'ENDED' ? ', ended_at = ' . $pdo->quote(date('Y-m-d H:i:s')) : '';
        $pdo->prepare("UPDATE housie_games SET status = ?, version = version + 1$extra WHERE id = ?")
            ->execute([$to, $game['id']]);
        audit_log($auditAction, ['game_id' => (int) $game['id'], 'calls' => (int) $game['calls_count']], $userId);
        return ['status' => $to];
    });
}

function housie_pause_game(int $userId): array
{
    return housie_set_status($userId, 'RUNNING', 'PAUSED', 'Only a running game can be paused.', 'HOUSIE_GAME_PAUSE');
}

function housie_resume_game(int $userId): array
{
    return housie_set_status($userId, 'PAUSED', 'RUNNING', 'Only a paused game can be resumed.', 'HOUSIE_GAME_RESUME');
}

function housie_end_game(int $userId): array
{
    return housie_set_status($userId, 'RUNNING|PAUSED', 'ENDED', 'Only a game in progress can be ended.', 'HOUSIE_GAME_END');
}

/** Called numbers of a game in calling order: [seq, number, called_at]. */
function housie_called_numbers(int $gameId): array
{
    $st = db()->prepare('SELECT seq, number, called_at FROM housie_draw_numbers WHERE game_id = ? ORDER BY seq');
    $st->execute([$gameId]);
    return $st->fetchAll();
}
