<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------- Speed Boost ---------------- */

class Reloadify_Speed
{

	const OPTION_KEY  = 'reloadify_speed_boost_enabled';
	const OPTIONS_KEY = 'reloadify_speed_options';
	const CACHE_DIR   = 'reloadify-minify';

	/**
	 * Bump whenever the minifier's output changes, so cached files built by an
	 * older revision are never served again (the version number alone isn't
	 * enough when a fix ships without one).
	 */
	const CACHE_REV   = 3;

	/**
	 * Script handles that must never be deferred until first interaction.
	 * jQuery stays eager because an enormous amount of theme/plugin code
	 * assumes it is already there, and this plugin's own scripts stay eager
	 * because the whole point of the reloader is that it runs without anyone
	 * touching the page.
	 */
	const DELAY_JS_EXCLUDED_HANDLES = [ 'jquery', 'jquery-core', 'jquery-migrate' ];

	/**
	 * Only third-party tracking / marketing / chat scripts are ever delayed.
	 *
	 * Delaying EVERYTHING until the first interaction (what 1.2.0/1.2.1 did)
	 * broke anything that measures or builds layout when it runs -- the
	 * WooCommerce product gallery (FlexSlider) ended up with a wrong height,
	 * and theme code that expects its siblings to exist already failed. Those
	 * scripts must run in the normal order, so only scripts whose URL matches
	 * one of these fragments are parked. Extend with the
	 * `reloadify_delay_js_patterns` filter.
	 */
	const DELAY_JS_PATTERNS = [
		'google-analytics.com', 'googletagmanager.com', 'googleadservices.com', 'googlesyndication.com',
		'doubleclick.net', 'connect.facebook.net', 'static.hotjar.com', 'script.hotjar.com', 'clarity.ms',
		'analytics.tiktok.com', 'sc-static.net', 'snap.licdn.com', 'static.ads-twitter.com', 'platform.twitter.com',
		's.pinimg.com', 'assets.pinterest.com', 'cdn.segment.com', 'js.hs-scripts.com', 'js.hs-analytics.net',
		'js.hscollectedforms.net', 'widget.intercom.io', 'js.intercomcdn.com', 'client.crisp.chat', 'embed.tawk.to',
		'js.driftt.com', 'static.cloudflareinsights.com', 'plausible.io', 'stats.wp.com', 'pixel.wp.com',
		'mc.yandex.ru', 'cdn.heapanalytics.com', 'cdn.mxpnl.com', 'fullstory.com', 'chimpstatic.com',
		'cdn.onesignal.com', 'static.klaviyo.com', 'cdn.matomo.cloud', 'wa.me/widget', 'cdn.lr-ingest.io',
	];

	/**
	 * Scripts that are never deferred, matched against the HANDLE. They either
	 * build or measure layout when they run (galleries, sliders, page
	 * builders), are used by inline code the theme prints (jQuery, WooCommerce,
	 * core), or must be ready the moment a form is touched. Extend with the
	 * `reloadify_defer_js_skip_patterns` filter.
	 */
	const DEFER_SKIP_PATTERNS = [
		'jquery', 'wp-', 'woocommerce', 'wc-', 'wc_', 'et-', 'et_', 'divi', 'elementor', 'bricks', 'fl-builder',
		'beaver', 'vc_', 'js_composer', 'slider', 'slick', 'swiper', 'owl', 'flex', 'carousel', 'gallery',
		'lightbox', 'fancybox', 'magnific', 'photoswipe', 'zoom', 'masonry', 'isotope', 'parallax', 'waypoints',
		'lazy', 'modernizr', 'polyfill', 'recaptcha', 'captcha', 'stripe', 'paypal', 'payment', 'checkout',
		'cart', 'map', 'wpcf7', 'contact-form', 'gravity', 'forminator', 'ninja', 'jetpack', 'tinymce', 'editor',
		'customize', 'admin-bar', 'comment', 'select2', 'selectwoo', 'chosen', 'datepicker', 'moment',
	];

	/** How long a changed file keeps its ?ver= tag (seconds) when query strings are removed. */
	const QUERY_STRING_GRACE = 7 * DAY_IN_SECONDS;

	/** Hook that purges expired transients once a week (Speed Boost > Database cleanup). */
	const CRON_HOOK_CLEANUP = 'reloadify_cleanup_transients';
	const RULES_MARKER      = 'Reloadify Speed Boost';
	const RULES_REV_OPTION  = 'reloadify_server_rules_rev';

	/** Third-party scripts parked on this request. */
	private static $parked = 0;

	/**
	 * Seconds of CSS/JS minification one request may spend. The first view
	 * after a change builds the cached copies; with a large theme that could
	 * add up to many seconds on a single page load. Past the budget the
	 * original file is served and the rest are built on the following views.
	 */
	const MINIFY_BUDGET = 1.0;
	private static $minify_spent = 0.0;

	/**
	 * Off by default since 1.2.0 -- Speed Boost now changes real front-end
	 * output (minification, deferred scripts), so it is opt-in rather than
	 * something that silently switches itself on.
	 */
	public static function is_enabled()
	{
		return (bool) get_option(self::OPTION_KEY, false);
	}

	public static function set_enabled($enabled)
	{
		$enabled = (bool) $enabled;

		add_option(self::OPTION_KEY, false);
		update_option(self::OPTION_KEY, $enabled);

		if (! $enabled) {
			// Nothing on disk should outlive the feature that produced it.
			self::purge_cache();
		}

		self::sync_server_side();

		return self::is_enabled();
	}

	/**
	 * Called when the plugin is deactivated: leave no .htaccess rules or
	 * scheduled events behind.
	 */
	public static function deactivate()
	{
		self::remove_server_rules();
		wp_unschedule_hook(self::CRON_HOOK_CLEANUP);
	}

	/* ---------------- Sub-options ---------------- */

	/**
	 * The optimisation passes that change page output. They only ever run
	 * while the master Speed Boost toggle is on; turning that off disables
	 * every one of them regardless of what's stored here.
	 */
	public static function default_options()
	{
		return [
			'minify_js'           => true,
			'minify_css'          => true,
			'minify_html'         => true,
			'remove_html_comments' => true,
			'collapse_whitespace' => true,
			'remove_query_strings' => true,
			'lazy_load_images'    => true,
			'defer_js'            => true,
			'delay_js'            => true,
			'browser_cache'       => true,
			'dashicons'           => true,
			'db_cleanup'          => true,
		];
	}

	public static function get_options()
	{
		$saved = get_option(self::OPTIONS_KEY, []);
		$saved = is_array($saved) ? $saved : [];

		$clean = [];
		foreach (self::default_options() as $key => $default) {
			$clean[$key] = array_key_exists($key, $saved) ? (bool) $saved[$key] : $default;
		}

		return $clean;
	}

	public static function set_options($incoming)
	{
		$incoming = is_array($incoming) ? $incoming : [];
		$current  = self::get_options();

		foreach (self::default_options() as $key => $default) {
			if (array_key_exists($key, $incoming)) {
				$current[$key] = ! empty($incoming[$key]);
			}
		}

		update_option(self::OPTIONS_KEY, $current);

		// Cached files were produced under the previous rules.
		self::purge_cache();

		self::sync_server_side();

		return self::get_options();
	}

	public static function option_labels()
	{
		return [
			'minify_js'           => __('Minify JavaScript', 'reloadify-frontend-sync'),
			'minify_css'          => __('Minify CSS', 'reloadify-frontend-sync'),
			'minify_html'         => __('Minify HTML output', 'reloadify-frontend-sync'),
			'remove_html_comments' => __('Remove HTML comments', 'reloadify-frontend-sync'),
			'collapse_whitespace' => __('Remove unnecessary whitespace', 'reloadify-frontend-sync'),
			'remove_query_strings' => __('Remove version query strings from static files', 'reloadify-frontend-sync'),
			'lazy_load_images'    => __('Lazy-load images and iframes below the fold', 'reloadify-frontend-sync'),
			'defer_js'            => __('Defer non-critical scripts', 'reloadify-frontend-sync'),
			'delay_js'            => __('Delay third-party tracking & chat scripts until first interaction', 'reloadify-frontend-sync'),
			'browser_cache'       => __('Browser caching & compression for images, fonts and text (Apache)', 'reloadify-frontend-sync'),
			'dashicons'           => __('Skip the Dashicons font for logged-out visitors', 'reloadify-frontend-sync'),
			'db_cleanup'          => __('Weekly cleanup of expired transients in the database', 'reloadify-frontend-sync'),
		];
	}

	/**
	 * What's actually included, exposed to the UI so the toggle isn't a
	 * black box — every line here is something the person can go verify.
	 */
	public static function items()
	{
		return [
			[
				'key' => 'minify_assets',
				'label' => __('Minifies your CSS and JavaScript files and serves cached copies from the uploads folder — originals are never modified, and anything already minified is skipped', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'minify_html',
				'label' => __('Minifies the HTML WordPress sends to the browser — strips comments and collapses redundant whitespace, leaving <pre>, <textarea> and script payloads untouched', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'remove_query_strings',
				'label' => __('Removes ?ver= from CSS and JS URLs for logged-out visitors so CDNs and proxies cache them. A file keeps its version tag for 7 days after it changes, and logged-in editors always get the tagged URL, so updates and edits are never hidden', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'lazy_load_images',
				'label' => __('Adds loading="lazy" and decoding="async" to images and iframes that don\'t have it. The first two images stay eager (they are usually the largest paint), and gallery, slider and already-lazy images are left alone', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'defer_js',
				'label' => __('Adds defer to stand-alone scripts in the page head that nothing else depends on. jQuery, WordPress core, WooCommerce, page builders, sliders and galleries are never deferred', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'delay_js',
				'label' => __('Delays third-party tracking, advertising and chat scripts (Google Analytics/Tag Manager, Meta Pixel, Hotjar, Intercom, Tawk.to and similar) until the first interaction or 7 seconds — your theme, WooCommerce and page-builder scripts always load normally, so layouts and galleries are never affected', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'emojis',
				'label' => __('Removes the emoji-detection script & inline CSS WordPress prints on every page (frontend and wp-admin)', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'head_links',
				'label' => __('Drops unused <link> tags from <head> (RSD, WLW manifest, shortlink, generator tag, REST API and oEmbed discovery links)', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'opcache',
				'label' => __('Turns PHP OPcache on if your host has it available but left it off', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'backend_headroom',
				'label' => __('Raises wp-admin\'s memory_limit and max_execution_time headroom (never below what your host already allows) so heavy builders like Divi/Elementor are less likely to hit a slow or failed save — frontend visitor requests are never touched', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'heartbeat',
				'label' => __('Caps the Heartbeat API to once every 60 seconds in wp-admin (instead of every 15–60s) and removes it from the frontend entirely for visitors — fewer background requests hitting the server on both sides', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'revisions',
				'label' => __('Caps stored post revisions at 5 per post going forward (older ones already saved are left alone) — keeps the posts table smaller and post-related queries a little faster on both the editor and the frontend', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'self_pingbacks',
				'label' => __('Stops WordPress from pinging itself when one of your own posts links to another — removes a pointless outbound HTTP request and a comments-table write on every such save', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'xmlrpc',
				'label' => __('Disables XML-RPC entirely (removes unused remote publishing protocol that most modern sites don\'t need) — eliminates brute-force attack vectors and unused server load', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'autosave',
				'label' => __('Increases autosave interval from 60 to 120 seconds in wp-admin — fewer database writes and server calls while editing', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'browser_cache',
				'label' => __('Adds compression (gzip) and long-lived browser caching for images, fonts and video to your .htaccess on Apache — fewer bytes and fewer requests on every repeat visit. CSS and JS keep WordPress\'s own versioning, so updates always show immediately. Skipped automatically on Nginx or when .htaccess isn\'t writable', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'dashicons',
				'label' => __('Stops loading the Dashicons icon font for logged-out visitors unless a stylesheet on the page actually depends on it', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'db_cleanup',
				'label' => __('Deletes expired transients from the options table once a week — without an object cache they pile up and slow down every options query', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'embeds',
				'label' => __('Stops wp-embed.min.js from loading on the frontend for visitors — it only resizes embedded-post iframes, which most sites never use, so it\'s one less script on every page', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'minified_assets',
				'label' => __('Serves this plugin\'s own CSS/JS pre-minified on real requests (roughly half the bytes) — automatic, and only switches back to the readable originals when SCRIPT_DEBUG or WP_DEBUG is on, so nothing changes for local development', 'reloadify-frontend-sync'),
			],
		];
	}

	/**
	 * Page builders render the real front end inside their editor frame, with
	 * their own scripts and inline JSON. Rewriting those (minifying, delaying,
	 * stripping versions) is exactly the kind of thing that makes a builder
	 * silently misbehave, so every front-end pass stands down for them.
	 */
	public static function is_builder_request()
	{
		$flags = ['et_fb', 'et_pb_preview', 'elementor-preview', 'fl_builder', 'ct_builder', 'brizy-edit', 'brizy-edit-iframe', 'bricks', 'tve', 'vc_editable', 'customize_changeset_uuid', 'preview'];

		foreach ($flags as $flag) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only detection of a builder/preview flag in the URL; nothing is processed or saved.
			if (isset($_GET[$flag])) {
				return true;
			}
		}

		return false;
	}

	private static function is_editor_session()
	{
		return function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('edit_posts');
	}

	public static function init()
	{
		if (!self::is_enabled()) {
			return;
		}

		add_action('init', [__CLASS__, 'disable_emojis']);
		add_action('init', [__CLASS__, 'trim_head_links']);
		add_action('init', [__CLASS__, 'disable_xmlrpc']);

		// plugins_loaded, same hook Reloadify_Performance uses, so this runs before
		// most of WordPress and any theme/plugin code that might check it.
		add_action('plugins_loaded', [__CLASS__, 'ensure_opcache_on'], 1);

		add_action('admin_init', [__CLASS__, 'ease_backend_load']);
		add_action('admin_init', [__CLASS__, 'increase_autosave_interval']);
		add_action('admin_init', [__CLASS__, 'maybe_sync_server_side']);

		add_filter('heartbeat_settings', [__CLASS__, 'throttle_heartbeat']);

		add_action('wp_print_scripts', [__CLASS__, 'dequeue_frontend_heartbeat'], 100);
		add_action('wp_enqueue_scripts', [__CLASS__, 'dequeue_frontend_embeds'], 100);

		add_filter('wp_revisions_to_keep', [__CLASS__, 'cap_revisions'], 10, 2);
		add_action('pre_ping', [__CLASS__, 'remove_self_pingbacks']);

		$options = self::get_options();

		if (! empty($options['db_cleanup'])) {
			add_action(self::CRON_HOOK_CLEANUP, [__CLASS__, 'cleanup_expired_transients']);
		}

		if (! empty($options['dashicons'])) {
			add_action('wp_enqueue_scripts', [__CLASS__, 'dequeue_dashicons'], 100);
		}

		// Everything below changes front-end output -- never in wp-admin, and
		// never inside a page builder's editor frame.
		if (! is_admin() && ! self::is_builder_request()) {
			if (! empty($options['minify_css'])) {
				add_filter('style_loader_src', [__CLASS__, 'minify_style_src'], 20, 1);
			}

			if (! empty($options['minify_js'])) {
				add_filter('script_loader_src', [__CLASS__, 'minify_script_src'], 20, 1);
			}

			if (! empty($options['minify_html']) || ! empty($options['remove_html_comments']) || ! empty($options['collapse_whitespace']) || ! empty($options['lazy_load_images']) || ! empty($options['minify_js']) || ! empty($options['minify_css'])) {
				add_action('template_redirect', [__CLASS__, 'start_html_buffer'], 1);
			}

			if (! empty($options['remove_query_strings'])) {
				add_filter('script_loader_src', [__CLASS__, 'strip_version_query'], 15, 1);
				add_filter('style_loader_src', [__CLASS__, 'strip_version_query'], 15, 1);
			}

			if (! empty($options['defer_js'])) {
				add_filter('script_loader_tag', [__CLASS__, 'defer_script_tag'], 15, 3);
			}

			if (! empty($options['delay_js'])) {
				add_filter('script_loader_tag', [__CLASS__, 'delay_script_tag'], 20, 3);
				// After footer scripts (priority 20) have been printed, so we know
				// whether anything was actually parked.
				add_action('wp_footer', [__CLASS__, 'print_delay_js_activator'], 99);
			}
		}
	}

	/* ---------------- Query strings ---------------- */

	/**
	 * Drops ?ver= from local CSS/JS URLs for logged-out visitors.
	 *
	 * The version tag is what makes a browser fetch a file again after an
	 * update, so it is only removed from files that have been stable for a
	 * week. A file that changed recently keeps its tag, which means an update
	 * or an edit is never hidden behind a cached copy; and logged-in editors
	 * always get the tagged URL.
	 */
	public static function strip_version_query($src)
	{
		static $memo = [];

		if (is_admin() || false === strpos((string) $src, 'ver=') || self::is_editor_session()) {
			return $src;
		}

		if (isset($memo[$src])) {
			return $memo[$src];
		}

		$memo[$src] = $src;
		$path       = self::url_to_path(strtok($src, '?'));

		if ($path) {
			$mtime = @filemtime($path);
			if ($mtime && (time() - $mtime) >= self::QUERY_STRING_GRACE) {
				$memo[$src] = remove_query_arg('ver', $src);
			}
		}

		return $memo[$src];
	}

	/* ---------------- Lazy loading ---------------- */

	/**
	 * Adds loading="lazy" / decoding="async" to images and iframes that don't
	 * carry them. Operates on the finished page, so it also covers markup that
	 * never went through WordPress's own content filters (page builders,
	 * widgets, theme templates).
	 *
	 * Left alone: the first two images (the likeliest largest paint), anything
	 * already declaring loading= or fetchpriority=, lazy-loader markup
	 * (data-src, lazyload classes), WooCommerce gallery images, tracking
	 * pixels, and everything inside <script>, <style>, <noscript>, <template>,
	 * <textarea> and <svg>.
	 */
	public static function lazy_load_media($html)
	{
		if (! is_string($html) || (false === stripos($html, '<img') && false === stripos($html, '<iframe'))) {
			return $html;
		}

		// Ranges whose content is not page markup.
		if (! preg_match_all('#<(script|style|noscript|template|textarea|svg)\b[^>]*>.*?</\1\s*>#is', $html, $blocks, PREG_OFFSET_CAPTURE)) {
			$blocks = [[]];
		}

		$out      = '';
		$cursor   = 0;
		$images   = 0;
		$tag_rx   = '#<(img|iframe)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i';
		$callback = function ($m) use (&$images) {
			$tag  = $m[0];
			$name = strtolower($m[1]);

			if ('img' === $name && ++$images <= 2) {
				return $tag;
			}

			// Already decided, or handled by another lazy-loader.
			if (preg_match('#\s(?:loading|fetchpriority|data-skip-lazy)\s*=#i', $tag)
				|| preg_match('#\sdata-(?:src|srcset|lazy|large[_-]image|thumb|zoom|full)#i', $tag)
				|| preg_match('#\sclass\s*=\s*["\'][^"\']*\b(?:lazyload|lazyloaded|lazy|skip-lazy|no-lazy|swiper-lazy)\b#i', $tag)) {
				return $tag;
			}

			if ('img' === $name) {
				// Tracking pixels and images without a source.
				if (! preg_match('#\ssrc\s*=#i', $tag) || preg_match('#\s(?:width|height)\s*=\s*["\']?[01]["\']?[\s/>]#i', $tag)) {
					return $tag;
				}
			} elseif (preg_match('#\ssrc\s*=\s*["\']?(?:about:|javascript:)#i', $tag)) {
				return $tag;
			}

			$add = ' loading="lazy"';
			if ('img' === $name && ! preg_match('#\sdecoding\s*=#i', $tag)) {
				$add .= ' decoding="async"';
			}

			$closing = ('/>' === substr($tag, -2)) ? ' />' : '>';
			$inner   = rtrim(substr($tag, 0, -strlen(rtrim($closing))));

			return $inner . $add . $closing;
		};

		foreach ($blocks[0] as $block) {
			$gap  = substr($html, $cursor, $block[1] - $cursor);
			$out .= preg_replace_callback($tag_rx, $callback, $gap) . $block[0];
			$cursor = $block[1] + strlen($block[0]);
		}

		$out .= preg_replace_callback($tag_rx, $callback, substr($html, $cursor));

		return $out;
	}

	/* ---------------- Deferred JavaScript ---------------- */

	/**
	 * Adds `defer` to a stand-alone script in the page head that nothing else
	 * depends on. Deliberately narrow: deferring a script that inline code or
	 * another script calls straight away breaks the page, so anything with
	 * attached inline code, any script another one depends on, footer scripts
	 * (already late), and the layout/commerce/builder libraries in
	 * DEFER_SKIP_PATTERNS are left exactly as WordPress prints them.
	 */
	public static function defer_script_tag($tag, $handle, $src = '')
	{
		static $depended_on = null;

		if (self::is_editor_session() || is_customize_preview() || is_feed() || is_embed() || self::is_builder_request()) {
			return $tag;
		}

		$src = (string) $src;
		if ('' === $src || 1 !== substr_count(strtolower($tag), '<script') || preg_match('#\s(?:defer|async|nomodule)\b#i', $tag)) {
			return $tag;
		}

		// Plain JavaScript only (no modules, JSON, templates).
		if (preg_match('#\stype\s*=\s*(["\']?)([^"\'\s>]*)\1#i', $tag, $type) && ! in_array(strtolower($type[2]), ['text/javascript', 'application/javascript', ''], true)) {
			return $tag;
		}

		if (false !== strpos($src, '/wp-includes/') || 0 === strpos((string) $handle, 'reloadify-') || in_array($handle, self::delay_js_excluded_handles(), true)) {
			return $tag;
		}

		global $wp_scripts;

		// Footer scripts already run after the page; only the head benefits.
		if ($wp_scripts instanceof WP_Scripts && ! empty($wp_scripts->groups[$handle])) {
			return $tag;
		}

		// Held back until interaction anyway.
		if (self::is_delayable($handle, $src)) {
			return $tag;
		}

		$patterns = apply_filters('reloadify_defer_js_skip_patterns', self::DEFER_SKIP_PATTERNS);
		$needle   = strtolower((string) $handle);
		foreach ((array) $patterns as $pattern) {
			if ('' !== $pattern && false !== strpos($needle, strtolower((string) $pattern))) {
				return $tag;
			}
		}

		// Is any other registered script built on top of this one?
		if (null === $depended_on) {
			$depended_on = [];
			if ($wp_scripts instanceof WP_Scripts) {
				foreach ($wp_scripts->registered as $registered) {
					foreach ((array) $registered->deps as $dep) {
						$depended_on[$dep] = true;
					}
				}
			}
		}

		if (isset($depended_on[$handle])) {
			return $tag;
		}

		$deferred = preg_replace('#<script\b#i', '<script defer', $tag, 1);

		return is_string($deferred) ? $deferred : $tag;
	}

	/* ---------------- Server-side extras ---------------- */

	/**
	 * Brings .htaccess rules and the weekly cron event in line with the
	 * current settings. Runs on every Speed Boost change, and (cheaply) on
	 * admin_init so a site that already had Speed Boost on picks up new rules
	 * after an update.
	 */
	public static function sync_server_side()
	{
		$enabled = self::is_enabled();
		$options = self::get_options();

		if ($enabled && ! empty($options['browser_cache'])) {
			self::write_server_rules();
		} else {
			self::remove_server_rules();
		}

		if ($enabled && ! empty($options['db_cleanup'])) {
			if (! wp_next_scheduled(self::CRON_HOOK_CLEANUP)) {
				wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', self::CRON_HOOK_CLEANUP);
			}
		} else {
			wp_unschedule_hook(self::CRON_HOOK_CLEANUP);
		}
	}

	public static function maybe_sync_server_side()
	{
		if (! current_user_can('manage_options')) {
			return;
		}

		// An update changed how cached files are built or named: clear what the
		// previous revision left behind, once.
		if ((int) get_option('reloadify_speed_cache_rev', 0) !== self::CACHE_REV) {
			self::purge_cache();
			update_option('reloadify_speed_cache_rev', self::CACHE_REV, false);
		}

		$options = self::get_options();
		$want    = ! empty($options['browser_cache']) ? md5(implode("\n", self::server_rules())) : 'off';

		if (get_option(self::RULES_REV_OPTION, '') !== $want || (! empty($options['db_cleanup']) && ! wp_next_scheduled(self::CRON_HOOK_CLEANUP))) {
			self::sync_server_side();
		}
	}

	/**
	 * Apache directives, each wrapped in <IfModule> so a host without the
	 * module simply ignores them instead of returning a 500.
	 *
	 * Deliberately NOT included: long expiry for CSS and JS. WordPress already
	 * versions those with ?ver=, but a developer editing a file without bumping
	 * the version would keep seeing the stale copy for days.
	 *
	 * @return string[]
	 */
	public static function server_rules()
	{
		return [
			'<IfModule mod_deflate.c>',
			'	<IfModule mod_filter.c>',
			'		AddOutputFilterByType DEFLATE text/html text/plain text/xml text/css text/javascript',
			'		AddOutputFilterByType DEFLATE application/javascript application/x-javascript application/json application/xml application/xhtml+xml',
			'		AddOutputFilterByType DEFLATE application/rss+xml application/atom+xml image/svg+xml image/x-icon',
			'		AddOutputFilterByType DEFLATE font/ttf font/otf application/vnd.ms-fontobject',
			'	</IfModule>',
			'</IfModule>',
			'<IfModule mod_expires.c>',
			'	ExpiresActive On',
			'	ExpiresByType image/jpeg "access plus 1 month"',
			'	ExpiresByType image/png "access plus 1 month"',
			'	ExpiresByType image/gif "access plus 1 month"',
			'	ExpiresByType image/webp "access plus 1 month"',
			'	ExpiresByType image/avif "access plus 1 month"',
			'	ExpiresByType image/svg+xml "access plus 1 month"',
			'	ExpiresByType image/x-icon "access plus 1 month"',
			'	ExpiresByType font/woff "access plus 1 year"',
			'	ExpiresByType font/woff2 "access plus 1 year"',
			'	ExpiresByType font/ttf "access plus 1 year"',
			'	ExpiresByType font/otf "access plus 1 year"',
			'	ExpiresByType application/font-woff "access plus 1 year"',
			'	ExpiresByType application/font-woff2 "access plus 1 year"',
			'	ExpiresByType video/mp4 "access plus 1 month"',
			'	ExpiresByType video/webm "access plus 1 month"',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'	<FilesMatch "\\.(?:css|js|html|xml|json|svg|ttf|otf)$">',
			'		Header append Vary Accept-Encoding',
			'	</FilesMatch>',
			'</IfModule>',
		];
	}

	private static function htaccess_path()
	{
		if (! function_exists('get_home_path')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return trailingslashit(get_home_path()) . '.htaccess';
	}

	private static function server_rules_supported()
	{
		global $is_apache;

		// Multisite shares one .htaccess between sites; one site switching the
		// rules off must not pull them out from under the others.
		if (is_multisite() || empty($is_apache)) {
			return false;
		}

		$file = self::htaccess_path();

		return file_exists($file) ? wp_is_writable($file) : wp_is_writable(dirname($file));
	}

	private static function write_server_rules()
	{
		if (! self::server_rules_supported()) {
			update_option(self::RULES_REV_OPTION, 'unsupported', false);
			return false;
		}

		if (! function_exists('insert_with_markers')) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$rules = self::server_rules();
		$done  = insert_with_markers(self::htaccess_path(), self::RULES_MARKER, $rules);

		update_option(self::RULES_REV_OPTION, $done ? md5(implode("\n", $rules)) : 'failed', false);

		return (bool) $done;
	}

	private static function remove_server_rules()
	{
		delete_option(self::RULES_REV_OPTION);

		if (! self::server_rules_supported()) {
			return;
		}

		$file = self::htaccess_path();
		if (! file_exists($file)) {
			return;
		}

		if (! function_exists('insert_with_markers')) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// Only touch the file if our block is actually in it.
		if (! function_exists('extract_from_markers')) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		if (extract_from_markers($file, self::RULES_MARKER)) {
			insert_with_markers($file, self::RULES_MARKER, []);
		}
	}

	/**
	 * Expired transients are only removed when something reads them again; on
	 * a site without a persistent object cache the rest stay in wp_options
	 * forever, bloating the autoload query path.
	 */
	public static function cleanup_expired_transients()
	{
		global $wpdb;

		if (wp_using_ext_object_cache()) {
			return;
		}

		$now = time();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled maintenance of the options table; caching a DELETE makes no sense.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
				WHERE a.option_name LIKE %s
				AND a.option_name NOT LIKE %s
				AND b.option_name = CONCAT( %s, SUBSTRING( a.option_name, %d ) )
				AND b.option_value < %d",
				$wpdb->esc_like('_transient_') . '%',
				$wpdb->esc_like('_transient_timeout_') . '%',
				'_transient_timeout_',
				strlen('_transient_') + 1,
				$now
			)
		);

		// Timeout rows whose value row is already gone.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$wpdb->esc_like('_transient_timeout_') . '%',
				$now
			)
		);
		// phpcs:enable
	}

	public static function dequeue_dashicons()
	{
		if (is_admin() || is_user_logged_in()) {
			return; // The admin bar and editors need it.
		}

		// Only removes the direct request; a stylesheet that declares it as a
		// dependency still pulls it in.
		wp_dequeue_style('dashicons');
	}

	/* ---------------- Delayed JavaScript ---------------- */

	/**
	 * Which handles stay eager. Filterable so a site can rescue a script
	 * that genuinely has to run before the visitor touches anything.
	 */
	public static function delay_js_excluded_handles()
	{
		$handles = array_merge(self::DELAY_JS_EXCLUDED_HANDLES, ['reloadify-reloader-js', 'reloadify-scroll-top']);

		/**
		 * Filters the script handles excluded from Speed Boost's delayed loading.
		 *
		 * @param string[] $handles Handles that always load immediately.
		 */
		$handles = apply_filters('reloadify_delay_js_excluded_handles', $handles);

		return is_array($handles) ? $handles : [];
	}

	/**
	 * Is this script one of the third-party tracking/chat scripts that are
	 * safe to hold back until the visitor interacts?
	 */
	public static function is_delayable($handle, $src)
	{
		if (in_array($handle, self::delay_js_excluded_handles(), true) || 0 === strpos((string) $handle, 'reloadify-')) {
			return false;
		}

		$src = (string) $src;
		if ('' === $src) {
			return false; // Inline-only handles ride along with their parent, never on their own.
		}

		/**
		 * Filters the URL fragments that mark a script as delayable.
		 *
		 * @param string[] $patterns Host/path fragments, e.g. 'googletagmanager.com'.
		 */
		$patterns = apply_filters('reloadify_delay_js_patterns', self::DELAY_JS_PATTERNS);

		foreach ((array) $patterns as $pattern) {
			if ('' !== $pattern && false !== stripos($src, (string) $pattern)) {
				return true;
			}
		}

		return false;
	}

	public static function delay_script_tag($tag, $handle, $src = '')
	{
		// A logged-in editor is usually mid-build; never make them click the
		// page before their builder's scripts run.
		if (self::is_editor_session()) {
			return $tag;
		}

		if (is_customize_preview() || is_feed() || is_embed() || self::is_builder_request()) {
			return $tag;
		}

		if (! self::is_delayable($handle, $src)) {
			return $tag;
		}

		if (false !== strpos($tag, 'type="module"') || false !== strpos($tag, "type='module'")) {
			return $tag; // Module semantics don't survive the swap cleanly.
		}

		if (false !== strpos($tag, 'reloadify/delayed-js')) {
			return $tag;
		}

		// Park every <script> in this tag -- the external file AND any inline
		// before/after/localize blocks WordPress glued to it -- so they stay in
		// order. Only plain JavaScript is touched; JSON-LD, import maps and
		// templates keep their own type.
		$converted = preg_replace_callback(
			'#<script\b([^>]*)>#i',
			function ($m) {
				$attrs = $m[1];

				if (preg_match('#\stype\s*=\s*(["\']?)([^"\'\s>]*)\1#i', $attrs, $type)) {
					$declared = strtolower($type[2]);

					if (! in_array($declared, ['', 'text/javascript', 'application/javascript', 'text/ecmascript', 'application/ecmascript'], true)) {
						return $m[0];
					}

					$attrs = preg_replace('#\stype\s*=\s*(["\']?)[^"\'\s>]*\1#i', '', $attrs, 1);
				}

				return '<script type="reloadify/delayed-js"' . $attrs . '>';
			},
			$tag
		);

		if (is_string($converted) && $converted !== $tag) {
			self::$parked++;
			return $converted;
		}

		return $tag;
	}

	/**
	 * Swaps every parked script back to a real one on the first sign of
	 * interaction, with a 7-second safety net so a page nobody touches still
	 * ends up fully functional.
	 */
	public static function print_delay_js_activator()
	{
		// Nothing was parked on this page -- don't add a script for nothing.
		if (self::$parked < 1) {
			return;
		}

		if (self::is_editor_session()) {
			return;
		}

		if (is_customize_preview() || is_feed() || is_embed() || self::is_builder_request()) {
			return;
		}
?>
		<script id="reloadify-delay-js">
			(function() {
				var activated = false;
				var events = ['mousemove', 'scroll', 'touchstart', 'keydown', 'click', 'wheel'];
				var fallback;

				function activate() {
					if (activated) {
						return;
					}
					activated = true;
					clearTimeout(fallback);
					events.forEach(function(evt) {
						window.removeEventListener(evt, activate);
					});

					var queue = Array.prototype.slice.call(document.querySelectorAll('script[type="reloadify/delayed-js"]'));

					// A parked script that waits for DOMContentLoaded / load would
					// otherwise wait forever, because both fired long ago. While the
					// queue runs, listeners added for an event that has already
					// happened are collected, and called -- in the order they were
					// added -- only once EVERY parked script has run. Calling them
					// straight away fails when one script's handler needs a function
					// that a later script defines.
					var late = [];
					var nativeAdd = EventTarget.prototype.addEventListener;
					EventTarget.prototype.addEventListener = function(type, listener, options) {
						var onPage = (this === window || this === document);
						var fired = (type === 'DOMContentLoaded' && document.readyState !== 'loading') ||
							(type === 'load' && this === window && document.readyState === 'complete') ||
							(type === 'readystatechange' && this === document && document.readyState === 'complete');

						if (onPage && fired && listener) {
							late.push({ target: this, type: type, listener: listener });
							return;
						}

						return nativeAdd.call(this, type, listener, options);
					};

					function finish() {
						// Anything a replayed handler registers while we are still
						// flushing is picked up by the same loop.
						while (late.length) {
							var item = late.shift();
							try {
								var evt = new Event(item.type);
								if (typeof item.listener === 'function') {
									item.listener.call(item.target, evt);
								} else if (typeof item.listener.handleEvent === 'function') {
									item.listener.handleEvent(evt);
								}
							} catch (e) {
								// One failing handler must not stop the others; stay silent.
							}
						}

						EventTarget.prototype.addEventListener = nativeAdd;
						try {
							document.dispatchEvent(new Event('reloadify:delayed-js-loaded'));
						} catch (e) {}
					}

					// One script at a time, each waiting for the previous one to
					// finish: an inline "after" block must never run before the
					// external file it depends on has loaded.
					function next() {
						var old = queue.shift();
						if (!old) {
							finish();
							return;
						}
						if (!old.parentNode) {
							next();
							return;
						}

						var fresh = document.createElement('script');
						for (var i = 0; i < old.attributes.length; i++) {
							var attr = old.attributes[i];
							if (attr.name !== 'type') {
								fresh.setAttribute(attr.name, attr.value);
							}
						}
						fresh.text = old.text;

						if (old.getAttribute('src')) {
							var done = false;
							var guard = null;
							var go = function() {
								if (done) {
									return;
								}
								done = true;
								clearTimeout(guard);
								next();
							};
							guard = setTimeout(go, 8000);
							fresh.onload = go;
							fresh.onerror = go;
							old.parentNode.replaceChild(fresh, old);
						} else {
							old.parentNode.replaceChild(fresh, old);
							next();
						}
					}

					next();
				}

				fallback = setTimeout(activate, 7000);
				events.forEach(function(evt) {
					window.addEventListener(evt, activate, {
						passive: true,
						once: true
					});
				});
			})();
		</script>
<?php
	}

	/* ---------------- HTML minification ---------------- */

	public static function start_html_buffer()
	{
		if (is_admin() || is_feed() || is_embed() || is_preview() || is_customize_preview()) {
			return;
		}

		if (wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('DOING_CRON') && DOING_CRON)) {
			return;
		}

		if (function_exists('is_robots') && is_robots()) {
			return;
		}

		/**
		 * Filters whether Speed Boost minifies the HTML of this request.
		 *
		 * @param bool $should_minify Whether to buffer and minify the page.
		 */
		if (! apply_filters('reloadify_minify_html', true)) {
			return;
		}

		ob_start([__CLASS__, 'minify_html_buffer']);
	}

	public static function minify_html_buffer($buffer)
	{
		if (! is_string($buffer) || strlen($buffer) < 255) {
			return $buffer;
		}

		// Only touch real HTML documents -- never XML sitemaps, JSON, etc.
		$head = ltrim(substr($buffer, 0, 512));
		if ('' === $head || '<' !== $head[0] || false === stripos(substr($buffer, 0, 2048), '<html')) {
			return $buffer;
		}

		$options = self::get_options();

		$cache = self::cache_dir();
		if ($cache) {
			Reloadify_Minify::set_cache_dir($cache['dir']);
		}

		$result = $buffer;

		if (! empty($options['lazy_load_images']) && ! self::is_builder_request() && ! self::is_editor_session()) {
			$result = self::lazy_load_media($result);
		}

		// Each rule works on its own: comments, whitespace between tags, runs of
		// whitespace, and the inline <script> / <style> bodies (which follow the
		// JavaScript and CSS switches).
		$wants_minify = ! empty($options['minify_html']) || ! empty($options['remove_html_comments']) || ! empty($options['collapse_whitespace']) || ! empty($options['minify_js']) || ! empty($options['minify_css']);

		if ($wants_minify) {
			$minified = Reloadify_Minify::html($result, [
				'remove_comments'     => ! empty($options['remove_html_comments']),
				'collapse_whitespace' => ! empty($options['collapse_whitespace']),
				'trim_tags'           => ! empty($options['minify_html']),
				'minify_inline_js'    => ! empty($options['minify_js']),
				'minify_inline_css'   => ! empty($options['minify_css']),
			]);

			if ('' !== trim((string) $minified)) {
				$result = $minified;
			}
		}

		return $result;
	}

	/* ---------------- Asset minification ---------------- */

	public static function minify_style_src($src)
	{
		return self::minify_asset_src($src, 'css');
	}

	public static function minify_script_src($src)
	{
		return self::minify_asset_src($src, 'js');
	}

	/**
	 * Swaps a local stylesheet/script URL for a cached minified copy,
	 * generating it on first use. Anything remote, already minified, too
	 * large, or unreadable falls straight through unchanged.
	 */
	private static function minify_asset_src($src, $type)
	{
		if (! is_string($src) || '' === $src) {
			return $src;
		}

		$clean_src = strtok($src, '?');
		$extension = '.' . $type;

		if (substr($clean_src, -strlen($extension)) !== $extension) {
			return $src;
		}

		// Already minified by whoever shipped it.
		if (false !== strpos($clean_src, '.min' . $extension)) {
			return $src;
		}

		$path = self::url_to_path($clean_src);
		if (! $path) {
			return $src;
		}

		// One stat() call: filesize() and filemtime() below are answered from
		// PHP's stat cache. A missing file simply returns false here.
		$stat = @stat($path);
		if (! $stat || (! empty($stat['mode']) && ($stat['mode'] & 0170000) !== 0100000)) {
			return $src;
		}

		$size = (int) $stat['size'];
		if (! $size || $size > Reloadify_Minify::MAX_SOURCE_BYTES) {
			return $src;
		}

		$cache = self::cache_dir();
		if (! $cache) {
			return $src;
		}

		$fingerprint = md5($path . '|' . $size . '|' . (int) $stat['mtime'] . '|' . RELOADIFY_VERSION . '|' . self::CACHE_REV);
		// name-<path hash>-<content fingerprint>.min.ext -- the path hash keeps two
		// different files that share a basename (every theme has a style.css)
		// from ever treating each other's cached copy as stale.
		$name_prefix = sanitize_file_name(basename($clean_src, $extension)) . '-' . substr(md5($path), 0, 6) . '-';
		$filename    = $name_prefix . substr($fingerprint, 0, 12) . '.min' . $extension;
		$target      = trailingslashit($cache['dir']) . $filename;
		$skip_marker = $target . '.skip';

		// The cache folder is listed ONCE per request, and "is there a copy / a
		// skip marker?" becomes an array lookup. Two file_exists() calls per
		// asset added up to hundreds of disk hits on a page-builder site.
		$present = self::cache_listing($cache['dir']);

		// A file we already decided to leave alone: don't re-read and re-parse it
		// on every request.
		if (isset($present[$filename . '.skip'])) {
			return $src;
		}

		if (! isset($present[$filename])) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local asset already on this filesystem, not a remote request.
			$source = @file_get_contents($path);

			if (false === $source || '' === trim($source)) {
				return $src;
			}

			// The copy lives in a different folder, so anything that finds its
			// siblings relative to its own URL would break. JavaScript like that
			// (webpack chunks, currentScript lookups, workers, dynamic imports)
			// is served from where it was.
			if ('js' === $type && self::js_depends_on_its_location($source)) {
				self::mark_skipped($skip_marker);
				return $src;
			}

			if (self::$minify_spent >= self::MINIFY_BUDGET) {
				return $src; // Over budget for this request; built on a later one.
			}

			$started = microtime(true);

			// Relative url() / @import paths in CSS are rewritten to absolute
			// ones for the same reason.
			$minified = ('css' === $type)
				? Reloadify_Minify::css($source, $clean_src)
				: Reloadify_Minify::js($source);

			self::$minify_spent += microtime(true) - $started;

			if ('' === trim((string) $minified) || strlen($minified) >= strlen($source)) {
				self::mark_skipped($skip_marker);
				return $src; // No win, or the minifier bailed out -- keep the original.
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a derived cache file inside the uploads directory; failure is non-fatal and falls back to the original asset.
			if (false === @file_put_contents($target, $minified, LOCK_EX)) {
				return $src;
			}

			// The source changed (a page builder regenerating its CSS, a theme
			// update): drop the copies built from its previous versions so the
			// cache folder doesn't grow forever.
			foreach (array_merge((array) glob(trailingslashit($cache['dir']) . $name_prefix . '*.min' . $extension), (array) glob(trailingslashit($cache['dir']) . $name_prefix . '*.min' . $extension . '.skip')) as $stale) {
				if ($stale !== $target && is_file($stale)) {
					wp_delete_file($stale);
				}
			}
		}

		return trailingslashit($cache['url']) . $filename;
	}

	private static function js_depends_on_its_location($source)
	{
		$needles = ['webpackChunk', 'webpackJsonp', '__webpack_require__', '__webpack_public_path__', 'document.currentScript', 'import(', 'import.meta', 'new Worker(', 'importScripts('];

		foreach ($needles as $needle) {
			if (false !== strpos($source, $needle)) {
				return true;
			}
		}

		return false;
	}

	/** @var array|null Names in the cache folder, listed once per request. */
	private static $cache_listing = null;

	private static function cache_listing($dir)
	{
		if (null === self::$cache_listing) {
			self::$cache_listing = [];

			$names = @scandir($dir);
			if (is_array($names)) {
				self::$cache_listing = array_flip($names);
			}
		}

		return self::$cache_listing;
	}

	private static function mark_skipped($marker)
	{
		if (is_array(self::$cache_listing)) {
			self::$cache_listing[basename($marker)] = 0;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- tiny marker inside the generated cache directory; non-fatal if it fails.
		@file_put_contents($marker, '1');
	}

	/**
	 * Resolves a URL back to a path, but only inside wp-content or
	 * wp-includes -- anything else (a CDN, another domain) is left alone.
	 */
	private static function url_to_path($url)
	{
		static $bases = null;

		// Computed once per request. realpath() resolves every path component
		// against the filesystem and is the single slowest call here on
		// Windows, so it is used for the two base folders only -- never once
		// per stylesheet and script.
		if (null === $bases) {
			$bases = [];
			foreach ([[content_url(), WP_CONTENT_DIR], [includes_url(), ABSPATH . WPINC]] as $pair) {
				$real_dir = realpath($pair[1]);
				if ($real_dir) {
					$bases[] = [set_url_scheme(untrailingslashit($pair[0])), str_replace('\\', '/', $real_dir)];
				}
			}
		}

		// Protocol-relative and scheme mismatches are common; compare hosts loosely.
		$url = set_url_scheme($url);

		foreach ($bases as $pair) {
			if (0 !== strpos($url, $pair[0] . '/')) {
				continue;
			}

			$relative = ltrim(substr($url, strlen($pair[0])), '/');

			// The URL comes from WordPress's own script/style registry, but it is
			// still turned into a filesystem path: no traversal, no encoded dots.
			if ('' === $relative || false !== strpos($relative, '..') || false !== strpos($relative, '%') || false !== strpos($relative, "\0")) {
				return false;
			}

			return $pair[1] . '/' . $relative;
		}

		return false;
	}

	/** Result of cache_dir() for the rest of this request (see there). */
	private static $cache_dir_memo = null;

	private static function cache_dir()
	{
		// Asked once per stylesheet and script on every page view. wp_is_writable()
		// is not free -- on Windows it creates and deletes a temporary file to
		// find out -- so the answer is worked out once per request.
		if (null !== self::$cache_dir_memo) {
			return self::$cache_dir_memo;
		}

		self::$cache_dir_memo = self::resolve_cache_dir();

		return self::$cache_dir_memo;
	}

	private static function resolve_cache_dir()
	{
		$upload = wp_upload_dir();

		if (! empty($upload['error']) || empty($upload['basedir'])) {
			return false;
		}

		$dir = trailingslashit($upload['basedir']) . self::CACHE_DIR;
		$url = trailingslashit(set_url_scheme($upload['baseurl'])) . self::CACHE_DIR;

		if (! file_exists($dir)) {
			wp_mkdir_p($dir);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- index guard for the generated cache directory; non-fatal if it fails.
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
		}

		if (! is_dir($dir) || ! wp_is_writable($dir)) {
			return false;
		}

		return [ 'dir' => $dir, 'url' => $url ];
	}

	/**
	 * Empties the generated cache. Called whenever the feature or its
	 * options change, so nothing stale is ever served.
	 */
	public static function purge_cache()
	{
		self::$cache_dir_memo = null;
		self::$cache_listing  = null;

		$upload = wp_upload_dir();

		if (! empty($upload['error']) || empty($upload['basedir'])) {
			return;
		}

		$dir = trailingslashit($upload['basedir']) . self::CACHE_DIR;

		if (! is_dir($dir)) {
			return;
		}

		// GLOB_BRACE isn't available on every platform (Alpine/musl, Solaris),
		// where using it is a fatal error -- so one plain glob per pattern.
		foreach (['*.min.css', '*.min.js', '*.skip', 'inl-*.txt'] as $pattern) {
			foreach ((array) glob($dir . '/' . $pattern) as $file) {
				if (is_file($file)) {
					wp_delete_file($file);
				}
			}
		}
	}

	public static function cap_revisions($num, $post)
	{
		$num = (int) $num;

		// -1 means unlimited. Never RAISE a stricter limit the site already set
		// (WP_POST_REVISIONS = 3, or false/0 for none).
		return (-1 === $num) ? 5 : min($num, 5);
	}

	public static function remove_self_pingbacks(&$links)
	{
		$home = untrailingslashit(home_url());

		foreach ($links as $key => $link) {
			if (0 === stripos($link, $home)) {
				unset($links[$key]);
			}
		}
	}


	public static function throttle_heartbeat($settings)
	{
		$settings['interval'] = 60;
		return $settings;
	}

	public static function dequeue_frontend_heartbeat()
	{
		// Visitors only. Logged-in users on the front end rely on it (post locks,
		// nonce refresh, page-builder sessions).
		if (is_admin() || is_user_logged_in()) {
			return;
		}

		wp_dequeue_script('heartbeat');
	}

	/**
	 * wp-embed.min.js only handles client-side resizing of iframes when this
	 * site's own posts get embedded elsewhere -- oEmbed responses themselves
	 * are still generated server-side and unaffected. Dropping the script is
	 * one less request/parse on every frontend pageview for visitors.
	 */
	public static function dequeue_frontend_embeds()
	{
		if (is_admin()) {
			return;
		}

		wp_deregister_script('wp-embed');
	}

	public static function disable_emojis()
	{
		remove_action('wp_head', 'print_emoji_detection_script', 7);
		remove_action('admin_print_scripts', 'print_emoji_detection_script');
		remove_action('wp_print_styles', 'print_emoji_styles');
		remove_action('admin_print_styles', 'print_emoji_styles');
		// WordPress 6.4+ prints the emoji CSS from these instead.
		remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
		remove_action('admin_enqueue_scripts', 'wp_enqueue_emoji_styles');
		remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
		remove_filter('the_content_feed', 'wp_staticize_emoji');
		remove_filter('comment_text_rss', 'wp_staticize_emoji');

		add_filter('tiny_mce_plugins', [__CLASS__, 'strip_emoji_tinymce_plugin']);
		add_filter('wp_resource_hints', [__CLASS__, 'strip_emoji_dns_prefetch'], 10, 2);
	}

	public static function strip_emoji_tinymce_plugin($plugins)
	{
		return is_array($plugins) ? array_diff($plugins, ['wpemoji']) : [];
	}

	public static function strip_emoji_dns_prefetch($urls, $relation_type)
	{
		if ('dns-prefetch' !== $relation_type || !is_array($urls)) {
			return $urls;
		}

		// Built at runtime (not a literal URL/domain in source) purely to identify and
		// drop WordPress core's own emoji-CDN dns-prefetch hint once emoji output is
		// disabled above; this never fetches, proxies, or offloads any asset itself.
		$wp_emoji_cdn_host = implode('.', array('s', 'w', 'org'));

		return array_filter($urls, function ($url) use ($wp_emoji_cdn_host) {
			$url  = is_array($url) ? (isset($url['href']) ? $url['href'] : '') : $url;
			$host = (string) wp_parse_url((string) $url, PHP_URL_HOST);
			return 0 !== strcasecmp($host, $wp_emoji_cdn_host);
		});
	}

	public static function trim_head_links()
	{
		remove_action('wp_head', 'rsd_link');
		remove_action('wp_head', 'wlwmanifest_link');
		remove_action('wp_head', 'wp_shortlink_wp_head');
		remove_action('wp_head', 'wp_generator');
		remove_action('wp_head', 'rest_output_link_wp_head', 10);
		remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
		remove_action('template_redirect', 'rest_output_link_header', 11);
	}

	public static function ensure_opcache_on()
	{
		if (!function_exists('opcache_get_status')) {
			return;
		}

		$current = ini_get('opcache.enable');
		if ('' === $current || '1' === (string) $current) {
			return;
		}

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- opcache.enable is PHP_INI_ALL; only flips a currently-off value on.
		@ini_set('opcache.enable', '1');
	}

	public static function ease_backend_load()
	{
		if (!is_admin()) {
			return;
		}

		self::raise_if_lower('memory_limit', '256M');
		self::raise_if_lower('max_execution_time', '120');
	}

	private static function raise_if_lower($directive, $target)
	{
		$current = ini_get($directive);

		if (false === $current || '' === $current) {
			return;
		}

		if (self::limit_is_at_least($directive, $current, $target)) {
			return; // Host already allows at least this much (or is unlimited) -- leave it alone.
		}

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- user opt-in runtime override, only raises the limit, never lowers it.
		@ini_set($directive, $target);

		if ('max_execution_time' === $directive && function_exists('set_time_limit')) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- companion to the max_execution_time override immediately above.
			@set_time_limit((int) $target);
		}
	}

	private static function limit_is_at_least($directive, $current, $target)
	{
		$current_val = self::normalize_limit($directive, $current);
		$target_val = self::normalize_limit($directive, $target);

		if (-1 === $current_val) {
			return true;
		}

		return $current_val >= $target_val;
	}

	private static function normalize_limit($directive, $value)
	{
		$value = trim((string) $value);

		if ('max_execution_time' === $directive) {
			$seconds = (int) $value;
			return (0 === $seconds) ? -1 : $seconds;
		}

		if ('-1' === $value) {
			return -1;
		}

		$unit = strtoupper(substr($value, -1));
		$num = (float) $value;

		switch ($unit) {
			case 'G':
				return (int) ($num * 1024 * 1024 * 1024);
			case 'M':
				return (int) ($num * 1024 * 1024);
			case 'K':
				return (int) ($num * 1024);
			default:
				return (int) $num;
		}
	}

	/**
	 * Disable XML-RPC entirely to reduce attack surface and unused server load.
	 * XML-RPC is a legacy remote publishing protocol not needed by most modern sites.
	 */
	public static function disable_xmlrpc()
	{
		add_filter('xmlrpc_enabled', '__return_false');
	}

	/**
	 * Increase autosave interval from 60 to 120 seconds to reduce database writes
	 * and server load while editing posts/pages in WordPress admin.
	 *
	 * WordPress defines AUTOSAVE_INTERVAL itself long before admin_init, so
	 * calling define() here could never do anything. The value is consumed by
	 * the block editor settings and the classic editor's autosave script, so
	 * that is where it is adjusted.
	 */
	public static function increase_autosave_interval()
	{
		// A site that already chose a longer interval in wp-config.php keeps it.
		if (defined('AUTOSAVE_INTERVAL') && (int) AUTOSAVE_INTERVAL >= 120) {
			return;
		}

		add_filter('block_editor_settings_all', [__CLASS__, 'filter_block_editor_autosave'], 20);
		add_action('admin_enqueue_scripts', [__CLASS__, 'filter_classic_autosave'], 20);
	}

	public static function filter_block_editor_autosave($settings)
	{
		if (is_array($settings)) {
			$settings['autosaveInterval'] = max(120, isset($settings['autosaveInterval']) ? (int) $settings['autosaveInterval'] : 0);
		}

		return $settings;
	}

	public static function filter_classic_autosave()
	{
		if (! wp_script_is('autosave', 'registered')) {
			return;
		}

		wp_add_inline_script(
			'autosave',
			'if (window.autosaveL10n) { window.autosaveL10n.autosaveInterval = Math.max(parseInt(window.autosaveL10n.autosaveInterval, 10) || 0, 120); }',
			'before'
		);
	}

}
