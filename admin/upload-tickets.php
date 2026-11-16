<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

/* Template download */
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lottery-tickets-template.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Lottery Number', 'Sold By', 'Sold To']);
    fputcsv($out, ['1001', 'Amit', 'Rahul']);
    fputcsv($out, ['1002', 'Suresh', '']);
    fputcsv($out, ['1003', 'Rajesh', 'Priya']);
    fclose($out);
    exit;
}

$report = null;
$lock = setup_lock_reason();

if (is_post()) {
    require_csrf();
    $skipInvalid = !empty($_POST['skip_invalid']);
    $file = $_FILES['ticket_file'] ?? null;

    if ($lock) {
        flash('danger', $lock);
    } elseif (!$file || !is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        flash('danger', 'Please choose a CSV or Excel file to upload.');
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'Upload failed (error code ' . (int) $file['error'] . '). The file may be too large.');
    } elseif ($file['size'] > UPLOAD_MAX_BYTES) {
        flash('danger', 'File is too large. Maximum size is ' . round(UPLOAD_MAX_BYTES / 1048576) . ' MB.');
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        flash('danger', 'Invalid upload.');
    } else {
        $originalName = basename((string) $file['name']);
        try {
            $rows = read_ticket_file($file['tmp_name'], $originalName);
            $result = validate_ticket_rows($rows);
            $report = [
                'file'     => $originalName,
                'total'    => $result['total'],
                'valid'    => count($result['valid']),
                'errors'   => $result['errors'],
                'imported' => 0,
                'saved'    => false,
            ];
            if ($result['total'] === 0) {
                flash('warning', 'The file has no ticket rows.');
            } elseif ($result['errors'] && !$skipInvalid) {
                flash('danger', 'Validation failed: ' . count($result['errors']) . ' row(s) have problems. Nothing was saved. Fix the file, or tick "Import valid rows and skip invalid rows".');
            } else {
                $report['imported'] = import_ticket_rows($result['valid'], $originalName);
                $report['saved'] = true;
                // Keep a copy of the source file for the record.
                $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                @copy($file['tmp_name'], APP_ROOT . '/uploads/tickets-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . preg_replace('/[^a-z0-9]/', '', $ext));
                $msg = $report['imported'] . ' ticket(s) imported successfully.';
                if ($result['errors']) {
                    $msg .= ' ' . count($result['errors']) . ' invalid row(s) were skipped.';
                }
                flash('success', $msg);
            }
        } catch (DrawException $ex) {
            flash('danger', $ex->getMessage());
        } catch (RuntimeException $ex) {
            flash('danger', $ex->getMessage());
        } catch (PDOException $ex) {
            flash('danger', $ex->getCode() === '23000'
                ? 'Import aborted: a duplicate lottery number was found while saving. Nothing was saved.'
                : log_exception($ex));
        } catch (Throwable $ex) {
            flash('danger', log_exception($ex));
        }
    }
    $lock = setup_lock_reason();
}

$stats = draw_stats();
$pageTitle = 'Upload Tickets';
$activeNav = 'upload';
require APP_ROOT . '/includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-cloud-arrow-up"></i> Upload Lottery Tickets</h1>

<?php if ($lock): ?>
    <div class="alert alert-warning"><i class="bi bi-lock-fill"></i> <?= e($lock) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">Upload CSV / Excel file</div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <input class="form-control" type="file" name="ticket_file" accept=".csv,.txt,.xlsx,.xls,.ods" required <?= $lock ? 'disabled' : '' ?>>
                        <div class="form-text">Accepted: .csv, .xlsx, .xls (max <?= round(UPLOAD_MAX_BYTES / 1048576) ?> MB).</div>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="skip_invalid" value="1" id="skip_invalid" <?= $lock ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="skip_invalid">Import valid rows and skip invalid rows</label>
                        <div class="form-text">If unticked, the whole file is rejected when any row is invalid (recommended).</div>
                    </div>
                    <button class="btn btn-festive" <?= $lock ? 'disabled' : '' ?>><i class="bi bi-upload"></i> Validate &amp; Upload</button>
                </form>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-header fw-semibold">File format</div>
            <div class="card-body">
                <p class="mb-2">Three columns, in this order. The header row is optional.</p>
                <table class="table table-sm table-bordered small mb-3">
                    <thead class="table-light"><tr><th>Lottery Number</th><th>Sold By</th><th>Sold To</th></tr></thead>
                    <tbody>
                        <tr><td>1001</td><td>Amit</td><td>Rahul</td></tr>
                        <tr><td>1002</td><td>Suresh</td><td></td></tr>
                        <tr><td>1003</td><td>Rajesh</td><td>Priya</td></tr>
                    </tbody>
                </table>
                <ul class="small text-muted mb-3">
                    <li><strong>Lottery Number</strong> and <strong>Sold By</strong> are required.</li>
                    <li><strong>Sold To</strong> is optional; leave it blank.</li>
                    <li>Lottery numbers must be unique (letters, digits, <code>- / _ .</code>; max 30 chars). Leading zeros are kept in CSV files.</li>
                </ul>
                <a class="btn btn-outline-primary btn-sm" href="<?= e(url('admin/upload-tickets.php?template=1')) ?>"><i class="bi bi-download"></i> Download CSV template</a>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between">
                <span>Upload Result</span>
                <span class="text-muted small">Tickets in system: <?= number_format($stats['tickets_total']) ?></span>
            </div>
            <div class="card-body">
                <?php if (!$report): ?>
                    <p class="text-muted mb-0">Upload a file to see the validation report here.</p>
                <?php else: ?>
                    <div class="row text-center g-2 mb-3">
                        <div class="col"><div class="border rounded p-2"><div class="small text-muted">Rows read</div><div class="fs-4 fw-bold"><?= (int) $report['total'] ?></div></div></div>
                        <div class="col"><div class="border rounded p-2"><div class="small text-muted">Valid</div><div class="fs-4 fw-bold text-success"><?= (int) $report['valid'] ?></div></div></div>
                        <div class="col"><div class="border rounded p-2"><div class="small text-muted">Invalid</div><div class="fs-4 fw-bold text-danger"><?= count($report['errors']) ?></div></div></div>
                        <div class="col"><div class="border rounded p-2"><div class="small text-muted">Saved</div><div class="fs-4 fw-bold"><?= (int) $report['imported'] ?></div></div></div>
                    </div>
                    <p class="small text-muted">File: <?= e($report['file']) ?></p>
                    <?php if ($report['errors']): ?>
                        <h2 class="h6 text-danger">Rows with problems</h2>
                        <div class="table-responsive" style="max-height: 420px">
                            <table class="table table-sm table-striped small">
                                <thead class="table-light sticky-top"><tr><th>Row</th><th>Lottery No.</th><th>Problem</th></tr></thead>
                                <tbody>
                                <?php foreach (array_slice($report['errors'], 0, 500) as $err): ?>
                                    <tr><td><?= (int) $err[0] ?></td><td class="font-monospace"><?= e($err[1]) ?></td><td><?= e($err[2]) ?></td></tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($report['errors']) > 500): ?><p class="small text-muted">Showing the first 500 problems.</p><?php endif; ?>
                    <?php elseif ($report['saved']): ?>
                        <div class="alert alert-success mb-0"><i class="bi bi-check-circle"></i> All rows were valid and saved.</div>
                    <?php endif; ?>
                    <?php if ($report['saved']): ?>
                        <a class="btn btn-outline-secondary btn-sm mt-3" href="<?= e(url('admin/tickets.php')) ?>">View tickets</a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
