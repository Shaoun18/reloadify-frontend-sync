<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------- Cleanup. ---------------- */

class Reloadify_Cleanup {

	const OPTION_KEY = 'reloadify_delete_data_on_uninstall';

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
}
