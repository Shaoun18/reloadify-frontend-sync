=== Reloadify Frontend Sync – Auto Reload, Server & Media Optimization ===
Contributors: shaounchandrashill
Tags: live reload, auto refresh, page builder, elementor, divi
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically refresh WordPress frontend previews after content updates with cross-browser live reload, server performance controls, and image/media optimization for Elementor, Divi, Gutenberg, and all page builders.

== Description ==

**Reloadify Frontend Sync** combines cross-browser live reload, server performance controls, and image/media optimization for WordPress developers, QA teams, and page-builder workflows.

It supports **Elementor**, **Divi**, **Gutenberg**, and other WordPress builders, with synchronized previews across browser tabs and windows.

= Why Use Reloadify? =

Manual refreshing after every save slows page-builder QA, especially during cross-browser testing.

Reloadify keeps open previews synchronized while providing practical server performance and image optimization controls in one dashboard.

= Key Features =

*   Works with **Elementor**, **Divi**, **Bricks**, **Oxygen**, **Beaver Builder**, **WPBakery Page Builder**, and the classic WordPress editor.
*   Cross-browser, cross-window reload for Chrome, Brave, Edge, Firefox, Safari, Opera, UC Browser, Vivaldi, Yandex Browser, and Samsung Internet. Chrome, Edge, and Safari are enabled by default. Incognito/private reload is available only after Developer Mode is enabled.
*   Choose **every open tab** or **active tab only**, preserving background-tab scroll position and form state.
*   Optional Speed Boost: CSS/JS/HTML minification, comment and whitespace stripping, and delayed non-essential JavaScript. Each pass is independently switchable.
*   Reloads all frontend views: home pages, single posts, archives, and search results.
*   Soft reload and cache-busting hard reload modes.
*   **Last change detected** status and a one-click **Check now** action.
*   A modern settings dashboard with per-browser cards and live status.
*   **Speed Boost** — on by default: strips the emoji script, trims unused `<head>` tags, throttles the Heartbeat API, caps stored post revisions, disables self-pingbacks, and eases wp-admin memory/time limits for heavy builders — all reversible with one toggle.
*   **Media Optimization** — new image uploads can get WebP/AVIF versions automatically when supported by the server. Video compression runs in the background when ffmpeg is available; offscreen images and embedded video are lazy-loaded.
*   **Extensions tab** — optional SVG upload support (off by default; scanned for scripts/embedded HTML before accepting) an optional Scroll To Top floating button (off by default; configurable position and color), a **Read More / Read Less** shortcode and an **AJAX Load More** shortcode for posts and WooCommerce products (both off by default).
*   An honest Server Performance panel: applies memory_limit / max_execution_time automatically, and generates ready-to-paste php.ini / .htaccess snippets for the settings a plugin genuinely cannot change at runtime (opcache, upload/post size limits, realpath cache).
*   Intelligent exclusion: never triggers a reload loop inside the builder canvas itself.

= Works With =

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

= Video =

https://youtu.be/3UPLTJkavJw

= How It Works (for end users) =

1.  Install and activate the plugin.
2.  Open a page in Elementor, Divi, Gutenberg, or another builder.
3.  Open the same page's live frontend view in another tab, another window, or even another browser entirely.
4.  Make a change and save it in the builder as normal.
5.  The frontend tab reloads on its own within a couple of seconds — no manual refresh needed.
6.  For browser rules, reload mode, Developer Mode, server performance, or media settings, open **Reloadify Sync** in the WordPress admin sidebar.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/reloadify-frontend-sync`, or install through the WordPress plugins screen.
2. Activate the plugin.
3. Open a page in the builder in one tab/browser, and the frontend view in another tab, window, or supported browser.
4. Save in the builder — the frontend reloads on its own.
5. Visit **Reloadify Sync** in the wp-admin sidebar to fine-tune Developer Mode, reload behavior, per-browser settings, server performance, and media optimization.

== Frequently Asked Questions ==

= Why doesn't it reload in a particular browser? =
Check Reloadify Sync → Cross-Browser Reload: confirm Developer Mode and the applicable browser/mode toggle are enabled. Incognito/private toggles stay disabled until Developer Mode is enabled. Chrome, Edge, and Safari are enabled by default; other browsers are optional.

= Is incognito/private mode detection 100% reliable? =
No — private/incognito detection is a best-effort heuristic because several browsers deliberately hide that information. Incognito/private reload controls are locked until Developer Mode is enabled. When Developer Mode is active, the selected browser rules are applied whenever private mode can be detected.

= Does the Server Performance panel really change opcache/upload limits? =
memory_limit, max_execution_time, and three opcache.* directives (enable, validate_timestamps, revalidate_freq) can be applied at runtime where PHP allows it. Other directives require php.ini changes and a PHP restart. The panel provides clear status and server-configuration guidance.

= Should I leave Developer Mode on in production? =
No. Developer Mode is intended for development and staging. It is off by default because visitor browsers poll the server while it is enabled, which adds load to a live site. Disable it after testing.

= Will this slow my site down? =
The frontend check uses a small static file, so PHP and WordPress are not involved in each poll. Developer Mode can still add load on busy live sites because every visitor browser polls continuously; use it only for active testing.

= How do I add a Read More button or a Load More button? =
Turn them on in Reloadify > Extensions, then paste a shortcode anywhere (Gutenberg Shortcode block, Elementor, Divi or a template). Use `[reloadify_read_more]Hidden text[/reloadify_read_more]` for expandable text, and `[reloadify_load_more]` (or `[reloadify_load_more post_type="product" per_page="8" columns="4"]`) for a post/product grid whose next batch loads without a page refresh.

= Does Media Optimization touch my original uploaded files? =
No. Original image files are never modified. WebP or AVIF versions are generated only for supported server image libraries. Video compression runs only when ffmpeg is available; otherwise video files remain unchanged. Media Optimization can be disabled at any time.

== Feedback ==

If Reloadify Frontend Sync improves a workflow, please consider leaving a review on WordPress.org.

Feedback helps improve the plugin and supports future updates.

== Screenshots ==

1. Cross-Browser Reload tab — Developer Mode toggle, live browser/reload-mode status, soft vs hard reload choice, and per-browser normal/incognito controls for Chrome, Brave, Edge, Firefox, Safari, Opera, UC Browser, Vivaldi, Yandex Browser, and Samsung Internet.
2. Server Performance tab — live PHP/OPcache values the plugin applies at runtime, plus the settings that require host-level changes, each clearly marked LIVE and AUTO-ATTEMPT.
3. Extensions tab — optional SVG upload support (off by default) and the Scroll To Top floating button controls (position, color, and scroll-distance threshold).

== Changelog ==

= 1.4.0 =
* Added **Read More / Read Less**: `[reloadify_read_more]` hides text behind an accessible button and opens it without a page refresh (text stays in the HTML, prints expanded, respects reduced motion, opens on #anchor links).
* Added **Load More (AJAX)**: `[reloadify_load_more]` prints the first batch of posts, pages, products or any public post type and loads the next batch through the REST API on a button press or when scrolling. Filter by category, tag, taxonomy, author or search; WooCommerce products show their price. Requests are signed, so only the query written in the shortcode can ever be loaded.
* Both tools have their own cards in the Extensions tab (texts, colours, animation, columns, trigger) with copy-ready shortcodes, are off by default, and load their CSS/JS only on pages that use them.

= 1.3.1 =
* Added **Prevent stale browser cache** toggle to Speed Boost (off by default): local CSS/JS are versioned by file modification time, replaced images get a fresh URL in rendered <img> tags (raw attachment URLs are never altered, so WooCommerce and image/SEO plugins are unaffected), pages are revalidated, and old back/forward-cache pages reload after 5 minutes (skipped on AMP, cart, checkout and account pages).
* Works inside page-builder editors (Divi, Elementor, etc.), so edits show without a hard reload.
* When enabled, it takes priority over **Remove query strings**.
* Fixed **WooCommerce checkout**: placing an order no longer triggers a live reload, so shoppers (and logged-in admins testing the shop) reach the order-received page instead of an empty cart. Only orders edited by a shop manager in wp-admin refresh the front end now.

= 1.3.0 =
* Added new **Speed Boost** features: query string management, lazy loading, script deferring, browser caching, gzip compression, and automatic cleanup.
* Redesigned **Speed Boost** with individual toggles, improved controls, and performance optimizations.
* Enhanced **Media Optimization** with WebP/AVIF support, compression settings, live previews, and faster background processing.
* Improved **Delete Data on Uninstall** with selective cleanup options.
* Redesigned **Server Performance** with live status updates, PHP settings, and improved controls.
* Enhanced **Scroll To Top** with custom Dashicons, animations, hover effects, scroll-progress rings, and live previews.
* Redesigned **Cross-Browser Reload** and **Extensions** with modern controls and customization options.
* Fixed **WooCommerce**, **Divi**, and page-builder compatibility issues.
* Fixed **Active Tab** synchronization, missed reloads, and content-change detection.
* Improved **CSS/JS optimization**, asset caching, and frontend performance.
* Added **WPML/Polylang** compatibility configuration.
* Fixed **WordPress coding standards**, WP-CLI activation, and unnecessary debug output.
* Reduced server requests, disk operations, and overall resource usage.

= 1.2.1 =
* Fixed **Active Tab** incorrectly switching to **All Tabs**.
* Fixed duplicated tabs sharing the same tab ID.
* Fixed tab ownership and newly focused tab synchronization.
* Fixed private-window reload behavior.
* Fixed **Heartbeat** and post-lock AJAX requests being detected as content changes.
* Fixed **CSS/JS minification** edge cases.
* Fixed **Delayed JavaScript** execution order and event replay.
* Fixed page-builder frame handling and cache issues.
* Fixed **Speed Boost** autosave, revisions, and Heartbeat behavior.
* Fixed **Media Optimization** and video processing issues.
* Fixed **Delete Data on Uninstall** cleanup on multisite.

= 1.2.0 =
* Added **Reload All Tabs** for synchronizing multiple frontend tabs and windows.
* Added **live settings propagation** for already-open frontend tabs.
* Added **CSS, JavaScript, and HTML minification** to Speed Boost.
* Added **Delayed JavaScript** optimization with essential-script exclusions.
* Changed Speed Boost, Media Optimization, and Delete Data on Uninstall to **opt-in for new installations**.
* Improved **media optimization performance** for large media libraries.
* Fixed **Active Tab** synchronization after page reloads.
* Added confirmation toasts and improved notification messages.
* Fixed translation template issues and regenerated the `.pot` file.

= 1.1.4 =
* Added **automatic saving** for Cross-Browser Reload, Server Performance, and Extensions settings.
* Updated default enabled browsers for new installations to Chrome, Edge, and Safari.
* Existing installations preserve their current browser settings.

= 1.1.3 =
* Fixed Apache 500 errors when applying automatic server overrides.
* Improved `.htaccess` validation, permissions, error handling, and backups.
* Added Apache/mod_php detection to prevent incompatible server configuration changes.
* Added fallback cache directives for supported Apache configurations.
* Added WordPress 7.1 compatibility.
* Improved Speed Boost server-side optimizations.

= 1.1.2 =
* Added `blueprint.json` support for WordPress Playground Live Preview.
* Added support for Vivaldi, Yandex Browser, and Samsung Internet.
* Reviewed frontend request handling to minimize server overhead.

= 1.1.1 =
* Fixed video compression on Windows servers.
* Added selectable image optimization formats: Automatic, WebP, or AVIF.
* Improved tooltip styling and positioning.
* Renamed the Extra Features tab to Extensions.
* Changed SVG Upload Support and Scroll To Top to be disabled by default.
* Updated Bengali translation.

= 1.1.0 =
* Added the Extensions tab with optional SVG Upload Support and Scroll To Top.
* Added Media Optimization for images and supported video files.
* Added lazy loading for images and embedded video.
* Enhanced Speed Boost with Heartbeat, revisions, and self-pingback optimizations.
* Added Last Change Detected and Check Now controls.
* Improved Developer Mode and settings notifications.
* Updated Bengali translation and improved code quality.

= 1.0.2 =
* Added a video walkthrough and How It Works section to the plugin description.

= 1.0.1 =
* Added Speed Boost with WordPress, PHP, and server performance optimizations.
* Added Delete Data on Uninstall option.
* Improved Developer Mode controls and frontend reload reliability.
* Added additional cache and reload detection safeguards.
* Added Bengali translation.
* Improved code quality and server configuration handling.

= 1.0.0 =
* Initial public release.
* Added cross-browser and cross-window frontend reload.
* Added support for Elementor, Divi, Bricks, Oxygen, Beaver Builder, and the Classic Editor.
* Added soft reload and cache-busting hard reload modes.
* Added site-wide frontend change detection.
* Added Developer Mode.
* Added Server Performance settings.
* Added browser status and synchronization controls.
* Added intelligent page-builder reload protection.
