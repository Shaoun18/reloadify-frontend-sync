=== Reloadify Frontend Sync ===
Contributors: shaounchandrashill
Tags: reload, auto-refresh, elementor, divi, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Auto-reloads the frontend in all open browsers whenever WordPress content is updated—works with any theme, plugin, or page builder.

== Description ==

**Reloadify Frontend Sync** is a developer and QA tool designed to streamline the workflow when building sites with page builders like **Elementor** and **Divi**.

Instead of manually refreshing your frontend tab every time you save a change in the builder, this plugin detects the save event and **automatically reloads** the frontend view for you — wherever that view happens to be open.

**Key Features:**
*   Works with **Elementor**, **Divi**, **Bricks**, **Oxygen**, **Beaver Builder**, and the classic WordPress editor.
*   Cross-browser, cross-window reload — enabled out of the box for Chrome, Brave, Edge, Firefox, Safari, Opera, and UC Browser, normal and incognito/private alike.
*   Reloads any frontend page (home, archives, search results — not just the exact post you're editing).
*   Choice of soft reload or cache-busting hard reload.
*   A modern settings dashboard with per-browser cards and live status.
*   An honest Server Performance panel: applies memory_limit / max_execution_time automatically, and generates ready-to-paste php.ini / .htaccess snippets for the settings a plugin genuinely cannot change at runtime (opcache, upload/post size limits, realpath cache).
*   Intelligent exclusion: never triggers a reload loop inside the builder canvas itself.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/reloadify-frontend-sync`, or install through the WordPress plugins screen.
2. Activate the plugin.
3. Open a page in your builder in one tab/browser, and the frontend view in another (any browser, any window).
4. Save in the builder — the frontend reloads on its own.
5. Visit **Auto Reloader** in the wp-admin sidebar to fine-tune Developer Mode, reload behavior, per-browser settings, and server performance.

== Frequently Asked Questions ==

= Why doesn't it reload in a particular browser? =
Check Auto Reloader → Cross-Browser Reload: confirm Developer Mode is on and that browser/mode is toggled on. Everything is on by default, but if you turned things off before, check there first.

= Is incognito/private mode detection 100% reliable? =
No — it's a best-effort heuristic. Several browsers deliberately make private mode indistinguishable from normal mode. When it can't tell, the plugin defaults to running the reloader anyway rather than staying silent.

= Does the Server Performance panel really change opcache/upload limits? =
memory_limit, max_execution_time, and three of the six opcache.* directives (enable, validate_timestamps, revalidate_freq) genuinely can be applied live by any WordPress plugin — that's just how PHP classifies them. The other three opcache directives (memory_consumption, interned_strings_buffer, max_accelerated_files) size shared memory once at PHP startup and truly can't be touched without editing php.ini and restarting PHP — same for post_max_size, upload_max_filesize, and realpath cache. For those, the panel writes a best-effort .user.ini/.htaccess (works on many hosts) or generates a copy-paste snippet, instead of pretending to apply them for you.

= Should I leave Developer Mode on in production? =
No — turn it on only while you're actively testing, then switch it back off. It's off by default for exactly this reason: while it's on, every visitor's browser polls the server, which is fine for staging but adds real load with real traffic. It also auto-disables itself after 12 hours in case you forget.

= Will this slow my site down? =
The frontend check is a small static file the webserver answers directly — no PHP or WordPress involved — so it's cheap per check. The main thing that adds load is leaving Developer Mode on for a long time on a busy live site, since every visitor's browser then polls continuously; keep it switched on only while you're actually testing.

== Screenshots ==

1. Cross-Browser Reload tab — Developer Mode toggle, live browser/reload-mode status, soft vs. hard reload choice, and per-browser normal/incognito controls for Chrome, Brave, Edge, Firefox, Safari, Opera, and UC Browser.
2. Server Performance tab — live PHP/OPcache values the plugin applies at runtime, plus the settings that require host-level changes, each clearly marked LIVE or AUTO-ATTEMPT.

== Changelog ==

= 1.0.1 =
*   New "Speed Boost" (on by default, one toggle to turn off): strips the emoji detection script/CSS WordPress prints on every page, trims a few unused `<head>` tags, turns PHP OPcache on if the host has it available but left it off, and — scoped strictly to wp-admin/admin-ajax.php, never a frontend visitor's request — raises memory_limit and max_execution_time headroom, but only ever upward, never below whatever the host already allows. Helps with the slow-or-failed-save symptom heavy builders like Divi/Elementor can hit on tight hosting/local-dev limits. No fixed "% faster" claim is made, since the real number depends on the site's theme, other plugins, and hosting.
*   New "Delete Data on Uninstall" toggle (Server Performance tab). On by default: deleting the plugin from the Plugins screen also removes its settings and the uploads/reloadify-reload folder. Turn it off to keep settings around for a later reinstall.
*   Developer Mode now shows a live countdown (hh:mm:ss) to when it will auto-disable itself, and the auto-off window is now 12 hours (was 6).
*   Fixed: the frontend could keep auto-reloading in a loop with no real change, if the page HTML itself was served from a cache (full-page cache plugin, host-level cache, or CDN) whose baked-in "last changed" value never caught up. The reloader no longer trusts that value for comparison — it now establishes its baseline from a live check on load instead. This also fixes a freshly opened tab sometimes missing the very next save until a manual refresh.
*   Added a periodic admin-ajax.php cross-check alongside the lightweight static-file check, plus no-cache headers for the timestamp file, so a cached/stale timestamp can't silently block reloads.
*   The local-development-only opcache override (Server Performance tab) now genuinely writes the 3 PHP-startup-locked directives to php.ini, with an automatic backup, once explicitly confirmed — instead of only generating a copy-paste snippet.
*   Added Bengali (bn_BD) translation for the entire settings dashboard and all admin-facing messages, bundled directly in this plugin's languages folder — WordPress.org automatically loads it for Bengali-locale sites without any extra code needed, and it displays translated immediately rather than waiting on the community translation queue.
*   Fixed two `\u2019`/`\u2014` escape sequences that were being printed literally instead of as an apostrophe/dash in a couple of Server Performance messages (PHP single-quoted strings don't interpret `\u` escapes).
*   Code-quality fix: prefixed two global variables in uninstall.php flagged by the WordPress Coding Standards checker.

= 1.0.0 =
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
