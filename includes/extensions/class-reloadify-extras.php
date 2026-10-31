<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/* ---------------- "Extensions" tab ---------------- */

class Reloadify_Extras {

	const OPTION_KEY = 'reloadify_extras_settings';

	public static function default_settings() {
		return [
			'svg_support' => [
				'enabled' => false,
			],
			'scroll_top' => [
				'enabled'           => false,
				'position'          => 'right',  // Horizontal edge: 'left' or 'right'.
				'vertical_position' => 'bottom', // Vertical edge: 'top' or 'bottom'.
				'horizontal_offset' => 24,       // Pixels from the selected horizontal edge.
				'vertical_offset'   => 28,       // Pixels from the selected vertical edge.
				'bg_color'          => '#4f46e5',
				'show_after'        => 300, // Pixels scrolled before the button appears.
				'icon'              => 'default', // 'default' (built-in chevron) or any 'dashicons-*' class name.
				'animation'         => 'slide',   // Entrance: fade | slide | zoom | bounce.
				'hover'             => 'lift',    // Hover effect: none | lift | pulse | wiggle.
				'progress_ring' => true,   // ring around the button that fills as the page is scrolled
			],
			// [reloadify_read_more] -- expand hidden text without a page refresh.
			'read_more' => [
				'enabled'       => false,
				'open_text'     => 'Read more',
				'close_text'    => 'Read less',
				'style'         => 'solid',  // solid | outline | link
				'animation'     => 'slide',  // none | fade | slide
				'bg_color'      => '#4f46e5',
				'text_color'    => '#ffffff',
				'smooth_scroll' => true,     // scroll back to the button when text is collapsed
			],
			// [reloadify_load_more] -- load the next batch of posts without a page refresh.
			'load_more' => [
				'enabled'       => false,
				'button_text'   => 'Load more',
				'loading_text'  => 'Loading…',
				'end_text'      => 'You\'ve reached the end',
				'per_page'      => 6,
				'columns'       => 3,
				'trigger'       => 'button', // button | scroll
				'bg_color'      => '#4f46e5',
				'text_color'    => '#ffffff',
				'show_image'    => true,
				'show_date'     => true,
				'show_excerpt'  => true,
				'excerpt_words' => 20,
			],
		];
	}

	const READ_MORE_STYLES = [ 'solid', 'outline', 'link' ];
	const READ_MORE_ANIMS  = [ 'none', 'fade', 'slide' ];
	const LOAD_TRIGGERS    = [ 'button', 'scroll' ];

	private static function clean_text( $value, $fallback, $max = 60 ) {
		$value = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';

		return '' === $value ? $fallback : mb_substr( $value, 0, $max );
	}

	private static function clean_hex( $value, $fallback ) {
		return ( is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) ? $value : $fallback;
	}

	private static function clean_bool( $group, $key, $fallback ) {
		return array_key_exists( $key, $group ) ? ! empty( $group[ $key ] ) : $fallback;
	}

	const ANIMATIONS = [ 'fade', 'slide', 'zoom', 'bounce' ];
	const HOVERS     = [ 'none', 'lift', 'pulse', 'wiggle' ];

	/**
	 * Every icon in WordPress's own Dashicons set, read from the stylesheet
	 * that ships with the installed WordPress version -- so the list is always
	 * "all of them" for this site, including icons added in newer releases.
	 * Cached for a week per WordPress version.
	 *
	 * @return string[] Class names such as 'dashicons-arrow-up-alt'.
	 */
	public static function dashicon_names() {
		$cache_key = 'reloadify_dashicons_' . md5( get_bloginfo( 'version' ) );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}

		$names = [];

		foreach ( [ 'dashicons.css', 'dashicons.min.css' ] as $file ) {
			$path = ABSPATH . WPINC . '/css/' . $file;

			if ( ! is_readable( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a stylesheet that ships with WordPress, on the local filesystem.
			$css = file_get_contents( $path );

			if ( is_string( $css ) && preg_match_all( '/\.(dashicons-[a-z0-9-]+)::?before/', $css, $matches ) ) {
				$names = array_values( array_diff( array_unique( $matches[1] ), [ 'dashicons-before' ] ) );
			}

			if ( $names ) {
				break;
			}
		}

		if ( ! $names ) {
			$names = [ 'dashicons-arrow-up', 'dashicons-arrow-up-alt', 'dashicons-arrow-up-alt2', 'dashicons-upload', 'dashicons-controls-play', 'dashicons-editor-expand', 'dashicons-admin-site', 'dashicons-star-filled', 'dashicons-heart', 'dashicons-yes' ];
		}

		sort( $names );
		set_transient( $cache_key, $names, WEEK_IN_SECONDS );

		return $names;
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION_KEY, [] );
		return self::merge_with_defaults( is_array( $saved ) ? $saved : [] );
	}

	private static function merge_with_defaults( $saved ) {
		$defaults = self::default_settings();

		$svg = ( isset( $saved['svg_support'] ) && is_array( $saved['svg_support'] ) ) ? $saved['svg_support'] : [];
		$top = ( isset( $saved['scroll_top'] ) && is_array( $saved['scroll_top'] ) ) ? $saved['scroll_top'] : [];

		$rm_in = ( isset( $saved['read_more'] ) && is_array( $saved['read_more'] ) ) ? $saved['read_more'] : [];
		$lm_in = ( isset( $saved['load_more'] ) && is_array( $saved['load_more'] ) ) ? $saved['load_more'] : [];
		$rm    = $defaults['read_more'];
		$lm    = $defaults['load_more'];

		$merged = [
			'read_more' => [
				'enabled'       => self::clean_bool( $rm_in, 'enabled', $rm['enabled'] ),
				'open_text'     => self::clean_text( isset( $rm_in['open_text'] ) ? $rm_in['open_text'] : '', $rm['open_text'] ),
				'close_text'    => self::clean_text( isset( $rm_in['close_text'] ) ? $rm_in['close_text'] : '', $rm['close_text'] ),
				'style'         => ( isset( $rm_in['style'] ) && in_array( $rm_in['style'], self::READ_MORE_STYLES, true ) ) ? $rm_in['style'] : $rm['style'],
				'animation'     => ( isset( $rm_in['animation'] ) && in_array( $rm_in['animation'], self::READ_MORE_ANIMS, true ) ) ? $rm_in['animation'] : $rm['animation'],
				'bg_color'      => self::clean_hex( isset( $rm_in['bg_color'] ) ? $rm_in['bg_color'] : '', $rm['bg_color'] ),
				'text_color'    => self::clean_hex( isset( $rm_in['text_color'] ) ? $rm_in['text_color'] : '', $rm['text_color'] ),
				'smooth_scroll' => self::clean_bool( $rm_in, 'smooth_scroll', $rm['smooth_scroll'] ),
			],
			'load_more' => [
				'enabled'       => self::clean_bool( $lm_in, 'enabled', $lm['enabled'] ),
				'button_text'   => self::clean_text( isset( $lm_in['button_text'] ) ? $lm_in['button_text'] : '', $lm['button_text'] ),
				'loading_text'  => self::clean_text( isset( $lm_in['loading_text'] ) ? $lm_in['loading_text'] : '', $lm['loading_text'] ),
				'end_text'      => self::clean_text( isset( $lm_in['end_text'] ) ? $lm_in['end_text'] : '', $lm['end_text'], 90 ),
				'per_page'      => isset( $lm_in['per_page'] ) ? min( 24, max( 1, (int) $lm_in['per_page'] ) ) : $lm['per_page'],
				'columns'       => isset( $lm_in['columns'] ) ? min( 6, max( 1, (int) $lm_in['columns'] ) ) : $lm['columns'],
				'trigger'       => ( isset( $lm_in['trigger'] ) && in_array( $lm_in['trigger'], self::LOAD_TRIGGERS, true ) ) ? $lm_in['trigger'] : $lm['trigger'],
				'bg_color'      => self::clean_hex( isset( $lm_in['bg_color'] ) ? $lm_in['bg_color'] : '', $lm['bg_color'] ),
				'text_color'    => self::clean_hex( isset( $lm_in['text_color'] ) ? $lm_in['text_color'] : '', $lm['text_color'] ),
				'show_image'    => self::clean_bool( $lm_in, 'show_image', $lm['show_image'] ),
				'show_date'     => self::clean_bool( $lm_in, 'show_date', $lm['show_date'] ),
				'show_excerpt'  => self::clean_bool( $lm_in, 'show_excerpt', $lm['show_excerpt'] ),
				'excerpt_words' => isset( $lm_in['excerpt_words'] ) ? min( 80, max( 5, (int) $lm_in['excerpt_words'] ) ) : $lm['excerpt_words'],
			],
			'svg_support' => [
				'enabled' => array_key_exists( 'enabled', $svg ) ? ! empty( $svg['enabled'] ) : $defaults['svg_support']['enabled'],
			],
			'scroll_top' => [
				'enabled'           => array_key_exists( 'enabled', $top ) ? ! empty( $top['enabled'] ) : $defaults['scroll_top']['enabled'],
				'position'          => ( isset( $top['position'] ) && 'left' === $top['position'] ) ? 'left' : $defaults['scroll_top']['position'],
				'vertical_position' => ( isset( $top['vertical_position'] ) && 'top' === $top['vertical_position'] ) ? 'top' : $defaults['scroll_top']['vertical_position'],
				'horizontal_offset' => isset( $top['horizontal_offset'] ) ? min( 1000, max( 0, (int) $top['horizontal_offset'] ) ) : $defaults['scroll_top']['horizontal_offset'],
				'vertical_offset'   => isset( $top['vertical_offset'] ) ? min( 1000, max( 0, (int) $top['vertical_offset'] ) ) : $defaults['scroll_top']['vertical_offset'],
				'bg_color'          => ( isset( $top['bg_color'] ) && preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $top['bg_color'] ) ) ? $top['bg_color'] : $defaults['scroll_top']['bg_color'],
				'show_after'        => isset( $top['show_after'] ) ? max( 0, (int) $top['show_after'] ) : $defaults['scroll_top']['show_after'],
				'icon'              => self::clean_icon( isset( $top['icon'] ) ? $top['icon'] : 'default' ),
				'animation'         => ( isset( $top['animation'] ) && in_array( $top['animation'], self::ANIMATIONS, true ) ) ? $top['animation'] : $defaults['scroll_top']['animation'],
				'hover'             => ( isset( $top['hover'] ) && in_array( $top['hover'], self::HOVERS, true ) ) ? $top['hover'] : $defaults['scroll_top']['hover'],
				'progress_ring' => array_key_exists( 'progress_ring', $top ) ? ! empty( $top['progress_ring'] ) : $defaults['scroll_top']['progress_ring'],
			],
		];

		return $merged;
	}

	/** Only 'default' or a real Dashicons class name is ever stored. */
	private static function clean_icon( $icon ) {
		$icon = is_string( $icon ) ? sanitize_html_class( $icon ) : 'default';

		if ( 'default' === $icon || '' === $icon ) {
			return 'default';
		}

		return in_array( $icon, self::dashicon_names(), true ) ? $icon : 'default';
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

	
	public static function fix_svg_filetype( $data, $file, $filename, $mimes, $real_mime = '' ) {
		if ( empty( $data['ext'] ) && empty( $data['type'] ) && preg_match( '/\.svg$/i', (string) $filename ) ) {
			$data['ext']  = 'svg';
			$data['type'] = 'image/svg+xml';
		}
		return $data;
	}

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

	/**
	 * Page builders load a real frontend document inside an editing canvas.
	 * The floating button belongs to visitor-facing pages only, not that canvas.
	 */
	private static function is_visual_builder_request() {
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true;
		}

		$builder_flags = [ 'et_fb', 'et_pb_preview', 'elementor-preview', 'fl_builder', 'fl_builder_ui', 'ct_builder', 'brizy-edit', 'brizy-edit-iframe', 'bricks', 'tve', 'vc_editable', 'customize_changeset_uuid' ];

		foreach ( $builder_flags as $flag ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL detection; no request value is processed or saved.
			if ( isset( $_GET[ $flag ] ) ) {
				return true;
			}
		}

		return false;
	}

	public static function maybe_enqueue_scroll_top() {
		if ( is_admin() || self::is_visual_builder_request() ) {
			return;
		}

		$settings = self::get_settings();

		if ( empty( $settings['scroll_top']['enabled'] ) ) {
			return;
		}

		wp_enqueue_script(
			'reloadify-scroll-top',
			RELOADIFY_PLUGIN_URL . 'assets/js/scroll-top' . reloadify_asset_suffix() . '.js',
			[],
			RELOADIFY_VERSION,
			true
		);

		$icon = $settings['scroll_top']['icon'];

		wp_localize_script( 'reloadify-scroll-top', 'ReloadifyScrollTop', [
			'position'         => $settings['scroll_top']['position'],
			'verticalPosition' => $settings['scroll_top']['vertical_position'],
			'horizontalOffset' => (int) $settings['scroll_top']['horizontal_offset'],
			'verticalOffset'   => (int) $settings['scroll_top']['vertical_offset'],
			'bgColor'          => $settings['scroll_top']['bg_color'],
			'showAfter'        => (int) $settings['scroll_top']['show_after'],
			'icon'             => $icon,
			'animation'        => $settings['scroll_top']['animation'],
			'hover'            => $settings['scroll_top']['hover'],
			'ring'             => $settings['scroll_top']['progress_ring'] ? 1 : 0,
			'label'            => __( 'Scroll to top', 'reloadify-frontend-sync' ),
			// The icon font is fetched by the script itself, and only for visitors who
			// actually scroll -- the built-in chevron needs no font at all.
			'iconCss'   => ( 'default' !== $icon ) ? includes_url( 'css/dashicons.min.css' ) : '',
		] );
	}
}
