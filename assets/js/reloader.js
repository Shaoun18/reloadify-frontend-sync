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
    var ACTIVE_TAB_STALE_MS = 6000;
    var ACTIVE_TAB_HEARTBEAT_MS = 2000;

    var lastFocusTime = 0;
    var lastActiveTabId = null;

    var TAB_ID = (function () {
        try {
            var existing = sessionStorage.getItem('reloadify_tab_id');
            if (existing) {
                return existing;
            }
            var id = 'rt-' + Date.now() + '-' + Math.random().toString(36).slice(2);
            sessionStorage.setItem('reloadify_tab_id', id);
            return id;
        } catch (e) {
            return 'rt-' + Date.now() + '-' + Math.random().toString(36).slice(2);
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

    function thisTabHasActiveClaim() {
        var claim = readActiveClaim();
        return !!(claim && claim.id === TAB_ID);
    }

    function writeActiveClaim() {
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
            var claim = readActiveClaim();
            if (isTabActive || (claim && claim.id === TAB_ID)) {
                writeActiveClaim();
            }
        }, ACTIVE_TAB_HEARTBEAT_MS);
    }

    function becomeActiveTab() {
        var wasActive = isTabActive;
        isTabActive = true;
        lastFocusTime = Date.now();
        lastActiveTabId = TAB_ID;
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

    function shouldThisTabReload() {
        if (allTabsReload) {
            return true;
        }

        if (isTabActive || thisTabHasActiveClaim()) {
            return true;
        }

        // Fallback for windows where localStorage is unavailable (private
        // browsing): if nothing has claimed the slot and this tab was the
        // last one the user touched, treat it as the active one.
        return lastActiveTabId === TAB_ID && !readActiveClaim();
    }

    window.addEventListener('storage', function (e) {
        if (e.key !== ACTIVE_TAB_KEY) {
            return;
        }

        if (e.newValue === null) {
            if (isThisWindowFocused() && !isTabActive) {
                becomeActiveTab();
            }
            return;
        }

        var claim = readActiveClaim();

        if (claim && claim.id !== TAB_ID && isTabActive) {
            var otherTabFocusTime = claim.ft || 0;
            if (otherTabFocusTime > lastFocusTime) {
                releaseActiveTab();
            }
        }
    });

    window.addEventListener('focus', function () {
        becomeActiveTab();
        catchUpIfNeeded();
    });

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            if (isTabActive || thisTabHasActiveClaim()) {
                writeActiveClaim();
            }
            if (isThisWindowFocused()) {
                becomeActiveTab();
            }
            catchUpIfNeeded();
        }
    });

    ['click', 'keydown', 'pointerdown', 'touchstart'].forEach(function (evt) {
        document.addEventListener(evt, function () {
            var wasAlreadyActive = isTabActive;
            becomeActiveTab();
            if (!wasAlreadyActive) {
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
    var isReloadingSelf = false;
    var RECLAIM_KEY = 'reloadify_reclaim';

    ['pagehide', 'beforeunload'].forEach(function (evt) {
        window.addEventListener(evt, function () {
            // A real close/navigate releases the slot straight away so a
            // sibling tab can take it. A reload keeps it.
            if (isTabActive && !isReloadingSelf) {
                try {
                    localStorage.removeItem(ACTIVE_TAB_KEY);
                } catch (e) {}
            }
        });
    });

    (function initialActiveTabState() {
        var claim = readActiveClaim();
        var now = Date.now();
        var claimIsOurs = claim && claim.id === TAB_ID;
        var claimIsStale = !claim || (now - claim.ts) > ACTIVE_TAB_STALE_MS;
        var focused = isThisWindowFocused();

        // Did this tab just reload itself? sessionStorage is per-tab and
        // survives a reload, so it answers that even when localStorage is
        // unavailable (private windows).
        var justReloaded = false;
        try {
            justReloaded = '1' === sessionStorage.getItem(RECLAIM_KEY);
            if (justReloaded) {
                sessionStorage.removeItem(RECLAIM_KEY);
            }
        } catch (e) {}

        if (claimIsOurs) {
            lastFocusTime = claim.ft || now;
            becomeActiveTab();
        } else if (justReloaded || (claimIsStale && focused)) {
            // Re-take the slot without needing focus: the user is most likely
            // still in wp-admin, and this tab is the one they were last
            // looking at on the front end.
            lastFocusTime = justReloaded ? now : now;
            becomeActiveTab();
        }

        if (claimIsOurs && !heartbeatTimer) {
            startHeartbeat();
        }
    })();

    function doReload(mode) {
        // Hold on to the active-tab claim across the reload (see the
        // isReloadingSelf comment above).
        isReloadingSelf = true;
        try {
            sessionStorage.setItem(RECLAIM_KEY, '1');
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

                if (allTabsReload || isTabActive || thisTabHasActiveClaim()) {
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

    function startPolling(allowed) {
        if (!allowed) {
            return;
        }

        var checkInterval = parseInt(ReloadifySync.interval, 10) || 2000;
        var nonce = ReloadifySync.nonce;
        var timestampUrl = ReloadifySync.timestamp_url;

        var useStaticFile = !!timestampUrl;

        var currentTimestamp = parseInt(ReloadifySync.timestamp, 10) || 0;
        var hasSyncedBaseline = false;

        var checksSinceAjaxVerify = 0;
        var AJAX_VERIFY_EVERY = 10; // roughly every 10 * checkInterval
        var scheduledTimeoutId = null;

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
            scheduledTimeoutId = setTimeout(checkForUpdates, delay);
        }

        function handleTimestamp(newTimestamp) {
            if (!hasSyncedBaseline) {
                hasSyncedBaseline = true;
                currentTimestamp = newTimestamp;
                return false;
            }

            if (newTimestamp > currentTimestamp) {
                currentTimestamp = newTimestamp;

                if (!shouldThisTabReload()) {
                    return false;
                }

                announceReload();
                doReload(reloadMode);
                return true;
            }
            return false;
        }

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

                    applyFreshSettings(response.atr, response.rm);

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
                    useStaticFile = false;
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
                    if (response && response.success && response.data) {
                        applyFreshSettings(response.data.all_tabs_reload_enabled, response.data.reload_mode);

                        if (!hasSyncedBaseline) {
                            hasSyncedBaseline = true;
                            currentTimestamp = parseInt(response.data.new_timestamp, 10) || currentTimestamp;
                            scheduleNext(checkInterval);
                            return;
                        }

                        if (response.data.reload) {
                            currentTimestamp = parseInt(response.data.new_timestamp, 10) || currentTimestamp;

                            if (!shouldThisTabReload()) {
                                scheduleNext(checkInterval);
                                return;
                            }

                            announceReload();
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

        // Called when this tab comes back to the foreground: check straight
        // away rather than waiting out the rest of the current interval.
        runImmediateCheck = function () {
            if (scheduledTimeoutId) {
                clearTimeout(scheduledTimeoutId);
                scheduledTimeoutId = null;
            }
            checkForUpdates();
        };

        checkForUpdates();
    }

    function init() {
        // Every visitor, editor or not, goes through the same browser_settings
        // check, so the per-browser Enable/Disable toggles actually control
        // whether this tab polls.
        var settings = ReloadifySync.browser_settings || {};

        resolveBrowserName(detectBrowserName()).then(function (browserName) {
            detectIncognito().then(function (isIncognito) {
                var mode = isIncognito ? 'incognito' : 'normal';
                var rule = settings[browserName];
                var allowed = !!(rule && rule[mode]);

                startPolling(allowed);
            });
        });
    }

    init();

})(jQuery);
