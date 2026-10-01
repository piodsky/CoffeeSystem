/**
 * EXECOM Logistics POS — Reports: net-sales column chart, tooltips, print.
 * The chart is drawn as SVG at the container's real width (so text never scales) and
 * redrawn on resize. Values come from a JSON <script> block; all text goes in via textContent.
 * Every value is also in the "Show as table" view, so the tooltip only adds convenience.
 */
(function () {
    'use strict';

    const SVG = 'http://www.w3.org/2000/svg';
    const tip = document.getElementById('chartTip');

    // ------------------------------------------------------------------
    // Print
    // ------------------------------------------------------------------
    document.getElementById('printReport')?.addEventListener('click', () => window.print());

    // ------------------------------------------------------------------
    // Tooltip
    // ------------------------------------------------------------------
    function showTip(value, title, note, x, y) {
        if (!tip) return;
        tip.querySelector('.chart-tip__value').textContent = value;
        tip.querySelector('.chart-tip__title').textContent = title;
        tip.querySelector('.chart-tip__note').textContent = note || '';
        tip.hidden = false;
        const w = tip.offsetWidth;
        const h = tip.offsetHeight;
        let left = x + 14;
        let top = y - h - 12;
        if (left + w > window.innerWidth - 8) left = x - w - 14;
        if (top < 8) top = y + 16;
        tip.style.left = `${Math.max(8, left)}px`;
        tip.style.top = `${Math.max(8, top)}px`;
    }
    function hideTip() {
        if (tip) tip.hidden = true;
    }

    // Bar lists: the whole row is the hit target (hover or keyboard focus).
    document.querySelectorAll('.barlist__row').forEach((row) => {
        const show = (x, y) => showTip(row.dataset.tipValue, row.dataset.tipTitle, row.dataset.tipNote, x, y);
        row.addEventListener('pointermove', (e) => show(e.clientX, e.clientY));
        row.addEventListener('pointerleave', hideTip);
        row.addEventListener('focus', () => {
            const r = row.querySelector('.barlist__bar').getBoundingClientRect();
            show(r.left + r.width / 2, r.top);
        });
        row.addEventListener('blur', hideTip);
    });

    // ------------------------------------------------------------------
    // Column chart
    // ------------------------------------------------------------------
    const box = document.getElementById('salesChart');
    const dataEl = document.getElementById('salesSeries');
    if (!box || !dataEl) return;

    const data = JSON.parse(dataEl.textContent);
    const points = data.points;
    const cur = data.currency || '₱';
    const money = (cents) => `${cur} ${(cents / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    const compact = (cents) => {
        const v = cents / 100;
        if (v === 0) return `${cur}0`;
        if (v >= 1e6) return `${cur}${+(v / 1e6).toFixed(v % 1e6 ? 1 : 0)}M`;
        if (v >= 1e3) return `${cur}${+(v / 1e3).toFixed(v % 1e3 ? 1 : 0)}K`;
        return `${cur}${Math.round(v)}`;
    };
    /** Round the axis to clean steps: 1, 2, 2.5 or 5 × 10^n. */
    function niceScale(max, ticks) {
        if (max <= 0) return { step: 100000, top: 400000 };
        const raw = max / ticks;
        const mag = 10 ** Math.floor(Math.log10(raw));
        const norm = raw / mag;
        const step = (norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 2.5 ? 2.5 : norm <= 5 ? 5 : 10) * mag;
        return { step, top: Math.ceil(max / step) * step };
    }
    const el = (name, attrs, parent) => {
        const n = document.createElementNS(SVG, name);
        for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, String(v));
        if (parent) parent.appendChild(n);
        return n;
    };

    let active = -1;
    let geo = null; // layout of the last render, for hit-testing

    function render() {
        const W = Math.max(280, Math.floor(box.clientWidth));
        const H = 280;
        const n = points.length;
        const maxCents = Math.max(0, ...points.map((p) => p.cents));
        const { step, top } = niceScale(maxCents, 4);
        const tickValues = [];
        for (let v = 0; v <= top + step / 2; v += step) tickValues.push(v);

        const labelChars = Math.max(...tickValues.map((v) => compact(v).length));
        const m = { top: 24, right: 6, bottom: 28, left: Math.ceil(labelChars * 7.4) + 12 };
        const plotW = W - m.left - m.right;
        const plotH = H - m.top - m.bottom;
        const band = plotW / n;
        const barW = Math.min(24, Math.max(1, Math.min(band * 0.72, band - 2)));
        const y = (cents) => m.top + plotH - (top ? (cents / top) * plotH : 0);
        const baseY = m.top + plotH;

        const svg = el('svg', {
            width: W, height: H, viewBox: `0 0 ${W} ${H}`, tabindex: 0, role: 'img',
            'aria-label': box.getAttribute('aria-label') + ' Use the left and right arrow keys to read each value.',
        });

        // Gridlines + y ticks (hairline, solid)
        tickValues.forEach((v) => {
            const ty = Math.round(y(v)) + 0.5;
            if (v > 0) el('line', { class: 'cc-grid', x1: m.left, x2: W - m.right, y1: ty, y2: ty }, svg);
            const t = el('text', { class: 'cc-tick', x: m.left - 10, y: ty + 4, 'text-anchor': 'end' }, svg);
            t.textContent = compact(v);
        });

        // Hit bands (bigger than the marks) — drawn under the bars
        const hits = points.map((p, i) => el('rect', {
            class: 'cc-hit', x: m.left + i * band, y: m.top, width: band, height: plotH,
        }, svg));

        // Columns: <= 24px, 4px rounded data end, square at the baseline
        let peak = -1;
        const bars = points.map((p, i) => {
            if (p.cents > 0 && (peak < 0 || p.cents > points[peak].cents)) peak = i;
            const h = baseY - y(p.cents);
            if (h < 0.5) return null;
            const x = m.left + i * band + (band - barW) / 2;
            const yt = baseY - h;
            const r = Math.min(4, barW / 2, h);
            return el('path', {
                class: 'cc-bar',
                d: `M${x},${baseY} V${yt + r} Q${x},${yt} ${x + r},${yt} H${x + barW - r} Q${x + barW},${yt} ${x + barW},${yt + r} V${baseY} Z`,
            }, svg);
        });

        // Baseline
        el('line', { class: 'cc-baseline', x1: m.left, x2: W - m.right, y1: baseY + 0.5, y2: baseY + 0.5 }, svg);

        // X labels: thinned so they never collide; the most recent one is always shown
        const labelW = data.group === 'month' ? 62 : 48;
        const every = Math.max(1, Math.ceil(labelW / band));
        points.forEach((p, i) => {
            if ((n - 1 - i) % every !== 0) return;
            const cx = m.left + i * band + band / 2;
            let anchor = 'middle';
            let tx = cx;
            if (cx - labelW / 2 < m.left - 8) { anchor = 'start'; tx = m.left + i * band; }
            if (cx + labelW / 2 > W) { anchor = 'end'; tx = m.left + (i + 1) * band; }
            const t = el('text', { class: 'cc-tick', x: tx, y: baseY + 18, 'text-anchor': anchor }, svg);
            t.textContent = p.label;
        });

        // Label only the extreme: the best day/month
        if (peak >= 0) {
            const cx = m.left + peak * band + band / 2;
            const text = money(points[peak].cents);
            const est = text.length * 7;
            let anchor = 'middle';
            let tx = cx;
            if (cx - est / 2 < 0) { anchor = 'start'; tx = m.left + peak * band; }
            if (cx + est / 2 > W) { anchor = 'end'; tx = m.left + (peak + 1) * band; }
            const t = el('text', { class: 'cc-peak', x: tx, y: y(points[peak].cents) - 8, 'text-anchor': anchor }, svg);
            t.textContent = text;
        }

        box.replaceChildren(svg);
        geo = { svg, m, band, bars, hits, n };
        bindChart(svg);
        if (active >= 0) setActive(active, false);
    }

    function setActive(i, fromPointer, px, py) {
        if (!geo) return;
        geo.bars.forEach((b, k) => b && b.classList.toggle('is-active', k === i));
        geo.hits.forEach((h, k) => h.classList.toggle('is-active', k === i));
        active = i;
        if (i < 0) { hideTip(); return; }
        const p = points[i];
        const note = p.count ? `${p.count} ${p.count === 1 ? 'sale' : 'sales'}` : 'No sales';
        if (fromPointer) {
            showTip(money(p.cents), p.long, note, px, py);
        } else {
            const r = (geo.bars[i] || geo.hits[i]).getBoundingClientRect();
            showTip(money(p.cents), p.long, note, r.left + r.width / 2, geo.bars[i] ? r.top : r.bottom - 10);
        }
    }

    function bindChart(svg) {
        const indexAt = (clientX) => {
            const r = svg.getBoundingClientRect();
            const i = Math.floor((clientX - r.left - geo.m.left) / geo.band);
            return i >= 0 && i < geo.n ? i : -1;
        };
        svg.addEventListener('pointermove', (e) => setActive(indexAt(e.clientX), true, e.clientX, e.clientY));
        svg.addEventListener('pointerleave', () => setActive(-1));
        svg.addEventListener('focus', () => {
            let last = -1;
            points.forEach((p, i) => { if (p.cents > 0) last = i; });
            setActive(active >= 0 ? active : Math.max(0, last), false);
        });
        svg.addEventListener('blur', () => setActive(-1));
        svg.addEventListener('keydown', (e) => {
            const moves = { ArrowLeft: -1, ArrowRight: 1, Home: -Infinity, End: Infinity };
            if (!(e.key in moves)) return;
            e.preventDefault();
            const next = Math.min(geo.n - 1, Math.max(0, (active < 0 ? 0 : active) + moves[e.key]));
            setActive(next, false);
        });
    }

    let frame = 0;
    new ResizeObserver(() => {
        cancelAnimationFrame(frame);
        frame = requestAnimationFrame(render);
    }).observe(box);
    window.addEventListener('scroll', () => { if (!tip?.hidden) hideTip(); }, { passive: true });
})();
