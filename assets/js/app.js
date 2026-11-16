/* Admin-wide helpers: CSRF-aware POST and toast notifications. */
(function () {
    'use strict';

    var meta = document.querySelector('meta[name="csrf-token"]');
    var csrf = meta ? meta.getAttribute('content') : '';

    function ensureToastHost() {
        var host = document.getElementById('toast-host');
        if (!host) {
            host = document.createElement('div');
            host.id = 'toast-host';
            host.className = 'toast-container position-fixed top-0 end-0 p-3';
            host.style.zIndex = 2000;
            document.body.appendChild(host);
        }
        return host;
    }

    function toast(message, type) {
        type = type || 'success';
        var host = ensureToastHost();
        var el = document.createElement('div');
        el.className = 'toast align-items-center border-0 text-bg-' + type;
        el.setAttribute('role', type === 'danger' ? 'alert' : 'status');
        el.setAttribute('aria-live', 'assertive');
        el.setAttribute('aria-atomic', 'true');
        var flex = document.createElement('div');
        flex.className = 'd-flex';
        var body = document.createElement('div');
        body.className = 'toast-body fs-6';
        body.textContent = message;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn-close btn-close-white me-2 m-auto';
        btn.setAttribute('data-bs-dismiss', 'toast');
        btn.setAttribute('aria-label', 'Close');
        flex.appendChild(body);
        flex.appendChild(btn);
        el.appendChild(flex);
        host.appendChild(el);
        var t = new bootstrap.Toast(el, { delay: type === 'danger' ? 7000 : 3500 });
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        t.show();
    }

    /** POST form data with the CSRF token; always resolves to a JSON object. */
    function post(url, data) {
        var fd = new FormData();
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        fd.append('csrf_token', csrf);
        return fetch(url, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' }
        }).then(function (res) {
            return res.json().catch(function () {
                return { ok: false, error: 'Unexpected server response (HTTP ' + res.status + ').' };
            }).then(function (json) {
                if (res.status === 401) {
                    json.sessionExpired = true;
                }
                return json;
            });
        }).catch(function () {
            return { ok: false, error: 'Network error: could not reach the server. Check the connection and try again.' };
        });
    }

    window.App = { post: post, toast: toast, csrf: csrf };
})();
