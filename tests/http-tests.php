<?php
/**
 * HTTP-level tests (included by run-tests.php --http).
 * Starts PHP's built-in server against the test database and exercises pages and APIs.
 */
if (PHP_SAPI !== 'cli' || !function_exists('check')) {
    exit(1);
}

section('8. HTTP: pages, security, APIs, reports');

$port = 8099;
$base = "http://127.0.0.1:$port";
$cookieJar = tempnam(sys_get_temp_dir(), 'ck');
$env = getenv();
$env['LOTTERY_DB_NAME'] = $GLOBALS['testDb'];
$server = proc_open('"' . PHP_BINARY . '" -S 127.0.0.1:' . $port . ' -t "' . APP_ROOT . '"',
    [1 => ['file', sys_get_temp_dir() . '/lottery-http.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/lottery-http.log', 'a']],
    $pipes, APP_ROOT, $env, ['bypass_shell' => true]);
for ($i = 0; $i < 50; $i++) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

function http(string $method, string $path, array $opts = []): array
{
    global $base, $cookieJar;
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieJar,
        CURLOPT_COOKIEFILE => $cookieJar,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 60,
    ]);
    if (isset($opts['post'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['post']);
    }
    if (isset($opts['headers'])) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    return ['code' => $code, 'headers' => substr((string) $raw, 0, $hs), 'body' => substr((string) $raw, $hs)];
}

function csrf_from(string $html): string
{
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $html, $m);
    return $m[1] ?? '';
}

function no_php_errors(string $body): bool
{
    return !preg_match('/(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)/', $body);
}

// Unauthenticated access
$r = http('GET', '/admin/dashboard.php');
check($r['code'] === 302 && strpos($r['headers'], 'login.php') !== false, 'Admin page redirects to login when not authenticated');
$r = http('POST', '/api/draw-start.php', ['post' => ['x' => 1]]);
check($r['code'] === 401, 'Draw API returns 401 when not authenticated');
$r = http('GET', '/api/live-status.php');
$j = json_decode($r['body'], true);
check($r['code'] === 200 && $j['ok'] === true, 'Public live-status API works without login');

// Login
$r = http('GET', '/login.php');
$token = csrf_from($r['body']);
check($r['code'] === 200 && $token !== '', 'Login page renders with CSRF token');
$r = http('POST', '/login.php', ['post' => ['csrf_token' => $token, 'username' => 'admin', 'password' => 'wrong']]);
check(strpos($r['body'], 'Invalid username or password') !== false, 'Wrong password rejected');
$r = http('POST', '/login.php', ['post' => ['csrf_token' => 'bad', 'username' => 'admin', 'password' => 'Admin@123']]);
check(strpos($r['body'], 'expired') !== false, 'Login without valid CSRF token rejected');
$r = http('GET', '/login.php');
$r = http('POST', '/login.php', ['post' => ['csrf_token' => csrf_from($r['body']), 'username' => 'admin', 'password' => 'Admin@123']]);
check($r['code'] === 302 && strpos($r['headers'], 'admin/dashboard.php') !== false, 'Correct login redirects to dashboard');
check(stripos($r['headers'], 'HttpOnly') !== false, 'Session cookie is HttpOnly');

// All admin pages render cleanly
foreach (['dashboard', 'tickets', 'upload-tickets', 'prizes', 'live-draw', 'reports', 'settings'] as $p) {
    $r = http('GET', "/admin/$p.php");
    check($r['code'] === 200 && no_php_errors($r['body']), "admin/$p.php renders (200, no PHP errors)");
}
$token = csrf_from(http('GET', '/admin/dashboard.php')['body']);

// CSRF on APIs
$r = http('POST', '/api/draw-start.php', ['post' => ['x' => 1]]);
check($r['code'] === 419, 'Draw API rejects request without CSRF token (419)');
$r = http('GET', '/api/draw-start.php');
check($r['code'] === 405 || $r['code'] === 419, 'Draw API rejects GET');

// Upload template + CSV upload
$r = http('GET', '/admin/upload-tickets.php?template=1');
check(strpos($r['headers'], 'text/csv') !== false && strpos($r['body'], '"Lottery Number","Sold By","Sold To"') !== false, 'CSV upload template downloads');
$r = http('POST', '/admin/upload-tickets.php', ['post' => [
    'csrf_token' => $token,
    'ticket_file' => new CURLFile(APP_ROOT . '/sample-tickets.csv', 'text/csv', 'sample-tickets.csv'),
]]);
check(strpos($r['body'], '120 ticket(s) imported successfully') !== false, 'Upload sample-tickets.csv via web form (120 imported)');
$r = http('POST', '/admin/upload-tickets.php', ['post' => [
    'csrf_token' => $token,
    'ticket_file' => new CURLFile(APP_ROOT . '/sample-tickets.csv', 'text/csv', 'sample-tickets.csv'),
]]);
check(strpos($r['body'], 'Validation failed') !== false && strpos($r['body'], 'already exists in the system') !== false, 'Re-upload of same file rejected as duplicates');
$r = http('GET', '/admin/tickets.php?q=1005');
check(strpos($r['body'], '1005') !== false && strpos($r['body'], 'Showing 1–1 of 1') !== false, 'Ticket search works');

// XSS: ticket with HTML in Sold By is escaped
$r = http('POST', '/admin/tickets.php', ['post' => ['csrf_token' => $token, 'action' => 'add', 'lottery_number' => '5555', 'sold_by' => '<script>alert(1)</script>', 'sold_to' => '']]);
$r = http('GET', '/admin/tickets.php?q=5555');
check(strpos($r['body'], '<script>alert(1)</script>') === false && strpos($r['body'], '&lt;script&gt;') !== false, 'HTML in ticket fields is escaped (XSS safe)');

// Prize configuration
$post = ['csrf_token' => $token];
for ($n = 1; $n <= 50; $n++) {
    $post["name[$n]"] = $n === 1 ? 'Grand Prize - Car' : "Test Prize $n";
    $post["description[$n]"] = '';
}
$r = http('POST', '/admin/prizes.php', ['post' => $post]);
$name1 = db()->query('SELECT prize_name FROM prizes WHERE prize_number = 1')->fetchColumn();
check($name1 === 'Grand Prize - Car', 'Prize names saved from the Prizes page');

// LED display page
$r = http('GET', '/live-display.php');
check($r['code'] === 200 && strpos($r['body'], 'DIWALI LOTTERY DRAW') !== false && no_php_errors($r['body']), 'LED display renders without login');
foreach (['/assets/css/live-display.css', '/assets/js/live-display.js', '/assets/js/draw-animation.js', '/assets/vendor/bootstrap/css/bootstrap.min.css'] as $asset) {
    check(http('GET', $asset)['code'] === 200, "Asset $asset served");
}

// Full draw cycle through the API
$h = ['X-CSRF-Token: ' . $token];
$api = function ($ep, $data = []) use ($h, $token) {
    $r = http('POST', '/api/' . $ep, ['post' => $data + ['csrf_token' => $token], 'headers' => $h]);
    return json_decode($r['body'], true) ?: ['ok' => false, 'raw' => $r['body'], 'code' => $r['code']];
};
$order = [];
$problem = '';
$j = $api('draw-finish.php');
check($j['ok'] === false && $j['status']['state'] === 'IDLE', 'One-click finish API refused when no draw is running');
for ($i = 0; $i < 50; $i++) {
    $a = $api('draw-start.php');
    if ($i % 2) {
        // One-click draw (what the START button does): draw-start, then draw-finish.
        if ($i === 1) {
            $mh = curl_multi_init();
            $handles = [];
            for ($k = 0; $k < 5; $k++) {
                $ch = curl_init($GLOBALS['base'] . '/api/draw-finish.php');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $GLOBALS['cookieJar'], CURLOPT_HTTPHEADER => $h,
                    CURLOPT_POSTFIELDS => ['csrf_token' => $token],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = $ch;
            }
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running);
            $wins = [];
            foreach ($handles as $ch) {
                $jj = json_decode(curl_multi_getcontent($ch), true);
                if (!empty($jj['ok'])) {
                    $wins[] = $jj['result']['prize_number'];
                }
                curl_multi_remove_handle($mh, $ch);
            }
            curl_multi_close($mh);
            check(count($wins) === 1, '5 simultaneous one-click FINISH requests -> exactly 1 winner saved');
            $order[] = $wins ? $wins[0] : null;
            continue;
        }
        $f = $api('draw-finish.php');
        if (!$a['ok'] || !$f['ok'] || $f['status']['state'] !== 'IDLE') {
            $problem = json_encode([$a, $f]);
            break;
        }
        $order[] = $f['result']['prize_number'];
        continue;
    }
    $b = $api('draw-reveal.php');
    if (!$a['ok'] || !$b['ok']) {
        $problem = json_encode([$a, $b]);
        break;
    }
    $st = $b['status'];
    if ($i === 0) {
        // simultaneous confirmation requests over HTTP
        $mh = curl_multi_init();
        $handles = [];
        for ($k = 0; $k < 5; $k++) {
            $ch = curl_init($GLOBALS['base'] . '/api/draw-confirm.php');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $GLOBALS['cookieJar'], CURLOPT_HTTPHEADER => $h,
                CURLOPT_POSTFIELDS => ['csrf_token' => $token, 'prize_id' => $st['current_prize']['id'], 'ticket_id' => $st['winner']['ticket_id']],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running);
        $oks = 0;
        foreach ($handles as $ch) {
            $j = json_decode(curl_multi_getcontent($ch), true);
            $oks += !empty($j['ok']) ? 1 : 0;
            curl_multi_remove_handle($mh, $ch);
        }
        curl_multi_close($mh);
        check($oks === 1, '5 simultaneous HTTP CONFIRM requests -> exactly 1 succeeds');
        $order[] = $st['current_prize']['number'];
        continue;
    }
    $c = $api('draw-confirm.php', ['prize_id' => $st['current_prize']['id'], 'ticket_id' => $st['winner']['ticket_id']]);
    if (!$c['ok']) {
        $problem = json_encode($c);
        break;
    }
    $order[] = $c['result']['prize_number'];
}
check($order === range(50, 1), 'All 50 draws via HTTP API in order 50 -> 1 (half one-click)', $problem);
$j = $api('draw-start.php');
check($j['ok'] === false && strpos($j['error'], 'All prizes') !== false, 'API refuses START after all prizes drawn');
$j = $api('draw-end.php', ['action' => 'end']);
check($j['ok'] && $j['status']['state'] === 'ENDED', 'END LOTTERY via API');

$r = http('GET', '/api/winners.php?order=prize');
$j = json_decode($r['body'], true);
check($j['count'] === 50 && $j['winners'][0]['prize_number'] === 50 && $j['winners'][49]['prize_number'] === 1, 'Winners API (prize order) returns 50 -> 1');

// Reports
$r = http('GET', '/admin/reports.php?export=pdf');
$isPdf = strpos($r['headers'], 'application/pdf') !== false && strpos($r['body'], '%PDF') === 0;
check($isPdf, 'PDF report downloads (application/pdf)');
$pdfPath = sys_get_temp_dir() . '/lottery-report-test.pdf';
file_put_contents($pdfPath, $r['body']);
$pdftotext = trim((string) @shell_exec(stripos(PHP_OS, 'WIN') === 0 ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null'));
if ($pdftotext === '' && is_file('C:/Program Files/Git/mingw64/bin/pdftotext.exe')) {
    $pdftotext = 'C:/Program Files/Git/mingw64/bin/pdftotext.exe';
}
if ($pdftotext !== '') {
    $txtPath = $pdfPath . '.txt';
    shell_exec('"' . strtok($pdftotext, "\r\n") . '" -enc UTF-8 -layout "' . $pdfPath . '" "' . $txtPath . '"');
    $txt = (string) @file_get_contents($txtPath);
    check(strpos($txt, 'DIWALI LOTTERY – FINAL WINNERS LIST') !== false, 'PDF title is "DIWALI LOTTERY – FINAL WINNERS LIST"');
    check(preg_match('/Page 1 of (\d+)/', $txt, $pm) === 1, 'PDF has page numbers (' . ($pm[0] ?? '-') . ')');
    preg_match_all('/^\s*(\d{1,2})\s{2,}(?:Test Prize \d+|Grand Prize - Car)/m', $txt, $mm);
    $nums = array_map('intval', $mm[1]);
    check($nums === range(50, 1), 'PDF lists all 50 winners in order 50 -> 1', implode(',', $nums));
    check(stripos($txt, 'Total Winners') !== false && stripos($txt, 'Event Date') !== false && stripos($txt, 'Generation Date') !== false, 'PDF has event date, totals and generation date');
} else {
    echo "  [SKIP] pdftotext not found - PDF text checks skipped\n";
}
$r = http('GET', '/admin/reports.php?export=csv');
$lines = array_values(array_filter(explode("\n", trim($r['body']))));
check(strpos($r['headers'], 'text/csv') !== false && count($lines) === 51 && strpos($lines[1], '50,') === 0, 'CSV export has header + 50 rows, starts with prize 50');

// Locked prize page after draws
$r = http('GET', '/admin/prizes.php');
check(strpos($r['body'], 'locked') !== false, 'Prizes page shows locked notice after draws');

// Housie / Tambola module over HTTP (still logged in)
require __DIR__ . '/housie-http-tests.php';

// Logout
$r = http('POST', '/logout.php', ['post' => ['csrf_token' => $token]]);
$r = http('GET', '/admin/dashboard.php');
check($r['code'] === 302, 'After logout, admin pages require login again');

proc_terminate($server);
proc_close($server);
@unlink($cookieJar);
foreach (glob(APP_ROOT . '/exports/diwali-lottery-*.pdf') ?: [] as $f) {
    if (filemtime($f) >= (int) $GLOBALS['t0'] - 5) {
        @unlink($f);
    }
}
foreach (glob(APP_ROOT . '/uploads/tickets-*') ?: [] as $f) {
    if (filemtime($f) >= (int) $GLOBALS['t0'] - 5) {
        @unlink($f);
    }
}
