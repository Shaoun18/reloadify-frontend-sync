<?php

/**
 * Plugin Name:       Reloadify Frontend Sync
 * Plugin URI:        https://wordpress.org/plugins/reloadify-frontend-sync/
 * Description:       Automatically refresh frontend previews after content updates with cross-browser live reload, server performance controls, and image/media optimization.
 * Version:           1.4.0
 * Author:            shaounchandrashill
 * Author URI:        https://shaoun18.github.io/
 * Text Domain:       reloadify-frontend-sync
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.4
 * Requires PHP:      7.4
 */

if (! defined('ABSPATH')) {
	exit;
}

define('RELOADIFY_VERSION', '1.4.0');
define('RELOADIFY_PLUGIN_FILE', __FILE__);
define('RELOADIFY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RELOADIFY_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RELOADIFY_PLUGIN_DIR . 'includes/reloadify-filesystem.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-settings.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/extensions/class-reloadify-performance.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/extensions/class-reloadify-minify.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/extensions/class-reloadify-speed.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/extensions/class-reloadify-cleanup.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/extensions/class-reloadify-media.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/extensions/class-reloadify-extras.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/extensions/class-reloadify-content.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-rest.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/admin/class-reloadify-admin-loader.php';

class Reloadify_Frontend_Sync
{

	private static $instance = null;

	public static function get_instance()
	{
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct()
	{
		register_activation_hook(RELOADIFY_PLUGIN_FILE, ['Reloadify_Settings', 'activate']);
		register_deactivation_hook(RELOADIFY_PLUGIN_FILE, ['Reloadify_Media', 'deactivate']);
		register_deactivation_hook(RELOADIFY_PLUGIN_FILE, ['Reloadify_Speed', 'deactivate']);
		add_action('activated_plugin', [$this, 'redirect_after_activation']);

		add_action('plugins_loaded', ['Reloadify_Performance', 'apply_runtime_overrides'], 0);

		Reloadify_Speed::init();
		Reloadify_Media::init();
		Reloadify_Extras::init();
		Reloadify_Content::init();

		add_action('save_post', [$this, 'record_site_update'], 10, 3);
		add_action('wp_trash_post', [$this, 'record_site_update_now']);
		add_action('before_delete_post', [$this, 'record_site_update_now']);
		add_action('untrash_post', [$this, 'record_site_update_now']);
		add_action('created_term', [$this, 'record_site_update_now']);
		add_action('edited_term', [$this, 'record_site_update_now']);
		add_action('delete_term', [$this, 'record_site_update_now']);
		add_action('customize_save_after', [$this, 'record_site_update_now']);
		add_action('wp_update_nav_menu', [$this, 'record_site_update_now']);
		add_action('switch_theme', [$this, 'record_site_update_now']);
		add_action('activated_plugin', [$this, 'record_site_update_now']);
		add_action('deactivated_plugin', [$this, 'record_site_update_now']);
		add_action('woocommerce_settings_saved', [$this, 'record_site_update_now']);

		// WooCommerce hooks 
		// Orders only count as a site change when an admin edits them in
		// wp-admin. An order placed at checkout, a payment-gateway callback
		// or a cron job must never make the open checkout tab reload, or the
		// shopper lands on an empty cart instead of the order-received page.
		add_action('woocommerce_new_order', [$this, 'record_order_update']);
		add_action('woocommerce_update_order', [$this, 'record_order_update']);
		add_action('woocommerce_order_status_changed', [$this, 'record_order_update']);
		add_action('updated_option', [$this, 'maybe_record_option_change'], 10, 1);
		add_action('added_option', [$this, 'maybe_record_option_change'], 10, 1);
		add_action('deleted_option', [$this, 'maybe_record_option_change'], 10, 1);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_reloader_script']);
		add_action('wp_ajax_reloadify_reloader_check', [$this, 'check_for_updates']);
		add_action('wp_ajax_nopriv_reloadify_reloader_check', [$this, 'check_for_updates']);

		Reloadify_Rest::init();
		Reloadify_Admin_Loader::init();
	}


	/* ---------------- Redirect after activation to the admin dashboard. ---------------- */

	public function redirect_after_activation($plugin)
	{
		if ($plugin !== plugin_basename(RELOADIFY_PLUGIN_FILE)) {
			return;
		}

		// WP-CLI, cron and REST/AJAX activations have no browser to redirect;
		// calling exit() there aborts the command with a stack trace.
		if ((defined('WP_CLI') && WP_CLI) || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_doing_cron()) {
			return;
		}

		if (null !== filter_input(INPUT_GET, 'activate-multi')) {
			return; // Bulk activation redirects on its own; don't hijack it.
		}

		wp_safe_redirect(admin_url('admin.php?page=' . Reloadify_Admin::PAGE_SLUG));
		exit;
	}

	/* ---------------- Record site update. ---------------- */

	/**
	 * True while WooCommerce is serving a shopper (classic wc-ajax checkout,
	 * Store API / block checkout, payment-gateway callbacks, pay-for-order).
	 * Whatever those requests write is a purchase, not a content update.
	 */
	private function is_shopper_request()
	{
		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- read-only detection of WooCommerce request markers; nothing is processed or saved.
		if (isset($_GET['wc-ajax']) || isset($_GET['wc-api']) || isset($_POST['woocommerce_pay']) || isset($_POST['woocommerce-process-checkout-nonce'])) {
			return true;
		}
		// phpcs:enable

		$uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

		return '' !== $uri && (false !== strpos($uri, '/wc/store/') || false !== stripos($uri, 'rest_route=%2fwc%2fstore') || false !== stripos($uri, 'rest_route=/wc/store'));
	}

	/** Order changes made by a shop manager inside wp-admin. */
	private function is_admin_order_edit()
	{
		return is_admin() && ! $this->is_shopper_request() && is_user_logged_in() && current_user_can('edit_shop_orders');
	}

	public function record_order_update()
	{
		if ($this->is_admin_order_edit()) {
			$this->record_site_update_now();
		}
	}

	public function record_site_update($post_id, $post, $update)
	{
		if ($this->is_shopper_request()) {
			return;
		}

		// Legacy (posts-based) order storage saves orders through save_post.
		if ($post instanceof WP_Post && in_array($post->post_type, ['shop_order', 'shop_order_refund', 'shop_order_placehold'], true) && ! $this->is_admin_order_edit()) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (wp_is_post_revision($post_id)) {
			return;
		}

		if (wp_is_post_autosave($post_id)) {
			return;
		}

		Reloadify_Settings::bump_site_updated_at();
	}

	public function record_site_update_now()
	{
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if ($this->is_shopper_request()) {
			return;
		}

		Reloadify_Settings::bump_site_updated_at();
	}

	public function maybe_record_option_change($option)
	{
		$request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
		$is_write_method = in_array($request_method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
		$is_admin_context = is_admin() || (defined('REST_REQUEST') && REST_REQUEST);

		if (! $is_admin_context || ! $is_write_method || $this->is_shopper_request()) {
			return;
		}

		// Background admin-ajax chatter (Heartbeat, post-lock refresh) writes
		// options on its own and is not a content change -- don't let it
		// trigger a frontend reload.
		if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only comparison against a fixed allow-list; nothing is processed or saved.
			$ajax_action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';
			if (in_array($ajax_action, ['heartbeat', 'wp-remove-post-lock', 'reloadify_reloader_check'], true)) {
				return;
			}
		}

		if (0 === strpos($option, 'reloadify_')) {
			return;
		}

		$ignored_prefixes = ['_transient_', '_site_transient_', '_wp_session_tokens'];
		foreach ($ignored_prefixes as $prefix) {
			if (0 === strpos($option, $prefix)) {
				return;
			}
		}

		$ignored_exact = [
			'cron',
			'rewrite_rules',
			'db_upgraded',
			'db_version',
			'finished_updating',
			'recently_activated',
			'uninstall_plugins',
			'https_detection_errors',
			'can_compress_scripts',
			'auto_updater.disabled',
			'_wp_suggested_policy_text_has_changed',
			'wp_user_roles',
		];
		if (in_array($option, $ignored_exact, true)) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		Reloadify_Settings::bump_site_updated_at();
	}

	/**
	 * Enqueue the frontend polling script on every front-end page load, unless it's a builder canvas or the admin dashboard.
	 */
	public function enqueue_reloader_script()
	{
		if (is_admin()) {
			return;
		}

		if ($this->is_builder_request()) {
			return;
		}

		$settings = Reloadify_Settings::get_settings();

		$is_editor_viewer = is_user_logged_in() && current_user_can('edit_posts');

		// A plain visitor only gets the script when Developer Mode is switched on.
		if (! $is_editor_viewer && empty($settings['dev_mode_enabled'])) {
			return;
		}

		$suffix = reloadify_asset_suffix();

		wp_enqueue_script(
			'reloadify-reloader-js',
			RELOADIFY_PLUGIN_URL . 'assets/js/reloader' . $suffix . '.js',
			['jquery'],
			RELOADIFY_VERSION,
			true
		);

		wp_localize_script('reloadify-reloader-js', 'ReloadifySync', [
			// Origin-relative for the same reason as the timestamp URL.
			'ajax_url'         => wp_make_link_relative(admin_url('admin-ajax.php')),
			'timestamp_url'    => Reloadify_Settings::get_timestamp_file_url(),
			'timestamp'        => Reloadify_Settings::get_site_updated_at(),
			'interval'         => (int) $settings['poll_interval'],
			'nonce'            => wp_create_nonce('reloadify_reloader_nonce'),
			'is_editor_viewer' => $is_editor_viewer ? '1' : '0',
			'reload_mode'      => $settings['reload_mode'],
			'all_tabs_reload_enabled' => empty($settings['all_tabs_reload_enabled']) ? '0' : '1',
			'browser_settings' => $settings['browsers'],
		]);
	}

	/**
	 * Checks the request against every supported builder's query-string signature,
	 * so the reloader never runs inside the builder canvas itself.
	 */
	private function is_builder_request()
	{
		$flag_params = ['elementor-preview', 'fl_builder', 'bricks', 'ct_builder', 'tve'];

		foreach ($flag_params as $param) {
			if (null !== filter_input(INPUT_GET, $param)) {
				return true;
			}
		}

		if ('1' === filter_input(INPUT_GET, 'et_fb')) {
			return true;
		}

		if ('vc_inline' === filter_input(INPUT_GET, 'vc_action')) {
			return true;
		}

		return false;
	}

	/**
	 * AJAX handler to check if the site has changed since the tab last loaded.
	 */
	public function check_for_updates()
	{
		if (! check_ajax_referer('reloadify_reloader_nonce', 'nonce', false)) {
			wp_send_json_error('Invalid Nonce');
		}

		$client_ts = isset($_POST['timestamp']) ? intval($_POST['timestamp']) : 0;
		$server_ts = Reloadify_Settings::get_site_updated_at();
		$settings  = Reloadify_Settings::get_settings();

		// The two reload-behaviour settings ride along on every check so an
		// already-open tab picks up a change to them without needing a manual
		// refresh first.
		wp_send_json_success([
			'reload'                  => $server_ts > $client_ts,
			'new_timestamp'           => $server_ts,
			'reload_mode'             => $settings['reload_mode'],
			'all_tabs_reload_enabled' => empty($settings['all_tabs_reload_enabled']) ? 0 : 1,
		]);
	}
}

Reloadify_Frontend_Sync::get_instance();
