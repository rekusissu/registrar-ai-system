// ============================================================
//  JS/SEARCHABLE-SELECT.JS
//  Progressive enhancement: turns any <select data-searchable>
//  into a type-to-search dropdown. Designed for large option
//  lists (1,000+ students) — renders a capped result set and
//  filters by substring match on the option label.
//
//  The native <select> stays in the DOM (hidden) and is kept in
//  sync, so form validation, form posts, and existing JS that
//  reads/writes select.value keep working unchanged.
// ============================================================

(function () {
    'use strict';

    var MAX_RESULTS = 100;   // cap rendered items; hint shown beyond this
    var SCROLL_H = 240;      // dropdown max height (px)

    function enhance(sel) {
        if (sel.dataset.ssInit) return;
        sel.dataset.ssInit = '1';

        var wrap = document.createElement('div');
        wrap.className = 'ss-wrap';
        sel.parentNode.insertBefore(wrap, sel);

        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'form-control ss-input';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('placeholder', sel.dataset.placeholder || 'Type to search…');

        var list = document.createElement('div');
        list.className = 'ss-list';

        wrap.appendChild(input);
        wrap.appendChild(list);
        wrap.appendChild(sel);          // keep native select inside (hidden)
        sel.classList.add('ss-native');


        // Options snapshot (placeholder = first empty-value option, excluded from search)
        var opts = [];
        var placeholderText = '';
        for (var i = 0; i < sel.options.length; i++) {
            var o = sel.options[i];
            if (o.value === '' && i === 0) { placeholderText = o.textContent.trim(); continue; }
            opts.push({ v: o.value, t: o.textContent.replace(/\s+/g, ' ').trim() });
        }
        if (!sel.dataset.placeholder && placeholderText) {
            input.setAttribute('placeholder', placeholderText);
        }

        var filtered = opts, hl = 0, editing = false;
        var lastVal = sel.value;

        function selected() {
            for (var i = 0; i < opts.length; i++) if (opts[i].v === sel.value) return opts[i];
            return null;
        }
        function escHtml(s) {
            return s.replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }
        function renderLabel() {
            if (editing) return;
            var s = selected();
            input.value = s ? s.t : '';
        }
        function renderList() {
            var q = input.value.trim().toLowerCase();
            filtered = !q ? opts.slice(0, MAX_RESULTS)
                          : opts.filter(function (x) { return x.t.toLowerCase().indexOf(q) !== -1; }).slice(0, MAX_RESULTS);
            hl = filtered.length ? 0 : -1;
            var html = '';
            for (var i = 0; i < filtered.length; i++) {
                html += '<div class="ss-item' + (i === hl ? ' hl' : '') + '" data-i="' + i + '">' + escHtml(filtered[i].t) + '</div>';
            }
            if (q && opts.length > MAX_RESULTS && filtered.length === MAX_RESULTS) {
                html += '<div class="ss-more">Refine your search to see more…</div>';
            } else if (!filtered.length) {
                html += '<div class="ss-more">No matches found</div>';
            }
            list.innerHTML = html;
        }
        function openList() {
            editing = true;
            input.value = '';
            renderList();
            list.classList.add('open');
            list.style.maxHeight = SCROLL_H + 'px';
        }
        function closeList() {
            list.classList.remove('open');
            editing = false;
            renderLabel();
        }
        function pick(i) {
            if (i < 0 || i >= filtered.length) return;
            sel.value = filtered[i].v;
            lastVal = sel.value;
            try { sel.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
            closeList();
            input.blur();
        }


        input.addEventListener('focus', openList);
        input.addEventListener('input', function () { editing = true; renderList(); });
        input.addEventListener('blur', function () { setTimeout(closeList, 140); });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (!list.classList.contains('open')) openList(); else if (hl < filtered.length - 1) { hl++; markHl(); } }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (hl > 0) { hl--; markHl(); } }
            else if (e.key === 'Enter') { if (list.classList.contains('open') && hl >= 0) { e.preventDefault(); pick(hl); } }
            else if (e.key === 'Escape') { closeList(); }
        });
        function markHl() {
            var items = list.querySelectorAll('.ss-item');
            for (var i = 0; i < items.length; i++) items[i].classList.toggle('hl', i === hl);
            if (items[hl]) items[hl].scrollIntoView({ block: 'nearest' });
        }

        list.addEventListener('mousedown', function (e) {   // mousedown fires before input blur
            var item = e.target.closest('.ss-item');
            if (item) { e.preventDefault(); pick(parseInt(item.dataset.i, 10)); }
        });

        // Keep the visible label in sync when other JS sets select.value
        // (e.g. edit modals prefilling the student).
        setInterval(function () {
            if (sel.value !== lastVal) { lastVal = sel.value; renderLabel(); }
        }, 300);

        renderLabel();
    }

    function initAll(root) {
        var sels = (root || document).querySelectorAll('select[data-searchable]');
        for (var i = 0; i < sels.length; i++) enhance(sels[i]);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); });
    } else {
        initAll();
    }
    window.refreshSearchableSelects = initAll;   // for dynamically injected selects
})();
