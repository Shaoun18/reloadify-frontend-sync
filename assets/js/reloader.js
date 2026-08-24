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
     * Private/incognito detection
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
