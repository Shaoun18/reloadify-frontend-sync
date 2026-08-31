<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------- Performance Boost ---------------- */

class Reloadify_Performance {

	const OPTION_KEY = 'reloadify_performance';

	public static function directive_map() {
		return [
			'memory_limit'                     => [ 'runtime' => true,  'default' => '512M' ],
			'max_execution_time'               => [ 'runtime' => true,  'default' => '300' ],
			'opcache.enable'                   => [ 'runtime' => true,  'default' => '1' ],
			'opcache.validate_timestamps'      => [ 'runtime' => true,  'default' => '0' ],
			'opcache.revalidate_freq'          => [ 'runtime' => true,  'default' => '0' ],
			'max_input_time'                   => [ 'runtime' => false, 'default' => '300' ],
			'post_max_size'                    => [ 'runtime' => false, 'default' => '256M' ],
			'upload_max_filesize'              => [ 'runtime' => false, 'default' => '256M' ],
			'opcache.memory_consumption'       => [ 'runtime' => false, 'default' => '512' ],
			'opcache.interned_strings_buffer'  => [ 'runtime' => false, 'default' => '16' ],
			'opcache.max_accelerated_files'    => [ 'runtime' => false, 'default' => '20000' ],
			'realpath_cache_size'              => [ 'runtime' => false, 'default' => '4096K' ],
			'realpath_cache_ttl'               => [ 'runtime' => false, 'default' => '600' ],
		];
	}

	public static function default_settings() {
		$settings = [
			'runtime_enabled' => [], // one entry per directive the map marks as runtime-capable
			'desired' => [],
		];

		foreach ( self::directive_map() as $key => $info ) {
			if ( $info['runtime'] ) {
				$settings['runtime_enabled'][ $key ] = false;
			}

			// Prefer what this server is actually running right now. Only fall back
			// to a generic demo value if ini_get() has nothing to report.
			$live = ini_get( $key );
			$settings['desired'][ $key ] = ( false !== $live && '' !== $live ) ? $live : $info['default'];
		}

		return $settings;
	}

	/**
	 * Re-reads live server values into "desired", discarding anything the user
	 * had typed but not saved. Used by the "Sync from server" action.
	 */
	public static function sync_desired_with_live() {
		$current = self::get_settings();

		foreach ( self::directive_map() as $key => $info ) {
			$live = ini_get( $key );
			$current['desired'][ $key ] = ( false !== $live && '' !== $live ) ? $live : $info['default'];
		}

		update_option( self::OPTION_KEY, $current );
		return $current;
	}

	public static function get_settings() {
		$saved    = get_option( self::OPTION_KEY, [] );
		$saved    = is_array( $saved ) ? $saved : [];
		$map      = self::directive_map();

		// Build static defaults from the directive map, not from live ini_get() values.
		// This ensures saved "desired" values persist instead of being overwritten
		// by the current live server state every time the page loads.
		$static_defaults = [
			'runtime_enabled' => [],
			'desired' => [],
		];

		foreach ( $map as $key => $info ) {
			if ( $info['runtime'] ) {
				$static_defaults['runtime_enabled'][ $key ] = false;
			}
			$static_defaults['desired'][ $key ] = $info['default'];
		}

		// Merge saved settings on top of static defaults. This preserves user-saved
		// custom values while filling in any missing keys with the hard-coded defaults.
		$merged = [
			'runtime_enabled' => isset( $saved['runtime_enabled'] ) && is_array( $saved['runtime_enabled'] )
				? array_merge( $static_defaults['runtime_enabled'], $saved['runtime_enabled'] )
				: $static_defaults['runtime_enabled'],
			'desired' => isset( $saved['desired'] ) && is_array( $saved['desired'] )
				? array_merge( $static_defaults['desired'], $saved['desired'] )
				: $static_defaults['desired'],
		];

		return $merged;
	}

	public static function update_settings( $incoming ) {
		$clean = self::sanitize( $incoming );
		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	public static function sanitize( $incoming ) {
		$defaults = self::default_settings();
		$map      = self::directive_map();

		$clean = [
			'runtime_enabled' => [],
			'desired' => [],
		];

		foreach ( $map as $key => $info ) {
			if ( $info['runtime'] ) {
				$clean['runtime_enabled'][ $key ] = ! empty( $incoming['runtime_enabled'][ $key ] );
			}
		}

		$incoming_desired = isset( $incoming['desired'] ) && is_array( $incoming['desired'] ) ? $incoming['desired'] : [];

		foreach ( $map as $key => $info ) {
			$value = isset( $incoming_desired[ $key ] ) ? sanitize_text_field( (string) $incoming_desired[ $key ] ) : $defaults['desired'][ $key ];
			$clean['desired'][ $key ] = '' !== $value ? $value : $defaults['desired'][ $key ];
		}

		return $clean;
	}

	/**
	 * Directives .user.ini / .htaccess can genuinely carry (per-directory PHP
	 * config). realpath_cache_* and opcache.* are handled separately below --
	 * they're PHP_INI_SYSTEM, which .user.ini and .htaccess cannot reach at
	 * all, no matter how the write itself is phrased. The only file that can
	 * ever affect them is the real, loaded php.ini itself.
	 */
	const AUTO_WRITE_KEYS = [ 'max_input_time', 'post_max_size', 'upload_max_filesize' ];

	/**
	 * Of those, the subset Apache's mod_php actually allows via .htaccess
	 * "php_value".
	 */
	const HTACCESS_KEYS = [ 'max_input_time', 'post_max_size', 'upload_max_filesize' ];

	/**
	 * PHP_INI_SYSTEM directives: locked in when PHP itself starts, before
	 * .user.ini or .htaccess are ever read. The real php.ini (plus a PHP
	 * restart) is the only file capable of changing any of these -- that's
	 * why they all live behind the danger-zone confirmation together.
	 */
	const REAL_INI_KEYS = [ 'opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files', 'realpath_cache_size', 'realpath_cache_ttl' ];

	const MARKER = 'Reloadify Frontend Sync';


	public static function attempt_server_override( $desired ) {
		$results = [
			'user_ini'  => self::write_user_ini( $desired ),
			'htaccess'  => self::write_htaccess( $desired ),
		];

		return $results;
	}

	/* ---------------- DANGER ZONE ---------------- */

	public static function attempt_opcache_override( $desired, $confirmed ) {
		if ( true !== $confirmed ) {
			return [
				'success' => false,
				'path'    => '',
				'message' => __( 'Not attempted — the confirmation was missing.', 'reloadify-frontend-sync' ),
			];
		}

		$path = php_ini_loaded_file();

		if ( false === $path ) {
			return [
				'success' => false,
				'path'    => '',
				'message' => __( 'PHP isn\'t using a php.ini file at all on this server (php_ini_loaded_file() returned nothing), so there\'s nothing to write to.', 'reloadify-frontend-sync' ),
			];
		}

		if ( ! reloadify_path_is_writable( $path ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'This php.ini is not writable by PHP. On a live/shared/managed host that\'s expected and correct — it means this action simply can\'t do anything here, safely.', 'reloadify-frontend-sync' ),
			];
		}

		$existing = @file_get_contents( $path );
		$existing = false !== $existing ? $existing : '';

		// Always back up before touching the real php.ini, and abort if the
		// backup itself can't be written -- never edit without one in hand.
		$backup_path = $path . '.reloadify-backup-' . time() . '.bak';
		$backup_written = @file_put_contents( $backup_path, $existing, LOCK_EX );
		if ( false === $backup_written ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'Aborted before making any changes: couldn\'t write a backup copy first.', 'reloadify-frontend-sync' ),
			];
		}

		$lines = [];
		foreach ( self::REAL_INI_KEYS as $key ) {
			if ( isset( $desired[ $key ] ) && '' !== $desired[ $key ] ) {
				$lines[] = $key . '=' . $desired[ $key ];
			}
		}

		if ( empty( $lines ) ) {
			return [
				'success'     => false,
				'path'        => $path,
				'message'     => __( 'No values to write. Did you set values for opcache.memory_consumption, opcache.interned_strings_buffer, opcache.max_accelerated_files, realpath_cache_size, and/or realpath_cache_ttl?', 'reloadify-frontend-sync' ),
				'backup_path' => $backup_path,
			];
		}

		$block = "; BEGIN " . self::MARKER . " (opcache)\n" . implode( "\n", $lines ) . "\n; END " . self::MARKER . " (opcache)";

		$pattern = '/; BEGIN ' . preg_quote( self::MARKER, '/' ) . ' \(opcache\).*?; END ' . preg_quote( self::MARKER, '/' ) . ' \(opcache\)/s';

		$new_content = preg_match( $pattern, $existing )
			? preg_replace( $pattern, $block, $existing )
			: rtrim( $existing ) . "\n\n" . $block . "\n";

		$write_result = @file_put_contents( $path, $new_content, LOCK_EX );
		if ( false === $write_result || 0 === $write_result ) {
			return [
				'success'     => false,
				'path'        => $path,
				'message'     => __( 'Write failed (file_put_contents returned 0 or false). The backup was created and left in place. Check permissions and disk space.', 'reloadify-frontend-sync' ),
				'backup_path' => $backup_path,
			];
		}

		return [
			'success'     => true,
			'path'        => $path,
			'backup_path' => $backup_path,
			'message'     => __( 'Written to the real php.ini. This does NOT take effect until PHP itself restarts — this plugin cannot do that for you (restart PHP-FPM/Apache, or just restart your local dev server). If anything breaks, restore the backup file listed above and restart PHP again.', 'reloadify-frontend-sync' ),
		];
	}

	private static function write_user_ini( $desired ) {
		$path = trailingslashit( ABSPATH ) . '.user.ini';

		$lines = [];
		foreach ( self::AUTO_WRITE_KEYS as $key ) {
			if ( ! empty( $desired[ $key ] ) ) {
				$lines[] = $key . '=' . $desired[ $key ];
			}
		}
		$block = "; BEGIN " . self::MARKER . "\n" . implode( "\n", $lines ) . "\n; END " . self::MARKER;

		$existing = file_exists( $path ) ? @file_get_contents( $path ) : '';
		$existing = false !== $existing ? $existing : '';

		$pattern = '/; BEGIN ' . preg_quote( self::MARKER, '/' ) . '.*?; END ' . preg_quote( self::MARKER, '/' ) . '/s';

		if ( preg_match( $pattern, $existing ) ) {
			$new_content = preg_replace( $pattern, $block, $existing );
		} else {
			$new_content = rtrim( $existing ) . "\n\n" . $block . "\n";
		}

		if ( ! reloadify_path_is_writable( dirname( $path ) ) || ( file_exists( $path ) && ! reloadify_path_is_writable( $path ) ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'The WordPress root directory (or the existing .user.ini file) is not writable by PHP, so nothing was changed.', 'reloadify-frontend-sync' ),
			];
		}

		// .user.ini must live in the WordPress root; wp_upload_dir() is not applicable here.
		// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- Server config file required at ABSPATH.
		$written = @file_put_contents( $path, ltrim( $new_content ), LOCK_EX );

		if ( false === $written ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'Write failed even though the file appeared writable. Check server error logs.', 'reloadify-frontend-sync' ),
			];
		}

		return [
			'success' => true,
			'path'    => $path,
			'message' => __( 'Written. Most PHP-FPM hosts re-read .user.ini every 300 seconds (user_ini.cache_ttl), so give it a few minutes and confirm with "Sync from server". Some hosts ignore .user.ini entirely — if the live value never changes, that\'s why.', 'reloadify-frontend-sync' ),
		];
	}

	/**
	 * FIXED: Detect if server is Apache with mod_php
	 * This prevents trying to write .htaccess on servers that don't support it
	 */
	private static function is_apache_mod_php() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only server identity check, not stored or output.
		$server_software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';
		
		// Must have "apache" in SERVER_SOFTWARE
		if ( false === strpos( $server_software, 'apache' ) ) {
			return false;
		}

		// Must have Apache SAPI (php_sapi_name contains 'apache')
		$sapi = php_sapi_name();
		if ( false === strpos( $sapi, 'apache' ) ) {
			return false;
		}

		return true;
	}

	private static function write_htaccess( $desired ) {
		$path = trailingslashit( ABSPATH ) . '.htaccess';

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		// FIXED: Validate server type FIRST
		if ( ! self::is_apache_mod_php() ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'This server doesn\'t appear to be running Apache with mod_php (detected via SERVER_SOFTWARE). .htaccess modifications only work on Apache with mod_php. Most managed hosts use Nginx or PHP-FPM now. Contact your host to confirm your setup.', 'reloadify-frontend-sync' ),
			];
		}

		$lines = [];
		foreach ( self::HTACCESS_KEYS as $key ) {
			if ( ! empty( $desired[ $key ] ) ) {
				$lines[] = 'php_value ' . $key . ' ' . $desired[ $key ];
			}
		}

		if ( empty( $lines ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'No PHP configuration values to write to .htaccess.', 'reloadify-frontend-sync' ),
			];
		}

		if ( ! file_exists( $path ) && ! reloadify_path_is_writable( dirname( $path ) ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'No .htaccess file exists and the WordPress root isn\'t writable, so one couldn\'t be created.', 'reloadify-frontend-sync' ),
			];
		}

		if ( file_exists( $path ) && ! reloadify_path_is_writable( $path ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( '.htaccess exists but isn\'t writable by PHP, so nothing was changed.', 'reloadify-frontend-sync' ),
			];
		}

		// FIXED: Create backup BEFORE modifying existing .htaccess
		$backup_path = '';
		if ( file_exists( $path ) ) {
			$backup_path = $path . '.reloadify-backup-' . time() . '.bak';
			// phpcs:ignore PluginCheck.CodeAnalysis.WriteFile.ABSPATHDetected -- .htaccess and its backup must live next to the original file in the web root; wp_upload_dir() is not a valid Apache config location.
			if ( ! @copy( $path, $backup_path ) ) {
				return [
					'success' => false,
					'path'    => $path,
					'message' => __( 'Couldn\'t create a backup of the existing .htaccess before modifying it. Aborted to prevent data loss.', 'reloadify-frontend-sync' ),
				];
			}
		}

		// Attempt to write
		$result = insert_with_markers( $path, self::MARKER, $lines );

		if ( ! $result ) {
			// FIXED: More detailed error handling
			if ( ! file_exists( $path ) ) {
				$error_msg = __( 'Write failed: .htaccess couldn\'t be created. Check file permissions and disk space.', 'reloadify-frontend-sync' );
			} else {
				$current_content = @file_get_contents( $path );
				if ( $current_content === false ) {
					$error_msg = __( 'Write failed: Can\'t read back the .htaccess file after writing. This usually means a permissions issue.', 'reloadify-frontend-sync' );
				} else {
					$error_msg = __( 'Write failed: insert_with_markers() returned false. The .htaccess file may have invalid syntax or Apache rejected the changes. Check server error logs.', 'reloadify-frontend-sync' );
				}
			}

			return [
				'success' => false,
				'path'    => $path,
				'message' => $error_msg,
				'backup_path' => $backup_path,
			];
		}

		// FIXED: Validate the write actually happened
		if ( ! file_exists( $path ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'Write operation completed but .htaccess file doesn\'t exist afterward. Unknown error.', 'reloadify-frontend-sync' ),
				'backup_path' => $backup_path,
			];
		}

		return [
			'success' => true,
			'path'    => $path,
			'message' => __( 'Written to .htaccess successfully. This only takes effect on Apache with mod_php. If you\'re on Nginx or PHP-FPM and still see errors, your host doesn\'t support .htaccess modifications (which is normal).', 'reloadify-frontend-sync' ),
			'backup_path' => $backup_path,
		];
	}

	/**
	 * Exposed to the UI so the danger-zone warning can show exactly which file
	 * would be touched, instead of asking for blind trust.
	 */
	public static function get_php_ini_path() {
		$path = php_ini_loaded_file();
		return false !== $path ? $path : '';
	}

	/**
	 * Reads what PHP is *actually* running with right now, for comparison in the UI.
	 */
	public static function get_live_values() {
		$live = [];
		foreach ( array_keys( self::directive_map() ) as $key ) {
			$live[ $key ] = ini_get( $key );
		}
		return $live;
	}

	/**
	 * Applies every directive the map marks as genuinely runtime-capable
	 * (memory_limit, max_execution_time, and opcache.enable,
	 * opcache.validate_timestamps, opcache.revalidate_freq — all PHP_INI_ALL).
	 * Hooked as early as possible so it affects the rest of the request.
	 */
	public static function apply_runtime_overrides() {
		$settings = self::get_settings();

		foreach ( self::directive_map() as $key => $info ) {
			if ( ! $info['runtime'] ) {
				continue;
			}

			if ( empty( $settings['runtime_enabled'][ $key ] ) || '' === $settings['desired'][ $key ] ) {
				continue;
			}

			$value = $settings['desired'][ $key ];

			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- runtime override is the plugin's core Speed Boost feature; user opt-in, not a fixed setting.
			@ini_set( $key, $value );

			if ( 'max_execution_time' === $key && function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- companion to the max_execution_time override immediately above.
				@set_time_limit( (int) $value );
			}
		}
	}
}
