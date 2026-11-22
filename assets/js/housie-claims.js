/* Housie Claim Verification: check -> show verdict and ticket -> confirm or reject. */
(function () {
    'use strict';

    var cfg = window.HOUSIE_CLAIMS;
    var form = document.getElementById('claim-form');
    if (!cfg || !form) {
        return;
    }
    var resultBox = document.getElementById('claim-result');
    var prizeSel = document.getElementById('claim-prize');
    var ticketIn = document.getElementById('claim-ticket');
    var checkBtn = document.getElementById('claim-check');
    var last = null;

    function el(tag, cls, txt) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (txt !== undefined && txt !== null) { e.textContent = txt; }
        return e;
    }

    function ticketGrid(t, evaluation) {
        var called = {};
        (t.called || []).forEach(function (n) { called[n] = true; });
        var pattern = {};
        (evaluation ? evaluation.cells : []).forEach(function (i) { pattern[i] = true; });
        var wrap = el('div', 'hz-ticket lg');
        var head = el('div', 'hz-ticket-head');
        head.appendChild(el('span', '', 'Ticket ' + t.code));
        head.appendChild(el('span', '', t.participant || ''));
        wrap.appendChild(head);
        var table = el('table');
        for (var r = 0; r < 3; r++) {
            var tr = el('tr');
            for (var c = 0; c < 9; c++) {
                var i = r * 9 + c;
                var n = t.grid[i];
                var cls = [];
                if (!n) { cls.push('blank'); } else if (called[n]) { cls.push('called'); }
                if (pattern[i]) {
                    cls.push('pattern');
                    if (n && !called[n]) { cls.push('missing'); }
                }
                tr.appendChild(el('td', cls.join(' '), n ? String(n) : ''));
            }
            table.appendChild(tr);
        }
        wrap.appendChild(table);
        return wrap;
    }

    function render(res) {
        resultBox.innerHTML = '';
        if (!res.ok) {
            resultBox.appendChild(el('div', 'alert alert-danger mb-0', res.error || 'Could not check the claim.'));
            return;
        }
        var r = res.result;
        last = r;
        var ok = r.can_confirm;
        var verdict = el('div', 'alert hz-verdict mb-3 ' + (ok ? 'alert-success border-success' : 'alert-danger border-danger'));
        var h = el('div', 'fs-3 fw-bold');
        h.textContent = ok ? '✓ VALID CLAIM' : (r.valid ? '⚠ CANNOT CONFIRM' : '✗ INVALID CLAIM');
        verdict.appendChild(h);
        verdict.appendChild(el('div', 'fw-semibold', r.prize.label + ' – ' + r.prize.prize_name + '  ·  Ticket ' + (r.ticket ? r.ticket.code : r.ticket_ref)
            + (r.ticket && r.ticket.participant ? '  ·  ' + r.ticket.participant : '')));
        if (r.evaluation) {
            verdict.appendChild(el('div', 'mt-1', r.evaluation.reason));
        }
        if (r.problems.length) {
            var ul = el('ul', 'mb-0 mt-2');
            r.problems.forEach(function (p) { ul.appendChild(el('li', '', p)); });
            verdict.appendChild(ul);
        }
        verdict.appendChild(el('div', 'small mt-2', 'Numbers called so far: ' + r.calls_count + '  ·  Winners confirmed for this category: '
            + r.prize.confirmed + ' of ' + r.prize.winners_allowed));
        resultBox.appendChild(verdict);

        if (r.ticket) {
            var gridWrap = el('div', 'mb-3 text-center');
            gridWrap.appendChild(ticketGrid(r.ticket, r.evaluation));
            var legend = el('div', 'small text-muted mt-2', 'Gold = called · Green = pattern number called · Red = pattern number not yet called');
            gridWrap.appendChild(legend);
            resultBox.appendChild(gridWrap);
        }

        var actions = el('div', 'd-flex flex-wrap gap-2');
        if (ok) {
            var confirmBtn = el('button', 'btn btn-success btn-lg');
            confirmBtn.type = 'button';
            confirmBtn.innerHTML = '<i class="bi bi-check2-circle"></i> ';
            confirmBtn.appendChild(document.createTextNode('Confirm winner'));
            confirmBtn.addEventListener('click', function () { confirmClaim(confirmBtn); });
            actions.appendChild(confirmBtn);
        }
        var rejectBtn = el('button', 'btn btn-outline-danger btn-lg');
        rejectBtn.type = 'button';
        rejectBtn.innerHTML = '<i class="bi bi-x-octagon"></i> ';
        rejectBtn.appendChild(document.createTextNode('Reject claim'));
        rejectBtn.addEventListener('click', function () { rejectClaim(rejectBtn); });
        actions.appendChild(rejectBtn);
        resultBox.appendChild(actions);
    }

    function done(res) {
        if (res.ok) {
            App.toast(res.message, 'success');
            setTimeout(function () { window.location.reload(); }, 1200);
        } else {
            App.toast(res.error || 'Something went wrong.', 'danger');
        }
    }

    function confirmClaim(button) {
        if (!last || !last.ticket) {
            return;
        }
        var who = 'Ticket ' + last.ticket.code + (last.ticket.participant ? ' (' + last.ticket.participant + ')' : '');
        if (!window.confirm('Confirm ' + who + ' as a winner of ' + last.prize.label + ' – ' + last.prize.prize_name + '?\n\nThis is saved permanently and shown on the LED screen.')) {
            return;
        }
        button.disabled = true;
        App.post(cfg.api + 'claim-confirm.php', { game_id: cfg.gameId, prize_id: last.prize.id, ticket: last.ticket.code }).then(done);
    }

    function rejectClaim(button) {
        if (!last) {
            return;
        }
        var note = window.prompt('Reject this claim? Optional note for the claims log:', '');
        if (note === null) {
            return;
        }
        button.disabled = true;
        App.post(cfg.api + 'claim-reject.php', { game_id: cfg.gameId, prize_id: last.prize.id, ticket: last.ticket ? last.ticket.code : last.ticket_ref, note: note }).then(done);
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!prizeSel.value || !ticketIn.value.trim()) {
            return;
        }
        checkBtn.disabled = true;
        App.post(cfg.api + 'claim-check.php', { prize_id: prizeSel.value, ticket: ticketIn.value.trim() }).then(function (res) {
            checkBtn.disabled = false;
            render(res);
        });
    });
})();

/* Manual winner entry (physical tickets verified by hand in the room). */
(function () {
    'use strict';

    var cfg = window.HOUSIE_CLAIMS;
    var form = document.getElementById('manual-form');
    if (!cfg || !form) {
        return;
    }
    var prize = document.getElementById('manual-prize');
    var ticket = document.getElementById('manual-ticket');
    var name = document.getElementById('manual-name');
    var note = document.getElementById('manual-note');
    var save = document.getElementById('manual-save');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!prize.value || !ticket.value.trim()) {
            return;
        }
        var label = prize.options[prize.selectedIndex].text.split(' (')[0];
        var who = 'Ticket ' + ticket.value.trim() + (name.value.trim() ? ' – ' + name.value.trim() : '');
        if (!window.confirm('Save ' + who + ' as a winner of ' + label + '?\n\nOnly do this after checking the ticket against the called numbers. It is shown on the LED screen immediately.')) {
            return;
        }
        save.disabled = true;
        App.post(cfg.api + 'claim-manual.php', {
            game_id: cfg.gameId, prize_id: prize.value, ticket: ticket.value.trim(), name: name.value.trim(), note: note.value.trim()
        }).then(function (res) {
            if (res.ok) {
                App.toast(res.message, 'success');
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                save.disabled = false;
                App.toast(res.error || 'Could not save the winner.', 'danger');
            }
        });
    });
})();
