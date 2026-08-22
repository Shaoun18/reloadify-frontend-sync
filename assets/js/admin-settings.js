(function () {
    'use strict';

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var render = wp.element.render;
    var __ = wp.i18n.__;

    wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(ReloadifyAdmin.nonce));

    var BROWSERS = ReloadifyAdmin.browsers || [];
    var LABELS = ReloadifyAdmin.browserLabels || {};

    // Two-tone gradients + a distinct glyph per browser. These are original
    // badges, not reproductions of any browser's actual trademarked logo --
    // this plugin ships to WordPress.org, and brand marks aren't ours to use.
    var BROWSER_THEME = {
        chrome:    { from: '#4285F4', to: '#34A853', glyph: 'ring' },
        brave:     { from: '#FB542B', to: '#F97316', glyph: 'shield' },
        edge:      { from: '#0A84D3', to: '#00B8D9', glyph: 'wave' },
        firefox:   { from: '#FF7139', to: '#FFB03A', glyph: 'flame' },
        safari:    { from: '#3F87F5', to: '#1D4ED8', glyph: 'compass' },
        opera:     { from: '#FF1B2D', to: '#E60039', glyph: 'circle' },
        ucbrowser: { from: '#1FA3FF', to: '#0F6FD1', glyph: 'bolt' }
    };

    function glyphPath(glyph) {
        switch (glyph) {
            case 'ring':
                return el('circle', { cx: 16, cy: 16, r: 6, fill: 'none', stroke: '#fff', strokeWidth: 3 });
            case 'shield':
                return el('path', { d: 'M16 8 L23 11 V17 C23 21 20 24 16 25 C12 24 9 21 9 17 V11 Z', fill: '#fff', opacity: 0.92 });
            case 'wave':
                return el('path', { d: 'M8 18c2-5 6-5 8-2s6 3 8-2', fill: 'none', stroke: '#fff', strokeWidth: 2.6, strokeLinecap: 'round' });
            case 'flame':
                return el('path', { d: 'M16 9c2 3-1 4-1 6a3 3 0 106 0c0-1-.5-2-1-3 2 1 3 4 3 6a7 7 0 11-14 0c0-4 3-6 7-9z', fill: '#fff', opacity: 0.92 });
            case 'compass':
                return el(
                    'g',
                    null,
                    el('circle', { cx: 16, cy: 16, r: 7, fill: 'none', stroke: '#fff', strokeWidth: 2 }),
                    el('path', { d: 'M18.5 13.5 L14 18 L18.5 13.5 L18.5 13.5 Z M18.5 13.5 L14 15.8 Z', fill: 'none' }),
                    el('path', { d: 'M19 13 L15 15 L13 19 L17 17 Z', fill: '#fff' })
                );
            case 'bolt':
                return el('path', { d: 'M18 8 L11 18 h4 l-1 6 8-11 h-4 z', fill: '#fff', opacity: 0.92 });
            default:
                return el('circle', { cx: 16, cy: 16, r: 5, fill: '#fff', opacity: 0.85 });
        }
    }

    var PERF_GROUPS = {
        runtime: {
            title: __('Applied automatically by this plugin', 'reloadify-frontend-sync'),
            hint: __('Changed live via ini_set() on every request \u2014 no server access needed.', 'reloadify-frontend-sync'),
            keys: ['memory_limit', 'max_execution_time', 'opcache.enable', 'opcache.validate_timestamps', 'opcache.revalidate_freq']
        },
        host: {
            title: __('Requires your host / server config', 'reloadify-frontend-sync'),
            hint: __('PHP locks these before WordPress loads. Auto-attempt below writes .user.ini / .htaccess.', 'reloadify-frontend-sync'),
            keys: ['max_input_time', 'post_max_size', 'upload_max_filesize', 'realpath_cache_size', 'realpath_cache_ttl']
        },
        opcache: {
            title: __('opcache memory sizing — danger zone', 'reloadify-frontend-sync'),
            hint: __('These size PHP\u2019s memory once at startup \u2014 only a real php.ini edit can change them.', 'reloadify-frontend-sync'),
            keys: ['opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files']
        }
    };

    var PERF_LABELS = {
        'memory_limit': 'memory_limit',
        'max_execution_time': 'max_execution_time',
        'max_input_time': 'max_input_time',
        'post_max_size': 'post_max_size',
        'upload_max_filesize': 'upload_max_filesize',
        'opcache.enable': 'opcache.enable',
        'opcache.memory_consumption': 'opcache.memory_consumption',
        'opcache.interned_strings_buffer': 'opcache.interned_strings_buffer',
        'opcache.max_accelerated_files': 'opcache.max_accelerated_files',
        'opcache.validate_timestamps': 'opcache.validate_timestamps',
        'opcache.revalidate_freq': 'opcache.revalidate_freq',
        'realpath_cache_size': 'realpath_cache_size',
        'realpath_cache_ttl': 'realpath_cache_ttl'
    };

    var HTACCESS_ELIGIBLE = ['memory_limit', 'max_execution_time', 'max_input_time', 'post_max_size', 'upload_max_filesize'];

    function BrowserIcon(props) {
        var theme = BROWSER_THEME[props.name] || { from: '#666', to: '#444', glyph: 'circle' };
        var gradId = 'reloadify-grad-' + props.name;
        return el(
            'svg',
            { className: 'reloadify-browser-icon', viewBox: '0 0 32 32', width: 34, height: 34 },
            el(
                'defs',
                null,
                el(
                    'linearGradient',
                    { id: gradId, x1: '0%', y1: '0%', x2: '100%', y2: '100%' },
                    el('stop', { offset: '0%', stopColor: theme.from }),
                    el('stop', { offset: '100%', stopColor: theme.to })
                )
            ),
            el('circle', { cx: 16, cy: 16, r: 16, fill: 'url(#' + gradId + ')' }),
            glyphPath(theme.glyph)
        );
    }

    function Toast(props) {
        if (!props.toast) return null;
        var cls = 'reloadify-toast reloadify-toast--' + (props.toast.type === 'error' ? 'error' : 'success');
        return el('div', { className: cls, role: 'status' },
            el('span', { className: 'reloadify-toast-dot' }),
            props.toast.message
        );
    }

    function Switch(props) {
        return el(
            'label',
            { className: 'reloadify-switch' + (props.large ? ' reloadify-switch--lg' : '') },
            el('input', { type: 'checkbox', checked: !!props.checked, onChange: props.onChange, disabled: !!props.disabled }),
            el('span', { className: 'reloadify-switch-slider' })
        );
    }

    /**
     * Small "i" badge next to a section title. Hover (or focus, for
     * keyboard/screen-reader users) shows the explanation that used to sit
     * as a permanent paragraph under every heading -- same information,
     * available on demand instead of always taking up space.
     */
    function InfoIcon(props) {
        var stateOpen = useState(false);
        var open = stateOpen[0], setOpen = stateOpen[1];

        return el(
            'span',
            { className: 'reloadify-info-wrap' },
            el(
                'span',
                {
                    className: 'reloadify-info-icon',
                    tabIndex: 0,
                    'aria-label': props.text,
                    onMouseEnter: function () { setOpen(true); },
                    onMouseLeave: function () { setOpen(false); },
                    onFocus: function () { setOpen(true); },
                    onBlur: function () { setOpen(false); },
                },
                '\u24d8'
            ),
            open && el('span', { className: 'reloadify-tooltip', role: 'tooltip' }, props.text)
        );
    }

    function SectionTitle(props) {
        return el(
            'div',
            { className: 'reloadify-section-title-row' },
            el('h2', null, props.text),
            props.hint && el(InfoIcon, { text: props.hint })
        );
    }

        /* ---------------- Reload tab ---------------- */

    function BrowserCard(props) {
        var row = props.value || { normal: false, incognito: false };

        function set(field, val) {
            var next = { normal: row.normal, incognito: row.incognito };
            next[field] = val;
            props.onChange(props.name, next);
        }

        var enabled = row.normal || row.incognito;

        return el(
            'div',
            { className: 'reloadify-browser-card' + (enabled ? ' is-on' : '') },
            el(BrowserIcon, { name: props.name }),
            el('div', { className: 'reloadify-browser-card-name' }, LABELS[props.name] || props.name),
            el(
                'div',
                { className: 'reloadify-browser-card-row' },
                el('span', null, __('Normal', 'reloadify-frontend-sync')),
                el(Switch, { checked: row.normal, onChange: function (e) { set('normal', e.target.checked); } })
            ),
            el(
                'div',
                { className: 'reloadify-browser-card-row' },
                el('span', null, __('Incognito', 'reloadify-frontend-sync')),
                el(Switch, { checked: row.incognito, onChange: function (e) { set('incognito', e.target.checked); } })
            )
        );
    }

    function timeAgo(unixSeconds) {
        var diff = Math.max(0, Math.floor(Date.now() / 1000) - unixSeconds);
        if (diff < 10) return __('just now', 'reloadify-frontend-sync');
        if (diff < 60) return diff + 's ' + __('ago', 'reloadify-frontend-sync');
        if (diff < 3600) return Math.floor(diff / 60) + 'm ' + __('ago', 'reloadify-frontend-sync');
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ' + __('ago', 'reloadify-frontend-sync');
        return Math.floor(diff / 86400) + 'd ' + __('ago', 'reloadify-frontend-sync');
    }

    function ReloadTab(props) {
        var settings = props.settings;
        var checkingState = useState(false);
        var checking = checkingState[0], setChecking = checkingState[1];

        function checkNow() {
            setChecking(true);
            props.onExpire();
            setTimeout(function () { setChecking(false); }, 500);
        }

        function updateBrowser(name, next) {
            var browsers = Object.assign({}, settings.browsers);
            browsers[name] = next;
            props.onChange(Object.assign({}, settings, { browsers: browsers }));
        }

        var enabledCount = BROWSERS.filter(function (name) {
            var r = settings.browsers[name];
            return r && (r.normal || r.incognito);
        }).length;

        return el(
            'div',
            null,
            el(
                'div',
                { className: 'reloadify-stat-row' },
                el(
                    'div',
                    { className: 'reloadify-stat-card reloadify-stat-card--accent' },
                    el(
                        'div',
                        { className: 'reloadify-stat-label-row' },
                        el('div', { className: 'reloadify-stat-label' }, __('Developer Mode', 'reloadify-frontend-sync')),
                        el(InfoIcon, { text: __('Off by default \u2014 leaving it on adds real load to a live site. Now stays on until you switch it off yourself.', 'reloadify-frontend-sync') })
                    ),
                    el('div', { className: 'reloadify-stat-value' }, settings.dev_mode_enabled ? __('On', 'reloadify-frontend-sync') : __('Off', 'reloadify-frontend-sync')),
                    el(Switch, {
                        large: true,
                        checked: settings.dev_mode_enabled,
                        onChange: function () { props.onChange(Object.assign({}, settings, { dev_mode_enabled: !settings.dev_mode_enabled })); }
                    })
                ),
                el(
                    'div',
                    { className: 'reloadify-stat-card' },
                    el('div', { className: 'reloadify-stat-label' }, __('Browsers enabled', 'reloadify-frontend-sync')),
                    el('div', { className: 'reloadify-stat-value' }, enabledCount + ' / ' + BROWSERS.length)
                ),
                el(
                    'div',
                    { className: 'reloadify-stat-card' },
                    el('div', { className: 'reloadify-stat-label' }, __('Reload mode', 'reloadify-frontend-sync')),
                    el('div', { className: 'reloadify-stat-value' }, settings.reload_mode === 'hard' ? __('Hard', 'reloadify-frontend-sync') : __('Soft', 'reloadify-frontend-sync'))
                ),
                el(
                    'div',
                    { className: 'reloadify-stat-card' },
                    el(
                        'div',
                        { className: 'reloadify-stat-label-row' },
                        el('div', { className: 'reloadify-stat-label' }, __('Last change detected', 'reloadify-frontend-sync')),
                        el(InfoIcon, { text: __('Ticks up on any wp-admin save. Not resetting on Check now usually means the save didn\u2019t submit.', 'reloadify-frontend-sync') })
                    ),
                    el('div', { className: 'reloadify-stat-value', style: { fontSize: 15 } }, settings.last_change_detected ? timeAgo(settings.last_change_detected) : '\u2014'),
                    el('button', {
                        type: 'button',
                        className: 'button button-small',
                        style: { marginTop: 8 },
                        onClick: checkNow,
                        disabled: checking
                    }, checking ? __('Checking\u2026', 'reloadify-frontend-sync') : __('Check now', 'reloadify-frontend-sync'))
                )
            ),

            el(
                'div',
                { className: 'reloadify-section' },
                el('h2', null, __('Reload behavior', 'reloadify-frontend-sync')),
                el(
                    'div',
                    { className: 'reloadify-reload-mode-row' },
                    el(
                        'label',
                        { className: 'reloadify-radio-card' + (settings.reload_mode !== 'hard' ? ' is-selected' : '') },
                        el('input', { type: 'radio', name: 'reload_mode', checked: settings.reload_mode !== 'hard', onChange: function () { props.onChange(Object.assign({}, settings, { reload_mode: 'soft' })); } }),
                        el('strong', null, __('Soft reload', 'reloadify-frontend-sync')),
                        el('p', null, __('Standard page reload. Fast, uses the browser cache like a normal refresh.', 'reloadify-frontend-sync'))
                    ),
                    el(
                        'label',
                        { className: 'reloadify-radio-card' + (settings.reload_mode === 'hard' ? ' is-selected' : '') },
                        el('input', { type: 'radio', name: 'reload_mode', checked: settings.reload_mode === 'hard', onChange: function () { props.onChange(Object.assign({}, settings, { reload_mode: 'hard' })); } }),
                        el('strong', null, __('Hard reload', 'reloadify-frontend-sync')),
                        el('p', null, __('Appends a cache-busting parameter so the page is fetched fresh instead of served from cache. Use this if soft reload sometimes shows stale content.', 'reloadify-frontend-sync'))
                    )
                )
            ),

            el(
                'div',
                { className: 'reloadify-section' },
                el(SectionTitle, { text: __('Browsers & windows', 'reloadify-frontend-sync'), hint: __('Incognito detection is best-effort, not a guarantee.', 'reloadify-frontend-sync') }),
                el(
                    'div',
                    { className: 'reloadify-browser-grid' },
                    BROWSERS.map(function (name) {
                        return el(BrowserCard, { key: name, name: name, value: settings.browsers[name], onChange: updateBrowser });
                    })
                )
            )
        );
    }

    /* ---------------- Performance tab ---------------- */

    function buildIniSnippet(desired) {
        return Object.keys(desired).map(function (key) {
            return key + '=' + desired[key];
        }).join('\n');
    }

    function buildHtaccessSnippet(desired) {
        return HTACCESS_ELIGIBLE.map(function (key) {
            return 'php_value ' + key + ' ' + desired[key];
        }).join('\n');
    }

    function SnippetModal(props) {
        var ini = buildIniSnippet(props.desired);
        var htaccess = buildHtaccessSnippet(props.desired);

        function copy(text, label) {
            navigator.clipboard.writeText(text).then(function () {
                props.onCopied(label);
            }).catch(function () {
                props.onCopied(null);
            });
        }

        return el(
            'div',
            { className: 'reloadify-modal-overlay', onClick: props.onClose },
            el(
                'div',
                { className: 'reloadify-modal reloadify-modal--wide', onClick: function (e) { e.stopPropagation(); } },
                el(
                    'div',
                    { className: 'reloadify-modal-header' },
                    el('h2', null, __('Server config snippets', 'reloadify-frontend-sync')),
                    el('button', { className: 'reloadify-modal-close', onClick: props.onClose, 'aria-label': 'Close' }, '\u00d7')
                ),
                el(
                    'div',
                    { className: 'reloadify-modal-body' },
                    el('p', { className: 'reloadify-hint' }, __('These values cannot be set by any WordPress plugin at runtime \u2014 PHP locks them before WordPress loads. Paste the block that matches your hosting setup, or send it to your host.', 'reloadify-frontend-sync')),

                    el('h3', null, __('php.ini / .user.ini', 'reloadify-frontend-sync')),
                    el('p', { className: 'reloadify-hint' }, __('Works for every directive below, including opcache and realpath cache. Use this if you manage php.ini directly, or your host supports .user.ini (common on PHP-FPM).', 'reloadify-frontend-sync')),
                    el('pre', { className: 'reloadify-code' }, ini),
                    el('button', { className: 'button', onClick: function () { copy(ini, 'php.ini'); } }, __('Copy', 'reloadify-frontend-sync')),

                    el('h3', null, __('.htaccess (Apache + mod_php only)', 'reloadify-frontend-sync')),
                    el('p', { className: 'reloadify-hint' }, __('Only covers the directives Apache is allowed to set per-directory. opcache.* and realpath_cache_* cannot go here \u2014 and this block won\u2019t work at all on Nginx or PHP-FPM.', 'reloadify-frontend-sync')),
                    el('pre', { className: 'reloadify-code' }, htaccess),
                    el('button', { className: 'button', onClick: function () { copy(htaccess, '.htaccess'); } }, __('Copy', 'reloadify-frontend-sync'))
                ),
                el(
                    'div',
                    { className: 'reloadify-modal-footer' },
                    el('button', { className: 'button button-primary', onClick: props.onClose }, __('Done', 'reloadify-frontend-sync'))
                )
            )
        );
    }

    var AUTO_WRITE_KEYS = ['max_input_time', 'post_max_size', 'upload_max_filesize', 'realpath_cache_size', 'realpath_cache_ttl'];
    var OPCACHE_KEYS = ['opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files'];

    function DirectiveCard(props) {
        var isRuntime = props.runtime;
        var isAutoWritable = !isRuntime && AUTO_WRITE_KEYS.indexOf(props.name) !== -1;
        var isOpcache = OPCACHE_KEYS.indexOf(props.name) !== -1;
        var enabled = isRuntime ? !!props.runtimeEnabled : null;

        var badge;
        if (isRuntime) {
            badge = el('span', { className: 'reloadify-badge reloadify-badge--live' }, __('Live', 'reloadify-frontend-sync'));
        } else if (isOpcache) {
            badge = el('span', { className: 'reloadify-badge reloadify-badge--danger' }, __('Local dev only', 'reloadify-frontend-sync'));
        } else if (isAutoWritable) {
            badge = el('span', { className: 'reloadify-badge reloadify-badge--auto' }, __('Auto-attempt', 'reloadify-frontend-sync'));
        } else {
            badge = el('span', { className: 'reloadify-badge reloadify-badge--host' }, __('Manual only', 'reloadify-frontend-sync'));
        }

        var matchStatus = null;
        if (isRuntime && enabled) {
            var matches = String(props.live) === String(props.value);
            matchStatus = el(
                'div',
                { className: 'reloadify-perf-status ' + (matches ? 'is-match' : 'is-mismatch') },
                matches
                    ? __('\u2713 Matches the live server right now.', 'reloadify-frontend-sync')
                    : __('\u2717 Not applied yet on this page load \u2014 save your changes, then reload this page to confirm.', 'reloadify-frontend-sync')
            );
        }

        return el(
            'div',
            { className: 'reloadify-perf-card' },
            el(
                'div',
                { className: 'reloadify-perf-card-top' },
                el('code', { title: PERF_LABELS[props.name] }, PERF_LABELS[props.name]),
                badge
            ),
            el(
                'div',
                { className: 'reloadify-perf-current' },
                __('Current: ', 'reloadify-frontend-sync'),
                el('strong', null, props.live || __('(default)', 'reloadify-frontend-sync'))
            ),
            matchStatus,
            el(
                'div',
                { className: 'reloadify-perf-controls' },
                el('input', {
                    type: 'text',
                    className: 'reloadify-perf-input',
                    value: props.value,
                    onChange: function (e) { props.onChange(props.name, e.target.value); }
                }),
                isRuntime && el(Switch, {
                    checked: enabled,
                    onChange: function (e) { props.onToggleRuntime(props.name, e.target.checked); }
                })
            )
        );
    }

    function ApplyResults(props) {
        if (!props.results) return null;
        var rows = [
            { key: 'user_ini', label: '.user.ini' },
            { key: 'htaccess', label: '.htaccess' }
        ];
        return el(
            'div',
            { className: 'reloadify-apply-results' },
            rows.map(function (row) {
                var r = props.results[row.key];
                if (!r) return null;
                return el(
                    'div',
                    { key: row.key, className: 'reloadify-apply-result-row' },
                    el('span', { className: 'reloadify-badge ' + (r.success ? 'reloadify-badge--live' : 'reloadify-badge--host') }, r.success ? __('Written', 'reloadify-frontend-sync') : __('Not applied', 'reloadify-frontend-sync')),
                    el('strong', null, row.label),
                    el('p', null, r.message)
                );
            })
        );
    }

    /**
     * On by default the moment the plugin is activated -- unlike everything
     * else in this tab, which stays opt-in. Deliberately does NOT show a
     * fixed "X% faster" number: nobody can honestly promise one, since the
     * real effect depends on the theme, other plugins, and hosting. What it
     * shows instead is the exact, short list of what's actually switched on.
     */
    function SpeedBoostCard(props) {
        var speed = props.speed;
        var stateSaving = useState(false);
        var saving = stateSaving[0], setSaving = stateSaving[1];

        function toggle() {
            var next = !speed.enabled;
            setSaving(true);
            wp.apiFetch({ path: '/reloadify/v1/speed', method: 'POST', data: { enabled: next } })
                .then(function (response) {
                    setSaving(false);
                    props.onChange(response);
                    props.onToast('success', response.enabled
                        ? __('Speed Boost turned on.', 'reloadify-frontend-sync')
                        : __('Speed Boost turned off.', 'reloadify-frontend-sync'));
                })
                .catch(function () {
                    setSaving(false);
                    props.onToast('error', __('Could not update Speed Boost.', 'reloadify-frontend-sync'));
                });
        }

        return el(
            'div',
            { className: 'reloadify-section reloadify-speed-card' },
            el(
                'div',
                { className: 'reloadify-speed-card-head' },
                el('h2', null, __('Speed Boost', 'reloadify-frontend-sync')),
                el(Switch, { large: true, checked: speed.enabled, onChange: toggle, disabled: saving })
            )
        );
    }

    /**
     * On by default: deleting the plugin from the Plugins screen (not just
     * deactivating it) also removes its settings and the uploads/reloadify-reload
     * folder, for a clean uninstall. Turn off to keep settings around for a
     * reinstall later.
     */
    function DeleteOnUninstallCard(props) {
        var cleanup = props.cleanup;
        var stateSaving = useState(false);
        var saving = stateSaving[0], setSaving = stateSaving[1];

        function toggle() {
            var next = !cleanup.enabled;
            setSaving(true);
            wp.apiFetch({ path: '/reloadify/v1/cleanup', method: 'POST', data: { enabled: next } })
                .then(function (response) {
                    setSaving(false);
                    props.onChange(response);
                    props.onToast('success', response.enabled
                        ? __('Will delete settings & the reloadify-reload folder if the plugin is deleted.', 'reloadify-frontend-sync')
                        : __('Settings & the reloadify-reload folder will be kept if the plugin is deleted.', 'reloadify-frontend-sync'));
                })
                .catch(function () {
                    setSaving(false);
                    props.onToast('error', __('Could not update this setting.', 'reloadify-frontend-sync'));
                });
        }

        return el(
            'div',
            { className: 'reloadify-section reloadify-speed-card' },
            el(
                'div',
                { className: 'reloadify-speed-card-head' },
                el('h2', null, __('Delete Data on Uninstall', 'reloadify-frontend-sync')),
                el(Switch, { large: true, checked: cleanup.enabled, onChange: toggle, disabled: saving })
            )
        );
    }

    /**
     * On by default. Backend/frontend media weight, not PHP settings: new
     * image uploads get WebP/AVIF versions (whichever this server's image
     * library actually supports), quality is capped at a visually-lossless
     * level, existing library images are backfilled gradually in the
     * background, and video gets compressed in the background too if
     * ffmpeg is available on the server. Same minimal title+toggle style as
     * Speed Boost -- the per-server specifics are in the REST payload
     * (`items`) for anyone who wants to check exactly what's active here,
     * without cluttering the card itself.
     */
    function MediaOptimizationCard(props) {
        var media = props.media;
        var stateSaving = useState(false);
        var saving = stateSaving[0], setSaving = stateSaving[1];

        var stateFormatSaving = useState(false);
        var formatSaving = stateFormatSaving[0], setFormatSaving = stateFormatSaving[1];

        var stateRunning = useState(false);
        var running = stateRunning[0], setRunning = stateRunning[1];

        var stateProgress = useState(null);
        var progress = stateProgress[0], setProgress = stateProgress[1];

        function toggle() {
            var next = !media.enabled;
            setSaving(true);
            wp.apiFetch({ path: '/reloadify/v1/media', method: 'POST', data: { enabled: next } })
                .then(function (response) {
                    setSaving(false);
                    props.onChange(response);
                    props.onToast('success', response.enabled
                        ? __('Media Optimization turned on.', 'reloadify-frontend-sync')
                        : __('Media Optimization turned off.', 'reloadify-frontend-sync'));
                })
                .catch(function () {
                    setSaving(false);
                    props.onToast('error', __('Could not update Media Optimization.', 'reloadify-frontend-sync'));
                });
        }

        function setFormat(format) {
            if (format === media.format_preference) {
                return;
            }
            setFormatSaving(true);
            wp.apiFetch({ path: '/reloadify/v1/media', method: 'POST', data: { enabled: media.enabled, format_preference: format } })
                .then(function (response) {
                    setFormatSaving(false);
                    props.onChange(response);
                })
                .catch(function () {
                    setFormatSaving(false);
                    props.onToast('error', __('Could not update Media Optimization.', 'reloadify-frontend-sync'));
                });
        }

        // WP-Cron only runs when something visits the site (or a real
        // system cron is configured to hit wp-cron.php) -- on a quiet or
        // local site, existing media can sit unprocessed for a long time
        // waiting on that. This runs batches right now instead, looping
        // until nothing's left, so it doesn't depend on traffic at all.
        function optimizeNow() {
            setRunning(true);
            setProgress(null);

            function runBatch() {
                wp.apiFetch({ path: '/reloadify/v1/media/backfill-now', method: 'POST' })
                    .then(function (response) {
                        setProgress(response);
                        var stillWorking = response.images_remaining > 0
                            && response.images_processed > 0;
                        if (stillWorking) {
                            runBatch();
                        } else {
                            setRunning(false);
                        }
                    })
                    .catch(function () {
                        setRunning(false);
                        props.onToast('error', __('Could not run existing-media optimization.', 'reloadify-frontend-sync'));
                    });
            }

            runBatch();
        }

        var statusNode = null;
        if (progress) {
            var videosStuck = (progress.videos_unavailable || 0) > 0;
            var stillPending = (progress.images_remaining || 0) > 0 || (progress.videos_remaining || 0) > 0;

            if (!stillPending && !videosStuck) {
                statusNode = el('span', { className: 'reloadify-media-backfill-status reloadify-media-backfill-status--ok' }, __('All optimized', 'reloadify-frontend-sync'));
            } else if (videosStuck && !stillPending) {
                statusNode = el('span', { className: 'reloadify-media-backfill-status reloadify-media-backfill-status--error' },
                    wp.i18n.sprintf(
                        __('%d video(s) not compressed \u2014 ffmpeg isn\u2019t available on this server', 'reloadify-frontend-sync'),
                        progress.videos_unavailable
                    )
                );
            } else {
                statusNode = el('span', { className: 'reloadify-media-backfill-status' },
                    wp.i18n.sprintf(__('%1$d images, %2$d videos left', 'reloadify-frontend-sync'), progress.images_remaining, progress.videos_remaining)
                );
            }
        }

        var caps = media.format_capabilities || { webp: false, avif: false };
        var formatOptions = [
            { value: 'auto', label: __('Automatic (recommended)', 'reloadify-frontend-sync'), disabled: false },
            { value: 'webp', label: __('WebP only', 'reloadify-frontend-sync'), disabled: !caps.webp },
            { value: 'avif', label: __('AVIF only', 'reloadify-frontend-sync'), disabled: !caps.avif },
        ];

        var formatRow = media.enabled && el(
            'div',
            { className: 'reloadify-media-format-row' },
            el('div', { className: 'reloadify-media-format-label' }, __('Image format', 'reloadify-frontend-sync')),
            el(
                'div',
                { className: 'reloadify-media-format-options' },
                formatOptions.map(function (opt) {
                    return el(
                        'label',
                        {
                            key: opt.value,
                            className: 'reloadify-media-format-option' + (opt.disabled ? ' is-disabled' : ''),
                        },
                        el('input', {
                            type: 'radio',
                            name: 'reloadify-media-format',
                            checked: (media.format_preference || 'auto') === opt.value,
                            disabled: opt.disabled || formatSaving,
                            onChange: function () { setFormat(opt.value); },
                        }),
                        opt.label
                    );
                })
            ),
            !caps.avif && el(
                'p',
                { className: 'reloadify-media-format-note' },
                __('AVIF isn\'t available on this server yet — pinning it falls back to WebP automatically.', 'reloadify-frontend-sync')
            )
        );

        return el(
            'div',
            { className: 'reloadify-section reloadify-speed-card' },
            el(
                'div',
                { className: 'reloadify-speed-card-head' },
                el('h2', null, __('Media Optimization', 'reloadify-frontend-sync')),
                el(Switch, { large: true, checked: media.enabled, onChange: toggle, disabled: saving })
            ),
            formatRow,
            media.enabled && el(
                'div',
                { className: 'reloadify-media-backfill-row' },
                el(
                    'button',
                    { type: 'button', className: 'button button-small', onClick: optimizeNow, disabled: running },
                    running ? __('Optimizing\u2026', 'reloadify-frontend-sync') : __('Optimize existing media now', 'reloadify-frontend-sync')
                ),
                statusNode
            )
        );
    }

    function PerformanceTab(props) {
        var data = props.data;
        var stateModal = useState(false);
        var modalOpen = stateModal[0];
        var setModalOpen = stateModal[1];

        var stateOpcacheConfirm = useState(false);
        var opcacheConfirmed = stateOpcacheConfirm[0], setOpcacheConfirmed = stateOpcacheConfirm[1];

        var stateOpcacheApplying = useState(false);
        var opcacheApplying = stateOpcacheApplying[0], setOpcacheApplying = stateOpcacheApplying[1];

        var stateOpcacheResult = useState(null);
        var opcacheResult = stateOpcacheResult[0], setOpcacheResult = stateOpcacheResult[1];

        function attemptOpcacheOverride() {
            if (!opcacheConfirmed) {
                return;
            }
            setOpcacheApplying(true);
            setOpcacheResult(null);
            wp.apiFetch({ path: '/reloadify/v1/performance/apply-opcache', method: 'POST', data: { confirmed: true, desired: data.settings.desired } })
                .then(function (response) {
                    setOpcacheResult(response.result);
                    props.onChange(Object.assign({}, data, { live: response.live }));
                    setOpcacheApplying(false);
                    props.onToast(response.result.success ? 'success' : 'error', response.result.success ? __('Written to php.ini \u2014 restart PHP for it to take effect.', 'reloadify-frontend-sync') : __('Not applied \u2014 see the result below for why.', 'reloadify-frontend-sync'));
                })
                .catch(function () {
                    setOpcacheApplying(false);
                    props.onToast('error', __('The request failed.', 'reloadify-frontend-sync'));
                });
        }

        var stateApplying = useState(false);
        var applying = stateApplying[0], setApplying = stateApplying[1];

        var stateResults = useState(null);
        var applyResults = stateResults[0], setApplyResults = stateResults[1];

        function setDesired(key, value) {
            var desired = Object.assign({}, data.settings.desired);
            desired[key] = value;
            props.onChange(Object.assign({}, data, { settings: Object.assign({}, data.settings, { desired: desired }) }));
        }

        function toggleRuntime(key, val) {
            var runtimeEnabled = Object.assign({}, data.settings.runtime_enabled);
            runtimeEnabled[key] = val;
            props.onChange(Object.assign({}, data, { settings: Object.assign({}, data.settings, { runtime_enabled: runtimeEnabled }) }));
        }

        function attemptServerOverride() {
            setApplying(true);
            setApplyResults(null);
            wp.apiFetch({ path: '/reloadify/v1/performance/apply-server', method: 'POST', data: { desired: data.settings.desired } })
                .then(function (response) {
                    setApplyResults(response.results);
                    var updatedData = Object.assign({}, data, { live: response.live });
                    props.onChange(updatedData);
                    setApplying(false);
                    var anySuccess = (response.results.user_ini && response.results.user_ini.success) || (response.results.htaccess && response.results.htaccess.success);
                    props.onToast(anySuccess ? 'success' : 'error', anySuccess ? __('Attempted \u2014 see results below. .user.ini changes can take a few minutes to take effect.', 'reloadify-frontend-sync') : __('Neither method could write on this server \u2014 see results below for why.', 'reloadify-frontend-sync'));
                })
                .catch(function () {
                    setApplying(false);
                    props.onToast('error', __('The request failed.', 'reloadify-frontend-sync'));
                });
        }

        return el(
            'div',
            null,
            el(
                'div',
                { className: 'reloadify-callout reloadify-perf-header' },
                el(
                    'div',
                    { className: 'reloadify-perf-header-label' },
                    __('Server Performance', 'reloadify-frontend-sync'),
                    el(InfoIcon, { text: __('Only PHP settings still changeable at runtime update live here. The rest need a server-side change.', 'reloadify-frontend-sync') })
                ),
                el(
                    'button',
                    { className: 'button', onClick: props.onSync, disabled: props.syncing },
                    props.syncing ? __('Syncing\u2026', 'reloadify-frontend-sync') : __('Sync from server', 'reloadify-frontend-sync')
                )
            ),

            el(
                'div',
                { className: 'reloadify-toggle-grid' },
                el(SpeedBoostCard, { speed: props.speed, onChange: props.onSpeedChange, onToast: props.onToast }),
                el(MediaOptimizationCard, { media: props.media, onChange: props.onMediaChange, onToast: props.onToast }),
                el(DeleteOnUninstallCard, { cleanup: props.cleanup, onChange: props.onCleanupChange, onToast: props.onToast })
            ),

            Object.keys(PERF_GROUPS).map(function (groupKey) {
                var group = PERF_GROUPS[groupKey];
                return el(
                    'div',
                    { className: 'reloadify-section', key: groupKey },
                    el(SectionTitle, { text: group.title, hint: group.hint }),
                    el(
                        'div',
                        { className: 'reloadify-perf-grid' },
                        group.keys.map(function (key) {
                            return el(DirectiveCard, {
                                key: key,
                                name: key,
                                runtime: groupKey === 'runtime',
                                runtimeEnabled: data.settings.runtime_enabled[key],
                                value: data.settings.desired[key],
                                live: data.live[key],
                                onChange: setDesired,
                                onToggleRuntime: toggleRuntime
                            });
                        })
                    ),
                    groupKey === 'host' && el(
                        'div',
                        { className: 'reloadify-perf-actions' },
                        el(
                            'button',
                            { className: 'button button-primary', onClick: attemptServerOverride, disabled: applying },
                            applying ? __('Attempting\u2026', 'reloadify-frontend-sync') : __('Attempt automatic server override', 'reloadify-frontend-sync')
                        ),
                        el(
                            'button',
                            { className: 'button', onClick: function () { setModalOpen(true); } },
                            __('Generate config snippet', 'reloadify-frontend-sync')
                        ),
                        el(InfoIcon, { text: __('Writes .user.ini / .htaccess. Safe to try \u2014 it just fails quietly if unsupported.', 'reloadify-frontend-sync') }),
                        el(ApplyResults, { results: applyResults })
                    ),

                    groupKey === 'opcache' && el(
                        'div',
                        { className: 'reloadify-danger-zone' },
                        el(
                            'div',
                            { className: 'reloadify-danger-banner' },
                            el('strong', null, __('\u26a0 Local development only \u2014 do not use on a live/production site.', 'reloadify-frontend-sync')),
                            el('p', null, __('These three settings are locked in when PHP starts up, so the only way to change them is editing your real php.ini file and restarting PHP \u2014 no plugin can do it any other way. This action edits that file directly. A mistake here, or running it on a shared or live server, can bring down PHP for every site on that server until someone fixes it by hand. Only use this on a local site you fully control and can easily reinstall (Local, XAMPP, MAMP, Laragon, or your own Docker setup).', 'reloadify-frontend-sync')),
                            data.phpIniPath
                                ? el('p', null, __('File this would write to: ', 'reloadify-frontend-sync'), el('code', null, data.phpIniPath))
                                : el('p', null, __('This server reports no loaded php.ini file at all, so this action can\u2019t do anything here.', 'reloadify-frontend-sync')),
                            el('p', null, __('It also does nothing until PHP restarts \u2014 this plugin cannot restart PHP for you.', 'reloadify-frontend-sync'))
                        ),
                        el(
                            'label',
                            { className: 'reloadify-danger-confirm' },
                            el('input', {
                                type: 'checkbox',
                                checked: opcacheConfirmed,
                                onChange: function (e) { setOpcacheConfirmed(e.target.checked); }
                            }),
                            __('I understand this can crash PHP for the whole server, and I\u2019m only doing this on a local development site I fully control.', 'reloadify-frontend-sync')
                        ),
                        el(
                            'button',
                            { className: 'button reloadify-button-danger', onClick: attemptOpcacheOverride, disabled: !opcacheConfirmed || opcacheApplying || !data.phpIniPath },
                            opcacheApplying ? __('Writing\u2026', 'reloadify-frontend-sync') : __('Attempt opcache override (local dev only)', 'reloadify-frontend-sync')
                        ),
                        opcacheResult && el(
                            'div',
                            { className: 'reloadify-apply-result-row reloadify-apply-result-row--solo' },
                            el('span', { className: 'reloadify-badge ' + (opcacheResult.success ? 'reloadify-badge--live' : 'reloadify-badge--host') }, opcacheResult.success ? __('Written', 'reloadify-frontend-sync') : __('Not applied', 'reloadify-frontend-sync')),
                            el('p', null, opcacheResult.message),
                            opcacheResult.backup_path && el('p', null, __('Backup saved at: ', 'reloadify-frontend-sync'), el('code', null, opcacheResult.backup_path))
                        )
                    )
                );
            }),

            modalOpen && el(SnippetModal, {
                desired: data.settings.desired,
                onClose: function () { setModalOpen(false); },
                onCopied: function (label) {
                    props.onToast(label ? 'success' : 'error', label ? (label + __(' snippet copied.', 'reloadify-frontend-sync')) : __('Could not copy \u2014 select and copy manually.', 'reloadify-frontend-sync'));
                }
            })
        );
    }

    /* ---------------- Extra Features tab ---------------- */

    function ExtrasTab(props) {
        var extras = props.extras;

        function updateSvg(patch) {
            props.onChange(Object.assign({}, extras, {
                svg_support: Object.assign({}, extras.svg_support, patch)
            }));
        }

        function updateScrollTop(patch) {
            props.onChange(Object.assign({}, extras, {
                scroll_top: Object.assign({}, extras.scroll_top, patch)
            }));
        }

        return el(
            'div',
            null,
            el(
                'div',
                { className: 'reloadify-section' },
                el('h2', null, __('SVG Upload Support', 'reloadify-frontend-sync')),
                el(
                    'div',
                    { className: 'reloadify-stat-card reloadify-stat-card--accent', style: { maxWidth: 420 } },
                    el('div', { className: 'reloadify-stat-label' }, __('Allow SVG uploads', 'reloadify-frontend-sync')),
                    el('div', { className: 'reloadify-stat-value' }, extras.svg_support.enabled ? __('On', 'reloadify-frontend-sync') : __('Off', 'reloadify-frontend-sync')),
                    el(Switch, {
                        large: true,
                        checked: extras.svg_support.enabled,
                        onChange: function (e) { updateSvg({ enabled: e.target.checked }); }
                    })
                )
            ),

            el(
                'div',
                { className: 'reloadify-section' },
                el('h2', null, __('Scroll To Top Button', 'reloadify-frontend-sync')),
                el(
                    'div',
                    { className: 'reloadify-stat-card reloadify-stat-card--accent', style: { maxWidth: 420, marginBottom: 16 } },
                    el('div', { className: 'reloadify-stat-label' }, __('Show button on frontend', 'reloadify-frontend-sync')),
                    el('div', { className: 'reloadify-stat-value' }, extras.scroll_top.enabled ? __('On', 'reloadify-frontend-sync') : __('Off', 'reloadify-frontend-sync')),
                    el(Switch, {
                        large: true,
                        checked: extras.scroll_top.enabled,
                        onChange: function (e) { updateScrollTop({ enabled: e.target.checked }); }
                    })
                ),
                el(
                    'div',
                    { className: 'reloadify-reload-mode-row' },
                    el(
                        'label',
                        { className: 'reloadify-radio-card' + (extras.scroll_top.position !== 'left' ? ' is-selected' : '') },
                        el('input', { type: 'radio', name: 'scroll_top_position', checked: extras.scroll_top.position !== 'left', onChange: function () { updateScrollTop({ position: 'right' }); } }),
                        el('strong', null, __('Bottom right', 'reloadify-frontend-sync')),
                        el('p', null, __('Default placement, out of the way of most site chrome.', 'reloadify-frontend-sync'))
                    ),
                    el(
                        'label',
                        { className: 'reloadify-radio-card' + (extras.scroll_top.position === 'left' ? ' is-selected' : '') },
                        el('input', { type: 'radio', name: 'scroll_top_position', checked: extras.scroll_top.position === 'left', onChange: function () { updateScrollTop({ position: 'left' }); } }),
                        el('strong', null, __('Bottom left', 'reloadify-frontend-sync')),
                        el('p', null, __('Use this if a chat widget or something else already occupies bottom right.', 'reloadify-frontend-sync'))
                    )
                ),
                el(
                    'div',
                    { className: 'reloadify-perf-card', style: { marginTop: 16, maxWidth: 420 } },
                    el('label', { className: 'reloadify-field-label' },
                        __('Button color', 'reloadify-frontend-sync'),
                        el('input', {
                            type: 'color',
                            value: extras.scroll_top.bg_color,
                            onChange: function (e) { updateScrollTop({ bg_color: e.target.value }); },
                            style: { marginLeft: 10, verticalAlign: 'middle', width: 44, height: 28, padding: 0, border: 'none', cursor: 'pointer' }
                        })
                    ),
                    el('label', { className: 'reloadify-field-label', style: { display: 'block', marginTop: 14 } },
                        __('Show after scrolling (pixels)', 'reloadify-frontend-sync'),
                        el('input', {
                            type: 'number',
                            min: 0,
                            step: 50,
                            value: extras.scroll_top.show_after,
                            onChange: function (e) { updateScrollTop({ show_after: parseInt(e.target.value, 10) || 0 }); },
                            style: { display: 'block', marginTop: 6, width: 140 }
                        })
                    )
                )
            )
        );
    }

    /* ---------------- App ---------------- */

    function App() {
        var tabState = useState('reload');
        var tab = tabState[0], setTab = tabState[1];

        var initial = ReloadifyAdmin.initial || {};

        var settingsState = useState(initial.settings || null);
        var settings = settingsState[0], setSettings = settingsState[1];

        var perfState = useState(initial.performance || null);
        var perf = perfState[0], setPerf = perfState[1];

        var speedState = useState(initial.speed || { enabled: true, items: [] });
        var speed = speedState[0], setSpeed = speedState[1];

        var mediaState = useState(initial.media || { enabled: true, items: [], format_preference: 'auto', format_capabilities: { webp: false, avif: false } });
        var media = mediaState[0], setMedia = mediaState[1];

        var cleanupState = useState(initial.cleanup || { enabled: true });
        var cleanup = cleanupState[0], setCleanup = cleanupState[1];

        var extrasState = useState(initial.extras || {
            svg_support: { enabled: true },
            scroll_top: { enabled: true, position: 'right', bg_color: '#4f46e5', show_after: 300 }
        });
        var extras = extrasState[0], setExtras = extrasState[1];

        var savingState = useState(false);
        var saving = savingState[0], setSaving = savingState[1];

        var syncingState = useState(false);
        var syncing = syncingState[0], setSyncing = syncingState[1];

        var toastState = useState(null);
        var toast = toastState[0], setToast = toastState[1];

        function showToast(type, message) {
            setToast({ type: type, message: message });
            setTimeout(function () { setToast(null); }, 3500);
        }

        function refreshSettings() {
            wp.apiFetch({ path: '/reloadify/v1/settings', method: 'GET' })
                .then(function (data) { setSettings(data); })
                .catch(function () { /* next manual save will still correct this */ });
        }

        function syncPerformance() {
            setSyncing(true);
            wp.apiFetch({ path: '/reloadify/v1/performance/sync', method: 'POST' })
                .then(function (data) {
                    setPerf(data);
                    setSyncing(false);
                    showToast('success', __('Synced with the server\u2019s current values.', 'reloadify-frontend-sync'));
                })
                .catch(function () {
                    setSyncing(false);
                    showToast('error', __('Could not sync from the server.', 'reloadify-frontend-sync'));
                });
        }

        function save() {
            setSaving(true);

            var request;
            if (tab === 'reload') {
                request = wp.apiFetch({ path: '/reloadify/v1/settings', method: 'POST', data: settings }).then(function (data) { setSettings(data); });
            } else if (tab === 'extras') {
                request = wp.apiFetch({ path: '/reloadify/v1/extras', method: 'POST', data: extras }).then(function (data) { setExtras(data); });
            } else {
                request = wp.apiFetch({ path: '/reloadify/v1/performance', method: 'POST', data: perf.settings }).then(function (data) { setPerf(data); });
            }

            request.then(function () {
                setSaving(false);
                showToast('success', __('Settings saved successfully.', 'reloadify-frontend-sync'));
            }).catch(function () {
                setSaving(false);
                showToast('error', __('Failed to save settings.', 'reloadify-frontend-sync'));
            });
        }

        if (!settings || !perf) {
            return el('div', { className: 'reloadify-loading' }, __('Loading\u2026', 'reloadify-frontend-sync'));
        }

        return el(
            'div',
            { className: 'reloadify-app' },
            el(Toast, { toast: toast }),
            el(
                'div',
                { className: 'reloadify-hero' },
                el(
                    'div',
                    null,
                    el('h1', null, __('Reloadify Frontend Sync', 'reloadify-frontend-sync')),
                    el('p', null, __('Cross-browser live reload for page-builder QA \u2014 and a clear picture of which server settings this plugin can and can\u2019t change for you.', 'reloadify-frontend-sync'))
                ),
                el('span', { className: 'reloadify-version-badge' }, 'v' + ReloadifyAdmin.version)
            ),
            el(
                'div',
                { className: 'reloadify-tabs' },
                el('button', { className: 'reloadify-tab' + (tab === 'reload' ? ' is-active' : ''), onClick: function () { setTab('reload'); } }, __('Cross-Browser Reload', 'reloadify-frontend-sync')),
                el('button', { className: 'reloadify-tab' + (tab === 'performance' ? ' is-active' : ''), onClick: function () { setTab('performance'); } }, __('Server Performance', 'reloadify-frontend-sync')),
                el('button', { className: 'reloadify-tab' + (tab === 'extras' ? ' is-active' : ''), onClick: function () { setTab('extras'); } }, __('Extensions', 'reloadify-frontend-sync'))
            ),
            el(
                'div',
                { className: 'reloadify-tab-panel' },
                tab === 'reload'
                    ? el(ReloadTab, { settings: settings, onChange: setSettings, onExpire: refreshSettings })
                    : tab === 'extras'
                        ? el(ExtrasTab, { extras: extras, onChange: setExtras })
                        : el(PerformanceTab, { data: perf, onChange: setPerf, onToast: showToast, onSync: syncPerformance, syncing: syncing, speed: speed, onSpeedChange: setSpeed, media: media, onMediaChange: setMedia, cleanup: cleanup, onCleanupChange: setCleanup })
            ),
            el(
                'div',
                { className: 'reloadify-save-bar' },
                el('button', { className: 'button button-primary button-hero', onClick: save, disabled: saving }, saving ? __('Saving\u2026', 'reloadify-frontend-sync') : __('Save Changes', 'reloadify-frontend-sync'))
            )
        );
    }

    document.addEventListener('DOMContentLoaded', function () {
        var root = document.getElementById('reloadify-settings-root');
        if (root) {
            render(el(App), root);
        }
    });

})();
