<?php
/**
 * Housie claim verification.
 *
 * Participants play on printed tickets and come to the admin to claim a category. The
 * admin checks the claim (read-only), then confirms or rejects it. A matching ticket is
 * never declared a winner automatically.
 *
 * Validation uses only numbers that have actually been called in this game:
 *   EARLY_FIVE    any 5 numbers of the ticket called
 *   TOP/MIDDLE/BOTTOM_LINE  all 5 numbers of that row called
 *   FOUR_CORNERS  the 4 corner cells contain numbers and all 4 are called
 *   FULL_HOUSE, SECOND_ and THIRD_FULL_HOUSE  all 15 numbers called
 *     (2nd needs the 1st Full House completed, 3rd needs the 2nd; a ticket can win only one of them)
 */

/** Cells (0-26) that make up a category's pattern. */
function housie_pattern_cells(array $grid, string $category): array
{
    switch ($category) {
        case 'TOP_LINE':
            return array_values(array_filter(range(0, 8), function ($i) use ($grid) { return $grid[$i] > 0; }));
        case 'MIDDLE_LINE':
            return array_values(array_filter(range(9, 17), function ($i) use ($grid) { return $grid[$i] > 0; }));
        case 'BOTTOM_LINE':
            return array_values(array_filter(range(18, 26), function ($i) use ($grid) { return $grid[$i] > 0; }));
        case 'FOUR_CORNERS':
            return [0, 8, 18, 26];
        default: // EARLY_FIVE and full houses look at every number
            return array_values(array_filter(range(0, 26), function ($i) use ($grid) { return $grid[$i] > 0; }));
    }
}

/**
 * Does the ticket satisfy the category with the numbers called so far?
 * $called: number => true. Returns [valid, reason, cells, marked, missing].
 */
function housie_evaluate(array $grid, array $called, string $category): array
{
    $cells = housie_pattern_cells($grid, $category);
    $marked = [];
    $missing = [];
    foreach ($cells as $i) {
        $n = $grid[$i];
        if ($n > 0 && isset($called[$n])) {
            $marked[] = $i;
        } elseif ($n > 0) {
            $missing[] = $n;
        }
    }
    $label = housie_category_label($category);
    if ($category === 'EARLY_FIVE') {
        $valid = count($marked) >= 5;
        $reason = $valid
            ? 'At least five numbers on this ticket have been called (' . count($marked) . ' marked).'
            : 'Only ' . count($marked) . ' number(s) on this ticket have been called; Early Five needs 5.';
        return ['valid' => $valid, 'reason' => $reason, 'cells' => $cells, 'marked' => $marked, 'missing' => []];
    }
    if ($category === 'FOUR_CORNERS') {
        foreach ($cells as $i) {
            if ($grid[$i] === 0) {
                return ['valid' => false, 'reason' => 'This ticket has a blank corner cell, so it cannot win Four Corners.',
                        'cells' => $cells, 'marked' => $marked, 'missing' => $missing];
            }
        }
    }
    $valid = count($missing) === 0;
    $reason = $valid
        ? "All numbers for $label have been called."
        : "$label is not complete. Not yet called: " . implode(', ', $missing) . '.';
    return ['valid' => $valid, 'reason' => $reason, 'cells' => $cells, 'marked' => $marked, 'missing' => $missing];
}

function housie_prize_row(int $gameId, int $prizeId, bool $forUpdate = false): ?array
{
    $st = db()->prepare('SELECT * FROM housie_prizes WHERE id = ? AND game_id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$prizeId, $gameId]);
    $p = $st->fetch();
    return $p ?: null;
}

function housie_confirmed_count(int $prizeId): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM housie_claims WHERE prize_id = ? AND status = 'CONFIRMED'");
    $st->execute([$prizeId]);
    return (int) $st->fetchColumn();
}

/**
 * Full read-only check of a claim. `problems` lists anything that blocks confirmation
 * (game not in play, category closed, duplicate, ticket not in this game...).
 */
function housie_check_claim(int $gameId, int $prizeId, string $ticketRef): array
{
    $game = housie_game($gameId);
    if (!$game) {
        throw new HousieException('Game not found.');
    }
    $prize = housie_prize_row($gameId, $prizeId);
    if (!$prize) {
        throw new HousieException('Select a winning category of this game.');
    }
    $ticketNo = housie_parse_ticket_ref($ticketRef);
    if ($ticketNo === null) {
        throw new HousieException('Enter a Ticket ID.');
    }
    $category = $prize['category'];
    $label = housie_category_label($category);
    $problems = [];
    if (!housie_game_in_play($game)) {
        $problems[] = $game['status'] === 'SETUP' ? 'The game has not started yet.' : 'This game has ended; no more claims can be confirmed.';
    }
    if (!(int) $prize['enabled']) {
        $problems[] = "$label is not enabled for this game.";
    }
    $confirmed = housie_confirmed_count($prizeId);
    if ($confirmed >= (int) $prize['winners_allowed']) {
        $problems[] = "$label is already completed ($confirmed of {$prize['winners_allowed']} winner(s) confirmed).";
    }

    $ticket = housie_ticket_by_no($gameId, $ticketNo);
    $result = [
        'game_id'      => $gameId,
        'prize'        => ['id' => (int) $prize['id'], 'category' => $category, 'label' => $label,
                           'prize_name' => $prize['prize_name'], 'winners_allowed' => (int) $prize['winners_allowed'],
                           'confirmed' => $confirmed],
        'ticket_ref'   => $ticketRef,
        'ticket'       => null,
        'calls_count'  => (int) $game['calls_count'],
        'evaluation'   => null,
        'valid'        => false,
        'problems'     => $problems,
        'can_confirm'  => false,
    ];
    if (!$ticket) {
        $result['problems'][] = 'Ticket ' . housie_ticket_code($ticketNo) . ' does not exist in this game.';
        return $result;
    }
    $grid = housie_grid_decode($ticket['grid']);
    $calledNumbers = array_map(function ($r) { return (int) $r['number']; }, housie_called_numbers($gameId));
    $called = array_fill_keys($calledNumbers, true);
    $eval = housie_evaluate($grid, $called, $category);

    // Duplicate: this ticket already confirmed for this category.
    $st = db()->prepare("SELECT COUNT(*) FROM housie_claims WHERE prize_id = ? AND ticket_id = ? AND status = 'CONFIRMED'");
    $st->execute([$prizeId, $ticket['id']]);
    if ((int) $st->fetchColumn() > 0) {
        $result['problems'][] = 'Ticket ' . housie_ticket_code($ticketNo) . " has already been confirmed for $label.";
    }

    // Full house tiers.
    $tiers = ['FULL_HOUSE', 'SECOND_FULL_HOUSE', 'THIRD_FULL_HOUSE'];
    $tier = array_search($category, $tiers, true);
    if ($tier !== false) {
        if ($tier > 0) {
            $prevCat = $tiers[$tier - 1];
            $st = db()->prepare('SELECT * FROM housie_prizes WHERE game_id = ? AND category = ?');
            $st->execute([$gameId, $prevCat]);
            $prev = $st->fetch();
            if (!$prev || !(int) $prev['enabled'] || housie_confirmed_count((int) $prev['id']) < (int) $prev['winners_allowed']) {
                $result['problems'][] = housie_category_label($prevCat) . " must be completed before $label can be claimed.";
            }
        }
        $in = "'" . implode("','", $tiers) . "'";
        $st = db()->prepare("SELECT p.category FROM housie_claims c JOIN housie_prizes p ON p.id = c.prize_id
                              WHERE c.game_id = ? AND c.ticket_id = ? AND c.status = 'CONFIRMED' AND p.category IN ($in) AND p.id <> ?");
        $st->execute([$gameId, $ticket['id'], $prizeId]);
        $won = $st->fetchColumn();
        if ($won) {
            $result['problems'][] = 'This ticket has already won ' . housie_category_label($won) . '; a ticket can win only one Full House.';
        }
    }

    $result['ticket'] = [
        'id'          => (int) $ticket['id'],
        'no'          => (int) $ticket['ticket_no'],
        'code'        => housie_ticket_code((int) $ticket['ticket_no']),
        'participant' => (string) $ticket['participant_name'],
        'phone'       => (string) $ticket['participant_phone'],
        'grid'        => $grid,
        'called'      => array_values(array_filter($grid, function ($n) use ($called) { return $n > 0 && isset($called[$n]); })),
    ];
    $result['evaluation'] = $eval;
    $result['valid'] = $eval['valid'];
    $result['can_confirm'] = $eval['valid'] && !$result['problems'];
    return $result;
}

/**
 * Confirm a claim. Everything is re-checked inside one transaction with the game row and
 * prize row locked, so no number can be called and no other confirmation can slip in
 * between the check and the save.
 */
function housie_confirm_claim(int $userId, int $gameId, int $prizeId, int $ticketNo): array
{
    return with_transaction(function (PDO $pdo) use ($userId, $gameId, $prizeId, $ticketNo) {
        $game = housie_lock_active_game();
        if ((int) $game['id'] !== $gameId) {
            throw new HousieException('This claim belongs to a different game than the one in progress.');
        }
        if (!housie_prize_row($gameId, $prizeId, true)) {
            throw new HousieException('Select a winning category of this game.');
        }
        $check = housie_check_claim($gameId, $prizeId, (string) $ticketNo);
        if (!$check['valid']) {
            throw new HousieException('Invalid claim: ' . ($check['evaluation']['reason'] ?? ($check['problems'][0] ?? 'ticket not found')));
        }
        if ($check['problems']) {
            throw new HousieException($check['problems'][0]);
        }
        $rank = $check['prize']['confirmed'] + 1;
        $lastNumber = $game['current_number'] !== null ? (int) $game['current_number'] : null;
        $pdo->prepare("INSERT INTO housie_claims (game_id, prize_id, ticket_id, ticket_ref, category, status, winner_rank,
                                                  reason, calls_at_claim, last_number, decided_by, created_at)
                       VALUES (?, ?, ?, ?, ?, 'CONFIRMED', ?, ?, ?, ?, ?, ?)")
            ->execute([$gameId, $prizeId, $check['ticket']['id'], $check['ticket']['code'], $check['prize']['category'],
                       $rank, $check['evaluation']['reason'], (int) $game['calls_count'], $lastNumber,
                       $userId ?: null, date('Y-m-d H:i:s')]);
        $claimId = (int) $pdo->lastInsertId();
        housie_bump_version($gameId);
        audit_log('HOUSIE_CLAIM_CONFIRM', [
            'game_id' => $gameId, 'claim_id' => $claimId, 'category' => $check['prize']['category'],
            'ticket' => $check['ticket']['code'], 'participant' => $check['ticket']['participant'],
            'rank' => $rank, 'calls' => (int) $game['calls_count'],
        ], $userId);
        return [
            'claim_id'    => $claimId,
            'rank'        => $rank,
            'label'       => $check['prize']['label'],
            'prize_name'  => $check['prize']['prize_name'],
            'ticket'      => $check['ticket']['code'],
            'participant' => $check['ticket']['participant'],
            'completed'   => $rank >= $check['prize']['winners_allowed'],
        ];
    });
}

/** Record a rejected (bogus/invalid) claim in the claims audit trail. */
function housie_reject_claim(int $userId, int $gameId, int $prizeId, string $ticketRef, string $note): array
{
    return with_transaction(function (PDO $pdo) use ($userId, $gameId, $prizeId, $ticketRef, $note) {
        $game = housie_lock_active_game();
        if ((int) $game['id'] !== $gameId) {
            throw new HousieException('This claim belongs to a different game than the one in progress.');
        }
        if (!housie_game_in_play($game)) {
            throw new HousieException('Claims can only be recorded while the game is in progress.');
        }
        $check = housie_check_claim($gameId, $prizeId, $ticketRef);
        $reason = trim($note) !== '' ? mb_substr(trim($note), 0, 200)
            : ($check['evaluation']['reason'] ?? ($check['problems'][0] ?? 'Rejected by admin'));
        $code = $check['ticket'] ? $check['ticket']['code'] : mb_substr(trim($ticketRef), 0, 30);
        $pdo->prepare("INSERT INTO housie_claims (game_id, prize_id, ticket_id, ticket_ref, category, status, reason,
                                                  calls_at_claim, last_number, decided_by, created_at)
                       VALUES (?, ?, ?, ?, ?, 'REJECTED', ?, ?, ?, ?, ?)")
            ->execute([$gameId, $prizeId, $check['ticket']['id'] ?? null, $code, $check['prize']['category'], $reason,
                       (int) $game['calls_count'], $game['current_number'], $userId ?: null, date('Y-m-d H:i:s')]);
        audit_log('HOUSIE_CLAIM_REJECT', ['game_id' => $gameId, 'category' => $check['prize']['category'],
                                          'ticket' => $code, 'reason' => $reason], $userId);
        return ['ticket' => $code, 'reason' => $reason];
    });
}

/** Claims of a game, newest first. */
function housie_claims_list(int $gameId, ?int $limit = null): array
{
    $sql = "SELECT c.*, t.ticket_no, COALESCE(c.participant_name, t.participant_name) AS participant_name, p.prize_name
              FROM housie_claims c
              JOIN housie_prizes p ON p.id = c.prize_id
              LEFT JOIN housie_tickets t ON t.id = c.ticket_id
             WHERE c.game_id = ? ORDER BY c.id DESC" . ($limit ? ' LIMIT ' . (int) $limit : '');
    $st = db()->prepare($sql);
    $st->execute([$gameId]);
    return $st->fetchAll();
}

/**
 * Manual winner entry for physical tickets the system did not generate (or when the
 * ticket was checked by hand in the room). The admin has already verified the ticket;
 * the server still enforces: game in progress, category enabled, winners-allowed limit,
 * no duplicate ticket for the same category, and the Full House order rules.
 */
function housie_record_manual_winner(int $userId, int $gameId, int $prizeId, string $ticketRef, string $name, string $note = ''): array
{
    $ticketRef = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $ticketRef)));
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    if ($ticketRef === '') {
        throw new HousieException('Enter the ticket number from the physical ticket.');
    }
    if (mb_strlen($ticketRef) > 30 || !preg_match('/^[\p{L}\p{N}\-\/#. ]+$/u', $ticketRef)) {
        throw new HousieException('Ticket number may only contain letters, digits and - / # . (max 30).');
    }
    if (mb_strlen($name) > 100) {
        throw new HousieException('Winner name is too long (max 100 characters).');
    }
    return with_transaction(function (PDO $pdo) use ($userId, $gameId, $prizeId, $ticketRef, $name, $note) {
        $game = housie_lock_active_game();
        if ((int) $game['id'] !== $gameId) {
            throw new HousieException('This entry belongs to a different game than the one in progress.');
        }
        if (!housie_game_in_play($game)) {
            throw new HousieException($game['status'] === 'SETUP' ? 'Start the game before entering winners.' : 'This game has ended; no more winners can be entered.');
        }
        $prize = housie_prize_row($gameId, $prizeId, true);
        if (!$prize) {
            throw new HousieException('Select a winning category of this game.');
        }
        $category = $prize['category'];
        $label = housie_category_label($category);
        if (!(int) $prize['enabled']) {
            throw new HousieException("$label is not enabled for this game.");
        }
        $confirmed = housie_confirmed_count($prizeId);
        if ($confirmed >= (int) $prize['winners_allowed']) {
            throw new HousieException("$label is already completed ($confirmed of {$prize['winners_allowed']} winner(s)).");
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM housie_claims WHERE prize_id = ? AND status = 'CONFIRMED' AND UPPER(ticket_ref) = ?");
        $st->execute([$prizeId, $ticketRef]);
        if ((int) $st->fetchColumn() > 0) {
            throw new HousieException("Ticket $ticketRef is already a winner of $label.");
        }
        $tiers = ['FULL_HOUSE', 'SECOND_FULL_HOUSE', 'THIRD_FULL_HOUSE'];
        $tier = array_search($category, $tiers, true);
        if ($tier !== false) {
            if ($tier > 0) {
                $st = $pdo->prepare('SELECT * FROM housie_prizes WHERE game_id = ? AND category = ?');
                $st->execute([$gameId, $tiers[$tier - 1]]);
                $prev = $st->fetch();
                if (!$prev || !(int) $prev['enabled'] || housie_confirmed_count((int) $prev['id']) < (int) $prev['winners_allowed']) {
                    throw new HousieException(housie_category_label($tiers[$tier - 1]) . " must be completed before $label.");
                }
            }
            $st = $pdo->prepare("SELECT p.category FROM housie_claims c JOIN housie_prizes p ON p.id = c.prize_id
                                  WHERE c.game_id = ? AND c.status = 'CONFIRMED' AND UPPER(c.ticket_ref) = ?
                                    AND p.category IN ('FULL_HOUSE','SECOND_FULL_HOUSE','THIRD_FULL_HOUSE')");
            $st->execute([$gameId, $ticketRef]);
            $won = $st->fetchColumn();
            if ($won) {
                throw new HousieException("Ticket $ticketRef has already won " . housie_category_label($won) . '; a ticket can win only one Full House.');
            }
        }
        $rank = $confirmed + 1;
        $reason = 'Verified manually in the room' . (trim($note) !== '' ? ': ' . mb_substr(trim($note), 0, 180) : '');
        $pdo->prepare("INSERT INTO housie_claims (game_id, prize_id, ticket_id, ticket_ref, participant_name, category, status, winner_rank,
                                                  reason, calls_at_claim, last_number, decided_by, created_at)
                       VALUES (?, ?, NULL, ?, ?, ?, 'CONFIRMED', ?, ?, ?, ?, ?, ?)")
            ->execute([$gameId, $prizeId, $ticketRef, $name === '' ? null : $name, $category, $rank, $reason,
                       (int) $game['calls_count'], $game['current_number'], $userId ?: null, date('Y-m-d H:i:s')]);
        $claimId = (int) $pdo->lastInsertId();
        housie_bump_version($gameId);
        audit_log('HOUSIE_CLAIM_MANUAL', ['game_id' => $gameId, 'claim_id' => $claimId, 'category' => $category,
                                          'ticket' => $ticketRef, 'participant' => $name, 'rank' => $rank,
                                          'calls' => (int) $game['calls_count']], $userId);
        return [
            'claim_id'    => $claimId,
            'rank'        => $rank,
            'label'       => $label,
            'prize_name'  => $prize['prize_name'],
            'ticket'      => $ticketRef,
            'participant' => $name,
            'completed'   => $rank >= (int) $prize['winners_allowed'],
        ];
    });
}
