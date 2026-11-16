/* LED display controller: polls the server and drives the on-screen animations. */
(function () {
    'use strict';

    var cfg = window.LED;
    var $ = function (id) { return document.getElementById(id); };
    var stage = $('stage');
    var els = {
        sub: $('event-sub'), prizeLabel: $('prize-label'), prizeNumber: $('prize-number'), prizeName: $('prize-name'),
        caption: $('number-caption'), number: $('lottery-number'), frame: $('number-frame'),
        soldBy: $('soldby'), soldTo: $('soldto'), soldToWrap: $('meta-soldto'), ribbon: $('ribbon'),
        list: $('winners-list'), empty: $('winners-empty'), scroll: $('winners-scroll'), count: $('winners-count'),
        closingSub: $('closing-sub'), conn: $('conn'), fsBtn: $('fs-btn')
    };

    var roller = new NumberRoller(els.number, { fastInterval: 55, slowInterval: 560, onTick: fitNumber });
    var fx = new Celebration($('fx'));

    var cur = null;            // last applied status
    var phase = null;          // what is on screen
    var revealTicket = null;   // ticket id currently being revealed / shown
    var seenDraws = null;      // draw ids already rendered in the winners list
    var version = null;
    var failures = 0;

    /* ---------------- Layout scaling (1920x1080 stage) ---------------- */
    function scaleStage() {
        var s = Math.min(window.innerWidth / 1920, window.innerHeight / 1080);
        stage.style.transform = 'scale(' + s + ')';
    }
    window.addEventListener('resize', scaleStage);
    scaleStage();

    /* ---------------- Helpers ---------------- */
    function text(el, v) { el.textContent = v === null || v === undefined ? '' : String(v); }

    function fitNumber(value) {
        var len = String(value || '').length || 4;
        var size = Math.min(270, Math.floor(1060 / (len * 0.68)));
        els.number.style.fontSize = size + 'px';
    }

    function setStageClass(name) {
        var keepPop = stage.classList.contains('pop');
        stage.className = 'state-' + name + (keepPop ? ' pop' : '');
    }

    function pop() {
        stage.classList.remove('pop');
        void stage.offsetWidth; // restart animation
        stage.classList.add('pop');
        setTimeout(function () { stage.classList.remove('pop'); }, 1000);
    }

    function setPrize(label, prize) {
        text(els.prizeLabel, label);
        text(els.prizeNumber, prize ? prize.number : '—');
        text(els.prizeName, prize ? prize.name : '');
    }

    function setMeta(soldBy, soldTo) {
        text(els.soldBy, soldBy || '');
        text(els.soldTo, soldTo || '');
        els.soldToWrap.classList.toggle('hidden', !soldTo);
    }

    function celebrate(big) {
        var r = els.frame.getBoundingClientRect();
        var cy = r.top + r.height / 2;
        fx.burst(r.left + r.width * 0.15, cy, big ? 120 : 60);
        fx.burst(r.left + r.width * 0.85, cy, big ? 120 : 60);
        if (big) {
            setTimeout(function () { fx.burst(r.left + r.width / 2, r.top, 140); }, 250);
            fx.rain(90);
        }
    }

    /* ---------------- Rendering ---------------- */
    function renderWinners(s, animateNew) {
        var winners = s.winners || [];
        var ids = {};
        els.list.innerHTML = '';
        winners.forEach(function (w) {
            ids[w.draw_id] = true;
            var li = document.createElement('li');
            if (animateNew && seenDraws && !seenDraws[w.draw_id]) {
                li.className = 'is-new';
            }
            var a = document.createElement('span'); a.className = 'w-prize'; a.textContent = w.prize_number;
            var b = document.createElement('span'); b.className = 'w-number'; b.textContent = w.lottery_number;
            var c = document.createElement('span'); c.className = 'w-by'; c.textContent = w.sold_by;
            li.appendChild(a); li.appendChild(b); li.appendChild(c);
            els.list.appendChild(li);
        });
        seenDraws = ids;
        els.empty.style.display = winners.length ? 'none' : 'block';
        text(els.count, s.stats.prizes_drawn + ' / ' + s.stats.prizes_total);
        els.scroll.scrollTop = 0;
    }

    function showWaiting(s) {
        roller.halt();
        phase = 'waiting';
        revealTicket = null;
        setStageClass('waiting');
        setPrize('NEXT PRIZE', s.next_prize);
        text(els.caption, 'LUCKY NUMBER');
        els.number.style.fontSize = '200px';
        text(els.number, '? ? ? ?');
        setMeta('', '');
        text(els.ribbon, s.next_prize ? '✦  GET READY! THE DRAW BEGINS SHORTLY  ✦' : '');
    }

    function showLastWinner(s, justConfirmed) {
        var w = s.last_winner;
        roller.halt();
        phase = 'confirmed';
        revealTicket = null;
        setStageClass('confirmed');
        setPrize('WINNER · PRIZE NO.', { number: w.prize_number, name: w.prize_name });
        text(els.caption, 'WINNING NUMBER');
        fitNumber(w.lottery_number);
        text(els.number, w.lottery_number);
        setMeta(w.sold_by, w.sold_to);
        if (s.all_drawn) {
            text(els.ribbon, '✨ ALL PRIZES DRAWN · CONGRATULATIONS TO ALL WINNERS! ✨');
        } else if (s.next_prize) {
            text(els.ribbon, 'UP NEXT: PRIZE NO. ' + s.next_prize.number + '  ·  ' + s.next_prize.name);
        } else {
            text(els.ribbon, '');
        }
        if (justConfirmed) {
            fx.rain(70);
        }
    }

    function showRolling(s) {
        setPrize('PRIZE NO.', s.current_prize);
        text(els.caption, 'DRAWING…');
        text(els.ribbon, '✦  DRAWING THE LUCKY NUMBER…  ✦');
        setMeta('', '');
        roller.setPool(s.roll_pool);
        if (phase !== 'rolling') {
            phase = 'rolling';
            revealTicket = null;
            setStageClass('rolling');
            fx.clear();
            roller.start();
        }
    }

    function showReveal(s, quick) {
        var w = s.winner;
        setPrize('PRIZE NO.', s.current_prize);
        if (revealTicket === w.ticket_id && (phase === 'revealing' || phase === 'revealed')) {
            return; // already revealing/revealed this ticket
        }
        revealTicket = w.ticket_id;
        phase = 'revealing';
        setStageClass('rolling');
        text(els.caption, 'DRAWING…');
        text(els.ribbon, '✦  AND THE WINNER IS…  ✦');
        setMeta('', '');
        roller.setPool(s.roll_pool);
        roller.start();
        roller.stopAt(w.lottery_number, quick ? 1400 : 3400).then(function (finished) {
            if (!finished || phase !== 'revealing' || revealTicket !== w.ticket_id) {
                return;
            }
            phase = 'revealed';
            setStageClass('revealed');
            fitNumber(w.lottery_number);
            text(els.caption, '🎉  WINNER  🎉');
            setMeta(w.sold_by, w.sold_to);
            text(els.ribbon, 'CONGRATULATIONS!  ·  PRIZE NO. ' + s.current_prize.number + '  ·  ' + s.current_prize.name);
            pop();
            celebrate(true);
        });
    }

    function apply(s, isInitial) {
        var prev = cur;
        cur = s;
        version = s.version;

        var ev = s.event || {};
        text(els.sub, [ev.name && ev.name.toUpperCase() !== 'DIWALI LOTTERY DRAW' ? ev.name : '', ev.date, ev.venue]
            .filter(Boolean).join('  ·  '));

        var newConfirm = !isInitial && prev && s.last_draw_id && s.last_draw_id !== prev.last_draw_id;
        renderWinners(s, !isInitial);

        if (s.state === 'ENDED') {
            roller.halt();
            if (s.last_winner) {
                showLastWinner(s, false);
            }
            phase = 'ended';
            setStageClass('ended');
            text(els.closingSub, s.stats.winners_total
                ? 'Congratulations to all ' + s.stats.winners_total + ' winners!'
                : 'Thank you for participating in the Diwali Lottery Draw');
            if (!isInitial && prev && prev.state !== 'ENDED') {
                fx.rain(140);
            }
            return;
        }

        if (s.state === 'ROLLING') {
            showRolling(s);
        } else if (s.state === 'REVEALED' && s.winner) {
            showReveal(s, isInitial || !prev || (prev.state !== 'ROLLING' && prev.state !== 'REVEALED'));
        } else if (s.state === 'REVEALED') {
            showRolling(s);
        } else if (s.last_winner) {
            showLastWinner(s, newConfirm);
        } else {
            showWaiting(s);
        }
    }

    /* ---------------- Polling ---------------- */
    function poll() {
        var url = cfg.statusUrl + (version !== null ? '?since=' + encodeURIComponent(version) : '');
        var ctrl = window.AbortController ? new AbortController() : null;
        var to = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 6000);
        fetch(url, { cache: 'no-store', signal: ctrl ? ctrl.signal : undefined })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (s) {
                failures = 0;
                els.conn.classList.remove('show');
                if (s && s.ok && s.changed !== false) {
                    apply(s, cur === null);
                }
            })
            .catch(function () {
                failures++;
                if (failures >= 3) {
                    els.conn.classList.add('show');
                }
            })
            .then(function () {
                clearTimeout(to);
                setTimeout(poll, failures ? Math.min(5000, cfg.pollMs * (1 + failures)) : cfg.pollMs);
            });
    }

    /* ---------------- Auto-scroll winners if they overflow ---------------- */
    (function autoScroll() {
        var pauseUntil = 0;
        setInterval(function () {
            var el = els.scroll;
            var max = el.scrollHeight - el.clientHeight;
            var now = Date.now();
            if (max <= 2 || now < pauseUntil) { return; }
            if (el.scrollTop >= max - 1) {
                pauseUntil = now + 3000;
                setTimeout(function () { el.scrollTop = 0; pauseUntil = Date.now() + 4000; }, 3000);
            } else {
                el.scrollTop += 1;
            }
        }, 40);
    })();

    /* ---------------- Full screen, cursor, wake lock ---------------- */
    function toggleFullscreen() {
        if (!document.fullscreenElement) {
            var p = document.documentElement.requestFullscreen && document.documentElement.requestFullscreen();
            if (p && p.catch) { p.catch(function () {}); }
        } else if (document.exitFullscreen) {
            document.exitFullscreen();
        }
    }
    els.fsBtn.addEventListener('click', toggleFullscreen);
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

    var wakeLock = null;
    function requestWakeLock() {
        if (navigator.wakeLock && document.visibilityState === 'visible') {
            navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; }).catch(function () {});
        }
    }
    document.addEventListener('visibilitychange', requestWakeLock);
    requestWakeLock();

    /* ---------------- Start ---------------- */
    if (cfg.initial && cfg.initial.ok) {
        apply(cfg.initial, true);
    } else {
        els.conn.classList.add('show');
    }
    setTimeout(poll, cfg.pollMs);
})();
