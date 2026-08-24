<?php


if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/* ---------------- Uninstall Cleanup ---------------- */

function reloadify_uninstall_cleanup_current_site() {
	delete_option( 'reloadify_settings' );
	delete_option( 'reloadify_last_site_update' );
	delete_option( 'reloadify_performance' );
	delete_option( 'reloadify_speed_boost_enabled' );
	delete_option( 'reloadify_media_optimize_enabled' );
	delete_option( 'reloadify_delete_data_on_uninstall' );
	delete_option( 'reloadify_extras_settings' );

	wp_clear_scheduled_hook( 'reloadify_media_backfill_batch' );
	wp_clear_scheduled_hook( 'reloadify_media_backfill_video_batch' );
	$timestamp = wp_next_scheduled( 'reloadify_media_compress_video' );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'reloadify_media_compress_video' );
		$timestamp = wp_next_scheduled( 'reloadify_media_compress_video' );
	}

	$upload = wp_upload_dir();
	if ( empty( $upload['basedir'] ) ) {
		return;
	}

	$dir = trailingslashit( $upload['basedir'] ) . 'reloadify-reload';

	if ( ! is_dir( $dir ) ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();

	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $dir, true );
	}
}

if ( ! get_option( 'reloadify_delete_data_on_uninstall', true ) ) {
	return;
}

if ( is_multisite() ) {
	$reloadify_site_ids = get_sites( [ 'fields' => 'ids' ] );

	foreach ( $reloadify_site_ids as $reloadify_site_id ) {
		switch_to_blog( $reloadify_site_id );
		reloadify_uninstall_cleanup_current_site();
		restore_current_blog();
	}
} else {
	reloadify_uninstall_cleanup_current_site();
}
