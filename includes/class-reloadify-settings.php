<?php

/**
 * Reloadify Settings Class
 *
 * Manages plugin settings, defaults, validation, and persistence.
 * Handles browser configuration, reload modes, polling intervals, and feature toggles.
 *
 * @class Reloadify_Settings
 * @package Reloadify_Frontend_Sync
 */

if (! defined('ABSPATH')) {
	exit;
}

class Reloadify_Settings
{

	/**
	 * WordPress option key for storing plugin settings.
	 * All plugin configuration is stored under this key in wp_options table.
	 *
	 * @const string
	 */
	const OPTION_KEY = 'reloadify_settings';

	/**
	 * Suppress settings bump flag.
	 * Used during activation to prevent timestamp update when initializing settings.
	 *
	 * @var bool
	 */
	private static $suppress_bump = false;

	/**
	 * Get list of supported browsers.
	 *
	 * Returns array of browser identifiers that the plugin can target for reloading.
	 * Each browser has detection logic in the frontend JavaScript.
	 *
	 * @return array Browser identifiers (chrome, firefox, safari, etc.)
	 */
	public static function supported_browsers()
	{
		return ['chrome', 'brave', 'edge', 'firefox', 'safari', 'opera', 'ucbrowser', 'vivaldi', 'yandex', 'samsung'];
	}

	/**
	 * Get human-readable browser labels.
	 *
	 * Maps browser identifiers to display names for the admin interface.
	 *
	 * @return array Browser ID => Display Label pairs.
	 */
	public static function browser_labels()
	{
		return [
			'chrome'    => 'Chrome',
			'brave'     => 'Brave',
			'edge'      => 'Edge',
			'firefox'   => 'Firefox',
			'safari'    => 'Safari',
			'opera'     => 'Opera',
			'ucbrowser' => 'UC Browser',
			'vivaldi'   => 'Vivaldi',
			'yandex'    => 'Yandex Browser',
			'samsung'   => 'Samsung Internet',
		];
	}

	/**
	 * Get default plugin settings.
	 *
	 * Provides factory defaults for all configuration options.
	 * Used on activation, reset, or when migrating between versions.
	 *
	 * Defaults:
	 * - Developer Mode: OFF (for security - prevents polling on live sites)
	 * - All Tabs Reload: OFF (reload only active tab by default)
	 * - Poll Interval: 500ms (fast change detection)
	 * - Reload Mode: soft (full reload without cache bypass)
	 * - Browsers: All browsers enabled for normal and incognito modes
	 *
	 * @return array Default settings array with all options.
	 */
	public static function default_settings()
	{
		$browsers = [];

		// Initialize all supported browsers with both normal and incognito modes enabled
		foreach (self::supported_browsers() as $browser) {
			$browsers[$browser] = [
				'normal'    => true,
				'incognito' => true,
			];
		}

		return [
			'dev_mode_enabled'        => false,
			'dev_mode_enabled_at'     => 0,
			'all_tabs_reload_enabled' => false,
			'poll_interval'           => 500,
			'reload_mode'             => 'soft',
			'browsers'                => $browsers,
		];
	}

	/**
	 * Plugin activation hook.
	 *
	 * Initializes plugin settings and timestamp tracking on first activation.
	 * Creates necessary database options and files.
	 *
	 * - Registers default settings if not already present
	 * - Creates initial timestamp record
	 * - Sets data deletion preference
	 * - Writes timestamp JSON file for cache busting
	 *
	 * @return void
	 */
	public static function activate()
	{
		// Suppress timestamp bumps during activation (prevent unnecessary reload trigger)
		self::$suppress_bump = true;

		// Initialize settings if not already present
		if (false === get_option(self::OPTION_KEY, false)) {
			add_option(self::OPTION_KEY, self::default_settings());
		}

		// Initialize last site update timestamp if not present
		if (false === get_option('reloadify_last_site_update', false)) {
			add_option('reloadify_last_site_update', time(), '', false);
		}

		// Set default for data deletion on uninstall
		if (false === get_option('reloadify_delete_data_on_uninstall', false)) {
			add_option('reloadify_delete_data_on_uninstall', true);
		}

		// Write initial timestamp file
		self::write_timestamp_file(time());
	}

	/**
	 * Get current plugin settings.
	 *
	 * Retrieves settings from database and merges with defaults to ensure
	 * all required keys are present even if database is incomplete.
	 *
	 * @return array Current settings array, merged with defaults.
	 */
	public static function get_settings()
	{
		$saved = get_option(self::OPTION_KEY, []);
		return self::merge_with_defaults(is_array($saved) ? $saved : []);
	}

	/**
	 * Migrate settings from older plugin versions.
	 *
	 * Handles upgrade path from v1.2.9 to v1.3.0:
	 * - Updates slow poll intervals (2000ms, 800ms) to new fast default (500ms)
	 * - Preserves custom poll intervals set by users
	 *
	 * This ensures old installations automatically get performance improvements.
	 *
	 * @return void
	 */
	public static function maybe_migrate_fast_reload()
	{
		// Check if migration already completed
		$migrated_flag = 'reloadify_fast_reload_migrated_129';
		if (get_option($migrated_flag, false)) {
			return;
		}

		$saved = get_option(self::OPTION_KEY, []);

		// If using old slow intervals, update to new fast default
		if (is_array($saved) && isset($saved['poll_interval']) && in_array((int) $saved['poll_interval'], [2000, 800], true)) {
			$saved['poll_interval'] = 500;
			update_option(self::OPTION_KEY, $saved);
			self::write_timestamp_file(self::get_site_updated_at());
		}

		// Mark migration as complete to prevent re-running
		update_option($migrated_flag, 1);
	}

	/**
	 * Update plugin settings from form input.
	 *
	 * Sanitizes and validates incoming settings, manages Developer Mode timestamp,
	 * and persists changes to database and timestamp file.
	 *
	 * @param array $incoming Raw settings array from user input (e.g., $_POST).
	 * @return array The sanitized and validated settings array.
	 */
	public static function update_settings($incoming)
	{
		// Sanitize and validate all incoming settings
		$clean = self::sanitize($incoming);

		$previous = self::get_settings();

		// Track when Developer Mode is enabled for auto-disable logic
		if ($clean['dev_mode_enabled'] && ! $previous['dev_mode_enabled']) {
			$clean['dev_mode_enabled_at'] = time();
		} elseif (! $clean['dev_mode_enabled']) {
			// Clear timestamp when Dev Mode is disabled
			$clean['dev_mode_enabled_at'] = 0;
		} else {
			// Preserve existing timestamp if Dev Mode remains enabled
			$clean['dev_mode_enabled_at'] = $previous['dev_mode_enabled_at'];
		}

		// Persist settings to database
		update_option(self::OPTION_KEY, $clean);

		// Update timestamp file for frontend polling
		self::write_timestamp_file(self::get_site_updated_at());

		return $clean;
	}

	/**
	 * Merge saved settings with defaults.
	 *
	 * Ensures all expected keys are present in settings array.
	 * Handles missing values, type coercion, and validation.
	 *
	 * @param array $saved Settings retrieved from database.
	 * @return array Merged settings with all keys and proper types.
	 */
	private static function merge_with_defaults($saved)
	{
		$defaults = self::default_settings();
		$merged   = wp_parse_args($saved, $defaults);

		// Handle browser settings array carefully
		$merged['browsers'] = isset($saved['browsers']) && is_array($saved['browsers'])
			? array_merge($defaults['browsers'], $saved['browsers'])
			: $defaults['browsers'];

		// Validate and coerce browser settings
		foreach (self::supported_browsers() as $browser) {
			$row = isset($merged['browsers'][$browser]) ? $merged['browsers'][$browser] : [];
			$merged['browsers'][$browser] = [
				'normal'    => ! empty($row['normal']),
				'incognito' => ! empty($row['incognito']),
			];
		}

		// Type coercion and validation for all settings
		$merged['dev_mode_enabled']        = ! empty($merged['dev_mode_enabled']);
		$merged['dev_mode_enabled_at']     = (int) (isset($merged['dev_mode_enabled_at']) ? $merged['dev_mode_enabled_at'] : 0);
		$merged['all_tabs_reload_enabled'] = ! empty($merged['all_tabs_reload_enabled']);
		$merged['poll_interval']           = max(300, (int) $merged['poll_interval']);
		$merged['reload_mode']             = in_array($merged['reload_mode'], ['soft', 'hard'], true) ? $merged['reload_mode'] : 'soft';

		return $merged;
	}

	/**
	 * Sanitize and validate settings from user input.
	 *
	 * Converts raw input (typically from $_POST) into safe, validated settings.
	 * Enforces type checking and whitelisting of allowed values.
	 *
	 * @param array $incoming Raw input array, typically from form submission.
	 * @return array Sanitized and validated settings array.
	 */
	public static function sanitize($incoming)
	{
		$defaults = self::default_settings();

		// Start with validated defaults, overwrite with incoming values
		$clean = [
			'dev_mode_enabled'        => ! empty($incoming['dev_mode_enabled']),
			'dev_mode_enabled_at'     => 0,
			'all_tabs_reload_enabled' => ! empty($incoming['all_tabs_reload_enabled']),
			'poll_interval'           => isset($incoming['poll_interval']) ? max(300, (int) $incoming['poll_interval']) : $defaults['poll_interval'],
			'reload_mode'             => (isset($incoming['reload_mode']) && 'hard' === $incoming['reload_mode']) ? 'hard' : 'soft',
			'browsers'                => [],
		];

		// Validate browser settings
		$incoming_browsers = isset($incoming['browsers']) && is_array($incoming['browsers']) ? $incoming['browsers'] : [];

		foreach (self::supported_browsers() as $browser) {
			$row = isset($incoming_browsers[$browser]) ? $incoming_browsers[$browser] : [];
			$clean['browsers'][$browser] = [
				'normal'    => ! empty($row['normal']),
				'incognito' => ! empty($row['incognito']),
			];
		}

		return $clean;
	}

	/**
	 * Update the site modification timestamp.
	 *
	 * Bumps the timestamp recorded in database and timestamp file.
	 * Triggers frontend reload checks by making new_timestamp > client_timestamp.
	 *
	 * Can be suppressed during activation via $suppress_bump flag.
	 *
	 * @return void
	 */
	public static function bump_site_updated_at()
	{
		// Skip during initialization/activation if suppressed
		if (self::$suppress_bump) {
			return;
		}

		$ts = time();
		update_option('reloadify_last_site_update', $ts, false);
		self::write_timestamp_file($ts);
	}

	/**
	 * Get the current site modification timestamp.
	 *
	 * Returns Unix timestamp of last recorded change to the site.
	 * Used by frontend polling to detect if reload is needed.
	 *
	 * @return int Unix timestamp of last site update.
	 */
	public static function get_site_updated_at()
	{
		return (int) get_option('reloadify_last_site_update', time());
	}

	/**
	 * Write timestamp file to wp-uploads directory.
	 *
	 * Creates a JSON file containing the current timestamp and reload settings.
	 * This file is served directly by the webserver (no PHP) for minimal overhead.
	 * Frontend polling reads this file to detect changes.
	 *
	 * File location: wp-content/uploads/reloadify-reload/timestamp.json
	 * File format: {"t": unix_timestamp, "atr": 0/1, "rm": "soft"/"hard"}
	 *
	 * Also creates:
	 * - .htaccess with no-cache headers
	 * - index.php for security
	 *
	 * @param int $ts Unix timestamp to write to file.
	 * @return bool True if file was written successfully, false otherwise.
	 */
	public static function write_timestamp_file($ts)
	{
		// Get WordPress upload directory
		$upload = wp_upload_dir();
		if (! empty($upload['error'])) {
			return false;
		}

		$dir = trailingslashit($upload['basedir']) . 'reloadify-reload';

		// Create directory if it doesn't exist
		if (! file_exists($dir)) {
			wp_mkdir_p($dir);
			// Write index.php to prevent directory listing
			@file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
		}

		// Write or update .htaccess with cache-busting headers
		$htaccess = $dir . '/.htaccess';
		if (! file_exists($htaccess)) {
			@file_put_contents(
				$htaccess,
				"<IfModule mod_headers.c>\n" .
					"\tHeader set Cache-Control \"no-cache, no-store, must-revalidate\"\n" .
					"\tHeader set Pragma \"no-cache\"\n" .
					"\tHeader set Expires 0\n" .
					"</IfModule>\n"
			);
		}

		// Verify directory is writable before attempting write
		if (! reloadify_path_is_writable($dir)) {
			return false;
		}

		// Get current settings to include in timestamp file
		$settings = self::get_settings();
		$payload  = [
			't'   => $ts,
			'atr' => $settings['all_tabs_reload_enabled'] ? 1 : 0,
			'rm'  => $settings['reload_mode'],
		];

		// Write JSON file with exclusive lock to prevent concurrent writes
		return false !== @file_put_contents($dir . '/timestamp.json', wp_json_encode($payload), LOCK_EX);
	}

	/**
	 * Get URL to timestamp file.
	 *
	 * Returns the web-accessible URL to the timestamp.json file.
	 * Used by frontend JavaScript to poll for changes.
	 *
	 * @return string Full URL to timestamp.json file, or empty string if uploads are unavailable.
	 */
	public static function get_timestamp_file_url()
	{
		$upload = wp_upload_dir();
		if (! empty($upload['error'])) {
			return '';
		}
		return trailingslashit($upload['baseurl']) . 'reloadify-reload/timestamp.json';
	}
}
