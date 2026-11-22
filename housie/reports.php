<?php
/**
 * Housie Reports (independent of the lottery reports).
 *   ?game=<id>                         which game (default: active game)
 *   ?export=csv&type=winners|history|tickets
 *   ?export=pdf[&inline=1]
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';
require_login();
housie_require_installed_page();

$gameId = (int) ($_GET['game'] ?? 0);
$game = $gameId > 0 ? housie_game($gameId) : housie_active_game();
$games = housie_games_list();

$winners = [];
$history = [];
$stats = null;
$gid = 0;
if ($game) {
    $gid = (int) $game['id'];
    foreach (housie_prizes($gid) as $p) {
        foreach ($p['winners'] as $w) {
            $winners[] = [
                'sort'        => $p['sort_order'],
                'category'    => $p['label'],
                'prize_name'  => $p['prize_name'],
                'rank'        => (int) $w['winner_rank'],
                'ticket'      => housie_winner_ticket($w),
                'participant' => (string) $w['participant_name'],
                'time'        => $w['created_at'],
                'calls'       => (int) $w['calls_at_claim'],
            ];
        }
    }
    $history = housie_called_numbers($gid);
    $stats = housie_stats($gid);
}

$export = get_str('export', 10);
if ($game && $export === 'csv') {
    $type = get_str('type', 10) ?: 'winners';
    $fileName = 'housie-' . $type . '-game' . $gid . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($type === 'history') {
        fputcsv($out, ['Sequence', 'Called Number', 'Called At']);
        foreach ($history as $h) {
            fputcsv($out, [$h['seq'], $h['number'], $h['called_at']]);
        }
    } elseif ($type === 'tickets') {
        fputcsv($out, ['Ticket ID', 'Participant Name', 'Phone', 'Row 1', 'Row 2', 'Row 3', 'Created']);
        $st = db()->prepare('SELECT * FROM housie_tickets WHERE game_id = ? ORDER BY ticket_no');
        $st->execute([$gid]);
        while ($t = $st->fetch()) {
            $g = housie_grid_decode($t['grid']);
            $rows = [];
            for ($r = 0; $r < 3; $r++) {
                $rows[] = implode(' ', array_map(function ($n) { return $n ?: '-'; }, array_slice($g, $r * 9, 9)));
            }
            fputcsv($out, array_merge([housie_ticket_code((int) $t['ticket_no']), (string) $t['participant_name'], (string) $t['participant_phone']], $rows, [$t['created_at']]));
        }
    } else {
        fputcsv($out, ['Category', 'Prize Name', 'Winner No.', 'Ticket ID', 'Participant Name', 'Winning Time', 'Numbers Called']);
        foreach ($winners as $w) {
            fputcsv($out, [$w['category'], $w['prize_name'], $w['rank'], $w['ticket'], $w['participant'], $w['time'], $w['calls']]);
        }
    }
    fclose($out);
    audit_log('HOUSIE_REPORT_CSV', ['game_id' => $gid, 'type' => $type]);
    exit;
}

if ($game && $export === 'pdf') {
    $title = housie_setting('display_title', 'DIWALI HOUSIE');
    $logo = housie_logo_data_uri();
    ob_start();
    ?>
<!doctype html>
<html><head><meta charset="utf-8"><style>
    @page { margin: 16mm 14mm 18mm 14mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #222; }
    .logo { text-align: center; } .logo img { width: 22mm; height: 22mm; }
    h1 { text-align: center; font-size: 16pt; color: #7a2a08; margin: 2mm 0 1mm; letter-spacing: 1px; }
    .sub { text-align: center; color: #555; margin-bottom: 5mm; }
    h2 { font-size: 11.5pt; color: #7a2a08; border-bottom: 1.5px solid #b8410b; padding-bottom: 1mm; margin: 6mm 0 2.5mm; }
    table { width: 100%; border-collapse: collapse; }
    th { background: #7a2a08; color: #fff; padding: 2mm; text-align: left; font-size: 8.5pt; }
    td { padding: 1.6mm 2mm; border: 1px solid #ccc; vertical-align: top; }
    tr:nth-child(even) td { background: #faf6f1; }
    table.stats td { width: 33%; background: #fdf6ec; border-color: #e3c9a8; }
    .k { font-size: 7pt; color: #8a6a55; text-transform: uppercase; display: block; } .v { font-size: 12pt; font-weight: bold; }
    table.hist td { text-align: center; width: 10%; padding: 1.2mm; }
    .muted { color: #777; }
</style></head><body>
<?php if ($logo): ?><div class="logo"><img src="<?= $logo ?>" alt=""></div><?php endif; ?>
<h1><?= e($title) ?> – <?= $game['status'] === 'ENDED' ? 'WINNERS REPORT' : 'WINNERS REPORT (PROVISIONAL)' ?></h1>
<div class="sub"><?= e($game['name']) ?><?= $game['game_date'] ? ' · ' . e(nice_date($game['game_date'])) : '' ?> · Generated <?= e(date('d M Y, h:i A')) ?></div>

<h2>Ticket report</h2>
<table class="stats"><tr>
    <td><span class="k">Tickets generated</span><span class="v"><?= (int) $stats['tickets'] ?></span></td>
    <td><span class="k">Participants assigned</span><span class="v"><?= (int) $stats['participants'] ?></span></td>
    <td><span class="k">Numbers called</span><span class="v"><?= (int) $stats['called'] ?> / 90</span></td>
</tr><tr>
    <td><span class="k">Claims received</span><span class="v"><?= (int) $stats['claims'] ?></span></td>
    <td><span class="k">Confirmed winners</span><span class="v"><?= (int) $stats['confirmed'] ?></span></td>
    <td><span class="k">Rejected claims</span><span class="v"><?= (int) $stats['rejected'] ?></span></td>
</tr></table>

<h2>Winners</h2>
<table><thead><tr><th>Category</th><th>Prize Name</th><th>Ticket ID</th><th>Participant Name</th><th>Winning Time</th></tr></thead><tbody>
<?php foreach ($winners as $w): ?>
    <tr><td><?= e($w['category']) ?><?= $w['rank'] > 1 ? ' (#' . $w['rank'] . ')' : '' ?></td><td><?= e($w['prize_name']) ?></td><td><strong><?= e($w['ticket']) ?></strong></td><td><?= e($w['participant']) ?: '<span class="muted">—</span>' ?></td><td><?= e(nice_datetime($w['time'])) ?></td></tr>
<?php endforeach; ?>
<?php if (!$winners): ?><tr><td colspan="5" class="muted">No confirmed winners yet.</td></tr><?php endif; ?>
</tbody></table>

<h2>Number calling history</h2>
<?php if ($history): ?>
<table class="hist"><tbody>
<?php foreach (array_chunk($history, 10) as $row): ?>
    <tr><?php foreach ($row as $h): ?><td><span class="k">#<?= (int) $h['seq'] ?> · <?= e(date('h:i:s', strtotime($h['called_at']))) ?></span><span class="v"><?= (int) $h['number'] ?></span></td><?php endforeach; ?>
    <?php for ($i = count($row); $i < 10; $i++): ?><td></td><?php endfor; ?></tr>
<?php endforeach; ?>
</tbody></table>
<?php else: ?><p class="muted">No numbers called.</p><?php endif; ?>
</body></html>
    <?php
    $html = (string) ob_get_clean();
    audit_log('HOUSIE_REPORT_PDF', ['game_id' => $gid, 'winners' => count($winners)]);
    housie_send_pdf($html, 'housie-winners-game' . $gid . '-' . date('Ymd-His') . '.pdf', isset($_GET['inline']), $game['name']);
}

$pageTitle = 'Housie Reports';
$activeNav = 'housie';
$pageStyles = ['assets/css/housie.css'];
require APP_ROOT . '/includes/header.php';
$q = $game ? '&game=' . (int) $game['id'] : '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-file-earmark-text text-warning"></i> Housie Winners Report</h1>
    <?php if ($game): ?>
    <div class="d-flex flex-wrap gap-2 no-print">
        <a class="btn btn-danger" href="<?= e(url('housie/reports.php') . '?export=pdf' . $q) ?>"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
        <a class="btn btn-outline-danger" href="<?= e(url('housie/reports.php') . '?export=pdf&inline=1' . $q) ?>" target="_blank"><i class="bi bi-eye"></i> Preview PDF</a>
        <div class="btn-group">
            <a class="btn btn-outline-success" href="<?= e(url('housie/reports.php') . '?export=csv&type=winners' . $q) ?>"><i class="bi bi-filetype-csv"></i> Winners CSV</a>
            <a class="btn btn-outline-success" href="<?= e(url('housie/reports.php') . '?export=csv&type=history' . $q) ?>">Numbers CSV</a>
            <a class="btn btn-outline-success" href="<?= e(url('housie/reports.php') . '?export=csv&type=tickets' . $q) ?>">Tickets CSV</a>
        </div>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
    <?php endif; ?>
</div>

<?php if (count($games) > 1): ?>
<form class="d-flex gap-2 mb-3 no-print" method="get" style="max-width: 460px">
    <select class="form-select" name="game" aria-label="Game">
        <?php foreach ($games as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $game && (int) $g['id'] === (int) $game['id'] ? 'selected' : '' ?>>#<?= (int) $g['id'] ?> · <?= e($g['name']) ?> (<?= e($g['status']) ?>)</option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline-primary">Show</button>
</form>
<?php endif; ?>

<?php if (!$game): ?>
    <?= housie_game_strip(null) ?>
<?php else: ?>
<div class="mb-3"><strong><?= e($game['name']) ?></strong> <?= housie_state_badge($game['status']) ?> <span class="text-muted small"><?= e(nice_date($game['game_date'])) ?></span></div>
<div class="row g-3 mb-4">
    <?php foreach ([['Tickets generated', $stats['tickets']], ['Participants assigned', $stats['participants']], ['Numbers called', $stats['called'] . ' / 90'],
                    ['Claims received', $stats['claims']], ['Confirmed winners', $stats['confirmed']], ['Rejected claims', $stats['rejected']]] as $c): ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="card h-100"><div class="card-body py-2"><div class="small text-muted"><?= e($c[0]) ?></div><div class="fs-4 fw-bold"><?= e((string) $c[1]) ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="bi bi-trophy"></i> Winners</div>
    <div class="table-responsive">
        <table class="table table-striped align-middle mb-0">
            <thead class="table-light"><tr><th>Category</th><th>Prize Name</th><th>Ticket ID</th><th>Participant Name</th><th>Winning Time</th><th>Numbers called</th></tr></thead>
            <tbody>
            <?php foreach ($winners as $w): ?>
                <tr><td><?= e($w['category']) ?><?= $w['rank'] > 1 ? ' <span class="text-muted">#' . $w['rank'] . '</span>' : '' ?></td><td><?= e($w['prize_name']) ?></td><td class="fw-bold font-monospace"><?= e($w['ticket']) ?></td><td><?= e($w['participant']) ?: '<span class="text-muted">—</span>' ?></td><td><?= e(nice_datetime($w['time'])) ?></td><td><?= (int) $w['calls'] ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$winners): ?><tr><td colspan="6" class="text-center text-muted py-4">No confirmed winners yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold"><i class="bi bi-123"></i> Number calling history (<?= count($history) ?>)</div>
    <div class="table-responsive" style="max-height: 520px">
        <table class="table table-sm mb-0">
            <thead class="table-light sticky-top"><tr><th>Sequence</th><th>Called Number</th><th>Called At</th></tr></thead>
            <tbody>
            <?php foreach ($history as $h): ?>
                <tr><td><?= (int) $h['seq'] ?></td><td class="fw-bold"><?= (int) $h['number'] ?></td><td><?= e(nice_datetime($h['called_at'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$history): ?><tr><td colspan="3" class="text-center text-muted py-3">No numbers called yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
