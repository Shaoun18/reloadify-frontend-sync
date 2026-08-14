<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controls whether deleting the plugin from the Plugins screen also wipes
 * this plugin's settings and the reloadify-reload uploads folder. On by default
 * (a proper "clean" uninstall) — turn it off if you want your settings to
 * survive a delete/reinstall.
 *
 * The actual cleanup happens in uninstall.php, which WordPress runs only
 * when the plugin is deleted (not on plain deactivate).
 */
class Reloadify_Cleanup {

	const OPTION_KEY = 'reloadify_delete_data_on_uninstall';

	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, true );
	}

	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;

		// Same WordPress quirk Reloadify_Speed::set_enabled() works around: update_option()
		// no-ops on a brand-new `false` value when the row doesn't exist yet, because
		// get_option() also returns `false` for "not found". Seed the row first.
		add_option( self::OPTION_KEY, true );
		update_option( self::OPTION_KEY, $enabled );

		return self::is_enabled();
	}
}
