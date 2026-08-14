(function ($) {
    'use strict';

    if (typeof ReloadifySync === 'undefined') {
        return;
    }

    /**
     * Best-effort browser fingerprint. UA strings can be spoofed, and Brave in
     * particular reports itself as Chrome, so this isn't a security boundary —
     * just enough to route the "which browsers are enabled" setting correctly.
     */
    function detectBrowserName() {
        var ua = navigator.userAgent || '';

        if (/Edg\//.test(ua)) return 'edge';
        if (/OPR\//.test(ua) || /Opera/.test(ua)) return 'opera';
        if (/UCBrowser/i.test(ua)) return 'ucbrowser';
        if (/Firefox\//.test(ua) && !/Seamonkey/.test(ua)) return 'firefox';
        if (/Chrome\//.test(ua) && !/Edg\//.test(ua) && !/OPR\//.test(ua)) return 'chrome';
        if (/Safari\//.test(ua) && !/Chrome\//.test(ua)) return 'safari';

        return 'unknown';
    }

    function resolveBrowserName(baseName) {
        return new Promise(function (resolve) {
            if (baseName === 'chrome' && navigator.brave && typeof navigator.brave.isBrave === 'function') {
                navigator.brave.isBrave()
                    .then(function (isBrave) { resolve(isBrave ? 'brave' : 'chrome'); })
                    .catch(function () { resolve(baseName); });
                return;
            }
            resolve(baseName);
        });
    }

    /**
     * Private/incognito detection is a heuristic, not a guarantee — several
     * browsers intentionally make storage quotas identical in normal and private
     * mode. When it can't tell, it assumes "normal" so the reloader still runs
     * rather than silently doing nothing.
     */
    function detectIncognito() {
        return new Promise(function (resolve) {
            if (navigator.storage && typeof navigator.storage.estimate === 'function') {
                navigator.storage.estimate()
                    .then(function (estimate) {
                        var quotaMB = (estimate.quota || 0) / (1024 * 1024);
                        resolve(quotaMB > 0 && quotaMB < 120);
                    })
                    .catch(function () { resolve(false); });
                return;
            }
            resolve(false);
        });
    }

    function doReload(mode) {
        if (mode === 'hard') {
            var url = window.location.href.replace(/([?&])_reloadify_ts=\d+/, '');
            url += (url.indexOf('?') === -1 ? '?' : '&') + '_reloadify_ts=' + Date.now();
            window.location.replace(url);
        } else {
            window.location.reload();
        }
    }

    function startPolling(allowed) {
        if (!allowed) {
            return;
        }

        var checkInterval = parseInt(ReloadifySync.interval, 10) || 2000;
        var nonce = ReloadifySync.nonce;
        var reloadMode = ReloadifySync.reload_mode === 'hard' ? 'hard' : 'soft';
        var timestampUrl = ReloadifySync.timestamp_url;
        // Whichever path answers, this is set once and reused -- no reason to
        // keep retrying the heavier admin-ajax.php fallback every single tick
        // once we know the lightweight static file works (or definitively
        // doesn't, e.g. blocked by server config).
        var useStaticFile = !!timestampUrl;

        // IMPORTANT: the page markup itself (and the ReloadifySync.timestamp
        // value baked into it) can be served from a full-page cache, a CDN edge
        // cache, or a browser back-forward cache. If that's stale, trusting it
        // as the comparison baseline makes literally every load look "changed"
        // -- and since the reload just re-serves the same stale cached page,
        // that becomes an infinite reload loop with no real edit involved. It
        // also explains why a brand-new tab can miss the very next real save:
        // whatever number happened to be baked into that particular cached
        // copy is not reliably "now".
        //
        // The fix: never compare against the baked-in value. Use it only as a
        // rough placeholder until the first LIVE response comes back, then
        // adopt that live value as the real baseline. Everything after that
        // point compares live-fetch vs. live-fetch, which is what makes the
        // "did it change" check trustworthy in the first place.
        var currentTimestamp = parseInt(ReloadifySync.timestamp, 10) || 0;
        var hasSyncedBaseline = false;

        // Belt-and-braces: even after the static file starts working, cross-check
        // against admin-ajax.php every so often. admin-ajax.php always executes
        // PHP fresh and is excluded from caching by virtually every caching
        // plugin/CDN by convention, so if some layer of the stack is quietly
        // serving a stale copy of timestamp.json forever, this cross-check is
        // what still catches the real change instead of polling a dead value
        // indefinitely.
        var checksSinceAjaxVerify = 0;
        var AJAX_VERIFY_EVERY = 10; // roughly every 10 * checkInterval

        // A self-scheduling loop (rather than setInterval) means the next check is
        // always spaced out from when the *previous one finished*, not from when it
        // started -- so a slow response never causes two checks to pile up and it
        // never falls behind. The very first check fires immediately, with no wait.
        function scheduleNext(delay) {
            setTimeout(checkForUpdates, delay);
        }

        function handleTimestamp(newTimestamp) {
            if (!hasSyncedBaseline) {
                // First live value we've ever seen this page-load: adopt it as
                // truth instead of comparing against the (possibly stale,
                // possibly cached) value the server rendered into the HTML.
                hasSyncedBaseline = true;
                currentTimestamp = newTimestamp;
                return false;
            }

            if (newTimestamp > currentTimestamp) {
                currentTimestamp = newTimestamp;
                console.log('Reloadify Frontend Sync: change detected, reloading (' + reloadMode + ').');
                doReload(reloadMode);
                return true;
            }
            return false;
        }

        // The cheap path: a plain file fetch the webserver answers directly,
        // no PHP or WordPress bootstrap involved. This is what keeps polling
        // affordable on a live site with real traffic.
        function checkViaStaticFile() {
            $.ajax({
                url: timestampUrl + '?_=' + Date.now(),
                type: 'GET',
                timeout: 5000,
                cache: false,
                dataType: 'json',
                headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' },
                success: function (response) {
                    if (!response) {
                        scheduleNext(checkInterval);
                        return;
                    }

                    if (handleTimestamp(parseInt(response.t, 10))) {
                        return;
                    }

                    checksSinceAjaxVerify++;
                    if (hasSyncedBaseline && checksSinceAjaxVerify >= AJAX_VERIFY_EVERY) {
                        checksSinceAjaxVerify = 0;
                        checkViaAjax();
                        return;
                    }

                    scheduleNext(checkInterval);
                },
                error: function () {
                    // The static file genuinely isn't reachable on this host
                    // (permissions, a security rule blocking /uploads/*.json,
                    // etc.) -- drop to the admin-ajax.php fallback from here on
                    // instead of re-trying the failing request forever.
                    useStaticFile = false;
                    scheduleNext(checkInterval);
                }
            });
        }

        // The fallback path: heavier (boots all of WordPress per check), only
        // used when the static file truly can't be reached on this host, and
        // periodically as a cross-check while it can (see AJAX_VERIFY_EVERY).
        function checkViaAjax() {
            $.ajax({
                url: ReloadifySync.ajax_url,
                type: 'POST',
                timeout: 5000,
                cache: false,
                data: {
                    action: 'reloadify_reloader_check',
                    timestamp: currentTimestamp,
                    nonce: nonce
                },
                success: function (response) {
                    if (response && response.success && response.data) {
                        if (!hasSyncedBaseline) {
                            hasSyncedBaseline = true;
                            currentTimestamp = parseInt(response.data.new_timestamp, 10) || currentTimestamp;
                            scheduleNext(checkInterval);
                            return;
                        }

                        if (response.data.reload) {
                            console.log('Reloadify Frontend Sync: change detected, reloading (' + reloadMode + ').');
                            doReload(reloadMode);
                            return;
                        }
                    }
                    scheduleNext(checkInterval);
                },
                error: function () {
                    scheduleNext(checkInterval);
                }
            });
        }

        function checkForUpdates() {
            if (useStaticFile) {
                checkViaStaticFile();
            } else {
                checkViaAjax();
            }
        }

        checkForUpdates();
    }

    function init() {
        // The signed-in editor's own tab always polls — no settings dependency.
        if (ReloadifySync.is_editor_viewer === '1') {
            startPolling(true);
            return;
        }

        var settings = ReloadifySync.browser_settings || {};

        resolveBrowserName(detectBrowserName()).then(function (browserName) {
            detectIncognito().then(function (isIncognito) {
                var mode = isIncognito ? 'incognito' : 'normal';
                var rule = settings[browserName];
                var allowed = !!(rule && rule[mode]);

                if (!allowed) {
                    console.log('Reloadify Frontend Sync: disabled for ' + browserName + ' (' + mode + '). Enable it under Auto Reloader in wp-admin.');
                }

                startPolling(allowed);
            });
        });
    }

    init();

})(jQuery);
