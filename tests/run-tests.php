<?php
/**
 * Automated test-suite for the Diwali Lottery system.
 *
 * Uses a SEPARATE database (diwali_lottery_test) which it creates from database.sql,
 * so your real event data is never touched.
 *
 *   php tests/run-tests.php            (core + concurrency tests)
 *   php tests/run-tests.php --http     (also starts PHP's built-in web server and tests pages/APIs)
 *
 * DB credentials come from config/database.php (or LOTTERY_DB_* environment variables).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$testDb = 'diwali_lottery_test';
putenv('LOTTERY_DB_NAME=' . $testDb);
require dirname(__DIR__) . '/includes/bootstrap.php';

$withHttp = in_array('--http', $argv, true);
$passed = 0;
$failed = 0;
$section = '';

function section(string $name): void
{
    global $section;
    $section = $name;
    echo "\n== $name ==\n";
}

function check(bool $cond, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  [PASS] $label\n";
    } else {
        $failed++;
        echo "  [FAIL] $label" . ($detail !== '' ? "  -> $detail" : '') . "\n";
    }
}

function expect_draw_error(callable $fn, string $label): void
{
    try {
        $fn();
        check(false, $label, 'no exception thrown');
    } catch (DrawException $e) {
        check(true, $label . ' (' . $e->getMessage() . ')');
    }
}

/** Recreate the test database from database.sql. */
function recreate_test_db(string $testDb): void
{
    $sql = file_get_contents(APP_ROOT . '/database.sql');
    $sql = str_replace('`diwali_lottery`', '`' . $testDb . '`', $sql);
    $pdo = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `$testDb`");
    $lines = array_filter(explode("\n", $sql), function ($l) {
        return strpos(ltrim($l), '--') !== 0;
    });
    foreach (preg_split('/;\s*\n/', implode("\n", $lines)) as $stmt) {
        if (trim($stmt) !== '') {
            $pdo->exec($stmt);
        }
    }
}

function make_csv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'lt') . '.csv';
    $fh = fopen($path, 'w');
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    fclose($fh);
    return $path;
}

/** Run N worker processes simultaneously; returns decoded results. */
function run_parallel(array $commands): array
{
    $php = PHP_BINARY;
    $startAt = microtime(true) + 1.5;
    $procs = [];
    foreach ($commands as $i => $args) {
        $cmd = '"' . $php . '" "' . __DIR__ . '/worker.php" ' . implode(' ', array_map('escapeshellarg', array_merge([$args[0], (string) $startAt], array_slice($args, 1))));
        $env = getenv();
        $env['LOTTERY_DB_NAME'] = $GLOBALS['testDb'];
        $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env, ['bypass_shell' => true]);
        $procs[$i] = [$procs[$i], $pipes];
    }
    $out = [];
    foreach ($procs as $i => $p) {
        $stdout = stream_get_contents($p[1][1]);
        $stderr = stream_get_contents($p[1][2]);
        fclose($p[1][1]);
        fclose($p[1][2]);
        proc_close($p[0]);
        $json = json_decode(trim($stdout), true);
        $out[$i] = $json ?: ['ok' => false, 'error' => 'bad output: ' . $stdout . $stderr];
    }
    return $out;
}

function sess(): array
{
    return draw_session_row();
}

$t0 = microtime(true);
echo "Diwali Lottery test-suite  (PHP " . PHP_VERSION . ", DB " . $testDb . ")\n";
recreate_test_db($testDb);

/* ====================================================================== */
section('1. Schema & prizes');
$prizeCount = (int) db()->query('SELECT COUNT(*) FROM prizes')->fetchColumn();
check($prizeCount === 50, 'Exactly 50 prizes seeded', "got $prizeCount");
$range = db()->query('SELECT MIN(prize_number), MAX(prize_number), COUNT(DISTINCT prize_number) FROM prizes')->fetch(PDO::FETCH_NUM);
check($range == [1, 50, 50], 'Prize numbers are unique 1..50');
try {
    db()->exec("INSERT INTO prizes (prize_number, prize_name) VALUES (51, 'x')");
    check(false, 'CHECK constraint blocks prize number 51');
} catch (PDOException $e) {
    check(true, 'CHECK constraint blocks prize number 51');
}
check(next_pending_prize()['prize_number'] == 50, 'First prize to draw is No. 50');
$admin = db()->query("SELECT password FROM users WHERE username = 'admin'")->fetchColumn();
check(password_verify('Admin@123', $admin), 'Default admin password hash is valid');

/* ====================================================================== */
section('2. Ticket upload & validation');
$csv = make_csv([
    ['Lottery Number', 'Sold By', 'Sold To'],
    ['1001', 'Amit', 'Rahul'],
    ['1002', 'Suresh', ''],
    ['1003', 'Rajesh', 'Priya'],
]);
$res = validate_ticket_rows(read_ticket_file($csv, 'x.csv'));
check($res['total'] === 3 && count($res['valid']) === 3 && !$res['errors'], 'Example CSV: 3 valid rows, header skipped');
$n = import_ticket_rows($res['valid'], 'x.csv');
check($n === 3, 'Imported 3 tickets');
$t1002 = db()->query("SELECT * FROM lottery_tickets WHERE lottery_number = '1002'")->fetch();
check($t1002 && $t1002['sold_to'] === null && $t1002['sold_by'] === 'Suresh', 'Optional Sold To stored as NULL');

$bad = make_csv([
    ['1003', 'Dup', 'In DB'],          // duplicate of DB
    ['2001', 'Ok', ''],
    ['2001', 'Dup', 'In file'],         // duplicate in file
    ['', 'NoNumber', ''],               // missing number
    ['2002', '', 'NoSeller'],           // missing sold by
    ['bad number!', 'X', ''],           // invalid characters
    ['2003', str_repeat('a', 120), ''], // too long
    ['', '', ''],                       // blank line ignored
]);
$res = validate_ticket_rows(read_ticket_file($bad, 'bad.csv'));
check(count($res['valid']) === 1 && count($res['errors']) === 6, 'Invalid file: 1 valid, 6 errors', json_encode($res['errors']));
$msgs = implode(' | ', array_column($res['errors'], 2));
check(strpos($msgs, 'already exists') !== false, 'Detects duplicate against database');
check(strpos($msgs, 'Duplicate Lottery Number in this file') !== false, 'Detects duplicate within file');
check(strpos($msgs, 'Sold By is required') !== false, 'Detects missing Sold By');

// Semicolon CSV with BOM + leading zeros
$path = tempnam(sys_get_temp_dir(), 'lt') . '.csv';
file_put_contents($path, "\xEF\xBB\xBFLottery Number;Sold By;Sold To\n00077;Neha;\n");
$res = validate_ticket_rows(read_ticket_file($path, 's.csv'));
check(count($res['valid']) === 1 && $res['valid'][0][1] === '00077', 'Semicolon CSV with BOM, leading zeros kept');

// Excel upload
$book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sh = $book->getActiveSheet();
$sh->fromArray([['Lottery Number', 'Sold By', 'Sold To'], [3001, 'Kiran', 'Meena'], [3002, 'Kiran', null]]);
$xlsx = tempnam(sys_get_temp_dir(), 'lt') . '.xlsx';
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($xlsx);
$res = validate_ticket_rows(read_ticket_file($xlsx, 'book.xlsx'));
check(count($res['valid']) === 2 && $res['valid'][0][1] === '3001' && $res['valid'][1][3] === '', 'XLSX upload parsed (numbers as text, empty Sold To)');

// Manual CRUD
check(ticket_create('1001', 'X', '') !== [], 'Manual add rejects duplicate number');
check(ticket_create('4001', '', '') !== [], 'Manual add rejects missing Sold By');
check(ticket_create('4001', 'Vikram', 'Ritu') === [], 'Manual add works');
$t = db()->query("SELECT id FROM lottery_tickets WHERE lottery_number = '4001'")->fetchColumn();
check(ticket_update((int) $t, '4002', 'Vikram', '') === [], 'Edit ticket works');
check(ticket_update((int) $t, '1001', 'Vikram', '') !== [], 'Edit rejects duplicate number');
check(ticket_delete((int) $t) === [], 'Delete ticket works before draw');

// Bulk sample file (120 tickets)
db()->exec('DELETE FROM lottery_tickets');
$res = validate_ticket_rows(read_ticket_file(APP_ROOT . '/sample-tickets.csv', 'sample-tickets.csv'));
$n = import_ticket_rows($res['valid'], 'sample-tickets.csv');
check($n === 120 && !$res['errors'], 'sample-tickets.csv imports 120 tickets', "imported $n");
try {
    db()->exec("INSERT INTO lottery_tickets (lottery_number, sold_by) VALUES ('1001', 'x')");
    check(false, 'UNIQUE index blocks duplicate lottery number at DB level');
} catch (PDOException $e) {
    check(true, 'UNIQUE index blocks duplicate lottery number at DB level');
}

/* ====================================================================== */
section('3. Draw state machine & business rules');
expect_draw_error(function () { draw_reveal(1); }, 'Cannot reveal before START');
expect_draw_error(function () { draw_confirm(1, 1, 1); }, 'Cannot confirm before reveal');
expect_draw_error(function () { draw_cancel(1); }, 'Cannot cancel when idle');
expect_draw_error(function () { draw_reveal_and_confirm(1); }, 'One-click finish rejected before START');

$r = draw_start(1);
check($r['prize_number'] === 50, 'START draws Prize 50 first');
$ls = live_status();
check($ls['state'] === 'ROLLING' && $ls['winner'] === null && count($ls['roll_pool']) > 0, 'While ROLLING, no winner is exposed publicly; roll pool provided');
expect_draw_error(function () { draw_start(1); }, 'Second START rejected while rolling');
check(ticket_create('9999', 'X', '') !== [], 'Ticket changes locked during an active draw');

$r = draw_reveal(1);
$s = sess();
check($s['state'] === 'REVEALED' && $s['lottery_ticket_id'], 'REVEAL selects a ticket on the server');
check(confirmed_draw_count() === 0, 'Revealed winner NOT saved before confirmation');
$ls = live_status();
check($ls['winner'] && $ls['winner']['lottery_number'] === $r['lottery_number'], 'Revealed winner exposed to LED after reveal');
expect_draw_error(function () { draw_reveal(1); }, 'Second REVEAL rejected (no re-roll without cancel)');

// Stale confirmation (wrong ticket) is rejected
expect_draw_error(function () use ($s) { draw_confirm(1, (int) $s['prize_id'], (int) $s['lottery_ticket_id'] + 100000); }, 'Confirm with stale/mismatched ticket rejected');

// Cancel discards
$discard = (int) $s['lottery_ticket_id'];
draw_cancel(1);
$s = sess();
check($s['state'] === 'IDLE' && confirmed_draw_count() === 0, 'CANCEL discards unconfirmed draw, nothing saved');
check(next_pending_prize()['prize_number'] == 50, 'After cancel, the same prize (50) is drawn again');
$st = db()->prepare('SELECT status FROM lottery_tickets WHERE id = ?');
$st->execute([$discard]);
check($st->fetchColumn() === 'AVAILABLE', 'Cancelled ticket stays eligible');

// Recovery: state persists across "reloads" (fresh status read)
draw_start(1);
draw_reveal(1);
$before = sess();
$fresh = live_status();
check($fresh['state'] === 'REVEALED' && $fresh['winner']['ticket_id'] === (int) $before['lottery_ticket_id'], 'State recovers after page reload (REVEALED + same winner)');
$c = draw_confirm(1, (int) $before['prize_id'], (int) $before['lottery_ticket_id']);
check($c['prize_number'] === 50, 'CONFIRM saves winner of Prize 50');
expect_draw_error(function () use ($before) { draw_confirm(1, (int) $before['prize_id'], (int) $before['lottery_ticket_id']); }, 'Duplicate confirmation blocked');
check(next_pending_prize()['prize_number'] == 49, 'Automatically moves to Prize 49');
$ls = live_status();
check($ls['last_winner']['prize_number'] === 50 && $ls['winners'][0]['prize_number'] === 50, 'LED shows last confirmed winner after refresh');

// DB-level uniqueness
try {
    db()->prepare("INSERT INTO lottery_draws (prize_id, lottery_ticket_id, winning_lottery_number, sold_by, drawn_at, confirmed_at)
                   VALUES (?, ?, 'x', 'x', NOW(), NOW())")->execute([(int) $before['prize_id'], 1]);
    check(false, 'UNIQUE(prize_id) blocks second winner for a prize');
} catch (PDOException $e) {
    check(true, 'UNIQUE(prize_id) blocks second winner for a prize');
}
try {
    $p49 = db()->query('SELECT id FROM prizes WHERE prize_number = 49')->fetchColumn();
    db()->prepare("INSERT INTO lottery_draws (prize_id, lottery_ticket_id, winning_lottery_number, sold_by, drawn_at, confirmed_at)
                   VALUES (?, ?, 'x', 'x', NOW(), NOW())")->execute([$p49, (int) $before['lottery_ticket_id']]);
    check(false, 'UNIQUE(lottery_ticket_id) blocks a ticket winning twice');
} catch (PDOException $e) {
    check(true, 'UNIQUE(lottery_ticket_id) blocks a ticket winning twice');
}

// Setup lock after first confirmed draw
$lockMsg = setup_lock_reason();
check($lockMsg !== null, 'Tickets/prizes locked after first confirmed draw');
check(ticket_create('8888', 'X', '') !== [], 'Ticket add rejected after draw started');
$anyTicket = (int) db()->query("SELECT id FROM lottery_tickets WHERE status='AVAILABLE' LIMIT 1")->fetchColumn();
check(ticket_delete($anyTicket) !== [], 'Ticket delete rejected after draw started');
try {
    import_ticket_rows([[1, '7777', 'X', '']], 't');
    check(false, 'Import rejected after draw started');
} catch (DrawException $e) {
    check(true, 'Import rejected after draw started');
}

// END / RESUME
draw_end(1);
expect_draw_error(function () { draw_start(1); }, 'Cannot START after END LOTTERY');
draw_resume(1);
check(sess()['state'] === 'IDLE', 'RESUME reopens the lottery');

/* ====================================================================== */
section('4. Simultaneous requests (separate processes)');
$res = run_parallel(array_fill(0, 6, ['start']));
$ok = count(array_filter($res, function ($r) { return $r['ok']; }));
check($ok === 1, "6 simultaneous START requests -> exactly 1 succeeds", json_encode($res));
$res = run_parallel(array_fill(0, 6, ['reveal']));
$ok = array_values(array_filter($res, function ($r) { return $r['ok']; }));
check(count($ok) === 1, '6 simultaneous REVEAL requests -> exactly 1 winner selected', json_encode($res));
$s = sess();
$res = run_parallel(array_fill(0, 6, ['confirm', (string) $s['prize_id'], (string) $s['lottery_ticket_id']]));
$okc = count(array_filter($res, function ($r) { return $r['ok']; }));
check($okc === 1, '6 simultaneous CONFIRM requests -> exactly 1 saved', json_encode($res));
check(confirmed_draw_count() === 2, 'Exactly 2 winners recorded so far');
$res = run_parallel([['start'], ['cancel'], ['start'], ['reveal'], ['start'], ['cancel']]);
$s = sess();
check(in_array($s['state'], ['IDLE', 'ROLLING', 'REVEALED'], true) && confirmed_draw_count() === 2, 'Mixed concurrent START/CANCEL/REVEAL leave a consistent state, no extra winners');
if ($s['state'] !== 'IDLE') {
    draw_cancel(1);
}

/* ====================================================================== */
section('5. Full event: remaining prizes 48 -> 1');
$order = [];
$err = '';
for ($i = 0; $i < 48; $i++) {
    try {
        $st = draw_start(1);
        if ($i % 2) {
            // one-click draw: pick + save in one call
            $cf = draw_reveal_and_confirm(1);
            if (sess()['state'] !== 'IDLE' || $cf['lottery_number'] === '') {
                throw new RuntimeException('one-click draw did not finish cleanly');
            }
        } else {
            $rv = draw_reveal(1);
            $s = sess();
            $cf = draw_confirm(1, (int) $s['prize_id'], (int) $s['lottery_ticket_id']);
        }
        $order[] = $cf['prize_number'];
    } catch (Throwable $e) {
        $err = $e->getMessage();
        break;
    }
}
check($order === range(48, 1), 'Prizes drawn strictly in descending order 48..1 (half via one-click reveal+confirm)', $err ?: json_encode($order));
$stats = draw_stats();
check($stats['prizes_drawn'] === 50 && $stats['winners_total'] === 50 && $stats['prizes_remaining'] === 0, 'All 50 prizes drawn, 50 winners');
check($stats['tickets_won'] === 50 && $stats['tickets_available'] === 70, '50 tickets marked WON, 70 still eligible');
$dupT = (int) db()->query('SELECT COUNT(*) - COUNT(DISTINCT lottery_ticket_id) FROM lottery_draws')->fetchColumn();
check($dupT === 0, 'No ticket won more than once');
$mismatch = (int) db()->query("SELECT COUNT(*) FROM lottery_draws d JOIN lottery_tickets t ON t.id = d.lottery_ticket_id
                                WHERE t.status <> 'WON' OR t.lottery_number <> d.winning_lottery_number")->fetchColumn();
check($mismatch === 0, 'Winning numbers match WON tickets');
expect_draw_error(function () { draw_start(1); }, 'START rejected when all prizes are drawn');
$w = all_winners('prize_desc');
check(array_column($w, 'prize_number') == range(50, 1), 'Report order is 50 -> 1');
$ls = live_status();
check($ls['all_drawn'] === true && count($ls['winners']) === LED_WINNERS_LIMIT && $ls['winners'][0]['prize_number'] === 1, 'LED winners list: latest ' . LED_WINNERS_LIMIT . ', newest (Prize 1) on top');
$since = live_status($ls['version']);
check($since['changed'] === false && count($since) === 3, 'Polling with ?since returns tiny unchanged payload');

$aud = db()->query("SELECT action, COUNT(*) c FROM audit_logs GROUP BY action")->fetchAll(PDO::FETCH_KEY_PAIR);
check(($aud['DRAW_CONFIRM'] ?? 0) == 50 && ($aud['DRAW_CANCEL'] ?? 0) >= 1, 'Audit log records confirmations and cancellations');

/* ====================================================================== */
section('6. Reset');
$r = lottery_reset(1, false);
$stats = draw_stats();
check($r['winners_cleared'] === 50 && $stats['winners_total'] === 0 && $stats['tickets_available'] === 120 && $stats['prizes_remaining'] === 50, 'Reset clears winners, tickets eligible again');
check($r['archive'] && is_file(APP_ROOT . '/exports/' . $r['archive']), 'Winners archived to exports/ before reset');
@unlink(APP_ROOT . '/exports/' . $r['archive']);
check(setup_lock_reason() === null, 'Setup unlocked after reset');

/* ====================================================================== */
section('7. Randomness / fairness');
db()->exec('DELETE FROM lottery_tickets');
import_ticket_rows(array_map(function ($i) { return [$i, 'R' . $i, 'Seller', '']; }, range(1, 10)), 'rand');
$counts = array_fill_keys(array_map(function ($i) { return 'R' . $i; }, range(1, 10)), 0);
$trials = 2000;
for ($i = 0; $i < $trials; $i++) {
    draw_start(1);
    $rv = draw_reveal(1);
    $counts[$rv['lottery_number']]++;
    draw_cancel(1);
}
$expected = $trials / 10;
$chi = 0.0;
foreach ($counts as $c) {
    $chi += ($c - $expected) ** 2 / $expected;
}
// df = 9, critical value at p = 0.001 is 27.88
check($chi < 27.88, sprintf('Uniform distribution over 10 tickets x %d draws (chi^2 = %.2f < 27.88)', $trials, $chi), json_encode($counts));
check(min($counts) > 0, 'Every ticket can be drawn');

// Won tickets are excluded
draw_start(1);
draw_reveal(1);
$s = sess();
draw_confirm(1, (int) $s['prize_id'], (int) $s['lottery_ticket_id']);
$wonId = (int) $s['lottery_ticket_id'];
$hit = false;
for ($i = 0; $i < 300; $i++) {
    draw_start(1);
    draw_reveal(1);
    if ((int) sess()['lottery_ticket_id'] === $wonId) {
        $hit = true;
    }
    draw_cancel(1);
}
check(!$hit, 'Already-won ticket never selected again (300 draws)');

// Running out of tickets
lottery_reset(1, true);
import_ticket_rows([[1, 'A1', 'S', ''], [2, 'A2', 'S', '']], 'small');
for ($i = 0; $i < 2; $i++) {
    draw_start(1);
    draw_reveal(1);
    $s = sess();
    draw_confirm(1, (int) $s['prize_id'], (int) $s['lottery_ticket_id']);
}
expect_draw_error(function () { draw_start(1); }, 'START rejected when no eligible tickets remain');
lottery_reset(1, true);

/* ====================================================================== */
if ($withHttp) {
    require __DIR__ . '/http-tests.php';
}

// Remove archive files created by test resets.
foreach (glob(APP_ROOT . '/exports/winners-before-reset-*.csv') ?: [] as $f) {
    if (filemtime($f) >= (int) $t0 - 5) {
        @unlink($f);
    }
}

printf("\n%d passed, %d failed  (%.1fs)\n", $passed, $failed, microtime(true) - $t0);
exit($failed ? 1 : 0);
