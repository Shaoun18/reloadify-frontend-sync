<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * IMPORTANT — read before touching this file.
 *
 * A WordPress plugin runs *after* PHP has already started handling the request,
 * so it can only change directives that PHP classifies as PHP_INI_ALL / PHP_INI_USER
 * (changeable with ini_set() at runtime). Directives that PHP classifies as
 * PHP_INI_PERDIR or PHP_INI_SYSTEM are locked in before WordPress ever loads —
 * no plugin, on any host, can change them with ini_set().
 *
 * Runtime-overridable from here (PHP_INI_ALL): memory_limit, max_execution_time,
 * and — despite common assumption — opcache.enable, opcache.validate_timestamps,
 * and opcache.revalidate_freq too. Those three are a per-request check, not a
 * startup-fixed allocation, so the PHP manual lists them as PHP_INI_ALL.
 *
 * NOT overridable at runtime, ever, from a plugin (PHP_INI_PERDIR / PHP_INI_SYSTEM):
 * post_max_size, upload_max_filesize, max_input_time, realpath_cache_size,
 * realpath_cache_ttl, and specifically opcache.memory_consumption,
 * opcache.interned_strings_buffer, opcache.max_accelerated_files — those three
 * size OPcache's shared-memory pool once at PHP startup, before any plugin runs.
 *
 * For the truly-locked group, the honest and useful thing this plugin can do is
 * show the *current* live value, let the user record a *desired* value, and
 * either (a) attempt a best-effort .user.ini/.htaccess write for the ones that
 * mechanism can reach, or (b) generate a ready-to-paste php.ini snippet. It must
 * never claim to silently apply those site-wide by itself.
 */
class Reloadify_Performance {

	const OPTION_KEY = 'reloadify_performance';

	/**
	 * key => [ live overridable via ini_set() from a plugin?, default desired value ]
	 *
	 * Correction worth flagging: opcache.enable, opcache.validate_timestamps, and
	 * opcache.revalidate_freq are actually PHP_INI_ALL per the PHP manual (see
	 * https://www.php.net/manual/en/opcache.configuration.php) — they're a runtime
	 * check performed on every request, not a startup-fixed memory allocation, so
	 * ini_set() genuinely works on them, same as memory_limit. (opcache.enable can
	 * only be *disabled* at runtime, not re-enabled once off — PHP itself enforces
	 * that, not this plugin.) Only opcache.memory_consumption,
	 * opcache.interned_strings_buffer, and opcache.max_accelerated_files size a
	 * shared-memory pool at OPcache's own startup, before any plugin runs — those
	 * three are the ones that genuinely need the real php.ini + a restart.
	 */
	public static function directive_map() {
		return [
			'memory_limit'                     => [ 'runtime' => true,  'default' => '512M' ],
			'max_execution_time'               => [ 'runtime' => true,  'default' => '300' ],
			'opcache.enable'                   => [ 'runtime' => true,  'default' => '1' ],
			'opcache.validate_timestamps'      => [ 'runtime' => true,  'default' => '1' ],
			'opcache.revalidate_freq'          => [ 'runtime' => true,  'default' => '2' ],
			'max_input_time'                   => [ 'runtime' => false, 'default' => '300' ],
			'post_max_size'                    => [ 'runtime' => false, 'default' => '128M' ],
			'upload_max_filesize'              => [ 'runtime' => false, 'default' => '128M' ],
			'opcache.memory_consumption'       => [ 'runtime' => false, 'default' => '128' ],
			'opcache.interned_strings_buffer'  => [ 'runtime' => false, 'default' => '16' ],
			'opcache.max_accelerated_files'    => [ 'runtime' => false, 'default' => '10000' ],
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
		$defaults = self::default_settings();

		$merged             = wp_parse_args( $saved, $defaults );
		$merged['desired']  = isset( $saved['desired'] ) && is_array( $saved['desired'] )
			? array_merge( $defaults['desired'], $saved['desired'] )
			: $defaults['desired'];
		$merged['runtime_enabled'] = isset( $saved['runtime_enabled'] ) && is_array( $saved['runtime_enabled'] )
			? array_merge( $defaults['runtime_enabled'], $saved['runtime_enabled'] )
			: $defaults['runtime_enabled'];

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
	 * config). opcache.* is handled separately below -- it's PHP_INI_SYSTEM,
	 * which .user.ini and .htaccess cannot reach at all. The only file that can
	 * ever affect it is the real, loaded php.ini itself.
	 */
	const AUTO_WRITE_KEYS = [ 'max_input_time', 'post_max_size', 'upload_max_filesize', 'realpath_cache_size', 'realpath_cache_ttl' ];

	/**
	 * Of those, the subset Apache's mod_php actually allows via .htaccess
	 * "php_value". realpath_cache_* is PHP_INI_SYSTEM even for .htaccess.
	 */
	const HTACCESS_KEYS = [ 'max_input_time', 'post_max_size', 'upload_max_filesize' ];

	const OPCACHE_KEYS = [ 'opcache.memory_consumption', 'opcache.interned_strings_buffer', 'opcache.max_accelerated_files' ];

	const MARKER = 'Reloadify Frontend Sync';

	/**
	 * Best-effort attempt to have the *server itself* pick up the desired
	 * values, by writing a .user.ini (PHP-FPM/CGI — the common case on modern
	 * hosting) and appending a marked block to .htaccess (Apache + mod_php).
	 * Neither is guaranteed: file permissions, open_basedir, Nginx without
	 * PHP-FPM per-directory ini support, or a host that simply ignores these
	 * files can all mean nothing happens. Every outcome is reported back
	 * honestly rather than assumed to have worked.
	 */
	public static function attempt_server_override( $desired ) {
		$results = [
			'user_ini'  => self::write_user_ini( $desired ),
			'htaccess'  => self::write_htaccess( $desired ),
		];

		return $results;
	}

	/**
	 * DANGER ZONE. This only handles the three opcache directives that are
	 * genuinely PHP_INI_SYSTEM (opcache.memory_consumption,
	 * opcache.interned_strings_buffer, opcache.max_accelerated_files — they size
	 * a shared-memory pool once at PHP startup). opcache.enable,
	 * opcache.validate_timestamps, and opcache.revalidate_freq are PHP_INI_ALL
	 * and handled safely via ini_set() in apply_runtime_overrides() instead —
	 * they never reach this method. For the three that remain, the only file
	 * that can ever affect them is the real php.ini PHP actually loaded. This
	 * plugin does not write to that file \u2014 it lives outside the WordPress
	 * install and is a server-level config file, not a location a plugin may
	 * modify. Instead this generates a ready-to-paste snippet and tells the
	 * person exactly which file to add it to and that PHP needs restarting
	 * afterwards.
	 */
	public static function attempt_opcache_override( $desired, $confirmed ) {
		if ( true !== $confirmed ) {
			return [
				'success' => false,
				'path'    => '',
				'message' => __( 'Not attempted \u2014 the confirmation was missing.', 'reloadify-frontend-sync' ),
			];
		}

		$path = php_ini_loaded_file();

		if ( false === $path ) {
			return [
				'success' => false,
				'path'    => '',
				'snippet' => '',
				'message' => __( 'PHP isn\u2019t using a php.ini file at all on this server (php_ini_loaded_file() returned nothing), so there\u2019s no file to point you to.', 'reloadify-frontend-sync' ),
			];
		}

		// This plugin never writes to php.ini itself: it lives outside the
		// WordPress install and is a server-level config file, not a location
		// a plugin is allowed to modify. Instead, generate the exact snippet
		// the person (or their host) can paste in by hand, and tell them
		// exactly where it goes.
		$lines = [];
		foreach ( self::OPCACHE_KEYS as $key ) {
			if ( isset( $desired[ $key ] ) && '' !== $desired[ $key ] ) {
				$lines[] = $key . '=' . $desired[ $key ];
			}
		}
		$snippet = "; " . self::MARKER . " (opcache)\n" . implode( "\n", $lines );

		return [
			'success' => true,
			'path'    => $path,
			'snippet' => $snippet,
			'message' => __( 'These directives can only take effect via the real php.ini, and only after a PHP restart \u2014 this plugin can\u2019t edit that file for you. Copy the snippet below into the php.ini shown, then restart PHP-FPM/Apache (or your local dev server).', 'reloadify-frontend-sync' ),
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
			'message' => __( 'Written. Most PHP-FPM hosts re-read .user.ini every 300 seconds (user_ini.cache_ttl), so give it a few minutes and confirm with "Sync from server". Some hosts ignore .user.ini entirely — if the live value never changes, that\u2019s why.', 'reloadify-frontend-sync' ),
		];
	}

	private static function write_htaccess( $desired ) {
		$path = trailingslashit( ABSPATH ) . '.htaccess';

		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}

		$lines = [];
		foreach ( self::HTACCESS_KEYS as $key ) {
			if ( ! empty( $desired[ $key ] ) ) {
				$lines[] = 'php_value ' . $key . ' ' . $desired[ $key ];
			}
		}

		if ( ! file_exists( $path ) && ! reloadify_path_is_writable( dirname( $path ) ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'No .htaccess file exists and the WordPress root isn\u2019t writable, so one couldn\u2019t be created.', 'reloadify-frontend-sync' ),
			];
		}

		if ( file_exists( $path ) && ! reloadify_path_is_writable( $path ) ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( '.htaccess exists but isn\u2019t writable by PHP, so nothing was changed.', 'reloadify-frontend-sync' ),
			];
		}

		$result = insert_with_markers( $path, self::MARKER, $lines );

		if ( ! $result ) {
			return [
				'success' => false,
				'path'    => $path,
				'message' => __( 'Write failed. This is also a no-op on Nginx or PHP-FPM setups that don\u2019t use .htaccess at all \u2014 confirm your server actually uses Apache with mod_php.', 'reloadify-frontend-sync' ),
			];
		}

		return [
			'success' => true,
			'path'    => $path,
			'message' => __( 'Written. Only takes effect on Apache with mod_php \u2014 has no effect on Nginx or PHP-FPM, which most managed hosts use today.', 'reloadify-frontend-sync' ),
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
	 * Hooked on admin_init. Only applies the runtime PHP-directive overrides
	 * when the current request is actually this plugin's own dashboard page —
	 * never for the rest of wp-admin, and never for frontend visitors. This is
	 * intentionally narrow: WordPress.org guidelines require any ini_set()/
	 * set_time_limit() calls to be scoped to the specific operation that needs
	 * them, not applied as a site-wide default on every request.
	 */
	public static function maybe_apply_runtime_overrides_for_admin() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Not form processing -- this only reads which admin screen is loaded
		// (same pattern WordPress core itself uses, e.g. get_current_screen()),
		// so there is no form submission here for a nonce to protect.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading current page slug only, not processing submitted data.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'reloadify-frontend-sync' !== $page ) {
			return;
		}

		self::apply_runtime_overrides();
	}

	/**
	 * Applies every directive the map marks as genuinely runtime-capable
	 * (memory_limit, max_execution_time, and opcache.enable,
	 * opcache.validate_timestamps, opcache.revalidate_freq — all PHP_INI_ALL).
	 * Callers must scope WHEN this runs themselves — see
	 * maybe_apply_runtime_overrides_for_admin() and Reloadify_Rest for the two
	 * places this plugin legitimately calls it from.
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

			// PHP only allows opcache.enable to be turned OFF at runtime, never
			// back on once it starts disabled -- that's PHP enforcing it, not a
			// bug here. ini_set() itself will just silently no-op the "enable"
			// direction if that's the case.
			// Intentional runtime PHP directive overrides for the Server Performance panel.
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required to apply user-configured memory/time limits.
			@ini_set( $key, $value );

			if ( 'max_execution_time' === $key && function_exists( 'set_time_limit' ) ) {
				// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Companion to max_execution_time override above.
				@set_time_limit( (int) $value );
			}
		}
	}
}
