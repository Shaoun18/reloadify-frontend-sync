<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reloadify_Admin {

	const PAGE_SLUG = 'reloadify-frontend-sync';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
	}

	public static function register_page() {
		add_menu_page(
			__( 'Auto Reloader', 'reloadify-frontend-sync' ),
			__( 'Auto Reloader', 'reloadify-frontend-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-update',
			80
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'reloadify-admin-settings',
			RELOADIFY_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			RELOADIFY_VERSION
		);

		wp_enqueue_script(
			'reloadify-admin-settings',
			RELOADIFY_PLUGIN_URL . 'assets/js/admin-settings.js',
			[ 'wp-element', 'wp-api-fetch', 'wp-i18n' ],
			RELOADIFY_VERSION,
			true
		);

		// Without this, every wp.i18n __() call in admin-settings.js still returns
		// the raw English string even when a matching translation exists -- this
		// is what actually points the script at /languages/reloadify-frontend-sync-{locale}-{handle}.json.
		wp_set_script_translations( 'reloadify-admin-settings', 'reloadify-frontend-sync', RELOADIFY_PLUGIN_DIR . 'languages' );

		wp_localize_script( 'reloadify-admin-settings', 'ReloadifyAdmin', [
			'restUrl'       => esc_url_raw( rest_url( 'reloadify/v1' ) ),
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'version'       => RELOADIFY_VERSION,
			'browsers'      => Reloadify_Settings::supported_browsers(),
			'browserLabels' => Reloadify_Settings::browser_labels(),
			'devModeMaxAgeSeconds' => Reloadify_Settings::DEV_MODE_MAX_AGE,
			// Rendered straight from PHP so the dashboard paints immediately instead
			// of showing a loading state while it waits on two REST round-trips.
			'initial'       => [
				'settings'    => Reloadify_Settings::get_settings(),
				'performance' => [
					'settings'   => Reloadify_Performance::get_settings(),
					'live'       => Reloadify_Performance::get_live_values(),
					'map'        => Reloadify_Performance::directive_map(),
					'phpIniPath' => Reloadify_Performance::get_php_ini_path(),
				],
				'speed'       => [
					'enabled' => Reloadify_Speed::is_enabled(),
					'items'   => Reloadify_Speed::items(),
				],
			],
		] );
	}

	public static function render_page() {
		?>
		<div id="reloadify-settings-root" class="reloadify-admin-wrap"></div>
		<?php
	}
}
