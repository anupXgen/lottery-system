<?php
/**
 * Housie / Tambola module - shared library.
 *
 * Completely independent of the lottery: it only uses the housie_* tables, plus the
 * existing generic helpers (db(), with_transaction(), audit_log(), auth). Load it after
 * includes/bootstrap.php:
 *
 *   require dirname(__DIR__) . '/includes/bootstrap.php';
 *   require APP_ROOT . '/includes/housie/housie.php';
 */

require_once __DIR__ . '/tickets.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/claims.php';
require_once __DIR__ . '/ui.php';

/** Business-rule violation in the Housie module (shown to the admin as-is). */
class HousieException extends RuntimeException
{
}

/**
 * Winning categories: key => [label, default description, default winners, enabled by default].
 * The order here is the default prize order.
 */
function housie_categories(): array
{
    return [
        'EARLY_FIVE'        => ['Early Five', 'First to mark any five numbers', 1, true],
        'TOP_LINE'          => ['Top Line', 'All five numbers in the top row', 1, true],
        'MIDDLE_LINE'       => ['Middle Line', 'All five numbers in the middle row', 1, true],
        'BOTTOM_LINE'       => ['Bottom Line', 'All five numbers in the bottom row', 1, true],
        'FOUR_CORNERS'      => ['Four Corners', 'The four corner numbers of the ticket', 1, true],
        'FULL_HOUSE'        => ['Full House', 'All 15 numbers on the ticket', 1, true],
        'SECOND_FULL_HOUSE' => ['Second Full House', 'All 15 numbers - second full house', 1, false],
        'THIRD_FULL_HOUSE'  => ['Third Full House', 'All 15 numbers - third full house', 1, false],
    ];
}

function housie_category_label(string $category): string
{
    $c = housie_categories();
    return isset($c[$category]) ? $c[$category][0] : $category;
}

/* ------------------------------------------------------------------ */
/* Installation / settings                                             */
/* ------------------------------------------------------------------ */

/** True once database/migrations/add_housie_module.sql has been applied. */
function housie_installed(): bool
{
    static $installed = null;
    if ($installed === null) {
        try {
            $installed = (bool) db()->query("SHOW TABLES LIKE 'housie_game_settings'")->fetchColumn();
        } catch (Throwable $e) {
            $installed = false;
        }
    }
    return $installed;
}

/** For admin pages: show a friendly notice instead of an error if the migration was not run. */
function housie_require_installed_page(): void
{
    if (housie_installed()) {
        return;
    }
    $pageTitle = 'Housie / Tambola';
    $activeNav = 'housie';
    require APP_ROOT . '/includes/header.php';
    echo '<div class="alert alert-warning"><h1 class="h5"><i class="bi bi-database-exclamation"></i> Housie module is not installed yet</h1>'
        . '<p class="mb-1">Run the database migration once on the server, then reload this page:</p>'
        . '<pre class="mb-0">mysql diwali_lottery &lt; database/migrations/add_housie_module.sql</pre></div>';
    require APP_ROOT . '/includes/footer.php';
    exit;
}

function housie_setting(string $key, string $default = ''): string
{
    $st = db()->prepare('SELECT setting_value FROM housie_game_settings WHERE setting_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? $default : (string) $v;
}

function housie_set_setting(string $key, string $value): void
{
    $st = db()->prepare('INSERT INTO housie_game_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $st->execute([$key, $value, date('Y-m-d H:i:s')]);
}

/* ------------------------------------------------------------------ */
/* Games                                                               */
/* ------------------------------------------------------------------ */

function housie_game(int $gameId, bool $forUpdate = false): ?array
{
    $st = db()->prepare('SELECT * FROM housie_games WHERE id = ?' . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$gameId]);
    $g = $st->fetch();
    return $g ?: null;
}

/** The game currently shown on the Housie screens (null if none created yet). */
function housie_active_game(bool $forUpdate = false): ?array
{
    $id = (int) housie_setting('active_game_id', '0');
    return $id > 0 ? housie_game($id, $forUpdate) : null;
}

function housie_game_in_play(?array $game): bool
{
    return $game !== null && ($game['status'] === 'RUNNING' || $game['status'] === 'PAUSED');
}

function housie_bump_version(int $gameId): void
{
    db()->prepare('UPDATE housie_games SET version = version + 1 WHERE id = ?')->execute([$gameId]);
}

/** Create a new game with the default prize configuration and make it the active game. */
function housie_create_game(int $userId, string $name, ?string $date): array
{
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 150) {
        throw new HousieException('Enter a game name (up to 150 characters).');
    }
    if ($date !== null && $date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new HousieException('Enter a valid game date.');
    }
    return with_transaction(function (PDO $pdo) use ($userId, $name, $date) {
        $active = housie_active_game(true);
        if (housie_game_in_play($active)) {
            throw new HousieException('Finish (END) the game in progress before creating a new one.');
        }
        $now = date('Y-m-d H:i:s');
        $pdo->prepare('INSERT INTO housie_games (name, game_date, status, created_by, created_at) VALUES (?, ?, \'SETUP\', ?, ?)')
            ->execute([$name, $date ?: null, $userId ?: null, $now]);
        $gameId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO housie_prizes (game_id, category, prize_name, description, winners_allowed, sort_order, enabled, updated_at)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $order = 0;
        foreach (housie_categories() as $cat => $def) {
            $ins->execute([$gameId, $cat, $def[0], $def[1], $def[2], ++$order, $def[3] ? 1 : 0, $now]);
        }
        housie_set_setting('active_game_id', (string) $gameId);
        audit_log('HOUSIE_GAME_CREATE', ['game_id' => $gameId, 'name' => $name], $userId);
        return housie_game($gameId);
    });
}

/** Switch which game the Housie screens show. Not allowed while a game is in play. */
function housie_activate_game(int $userId, int $gameId): void
{
    with_transaction(function () use ($userId, $gameId) {
        $active = housie_active_game(true);
        if ($active && (int) $active['id'] === $gameId) {
            return;
        }
        if (housie_game_in_play($active)) {
            throw new HousieException('A game is in progress. END it before switching to another game.');
        }
        $game = housie_game($gameId, true);
        if (!$game) {
            throw new HousieException('Game not found.');
        }
        housie_set_setting('active_game_id', (string) $gameId);
        housie_bump_version($gameId);
        audit_log('HOUSIE_GAME_ACTIVATE', ['game_id' => $gameId], $userId);
    });
}

function housie_games_list(): array
{
    return db()->query('SELECT g.*,
                               (SELECT COUNT(*) FROM housie_tickets t WHERE t.game_id = g.id) AS tickets,
                               (SELECT COUNT(*) FROM housie_claims c WHERE c.game_id = g.id AND c.status = \'CONFIRMED\') AS winners
                          FROM housie_games g ORDER BY g.id DESC')->fetchAll();
}

/* ------------------------------------------------------------------ */
/* Prizes                                                              */
/* ------------------------------------------------------------------ */

/** Prizes of a game in prize order, each with its confirmed winners and status. */
function housie_prizes(int $gameId, bool $enabledOnly = false): array
{
    $st = db()->prepare('SELECT * FROM housie_prizes WHERE game_id = ?' . ($enabledOnly ? ' AND enabled = 1' : '')
                        . ' ORDER BY sort_order, id');
    $st->execute([$gameId]);
    $prizes = $st->fetchAll();

    // LEFT JOIN: manual (physical ticket) winners have no system ticket row.
    $w = db()->prepare("SELECT c.id AS claim_id, c.prize_id, c.winner_rank, c.created_at, c.calls_at_claim, c.last_number,
                               c.ticket_ref, t.ticket_no, COALESCE(c.participant_name, t.participant_name) AS participant_name
                          FROM housie_claims c LEFT JOIN housie_tickets t ON t.id = c.ticket_id
                         WHERE c.game_id = ? AND c.status = 'CONFIRMED'
                         ORDER BY c.winner_rank");
    $w->execute([$gameId]);
    $byPrize = [];
    foreach ($w->fetchAll() as $row) {
        $byPrize[(int) $row['prize_id']][] = $row;
    }
    foreach ($prizes as &$p) {
        $p['winners'] = $byPrize[(int) $p['id']] ?? [];
        $p['confirmed'] = count($p['winners']);
        $p['state'] = housie_prize_state((int) $p['winners_allowed'], $p['confirmed']);
        $p['label'] = housie_category_label($p['category']);
    }
    unset($p);
    return $prizes;
}

/** Ticket shown for a winner: the system Ticket ID, or the physical ticket number typed in. */
function housie_winner_ticket(array $w): string
{
    return $w['ticket_no'] !== null ? housie_ticket_code((int) $w['ticket_no']) : (string) $w['ticket_ref'];
}

/** PENDING (no winner yet) / CLAIMED (some winners) / COMPLETED (all winners confirmed). */
function housie_prize_state(int $allowed, int $confirmed): string
{
    if ($confirmed <= 0) {
        return 'PENDING';
    }
    return $confirmed >= $allowed ? 'COMPLETED' : 'CLAIMED';
}

/**
 * Save the prize configuration. $rows: category => [enabled, prize_name, description, winners_allowed, sort_order].
 * A category with confirmed winners cannot be disabled or set below its confirmed count.
 */
function housie_save_prizes(int $userId, int $gameId, array $rows): void
{
    with_transaction(function (PDO $pdo) use ($userId, $gameId, $rows) {
        $game = housie_game($gameId, true);
        if (!$game) {
            throw new HousieException('Game not found.');
        }
        if ($game['status'] === 'ENDED') {
            throw new HousieException('This game has ended; its prizes can no longer be changed.');
        }
        $current = [];
        foreach (housie_prizes($gameId) as $p) {
            $current[$p['category']] = $p;
        }
        $upd = $pdo->prepare('UPDATE housie_prizes SET enabled = ?, prize_name = ?, description = ?, winners_allowed = ?,
                                     sort_order = ?, updated_at = ? WHERE id = ?');
        $now = date('Y-m-d H:i:s');
        $enabledCount = 0;
        foreach (housie_categories() as $cat => $def) {
            if (!isset($current[$cat], $rows[$cat])) {
                continue;
            }
            $r = $rows[$cat];
            $p = $current[$cat];
            $enabled = !empty($r['enabled']);
            $name = trim((string) ($r['prize_name'] ?? ''));
            $desc = trim((string) ($r['description'] ?? ''));
            $winners = (int) ($r['winners_allowed'] ?? 1);
            $order = (int) ($r['sort_order'] ?? $p['sort_order']);
            $label = $def[0];
            if ($name === '' || mb_strlen($name) > 150) {
                throw new HousieException("$label: enter a prize name (up to 150 characters).");
            }
            if (mb_strlen($desc) > 255) {
                throw new HousieException("$label: the description is too long (max 255 characters).");
            }
            if ($winners < 1 || $winners > 50) {
                throw new HousieException("$label: number of winners must be between 1 and 50.");
            }
            if ($order < 0 || $order > 99) {
                throw new HousieException("$label: prize order must be between 0 and 99.");
            }
            if ($p['confirmed'] > 0 && !$enabled) {
                throw new HousieException("$label already has a confirmed winner and cannot be disabled.");
            }
            if ($winners < $p['confirmed']) {
                throw new HousieException("$label already has {$p['confirmed']} confirmed winner(s); winners allowed cannot be lower.");
            }
            $enabledCount += $enabled ? 1 : 0;
            $upd->execute([$enabled ? 1 : 0, $name, $desc === '' ? null : $desc, $winners, $order, $now, $p['id']]);
        }
        if ($enabledCount === 0) {
            throw new HousieException('Enable at least one winning category.');
        }
        housie_bump_version($gameId);
        audit_log('HOUSIE_PRIZES_SAVE', ['game_id' => $gameId, 'enabled' => $enabledCount], $userId);
    });
}

/* ------------------------------------------------------------------ */
/* Read models                                                         */
/* ------------------------------------------------------------------ */

function housie_stats(int $gameId): array
{
    $q = function (string $sql) use ($gameId) {
        $st = db()->prepare($sql);
        $st->execute([$gameId]);
        return (int) $st->fetchColumn();
    };
    return [
        'tickets'      => $q('SELECT COUNT(*) FROM housie_tickets WHERE game_id = ?'),
        'participants' => $q("SELECT COUNT(*) FROM housie_tickets WHERE game_id = ? AND participant_name IS NOT NULL AND participant_name <> ''"),
        'called'       => $q('SELECT COUNT(*) FROM housie_draw_numbers WHERE game_id = ?'),
        'claims'       => $q('SELECT COUNT(*) FROM housie_claims WHERE game_id = ?'),
        'confirmed'    => $q("SELECT COUNT(*) FROM housie_claims WHERE game_id = ? AND status = 'CONFIRMED'"),
        'rejected'     => $q("SELECT COUNT(*) FROM housie_claims WHERE game_id = ? AND status = 'REJECTED'"),
    ];
}

/**
 * Public status for the Housie LED screen and admin pages. Contains only numbers that
 * have already been called - never the stored sequence. `token` changes on every update,
 * so pollers can send ?since=<token> and get a tiny "unchanged" reply.
 */
function housie_live_status(?string $since = null): array
{
    $title = housie_setting('display_title', 'DIWALI HOUSIE');
    $game = housie_active_game();
    if (!$game) {
        return ['ok' => true, 'changed' => true, 'token' => 'none', 'title' => $title, 'game' => null];
    }
    $token = $game['id'] . ':' . $game['version'];
    if ($since !== null && $since === $token) {
        return ['ok' => true, 'changed' => false, 'token' => $token];
    }
    $gameId = (int) $game['id'];
    $called = housie_called_numbers($gameId);
    $numbers = array_map(function ($r) { return (int) $r['number']; }, $called);

    $prizes = [];
    $latest = null;
    foreach (housie_prizes($gameId, true) as $p) {
        $winners = [];
        foreach ($p['winners'] as $w) {
            $item = [
                'claim_id'    => (int) $w['claim_id'],
                'rank'        => (int) $w['winner_rank'],
                'ticket'      => housie_winner_ticket($w),
                'participant' => (string) $w['participant_name'],
                'at'          => $w['created_at'],
            ];
            $winners[] = $item;
            if ($latest === null || $item['claim_id'] > $latest['claim_id']) {
                $latest = $item + ['category' => $p['category'], 'label' => $p['label'], 'prize_name' => $p['prize_name']];
            }
        }
        $prizes[] = [
            'id'              => (int) $p['id'],
            'category'        => $p['category'],
            'label'           => $p['label'],
            'prize_name'      => $p['prize_name'],
            'description'     => (string) $p['description'],
            'winners_allowed' => (int) $p['winners_allowed'],
            'state'           => $p['state'],
            'winners'         => $winners,
        ];
    }

    return [
        'ok'             => true,
        'changed'        => true,
        'token'          => $token,
        'title'          => $title,
        'game'           => [
            'id'     => $gameId,
            'name'   => $game['name'],
            'date'   => nice_date($game['game_date']),
            'status' => $game['status'],
        ],
        'current_number' => $game['current_number'] !== null ? (int) $game['current_number'] : null,
        'calls_count'    => (int) $game['calls_count'],
        'remaining'      => 90 - (int) $game['calls_count'],
        'called'         => $numbers,
        'previous'       => array_slice(array_reverse($numbers), 1, 5),
        'prizes'         => $prizes,
        'latest_winner'  => $latest,
        'server_time'    => date('c'),
    ];
}

/**
 * Shared runner for Housie admin APIs: login + POST + CSRF, run the action and reply
 * with JSON including the fresh live status.
 */
function housie_api_action(callable $action, string $successMessage): void
{
    require_admin_api();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if (!housie_installed()) {
        json_error('The Housie module is not installed (database migration not applied).', 503);
    }
    try {
        $result = $action($userId);
        json_response(['ok' => true, 'message' => $successMessage, 'result' => $result, 'status' => housie_live_status()]);
    } catch (HousieException $e) {
        json_response(['ok' => false, 'error' => $e->getMessage(), 'status' => housie_live_status()], 409);
    } catch (PDOException $e) {
        $code = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        if ($code === 1205 || $code === 1213) {
            json_response(['ok' => false, 'error' => 'Another request is being processed. Please try again.', 'status' => housie_live_status()], 409);
        }
        if ($code === 1062) {
            json_response(['ok' => false, 'error' => 'That was already recorded (duplicate blocked).', 'status' => housie_live_status()], 409);
        }
        json_error(log_exception($e), 500);
    } catch (Throwable $e) {
        json_error(log_exception($e), 500);
    }
}
