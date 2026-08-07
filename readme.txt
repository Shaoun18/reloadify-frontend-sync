=== Reloadify Frontend Sync ===
Contributors: shaounchandrashill
Tags: reload, auto-refresh, elementor, divi, performance
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
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
No — turn it on only while you're actively testing, then switch it back off. It's off by default for exactly this reason: while it's on, every visitor's browser polls the server, which is fine for staging but adds real load with real traffic. It also auto-disables itself after 6 hours in case you forget.

= Will this slow my site down? =
The frontend check is a small static file the webserver answers directly — no PHP or WordPress involved — so it's cheap per check. The main thing that adds load is leaving Developer Mode on for a long time on a busy live site, since every visitor's browser then polls continuously; keep it switched on only while you're actually testing.

== Screenshots ==

1. Cross-Browser Reload tab — Developer Mode toggle, live browser/reload-mode status, soft vs. hard reload choice, and per-browser normal/incognito controls for Chrome, Brave, Edge, Firefox, Safari, Opera, and UC Browser.
2. Server Performance tab — live PHP/OPcache values the plugin applies at runtime, plus the settings that require host-level changes, each clearly marked LIVE or AUTO-ATTEMPT.

== Changelog ==

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