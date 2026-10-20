## Changelog

### 1.3.0

*   **New (Speed Boost): Remove query strings.** `?ver=` is removed from local CSS/JS URLs for logged-out visitors. A file that changed in the last 7 days keeps its tag, logged-in editors always get the tagged URL, third-party URLs are untouched, and other query parameters are preserved.
*   **New (Speed Boost): Lazy-load images.** `loading="lazy"` and `decoding="async"` are added to images and iframes that lack them, on the finished page (so page-builder and theme markup is covered). The first two images stay eager; images that already declare `loading`/`fetchpriority`, lazy-loader markup, WooCommerce gallery images, tracking pixels and anything in `<script>`, `<style>`, `<noscript>`, `<template>`, `<textarea>` or `<svg>` are left alone.
*   **New (Speed Boost): Defer non-critical scripts.** `defer` is added to stand-alone head scripts that no other script depends on. jQuery, WordPress core, WooCommerce, page builders, sliders, galleries, payment/captcha scripts and footer scripts are never deferred (`reloadify_defer_js_skip_patterns` filter to extend).
*   **Changed (Speed Boost): compact list.** Tighter row spacing, one fixed toggle column so every switch aligns, and an "N enabled" counter.
*   **New (Media Optimization): format selector** — Auto, WebP, AVIF or Original (keep the uploaded format). Options the server cannot encode are disabled.
*   **New (Media Optimization): compression-quality slider (40–95%)**, applied as a ceiling that only tightens: an encoder whose own default is lower is kept.
*   **New (Media Optimization): savings tiles** — average size saved and estimated total saved across optimized images.
*   **New (Media Optimization): real before/after preview.** The newest JPEG/PNG in the library is scaled to preview size and encoded the way WordPress would without the plugin and with the chosen format and quality; both sizes are measured and both images are shown. It updates as the slider moves (debounced) and shows "% larger" honestly when a setting would not help.
*   **New (Delete Data on Uninstall): choose what is removed** — plugin settings, cached files, local storage (per-image/per-video optimization records) and logs & diagnostics — with a privacy callout and a warning. Defaults: settings and cached files on, the other two off. Media Library files are never deleted. Sites that already had the option on keep the old behaviour for settings and cache.
*   **New: confirmation toasts** for every switch in the three panels.
*   **Changed: Cross-Browser Reload tab redesigned** in the same card language. Developer Mode and Reload all tabs are control cards with an icon and an On/Off pill; browsers enabled (with a progress meter), reload mode and last change are status tiles; Reload behavior is a pair of icon choice cards; the browser grid shows a status dot per browser, icons for Normal / Incognito, an "N enabled" counter and **Enable all / Disable all** shortcuts. Every change shows a toast (the reload-mode choice had none before).
*   **Changed: Extensions tab redesigned.** SVG Upload Support and Scroll To Top Button are cards with a header switch. The scroll button gets a live preview, position choice cards, six colour presets plus a custom picker and hex field, and a "show after scrolling" slider (0–1500 px) — each with a confirmation toast.
*   **Fixed: the Server Performance header** could pick up the privacy-callout styling because both used the same CSS class.
*   **Changed: Server Performance tab redesigned.** A summary header (live / host / startup-only counts, Sync from server with a spinner); each section is a card with a subtitle and counter; every PHP setting is a tile with a one-line explanation, its current value, a live match/mismatch note, an input and an Apply switch (now with a toast); the host section has clear primary and secondary actions; and the startup-only "danger zone" is a red panel with three short warnings, the php.ini path, the full explanation tucked into a "Read the full explanation" disclosure, a consent card and a destructive button that stays locked until consent is given.
*   **New (Scroll To Top): choose any WordPress icon.** The icon picker lists **every Dashicon in the installed WordPress version** (read from core's own stylesheet, so it is always complete — 300+ icons), with search and a built-in default arrow. The Dashicons font is only downloaded for visitors who actually start scrolling, so the default costs nothing and an icon costs nothing for visitors who never scroll; if the font can't load, the built-in arrow takes over.
*   **New (Scroll To Top): modern animations.** Entrance animation (Fade, Slide up, Zoom, Bounce), hover effect (None, Lift, Pulse, Nudge) and an optional scroll-progress ring that fills as the visitor scrolls down. The admin preview shows all of it live, with a Replay animation button. Everything respects the visitor's "reduce motion" setting, and the button is keyboard-focusable with a visible focus ring and a translatable label.
*   **Changed (Scroll To Top): the card is now two columns** (preview, position, colour, distance | icon, animation, hover, ring). Settings saved before this release gain the new defaults automatically (built-in arrow, slide-up, lift, progress ring on).
*   **Changed: version 1.3.0** (new features, backwards compatible).

### 1.2.2

*   **Fixed (Speed Boost): the WooCommerce product gallery was shown with a wrong height.** Delay JavaScript parked *every* script until the first interaction, so FlexSlider and the product-gallery code measured the layout at the wrong moment. Delay JavaScript now only holds back third-party tracking, advertising and chat scripts (Google Analytics / Tag Manager, Meta Pixel, Hotjar, Intercom, Tawk.to and similar). Theme, WooCommerce and page-builder scripts load normally. Add your own patterns with the `reloadify_delay_js_patterns` filter.
*   **Fixed (Speed Boost): `window.et_pb_init_nav_menu is not a function`.** A `DOMContentLoaded` listener added by one delayed script was replayed immediately, before a later script had defined the function it calls. Replayed listeners now run in order, after the last delayed script.
*   **Fixed (Speed Boost): removing `?ver=` from CSS/JS could leave visitors on stale files after an update.** The pass was removed; WordPress's own versioning is left intact.
*   **Fixed (Speed Boost): the activator script is printed only when a script was actually delayed on that page.**
*   **New (Speed Boost): browser caching & gzip (Apache).** Writes a guarded block to `.htaccess` (every directive wrapped in `<IfModule>`): compression for text, and long-lived caching for images, fonts and video. CSS and JS are deliberately not given long expiry. Skipped on Nginx, multisite, or when `.htaccess` is not writable; removed when the option is switched off, on deactivation and on uninstall.
*   **New (Speed Boost): skip the Dashicons font for logged-out visitors** unless a stylesheet on the page depends on it.
*   **New (Speed Boost): weekly cleanup of expired transients** from the options table (skipped when a persistent object cache is in use).
*   **New (Speed Boost): REST API and oEmbed discovery links are dropped from `<head>`** together with the other unused links.
*   **Changed: scheduled events** (Media Optimization, transient cleanup) are now always removed on uninstall, not only when "Delete Data on Uninstall" is on.
*   **Fixed: auto-reload stopped working after a while.** Five separate causes:
    *   A change that arrived while the tab was not the active one was marked as seen and dropped. It is now remembered and applied the moment the tab becomes active.
    *   With no active claim, a visible tab still needed keyboard focus to reload, so a preview window sitting beside the editor never did. A visible tab now takes the claim.
    *   A claim left behind by a tab that was closed, crashed or discarded blocked every other tab indefinitely. Claims nobody has refreshed for 150 seconds are treated as gone, and a tab being frozen hands its claim back.
    *   One failed read of the timestamp file (for instance caught mid-write) switched polling to admin-ajax permanently, where an expired nonce then ended it silently. It now takes three failures, retries the static file regularly, ignores empty or garbled responses, and a hung request can no longer stall the loop.
    *   A background tab's timers are throttled to once a minute. The polling beat now comes from a Web Worker (with a plain interval as fallback), so a preview tab behind the editor keeps up.
*   **Fixed: polling never started** if the private-window detection hung; both probes now time out after 2.5 seconds.
*   **Changed (Speed Boost): the optimization passes are now a modern list** — each pass is a card with an icon, a title, a description and a switch, all enabled by default, with a toast ("Minify CSS enabled" / "disabled") and an active counter.
*   **Fixed: reload fired before a save had finished.** The timestamp is now written at the end of the request, so page builders and WooCommerce have finished writing meta. Timestamps are strictly increasing, so two saves in the same second both reload.
*   **Fixed: `wpml-config.xml` described a different plugin.** It now matches this plugin (Media Optimization attachment meta only; no translatable options or front-end text). The `.pot` file was regenerated (218 strings).
*   **Removed: all console output and debug logging** (`console.error` in the delayed-script replay, the `error_log()` call after a failed `.htaccess` write) and the discouraged `load_plugin_textdomain()` call — WordPress.org loads translations for the plugin slug automatically.
*   **Fixed: PHPCS** `WordPress.WP.AlternativeFunctions.rename_rename` (documented exception: `rename()` is the only atomic replace) and `rand_mt_rand` (`random_int()`).
*   **Fixed: activating through WP-CLI printed a stack trace** from the post-activation redirect.
*   **Faster (Speed Boost): fewer disk operations per page.** No `realpath()` per stylesheet/script (expensive on Windows), one `stat()` per asset, and the cache folder is listed once per request instead of two `file_exists()` calls per asset. Measured on a WooCommerce page: about 7 ms for all of Speed Boost, 1.2 ms of it for assets.
*   **Faster: the timestamp file is written atomically** (temp file + rename), so a poll can never read it half-written.
*   **Faster: the timestamp and admin-ajax URLs are origin-relative**, so opening the site through another host name or scheme than the one stored in WordPress no longer blocks the request and forces the heavy admin-ajax path.
*   **Faster: hidden tabs that are not the active one stop polling** (they catch up when brought forward), hidden tabs in "all tabs" mode poll at a quarter of the rate, and the admin-ajax cross-check runs every ~2 minutes instead of every ~20 seconds.
*   **Faster (Speed Boost): large inline `<style>` / `<script>` blocks are minified once and cached by content hash**, instead of being run through the minifier on every page view.
*   **Faster (Speed Boost): minification has a one-second budget per request**, so the first view after a change can no longer take many seconds; the remaining files are built on the following views.
*   **Faster (Speed Boost): the cache folder check runs once per request** instead of once per stylesheet and script (on Windows it creates and deletes a temporary file each time).
*   **Faster: repeated saves within one second** (WooCommerce and page builders update dozens of options at once) rewrite the timestamp file once instead of once per option.

### 1.2.1

*   **Fixed: "Active tab only" turned into "all tabs" after a while.** A tab that had been backgrounded, frozen or throttled by the browser could miss the message telling it to give up the active-tab slot, wake up still believing it was active, and reload together with the real active tab. The shared active-tab claim is now the single source of truth: whenever a claim exists, only its owner reloads, and a stale tab corrects itself instead of reloading.
*   **Fixed: Duplicated tabs shared one tab ID.** The ID was stored in sessionStorage, which "Duplicate tab" and window.open copy, so two tabs could both look like the claim holder. Every page load now gets its own ID.
*   **Fixed: Tabs fighting over the active slot.** The heartbeat no longer overwrites a newer claim written by another tab; it lets go instead.
*   **Fixed: Private windows without storage reloaded every touched tab.** The fallback now reloads only the visible tab.
*   **Fixed: A freshly opened, focused tab now claims the active slot immediately** instead of waiting for the first click.
*   **Fixed: Background admin-ajax requests (Heartbeat, post-lock refresh) could count as a content change** and trigger a reload.
*   **Fixed: Duplicate `added_option` hook registration** removed.
*   **Fixed (Speed Boost): minified CSS lost its images and fonts.** The cached copy is served from the uploads folder, so every relative `url()` and `@import` in it pointed at the wrong place. They are now rewritten to absolute URLs.
*   **Fixed (Speed Boost): CSS minifier broke `calc(a + b)`** and rewrote text inside strings and `url()` values. Strings and `url()` are now left untouched, `+` is never tightened, and `a :hover` keeps its meaning.
*   **Fixed (Speed Boost): JS minifier** could glue `a + +b` into `a++b`, join words across a comment, and mis-read a regex after `return`/`typeof` or nested template literals.
*   **Fixed (Speed Boost): delayed JavaScript ran out of order.** Scripts are now started one after another, so an inline block never runs before its external file has loaded, and `DOMContentLoaded` / `load` listeners added by delayed scripts are replayed instead of never firing.
*   **Fixed (Speed Boost): delayed JavaScript missed inline blocks** when a script tag mixed attribute styles; every `<script>` in the tag is now parked reliably, while JSON-LD and templates keep their type.
*   **Fixed (Speed Boost): scripts that locate themselves by their own URL** (webpack chunks, `document.currentScript`, workers, dynamic imports) are no longer relocated into the cache folder.
*   **Fixed (Speed Boost): stale cache after a fix.** Cached files now carry a minifier revision, and files that were skipped are remembered instead of being re-read on every request.
*   **Fixed (Speed Boost): fatal error on hosts without `GLOB_BRACE`** (Alpine/musl) when turning Speed Boost off.
*   **Fixed (Speed Boost): page-builder frames** (Divi `et_fb`, Elementor, Beaver, Bricks, etc.) and the Customizer are no longer rewritten.
*   **Fixed (Speed Boost): version query strings** are only removed for visitors, so editors and wp-admin always get fresh files after an update.
*   **Fixed (Speed Boost): the autosave interval never changed.** WordPress already defines the constant before admin_init, so the old `define()` did nothing; the block and classic editors are now adjusted directly.
*   **Fixed (Speed Boost): the revision cap could raise a stricter limit** (e.g. `WP_POST_REVISIONS = 3` became 5).
*   **Fixed (Speed Boost): the front-end Heartbeat was removed for logged-in users**, not just visitors; embeds/emoji CSS handling updated for WordPress 6.4+.
*   **Fixed (Media Optimization): the "about X% smaller" figure compared WebP with WebP** and showed ~0%; it now measures against the original format.
*   **Fixed (Media Optimization): disabling it left per-video cron events queued**, and an uploaded video that wasn't an MP4 could be overwritten with MP4 data under its old extension. Non-MP4 videos are now skipped, and unusable attachments no longer block the backfill queue.
*   **Fixed (Media Optimization): ffmpeg paths containing spaces** (common on Windows) failed to run.
*   **Fixed (Media Optimization): the backfill regenerated every thumbnail** even when the server cannot produce WebP/AVIF.
*   **Fixed: changing only the image format via the REST API switched Media Optimization off.** The on/off switch is only touched when the request includes it.
*   **Fixed (Delete Data on Uninstall): on multisite the main site's choice decided for every site;** each site now uses its own setting. Per-video cron events and Media Optimization post meta are now removed too.

### 1.2.0

*   **Added: Reload all tabs.** A new toggle on the Cross-Browser Reload tab decides whether a change refreshes every open tab and window at once, or only the tab you're actually looking at. "Active tab only" is the default, so background tabs keep their scroll position and form state instead of resetting every time you save. Tabs coordinate through a shared active-tab claim plus BroadcastChannel, with a fallback for private windows where storage is blocked.
*   **Added: Live settings propagation.** Reload mode and the all-tabs flag now ride along on every poll response (both the static timestamp file and the admin-ajax check), so changing either takes effect on tabs that are already open instead of only after their next full page load.
*   **Added: CSS, JavaScript and HTML minification under Speed Boost.** Five new passes — Minify JavaScript, Minify CSS, Minify HTML output, Remove HTML comments, Remove unnecessary whitespace — each individually switchable. Minified CSS/JS are cached in `uploads/reloadify-minify/` and keyed by file size, modification time and plugin version; your original files are never modified, and anything already minified, remote, or over 1 MB is skipped. The JavaScript pass is deliberately conservative and returns the original file untouched whenever it isn't completely confident.
*   **Added: Delayed JavaScript, bundled into Speed Boost.** Non-essential scripts now wait for the first interaction (scroll, tap, key, mouse move) or 7 seconds, whichever comes first. There's no separate switch to find — enabling Speed Boost is all it takes. jQuery, this plugin's own scripts, module scripts, and anything served to a logged-in editor always load immediately, and `reloadify_delay_js_excluded_handles` lets you rescue any other handle.
*   **Changed: Speed Boost, Media Optimization and Delete Data on Uninstall now start switched off.** All three change real output or real files, so they're opt-in on new installs. Existing sites keep whatever you already had set.
*   **Changed: Deleting the plugin keeps your data unless you ask otherwise.** Delete Data on Uninstall defaults to off, and its confirmation now spells out exactly what would be removed and that deactivating alone changes nothing.
*   **Improved: "Optimize existing media now" is dramatically faster.** It used to convert three images per HTTP round trip, which on a real library meant hundreds of sequential requests. Each request now keeps converting for up to 15 seconds, raises the memory and time limits first, and leaves video compression until the images are finished so one slow video can't stall the run.
*   **Fixed: "Active tab only" stopped working after the first reload.** The tab released its active-tab claim on the way out of a reload, then came back with the user's focus still over in wp-admin, so it never re-claimed the slot — leaving that browser with no active tab and nothing to refresh on the next save. The claim now survives a self-initiated reload and is released only on a real close or navigation.
*   **Added: Confirmation toasts on every switch.** Developer Mode, the all-tabs / active-tab mode, each browser's Normal and Incognito window, SVG uploads and the Scroll To Top button all confirm what just changed.
*   **Changed: Shorter notifications.** Speed Boost, Media Optimization and Delete Data on Uninstall now confirm the change in a single line instead of a paragraph.
*   **Improved: Notifications.** Toasts now carry an icon, a dismiss button and a clean single-line layout.
*   **Fixed: Translation template.** JavaScript strings containing `\uXXXX` escapes were being written into the .pot with the escape intact, so those msgids could never match what `wp.i18n` looks up at runtime and translations for them silently did nothing. The .pot is regenerated for 1.2.0 with real characters (189 strings).

### 1.1.4

*   **Changed: Settings now save automatically.** Every toggle, radio, and field on the Cross-Browser Reload, Server Performance, and Extensions tabs saves itself the moment you change it — no more "Save Changes" button and no risk of losing changes by navigating away before clicking it.
*   **Changed: Default enabled browsers.** New installs now enable only Chrome, Edge, and Safari out of the box instead of all ten supported browsers. Enable any of the rest (Brave, Firefox, Opera, UC Browser, Vivaldi, Yandex Browser, Samsung Internet) from the Browsers & windows section whenever you need to test in them. Existing installs keep whatever browsers you already had enabled.
*   **Fixed: Broken dash characters in Media Optimization badges and the Heartbeat label.** Several UI strings used a `\u2013`-style escape inside single-quoted PHP strings, which PHP doesn't interpret — so the badges literally showed the text `\u2013` instead of an en dash. Replaced with real UTF-8 characters.
*   **Updated: Translation template (.pot) regenerated** — it was still tagged 1.1.1 and pointed at pre-reorg file paths (`includes/class-*.php` instead of `includes/extensions/` and `includes/admin/`), so translators using it would get stale source references and miss newer strings (e.g. the wp-embed toggle). Now in sync with the current source tree.

### 1.1.3 
*   **Fixed: Apache 500 Internal Server Error** when clicking "Attempt automatic server override" — the plugin was writing .htaccess files without validating server type, permissions, or write success, causing crashes on Apache. Now validates server type first (Apache with mod_php) and creates backups before modifications.
*   **Improved: .htaccess error handling** — permission checks now run before attempting writes; write failures are logged instead of silently crashing; file existence validated after write attempts.
*   **Added: Apache mod_php detection** — prevents attempting .htaccess modifications on Nginx or PHP-FPM setups; users get a clear error message instead of a 500 error.
*   **Added: Automatic .htaccess backups** — creates backup before modifying existing .htaccess files so users can recover if something goes wrong.
*   **Added fallback cache directives** — .htaccess now includes mod_expires as fallback for servers without mod_headers enabled.
*   **Confirmed compatible with WordPress 7.1** ("Tested up to: 7.1").
*   Enhanced Speed Boost with additional server-side optimizations for better performance.

### 1.1.2

* Added a `blueprint.json` so the "Live Preview" (WordPress Playground) button on this plugin's WordPress.org page works --- it was previously disabled with a "missing or invalid blueprint.json" notice.
* Added support for three more browsers in Cross-Browser Reload: Vivaldi, Yandex Browser, and Samsung Internet.
* Reviewed the request-path code that runs on every frontend visit for load concerns on busy servers; no changes were needed.

### 1.1.1

* Fixed a video compression bug on Windows servers: the background ffmpeg command included `nice`, a Unix-only process-priority tool with no Windows equivalent, so the entire command failed to run there and video was silently never compressed (the original file was never at risk --- this plugin only ever swaps a video if compression reports success --- but Windows/local-dev users got zero benefit from the feature). Also hardened the success check so an implausibly small/truncated encode is rejected instead of accepted.
* New: Media Optimization image format is now a real choice --- **Automatic** (default; picks the best your server supports), **WebP only**, or **AVIF only** --- instead of always being decided for you. Choosing AVIF on a server that can't produce it falls back to WebP automatically.
* Fixed: the help (i) icons were using the browser's native tooltip (the plain `title` attribute), which can't be styled or positioned and could render overlapping adjacent labels. Replaced with a proper tooltip component --- dark, rounded, positioned consistently below its icon.
* Renamed the **Extra Features** tab to **Extensions**.
* Fixed: **SVG Upload Support** defaulted to on, which didn't match this plugin's own documented intent or the Scroll To Top button's existing off-by-default behavior. Both now default to off.
* Updated the Bengali (bn_BD) translation to cover the new format-choice control and the Extensions tab name.

### 1.1.0

* New "Extra Features" tab: optional SVG upload support (on by default --- every upload is scanned for `<script>` tags, event-handler attributes, `javascript:` URIs, and embedded HTML before being accepted; blocked and rejected if any are found) and an optional Scroll To Top floating button (off by default; configurable position, color, and scroll-distance threshold).
* New "Media Optimization" (Server Performance tab, on by default): new image uploads automatically get WebP or AVIF versions generated alongside the original --- whichever your server actually supports --- without ever touching the original uploaded file. Existing media library images are optimized gradually in the background so a large library can't turn into one long blocking job. Video is compressed in the background only when the server genuinely has ffmpeg available; otherwise it's left untouched. Includes an "Optimize existing media now" button and a new "Optimization" column in the Media Library showing the real, measured before/after size for every file.
* Added forced lazy-loading for images and embedded video iframes (YouTube, Vimeo, etc.).
* Speed Boost: three more items --- throttles the Heartbeat API to once every 60 seconds in wp-admin and removes it from the frontend entirely, caps stored post revisions at 5 going forward, and stops WordPress from pinging itself when a post links to another post on the same site.
* New "Last change detected" readout with a Check now button on the Cross-Browser Reload tab.
* Fixed: an admin settings save could go undetected the very first time a given option row was written (e.g. a WooCommerce setting saved for the first time on a fresh install) --- WordPress fires `added_option` instead of `updated_option` for that first write, and only the latter was being listened for.
* Changed: Developer Mode is now a plain on/off toggle with no automatic timeout --- it previously auto-disabled itself after 12 hours, but now stays exactly as you set it until you switch it off yourself.
* Replaced permanent help paragraphs under section titles with a small (i) icon you hover for the same explanation on demand --- except the opcache "local dev only" danger warning, which stays as visible text since it's a safety warning.
* Speed Boost, Media Optimization, and Delete Data on Uninstall now sit side by side in one row instead of stacked full-width.
* Confirmed compatible with WordPress 7.0 ("Tested up to" header --- WordPress.org's validator only accepts major.minor here, not the full patch version).
* Updated the Bengali (bn_BD) translation to cover every new string introduced by Extra Features, Media Optimization, and the additional Speed Boost items.
* Fixed the same literal `\u2019`/`\u2014`/`\u201c`/`\u201d` escape-sequence bug from 1.0.1 recurring in the new Extra Features, Media Optimization, and Speed Boost messages.
* Code-quality fix: prefixed two global variables in `uninstall.php` flagged by the WordPress Coding Standards checker.

### 1.0.2

* Added a video walkthrough and a step-by-step "How It Works" section to the plugin description, for people evaluating the plugin before installing.

### 1.0.1

* New "Speed Boost" (on by default, one toggle to turn off): strips the emoji detection script/CSS WordPress prints on every page, trims a few unused `<head>` tags, turns PHP OPcache on if the host has it available but left it off, and --- scoped strictly to wp-admin/admin-ajax.php, never a frontend visitor's request --- raises `memory_limit`/`max_execution_time` headroom, only ever upward, never below whatever the host already allows. No fixed "% faster" claim is shown, since the real number depends on the site's theme, other plugins, and hosting.
* New "Delete Data on Uninstall" toggle (on by default): deleting the plugin from the Plugins screen also removes its settings and the `uploads/reloadify-reload` folder. Turn it off to keep settings around for a later reinstall.
* Developer Mode now shows a live countdown (hh:mm:ss) to when it will auto-disable itself, and the auto-off window is now 12 hours (was 6).
* Fixed: the frontend could keep auto-reloading in a loop with no real change if the page HTML was served from a cache (full-page cache plugin, host-level cache, or CDN) whose baked-in "last changed" value never caught up --- the reloader now establishes its baseline from a live check on load instead of trusting cached markup. Same fix also resolves a freshly opened tab sometimes missing the very next save until a manual refresh.
* Added a periodic admin-ajax.php cross-check alongside the lightweight static-file check, plus no-cache headers for the timestamp file, so a cached/stale timestamp can't silently block reloads.
* The local-development-only opcache override (Server Performance tab) now genuinely writes the 3 PHP-startup-locked directives to `php.ini`, with an automatic backup, once explicitly confirmed --- instead of only generating a copy-paste snippet.
* Added Bengali (bn_BD) translation for the entire settings dashboard and all admin-facing messages, bundled directly in this plugin's languages folder --- WordPress.org automatically loads it for Bengali-locale sites without any extra code needed, and it displays translated immediately rather than waiting on the community translation queue.
* Fixed two `\u2019`/`\u2014` escape sequences that were being printed literally instead of as an apostrophe/dash in a couple of Server Performance messages (PHP single-quoted strings don't interpret `\u` escapes).
* Code-quality fix: prefixed two global variables in `uninstall.php` flagged by the WordPress Coding Standards checker.

### 1.0.0

* Initial public release.
* Cross-browser, cross-window frontend reload --- works with Elementor, Divi, Bricks, Oxygen, Beaver Builder, and the classic WordPress editor, in Chrome, Brave, Edge, Firefox, Safari, Opera, and UC Browser (including incognito/private).
* Reload now works on the homepage, archives, and any frontend page --- not just the exact post being edited --- via a single site-wide "last changed" clock.
* Choice of soft reload or cache-busting hard reload.
* Frontend polling checks a small static JSON file served directly by the webserver instead of booting WordPress on every check, for minimal overhead.
* Developer Mode is off by default and auto-disables after 6 hours as a safety net, since it's the setting that adds ongoing load on a live site while active.
* A Server Performance panel that's upfront about what a plugin can and can't do: applies memory_limit, max_execution_time, and 3 of 6 opcache.\* directives live; generates ready-to-paste php.ini / .htaccess snippets and offers a best-effort .user.ini/.htaccess write for the settings that genuinely require a real server-side change (upload/post size limits, realpath cache, and the 3 memory-sizing opcache directives).
* A local-development-only, explicitly-confirmed option to write the 3 truly PHP-startup-locked opcache directives directly to php.ini, with automatic backup.
* Modern tabbed settings dashboard with per-browser cards, live status, and a "Sync from server" action.
* Intelligent exclusion: never triggers a reload loop inside a page builder's own editing canvas.
