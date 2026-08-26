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
		return [ 'chrome', 'brave', 'edge', 'firefox', 'safari', 'opera', 'ucbrowser', 'vivaldi', 'yandex', 'samsung' ];
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
			'vivaldi'   => 'Vivaldi',
			'yandex'    => 'Yandex Browser',
			'samsung'   => 'Samsung Internet',
		];
	}

	/* ---------------- Settings ---------------- */

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

	/* ---------------- Timestamp file ---------------- */

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

		// FIXED: Check permissions BEFORE attempting to write anything
		if ( ! reloadify_path_is_writable( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '/.htaccess';
		
		// FIXED: Only write .htaccess if it doesn't exist to avoid conflicts
		if ( ! file_exists( $htaccess ) ) {
			$htaccess_content = "# Reloadify Frontend Sync - Cache control for timestamp detection\n";
			$htaccess_content .= "<IfModule mod_headers.c>\n";
			$htaccess_content .= "\tHeader set Cache-Control \"no-cache, no-store, must-revalidate\"\n";
			$htaccess_content .= "\tHeader set Pragma \"no-cache\"\n";
			$htaccess_content .= "\tHeader set Expires \"0\"\n";
			$htaccess_content .= "</IfModule>\n";
			$htaccess_content .= "<IfModule mod_expires.c>\n";
			$htaccess_content .= "\tExpiresActive On\n";
			$htaccess_content .= "\tExpiresDefault \"now\"\n";
			$htaccess_content .= "</IfModule>\n";
			
			// FIXED: Catch errors - don't crash if write fails
			$written = @file_put_contents( $htaccess, $htaccess_content, LOCK_EX );
			if ( false === $written ) {
				// Log error but don't interrupt the rest of the function
				error_log( '[Reloadify] Failed to write .htaccess in ' . $htaccess );
			}
		}

		// Timestamp file write (this is the critical part - must succeed)
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
