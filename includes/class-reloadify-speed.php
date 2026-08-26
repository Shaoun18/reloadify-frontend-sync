<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------- Speed Boost ---------------- */

class Reloadify_Speed
{

	const OPTION_KEY = 'reloadify_speed_boost_enabled';

	/**
	 * Enabled by default for every install (new or upgrading) unless the
	 * site owner has explicitly turned it off.
	 */
	public static function is_enabled()
	{
		return (bool) get_option(self::OPTION_KEY, true);
	}

	public static function set_enabled($enabled)
	{
		$enabled = (bool) $enabled;

		add_option(self::OPTION_KEY, true);
		update_option(self::OPTION_KEY, $enabled);

		return self::is_enabled();
	}

	/**
	 * What's actually included, exposed to the UI so the toggle isn't a
	 * black box — every line here is something the person can go verify.
	 */
	public static function items()
	{
		return [
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
				'label' => __('Caps the Heartbeat API to once every 60 seconds in wp-admin (instead of every 15\u201360s) and removes it from the frontend entirely for visitors — fewer background requests hitting the server on both sides', 'reloadify-frontend-sync'),
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

		add_filter('wp_revisions_to_keep', [__CLASS__, 'cap_revisions'], 10, 2);
		add_action('pre_ping', [__CLASS__, 'remove_self_pingbacks']);

		// Remove query strings from static resources
		add_filter('script_loader_src', [__CLASS__, 'remove_query_strings'], 15, 1);
		add_filter('style_loader_src', [__CLASS__, 'remove_query_strings'], 15, 1);
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

		return array_filter($urls, function ($url) {
			$url = is_array($url) ? (isset($url['href']) ? $url['href'] : '') : $url;
			return false === stripos((string) $url, 's.w.org');
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