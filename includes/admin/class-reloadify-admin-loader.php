<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reloadify_Admin (menu registration, admin_enqueue_scripts, settings page
 * markup) only ever does anything inside wp-admin. Requiring that file on
 * every single front-end pageview -- for every visitor, on every request --
 * costs a class parse + compile for code that can never run there.
 *
 * This loader is the only thing the main plugin file requires unconditionally.
 * It defers the actual require + init() to admin-ish requests only, so the
 * frontend hot path skips the file entirely.
 */
class Reloadify_Admin_Loader {

	public static function init() {
		if ( ! self::is_admin_context() ) {
			return;
		}

		require_once __DIR__ . '/class-reloadify-admin.php';

		Reloadify_Admin::init();
	}

	/**
	 * True for wp-admin itself, and for the underlying admin-ajax.php /
	 * admin-post.php requests wp-admin pages fire in the background -- both
	 * still need Reloadify_Admin loaded even though is_admin() alone covers
	 * most of that already. Kept as its own method so the condition only has
	 * to be reasoned about in one place.
	 */
	private static function is_admin_context() {
		return is_admin();
	}
}
