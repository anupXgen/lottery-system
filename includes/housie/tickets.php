<?php
/**
 * Housie tickets: generation, validation, lookup and participant details.
 *
 * A ticket is stored as 27 cells (3 rows x 9 columns, row-major) where 0 = blank.
 * Standard Tambola rules enforced by housie_validate_grid():
 *   - 3 rows x 9 columns, exactly 15 numbers, exactly 5 per row
 *   - column ranges 1-9, 10-19, ..., 70-79, 80-90; every column has 1-3 numbers
 *   - numbers ascend top to bottom within a column; all numbers unique
 * Generated tickets additionally always have numbers in the four corner cells, so every
 * ticket can win Four Corners.
 */

const HOUSIE_MAX_TICKETS_PER_BATCH = 1000;
const HOUSIE_MAX_TICKETS_PER_GAME = 10000;
const HOUSIE_PRINT_LIMIT = 300;

/** Number range [min, max] of a column (0-based). */
function housie_column_range(int $col): array
{
    if ($col === 0) {
        return [1, 9];
    }
    if ($col === 8) {
        return [80, 90];
    }
    return [$col * 10, $col * 10 + 9];
}

/** Generate one valid ticket grid (27 ints, 0 = blank) using random_int(). */
function housie_generate_grid(): array
{
    for ($attempt = 0; $attempt < 200; $attempt++) {
        // 1. How many numbers in each column: 1-3, total 15; first and last columns >= 2 (corners).
        $counts = [2, 1, 1, 1, 1, 1, 1, 1, 2];
        $extra = 15 - array_sum($counts);
        while ($extra > 0) {
            $c = random_int(0, 8);
            if ($counts[$c] < 3) {
                $counts[$c]++;
                $extra--;
            }
        }

        // 2. Which rows each column uses, so that every row ends up with exactly 5 numbers.
        $layout = array_fill(0, 3, array_fill(0, 9, false));
        $rowLeft = [5, 5, 5];
        foreach ([0, 8] as $c) {                // corners are always filled
            $layout[0][$c] = $layout[2][$c] = true;
            $rowLeft[0]--;
            $rowLeft[2]--;
            if ($counts[$c] === 3) {
                $layout[1][$c] = true;
                $rowLeft[1]--;
            }
        }
        $cols = range(1, 7);
        // Place the fuller columns first, random order among equals.
        usort($cols, function ($a, $b) use ($counts) {
            return $counts[$b] <=> $counts[$a] ?: random_int(-1, 1);
        });
        $ok = true;
        foreach ($cols as $c) {
            $rows = [0, 1, 2];
            // prefer rows with the most room left, ties broken randomly
            usort($rows, function ($a, $b) use ($rowLeft) {
                return $rowLeft[$b] <=> $rowLeft[$a] ?: random_int(-1, 1);
            });
            $chosen = array_slice(array_values(array_filter($rows, function ($r) use ($rowLeft) {
                return $rowLeft[$r] > 0;
            })), 0, $counts[$c]);
            if (count($chosen) < $counts[$c]) {
                $ok = false;
                break;
            }
            foreach ($chosen as $r) {
                $layout[$r][$c] = true;
                $rowLeft[$r]--;
            }
        }
        if (!$ok || $rowLeft !== [0, 0, 0]) {
            continue;
        }

        // 3. Pick distinct numbers per column (partial Fisher-Yates) and place them in ascending order.
        $grid = array_fill(0, 27, 0);
        for ($c = 0; $c < 9; $c++) {
            list($lo, $hi) = housie_column_range($c);
            $pool = range($lo, $hi);
            $n = count($pool);
            for ($i = 0; $i < $counts[$c]; $i++) {
                $j = random_int($i, $n - 1);
                $tmp = $pool[$i];
                $pool[$i] = $pool[$j];
                $pool[$j] = $tmp;
            }
            $picked = array_slice($pool, 0, $counts[$c]);
            sort($picked);
            foreach ([0, 1, 2] as $r) {
                if ($layout[$r][$c]) {
                    $grid[$r * 9 + $c] = array_shift($picked);
                }
            }
        }
        if (housie_validate_grid($grid) === null) {
            return $grid;
        }
    }
    throw new RuntimeException('Could not generate a valid Housie ticket.');
}

/** Returns null when the grid is a valid Tambola ticket, otherwise the reason. */
function housie_validate_grid(array $grid): ?string
{
    if (count($grid) !== 27) {
        return 'A ticket must have 27 cells (3 x 9).';
    }
    $seen = [];
    for ($r = 0; $r < 3; $r++) {
        $inRow = 0;
        for ($c = 0; $c < 9; $c++) {
            $v = $grid[$r * 9 + $c];
            if (!is_int($v) || $v < 0 || $v > 90) {
                return "Invalid value in row $r column $c.";
            }
            if ($v === 0) {
                continue;
            }
            list($lo, $hi) = housie_column_range($c);
            if ($v < $lo || $v > $hi) {
                return "Number $v is outside column " . ($c + 1) . " range $lo-$hi.";
            }
            if (isset($seen[$v])) {
                return "Number $v appears twice.";
            }
            $seen[$v] = true;
            $inRow++;
        }
        if ($inRow !== 5) {
            return 'Row ' . ($r + 1) . " has $inRow numbers (must be 5).";
        }
    }
    for ($c = 0; $c < 9; $c++) {
        $prev = 0;
        $inCol = 0;
        for ($r = 0; $r < 3; $r++) {
            $v = $grid[$r * 9 + $c];
            if ($v === 0) {
                continue;
            }
            if ($v <= $prev) {
                return 'Column ' . ($c + 1) . ' is not in ascending order.';
            }
            $prev = $v;
            $inCol++;
        }
        if ($inCol < 1) {
            return 'Column ' . ($c + 1) . ' is empty.';
        }
    }
    return count($seen) === 15 ? null : 'A ticket must contain exactly 15 numbers.';
}

function housie_grid_encode(array $grid): string
{
    return implode(',', $grid);
}

function housie_grid_decode(string $stored): array
{
    return array_map('intval', explode(',', $stored));
}

/** Sorted list of the 15 numbers on a ticket. */
function housie_grid_numbers(array $grid): array
{
    $n = array_values(array_filter($grid));
    sort($n);
    return $n;
}

/** Ticket ID as printed, e.g. 42 -> "0042". */
function housie_ticket_code(int $ticketNo): string
{
    return str_pad((string) $ticketNo, 4, '0', STR_PAD_LEFT);
}

/** Accepts "42", "0042", "T-0042", "#42" -> 42; null if no number given. */
function housie_parse_ticket_ref(string $ref): ?int
{
    $digits = preg_replace('/\D+/', '', $ref);
    if ($digits === '' || strlen($digits) > 9) {
        return null;
    }
    $n = (int) $digits;
    return $n > 0 ? $n : null;
}

/** Generate $count new tickets for a game that has not started yet. */
function housie_generate_tickets(int $userId, int $gameId, int $count): array
{
    if ($count < 1 || $count > HOUSIE_MAX_TICKETS_PER_BATCH) {
        throw new HousieException('Generate between 1 and ' . HOUSIE_MAX_TICKETS_PER_BATCH . ' tickets at a time.');
    }
    return with_transaction(function (PDO $pdo) use ($userId, $gameId, $count) {
        $game = housie_game($gameId, true);
        if (!$game) {
            throw new HousieException('Game not found.');
        }
        if ($game['status'] !== 'SETUP') {
            throw new HousieException('Tickets can only be generated before the game starts.');
        }
        $st = $pdo->prepare('SELECT numbers_key FROM housie_tickets WHERE game_id = ?');
        $st->execute([$gameId]);
        $keys = array_fill_keys($st->fetchAll(PDO::FETCH_COLUMN), true);
        if (count($keys) + $count > HOUSIE_MAX_TICKETS_PER_GAME) {
            throw new HousieException('A game can have at most ' . HOUSIE_MAX_TICKETS_PER_GAME . ' tickets.');
        }
        $st = $pdo->prepare('SELECT COALESCE(MAX(ticket_no), 0) FROM housie_tickets WHERE game_id = ?');
        $st->execute([$gameId]);
        $next = (int) $st->fetchColumn() + 1;
        $first = $next;
        $now = date('Y-m-d H:i:s');

        $rows = [];
        while (count($rows) < $count) {
            $grid = housie_generate_grid();
            $key = implode(',', housie_grid_numbers($grid));
            if (isset($keys[$key])) {
                continue; // identical combination already exists in this game
            }
            $keys[$key] = true;
            $rows[] = [$gameId, $next++, housie_grid_encode($grid), $key, $now];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            $sql = 'INSERT INTO housie_tickets (game_id, ticket_no, grid, numbers_key, created_at) VALUES '
                 . implode(',', array_fill(0, count($chunk), '(?,?,?,?,?)'));
            $pdo->prepare($sql)->execute(array_merge(...$chunk));
        }
        audit_log('HOUSIE_TICKETS_GENERATE', ['game_id' => $gameId, 'count' => $count, 'from' => $first, 'to' => $next - 1], $userId);
        return ['count' => $count, 'from' => $first, 'to' => $next - 1];
    });
}

function housie_ticket_by_no(int $gameId, int $ticketNo): ?array
{
    $st = db()->prepare('SELECT * FROM housie_tickets WHERE game_id = ? AND ticket_no = ?');
    $st->execute([$gameId, $ticketNo]);
    $t = $st->fetch();
    return $t ?: null;
}

/** Find a ticket of this game by the Ticket ID as typed (null if none). */
function housie_find_ticket(int $gameId, string $ref): ?array
{
    $no = housie_parse_ticket_ref($ref);
    return $no === null ? null : housie_ticket_by_no($gameId, $no);
}

/** Set participant details; only allowed before the game starts. */
function housie_update_participant(int $userId, int $gameId, int $ticketNo, string $name, string $phone): void
{
    $name = trim(preg_replace('/\s+/u', ' ', $name));
    $phone = trim($phone);
    if (mb_strlen($name) > 100) {
        throw new HousieException('Participant name is too long (max 100 characters).');
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{5,30}$/', $phone)) {
        throw new HousieException('Enter a valid phone number (digits, spaces, + - ( ) only).');
    }
    with_transaction(function (PDO $pdo) use ($userId, $gameId, $ticketNo, $name, $phone) {
        $game = housie_game($gameId, true);
        if (!$game || $game['status'] !== 'SETUP') {
            throw new HousieException('Participant details can only be changed before the game starts.');
        }
        $st = $pdo->prepare('UPDATE housie_tickets SET participant_name = ?, participant_phone = ?, updated_at = ?
                              WHERE game_id = ? AND ticket_no = ?');
        $st->execute([$name === '' ? null : $name, $phone === '' ? null : $phone, date('Y-m-d H:i:s'), $gameId, $ticketNo]);
        if ($st->rowCount() === 0 && !housie_ticket_by_no($gameId, $ticketNo)) {
            throw new HousieException('Ticket ' . housie_ticket_code($ticketNo) . ' does not exist in this game.');
        }
        audit_log('HOUSIE_TICKET_PARTICIPANT', ['game_id' => $gameId, 'ticket' => $ticketNo, 'name' => $name], $userId);
    });
}

/** Paged ticket search by Ticket ID or participant name. */
function housie_tickets_page(int $gameId, string $q, int $page, int $perPage): array
{
    $where = 'game_id = ?';
    $params = [$gameId];
    $q = trim($q);
    if ($q !== '') {
        $no = preg_match('/^[#A-Za-z\-\s]*\d+$/', $q) ? housie_parse_ticket_ref($q) : null;
        if ($no !== null) {
            $where .= ' AND (ticket_no = ? OR participant_name LIKE ?)';
            $params[] = $no;
        } else {
            $where .= ' AND participant_name LIKE ?';
        }
        $params[] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
    }
    $st = db()->prepare("SELECT COUNT(*) FROM housie_tickets WHERE $where");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    $st = db()->prepare("SELECT * FROM housie_tickets WHERE $where ORDER BY ticket_no LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage));
    $st->execute($params);
    return ['rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** Tickets for printing: by explicit ticket numbers or a from-to range. */
function housie_tickets_for_print(int $gameId, ?array $numbers, ?int $from, ?int $to, int $limit): array
{
    if ($numbers) {
        $numbers = array_slice(array_values(array_unique(array_map('intval', $numbers))), 0, $limit);
        $in = implode(',', array_fill(0, count($numbers), '?'));
        $st = db()->prepare("SELECT * FROM housie_tickets WHERE game_id = ? AND ticket_no IN ($in) ORDER BY ticket_no");
        $st->execute(array_merge([$gameId], $numbers));
        return $st->fetchAll();
    }
    $st = db()->prepare('SELECT * FROM housie_tickets WHERE game_id = ? AND ticket_no BETWEEN ? AND ? ORDER BY ticket_no LIMIT ' . (int) $limit);
    $st->execute([$gameId, $from ?? 1, $to ?? PHP_INT_MAX]);
    return $st->fetchAll();
}
