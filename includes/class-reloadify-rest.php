<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reloadify_Rest {

	const NAMESPACE = 'reloadify/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_settings' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_settings' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/performance/apply-opcache',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'apply_opcache_override' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/performance/apply-server',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'apply_server_override' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/performance/sync',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'sync_performance' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/speed',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_speed' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_speed' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/media',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_media' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_media' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/media/backfill-now',
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'run_media_backfill_now' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/cleanup',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_cleanup' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_cleanup' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/extras',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_extras' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_extras' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/performance',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'get_performance' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ __CLASS__, 'update_performance' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);
	}

	public static function apply_opcache_override( WP_REST_Request $request ) {
		$body      = $request->get_json_params();
		$body      = is_array( $body ) ? $body : [];
		$confirmed = ! empty( $body['confirmed'] );

		// Use the desired values from the request (form), or fall back to saved settings
		$settings = Reloadify_Performance::get_settings();
		$desired  = $settings['desired']; // Start with saved values
		
		// Merge in any values from the request
		if ( isset( $body['desired'] ) && is_array( $body['desired'] ) ) {
			foreach ( $body['desired'] as $key => $value ) {
				$desired[ $key ] = $value;
			}
		}

		$result   = Reloadify_Performance::attempt_opcache_override( $desired, $confirmed );
		$result   = is_array( $result ) ? $result : [];

		// If the write was successful, save these as the new desired values
		$anySuccess = ! empty( $result['success'] );
		if ( $anySuccess ) {
			$settings['desired'] = $desired;
			update_option( Reloadify_Performance::OPTION_KEY, $settings );
		}

		// If successful, return the desired values as "live" for immediate display
		$live = $anySuccess ? $desired : Reloadify_Performance::get_live_values();

		return rest_ensure_response( [
			'result' => $result,
			'live'   => $live,
		] );
	}

	public static function apply_server_override( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : [];
		
		// Use the desired values from the request (form), or fall back to saved settings
		$settings = Reloadify_Performance::get_settings();
		$desired  = $settings['desired']; // Start with saved values
		
		// Merge in any values from the request
		if ( isset( $body['desired'] ) && is_array( $body['desired'] ) ) {
			foreach ( $body['desired'] as $key => $value ) {
				$desired[ $key ] = $value;
			}
		}
		
		$results  = Reloadify_Performance::attempt_server_override( $desired );
		$results  = is_array( $results ) ? $results : [];
		
		// If the write was successful, save these as the new desired values
		// so they persist in the database and display correctly
		$anySuccess = false;
		if ( isset( $results['user_ini'] ) && is_array( $results['user_ini'] ) && ! empty( $results['user_ini']['success'] ) ) {
			$anySuccess = true;
		}
		if ( isset( $results['htaccess'] ) && is_array( $results['htaccess'] ) && ! empty( $results['htaccess']['success'] ) ) {
			$anySuccess = true;
		}
		
		if ( $anySuccess ) {
			$settings['desired'] = $desired;
			update_option( Reloadify_Performance::OPTION_KEY, $settings );
		}

		// If successful, return the desired values as "live" for immediate display
		// (they'll update in ini_get() once PHP re-reads the config files)
		$live = $anySuccess ? $desired : Reloadify_Performance::get_live_values();

		return rest_ensure_response( [
			'results' => $results,
			'live'    => $live,
		] );
	}

	public static function sync_performance() {
		$result = Reloadify_Performance::sync_desired_with_live();

		return rest_ensure_response( [
			'settings' => $result,
			'live'     => Reloadify_Performance::get_live_values(),
			'map'      => Reloadify_Performance::directive_map(),
			'phpIniPath' => Reloadify_Performance::get_php_ini_path(),
		] );
	}

	public static function get_speed() {
		return rest_ensure_response( [
			'enabled' => Reloadify_Speed::is_enabled(),
			'items'   => Reloadify_Speed::items(),
		] );
	}

	public static function update_speed( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : [];
		$enabled = Reloadify_Speed::set_enabled( ! empty( $body['enabled'] ) );

		return rest_ensure_response( [
			'enabled' => $enabled,
			'items'   => Reloadify_Speed::items(),
		] );
	}

	public static function run_media_backfill_now() {
		$result = Reloadify_Media::run_backfill_batch_now();
		return rest_ensure_response( $result );
	}

	public static function get_media() {
		$caps = Reloadify_Media::capabilities();
		return rest_ensure_response( [
			'enabled'            => Reloadify_Media::is_enabled(),
			'items'              => Reloadify_Media::items(),
			'format_preference'  => Reloadify_Media::format_preference(),
			'format_capabilities' => [
				'webp' => (bool) $caps['webp'],
				'avif' => (bool) $caps['avif'],
			],
		] );
	}

	public static function update_media( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : [];
		$enabled = Reloadify_Media::set_enabled( ! empty( $body['enabled'] ) );

		if ( isset( $body['format_preference'] ) ) {
			Reloadify_Media::set_format_preference( sanitize_key( $body['format_preference'] ) );
		}

		$caps = Reloadify_Media::capabilities();

		return rest_ensure_response( [
			'enabled'            => $enabled,
			'items'              => Reloadify_Media::items(),
			'format_preference'  => Reloadify_Media::format_preference(),
			'format_capabilities' => [
				'webp' => (bool) $caps['webp'],
				'avif' => (bool) $caps['avif'],
			],
		] );
	}

	public static function get_cleanup() {
		return rest_ensure_response( [
			'enabled' => Reloadify_Cleanup::is_enabled(),
		] );
	}

	public static function update_cleanup( WP_REST_Request $request ) {
		$body    = $request->get_json_params();
		$body    = is_array( $body ) ? $body : [];
		$enabled = Reloadify_Cleanup::set_enabled( ! empty( $body['enabled'] ) );

		return rest_ensure_response( [
			'enabled' => $enabled,
		] );
	}

	public static function get_extras() {
		return rest_ensure_response( Reloadify_Extras::get_settings() );
	}

	public static function update_extras( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : [];
		$result = Reloadify_Extras::update_settings( $body );

		return rest_ensure_response( $result );
	}

	public static function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public static function get_settings() {
		$settings = Reloadify_Settings::get_settings();
		$settings['last_change_detected'] = Reloadify_Settings::get_site_updated_at();
		return rest_ensure_response( $settings );
	}

	public static function update_settings( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : [];
		$result = Reloadify_Settings::update_settings( $body );
		$result['last_change_detected'] = Reloadify_Settings::get_site_updated_at();

		return rest_ensure_response( $result );
	}

	public static function get_performance() {
		return rest_ensure_response( [
			'settings' => Reloadify_Performance::get_settings(),
			'live'     => Reloadify_Performance::get_live_values(),
			'map'      => Reloadify_Performance::directive_map(),
			'phpIniPath' => Reloadify_Performance::get_php_ini_path(),
		] );
	}

	public static function update_performance( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : [];
		$result = Reloadify_Performance::update_settings( $body );


		Reloadify_Performance::apply_runtime_overrides();

		return rest_ensure_response( [
			'settings' => $result,
			'live'     => Reloadify_Performance::get_live_values(),
			'map'      => Reloadify_Performance::directive_map(),
			'phpIniPath' => Reloadify_Performance::get_php_ini_path(),
		] );
	}
}
