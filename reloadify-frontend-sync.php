<?php

/**
 * Plugin Name:       Reloadify Frontend Sync
 * Plugin URI:        https://wordpress.org/plugins/reloadify-frontend-sync/
 * Description:       Automatically reloads the frontend across all open browsers whenever WordPress content updates—works with any theme, plugin, or page builder.
 * Version:           1.1.2
 * Author:            Programmershaoun
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

define('RELOADIFY_VERSION', '1.1.2');
define('RELOADIFY_PLUGIN_FILE', __FILE__);
define('RELOADIFY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RELOADIFY_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RELOADIFY_PLUGIN_DIR . 'includes/reloadify-filesystem.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-settings.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-performance.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-speed.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-cleanup.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-media.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-extras.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-rest.php';
require_once RELOADIFY_PLUGIN_DIR . 'includes/class-reloadify-admin.php';

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
		add_action('activated_plugin', [$this, 'redirect_after_activation']);

		add_action('plugins_loaded', ['Reloadify_Performance', 'apply_runtime_overrides'], 0);

		Reloadify_Speed::init();
		Reloadify_Media::init();
		Reloadify_Extras::init();

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
		add_action('woocommerce_new_order', [$this, 'record_site_update_now']);
		add_action('woocommerce_update_order', [$this, 'record_site_update_now']);
		add_action('woocommerce_order_status_changed', [$this, 'record_site_update_now']);
		add_action('added_option', [$this, 'maybe_record_option_change'], 10, 2);
		add_action('updated_option', [$this, 'maybe_record_option_change'], 10, 1);
		add_action('added_option', [$this, 'maybe_record_option_change'], 10, 1);
		add_action('deleted_option', [$this, 'maybe_record_option_change'], 10, 1);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_reloader_script']);
		add_action('wp_ajax_reloadify_reloader_check', [$this, 'check_for_updates']);
		add_action('wp_ajax_nopriv_reloadify_reloader_check', [$this, 'check_for_updates']);

		Reloadify_Rest::init();
		Reloadify_Admin::init();
	}


	/* ---------------- Redirect after activation to the admin dashboard. ---------------- */

	public function redirect_after_activation($plugin)
	{
		if ($plugin !== plugin_basename(RELOADIFY_PLUGIN_FILE)) {
			return;
		}

		if (null !== filter_input(INPUT_GET, 'activate-multi')) {
			return; // Bulk activation redirects on its own; don't hijack it.
		}

		wp_safe_redirect(admin_url('admin.php?page=' . Reloadify_Admin::PAGE_SLUG));
		exit;
	}

	/* ---------------- Record site update. ---------------- */

	public function record_site_update($post_id, $post, $update)
	{
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

		Reloadify_Settings::bump_site_updated_at();
	}

	public function maybe_record_option_change($option)
	{
		$request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
		$is_write_method = in_array($request_method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
		$is_admin_context = is_admin() || (defined('REST_REQUEST') && REST_REQUEST);

		if (! $is_admin_context || ! $is_write_method) {
			return;
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

		wp_enqueue_script(
			'reloadify-reloader-js',
			RELOADIFY_PLUGIN_URL . 'assets/js/reloader.js',
			['jquery'],
			RELOADIFY_VERSION,
			true
		);

		wp_localize_script('reloadify-reloader-js', 'ReloadifySync', [
			'ajax_url'         => admin_url('admin-ajax.php'),
			'timestamp_url'    => Reloadify_Settings::get_timestamp_file_url(),
			'timestamp'        => Reloadify_Settings::get_site_updated_at(),
			'interval'         => (int) $settings['poll_interval'],
			'nonce'            => wp_create_nonce('reloadify_reloader_nonce'),
			'is_editor_viewer' => $is_editor_viewer ? '1' : '0',
			'reload_mode'      => $settings['reload_mode'],
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

		if ($server_ts > $client_ts) {
			wp_send_json_success([
				'reload'        => true,
				'new_timestamp' => $server_ts,
			]);
		} else {
			wp_send_json_success([
				'reload'        => false,
				'new_timestamp' => $server_ts,
			]);
		}
	}
}

Reloadify_Frontend_Sync::get_instance();
