<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------- Cleanup. ---------------- */

class Reloadify_Cleanup {

	const OPTION_KEY = 'reloadify_delete_data_on_uninstall';
	const SCOPE_KEY  = 'reloadify_cleanup_scope';

	/**
	 * Opt-in since 1.2.0. Deleting the plugin should not quietly take the
	 * site owner's settings with it unless they asked for that.
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, false );
	}

	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;

		add_option( self::OPTION_KEY, false );
		update_option( self::OPTION_KEY, $enabled );

		return self::is_enabled();
	}

	/**
	 * What "delete my data" covers. Each group can be switched on its own.
	 *
	 * Optimized images and videos in the Media Library are NEVER part of this:
	 * they are the site's content, not the plugin's data.
	 *
	 * @return array<string,bool>
	 */
	public static function default_scope() {
		return [
			'settings' => true,  // Saved preferences and configuration.
			'cache'    => true,  // Generated files: minified CSS/JS, reload timestamp.
			'storage'  => false, // Per-image / per-video optimization records.
			'logs'     => false, // Sync history, measured savings and diagnostics.
		];
	}

	public static function scope() {
		$stored = get_option( self::SCOPE_KEY, [] );
		$stored = is_array( $stored ) ? $stored : [];
		$scope  = self::default_scope();

		foreach ( $scope as $key => $default ) {
			if ( array_key_exists( $key, $stored ) ) {
				$scope[ $key ] = (bool) $stored[ $key ];
			}
		}

		return $scope;
	}

	/**
	 * @param array $incoming Partial map of group => bool. Unknown keys are ignored.
	 */
	public static function set_scope( $incoming ) {
		$scope = self::scope();

		if ( is_array( $incoming ) ) {
			foreach ( $scope as $key => $value ) {
				if ( array_key_exists( $key, $incoming ) ) {
					$scope[ $key ] = (bool) $incoming[ $key ];
				}
			}
		}

		update_option( self::SCOPE_KEY, $scope, false );

		return self::scope();
	}

	/** Titles and one-line descriptions for the settings screen. */
	public static function scope_labels() {
		return [
			'settings' => [
				'title' => __( 'Plugin settings', 'reloadify-frontend-sync' ),
				'text'  => __( 'Saved preferences and configuration.', 'reloadify-frontend-sync' ),
			],
			'cache'    => [
				'title' => __( 'Cached files', 'reloadify-frontend-sync' ),
				'text'  => __( 'Minified CSS/JS and the reload timestamp file.', 'reloadify-frontend-sync' ),
			],
			'storage'  => [
				'title' => __( 'Local storage', 'reloadify-frontend-sync' ),
				'text'  => __( 'Per-image and per-video optimization records kept in the WordPress database.', 'reloadify-frontend-sync' ),
			],
			'logs'     => [
				'title' => __( 'Logs & diagnostics', 'reloadify-frontend-sync' ),
				'text'  => __( 'Change history, measured savings and sync state.', 'reloadify-frontend-sync' ),
			],
		];
	}

	/** One shape for the REST responses and the settings screen. */
	public static function payload() {
		return [
			'enabled' => self::is_enabled(),
			'scope'   => self::scope(),
			'labels'  => self::scope_labels(),
		];
	}
}
