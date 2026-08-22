(function ($) {
    'use strict';

    if (typeof ReloadifySync === 'undefined') {
        return;
    }

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

    var ACTIVE_TAB_KEY = 'reloadify_active_tab';
    var ACTIVE_TAB_STALE_MS = 6000;
    var ACTIVE_TAB_HEARTBEAT_MS = 2000;
    var lastFocusTime = 0;

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

    var moduleAllTabsReload = (ReloadifySync.all_tabs_reload_enabled === '1' || ReloadifySync.all_tabs_reload_enabled === true);

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

        if (typeof document !== 'undefined' && document.visibilityState === 'visible') {
            return true;
        }

        var claim = readActiveClaim();
        if (claim && claim.id === TAB_ID) {
            return true;
        }

        return false;
    }

    function writeActiveClaim() {
        try {

            localStorage.setItem(ACTIVE_TAB_KEY, JSON.stringify({
                id: TAB_ID,
                ts: Date.now(),
                ft: lastFocusTime
            }));
        } catch (e) {

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

    ['pagehide', 'beforeunload'].forEach(function (evt) {
        window.addEventListener(evt, function () {
            if (isTabActive) {
                try {
                    localStorage.removeItem(ACTIVE_TAB_KEY);
                } catch (e) {

                }
            }
        });
    });

    (function initialActiveTabState() {
        var claim = readActiveClaim();
        var now = Date.now();
        var claimIsOurs = claim && claim.id === TAB_ID;
        var claimIsStale = !claim || (now - claim.ts) > ACTIVE_TAB_STALE_MS;
        var focused = isThisWindowFocused();

        if (claimIsOurs) {
            lastFocusTime = claim.ft || now;
            becomeActiveTab();
        } else if (claimIsStale && focused) {

            lastFocusTime = now;
            becomeActiveTab();
        }

        if (claimIsOurs && !heartbeatTimer) {
            startHeartbeat();
        }

    })();

    if (typeof BroadcastChannel !== 'undefined') {
        try {
            broadcastChannel = new BroadcastChannel('reloadify_sync');
            broadcastChannel.onmessage = function (event) {
                if (event.data.action === 'reload_triggered') {

                    if (moduleAllTabsReload) {

                        doReload(event.data.reloadMode);
                    } else if (isTabActive || thisTabHasActiveClaim()) {

                        doReload(event.data.reloadMode);
                    } else {
                    }
                }
            };
        } catch (e) {
            broadcastChannel = null;
        }
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

        var checkInterval = parseInt(ReloadifySync.interval, 10) || 500;
        var nonce = ReloadifySync.nonce;

        var reloadMode = ReloadifySync.reload_mode === 'hard' ? 'hard' : 'soft';
        var allTabsReload = ReloadifySync.all_tabs_reload_enabled === '1' || ReloadifySync.all_tabs_reload_enabled === true;
        var timestampUrl = ReloadifySync.timestamp_url;

        var useStaticFile = !!timestampUrl;

        var currentTimestamp = parseInt(ReloadifySync.timestamp, 10) || 0;
        var hasSyncedBaseline = false;

        function applyFreshSettings(allTabsValue, reloadModeValue) {
            if (allTabsValue !== undefined && allTabsValue !== null) {
                allTabsReload = (allTabsValue === '1' || allTabsValue === 1 || allTabsValue === true);
                moduleAllTabsReload = allTabsReload;
            }
            if (reloadModeValue) {
                reloadMode = reloadModeValue === 'hard' ? 'hard' : 'soft';
            }
        }

        var checksSinceAjaxVerify = 0;
        var AJAX_VERIFY_EVERY = 10;
        var scheduledTimeoutId = null;

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

                var shouldReload = isTabActive || thisTabHasActiveClaim();

                if (!allTabsReload && !shouldReload) {
                    return false;
                }

                if (shouldReload && broadcastChannel) {
                    try {
                        broadcastChannel.postMessage({ action: 'reload_triggered', reloadMode: reloadMode });
                    } catch (e) {

                    }
                }

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

                            var shouldReload = isTabActive || thisTabHasActiveClaim();

                            if (!allTabsReload && !shouldReload) {
                                scheduleNext(checkInterval);
                                return;
                            }

                            if (shouldReload && broadcastChannel) {
                                try {
                                    broadcastChannel.postMessage({ action: 'reload_triggered', reloadMode: reloadMode });
                                } catch (e) {

                                }
                            }

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

                startPolling(allowed);
            });
        });
    }

    init();

})(jQuery);
