/**
 * Reloadify - Read more / Read less (no page refresh).
 * Markup comes from [reloadify_read_more]; the hidden text is already in the
 * page HTML, this only opens and closes it.
 */
(function () {
    'use strict';

    var cfg = window.ReloadifyReadMore || {};
    var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function after(panel, fn) {
        var called = false;
        function run() {
            if (called) { return; }
            called = true;
            panel.removeEventListener('transitionend', onEnd);
            fn();
        }
        function onEnd(e) { if (e.target === panel) { run(); } }
        panel.addEventListener('transitionend', onEnd);
        window.setTimeout(run, 450);
    }

    function reset(panel) {
        panel.style.height = '';
        panel.style.overflow = '';
        panel.style.opacity = '';
        panel.style.transition = '';
    }

    function setState(root, btn, open) {
        var label = btn.querySelector('.reloadify-rm__label');
        root.classList.toggle('is-open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (label) { label.textContent = btn.getAttribute(open ? 'data-close' : 'data-open') || label.textContent; }
    }

    function expand(root, btn, panel) {
        var mode = reduce ? 'none' : (root.getAttribute('data-animation') || cfg.animation || 'slide');
        var height;

        panel.hidden = false;
        setState(root, btn, true);

        if (mode === 'fade') {
            panel.style.opacity = '0';
            panel.style.transition = 'opacity .3s ease';
            void panel.offsetHeight;
            panel.style.opacity = '1';
            after(panel, function () { reset(panel); });
        } else if (mode === 'slide') {
            height = panel.scrollHeight;
            panel.style.overflow = 'hidden';
            panel.style.height = '0px';
            panel.style.transition = 'height .3s ease';
            void panel.offsetHeight;
            panel.style.height = height + 'px';
            after(panel, function () { reset(panel); });
        }
    }

    function collapse(root, btn, panel) {
        var mode = reduce ? 'none' : (root.getAttribute('data-animation') || cfg.animation || 'slide');

        function finish() {
            panel.hidden = true;
            reset(panel);
        }

        setState(root, btn, false);

        if (mode === 'fade') {
            panel.style.transition = 'opacity .25s ease';
            panel.style.opacity = '0';
            after(panel, finish);
        } else if (mode === 'slide') {
            panel.style.overflow = 'hidden';
            panel.style.height = panel.scrollHeight + 'px';
            panel.style.transition = 'height .3s ease';
            void panel.offsetHeight;
            panel.style.height = '0px';
            after(panel, finish);
        } else {
            finish();
        }

        // Bring the button back into view when a long text was closed from far below.
        if (cfg.scroll && btn.getBoundingClientRect().top < 0 && btn.scrollIntoView) {
            btn.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
        }
    }

    function toggle(btn) {
        var root = btn.closest('.reloadify-rm');
        var panel = root && root.querySelector('.reloadify-rm__content');

        if (!root || !panel) { return; }

        if (btn.getAttribute('aria-expanded') === 'true') {
            collapse(root, btn, panel);
        } else {
            expand(root, btn, panel);
        }
    }

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('.reloadify-rm__button') : null;
        if (btn) { toggle(btn); }
    });

    // A link such as /page/#section that points inside hidden text opens it.
    function openForHash() {
        var id = window.location.hash ? decodeURIComponent(window.location.hash.slice(1)) : '';
        var target = id ? document.getElementById(id) : null;
        var panel = target && target.closest ? target.closest('.reloadify-rm__content') : null;

        if (panel && panel.hidden) {
            var root = panel.closest('.reloadify-rm');
            var btn = root && root.querySelector('.reloadify-rm__button');
            if (btn) {
                panel.hidden = false;
                setState(root, btn, true);
                if (target.scrollIntoView) { target.scrollIntoView(); }
            }
        }
    }

    openForHash();
    window.addEventListener('hashchange', openForHash);
}());
