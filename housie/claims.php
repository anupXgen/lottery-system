<?php
/** Housie Claim Verification: check a ticket against the called numbers, then confirm or reject. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';
require_login();
housie_require_installed_page();

$game = housie_active_game();
$prizes = $game ? housie_prizes((int) $game['id'], true) : [];
$claims = $game ? housie_claims_list((int) $game['id'], 100) : [];
$pageTitle = 'Housie Claims';
$activeNav = 'housie';
$pageStyles = ['assets/css/housie.css'];
$pageScripts = ['assets/js/housie-claims.js'];
require APP_ROOT . '/includes/header.php';
?>
<script>
    window.HOUSIE_CLAIMS = {
        api: <?= json_encode(url('housie/api/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>,
        gameId: <?= $game ? (int) $game['id'] : 0 ?>
    };
</script>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-patch-check text-success"></i> Housie Claim Verification</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('housie/live-draw.php')) ?>"><i class="bi bi-broadcast"></i> Back to Live Draw</a>
</div>
<?= housie_game_strip($game) ?>

<?php if ($game): ?>
<?php if (!housie_game_in_play($game)): ?>
    <div class="alert alert-warning"><?= $game['status'] === 'SETUP' ? 'The game has not started yet. Claims can be verified once numbers are being called.' : 'This game has ended. You can still check tickets, but no more claims can be confirmed.' ?></div>
<?php endif; ?>

<!-- Manual winner entry: physical tickets checked by hand in the room -->
<div class="card mb-4 border-success">
    <div class="card-header fw-semibold bg-success-subtle"><i class="bi bi-pencil-square"></i> Manual winner entry (physical tickets)</div>
    <div class="card-body">
        <p class="small text-muted mb-3">Check the participant's ticket by hand against the called numbers below, then enter the winner. It is saved and shown on the Housie LED screen straight away.</p>
        <form id="manual-form" class="row g-3 align-items-end" autocomplete="off">
            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="manual-prize">Winning category</label>
                <select class="form-select" id="manual-prize" required>
                    <option value="">Select…</option>
                    <?php foreach ($prizes as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $p['state'] === 'COMPLETED' ? 'disabled' : '' ?>><?= e($p['label']) ?> – <?= e($p['prize_name']) ?> (<?= (int) $p['confirmed'] ?>/<?= (int) $p['winners_allowed'] ?><?= $p['state'] === 'COMPLETED' ? ', completed' : '' ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-xl-2">
                <label class="form-label" for="manual-ticket">Ticket number</label>
                <input class="form-control font-monospace" id="manual-ticket" maxlength="30" required placeholder="As printed">
            </div>
            <div class="col-md-6 col-xl-3">
                <label class="form-label" for="manual-name">Winner name</label>
                <input class="form-control" id="manual-name" maxlength="100" placeholder="Shown on the LED screen">
            </div>
            <div class="col-md-6 col-xl-2">
                <label class="form-label" for="manual-note">Note (optional)</label>
                <input class="form-control" id="manual-note" maxlength="180" placeholder="e.g. checked by Anup">
            </div>
            <div class="col-xl-2 d-grid">
                <button class="btn btn-success" id="manual-save"><i class="bi bi-trophy"></i> Save winner</button>
            </div>
        </form>
        <?php $calledNow = array_fill_keys(array_map(function ($r) { return (int) $r['number']; }, housie_called_numbers((int) $game['id'])), true); ?>
        <div class="mt-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold small">Numbers called so far: <?= count($calledNow) ?> / 90 <?= $game['current_number'] ? '· last called <span class="badge text-bg-danger fs-6">' . (int) $game['current_number'] . '</span>' : '' ?></span>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('housie/claims.php')) ?>"><i class="bi bi-arrow-clockwise"></i> Refresh</a>
            </div>
            <div class="hz-board" style="max-width: 640px">
                <?php for ($n = 1; $n <= 90; $n++): ?><span class="<?= isset($calledNow[$n]) ? ((int) $game['current_number'] === $n ? 'called current' : 'called') : '' ?>"><?= $n ?></span><?php endfor; ?>
            </div>
        </div>
    </div>
</div>

<?php if (housie_stats((int) $game['id'])['tickets'] > 0): ?>
<div class="row g-4">
    <div class="col-xl-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-search"></i> Check a system-generated ticket</div>
            <div class="card-body">
                <form id="claim-form" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label" for="claim-prize">1. Winning category</label>
                        <select class="form-select form-select-lg" id="claim-prize" required>
                            <option value="">Select a category…</option>
                            <?php foreach ($prizes as $p): ?>
                                <option value="<?= (int) $p['id'] ?>" <?= $p['state'] === 'COMPLETED' ? 'disabled' : '' ?>>
                                    <?= e($p['label']) ?> – <?= e($p['prize_name']) ?> (<?= (int) $p['confirmed'] ?>/<?= (int) $p['winners_allowed'] ?><?= $p['state'] === 'COMPLETED' ? ', completed' : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="claim-ticket">2. Ticket ID</label>
                        <input class="form-control form-control-lg font-monospace" id="claim-ticket" inputmode="numeric" maxlength="30" placeholder="e.g. 0042" required>
                    </div>
                    <button class="btn btn-primary btn-lg w-100" id="claim-check"><i class="bi bi-search"></i> Check ticket</button>
                </form>
                <div class="small text-muted mt-3">Checking does not save anything. Only <strong>Confirm winner</strong> records a winner, after the server re-checks the claim.</div>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-clipboard-check"></i> Result</div>
            <div class="card-body" id="claim-result">
                <p class="text-muted mb-0">Select a category and enter the Ticket ID the participant is claiming with.</p>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

<div class="card mt-4">
    <div class="card-header fw-semibold"><i class="bi bi-journal-text"></i> Claims log (confirmed and rejected)</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Time</th><th>Category</th><th>Ticket</th><th>Participant</th><th>Result</th><th>Numbers called</th><th>Reason</th></tr></thead>
            <tbody>
            <?php foreach ($claims as $c): ?>
                <tr>
                    <td class="small text-nowrap"><?= e(date('h:i:s A', strtotime($c['created_at']))) ?></td>
                    <td><?= e(housie_category_label($c['category'])) ?></td>
                    <td class="font-monospace fw-bold"><?= e($c['ticket_no'] ? housie_ticket_code((int) $c['ticket_no']) : $c['ticket_ref']) ?></td>
                    <td><?= e((string) $c['participant_name']) ?></td>
                    <td><?= $c['status'] === 'CONFIRMED' ? '<span class="badge text-bg-success">Confirmed #' . (int) $c['winner_rank'] . '</span>' : '<span class="badge text-bg-danger">Rejected</span>' ?></td>
                    <td><?= (int) $c['calls_at_claim'] ?></td>
                    <td class="small"><?= e((string) $c['reason']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$claims): ?><tr><td colspan="7" class="text-center text-muted py-3">No claims yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
