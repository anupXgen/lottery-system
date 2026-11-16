<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

$winners = all_winners('prize_desc');          // only confirmed winners, 50 -> 1
$stats = draw_stats();
$settings = settings_all();
$complete = $stats['prizes_remaining'] === 0 && count($winners) === $stats['prizes_total'];
$export = get_str('export', 10);

/* ---------------------------- CSV export ---------------------------- */
if ($export === 'csv') {
    $name = 'diwali-lottery-winners-' . date('Ymd-His') . '.csv';
    audit_log('REPORT_CSV', ['winners' => count($winners)]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    write_winners_csv($out, $winners);
    fclose($out);
    exit;
}

/* ---------------------------- PDF export ---------------------------- */
if ($export === 'pdf') {
    if (!class_exists(\Dompdf\Dompdf::class)) {
        flash('danger', 'PDF library (Dompdf) is not installed. Run "composer install" in the project folder.');
        redirect('admin/reports.php');
    }
    $title = $complete ? 'DIWALI LOTTERY – FINAL WINNERS LIST' : 'DIWALI LOTTERY – WINNERS LIST (PROVISIONAL)';
    ob_start();
    ?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 26mm 14mm 20mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; color: #222; }
    header { position: fixed; top: -19mm; left: 0; right: 0; height: 14mm; border-bottom: 2px solid #b8410b; }
    header .brand { font-size: 9pt; color: #b8410b; font-weight: bold; letter-spacing: 1px; }
    header .right { position: absolute; right: 0; top: 0; font-size: 8pt; color: #777; }
    h1 { text-align: center; font-size: 17pt; color: #7a2a08; margin: 0 0 4mm; letter-spacing: 1px; }
    .sub { text-align: center; color: #555; margin-bottom: 5mm; }
    table.meta { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
    table.meta td { padding: 2mm 3mm; border: 1px solid #e3c9a8; background: #fdf6ec; width: 25%; }
    table.meta .k { font-size: 7.5pt; color: #8a6a55; text-transform: uppercase; display: block; }
    table.meta .v { font-size: 11pt; font-weight: bold; }
    table.winners { width: 100%; border-collapse: collapse; }
    table.winners th { background: #7a2a08; color: #fff; padding: 2.4mm 2mm; border: 1px solid #5a1a07; font-size: 9pt; text-align: left; }
    table.winners td { padding: 2mm; border: 1px solid #bbb; vertical-align: top; }
    table.winners tr:nth-child(even) td { background: #faf6f1; }
    table.winners td.c, table.winners th.c { text-align: center; }
    td.num { font-weight: bold; font-size: 11pt; text-align: center; }
    tr.grand td { background: #fff3cd !important; font-weight: bold; }
    .note { margin-top: 6mm; font-size: 8pt; color: #777; }
    .sign { margin-top: 16mm; width: 100%; }
    .sign td { width: 50%; padding-top: 12mm; font-size: 9pt; }
    .sign span { border-top: 1px solid #555; padding-top: 1mm; display: inline-block; width: 60mm; text-align: center; }
    .empty { text-align: center; padding: 10mm; color: #777; }
</style>
</head>
<body>
<header>
    <div class="brand"><?= e(mb_strtoupper($settings['event_name'])) ?></div>
    <div class="right">Generated <?= e(date('d M Y, h:i A')) ?></div>
</header>
<h1><?= e($title) ?></h1>
<div class="sub"><?= e($settings['event_name']) ?><?= $settings['event_venue'] ? ' · ' . e($settings['event_venue']) : '' ?></div>
<table class="meta">
    <tr>
        <td><span class="k">Event Date</span><span class="v"><?= e(nice_date($settings['event_date'])) ?></span></td>
        <td><span class="k">Total Prizes</span><span class="v"><?= (int) $stats['prizes_total'] ?></span></td>
        <td><span class="k">Total Winners</span><span class="v"><?= count($winners) ?></span></td>
        <td><span class="k">Generation Date</span><span class="v"><?= e(date('d M Y')) ?></span></td>
    </tr>
</table>
<table class="winners">
    <thead>
        <tr>
            <th class="c" style="width: 13%">Prize No.</th>
            <th style="width: 27%">Prize Name</th>
            <th class="c" style="width: 18%">Lottery Number</th>
            <th style="width: 21%">Sold By</th>
            <th style="width: 21%">Sold To</th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$winners): ?>
        <tr><td colspan="5" class="empty">No confirmed winners yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($winners as $w): ?>
        <tr class="<?= (int) $w['prize_number'] === 1 ? 'grand' : '' ?>">
            <td class="c"><?= (int) $w['prize_number'] ?></td>
            <td><?= e($w['prize_name']) ?></td>
            <td class="num"><?= e($w['winning_lottery_number']) ?></td>
            <td><?= e($w['sold_by']) ?></td>
            <td><?= $w['sold_to'] !== null && $w['sold_to'] !== '' ? e($w['sold_to']) : '—' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if (!$complete): ?>
    <p class="note">Provisional report: <?= count($winners) ?> of <?= (int) $stats['prizes_total'] ?> prizes have confirmed winners. Only confirmed winners are listed.</p>
<?php else: ?>
    <p class="note">All <?= (int) $stats['prizes_total'] ?> prizes were drawn by secure server-side random selection and confirmed by the administrator.</p>
<?php endif; ?>
<table class="sign">
    <tr>
        <td><span>Organiser</span></td>
        <td style="text-align: right"><span>Witness</span></td>
    </tr>
</table>
</body>
</html>
    <?php
    $html = ob_get_clean();

    try {
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('chroot', APP_ROOT);
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Page numbers in the footer of every page
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(
            $canvas->get_width() / 2 - 30,
            $canvas->get_height() - 34,
            'Page {PAGE_NUM} of {PAGE_COUNT}',
            $font,
            8,
            [0.4, 0.4, 0.4]
        );
        $canvas->page_text(40, $canvas->get_height() - 34, $settings['event_name'], $font, 7, [0.55, 0.55, 0.55]);

        $pdf = $dompdf->output();
        $fileName = ($complete ? 'diwali-lottery-final-winners-' : 'diwali-lottery-winners-provisional-') . date('Ymd-His') . '.pdf';
        @file_put_contents(APP_ROOT . '/exports/' . $fileName, $pdf); // archive copy
        audit_log('REPORT_PDF', ['file' => $fileName, 'winners' => count($winners), 'final' => $complete]);

        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (isset($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: no-store');
        echo $pdf;
        exit;
    } catch (Throwable $ex) {
        flash('danger', 'Could not generate the PDF: ' . log_exception($ex));
        redirect('admin/reports.php');
    }
}

/* ---------------------------- Page ---------------------------- */
$pageTitle = 'Reports';
$activeNav = 'reports';
require APP_ROOT . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-file-earmark-pdf"></i> Final Winners Report</h1>
        <p class="text-muted mb-0">Only confirmed winners are included, sorted by prize number <?= TOTAL_PRIZES ?> → 1.</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-festive btn-lg" href="<?= e(url('admin/reports.php?export=pdf')) ?>"><i class="bi bi-file-earmark-pdf"></i> Download PDF</a>
        <a class="btn btn-outline-secondary btn-lg" href="<?= e(url('admin/reports.php?export=pdf&inline=1')) ?>" target="_blank" rel="noopener"><i class="bi bi-eye"></i> Preview</a>
        <a class="btn btn-outline-success btn-lg" href="<?= e(url('admin/reports.php?export=csv')) ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
    </div>
</div>

<?php if ($complete): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> All <?= (int) $stats['prizes_total'] ?> prizes have confirmed winners. The PDF is the <strong>final</strong> winners list.</div>
<?php else: ?>
    <div class="alert alert-warning"><i class="bi bi-hourglass-split"></i> <?= count($winners) ?> of <?= (int) $stats['prizes_total'] ?> prizes drawn. The PDF will be marked <strong>provisional</strong> until all prizes are drawn.</div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead class="table-light">
                <tr><th>Prize No.</th><th>Prize Name</th><th>Lottery Number</th><th>Sold By</th><th>Sold To</th><th>Confirmed At</th></tr>
            </thead>
            <tbody>
            <?php if (!$winners): ?>
                <tr><td colspan="6" class="text-center text-muted py-5">No confirmed winners yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($winners as $w): ?>
                <tr>
                    <td class="fw-bold"><?= (int) $w['prize_number'] ?></td>
                    <td><?= e($w['prize_name']) ?></td>
                    <td class="fw-bold font-monospace fs-5"><?= e($w['winning_lottery_number']) ?></td>
                    <td><?= e($w['sold_by']) ?></td>
                    <td><?= $w['sold_to'] !== null && $w['sold_to'] !== '' ? e($w['sold_to']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="small text-muted"><?= e(nice_datetime($w['confirmed_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
