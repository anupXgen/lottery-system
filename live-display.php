<?php
/**
 * LED screen display (1920x1080, 16:9). Public, read-only; needs no admin login.
 * Polls api/live-status.php and animates draws. Press F (or the button) for full screen.
 */
require __DIR__ . '/includes/bootstrap.php';

try {
    $initial = live_status();
} catch (Throwable $e) {
    $initial = null;
    log_exception($e);
}
$diya = '<svg class="diya" viewBox="0 0 120 120" aria-hidden="true">'
    . '<path class="diya-flame" d="M60 8 C72 30 76 44 60 58 C44 44 48 30 60 8 Z"/>'
    . '<path class="diya-flame-core" d="M60 26 C66 38 67 46 60 54 C53 46 54 38 60 26 Z"/>'
    . '<path class="diya-bowl" d="M8 66 Q60 64 112 66 Q104 104 60 106 Q16 104 8 66 Z"/>'
    . '<path class="diya-rim" d="M8 66 Q60 76 112 66" fill="none"/>'
    . '<circle class="diya-dot" cx="38" cy="86" r="4"/><circle class="diya-dot" cx="60" cy="90" r="4"/><circle class="diya-dot" cx="82" cy="86" r="4"/>'
    . '</svg>';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Diwali Lottery Draw – Live</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🪔</text></svg>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;900&family=Montserrat:wght@600;800;900&display=swap">
    <link rel="stylesheet" href="<?= e(url('assets/css/live-display.css')) ?>">
</head>
<body>
<div id="viewport">
    <div id="stage" class="state-idle">
        <!-- string lights -->
        <div class="lights" aria-hidden="true">
            <?php for ($i = 0; $i < 42; $i++): ?><span style="--i:<?= $i ?>"></span><?php endfor; ?>
        </div>

        <!-- LEFT: active draw (70%) -->
        <section class="panel-left">
            <header class="event-head">
                <div class="event-title">DIWALI LOTTERY DRAW</div>
                <div class="event-sub" id="event-sub"></div>
            </header>

            <div class="prize-block">
                <div class="prize-label" id="prize-label">PRIZE NO.</div>
                <div class="prize-number" id="prize-number">—</div>
                <div class="prize-name" id="prize-name"></div>
            </div>

            <div class="number-frame" id="number-frame">
                <span class="corner tl"></span><span class="corner tr"></span><span class="corner bl"></span><span class="corner br"></span>
                <div class="number-caption" id="number-caption">LUCKY NUMBER</div>
                <div class="lottery-number" id="lottery-number">? ? ? ?</div>
                <div class="confirmed-stamp" id="confirmed-stamp">✓ CONFIRMED</div>
            </div>

            <div class="winner-meta" id="winner-meta">
                <div class="meta-item" id="meta-soldby"><span class="meta-k">Sold By</span><span class="meta-v" id="soldby"></span></div>
                <div class="meta-item" id="meta-soldto"><span class="meta-k">Sold To</span><span class="meta-v" id="soldto"></span></div>
            </div>

            <div class="ribbon" id="ribbon"></div>

            <div class="diyas" aria-hidden="true"><?= str_repeat($diya, 7) ?></div>
        </section>

        <!-- RIGHT: winners list (30%) -->
        <aside class="panel-right">
            <div class="winners-head">
                <div class="winners-title">WINNERS</div>
                <div class="winners-count" id="winners-count">0 / 50</div>
            </div>
            <div class="winners-cols"><span>Prize</span><span>Lottery No.</span><span>Sold By</span></div>
            <div class="winners-scroll" id="winners-scroll">
                <ul class="winners-list" id="winners-list"></ul>
                <div class="winners-empty" id="winners-empty">Winners will appear here</div>
            </div>
        </aside>

        <!-- closing screen -->
        <div class="closing" id="closing">
            <div class="closing-inner">
                <div class="closing-diyas" aria-hidden="true"><?= str_repeat($diya, 3) ?></div>
                <div class="closing-title">Shubh Deepavali!</div>
                <div class="closing-sub" id="closing-sub">Thank you for participating in the Diwali Lottery Draw</div>
            </div>
        </div>
    </div>
</div>

<canvas id="fx" aria-hidden="true"></canvas>
<div id="conn" class="conn" role="status">Reconnecting…</div>
<button id="fs-btn" class="fs-btn" type="button" title="Full screen (F)">⛶ Full screen <kbd>F</kbd></button>

<script>
    window.LED = {
        statusUrl: <?= json_encode(url('api/live-status.php'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>,
        pollMs: <?= (int) LED_POLL_MS ?>,
        totalPrizes: <?= (int) TOTAL_PRIZES ?>,
        initial: <?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    };
</script>
<script src="<?= e(url('assets/js/draw-animation.js')) ?>"></script>
<script src="<?= e(url('assets/js/live-display.js')) ?>"></script>
</body>
</html>
