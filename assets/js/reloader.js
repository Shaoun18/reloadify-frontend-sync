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
     * This used to be a single check (storage.estimate() reporting a small
     * quota) -- but Chrome now deliberately reports the SAME quota in normal
     * and Incognito windows for most sites (its "predictable reported
     * storage quota" mitigation), and quota-based detection never worked on
     * Firefox private windows to begin with. That single check silently
     * failing closed is exactly why this was firing wrong: every session,
     * private or not, was resolving to "normal" and only ever consulting
     * the Normal toggle -- which is why turning Incognito off didn't stop a
     * reload, and turning it on didn't start one.
     *
     * Fix: run several independent signals and go with the majority,
     * so no single patched API can flip the result on its own. Any probe
     * that isn't supported (or gives an inconclusive answer) abstains
     * rather than voting, instead of being silently forced to "false" like
     * before.
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
                    return false; // nothing conclusive — same safe default as before
                }
                var privateVotes = votes.filter(Boolean).length;
                return privateVotes > votes.length / 2;
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
  
        var useStaticFile = !!timestampUrl;

        var currentTimestamp = parseInt(ReloadifySync.timestamp, 10) || 0;
        var hasSyncedBaseline = false;

        
        var checksSinceAjaxVerify = 0;
        var AJAX_VERIFY_EVERY = 10; // roughly every 10 * checkInterval

        function scheduleNext(delay) {
            setTimeout(checkForUpdates, delay);
        }

        function handleTimestamp(newTimestamp) {
            if (!hasSyncedBaseline) {
              
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
        // NOTE: this used to unconditionally start polling for any signed-in
        // editor/admin, bypassing the per-browser Enable/Disable toggles
        // entirely ("no settings dependency"). That's why turning a browser
        // off did nothing while testing logged in — the toggle was never
        // even being read. Every visitor, editor or not, now goes through
        // the same browser_settings check below, so Enable/Disable actually
        // controls whether this tab polls.
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
