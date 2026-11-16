/* Admin Live Draw control panel. Server is the source of truth; this page just renders state. */
(function () {
    'use strict';

    var cfg = window.LOTTERY;
    var status = cfg.initial;
    var busy = false;
    var pollTimer = null;
    var failures = 0;

    var $ = function (id) { return document.getElementById(id); };
    var els = {
        badge: $('state-badge'), conn: $('conn-indicator'),
        prizeLabel: $('prize-label'), prizeNumber: $('prize-number'), prizeName: $('prize-name'), prizeDesc: $('prize-desc'),
        numberLabel: $('number-label'), number: $('stage-number'), meta: $('stage-meta'), message: $('stage-message'),
        start: $('btn-start'), reveal: $('btn-reveal'), confirm: $('btn-confirm'), cancel: $('btn-cancel'),
        resume: $('btn-resume'), report: $('btn-report'), display: $('btn-display'), end: $('btn-end'),
        confirmFinal: $('btn-confirm-final'), winners: $('winners-body')
    };
    var roller = new NumberRoller(els.number, { fastInterval: 60 });
    var confirmModal = new bootstrap.Modal($('confirmModal'));
    var askModal = new bootstrap.Modal($('askModal'));
    var askHandler = null;

    var STATE_TEXT = {
        IDLE: ['secondary', 'Ready'],
        ROLLING: ['warning', 'Rolling…'],
        REVEALED: ['info', 'Winner revealed – awaiting confirmation'],
        ENDED: ['dark', 'Lottery ended'],
        DONE: ['success', 'All prizes drawn']
    };

    function show(el, visible) {
        el.classList.toggle('d-none', !visible);
    }

    function text(el, value) {
        el.textContent = value === null || value === undefined ? '' : String(value);
    }

    function effectiveState(s) {
        if (s.state === 'IDLE' && s.all_drawn) {
            return 'DONE';
        }
        return s.state;
    }

    function render(s) {
        status = s;
        var st = effectiveState(s);
        var badge = STATE_TEXT[st] || STATE_TEXT.IDLE;
        els.badge.className = 'badge fs-6 text-bg-' + badge[0];
        text(els.badge, badge[1]);

        var prize = (s.state === 'ROLLING' || s.state === 'REVEALED') ? s.current_prize : s.next_prize;
        text(els.prizeLabel, (s.state === 'ROLLING' || s.state === 'REVEALED') ? 'Now Drawing' : (prize ? 'Next Prize' : 'Prizes'));
        text(els.prizeNumber, prize ? prize.number : '✓');
        text(els.prizeName, prize ? prize.name : 'All prizes drawn');
        text(els.prizeDesc, prize ? prize.description : '');

        els.number.classList.remove('is-rolling', 'is-revealed');
        if (s.state === 'ROLLING') {
            roller.setPool(s.roll_pool);
            roller.start();
            els.number.classList.add('is-rolling');
            text(els.numberLabel, 'Rolling on LED screen…');
            text(els.meta, ' ');
            text(els.message, 'Press REVEAL WINNER when the audience is ready.');
        } else if (s.state === 'REVEALED' && s.winner) {
            roller.halt(s.winner.lottery_number);
            els.number.classList.add('is-revealed');
            text(els.numberLabel, 'Revealed Winning Number (not saved yet)');
            text(els.meta, 'Sold By: ' + s.winner.sold_by + (s.winner.sold_to ? '   ·   Sold To: ' + s.winner.sold_to : ''));
            text(els.message, 'Verify the ticket, then CONFIRM WINNER to save it permanently, or CANCEL DRAW to discard.');
        } else {
            roller.halt(s.last_winner ? s.last_winner.lottery_number : '----');
            if (s.last_winner) {
                text(els.numberLabel, 'Last Confirmed Winner – Prize ' + s.last_winner.prize_number);
                text(els.meta, 'Sold By: ' + s.last_winner.sold_by + (s.last_winner.sold_to ? '   ·   Sold To: ' + s.last_winner.sold_to : ''));
            } else {
                text(els.numberLabel, 'Winning Lottery Number');
                text(els.meta, ' ');
            }
            if (s.state === 'ENDED') {
                text(els.message, s.all_drawn ? 'The lottery is complete. Download the final winners report.'
                    : 'The lottery was ended with ' + s.stats.prizes_remaining + ' prize(s) remaining. Resume to continue drawing.');
            } else if (s.all_drawn) {
                text(els.message, 'All ' + s.stats.prizes_total + ' prizes have been drawn. Press END LOTTERY, then download the final report.');
            } else if (s.stats.tickets_available === 0) {
                text(els.message, 'No eligible tickets left to draw. Upload tickets first.');
            } else {
                text(els.message, 'Ready to draw Prize No. ' + (s.next_prize ? s.next_prize.number : '') + '. Press START DRAW.');
            }
        }

        // Buttons for each state
        var canStart = s.state === 'IDLE' && !s.all_drawn && s.stats.tickets_available > 0;
        show(els.start, canStart);
        show(els.reveal, s.state === 'ROLLING');
        show(els.confirm, s.state === 'REVEALED');
        show(els.cancel, s.state === 'ROLLING' || s.state === 'REVEALED');
        show(els.end, s.state === 'IDLE');
        show(els.resume, s.state === 'ENDED' && !s.all_drawn);
        show(els.report, s.all_drawn || s.state === 'ENDED');
        [els.start, els.reveal, els.confirm, els.cancel, els.end, els.resume].forEach(function (b) { b.disabled = busy; });

        // Stats
        var stats = s.stats;
        text($('st-tickets'), stats.tickets_total.toLocaleString());
        text($('st-available'), stats.tickets_available.toLocaleString());
        text($('st-prizes'), stats.prizes_total);
        text($('st-drawn'), stats.prizes_drawn);
        text($('st-remaining'), stats.prizes_remaining);
        text($('st-winners'), stats.winners_total);
        text($('st-next'), s.next_prize ? 'No. ' + s.next_prize.number : '—');
        text($('st-state'), badge[1]);
        $('st-progress').style.width = (stats.prizes_total ? (100 * stats.prizes_drawn / stats.prizes_total) : 0) + '%';

        // Winners
        els.winners.innerHTML = '';
        if (!s.winners.length) {
            var tr = document.createElement('tr');
            var td = document.createElement('td');
            td.colSpan = 4;
            td.className = 'text-center text-muted py-4';
            td.textContent = 'No winners yet.';
            tr.appendChild(td);
            els.winners.appendChild(tr);
        }
        s.winners.forEach(function (w) {
            var row = document.createElement('tr');
            [w.prize_number, w.lottery_number, w.sold_by, w.sold_to || '—'].forEach(function (v, i) {
                var c = document.createElement('td');
                c.textContent = v;
                if (i === 1) {
                    c.className = 'fw-bold font-monospace';
                }
                row.appendChild(c);
            });
            els.winners.appendChild(row);
        });
    }

    function setBusy(b) {
        busy = b;
        render(status);
    }

    function act(endpoint, data, onSuccess) {
        if (busy) {
            return;
        }
        setBusy(true);
        App.post(cfg.api + endpoint, data || {}).then(function (res) {
            busy = false;
            if (res.sessionExpired) {
                App.toast(res.error, 'danger');
                setTimeout(function () { window.location.reload(); }, 1500);
                return;
            }
            if (res.status && res.status.changed !== false) {
                render(res.status);
            } else {
                render(status);
            }
            if (res.ok) {
                App.toast(res.message || 'Done', 'success');
                if (onSuccess) {
                    onSuccess(res);
                }
            } else {
                App.toast(res.error || 'Something went wrong.', 'danger');
            }
        });
    }

    function ask(title, bodyText, yesLabel, handler) {
        text($('askModalTitle'), title);
        text($('askModalBody'), bodyText);
        text($('askModalYes'), yesLabel);
        askHandler = handler;
        askModal.show();
    }

    $('askModalYes').addEventListener('click', function () {
        askModal.hide();
        if (askHandler) {
            var h = askHandler;
            askHandler = null;
            h();
        }
    });

    els.start.addEventListener('click', function () { act('draw-start.php'); });
    els.reveal.addEventListener('click', function () { act('draw-reveal.php'); });

    els.confirm.addEventListener('click', function () {
        if (status.state !== 'REVEALED' || !status.winner || !status.current_prize) {
            return;
        }
        text($('cm-prize'), 'No. ' + status.current_prize.number + ' – ' + status.current_prize.name);
        text($('cm-number'), status.winner.lottery_number);
        text($('cm-soldby'), status.winner.sold_by);
        text($('cm-soldto'), status.winner.sold_to || '—');
        els.confirmFinal.dataset.prize = status.current_prize.id;
        els.confirmFinal.dataset.ticket = status.winner.ticket_id;
        confirmModal.show();
    });

    els.confirmFinal.addEventListener('click', function () {
        confirmModal.hide();
        act('draw-confirm.php', { prize_id: els.confirmFinal.dataset.prize, ticket_id: els.confirmFinal.dataset.ticket });
    });

    els.cancel.addEventListener('click', function () {
        ask('Cancel this draw?', 'The current draw will be discarded and nothing will be saved. The same prize can then be drawn again.',
            'Yes, cancel draw', function () { act('draw-cancel.php'); });
    });

    els.end.addEventListener('click', function () {
        var remaining = status.stats.prizes_remaining;
        var msg = remaining > 0
            ? 'There are still ' + remaining + ' prize(s) not drawn. End the lottery anyway? You can resume later.'
            : 'All prizes are drawn. End the lottery and show the closing screen on the LED display?';
        ask('End the lottery?', msg, 'Yes, end lottery', function () { act('draw-end.php', { action: 'end' }); });
    });

    els.resume.addEventListener('click', function () { act('draw-end.php', { action: 'resume' }); });

    // Poll to stay in sync (other admin tabs, page reloads, LED display state).
    function poll() {
        fetch(cfg.api + 'live-status.php?since=' + encodeURIComponent(status.version), { cache: 'no-store', credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (s) {
                failures = 0;
                els.conn.className = 'small text-success';
                els.conn.innerHTML = '<i class="bi bi-circle-fill"></i> Live';
                if (s.ok && s.changed !== false && !busy) {
                    render(s);
                }
            })
            .catch(function () {
                failures++;
                if (failures >= 2) {
                    els.conn.className = 'small text-danger';
                    els.conn.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i> Connection lost – retrying';
                }
            })
            .then(function () { pollTimer = setTimeout(poll, 2000); });
    }

    render(status);
    pollTimer = setTimeout(poll, 2000);
})();
