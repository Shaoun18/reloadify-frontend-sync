## Changelog

### 1.2.0

* Faster frontend reloads: the default poll interval is now 500ms (from 2000ms), and the focus/visibility catch-up check is now 400ms (from 800ms).
* Faster "All tabs" handoff: active-tab heartbeat is now 2 seconds with a 6-second stale window (from 4 and 12 seconds).
* Older installations are migrated automatically: the faster poll interval is applied only when the previous value was never customized.
* Production CSS and JavaScript are now minified and loaded from `.min.css` / `.min.js` files. Readable source files are used automatically when `SCRIPT_DEBUG` is enabled.
* Active-tab detection now checks immediately when a tab regains focus or becomes visible.
* Fixed: "Active tab only" could incorrectly reload every tab because the JavaScript `"0"` value was treated as enabled.
* Fixed: open tabs could continue using old reload settings after those settings were changed in wp-admin. Tabs now refresh their settings during normal checks.
* Fixed: "Active tab only" could treat multiple browser windows as active at the same time. Actual browser focus is now used and coordinated across tabs.
* Fixed: plugin activation could trigger an unnecessary reload on already-open frontend tabs.
* Fixed: change detection could fail on some cached live sites when an outdated security token was present.
* Improved: removed unnecessary Speed Boost and Media Optimization detail lists from the settings cards.
* Improved: refreshed Developer Mode and Reload All Tabs tooltip wording and removed the unnecessary info icon from "Last change detected."
* Plugin Check cleanup completed for intentional page checks, runtime limit adjustments, and capped background Media Optimization queries. No behavior changes.
* Verified: REST routes use capability checks and nonces; file writes use WordPress APIs; output paths are escaped; no direct database queries are used.
* Verified: WordPress 7.1 compatibility.
* New: **Delay non-essential JavaScript until interaction** (Speed Boost, off by default). Third-party scripts such as analytics, chat widgets, and ads are delayed until visitor interaction or 7 seconds. WordPress core, jQuery, and Reloadify scripts are excluded. Test this feature on each site before enabling it.

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
