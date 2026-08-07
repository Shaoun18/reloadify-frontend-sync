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

		$settings = Reloadify_Performance::get_settings();
		$result   = Reloadify_Performance::attempt_opcache_override( $settings['desired'], $confirmed );

		return rest_ensure_response( [
			'result' => $result,
			'live'   => Reloadify_Performance::get_live_values(),
		] );
	}

	public static function apply_server_override() {
		$settings = Reloadify_Performance::get_settings();
		$results  = Reloadify_Performance::attempt_server_override( $settings['desired'] );

		return rest_ensure_response( [
			'results' => $results,
			'live'    => Reloadify_Performance::get_live_values(),
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

	public static function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public static function get_settings() {
		return rest_ensure_response( Reloadify_Settings::get_settings() );
	}

	public static function update_settings( WP_REST_Request $request ) {
		$body   = $request->get_json_params();
		$body   = is_array( $body ) ? $body : [];
		$result = Reloadify_Settings::update_settings( $body );

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

		// apply_runtime_overrides() already ran once for this request, at
		// plugins_loaded -- before the settings above were saved. Without
		// running it again here, the "live" values below would reflect the
		// *previous* saved state, not what was just saved, making it look
		// like Save did nothing even though the next page load would have
		// picked it up correctly. Re-applying now makes the response the
		// person sees immediately after clicking Save actually true.
		Reloadify_Performance::apply_runtime_overrides();

		return rest_ensure_response( [
			'settings' => $result,
			'live'     => Reloadify_Performance::get_live_values(),
			'map'      => Reloadify_Performance::directive_map(),
			'phpIniPath' => Reloadify_Performance::get_php_ini_path(),
		] );
	}
}
