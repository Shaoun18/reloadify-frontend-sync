<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reloadify_Settings {

	const OPTION_KEY = 'reloadify_settings';

	/**
	 * The full list of browsers the settings panel exposes.
	 */
	public static function supported_browsers() {
		return [ 'chrome', 'brave', 'edge', 'firefox', 'safari', 'opera', 'ucbrowser' ];
	}

	public static function browser_labels() {
		return [
			'chrome'    => 'Chrome',
			'brave'     => 'Brave',
			'edge'      => 'Edge',
			'firefox'   => 'Firefox',
			'safari'    => 'Safari',
			'opera'     => 'Opera',
			'ucbrowser' => 'UC Browser',
		];
	}

	/**
	 * Default state: browsers are pre-enabled so cross-browser reload works the
	 * moment Developer Mode is switched on -- but Developer Mode itself defaults
	 * OFF. It makes every visitor's browser poll the server repeatedly for as
	 * long as it's on; leaving that on by default was a real mistake that made
	 * a live site slow. It's a "turn on while you're actively testing, then
	 * turn off" tool, not an always-on default.
	 */
	public static function default_settings() {
		$browsers = [];

		foreach ( self::supported_browsers() as $browser ) {
			$browsers[ $browser ] = [
				'normal'    => true,
				'incognito' => true,
			];
		}

		return [
			'dev_mode_enabled'    => false,
			'dev_mode_enabled_at' => 0,
			'poll_interval'       => 2000,
			'reload_mode'         => 'soft', // 'soft' or 'hard'
			'browsers'            => $browsers,
		];
	}

	public static function activate() {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, self::default_settings() );
		}
		if ( false === get_option( 'reloadify_last_site_update', false ) ) {
			add_option( 'reloadify_last_site_update', time(), '', false );
		}
		if ( false === get_option( 'reloadify_delete_data_on_uninstall', false ) ) {
			add_option( 'reloadify_delete_data_on_uninstall', true );
		}
		self::write_timestamp_file( time() );
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, [] );
		return self::merge_with_defaults( is_array( $saved ) ? $saved : [] );
	}

	public static function update_settings( $incoming ) {
		$clean = self::sanitize( $incoming );

		// Stamp the moment Developer Mode is switched on (kept as plain info,
		// not used for any auto-off logic anymore). Switching it off (or
		// leaving it off) clears the stamp.
		$previous = self::get_settings();
		if ( $clean['dev_mode_enabled'] && ! $previous['dev_mode_enabled'] ) {
			$clean['dev_mode_enabled_at'] = time();
		} elseif ( ! $clean['dev_mode_enabled'] ) {
			$clean['dev_mode_enabled_at'] = 0;
		} else {
			$clean['dev_mode_enabled_at'] = $previous['dev_mode_enabled_at'];
		}

		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	private static function merge_with_defaults( $saved ) {
		$defaults = self::default_settings();
		$merged   = wp_parse_args( $saved, $defaults );

		$merged['browsers'] = isset( $saved['browsers'] ) && is_array( $saved['browsers'] )
			? array_merge( $defaults['browsers'], $saved['browsers'] )
			: $defaults['browsers'];

		foreach ( self::supported_browsers() as $browser ) {
			$row = isset( $merged['browsers'][ $browser ] ) ? $merged['browsers'][ $browser ] : [];
			$merged['browsers'][ $browser ] = [
				'normal'    => ! empty( $row['normal'] ),
				'incognito' => ! empty( $row['incognito'] ),
			];
		}

		$merged['dev_mode_enabled'] = ! empty( $merged['dev_mode_enabled'] );
		$merged['dev_mode_enabled_at'] = (int) ( isset( $merged['dev_mode_enabled_at'] ) ? $merged['dev_mode_enabled_at'] : 0 );
		$merged['poll_interval']    = max( 300, (int) $merged['poll_interval'] );
		$merged['reload_mode']      = in_array( $merged['reload_mode'], [ 'soft', 'hard' ], true ) ? $merged['reload_mode'] : 'soft';

		return $merged;
	}

	public static function sanitize( $incoming ) {
		$defaults = self::default_settings();

		$clean = [
			'dev_mode_enabled'    => ! empty( $incoming['dev_mode_enabled'] ),
			'dev_mode_enabled_at' => 0, // update_settings() fills this in with the correct stamp
			'poll_interval'       => isset( $incoming['poll_interval'] ) ? max( 300, (int) $incoming['poll_interval'] ) : $defaults['poll_interval'],
			'reload_mode'         => ( isset( $incoming['reload_mode'] ) && 'hard' === $incoming['reload_mode'] ) ? 'hard' : 'soft',
			'browsers'            => [],
		];

		$incoming_browsers = isset( $incoming['browsers'] ) && is_array( $incoming['browsers'] ) ? $incoming['browsers'] : [];

		foreach ( self::supported_browsers() as $browser ) {
			$row = isset( $incoming_browsers[ $browser ] ) ? $incoming_browsers[ $browser ] : [];
			$clean['browsers'][ $browser ] = [
				'normal'    => ! empty( $row['normal'] ),
				'incognito' => ! empty( $row['incognito'] ),
			];
		}

		return $clean;
	}

	/**
	 * Global "something changed" clock. Every save touches this instead of a
	 * per-post value, so any frontend tab -- whatever page it happens to be on --
	 * reloads the moment anything is saved in the builder.
	 */
	public static function bump_site_updated_at() {
		$ts = time();
		update_option( 'reloadify_last_site_update', $ts, false );
		self::write_timestamp_file( $ts );
	}

	public static function get_site_updated_at() {
		return (int) get_option( 'reloadify_last_site_update', time() );
	}

	/**
	 * Every poll originally hit admin-ajax.php, which boots the *entire* of
	 * WordPress (all active plugins included) just to compare two integers.
	 * At a couple of requests per second per open tab, across every visitor
	 * once Developer Mode is on, that's real load on a live site. A static
	 * JSON file in the uploads directory lets the frontend check for changes
	 * with a plain file fetch the webserver can answer directly -- no PHP,
	 * no WordPress bootstrap, effectively free. admin-ajax.php stays wired up
	 * only as a fallback for hosts where the uploads directory somehow isn't
	 * fetchable (see reloader.js).
	 */
	public static function write_timestamp_file( $ts ) {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return false;
		}

		$dir = trailingslashit( $upload['basedir'] ) . 'reloadify-reload';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}

		// Some hosts/CDNs apply far-future "cache everything static" rules that
		// don't care about query strings. If timestamp.json ever gets caught by
		// one of those, the frontend keeps polling a value that's stuck in the
		// past -- this file asks Apache (where supported) not to do that.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents(
				$htaccess,
				"<IfModule mod_headers.c>\n" .
				"\tHeader set Cache-Control \"no-cache, no-store, must-revalidate\"\n" .
				"\tHeader set Pragma \"no-cache\"\n" .
				"\tHeader set Expires 0\n" .
				"</IfModule>\n"
			);
		}

		if ( ! reloadify_path_is_writable( $dir ) ) {
			return false;
		}

		return false !== @file_put_contents( $dir . '/timestamp.json', wp_json_encode( [ 't' => $ts ] ), LOCK_EX );
	}

	public static function get_timestamp_file_url() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}
		return trailingslashit( $upload['baseurl'] ) . 'reloadify-reload/timestamp.json';
	}
}
