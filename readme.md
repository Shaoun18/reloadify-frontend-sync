# Reloadify Frontend Sync

Automatically reloads the frontend across all open browsers and windows whenever content is updated in WordPress — regardless of the theme, plugin, or page builder you're using.

**Version:** 1.0.0
**Requires at least:** WordPress 6.4
**Requires PHP:** 7.4
**License:** GPLv2 or later

## How reload detection works

Reload uses a single **site-wide "last changed" clock** (`reloadify_last_site_update`), bumped on every `save_post`, and the polling script is enqueued on **every** front-end page — home, archive, search, singular, all of it. That's what makes reload work no matter which frontend tab is open, not just the exact post being edited.

## Key features

- Reload works on any frontend page type, in any browser, in normal or incognito windows, out of the box.
- **Soft vs. hard reload** — soft is a normal `location.reload()`; hard appends a cache-busting `_far=<timestamp>` parameter and navigates via `location.replace()` so you get a genuinely fresh fetch instead of a cached copy.
- **Tabbed dashboard** — gradient hero header, tabbed layout (Cross-Browser Reload / Server Performance), stat cards, and a card grid per browser with a colored icon badge instead of a plain table.
- **Server Performance panel** — see below, this one comes with an important honesty note.

## Server Performance panel — read this before relying on it

You asked for a panel that applies settings like `opcache.*`, `post_max_size`, `upload_max_filesize`, and `memory_limit` site-wide by default. Here's the technical reality: **a WordPress plugin runs after PHP has already started processing the request.** PHP directives are split into categories:

- `PHP_INI_ALL` / `PHP_INI_USER` — changeable at runtime with `ini_set()`. Only **`memory_limit`** and **`max_execution_time`** from your list fall here.
- `PHP_INI_PERDIR` / `PHP_INI_SYSTEM` — locked in before your plugin code ever runs. This is **everything else you listed**: `max_input_time`, `post_max_size`, `upload_max_filesize`, every `opcache.*` directive, and `realpath_cache_size`/`realpath_cache_ttl`.

No WordPress plugin, on any host, can change the second group at runtime — that's not a limitation of this plugin's code, it's how PHP itself works. A plugin that claimed to apply those "automatically" would silently do nothing on the directives that matter most (opcache tuning, upload limits) while looking like it worked.

So the panel is split honestly:

- **Applied automatically** (`memory_limit`, `max_execution_time`) — toggle these on and the plugin calls `ini_set()`/`set_time_limit()` on every request. This actually works.
- **Requires host config** (everything else) — set your desired value, then click **Generate config snippet** to get copy-paste blocks for `php.ini`/`.user.ini` (works for all of them, including opcache) and `.htaccess` (Apache + mod_php only, and it can't carry opcache or realpath_cache directives at all — those aren't legal in `.htaccess` on any server). Hand the relevant block to yourself or your host.

## Can a plugin really override server PHP settings?

Short answer: **partially, and it depends on your host.** There's an opt-in **"Attempt automatic server override"** action that writes:

- **`.user.ini`** in the WordPress root — the mechanism PHP-FPM/CGI hosting (the majority of modern managed WordPress hosts) uses for per-directory PHP config. Covers `max_input_time`, `post_max_size`, `upload_max_filesize`, `realpath_cache_size`, `realpath_cache_ttl`.
- **`.htaccess`** — a marked `php_value` block for Apache + mod*php setups specifically. Only covers `max_input_time`, `post_max_size`, `upload_max_filesize` (Apache doesn't accept `realpath_cache*\*`there — it's`PHP_INI_SYSTEM`, not per-directory).

Both are genuinely real server config mechanisms, not a trick — when they work, they work for real, for every request, indefinitely, no plugin code needed after the write. But neither is guaranteed:

- `.user.ini` is picked up on a cache cycle (`user_ini.cache_ttl`, 300 seconds by default) — not instantly.
- Some hosts disable `.user.ini` scanning entirely, or run `open_basedir`/read-only filesystems that block the write outright.
- `.htaccess` `php_value` lines do nothing at all on Nginx or PHP-FPM without Apache — which is most modern hosting.
- **`opcache.*` cannot be reached by either mechanism, or by any plugin, on any host.** It's `PHP_INI_SYSTEM` — fixed at PHP startup, before any per-directory config is even read. The only way to change it is editing the real `php.ini` (or an FPM pool file) and restarting PHP. The panel is explicit about this rather than pretending a file write can reach it.

Every write attempt reports back exactly what happened (written / not writable / write failed) rather than assuming success — check the results panel after clicking, and use "Sync from server" afterward to confirm whether the live value actually changed.

### Why memory_limit looked "admin only"

`ini_set('memory_limit', ...)` changes the limit for the rest of _that specific request_, for any request that actually executes PHP through WordPress — front-end or admin, no difference in the code. The most common reason it can look admin-only: a **page-caching plugin or CDN serving frontend pages without hitting PHP at all**. wp-admin is essentially never full-page-cached, so it always runs our override; a cached frontend hit skips PHP (and therefore this plugin) entirely, so it still shows whatever value was baked in when that cache entry was generated. The override runs on `plugins_loaded` priority 0 — the earliest hook any regular plugin can use — for maximum consistency on requests that do hit PHP.

### opcache.\* — three are live-applied, three are local development only

Turns out not all six opcache directives are equally locked down. Per the PHP manual, `opcache.enable`, `opcache.validate_timestamps`, and `opcache.revalidate_freq` are `PHP_INI_ALL` — a per-request check, not a startup allocation — so they moved into the same safe "Applied automatically" group as `memory_limit`, live via `ini_set()`, no file writing involved. (`opcache.enable` can only be switched _off_ this way, never back on once the server started with it disabled — that's PHP's own rule, not this plugin's.)

The other three — `opcache.memory_consumption`, `opcache.interned_strings_buffer`, `opcache.max_accelerated_files` — genuinely can't be reached any other way: they size a shared-memory pool once when PHP starts, before any plugin runs. For just these three, there's an opt-in **"Attempt opcache override"** action, but it comes with a hard line: **only ever use it on a local development environment you fully control — never on a live/production site.**

It writes directly to the real, loaded `php.ini` (always backing up the original first) — a bad edit, or running this on a shared or live server, can break PHP for _every site the server hosts_, not just this one, until someone fixes it by hand. It's also a no-op until PHP restarts, which this plugin has no way to trigger. Because of that, it's gated behind a persistent red warning banner and a required "I understand this can crash the server" checkbox — it will not run without both.

## Settings dashboard

Found under **Auto Reloader** in the wp-admin sidebar (top-level menu, not buried in Settings). Built with `wp.element` (React bundled with WordPress core, no build step) and `wp.apiFetch`.

- **Cross-Browser Reload tab** — Developer Mode switch, soft/hard reload choice, and a card grid (icon + name + Normal/Incognito toggles) for Chrome, Brave, Edge, Firefox, Safari, Opera, and UC Browser.
- **Server Performance tab** — the panel described above, plus the snippet-generator modal.
- Toasts (green success / red error) confirm every save.

## File structure

```
reloadify-frontend-sync/
├── reloadify-frontend-sync.php     Bootstrap, hooks, global "site changed" clock, AJAX handler
├── includes/
│   ├── class-reloadify-settings.php     Reload/browser settings, defaults, sanitization
│   ├── class-reloadify-performance.php  Runtime vs. host-only PHP directive handling
│   ├── class-reloadify-rest.php         REST API for both settings groups
│   └── class-reloadify-admin.php        Top-level admin menu + asset enqueueing
├── assets/
│   ├── js/
│   │   ├── reloader.js            Frontend polling, browser/mode gating, soft/hard reload
│   │   └── admin-settings.js      Tabbed React dashboard
│   └── css/
│       └── admin-settings.css     Modern gradient/card design
├── readme.txt                     WordPress.org plugin readme
└── readme.md                      This file
```

## Security notes

- The AJAX update-check endpoint is nonce-protected for both logged-in and logged-out requests.
- The only thing exposed to a logged-out visitor is a single "site last changed" integer timestamp — no post content, titles, or IDs.
- Developer Mode defaults to **off** and auto-disables itself after 6 hours if left on — turn it on only while actively testing, so the polling script isn't shipped to real visitors on a live site.
- The Server Performance panel never writes to `php.ini`, `.htaccess`, or any server file on its own — it only shows you the snippet to apply yourself.

## Changelog

Initial public release, v1.0.0. See `readme.txt` for the full changelog.
