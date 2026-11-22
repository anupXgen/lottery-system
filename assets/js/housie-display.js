/* Housie LED display controller: polls the server and animates numbers and winners. */
(function () {
    'use strict';

    var cfg = window.HOUSIE_LED;
    var $ = function (id) { return document.getElementById(id); };
    var stage = $('stage');
    var ball = $('ball');
    var ballNum = $('ball-num');
    var roller = new NumberRoller(ballNum, { fastInterval: 55, slowInterval: 520 });
    var fx = new Celebration($('fx'));

    var cur = null;              // latest status
    var shownCount = null;       // calls_count already shown on screen
    var animating = null;        // number currently being revealed
    var lastClaim = null;        // highest confirmed claim id already announced
    var token = null;
    var failures = 0;
    var announceTimer = null;

    /* ---------- stage scaling ---------- */
    function scaleStage() {
        stage.style.transform = 'scale(' + Math.min(window.innerWidth / 1920, window.innerHeight / 1080) + ')';
    }
    window.addEventListener('resize', scaleStage);
    scaleStage();

    /* ---------- board ---------- */
    var cells = {};
    for (var n = 1; n <= 90; n++) {
        var c = document.createElement('span');
        c.textContent = n;
        $('board').appendChild(c);
        cells[n] = c;
    }

    function text(el, v) { el.textContent = v === null || v === undefined ? '' : String(v); }

    function setBall(value, small) {
        roller.halt();
        ball.classList.remove('rolling');
        ball.classList.toggle('small-text', !!small);
        text(ballNum, value);
    }

    function renderBoard(s) {
        var called = {};
        (s.called || []).forEach(function (x) { called[x] = true; });
        for (var k = 1; k <= 90; k++) {
            var hidden = animating !== null && k === animating;   // not revealed yet
            var cls = called[k] && !hidden ? 'called' : '';
            if (cls && k === s.current_number && animating === null && s.game && s.game.status !== 'ENDED') {
                cls += ' current';
            }
            cells[k].className = cls;
        }
        var shown = (s.calls_count || 0) - (animating !== null ? 1 : 0);
        text($('board-count'), Math.max(0, shown) + ' / 90');
    }

    function renderPrev(s) {
        var box = $('prev');
        box.innerHTML = '<span class="prev-label">PREVIOUS</span>';
        var list = (s.previous || []).slice(0, 5);
        list.forEach(function (num) {
            var b = document.createElement('span');
            b.className = 'b';
            b.textContent = num;
            box.appendChild(b);
        });
        box.style.visibility = list.length ? 'visible' : 'hidden';
    }

    function renderPrizes(s, flashCategory) {
        var row = $('prizes');
        row.innerHTML = '';
        var prizes = s.prizes || [];
        if (!prizes.length) {
            var empty = document.createElement('div');
            empty.className = 'prize-card';
            empty.innerHTML = '<div class="lbl">Prizes will appear here</div>';
            row.appendChild(empty);
            return;
        }
        prizes.forEach(function (p) {
            var card = document.createElement('div');
            card.className = 'prize-card ' + p.state + (flashCategory === p.category ? ' flash' : '');
            var lbl = document.createElement('div'); lbl.className = 'lbl'; lbl.textContent = p.label;
            var pn = document.createElement('div'); pn.className = 'pn'; pn.textContent = p.prize_name;
            card.appendChild(lbl);
            card.appendChild(pn);
            var shownW = p.winners.slice(0, 3);
            shownW.forEach(function (w) {
                var line = document.createElement('div');
                line.className = 'w';
                var b = document.createElement('b');
                b.textContent = '#' + w.ticket;
                line.appendChild(b);
                line.appendChild(document.createTextNode(w.participant ? '  ' + w.participant : ''));
                card.appendChild(line);
            });
            if (p.winners.length > 3) {
                var more = document.createElement('div'); more.className = 'more'; more.textContent = '+' + (p.winners.length - 3) + ' more';
                card.appendChild(more);
            }
            var st = document.createElement('div');
            st.className = 'st';
            st.textContent = p.state === 'COMPLETED' ? '✓ COMPLETED' : p.state === 'CLAIMED' ? 'CLAIMED ' + p.winners.length + ' / ' + p.winners_allowed : 'OPEN';
            card.appendChild(st);
            row.appendChild(card);
        });
    }

    function caption(s) {
        if (!s.game) { return 'HOUSIE BEGINS SOON'; }
        var st = s.game.status;
        if (st === 'SETUP') { return 'GET READY'; }
        if (st === 'PAUSED') { return 'PAUSED · CHECKING CLAIMS'; }
        if (st === 'ENDED') { return 'GAME OVER · THANK YOU'; }
        if (animating !== null) { return 'NEXT NUMBER…'; }
        if (!s.calls_count) { return 'FIRST NUMBER COMING UP'; }
        return s.remaining === 0 ? 'ALL NUMBERS CALLED' : 'NUMBER ' + s.calls_count + ' OF 90';
    }

    function renderStatic(s) {
        text($('title'), s.title || 'DIWALI HOUSIE');
        text($('sub'), s.game ? [s.game.name, s.game.date].filter(Boolean).join('  ·  ') : '');
        var st = s.game ? s.game.status : 'SETUP';
        stage.className = 'st-' + st;
        text($('status-tag'), st === 'PAUSED' ? 'PAUSED' : st === 'ENDED' ? 'GAME OVER' : 'GET READY');
        text($('called'), Math.max(0, (s.calls_count || 0) - (animating !== null ? 1 : 0)));
        text($('remaining'), animating !== null ? s.remaining + 1 : (s.game ? s.remaining : 90));
        text($('caption'), caption(s));
        renderBoard(s);
        renderPrev(s);
    }

    /** Roll the ball over the numbers not yet shown, then land on the new number. */
    function revealNumber(s) {
        var number = s.current_number;
        animating = number;
        var called = {};
        (s.called || []).forEach(function (x) { called[x] = true; });
        var pool = [];
        for (var k = 1; k <= 90; k++) {
            if (!called[k] || k === number) { pool.push(String(k)); }
        }
        renderStatic(s);
        ball.classList.remove('small-text', 'pop');
        ball.classList.add('rolling');
        roller.setPool(pool);
        roller.start();
        roller.stopAt(number, 2200).then(function (finished) {
            if (!finished || animating !== number) {
                return;
            }
            animating = null;
            ball.classList.remove('rolling');
            void ball.offsetWidth;
            ball.classList.add('pop');
            renderStatic(cur);
            var r = $('ball').getBoundingClientRect();
            fx.burst(r.left + r.width / 2, r.top + r.height / 2, 50);
        });
    }

    function announce(w) {
        text($('an-cat'), w.label + (w.rank > 1 ? ' #' + w.rank : ''));
        text($('an-prize'), w.prize_name);
        text($('an-ticket'), 'TICKET ' + w.ticket);
        text($('an-name'), w.participant || '');
        var box = $('announce');
        box.classList.remove('show');
        void box.offsetWidth;
        box.classList.add('show');
        fx.rain(140);
        setTimeout(function () { fx.burst(560, 300, 140); fx.burst(1100, 300, 140); }, 300);
        clearTimeout(announceTimer);
        announceTimer = setTimeout(function () { box.classList.remove('show'); }, 9000);
    }

    function apply(s, isInitial) {
        cur = s;
        token = s.token;
        var count = s.calls_count || 0;
        var latest = s.latest_winner;
        var newWinner = !isInitial && latest && lastClaim !== null && latest.claim_id > lastClaim;

        if (!s.game) {
            animating = null;
            shownCount = 0;
            setBall('? ?', true);
        } else if (!isInitial && shownCount !== null && count > shownCount && s.current_number) {
            if (animating !== null) {
                roller.halt();
                animating = null;
            }
            revealNumber(s);
        } else if (animating === null) {
            if (s.current_number) {
                setBall(s.current_number, false);
            } else {
                setBall(s.game.status === 'ENDED' ? '–' : '? ?', true);
            }
        }
        shownCount = count;
        if (animating === null) {
            renderStatic(s);
        }
        renderPrizes(s, newWinner ? latest.category : null);
        if (newWinner) {
            announce(latest);
        }
        if (s.game && s.game.status === 'ENDED' && !isInitial && cur && !stage.dataset.endedShown) {
            stage.dataset.endedShown = '1';
            fx.rain(160);
        }
        lastClaim = latest ? latest.claim_id : 0;
    }

    /* ---------- polling ---------- */
    function poll() {
        var url = cfg.statusUrl + (token ? '?since=' + encodeURIComponent(token) : '');
        var ctrl = window.AbortController ? new AbortController() : null;
        var to = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 6000);
        fetch(url, { cache: 'no-store', signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (s) {
                failures = 0;
                $('conn').classList.remove('show');
                if (s && s.ok && s.changed !== false) {
                    if (cur && cur.game && s.game && cur.game.id !== s.game.id) {
                        shownCount = null;      // switched to another game: no animations for the switch
                        lastClaim = null;
                        delete stage.dataset.endedShown;
                    }
                    apply(s, cur === null || shownCount === null);
                }
            })
            .catch(function () {
                if (++failures >= 3) { $('conn').classList.add('show'); }
            })
            .then(function () {
                clearTimeout(to);
                setTimeout(poll, failures ? Math.min(5000, cfg.pollMs * (1 + failures)) : cfg.pollMs);
            });
    }

    /* ---------- full screen, cursor, wake lock ---------- */
    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            var p = document.documentElement.requestFullscreen && document.documentElement.requestFullscreen();
            if (p && p.catch) { p.catch(function () {}); }
        } else if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }
    $('fs-btn').addEventListener('click', toggleFullscreen);
    document.addEventListener('keydown', function (e) {
        if (e.key === 'f' || e.key === 'F' || e.key === 'F11') {
            e.preventDefault();
            toggleFullscreen();
        }
    });
    document.addEventListener('fullscreenchange', function () {
        document.body.classList.toggle('is-fullscreen', !!document.fullscreenElement);
        setTimeout(scaleStage, 50);
    });
    var cursorTimer = null;
    document.addEventListener('mousemove', function () {
        document.body.classList.remove('cursor-hidden');
        clearTimeout(cursorTimer);
        cursorTimer = setTimeout(function () { document.body.classList.add('cursor-hidden'); }, 3000);
    });
    function requestWakeLock() {
        if (navigator.wakeLock && document.visibilityState === 'visible') {
            navigator.wakeLock.request('screen').catch(function () {});
        }
    }
    document.addEventListener('visibilitychange', requestWakeLock);
    requestWakeLock();

    /* ---------- start ---------- */
    if (cfg.initial && cfg.initial.ok) {
        apply(cfg.initial, true);
    } else {
        $('conn').classList.add('show');
    }
    setTimeout(poll, cfg.pollMs);
})();
