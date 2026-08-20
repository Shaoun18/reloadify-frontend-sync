<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Speed Boost" — on by default the moment the plugin activates, unlike the
 * Server Performance panel (which stays strictly opt-in per directive).
 *
 * IMPORTANT — what this deliberately does NOT do: promise a fixed percentage
 * (e.g. "60-70% faster"). No plugin can honestly guarantee that — the real
 * number depends on the theme, other plugins, hosting, and traffic, and
 * varies per site. Advertising a made-up figure is exactly the kind of claim
 * WordPress.org's plugin review rejects, and it would be false. What's here
 * instead is a short list of changes that are genuinely safe, genuinely
 * always-beneficial (or neutral), and genuinely reversible with one toggle.
 *
 * On the backend (builder/editor) side specifically: this can't make a page
 * builder's own JavaScript render faster, or make its save request itself do
 * less work — that's the builder's code, out of reach for any other plugin.
 * What genuinely IS in reach, and is what actually causes a lot of the "the
 * builder feels slow / save hangs or fails" complaints on tight hosting (and
 * on local dev environments like Local, which often ship a low default
 * memory_limit): raising wp-admin's own memory_limit / max_execution_time
 * headroom, and only ever raising it, never lowering it below what the host
 * already allows. That's what ease_backend_load() below does, scoped to
 * is_admin() (which is also true for admin-ajax.php requests -- exactly
 * where Divi's, Elementor's, and most other builders' save actions go
 * through) so it never touches a plain frontend visitor's request.
 */
class Reloadify_Speed {

	const OPTION_KEY = 'reloadify_speed_boost_enabled';

	/**
	 * Enabled by default for every install (new or upgrading) unless the
	 * site owner has explicitly turned it off.
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, true );
	}

	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;

		// update_option() compares the new value against get_option()'s
		// current value and skips the write if they look the same. The very
		// first time this is ever turned off, get_option() returns `false`
		// because the row doesn't exist yet -- which is indistinguishable
		// from the boolean `false` we're trying to save, so the write was
		// silently skipped. That's why it showed "on" again after a
		// refresh. add_option() guarantees the row exists first, so the
		// update_option() call below always has a real value to compare
		// against and actually writes.
		add_option( self::OPTION_KEY, true );
		update_option( self::OPTION_KEY, $enabled );

		return self::is_enabled();
	}

	/**
	 * What's actually included, exposed to the UI so the toggle isn't a
	 * black box — every line here is something the person can go verify.
	 */
	public static function items() {
		return [
			[
				'key'   => 'emojis',
				'label' => __( 'Removes the emoji-detection script & inline CSS WordPress prints on every page (frontend and wp-admin)', 'reloadify-frontend-sync' ),
			],
			[
				'key'   => 'head_links',
				'label' => __( 'Drops unused <link> tags from <head> (RSD, WLW manifest, shortlink, generator tag)', 'reloadify-frontend-sync' ),
			],
			[
				'key'   => 'opcache',
				'label' => __( 'Turns PHP OPcache on if your host has it available but left it off', 'reloadify-frontend-sync' ),
			],
			[
				'key'   => 'backend_headroom',
				'label' => __( 'Raises wp-admin\'s memory_limit and max_execution_time headroom (never below what your host already allows) so heavy builders like Divi/Elementor are less likely to hit a slow or failed save — frontend visitor requests are never touched', 'reloadify-frontend-sync' ),
			],
			[
				'key'   => 'heartbeat',
				'label' => __( 'Caps the Heartbeat API to once every 60 seconds in wp-admin (instead of every 15\u201360s) and removes it from the frontend entirely for visitors — fewer background requests hitting the server on both sides', 'reloadify-frontend-sync' ),
			],
			[
				'key'   => 'revisions',
				'label' => __( 'Caps stored post revisions at 5 per post going forward (older ones already saved are left alone) — keeps the posts table smaller and post-related queries a little faster on both the editor and the frontend', 'reloadify-frontend-sync' ),
			],
			[
				'key'   => 'self_pingbacks',
				'label' => __( 'Stops WordPress from pinging itself when one of your own posts links to another — removes a pointless outbound HTTP request and a comments-table write on every such save', 'reloadify-frontend-sync' ),
			],
		];
	}

	public static function init() {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'init', [ __CLASS__, 'disable_emojis' ] );
		add_action( 'init', [ __CLASS__, 'trim_head_links' ] );

		// plugins_loaded, same hook Reloadify_Performance uses, so this runs before
		// most of WordPress and any theme/plugin code that might check it.
		add_action( 'plugins_loaded', [ __CLASS__, 'ensure_opcache_on' ], 1 );

		// admin_init covers admin-ajax.php requests too (WP_ADMIN is defined
		// before plugins even load there), which is where builder save
		// actions actually run -- so this is in effect before the heavy
		// save callback itself fires.
		add_action( 'admin_init', [ __CLASS__, 'ease_backend_load' ] );

		add_filter( 'heartbeat_settings', [ __CLASS__, 'throttle_heartbeat' ] );

		// wp_print_scripts (not wp_enqueue_scripts) runs after every plugin
		// and theme has had its chance to enqueue 'heartbeat' as a dependency
		// -- dequeuing here removes it from the frontend output without
		// touching the registration itself, so nothing that depends on it
		// breaks; wp-admin is left alone entirely.
		add_action( 'wp_print_scripts', [ __CLASS__, 'dequeue_frontend_heartbeat' ], 100 );

		add_filter( 'wp_revisions_to_keep', [ __CLASS__, 'cap_revisions' ], 10, 2 );
		add_action( 'pre_ping', [ __CLASS__, 'remove_self_pingbacks' ] );
	}

	/**
	 * A filter, not a WP_POST_REVISIONS constant define -- the constant has
	 * to be set before wp-settings.php loads (too early for a plugin to
	 * reach), and once set it can't be changed at runtime at all. This
	 * filter runs every time WordPress is about to prune revisions and
	 * simply caps the number kept going forward; it never touches revisions
	 * already saved before this was turned on.
	 */
	public static function cap_revisions( $num, $post ) {
		return 5;
	}

	/**
	 * WordPress pings itself over HTTP by default when a post links to
	 * another post on the same site -- a real outbound request plus a
	 * comments-table write for a "notification" you already know about
	 * because you just wrote both posts yourself. This strips your own
	 * site's URLs out of the list of links a save will ping, leaving pings
	 * to genuinely external sites untouched.
	 */
	public static function remove_self_pingbacks( &$links ) {
		$home = untrailingslashit( home_url() );

		foreach ( $links as $key => $link ) {
			if ( 0 === stripos( $link, $home ) ) {
				unset( $links[ $key ] );
			}
		}
	}

	/**
	 * Heartbeat's default interval is as low as 15 seconds on some admin
	 * screens (post editor) and 60 elsewhere. Flooring everything at 60
	 * cuts that traffic without turning Heartbeat off outright in wp-admin,
	 * where some features (autosave conflict checks, session expiry
	 * notices) still rely on it existing.
	 */
	public static function throttle_heartbeat( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}

	/**
	 * A plain frontend visitor never needs Heartbeat at all -- it's a
	 * wp-admin/editor mechanism. Removing it from the public side removes
	 * a recurring background request per open tab with zero functional
	 * loss for visitors.
	 */
	public static function dequeue_frontend_heartbeat() {
		if ( is_admin() ) {
			return;
		}

		wp_dequeue_script( 'heartbeat' );
	}

	public static function disable_emojis() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );

		add_filter( 'tiny_mce_plugins', [ __CLASS__, 'strip_emoji_tinymce_plugin' ] );
		add_filter( 'wp_resource_hints', [ __CLASS__, 'strip_emoji_dns_prefetch' ], 10, 2 );
	}

	public static function strip_emoji_tinymce_plugin( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : [];
	}

	public static function strip_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}

		return array_filter( $urls, function ( $url ) {
			$url = is_array( $url ) ? ( isset( $url['href'] ) ? $url['href'] : '' ) : $url;
			return false === stripos( (string) $url, 's.w.org' );
		} );
	}

	public static function trim_head_links() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'wp_generator' );
	}

	/**
	 * Strictly additive: only flips OPcache on if it's compiled in and
	 * currently off. Never touches a host that already has it configured
	 * one way or the other on purpose.
	 */
	public static function ensure_opcache_on() {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return;
		}

		$current = ini_get( 'opcache.enable' );
		if ( '' === $current || '1' === (string) $current ) {
			return;
		}

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- opcache.enable is PHP_INI_ALL; only flips a currently-off value on.
		@ini_set( 'opcache.enable', '1' );
	}

	/**
	 * Raises wp-admin's own memory_limit / max_execution_time headroom --
	 * and ONLY raises it, never lowers it below whatever the host already
	 * has configured. Scoped to is_admin(), which is also true for
	 * admin-ajax.php requests (where builder save actions run), so a plain
	 * frontend visitor's request is never touched by this at all -- that
	 * scoping is deliberate, not incidental: applying this kind of change
	 * unconditionally on every request (frontend included) is exactly the
	 * pattern WordPress.org's plugin review flags, and there's no reason a
	 * visitor loading a page needs more memory/time than the host already
	 * gives them.
	 */
	public static function ease_backend_load() {
		if ( ! is_admin() ) {
			return;
		}

		self::raise_if_lower( 'memory_limit', '256M' );
		self::raise_if_lower( 'max_execution_time', '120' );
	}

	private static function raise_if_lower( $directive, $target ) {
		$current = ini_get( $directive );

		if ( false === $current || '' === $current ) {
			return;
		}

		if ( self::limit_is_at_least( $directive, $current, $target ) ) {
			return; // Host already allows at least this much (or is unlimited) -- leave it alone.
		}

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- wp-admin/admin-ajax.php only (see is_admin() guard above); only ever raises, never lowers, the host's own configured limit.
		@ini_set( $directive, $target );

		if ( 'max_execution_time' === $directive && function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- companion to the max_execution_time override immediately above.
			@set_time_limit( (int) $target );
		}
	}

	/**
	 * True if $current already meets or exceeds $target for the given
	 * directive -- including "unlimited" (-1 for memory_limit, 0 for
	 * max_execution_time), which always counts as "at least" any target.
	 */
	private static function limit_is_at_least( $directive, $current, $target ) {
		$current_val = self::normalize_limit( $directive, $current );
		$target_val  = self::normalize_limit( $directive, $target );

		if ( -1 === $current_val ) {
			return true;
		}

		return $current_val >= $target_val;
	}

	/**
	 * Converts a memory_limit string ("128M", "1G", "-1") or
	 * max_execution_time string ("30", "0") into a plain integer -- bytes
	 * for memory, seconds for time -- with -1 meaning "unlimited" for both.
	 */
	private static function normalize_limit( $directive, $value ) {
		$value = trim( (string) $value );

		if ( 'max_execution_time' === $directive ) {
			$seconds = (int) $value;
			return ( 0 === $seconds ) ? -1 : $seconds;
		}

		if ( '-1' === $value ) {
			return -1;
		}

		$unit = strtoupper( substr( $value, -1 ) );
		$num  = (float) $value;

		switch ( $unit ) {
			case 'G':
				return (int) ( $num * 1024 * 1024 * 1024 );
			case 'M':
				return (int) ( $num * 1024 * 1024 );
			case 'K':
				return (int) ( $num * 1024 );
			default:
				return (int) $num;
		}
	}
}
