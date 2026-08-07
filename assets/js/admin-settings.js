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
            hint: __('These are PHP_INI_ALL, meaning ini_set() genuinely works on them at runtime \u2014 no server access needed, applied on every WordPress-processed request. (opcache.enable can only be switched off this way, never back on once the server started with it disabled \u2014 that\u2019s PHP\u2019s own rule.)', 'reloadify-frontend-sync'),
            keys: ['memory_limit', 'max_execution_time', 'opcache.enable', 'opcache.validate_timestamps', 'opcache.revalidate_freq']
        },
        host: {
            title: __('Requires your host / server config', 'reloadify-frontend-sync'),
            hint: __('PHP locks these before WordPress ever loads. The automatic attempt below writes .user.ini / .htaccess for these — safe to try on a live site, since at worst it just fails quietly if your host doesn\u2019t support it.', 'reloadify-frontend-sync'),
            keys: ['max_input_time', 'post_max_size', 'upload_max_filesize', 'realpath_cache_size', 'realpath_cache_ttl']
        },
        opcache: {
            title: __('opcache memory sizing — danger zone', 'reloadify-frontend-sync'),
            hint: __('Only these three actually require editing the real php.ini: they size a shared-memory pool once when PHP starts, before any plugin runs. See the warning below before touching this section.', 'reloadify-frontend-sync'),
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
            el('input', { type: 'checkbox', checked: !!props.checked, onChange: props.onChange }),
            el('span', { className: 'reloadify-switch-slider' })
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

    function ReloadTab(props) {
        var settings = props.settings;

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
                    el('div', { className: 'reloadify-stat-label' }, __('Developer Mode', 'reloadify-frontend-sync')),
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
                )
            ),

            !settings.dev_mode_enabled && el(
                'div',
                { className: 'reloadify-callout reloadify-callout--warn' },
                __('Developer Mode is off by default on purpose \u2014 while it\u2019s on, every visitor\u2019s browser keeps checking the server, which is fine for staging but adds real load on a busy live site. Turn it on while you\u2019re testing, then switch it off when you\u2019re done. It also turns itself off automatically after 6 hours, just in case.', 'reloadify-frontend-sync')
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
                el('h2', null, __('Browsers & windows', 'reloadify-frontend-sync')),
                el('p', { className: 'reloadify-hint' }, __('Incognito/private detection is best-effort \u2014 some browsers deliberately make it undetectable, so treat it as a helper, not a guarantee.', 'reloadify-frontend-sync')),
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
            wp.apiFetch({ path: '/reloadify/v1/performance/apply-opcache', method: 'POST', data: { confirmed: true } })
                .then(function (response) {
                    setOpcacheResult(response.result);
                    props.onChange(Object.assign({}, data, { live: response.live }));
                    setOpcacheApplying(false);
                    props.onToast(response.result.success ? 'success' : 'error', response.result.success ? __('Snippet ready below \u2014 paste it into the php.ini shown, then restart PHP.', 'reloadify-frontend-sync') : __('Couldn\u2019t generate a snippet \u2014 see the result below for why.', 'reloadify-frontend-sync'));
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
            wp.apiFetch({ path: '/reloadify/v1/performance/apply-server', method: 'POST' })
                .then(function (response) {
                    setApplyResults(response.results);
                    props.onChange(Object.assign({}, data, { live: response.live }));
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
                    null,
                    __('A plugin can only change PHP settings that are still adjustable while a page is loading \u2014 that covers memory_limit, max_execution_time, and 3 of the 6 opcache settings below. "Live" means it applies to real WordPress page loads; a cached page served without hitting PHP won\u2019t see it. Upload limits, realpath cache, and the 3 memory-sizing opcache settings are locked before WordPress even starts, so those need a real php.ini, .user.ini, or host-level change instead. Everything below starts out showing what your server is actually running right now.', 'reloadify-frontend-sync')
                ),
                el(
                    'button',
                    { className: 'button', onClick: props.onSync, disabled: props.syncing },
                    props.syncing ? __('Syncing\u2026', 'reloadify-frontend-sync') : __('Sync from server', 'reloadify-frontend-sync')
                )
            ),

            Object.keys(PERF_GROUPS).map(function (groupKey) {
                var group = PERF_GROUPS[groupKey];
                return el(
                    'div',
                    { className: 'reloadify-section', key: groupKey },
                    el('h2', null, group.title),
                    el('p', { className: 'reloadify-hint' }, group.hint),
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
                        el('p', { className: 'reloadify-hint' }, __('Writes .user.ini and .htaccess in your WordPress root. Safe to try on a live site \u2014 if your host doesn\u2019t support it, it just fails and tells you why.', 'reloadify-frontend-sync')),
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
                            opcacheApplying ? __('Generating\u2026', 'reloadify-frontend-sync') : __('Generate opcache snippet (local dev only)', 'reloadify-frontend-sync')
                        ),
                        opcacheResult && el(
                            'div',
                            { className: 'reloadify-apply-result-row reloadify-apply-result-row--solo' },
                            el('span', { className: 'reloadify-badge ' + (opcacheResult.success ? 'reloadify-badge--live' : 'reloadify-badge--host') }, opcacheResult.success ? __('Snippet ready', 'reloadify-frontend-sync') : __('Not available', 'reloadify-frontend-sync')),
                            el('p', null, opcacheResult.message),
                            opcacheResult.snippet && el('pre', { className: 'reloadify-code' }, opcacheResult.snippet)
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

    /* ---------------- App ---------------- */

    function App() {
        var tabState = useState('reload');
        var tab = tabState[0], setTab = tabState[1];

        var initial = ReloadifyAdmin.initial || {};

        var settingsState = useState(initial.settings || null);
        var settings = settingsState[0], setSettings = settingsState[1];

        var perfState = useState(initial.performance || null);
        var perf = perfState[0], setPerf = perfState[1];

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

            var request = tab === 'reload'
                ? wp.apiFetch({ path: '/reloadify/v1/settings', method: 'POST', data: settings }).then(function (data) { setSettings(data); })
                : wp.apiFetch({ path: '/reloadify/v1/performance', method: 'POST', data: perf.settings }).then(function (data) { setPerf(data); });

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
                el('button', { className: 'reloadify-tab' + (tab === 'performance' ? ' is-active' : ''), onClick: function () { setTab('performance'); } }, __('Server Performance', 'reloadify-frontend-sync'))
            ),
            el(
                'div',
                { className: 'reloadify-tab-panel' },
                tab === 'reload'
                    ? el(ReloadTab, { settings: settings, onChange: setSettings })
                    : el(PerformanceTab, { data: perf, onChange: setPerf, onToast: showToast, onSync: syncPerformance, syncing: syncing })
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
