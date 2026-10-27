<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------- Media Optimization ---------------- */

class Reloadify_Media {

	const OPTION_KEY        = 'reloadify_media_optimize_enabled';
	const FORMAT_OPTION_KEY = 'reloadify_media_format_preference';
	const CRON_HOOK_BACKFILL = 'reloadify_media_backfill_batch';
	const CRON_HOOK_VIDEO_BACKFILL = 'reloadify_media_backfill_video_batch';
	const CRON_HOOK_VIDEO    = 'reloadify_media_compress_video';
	const BACKFILL_META_KEY  = '_reloadify_media_optimized';
	const ORIGINALS_OPTION_KEY = 'reloadify_media_optimize_originals';
	const ORIGINAL_META_KEY = '_reloadify_media_original_optimized';
	const VIDEO_BACKFILL_META_KEY = '_reloadify_media_video_optimized';
	const STATS_META_KEY     = '_reloadify_media_stats';
	const QUALITY_OPTION_KEY = 'reloadify_media_quality';
	const TOTALS_TRANSIENT   = 'reloadify_media_totals';
	const DEFAULT_QUALITY    = 80;
	const BACKFILL_BATCH_SIZE = 3;

	/**
	 * How long one click of "Optimize existing media now" is allowed to keep
	 * working before it hands control back to the browser. The button used to
	 * convert three images per HTTP round trip, which on a library of any real
	 * size meant hundreds of sequential requests and a wait measured in
	 * minutes. Now each request keeps converting until this budget is spent,
	 * so the same library finishes in a fraction of the round trips.
	 */
	const MANUAL_TIME_BUDGET = 12;
	const MANUAL_FETCH       = 12;
	const PREVIEW_WIDTH      = 480;
	const PREVIEW_DIR        = 'reloadify-preview';

	/** Set while a backfill runs, so the (expensive) savings sampling backs off. */
	private static $in_backfill = false;
	private static $need_samples = null;

	/**
	 * Opt-in since 1.2.0 -- conversion rewrites real files in the uploads
	 * folder, so nothing starts running until the site owner turns it on.
	 */
	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, false );
	}

	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;


		add_option( self::OPTION_KEY, false );
		update_option( self::OPTION_KEY, $enabled );

		if ( self::is_enabled() ) {
			self::schedule_backfill();
		} else {
			self::clear_scheduled_events();
		}

		return self::is_enabled();
	}

	/**
	 * 'auto' (default) picks the best format this server actually
	 * supports, WebP first (it encodes far faster). 'webp' / 'avif' let the person pin a specific
	 * format instead of leaving the choice to the plugin.
	 */
	public static function format_preference() {
		$value = get_option( self::FORMAT_OPTION_KEY, 'auto' );
		return in_array( $value, [ 'auto', 'webp', 'avif', 'original' ], true ) ? $value : 'auto';
	}

	public static function set_format_preference( $format ) {
		$format = in_array( $format, [ 'auto', 'webp', 'avif', 'original' ], true ) ? $format : 'auto';
		update_option( self::FORMAT_OPTION_KEY, $format );
		return $format;
	}

	/**
	 * Opt-in (1.4.0): also re-compress the main uploaded file of each JPEG/PNG,
	 * in place and in its own format. Off by default -- originals stay untouched
	 * unless the site owner asks for this, because it replaces real files.
	 */
	public static function optimize_originals() {
		return (bool) get_option( self::ORIGINALS_OPTION_KEY, false );
	}

	public static function set_optimize_originals( $enabled ) {
		update_option( self::ORIGINALS_OPTION_KEY, (bool) $enabled );
		delete_transient( self::TOTALS_TRANSIENT );

		if ( self::optimize_originals() && self::is_enabled() ) {
			self::schedule_backfill();
		}

		return self::optimize_originals();
	}

	/**
	 * Quality ceiling for generated JPEG / WebP / AVIF sizes (40-95, default
	 * 80). It only ever tightens: an encoder whose own default is already lower
	 * is left alone.
	 */
	public static function quality() {
		$value = (int) get_option( self::QUALITY_OPTION_KEY, self::DEFAULT_QUALITY );
		return max( 40, min( 95, $value ) );
	}

	public static function set_quality( $quality ) {
		$quality = max( 40, min( 95, (int) $quality ) );
		update_option( self::QUALITY_OPTION_KEY, $quality );
		delete_transient( self::TOTALS_TRANSIENT );
		return $quality;
	}

	/**
	 * What this server can actually do -- checked, never assumed. Feeds
	 * both items() below and the actual behavior in init().
	 */
	public static function capabilities() {
		static $caps = null;

		if ( null !== $caps ) {
			return $caps;
		}

		$caps = [
			'webp'   => function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ),
			'avif'   => function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( [ 'mime_type' => 'image/avif' ] ),
			'ffmpeg' => (bool) self::ffmpeg_path(),
		];

		return $caps;
	}

	/**
	 * AVIF compresses a little smaller than WebP at the same visual quality when a
	 * host supports it; WebP is the safe, far more common fallback.
	 */
	public static function preferred_image_format() {
		return self::resolve_format( self::format_preference() );
	}

	/**
	 * Which format a given preference actually produces on THIS server
	 * ('original' keeps the uploaded format, so nothing is converted).
	 *
	 * @return string|null 'avif', 'webp', or null for no conversion.
	 */
	public static function resolve_format( $pref ) {
		$caps = self::capabilities();

		if ( 'original' === $pref ) {
			return null;
		}

		if ( 'webp' === $pref ) {
			return ! empty( $caps['webp'] ) ? 'webp' : null;
		}

		if ( 'avif' === $pref ) {
			if ( ! empty( $caps['avif'] ) ) {
				return 'avif';
			}
			return ! empty( $caps['webp'] ) ? 'webp' : null;
		}

		// Auto: WebP. AVIF compresses a little better, but it takes several times
		// longer to encode -- on every upload, for every thumbnail size -- which
		// made uploads and "Optimize existing media" painfully slow. AVIF stays
		// one click away for sites that prefer the smallest files; Auto only uses
		// it when WebP isn't available at all.
		if ( ! empty( $caps['webp'] ) ) {
			return 'webp';
		}

		if ( ! empty( $caps['avif'] ) ) {
			return 'avif';
		}

		return null;
	}

	/**
	 * What's actually included, exposed to the UI so the toggle isn't a
	 * black box -- and honestly reflects what THIS server can do, not a
	 * generic feature list.
	 */
	public static function items() {
		$caps      = self::capabilities();
		$preferred = self::preferred_image_format();

		$items = [];

		$pref = self::format_preference();

		if ( 'original' === $pref ) {
			$items[] = [
				'key'   => 'image_format',
				'label' => __( 'Original format selected: images keep the uploaded format and only the quality ceiling below is applied', 'reloadify-frontend-sync' ),
			];
		} elseif ( $preferred ) {
			if ( 'avif' === $pref && 'webp' === $preferred ) {
				$items[] = [
					'key'   => 'image_format',
					'label' => __( 'AVIF was selected, but this server can\'t produce it, so WebP is used instead — the safe fallback, still smaller than the original format', 'reloadify-frontend-sync' ),
				];
			} else {
				$items[] = [
					'key'   => 'image_format',
					'label' => sprintf(
						/* translators: %s: AVIF or WEBP */
						__( 'New image uploads automatically get %s versions generated alongside the original — supported by the server', 'reloadify-frontend-sync' ),
						strtoupper( $preferred )
					),
				];
			}
		} else {
			$items[] = [
				'key'   => 'image_format',
				'label' => __( 'The server image library does not support WebP or AVIF, so format conversion is skipped — quality-capped compression below still applies', 'reloadify-frontend-sync' ),
			];
		}

		$items[] = [
			'key'   => 'image_quality',
			'label' => sprintf(
				/* translators: %d: quality ceiling in percent */
				__( 'Image quality is capped at %d%% — it only tightens an unusually high setting and never loosens an existing one', 'reloadify-frontend-sync' ),
				self::quality()
			),
		];

		$items[] = [
			'key'   => 'existing_images',
			'label' => __( 'Existing media library images are optimized gradually in the background, a few at a time, so it never blocks or slows down a live site', 'reloadify-frontend-sync' ),
		];

		if ( ! empty( $caps['ffmpeg'] ) ) {
			$items[] = [
				'key'   => 'video',
				'label' => __( 'Newly uploaded video is compressed in the background a few seconds after upload (ffmpeg found on this server) — runs at the lowest OS priority and never blocks the upload itself', 'reloadify-frontend-sync' ),
			];
		} else {
			$items[] = [
				'key'   => 'video',
				'label' => __( 'Video compression needs the ffmpeg program on the server, which isn\'t available here, so video is left untouched — image optimization above still applies', 'reloadify-frontend-sync' ),
			];
		}

		$items[] = [
			'key'   => 'lazy_loading',
			'label' => __( 'Forces native browser lazy-loading on for images (in case a theme or another plugin turned it off) and adds it to embedded video iframes (YouTube, Vimeo, etc.), so offscreen media doesn\'t load until a visitor actually scrolls to it', 'reloadify-frontend-sync' ),
		];

		$items[] = [
			'key'   => 'visibility',
			'label' => __( 'Every optimized image and video gets a real, measured before/after size — visible as an “Optimization” column in the Media Library, not an estimate', 'reloadify-frontend-sync' ),
		];

		return $items;
	}

	/**
	 * Measured savings across optimized images, for the two summary tiles.
	 * Cached for five minutes -- it walks post meta -- and cleared whenever a
	 * new measurement is recorded or the quality changes.
	 *
	 * @return array{images:int,avg_percent:int,saved_bytes:int}
	 */
	public static function stats() {
		$cached = get_transient( self::TOTALS_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'meta_key'       => self::STATS_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded to 300 rows, result cached.
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);

		$count = 0;
		$pct   = 0.0;
		$saved = 0.0;

		foreach ( $ids as $id ) {
			$m = get_post_meta( $id, self::STATS_META_KEY, true );
			if ( ! is_array( $m ) || empty( $m['sample_original_bytes'] ) || empty( $m['sample_optimized_bytes'] ) ) {
				continue;
			}

			$orig = (int) $m['sample_original_bytes'];
			$opt  = (int) $m['sample_optimized_bytes'];
			if ( $orig <= 0 || $opt <= 0 ) {
				continue;
			}

			$count++;
			$pct += max( 0, 100 * ( 1 - $opt / $orig ) );

			// Scale the one measured size up to every generated size of the image.
			$meta        = wp_get_attachment_metadata( $id );
			$sizes_total = 0;
			if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $size ) {
					$sizes_total += isset( $size['filesize'] ) ? (int) $size['filesize'] : 0;
				}
			}

			$ratio  = $orig / $opt;
			$saved += ( $sizes_total > 0 && $ratio > 1 ) ? $sizes_total * ( $ratio - 1 ) : max( 0, $orig - $opt );
		}

		$result = [
			'images'      => $count,
			'avg_percent' => $count ? (int) round( $pct / $count ) : 0,
			'saved_bytes' => (int) round( $saved ),
		];

		set_transient( self::TOTALS_TRANSIENT, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/** One shape for the REST responses and the settings screen. */
	public static function payload() {
		$caps = self::capabilities();

		return [
			'enabled'             => self::is_enabled(),
			'items'               => self::items(),
			'format_preference'   => self::format_preference(),
			'format_capabilities' => [
				'webp' => (bool) $caps['webp'],
				'avif' => (bool) $caps['avif'],
			],
			'quality'             => self::quality(),
			'optimize_originals'  => self::optimize_originals(),
			'stats'               => self::stats(),
			'pending'             => [
				'images' => self::count_pending( 'image' ),
				'videos' => self::count_pending( 'video' ),
			],
		];
	}

	/**
	 * Real before/after for the preview panel: the newest JPEG/PNG in the
	 * library, scaled to preview size, encoded once the way WordPress would
	 * without this plugin and once with the chosen format and quality. Both
	 * sizes are measured, not guessed, and both images come back inline so
	 * moving the slider shows the actual result.
	 *
	 * @param string|null $format  auto|webp|avif|original (default: saved choice).
	 * @param int|null    $quality 40-95 (default: saved choice).
	 * @return array
	 */
	public static function preview( $format = null, $quality = null ) {
		$format  = in_array( $format, [ 'auto', 'webp', 'avif', 'original' ], true ) ? $format : self::format_preference();
		$quality = ( null === $quality ) ? self::quality() : max( 40, min( 95, (int) $quality ) );
		$target  = self::resolve_format( $format );

		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/jpeg', 'image/png' ],
				'posts_per_page' => 8,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			]
		);

		$file = '';
		$id   = 0;
		foreach ( $ids as $candidate ) {
			$path = get_attached_file( $candidate );
			if ( $path && file_exists( $path ) && filesize( $path ) > 4096 ) {
				$file = $path;
				$id   = (int) $candidate;
				break;
			}
		}

		if ( ! $file ) {
			return [ 'available' => false, 'reason' => 'no_image' ];
		}

		$mime   = get_post_mime_type( $id );
		$cache  = self::TOTALS_TRANSIENT . '_p' . md5( $id . '|' . filemtime( $file ) . '|' . (string) $target . '|' . $quality );
		$cached = get_transient( $cache );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$after_mime = $target ? ( 'avif' === $target ? 'image/avif' : 'image/webp' ) : $mime;
		$after_ext  = $target ? $target : ( 'image/png' === $mime ? 'png' : 'jpg' );
		$before_ext = ( 'image/png' === $mime ) ? 'png' : 'jpg';

		// Decoding the full-size original is by far the slowest step (a 12-megapixel
		// photo costs a couple of hundred milliseconds EACH time), and a preview only
		// needs a few hundred pixels. A small copy is made once per image and every
		// slider position after that starts from it.
		$source = self::preview_source( $id, $file, $mime );

		// What WordPress would save without this plugin never changes while the
		// slider moves, so it is measured once per image, not once per position.
		$before_cache = self::TOTALS_TRANSIENT . '_b' . md5( $id . '|' . filemtime( $file ) );
		$before       = get_transient( $before_cache );

		// What an upload would really get: the ceiling only tightens, so an encoder
		// whose own default is already lower keeps it. The preview must show that,
		// not a quality the plugin would never apply.
		$effective = min( $quality, self::default_quality( $after_mime ) );

		$tmp = trailingslashit( get_temp_dir() ) . 'reloadify-preview-' . wp_generate_password( 8, false );

		if ( ! is_array( $before ) ) {
			$before = self::encode_preview( $source, $tmp . '-before.' . $before_ext, $mime, self::default_quality( $mime ) );
			if ( $before ) {
				set_transient( $before_cache, $before, 10 * MINUTE_IN_SECONDS );
			}
		}

		$after = self::encode_preview( $source, $tmp . '-after.' . $after_ext, $after_mime, $effective );

		if ( ! $before || ! $after ) {
			return [ 'available' => false, 'reason' => 'encode_failed' ];
		}

		$meta   = wp_get_attachment_metadata( $id );
		$result = [
			'available'      => true,
			'name'           => wp_basename( $file ),
			'width'          => isset( $meta['width'] ) ? (int) $meta['width'] : $before['width'],
			'height'         => isset( $meta['height'] ) ? (int) $meta['height'] : $before['height'],
			'original_bytes' => (int) filesize( $file ),
			'before'         => $before,
			'after'          => $after,
			'format'         => $target ? $target : 'original',
			'quality'        => $quality,
			'effective_quality' => $effective,
			'saved_percent'  => $before['bytes'] > 0 ? (int) round( ( 1 - $after['bytes'] / $before['bytes'] ) * 100 ) : 0,
		];

		set_transient( $cache, $result, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * A small copy of the image used as the starting point for previews. Kept
	 * in uploads/reloadify-preview (cleaned up with the plugin's cached files);
	 * if it can't be written the original is used, just more slowly.
	 */
	private static function preview_source( $id, $file, $mime ) {
		$upload = wp_upload_dir();

		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
			return $file;
		}

		$dir = trailingslashit( $upload['basedir'] ) . self::PREVIEW_DIR;
		$ext = ( 'image/png' === $mime ) ? 'png' : 'jpg';
		$out = $dir . '/src-' . (int) $id . '-' . substr( md5( (string) filemtime( $file ) ), 0, 8 ) . '.' . $ext;

		if ( file_exists( $out ) ) {
			return $out;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return $file;
		}

		// Old copies of the same image (it was edited) would otherwise pile up.
		foreach ( (array) glob( $dir . '/src-' . (int) $id . '-*' ) as $stale ) {
			wp_delete_file( $stale );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $file;
		}

		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > self::PREVIEW_WIDTH ) {
			$editor->resize( self::PREVIEW_WIDTH, null, false );
		}

		// Near-lossless: it is only the common starting point for both sides.
		remove_filter( 'wp_editor_set_quality', [ __CLASS__, 'cap_quality' ], 10 );
		remove_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10 );
		$editor->set_quality( 95 );
		$saved = $editor->save( $out, $mime );
		if ( self::is_enabled() ) {
			add_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10, 1 );
			add_filter( 'wp_editor_set_quality', [ __CLASS__, 'cap_quality' ], 10, 2 );
		}

		return ( ! is_wp_error( $saved ) && file_exists( $out ) ) ? $out : $file;
	}

	/** WordPress's own default quality per output format. */
	private static function default_quality( $mime ) {
		$defaults = [
			'image/jpeg' => 82,
			'image/webp' => 86,
			'image/avif' => 63,
		];

		return isset( $defaults[ $mime ] ) ? $defaults[ $mime ] : 82;
	}

	/**
	 * Encodes one preview image and returns its size plus an inline data URI,
	 * or null on failure. The temp file never outlives this call.
	 *
	 * @return array|null
	 */
	private static function encode_preview( $source, $dest, $mime, $quality ) {
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$size = $editor->get_size();
		if ( ! empty( $size['width'] ) && $size['width'] > 640 ) {
			$editor->resize( 640, null, false );
		}

		// Our own filters would re-map the format and cap the quality to the SAVED
		// settings -- the preview has to show the settings being tried.
		//
		// The quality goes in through the wp_editor_set_quality filter, not
		// $editor->set_quality(): when the output format differs from the source
		// (JPEG -> WebP) WordPress re-derives the quality at save time, so a value
		// set on the editor is silently ignored -- every slider position would show
		// the same file size.
		$force_quality = static function () use ( $quality ) {
			return $quality;
		};

		// Both are needed: the editor already derived a quality for the source
		// format when it loaded (same-format saves use it), and the filter covers
		// the re-derivation that happens when the format changes.
		$editor->set_quality( $quality );

		remove_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10 );
		remove_filter( 'wp_editor_set_quality', [ __CLASS__, 'cap_quality' ], 10 );
		add_filter( 'wp_editor_set_quality', $force_quality, 99 );

		$saved = $editor->save( $dest, $mime );

		remove_filter( 'wp_editor_set_quality', $force_quality, 99 );
		if ( self::is_enabled() ) {
			add_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10, 1 );
			add_filter( 'wp_editor_set_quality', [ __CLASS__, 'cap_quality' ], 10, 2 );
		}

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return null;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the temp file this method just wrote.
		$bytes_raw = file_get_contents( $saved['path'] );
		$out_mime  = ! empty( $saved['mime-type'] ) ? $saved['mime-type'] : $mime;
		$result    = [
			'bytes'  => (int) filesize( $saved['path'] ),
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
			'mime'   => $out_mime,
			'uri'    => ( false !== $bytes_raw ) ? 'data:' . $out_mime . ';base64,' . base64_encode( $bytes_raw ) : '', // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inline image preview, not obfuscation.
		];

		wp_delete_file( $saved['path'] );

		return $result;
	}

	public static function init() {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_five_minute_schedule' ] );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10, 1 );
		add_filter( 'wp_editor_set_quality', [ __CLASS__, 'cap_quality' ], 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'record_image_stats' ], 20, 2 );
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'mark_converted_on_upload' ], 30, 2 );
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'optimize_original_on_upload' ], 35, 2 );

		add_action( 'add_attachment', [ __CLASS__, 'maybe_schedule_video_compression' ] );
		add_action( self::CRON_HOOK_VIDEO, [ __CLASS__, 'compress_video' ] );
		add_action( self::CRON_HOOK_BACKFILL, [ __CLASS__, 'cron_backfill_batch' ] );
		add_action( self::CRON_HOOK_VIDEO_BACKFILL, [ __CLASS__, 'backfill_existing_videos' ] );

		// Lazy loading.
		add_filter( 'wp_lazy_loading_enabled', '__return_true', 20 );
		add_filter( 'the_content', [ __CLASS__, 'lazy_load_iframes' ], 20 );
		add_filter( 'embed_oembed_html', [ __CLASS__, 'lazy_load_iframes' ] );

		// Media Library "Optimization" column.
		add_filter( 'manage_media_columns', [ __CLASS__, 'add_media_column' ] );
		add_action( 'manage_media_custom_column', [ __CLASS__, 'render_media_column' ], 10, 2 );
		add_action( 'admin_head-upload.php', [ __CLASS__, 'media_column_css' ] );

		self::schedule_backfill();
	}

	public static function deactivate() {
		self::clear_scheduled_events();
	}

	private static function clear_scheduled_events() {
		// wp_unschedule_hook() removes every event on the hook whatever its
		// arguments. The per-video events are scheduled WITH an attachment ID
		// argument, which wp_next_scheduled( $hook ) (no args) never finds, so
		// the old loop left them queued.
		wp_unschedule_hook( self::CRON_HOOK_BACKFILL );
		wp_unschedule_hook( self::CRON_HOOK_VIDEO_BACKFILL );
		wp_unschedule_hook( self::CRON_HOOK_VIDEO );
	}

	private static function schedule_backfill() {
		if ( ! wp_next_scheduled( self::CRON_HOOK_BACKFILL ) ) {
			wp_schedule_event( time() + 60, 'reloadify_five_minutes', self::CRON_HOOK_BACKFILL );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK_VIDEO_BACKFILL ) ) {
			wp_schedule_event( time() + 120, 'reloadify_five_minutes', self::CRON_HOOK_VIDEO_BACKFILL );
		}
	}

	public static function add_five_minute_schedule( $schedules ) {
		if ( ! isset( $schedules['reloadify_five_minutes'] ) ) {
			$schedules['reloadify_five_minutes'] = [
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every 5 minutes (Reloadify Frontend Sync)', 'reloadify-frontend-sync' ),
			];
		}
		return $schedules;
	}

	/* ---------------- Images ---------------- */

	/**
	 * Only remaps the source mimes WordPress already knows how to generate
	 * intermediate sizes for. Leaves the original uploaded file's own mime
	 * type alone -- this only affects the extra generated sizes (thumbnail,
	 * medium, large, etc.), never the original.
	 */
	public static function set_output_format( $formats ) {
		$preferred = self::preferred_image_format();

		if ( ! $preferred ) {
			return $formats;
		}

		$target_mime = ( 'avif' === $preferred ) ? 'image/avif' : 'image/webp';

		$formats['image/jpeg'] = $target_mime;
		$formats['image/png']  = $target_mime;

		return $formats;
	}

	/**
	 * Caps quality at the ceiling chosen in the settings (default 80).
	 */
	public static function cap_quality( $quality, $mime_type ) {
		$capped_mimes = [ 'image/jpeg', 'image/webp', 'image/avif' ];

		if ( ! in_array( $mime_type, $capped_mimes, true ) ) {
			return $quality;
		}

		return min( (int) $quality, self::quality() );
	}

	/**
	 * SQL condition for "this image still needs work": not yet converted, or --
	 * when Optimize original images is on -- its main file not yet handled.
	 */
	private static function image_pending_sql() {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- both fragments are prepared below; they carry no user input.
		$converted = $wpdb->prepare( "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s )", self::BACKFILL_META_KEY );

		if ( ! self::optimize_originals() ) {
			return $converted;
		}

		$original = $wpdb->prepare( "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m2 WHERE m2.post_id = p.ID AND m2.meta_key = %s )", self::ORIGINAL_META_KEY );
		// phpcs:enable

		return '( ' . $converted . ' OR ' . $original . ' )';
	}

	/** Animated PNGs would lose their frames when re-encoded, so they are left alone. */
	private static function is_animated_png( $file ) {
		$head = file_get_contents( $file, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file header read, 8 KB.

		return false !== $head && false !== strpos( $head, 'acTL' );
	}

	/**
	 * Re-compresses one original file in place, in its own format. The new file
	 * only replaces the old one when it is clearly smaller (3 % or more), so a
	 * file can never grow. Returns the bytes saved (0 = left untouched).
	 */
	private static function recompress_in_place( $file, $mime ) {
		if ( ! is_file( $file ) || ! is_writable( $file ) || ( 'image/png' === $mime && self::is_animated_png( $file ) ) ) {
			return 0;
		}

		$editor = wp_get_image_editor( $file );

		if ( is_wp_error( $editor ) ) {
			return 0;
		}

		// Bake an EXIF rotation into the pixels before any metadata can be dropped.
		if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
			$editor->maybe_exif_rotate();
		}

		if ( 'image/jpeg' === $mime ) {
			$editor->set_quality( self::quality() );
		}

		$tmp = trailingslashit( dirname( $file ) ) . '.reloadify-' . wp_generate_password( 8, false ) . ( 'image/png' === $mime ? '.png' : '.jpg' );

		// Our own WebP/AVIF mapping is for generated sizes; the original keeps its format.
		$had_filter = has_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ] );
		if ( false !== $had_filter ) {
			remove_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10 );
		}

		$result = $editor->save( $tmp, $mime );

		if ( false !== $had_filter ) {
			add_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10, 1 );
		}

		if ( is_wp_error( $result ) || empty( $result['path'] ) || ! is_file( $result['path'] ) ) {
			return 0;
		}

		$new = $result['path'];

		if ( empty( $result['mime-type'] ) || $mime !== $result['mime-type'] ) {
			wp_delete_file( $new );
			return 0;
		}

		clearstatcache( true, $file );
		clearstatcache( true, $new );

		$before = (int) filesize( $file );
		$after  = (int) filesize( $new );

		if ( $after > 0 && $after < $before * 0.97 ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same-directory swap of a file this plugin just wrote.
			if ( @rename( $new, $file ) ) {
				clearstatcache( true, $file );
				return $before - $after;
			}
		}

		wp_delete_file( $new );

		return 0;
	}

	/**
	 * Handles the main file of one attachment and marks it done, so the job never
	 * revisits it. Returns the new file size when the file shrank, otherwise 0.
	 */
	private static function optimize_original_file( $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		$mime = get_post_mime_type( $attachment_id );
		$size = 0;

		if ( $file && in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
			$saved = self::recompress_in_place( $file, $mime );

			if ( $saved > 0 ) {
				$size = (int) filesize( $file );
			}

			update_post_meta( $attachment_id, self::ORIGINAL_META_KEY, $saved );
		} else {
			update_post_meta( $attachment_id, self::ORIGINAL_META_KEY, 0 );
		}

		return $size;
	}

	/** New uploads: shrink the main file after the generated sizes exist (they stay based on the pristine original). */
	public static function optimize_original_on_upload( $metadata, $attachment_id ) {
		if ( ! self::optimize_originals() || metadata_exists( 'post', $attachment_id, self::ORIGINAL_META_KEY ) ) {
			return $metadata;
		}

		$size = self::optimize_original_file( $attachment_id );

		if ( $size > 0 && is_array( $metadata ) ) {
			$metadata['filesize'] = $size;
		}

		return $metadata;
	}

	/**
	 * IDs still waiting on the background job. One plain NOT EXISTS query: the
	 * WP_Query meta_query this replaced built a LEFT JOIN that gets slow on a
	 * large library, and it ran on every round trip.
	 *
	 * @return int[]
	 */
	private static function pending_ids( $type, $limit ) {
		global $wpdb;

		$limit = max( 1, (int) $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded maintenance query over attachments; result is never cacheable between batches.
		if ( 'video' === $type ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s
					AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s )
					ORDER BY p.ID ASC LIMIT %d",
					'video/%',
					self::VIDEO_BACKFILL_META_KEY,
					$limit
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN ( 'image/jpeg', 'image/png' )
					AND " . self::image_pending_sql() . "
					ORDER BY p.ID ASC LIMIT %d",
					$limit
				)
			);
		}
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Has this image already been converted? Images uploaded while the feature
	 * was on, or processed by an earlier run, need no second pass -- regenerating
	 * every thumbnail again was the bulk of the time "Optimize existing media"
	 * spent.
	 */
	private static function sizes_already_converted( $attachment_id, $target ) {
		$meta = wp_get_attachment_metadata( $attachment_id );

		// Nothing to convert (an image smaller than every thumbnail size).
		if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || ! is_array( $meta['sizes'] ) ) {
			return true;
		}

		$mime = ( 'avif' === $target ) ? 'image/avif' : 'image/webp';

		foreach ( $meta['sizes'] as $size ) {
			if ( empty( $size['mime-type'] ) || $mime !== $size['mime-type'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Converts pending images, stopping when the deadline passes. Returns how
	 * many images left the pending list (converted or found already done).
	 */
	public static function backfill_existing_images( $batch_size = 0, $deadline = 0 ) {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		$batch_size = $batch_size > 0 ? (int) $batch_size : self::BACKFILL_BATCH_SIZE;

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		// With no WebP/AVIF support on this server there is nothing to convert
		// to, and regenerating every thumbnail would burn CPU and rewrite files
		// for no gain -- just record the image as checked.
		$target       = self::preferred_image_format();
		$done         = 0;
		self::$in_backfill = true;

		foreach ( self::pending_ids( 'image', $batch_size ) as $attachment_id ) {
			if ( $deadline && microtime( true ) >= $deadline ) {
				break;
			}

			$file = get_attached_file( $attachment_id );

			if ( $target && $file && file_exists( $file ) && ! self::sizes_already_converted( $attachment_id, $target ) ) {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
				if ( $metadata ) {
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}

			// Main file last, so the generated sizes above came from the untouched original.
			if ( self::optimize_originals() && ! metadata_exists( 'post', $attachment_id, self::ORIGINAL_META_KEY ) ) {
				$new_size = self::optimize_original_file( $attachment_id );

				if ( $new_size > 0 ) {
					$meta = wp_get_attachment_metadata( $attachment_id );
					if ( is_array( $meta ) ) {
						$meta['filesize'] = $new_size;
						wp_update_attachment_metadata( $attachment_id, $meta );
					}
				}
			}

			update_post_meta( $attachment_id, self::BACKFILL_META_KEY, 1 );
			$done++;
		}

		self::$in_backfill = false;

		return $done;
	}

	/**
	 * WP-Cron runner. Works through the queue for a short budget, then books
	 * itself again so conversion carries on in the background without anyone
	 * keeping a browser tab open.
	 */
	public static function cron_backfill_batch() {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$deadline = microtime( true ) + 20;
		$done     = self::backfill_existing_images( self::MANUAL_FETCH, $deadline );

		if ( $done > 0 && self::count_pending( 'image' ) > 0 && ! wp_next_scheduled( self::CRON_HOOK_BACKFILL, [] ) ) {
			wp_schedule_single_event( time() + 15, self::CRON_HOOK_BACKFILL );
		}
	}

	/**
	 * Records an image uploaded while the feature is on as already optimized,
	 * so the backfill doesn't convert it a second time.
	 */
	public static function mark_converted_on_upload( $metadata, $attachment_id ) {
		$target = self::preferred_image_format();

		if ( $target && in_array( get_post_mime_type( $attachment_id ), [ 'image/jpeg', 'image/png' ], true )
			&& is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
			$mime = ( 'avif' === $target ) ? 'image/avif' : 'image/webp';
			$all  = true;

			foreach ( $metadata['sizes'] as $size ) {
				if ( empty( $size['mime-type'] ) || $mime !== $size['mime-type'] ) {
					$all = false;
					break;
				}
			}

			if ( $all ) {
				update_post_meta( $attachment_id, self::BACKFILL_META_KEY, 1 );
			}
		}

		return $metadata;
	}

	/**
	 * How many existing images are still waiting on the background job.
	 */
	public static function count_pending( $type ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap COUNT used by the progress display; a cached value would be wrong a second later.
		if ( 'video' === $type ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p
					WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s
					AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s )",
					'video/%',
					self::VIDEO_BACKFILL_META_KEY
				)
			);
		}

		// The only variable part is image_pending_sql(), which is already prepared; a second prepare() would have no placeholder to fill.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type IN ( 'image/jpeg', 'image/png' )
			AND " . self::image_pending_sql() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		// phpcs:enable
	}

	/**
	 * Runs one batch right now instead of waiting for WP-Cron's next tick --
	 * WP-Cron only fires on real site traffic (or a configured system cron),
	 * so a low-traffic or local site can sit with existing media
	 * unprocessed for a long time otherwise. Triggered only by an explicit
	 * admin click (the "Optimize existing media now" button), never
	 * automatically. Runs one video synchronously too (see
	 * backfill_one_video_now()) rather than only scheduling it, for the
	 * same reason.
	 */
	public static function run_backfill_batch_now() {
		if ( ! self::is_enabled() ) {
			return [
				'enabled'           => false,
				'images_processed'  => 0,
				'images_remaining'  => 0,
				'videos_remaining'  => 0,
				'videos_unavailable' => 0,
				'ffmpeg_available'  => false,
			];
		}

		// Image conversion is CPU- and memory-hungry; give this request as much
		// headroom as the host will allow before starting.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- explicit admin-triggered batch job; without this a long batch dies mid-conversion.
			@set_time_limit( 0 );
		}

		$deadline         = microtime( true ) + self::MANUAL_TIME_BUDGET;
		$images_processed = 0;

		// Keep converting for as long as the budget allows instead of stopping
		// after a single small batch -- far fewer HTTP round trips for the same
		// number of images.
		do {
			$converted         = self::backfill_existing_images( self::MANUAL_FETCH, $deadline );
			$images_processed += $converted;
		} while ( $converted > 0 && microtime( true ) < $deadline );

		$images_remaining = self::count_pending( 'image' );

		// Video compression is much slower again, so it only starts once the
		// images are done -- otherwise a single video stalls the whole run.
		if ( 0 === $images_remaining ) {
			self::backfill_one_video_now();
		}

		$video_status = self::video_backfill_status();

		return [
			'enabled'            => true,
			'images_processed'   => $images_processed,
			'images_remaining'   => $images_remaining,
			'videos_remaining'   => $video_status['remaining'],
			'videos_unavailable' => $video_status['unavailable'],
			'ffmpeg_available'   => (bool) self::ffmpeg_path(),
		];
	}

	/**
	 * The savings figure is an average, so it does not need a measurement for
	 * every image. Measuring one means decoding the full-size original a second
	 * time and encoding it again -- on a 12-megapixel photo that was close to half
	 * of the total upload time. A few measurements get the tile started, after
	 * that one image in five keeps it current.
	 */
	private static function should_sample_stats( $attachment_id ) {
		if ( null === self::$need_samples ) {
			$stats               = self::stats();
			self::$need_samples = ( $stats['images'] < 8 );
		}

		if ( self::$need_samples ) {
			return true;
		}

		return 0 === ( (int) $attachment_id % ( self::$in_backfill ? 10 : 5 ) );
	}

	public static function record_image_stats( $metadata, $attachment_id ) {
		$preferred = self::preferred_image_format();

		if ( ! $preferred || empty( $metadata['sizes'] ) || empty( $metadata['file'] ) ) {
			return $metadata;
		}

		if ( ! self::should_sample_stats( $attachment_id ) ) {
			return $metadata;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
			return $metadata;
		}

		$original_file = get_attached_file( $attachment_id );
		if ( ! $original_file || ! file_exists( $original_file ) ) {
			return $metadata;
		}

		$base_dir = trailingslashit( dirname( $original_file ) );
		$sizes    = $metadata['sizes'];
		$sample   = isset( $sizes['medium'] ) ? $sizes['medium'] : reset( $sizes );

		if ( empty( $sample['file'] ) || empty( $sample['width'] ) || empty( $sample['height'] ) ) {
			return $metadata;
		}

		$optimized_path = $base_dir . $sample['file'];
		if ( ! file_exists( $optimized_path ) ) {
			return $metadata;
		}
		$optimized_bytes = filesize( $optimized_path );

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$editor = wp_get_image_editor( $original_file );
		if ( is_wp_error( $editor ) ) {
			return $metadata;
		}

		$editor->resize( (int) $sample['width'], (int) $sample['height'], false );
		$ext           = ( 'image/png' === $mime ) ? 'png' : 'jpg';
		$comparison_path = $base_dir . 'reloadify-media-compare-' . $attachment_id . '.' . $ext;

		// save() runs the same image_editor_output_format filter this class
		// registers, so without this the "before" file would be converted to
		// WebP/AVIF too and the comparison would measure WebP against WebP
		// (always ~0% saved). Step aside for this one save.
		remove_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10 );
		$saved = $editor->save( $comparison_path, $mime );
		add_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10, 1 );

		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			if ( file_exists( $comparison_path ) ) {
				wp_delete_file( $comparison_path ); // In case a partial file was left behind.
			}
			return $metadata;
		}

		$comparison_bytes = filesize( $saved['path'] );
		wp_delete_file( $saved['path'] ); // Comparison file only ever existed to be measured -- never kept or served.

		if ( $comparison_bytes <= 0 ) {
			return $metadata;
		}

		$percent_saved = round( ( 1 - ( $optimized_bytes / $comparison_bytes ) ) * 100 );

		delete_transient( self::TOTALS_TRANSIENT );

		update_post_meta( $attachment_id, self::STATS_META_KEY, [
			'type'                   => 'image',
			'format'                 => $preferred,
			'sample_size'            => isset( $sizes['medium'] ) ? 'medium' : key( $sizes ),
			'sample_original_bytes'  => $comparison_bytes,
			'sample_optimized_bytes' => $optimized_bytes,
			'percent_saved'          => $percent_saved,
			'measured_at'            => time(),
		] );

		return $metadata;
	}

	/* ---------------- Video ---------------- */

	public static function maybe_schedule_video_compression( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );

		if ( ! $mime || 0 !== strpos( $mime, 'video/' ) ) {
			return;
		}

		if ( ! self::ffmpeg_path() ) {
			// Not available on this server -- leave the video file alone
			// (don't fake it), but DO mark it as checked so it stops
			// showing up as "pending" forever. Nothing about re-checking
			// it will change unless the server itself changes.
			if ( '' === get_post_meta( $attachment_id, self::VIDEO_BACKFILL_META_KEY, true ) ) {
				update_post_meta( $attachment_id, self::VIDEO_BACKFILL_META_KEY, 'unavailable' );
			}
			return;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK_VIDEO, [ $attachment_id ] ) ) {
			wp_schedule_single_event( time() + 10, self::CRON_HOOK_VIDEO, [ $attachment_id ] );
		}
	}

	/**
	 * Compresses one video, deferred to WP-Cron so it never runs inside the
	 * person's actual upload request. Only ever swaps the file if ffmpeg
	 * reports success AND the result is a valid, smaller file -- otherwise
	 * the original is left exactly as it was.
	 */
	public static function compress_video( $attachment_id ) {
		$ffmpeg = self::ffmpeg_path();

		if ( ! $ffmpeg ) {
			return;
		}

		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			// Mark it checked, otherwise the backfill keeps re-selecting the same
			// unusable attachment and never reaches the ones behind it.
			update_post_meta( $attachment_id, self::VIDEO_BACKFILL_META_KEY, 1 );
			return;
		}

		// The result is an MP4 written over the original file, so only files
		// that already ARE mp4 are safe to replace in place. A .webm/.mov/.avi
		// holding MP4 data would stop playing.
		$extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
		if ( ! in_array( $extension, [ 'mp4', 'm4v' ], true ) ) {
			update_post_meta( $attachment_id, self::VIDEO_BACKFILL_META_KEY, 1 );
			return;
		}

		$original_size = filesize( $file );

		if ( ! $original_size ) {
			update_post_meta( $attachment_id, self::VIDEO_BACKFILL_META_KEY, 1 );
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- background video job; without this a long encode can be cut off mid-way.
			@set_time_limit( 0 );
		}

		$tmp_out       = $file . '.reloadify-optimized.mp4';

	
		$is_windows = ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) );
		$cmd        = sprintf(
			'%s%s -y -i %s -c:v libx264 -crf 26 -preset veryfast -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
			$is_windows ? '' : 'nice -n 19 ',
			// escapeshellarg(), not escapeshellcmd(): the latter doesn't quote, so an
			// ffmpeg path containing spaces (common on Windows) breaks.
			escapeshellarg( $ffmpeg ),
			escapeshellarg( $file ),
			escapeshellarg( $tmp_out )
		);

		$output    = [];
		$exit_code = 1;

		@exec( $cmd, $output, $exit_code );

	
		$succeeded = ( 0 === $exit_code )
			&& file_exists( $tmp_out )
			&& filesize( $tmp_out ) > ( $original_size * 0.02 )
			&& filesize( $tmp_out ) < $original_size;

		if ( $succeeded ) {
			$optimized_size = filesize( $tmp_out );

			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			global $wp_filesystem;

			$moved = $wp_filesystem && $wp_filesystem->move( $tmp_out, $file, true );

			if ( $moved ) {
				clean_attachment_cache( $attachment_id );

				$attachment_meta = wp_get_attachment_metadata( $attachment_id );
				if ( is_array( $attachment_meta ) && isset( $attachment_meta['filesize'] ) ) {
					$attachment_meta['filesize'] = $optimized_size;
					wp_update_attachment_metadata( $attachment_id, $attachment_meta );
				}

				update_post_meta( $attachment_id, self::STATS_META_KEY, [
					'type'              => 'video',
					'original_bytes'    => $original_size,
					'optimized_bytes'   => $optimized_size,
					'percent_saved'     => round( ( 1 - ( $optimized_size / $original_size ) ) * 100 ),
					'measured_at'       => time(),
				] );
			} elseif ( file_exists( $tmp_out ) ) {
				wp_delete_file( $tmp_out );
			}
		} elseif ( file_exists( $tmp_out ) ) {
			wp_delete_file( $tmp_out );
		}

		update_post_meta( $attachment_id, self::VIDEO_BACKFILL_META_KEY, 1 );
	}

	/**
	 * Splits video backfill state into two real, distinguishable numbers
	 * instead of one ambiguous "remaining" count: videos still waiting to
	 * be checked, vs. videos already checked where this specific server
	 * genuinely can't compress them (no ffmpeg). The UI uses this to show
	 * green ("done") vs red ("blocked, here's why") instead of guessing.
	 */
	public static function video_backfill_status() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cheap COUNT for the progress display.
		$unavailable = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
				WHERE p.post_type = 'attachment' AND p.post_status = 'inherit' AND p.post_mime_type LIKE %s",
				self::VIDEO_BACKFILL_META_KEY,
				'unavailable',
				'video/%'
			)
		);
		// phpcs:enable

		return [
			'remaining'   => self::count_pending( 'video' ),
			'unavailable' => $unavailable,
		];
	}

	private static function backfill_one_video_now() {
		if ( ! self::ffmpeg_path() ) {
			// Mark any still-pending videos as checked (not endlessly
			// "pending") so the count reflects reality: not possible here,
			// not "still working on it".
			$query = new WP_Query( [
				'post_type'      => 'attachment',
				'post_mime_type' => 'video',
				'post_status'    => 'inherit',
				'posts_per_page' => 1000, // Bulk "mark as checked" only, not per-page filtering -- capped so one click on a very large library can't load every video ID into memory at once; a second click picks up any remainder.
				'fields'         => 'ids',
				
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single indexed meta key ('NOT EXISTS'/'EXISTS' lookup) used only in an infrequent background backfill job, not on hot request paths.
				'meta_query'     => [
					[
						'key'     => self::VIDEO_BACKFILL_META_KEY,
						'compare' => 'NOT EXISTS',
					],
				],
			] );
			foreach ( $query->posts as $attachment_id ) {
				update_post_meta( $attachment_id, self::VIDEO_BACKFILL_META_KEY, 'unavailable' );
			}
			return 0;
		}

		$query = new WP_Query( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'video',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
		
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single indexed meta key ('NOT EXISTS'/'EXISTS' lookup) used only in an infrequent background backfill job, not on hot request paths.
			'meta_query'     => [
				[
					'key'     => self::VIDEO_BACKFILL_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		if ( empty( $query->posts ) ) {
			return 0;
		}

		self::compress_video( $query->posts[0] );
		return 1;
	}

	public static function backfill_existing_videos() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$query = new WP_Query( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'video',
			'post_status'    => 'inherit',
			'posts_per_page' => self::BACKFILL_BATCH_SIZE,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'cache_results'  => false,
			
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single indexed meta key ('NOT EXISTS'/'EXISTS' lookup) used only in an infrequent background backfill job, not on hot request paths.
			'meta_query'     => [
				[
					'key'     => self::VIDEO_BACKFILL_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		foreach ( $query->posts as $attachment_id ) {
			self::maybe_schedule_video_compression( $attachment_id );
		}
	}

	/**
	 * Feature-detects ffmpeg itself: whether the PHP functions needed to
	 * run it are even callable (a lot of shared hosts disable exec/
	 * shell_exec outright), and if so, whether the binary can actually be
	 * found. Returns false rather than guessing the moment either check
	 * fails.
	 */
	public static function ffmpeg_path() {
		static $checked = false;
		static $path    = false;

		if ( $checked ) {
			return $path;
		}
		$checked = true;

		if ( ! function_exists( 'exec' ) ) {
			return false;
		}

		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		if ( in_array( 'exec', $disabled, true ) ) {
			return false;
		}

		$is_windows = ( 0 === stripos( PHP_OS, 'WIN' ) );

		$candidates = $is_windows
			? [ 'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe' ]
			: [ '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg', '/usr/local/sbin/ffmpeg', '/snap/bin/ffmpeg' ];

		foreach ( $candidates as $candidate ) {
			if ( is_file( $candidate ) && ( $is_windows || is_executable( $candidate ) ) ) {
				$path = $candidate;
				return $path;
			}
		}

		// `command -v` only works on Unix-like shells; Windows needs `where`.
		// Try both rather than assuming the OS -- some Windows local-dev
		// stacks (WSL-backed ones especially) still run a Unix-style shell.
		$lookup_commands = $is_windows
			? [ 'where ffmpeg 2>NUL', 'command -v ffmpeg 2>/dev/null' ]
			: [ 'command -v ffmpeg 2>/dev/null', 'which ffmpeg 2>/dev/null' ];

		foreach ( $lookup_commands as $lookup ) {
			$output    = [];
			$exit_code = 1;
		
			@exec( $lookup, $output, $exit_code );

			if ( 0 === $exit_code && ! empty( $output[0] ) ) {
				$path = trim( $output[0] );
				return $path;
			}
		}

		return $path;
	}

	/* ---------------- Lazy loading ---------------- */
	
	public static function lazy_load_iframes( $html ) {
		if ( empty( $html ) || false === stripos( $html, '<iframe' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'/<iframe\b([^>]*)>/i',
			function ( $matches ) {
				if ( preg_match( '/\bloading\s*=/i', $matches[1] ) ) {
					return $matches[0]; // Already has its own loading attribute -- leave it alone.
				}
				return '<iframe' . $matches[1] . ' loading="lazy">';
			},
			$html
		);
	}

	/* ---------------- Media Library visibility ---------------- */

	public static function add_media_column( $columns ) {
		$columns['reloadify_media_optimize'] = __( 'Optimization', 'reloadify-frontend-sync' );
		return $columns;
	}

	public static function media_column_css() {
		echo '<style>
			.reloadify-media-badge { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 11px; line-height: 1.6; background: #f0f0f1; color: #50575e; white-space: nowrap; }
			.reloadify-media-badge--ok { background: #edfaef; color: #1a7f37; }
			.reloadify-media-badge--muted { background: transparent; color: #a7aaad; padding-left: 0; }
		</style>';
	}

	public static function render_media_column( $column_name, $attachment_id ) {
		if ( 'reloadify_media_optimize' !== $column_name ) {
			return;
		}

		$mime  = get_post_mime_type( $attachment_id );
		$stats = get_post_meta( $attachment_id, self::STATS_META_KEY, true );

		if ( is_array( $stats ) && isset( $stats['percent_saved'] ) ) {
			$percent = (int) $stats['percent_saved'];
			$label   = ( 'video' === $stats['type'] )
				? sprintf(
					/* translators: %d: percent smaller */
					__( 'Video compressed – %d%% smaller', 'reloadify-frontend-sync' ),
					max( 0, $percent )
				)
				: sprintf(
					/* translators: 1: WEBP or AVIF, 2: percent smaller */
					__( '%1$s – about %2$d%% smaller', 'reloadify-frontend-sync' ),
					strtoupper( $stats['format'] ),
					max( 0, $percent )
				);
			echo '<span class="reloadify-media-badge reloadify-media-badge--ok">' . esc_html( $label ) . '</span>';
			return;
		}

		if ( 0 === strpos( (string) $mime, 'image/jpeg' ) || 0 === strpos( (string) $mime, 'image/png' ) ) {
			if ( ! self::preferred_image_format() ) {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Original kept – server has no WebP/AVIF support', 'reloadify-frontend-sync' ) . '</span>';
			} else {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Pending – optimizes in the background shortly', 'reloadify-frontend-sync' ) . '</span>';
			}
			return;
		}

		if ( 0 === strpos( (string) $mime, 'video/' ) ) {
			if ( ! self::ffmpeg_path() ) {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Not compressed – ffmpeg unavailable on this server', 'reloadify-frontend-sync' ) . '</span>';
			} else {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Pending – compresses in the background shortly', 'reloadify-frontend-sync' ) . '</span>';
			}
			return;
		}

		echo '<span class="reloadify-media-badge reloadify-media-badge--muted">' . esc_html__( 'Not an image or video', 'reloadify-frontend-sync' ) . '</span>';
	}
}
