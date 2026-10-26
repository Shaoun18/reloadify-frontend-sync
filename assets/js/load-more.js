/**
 * Reloadify - Load more (no page refresh).
 * Markup comes from [reloadify_load_more]. The first batch is in the page
 * HTML; each press (or scroll) asks the REST API for the next signed batch.
 */
(function () {
    'use strict';

    var cfg = window.ReloadifyLoadMore || {};

    function init(root) {
        if (root.reloadifyReady) { return; }
        root.reloadifyReady = true;

        var grid = root.querySelector('.reloadify-lm__grid');
        var btn = root.querySelector('.reloadify-lm__button');
        var status = root.querySelector('.reloadify-lm__status');

        if (!grid || !btn || !status || !window.fetch || !cfg.endpoint) { return; }

        var page = parseInt(root.getAttribute('data-page'), 10) || 1;
        var max = parseInt(root.getAttribute('data-max'), 10) || 1;
        var trigger = root.getAttribute('data-trigger');
        var label = btn.textContent;
        var busy = false;
        var observer = null;

        function done() {
            btn.hidden = true;
            status.textContent = root.getAttribute('data-end') || '';
            if (observer) { observer.disconnect(); }
        }

        function recheck() {
            if (observer && !btn.hidden) {
                observer.unobserve(btn);
                observer.observe(btn);
            }
        }

        function load() {
            var url;

            if (busy || page >= max) { return; }

            busy = true;
            btn.disabled = true;
            btn.classList.add('is-loading');
            btn.textContent = root.getAttribute('data-loading') || label;
            status.textContent = '';

            url = cfg.endpoint + (cfg.endpoint.indexOf('?') === -1 ? '?' : '&') +
                'config=' + encodeURIComponent(root.getAttribute('data-config')) +
                '&sig=' + encodeURIComponent(root.getAttribute('data-sig')) +
                '&page=' + (page + 1);

            window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (res) {
                    if (!res.ok) { throw new Error('http ' + res.status); }
                    return res.json();
                })
                .then(function (data) {
                    var tpl = document.createElement('template');
                    var nodes;
                    var link;

                    tpl.innerHTML = data.html || '';
                    nodes = Array.prototype.slice.call(tpl.content.children);
                    nodes.forEach(function (node) {
                        node.classList.add('is-new');
                        grid.appendChild(node);
                    });

                    page = data.page || page + 1;
                    max = data.max || max;
                    root.setAttribute('data-page', String(page));

                    busy = false;
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.textContent = label;

                    if (!data.has_more || page >= max) {
                        done();
                    } else if (trigger !== 'scroll' && nodes[0]) {
                        // Keyboard and screen-reader users continue from the first new item.
                        link = nodes[0].querySelector('a[href]:not([tabindex="-1"])');
                        if (link) { link.focus({ preventScroll: true }); }
                    }

                    try {
                        root.dispatchEvent(new CustomEvent('reloadify:loadmore', { bubbles: true, detail: { items: nodes, page: page } }));
                    } catch (e) { /* old browser: no event, still works */ }

                    recheck();
                })
                .catch(function () {
                    busy = false;
                    btn.disabled = false;
                    btn.classList.remove('is-loading');
                    btn.textContent = cfg.retry || label;
                    status.textContent = cfg.error || '';
                });
        }

        btn.addEventListener('click', load);

        if (trigger === 'scroll' && 'IntersectionObserver' in window) {
            root.classList.add('is-auto');
            observer = new window.IntersectionObserver(function (entries) {
                if (entries[0] && entries[0].isIntersecting) { load(); }
            }, { rootMargin: '400px 0px' });
            observer.observe(btn);
        }
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('.reloadify-lm'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
