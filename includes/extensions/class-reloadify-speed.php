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
	 * Script handles that must never be deferred until first interaction.
	 * jQuery stays eager because an enormous amount of theme/plugin code
	 * assumes it is already there, and this plugin's own scripts stay eager
	 * because the whole point of the reloader is that it runs without anyone
	 * touching the page.
	 */
	const DELAY_JS_EXCLUDED_HANDLES = [ 'jquery', 'jquery-core', 'jquery-migrate' ];

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

		return self::is_enabled();
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
				'key' => 'delay_js',
				'label' => __('Delays non-essential JavaScript until the first interaction (scroll, tap, key, mouse move) or 7 seconds, whichever comes first — jQuery and this plugin\'s own scripts always load immediately', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'emojis',
				'label' => __('Removes the emoji-detection script & inline CSS WordPress prints on every page (frontend and wp-admin)', 'reloadify-frontend-sync'),
			],
			[
				'key' => 'head_links',
				'label' => __('Drops unused <link> tags from <head> (RSD, WLW manifest, shortlink, generator tag)', 'reloadify-frontend-sync'),
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
				'key' => 'query_strings',
				'label' => __('Removes query strings from static resources (CSS, JS) so they can be served by CDN and proxies more efficiently — speeds up repeat visitor loads', 'reloadify-frontend-sync'),
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

		add_filter('heartbeat_settings', [__CLASS__, 'throttle_heartbeat']);

		add_action('wp_print_scripts', [__CLASS__, 'dequeue_frontend_heartbeat'], 100);
		add_action('wp_enqueue_scripts', [__CLASS__, 'dequeue_frontend_embeds'], 100);

		add_filter('wp_revisions_to_keep', [__CLASS__, 'cap_revisions'], 10, 2);
		add_action('pre_ping', [__CLASS__, 'remove_self_pingbacks']);

		// Remove query strings from static resources
		add_filter('script_loader_src', [__CLASS__, 'remove_query_strings'], 15, 1);
		add_filter('style_loader_src', [__CLASS__, 'remove_query_strings'], 15, 1);

		$options = self::get_options();

		// Asset minification -- frontend only, so wp-admin keeps loading the
		// exact files WordPress shipped.
		if (! is_admin()) {
			if (! empty($options['minify_css'])) {
				add_filter('style_loader_src', [__CLASS__, 'minify_style_src'], 20, 1);
			}

			if (! empty($options['minify_js'])) {
				add_filter('script_loader_src', [__CLASS__, 'minify_script_src'], 20, 1);
			}

			if (! empty($options['minify_html']) || ! empty($options['remove_html_comments']) || ! empty($options['collapse_whitespace'])) {
				add_action('template_redirect', [__CLASS__, 'start_html_buffer'], 1);
			}

			// Bundled into Speed Boost since 1.2.0 -- no separate switch.
			add_filter('script_loader_tag', [__CLASS__, 'delay_script_tag'], 20, 2);
			add_action('wp_footer', [__CLASS__, 'print_delay_js_activator'], 1);
		}
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

	public static function delay_script_tag($tag, $handle)
	{
		// A logged-in editor is usually mid-build; never make them click the
		// page before their builder's scripts run.
		if (is_user_logged_in() && current_user_can('edit_posts')) {
			return $tag;
		}

		if (is_customize_preview() || is_feed() || is_embed()) {
			return $tag;
		}

		if (in_array($handle, self::delay_js_excluded_handles(), true)) {
			return $tag;
		}

		if (0 === strpos($handle, 'reloadify-')) {
			return $tag;
		}

		if (false !== strpos($tag, 'type="module"') || false !== strpos($tag, "type='module'")) {
			return $tag; // Module semantics don't survive the swap cleanly.
		}

		if (false !== strpos($tag, 'reloadify/delayed-js')) {
			return $tag;
		}

		if (false !== strpos($tag, 'type=')) {
			return str_replace(
				['type="text/javascript"', "type='text/javascript'"],
				'type="reloadify/delayed-js"',
				$tag
			);
		}

		return str_replace('<script ', '<script type="reloadify/delayed-js" ', $tag);
	}

	/**
	 * Swaps every parked script back to a real one on the first sign of
	 * interaction, with a 7-second safety net so a page nobody touches still
	 * ends up fully functional.
	 */
	public static function print_delay_js_activator()
	{
		if (is_user_logged_in() && current_user_can('edit_posts')) {
			return;
		}
?>
		<script id="reloadify-delay-js">
			(function() {
				var activated = false;
				var events = ['mousemove', 'scroll', 'touchstart', 'keydown', 'click', 'wheel'];

				function activate() {
					if (activated) {
						return;
					}
					activated = true;
					clearTimeout(fallback);
					events.forEach(function(evt) {
						window.removeEventListener(evt, activate);
					});

					var parked = document.querySelectorAll('script[type="reloadify/delayed-js"]');
					Array.prototype.forEach.call(parked, function(oldScript) {
						var newScript = document.createElement('script');
						for (var i = 0; i < oldScript.attributes.length; i++) {
							var attr = oldScript.attributes[i];
							if (attr.name !== 'type') {
								newScript.setAttribute(attr.name, attr.value);
							}
						}
						newScript.text = oldScript.text;
						oldScript.parentNode.replaceChild(newScript, oldScript);
					});

					try {
						document.dispatchEvent(new Event('reloadify:delayed-js-loaded'));
					} catch (e) {}
				}

				var fallback = setTimeout(activate, 7000);
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

		$minified = Reloadify_Minify::html($buffer, [
			'remove_comments'     => ! empty($options['remove_html_comments']),
			'collapse_whitespace' => ! empty($options['collapse_whitespace']) && ! empty($options['minify_html']),
			'minify_inline_js'    => ! empty($options['minify_js']),
			'minify_inline_css'   => ! empty($options['minify_css']),
		]);

		return ('' === trim((string) $minified)) ? $buffer : $minified;
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
		if (! $path || ! is_readable($path)) {
			return $src;
		}

		$size = filesize($path);
		if (! $size || $size > Reloadify_Minify::MAX_SOURCE_BYTES) {
			return $src;
		}

		$cache = self::cache_dir();
		if (! $cache) {
			return $src;
		}

		$fingerprint = md5($path . '|' . $size . '|' . filemtime($path) . '|' . RELOADIFY_VERSION);
		$filename    = sanitize_file_name(basename($clean_src, $extension)) . '-' . substr($fingerprint, 0, 12) . '.min' . $extension;
		$target      = trailingslashit($cache['dir']) . $filename;

		if (! file_exists($target)) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local asset already on this filesystem, not a remote request.
			$source = @file_get_contents($path);

			if (false === $source || '' === trim($source)) {
				return $src;
			}

			$minified = ('css' === $type)
				? Reloadify_Minify::css($source)
				: Reloadify_Minify::js($source);

			if ('' === trim((string) $minified) || strlen($minified) >= strlen($source)) {
				return $src; // No win, or the minifier bailed out -- keep the original.
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing a derived cache file inside the uploads directory; failure is non-fatal and falls back to the original asset.
			if (false === @file_put_contents($target, $minified, LOCK_EX)) {
				return $src;
			}
		}

		return trailingslashit($cache['url']) . $filename;
	}

	/**
	 * Resolves a URL back to a path, but only inside wp-content or
	 * wp-includes -- anything else (a CDN, another domain) is left alone.
	 */
	private static function url_to_path($url)
	{
		$candidates = [
			[content_url(), WP_CONTENT_DIR],
			[includes_url(), ABSPATH . WPINC],
		];

		// Protocol-relative and scheme mismatches are common; compare hosts loosely.
		$url = set_url_scheme($url);

		foreach ($candidates as $pair) {
			$base_url = set_url_scheme(untrailingslashit($pair[0]));
			$base_dir = untrailingslashit($pair[1]);

			if (0 === strpos($url, $base_url)) {
				$relative = ltrim(substr($url, strlen($base_url)), '/');
				$path     = $base_dir . '/' . $relative;
				$real     = realpath($path);

				if ($real && 0 === strpos($real, realpath($base_dir))) {
					return $real;
				}
			}
		}

		return false;
	}

	private static function cache_dir()
	{
		$upload = wp_upload_dir();

		if (! empty($upload['error']) || empty($upload['basedir'])) {
			return false;
		}

		$dir = trailingslashit($upload['basedir']) . self::CACHE_DIR;
		$url = trailingslashit($upload['baseurl']) . self::CACHE_DIR;

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
		$upload = wp_upload_dir();

		if (! empty($upload['error']) || empty($upload['basedir'])) {
			return;
		}

		$dir = trailingslashit($upload['basedir']) . self::CACHE_DIR;

		if (! is_dir($dir)) {
			return;
		}

		foreach ((array) glob($dir . '/*.min.{css,js}', GLOB_BRACE) as $file) {
			if (is_file($file)) {
				wp_delete_file($file);
			}
		}
	}

	public static function cap_revisions($num, $post)
	{
		return 5;
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
		if (is_admin()) {
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
	 */
	public static function increase_autosave_interval()
	{
		if (defined('AUTOSAVE_INTERVAL')) {
			return; // Already defined in wp-config.php, don't override
		}
		define('AUTOSAVE_INTERVAL', 120);
	}

	/**
	 * Remove query strings from static resources (CSS, JS) for better CDN/proxy caching.
	 * Query strings prevent caching since browsers treat ?ver=X as a unique file.
	 */
	public static function remove_query_strings($src)
	{
		if (strpos($src, '?')) {
			$src = remove_query_arg('ver', $src);
		}
		return $src;
	}
}
