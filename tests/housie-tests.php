<?php
/**
 * Housie / Tambola module tests (included by run-tests.php after the lottery tests).
 * Runs against the same test database; the lottery tables are checksummed before and
 * after to prove the Housie module never touches them.
 */
if (PHP_SAPI !== 'cli' || !function_exists('check')) {
    exit(1);
}
require_once APP_ROOT . '/includes/housie/housie.php';

function expect_housie_error(callable $fn, string $label, string $contains = ''): void
{
    try {
        $fn();
        check(false, $label, 'no exception thrown');
    } catch (HousieException $e) {
        check($contains === '' || stripos($e->getMessage(), $contains) !== false, $label . ' (' . $e->getMessage() . ')', $e->getMessage());
    }
}

function lottery_fingerprint(): string
{
    $rows = db()->query('CHECKSUM TABLE users, lottery_tickets, prizes, lottery_draws, draw_session, settings')->fetchAll();
    return json_encode($rows);
}

/** Force a deterministic calling order for a started test game (test-only shortcut). */
function housie_test_set_sequence(int $gameId, array $first): void
{
    $rest = array_values(array_diff(range(1, 90), $first));
    db()->prepare('UPDATE housie_games SET number_sequence = ? WHERE id = ?')->execute([implode(',', array_merge($first, $rest)), $gameId]);
}

function housie_prize_id(int $gameId, string $category): int
{
    $st = db()->prepare('SELECT id FROM housie_prizes WHERE game_id = ? AND category = ?');
    $st->execute([$gameId, $category]);
    return (int) $st->fetchColumn();
}

$lotteryBefore = lottery_fingerprint();

/* ====================================================================== */
section('H1. Housie schema (additive migration)');
$tables = db()->query("SHOW TABLES LIKE 'housie\\_%'")->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
check($tables === ['housie_claims', 'housie_draw_numbers', 'housie_game_settings', 'housie_games', 'housie_prizes', 'housie_tickets'], 'All 6 housie_* tables created', implode(',', $tables));
check(housie_installed(), 'Module reports installed');
check(housie_active_game() === null && housie_live_status()['game'] === null, 'No game yet: live status is empty, not an error');

/* ====================================================================== */
section('H2. Ticket generation & validation');
$t0h = microtime(true);
$bad = [];
$cornersOk = true;
for ($i = 0; $i < 3000; $i++) {
    $g = housie_generate_grid();
    // independent re-implementation of the rules (not reusing housie_validate_grid)
    $nums = array_values(array_filter($g));
    $rowsOk = true;
    for ($r = 0; $r < 3; $r++) {
        $rowsOk = $rowsOk && count(array_filter(array_slice($g, $r * 9, 9))) === 5;
    }
    $colsOk = true;
    for ($c = 0; $c < 9; $c++) {
        $lo = $c === 0 ? 1 : $c * 10;
        $hi = $c === 8 ? 90 : $c * 10 + 9;
        $col = array_values(array_filter([$g[$c], $g[9 + $c], $g[18 + $c]]));
        $sorted = $col;
        sort($sorted);
        $colsOk = $colsOk && count($col) >= 1 && count($col) <= 3 && $col === $sorted
            && count(array_filter($col, function ($n) use ($lo, $hi) { return $n < $lo || $n > $hi; })) === 0;
    }
    if (count($g) !== 27 || count($nums) !== 15 || count(array_unique($nums)) !== 15 || !$rowsOk || !$colsOk || housie_validate_grid($g) !== null) {
        $bad[] = implode(',', $g);
    }
    $cornersOk = $cornersOk && $g[0] && $g[8] && $g[18] && $g[26];
}
check(!$bad, '3000 generated grids: 3x9, exactly 15 numbers, 5 per row, column ranges, ascending columns, unique', $bad ? $bad[0] : '');
check($cornersOk, 'Every generated ticket has numbers in all four corners');
printf("    (%.2fs)\n", microtime(true) - $t0h);

// validator rejects broken tickets
$good = housie_generate_grid();
$broken = $good; $i0 = array_search(0, $broken, true); $broken[$i0] = 0; // no-op guard
$sixInRow = $good;
for ($c = 0; $c < 9; $c++) { if ($sixInRow[$c] === 0) { list($lo) = housie_column_range($c); $sixInRow[$c] = $lo; break; } }
check(housie_validate_grid($sixInRow) !== null, 'Validator rejects a row with 6 numbers');
$wrongCol = $good;
for ($c = 1; $c < 9; $c++) { if ($wrongCol[$c] > 0) { $wrongCol[$c] = 5; break; } }
check(housie_validate_grid($wrongCol) !== null, 'Validator rejects a number in the wrong column range');
$dup = $good;
$firstNums = array_keys(array_filter($dup));
$dup[$firstNums[1]] = $dup[$firstNums[0]];
check(housie_validate_grid($dup) !== null, 'Validator rejects duplicate numbers');
check(housie_validate_grid(array_slice($good, 0, 26)) !== null, 'Validator rejects a grid that is not 3x9');

$game1 = housie_create_game(1, 'Test Housie Game 1', '2026-11-22');
$g1 = (int) $game1['id'];
check($game1['status'] === 'SETUP' && (int) housie_setting('active_game_id') === $g1, 'Create game -> SETUP and active');
check(count(housie_prizes($g1)) === 8 && count(housie_prizes($g1, true)) === 6, '8 prize categories seeded (6 enabled, 2nd/3rd Full House off by default)');
expect_housie_error(function () { housie_generate_tickets(1, $GLOBALS['g1'], 0); }, 'Generating 0 tickets rejected');
expect_housie_error(function () { housie_generate_tickets(1, $GLOBALS['g1'], 5000); }, 'Generating more than the batch limit rejected');
$r1 = housie_generate_tickets(1, $g1, 1000);
$r2 = housie_generate_tickets(1, $g1, 200);
check($r1['from'] === 1 && $r1['to'] === 1000 && $r2['from'] === 1001 && $r2['to'] === 1200, 'Bulk generation: 1000 + 200 tickets, sequential Ticket IDs 1..1200');
$cnt = db()->query("SELECT COUNT(*), COUNT(DISTINCT ticket_no), COUNT(DISTINCT numbers_key) FROM housie_tickets WHERE game_id = $g1")->fetch(PDO::FETCH_NUM);
check($cnt === ['1200', '1200', '1200'] || $cnt === [1200, 1200, 1200], 'All 1200 Ticket IDs and number combinations are unique', json_encode($cnt));
$invalidStored = 0;
foreach (db()->query("SELECT grid FROM housie_tickets WHERE game_id = $g1")->fetchAll(PDO::FETCH_COLUMN) as $grid) {
    $invalidStored += housie_validate_grid(housie_grid_decode($grid)) === null ? 0 : 1;
}
check($invalidStored === 0, 'Every stored ticket passes standard Tambola validation');
check(housie_ticket_code(7) === '0007' && housie_parse_ticket_ref('T-0042') === 42 && housie_parse_ticket_ref('#42') === 42 && housie_parse_ticket_ref('abc') === null, 'Ticket ID format and parsing');

// participants
housie_update_participant(1, $g1, 1, 'Rahul Sharma', '98300 12345');
housie_update_participant(1, $g1, 2, 'Priya <b>Das</b>', '');
check(housie_ticket_by_no($g1, 1)['participant_name'] === 'Rahul Sharma', 'Assign participant name to a ticket');
$page = housie_tickets_page($g1, 'rahul', 1, 30);
check($page['total'] === 1 && (int) $page['rows'][0]['ticket_no'] === 1, 'Search by participant name');
$page = housie_tickets_page($g1, '0002', 1, 30);
check($page['total'] >= 1 && (int) $page['rows'][0]['ticket_no'] === 2, 'Search by Ticket ID');
expect_housie_error(function () { housie_update_participant(1, $GLOBALS['g1'], 1, 'X', 'not-a-phone!'); }, 'Invalid phone rejected');
expect_housie_error(function () { housie_update_participant(1, $GLOBALS['g1'], 99999, 'Nobody', ''); }, 'Unknown ticket rejected');

/* ====================================================================== */
section('H3. Prize configuration');
$rows = [];
foreach (housie_prizes($g1) as $p) {
    $rows[$p['category']] = ['enabled' => true, 'prize_name' => $p['label'] . ' Prize', 'description' => 'desc', 'winners_allowed' => 1, 'sort_order' => $p['sort_order']];
}
$rows['TOP_LINE']['winners_allowed'] = 2;          // multiple winners
$rows['FULL_HOUSE']['prize_name'] = 'Grand Hamper';
$rows['THIRD_FULL_HOUSE']['enabled'] = false;
housie_save_prizes(1, $g1, $rows);
$byCat = [];
foreach (housie_prizes($g1) as $p) { $byCat[$p['category']] = $p; }
check((int) $byCat['TOP_LINE']['winners_allowed'] === 2 && $byCat['FULL_HOUSE']['prize_name'] === 'Grand Hamper'
    && (int) $byCat['SECOND_FULL_HOUSE']['enabled'] === 1 && (int) $byCat['THIRD_FULL_HOUSE']['enabled'] === 0, 'Enable/disable, names, winners allowed saved');
check($byCat['EARLY_FIVE']['state'] === 'PENDING', 'Prize status starts as Pending');
$none = $rows;
foreach ($none as &$x) { $x['enabled'] = false; } unset($x);
expect_housie_error(function () use ($none) { housie_save_prizes(1, $GLOBALS['g1'], $none); }, 'Disabling every category rejected');
$badW = $rows; $badW['EARLY_FIVE']['winners_allowed'] = 0;
expect_housie_error(function () use ($badW) { housie_save_prizes(1, $GLOBALS['g1'], $badW); }, 'Winners allowed of 0 rejected');

/* ====================================================================== */
section('H4. Number draw engine');
expect_housie_error(function () { housie_call_next(1); }, 'CALL before START rejected', 'START');
housie_start_game(1);
$game = housie_active_game();
$seq = array_map('intval', explode(',', $game['number_sequence']));
$sortedSeq = $seq; sort($sortedSeq);
check($game['status'] === 'RUNNING' && $sortedSeq === range(1, 90), 'START stores a full permutation of 1..90');
expect_housie_error(function () { housie_start_game(1); }, 'Second START rejected');
expect_housie_error(function () { housie_generate_tickets(1, $GLOBALS['g1'], 5); }, 'Generating tickets after START rejected');
expect_housie_error(function () { housie_update_participant(1, $GLOBALS['g1'], 3, 'Late', ''); }, 'Editing participants after START rejected');
expect_housie_error(function () { housie_create_game(1, 'Another', null); }, 'Creating a new game while one is running rejected');

// fairness of the shuffle: first position over 9000 shuffles (chi-square, df=89, p=0.001 critical ~ 129)
$counts = array_fill(1, 90, 0);
for ($i = 0; $i < 9000; $i++) { $counts[housie_secure_shuffle()[0]]++; }
$chi = 0.0;
foreach ($counts as $c) { $chi += ($c - 100) ** 2 / 100; }
check($chi < 129.0, sprintf('Shuffle is uniform: chi-square %.1f < 129 (9000 shuffles, df=89)', $chi));
$positions = 0;
for ($i = 0; $i < 200; $i++) { $positions += housie_secure_shuffle() === range(1, 90) ? 1 : 0; }
check($positions === 0, 'Shuffles are not the identity order');

// deterministic order for the claim tests: ticket 5's top row first, then the rest of ticket 5
$t5 = housie_grid_decode(housie_ticket_by_no($g1, 5)['grid']);
$top5 = array_values(array_filter(array_slice($t5, 0, 9)));
$all5 = housie_grid_numbers($t5);
housie_test_set_sequence($g1, array_merge($top5, array_values(array_diff($all5, $top5))));

$called = [];
for ($i = 0; $i < 4; $i++) { $called[] = housie_call_next(1)['number']; }
check($called === array_slice($top5, 0, 4), 'CALL NEXT follows the stored server sequence');
$r = housie_call_next(1, 4);
check($r['seq'] === 5 && $r['number'] === $top5[4], 'CALL with the right expected_count works');
expect_housie_error(function () { housie_call_next(1, 4); }, 'Stale expected_count (double click / second screen) rejected', 'another screen');

// pause / resume
housie_pause_game(1);
expect_housie_error(function () { housie_call_next(1); }, 'CALL while PAUSED rejected', 'paused');
expect_housie_error(function () { housie_pause_game(1); }, 'PAUSE twice rejected');
housie_resume_game(1);
check(housie_active_game()['status'] === 'RUNNING', 'RESUME returns to RUNNING');

// refresh / restart recovery: state comes only from the database
$s1 = housie_live_status();
$s2 = housie_live_status();
check($s1 == $s2 && $s1['calls_count'] === 5 && $s1['current_number'] === $top5[4] && $s1['called'] === $top5, 'Status after "refresh" is identical (current number, called list)');
check($s1['previous'] === array_slice(array_reverse($top5), 1, 5), 'Previous numbers list (newest first, excluding current)');
check(housie_live_status($s1['token']) === ['ok' => true, 'changed' => false, 'token' => $s1['token']], 'Polling with ?since=token returns "unchanged"');
check(strpos(json_encode($s1), (string) $game['number_sequence']) === false && !isset($s1['number_sequence']), 'Uncalled sequence is never exposed in the public status');

/* ====================================================================== */
section('H5. Claim validation rules');
$tk = function (array $grid) { return $grid; };
$grid = [1, 0, 21, 0, 41, 0, 61, 0, 81,  0, 12, 0, 32, 45, 52, 0, 72, 0,  3, 0, 23, 33, 0, 53, 0, 0, 83];
// (custom grid for rule checks; corners = 1, 81, 3, 83)
check(housie_validate_grid($grid) === null, 'Rule-test ticket is valid', (string) housie_validate_grid($grid));
$cs = function (array $nums) { return array_fill_keys($nums, true); };
$ev = function ($cat, $nums) use ($grid, $cs) { return housie_evaluate($grid, $cs($nums), $cat)['valid']; };
check($ev('EARLY_FIVE', [1, 21, 12, 3, 83]) && !$ev('EARLY_FIVE', [1, 21, 12, 3, 2, 4, 5]), 'Early Five: any 5 called numbers; 4 is not enough');
check($ev('TOP_LINE', [1, 21, 41, 61, 81]) && !$ev('TOP_LINE', [1, 21, 41, 61, 12, 32]), 'Top Line: all 5 top-row numbers');
check($ev('MIDDLE_LINE', [12, 32, 45, 52, 72]) && !$ev('MIDDLE_LINE', [12, 32, 52, 72, 1]), 'Middle Line: all 5 middle-row numbers');
check($ev('BOTTOM_LINE', [3, 23, 33, 53, 83]) && !$ev('BOTTOM_LINE', [3, 23, 33, 53, 81]), 'Bottom Line: all 5 bottom-row numbers');
check($ev('FOUR_CORNERS', [1, 81, 3, 83]) && !$ev('FOUR_CORNERS', [1, 81, 3, 23, 33]), 'Four Corners: the 4 corner numbers');
$blankCorner = $grid; $blankCorner[0] = 0; $blankCorner[2] = 0; // not a valid ticket, only for the rule
check(!housie_evaluate($blankCorner, $cs([81, 3, 83, 21]), 'FOUR_CORNERS')['valid'], 'Four Corners: blank corner cell can never win');
$all = array_values(array_filter($grid));
check($ev('FULL_HOUSE', $all) && !$ev('FULL_HOUSE', array_slice($all, 1)), 'Full House: all 15 numbers; 14 is not enough');
check($ev('SECOND_FULL_HOUSE', $all) && $ev('THIRD_FULL_HOUSE', $all), '2nd/3rd Full House use the full-house pattern');
$e = housie_evaluate($grid, $cs([1, 21, 41]), 'TOP_LINE');
check($e['missing'] === [61, 81] && strpos($e['reason'], '61, 81') !== false, 'Reason lists the numbers not yet called');

/* ====================================================================== */
section('H6. Claims: verify, confirm, multiple winners, duplicates');
$pTop = housie_prize_id($g1, 'TOP_LINE');
$pE5 = housie_prize_id($g1, 'EARLY_FIVE');
$pFH = housie_prize_id($g1, 'FULL_HOUSE');
$p2FH = housie_prize_id($g1, 'SECOND_FULL_HOUSE');
$p3FH = housie_prize_id($g1, 'THIRD_FULL_HOUSE');
$pMid = housie_prize_id($g1, 'MIDDLE_LINE');

$c = housie_check_claim($g1, $pTop, '0005');
check($c['valid'] && $c['can_confirm'] && $c['ticket']['code'] === '0005', 'Ticket 0005 Top Line claim is valid after its 5 top numbers are called');
$c = housie_check_claim($g1, $pMid, '5');
check(!$c['valid'] && !$c['can_confirm'], 'Middle Line claim on the same ticket is invalid (numbers not called yet)');
expect_housie_error(function () use ($pMid) { housie_confirm_claim(1, $GLOBALS['g1'], $pMid, 5); }, 'Invalid claim cannot be confirmed', 'Invalid claim');
check(db()->query("SELECT COUNT(*) FROM housie_claims WHERE game_id = $g1")->fetchColumn() == 0, 'Checking a claim saves nothing');

$w = housie_confirm_claim(1, $g1, $pTop, 5);
check($w['rank'] === 1 && $w['ticket'] === '0005' && $w['completed'] === false, 'Top Line winner #1 confirmed (1 of 2 allowed)');
$st = housie_live_status();
$topPrize = array_values(array_filter($st['prizes'], function ($p) { return $p['category'] === 'TOP_LINE'; }))[0];
check($topPrize['state'] === 'CLAIMED' && $topPrize['winners'][0]['ticket'] === '0005' && $st['latest_winner']['ticket'] === '0005', 'LED status shows the winner; category status Claimed');
$c = housie_check_claim($g1, $pTop, '0005');
check(!$c['can_confirm'] && strpos(implode(' ', $c['problems']), 'already been confirmed') !== false, 'Duplicate claim (same ticket, same category) blocked');
expect_housie_error(function () use ($pTop) { housie_confirm_claim(1, $GLOBALS['g1'], $pTop, 5); }, 'Duplicate confirmation rejected');

// Early Five for ticket 5 is valid too (5 numbers called) - same ticket may win different categories
$w = housie_confirm_claim(1, $g1, $pE5, 5);
check($w['completed'] === true, 'Same ticket can win a different category (Early Five), which is then Completed');
$c = housie_check_claim($g1, $pE5, '0006');
check(!$c['can_confirm'] && strpos(implode(' ', $c['problems']), 'completed') !== false, 'Completed category accepts no more winners');

// second Top Line winner: find another ticket whose top row is fully called after a few more numbers
$rest5 = array_values(array_diff($all5, $top5));
for ($i = 0; $i < 10; $i++) { housie_call_next(1); }   // calls the rest of ticket 5 (10 numbers)
$st = housie_live_status();
check($st['calls_count'] === 15, '15 numbers called (full ticket 5)');
$c = housie_check_claim($g1, $pFH, '0005');
check($c['can_confirm'], 'Full House on ticket 5 is valid once all 15 numbers are called');
$c2 = housie_check_claim($g1, $p2FH, '0005');
check(!$c2['can_confirm'] && strpos(implode(' ', $c2['problems']), 'must be completed') !== false, 'Second Full House blocked until Full House is completed');
housie_confirm_claim(1, $g1, $pFH, 5);
$c2 = housie_check_claim($g1, $p2FH, '0005');
check(!$c2['can_confirm'] && strpos(implode(' ', $c2['problems']), 'only one Full House') !== false, 'A ticket cannot win a second Full House tier');

// a different ticket for a second Top Line winner: call numbers until some other ticket completes its top row
$second = null;
for ($k = 0; $k < 75 && $second === null; $k++) {
    $calledNow = array_fill_keys(housie_live_status()['called'], true);
    foreach (db()->query("SELECT ticket_no, grid FROM housie_tickets WHERE game_id = $g1 AND ticket_no <> 5 ORDER BY ticket_no")->fetchAll() as $row) {
        if (housie_evaluate(housie_grid_decode($row['grid']), $calledNow, 'TOP_LINE')['valid']) {
            $second = (int) $row['ticket_no'];
            break;
        }
    }
    if ($second === null) { housie_call_next(1); }
}
check($second !== null, 'Another ticket completes its Top Line as more numbers are called (ticket ' . ($second ? housie_ticket_code($second) : '-') . ')');
$w2 = housie_confirm_claim(1, $g1, $pTop, (int) $second);
check($w2['rank'] === 2 && $w2['completed'] === true, 'Multiple winners: Top Line winner #2 confirmed, category now Completed');
$third = null;
$calledNow = array_fill_keys(housie_live_status()['called'], true);
foreach (db()->query("SELECT ticket_no, grid FROM housie_tickets WHERE game_id = $g1 AND ticket_no NOT IN (5, $second)")->fetchAll() as $row) {
    if (housie_evaluate(housie_grid_decode($row['grid']), $calledNow, 'TOP_LINE')['valid']) { $third = (int) $row['ticket_no']; break; }
}
if ($third) {
    expect_housie_error(function () use ($pTop, $third) { housie_confirm_claim(1, $GLOBALS['g1'], $pTop, $third); }, 'Winner limit enforced (3rd Top Line refused)', 'completed');
} else {
    $c = housie_check_claim($g1, $pTop, '0001');
    check(!$c['can_confirm'], 'Winner limit enforced (Top Line completed)');
}
$byCat = [];
foreach (housie_prizes($g1) as $p) { $byCat[$p['category']] = $p; }
$lower = $rows; $lower['TOP_LINE']['winners_allowed'] = 1;
expect_housie_error(function () use ($lower) { housie_save_prizes(1, $GLOBALS['g1'], $lower); }, 'Winners allowed cannot go below confirmed winners');
$dis = $rows; $dis['EARLY_FIVE']['enabled'] = false;
expect_housie_error(function () use ($dis) { housie_save_prizes(1, $GLOBALS['g1'], $dis); }, 'Category with confirmed winners cannot be disabled');
$c = housie_check_claim($g1, $p3FH, '0001');
check(in_array('Third Full House is not enabled for this game.', $c['problems'], true), 'Disabled category cannot be claimed');

// rejected claims are recorded
$rj = housie_reject_claim(1, $g1, $pMid, '0002', '');
check(strpos($rj['reason'], 'not complete') !== false || strpos($rj['reason'], 'Middle Line') !== false, 'Rejected claim recorded with the validation reason');
housie_reject_claim(1, $g1, $pMid, '777777', 'Ticket not from this game');
$rej = db()->query("SELECT COUNT(*) FROM housie_claims WHERE game_id = $g1 AND status = 'REJECTED'")->fetchColumn();
check((int) $rej === 2, 'Rejected claims (incl. unknown ticket) kept in the claims audit trail');
check((int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('HOUSIE_CLAIM_CONFIRM','HOUSIE_CLAIM_REJECT')")->fetchColumn() >= 6, 'Confirmations and rejections written to the audit log');

/* ====================================================================== */
section('H7. Simultaneous requests (separate processes)');
$exp = housie_active_game()['calls_count'];
$res = run_parallel(array_fill(0, 6, ['housie-call', (string) $exp]));
$ok = array_values(array_filter($res, function ($r) { return $r['ok']; }));
check(count($ok) === 1, '6 simultaneous CALL NEXT clicks (same screen state) -> exactly 1 number called', json_encode($res));
$before = housie_active_game()['calls_count'];
$res = run_parallel(array_fill(0, 6, ['housie-call', '']));
$nums = array_map(function ($r) { return $r['result']['number']; }, array_filter($res, function ($r) { return $r['ok']; }));
$after = housie_active_game()['calls_count'];
check(count($nums) === 6 && count(array_unique($nums)) === 6 && $after === $before + 6, '6 simultaneous CALLs without a guard -> 6 different numbers, serialized');
// concurrent confirmations on a fresh 1-winner category
$calledNow = array_fill_keys(housie_live_status()['called'], true);
$mlTickets = [];
foreach (db()->query("SELECT ticket_no, grid FROM housie_tickets WHERE game_id = $g1")->fetchAll() as $row) {
    if (housie_evaluate(housie_grid_decode($row['grid']), $calledNow, 'MIDDLE_LINE')['valid']) { $mlTickets[] = (int) $row['ticket_no']; }
    if (count($mlTickets) >= 3) { break; }
}
while (count($mlTickets) < 2) {
    housie_call_next(1);
    $calledNow = array_fill_keys(housie_live_status()['called'], true);
    $mlTickets = [];
    foreach (db()->query("SELECT ticket_no, grid FROM housie_tickets WHERE game_id = $g1")->fetchAll() as $row) {
        if (housie_evaluate(housie_grid_decode($row['grid']), $calledNow, 'MIDDLE_LINE')['valid']) { $mlTickets[] = (int) $row['ticket_no']; }
        if (count($mlTickets) >= 3) { break; }
    }
}
$cmds = [];
foreach ($mlTickets as $t) {
    $cmds[] = ['housie-confirm', (string) $g1, (string) $pMid, (string) $t];
    $cmds[] = ['housie-confirm', (string) $g1, (string) $pMid, (string) $t];
}
$res = run_parallel($cmds);
$okc = count(array_filter($res, function ($r) { return $r['ok']; }));
$saved = (int) db()->query("SELECT COUNT(*) FROM housie_claims WHERE prize_id = $pMid AND status = 'CONFIRMED'")->fetchColumn();
check($okc === 1 && $saved === 1, count($cmds) . ' simultaneous confirmations for a 1-winner category -> exactly 1 winner', json_encode($res));

/* ====================================================================== */
section('H8. End game, game isolation, lottery untouched');
// call everything that is left, then the 91st is refused
while (housie_active_game()['calls_count'] < 90) { housie_call_next(1); }
$dbNums = db()->query("SELECT COUNT(*), COUNT(DISTINCT number), MIN(number), MAX(number) FROM housie_draw_numbers WHERE game_id = $g1")->fetch(PDO::FETCH_NUM);
check(array_map('intval', $dbNums) === [90, 90, 1, 90], 'All 90 numbers called exactly once (1..90, no repeats)');
expect_housie_error(function () { housie_call_next(1); }, '91st call rejected', 'All 90');
housie_end_game(1);
expect_housie_error(function () { housie_call_next(1); }, 'CALL after END rejected');
$c = housie_check_claim($g1, $pMid, '0001');
check(!$c['can_confirm'] && strpos(implode(' ', $c['problems']), 'ended') !== false, 'No claims can be confirmed after END');

$game2 = housie_create_game(1, 'Test Housie Game 2', null);
$g2 = (int) $game2['id'];
$r = housie_generate_tickets(1, $g2, 10);
check($g2 !== $g1 && $r['from'] === 1, 'Game 2 is separate: its Ticket IDs start again at 1');
housie_start_game(1);
housie_call_next(1);
$st = housie_live_status();
check($st['game']['id'] === $g2 && $st['calls_count'] === 1 && count($st['called']) === 1 && !$st['latest_winner'], 'Game 2 has its own numbers and no winners from game 1');
expect_housie_error(function () use ($pTop) { housie_confirm_claim(1, $GLOBALS['g1'], $pTop, 1); }, 'Claim for game 1 refused while game 2 is in play', 'different game');
$pTop2 = housie_prize_id($g2, 'TOP_LINE');
$c = housie_check_claim($g2, $pTop2, '0500');
check(!$c['ticket'] && strpos(implode(' ', $c['problems']), 'does not exist in this game') !== false, 'Ticket from another game is not found in this game');
expect_housie_error(function () use ($g1) { housie_activate_game(1, $g1); }, 'Cannot switch games while one is in play');
housie_end_game(1);
housie_activate_game(1, $g1);
check((int) housie_active_game()['id'] === $g1 && housie_live_status()['calls_count'] === 90, 'Switching back shows game 1 exactly as it was');
check(lottery_fingerprint() === $lotteryBefore, 'Lottery tables are byte-for-byte unchanged by all Housie operations');

/* ====================================================================== */
section('H10. Physical tickets: number draw only + manual winner entry');
$game3 = housie_create_game(1, 'Physical Ticket Night', null);
$g3 = (int) $game3['id'];
$pE5x = housie_prize_id($g3, 'EARLY_FIVE');
$pFHx = housie_prize_id($g3, 'FULL_HOUSE');
$p2FHx = housie_prize_id($g3, 'SECOND_FULL_HOUSE');
expect_housie_error(function () use ($g3, $pE5x) { housie_record_manual_winner(1, $g3, $pE5x, 'A-101', 'Ravi'); }, 'Manual winner before START rejected', 'Start the game');
housie_start_game(1);
check(housie_active_game()['status'] === 'RUNNING' && housie_stats($g3)['tickets'] === 0, 'Game starts with no system tickets (physical tickets only)');
for ($i = 0; $i < 12; $i++) { housie_call_next(1); }
$m = housie_record_manual_winner(1, $g3, $pE5x, ' a-101 ', 'Ravi Kumar', 'checked by host');
check($m['rank'] === 1 && $m['ticket'] === 'A-101' && $m['participant'] === 'Ravi Kumar' && $m['completed'] === true, 'Manual winner saved (ticket number as printed, name, completes 1-winner category)');
$st = housie_live_status();
$e5 = array_values(array_filter($st['prizes'], function ($p) { return $p['category'] === 'EARLY_FIVE'; }))[0];
check($st['latest_winner']['ticket'] === 'A-101' && $st['latest_winner']['participant'] === 'Ravi Kumar' && $e5['state'] === 'COMPLETED'
    && $e5['winners'][0]['ticket'] === 'A-101', 'Manual winner appears on the LED status and the category shows Completed');
$row = db()->query("SELECT * FROM housie_claims WHERE game_id = $g3")->fetch();
check($row['ticket_id'] === null && $row['status'] === 'CONFIRMED' && strpos($row['reason'], 'Verified manually') === 0 && (int) $row['calls_at_claim'] === 12, 'Stored as a confirmed manual claim with the number of calls at that moment');
expect_housie_error(function () use ($g3, $pE5x) { housie_record_manual_winner(1, $g3, $pE5x, 'B-7', 'Someone'); }, 'Winner limit enforced for manual entries', 'completed');
$pTopx = housie_prize_id($g3, 'TOP_LINE');
housie_record_manual_winner(1, $g3, $pTopx, 'C-9', '');
$rowsTop = $rows; $rowsTop['TOP_LINE']['winners_allowed'] = 3; $rowsTop['SECOND_FULL_HOUSE']['enabled'] = true;
housie_save_prizes(1, $g3, $rowsTop);
expect_housie_error(function () use ($g3, $pTopx) { housie_record_manual_winner(1, $g3, $pTopx, 'c-9', 'Again'); }, 'Same ticket twice in a category rejected (case-insensitive)', 'already a winner');
expect_housie_error(function () use ($g3, $pTopx) { housie_record_manual_winner(1, $g3, $pTopx, '', 'X'); }, 'Empty ticket number rejected');
expect_housie_error(function () use ($g3, $pTopx) { housie_record_manual_winner(1, $g3, $pTopx, '<script>', 'X'); }, 'Ticket number with unsafe characters rejected');
expect_housie_error(function () use ($g3, $p2FHx) { housie_record_manual_winner(1, $g3, $p2FHx, 'D-1', 'X'); }, '2nd Full House before Full House rejected', 'must be completed');
housie_record_manual_winner(1, $g3, $pFHx, 'D-1', 'Full House Winner');
expect_housie_error(function () use ($g3, $p2FHx) { housie_record_manual_winner(1, $g3, $p2FHx, 'd-1', 'X'); }, 'Same ticket cannot win a 2nd Full House', 'only one Full House');
$m2 = housie_record_manual_winner(1, $g3, $p2FHx, 'E-2', 'Second FH');
check($m2['rank'] === 1, 'Second Full House recorded for a different ticket');
$w = [];
foreach (housie_prizes($g3) as $p) { foreach ($p['winners'] as $x) { $w[] = housie_winner_ticket($x) . '|' . $x['participant_name']; } }
check(in_array('A-101|Ravi Kumar', $w, true) && in_array('C-9|', $w, true) && count($w) === 4, 'Report winners list includes manual entries (with and without a name)');
expect_housie_error(function () use ($pTopx) { housie_record_manual_winner(1, $GLOBALS['g1'], $pTopx, 'Z-1', ''); }, 'Manual entry for a different game rejected', 'different game');
housie_end_game(1);
expect_housie_error(function () use ($g3, $pTopx) { housie_record_manual_winner(1, $g3, $pTopx, 'F-3', ''); }, 'Manual entry after END rejected', 'ended');
check(lottery_fingerprint() === $lotteryBefore, 'Lottery tables still unchanged after manual-entry tests');
