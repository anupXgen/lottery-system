<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

/* ---------------------------- Actions (POST) ---------------------------- */
if (is_post()) {
    require_csrf();
    $action = post_str('action', 20);
    $back = 'admin/tickets.php';
    try {
        if ($action === 'add') {
            $errors = ticket_create(post_str('lottery_number', 60), post_str('sold_by', 200), post_str('sold_to', 200));
            if ($errors) {
                $_SESSION['old_ticket'] = $_POST;
                foreach ($errors as $err) {
                    flash('danger', $err);
                }
            } else {
                flash('success', 'Ticket added successfully.');
            }
        } elseif ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $errors = ticket_update($id, post_str('lottery_number', 60), post_str('sold_by', 200), post_str('sold_to', 200));
            if ($errors) {
                foreach ($errors as $err) {
                    flash('danger', $err);
                }
                $back = 'admin/tickets.php?edit=' . $id;
            } else {
                flash('success', 'Ticket updated successfully.');
            }
        } elseif ($action === 'delete') {
            $errors = ticket_delete((int) ($_POST['id'] ?? 0));
            flash($errors ? 'danger' : 'success', $errors ? implode(' ', $errors) : 'Ticket deleted.');
        } elseif ($action === 'delete_all') {
            if (post_str('confirm_text', 20) !== 'DELETE') {
                flash('danger', 'Type DELETE in the confirmation box to remove all tickets.');
            } else {
                $errors = ticket_delete_all();
                flash($errors ? 'danger' : 'success', $errors ? implode(' ', $errors) : 'All tickets deleted.');
            }
        }
    } catch (Throwable $ex) {
        flash('danger', log_exception($ex));
    }
    // Preserve list filters
    $qs = [];
    foreach (['q', 'status', 'page'] as $k) {
        if (!empty($_POST['return_' . $k])) {
            $qs[$k] = (string) $_POST['return_' . $k];
        }
    }
    if ($qs && strpos($back, '?') === false) {
        $back .= '?' . http_build_query($qs);
    }
    redirect($back);
}

/* ---------------------------- Listing ---------------------------- */
$lock = setup_lock_reason();
$stats = draw_stats();
$q = get_str('q', 100);
$status = get_str('status', 20);
$perPage = 50;
$page = max(1, (int) ($_GET['page'] ?? 1));

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(t.lottery_number LIKE ? OR t.sold_by LIKE ? OR t.sold_to LIKE ?)';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, $like, $like, $like);
}
if ($status === 'AVAILABLE' || $status === 'WON') {
    $where[] = 't.status = ?';
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cnt = db()->prepare("SELECT COUNT(*) FROM lottery_tickets t $whereSql");
$cnt->execute($params);
$filtered = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($filtered / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$list = db()->prepare("SELECT t.*, p.prize_number
                         FROM lottery_tickets t
                    LEFT JOIN lottery_draws d ON d.lottery_ticket_id = t.id
                    LEFT JOIN prizes p ON p.id = d.prize_id
                         $whereSql
                     ORDER BY CHAR_LENGTH(t.lottery_number), t.lottery_number
                        LIMIT $perPage OFFSET $offset");
$list->execute($params);
$tickets = $list->fetchAll();

$editTicket = isset($_GET['edit']) ? find_ticket((int) $_GET['edit']) : null;
$old = $_SESSION['old_ticket'] ?? [];
unset($_SESSION['old_ticket']);

$pageTitle = 'Lottery Tickets';
$activeNav = 'tickets';
require APP_ROOT . '/includes/header.php';

$returnFields = '<input type="hidden" name="return_q" value="' . e($q) . '">'
    . '<input type="hidden" name="return_status" value="' . e($status) . '">'
    . '<input type="hidden" name="return_page" value="' . e((string) $page) . '">';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-ticket-perforated"></i> Lottery Tickets</h1>
    <div class="d-flex gap-2 flex-wrap">
        <span class="badge text-bg-dark fs-6">Total: <?= number_format($stats['tickets_total']) ?></span>
        <span class="badge text-bg-success fs-6">Eligible: <?= number_format($stats['tickets_available']) ?></span>
        <span class="badge text-bg-warning fs-6">Won: <?= number_format($stats['tickets_won']) ?></span>
    </div>
</div>

<?php if ($lock): ?>
    <div class="alert alert-warning"><i class="bi bi-lock-fill"></i> <?= e($lock) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <?php if ($editTicket): ?>
            <div class="card border-primary">
                <div class="card-header fw-semibold"><i class="bi bi-pencil-square"></i> Edit Ticket</div>
                <div class="card-body">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $editTicket['id'] ?>">
                        <div class="mb-3">
                            <label class="form-label">Lottery Number <span class="text-danger">*</span></label>
                            <input class="form-control" name="lottery_number" maxlength="30" required value="<?= e($editTicket['lottery_number']) ?>" <?= $lock ? 'disabled' : '' ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sold By <span class="text-danger">*</span></label>
                            <input class="form-control" name="sold_by" maxlength="100" required value="<?= e($editTicket['sold_by']) ?>" <?= $lock ? 'disabled' : '' ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sold To <span class="text-muted small">(optional)</span></label>
                            <input class="form-control" name="sold_to" maxlength="100" value="<?= e((string) $editTicket['sold_to']) ?>" <?= $lock ? 'disabled' : '' ?>>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary" <?= $lock ? 'disabled' : '' ?>><i class="bi bi-save"></i> Save Changes</button>
                            <a class="btn btn-outline-secondary" href="<?= e(url('admin/tickets.php')) ?>">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-header fw-semibold"><i class="bi bi-plus-circle"></i> Add Ticket</div>
                <div class="card-body">
                    <form method="post">
                        <?= csrf_field() ?>
                        <?= $returnFields ?>
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label class="form-label">Lottery Number <span class="text-danger">*</span></label>
                            <input class="form-control" name="lottery_number" maxlength="30" required value="<?= e((string) ($old['lottery_number'] ?? '')) ?>" <?= $lock ? 'disabled' : '' ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sold By <span class="text-danger">*</span></label>
                            <input class="form-control" name="sold_by" maxlength="100" required value="<?= e((string) ($old['sold_by'] ?? '')) ?>" <?= $lock ? 'disabled' : '' ?>>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sold To <span class="text-muted small">(optional)</span></label>
                            <input class="form-control" name="sold_to" maxlength="100" value="<?= e((string) ($old['sold_to'] ?? '')) ?>" <?= $lock ? 'disabled' : '' ?>>
                        </div>
                        <button class="btn btn-festive" <?= $lock ? 'disabled' : '' ?>><i class="bi bi-plus-lg"></i> Add Ticket</button>
                    </form>
                    <hr>
                    <a href="<?= e(url('admin/upload-tickets.php')) ?>" class="btn btn-outline-primary w-100"><i class="bi bi-cloud-arrow-up"></i> Bulk upload CSV / Excel</a>
                </div>
            </div>
            <?php if (!$lock && $stats['tickets_total'] > 0): ?>
                <div class="card border-danger mt-4">
                    <div class="card-header text-danger fw-semibold"><i class="bi bi-trash"></i> Delete All Tickets</div>
                    <div class="card-body">
                        <form method="post" onsubmit="return confirm('Delete ALL <?= (int) $stats['tickets_total'] ?> tickets? This cannot be undone.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_all">
                            <p class="small text-muted">Useful if a wrong file was uploaded. Type <strong>DELETE</strong> to confirm.</p>
                            <div class="input-group">
                                <input class="form-control" name="confirm_text" placeholder="DELETE" autocomplete="off">
                                <button class="btn btn-outline-danger">Delete all</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <form class="row g-2 align-items-center" method="get">
                    <div class="col-sm-6">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search number, sold by, sold to">
                        </div>
                    </div>
                    <div class="col-sm-3">
                        <select class="form-select" name="status">
                            <option value="">All statuses</option>
                            <option value="AVAILABLE" <?= $status === 'AVAILABLE' ? 'selected' : '' ?>>Eligible</option>
                            <option value="WON" <?= $status === 'WON' ? 'selected' : '' ?>>Won</option>
                        </select>
                    </div>
                    <div class="col-sm-3 d-flex gap-2">
                        <button class="btn btn-primary flex-fill">Search</button>
                        <?php if ($q !== '' || $status !== ''): ?><a class="btn btn-outline-secondary" href="<?= e(url('admin/tickets.php')) ?>" title="Clear"><i class="bi bi-x-lg"></i></a><?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>#</th><th>Lottery No.</th><th>Sold By</th><th>Sold To</th><th>Status</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                    <?php if (!$tickets): ?>
                        <tr><td colspan="6" class="text-center text-muted py-5">
                            <?= $q !== '' || $status !== '' ? 'No tickets match your search.' : 'No tickets uploaded yet.' ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($tickets as $i => $t): ?>
                        <tr>
                            <td class="text-muted small"><?= $offset + $i + 1 ?></td>
                            <td class="fw-bold font-monospace"><?= e($t['lottery_number']) ?></td>
                            <td><?= e($t['sold_by']) ?></td>
                            <td><?= $t['sold_to'] !== null && $t['sold_to'] !== '' ? e($t['sold_to']) : '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if ($t['status'] === 'WON'): ?>
                                    <span class="badge text-bg-warning"><i class="bi bi-trophy-fill"></i> Won<?= $t['prize_number'] ? ' · Prize ' . (int) $t['prize_number'] : '' ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-success">Eligible</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <?php if (!$lock): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/tickets.php?edit=' . (int) $t['id'])) ?>" title="Edit"><i class="bi bi-pencil"></i></a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete ticket <?= e($t['lottery_number']) ?>?');">
                                        <?= csrf_field() ?>
                                        <?= $returnFields ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php else: ?>
                                    <i class="bi bi-lock text-muted" title="Locked"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">Showing <?= $filtered ? $offset + 1 : 0 ?>–<?= min($offset + $perPage, $filtered) ?> of <?= number_format($filtered) ?></span>
                <?php if ($pages > 1):
                    $link = function ($p) use ($q, $status) {
                        return e(url('admin/tickets.php?' . http_build_query(array_filter(['q' => $q, 'status' => $status, 'page' => $p]))));
                    }; ?>
                    <nav><ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link($page - 1) ?>">&laquo;</a></li>
                        <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                            <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= $link($p) ?>"><?= $p ?></a></li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= $link($page + 1) ?>">&raquo;</a></li>
                    </ul></nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
