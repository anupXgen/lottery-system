/* Housie admin Live Number Draw. The server picks every number; this page only animates it. */
(function () {
    'use strict';

    var cfg = window.HOUSIE;
    if (!cfg || !document.getElementById('hz-ball')) {
        return;
    }
    var $ = function (id) { return document.getElementById(id); };
    var status = cfg.initial;
    var busy = false;
    var animating = false;
    var failures = 0;
    var ball = $('hz-ball');
    var roller = new NumberRoller(ball, { fastInterval: 60, slowInterval: 380 });
    var askModal = new bootstrap.Modal($('hzAsk'));
    var askHandler = null;
    var btn = { start: $('hz-start'), call: $('hz-call'), pause: $('hz-pause'), resume: $('hz-resume'), end: $('hz-end') };

    // Board cells 1..90
    var board = $('hz-board');
    var cells = {};
    for (var n = 1; n <= 90; n++) {
        var s = document.createElement('span');
        s.textContent = n;
        board.appendChild(s);
        cells[n] = s;
    }

    function text(el, v) { el.textContent = v === null || v === undefined ? '' : String(v); }
    function show(el, v) { el.classList.toggle('d-none', !v); }

    var STATE = { SETUP: 'Setup', RUNNING: 'Running', PAUSED: 'Paused', ENDED: 'Ended' };

    function render(s) {
        status = s;
        if (!s.game) {
            return;
        }
        var st = s.game.status;
        var badge = $('hz-state');
        badge.className = 'badge fs-6 hz-state-' + st;
        text(badge, STATE[st] || st);

        if (!animating) {
            roller.halt();
            ball.classList.remove('rolling');
            ball.classList.toggle('idle', s.current_number === null);
            text(ball, s.current_number === null ? '–' : s.current_number);
        }
        text($('hz-count'), s.calls_count);
        text($('hz-remaining'), s.remaining);

        var prev = $('hz-prev');
        prev.innerHTML = '';
        (s.previous || []).forEach(function (num) {
            var b = document.createElement('span');
            b.textContent = num;
            prev.appendChild(b);
        });
        if (!s.previous || !s.previous.length) {
            prev.innerHTML = '<span class="text-white-50 border-0 bg-transparent" style="width:auto">—</span>';
        }

        var called = {};
        (s.called || []).forEach(function (num) { called[num] = true; });
        for (var k = 1; k <= 90; k++) {
            cells[k].className = called[k] ? (k === s.current_number && !animating ? 'called current' : 'called') : '';
        }

        var msg = '';
        if (st === 'SETUP') {
            msg = 'Ready. Press START HOUSIE GAME when the audience is ready.';
        } else if (st === 'RUNNING') {
            msg = s.remaining > 0 ? 'Press CALL NEXT NUMBER to call the next number.' : 'All 90 numbers have been called. Verify remaining claims, then END GAME.';
        } else if (st === 'PAUSED') {
            msg = 'Game paused – verify claims, then RESUME GAME.';
        } else if (st === 'ENDED') {
            msg = 'This game has ended. Download the Housie report.';
        }
        text($('hz-message'), msg);

        show(btn.start, st === 'SETUP');
        show(btn.call, st === 'RUNNING' && s.remaining > 0);
        show(btn.pause, st === 'RUNNING');
        show(btn.resume, st === 'PAUSED');
        show(btn.end, st === 'RUNNING' || st === 'PAUSED');
        Object.keys(btn).forEach(function (k) { btn[k].disabled = busy; });

        var list = $('hz-prizes');
        list.innerHTML = '';
        (s.prizes || []).forEach(function (p) {
            var li = document.createElement('li');
            li.className = 'list-group-item d-flex justify-content-between align-items-start gap-2';
            var left = document.createElement('div');
            var title = document.createElement('div');
            title.className = 'fw-semibold';
            title.textContent = p.label + ' · ' + p.prize_name;
            left.appendChild(title);
            p.winners.forEach(function (w) {
                var d = document.createElement('div');
                d.className = 'small text-success';
                d.textContent = '✓ Ticket ' + w.ticket + (w.participant ? ' – ' + w.participant : '');
                left.appendChild(d);
            });
            var badgeP = document.createElement('span');
            badgeP.className = 'badge hz-prize-' + p.state;
            badgeP.textContent = (p.state === 'PENDING' ? 'Pending' : p.state === 'CLAIMED' ? 'Claimed' : 'Completed') + ' ' + p.winners.length + '/' + p.winners_allowed;
            li.appendChild(left);
            li.appendChild(badgeP);
            list.appendChild(li);
        });
    }

    function post(endpoint, data) {
        return App.post(cfg.api + endpoint, data || {}).then(function (res) {
            if (res.sessionExpired) {
                App.toast(res.error || 'Session expired', 'danger');
                setTimeout(function () { window.location.reload(); }, 1500);
            }
            return res;
        });
    }

    function act(endpoint, data) {
        if (busy) {
            return;
        }
        busy = true;
        render(status);
        post(endpoint, data).then(function (res) {
            busy = false;
            if (res.status && res.status.changed !== false) {
                render(res.status);
            } else {
                render(status);
            }
            App.toast(res.ok ? res.message : (res.error || 'Something went wrong.'), res.ok ? 'success' : 'danger');
        });
    }

    function callNext() {
        if (busy) {
            return;
        }
        busy = true;
        render(status);
        // roll over the numbers not yet called while the server picks
        var called = {};
        (status.called || []).forEach(function (x) { called[x] = true; });
        var pool = [];
        for (var i = 1; i <= 90; i++) { if (!called[i]) { pool.push(String(i)); } }
        animating = true;
        ball.classList.remove('idle');
        ball.classList.add('rolling');
        roller.setPool(pool);
        roller.start();
        var started = Date.now();
        post('call.php', { expected_count: status.calls_count }).then(function (res) {
            var finish = function () {
                animating = false;
                busy = false;
                ball.classList.remove('rolling');
                if (res.status && res.status.changed !== false) {
                    render(res.status);
                } else {
                    render(status);
                }
                if (!res.ok) {
                    App.toast(res.error || 'Could not call a number.', 'danger');
                }
            };
            if (res.ok && res.result) {
                var wait = Math.max(0, 600 - (Date.now() - started));
                setTimeout(function () {
                    roller.stopAt(res.result.number, 1100).then(finish);
                }, wait);
            } else {
                roller.halt();
                finish();
            }
        });
    }

    function ask(title, body, yes, handler) {
        text($('hzAskTitle'), title);
        text($('hzAskBody'), body);
        text($('hzAskYes'), yes);
        askHandler = handler;
        askModal.show();
    }
    $('hzAskYes').addEventListener('click', function () {
        askModal.hide();
        if (askHandler) { var h = askHandler; askHandler = null; h(); }
    });

    btn.start.addEventListener('click', function () {
        ask('Start the Housie game?', 'Tickets and participant details will be locked and the numbers will be prepared in a secure random order.',
            'Yes, start game', function () { act('start.php'); });
    });
    btn.call.addEventListener('click', callNext);
    btn.pause.addEventListener('click', function () { act('pause.php'); });
    btn.resume.addEventListener('click', function () { act('resume.php'); });
    btn.end.addEventListener('click', function () {
        ask('End this game?', 'No more numbers can be called and no more claims can be confirmed for this game.',
            'Yes, end game', function () { act('end.php'); });
    });

    function poll() {
        fetch(cfg.api + 'live-status.php?since=' + encodeURIComponent(status.token || ''), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (s) {
                failures = 0;
                $('hz-conn').className = 'small text-success';
                $('hz-conn').innerHTML = '<i class="bi bi-circle-fill"></i> Live';
                if (s.ok && s.changed !== false && !busy) {
                    render(s);
                }
            })
            .catch(function () {
                if (++failures >= 2) {
                    $('hz-conn').className = 'small text-danger';
                    $('hz-conn').innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Connection lost – retrying';
                }
            })
            .then(function () { setTimeout(poll, 2000); });
    }

    render(status);
    setTimeout(poll, 2000);
})();
