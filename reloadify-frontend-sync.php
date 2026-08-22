<?php

/**
 * Plugin Name:       Reloadify Frontend Sync
 * Plugin URI:        https://wordpress.org/plugins/reloadify-frontend-sync/
 * Description:       Automatically reloads the frontend across all open browsers whenever WordPress content updates—works with any theme, plugin, or page builder.
 * Version:           1.2.0
 * Author:            Programmershaoun
 * Author URI:        https://shaoun18.github.io/
 * Text Domain:       reloadify-frontend-sync
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP:      7.4
 */

/**
 * @package Reloadify_Frontend_Sync
 * @version 1.2.0
 * @author  Shaoun Chandra Shill
 * @license GPL-2.0-or-later
 */

// Prevent direct file access - foundational security check.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin Version - Used for script/style versioning and capability tracking.
 * Update this version on every plugin release.
 */
define( 'RELOADIFY_VERSION', '1.2.0' );

/**
 * Plugin File Path - Absolute path to the main plugin file.
 * Used for hook registration and resource location.
 */
define( 'RELOADIFY_PLUGIN_FILE', __FILE__ );

/**
 * Plugin Directory Path - Absolute path to the plugin directory with trailing slash.
 * Used for requiring/including plugin files.
 */
define( 'RELOADIFY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Plugin URL - Web URL to the plugin directory with trailing slash.
 * Used for enqueueing scripts and styles.
 */
define( 'RELOADIFY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load all plugin dependencies.
 * These classes handle core functionality, settings, performance, and admin features.
 */
require_once RELOADIFY_PLUGIN_DIR . 'includes/reloadify-filesystem.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-settings.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-performance.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-speed.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-cleanup.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-media.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-extras.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-rest.php';
require_once RELOADIFY_PLUGIN_DIR . 'admin/class-reloadify-admin.php';

/**
 * Reloadify Frontend Sync - Main Plugin Class
 *
 * Singleton pattern class that orchestrates the entire plugin.
 * Handles:
 * - Plugin lifecycle (activation, deactivation)
 * - Change detection (hooks to various WordPress update actions)
 * - Frontend script enqueueing
 * - AJAX endpoints for client communication
 * - Settings persistence and retrieval
 *
 * @class Reloadify_Frontend_Sync
 */
class Reloadify_Frontend_Sync {

	/**
	 * Singleton instance.
	 *
	 * @var Reloadify_Frontend_Sync|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 * Ensures only one instance of the plugin class runs at any given time.
	 *
	 * @return Reloadify_Frontend_Sync The singleton instance.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - Initialize plugin hooks and functionality.
	 * Private to enforce singleton pattern.
	 *
	 * Initializes:
	 * - Plugin activation/deactivation hooks
	 * - Change detection hooks for various WordPress events
	 * - Frontend script enqueueing
	 * - AJAX endpoints
	 * - Admin interface
	 *
	 * @return void
	 */
	private function __construct() {
		// Plugin lifecycle hooks
		register_activation_hook( RELOADIFY_PLUGIN_FILE, [ 'Reloadify_Settings', 'activate' ] );
		register_deactivation_hook( RELOADIFY_PLUGIN_FILE, [ 'Reloadify_Media', 'deactivate' ] );
		add_action( 'activated_plugin', [ $this, 'redirect_after_activation' ] );

		// Performance and settings initialization
		add_action( 'admin_init', [ 'Reloadify_Performance', 'apply_runtime_overrides' ], 0 );
		add_action( 'plugins_loaded', [ 'Reloadify_Settings', 'maybe_migrate_fast_reload' ], 5 );

		// Initialize feature classes
		Reloadify_Speed::init();
		Reloadify_Media::init();
		Reloadify_Extras::init();

		// Post save detection - Primary change tracking hook
		add_action( 'save_post', [ $this, 'record_site_update' ], 10, 3 );

		// Post lifecycle hooks - Detect deletions, restorations, and trashing
		add_action( 'wp_trash_post', [ $this, 'record_site_update_now' ] );
		add_action( 'before_delete_post', [ $this, 'record_site_update_now' ] );
		add_action( 'untrash_post', [ $this, 'record_site_update_now' ] );

		// Taxonomy changes - Detect category/tag/custom taxonomy updates
		add_action( 'created_term', [ $this, 'record_site_update_now' ] );
		add_action( 'edited_term', [ $this, 'record_site_update_now' ] );
		add_action( 'delete_term', [ $this, 'record_site_update_now' ] );

		// Theme and plugin changes
		add_action( 'customize_save_after', [ $this, 'record_site_update_now' ] );
		add_action( 'wp_update_nav_menu', [ $this, 'record_site_update_now' ] );
		add_action( 'switch_theme', [ $this, 'record_site_update_now' ] );
		add_action( 'activated_plugin', [ $this, 'record_site_update_now' ] );
		add_action( 'deactivated_plugin', [ $this, 'record_site_update_now' ] );

		// WooCommerce integration - Detect product/order/setting changes
		add_action( 'woocommerce_settings_saved', [ $this, 'record_site_update_now' ] );
		add_action( 'woocommerce_new_order', [ $this, 'record_site_update_now' ] );
		add_action( 'woocommerce_update_order', [ $this, 'record_site_update_now' ] );
		add_action( 'woocommerce_order_status_changed', [ $this, 'record_site_update_now' ] );

		// Options change detection - Catches miscellaneous settings updates
		add_action( 'added_option', [ $this, 'maybe_record_option_change' ], 10, 2 );
		add_action( 'updated_option', [ $this, 'maybe_record_option_change' ], 10, 1 );
		add_action( 'added_option', [ $this, 'maybe_record_option_change' ], 10, 1 );
		add_action( 'deleted_option', [ $this, 'maybe_record_option_change' ], 10, 1 );

		// Frontend script and AJAX
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_reloader_script' ] );
		add_action( 'wp_ajax_reloadify_reloader_check', [ $this, 'check_for_updates' ] );
		add_action( 'wp_ajax_nopriv_reloadify_reloader_check', [ $this, 'check_for_updates' ] );

		// Initialize REST API and Admin interface
		Reloadify_Rest::init();
		Reloadify_Admin::init();
	}

	/**
	 * Redirect to plugin settings after activation.
	 * Provides better user experience on first install.
	 *
	 * @param string $plugin The plugin file path being activated.
	 * @return void
	 */
	public function redirect_after_activation( $plugin ) {
		// Only redirect for this plugin, not multi-plugin activations
		if ( $plugin !== plugin_basename( RELOADIFY_PLUGIN_FILE ) ) {
			return;
		}

		// Don't redirect during bulk/multi-plugin activation
		if ( null !== filter_input( INPUT_GET, 'activate-multi' ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . Reloadify_Admin::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Record site update when a post is saved.
	 *
	 * Tracks post save events and updates the site modification timestamp.
	 * Filters out autosaves and revisions to prevent false triggers.
	 *
	 * @param int    $post_id The post ID being saved.
	 * @param object $post    The post object.
	 * @param bool   $update  Whether this is an update (true) or creation (false).
	 * @return void
	 */
	public function record_site_update( $post_id, $post, $update ) {
		// Skip autosaves - these don't represent intentional changes
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip post revisions
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Skip automatic saves
		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Update the site modification timestamp
		Reloadify_Settings::bump_site_updated_at();
	}

	/**
	 * Record site update immediately (no autosave filtering).
	 * Used for events that always represent real changes.
	 *
	 * @return void
	 */
	public function record_site_update_now() {
		// Skip during autosaves
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Update the site modification timestamp immediately
		Reloadify_Settings::bump_site_updated_at();
	}

	/**
	 * Detect option changes and record site update if relevant.
	 *
	 * Intelligently filters options to avoid false positives:
	 * - Ignores transient options
	 * - Ignores WordPress internal options
	 * - Ignores plugin's own options
	 * - Only triggers on write operations (POST, PUT, PATCH, DELETE)
	 * - Only triggers in admin or REST API context
	 *
	 * @param string $option The option name being changed.
	 * @return void
	 */
	public function maybe_record_option_change( $option ) {
		// Get the request method and ensure it's a write operation
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$is_write_method = in_array( $request_method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true );

		// Only track changes in admin or REST context
		$is_admin_context = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );

		if ( ! $is_admin_context || ! $is_write_method ) {
			return;
		}

		// Ignore this plugin's own options
		if ( 0 === strpos( $option, 'reloadify_' ) ) {
			return;
		}

		// Ignore transients and session tokens - these change frequently and aren't content
		$ignored_prefixes = [ '_transient_', '_site_transient_', '_wp_session_tokens' ];
		foreach ( $ignored_prefixes as $prefix ) {
			if ( 0 === strpos( $option, $prefix ) ) {
				return;
			}
		}

		// Ignore WordPress internal maintenance options
		$ignored_exact = [
			'cron',
			'rewrite_rules',
			'db_upgraded',
			'db_version',
			'finished_updating',
			'recently_activated',
			'active_plugins',
			'uninstall_plugins',
			'https_detection_errors',
			'can_compress_scripts',
			'auto_updater.disabled',
			'_wp_suggested_policy_text_has_changed',
			'wp_user_roles',
		];
		if ( in_array( $option, $ignored_exact, true ) ) {
			return;
		}

		// Skip autosaves
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// This option change is relevant - update the site modification timestamp
		Reloadify_Settings::bump_site_updated_at();
	}

	/**
	 * Enqueue the frontend reload checker script.
	 *
	 * Loads the JavaScript that polls the server for updates and reloads the page
	 * when changes are detected. Only loads on the frontend when appropriate conditions
	 * are met (developer mode on, or user is logged in and can edit posts).
	 *
	 * @return void
	 */
	public function enqueue_reloader_script() {
		// Don't load on admin pages
		if ( is_admin() ) {
			return;
		}

		// Don't load inside page builder preview iframes
		if ( $this->is_builder_request() ) {
			return;
		}

		$settings = Reloadify_Settings::get_settings();

		// Check if user is logged in editor/viewer
		$is_editor_viewer = is_user_logged_in() && current_user_can( 'edit_posts' );

		// Only load the reloader if Developer Mode is on OR user can edit content
		if ( ! $is_editor_viewer && empty( $settings['dev_mode_enabled'] ) ) {
			return;
		}

		// Use minified or debug version based on SCRIPT_DEBUG constant
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		// Enqueue the main reload checker script
		wp_enqueue_script(
			'reloadify-reloader-js',
			RELOADIFY_PLUGIN_URL . 'assets/js/reloader' . $suffix . '.js',
			[ 'jquery' ],
			RELOADIFY_VERSION,
			true
		);

		// Localize script with settings and AJAX endpoint data
		wp_localize_script( 'reloadify-reloader-js', 'ReloadifySync', [
			'ajax_url'                => admin_url( 'admin-ajax.php' ),
			'timestamp_url'           => Reloadify_Settings::get_timestamp_file_url(),
			'timestamp'               => Reloadify_Settings::get_site_updated_at(),
			'interval'                => (int) $settings['poll_interval'],
			'nonce'                   => wp_create_nonce( 'reloadify_reloader_nonce' ),
			'is_editor_viewer'        => $is_editor_viewer ? '1' : '0',
			'reload_mode'             => $settings['reload_mode'],
			'all_tabs_reload_enabled' => $settings['all_tabs_reload_enabled'] ? '1' : '0',
			'browser_settings'        => $settings['browsers'],
		] );
	}

	/**
	 * Check if current request is a page builder preview.
	 *
	 * Detects if the request is coming from within a page builder's preview/editing
	 * interface. The reload script should not run inside these contexts to prevent
	 * recursive reloads that interfere with the builder's UI.
	 *
	 * @return bool True if this is a builder request, false otherwise.
	 */
	private function is_builder_request() {
		// Check for page builder query parameters
		$flag_params = [ 'elementor-preview', 'fl_builder', 'bricks', 'ct_builder', 'tve' ];

		foreach ( $flag_params as $param ) {
			if ( null !== filter_input( INPUT_GET, $param ) ) {
				return true;
			}
		}

		// Divi builder specific flag
		if ( '1' === filter_input( INPUT_GET, 'et_fb' ) ) {
			return true;
		}

		// WPBakery builder specific flag
		if ( 'vc_inline' === filter_input( INPUT_GET, 'vc_action' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * AJAX endpoint: Check for updates and return reload status.
	 *
	 * Frontend JavaScript polls this endpoint to determine if the page should reload.
	 * Compares client-side timestamp with server-side timestamp and returns reload instruction.
	 *
	 * Security:
	 * - Validates nonce to prevent CSRF attacks
	 * - Sends no-cache headers to prevent stale responses
	 * - Returns JSON-RPC formatted response
	 *
	 * @return void JSON response containing reload status and settings.
	 */
	public function check_for_updates() {
		// Verify AJAX security token (nonce) - prevents CSRF attacks
		check_ajax_referer( 'reloadify_reloader_nonce', 'nonce', false );

		// Send no-cache headers to ensure fresh response
		nocache_headers();

		// Get client-side timestamp and server-side timestamp
		$client_ts = isset( $_POST['timestamp'] ) ? intval( $_POST['timestamp'] ) : 0;
		$server_ts = Reloadify_Settings::get_site_updated_at();
		$settings  = Reloadify_Settings::get_settings();

		// Return reload status and current settings
		wp_send_json_success( [
			'reload'                  => $server_ts > $client_ts,
			'new_timestamp'           => $server_ts,
			'all_tabs_reload_enabled' => $settings['all_tabs_reload_enabled'] ? 1 : 0,
			'reload_mode'             => $settings['reload_mode'],
		] );
	}
}

/**
 * Initialize the plugin - Create singleton instance and start execution.
 */
Reloadify_Frontend_Sync::get_instance();
