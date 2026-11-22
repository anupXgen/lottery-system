<?php
/** Housie Ticket Management: generate, search, assign participants, print / PDF. */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';
require_login();
housie_require_installed_page();
$userId = (int) $_SESSION['user_id'];
$game = housie_active_game();

if (is_post() && $game) {
    require_csrf();
    $back = 'housie/tickets.php' . (isset($_POST['back']) && is_string($_POST['back']) && preg_match('/^\?[\w=&%+.\-]*$/', $_POST['back']) ? $_POST['back'] : '');
    try {
        $action = post_str('action', 20);
        if ($action === 'generate') {
            $r = housie_generate_tickets($userId, (int) $game['id'], (int) ($_POST['count'] ?? 0));
            flash('success', "Generated {$r['count']} tickets (" . housie_ticket_code($r['from']) . ' to ' . housie_ticket_code($r['to']) . ').');
        } elseif ($action === 'participant') {
            $no = (int) ($_POST['ticket_no'] ?? 0);
            housie_update_participant($userId, (int) $game['id'], $no, post_str('participant_name', 100), post_str('participant_phone', 30));
            flash('success', 'Ticket ' . housie_ticket_code($no) . ' updated.');
        }
    } catch (HousieException $e) {
        flash('danger', $e->getMessage());
    }
    redirect($back);
}

$q = get_str('q', 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$list = $game ? housie_tickets_page((int) $game['id'], $q, $page, 30) : ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
$stats = $game ? housie_stats((int) $game['id']) : null;
$editable = $game && $game['status'] === 'SETUP';
$pageTitle = 'Housie Tickets';
$activeNav = 'housie';
$pageStyles = ['assets/css/housie.css'];
$link = function (int $p) use ($q) {
    return e(url('housie/tickets.php') . '?' . http_build_query(array_filter(['q' => $q, 'page' => $p > 1 ? $p : null])));
};
$backQuery = '?' . http_build_query(array_filter(['q' => $q, 'page' => $list['page'] > 1 ? $list['page'] : null]));
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 mb-2"><i class="bi bi-ticket-detailed text-warning"></i> Housie Ticket Management</h1>
<?= housie_game_strip($game) ?>

<?php if ($game): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-magic"></i> Generate tickets</div>
            <div class="card-body">
                <?php if ($editable): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="generate">
                    <label class="form-label" for="count">Number of tickets</label>
                    <div class="input-group">
                        <input class="form-control" id="count" name="count" type="number" min="1" max="<?= HOUSIE_MAX_TICKETS_PER_BATCH ?>" value="50" required>
                        <button class="btn btn-primary"><i class="bi bi-plus-lg"></i> Generate</button>
                    </div>
                    <div class="form-text">Up to <?= HOUSIE_MAX_TICKETS_PER_BATCH ?> per batch. Every ticket is a valid, unique 3 × 9 ticket with 15 numbers and its own Ticket ID.</div>
                </form>
                <?php else: ?>
                    <p class="text-muted mb-0">The game has started, so tickets and participant details are locked.</p>
                <?php endif; ?>
                <hr>
                <div class="small text-muted">Tickets: <strong class="text-body"><?= (int) $stats['tickets'] ?></strong> · Participants assigned: <strong class="text-body"><?= (int) $stats['participants'] ?></strong></div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-printer"></i> Print / PDF</div>
            <div class="card-body">
                <form class="row g-2 align-items-end" method="get" action="<?= e(url('housie/print-tickets.php')) ?>" target="_blank">
                    <div class="col-sm-3"><label class="form-label" for="from">From Ticket ID</label><input class="form-control" id="from" name="from" type="number" min="1" value="1"></div>
                    <div class="col-sm-3"><label class="form-label" for="to">To Ticket ID</label><input class="form-control" id="to" name="to" type="number" min="1" value="<?= max(1, min((int) $stats['tickets'], 60)) ?>"></div>
                    <div class="col-sm-6 d-flex gap-2">
                        <button class="btn btn-outline-primary" name="format" value="html"><i class="bi bi-printer"></i> Print view</button>
                        <button class="btn btn-outline-danger" name="format" value="pdf"><i class="bi bi-file-earmark-pdf"></i> Download PDF</button>
                    </div>
                    <div class="col-12 form-text">10 tickets per A4 page (2 × 5), with cut lines. Up to <?= HOUSIE_PRINT_LIMIT ?> tickets per print or PDF; use ranges for more.</div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold"><i class="bi bi-list-ul"></i> All tickets (<?= (int) $list['total'] ?>)</span>
        <form class="d-flex gap-2" method="get">
            <input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Ticket ID or participant name" aria-label="Search tickets">
            <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <?php if ($q !== ''): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('housie/tickets.php')) ?>">Clear</a><?php endif; ?>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Ticket ID</th><th>Ticket</th><th>Participant</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php foreach ($list['rows'] as $t): ?>
                <tr>
                    <td class="fw-bold font-monospace fs-5"><?= e(housie_ticket_code((int) $t['ticket_no'])) ?></td>
                    <td><?= housie_ticket_html($t, 'sm') ?></td>
                    <td>
                        <?php if ($editable): ?>
                        <form method="post" class="d-flex flex-wrap gap-1" style="max-width: 360px">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="participant">
                            <input type="hidden" name="ticket_no" value="<?= (int) $t['ticket_no'] ?>">
                            <input type="hidden" name="back" value="<?= e($backQuery) ?>">
                            <input class="form-control form-control-sm" name="participant_name" value="<?= e((string) $t['participant_name']) ?>" placeholder="Participant name" maxlength="100" aria-label="Participant name for ticket <?= e(housie_ticket_code((int) $t['ticket_no'])) ?>">
                            <input class="form-control form-control-sm" name="participant_phone" value="<?= e((string) $t['participant_phone']) ?>" placeholder="Phone (optional)" maxlength="30" aria-label="Phone">
                            <button class="btn btn-sm btn-outline-success"><i class="bi bi-check-lg"></i> Save</button>
                        </form>
                        <?php else: ?>
                            <?= e((string) $t['participant_name']) ?: '<span class="text-muted">—</span>' ?>
                            <?php if ($t['participant_phone']): ?><div class="small text-muted"><?= e($t['participant_phone']) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e(nice_datetime($t['created_at'])) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('housie/print-tickets.php') . '?ticket=' . (int) $t['ticket_no']) ?>" target="_blank" title="Print ticket"><i class="bi bi-printer"></i></a>
                        <a class="btn btn-sm btn-outline-danger" href="<?= e(url('housie/print-tickets.php') . '?format=pdf&ticket=' . (int) $t['ticket_no']) ?>" title="Ticket PDF"><i class="bi bi-file-earmark-pdf"></i></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$list['rows']): ?>
                <tr><td colspan="5" class="text-center text-muted py-4"><?= $q !== '' ? 'No tickets match your search.' : 'No tickets yet. Generate some above.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($list['pages'] > 1): $page = $list['page']; $pages = $list['pages']; ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= $page ?> of <?= $pages ?></span>
        <nav><ul class="pagination pagination-sm mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link($page - 1) ?>">&laquo;</a></li>
            <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $link($p) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link($page + 1) ?>">&raquo;</a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
