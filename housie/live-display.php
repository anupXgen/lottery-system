<?php
/**
 * Housie LED display (1920x1080). Public, read-only; needs no admin login.
 * Separate from the lottery LED screen. Polls housie/api/live-status.php every second.
 * Press F (or the button) for full screen.
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';

$initial = null;
try {
    $initial = housie_installed()
        ? housie_live_status()
        : ['ok' => true, 'changed' => true, 'token' => 'not-installed', 'installed' => false, 'title' => 'DIWALI HOUSIE', 'game' => null];
} catch (Throwable $e) {
    log_exception($e);
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diwali Housie – Live</title>
    <link rel="icon" href="<?= e(url('assets/images/logo.jpg')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&family=Montserrat:wght@600;700;800;900&display=swap">
    <link rel="stylesheet" href="<?= e(url('assets/css/housie-display.css')) ?>">
</head>
<body>
<div id="viewport">
    <div id="stage" class="st-SETUP">
        <div class="lights" aria-hidden="true">
            <?php for ($i = 0; $i < 42; $i++): ?><span style="--i:<?= $i ?>"></span><?php endfor; ?>
        </div>

        <!-- LEFT (65%): current number -->
        <section class="panel-left">
            <div class="head">
                <img class="org-logo" src="<?= e(url('assets/images/logo.jpg')) ?>" alt="Don Bosco Alumni Liluah">
                <div>
                    <div class="title" id="title">DIWALI HOUSIE</div>
                    <div class="sub" id="sub"></div>
                </div>
            </div>
            <div class="ball-wrap"><div class="ball small-text" id="ball"><span id="ball-num">? ?</span><span class="ring"></span></div></div>
            <div class="caption" id="caption">GET READY</div>
            <div class="counts">
                <div>CALLED<strong id="called">0</strong></div>
                <div>REMAINING<strong id="remaining">90</strong></div>
            </div>
            <div class="prev" id="prev"><span class="prev-label">PREVIOUS</span></div>
        </section>
        <div class="status-tag" id="status-tag">GET READY</div>

        <!-- RIGHT (35%): 1-90 board -->
        <aside class="panel-right">
            <div class="board-head"><div class="board-title">NUMBER BOARD</div><div class="board-count" id="board-count">0 / 90</div></div>
            <div class="board" id="board"></div>
        </aside>

        <!-- BOTTOM: categories and confirmed winners -->
        <section class="bottom">
            <div class="bottom-title">PRIZES &amp; WINNERS</div>
            <div class="prize-row" id="prizes"></div>
        </section>

        <div class="announce" id="announce" role="status" aria-live="polite">
            <div class="a1">🎉 WE HAVE A WINNER 🎉</div>
            <div class="a2" id="an-cat"></div>
            <div class="a3" id="an-prize"></div>
            <div class="a4" id="an-ticket"></div>
            <div class="a5" id="an-name"></div>
        </div>

        <canvas id="fx"></canvas>
        <div class="conn" id="conn">Reconnecting…</div>
    </div>
</div>
<button type="button" class="fs-btn" id="fs-btn">⛶ Full screen <kbd>F</kbd></button>
<script>
    window.HOUSIE_LED = {
        statusUrl: <?= json_encode(url('housie/api/live-status.php'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>,
        pollMs: <?= (int) LED_POLL_MS ?>,
        initial: <?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    };
</script>
<script src="<?= e(url('assets/js/draw-animation.js')) ?>"></script>
<script src="<?= e(url('assets/js/housie-display.js')) ?>"></script>
</body>
</html>
