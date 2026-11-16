<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

// Guarantee that exactly prize numbers 1..TOTAL_PRIZES exist.
$existingNumbers = db()->query('SELECT prize_number FROM prizes')->fetchAll(PDO::FETCH_COLUMN);
$missing = array_diff(range(1, TOTAL_PRIZES), array_map('intval', $existingNumbers));
if ($missing) {
    $ins = db()->prepare('INSERT INTO prizes (prize_number, prize_name) VALUES (?, ?)');
    foreach ($missing as $n) {
        $ins->execute([$n, 'Prize No. ' . $n]);
    }
}

$lock = setup_lock_reason();

if (is_post()) {
    require_csrf();
    $names = isset($_POST['name']) && is_array($_POST['name']) ? $_POST['name'] : [];
    $descs = isset($_POST['description']) && is_array($_POST['description']) ? $_POST['description'] : [];
    $errors = [];
    $clean = [];
    for ($n = 1; $n <= TOTAL_PRIZES; $n++) {
        $name = mb_substr(clean_text(is_string($names[$n] ?? null) ? $names[$n] : ''), 0, 300);
        $desc = mb_substr(clean_text(is_string($descs[$n] ?? null) ? $descs[$n] : ''), 0, 1000);
        if ($name === '') {
            $errors[] = "Prize $n: name is required.";
        } elseif (mb_strlen($name) > 150) {
            $errors[] = "Prize $n: name must be at most 150 characters.";
        }
        if (mb_strlen($desc) > 500) {
            $errors[] = "Prize $n: description must be at most 500 characters.";
        }
        $clean[$n] = [$name, $desc];
    }

    if ($errors) {
        foreach (array_slice($errors, 0, 10) as $err) {
            flash('danger', $err);
        }
        $_SESSION['old_prizes'] = $clean;
    } else {
        try {
            with_transaction(function (PDO $pdo) use ($clean) {
                $s = draw_session_row(true);
                if ($s['state'] === 'ROLLING' || $s['state'] === 'REVEALED' || confirmed_draw_count() > 0) {
                    throw new DrawException('Prizes are locked because the lottery draw has started. Use Settings › Reset Lottery to unlock.');
                }
                $up = $pdo->prepare('UPDATE prizes SET prize_name = ?, prize_description = ? WHERE prize_number = ?');
                foreach ($clean as $n => $row) {
                    $up->execute([$row[0], $row[1] === '' ? null : $row[1], $n]);
                }
                $pdo->exec('UPDATE draw_session SET version = version + 1 WHERE id = 1');
            });
            audit_log('PRIZES_UPDATE', ['count' => count($clean)]);
            flash('success', 'All ' . TOTAL_PRIZES . ' prizes saved.');
        } catch (DrawException $ex) {
            flash('danger', $ex->getMessage());
        } catch (Throwable $ex) {
            flash('danger', log_exception($ex));
        }
    }
    redirect('admin/prizes.php');
}

$prizes = db()->query('SELECT p.*, d.winning_lottery_number, d.sold_by, d.sold_to
                         FROM prizes p LEFT JOIN lottery_draws d ON d.prize_id = p.id
                        WHERE p.prize_number BETWEEN 1 AND ' . (int) TOTAL_PRIZES . '
                        ORDER BY p.prize_number DESC')->fetchAll();
$old = $_SESSION['old_prizes'] ?? [];
unset($_SESSION['old_prizes']);

$pageTitle = 'Prizes';
$activeNav = 'prizes';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-gift"></i> Prize Master</h1>
        <p class="text-muted mb-0">Exactly <?= TOTAL_PRIZES ?> prizes. The draw runs in <strong>descending order: <?= TOTAL_PRIZES ?> → 1</strong>. Prize 1 is the Grand Prize, drawn last.</p>
    </div>
</div>

<?php if ($lock): ?>
    <div class="alert alert-warning"><i class="bi bi-lock-fill"></i> <?= e($lock) ?></div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <div class="card">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:90px">Prize No.</th>
                        <th style="width:110px">Draw Order</th>
                        <th>Prize Name <span class="text-danger">*</span></th>
                        <th>Description <span class="text-muted small">(optional)</span></th>
                        <th style="width:110px">Status</th>
                        <th>Winner</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($prizes as $p):
                    $n = (int) $p['prize_number'];
                    $name = $old[$n][0] ?? $p['prize_name'];
                    $desc = $old[$n][1] ?? (string) $p['prize_description']; ?>
                    <tr class="<?= $n === 1 ? 'table-warning' : '' ?>">
                        <td class="fw-bold fs-5"><?= $n ?></td>
                        <td class="text-muted small">#<?= TOTAL_PRIZES - $n + 1 ?> to draw</td>
                        <td><input class="form-control form-control-sm" name="name[<?= $n ?>]" value="<?= e($name) ?>" maxlength="150" required <?= $lock ? 'disabled' : '' ?>></td>
                        <td><input class="form-control form-control-sm" name="description[<?= $n ?>]" value="<?= e($desc) ?>" maxlength="500" <?= $lock ? 'disabled' : '' ?>></td>
                        <td>
                            <?php if ($p['status'] === 'DRAWN'): ?>
                                <span class="badge text-bg-success">Drawn</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($p['winning_lottery_number'] !== null): ?>
                                <span class="fw-bold font-monospace"><?= e($p['winning_lottery_number']) ?></span>
                                <span class="text-muted">· <?= e($p['sold_by']) ?><?= $p['sold_to'] ? ' → ' . e($p['sold_to']) : '' ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex justify-content-end sticky-bottom bg-body">
            <button class="btn btn-festive btn-lg" <?= $lock ? 'disabled' : '' ?>><i class="bi bi-save"></i> Save All Prizes</button>
        </div>
    </div>
</form>
<?php require APP_ROOT . '/includes/footer.php'; ?>
