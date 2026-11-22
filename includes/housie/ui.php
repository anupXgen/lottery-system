<?php
/** Small HTML helpers shared by the Housie admin pages (output is always escaped). */

/** Render a ticket grid as a table. $called: number => true (highlighted), $pattern: cell indexes outlined. */
function housie_ticket_html(array $ticket, string $size = '', array $called = [], array $pattern = []): string
{
    $grid = is_array($ticket['grid']) ? $ticket['grid'] : housie_grid_decode($ticket['grid']);
    $patternSet = array_fill_keys($pattern, true);
    $html = '<div class="hz-ticket ' . e($size) . '"><div class="hz-ticket-head"><span>Ticket ' . e(housie_ticket_code((int) $ticket['ticket_no']))
          . '</span><span>' . e((string) ($ticket['participant_name'] ?? '')) . '</span></div><table>';
    for ($r = 0; $r < 3; $r++) {
        $html .= '<tr>';
        for ($c = 0; $c < 9; $c++) {
            $i = $r * 9 + $c;
            $n = $grid[$i];
            $cls = [];
            if ($n === 0) {
                $cls[] = 'blank';
            } elseif (isset($called[$n])) {
                $cls[] = 'called';
            }
            if (isset($patternSet[$i])) {
                $cls[] = 'pattern';
                if ($n > 0 && !isset($called[$n])) {
                    $cls[] = 'missing';
                }
            }
            $html .= '<td class="' . e(implode(' ', $cls)) . '">' . ($n ? (int) $n : '') . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table></div>';
}

function housie_state_badge(string $status): string
{
    $text = ['SETUP' => 'Setup', 'RUNNING' => 'Running', 'PAUSED' => 'Paused', 'ENDED' => 'Ended'][$status] ?? $status;
    return '<span class="badge hz-state-' . e($status) . '">' . e($text) . '</span>';
}

function housie_prize_badge(string $state): string
{
    $text = ['PENDING' => 'Pending', 'CLAIMED' => 'Claimed', 'COMPLETED' => 'Completed'][$state] ?? $state;
    return '<span class="badge hz-prize-' . e($state) . '">' . e($text) . '</span>';
}

/** Page header strip with the active game name and status. */
function housie_game_strip(?array $game): string
{
    if (!$game) {
        return '<div class="alert alert-info">No Housie game yet. <a href="' . e(url('housie/index.php')) . '">Create a game</a> to begin.</div>';
    }
    return '<div class="d-flex flex-wrap align-items-center gap-2 mb-3 small text-muted"><span>Active game:</span><strong class="text-body">'
        . e($game['name']) . '</strong>' . ($game['game_date'] ? '<span>· ' . e(nice_date($game['game_date'])) . '</span>' : '')
        . housie_state_badge($game['status']) . '</div>';
}

/** Embedded organiser logo for printouts / PDFs (null if the file is missing). */
function housie_logo_data_uri(): ?string
{
    static $uri = false;
    if ($uri === false) {
        $file = APP_ROOT . '/assets/images/logo.jpg';
        $uri = is_file($file) ? 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents($file)) : null;
    }
    return $uri;
}

/** Render HTML to an A4 PDF with Dompdf and send it (same settings as the lottery report). */
function housie_send_pdf(string $html, string $fileName, bool $inline, ?string $footerText = null): void
{
    if (!class_exists(\Dompdf\Dompdf::class)) {
        http_response_code(500);
        exit('PDF library (Dompdf) is not installed.');
    }
    @set_time_limit(120);
    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isPhpEnabled', false);
    $options->set('chroot', APP_ROOT);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $canvas->page_text($canvas->get_width() / 2 - 30, $canvas->get_height() - 28, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.4, 0.4, 0.4]);
    if ($footerText !== null) {
        $canvas->page_text(36, $canvas->get_height() - 28, $footerText, $font, 7, [0.55, 0.55, 0.55]);
    }
    $pdf = $dompdf->output();
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: no-store');
    echo $pdf;
    exit;
}
