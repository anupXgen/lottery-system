<?php
/**
 * Printable Housie tickets (10 per A4 page, 2 x 5 with cut lines).
 *   ?ticket=12               one ticket
 *   ?from=1&to=60            a range
 *   &format=pdf              download as PDF instead of the print view
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';
require_login();
housie_require_installed_page();

$game = housie_active_game();
if (!$game) {
    flash('warning', 'Create a Housie game first.');
    redirect('housie/index.php');
}
$single = (int) ($_GET['ticket'] ?? 0);
$from = max(1, (int) ($_GET['from'] ?? 1));
$to = (int) ($_GET['to'] ?? 0) ?: $from + HOUSIE_PRINT_LIMIT - 1;
if ($to < $from) {
    list($from, $to) = [$to, $from];
}
$tickets = $single > 0
    ? housie_tickets_for_print((int) $game['id'], [$single], null, null, 1)
    : housie_tickets_for_print((int) $game['id'], null, $from, $to, HOUSIE_PRINT_LIMIT);
$isPdf = get_str('format', 10) === 'pdf';
$title = housie_setting('display_title', 'DIWALI HOUSIE');
$logo = housie_logo_data_uri();

ob_start();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Housie tickets · <?= e($game['name']) ?></title>
<style>
    @page { size: A4 portrait; margin: 10mm 10mm 14mm 10mm; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; color: #2a1305; margin: 0; }
    .page { page-break-after: always; }
    .page:last-child { page-break-after: auto; }
    table.sheet { width: 100%; border-collapse: separate; border-spacing: 4mm 4mm; }
    table.sheet > tbody > tr > td { width: 50%; vertical-align: top; padding: 0; }
    .ticket { border: 1.6px solid #7a2a08; border-radius: 3mm; border-style: solid; overflow: hidden; }
    .ticket .head { background: #7a2a08; color: #fff; padding: 1.4mm 2.4mm; }
    .ticket .head table { width: 100%; border-collapse: collapse; }
    .ticket .head td { color: #fff; vertical-align: middle; padding: 0; }
    .ticket .head img { width: 8mm; height: 8mm; border-radius: 4mm; }
    .ticket .ev { font-size: 7.5pt; font-weight: bold; letter-spacing: 0.4pt; }
    .ticket .gm { font-size: 6.5pt; opacity: 0.85; }
    .ticket .tid { font-size: 11pt; font-weight: bold; text-align: right; white-space: nowrap; }
    table.grid { width: 100%; border-collapse: collapse; }
    table.grid td { border: 0.6px solid #c9a57a; height: 8.6mm; text-align: center; font-size: 13pt; font-weight: bold; width: 11.1%; }
    table.grid td.b { background: #f4e6cf; }
    .ticket .name { font-size: 7pt; padding: 1.2mm 2.4mm; border-top: 0.6px solid #c9a57a; color: #5a3a20; }
    .cut { border: 0.5px dashed #bbb; padding: 1.2mm; }
    .toolbar { font-family: Arial, sans-serif; padding: 10px; background: #fff3cd; border-bottom: 1px solid #e0c97a; }
    .toolbar button { font-size: 15px; padding: 6px 14px; }
    @media print { .toolbar { display: none; } }
</style>
</head>
<body>
<?php if (!$isPdf): ?>
<div class="toolbar">
    <button type="button" onclick="window.print()">Print</button>
    <span><?= count($tickets) ?> ticket(s) · <?= e($game['name']) ?></span>
</div>
<?php endif; ?>
<?php if (!$tickets): ?>
    <p style="padding: 20px">No tickets in this range.</p>
<?php endif; ?>
<?php foreach (array_chunk($tickets, 10) as $pageTickets): ?>
<div class="page">
    <table class="sheet"><tbody>
    <?php foreach (array_chunk($pageTickets, 2) as $pair): ?>
        <tr>
        <?php foreach ($pair as $t): $grid = housie_grid_decode($t['grid']); ?>
            <td><div class="cut"><div class="ticket">
                <div class="head"><table><tr>
                    <?php if ($logo): ?><td style="width: 10mm"><img src="<?= $logo ?>" alt=""></td><?php endif; ?>
                    <td><div class="ev"><?= e($title) ?></div><div class="gm"><?= e($game['name']) ?><?= $game['game_date'] ? ' · ' . e(nice_date($game['game_date'])) : '' ?></div></td>
                    <td class="tid">Ticket <?= e(housie_ticket_code((int) $t['ticket_no'])) ?></td>
                </tr></table></div>
                <table class="grid">
                <?php for ($r = 0; $r < 3; $r++): ?>
                    <tr>
                    <?php for ($c = 0; $c < 9; $c++): $n = $grid[$r * 9 + $c]; ?>
                        <td class="<?= $n ? '' : 'b' ?>"><?= $n ? (int) $n : '' ?></td>
                    <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
                </table>
                <div class="name">Name: <?= $t['participant_name'] ? e($t['participant_name']) : '________________________' ?></div>
            </div></div></td>
        <?php endforeach; ?>
        <?php if (count($pair) === 1): ?><td></td><?php endif; ?>
        </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<?php endforeach; ?>
</body>
</html>
<?php
$html = (string) ob_get_clean();
if ($isPdf) {
    audit_log('HOUSIE_TICKETS_PDF', ['game_id' => (int) $game['id'], 'count' => count($tickets)]);
    $name = 'housie-tickets-game' . (int) $game['id'] . ($single ? '-ticket-' . housie_ticket_code($single) : '-' . $from . '-' . $to) . '.pdf';
    housie_send_pdf($html, $name, isset($_GET['inline']), $game['name']);
}
echo $html;
