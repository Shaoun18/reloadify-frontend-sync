<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
