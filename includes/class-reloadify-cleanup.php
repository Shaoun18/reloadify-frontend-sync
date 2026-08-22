<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reloadify_Cleanup {

	const OPTION_KEY = 'reloadify_delete_data_on_uninstall';

	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, true );
	}

	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;

		add_option( self::OPTION_KEY, true );
		update_option( self::OPTION_KEY, $enabled );

		return self::is_enabled();
	}
}
