<?php
/** Housie Prize Configuration: enable categories, names, descriptions, winners allowed, order. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';
require_login();
housie_require_installed_page();
$userId = (int) $_SESSION['user_id'];
$game = housie_active_game();

if (is_post() && $game) {
    require_csrf();
    $rows = [];
    $in = isset($_POST['p']) && is_array($_POST['p']) ? $_POST['p'] : [];
    foreach (housie_categories() as $cat => $def) {
        $r = isset($in[$cat]) && is_array($in[$cat]) ? $in[$cat] : [];
        $rows[$cat] = [
            'enabled'         => !empty($r['enabled']),
            'prize_name'      => mb_substr(clean_text(is_string($r['prize_name'] ?? null) ? $r['prize_name'] : ''), 0, 200),
            'description'     => mb_substr(clean_text(is_string($r['description'] ?? null) ? $r['description'] : ''), 0, 300),
            'winners_allowed' => (int) ($r['winners_allowed'] ?? 1),
            'sort_order'      => (int) ($r['sort_order'] ?? 0),
        ];
    }
    try {
        housie_save_prizes($userId, (int) $game['id'], $rows);
        flash('success', 'Prize configuration saved.');
    } catch (HousieException $e) {
        flash('danger', $e->getMessage());
    }
    redirect('housie/prizes.php');
}

$prizes = $game ? housie_prizes((int) $game['id']) : [];
$locked = $game && $game['status'] === 'ENDED';
$pageTitle = 'Housie Prizes';
$activeNav = 'housie';
$pageStyles = ['assets/css/housie.css'];
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 mb-2"><i class="bi bi-trophy text-warning"></i> Housie Prize Configuration</h1>
<?= housie_game_strip($game) ?>

<?php if ($game): ?>
<form method="post">
    <?= csrf_field() ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                <tr><th>Enabled</th><th>Category</th><th style="min-width: 200px">Prize name</th><th style="min-width: 220px">Description</th><th>Winners allowed</th><th>Order</th><th>Status</th></tr>
                </thead>
                <tbody>
                <?php foreach ($prizes as $p): $k = 'p[' . $p['category'] . ']'; $hasWinners = $p['confirmed'] > 0; ?>
                <tr class="<?= (int) $p['enabled'] ? '' : 'table-light text-muted' ?>">
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="<?= e($k) ?>[enabled]" value="1" id="en-<?= e($p['category']) ?>"
                                <?= (int) $p['enabled'] ? 'checked' : '' ?> <?= $hasWinners || $locked ? 'disabled' : '' ?> aria-label="Enable <?= e($p['label']) ?>">
                            <?php if ($hasWinners): ?><input type="hidden" name="<?= e($k) ?>[enabled]" value="1"><?php endif; ?>
                        </div>
                    </td>
                    <td><strong><?= e($p['label']) ?></strong><div class="small text-muted"><?= e(housie_categories()[$p['category']][1]) ?></div></td>
                    <td><input class="form-control" name="<?= e($k) ?>[prize_name]" value="<?= e($p['prize_name']) ?>" maxlength="150" required <?= $locked ? 'disabled' : '' ?> aria-label="Prize name for <?= e($p['label']) ?>"></td>
                    <td><input class="form-control" name="<?= e($k) ?>[description]" value="<?= e((string) $p['description']) ?>" maxlength="255" <?= $locked ? 'disabled' : '' ?> aria-label="Description for <?= e($p['label']) ?>"></td>
                    <td style="width: 120px"><input class="form-control" type="number" min="<?= max(1, (int) $p['confirmed']) ?>" max="50" name="<?= e($k) ?>[winners_allowed]" value="<?= (int) $p['winners_allowed'] ?>" <?= $locked ? 'disabled' : '' ?> aria-label="Winners allowed for <?= e($p['label']) ?>"></td>
                    <td style="width: 95px"><input class="form-control" type="number" min="0" max="99" name="<?= e($k) ?>[sort_order]" value="<?= (int) $p['sort_order'] ?>" <?= $locked ? 'disabled' : '' ?> aria-label="Order for <?= e($p['label']) ?>"></td>
                    <td class="text-nowrap"><?= (int) $p['enabled'] ? housie_prize_badge($p['state']) : '<span class="badge text-bg-light">Disabled</span>' ?>
                        <div class="small text-muted"><?= (int) $p['confirmed'] ?> / <?= (int) $p['winners_allowed'] ?> confirmed</div></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="small text-muted">Second / Third Full House can only be claimed after the previous Full House is completed, and a ticket can win only one Full House. A category with confirmed winners cannot be disabled.</span>
            <?php if (!$locked): ?><button class="btn btn-primary"><i class="bi bi-save"></i> Save prizes</button><?php else: ?><span class="text-muted">This game has ended; prizes are read-only.</span><?php endif; ?>
        </div>
    </div>
</form>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
