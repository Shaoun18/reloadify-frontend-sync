<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read More / Read Less and AJAX Load More (Extensions tab).
 *
 * Two small front-end tools that work without a page refresh:
 *
 *  - [reloadify_read_more]...[/reloadify_read_more]  hides the enclosed content
 *    behind a button. The text stays in the HTML (crawlable), it is only
 *    hidden until the visitor opens it.
 *  - [reloadify_load_more]  prints a first batch of posts/products and loads
 *    the next batch through the REST API when the button is pressed (or when
 *    it scrolls into view).
 *
 * Assets are registered up front but only enqueued by a shortcode that is
 * actually rendered, so pages that don't use them pay nothing.
 */
class Reloadify_Content {

	const REST_NAMESPACE = 'reloadify/v1';
	const MAX_PER_PAGE   = 24;
	const MAX_PAGE       = 500;

	/** Running counter so every shortcode on a page gets a unique id. */
	private static $counter = 0;

	/** Localized script data is printed once per page, however many shortcodes exist. */
	private static $localized = [];

	public static function init() {
		add_shortcode( 'reloadify_read_more', [ __CLASS__, 'render_read_more' ] );
		add_shortcode( 'reloadify_load_more', [ __CLASS__, 'render_load_more' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_assets' ] );
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	/* ---------------- Assets ---------------- */

	public static function register_assets() {
		$suffix = reloadify_asset_suffix();

		wp_register_style( 'reloadify-content', RELOADIFY_PLUGIN_URL . 'assets/css/content' . $suffix . '.css', [], RELOADIFY_VERSION );
		wp_register_script( 'reloadify-read-more', RELOADIFY_PLUGIN_URL . 'assets/js/read-more' . $suffix . '.js', [], RELOADIFY_VERSION, true );
		wp_register_script( 'reloadify-load-more', RELOADIFY_PLUGIN_URL . 'assets/js/load-more' . $suffix . '.js', [], RELOADIFY_VERSION, true );
	}

	private static function enqueue( $tool ) {
		wp_enqueue_style( 'reloadify-content' );

		$handle = 'reloadify-' . $tool;

		wp_enqueue_script( $handle );

		if ( isset( self::$localized[ $tool ] ) ) {
			return;
		}

		self::$localized[ $tool ] = true;

		if ( 'read-more' === $tool ) {
			wp_localize_script( $handle, 'ReloadifyReadMore', [
				'animation' => self::settings( 'read_more' )['animation'],
				'scroll'    => self::settings( 'read_more' )['smooth_scroll'] ? 1 : 0,
			] );
			return;
		}

		wp_localize_script( $handle, 'ReloadifyLoadMore', [
			'endpoint' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/load-more' ) ),
			'error'    => __( 'Could not load more. Please try again.', 'reloadify-frontend-sync' ),
			'retry'    => __( 'Try again', 'reloadify-frontend-sync' ),
		] );
	}

	private static function settings( $group ) {
		$all = Reloadify_Extras::get_settings();

		return $all[ $group ];
	}

	/** Page builders render shortcodes inside editing canvases; show everything expanded there. */
	private static function is_builder_canvas() {
		foreach ( [ 'et_fb', 'et_pb_preview', 'elementor-preview', 'fl_builder', 'ct_builder', 'brizy-edit', 'bricks', 'tve', 'vc_editable' ] as $flag ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only URL detection; nothing is processed or saved.
			if ( isset( $_GET[ $flag ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function clean_color( $value, $fallback ) {
		return ( is_string( $value ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $value ) ) ? $value : $fallback;
	}

	/* ---------------- [reloadify_read_more] ---------------- */

	public static function render_read_more( $atts, $content = null ) {
		$content = (string) $content;
		$cfg     = self::settings( 'read_more' );

		// Feature off: never swallow the author's text, just show it.
		if ( empty( $cfg['enabled'] ) ) {
			return do_shortcode( $content );
		}

		$atts = shortcode_atts( [
			'open'      => $cfg['open_text'],
			'close'     => $cfg['close_text'],
			'style'     => $cfg['style'],
			'animation' => $cfg['animation'],
			'color'     => $cfg['bg_color'],
			'text'      => $cfg['text_color'],
			'class'     => '',
			'expanded'  => '',
		], $atts, 'reloadify_read_more' );

		$inner = do_shortcode( $content );

		if ( '' === trim( wp_strip_all_tags( $inner, true ) ) && false === stripos( $inner, '<img' ) ) {
			return '';
		}

		self::enqueue( 'read-more' );

		++self::$counter;

		$id        = 'reloadify-rm-' . self::$counter;
		$style     = in_array( $atts['style'], [ 'solid', 'outline', 'link' ], true ) ? $atts['style'] : 'solid';
		$animation = in_array( $atts['animation'], [ 'none', 'fade', 'slide' ], true ) ? $atts['animation'] : 'slide';
		$expanded  = self::is_builder_canvas() || in_array( strtolower( (string) $atts['expanded'] ), [ '1', 'true', 'yes' ], true );
		$color     = self::clean_color( $atts['color'], $cfg['bg_color'] );
		$text      = self::clean_color( $atts['text'], $cfg['text_color'] );
		$open      = sanitize_text_field( $atts['open'] );
		$close     = sanitize_text_field( $atts['close'] );
		$extra     = implode( ' ', array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) ) ) );

		$classes = trim( 'reloadify-rm reloadify-rm--' . $style . ( $expanded ? ' is-open' : '' ) . ( '' !== $extra ? ' ' . $extra : '' ) );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $classes ); ?>" data-animation="<?php echo esc_attr( $animation ); ?>" style="--reloadify-rm-bg:<?php echo esc_attr( $color ); ?>;--reloadify-rm-fg:<?php echo esc_attr( $text ); ?>">
			<div class="reloadify-rm__content" id="<?php echo esc_attr( $id ); ?>"<?php echo $expanded ? '' : ' hidden'; ?>><?php echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Author content, already run through the_content filters and do_shortcode(). ?></div>
			<button type="button" class="reloadify-rm__button" aria-expanded="<?php echo $expanded ? 'true' : 'false'; ?>" aria-controls="<?php echo esc_attr( $id ); ?>" data-open="<?php echo esc_attr( $open ); ?>" data-close="<?php echo esc_attr( $close ); ?>"><span class="reloadify-rm__label"><?php echo esc_html( $expanded ? $close : $open ); ?></span><span class="reloadify-rm__chevron" aria-hidden="true"></span></button>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ---------------- [reloadify_load_more] ---------------- */

	/**
	 * Attributes that are allowed to reach the REST endpoint. They are signed
	 * (see sign()), so a visitor can only ever page through the exact query the
	 * page author wrote -- never swap in another post type or status.
	 */
	private static function query_args_from_atts( $atts ) {
		$post_types = array_values( array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['post_type'] ) ) ) );
		$post_types = array_values( array_filter( $post_types, function ( $type ) {
			$object = get_post_type_object( $type );

			return $object && $object->public;
		} ) );

		if ( ! $post_types ) {
			$post_types = [ 'post' ];
		}

		$orderby = in_array( $atts['orderby'], [ 'date', 'title', 'modified', 'rand', 'menu_order', 'comment_count' ], true ) ? $atts['orderby'] : 'date';
		$order   = 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC';

		$config = [
			'pt'  => $post_types,
			'pp'  => min( self::MAX_PER_PAGE, max( 1, (int) $atts['per_page'] ) ),
			'ob'  => $orderby,
			'od'  => $order,
			'cat' => sanitize_text_field( (string) $atts['category'] ),
			'tag' => sanitize_text_field( (string) $atts['tag'] ),
			'tx'  => sanitize_key( (string) $atts['taxonomy'] ),
			'tm'  => sanitize_text_field( (string) $atts['terms'] ),
			'ex'  => array_values( array_filter( array_map( 'absint', explode( ',', (string) $atts['exclude'] ) ) ) ),
			'au'  => absint( $atts['author'] ),
			's'   => sanitize_text_field( (string) $atts['search'] ),
		];

		return $config;
	}

	private static function sign( $json ) {
		return hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	}

	/** Builds the WP_Query arguments from a (trusted, signed) config. */
	private static function build_query( $config, $page ) {
		$args = [
			'post_type'           => $config['pt'],
			'post_status'         => 'publish',
			'posts_per_page'      => $config['pp'],
			'paged'               => max( 1, (int) $page ),
			'orderby'             => $config['ob'],
			'order'               => $config['od'],
			'ignore_sticky_posts' => true,
			'has_password'        => false,
		];

		if ( ! empty( $config['cat'] ) ) {
			$args['category_name'] = $config['cat'];
		}

		if ( ! empty( $config['tag'] ) ) {
			$args['tag'] = $config['tag'];
		}

		if ( ! empty( $config['tx'] ) && ! empty( $config['tm'] ) && taxonomy_exists( $config['tx'] ) ) {
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Author-defined filter on a signed shortcode, bounded by posts_per_page.
				[
					'taxonomy' => $config['tx'],
					'field'    => 'slug',
					'terms'    => array_map( 'trim', explode( ',', $config['tm'] ) ),
				],
			];
		}

		if ( ! empty( $config['ex'] ) ) {
			$args['post__not_in'] = $config['ex']; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Small author-supplied list.
		}

		if ( ! empty( $config['au'] ) ) {
			$args['author'] = $config['au'];
		}

		if ( ! empty( $config['s'] ) ) {
			$args['s'] = $config['s'];
		}

		return $args;
	}

	public static function render_load_more( $atts ) {
		$cfg = self::settings( 'load_more' );

		if ( empty( $cfg['enabled'] ) ) {
			return current_user_can( 'manage_options' ) ? '<!-- Reloadify Load More is switched off (Reloadify > Extensions). -->' : '';
		}

		$atts = shortcode_atts( [
			'post_type'  => 'post',
			'per_page'   => $cfg['per_page'],
			'columns'    => $cfg['columns'],
			'orderby'    => 'date',
			'order'      => 'DESC',
			'category'   => '',
			'tag'        => '',
			'taxonomy'   => '',
			'terms'      => '',
			'exclude'    => '', // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Shortcode attribute name only; it is turned into a small, signed post__not_in list below, never passed to get_posts().
			'author'     => '',
			'search'     => '',
			'button'     => $cfg['button_text'],
			'loading'    => $cfg['loading_text'],
			'end'        => $cfg['end_text'],
			'trigger'    => $cfg['trigger'],
			'color'      => $cfg['bg_color'],
			'text'       => $cfg['text_color'],
			'image'      => $cfg['show_image'] ? '1' : '0',
			'date'       => $cfg['show_date'] ? '1' : '0',
			'excerpt'    => $cfg['show_excerpt'] ? '1' : '0',
			'words'      => $cfg['excerpt_words'],
			'class'      => '',
			'exclude_current' => '1',
		], $atts, 'reloadify_load_more' );

		$config = self::query_args_from_atts( $atts );

		if ( '1' === (string) $atts['exclude_current'] && is_singular() && get_the_ID() ) {
			$config['ex'][] = (int) get_the_ID();
		}

		$view = [
			'img' => ! in_array( strtolower( (string) $atts['image'] ), [ '0', 'false', 'no' ], true ),
			'dt'  => ! in_array( strtolower( (string) $atts['date'] ), [ '0', 'false', 'no' ], true ),
			'ex'  => ! in_array( strtolower( (string) $atts['excerpt'] ), [ '0', 'false', 'no' ], true ),
			'wd'  => min( 80, max( 5, (int) $atts['words'] ) ),
		];

		$config['v'] = $view;

		$query = new WP_Query( self::build_query( $config, 1 ) );

		if ( ! $query->have_posts() ) {
			wp_reset_postdata();
			return '';
		}

		self::enqueue( 'load-more' );

		++self::$counter;

		$columns = min( 6, max( 1, (int) $atts['columns'] ) );
		$trigger = ( 'scroll' === $atts['trigger'] ) ? 'scroll' : 'button';
		$color   = self::clean_color( $atts['color'], $cfg['bg_color'] );
		$text    = self::clean_color( $atts['text'], $cfg['text_color'] );
		$json    = wp_json_encode( $config );
		$max     = (int) $query->max_num_pages;
		$extra   = implode( ' ', array_filter( array_map( 'sanitize_html_class', explode( ' ', (string) $atts['class'] ) ) ) );

		$wrapper = trim( 'reloadify-lm reloadify-lm--cols-' . $columns . ( '' !== $extra ? ' ' . $extra : '' ) );

		ob_start();
		?>
		<div class="<?php echo esc_attr( $wrapper ); ?>" id="reloadify-lm-<?php echo esc_attr( (string) self::$counter ); ?>" data-config="<?php echo esc_attr( $json ); ?>" data-sig="<?php echo esc_attr( self::sign( $json ) ); ?>" data-page="1" data-max="<?php echo esc_attr( (string) $max ); ?>" data-trigger="<?php echo esc_attr( $trigger ); ?>" data-end="<?php echo esc_attr( sanitize_text_field( $atts['end'] ) ); ?>" data-loading="<?php echo esc_attr( sanitize_text_field( $atts['loading'] ) ); ?>" style="--reloadify-lm-bg:<?php echo esc_attr( $color ); ?>;--reloadify-lm-fg:<?php echo esc_attr( $text ); ?>">
			<div class="reloadify-lm__grid">
				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					echo self::item_html( get_post(), $view ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside item_html().
				}
				?>
			</div>
			<?php if ( $max > 1 ) : ?>
				<div class="reloadify-lm__footer">
					<button type="button" class="reloadify-lm__button"><?php echo esc_html( sanitize_text_field( $atts['button'] ) ); ?></button>
					<p class="reloadify-lm__status" role="status" aria-live="polite"></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
		wp_reset_postdata();

		return ob_get_clean();
	}

	/**
	 * One card. Filterable so a theme can replace the markup entirely:
	 * add_filter( 'reloadify_load_more_item_html', fn( $html, $post, $view ) => ... , 10, 3 );
	 */
	private static function item_html( $post, $view ) {
		$permalink = get_permalink( $post );
		$title     = get_the_title( $post );
		$classes   = 'reloadify-lm__item reloadify-lm__item--' . sanitize_html_class( $post->post_type );

		ob_start();
		?>
		<article class="<?php echo esc_attr( $classes ); ?>">
			<?php if ( $view['img'] && has_post_thumbnail( $post ) ) : ?>
				<a class="reloadify-lm__thumb" href="<?php echo esc_url( $permalink ); ?>" tabindex="-1" aria-hidden="true"><?php echo get_the_post_thumbnail( $post, 'medium_large', [ 'loading' => 'lazy', 'alt' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core-generated <img>. ?></a>
			<?php endif; ?>
			<div class="reloadify-lm__body">
				<?php if ( $view['dt'] && 'product' !== $post->post_type ) : ?>
					<time class="reloadify-lm__date" datetime="<?php echo esc_attr( get_the_date( 'c', $post ) ); ?>"><?php echo esc_html( get_the_date( '', $post ) ); ?></time>
				<?php endif; ?>
				<h3 class="reloadify-lm__title"><a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a></h3>
				<?php
				if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
					$product = wc_get_product( $post->ID );
					if ( $product ) {
						echo '<div class="reloadify-lm__price">' . wp_kses_post( $product->get_price_html() ) . '</div>';
					}
				}
				?>
				<?php if ( $view['ex'] ) : ?>
					<?php $excerpt = wp_trim_words( has_excerpt( $post ) ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), (int) $view['wd'], '…' ); ?>
					<?php if ( '' !== $excerpt ) : ?>
						<p class="reloadify-lm__excerpt"><?php echo esc_html( $excerpt ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</article>
		<?php
		$html = (string) ob_get_clean();

		/**
		 * Filters the HTML of one Load More item.
		 *
		 * @param string  $html Item markup.
		 * @param WP_Post $post The post.
		 * @param array   $view Display flags: img, dt, ex, wd.
		 */
		return (string) apply_filters( 'reloadify_load_more_item_html', $html, $post, $view );
	}

	/* ---------------- REST: next batch ---------------- */

	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/load-more',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'rest_load_more' ],
				'permission_callback' => '__return_true', // Public front-end data; the query is signed and only ever returns published posts.
				'args'                => [
					'config' => [ 'required' => true, 'type' => 'string' ],
					'sig'    => [ 'required' => true, 'type' => 'string' ],
					'page'   => [ 'required' => true, 'type' => 'integer', 'minimum' => 2, 'maximum' => self::MAX_PAGE ],
				],
			]
		);
	}

	public static function rest_load_more( WP_REST_Request $request ) {
		$json = (string) $request->get_param( 'config' );
		$sig  = (string) $request->get_param( 'sig' );
		$page = (int) $request->get_param( 'page' );

		if ( ! hash_equals( self::sign( $json ), $sig ) ) {
			return new WP_Error( 'reloadify_bad_signature', __( 'Invalid request.', 'reloadify-frontend-sync' ), [ 'status' => 403 ] );
		}

		$config = json_decode( $json, true );

		if ( ! is_array( $config ) || empty( $config['pt'] ) || empty( $config['pp'] ) ) {
			return new WP_Error( 'reloadify_bad_config', __( 'Invalid request.', 'reloadify-frontend-sync' ), [ 'status' => 400 ] );
		}

		$view = ( isset( $config['v'] ) && is_array( $config['v'] ) ) ? $config['v'] : [ 'img' => true, 'dt' => true, 'ex' => true, 'wd' => 20 ];

		$query = new WP_Query( self::build_query( $config, $page ) );
		$html  = '';

		while ( $query->have_posts() ) {
			$query->the_post();
			$html .= self::item_html( get_post(), $view );
		}

		wp_reset_postdata();

		$response = rest_ensure_response( [
			'html'     => $html,
			'page'     => $page,
			'max'      => (int) $query->max_num_pages,
			'has_more' => $page < (int) $query->max_num_pages,
		] );

		// Fresh content on every press; the signed URL itself is stable, so let CDNs hold it briefly.
		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}
}
