<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

if (is_post()) {
    require_csrf();
    $action = post_str('action', 20);
    try {
        if ($action === 'event') {
            $name = post_str('event_name', 150);
            $date = post_str('event_date', 10);
            $venue = post_str('event_venue', 150);
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if ($name === '') {
                flash('danger', 'Event name is required.');
            } elseif (!$d || $d->format('Y-m-d') !== $date) {
                flash('danger', 'Please enter a valid event date.');
            } else {
                set_setting('event_name', $name);
                set_setting('event_date', $date);
                set_setting('event_venue', $venue);
                bump_draw_version();
                audit_log('SETTINGS_UPDATE', ['event_name' => $name, 'event_date' => $date, 'event_venue' => $venue]);
                flash('success', 'Event details saved.');
            }
        } elseif ($action === 'password') {
            $current = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $confirm = (string) ($_POST['confirm_password'] ?? '');
            if (!verify_current_password($current)) {
                flash('danger', 'Current password is incorrect.');
            } elseif (strlen($new) < 8) {
                flash('danger', 'New password must be at least 8 characters.');
            } elseif ($new !== $confirm) {
                flash('danger', 'New password and confirmation do not match.');
            } else {
                $st = db()->prepare('UPDATE users SET password = ? WHERE id = ?');
                $st->execute([password_hash($new, PASSWORD_DEFAULT), (int) $_SESSION['user_id']]);
                session_regenerate_id(true);
                audit_log('PASSWORD_CHANGE');
                flash('success', 'Password changed successfully.');
            }
        } elseif ($action === 'reset') {
            $mode = post_str('reset_mode', 20);
            if (post_str('confirm_text', 20) !== 'RESET') {
                flash('danger', 'Type RESET in capital letters to confirm.');
            } elseif (!verify_current_password((string) ($_POST['password'] ?? ''))) {
                flash('danger', 'Password is incorrect. Reset cancelled.');
            } else {
                $r = lottery_reset((int) $_SESSION['user_id'], $mode === 'full');
                $msg = 'Lottery reset: ' . $r['winners_cleared'] . ' winner(s) cleared';
                if ($mode === 'full') {
                    $msg .= ', ' . $r['tickets_deleted'] . ' ticket(s) deleted';
                }
                if ($r['archive']) {
                    $msg .= '. Previous winners archived to exports/' . $r['archive'];
                }
                flash('success', $msg . '.');
            }
        }
    } catch (Throwable $ex) {
        flash('danger', log_exception($ex));
    }
    redirect('admin/settings.php');
}

$settings = settings_all();
$stats = draw_stats();
$audit = db()->query('SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                      ORDER BY a.id DESC LIMIT 150')->fetchAll();

$pageTitle = 'Settings';
$activeNav = 'settings';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-gear"></i> Settings</h1>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="bi bi-calendar-event"></i> Event Details</div>
            <div class="card-body">
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="event">
                    <div class="mb-3">
                        <label class="form-label" for="event_name">Event name</label>
                        <input class="form-control" id="event_name" name="event_name" maxlength="150" required value="<?= e($settings['event_name']) ?>">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-5">
                            <label class="form-label" for="event_date">Event date</label>
                            <input class="form-control" id="event_date" type="date" name="event_date" required value="<?= e($settings['event_date']) ?>">
                        </div>
                        <div class="col-sm-7">
                            <label class="form-label" for="event_venue">Venue <span class="text-muted small">(optional)</span></label>
                            <input class="form-control" id="event_venue" name="event_venue" maxlength="150" value="<?= e($settings['event_venue']) ?>">
                        </div>
                    </div>
                    <button class="btn btn-festive"><i class="bi bi-save"></i> Save</button>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="bi bi-key"></i> Change Password</div>
            <div class="card-body">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="password">
                    <div class="mb-3">
                        <label class="form-label" for="current_password">Current password</label>
                        <input class="form-control" id="current_password" type="password" name="current_password" required>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="new_password">New password</label>
                            <input class="form-control" id="new_password" type="password" name="new_password" minlength="8" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="confirm_password">Confirm new password</label>
                            <input class="form-control" id="confirm_password" type="password" name="confirm_password" minlength="8" required>
                        </div>
                    </div>
                    <button class="btn btn-primary"><i class="bi bi-shield-lock"></i> Change Password</button>
                </form>
            </div>
        </div>

        <div class="card border-danger">
            <div class="card-header text-danger fw-semibold"><i class="bi bi-exclamation-octagon"></i> Reset Lottery (Danger Zone)</div>
            <div class="card-body">
                <p class="small">Use this only for a rehearsal or to restart the event. All confirmed winners (<?= (int) $stats['winners_total'] ?>) are removed after being archived as a CSV in <code>exports/</code>, every ticket becomes eligible again and every prize returns to <em>Pending</em>. Tickets and prizes become editable again.</p>
                <form method="post" onsubmit="return confirm('Really reset the lottery? This clears all confirmed winners.');" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset">
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="reset_mode" value="draws" id="rm1" checked>
                            <label class="form-check-label" for="rm1">Reset draws only (keep tickets and prize names)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="reset_mode" value="full" id="rm2">
                            <label class="form-check-label" for="rm2">Full reset (also delete all <?= number_format($stats['tickets_total']) ?> tickets)</label>
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-sm-6">
                            <label class="form-label" for="confirm_text">Type <strong>RESET</strong></label>
                            <input class="form-control" id="confirm_text" name="confirm_text" required>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="reset_password">Your password</label>
                            <input class="form-control" id="reset_password" type="password" name="password" required>
                        </div>
                    </div>
                    <button class="btn btn-danger"><i class="bi bi-arrow-counterclockwise"></i> Reset Lottery</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-journal-text"></i> Audit Log (latest 150)</div>
            <div class="table-responsive" style="max-height: 900px">
                <table class="table table-sm small mb-0 align-middle">
                    <thead class="table-light sticky-top"><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($audit as $a): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(date('d M H:i:s', strtotime($a['created_at']))) ?></td>
                            <td><?= e($a['username'] ?? '—') ?></td>
                            <td><span class="badge text-bg-light border"><?= e($a['action']) ?></span></td>
                            <td class="text-break"><?= e(mb_strimwidth((string) $a['details'], 0, 220, '…')) ?></td>
                            <td class="text-muted"><?= e($a['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
