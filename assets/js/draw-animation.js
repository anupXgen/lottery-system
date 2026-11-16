/*
 * Draw animation helpers shared by the LED display and the admin panel.
 *  - NumberRoller: rolling-number effect that can decelerate onto a final value.
 *  - Celebration: lightweight canvas confetti / sparkle burst (auto-stops).
 * The real winner is always chosen by the server; these are purely visual.
 */
(function (global) {
    'use strict';

    function NumberRoller(el, options) {
        options = options || {};
        this.el = el;
        this.pool = [];
        this.running = false;
        this.mode = 'idle';
        this.fastInterval = options.fastInterval || 55;
        this.slowInterval = options.slowInterval || 520;
        this.onTick = options.onTick || null;
        this._raf = null;
        this._last = 0;
        this._final = null;
        this._resolve = null;
        this._loop = this._loop.bind(this);
    }

    NumberRoller.prototype.setPool = function (pool) {
        this.pool = Array.isArray(pool) ? pool.filter(function (x) { return x !== null && x !== ''; }) : [];
    };

    NumberRoller.prototype._pick = function () {
        var current = this.el.textContent;
        if (this.pool.length > 1) {
            var v;
            var guard = 0;
            do {
                v = this.pool[Math.floor(Math.random() * this.pool.length)];
            } while (v === current && guard++ < 5);
            return v;
        }
        if (this.pool.length === 1 && Math.random() < 0.5) {
            return this.pool[0];
        }
        var len = (this._final || this.pool[0] || '0000').length;
        var s = '';
        for (var i = 0; i < len; i++) {
            s += Math.floor(Math.random() * 10);
        }
        return s;
    };

    NumberRoller.prototype._set = function (value) {
        this.el.textContent = value;
        if (this.onTick) {
            this.onTick(value);
        }
    };

    /** Start fast rolling (no-op if already rolling). */
    NumberRoller.prototype.start = function () {
        if (this.running) {
            if (this.mode === 'slow') {
                return; // already stopping
            }
            return;
        }
        this.running = true;
        this.mode = 'fast';
        this._final = null;
        this._last = 0;
        this._raf = requestAnimationFrame(this._loop);
    };

    /**
     * Decelerate and land on `finalValue` over `duration` ms. Returns a Promise.
     */
    NumberRoller.prototype.stopAt = function (finalValue, duration) {
        var self = this;
        duration = duration || 3000;
        if (!this.running) {
            this.start();
        }
        if (this._resolve) {
            this._resolve(false);
        }
        this.mode = 'slow';
        this._final = String(finalValue);
        this._slowStart = performance.now();
        this._slowDuration = duration;
        // Safety net: land on the final value even if animation frames are throttled
        // (background tab, busy GPU, headless browser).
        clearTimeout(this._stopTimer);
        this._stopTimer = setTimeout(function () { self._finish(); }, duration + 250);
        return new Promise(function (resolve) { self._resolve = resolve; });
    };

    NumberRoller.prototype._finish = function () {
        if (this.mode !== 'slow') {
            return;
        }
        clearTimeout(this._stopTimer);
        this.running = false;
        this.mode = 'idle';
        if (this._raf) {
            cancelAnimationFrame(this._raf);
            this._raf = null;
        }
        this._set(this._final);
        var r = this._resolve;
        this._resolve = null;
        if (r) {
            r(true);
        }
    };

    /** Stop immediately and show a value (or leave the current text). */
    NumberRoller.prototype.halt = function (value) {
        clearTimeout(this._stopTimer);
        this.running = false;
        this.mode = 'idle';
        if (this._raf) {
            cancelAnimationFrame(this._raf);
            this._raf = null;
        }
        if (this._resolve) {
            var r = this._resolve;
            this._resolve = null;
            r(false);
        }
        if (value !== undefined && value !== null) {
            this._set(String(value));
        }
    };

    NumberRoller.prototype._loop = function () {
        if (!this.running) {
            return;
        }
        var now = performance.now(); // same clock as _slowStart
        var interval = this.fastInterval;
        if (this.mode === 'slow') {
            var t = (now - this._slowStart) / this._slowDuration;
            if (t >= 1) {
                this._finish();
                return;
            }
            // ease-in: ticks get slower and slower
            interval = this.fastInterval + (this.slowInterval - this.fastInterval) * t * t * t;
        }
        if (now - this._last >= interval) {
            this._last = now;
            this._set(this._pick());
        }
        this._raf = requestAnimationFrame(this._loop);
    };

    /* ------------------------------------------------------------------ */

    function Celebration(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.particles = [];
        this._raf = null;
        this._tick = this._tick.bind(this);
        this._resize = this._resize.bind(this);
        window.addEventListener('resize', this._resize);
        this._resize();
    }

    Celebration.prototype._resize = function () {
        var dpr = Math.min(window.devicePixelRatio || 1, 1.5);
        this.dpr = dpr;
        this.w = this.canvas.clientWidth || window.innerWidth;
        this.h = this.canvas.clientHeight || window.innerHeight;
        this.canvas.width = Math.round(this.w * dpr);
        this.canvas.height = Math.round(this.h * dpr);
        this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    };

    var COLORS = ['#ffd54f', '#ffb300', '#ff8f00', '#ff6f00', '#ff5252', '#ff4081', '#fff8e1', '#ffe082'];

    /** Burst from (x, y) in CSS pixels relative to the canvas. */
    Celebration.prototype.burst = function (x, y, count) {
        count = count || 140;
        for (var i = 0; i < count; i++) {
            var angle = Math.random() * Math.PI * 2;
            var speed = 4 + Math.random() * 10;
            this.particles.push({
                x: x, y: y,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed - 4,
                size: 5 + Math.random() * 8,
                color: COLORS[(Math.random() * COLORS.length) | 0],
                rot: Math.random() * Math.PI,
                vr: (Math.random() - 0.5) * 0.3,
                life: 0,
                maxLife: 110 + Math.random() * 70,
                shape: Math.random() < 0.35 ? 'spark' : 'rect'
            });
        }
        if (!this._raf) {
            this._raf = requestAnimationFrame(this._tick);
        }
    };

    /** Confetti falling from the top across the width. */
    Celebration.prototype.rain = function (count) {
        count = count || 120;
        for (var i = 0; i < count; i++) {
            this.particles.push({
                x: Math.random() * this.w, y: -20 - Math.random() * this.h * 0.4,
                vx: (Math.random() - 0.5) * 2,
                vy: 2 + Math.random() * 3,
                size: 6 + Math.random() * 8,
                color: COLORS[(Math.random() * COLORS.length) | 0],
                rot: Math.random() * Math.PI,
                vr: (Math.random() - 0.5) * 0.2,
                life: 0,
                maxLife: 220 + Math.random() * 80,
                shape: 'rect'
            });
        }
        if (!this._raf) {
            this._raf = requestAnimationFrame(this._tick);
        }
    };

    Celebration.prototype.clear = function () {
        this.particles = [];
        this.ctx.clearRect(0, 0, this.w, this.h);
    };

    Celebration.prototype._tick = function () {
        var ctx = this.ctx;
        ctx.clearRect(0, 0, this.w, this.h);
        var alive = [];
        for (var i = 0; i < this.particles.length; i++) {
            var p = this.particles[i];
            p.life++;
            p.vy += 0.18;
            p.vx *= 0.99;
            p.vy *= 0.99;
            p.x += p.vx;
            p.y += p.vy;
            p.rot += p.vr;
            if (p.life > p.maxLife || p.y > this.h + 40) {
                continue;
            }
            var alpha = Math.max(0, 1 - p.life / p.maxLife);
            ctx.globalAlpha = alpha;
            ctx.fillStyle = p.color;
            if (p.shape === 'spark') {
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.size / 2.5, 0, Math.PI * 2);
                ctx.fill();
            } else {
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.rot);
                ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
                ctx.restore();
            }
            alive.push(p);
        }
        ctx.globalAlpha = 1;
        this.particles = alive;
        if (alive.length) {
            this._raf = requestAnimationFrame(this._tick);
        } else {
            this._raf = null;
            ctx.clearRect(0, 0, this.w, this.h);
        }
    };

    global.NumberRoller = NumberRoller;
    global.Celebration = Celebration;
})(window);
