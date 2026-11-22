<?php
/**
 * Housie HTTP tests (included by http-tests.php while logged in as admin).
 */
if (PHP_SAPI !== 'cli' || !function_exists('http')) {
    exit(1);
}

section('H9. Housie over HTTP: pages, security, APIs, LED, reports');

/** Request without the admin session cookie. */
function http_anon(string $method, string $path, array $post = null): array
{
    $ch = curl_init($GLOBALS['base'] . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $raw = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

$hz = function (string $ep, array $data = []) use ($token) {
    $r = http('POST', '/housie/api/' . $ep, ['post' => $data + ['csrf_token' => $token], 'headers' => ['X-CSRF-Token: ' . $token]]);
    $j = json_decode($r['body'], true) ?: ['ok' => false, 'raw' => substr($r['body'], 0, 300)];
    $j['_code'] = $r['code'];
    return $j;
};

// --- access control -------------------------------------------------------
$r = http_anon('GET', '/housie/index.php');
check($r['code'] === 302 && strpos($r['headers'], 'login.php') !== false, 'Housie admin pages require the existing admin login');
$r = http_anon('POST', '/housie/api/call.php', ['x' => 1]);
check($r['code'] === 401, 'Housie draw API returns 401 without login');
$r = http_anon('GET', '/housie/api/live-status.php');
$j = json_decode($r['body'], true);
check($r['code'] === 200 && $j['ok'] === true, 'Housie live-status is public (for the LED screen)');
$r = http_anon('GET', '/housie/live-display.php');
check($r['code'] === 200 && strpos($r['body'], 'DIWALI HOUSIE') !== false && strpos($r['body'], 'housie-display.js') !== false, 'Housie LED display page loads without login');
// (PHP's built-in test server ignores nginx/.htaccess rules, so only PHP source exposure is testable here;
//  the .sql / folder deny rules are checked on the real nginx by deploy/remote-deploy.sh.)
foreach (['/includes/housie/housie.php', '/includes/housie/claims.php'] as $p) {
    $r = http_anon('GET', $p);
    check(strpos($r['body'], '<?php') === false, "Housie library source not exposed: $p");
}

// --- pages ---------------------------------------------------------------
foreach (['index', 'tickets', 'prizes', 'live-draw', 'claims', 'reports'] as $pg) {
    $r = http('GET', "/housie/$pg.php");
    check($r['code'] === 200 && no_php_errors($r['body']) && strpos($r['body'], 'Housie / Tambola') !== false, "Housie page $pg.php renders (with Housie menu)");
}
$r = http('GET', '/admin/dashboard.php');
check($r['code'] === 200 && strpos($r['body'], 'housie/index.php') !== false && strpos($r['body'], 'admin/live-draw.php') !== false, 'Lottery dashboard still renders; nav has both Lottery and Housie');

// --- create game, tickets, participants ----------------------------------
$r = http('POST', '/housie/index.php', ['post' => ['csrf_token' => 'bad', 'action' => 'create', 'name' => 'CSRF Probe']]);
$probe = (int) db()->query("SELECT COUNT(*) FROM housie_games WHERE name = 'CSRF Probe'")->fetchColumn();
check($r['code'] === 302 && $probe === 0, 'Housie forms reject a bad CSRF token (redirect, nothing created)', (string) $r['code']);
$r = http('POST', '/housie/index.php', ['post' => ['csrf_token' => $token, 'action' => 'create', 'name' => 'HTTP Housie Night', 'game_date' => '2026-11-22']]);
$st = json_decode(http('GET', '/housie/api/live-status.php')['body'], true);
check($r['code'] === 302 && $st['game']['name'] === 'HTTP Housie Night' && $st['game']['status'] === 'SETUP', 'Create game via dashboard form');
$gid = $st['game']['id'];
http('POST', '/housie/tickets.php', ['post' => ['csrf_token' => $token, 'action' => 'generate', 'count' => '30']]);
$cnt = (int) db()->query("SELECT COUNT(*) FROM housie_tickets WHERE game_id = $gid")->fetchColumn();
check($cnt === 30, 'Generate 30 tickets via Ticket Management');
http('POST', '/housie/tickets.php', ['post' => ['csrf_token' => $token, 'action' => 'participant', 'ticket_no' => '1', 'participant_name' => '<script>alert(1)</script> Amit', 'participant_phone' => '']]);
http('POST', '/housie/tickets.php', ['post' => ['csrf_token' => $token, 'action' => 'participant', 'ticket_no' => '2', 'participant_name' => 'Sneha Roy', 'participant_phone' => '9830012345']]);
$r = http('GET', '/housie/tickets.php?q=amit');
check(strpos($r['body'], '<script>alert(1)</script>') === false && strpos($r['body'], '&lt;script&gt;') !== false, 'Participant names are HTML-escaped (XSS safe)');
$r = http('GET', '/housie/tickets.php?q=0002');
check(strpos($r['body'], 'Sneha Roy') !== false, 'Ticket search by Ticket ID shows the participant');

// --- print / PDF -----------------------------------------------------------
$r = http('GET', '/housie/print-tickets.php?from=1&to=12');
check($r['code'] === 200 && substr_count($r['body'], 'class="ticket"') === 12 && strpos($r['body'], 'Ticket 0012') !== false, 'Print view renders 12 tickets (multiple together)');
$r = http('GET', '/housie/print-tickets.php?ticket=2');
check(substr_count($r['body'], 'class="ticket"') === 1 && strpos($r['body'], 'Sneha Roy') !== false, 'Print a single ticket with participant name');
$t = microtime(true);
$r = http('GET', '/housie/print-tickets.php?format=pdf&from=1&to=30');
$pdfOk = strpos($r['headers'], 'application/pdf') !== false && strpos($r['body'], '%PDF') === 0;
check($pdfOk, sprintf('Tickets PDF downloads (30 tickets, %.1fs)', microtime(true) - $t));
if ($pdfOk && $pdftotext !== '') {
    $p = sys_get_temp_dir() . '/housie-tickets-test.pdf';
    file_put_contents($p, $r['body']);
    shell_exec('"' . strtok($pdftotext, "\r\n") . '" -enc UTF-8 -layout "' . $p . '" "' . $p . '.txt"');
    $txt = (string) @file_get_contents($p . '.txt');
    $grid = housie_grid_decode(db()->query("SELECT grid FROM housie_tickets WHERE game_id = $gid AND ticket_no = 30")->fetchColumn());
    check(strpos($txt, 'Ticket 0001') !== false && strpos($txt, 'Ticket 0030') !== false && preg_match('/Page 1 of 3/', $txt), 'Tickets PDF: Ticket IDs present, 10 per page (3 pages)');
}

// --- prizes ------------------------------------------------------------------
$prizeRows = [];
foreach (housie_prizes($gid) as $p) {
    $prizeRows['p[' . $p['category'] . '][enabled]'] = (int) $p['enabled'] ? '1' : '';
    $prizeRows['p[' . $p['category'] . '][prize_name]'] = $p['category'] === 'EARLY_FIVE' ? 'Sweet Box' : $p['prize_name'];
    $prizeRows['p[' . $p['category'] . '][description]'] = (string) $p['description'];
    $prizeRows['p[' . $p['category'] . '][winners_allowed]'] = $p['category'] === 'EARLY_FIVE' ? '2' : (string) $p['winners_allowed'];
    $prizeRows['p[' . $p['category'] . '][sort_order]'] = (string) $p['sort_order'];
}
$prizeRows = array_filter($prizeRows, function ($v) { return $v !== ''; });
http('POST', '/housie/prizes.php', ['post' => $prizeRows + ['csrf_token' => $token]]);
$e5 = array_values(array_filter(housie_prizes($gid), function ($p) { return $p['category'] === 'EARLY_FIVE'; }))[0];
check($e5['prize_name'] === 'Sweet Box' && (int) $e5['winners_allowed'] === 2, 'Prize configuration saved via the form');

// --- live draw -----------------------------------------------------------------
$r = http('POST', '/housie/api/call.php', ['post' => ['csrf_token' => 'bad'], 'headers' => ['X-CSRF-Token: bad']]);
check($r['code'] === 419, 'Housie API rejects a bad CSRF token');
$j = $hz('call.php');
check($j['_code'] === 409 && strpos($j['error'], 'START') !== false, 'CALL before START refused');
$j = $hz('start.php');
check($j['ok'] && $j['status']['game']['status'] === 'RUNNING', 'START HOUSIE GAME via API');
$seen = [];
$problem = '';
for ($i = 0; $i < 20; $i++) {
    $j = $hz('call.php', ['expected_count' => (string) $i]);
    if (!$j['ok']) { $problem = json_encode($j); break; }
    $seen[] = $j['result']['number'];
}
check(count($seen) === 20 && count(array_unique($seen)) === 20, '20 numbers called via API, no repeats', $problem);
$j = $hz('call.php', ['expected_count' => '5']);
check($j['_code'] === 409 && !$j['ok'], 'Stale CALL (double click from an old screen) refused');
$led = json_decode(http_anon('GET', '/housie/api/live-status.php')['body'], true);
check($led['calls_count'] === 20 && $led['current_number'] === $seen[19] && $led['called'] === $seen && $led['previous'] === array_slice(array_reverse($seen), 1, 5), 'LED status: current number, called list, previous five');
check(!isset($led['number_sequence']) && strpos(json_encode($led), 'sequence') === false, 'LED status never contains the stored sequence');
$r = http_anon('GET', '/housie/live-display.php');
check(strpos($r['body'], '"calls_count":20') !== false, 'LED page embeds the current state (refresh recovery)');
$un = json_decode(http_anon('GET', '/housie/api/live-status.php?since=' . urlencode($led['token']))['body'], true);
check($un === ['ok' => true, 'changed' => false, 'token' => $led['token']], 'LED polling returns "unchanged" when nothing happened');
$j = $hz('pause.php');
$j2 = $hz('call.php');
check($j['ok'] && $j2['_code'] === 409, 'PAUSE via API; CALL refused while paused');
$j = $hz('resume.php');
check($j['ok'] && $j['status']['game']['status'] === 'RUNNING', 'RESUME via API');

// --- claims ----------------------------------------------------------------
$calledSet = array_fill_keys($seen, true);
$valid = null;
$invalid = null;
foreach (db()->query("SELECT ticket_no, grid FROM housie_tickets WHERE game_id = $gid ORDER BY ticket_no")->fetchAll() as $row) {
    $okE5 = housie_evaluate(housie_grid_decode($row['grid']), $calledSet, 'EARLY_FIVE')['valid'];
    if ($okE5 && $valid === null) { $valid = (int) $row['ticket_no']; }
    if (!$okE5 && $invalid === null) { $invalid = (int) $row['ticket_no']; }
}
while ($valid === null) {     // call more numbers until some ticket has five
    $j = $hz('call.php');
    $calledSet[$j['result']['number']] = true;
    foreach (db()->query("SELECT ticket_no, grid FROM housie_tickets WHERE game_id = $gid ORDER BY ticket_no")->fetchAll() as $row) {
        if (housie_evaluate(housie_grid_decode($row['grid']), $calledSet, 'EARLY_FIVE')['valid']) { $valid = (int) $row['ticket_no']; break; }
    }
}
$code = housie_ticket_code($valid);
$j = $hz('claim-check.php', ['prize_id' => (string) $e5['id'], 'ticket' => $code]);
check($j['ok'] && $j['result']['can_confirm'] === true && $j['result']['ticket']['code'] === $code, "Claim check via API: Early Five on ticket $code is valid");
if ($invalid !== null) {
    $j = $hz('claim-check.php', ['prize_id' => (string) $e5['id'], 'ticket' => housie_ticket_code($invalid)]);
    check($j['ok'] && $j['result']['valid'] === false && $j['result']['can_confirm'] === false, 'Claim check via API: a ticket without five called numbers is invalid');
    $j = $hz('claim-confirm.php', ['game_id' => (string) $gid, 'prize_id' => (string) $e5['id'], 'ticket' => housie_ticket_code($invalid)]);
    check($j['_code'] === 409 && !$j['ok'], 'Confirming an invalid claim is refused by the server');
    $j = $hz('claim-reject.php', ['game_id' => (string) $gid, 'prize_id' => (string) $e5['id'], 'ticket' => housie_ticket_code($invalid), 'note' => 'Bogey']);
    check($j['ok'] && $j['result']['reason'] === 'Bogey', 'Reject claim via API (recorded with note)');
}
$j = $hz('claim-check.php', ['prize_id' => (string) $e5['id'], 'ticket' => '9999']);
check($j['ok'] && $j['result']['ticket'] === null && !$j['result']['can_confirm'], 'Unknown Ticket ID is reported, not confirmable');
$j = $hz('claim-confirm.php', ['game_id' => (string) $gid, 'prize_id' => (string) $e5['id'], 'ticket' => $code]);
check($j['ok'] && $j['result']['rank'] === 1 && $j['result']['completed'] === false, 'Confirm winner via API (1 of 2)');
$j = $hz('claim-confirm.php', ['game_id' => (string) $gid, 'prize_id' => (string) $e5['id'], 'ticket' => $code]);
check($j['_code'] === 409 && !$j['ok'], 'Duplicate confirmation via API refused');
$led = json_decode(http_anon('GET', '/housie/api/live-status.php')['body'], true);
check($led['latest_winner']['ticket'] === $code && $led['latest_winner']['category'] === 'EARLY_FIVE' && $led['latest_winner']['prize_name'] === 'Sweet Box', 'Winner appears in the LED status');
$r = http('GET', '/housie/claims.php');
check(strpos($r['body'], 'Confirmed #1') !== false && strpos($r['body'], 'Rejected') !== false, 'Claims log shows confirmed and rejected claims');

// --- reports -------------------------------------------------------------------
$r = http('GET', '/housie/reports.php');
check($r['code'] === 200 && strpos($r['body'], $code) !== false && strpos($r['body'], 'Sweet Box') !== false, 'Housie report page lists the winner');
$r = http('GET', '/housie/reports.php?export=csv&type=winners');
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
check(strpos($r['headers'], 'text/csv') !== false && strpos($lines[0], 'Category') !== false && count($lines) === 2 && strpos($lines[1], $code) !== false, 'Winners CSV export');
$r = http('GET', '/housie/reports.php?export=csv&type=history');
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
check(count($lines) === count($calledSet) + 1 && strpos($lines[0], 'Sequence') !== false, 'Number calling history CSV export (' . count($calledSet) . ' rows)');
$r = http('GET', '/housie/reports.php?export=csv&type=tickets');
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
check(count($lines) === 31, 'Tickets CSV export (30 tickets)');
$r = http('GET', '/housie/reports.php?export=pdf');
$pdfOk = strpos($r['headers'], 'application/pdf') !== false && strpos($r['body'], '%PDF') === 0;
check($pdfOk, 'Housie winners PDF downloads');
if ($pdfOk && $pdftotext !== '') {
    $p = sys_get_temp_dir() . '/housie-report-test.pdf';
    file_put_contents($p, $r['body']);
    shell_exec('"' . strtok($pdftotext, "\r\n") . '" -enc UTF-8 -layout "' . $p . '" "' . $p . '.txt"');
    $txt = (string) @file_get_contents($p . '.txt');
    check(strpos($txt, 'WINNERS REPORT') !== false && strpos($txt, $code) !== false && strpos($txt, 'Sweet Box') !== false
        && stripos($txt, 'Number calling history') !== false && stripos($txt, 'Tickets generated') !== false, 'Housie PDF: winners, ticket report and calling history');
    check(strpos($txt, 'FINAL WINNERS LIST') === false, 'Housie PDF is separate from the lottery winners list');
}
// --- manual winner entry (physical tickets) -----------------------------------
$topId = array_values(array_filter(housie_prizes($gid), function ($p) { return $p['category'] === 'TOP_LINE'; }))[0]['id'];
$j = $hz('claim-manual.php', ['game_id' => (string) $gid, 'prize_id' => (string) $topId, 'ticket' => 'P-55', 'name' => '<b>Room</b> Winner', 'note' => 'checked']);
check($j['ok'] && $j['result']['ticket'] === 'P-55' && $j['status']['latest_winner']['ticket'] === 'P-55', 'Manual winner entry via API shows on the LED status');
$j = $hz('claim-manual.php', ['game_id' => (string) $gid, 'prize_id' => (string) $topId, 'ticket' => 'p-55', 'name' => 'Dup']);
check($j['_code'] === 409 && !$j['ok'], 'Duplicate manual entry via API refused');
$r = http('GET', '/housie/claims.php');
check(strpos($r['body'], 'id="manual-form"') !== false && strpos($r['body'], 'P-55') !== false && strpos($r['body'], '<b>Room</b>') === false
    && strpos($r['body'], '&lt;b&gt;Room&lt;/b&gt;') !== false, 'Claims page: manual form, manual winner in the log, name escaped');
$r = http('GET', '/housie/reports.php?export=csv&type=winners');
check(strpos($r['body'], 'P-55') !== false, 'Manual winner included in the winners CSV');

$j = $hz('end.php');
check($j['ok'] && $j['status']['game']['status'] === 'ENDED', 'END GAME via API');

// --- lottery still fine ---------------------------------------------------------
$r = http_anon('GET', '/live-display.php');
check($r['code'] === 200 && strpos($r['body'], 'DIWALI LOTTERY DRAW') !== false && strpos($r['body'], 'housie') === false, 'Lottery LED display unchanged and independent');
$j = json_decode(http_anon('GET', '/api/live-status.php')['body'], true);
check($j['ok'] === true && isset($j['stats']['prizes_total']) && !isset($j['calls_count']), 'Lottery live-status API unchanged');
