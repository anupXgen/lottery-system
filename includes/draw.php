<?php
/**
 * Draw engine.
 *
 * All state changes go through these functions. Each one runs inside a database
 * transaction that first locks the single `draw_session` row (SELECT ... FOR UPDATE).
 * That row acts as a global mutex, so simultaneous/double-clicked requests are
 * serialised and the state machine below is always respected:
 *
 *      IDLE --start--> ROLLING --reveal--> REVEALED --confirm--> IDLE (next prize)
 *                         |                    |
 *                         +------cancel--------+--------------> IDLE (same prize)
 *      IDLE --end--> ENDED --resume--> IDLE
 *
 * The random winner is chosen on the server with random_int() (CSPRNG) at REVEAL time,
 * from tickets whose status is AVAILABLE. Nothing is written to `lottery_draws`
 * until CONFIRM, which is protected by UNIQUE keys on prize_id and lottery_ticket_id.
 */

class DrawException extends RuntimeException
{
}

/** Run $fn inside a transaction; rolls back on any exception. */
function with_transaction(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Fetch the draw session row (optionally locking it for the current transaction). */
function draw_session_row(bool $forUpdate = false): array
{
    $sql = 'SELECT * FROM draw_session WHERE id = 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $row = db()->query($sql)->fetch();
    if (!$row) {
        // Self-heal if the row was removed manually.
        db()->exec("INSERT IGNORE INTO draw_session (id, state, version) VALUES (1, 'IDLE', 1)");
        $row = db()->query($sql)->fetch();
    }
    return $row;
}

/** The next prize to draw: highest prize number still PENDING (50 -> 1). */
function next_pending_prize(bool $forUpdate = false): ?array
{
    $sql = "SELECT * FROM prizes WHERE status = 'PENDING' ORDER BY prize_number DESC LIMIT 1"
         . ($forUpdate ? ' FOR UPDATE' : '');
    $row = db()->query($sql)->fetch();
    return $row ?: null;
}

function bump_draw_version(): void
{
    db()->exec('UPDATE draw_session SET version = version + 1 WHERE id = 1');
}

function confirmed_draw_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM lottery_draws')->fetchColumn();
}

/**
 * Tickets and prizes may only be changed before the first confirmed draw
 * and while no draw is in progress. Returns a reason string when locked.
 */
function setup_lock_reason(): ?string
{
    if (confirmed_draw_count() > 0) {
        return 'The lottery has started (winners already confirmed). Tickets and prizes are locked. Use Settings › Reset Lottery to unlock.';
    }
    $state = draw_session_row()['state'];
    if ($state === 'ROLLING' || $state === 'REVEALED') {
        return 'A draw is currently in progress. Cancel or confirm it first.';
    }
    return null;
}

/* ------------------------------------------------------------------ */
/* State transitions                                                   */
/* ------------------------------------------------------------------ */

/** START DRAW: begin the rolling animation for the next prize. */
function draw_start(int $userId): array
{
    return with_transaction(function (PDO $pdo) use ($userId) {
        $s = draw_session_row(true);
        if ($s['state'] === 'ENDED') {
            throw new DrawException('The lottery has been ended. Resume it first to continue drawing.');
        }
        if ($s['state'] !== 'IDLE') {
            throw new DrawException('A draw is already in progress for this prize.');
        }
        $prize = next_pending_prize(true);
        if (!$prize) {
            throw new DrawException('All prizes have already been drawn.');
        }
        $available = (int) $pdo->query("SELECT COUNT(*) FROM lottery_tickets WHERE status = 'AVAILABLE'")->fetchColumn();
        if ($available === 0) {
            throw new DrawException('There are no eligible (undrawn) lottery tickets left.');
        }

        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare("UPDATE draw_session
                                SET state = 'ROLLING', prize_id = ?, lottery_ticket_id = NULL,
                                    started_at = ?, revealed_at = NULL, version = version + 1
                              WHERE id = 1");
        $st->execute([$prize['id'], $now]);

        audit_log('DRAW_START', ['prize_number' => (int) $prize['prize_number'], 'eligible_tickets' => $available], $userId);
        return ['prize_number' => (int) $prize['prize_number']];
    });
}

/** REVEAL WINNER: pick a random eligible ticket on the server (not yet saved). */
function draw_reveal(int $userId): array
{
    return with_transaction(function (PDO $pdo) use ($userId) {
        $s = draw_session_row(true);
        if ($s['state'] === 'REVEALED') {
            throw new DrawException('The winner for this draw has already been revealed.');
        }
        if ($s['state'] !== 'ROLLING') {
            throw new DrawException('No draw is running. Press START DRAW first.');
        }

        $prize = $pdo->prepare('SELECT * FROM prizes WHERE id = ? FOR UPDATE');
        $prize->execute([$s['prize_id']]);
        $prize = $prize->fetch();
        if (!$prize || $prize['status'] !== 'PENDING') {
            throw new DrawException('This prize has already been drawn. Cancel the draw and start again.');
        }

        // Lock the pool of eligible tickets so nothing changes under us, then pick one uniformly at random.
        $ids = $pdo->query("SELECT id FROM lottery_tickets WHERE status = 'AVAILABLE' ORDER BY id FOR UPDATE")
                   ->fetchAll(PDO::FETCH_COLUMN);
        $count = count($ids);
        if ($count === 0) {
            throw new DrawException('There are no eligible (undrawn) lottery tickets left.');
        }
        $ticketId = (int) $ids[random_int(0, $count - 1)];

        $now = date('Y-m-d H:i:s');
        $st = $pdo->prepare("UPDATE draw_session
                                SET state = 'REVEALED', lottery_ticket_id = ?, revealed_at = ?, version = version + 1
                              WHERE id = 1");
        $st->execute([$ticketId, $now]);

        $t = $pdo->prepare('SELECT lottery_number FROM lottery_tickets WHERE id = ?');
        $t->execute([$ticketId]);
        $number = (string) $t->fetchColumn();

        audit_log('DRAW_REVEAL', [
            'prize_number'     => (int) $prize['prize_number'],
            'lottery_number'   => $number,
            'eligible_tickets' => $count,
        ], $userId);

        return ['prize_number' => (int) $prize['prize_number'], 'lottery_number' => $number];
    });
}

/**
 * CONFIRM WINNER: permanently record the revealed winner.
 * $expectedPrizeId / $expectedTicketId must match what the admin saw, so a stale
 * browser tab can never confirm a different draw.
 */
function draw_confirm(int $userId, int $expectedPrizeId, int $expectedTicketId): array
{
    try {
        return with_transaction(function (PDO $pdo) use ($userId, $expectedPrizeId, $expectedTicketId) {
            $s = draw_session_row(true);
            if ($s['state'] !== 'REVEALED') {
                throw new DrawException('There is no revealed winner waiting for confirmation (it may already be confirmed).');
            }
            if ((int) $s['prize_id'] !== $expectedPrizeId || (int) $s['lottery_ticket_id'] !== $expectedTicketId) {
                throw new DrawException('The draw has changed since you loaded it. Please review the current winner again.');
            }

            $p = $pdo->prepare('SELECT * FROM prizes WHERE id = ? FOR UPDATE');
            $p->execute([$s['prize_id']]);
            $prize = $p->fetch();
            if (!$prize || $prize['status'] !== 'PENDING') {
                throw new DrawException('This prize already has a confirmed winner.');
            }
            $next = next_pending_prize(true);
            if (!$next || (int) $next['id'] !== (int) $prize['id']) {
                throw new DrawException('Prizes must be drawn in descending order. Cancel this draw and start again.');
            }

            $t = $pdo->prepare('SELECT * FROM lottery_tickets WHERE id = ? FOR UPDATE');
            $t->execute([$s['lottery_ticket_id']]);
            $ticket = $t->fetch();
            if (!$ticket || $ticket['status'] !== 'AVAILABLE') {
                throw new DrawException('This lottery ticket is no longer eligible. Cancel the draw and start again.');
            }

            $now = date('Y-m-d H:i:s');
            $ins = $pdo->prepare('INSERT INTO lottery_draws
                    (prize_id, lottery_ticket_id, winning_lottery_number, sold_by, sold_to, draw_status,
                     drawn_at, confirmed_at, confirmed_by, created_at)
                VALUES (?, ?, ?, ?, ?, \'CONFIRMED\', ?, ?, ?, ?)');
            $ins->execute([
                $prize['id'], $ticket['id'], $ticket['lottery_number'], $ticket['sold_by'], $ticket['sold_to'],
                $s['revealed_at'] ?: $now, $now, $userId ?: null, $now,
            ]);
            $drawId = (int) $pdo->lastInsertId();

            $u1 = $pdo->prepare("UPDATE lottery_tickets SET status = 'WON' WHERE id = ? AND status = 'AVAILABLE'");
            $u1->execute([$ticket['id']]);
            $u2 = $pdo->prepare("UPDATE prizes SET status = 'DRAWN' WHERE id = ? AND status = 'PENDING'");
            $u2->execute([$prize['id']]);
            if ($u1->rowCount() !== 1 || $u2->rowCount() !== 1) {
                throw new DrawException('Could not record the winner because the data changed. Please try again.');
            }

            $u3 = $pdo->prepare("UPDATE draw_session
                                    SET state = 'IDLE', prize_id = NULL, lottery_ticket_id = NULL,
                                        started_at = NULL, revealed_at = NULL, last_draw_id = ?, version = version + 1
                                  WHERE id = 1");
            $u3->execute([$drawId]);

            audit_log('DRAW_CONFIRM', [
                'draw_id'        => $drawId,
                'prize_number'   => (int) $prize['prize_number'],
                'prize_name'     => $prize['prize_name'],
                'lottery_number' => $ticket['lottery_number'],
                'sold_by'        => $ticket['sold_by'],
                'sold_to'        => $ticket['sold_to'],
            ], $userId);

            return [
                'draw_id'        => $drawId,
                'prize_number'   => (int) $prize['prize_number'],
                'lottery_number' => $ticket['lottery_number'],
            ];
        });
    } catch (PDOException $e) {
        // 23000 = integrity constraint violation (unique key) -> duplicate winner attempt
        if ($e->getCode() === '23000') {
            throw new DrawException('This winner or prize has already been recorded. Duplicate confirmation blocked.');
        }
        throw $e;
    }
}

/** CANCEL DRAW: abandon the in-progress (unconfirmed) draw. */
function draw_cancel(int $userId): array
{
    return with_transaction(function (PDO $pdo) use ($userId) {
        $s = draw_session_row(true);
        if ($s['state'] !== 'ROLLING' && $s['state'] !== 'REVEALED') {
            throw new DrawException('There is no active draw to cancel.');
        }
        $info = ['previous_state' => $s['state']];
        if ($s['prize_id']) {
            $p = $pdo->prepare('SELECT prize_number FROM prizes WHERE id = ?');
            $p->execute([$s['prize_id']]);
            $info['prize_number'] = (int) $p->fetchColumn();
        }
        if ($s['lottery_ticket_id']) {
            $t = $pdo->prepare('SELECT lottery_number FROM lottery_tickets WHERE id = ?');
            $t->execute([$s['lottery_ticket_id']]);
            $info['discarded_lottery_number'] = (string) $t->fetchColumn();
        }
        $pdo->exec("UPDATE draw_session
                       SET state = 'IDLE', prize_id = NULL, lottery_ticket_id = NULL,
                           started_at = NULL, revealed_at = NULL, version = version + 1
                     WHERE id = 1");
        audit_log('DRAW_CANCEL', $info, $userId);
        return $info;
    });
}

/** END LOTTERY: close the event (LED shows the closing screen). */
function draw_end(int $userId): array
{
    return with_transaction(function (PDO $pdo) use ($userId) {
        $s = draw_session_row(true);
        if ($s['state'] === 'ENDED') {
            throw new DrawException('The lottery has already been ended.');
        }
        if ($s['state'] !== 'IDLE') {
            throw new DrawException('A draw is in progress. Confirm or cancel it before ending the lottery.');
        }
        $pdo->exec("UPDATE draw_session SET state = 'ENDED', version = version + 1 WHERE id = 1");
        $remaining = (int) $pdo->query("SELECT COUNT(*) FROM prizes WHERE status = 'PENDING'")->fetchColumn();
        audit_log('LOTTERY_END', ['prizes_remaining' => $remaining], $userId);
        return ['prizes_remaining' => $remaining];
    });
}

/** RESUME: reopen an ended lottery (e.g. ended by mistake). */
function draw_resume(int $userId): array
{
    return with_transaction(function (PDO $pdo) use ($userId) {
        $s = draw_session_row(true);
        if ($s['state'] !== 'ENDED') {
            throw new DrawException('The lottery is not ended.');
        }
        $pdo->exec("UPDATE draw_session SET state = 'IDLE', version = version + 1 WHERE id = 1");
        audit_log('LOTTERY_RESUME', [], $userId);
        return [];
    });
}

/**
 * Administrative reset. Clears all confirmed winners (after archiving them to exports/),
 * marks every ticket AVAILABLE and every prize PENDING. Optionally deletes all tickets.
 */
function lottery_reset(int $userId, bool $deleteTickets): array
{
    $winners = all_winners();
    $archive = null;
    if ($winners) {
        $archive = 'winners-before-reset-' . date('Ymd-His') . '.csv';
        $fh = @fopen(APP_ROOT . '/exports/' . $archive, 'w');
        if ($fh) {
            write_winners_csv($fh, $winners);
            fclose($fh);
        } else {
            $archive = null;
        }
    }

    return with_transaction(function (PDO $pdo) use ($userId, $deleteTickets, $winners, $archive) {
        draw_session_row(true);
        $pdo->exec("UPDATE draw_session
                       SET state = 'IDLE', prize_id = NULL, lottery_ticket_id = NULL, started_at = NULL,
                           revealed_at = NULL, last_draw_id = NULL, version = version + 1
                     WHERE id = 1");
        $pdo->exec('DELETE FROM lottery_draws');
        $pdo->exec("UPDATE prizes SET status = 'PENDING'");
        if ($deleteTickets) {
            $deleted = $pdo->exec('DELETE FROM lottery_tickets');
        } else {
            $pdo->exec("UPDATE lottery_tickets SET status = 'AVAILABLE' WHERE status <> 'AVAILABLE'");
            $deleted = 0;
        }
        audit_log('LOTTERY_RESET', [
            'winners_cleared' => count($winners),
            'tickets_deleted' => $deleted,
            'archive_file'    => $archive,
            'winners'         => array_map(function ($w) {
                return $w['prize_number'] . ':' . $w['winning_lottery_number'];
            }, $winners),
        ], $userId);
        return ['winners_cleared' => count($winners), 'tickets_deleted' => (int) $deleted, 'archive' => $archive];
    });
}

/**
 * Shared runner for the admin draw API endpoints: authenticates, executes the
 * action and responds with JSON containing the fresh live status.
 */
function api_draw_action(callable $action, string $successMessage): void
{
    require_admin_api();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    try {
        $result = $action($userId);
        json_response([
            'ok'      => true,
            'message' => $successMessage,
            'result'  => $result,
            'status'  => live_status(),
        ]);
    } catch (DrawException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage(), 'status' => live_status()], 409);
    } catch (PDOException $e) {
        // 1205 = lock wait timeout, 1213 = deadlock: another request is busy with the draw.
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($code === 1205 || $code === 1213) {
            json_response(['ok' => false, 'error' => 'Another draw request is being processed. Please wait a moment.', 'status' => live_status()], 409);
        }
        json_error(log_exception($e), 500);
    } catch (Throwable $e) {
        json_error(log_exception($e), 500);
    }
}

/* ------------------------------------------------------------------ */
/* Read models                                                         */
/* ------------------------------------------------------------------ */

function draw_stats(): array
{
    $pdo = db();
    $t = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'AVAILABLE'), 0) AS available,
                             COALESCE(SUM(status = 'WON'), 0) AS won
                        FROM lottery_tickets")->fetch();
    $p = $pdo->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'DRAWN'), 0) AS drawn
                        FROM prizes")->fetch();
    $winners = confirmed_draw_count();
    return [
        'tickets_total'     => (int) $t['total'],
        'tickets_available' => (int) $t['available'],
        'tickets_won'       => (int) $t['won'],
        'prizes_total'      => (int) $p['total'],
        'prizes_drawn'      => (int) $p['drawn'],
        'prizes_remaining'  => (int) $p['total'] - (int) $p['drawn'],
        'winners_total'     => $winners,
    ];
}

/** Confirmed winners joined with prize details, newest first (or by prize number). */
function all_winners(string $order = 'prize_desc', ?int $limit = null): array
{
    $orderSql = $order === 'latest'
        ? 'd.confirmed_at DESC, d.id DESC'
        : 'p.prize_number DESC';
    $sql = "SELECT d.id, d.winning_lottery_number, d.sold_by, d.sold_to, d.drawn_at, d.confirmed_at,
                   p.id AS prize_id, p.prize_number, p.prize_name, p.prize_description
              FROM lottery_draws d
              JOIN prizes p ON p.id = d.prize_id
             ORDER BY $orderSql";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, (int) $limit);
    }
    return db()->query($sql)->fetchAll();
}

function write_winners_csv($fh, array $winners): void
{
    fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows names correctly
    fputcsv($fh, ['Prize No.', 'Prize Name', 'Lottery Number', 'Sold By', 'Sold To', 'Confirmed At']);
    foreach ($winners as $w) {
        fputcsv($fh, [
            $w['prize_number'], $w['prize_name'], $w['winning_lottery_number'],
            $w['sold_by'], (string) $w['sold_to'], $w['confirmed_at'],
        ]);
    }
}

/**
 * Public status payload used by the LED display and the admin live-draw page.
 * When $since equals the current version, a tiny "unchanged" response is returned.
 * The randomly selected ticket is only exposed once the state is REVEALED.
 */
function live_status(?int $since = null): array
{
    $pdo = db();
    $s = draw_session_row();
    $version = (int) $s['version'];

    if ($since !== null && $since === $version) {
        return ['ok' => true, 'changed' => false, 'version' => $version];
    }

    $prizeCols = function (?array $p) {
        if (!$p) {
            return null;
        }
        return [
            'id'          => (int) $p['id'],
            'number'      => (int) $p['prize_number'],
            'name'        => $p['prize_name'],
            'description' => (string) $p['prize_description'],
        ];
    };

    $state = $s['state'];
    $next = next_pending_prize();
    $current = null;
    if (($state === 'ROLLING' || $state === 'REVEALED') && $s['prize_id']) {
        $st = $pdo->prepare('SELECT * FROM prizes WHERE id = ?');
        $st->execute([$s['prize_id']]);
        $current = $st->fetch() ?: null;
    }

    $winner = null;
    if ($state === 'REVEALED' && $s['lottery_ticket_id']) {
        $st = $pdo->prepare('SELECT id, lottery_number, sold_by, sold_to FROM lottery_tickets WHERE id = ?');
        $st->execute([$s['lottery_ticket_id']]);
        $t = $st->fetch();
        if ($t) {
            $winner = [
                'ticket_id'      => (int) $t['id'],
                'lottery_number' => $t['lottery_number'],
                'sold_by'        => $t['sold_by'],
                'sold_to'        => (string) $t['sold_to'],
            ];
        }
    }

    $winners = array_map(function ($w) {
        return [
            'draw_id'        => (int) $w['id'],
            'prize_number'   => (int) $w['prize_number'],
            'prize_name'     => $w['prize_name'],
            'lottery_number' => $w['winning_lottery_number'],
            'sold_by'        => $w['sold_by'],
            'sold_to'        => (string) $w['sold_to'],
            'confirmed_at'   => $w['confirmed_at'],
        ];
    }, all_winners('latest', LED_WINNERS_LIMIT));

    // Numbers used purely for the rolling animation (the real pick happens on the server).
    $rollPool = [];
    if ($state === 'ROLLING' || $state === 'REVEALED') {
        $rollPool = $pdo->query("SELECT lottery_number FROM lottery_tickets WHERE status = 'AVAILABLE' ORDER BY RAND() LIMIT 80")
                        ->fetchAll(PDO::FETCH_COLUMN);
    }

    $stats = draw_stats();
    $settings = settings_all();

    return [
        'ok'            => true,
        'changed'       => true,
        'version'       => $version,
        'state'         => $state,
        'all_drawn'     => $stats['prizes_remaining'] === 0,
        'event'         => [
            'name'  => (string) $settings['event_name'],
            'date'  => nice_date($settings['event_date']),
            'venue' => (string) $settings['event_venue'],
        ],
        'current_prize' => $prizeCols($current),
        'next_prize'    => $prizeCols($next),
        'winner'        => $winner,
        'last_winner'   => $winners ? $winners[0] : null,
        'last_draw_id'  => $s['last_draw_id'] ? (int) $s['last_draw_id'] : null,
        'winners'       => $winners,
        'roll_pool'     => array_map('strval', $rollPool),
        'stats'         => $stats,
        'server_time'   => date('c'),
    ];
}
