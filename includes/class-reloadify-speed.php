<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reloadify_Speed {

	const OPTION_KEY = 'reloadify_speed_boost_enabled';
	const DELAY_JS_OPTION_KEY = 'reloadify_delay_js_enabled';

	const DELAY_JS_EXCLUDED_HANDLES = [ 'jquery', 'jquery-core', 'jquery-migrate', 'reloadify-reloader-js', 'reloadify-scroll-top' ];

	public static function delay_js_enabled() {
		return (bool) get_option( self::DELAY_JS_OPTION_KEY, false );
	}

	public static function set_delay_js_enabled( $enabled ) {
		$enabled = (bool) $enabled;
		add_option( self::DELAY_JS_OPTION_KEY, false );
		update_option( self::DELAY_JS_OPTION_KEY, $enabled );
		return self::delay_js_enabled();
	}

	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, true );
	}

	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;

		add_option( self::OPTION_KEY, true );
		update_option( self::OPTION_KEY, $enabled );

		return self::is_enabled();
	}

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
		if ( self::is_enabled() ) {
			add_action( 'init', [ __CLASS__, 'disable_emojis' ] );
			add_action( 'init', [ __CLASS__, 'trim_head_links' ] );

			add_action( 'plugins_loaded', [ __CLASS__, 'ensure_opcache_on' ], 1 );

			add_action( 'admin_init', [ __CLASS__, 'ease_backend_load' ] );

			add_filter( 'heartbeat_settings', [ __CLASS__, 'throttle_heartbeat' ] );

			add_action( 'wp_print_scripts', [ __CLASS__, 'dequeue_frontend_heartbeat' ], 100 );

			add_filter( 'wp_revisions_to_keep', [ __CLASS__, 'cap_revisions' ], 10, 2 );
			add_action( 'pre_ping', [ __CLASS__, 'remove_self_pingbacks' ] );
		}

		if ( self::delay_js_enabled() && ! is_admin() ) {
			add_filter( 'script_loader_tag', [ __CLASS__, 'delay_script_tag' ], 10, 2 );
			add_action( 'wp_footer', [ __CLASS__, 'print_delay_js_activator' ], 1 );
		}
	}

	public static function delay_script_tag( $tag, $handle ) {
		if ( in_array( $handle, self::DELAY_JS_EXCLUDED_HANDLES, true ) ) {
			return $tag;
		}

		if ( 0 === strpos( $handle, 'reloadify-' ) ) {
			return $tag;
		}

		return str_replace( [ 'text/javascript', "type='text/javascript'" ], 'type="reloadify/delayed-js"', $tag );
	}

	public static function print_delay_js_activator() {
		?>
		<script>
		(function () {
			var activated = false;
			function activate() {
				if ( activated ) { return; }
				activated = true;
				document.querySelectorAll( 'script[type="reloadify/delayed-js"]' ).forEach( function ( oldScript ) {
					var newScript = document.createElement( 'script' );
					for ( var i = 0; i < oldScript.attributes.length; i++ ) {
						var attr = oldScript.attributes[ i ];
						if ( attr.name !== 'type' ) {
							newScript.setAttribute( attr.name, attr.value );
						}
					}
					newScript.text = oldScript.text;
					oldScript.parentNode.replaceChild( newScript, oldScript );
				} );
				[ 'mousemove', 'scroll', 'touchstart', 'keydown', 'click' ].forEach( function ( evt ) {
					window.removeEventListener( evt, activate, { passive: true } );
				} );
				clearTimeout( fallback );
			}
			var fallback = setTimeout( activate, 7000 );
			[ 'mousemove', 'scroll', 'touchstart', 'keydown', 'click' ].forEach( function ( evt ) {
				window.addEventListener( evt, activate, { passive: true, once: true } );
			} );
		})();
		</script>
		<?php
	}

	public static function cap_revisions( $num, $post ) {
		return 5;
	}

	public static function remove_self_pingbacks( &$links ) {
		$home = untrailingslashit( home_url() );

		foreach ( $links as $key => $link ) {
			if ( 0 === stripos( $link, $home ) ) {
				unset( $links[ $key ] );
			}
		}
	}

	public static function throttle_heartbeat( $settings ) {
		$settings['interval'] = 60;
		return $settings;
	}

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
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading -- Filters WordPress emoji CDN URLs, not offloading content.
			return false === stripos( (string) $url, 's.w.org' );
		} );
	}

	public static function trim_head_links() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'wp_generator' );
	}

	public static function ensure_opcache_on() {
		if ( ! function_exists( 'opcache_get_status' ) ) {
			return;
		}

		$current = ini_get( 'opcache.enable' );
		if ( '' === $current || '1' === (string) $current ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Only flips OPcache on when it's already installed but disabled; no other opcache setting is touched.
		@ini_set( 'opcache.enable', '1' );
	}

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
			return;
		}

		// phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Admin-screen-only, raises the limit no higher than the fixed target above, never lowers it.
		@ini_set( $directive, $target );

		if ( 'max_execution_time' === $directive && function_exists( 'set_time_limit' ) ) {

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit, Squiz.PHP.DiscouragedFunctions.Discouraged -- Admin-screen-only raise, capped at the fixed target above.
			@set_time_limit( (int) $target );
		}
	}

	private static function limit_is_at_least( $directive, $current, $target ) {
		$current_val = self::normalize_limit( $directive, $current );
		$target_val  = self::normalize_limit( $directive, $target );

		if ( -1 === $current_val ) {
			return true;
		}

		return $current_val >= $target_val;
	}

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
