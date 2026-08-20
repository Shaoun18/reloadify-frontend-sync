<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Extra Features" tab -- new in 1.1.0. Two independent, opt-in-by-toggle
 * additions that have nothing to do with the reload/performance logic
 * above, so they live in their own class and their own settings row.
 *
 * 1. SVG Upload Support: WordPress core blocks .svg uploads in the Media
 *    Library on purpose, because an SVG is really an XML document and can
 *    carry a <script> tag same as an HTML page can. This is ON by default
 *    in this plugin, and every uploaded SVG is run through a basic scan
 *    that rejects files containing <script> tags, on*="" event handler
 *    attributes, or javascript: URIs -- turn the toggle off if you'd
 *    rather WordPress's own stricter default (no SVG uploads at all) stay
 *    in place. The scan is a meaningful floor, not a full sanitizer -- a
 *    high-security multisite with untrusted authors should still restrict
 *    SVG upload to trusted roles, or use a dedicated sanitizing library.
 *
 * 2. Scroll To Top: a small floating button on the frontend that fades in
 *    after the visitor scrolls past a threshold and smooth-scrolls back to
 *    the top on click. Purely cosmetic/UX, OFF by default (a purely visual
 *    addition to a theme shouldn't switch itself on for you), fully
 *    configurable (position, color, scroll threshold) once turned on, and
 *    never loaded in wp-admin.
 */
class Reloadify_Extras {

	const OPTION_KEY = 'reloadify_extras_settings';

	public static function default_settings() {
		return [
			'svg_support' => [
				'enabled' => true,
			],
			'scroll_top' => [
				'enabled'    => true,
				'position'   => 'right', // 'left' or 'right'
				'bg_color'   => '#4f46e5',
				'show_after' => 300, // pixels scrolled before the button appears
			],
		];
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, [] );
		return self::merge_with_defaults( is_array( $saved ) ? $saved : [] );
	}

	private static function merge_with_defaults( $saved ) {
		$defaults = self::default_settings();

		$svg = ( isset( $saved['svg_support'] ) && is_array( $saved['svg_support'] ) ) ? $saved['svg_support'] : [];
		$top = ( isset( $saved['scroll_top'] ) && is_array( $saved['scroll_top'] ) ) ? $saved['scroll_top'] : [];

		$merged = [
			'svg_support' => [
				'enabled' => array_key_exists( 'enabled', $svg ) ? ! empty( $svg['enabled'] ) : $defaults['svg_support']['enabled'],
			],
			'scroll_top' => [
				'enabled'    => array_key_exists( 'enabled', $top ) ? ! empty( $top['enabled'] ) : $defaults['scroll_top']['enabled'],
				'position'   => ( isset( $top['position'] ) && 'left' === $top['position'] ) ? 'left' : $defaults['scroll_top']['position'],
				'bg_color'   => ( isset( $top['bg_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $top['bg_color'] ) ) ? $top['bg_color'] : $defaults['scroll_top']['bg_color'],
				'show_after' => isset( $top['show_after'] ) ? max( 0, (int) $top['show_after'] ) : $defaults['scroll_top']['show_after'],
			],
		];

		return $merged;
	}

	public static function sanitize( $incoming ) {
		return self::merge_with_defaults( is_array( $incoming ) ? $incoming : [] );
	}

	public static function update_settings( $incoming ) {
		$clean = self::sanitize( $incoming );
		update_option( self::OPTION_KEY, $clean );
		return $clean;
	}

	public static function init() {
		$settings = self::get_settings();

		if ( ! empty( $settings['svg_support']['enabled'] ) ) {
			self::init_svg_support();
		}

		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_scroll_top' ] );
	}

	/* ---------------- SVG upload support ---------------- */

	private static function init_svg_support() {
		add_filter( 'upload_mimes', [ __CLASS__, 'allow_svg_mime' ] );
		add_filter( 'wp_check_filetype_and_ext', [ __CLASS__, 'fix_svg_filetype' ], 10, 5 );
		add_filter( 'wp_handle_upload_prefilter', [ __CLASS__, 'scan_svg_upload' ] );
		add_action( 'admin_head', [ __CLASS__, 'svg_media_thumbnail_css' ] );
		add_filter( 'wp_prepare_attachment_for_js', [ __CLASS__, 'fix_svg_media_js' ] );
	}

	public static function allow_svg_mime( $mimes ) {
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	}

	/**
	 * Core's own MIME sniffing doesn't know about SVG, so without this an
	 * upload that upload_mimes now permits would still get rejected at the
	 * filetype-check step with "sorry, this file type is not permitted".
	 */
	public static function fix_svg_filetype( $data, $file, $filename, $mimes, $real_mime = '' ) {
		if ( empty( $data['ext'] ) && empty( $data['type'] ) && preg_match( '/\.svg$/i', (string) $filename ) ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

	/**
	 * Runs before the file lands in the uploads directory. Rejects the
	 * upload outright (rather than trying to strip and re-save the SVG)
	 * if it finds a <script> tag, an on*="" event handler attribute, or a
	 * javascript: URI anywhere in the file. Deliberately conservative:
	 * a false positive just means re-exporting a cleaner SVG from the
	 * design tool; a false negative would be a real vulnerability.
	 */
	public static function scan_svg_upload( $file ) {
		if ( empty( $file['type'] ) || 'image/svg+xml' !== $file['type'] ) {
			return $file;
		}

		$content = ! empty( $file['tmp_name'] ) ? @file_get_contents( $file['tmp_name'] ) : false;

		if ( false === $content ) {
			$file['error'] = __( 'Reloadify Frontend Sync could not scan this SVG before upload, so it was blocked to be safe.', 'reloadify-frontend-sync' );
			return $file;
		}

		$is_risky = preg_match( '/<\s*script/i', $content )
			|| preg_match( '/on[a-z]+\s*=\s*["\']/i', $content )
			|| preg_match( '/javascript\s*:/i', $content )
			|| preg_match( '/<\s*!doctype[^>]*entity/i', $content )
			|| preg_match( '/<\s*foreignObject/i', $content );

		if ( $is_risky ) {
			$file['error'] = __( 'This SVG was blocked: it contains a script tag, an event-handler attribute, an external entity, or embedded HTML — any of which could run code in the browser.', 'reloadify-frontend-sync' );
		}

		return $file;
	}

	/**
	 * SVGs have no raster intermediate sizes, so the Media Library grid can
	 * otherwise show them full-bleed and oversized. Keeps the icon column
	 * looking like every other thumbnail.
	 */
	public static function svg_media_thumbnail_css() {
		echo '<style>.media-icon img[src$=".svg"], td.media-icon img[src$=".svg"], .attachment-preview img[src$=".svg"] { width: 100% !important; height: auto !important; }</style>';
	}

	public static function fix_svg_media_js( $response ) {
		if ( isset( $response['mime'] ) && 'image/svg+xml' === $response['mime'] && empty( $response['sizes'] ) ) {
			$response['sizes'] = [
				'full' => [
					'url'         => $response['url'],
					'width'       => 200,
					'height'      => 200,
					'orientation' => 'landscape',
				],
			];
		}
		return $response;
	}

	/* ---------------- Scroll to top ---------------- */

	public static function maybe_enqueue_scroll_top() {
		if ( is_admin() ) {
			return;
		}

		$settings = self::get_settings();

		if ( empty( $settings['scroll_top']['enabled'] ) ) {
			return;
		}

		wp_enqueue_script(
			'reloadify-scroll-top',
			RELOADIFY_PLUGIN_URL . 'assets/js/scroll-top.js',
			[],
			RELOADIFY_VERSION,
			true
		);

		wp_localize_script( 'reloadify-scroll-top', 'ReloadifyScrollTop', [
			'position'  => $settings['scroll_top']['position'],
			'bgColor'   => $settings['scroll_top']['bg_color'],
			'showAfter' => (int) $settings['scroll_top']['show_after'],
		] );
	}
}
