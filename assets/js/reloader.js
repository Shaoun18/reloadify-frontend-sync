(function ($) {
    'use strict';

    if (typeof ReloadifySync === 'undefined') {
        return;
    }

    /**
     * Best-effort browser fingerprint.
     */
    function detectBrowserName() {
        var ua = navigator.userAgent || '';

        if (/Edg\//.test(ua)) return 'edge';
        if (/OPR\//.test(ua) || /Opera/.test(ua)) return 'opera';
        if (/UCBrowser/i.test(ua)) return 'ucbrowser';
        if (/Vivaldi\//.test(ua)) return 'vivaldi';
        if (/YaBrowser\//.test(ua)) return 'yandex';
        if (/SamsungBrowser\//.test(ua)) return 'samsung';
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
     * Private/incognito detection.
     *
     * There's no official "am I private?" flag; every technique here infers
     * it from a side-effect, and browser vendors keep patching those closed.
     * Several independent signals run and the majority wins, so no single
     * patched API can flip the result on its own. Any probe that isn't
     * supported (or gives an inconclusive answer) abstains rather than
     * voting.
     */
    function detectIncognito() {
        function probeQuota() {
            return new Promise(function (resolve) {
                if (!navigator.storage || typeof navigator.storage.estimate !== 'function') {
                    resolve(null);
                    return;
                }
                navigator.storage.estimate().then(function (estimate) {
                    var quotaMB = (estimate.quota || 0) / (1024 * 1024);
                    if (quotaMB <= 0) {
                        resolve(null);
                        return;
                    }
                    // Where available, weigh quota against device memory rather than
                    // a flat 120MB cut-off -- that flat cut-off is the exact check
                    // Chrome's mitigation was built to defeat.
                    if (navigator.deviceMemory) {
                        resolve((quotaMB / (navigator.deviceMemory * 1024)) < 0.2);
                        return;
                    }
                    resolve(quotaMB < 120);
                }).catch(function () { resolve(null); });
            });
        }

        function probeIndexedDB() {
            return new Promise(function (resolve) {
                if (!window.indexedDB) {
                    resolve(null);
                    return;
                }
                try {
                    var req = indexedDB.open('__reloadify_probe__');
                    req.onerror = function () { resolve(true); };
                    req.onsuccess = function () {
                        try { req.result.close(); } catch (e) {}
                        try { indexedDB.deleteDatabase('__reloadify_probe__'); } catch (e) {}
                        resolve(false);
                    };
                } catch (e) {
                    resolve(true);
                }
            });
        }

        function probeLocalStorage() {
            return new Promise(function (resolve) {
                try {
                    window.localStorage.setItem('__reloadify_probe__', '1');
                    window.localStorage.removeItem('__reloadify_probe__');
                    resolve(false);
                } catch (e) {
                    resolve(e && e.code === 22 /* QUOTA_EXCEEDED_ERR */ ? true : null);
                }
            });
        }

        return Promise.all([probeQuota(), probeIndexedDB(), probeLocalStorage()])
            .then(function (results) {
                var votes = results.filter(function (r) { return r !== null; });
                if (!votes.length) {
                    return false; // nothing conclusive -- same safe default as before
                }
                var privateVotes = votes.filter(Boolean).length;
                return privateVotes > votes.length / 2;
            });
    }

    /* ---------------- Which tab is the user actually looking at? ---------------- */

    /**
     * "Reload all tabs" off means only the tab in front of the user reloads,
     * so every open tab needs a shared answer to "is it me?". Tabs claim the
     * active slot in localStorage (shared across every tab of the same
     * browser profile) and refresh that claim on a heartbeat; whoever holds
     * a live claim is the one that reloads. Every storage call is wrapped,
     * because private windows can refuse localStorage outright -- in that
     * case the tab falls back to its own focus state.
     */

    var ACTIVE_TAB_KEY = 'reloadify_active_tab';
    var ACTIVE_TAB_HEARTBEAT_MS = 2000;

    // A claim nobody has refreshed for this long belongs to a tab that is gone
    // (crashed, discarded by the browser, closed without a chance to clean up).
    // Generous on purpose: a hidden tab's timers can be throttled to once a
    // minute, and a live holder must never look dead.
    var CLAIM_STALE_MS = 150000;
    var lastClaimWrite = 0;

    // Set when a change arrived while this tab was not the one meant to
    // reload; honoured the moment the tab becomes the active one.
    var missedChange = false;
    var isReloadingSelf = false;

    var lastFocusTime = 0;

    // A fresh id on every page load. It used to live in sessionStorage, but
    // "Duplicate tab" (and window.open) copies sessionStorage -- two tabs then
    // shared one id and both looked like the holder of the active claim.
    var TAB_ID = 'rt-' + Date.now() + '-' + Math.random().toString(36).slice(2);

    var STORAGE_OK = (function () {
        try {
            localStorage.setItem('__reloadify_probe__', '1');
            localStorage.removeItem('__reloadify_probe__');
            return true;
        } catch (e) {
            return false;
        }
    })();

    var isTabActive = false;
    var heartbeatTimer = null;
    var broadcastChannel = null;

    var allTabsReload = (ReloadifySync.all_tabs_reload_enabled === '1' || ReloadifySync.all_tabs_reload_enabled === 1 || ReloadifySync.all_tabs_reload_enabled === true);
    var reloadMode = ReloadifySync.reload_mode === 'hard' ? 'hard' : 'soft';

    var runImmediateCheck = null;
    var lastImmediateCheckAt = 0;

    function catchUpIfNeeded() {
        if (!runImmediateCheck) {
            return;
        }
        var now = Date.now();
        if (now - lastImmediateCheckAt < 400) {
            return;
        }
        lastImmediateCheckAt = now;
        runImmediateCheck();
    }

    function isThisWindowFocused() {
        try {
            return document.hasFocus();
        } catch (e) {
            return !document.hidden;
        }
    }

    function readActiveClaim() {
        try {
            var raw = localStorage.getItem(ACTIVE_TAB_KEY);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    /** The claim, unless it was left behind by a tab that no longer exists. */
    function readLiveClaim() {
        var claim = readActiveClaim();
        if (!claim) {
            return null;
        }
        if (claim.id !== TAB_ID && (Date.now() - (claim.ts || 0)) > CLAIM_STALE_MS) {
            return null;
        }
        return claim;
    }

    function thisTabHasActiveClaim() {
        var claim = readActiveClaim();
        return !!(claim && claim.id === TAB_ID);
    }

    function writeActiveClaim() {
        lastClaimWrite = Date.now();
        try {
            localStorage.setItem(ACTIVE_TAB_KEY, JSON.stringify({
                id: TAB_ID,
                ts: Date.now(),
                ft: lastFocusTime
            }));
        } catch (e) {
            // Private windows can refuse writes entirely; focus state still works.
        }
    }

    function stopHeartbeat() {
        if (heartbeatTimer) {
            clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    function startHeartbeat() {
        stopHeartbeat();
        heartbeatTimer = setInterval(function () {
            var claim = readLiveClaim();

            if (claim && claim.id !== TAB_ID) {
                // Another tab took over while we weren't looking: let go
                // instead of fighting it for the slot.
                if ((claim.ft || 0) >= lastFocusTime || !isThisTabVisibleAndFocused()) {
                    releaseActiveTab();
                    stopHeartbeat();
                    return;
                }
            }

            if (isTabActive || (claim && claim.id === TAB_ID)) {
                writeActiveClaim();
            }
        }, ACTIVE_TAB_HEARTBEAT_MS);
    }

    function becomeActiveTab() {
        var wasActive = isTabActive;
        isTabActive = true;
        lastFocusTime = Date.now();
        writeActiveClaim();
        if (!wasActive) {
            startHeartbeat();
        }
    }

    function releaseActiveTab() {
        isTabActive = false;

        var claim = readActiveClaim();
        if (!claim || claim.id !== TAB_ID) {
            stopHeartbeat();
        }
    }

    function isThisTabVisibleAndFocused() {
        return !document.hidden && isThisWindowFocused();
    }

    /**
     * The shared claim in localStorage is the single source of truth.
     *
     * Trusting only a tab's own isTabActive flag was the "all tabs reload
     * after a while" bug: a tab that had been backgrounded, frozen or
     * throttled could miss the storage event that told it to let go, wake up
     * still believing it was active, and reload alongside the real active tab.
     * So while a LIVE claim exists, only its owner reloads.
     *
     * The opposite failure is just as bad -- nobody reloading. That happens
     * when the claim is missing, or left behind by a tab that no longer
     * exists. In that case the tab in front of the user (visible; it does not
     * need keyboard focus, because a preview window beside the editor never
     * has it) takes the claim and reloads.
     */
    function shouldThisTabReload() {
        if (allTabsReload) {
            return true;
        }

        if (!STORAGE_OK) {
            // No shared storage (some private windows): the best we can know
            // is whether this tab is the one in front of the user.
            return !document.hidden;
        }

        var claim = readLiveClaim();

        if (claim) {
            if (claim.id === TAB_ID) {
                return true;
            }
            // Someone else holds it. Self-correct our stale local state.
            if (isTabActive) {
                isTabActive = false;
            }
            return false;
        }

        if (!document.hidden) {
            becomeActiveTab();
            return true;
        }

        return false;
    }

    /** A change was skipped earlier; act on it now that this tab is the active one. */
    function reloadIfMissed() {
        if (!missedChange || allTabsReload) {
            return;
        }
        if (shouldThisTabReload()) {
            missedChange = false;
            doReload(reloadMode);
        }
    }

    window.addEventListener('storage', function (e) {
        if (e.key !== ACTIVE_TAB_KEY) {
            return;
        }

        if (e.newValue === null) {
            if (!document.hidden && !isTabActive) {
                becomeActiveTab();
                reloadIfMissed();
            }
            return;
        }

        var claim = readActiveClaim();

        if (claim && claim.id !== TAB_ID && isTabActive) {
            if ((claim.ft || 0) >= lastFocusTime || !isThisTabVisibleAndFocused()) {
                releaseActiveTab();
            } else {
                writeActiveClaim(); // we are genuinely the focused one
            }
        }
    });

    // A frozen/discarded tab wakes up with whatever it believed before it
    // slept. Re-check against the shared claim straight away.
    function revalidateActiveState() {
        var claim = readLiveClaim();
        if (claim && claim.id !== TAB_ID) {
            isTabActive = false;
        } else if (!claim && !document.hidden) {
            becomeActiveTab();
        }
    }

    // A tab the browser is about to freeze stops running timers; hand the slot
    // back so a sibling can take it instead of everyone waiting on a ghost.
    document.addEventListener('freeze', function () {
        if (!isReloadingSelf && thisTabHasActiveClaim()) {
            try {
                localStorage.removeItem(ACTIVE_TAB_KEY);
            } catch (e) {}
        }
        isTabActive = false;
        stopHeartbeat();
    });
    document.addEventListener('resume', revalidateActiveState);
    window.addEventListener('pageshow', revalidateActiveState);

    window.addEventListener('focus', function () {
        becomeActiveTab();
        reloadIfMissed();
        catchUpIfNeeded();
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            revalidateActiveState();
            if (isThisWindowFocused()) {
                becomeActiveTab();
            } else if (thisTabHasActiveClaim()) {
                writeActiveClaim();
            }
            reloadIfMissed();
            catchUpIfNeeded();
        }
    });

    ['click', 'keydown', 'pointerdown', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, function () {
            var wasAlreadyActive = isTabActive;
            becomeActiveTab();
            if (!wasAlreadyActive) {
                reloadIfMissed();
                catchUpIfNeeded();
            }
        }, { passive: true, capture: true });
    });

    /**
     * Set while this tab is reloading itself.
     *
     * The claim must survive that reload. Dropping it on unload was the bug
     * behind "it only ever reloads once": the tab tore up its own claim on
     * the way out, came back with the user's focus still over in wp-admin,
     * and so never re-claimed the active slot -- leaving the browser with no
     * active tab at all, and nothing to reload on the next save.
     */
    var RECLAIM_KEY = 'reloadify_reclaim';

    ['pagehide', 'beforeunload'].forEach(function (evt) {
        window.addEventListener(evt, function () {
            // A real close/navigate releases the slot straight away so a
            // sibling tab can take it. A reload keeps it.
            if (!isReloadingSelf && thisTabHasActiveClaim()) {
                try {
                    localStorage.removeItem(ACTIVE_TAB_KEY);
                } catch (e) {}
            }
        });
    });

    (function initialActiveTabState() {
        var now = Date.now();
        var focused = isThisTabVisibleAndFocused();

        // Did this tab just reload itself? sessionStorage is per-tab and
        // survives a reload, so it answers that even when localStorage is
        // unavailable. The flag is timestamped so a copied sessionStorage
        // (duplicated tab) can't carry a stale "I just reloaded" forever.
        var justReloaded = false;
        try {
            var flag = parseInt(sessionStorage.getItem(RECLAIM_KEY), 10);
            justReloaded = flag > 0 && (now - flag) < 15000;
            sessionStorage.removeItem(RECLAIM_KEY);
        } catch (e) {}

        if (justReloaded || focused) {
            lastFocusTime = now;
            becomeActiveTab();
        }
    })();

    function doReload(mode) {
        // Hold on to the active-tab claim across the reload (see the
        // isReloadingSelf comment above).
        isReloadingSelf = true;
        try {
            sessionStorage.setItem(RECLAIM_KEY, String(Date.now()));
        } catch (e) {}

        if (mode === 'hard') {
            var url = window.location.href.replace(/([?&])_reloadify_ts=\d+/, '');
            url += (url.indexOf('?') === -1 ? '?' : '&') + '_reloadify_ts=' + Date.now();
            window.location.replace(url);
        } else {
            window.location.reload();
        }
    }

    /**
     * One tab notices the change first; the rest hear about it here instead
     * of waiting out their own poll, so an "all tabs" reload lands at the
     * same moment everywhere.
     */
    if (typeof BroadcastChannel !== 'undefined') {
        try {
            broadcastChannel = new BroadcastChannel('reloadify_sync');
            broadcastChannel.onmessage = function (event) {
                if (!event.data || 'reload_triggered' !== event.data.action) {
                    return;
                }

                if (shouldThisTabReload()) {
                    doReload(event.data.reloadMode);
                }
            };
        } catch (e) {
            broadcastChannel = null;
        }
    }

    function announceReload() {
        if (!broadcastChannel) {
            return;
        }
        try {
            broadcastChannel.postMessage({ action: 'reload_triggered', reloadMode: reloadMode });
        } catch (e) {}
    }

    /**
     * A steady beat that is not throttled when the tab is in the background.
     *
     * Browsers slow a hidden tab's setTimeout/setInterval to once a minute
     * after a few minutes, so a preview tab left behind the editor noticed
     * saves up to a minute late -- which looks exactly like "reload stopped
     * working". A dedicated Worker's timer is not throttled that way, so it
     * carries the beat and the page does the actual work. A plain interval
     * stays as a safety net (and as the whole mechanism where Workers are
     * blocked).
     */
    function startTicker(callback, ms) {
        var safety = setInterval(callback, 5000);
        var worker = null;
        var started = false;

        function fallBack() {
            try {
                if (worker) {
                    worker.terminate();
                }
            } catch (e) {}
            worker = null;
            if (!started) {
                started = true; // stop the "never ticked" timer from firing again
            }
        }

        if (typeof Worker === 'undefined' || typeof Blob === 'undefined' || !window.URL || !URL.createObjectURL) {
            return safety;
        }

        try {
            var blobUrl = URL.createObjectURL(new Blob(['setInterval(function(){postMessage(0);},' + ms + ');'], { type: 'text/javascript' }));
            worker = new Worker(blobUrl);
            worker.onmessage = function () {
                if (!started) {
                    started = true;
                    try { URL.revokeObjectURL(blobUrl); } catch (e) {}
                }
                callback();
            };
            worker.onerror = fallBack;
        } catch (e) {
            fallBack();
        }

        return safety;
    }

    function startPolling(allowed) {
        if (!allowed) {
            return;
        }

        var checkInterval = Math.max(500, parseInt(ReloadifySync.interval, 10) || 2000);
        var nonce = ReloadifySync.nonce;
        var timestampUrl = ReloadifySync.timestamp_url;

        var useStaticFile = !!timestampUrl;
        var staticFailures = 0;
        var ajaxChecksSinceFallback = 0;

        var currentTimestamp = parseInt(ReloadifySync.timestamp, 10) || 0;
        var hasSyncedBaseline = false;

        var checksSinceAjaxVerify = 0;
        var AJAX_VERIFY_EVERY = 60; // roughly every 60 * checkInterval

        var inFlight = false;
        var inFlightSince = 0;
        var nextDueAt = 0;

        /**
         * Reload mode and the all-tabs flag ride along on every poll response,
         * so changing either in wp-admin takes effect on tabs that are already
         * open instead of only on their next full page load.
         */
        function applyFreshSettings(allTabsValue, reloadModeValue) {
            if (allTabsValue !== undefined && allTabsValue !== null) {
                allTabsReload = (allTabsValue === '1' || allTabsValue === 1 || allTabsValue === true);
            }
            if (reloadModeValue) {
                reloadMode = 'hard' === reloadModeValue ? 'hard' : 'soft';
            }
        }

        function scheduleNext(delay) {
            inFlight = false;
            var wait = delay;

            // A hidden tab that is not the one being reloaded can afford to
            // look less often; it checks at once when it comes back anyway.
            if (document.hidden && !thisTabHasActiveClaim()) {
                wait = delay * 4;
            }
            nextDueAt = Date.now() + wait;
        }

        function handleTimestamp(newTimestamp) {
            // An empty, partial or garbled response is not "time zero".
            if (!(newTimestamp > 0)) {
                return false;
            }

            if (!hasSyncedBaseline) {
                hasSyncedBaseline = true;
                currentTimestamp = newTimestamp;
                return false;
            }

            if (newTimestamp > currentTimestamp) {
                currentTimestamp = newTimestamp;

                if (!shouldThisTabReload()) {
                    missedChange = true;
                    return false;
                }

                announceReload();
                doReload(reloadMode);
                return true;
            }

            if (newTimestamp < currentTimestamp) {
                currentTimestamp = newTimestamp; // clock moved back: keep following it
            }
            return false;
        }

        function checkViaStaticFile() {
            $.ajax({
                url: timestampUrl + (timestampUrl.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now(),
                type: 'GET',
                timeout: 5000,
                cache: false,
                dataType: 'json',
                headers: { 'Cache-Control': 'no-cache, no-store, must-revalidate', 'Pragma': 'no-cache' },
                success: function (response) {
                    try {
                        if (response && typeof response === 'object') {
                            staticFailures = 0;
                            applyFreshSettings(response.atr, response.rm);

                            if (handleTimestamp(parseInt(response.t, 10))) {
                                return;
                            }

                            checksSinceAjaxVerify++;
                            if (hasSyncedBaseline && !document.hidden && checksSinceAjaxVerify >= AJAX_VERIFY_EVERY) {
                                checksSinceAjaxVerify = 0;
                                checkViaAjax();
                                return;
                            }
                        }
                    } catch (e) {}

                    scheduleNext(checkInterval);
                },
                error: function () {
                    // One bad read (the file caught mid-write, a network blip)
                    // must not drop us onto the heavy admin-ajax path for good.
                    staticFailures++;
                    if (staticFailures >= 3) {
                        useStaticFile = false;
                        ajaxChecksSinceFallback = 0;
                    }
                    scheduleNext(checkInterval);
                }
            });
        }

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
                    try {
                        if (response && response.success && response.data) {
                            applyFreshSettings(response.data.all_tabs_reload_enabled, response.data.reload_mode);

                            if (!hasSyncedBaseline) {
                                hasSyncedBaseline = true;
                                currentTimestamp = parseInt(response.data.new_timestamp, 10) || currentTimestamp;
                            } else if (response.data.reload) {
                                currentTimestamp = parseInt(response.data.new_timestamp, 10) || currentTimestamp;

                                if (shouldThisTabReload()) {
                                    announceReload();
                                    doReload(reloadMode);
                                    return;
                                }
                                missedChange = true;
                            }
                        }

                        // On the AJAX path only because the static file failed:
                        // try it again after a while.
                        if (!useStaticFile && timestampUrl && ++ajaxChecksSinceFallback >= 15) {
                            useStaticFile = true;
                            staticFailures = 2; // one more failure sends us straight back
                        }
                    } catch (e) {}

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

        // Which tabs need to look at all? In "all tabs" mode every tab does.
        // Otherwise a hidden tab that is not the active one has nothing to
        // reload, so it stays quiet -- fewer requests with many tabs open --
        // and catches up the moment it is brought forward.
        function shouldPollNow() {
            if (!document.hidden || allTabsReload) {
                return true;
            }
            return !STORAGE_OK || thisTabHasActiveClaim();
        }

        function tick() {
            var now = Date.now();

            // Keep the claim fresh even when this tab's own timers are throttled.
            if (isTabActive && STORAGE_OK && thisTabHasActiveClaim() && (now - lastClaimWrite) > 4000) {
                writeActiveClaim();
            }

            // A request that never came back must not stall the beat for good.
            if (inFlight && (now - inFlightSince) > 15000) {
                inFlight = false;
            }

            if (inFlight || now < nextDueAt) {
                return;
            }

            if (!shouldPollNow()) {
                nextDueAt = now + checkInterval;
                return;
            }

            inFlight = true;
            inFlightSince = now;

            try {
                checkForUpdates();
            } catch (e) {
                inFlight = false;
                nextDueAt = now + checkInterval;
            }
        }

        // Called when this tab comes back to the foreground: check straight
        // away rather than waiting out the rest of the current interval.
        runImmediateCheck = function () {
            if (inFlight) {
                return;
            }
            nextDueAt = 0;
            tick();
        };

        startTicker(tick, Math.max(250, Math.min(500, Math.floor(checkInterval / 4))));
        tick();
    }

    /** Resolves with the promise's value, or `fallback` if it hangs or fails. */
    function withTimeout(promise, ms, fallback) {
        return new Promise(function (resolve) {
            var done = false;
            var timer = setTimeout(function () {
                if (!done) {
                    done = true;
                    resolve(fallback);
                }
            }, ms);

            function finish(value) {
                if (!done) {
                    done = true;
                    clearTimeout(timer);
                    resolve(value);
                }
            }

            promise.then(finish, function () { finish(fallback); });
        });
    }

    function init() {
        // Every visitor, editor or not, goes through the same browser_settings
        // check, so the per-browser Enable/Disable toggles actually control
        // whether this tab polls.
        var settings = ReloadifySync.browser_settings || {};
        var baseName = detectBrowserName();

        // Both probes can hang in some private-mode / blocked-storage setups.
        // Without a timeout polling would simply never start.
        withTimeout(resolveBrowserName(baseName), 2500, baseName).then(function (browserName) {
            withTimeout(detectIncognito(), 2500, false).then(function (isIncognito) {
                var mode = isIncognito ? 'incognito' : 'normal';
                var rule = settings[browserName];
                var allowed = !!(rule && rule[mode]);

                startPolling(allowed);
            });
        });
    }

    init();

})(jQuery);
