<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reloadify_Admin_Loader {

	private static $instance = null;

	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {

		if ( is_admin() && current_user_can( 'manage_options' ) ) {
			add_action( 'admin_init', [ $this, 'maybe_load_admin' ] );
		}
	}

	public function maybe_load_admin() {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page-identity check (which admin screen is loading), not form processing; nothing is written or acted on based on this value.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

		if ( 'reloadify-frontend-sync' === $page ) {

			if ( ! class_exists( 'Reloadify_Admin' ) ) {
				require_once RELOADIFY_PLUGIN_DIR . 'admin/class-reloadify-admin.php';
				Reloadify_Admin::init();
			}
		}
	}
}
