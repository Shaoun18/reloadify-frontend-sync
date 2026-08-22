# Reloadify Frontend Sync

**Contributors:** shaounchandrashill
**Tags:** reload, auto-refresh, elementor, divi, performance
**Requires at least:** 6.4
**Tested up to:** 7.0
**Requires PHP:** 7.4
**Stable tag:** 1.1.1
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Automatically reloads the frontend across all open browsers whenever WordPress content updates—works with any theme, plugin, or page builder.

## Description

**Reloadify Frontend Sync** is a developer and QA tool designed to streamline the workflow when building sites with page builders like **Elementor** and **Divi**.

Instead of manually refreshing your frontend every time you save a change, **Reloadify Frontend Sync** automatically reloads every connected browser or window, keeping all your frontend previews synchronized without interrupting your workflow.

### Why Use Reloadify?

Manually refreshing the frontend after every save interrupts your workflow and wastes time, especially when testing across multiple browsers.

Reloadify Frontend Sync keeps every open browser automatically synchronized, allowing you to focus on building instead of constantly refreshing.

### Key Features

*   Works with **Elementor**, **Divi**, **Bricks**, **Oxygen**, **Beaver Builder**, **WPBakery Page Builder**, and the classic WordPress editor.
*   Cross-browser, cross-window reload — enabled out of the box for Chrome, Brave, Edge, Firefox, Safari, Opera, and UC Browser, normal and incognito/private alike.
*   Reloads any frontend page (home, archives, search results — not just the exact post you're editing).
*   Choice of soft reload or cache-busting hard reload (`_reloadify_ts` query param).
*   A "Last change detected" readout with a one-click Check now button, so you can confirm the plugin picked up a save without waiting for a frontend tab to reload.
*   A modern settings dashboard with per-browser cards and live status.
*   **Speed Boost** — on by default: strips the emoji script, trims unused `<head>` tags, throttles the Heartbeat API, caps stored post revisions, disables self-pingbacks, and eases wp-admin memory/time limits for heavy builders — all reversible with one toggle.
*   **Media Optimization** — on by default: new image uploads get WebP/AVIF versions automatically (Automatic picks the best your server supports, or pin WebP/AVIF yourself), video is compressed in the background when ffmpeg is available, offscreen images and embedded video are lazy-loaded, and a new **Optimization** column in the Media Library shows the real, measured size saved on every file.
*   **Extensions tab** — optional SVG upload support (off by default; scanned for scripts/embedded HTML before accepting) and an optional Scroll To Top floating button (off by default; configurable position and color).
*   An honest Server Performance panel: applies memory_limit / max_execution_time automatically, and generates ready-to-paste php.ini / .htaccess snippets for the settings a plugin genuinely cannot change at runtime (opcache, upload/post size limits, realpath cache).
*   Intelligent exclusion: never triggers a reload loop inside the builder canvas itself.

### Works With

Reloadify Frontend Sync has been tested with:

* WordPress Block Editor (Gutenberg)
* Classic Editor
* Elementor
* Divi
* Bricks Builder
* Oxygen Builder
* Beaver Builder
* WPBakery Page Builder
* Most WordPress themes

[Watch the video walkthrough on YouTube](https://youtu.be/3UPLTJkavJw)

### How It Works (for end users)

1.  Install and activate the plugin — no configuration needed to start; everything works out of the box.
2.  Open your page in the builder (Elementor, Divi, etc.) in one browser tab.
3.  Open the same page's live frontend view in another tab, another window, or even another browser entirely.
4.  Make a change and save it in the builder as normal.
5.  The frontend tab reloads on its own within a couple of seconds — no manual refresh needed.
6.  If you ever need to fine-tune behavior (turn off a specific browser, switch to hard reload, check server settings), go to **Auto Reloader** in your wp-admin sidebar.

## Installation

1. Upload the plugin files to `/wp-content/plugins/reloadify-frontend-sync`, or install through the WordPress plugins screen.
2. Activate the plugin.
3. Open a page in your builder in one tab/browser, and the frontend view in another (any browser, any window).
4. Save in the builder — the frontend reloads on its own.
5. Visit **Auto Reloader** in the wp-admin sidebar to fine-tune Developer Mode, reload behavior, per-browser settings, and server performance.

## Frequently Asked Questions

**Why doesn't it reload in a particular browser?**
Check Auto Reloader → Cross-Browser Reload: confirm Developer Mode is on and that browser/mode is toggled on. Everything is on by default, but if you turned things off before, check there first.

**Is incognito/private mode detection 100% reliable?**
No — it's a best-effort heuristic. Several browsers deliberately make private mode indistinguishable from normal mode. When it can't tell, the plugin defaults to running the reloader anyway rather than staying silent.

**Does the Server Performance panel really change opcache/upload limits?**
memory_limit, max_execution_time, and three of the six opcache.* directives (enable, validate_timestamps, revalidate_freq) genuinely can be applied live by any WordPress plugin — that's just how PHP classifies them. The other three opcache directives (memory_consumption, interned_strings_buffer, max_accelerated_files) size shared memory once at PHP startup and truly can't be touched without editing php.ini and restarting PHP — same for post_max_size, upload_max_filesize, and realpath cache. For those, the panel writes a best-effort .user.ini/.htaccess (works on many hosts) or generates a copy-paste snippet, instead of pretending to apply them for you.

**Should I leave Developer Mode on in production?**
No — turn it on only while you're actively testing, then switch it back off yourself. It's off by default for exactly this reason: while it's on, every visitor's browser polls the server, which is fine for staging but adds real load with real traffic. Unlike earlier versions, Developer Mode no longer disables itself automatically — it stays exactly as you set it until you change it, so remembering to switch it off is now on you.

**Will this slow my site down?**
The frontend check is a small static file the webserver answers directly — no PHP or WordPress involved — so it's cheap per check. The main thing that adds load is leaving Developer Mode on for a long time on a busy live site, since every visitor's browser then polls continuously; keep it switched on only while you're actually testing.

**Does Media Optimization touch my original uploaded files?**
No. For images, the original file you uploaded is never modified — only the generated thumbnail/medium/large sizes get WebP or AVIF versions alongside them, and only if your server's image library actually supports that format. Video compression only runs if the server has ffmpeg available; if it doesn't, video is left completely untouched. The whole feature is one toggle — turn it off any time to leave media exactly as WordPress would handle it by default.

## Changelog

### 1.1.1
*   Fixed a video compression bug on Windows servers: the background ffmpeg command included `nice`, a Unix-only process-priority tool with no Windows equivalent, so the entire command failed to run there and video was silently never compressed (the original file was never at risk — this plugin only ever swaps a video if compression reports success — but Windows/local-dev users got zero benefit from the feature). Also hardened the success check so an implausibly small/truncated encode is rejected instead of accepted.
*   New: Media Optimization image format is now a real choice — **Automatic** (default; picks the best your server supports), **WebP only**, or **AVIF only** — instead of always being decided for you. Choosing AVIF on a server that can't produce it falls back to WebP automatically.
*   Fixed: the help (i) icons were using the browser's native tooltip (the plain `title` attribute), which can't be styled or positioned and could render overlapping adjacent labels. Replaced with a proper tooltip component — dark, rounded, positioned consistently below its icon.
*   Renamed the **Extra Features** tab to **Extensions**.
*   Fixed: **SVG Upload Support** defaulted to on, which didn't match this plugin's own documented intent or the Scroll To Top button's existing off-by-default behavior. Both now default to off.
*   Updated the Bengali (bn_BD) translation to cover the new format-choice control and the Extensions tab name.

### 1.1.0
*   New "Extra Features" tab: optional SVG upload support (on by default — every upload is scanned for `<script>` tags, event-handler attributes, `javascript:` URIs, and embedded HTML before being accepted; blocked and rejected if any are found) and an optional Scroll To Top floating button (off by default; configurable position, color, and scroll-distance threshold).
*   New "Media Optimization" (Server Performance tab, on by default): new image uploads automatically get WebP or AVIF versions generated alongside the original — whichever your server actually supports — without ever touching the original uploaded file. Existing media library images are optimized gradually in the background so a large library can't turn into one long blocking job. Video is compressed in the background only when the server genuinely has ffmpeg available; otherwise it's left untouched. Includes an "Optimize existing media now" button and a new "Optimization" column in the Media Library showing the real, measured before/after size for every file.
*   Added forced lazy-loading for images and embedded video iframes (YouTube, Vimeo, etc.).
*   Speed Boost: three more items — throttles the Heartbeat API to once every 60 seconds in wp-admin and removes it from the frontend entirely, caps stored post revisions at 5 going forward, and stops WordPress from pinging itself when a post links to another post on the same site.
*   New "Last change detected" readout with a Check now button on the Cross-Browser Reload tab.
*   Fixed: an admin settings save could go undetected the very first time a given option row was written (e.g. a WooCommerce setting saved for the first time on a fresh install) — WordPress fires `added_option` instead of `updated_option` for that first write, and only the latter was being listened for.
*   Changed: Developer Mode is now a plain on/off toggle with no automatic timeout — it previously auto-disabled itself after 12 hours, but now stays exactly as you set it until you switch it off yourself.
*   Replaced permanent help paragraphs under section titles with a small (i) icon you hover for the same explanation on demand — except the opcache "local dev only" danger warning, which stays as visible text since it's a safety warning.
*   Speed Boost, Media Optimization, and Delete Data on Uninstall now sit side by side in one row instead of stacked full-width.
*   Confirmed compatible with WordPress 7.0 ("Tested up to" header — WordPress.org's validator only accepts major.minor here, not the full patch version).
*   Updated the Bengali (bn_BD) translation to cover every new string introduced by Extra Features, Media Optimization, and the additional Speed Boost items.
*   Fixed the same literal `\u2019`/`\u2014`/`\u201c`/`\u201d` escape-sequence bug from 1.0.1 recurring in the new Extra Features, Media Optimization, and Speed Boost messages.
*   Code-quality fix: prefixed two global variables in `uninstall.php` flagged by the WordPress Coding Standards checker.

### 1.0.2
*   Added a video walkthrough and a step-by-step "How It Works" section to the plugin description, for people evaluating the plugin before installing.

### 1.0.1
*   New "Speed Boost" (on by default, one toggle to turn off): strips the emoji detection script/CSS WordPress prints on every page, trims a few unused `<head>` tags, turns PHP OPcache on if the host has it available but left it off, and — scoped strictly to wp-admin/admin-ajax.php, never a frontend visitor's request — raises `memory_limit`/`max_execution_time` headroom, only ever upward, never below whatever the host already allows. No fixed "% faster" claim is shown, since the real number depends on the site's theme, other plugins, and hosting.
*   New "Delete Data on Uninstall" toggle (on by default): deleting the plugin from the Plugins screen also removes its settings and the `uploads/reloadify-reload` folder. Turn it off to keep settings around for a later reinstall.
*   Developer Mode now shows a live countdown (hh:mm:ss) to when it will auto-disable itself, and the auto-off window is now 12 hours (was 6).
*   Fixed: the frontend could keep auto-reloading in a loop with no real change if the page HTML was served from a cache (full-page cache plugin, host-level cache, or CDN) whose baked-in "last changed" value never caught up — the reloader now establishes its baseline from a live check on load instead of trusting cached markup. Same fix also resolves a freshly opened tab sometimes missing the very next save until a manual refresh.
*   Added a periodic admin-ajax.php cross-check alongside the lightweight static-file check, plus no-cache headers for the timestamp file, so a cached/stale timestamp can't silently block reloads.
*   The local-development-only opcache override (Server Performance tab) now genuinely writes the 3 PHP-startup-locked directives to `php.ini`, with an automatic backup, once explicitly confirmed — instead of only generating a copy-paste snippet.
*   Added Bengali (bn_BD) translation for the entire settings dashboard and all admin-facing messages, bundled directly in this plugin's languages folder — WordPress.org automatically loads it for Bengali-locale sites without any extra code needed, and it displays translated immediately rather than waiting on the community translation queue.
*   Fixed two `\u2019`/`\u2014` escape sequences that were being printed literally instead of as an apostrophe/dash in a couple of Server Performance messages (PHP single-quoted strings don't interpret `\u` escapes).
*   Code-quality fix: prefixed two global variables in `uninstall.php` flagged by the WordPress Coding Standards checker.

### 1.0.0
*   Initial public release.
*   Cross-browser, cross-window frontend reload — works with Elementor, Divi, Bricks, Oxygen, Beaver Builder, and the classic WordPress editor, in Chrome, Brave, Edge, Firefox, Safari, Opera, and UC Browser (including incognito/private).
*   Reload now works on the homepage, archives, and any frontend page — not just the exact post being edited — via a single site-wide "last changed" clock.
*   Choice of soft reload or cache-busting hard reload.
*   Frontend polling checks a small static JSON file served directly by the webserver instead of booting WordPress on every check, for minimal overhead.
*   Developer Mode is off by default and auto-disables after 6 hours as a safety net, since it's the setting that adds ongoing load on a live site while active.
*   A Server Performance panel that's upfront about what a plugin can and can't do: applies memory_limit, max_execution_time, and 3 of 6 opcache.* directives live; generates ready-to-paste php.ini / .htaccess snippets and offers a best-effort .user.ini/.htaccess write for the settings that genuinely require a real server-side change (upload/post size limits, realpath cache, and the 3 memory-sizing opcache directives).
*   A local-development-only, explicitly-confirmed option to write the 3 truly PHP-startup-locked opcache directives directly to php.ini, with automatic backup.
*   Modern tabbed settings dashboard with per-browser cards, live status, and a "Sync from server" action.
*   Intelligent exclusion: never triggers a reload loop inside a page builder's own editing canvas.
