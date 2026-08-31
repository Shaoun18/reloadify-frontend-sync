<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a path is writable, using the WordPress filesystem API.
 *
 * @param string $path Absolute filesystem path.
 * @return bool
 */
function reloadify_path_is_writable( $path ) {
	global $wp_filesystem;

	if ( empty( $wp_filesystem ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
	}

	if ( empty( $wp_filesystem ) ) {
		return false;
	}

	return $wp_filesystem->is_writable( $path );
}

/**
 * '.min' in production, '' when SCRIPT_DEBUG is on -- the same convention
 * WordPress core itself uses. Every enqueue in this plugin loads whichever
 * file this returns, so minified assets serve on real sites (smaller,
 * faster requests) while `wp-config.php` with SCRIPT_DEBUG true (or WP_DEBUG,
 * as a fallback) keeps loading the original, readable source for development.
 * Both copies ship in the plugin -- nothing is generated on the fly.
 */
function reloadify_asset_suffix() {
	$debug = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
	return $debug ? '' : '.min';
}
