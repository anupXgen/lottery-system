<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

$initial = live_status();
$pageTitle = 'Live Draw';
$activeNav = 'live';
$pageScripts = ['assets/js/draw-animation.js', 'assets/js/live-draw.js'];
require APP_ROOT . '/includes/header.php';
?>
<script>
    window.LOTTERY = {
        api: <?= json_encode(url('api/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?>,
        reportsUrl: <?= json_encode(url('admin/reports.php'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>,
        displayUrl: <?= json_encode(url('live-display.php'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>,
        initial: <?= json_encode($initial, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>
    };
</script>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h1 class="h3 mb-0"><i class="bi bi-broadcast text-danger"></i> Live Draw Control Panel</h1>
    <div class="d-flex align-items-center gap-3">
        <span id="conn-indicator" class="small text-success"><i class="bi bi-circle-fill"></i> Live</span>
        <span id="state-badge" class="badge fs-6 text-bg-secondary">—</span>
    </div>
</div>

<div class="row g-4">
    <!-- Main control stage -->
    <div class="col-xl-8">
        <div class="card control-stage">
            <div class="card-body p-4">
                <div class="row align-items-center g-4">
                    <div class="col-md-4 text-center">
                        <div class="stage-label" id="prize-label">Next Prize</div>
                        <div class="stage-prize-number" id="prize-number">—</div>
                        <div class="stage-prize-name" id="prize-name"></div>
                        <div class="small text-white-50" id="prize-desc"></div>
                    </div>
                    <div class="col-md-8 text-center">
                        <div class="stage-label" id="number-label">Winning Lottery Number</div>
                        <div class="stage-number" id="stage-number">----</div>
                        <div class="stage-meta" id="stage-meta">&nbsp;</div>
                    </div>
                </div>
                <div class="stage-message mt-3" id="stage-message"></div>
            </div>
            <div class="card-footer control-buttons">
                <button type="button" class="btn btn-lg btn-success" id="btn-start"><i class="bi bi-play-circle-fill"></i> START DRAW</button>
                <button type="button" class="btn btn-lg btn-warning" id="btn-reveal"><i class="bi bi-stars"></i> REVEAL WINNER</button>
                <button type="button" class="btn btn-lg btn-primary" id="btn-confirm"><i class="bi bi-check2-circle"></i> CONFIRM WINNER</button>
                <button type="button" class="btn btn-lg btn-outline-danger" id="btn-cancel"><i class="bi bi-x-circle"></i> CANCEL DRAW</button>
                <button type="button" class="btn btn-lg btn-outline-success" id="btn-resume"><i class="bi bi-arrow-counterclockwise"></i> RESUME LOTTERY</button>
                <a class="btn btn-lg btn-festive" id="btn-report" href="<?= e(url('admin/reports.php')) ?>"><i class="bi bi-file-earmark-pdf"></i> FINAL REPORT</a>
                <span class="flex-grow-1"></span>
                <a class="btn btn-lg btn-outline-light" id="btn-display" href="<?= e(url('live-display.php')) ?>" target="ledDisplay" rel="noopener"><i class="bi bi-display"></i> OPEN LED DISPLAY</a>
                <button type="button" class="btn btn-lg btn-outline-warning" id="btn-end"><i class="bi bi-flag-fill"></i> END LOTTERY</button>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header fw-semibold"><i class="bi bi-info-circle"></i> How to run each draw</div>
            <div class="card-body small">
                <ol class="mb-0">
                    <li><strong>START DRAW</strong> starts the rolling animation on the LED screen for the next prize.</li>
                    <li><strong>REVEAL WINNER</strong> makes the server pick a random eligible ticket. The LED screen slows down and reveals it.</li>
                    <li>Check the ticket, then press <strong>CONFIRM WINNER</strong> and confirm. Only then is it saved permanently.</li>
                    <li>The system automatically moves to the next prize (<?= TOTAL_PRIZES ?> → 1). Use <strong>CANCEL DRAW</strong> to discard an unconfirmed draw.</li>
                </ol>
            </div>
        </div>
    </div>

    <!-- Stats + recent winners -->
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="bi bi-bar-chart"></i> Event Statistics</div>
            <ul class="list-group list-group-flush" id="stats-list">
                <li class="list-group-item d-flex justify-content-between">Total tickets uploaded <strong id="st-tickets">0</strong></li>
                <li class="list-group-item d-flex justify-content-between">Eligible tickets left <strong id="st-available">0</strong></li>
                <li class="list-group-item d-flex justify-content-between">Total prizes <strong id="st-prizes">0</strong></li>
                <li class="list-group-item d-flex justify-content-between">Prizes completed <strong id="st-drawn" class="text-success">0</strong></li>
                <li class="list-group-item d-flex justify-content-between">Prizes remaining <strong id="st-remaining" class="text-warning">0</strong></li>
                <li class="list-group-item d-flex justify-content-between">Winners announced <strong id="st-winners">0</strong></li>
                <li class="list-group-item d-flex justify-content-between">Next prize number <strong id="st-next">—</strong></li>
                <li class="list-group-item d-flex justify-content-between">Current draw status <strong id="st-state">—</strong></li>
            </ul>
            <div class="card-body pt-0">
                <div class="progress mt-3" role="progressbar" aria-label="Draw progress" style="height: 12px">
                    <div class="progress-bar bg-warning" id="st-progress" style="width: 0%"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold"><i class="bi bi-trophy"></i> Latest Winners</div>
            <div class="table-responsive" style="max-height: 460px">
                <table class="table table-sm table-striped mb-0 align-middle">
                    <thead class="table-light sticky-top"><tr><th>Prize</th><th>Lottery No.</th><th>Sold By</th><th>Sold To</th></tr></thead>
                    <tbody id="winners-body"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Confirm winner modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning-subtle">
                <h2 class="modal-title h5" id="confirmModalTitle"><i class="bi bi-shield-check"></i> Confirm Winner</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Please verify the details below. Once confirmed, the winner is <strong>saved permanently</strong> and cannot be changed from the draw screen.</p>
                <table class="table table-bordered fs-5 mb-0">
                    <tr><th style="width: 40%">Prize</th><td id="cm-prize"></td></tr>
                    <tr><th>Winning Lottery Number</th><td class="fw-bold fs-2 font-monospace text-danger" id="cm-number"></td></tr>
                    <tr><th>Sold By</th><td id="cm-soldby"></td></tr>
                    <tr><th>Sold To</th><td id="cm-soldto"></td></tr>
                </table>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-lg" data-bs-dismiss="modal">Go Back</button>
                <button type="button" class="btn btn-primary btn-lg" id="btn-confirm-final"><i class="bi bi-check2-circle"></i> Yes, Confirm &amp; Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Generic confirm modal -->
<div class="modal fade" id="askModal" tabindex="-1" aria-labelledby="askModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="askModalTitle">Are you sure?</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="askModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">No, go back</button>
                <button type="button" class="btn btn-danger" id="askModalYes">Yes</button>
            </div>
        </div>
    </div>
</div>
<?php require APP_ROOT . '/includes/footer.php'; ?>
