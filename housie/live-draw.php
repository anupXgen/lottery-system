<?php
/** Housie Live Number Draw (admin control panel). */
require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/includes/housie/housie.php';
require_login();
housie_require_installed_page();

$game = housie_active_game();
$initial = housie_live_status();
$pageTitle = 'Housie Live Draw';
$activeNav = 'housie';
$pageStyles = ['assets/css/housie.css'];
$pageScripts = ['assets/js/draw-animation.js', 'assets/js/housie-draw.js'];
require APP_ROOT . '/includes/header.php';
?>
<script>
    window.HOUSIE = {
        api: <?= json_encode(url('housie/api/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>,
        initial: <?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    };
</script>
<div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-broadcast text-danger"></i> Housie Live Number Draw</h1>
    <div class="d-flex align-items-center gap-2">
        <span id="hz-conn" class="small text-success"><i class="bi bi-circle-fill"></i> Live</span>
        <span id="hz-state" class="badge fs-6 hz-state-SETUP">—</span>
    </div>
</div>
<?= housie_game_strip($game) ?>

<?php if ($game): ?>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card control-stage">
            <div class="card-body p-4 text-center">
                <div class="stage-label mb-2" id="hz-label">Current Number</div>
                <div class="hz-ball idle" id="hz-ball">–</div>
                <div class="mt-3 text-white-50">Called <strong class="text-white fs-5" id="hz-count">0</strong> of 90 · Remaining <strong class="text-white fs-5" id="hz-remaining">90</strong></div>
                <div class="mt-3"><div class="small text-white-50 mb-1">Previous numbers</div><div class="hz-prev" id="hz-prev"></div></div>
                <div class="stage-message mt-3" id="hz-message"></div>
            </div>
            <div class="card-footer control-buttons">
                <button type="button" class="btn btn-lg btn-success d-none" id="hz-start"><i class="bi bi-play-circle-fill"></i> START HOUSIE GAME</button>
                <button type="button" class="btn btn-lg btn-warning d-none" id="hz-call"><i class="bi bi-stars"></i> CALL NEXT NUMBER</button>
                <button type="button" class="btn btn-lg btn-outline-warning d-none" id="hz-pause"><i class="bi bi-pause-circle"></i> PAUSE GAME</button>
                <button type="button" class="btn btn-lg btn-success d-none" id="hz-resume"><i class="bi bi-play-circle"></i> RESUME GAME</button>
                <span class="flex-grow-1"></span>
                <a class="btn btn-lg btn-outline-light" href="<?= e(url('housie/claims.php')) ?>"><i class="bi bi-patch-check"></i> VERIFY CLAIM</a>
                <a class="btn btn-lg btn-outline-light" href="<?= e(url('housie/live-display.php')) ?>" target="housieDisplay" rel="noopener"><i class="bi bi-display"></i> LED DISPLAY</a>
                <button type="button" class="btn btn-lg btn-outline-danger d-none" id="hz-end"><i class="bi bi-flag-fill"></i> END GAME</button>
            </div>
        </div>
        <div class="card mt-4">
            <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> How to run the game</div>
            <div class="card-body small">
                <ol class="mb-0">
                    <li><strong>START HOUSIE GAME</strong> locks the tickets and prepares a secure random order of 1–90 on the server.</li>
                    <li><strong>CALL NEXT NUMBER</strong> calls the next number. It is never repeated, and appears on the LED screen within a second.</li>
                    <li>When someone claims a prize, <strong>PAUSE GAME</strong> and use <strong>VERIFY CLAIM</strong>; then <strong>RESUME GAME</strong>.</li>
                    <li><strong>END GAME</strong> when finished, then download the Housie report.</li>
                </ol>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="bi bi-grid-3x3"></i> Number board</div>
            <div class="card-body"><div class="hz-board" id="hz-board"></div></div>
        </div>
        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-trophy"></i> Prizes</div>
            <ul class="list-group list-group-flush" id="hz-prizes"></ul>
        </div>
    </div>
</div>

<div class="modal fade" id="hzAsk" tabindex="-1" aria-labelledby="hzAskTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title h5" id="hzAskTitle">Are you sure?</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body" id="hzAskBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No, go back</button>
                <button type="button" class="btn btn-danger" id="hzAskYes">Yes</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php require APP_ROOT . '/includes/footer.php'; ?>
