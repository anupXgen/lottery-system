<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

$stats = draw_stats();
$session = draw_session_row();
$next = next_pending_prize();
$lock = setup_lock_reason();
$settings = settings_all();
$defaultNames = (int) db()->query("SELECT COUNT(*) FROM prizes WHERE prize_name REGEXP '^Prize No\\\\. [0-9]+$'")->fetchColumn();
$audit = db()->query('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                      ORDER BY a.id DESC LIMIT 12')->fetchAll();

$stateLabels = [
    'IDLE'     => ['secondary', 'Waiting to start'],
    'ROLLING'  => ['warning', 'Draw rolling'],
    'REVEALED' => ['info', 'Winner revealed – awaiting confirmation'],
    'ENDED'    => ['dark', 'Lottery ended'],
];
$stateInfo = $stateLabels[$session['state']];
if ($session['state'] === 'IDLE' && $stats['prizes_remaining'] === 0) {
    $stateInfo = ['success', 'All prizes drawn'];
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h1 class="h3 mb-0"><?= e($settings['event_name']) ?></h1>
        <div class="text-muted"><i class="bi bi-calendar-event"></i> <?= e(nice_date($settings['event_date'])) ?>
            <?php if ($settings['event_venue']): ?> &middot; <i class="bi bi-geo-alt"></i> <?= e($settings['event_venue']) ?><?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= e(url('admin/live-draw.php')) ?>" class="btn btn-festive btn-lg"><i class="bi bi-broadcast"></i> Go to Live Draw</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Tickets Uploaded</div><div class="stat-value"><?= number_format($stats['tickets_total']) ?></div><div class="stat-sub"><?= number_format($stats['tickets_available']) ?> eligible</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Prizes</div><div class="stat-value"><?= $stats['prizes_total'] ?></div><div class="stat-sub"><?= $stats['prizes_drawn'] ?> drawn · <?= $stats['prizes_remaining'] ?> remaining</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Winners Announced</div><div class="stat-value"><?= $stats['winners_total'] ?></div><div class="stat-sub">confirmed &amp; saved</div></div></div>
    <div class="col-6 col-lg-3"><div class="stat-card"><div class="stat-label">Next Prize</div><div class="stat-value"><?= $next ? 'No. ' . (int) $next['prize_number'] : '—' ?></div><div class="stat-sub text-truncate"><?= $next ? e($next['prize_name']) : 'All drawn' ?></div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-list-check"></i> Event Readiness</div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi <?= $stats['tickets_total'] > 0 ? 'bi-check-circle-fill text-success' : 'bi-circle text-muted' ?>"></i> 1. Upload lottery tickets</span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/upload-tickets.php')) ?>">Upload</a>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi <?= $stats['tickets_total'] >= TOTAL_PRIZES ? 'bi-check-circle-fill text-success' : 'bi-exclamation-circle text-warning' ?>"></i> 2. At least <?= TOTAL_PRIZES ?> tickets for <?= TOTAL_PRIZES ?> prizes
                        <span class="text-muted small">(<?= number_format($stats['tickets_total']) ?> now)</span></span>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi <?= $defaultNames === 0 ? 'bi-check-circle-fill text-success' : 'bi-exclamation-circle text-warning' ?>"></i> 3. Configure prize names
                        <?php if ($defaultNames): ?><span class="text-muted small">(<?= $defaultNames ?> still have default names)</span><?php endif; ?></span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/prizes.php')) ?>">Prizes</a>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-check-circle-fill text-success"></i> 4. Event name &amp; date</span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/settings.php')) ?>">Settings</a>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-display text-primary"></i> 5. Open the LED display on the big screen</span>
                    <a class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener" href="<?= e(url('live-display.php')) ?>">Open</a>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-broadcast text-danger"></i> 6. Run the live draw (Prize <?= TOTAL_PRIZES ?> → 1)</span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/live-draw.php')) ?>">Live Draw</a>
                </li>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-file-earmark-pdf text-danger"></i> 7. Download the final winners PDF</span>
                    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/reports.php')) ?>">Reports</a>
                </li>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="bi bi-activity"></i> Draw Status</div>
            <div class="card-body">
                <span class="badge text-bg-<?= e($stateInfo[0]) ?> fs-6"><?= e($stateInfo[1]) ?></span>
                <?php if ($lock): ?>
                    <div class="alert alert-warning mt-3 mb-0 small"><i class="bi bi-lock-fill"></i> <?= e($lock) ?></div>
                <?php else: ?>
                    <div class="alert alert-success mt-3 mb-0 small"><i class="bi bi-unlock-fill"></i> Setup is open: tickets and prizes can still be changed.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-journal-text"></i> Recent Activity</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small align-middle">
                    <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
                    <tbody>
                    <?php if (!$audit): ?>
                        <tr><td colspan="4" class="text-muted text-center py-3">No activity yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($audit as $a): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(date('d M H:i:s', strtotime($a['created_at']))) ?></td>
                            <td><?= e($a['username'] ?? '—') ?></td>
                            <td><span class="badge text-bg-light border"><?= e($a['action']) ?></span></td>
                            <td class="text-break text-muted"><?= e(mb_strimwidth((string) $a['details'], 0, 90, '…')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
