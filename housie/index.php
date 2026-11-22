<?php
/** Housie dashboard: active game overview, create / switch games. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';
require_login();
housie_require_installed_page();
$userId = (int) $_SESSION['user_id'];

if (is_post()) {
    require_csrf();
    try {
        $action = post_str('action', 20);
        if ($action === 'create') {
            $g = housie_create_game($userId, post_str('name', 150), post_str('game_date', 10));
            flash('success', 'Game "' . $g['name'] . '" created. Next: generate tickets and check the prize configuration.');
        } elseif ($action === 'activate') {
            housie_activate_game($userId, (int) ($_POST['game_id'] ?? 0));
            flash('success', 'Active game switched.');
        }
    } catch (HousieException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('housie/index.php');
}

$game = housie_active_game();
$stats = $game ? housie_stats((int) $game['id']) : null;
$prizes = $game ? housie_prizes((int) $game['id'], true) : [];
$games = housie_games_list();
$pageTitle = 'Housie Dashboard';
$activeNav = 'housie';
$pageStyles = ['assets/css/housie.css'];
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-grid-3x3-gap text-warning"></i> Housie / Tambola</h1>
    <a class="btn btn-warning fw-semibold" href="<?= e(url('housie/live-display.php')) ?>" target="housieDisplay" rel="noopener"><i class="bi bi-display"></i> Open Housie LED Display</a>
</div>
<?= housie_game_strip($game) ?>

<?php if ($game): ?>
<div class="row g-3 mb-4">
    <?php foreach ([
        ['Tickets', $stats['tickets'], 'bi-ticket-detailed', 'housie/tickets.php'],
        ['Participants assigned', $stats['participants'], 'bi-people', 'housie/tickets.php'],
        ['Numbers called', $game['calls_count'] . ' / 90', 'bi-123', 'housie/live-draw.php'],
        ['Confirmed winners', $stats['confirmed'], 'bi-trophy', 'housie/reports.php'],
        ['Claims rejected', $stats['rejected'], 'bi-x-octagon', 'housie/claims.php'],
    ] as $card): ?>
    <div class="col-6 col-md-4 col-xl">
        <a class="card text-decoration-none h-100" href="<?= e(url($card[3])) ?>">
            <div class="card-body">
                <div class="text-muted small"><i class="bi <?= e($card[2]) ?>"></i> <?= e($card[0]) ?></div>
                <div class="fs-3 fw-bold text-body"><?= e((string) $card[1]) ?></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-list-check"></i> Game checklist</div>
            <ul class="list-group list-group-flush">
                <?php
                $steps = [
                    [$stats['tickets'] > 0, 'Generate and print tickets (optional – skip if you use pre-printed physical tickets)', 'housie/tickets.php'],
                    [count($prizes) > 0, 'Configure prizes (' . count($prizes) . ' categories enabled)', 'housie/prizes.php'],
                    [$game['status'] !== 'SETUP', 'Start the game and call numbers', 'housie/live-draw.php'],
                    [$stats['confirmed'] > 0, 'Verify claims and confirm winners', 'housie/claims.php'],
                    [$game['status'] === 'ENDED', 'End the game and download the report', 'housie/reports.php'],
                ];
                foreach ($steps as $s): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi <?= $s[0] ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?>"></i> <?= e($s[1]) ?></span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($s[2])) ?>">Open</a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-trophy"></i> Prizes</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($prizes as $p): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><strong><?= e($p['label']) ?></strong> <span class="text-muted small">· <?= e($p['prize_name']) ?> · <?= (int) $p['confirmed'] ?>/<?= (int) $p['winners_allowed'] ?></span></span>
                    <?= housie_prize_badge($p['state']) ?>
                </li>
                <?php endforeach; ?>
                <?php if (!$prizes): ?><li class="list-group-item text-muted">No categories enabled.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4 mt-1">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-plus-circle"></i> Create a new game</div>
            <div class="card-body">
                <?php if (housie_game_in_play($game)): ?>
                    <p class="text-muted mb-0">A game is in progress. End it on the Live Number Draw page before creating a new one.</p>
                <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <div class="mb-2">
                        <label class="form-label" for="name">Game name</label>
                        <input class="form-control" id="name" name="name" maxlength="150" required placeholder="e.g. Diwali Housie Night 2026">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="game_date">Date</label>
                        <input class="form-control" id="game_date" name="game_date" type="date" value="<?= e(date('Y-m-d')) ?>">
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> Create game</button>
                    <div class="form-text">The new game becomes the active game, with its own tickets, prizes, numbers and winners.</div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-collection"></i> All games</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Game</th><th>Date</th><th>Status</th><th>Tickets</th><th>Called</th><th>Winners</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($games as $g): $isActive = $game && (int) $g['id'] === (int) $game['id']; ?>
                        <tr class="<?= $isActive ? 'table-warning' : '' ?>">
                            <td><?= (int) $g['id'] ?></td>
                            <td><?= e($g['name']) ?></td>
                            <td><?= e(nice_date($g['game_date'])) ?></td>
                            <td><?= housie_state_badge($g['status']) ?></td>
                            <td><?= (int) $g['tickets'] ?></td>
                            <td><?= (int) $g['calls_count'] ?></td>
                            <td><?= (int) $g['winners'] ?></td>
                            <td class="text-end">
                                <?php if ($isActive): ?>
                                    <span class="badge text-bg-warning">Active</span>
                                <?php elseif (!housie_game_in_play($game)): ?>
                                    <form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><input type="hidden" name="game_id" value="<?= (int) $g['id'] ?>"><button class="btn btn-sm btn-outline-secondary">Make active</button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$games): ?><tr><td colspan="8" class="text-center text-muted py-3">No games yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
