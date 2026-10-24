(function () {
    'use strict';

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var useRef = wp.element.useRef;
    var render = wp.element.render;
    var __ = wp.i18n.__;

    /* Debounce before firing an autosave request, so fast interactions
       (dragging a color picker, typing a number) collapse into one save
       instead of one request per keystroke. */
    var AUTOSAVE_DEBOUNCE_MS = 700;

    wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(ReloadifyAdmin.nonce));

    var BROWSERS = ReloadifyAdmin.browsers || [];
    var LABELS = ReloadifyAdmin.browserLabels || {};

    /* ---------------- Browser Theme ---------------- */

    var BROWSER_THEME = {
        chrome:    { from: '#4285F4', to: '#34A853', glyph: 'ring' },
        brave:     { from: '#FB542B', to: '#F97316', glyph: 'shield' },
        edge:      { from: '#0A84D3', to: '#00B8D9', glyph: 'wave' },
        firefox:   { from: '#FF7139', to: '#FFB03A', glyph: 'flame' },
        safari:    { from: '#3F87F5', to: '#1D4ED8', glyph: 'compass' },
        opera:     { from: '#FF1B2D', to: '#E60039', glyph: 'circle' },
        ucbrowser: { from: '#1FA3FF', to: '#0F6FD1', glyph: 'bolt' },
        vivaldi:   { from: '#EF3939', to: '#B71C1C', glyph: 'diamond' },
        yandex:    { from: '#FFCC00', to: '#FF3333', glyph: 'drop' },
        samsung:   { from: '#1259A6', to: '#0D3E7A', glyph: 'star' }
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
            case 'diamond':
                return el('path', { d: 'M16 8 L23 16 L16 24 L9 16 Z', fill: 'none', stroke: '#fff', strokeWidth: 2.4 });
            case 'drop':
                return el('path', { d: 'M16 8c4 5 6 8 6 11a6 6 0 11-12 0c0-3 2-6 6-11z', fill: '#fff', opacity: 0.92 });
            case 'star':
                return el('path', { d: 'M16 8 L18.2 13.6 L24 14.2 L19.6 18 L21 23.8 L16 20.6 L11 23.8 L12.4 18 L8 14.2 L13.8 13.6 Z', fill: '#fff', opacity: 0.92 });
            default:
                return el('circle', { cx: 16, cy: 16, r: 5, fill: '#fff', opacity: 0.85 });
        }
    }

    function perfHelp(key) {
        var text = {
            'memory_limit': __('Most memory a single request may use.', 'reloadify-frontend-sync'),
            'max_execution_time': __('Seconds a script may run before PHP stops it.', 'reloadify-frontend-sync'),
            'opcache.enable': __('Keeps compiled PHP in memory so pages build faster.', 'reloadify-frontend-sync'),
            'opcache.validate_timestamps': __('Check files for edits. Keep on (1) while developing.', 'reloadify-frontend-sync'),
            'opcache.revalidate_freq': __('Seconds between those edit checks.', 'reloadify-frontend-sync'),
            'max_input_time': __('Seconds PHP may spend reading request data.', 'reloadify-frontend-sync'),
            'post_max_size': __('Largest request body PHP accepts.', 'reloadify-frontend-sync'),
            'upload_max_filesize': __('Largest single file you can upload.', 'reloadify-frontend-sync'),
            'opcache.memory_consumption': __('Memory (MB) reserved for compiled scripts.', 'reloadify-frontend-sync'),
            'opcache.interned_strings_buffer': __('Memory (MB) for strings shared between scripts.', 'reloadify-frontend-sync'),
            'opcache.max_accelerated_files': __('How many files opcache can hold.', 'reloadify-frontend-sync'),
            'realpath_cache_size': __('Cache size for resolved file paths.', 'reloadify-frontend-sync'),
            'realpath_cache_ttl': __('Seconds a resolved path stays cached.', 'reloadify-frontend-sync')
        };
        return text[key] || '';
    }

    var PERF_GROUPS = {
        runtime: {
            title: __('Applied automatically by this plugin', 'reloadify-frontend-sync'),
            hint: __('Changed live via ini_set() on every request \u2014 no server access needed.', 'reloadify-frontend-sync'),
            subtitle: __('Changed live on every request \u2014 no server access needed', 'reloadify-frontend-sync'),
            icon: 'bolt',
            keys: ['memory_limit', 'max_execution_time', 'opcache.enable', 'opcache.validate_timestamps', 'opcache.revalidate_freq']
        },
        host: {
            title: __('Requires your host / server config', 'reloadify-frontend-sync'),
            hint: __('PHP locks these before WordPress loads. Auto-attempt below writes .user.ini / .htaccess.', 'reloadify-frontend-sync'),
            subtitle: __('PHP locks these before WordPress loads', 'reloadify-frontend-sync'),
            icon: 'server',
            keys: ['max_input_time', 'post_max_size', 'upload_max_filesize']
        },
        opcache: {
            title: __('Startup-only PHP settings — danger zone', 'reloadify-frontend-sync'),
            hint: __('opcache sizing and the realpath cache are both locked in once at PHP startup \u2014 .user.ini and .htaccess can\u2019t reach them at all, only a real php.ini edit can.', 'reloadify-frontend-sync'),
            subtitle: __('Locked in once when PHP starts \u2014 local development only', 'reloadify-frontend-sync'),
            icon: 'warn',
            keys: ['opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files', 'realpath_cache_size', 'realpath_cache_ttl']
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

    var TOAST_GLYPHS = {
        success: '\u2713',
        error: '\u2717',
        info: '\u24d8',
        warning: '\u26a0'
    };

    function Toast(props) {
        if (!props.toast) return null;

        var type = TOAST_GLYPHS[props.toast.type] ? props.toast.type : 'success';
        var cls = 'reloadify-toast reloadify-toast--' + type;

        return el(
            'div',
            { className: cls, role: 'status', 'aria-live': 'polite' },
            el('span', { className: 'reloadify-toast-icon', 'aria-hidden': 'true' }, TOAST_GLYPHS[type]),
            el(
                'div',
                { className: 'reloadify-toast-body' },
                props.toast.title && el('strong', { className: 'reloadify-toast-title' }, props.toast.title),
                el('span', { className: 'reloadify-toast-message' }, props.toast.message)
            ),
            el('button', {
                type: 'button',
                className: 'reloadify-toast-close',
                'aria-label': __('Dismiss', 'reloadify-frontend-sync'),
                onClick: props.onDismiss
            }, '\u00d7')
        );
    }

    /** Long-running progress, shown top-right on every tab until it finishes. */
    function ProgressToast(props) {
        var st = props.state;
        if (!st || st.status === 'idle') { return null; }

        var running = st.status === 'running';
        var pct = st.total > 0 ? Math.min(100, Math.round((st.processed / st.total) * 100)) : 0;
        var type = running ? 'progress' : (st.status === 'done' ? 'success' : (st.status === 'stopped' ? 'info' : 'error'));
        var title;
        var line;

        if (running) {
            title = __('Optimizing media', 'reloadify-frontend-sync');
            if (st.total === 0 && st.videos === 0) {
                line = __('Starting\u2026', 'reloadify-frontend-sync');
            } else if (st.remaining === 0 && st.videos > 0) {
                line = wp.i18n.sprintf(
                    /* translators: %d: number of videos still to compress */
                    __('%d videos left \u00b7 compressing videos', 'reloadify-frontend-sync'), st.videos);
            } else {
                line = wp.i18n.sprintf(
                    /* translators: 1: images left, 2: percent complete */
                    __('%1$d images left \u00b7 %2$d%% complete', 'reloadify-frontend-sync'), st.remaining, pct);
            }
        } else if (st.status === 'done') {
            title = __('All media optimized', 'reloadify-frontend-sync');
            line = st.processed > 0
                ? wp.i18n.sprintf(
                    /* translators: %d: number of images processed */
                    __('%d images processed \u00b7 100% complete', 'reloadify-frontend-sync'), st.processed)
                : __('Everything was already optimized.', 'reloadify-frontend-sync');
        } else if (st.status === 'stopped') {
            title = __('Optimization stopped', 'reloadify-frontend-sync');
            line = wp.i18n.sprintf(
                /* translators: %d: images still waiting */
                __('%d images are still waiting. Start again any time.', 'reloadify-frontend-sync'), st.remaining || 0);
        } else {
            title = __('Optimization paused', 'reloadify-frontend-sync');
            line = __('Some media could not be processed. Try again, or check the server\u2019s image library.', 'reloadify-frontend-sync');
        }

        return el(
            'div',
            { className: 'reloadify-toast reloadify-toast--' + type + ' reloadify-toast--progress', role: 'status', 'aria-live': 'polite' },
            el(
                'span',
                { className: 'reloadify-toast-icon', 'aria-hidden': 'true' },
                running ? el('span', { className: 'reloadify-spinner' }) : TOAST_GLYPHS[type === 'progress' ? 'info' : type]
            ),
            el(
                'div',
                { className: 'reloadify-toast-body' },
                el('strong', { className: 'reloadify-toast-title' }, title),
                el('span', { className: 'reloadify-toast-message' }, line),
                (running || st.status === 'done') && el(
                    'div',
                    { className: 'reloadify-progress' + (running && st.total === 0 ? ' is-indeterminate' : ''), role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': st.status === 'done' ? 100 : pct },
                    el('span', { style: { width: (st.status === 'done' ? 100 : pct) + '%' } })
                )
            ),
            running
                ? el('button', { type: 'button', className: 'reloadify-toast-stop', onClick: props.onStop }, __('Stop', 'reloadify-frontend-sync'))
                : el('button', { type: 'button', className: 'reloadify-toast-close', 'aria-label': __('Dismiss', 'reloadify-frontend-sync'), onClick: props.onDismiss }, '\u00d7')
        );
    }

    function AutoSaveStatus(props) {
        var label;
        var cls = 'reloadify-autosave-status';

        if (props.state === 'saving') {
            label = __('Saving\u2026', 'reloadify-frontend-sync');
            cls += ' is-saving';
        } else if (props.state === 'error') {
            label = __('Couldn\u2019t save \u2014 will retry on your next change', 'reloadify-frontend-sync');
            cls += ' is-error';
        } else {
            label = __('Saved automatically', 'reloadify-frontend-sync');
            cls += ' is-saved';
        }

        return el('div', { className: cls }, el('span', { className: 'reloadify-autosave-dot' }), label);
    }

    function Switch(props) {
        return el(
            'label',
            { className: 'reloadify-switch' + (props.large ? ' reloadify-switch--lg' : '') },
            el('input', { type: 'checkbox', checked: !!props.checked, onChange: props.onChange, disabled: !!props.disabled }),
            el('span', { className: 'reloadify-switch-slider' })
        );
    }

    /* ---------------- Info Icon ---------------- */
    
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

            if (props.onToast) {
                var browserLabel = LABELS[props.name] || props.name;
                var windowLabel = 'incognito' === field
                    ? __('Incognito', 'reloadify-frontend-sync')
                    : __('Normal', 'reloadify-frontend-sync');

                props.onToast(
                    val ? 'success' : 'info',
                    wp.i18n.sprintf(
                        // translators: 1: browser name, 2: window type (Normal or Incognito).
                        val
                            ? __('%1$s %2$s window enabled', 'reloadify-frontend-sync')
                            : __('%1$s %2$s window disabled', 'reloadify-frontend-sync'),
                        browserLabel,
                        windowLabel
                    )
                );
            }
        }

        var enabled = row.normal || row.incognito;

        return el(
            'div',
            { className: 'reloadify-browser-card' + (enabled ? ' is-on' : '') },
            el('span', { className: 'reloadify-browser-dot' + (enabled ? ' is-on' : ''), title: enabled ? __('Enabled', 'reloadify-frontend-sync') : __('Disabled', 'reloadify-frontend-sync') }),
            el(BrowserIcon, { name: props.name }),
            el('div', { className: 'reloadify-browser-card-name' }, LABELS[props.name] || props.name),
            el(
                'div',
                { className: 'reloadify-browser-card-row' },
                el('span', { className: 'reloadify-browser-row-label' }, el(Ico, { name: 'window', size: 13 }), __('Normal', 'reloadify-frontend-sync')),
                el(Switch, { checked: row.normal, onChange: function (e) { set('normal', e.target.checked); } })
            ),
            el(
                'div',
                { className: 'reloadify-browser-card-row' },
                el('span', { className: 'reloadify-browser-row-label' }, el(Ico, { name: 'mask', size: 13 }), __('Incognito', 'reloadify-frontend-sync')),
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

    /* Reload-tab building blocks. They live at module level (not inside ReloadTab) so React keeps
       their state -- tooltips, switch animations -- instead of remounting them on every change. */

    function ReloadControl(cprops) {
        return el(
            'div',
            { className: 'reloadify-control' + (cprops.on ? ' is-on' : '') },
            el(IconChip, { name: cprops.icon, tone: cprops.on ? 'indigo' : 'gray' }),
            el(
                'div',
                { className: 'reloadify-control-text' },
                el('div', { className: 'reloadify-control-title' }, cprops.title, cprops.hint && el(InfoIcon, { text: cprops.hint })),
                el('span', { className: 'reloadify-status-pill' + (cprops.on ? ' is-on' : '') }, cprops.status)
            ),
            el(Switch, { large: true, checked: cprops.on, onChange: cprops.onChange })
        );
    }

    function ReloadTile(tprops) {
        return el(
            'div',
            { className: 'reloadify-tile' },
            el('div', { className: 'reloadify-tile-head' }, el(IconChip, { name: tprops.icon, tone: tprops.tone || 'gray', size: 16 }), el('span', null, tprops.label), tprops.hint && el(InfoIcon, { text: tprops.hint })),
            el('div', { className: 'reloadify-tile-value' }, tprops.value),
            tprops.children
        );
    }

    function ChoiceCard(cprops) {
        return el(
            'button',
            {
                type: 'button',
                role: 'radio',
                'aria-checked': cprops.selected,
                className: 'reloadify-choice' + (cprops.selected ? ' is-selected' : ''),
                onClick: cprops.onSelect
            },
            el(IconChip, { name: cprops.icon, tone: cprops.selected ? 'indigo' : 'gray' }),
            el('span', { className: 'reloadify-choice-text' }, el('strong', null, cprops.title), el('span', null, cprops.text)),
            el('span', { className: 'reloadify-choice-check', 'aria-hidden': 'true' }, cprops.selected && el(Ico, { name: 'check', size: 14 }))
        );
    }

    function ReloadTab(props) {
        var settings = props.settings;
        var checkingState = useState(false);
        var checking = checkingState[0], setChecking = checkingState[1];

        function checkNow() {
            setChecking(true);
            props.onExpire();
            props.onToast('info', __('Checking the server for changes\u2026', 'reloadify-frontend-sync'));
            setTimeout(function () { setChecking(false); }, 500);
        }

        function patch(change) {
            props.onChange(Object.assign({}, settings, change));
        }

        function updateBrowser(name, next) {
            var browsers = Object.assign({}, settings.browsers);
            browsers[name] = next;
            patch({ browsers: browsers });
        }

        function setAllBrowsers(on) {
            var browsers = {};
            BROWSERS.forEach(function (name) { browsers[name] = { normal: on, incognito: on }; });
            patch({ browsers: browsers });
            props.onToast(on ? 'success' : 'info', on
                ? __('All browsers enabled (normal and incognito)', 'reloadify-frontend-sync')
                : __('All browsers disabled', 'reloadify-frontend-sync'));
        }

        function chooseMode(mode) {
            if (settings.reload_mode === mode) { return; }
            patch({ reload_mode: mode });
            props.onToast('success', mode === 'hard'
                ? __('Hard reload selected', 'reloadify-frontend-sync')
                : __('Soft reload selected', 'reloadify-frontend-sync'));
        }

        var enabledCount = BROWSERS.filter(function (name) {
            var r = settings.browsers[name];
            return r && (r.normal || r.incognito);
        }).length;
        var pct = BROWSERS.length ? Math.round((enabledCount / BROWSERS.length) * 100) : 0;

        return el(
            'div',
            { className: 'reloadify-tab-body' },
            el(
                'div',
                { className: 'reloadify-overview' },
                el(ReloadControl, {
                    icon: 'power',
                    title: __('Developer Mode', 'reloadify-frontend-sync'),
                    hint: __('Off by default \u2014 leaving it on adds real load to a live site. Now stays on until you switch it off yourself.', 'reloadify-frontend-sync'),
                    status: settings.dev_mode_enabled ? __('On', 'reloadify-frontend-sync') : __('Off', 'reloadify-frontend-sync'),
                    on: !!settings.dev_mode_enabled,
                    onChange: function () {
                        var next = !settings.dev_mode_enabled;
                        patch({ dev_mode_enabled: next });
                        props.onToast(next ? 'success' : 'info', next ? __('Developer Mode enabled', 'reloadify-frontend-sync') : __('Developer Mode disabled', 'reloadify-frontend-sync'));
                    }
                }),
                el(ReloadControl, {
                    icon: 'tabs',
                    title: __('Reload all tabs', 'reloadify-frontend-sync'),
                    hint: __('Off by default: only the tab you are actually looking at reloads, so background tabs keep their scroll position and form state. Turn it on to refresh every open tab and window at once.', 'reloadify-frontend-sync'),
                    status: settings.all_tabs_reload_enabled ? __('All tabs', 'reloadify-frontend-sync') : __('Active tab only', 'reloadify-frontend-sync'),
                    on: !!settings.all_tabs_reload_enabled,
                    onChange: function () {
                        var next = !settings.all_tabs_reload_enabled;
                        patch({ all_tabs_reload_enabled: next });
                        // One switch, two modes: turning all-tabs off is the same action as turning active-tab-only on.
                        props.onToast('success', next ? __('All tab mode enabled', 'reloadify-frontend-sync') : __('Active tab mode enabled', 'reloadify-frontend-sync'));
                    }
                }),
                el(ReloadTile, { icon: 'browsers', tone: 'indigo', label: __('Browsers enabled', 'reloadify-frontend-sync'), value: enabledCount + ' / ' + BROWSERS.length },
                    el('div', { className: 'reloadify-meter', role: 'img', 'aria-label': pct + '%' }, el('span', { style: { width: pct + '%' } }))),
                el(ReloadTile, { icon: settings.reload_mode === 'hard' ? 'bolt' : 'refresh', tone: 'indigo', label: __('Reload mode', 'reloadify-frontend-sync'), value: settings.reload_mode === 'hard' ? __('Hard', 'reloadify-frontend-sync') : __('Soft', 'reloadify-frontend-sync') },
                    el('span', { className: 'reloadify-tile-meta' }, settings.reload_mode === 'hard' ? __('Fetches fresh from the server', 'reloadify-frontend-sync') : __('Uses the browser cache', 'reloadify-frontend-sync'))),
                el(ReloadTile, {
                    icon: 'clock', tone: 'indigo', label: __('Last change detected', 'reloadify-frontend-sync'),
                    hint: __('Ticks up on any wp-admin save. Not resetting on Check now usually means the save didn\u2019t submit.', 'reloadify-frontend-sync'),
                    value: settings.last_change_detected ? timeAgo(settings.last_change_detected) : '\u2014'
                },
                    el('button', { type: 'button', className: 'reloadify-ghost-btn', onClick: checkNow, disabled: checking }, checking ? __('Checking\u2026', 'reloadify-frontend-sync') : __('Check now', 'reloadify-frontend-sync')))
            ),

            el(
                'div',
                { className: 'reloadify-section reloadify-card' },
                el(CardHeading, { title: __('Reload behavior', 'reloadify-frontend-sync'), subtitle: __('How the page refreshes after a change is detected', 'reloadify-frontend-sync') }),
                el(
                    'div',
                    { className: 'reloadify-choice-row', role: 'radiogroup', 'aria-label': __('Reload behavior', 'reloadify-frontend-sync') },
                    el(ChoiceCard, { selected: settings.reload_mode !== 'hard', onSelect: function () { chooseMode('soft'); }, icon: 'refresh', title: __('Soft reload', 'reloadify-frontend-sync'), text: __('Standard page reload. Fast, uses the browser cache like a normal refresh.', 'reloadify-frontend-sync') }),
                    el(ChoiceCard, { selected: settings.reload_mode === 'hard', onSelect: function () { chooseMode('hard'); }, icon: 'bolt', title: __('Hard reload', 'reloadify-frontend-sync'), text: __('Appends a cache-busting parameter so the page is fetched fresh instead of served from cache. Use this if soft reload sometimes shows stale content.', 'reloadify-frontend-sync') })
                )
            ),

            el(
                'div',
                { className: 'reloadify-section reloadify-card' },
                el(CardHeading, {
                    title: __('Browsers & windows', 'reloadify-frontend-sync'),
                    subtitle: __('Choose where auto-reload runs \u2014 normal and incognito windows are separate', 'reloadify-frontend-sync'),
                    hint: __('Incognito detection is best-effort, not a guarantee \u2014 browsers keep patching the signals it relies on, so it can occasionally miss.', 'reloadify-frontend-sync'),
                    aside: el(
                        'div',
                        { className: 'reloadify-heading-actions' },
                        el('span', { className: 'reloadify-pass-count', 'aria-live': 'polite' }, enabledCount + ' ' + __('enabled', 'reloadify-frontend-sync')),
                        el('button', { type: 'button', className: 'reloadify-ghost-btn', onClick: function () { setAllBrowsers(true); }, disabled: enabledCount === BROWSERS.length && BROWSERS.every(function (n) { var r = settings.browsers[n]; return r && r.normal && r.incognito; }) }, __('Enable all', 'reloadify-frontend-sync')),
                        el('button', { type: 'button', className: 'reloadify-ghost-btn', onClick: function () { setAllBrowsers(false); }, disabled: enabledCount === 0 }, __('Disable all', 'reloadify-frontend-sync'))
                    )
                }),
                el(
                    'div',
                    { className: 'reloadify-browser-grid' },
                    BROWSERS.map(function (name) {
                        return el(BrowserCard, { key: name, name: name, value: settings.browsers[name], onChange: updateBrowser, onToast: props.onToast });
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

                    el('h3', null, __('php.ini', 'reloadify-frontend-sync')),
                    el('p', { className: 'reloadify-hint' }, __('Paste this whole block into your real php.ini and restart PHP. It\u2019s the only file that can set opcache.* and realpath_cache_* \u2014 if your host only gives you .user.ini access, ask them to apply the opcache / realpath_cache lines for you, since .user.ini can\u2019t set those either, only the three below it.', 'reloadify-frontend-sync')),
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

    var AUTO_WRITE_KEYS = ['max_input_time', 'post_max_size', 'upload_max_filesize'];
    var REAL_INI_KEYS = ['opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files', 'realpath_cache_size', 'realpath_cache_ttl'];

    function DirectiveCard(props) {
        var isRuntime = props.runtime;
        var isAutoWritable = !isRuntime && AUTO_WRITE_KEYS.indexOf(props.name) !== -1;
        var isOpcache = REAL_INI_KEYS.indexOf(props.name) !== -1;
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
            var liveValue = props.live || __('(default)', 'reloadify-frontend-sync');
            matchStatus = el(
                'div',
                { className: 'reloadify-setting-status ' + (matches ? 'is-match' : 'is-mismatch'), role: 'status' },
                el('span', { className: 'reloadify-setting-status-icon', 'aria-hidden': 'true' }, el(Ico, { name: matches ? 'check' : 'clock', size: 14 })),
                el(
                    'span',
                    { className: 'reloadify-setting-status-text' },
                    matches
                        ? el('span', null, __('Updated \u2014 the server is now using', 'reloadify-frontend-sync'), ' ', el('strong', null, liveValue))
                        : el('span', null, __('Saved \u2014 applies on the next page load. The server is currently using', 'reloadify-frontend-sync'), ' ', el('strong', null, liveValue))
                )
            );
        }

        var inputId = 'reloadify-perf-' + String(props.name).replace(/[^a-z0-9]+/gi, '-');

        return el(
            'div',
            { className: 'reloadify-setting' + (isRuntime ? (enabled ? ' is-live' : ' is-off') : '') + (isOpcache ? ' is-danger' : '') },
            el(
                'div',
                { className: 'reloadify-setting-top' },
                el('code', { title: PERF_LABELS[props.name] }, PERF_LABELS[props.name]),
                badge
            ),
            el('p', { className: 'reloadify-setting-help' }, perfHelp(props.name)),
            el(
                'div',
                { className: 'reloadify-setting-current' },
                el('span', null, __('Current', 'reloadify-frontend-sync')),
                el('strong', null, props.live || __('(default)', 'reloadify-frontend-sync'))
            ),
            matchStatus,
            el(
                'div',
                { className: 'reloadify-setting-controls' },
                el('label', { className: 'reloadify-sr-only', htmlFor: inputId }, PERF_LABELS[props.name]),
                el('input', {
                    id: inputId,
                    type: 'text',
                    className: 'reloadify-setting-input',
                    value: props.value,
                    spellCheck: false,
                    onChange: function (e) { props.onChange(props.name, e.target.value); }
                }),
                isRuntime && el(
                    'span',
                    { className: 'reloadify-setting-apply' },
                    el('span', null, enabled ? __('Applied', 'reloadify-frontend-sync') : __('Off', 'reloadify-frontend-sync')),
                    el(Switch, {
                        checked: enabled,
                        onChange: function (e) { props.onToggleRuntime(props.name, e.target.checked); }
                    })
                )
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

    /* ---------------- Shared icon + control pieces (Reload and Extensions tabs) ---------------- */

    var ICONS = {
        power: 'M12 3v8M7.1 6.4a7 7 0 109.8 0',
        tabs: 'M3 7a2 2 0 012-2h5l2 2h7a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z',
        browsers: 'M3 5h18v14H3V5zM3 9h18M7 7h.01M10 7h.01',
        refresh: 'M20 11a8 8 0 10-2.3 5.7M20 4v7h-7',
        bolt: 'M13 2L4 14h7l-1 8 9-12h-7l1-8z',
        clock: 'M12 7v5l3 2M12 3a9 9 0 100 18 9 9 0 000-18z',
        window: 'M3 5h18v14H3V5zM3 9h18',
        mask: 'M3 11c0-2 2-3 4-3 2 0 3 1 5 1s3-1 5-1c2 0 4 1 4 3 0 4-3 7-9 7s-9-3-9-7zM8 12h.01M16 12h.01',
        image: 'M4 5h16v14H4V5zM4 16l5-5 4 4 3-3 4 4M15.5 9.5h.01',
        shield: 'M12 3l8 3v6c0 4.5-3.2 8.2-8 9-4.8-.8-8-4.5-8-9V6l8-3zM9 12l2 2 4-4',
        upload: 'M12 16V4M7 9l5-5 5 5M4 16v4h16v-4',
        arrowUp: 'M12 19V5M6 11l6-6 6 6',
        palette: 'M12 3a9 9 0 100 18c1.4 0 2-1 2-2 0-1.2-1-1.6-1-2.7 0-1 .8-1.8 1.8-1.8H17a4 4 0 004-4c0-4-4-7.5-9-7.5zM7.5 11h.01M10 7.5h.01M14.5 7.5h.01',
        ruler: 'M4 16L16 4l4 4L8 20l-4-4zM8 12l2 2M11 9l2 2M14 6l2 2',
        check: 'M5 13l4 4L19 7',
        server: 'M4 5h16v5H4V5zM4 14h16v5H4v-5zM8 7.5h.01M8 16.5h.01M12 7.5h4M12 16.5h4',
        warn: 'M12 3l10 18H2L12 3zM12 10v5M12 18h.01',
        sparkle: 'M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3zM19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8L19 16z',
        search: 'M11 4a7 7 0 100 14 7 7 0 000-14zM21 21l-5-5',
        play: 'M8 5v14l11-7L8 5z',
        file: 'M6 3h9l4 4v14H6V3zM14 3v5h5',
        info: 'M12 8h.01M11 12h1v5h1M12 3a9 9 0 100 18 9 9 0 000-18z'
    };

    function Ico(props) {
        return el(
            'svg',
            { viewBox: '0 0 24 24', width: props.size || 18, height: props.size || 18, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' },
            el('path', { d: ICONS[props.name] || ICONS.info })
        );
    }

    function IconChip(props) {
        return el('span', { className: 'reloadify-chip reloadify-chip--' + (props.tone || 'indigo'), 'aria-hidden': 'true' }, el(Ico, { name: props.name, size: props.size }));
    }

    /** Fires `fn` once the user stops changing a continuous control (colour, slider, number). */
    function useSettled(fn, delay) {
        var timer = useRef(null);
        return function () {
            var args = arguments;
            clearTimeout(timer.current);
            timer.current = setTimeout(function () { fn.apply(null, args); }, delay || 700);
        };
    }

    /* ---------------- Shared card pieces ---------------- */

    function CardHeading(props) {
        return el(
            'div',
            { className: 'reloadify-card-head' },
            el(
                'div',
                { className: 'reloadify-card-head-text' },
                el('h2', null, props.title, props.hint && el(InfoIcon, { text: props.hint })),
                props.subtitle && el('p', { className: 'reloadify-card-sub' }, props.subtitle)
            ),
            props.aside
        );
    }

    function CardHead(props) {
        return el(
            'div',
            { className: 'reloadify-card-head' },
            el(
                'div',
                { className: 'reloadify-card-head-text' },
                el('h2', null, props.title),
                props.subtitle && el('p', { className: 'reloadify-card-sub' }, props.subtitle)
            ),
            el(Switch, { large: true, checked: props.checked, onChange: props.onChange, disabled: props.disabled })
        );
    }

    function formatBytes(bytes) {
        bytes = Number(bytes) || 0;
        if (bytes >= 1048576) { return (bytes / 1048576).toFixed(bytes >= 10485760 ? 0 : 1) + ' MB'; }
        if (bytes >= 1024) { return Math.round(bytes / 1024) + ' KB'; }
        return bytes + ' B';
    }

    /* ---------------- Speed Boost Card ---------------- */

    var SPEED_OPTION_ORDER = ['minify_js', 'minify_css', 'minify_html', 'remove_html_comments', 'collapse_whitespace', 'remove_query_strings', 'lazy_load_images', 'defer_js', 'delay_js', 'browser_cache', 'dashicons', 'db_cleanup'];

    /* One small inline SVG per pass (24x24, stroke icons) so the list stays dependency-free. */
    var PASS_ICONS = {
        minify_js: 'M8 8l-4 4 4 4M16 8l4 4-4 4M13.5 6l-3 12',
        minify_css: 'M4 4h16l-1.5 14L12 20l-6.5-2L4 4zM8 9h8M8.5 13h7',
        minify_html: 'M9 8l-5 4 5 4M15 8l5 4-5 4',
        remove_html_comments: 'M4 5h16v11H9l-5 4V5zM9 9.5l5 3M14 9.5l-5 3',
        collapse_whitespace: 'M4 6h16M4 18h16M12 9v6M9 12l3-3 3 3',
        remove_query_strings: 'M10 14a4 4 0 005.7 0l3-3a4 4 0 00-5.7-5.7l-1 1M14 10a4 4 0 00-5.7 0l-3 3A4 4 0 0011 18.7l1-1M4 4l16 16',
        lazy_load_images: 'M4 5h16v14H4V5zM4 16l5-5 4 4 3-3 4 4M15.5 9.5h.01',
        defer_js: 'M5 5l7 7-7 7M13 5l7 7-7 7',
        delay_js: 'M12 7v5l3 2M12 3a9 9 0 100 18 9 9 0 000-18z',
        browser_cache: 'M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 7v5c0 1.7 3.6 3 8 3s8-1.3 8-3V7M4 12v5c0 1.7 3.6 3 8 3s8-1.3 8-3v-5',
        dashicons: 'M12 3l2.6 5.6 6 .7-4.5 4.1 1.2 6L12 16.5 6.7 19.4l1.2-6L3.4 9.3l6-.7L12 3z',
        db_cleanup: 'M5 7h14M10 7V4h4v3M7 7l1 13h8l1-13M10 11v6M14 11v6'
    };

    function passTitle(key) {
        var titles = {
            minify_js: __('Minify JavaScript', 'reloadify-frontend-sync'),
            minify_css: __('Minify CSS', 'reloadify-frontend-sync'),
            minify_html: __('Minify HTML output', 'reloadify-frontend-sync'),
            remove_html_comments: __('Remove HTML comments', 'reloadify-frontend-sync'),
            collapse_whitespace: __('Remove unnecessary whitespace', 'reloadify-frontend-sync'),
            remove_query_strings: __('Remove query strings', 'reloadify-frontend-sync'),
            lazy_load_images: __('Lazy-load images', 'reloadify-frontend-sync'),
            defer_js: __('Defer non-critical scripts', 'reloadify-frontend-sync'),
            delay_js: __('Delay tracking & chat scripts', 'reloadify-frontend-sync'),
            browser_cache: __('Browser caching & compression', 'reloadify-frontend-sync'),
            dashicons: __('Skip Dashicons for visitors', 'reloadify-frontend-sync'),
            db_cleanup: __('Weekly transient cleanup', 'reloadify-frontend-sync')
        };
        return titles[key] || key;
    }

    function passDescription(key) {
        var text = {
            minify_js: __('Reduce script payload size, including large inline scripts. Cached copies only \u2014 your originals are never touched.', 'reloadify-frontend-sync'),
            minify_css: __('Trim stylesheets and inline styles, keeping images and fonts working by rewriting their paths.', 'reloadify-frontend-sync'),
            minify_html: __('Remove unnecessary markup: whitespace between tags in the HTML WordPress sends.', 'reloadify-frontend-sync'),
            remove_html_comments: __('Drop developer comments from the page source (conditional comments are kept).', 'reloadify-frontend-sync'),
            collapse_whitespace: __('Collapse extra spaces and line breaks. <pre> and <textarea> are left alone.', 'reloadify-frontend-sync'),
            remove_query_strings: __('Improve static asset caching. A file keeps its ?ver= tag for 7 days after it changes, so updates are never hidden.', 'reloadify-frontend-sync'),
            lazy_load_images: __('Load images and iframes when needed. The first two images stay eager; galleries and sliders are skipped.', 'reloadify-frontend-sync'),
            defer_js: __('Prioritize page content. Only stand-alone head scripts nothing else depends on \u2014 never jQuery, WooCommerce or builders.', 'reloadify-frontend-sync'),
            delay_js: __('Hold back analytics, ads and chat widgets until the first interaction.', 'reloadify-frontend-sync'),
            browser_cache: __('Reuse repeat-visit assets: gzip plus long-lived caching for images, fonts and video (Apache .htaccess).', 'reloadify-frontend-sync'),
            dashicons: __('Skip the Dashicons font for logged-out visitors unless a stylesheet depends on it.', 'reloadify-frontend-sync'),
            db_cleanup: __('Delete expired transients once a week to keep database queries fast.', 'reloadify-frontend-sync')
        };
        return text[key] || '';
    }

    function PassIcon(props) {
        return el(
            'span',
            { className: 'reloadify-pass-icon', 'aria-hidden': 'true' },
            el(
                'svg',
                { viewBox: '0 0 24 24', width: 20, height: 20, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' },
                el('path', { d: PASS_ICONS[props.name] || PASS_ICONS.minify_js })
            )
        );
    }

    function SpeedBoostCard(props) {
        var speed = props.speed;
        var stateSaving = useState(false);
        var saving = stateSaving[0], setSaving = stateSaving[1];

        var options = speed.options || {};
        var labels = speed.optionLabels || {};

        function save(data, onDone) {
            setSaving(true);
            wp.apiFetch({ path: '/reloadify/v1/speed', method: 'POST', data: data })
                .then(function (response) {
                    setSaving(false);
                    props.onChange(response);
                    if (onDone) { onDone(response); }
                })
                .catch(function () {
                    setSaving(false);
                    props.onToast('error', __('Could not update Speed Boost.', 'reloadify-frontend-sync'));
                });
        }

        function toggle() {
            save({ enabled: !speed.enabled }, function (response) {
                props.onToast(response.enabled ? 'success' : 'info', response.enabled
                    ? __('Speed Boost enabled', 'reloadify-frontend-sync')
                    : __('Speed Boost disabled', 'reloadify-frontend-sync'));
            });
        }

        function toggleOption(key) {
            var next = Object.assign({}, options);
            next[key] = !next[key];
            save({ options: next }, function (response) {
                var on = !!(response.options && response.options[key]);
                props.onToast(on ? 'success' : 'info', on
                    ? passTitle(key) + ' ' + __('enabled', 'reloadify-frontend-sync')
                    : passTitle(key) + ' ' + __('disabled', 'reloadify-frontend-sync'));
            });
        }

        var activeCount = SPEED_OPTION_ORDER.filter(function (key) { return !!options[key]; }).length;

        var optionList = speed.enabled && el(
            'div',
            { className: 'reloadify-speed-options' },
            el(
                'div',
                { className: 'reloadify-card-eyebrow' },
                el('span', null, __('Optimization rules', 'reloadify-frontend-sync')),
                el('span', { className: 'reloadify-pass-count', 'aria-live': 'polite' }, activeCount + ' ' + __('enabled', 'reloadify-frontend-sync'))
            ),
            el(
                'div',
                { className: 'reloadify-pass-list' },
                SPEED_OPTION_ORDER.map(function (key) {
                    if (!Object.prototype.hasOwnProperty.call(labels, key)) { return null; }
                    var on = !!options[key];
                    return el(
                        'div',
                        { key: key, className: 'reloadify-pass' + (on ? ' is-on' : ' is-off') },
                        el(PassIcon, { name: key }),
                        el(
                            'div',
                            { className: 'reloadify-pass-text' },
                            el('strong', { className: 'reloadify-pass-title' }, passTitle(key)),
                            el('span', { className: 'reloadify-pass-desc' }, passDescription(key) || labels[key])
                        ),
                        el(Switch, { checked: on, onChange: function () { toggleOption(key); }, disabled: saving })
                    );
                })
            )
        );

        return el(
            'div',
            { className: 'reloadify-section reloadify-speed-card' },
            el(CardHead, {
                title: __('Speed Boost', 'reloadify-frontend-sync'),
                subtitle: __('Lightweight rules for faster page loads', 'reloadify-frontend-sync'),
                checked: speed.enabled,
                onChange: toggle,
                disabled: saving
            }),
            optionList
        );
    }

    /* ---------------- Delete Data on Uninstall Card ---------------- */

    var SCOPE_ORDER = ['settings', 'cache', 'storage', 'logs'];
    var SCOPE_ICONS = {
        settings: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19 12a7 7 0 00-.1-1.2l2-1.5-2-3.4-2.3 1a7 7 0 00-2-1.2L14.3 3h-4l-.4 2.7a7 7 0 00-2 1.2l-2.3-1-2 3.4 2 1.5A7 7 0 005 12c0 .4 0 .8.1 1.2l-2 1.5 2 3.4 2.3-1a7 7 0 002 1.2l.4 2.7h4l.4-2.7a7 7 0 002-1.2l2.3 1 2-3.4-2-1.5c.1-.4.1-.8.1-1.2z',
        cache: 'M4 7c0-1.7 3.6-3 8-3s8 1.3 8 3-3.6 3-8 3-8-1.3-8-3zM4 7v5c0 1.7 3.6 3 8 3s8-1.3 8-3V7',
        storage: 'M4 6h16v4H4V6zM4 14h16v4H4v-4zM8 8h.01M8 16h.01',
        logs: 'M6 3h9l4 4v14H6V3zM14 3v5h5M9 13h7M9 17h7'
    };

    function DeleteOnUninstallCard(props) {
        var cleanup = props.cleanup;
        var scope = cleanup.scope || {};
        var scopeLabels = cleanup.labels || {};
        var stateSaving = useState(false);
        var saving = stateSaving[0], setSaving = stateSaving[1];

        function save(data, onDone) {
            setSaving(true);
            wp.apiFetch({ path: '/reloadify/v1/cleanup', method: 'POST', data: data })
                .then(function (response) {
                    setSaving(false);
                    props.onChange(response);
                    if (onDone) { onDone(response); }
                })
                .catch(function () {
                    setSaving(false);
                    props.onToast('error', __('Couldn\u2019t save that setting', 'reloadify-frontend-sync'));
                });
        }

        function toggleMaster() {
            save({ enabled: !cleanup.enabled }, function (response) {
                props.onToast(response.enabled ? 'warning' : 'info', response.enabled
                    ? __('Data removal on uninstall enabled', 'reloadify-frontend-sync')
                    : __('Delete Data on Uninstall disabled', 'reloadify-frontend-sync'));
            });
        }

        function toggleScope(key) {
            var next = {};
            next[key] = !scope[key];
            save({ scope: next }, function (response) {
                var title = (scopeLabels[key] && scopeLabels[key].title) || key;
                var on = !!(response.scope && response.scope[key]);
                props.onToast(on ? 'warning' : 'info', on
                    ? title + ' ' + __('will be removed on uninstall', 'reloadify-frontend-sync')
                    : title + ' ' + __('will be kept on uninstall', 'reloadify-frontend-sync'));
            });
        }

        var selectedCount = SCOPE_ORDER.filter(function (key) { return !!scope[key]; }).length;

        var body = el(
            'div',
            { className: 'reloadify-cleanup-body' + (cleanup.enabled ? '' : ' is-inactive') },
            el(
                'div',
                { className: 'reloadify-privacy' },
                el('span', { className: 'reloadify-privacy-icon', 'aria-hidden': 'true' },
                    el('svg', { viewBox: '0 0 24 24', width: 20, height: 20, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' },
                        el('path', { d: 'M12 3l8 3v6c0 4.5-3.2 8.2-8 9-4.8-.8-8-4.5-8-9V6l8-3zM12 8v5M12 16h.01' }))),
                el(
                    'div',
                    { className: 'reloadify-privacy-text' },
                    el('strong', null, __('Privacy cleanup', 'reloadify-frontend-sync')),
                    el('span', null, __('Remove plugin data when the plugin is deleted.', 'reloadify-frontend-sync'))
                )
            ),
            el(
                'div',
                { className: 'reloadify-card-eyebrow' },
                el('span', null, __('Select what to remove', 'reloadify-frontend-sync')),
                el('span', { className: 'reloadify-pass-count', 'aria-live': 'polite' }, selectedCount + ' / ' + SCOPE_ORDER.length)
            ),
            el(
                'div',
                { className: 'reloadify-pass-list' },
                SCOPE_ORDER.map(function (key) {
                    var info = scopeLabels[key] || { title: key, text: '' };
                    var on = !!scope[key];
                    return el(
                        'div',
                        { key: key, className: 'reloadify-pass reloadify-pass--scope' + (on ? ' is-on' : ' is-off') },
                        el('span', { className: 'reloadify-pass-icon', 'aria-hidden': 'true' },
                            el('svg', { viewBox: '0 0 24 24', width: 20, height: 20, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' },
                                el('path', { d: SCOPE_ICONS[key] }))),
                        el(
                            'div',
                            { className: 'reloadify-pass-text' },
                            el('strong', { className: 'reloadify-pass-title' }, info.title),
                            el('span', { className: 'reloadify-pass-desc' }, info.text)
                        ),
                        el(Switch, { checked: on, onChange: function () { toggleScope(key); }, disabled: saving || !cleanup.enabled })
                    );
                })
            ),
            el(
                'div',
                { className: 'reloadify-warning', role: 'note' },
                el('strong', null, __('Review before enabling', 'reloadify-frontend-sync')),
                el('span', null, __('Deleting the plugin then permanently removes the selected data \u2014 it cannot be undone. Your Media Library files are never touched.', 'reloadify-frontend-sync'))
            )
        );

        return el(
            'div',
            { className: 'reloadify-section reloadify-speed-card reloadify-cleanup-card' },
            el(CardHead, {
                title: __('Delete Data on Uninstall', 'reloadify-frontend-sync'),
                subtitle: __('Choose what happens to local data', 'reloadify-frontend-sync'),
                checked: cleanup.enabled,
                onChange: toggleMaster,
                disabled: saving
            }),
            body
        );
    }

    /* ---------------- Media Optimization Card ---------------- */

    function PreviewPane(props) {
        var side = props.side;
        var pill = props.pill;
        return el(
            'figure',
            { className: 'reloadify-preview-pane reloadify-preview-pane--' + props.kind },
            el('span', { className: 'reloadify-preview-badge' }, props.badge),
            side.uri
                ? el('img', { src: side.uri, alt: props.badge, loading: 'lazy', decoding: 'async' })
                : el('div', { className: 'reloadify-preview-empty' }),
            el(
                'figcaption',
                null,
                el('strong', null, props.label),
                el('span', null, formatBytes(side.bytes)),
                pill
            )
        );
    }

    function BeforeAfterPreview(props) {
        var preview = props.preview;

        if (props.loading && !preview) {
            return el('div', { className: 'reloadify-preview-note' }, __('Preparing a real before/after\u2026', 'reloadify-frontend-sync'));
        }

        if (!preview) { return null; }

        if (!preview.available) {
            return el('div', { className: 'reloadify-preview-note' },
                preview.reason === 'no_image'
                    ? __('Upload a JPEG or PNG to the Media Library to see a real before/after here.', 'reloadify-frontend-sync')
                    : __('A preview could not be generated on this server.', 'reloadify-frontend-sync'));
        }

        var saved = preview.saved_percent;
        var pillText = saved > 0
            ? wp.i18n.sprintf(
                /* translators: %d: percent smaller */
                __('%d%% smaller', 'reloadify-frontend-sync'), saved)
            : (saved < 0
                ? wp.i18n.sprintf(
                    /* translators: %d: percent larger */
                    __('%d%% larger', 'reloadify-frontend-sync'), Math.abs(saved))
                : __('Same size', 'reloadify-frontend-sync'));

        var afterLabel = (preview.format === 'original'
            ? (preview.after.mime === 'image/png' ? 'PNG' : 'JPEG')
            : String(preview.format).toUpperCase()) + ' \u00b7 ' + preview.effective_quality + '%';
        var beforeLabel = (preview.before.mime === 'image/png' ? 'PNG' : 'JPEG') + ' \u00b7 ' + __('as WordPress saves it', 'reloadify-frontend-sync');

        return el(
            'div',
            { className: 'reloadify-preview' + (props.loading ? ' is-loading' : '') },
            el('div', { className: 'reloadify-card-eyebrow' }, el('span', null, __('Before / after preview', 'reloadify-frontend-sync'))),
            el(
                'div',
                { className: 'reloadify-preview-grid' },
                el(PreviewPane, { kind: 'before', badge: __('Before', 'reloadify-frontend-sync'), side: preview.before, label: beforeLabel }),
                el(PreviewPane, {
                    kind: 'after',
                    badge: __('After', 'reloadify-frontend-sync'),
                    side: preview.after,
                    label: afterLabel,
                    pill: el('em', { className: 'reloadify-preview-pill' + (saved < 0 ? ' is-worse' : '') }, pillText)
                })
            ),
            el('p', { className: 'reloadify-preview-meta' },
                wp.i18n.sprintf(
                    /* translators: 1: file name, 2: width, 3: height, 4: file size */
                    __('Measured on %1$s (%2$d\u00d7%3$d, %4$s) scaled to preview size.', 'reloadify-frontend-sync'),
                    preview.name, preview.width, preview.height, formatBytes(preview.original_bytes)
                )
            )
        );
    }

    function MediaOptimizationCard(props) {
        var media = props.media;
        var stats = media.stats || { images: 0, avg_percent: 0, saved_bytes: 0 };
        var caps = media.format_capabilities || { webp: false, avif: false };

        var stateSaving = useState(false);
        var saving = stateSaving[0], setSaving = stateSaving[1];

        var stateQuality = useState(media.quality || 80);
        var quality = stateQuality[0], setQuality = stateQuality[1];

        var statePreview = useState(null);
        var preview = statePreview[0], setPreview = statePreview[1];

        var stateLoading = useState(false);
        var previewLoading = stateLoading[0], setPreviewLoading = stateLoading[1];

        var requestId = useRef(0);
        var saveTimer = useRef(null);

        function save(data, onDone) {
            setSaving(true);
            wp.apiFetch({ path: '/reloadify/v1/media', method: 'POST', data: data })
                .then(function (response) {
                    setSaving(false);
                    props.onChange(response);
                    if (onDone) { onDone(response); }
                })
                .catch(function () {
                    setSaving(false);
                    props.onToast('error', __('Could not update Media Optimization.', 'reloadify-frontend-sync'));
                });
        }

        function toggle() {
            save({ enabled: !media.enabled }, function (response) {
                props.onToast(response.enabled ? 'success' : 'info', response.enabled
                    ? __('Media Optimization enabled', 'reloadify-frontend-sync')
                    : __('Media Optimization disabled', 'reloadify-frontend-sync'));
            });
        }

        function setFormat(format) {
            if (format === media.format_preference) { return; }
            save({ format_preference: format }, function (response) {
                var names = { auto: __('Automatic', 'reloadify-frontend-sync'), webp: 'WebP', avif: 'AVIF', original: __('Original format', 'reloadify-frontend-sync') };
                props.onToast('success', __('Image format:', 'reloadify-frontend-sync') + ' ' + (names[response.format_preference] || response.format_preference));
            });
        }

        // The slider moves the number and the preview at once; the setting itself
        // is saved once the hand stops, so dragging doesn't fire a request per step.
        function onQuality(value) {
            value = parseInt(value, 10);
            setQuality(value);
            clearTimeout(saveTimer.current);
            saveTimer.current = setTimeout(function () {
                save({ quality: value }, function () {
                    props.onToast('success', wp.i18n.sprintf(
                        /* translators: %d: quality percent */
                        __('Compression quality set to %d%%', 'reloadify-frontend-sync'), value));
                });
            }, 700);
        }

        // Real before/after for whatever format and quality are selected right now.
        useEffect(function () {
            if (!media.enabled) { return undefined; }
            var id = ++requestId.current;
            setPreviewLoading(true);
            var timer = setTimeout(function () {
                wp.apiFetch({ path: '/reloadify/v1/media/preview', method: 'POST', data: { format: media.format_preference, quality: quality } })
                    .then(function (response) {
                        if (id !== requestId.current) { return; }
                        setPreview(response);
                        setPreviewLoading(false);
                    })
                    .catch(function () {
                        if (id !== requestId.current) { return; }
                        setPreview({ available: false, reason: 'error' });
                        setPreviewLoading(false);
                    });
            }, 400);
            return function () { clearTimeout(timer); };
        }, [media.enabled, media.format_preference, quality]);

        var bf = props.backfill || { status: 'idle' };
        var running = bf.status === 'running';
        var pendingImages = (media.pending && media.pending.images) || 0;
        var pendingVideos = (media.pending && media.pending.videos) || 0;
        var pct = bf.total > 0 ? Math.min(100, Math.round((bf.processed / bf.total) * 100)) : 0;
        var allDone = !running && pendingImages === 0 && pendingVideos === 0;

        function optimizeNow() {
            props.onBackfillStart();
        }

        var buttonLabel;
        if (running) {
            buttonLabel = bf.total > 0
                ? wp.i18n.sprintf(
                    /* translators: %d: percent complete */
                    __('Optimizing\u2026 %d%%', 'reloadify-frontend-sync'), pct)
                : __('Optimizing\u2026', 'reloadify-frontend-sync');
        } else if (bf.status === 'done' || allDone) {
            buttonLabel = __('All media optimized', 'reloadify-frontend-sync');
        } else {
            buttonLabel = __('Optimize existing media now', 'reloadify-frontend-sync');
        }

        var waiting = null;
        if (!running && (pendingImages > 0 || pendingVideos > 0)) {
            waiting = el('span', { className: 'reloadify-media-waiting' },
                wp.i18n.sprintf(
                    /* translators: 1: images waiting, 2: videos waiting */
                    __('%1$d images and %2$d videos waiting', 'reloadify-frontend-sync'), pendingImages, pendingVideos));
        } else if (running) {
            waiting = el('span', { className: 'reloadify-media-waiting' }, __('Runs in the background \u2014 you can switch tabs.', 'reloadify-frontend-sync'));
        }

        var formatOptions = [
            { value: 'auto', label: __('Auto', 'reloadify-frontend-sync'), disabled: false },
            { value: 'webp', label: 'WebP', disabled: !caps.webp },
            { value: 'avif', label: 'AVIF', disabled: !caps.avif },
            { value: 'original', label: __('Original', 'reloadify-frontend-sync'), disabled: false }
        ];

        var body = media.enabled && el(
            'div',
            { className: 'reloadify-media-body' },
            el(
                'div',
                { className: 'reloadify-media-panel' },
                el('div', { className: 'reloadify-media-format-label' }, __('Image format', 'reloadify-frontend-sync')),
                el(
                    'div',
                    { className: 'reloadify-segmented', role: 'radiogroup', 'aria-label': __('Image format', 'reloadify-frontend-sync') },
                    formatOptions.map(function (opt) {
                        var active = (media.format_preference || 'auto') === opt.value;
                        return el(
                            'button',
                            {
                                key: opt.value,
                                type: 'button',
                                role: 'radio',
                                'aria-checked': active,
                                className: 'reloadify-segment' + (active ? ' is-active' : '') + (opt.disabled ? ' is-disabled' : ''),
                                disabled: opt.disabled || saving,
                                title: opt.disabled ? __('Not supported by this server\u2019s image library', 'reloadify-frontend-sync') : '',
                                onClick: function () { setFormat(opt.value); }
                            },
                            opt.label
                        );
                    })
                ),
                el('p', { className: 'reloadify-media-format-note' }, caps.avif
                    ? __('Auto uses WebP \u2014 fast to create and supported everywhere. AVIF makes slightly smaller files but takes several times longer to encode.', 'reloadify-frontend-sync')
                    : __('AVIF isn\u2019t available on this server \u2014 Auto uses WebP, which is also the fastest to create.', 'reloadify-frontend-sync'))
            ),
            el(
                'div',
                { className: 'reloadify-quality' },
                el(
                    'div',
                    { className: 'reloadify-quality-head' },
                    el('label', { htmlFor: 'reloadify-quality-range' }, __('Compression quality', 'reloadify-frontend-sync')),
                    el('span', { className: 'reloadify-quality-pill' }, quality + '%')
                ),
                el('input', {
                    id: 'reloadify-quality-range',
                    className: 'reloadify-range',
                    type: 'range', min: 40, max: 95, step: 5, value: quality,
                    style: { '--reloadify-fill': (((quality - 40) / 55) * 100) + '%' },
                    onChange: function (e) { onQuality(e.target.value); }
                }),
                el('div', { className: 'reloadify-quality-scale' },
                    el('span', null, __('Smaller files', 'reloadify-frontend-sync')),
                    el('span', null, __('Sharper images', 'reloadify-frontend-sync'))
                ),
                el('p', { className: 'reloadify-media-format-note' },
                    __('A ceiling, never a boost: if an encoder\u2019s own default is already lower, it is kept.', 'reloadify-frontend-sync'))
            ),
            el(
                'div',
                { className: 'reloadify-stat-grid' },
                el('div', { className: 'reloadify-stat' },
                    el('strong', null, stats.images ? stats.avg_percent + '%' : '\u2014'),
                    el('span', null, __('Avg. size saved', 'reloadify-frontend-sync'))),
                el('div', { className: 'reloadify-stat' },
                    el('strong', null, stats.images ? formatBytes(stats.saved_bytes) : '\u2014'),
                    el('span', null, stats.images
                        ? wp.i18n.sprintf(
                            /* translators: %d: number of optimized images */
                            __('Estimated saved \u00b7 %d images', 'reloadify-frontend-sync'), stats.images)
                        : __('Appears after images are optimized', 'reloadify-frontend-sync')))
            ),
            el(BeforeAfterPreview, { preview: preview, loading: previewLoading }),
            el('p', { className: 'reloadify-tip' }, __('Tip: your original files are always kept, so nothing is lost if you change your mind.', 'reloadify-frontend-sync')),
            el(
                'div',
                { className: 'reloadify-media-backfill-row' },
                el(
                    'button',
                    {
                        type: 'button',
                        className: 'reloadify-optimize-btn' + (running ? ' is-running' : '') + ((bf.status === 'done' || allDone) ? ' is-done' : ''),
                        onClick: optimizeNow,
                        disabled: running || allDone,
                        'aria-busy': running
                    },
                    running ? el('span', { className: 'reloadify-spinner reloadify-spinner--light' }) : el(Ico, { name: (bf.status === 'done' || allDone) ? 'check' : 'bolt', size: 15 }),
                    buttonLabel
                ),
                waiting
            )
        );

        return el(
            'div',
            { className: 'reloadify-section reloadify-speed-card reloadify-media-card' },
            el(CardHead, {
                title: __('Media Optimization', 'reloadify-frontend-sync'),
                subtitle: __('Compress and serve smarter images', 'reloadify-frontend-sync'),
                checked: media.enabled,
                onChange: toggle,
                disabled: saving
            }),
            body
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
            props.onToast(val ? 'success' : 'info', PERF_LABELS[key] + ' ' + (val ? __('will be applied live', 'reloadify-frontend-sync') : __('is no longer applied', 'reloadify-frontend-sync')));
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

        var liveApplied = PERF_GROUPS.runtime.keys.filter(function (key) {
            return !!data.settings.runtime_enabled[key];
        }).length;

        return el(
            'div',
            { className: 'reloadify-tab-body' },
            el(
                'div',
                { className: 'reloadify-perf-hero' },
                el(IconChip, { name: 'server', tone: 'indigo', size: 22 }),
                el(
                    'div',
                    { className: 'reloadify-perf-hero-text' },
                    el('h2', null, __('Server Performance', 'reloadify-frontend-sync'), el(InfoIcon, { text: __('Only PHP settings still changeable at runtime update live here. The rest need a server-side change.', 'reloadify-frontend-sync') })),
                    el('p', null, __('See what is live, what needs your host, and what is locked at PHP startup.', 'reloadify-frontend-sync')),
                    el(
                        'div',
                        { className: 'reloadify-perf-hero-stats' },
                        el('span', { className: 'reloadify-stat-chip is-live' }, liveApplied + ' / ' + PERF_GROUPS.runtime.keys.length + ' ' + __('live', 'reloadify-frontend-sync')),
                        el('span', { className: 'reloadify-stat-chip' }, PERF_GROUPS.host.keys.length + ' ' + __('need host', 'reloadify-frontend-sync')),
                        el('span', { className: 'reloadify-stat-chip is-danger' }, PERF_GROUPS.opcache.keys.length + ' ' + __('startup-only', 'reloadify-frontend-sync'))
                    )
                ),
                el(
                    'button',
                    { type: 'button', className: 'reloadify-sync-btn', onClick: props.onSync, disabled: props.syncing },
                    el(Ico, { name: 'refresh', size: 15 }),
                    props.syncing ? __('Syncing\u2026', 'reloadify-frontend-sync') : __('Sync from server', 'reloadify-frontend-sync')
                )
            ),

            el(
                'div',
                { className: 'reloadify-toggle-grid' },
                el(SpeedBoostCard, { speed: props.speed, onChange: props.onSpeedChange, onToast: props.onToast }),
                el(MediaOptimizationCard, { media: props.media, onChange: props.onMediaChange, onToast: props.onToast, backfill: props.backfill, onBackfillStart: props.onBackfillStart }),
                el(DeleteOnUninstallCard, { cleanup: props.cleanup, onChange: props.onCleanupChange, onToast: props.onToast })
            ),

            Object.keys(PERF_GROUPS).map(function (groupKey) {
                var group = PERF_GROUPS[groupKey];
                var isDanger = groupKey === 'opcache';

                var aside = null;
                if (groupKey === 'runtime') {
                    aside = el('span', { className: 'reloadify-pass-count' }, liveApplied + ' / ' + group.keys.length + ' ' + __('applied', 'reloadify-frontend-sync'));
                } else if (groupKey === 'host') {
                    aside = el('span', { className: 'reloadify-stat-chip' }, __('Auto-attempt available', 'reloadify-frontend-sync'));
                } else {
                    aside = el('span', { className: 'reloadify-stat-chip is-danger' }, __('Local dev only', 'reloadify-frontend-sync'));
                }

                return el(
                    'div',
                    { className: 'reloadify-section reloadify-card' + (isDanger ? ' reloadify-card--danger' : ''), key: groupKey },
                    el(CardHeading, { title: group.title, subtitle: group.subtitle, hint: group.hint, aside: aside }),
                    el(
                        'div',
                        { className: 'reloadify-setting-grid' },
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
                            { type: 'button', className: 'reloadify-btn reloadify-btn--primary', onClick: attemptServerOverride, disabled: applying },
                            el(Ico, { name: 'bolt', size: 15 }),
                            applying ? __('Attempting\u2026', 'reloadify-frontend-sync') : __('Attempt automatic server override', 'reloadify-frontend-sync')
                        ),
                        el(
                            'button',
                            { type: 'button', className: 'reloadify-btn', onClick: function () { setModalOpen(true); } },
                            el(Ico, { name: 'file', size: 15 }),
                            __('Generate config snippet', 'reloadify-frontend-sync')
                        ),
                        el(InfoIcon, { text: __('Writes .user.ini / .htaccess. Safe to try \u2014 it just fails quietly if unsupported.', 'reloadify-frontend-sync') }),
                        el(ApplyResults, { results: applyResults })
                    ),

                    isDanger && el(
                        'div',
                        { className: 'reloadify-danger-panel' },
                        el(
                            'div',
                            { className: 'reloadify-danger-head' },
                            el('span', { className: 'reloadify-chip reloadify-chip--red', 'aria-hidden': 'true' }, el(Ico, { name: 'warn', size: 18 })),
                            el('strong', null, __('Local development only \u2014 do not use on a live/production site.', 'reloadify-frontend-sync'))
                        ),
                        el(
                            'ul',
                            { className: 'reloadify-danger-facts' },
                            el('li', null, __('These five values are fixed when PHP starts, so only a real php.ini edit can change them.', 'reloadify-frontend-sync')),
                            el('li', null, __('A mistake can bring PHP down for every site on the server.', 'reloadify-frontend-sync')),
                            el('li', null, __('Nothing changes until PHP is restarted \u2014 this plugin cannot restart it for you.', 'reloadify-frontend-sync'))
                        ),
                        el(
                            'div',
                            { className: 'reloadify-danger-file' },
                            el(Ico, { name: 'file', size: 15 }),
                            data.phpIniPath
                                ? el('span', null, __('File this would write to: ', 'reloadify-frontend-sync'), el('code', null, data.phpIniPath))
                                : el('span', null, __('This server reports no loaded php.ini file at all, so this action can\u2019t do anything here.', 'reloadify-frontend-sync'))
                        ),
                        el(
                            'details',
                            { className: 'reloadify-danger-details' },
                            el('summary', null, __('Read the full explanation', 'reloadify-frontend-sync')),
                            el('p', null, __('These five settings (opcache sizing plus the realpath cache) are locked in when PHP starts up, so the only way to change them is editing your real php.ini file and restarting PHP \u2014 no plugin can do it any other way, and .user.ini / .htaccess genuinely cannot reach them regardless of what\u2019s written there. This action edits that file directly. A mistake here, or running it on a shared or live server, can bring down PHP for every site on that server until someone fixes it by hand. Only use this on a local site you fully control and can easily reinstall (Local, XAMPP, MAMP, Laragon, or your own Docker setup).', 'reloadify-frontend-sync'))
                        ),
                        el(
                            'label',
                            { className: 'reloadify-danger-confirm' + (opcacheConfirmed ? ' is-checked' : '') },
                            el('input', {
                                type: 'checkbox',
                                checked: opcacheConfirmed,
                                onChange: function (e) { setOpcacheConfirmed(e.target.checked); }
                            }),
                            el('span', null, __('I understand this can crash PHP for the whole server, and I\u2019m only doing this on a local development site I fully control.', 'reloadify-frontend-sync'))
                        ),
                        el(
                            'button',
                            { type: 'button', className: 'reloadify-btn reloadify-btn--danger', onClick: attemptOpcacheOverride, disabled: !opcacheConfirmed || opcacheApplying || !data.phpIniPath },
                            el(Ico, { name: 'warn', size: 15 }),
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

    var SCROLL_COLORS = ['#4f46e5', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444', '#111827'];

    function SvgSupportCard(props) {
        var extras = props.extras;
        var on = !!extras.svg_support.enabled;

        function toggle() {
            var next = !on;
            props.update({ svg_support: Object.assign({}, extras.svg_support, { enabled: next }) });
            props.onToast(next ? 'success' : 'info', next ? __('SVG Upload enabled', 'reloadify-frontend-sync') : __('SVG Upload disabled', 'reloadify-frontend-sync'));
        }

        var facts = [
            { icon: 'upload', title: __('Upload .svg like any image', 'reloadify-frontend-sync'), text: __('SVG files are accepted by the Media Library and show proper thumbnails.', 'reloadify-frontend-sync') },
            { icon: 'shield', title: __('Scanned before it is accepted', 'reloadify-frontend-sync'), text: __('Files with script tags, event handlers, external entities or embedded HTML are blocked.', 'reloadify-frontend-sync') },
            { icon: 'image', title: __('Sharp at every size', 'reloadify-frontend-sync'), text: __('Vector logos and icons stay crisp and are usually far smaller than PNG.', 'reloadify-frontend-sync') }
        ];

        return el(
            'div',
            { className: 'reloadify-section reloadify-card' },
            el(CardHead, {
                title: __('SVG Upload Support', 'reloadify-frontend-sync'),
                subtitle: __('Allow vector images in the Media Library', 'reloadify-frontend-sync'),
                checked: on,
                onChange: toggle
            }),
            el(
                'div',
                { className: 'reloadify-fact-list' + (on ? '' : ' is-inactive') },
                facts.map(function (fact) {
                    return el(
                        'div',
                        { key: fact.icon, className: 'reloadify-fact' },
                        el(IconChip, { name: fact.icon, tone: on ? 'green' : 'gray' }),
                        el('div', { className: 'reloadify-pass-text' }, el('strong', { className: 'reloadify-pass-title' }, fact.title), el('span', { className: 'reloadify-pass-desc' }, fact.text))
                    );
                })
            ),
            el('p', { className: 'reloadify-tip' }, __('Tip: only enable this if the people who can upload files are people you trust.', 'reloadify-frontend-sync'))
        );
    }

    var ANIMATION_CHOICES = ['fade', 'slide', 'zoom', 'bounce'];
    var HOVER_CHOICES = ['none', 'lift', 'pulse', 'wiggle'];

    function animationLabel(key) {
        return {
            fade: __('Fade', 'reloadify-frontend-sync'),
            slide: __('Slide up', 'reloadify-frontend-sync'),
            zoom: __('Zoom', 'reloadify-frontend-sync'),
            bounce: __('Bounce', 'reloadify-frontend-sync'),
            none: __('None', 'reloadify-frontend-sync'),
            lift: __('Lift', 'reloadify-frontend-sync'),
            pulse: __('Pulse', 'reloadify-frontend-sync'),
            wiggle: __('Nudge', 'reloadify-frontend-sync')
        }[key] || key;
    }

    function iconTitle(name) {
        return String(name).replace(/^dashicons-/, '').replace(/-/g, ' ');
    }

    function ScrollTopGlyph(props) {
        if (props.icon && props.icon !== 'default') {
            return el('span', { className: 'dashicons ' + props.icon, 'aria-hidden': 'true' });
        }
        return el(
            'svg',
            { viewBox: '0 0 24 24', width: props.size || 20, height: props.size || 20, fill: 'none', stroke: 'currentColor', strokeWidth: 2.6, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' },
            el('polyline', { points: '18 15 12 9 6 15' })
        );
    }

    function ScrollTopPreview(props) {
        var left = props.position === 'left';
        var c = 2 * Math.PI * 21;
        return el(
            'div',
            { className: 'reloadify-st-preview' + (props.on ? '' : ' is-off'), 'aria-hidden': 'true' },
            el('span', { className: 'reloadify-st-line reloadify-st-line--1' }),
            el('span', { className: 'reloadify-st-line reloadify-st-line--2' }),
            el('span', { className: 'reloadify-st-line reloadify-st-line--3' }),
            el(
                'span',
                {
                    key: props.replayKey,
                    className: 'reloadify-st-btn reloadify-st-anim-' + props.animation + ' reloadify-st-hover-' + props.hover + (left ? ' is-left' : ''),
                    style: { background: props.color }
                },
                props.ring && el(
                    'svg',
                    { className: 'reloadify-st-ring', viewBox: '0 0 48 48' },
                    el('circle', { className: 'reloadify-st-ring-track', cx: 24, cy: 24, r: 21 }),
                    el('circle', { className: 'reloadify-st-ring-bar', cx: 24, cy: 24, r: 21, style: { strokeDasharray: c, strokeDashoffset: c * 0.35 } })
                ),
                el('span', { className: 'reloadify-st-glyph' }, el(ScrollTopGlyph, { icon: props.icon }))
            ),
            el(
                'button',
                { type: 'button', className: 'reloadify-st-replay', onClick: props.onReplay },
                el(Ico, { name: 'play', size: 12 }),
                __('Replay animation', 'reloadify-frontend-sync')
            )
        );
    }

    function PickSegments(sprops) {
        return el(
            'div',
            { className: 'reloadify-segmented', role: 'radiogroup', 'aria-label': sprops.label },
            sprops.choices.map(function (key) {
                var active = sprops.value === key;
                return el('button', {
                    key: key, type: 'button', role: 'radio', 'aria-checked': active,
                    className: 'reloadify-segment' + (active ? ' is-active' : ''),
                    onClick: function () { sprops.onPick(key); }
                }, animationLabel(key));
            })
        );
    }

    function IconPicker(props) {
        var names = props.names || [];
        var stateQuery = useState('');
        var query = stateQuery[0], setQuery = stateQuery[1];
        var stateOpen = useState(false);
        var open = stateOpen[0], setOpen = stateOpen[1];
        var stateAll = useState(false);
        var showAll = stateAll[0], setShowAll = stateAll[1];

        var needle = query.trim().toLowerCase().replace(/\s+/g, '-');
        var matches = needle ? names.filter(function (n) { return n.indexOf(needle) !== -1; }) : names;
        var visible = (showAll || needle) ? matches : matches.slice(0, 96);

        return el(
            'div',
            { className: 'reloadify-icon-picker' },
            el(
                'div',
                { className: 'reloadify-icon-current' },
                el('span', { className: 'reloadify-icon-current-glyph', style: { background: props.color } }, el(ScrollTopGlyph, { icon: props.value, size: 22 })),
                el(
                    'div',
                    { className: 'reloadify-icon-current-text' },
                    el('strong', null, props.value === 'default' ? __('Built-in arrow', 'reloadify-frontend-sync') : iconTitle(props.value)),
                    el('span', null, props.value === 'default'
                        ? __('Lightweight \u2014 no icon font to download.', 'reloadify-frontend-sync')
                        : __('WordPress Dashicons \u2014 loaded only when a visitor scrolls.', 'reloadify-frontend-sync'))
                ),
                el('button', { type: 'button', className: 'reloadify-ghost-btn', onClick: function () { setOpen(!open); }, 'aria-expanded': open }, open ? __('Close', 'reloadify-frontend-sync') : __('Choose icon', 'reloadify-frontend-sync'))
            ),
            open && el(
                'div',
                { className: 'reloadify-icon-panel' },
                el(
                    'label',
                    { className: 'reloadify-icon-search' },
                    el(Ico, { name: 'search', size: 15 }),
                    el('input', {
                        type: 'search', value: query, placeholder: wp.i18n.sprintf(
                            /* translators: %d: number of icons available */
                            __('Search %d WordPress icons\u2026', 'reloadify-frontend-sync'), names.length),
                        'aria-label': __('Search icons', 'reloadify-frontend-sync'),
                        onChange: function (e) { setQuery(e.target.value); }
                    })
                ),
                el(
                    'div',
                    { className: 'reloadify-icon-grid', role: 'listbox', 'aria-label': __('Icons', 'reloadify-frontend-sync') },
                    (!needle || 'default'.indexOf(needle) !== -1) && el('button', {
                        key: 'default', type: 'button', role: 'option', 'aria-selected': props.value === 'default',
                        className: 'reloadify-icon-tile reloadify-icon-tile--default' + (props.value === 'default' ? ' is-selected' : ''),
                        title: __('Built-in arrow (default)', 'reloadify-frontend-sync'),
                        onClick: function () { props.onSelect('default'); }
                    }, el(ScrollTopGlyph, { icon: 'default', size: 20 })),
                    visible.map(function (name) {
                        return el('button', {
                            key: name, type: 'button', role: 'option', 'aria-selected': props.value === name,
                            className: 'reloadify-icon-tile' + (props.value === name ? ' is-selected' : ''),
                            title: iconTitle(name),
                            onClick: function () { props.onSelect(name); }
                        }, el('span', { className: 'dashicons ' + name, 'aria-hidden': 'true' }));
                    })
                ),
                !visible.length && el('p', { className: 'reloadify-media-format-note' }, __('No icon matches that search.', 'reloadify-frontend-sync')),
                (!showAll && !needle && matches.length > visible.length) && el(
                    'button',
                    { type: 'button', className: 'reloadify-ghost-btn reloadify-icon-more', onClick: function () { setShowAll(true); } },
                    wp.i18n.sprintf(
                        /* translators: %d: number of icons */
                        __('Show all %d icons', 'reloadify-frontend-sync'), matches.length)
                )
            )
        );
    }

    function ScrollTopCard(props) {
        var extras = props.extras;
        var cfg = extras.scroll_top;
        var on = !!cfg.enabled;
        var iconNames = (window.ReloadifyAdmin && window.ReloadifyAdmin.initial && window.ReloadifyAdmin.initial.dashicons) || [];

        var stateColor = useState(cfg.bg_color);
        var colorDraft = stateColor[0], setColorDraft = stateColor[1];

        var stateReplay = useState(0);
        var replayKey = stateReplay[0], setReplayKey = stateReplay[1];

        function patch(change) {
            props.update({ scroll_top: Object.assign({}, cfg, change) });
        }

        var toastColor = useSettled(function (value) {
            props.onToast('success', __('Button colour set to', 'reloadify-frontend-sync') + ' ' + value.toUpperCase());
        }, 800);

        var toastDistance = useSettled(function (value) {
            props.onToast('success', value === 0
                ? __('Button is always visible', 'reloadify-frontend-sync')
                : wp.i18n.sprintf(
                    /* translators: %d: pixels scrolled before the button appears */
                    __('Button appears after %d px of scrolling', 'reloadify-frontend-sync'), value));
        }, 800);

        function toggle() {
            var next = !on;
            patch({ enabled: next });
            props.onToast(next ? 'success' : 'info', next ? __('Scroll To Top Button enabled', 'reloadify-frontend-sync') : __('Scroll To Top Button disabled', 'reloadify-frontend-sync'));
        }

        function setPosition(position) {
            if ((cfg.position === 'left' ? 'left' : 'right') === position) { return; }
            patch({ position: position });
            props.onToast('success', position === 'left' ? __('Button placed bottom left', 'reloadify-frontend-sync') : __('Button placed bottom right', 'reloadify-frontend-sync'));
        }

        function setColor(value) {
            setColorDraft(value);
            if (/^#[0-9a-fA-F]{6}$/.test(value)) {
                patch({ bg_color: value });
                toastColor(value);
            }
        }

        function setDistance(raw) {
            var value = Math.max(0, parseInt(raw, 10) || 0);
            patch({ show_after: value });
            toastDistance(value);
        }

        function setIcon(name) {
            patch({ icon: name });
            props.onToast('success', name === 'default'
                ? __('Icon set to the built-in arrow', 'reloadify-frontend-sync')
                : __('Icon set to', 'reloadify-frontend-sync') + ' ' + iconTitle(name));
        }

        function setAnimation(name) {
            patch({ animation: name });
            setReplayKey(replayKey + 1);
            props.onToast('success', __('Entrance animation:', 'reloadify-frontend-sync') + ' ' + animationLabel(name));
        }

        function setHover(name) {
            patch({ hover: name });
            props.onToast('success', __('Hover effect:', 'reloadify-frontend-sync') + ' ' + animationLabel(name));
        }

        function toggleRing() {
            var next = !ringOn;
            patch({ progress_ring: next });
            props.onToast(next ? 'success' : 'info', next ? __('Scroll progress ring enabled', 'reloadify-frontend-sync') : __('Scroll progress ring disabled', 'reloadify-frontend-sync'));
        }

        var position = cfg.position === 'left' ? 'left' : 'right';
        var distance = Math.max(0, parseInt(cfg.show_after, 10) || 0);
        var animation = ANIMATION_CHOICES.indexOf(cfg.animation) !== -1 ? cfg.animation : 'slide';
        var hover = HOVER_CHOICES.indexOf(cfg.hover) !== -1 ? cfg.hover : 'lift';
        var ringOn = cfg.progress_ring !== false;
        var icon = cfg.icon || 'default';
        var color = /^#[0-9a-fA-F]{6}$/.test(colorDraft) ? colorDraft : cfg.bg_color;

        return el(
            'div',
            { className: 'reloadify-section reloadify-card reloadify-card--wide' },
            el(CardHead, {
                title: __('Scroll To Top Button', 'reloadify-frontend-sync'),
                subtitle: __('A small floating button that returns visitors to the top', 'reloadify-frontend-sync'),
                checked: on,
                onChange: toggle
            }),
            el(
                'div',
                { className: 'reloadify-st-layout' + (on ? '' : ' is-inactive') },
                el(
                    'div',
                    { className: 'reloadify-st-col' },
                    el(ScrollTopPreview, { color: color, position: position, on: on, icon: icon, animation: animation, hover: hover, ring: ringOn, replayKey: replayKey, onReplay: function () { setReplayKey(replayKey + 1); } }),
                    el('div', { className: 'reloadify-card-eyebrow reloadify-eyebrow-gap' }, el('span', null, __('Position', 'reloadify-frontend-sync'))),
                    el(
                        'div',
                        { className: 'reloadify-choice-row reloadify-choice-row--compact', role: 'radiogroup', 'aria-label': __('Position', 'reloadify-frontend-sync') },
                        el(ChoiceCard, { selected: position === 'right', onSelect: function () { setPosition('right'); }, icon: 'arrowUp', title: __('Bottom right', 'reloadify-frontend-sync'), text: __('Default, out of the way of most site chrome.', 'reloadify-frontend-sync') }),
                        el(ChoiceCard, { selected: position === 'left', onSelect: function () { setPosition('left'); }, icon: 'arrowUp', title: __('Bottom left', 'reloadify-frontend-sync'), text: __('Use this if a chat widget already sits bottom right.', 'reloadify-frontend-sync') })
                    ),
                    el('div', { className: 'reloadify-card-eyebrow reloadify-eyebrow-gap' }, el('span', null, __('Button colour', 'reloadify-frontend-sync'))),
                    el(
                        'div',
                        { className: 'reloadify-color-row' },
                        el(
                            'div',
                            { className: 'reloadify-swatches', role: 'group', 'aria-label': __('Colour presets', 'reloadify-frontend-sync') },
                            SCROLL_COLORS.map(function (swatch) {
                                var active = swatch.toLowerCase() === String(cfg.bg_color).toLowerCase();
                                return el('button', {
                                    key: swatch, type: 'button', className: 'reloadify-swatch' + (active ? ' is-active' : ''),
                                    style: { background: swatch }, title: swatch.toUpperCase(), 'aria-label': swatch.toUpperCase(), 'aria-pressed': active,
                                    onClick: function () { setColor(swatch); }
                                }, active && el(Ico, { name: 'check', size: 13 }));
                            })
                        ),
                        el('input', {
                            type: 'color', className: 'reloadify-color-input', value: color,
                            'aria-label': __('Pick a custom colour', 'reloadify-frontend-sync'),
                            onChange: function (e) { setColor(e.target.value); }
                        }),
                        el('input', {
                            type: 'text', className: 'reloadify-hex-input', value: colorDraft, maxLength: 7, spellCheck: false,
                            'aria-label': __('Hex colour', 'reloadify-frontend-sync'),
                            onChange: function (e) { setColor(e.target.value); },
                            onBlur: function () { if (!/^#[0-9a-fA-F]{6}$/.test(colorDraft)) { setColorDraft(cfg.bg_color); } }
                        })
                    ),
                    el(
                        'div',
                        { className: 'reloadify-quality reloadify-st-distance' },
                        el(
                            'div',
                            { className: 'reloadify-quality-head' },
                            el('label', { htmlFor: 'reloadify-scroll-after' }, __('Show after scrolling', 'reloadify-frontend-sync')),
                            el('span', { className: 'reloadify-quality-pill' }, distance + ' px')
                        ),
                        el('input', {
                            id: 'reloadify-scroll-after', className: 'reloadify-range', type: 'range', min: 0, max: 1500, step: 50, value: Math.min(distance, 1500),
                            style: { '--reloadify-fill': ((Math.min(distance, 1500) / 1500) * 100) + '%' },
                            onChange: function (e) { setDistance(e.target.value); }
                        }),
                        el('div', { className: 'reloadify-quality-scale' }, el('span', null, __('Always visible', 'reloadify-frontend-sync')), el('span', null, '1500 px'))
                    )
                ),
                el(
                    'div',
                    { className: 'reloadify-st-col' },
                    el('div', { className: 'reloadify-card-eyebrow' }, el('span', null, __('Icon', 'reloadify-frontend-sync')), el('span', { className: 'reloadify-pass-count' }, iconNames.length + ' ' + __('WordPress icons', 'reloadify-frontend-sync'))),
                    el(IconPicker, { names: iconNames, value: icon, color: color, onSelect: setIcon }),
                    el('div', { className: 'reloadify-card-eyebrow reloadify-eyebrow-gap' }, el('span', null, __('Entrance animation', 'reloadify-frontend-sync'))),
                    el(PickSegments, { label: __('Entrance animation', 'reloadify-frontend-sync'), choices: ANIMATION_CHOICES, value: animation, onPick: setAnimation }),
                    el('div', { className: 'reloadify-card-eyebrow reloadify-eyebrow-gap' }, el('span', null, __('Hover effect', 'reloadify-frontend-sync'))),
                    el(PickSegments, { label: __('Hover effect', 'reloadify-frontend-sync'), choices: HOVER_CHOICES, value: hover, onPick: setHover }),
                    el(
                        'div',
                        { className: 'reloadify-pass reloadify-pass--wide' + (ringOn ? ' is-on' : ' is-off') },
                        el(IconChip, { name: 'sparkle', tone: ringOn ? 'green' : 'gray' }),
                        el(
                            'div',
                            { className: 'reloadify-pass-text' },
                            el('strong', { className: 'reloadify-pass-title' }, __('Scroll progress ring', 'reloadify-frontend-sync')),
                            el('span', { className: 'reloadify-pass-desc' }, __('A ring around the button fills as the visitor scrolls down the page.', 'reloadify-frontend-sync'))
                        ),
                        el(Switch, { checked: ringOn, onChange: toggleRing })
                    ),
                    el('p', { className: 'reloadify-tip' }, __('Animations respect the visitor\u2019s \u201creduce motion\u201d setting automatically.', 'reloadify-frontend-sync'))
                )
            )
        );
    }

    function ExtrasTab(props) {
        var extras = props.extras;

        function update(change) {
            props.onChange(Object.assign({}, extras, change));
        }

        return el(
            'div',
            { className: 'reloadify-tab-body reloadify-card-grid' },
            el(SvgSupportCard, { extras: extras, update: update, onToast: props.onToast }),
            el(ScrollTopCard, { extras: extras, update: update, onToast: props.onToast })
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

        var speedState = useState(initial.speed || { enabled: false, items: [], options: {}, optionLabels: {} });
        var speed = speedState[0], setSpeed = speedState[1];

        var mediaState = useState(initial.media || { enabled: false, items: [], format_preference: 'auto', format_capabilities: { webp: false, avif: false } });
        var media = mediaState[0], setMedia = mediaState[1];

        var cleanupState = useState(initial.cleanup || { enabled: false });
        var cleanup = cleanupState[0], setCleanup = cleanupState[1];

        var extrasState = useState(initial.extras || {
            svg_support: { enabled: true },
            scroll_top: { enabled: true, position: 'right', bg_color: '#4f46e5', show_after: 300 }
        });
        var extras = extrasState[0], setExtras = extrasState[1];

        var syncingState = useState(false);
        var syncing = syncingState[0], setSyncing = syncingState[1];

        var toastState = useState(null);
        var toast = toastState[0], setToast = toastState[1];

        var autosaveState = useState('idle'); // 'idle' | 'saving' | 'saved' | 'error'
        var autosave = autosaveState[0], setAutosave = autosaveState[1];

        var toastTimer = useRef(null);

        // Existing-media optimization lives here, not inside the Media card, so it
        // keeps running -- and keeps reporting progress -- while the person moves
        // between tabs.
        var backfillState = useState({ status: 'idle', processed: 0, remaining: 0, total: 0, videos: 0 });
        var backfill = backfillState[0], setBackfill = backfillState[1];
        var backfillRun = useRef({ running: false, cancel: false, processed: 0, total: 0, lastVideos: -1, stalls: 0 });
        var backfillHide = useRef(null);

        function endBackfill(status, extra) {
            var run = backfillRun.current;
            run.running = false;
            clearTimeout(backfillHide.current);

            wp.apiFetch({ path: '/reloadify/v1/media' }).then(function (fresh) { setMedia(fresh); }).catch(function () {});

            setBackfill(Object.assign({ status: status, processed: run.processed, remaining: 0, total: run.total, videos: 0 }, extra || {}));

            var wait = status === 'error' ? 12000 : (status === 'stopped' ? 4500 : 5000);
            backfillHide.current = setTimeout(function () {
                setBackfill({ status: 'idle', processed: 0, remaining: 0, total: 0, videos: 0 });
            }, wait);
        }

        function backfillStep() {
            var run = backfillRun.current;

            if (run.cancel) {
                endBackfill('stopped', { remaining: run.lastRemaining || 0 });
                return;
            }

            wp.apiFetch({ path: '/reloadify/v1/media/backfill-now', method: 'POST' })
                .then(function (r) {
                    var done = r.images_processed || 0;
                    var remaining = r.images_remaining || 0;
                    var videos = r.videos_remaining || 0;

                    run.processed += done;
                    run.total = Math.max(run.total, run.processed + remaining);
                    run.lastRemaining = remaining;

                    var videoLeft = videos > 0 && r.ffmpeg_available;
                    var progressed = done > 0 || (run.lastVideos >= 0 && videos < run.lastVideos);
                    run.lastVideos = videos;

                    if (remaining === 0 && !videoLeft) {
                        endBackfill('done', { unavailable: r.videos_unavailable || 0 });
                        return;
                    }

                    run.stalls = progressed ? 0 : run.stalls + 1;
                    if (run.stalls >= 2) {
                        endBackfill('error', { remaining: remaining, videos: videos });
                        return;
                    }

                    setBackfill({ status: 'running', processed: run.processed, remaining: remaining, total: run.total, videos: videoLeft ? videos : 0 });
                    backfillStep();
                })
                .catch(function () {
                    endBackfill('error', { remaining: run.lastRemaining || 0 });
                });
        }

        function startBackfill() {
            if (backfillRun.current.running) { return; }
            clearTimeout(backfillHide.current);

            var pending = (media.pending && media.pending.images) || 0;
            var pendingVideos = (media.pending && media.pending.videos) || 0;

            backfillRun.current = { running: true, cancel: false, processed: 0, total: pending, lastVideos: -1, stalls: 0, lastRemaining: pending };
            setBackfill({ status: 'running', processed: 0, remaining: pending, total: pending, videos: pendingVideos });
            backfillStep();
        }

        function stopBackfill() {
            backfillRun.current.cancel = true;
        }

        function showToast(type, message, title) {
            if (toastTimer.current) { clearTimeout(toastTimer.current); }
            setToast({ type: type, message: message, title: title || null });
            // Titled toasts carry more to read, so they linger a little longer.
            toastTimer.current = setTimeout(function () { setToast(null); }, title ? 6000 : 3500);
        }

        function dismissToast() {
            if (toastTimer.current) { clearTimeout(toastTimer.current); }
            setToast(null);
        }

        function refreshSettings() {
            settingsSkipNext.current = true;
            wp.apiFetch({ path: '/reloadify/v1/settings', method: 'GET' })
                .then(function (data) { setSettings(data); })
                .catch(function () { settingsSkipNext.current = false; });
        }

        function syncPerformance() {
            setSyncing(true);
            perfSkipNext.current = true;
            wp.apiFetch({ path: '/reloadify/v1/performance/sync', method: 'POST' })
                .then(function (data) {
                    setPerf(data);
                    setSyncing(false);
                    showToast('success', __('Synced with the server\u2019s current values.', 'reloadify-frontend-sync'));
                })
                .catch(function () {
                    perfSkipNext.current = false;
                    setSyncing(false);
                    showToast('error', __('Could not sync from the server.', 'reloadify-frontend-sync'));
                });
        }

        /* ---------------- Autosave: Reload tab (settings) ---------------- */
        var settingsSkipNext = useRef(true); // true on mount and right after a programmatic (non-user) update

        useEffect(function () {
            if (settingsSkipNext.current) { settingsSkipNext.current = false; return; }

            setAutosave('saving');
            var handle = setTimeout(function () {
                wp.apiFetch({ path: '/reloadify/v1/settings', method: 'POST', data: settings })
                    .then(function (data) {
                        settingsSkipNext.current = true;
                        setSettings(data);
                        setAutosave('saved');
                    })
                    .catch(function () {
                        setAutosave('error');
                    });
            }, AUTOSAVE_DEBOUNCE_MS);

            return function () { clearTimeout(handle); };
        }, [settings]);

        /* ---------------- Autosave: Extensions tab ---------------- */
        var extrasSkipNext = useRef(true);

        useEffect(function () {
            if (extrasSkipNext.current) { extrasSkipNext.current = false; return; }

            setAutosave('saving');
            var handle = setTimeout(function () {
                wp.apiFetch({ path: '/reloadify/v1/extras', method: 'POST', data: extras })
                    .then(function (data) {
                        extrasSkipNext.current = true;
                        setExtras(data);
                        setAutosave('saved');
                    })
                    .catch(function () {
                        setAutosave('error');
                    });
            }, AUTOSAVE_DEBOUNCE_MS);

            return function () { clearTimeout(handle); };
        }, [extras]);

        /* ---------------- Autosave: Server Performance tab (editable directives) ---------------- */
        var perfSkipNext = useRef(true);

        useEffect(function () {
            if (perfSkipNext.current) { perfSkipNext.current = false; return; }
            if (!perf || !perf.settings) { return; }

            setAutosave('saving');
            var handle = setTimeout(function () {
                wp.apiFetch({ path: '/reloadify/v1/performance', method: 'POST', data: perf.settings })
                    .then(function (data) {
                        perfSkipNext.current = true;
                        setPerf(data);
                        setAutosave('saved');
                    })
                    .catch(function () {
                        setAutosave('error');
                    });
            }, AUTOSAVE_DEBOUNCE_MS);

            return function () { clearTimeout(handle); };
        }, [perf && perf.settings]);

        if (!settings || !perf) {
            return el('div', { className: 'reloadify-loading' }, __('Loading\u2026', 'reloadify-frontend-sync'));
        }

        return el(
            'div',
            { className: 'reloadify-app' },
            el(
                'div',
                { className: 'reloadify-toast-stack' },
                el(ProgressToast, { state: backfill, onStop: stopBackfill, onDismiss: function () { clearTimeout(backfillHide.current); setBackfill({ status: 'idle', processed: 0, remaining: 0, total: 0, videos: 0 }); } }),
                el(Toast, { toast: toast, onDismiss: dismissToast })
            ),
            el(
                'div',
                { className: 'reloadify-hero' },
                el(
                    'div',
                    null,
                    el('h1', null, __('Reloadify Frontend Sync', 'reloadify-frontend-sync')),
                    el('p', null, __('Cross-browser live reload for page-builder QA \u2014 and a clear picture of which server settings this plugin can and can\u2019t change for you.', 'reloadify-frontend-sync'))
                ),
                el(
                    'div',
                    { className: 'reloadify-hero-meta' },
                    el(AutoSaveStatus, { state: autosave }),
                    el('span', { className: 'reloadify-version-badge' }, 'v' + ReloadifyAdmin.version)
                )
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
                    ? el(ReloadTab, { settings: settings, onChange: setSettings, onExpire: refreshSettings, onToast: showToast })
                    : tab === 'extras'
                        ? el(ExtrasTab, { extras: extras, onChange: setExtras, onToast: showToast })
                        : el(PerformanceTab, { data: perf, onChange: setPerf, onToast: showToast, onSync: syncPerformance, syncing: syncing, speed: speed, onSpeedChange: setSpeed, media: media, onMediaChange: setMedia, cleanup: cleanup, onCleanupChange: setCleanup, backfill: backfill, onBackfillStart: startBackfill })
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
