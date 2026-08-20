<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Media Optimization" — on by default. Shrinks images and (where possible)
 * video without a visible quality hit, so uploading media doesn't turn into
 * the heaviest thing happening on the site.
 *
 * Same honesty rule as Reloadify_Speed: nothing here is faked, and nothing runs
 * unless this server can actually do it.
 *
 * - Images: uses WordPress's own image editor (GD or Imagick, whichever the
 *   host has) to save newly generated thumbnail/intermediate sizes as WebP,
 *   or AVIF if the host's image library supports it -- checked with core's
 *   own wp_image_editor_supports(), never assumed. The original uploaded
 *   file is left untouched; only the generated sizes change format. Quality
 *   is capped (never raised) at a level generally considered visually
 *   lossless, so this only ever tightens an unusually high setting, never
 *   loosens a lower one someone already chose on purpose.
 * - Existing images (uploaded before this was ever turned on): picked up a
 *   few at a time by a WP-Cron job every 5 minutes, not all at once, so a
 *   large media library can't turn into one huge blocking job on a live
 *   site.
 * - Video: genuinely compressing video needs the ffmpeg program on the
 *   server. Most shared hosting does not have it, and a lot of hosts
 *   disable the PHP functions (exec/shell_exec) needed to run it even when
 *   it's installed. Both are feature-detected in ffmpeg_path() below; if
 *   either is missing, video is simply left alone -- no fake "optimized"
 *   claim. When it IS available, the compression job is deferred to
 *   WP-Cron (runs a few seconds after upload, in its own request) so it
 *   never blocks the person's upload, and runs at the lowest OS scheduling
 *   priority (`nice -n 19`) so it competes as little as possible with
 *   requests other visitors are making at the same time. Existing videos
 *   (from before this was turned on) are picked up the same gradual way
 *   existing images are.
 * - Lazy loading: WordPress has loaded images natively (loading="lazy")
 *   since 5.5, but another plugin, theme, or filter can turn that off --
 *   this re-forces it on while the toggle is enabled. It also adds
 *   loading="lazy" to embedded video iframes (YouTube, Vimeo, etc. added
 *   via the block editor's embed feature), which core does not cover on
 *   its own and which are often the single heaviest thing on a page.
 * - Visibility: every optimized image and video gets a real, measured
 *   before/after size stored against it (not an estimate) -- visible as a
 *   new "Optimization" column in the Media Library list.
 */
class Reloadify_Media {

	const OPTION_KEY        = 'reloadify_media_optimize_enabled';
	const CRON_HOOK_BACKFILL = 'reloadify_media_backfill_batch';
	const CRON_HOOK_VIDEO_BACKFILL = 'reloadify_media_backfill_video_batch';
	const CRON_HOOK_VIDEO    = 'reloadify_media_compress_video';
	const BACKFILL_META_KEY  = '_reloadify_media_optimized';
	const VIDEO_BACKFILL_META_KEY = '_reloadify_media_video_optimized';
	const STATS_META_KEY     = '_reloadify_media_stats';
	const BACKFILL_BATCH_SIZE = 3;

	public static function is_enabled() {
		return (bool) get_option( self::OPTION_KEY, true );
	}

	public static function set_enabled( $enabled ) {
		$enabled = (bool) $enabled;

		// Same WordPress quirk worked around elsewhere in this plugin
		// (Reloadify_Speed, Reloadify_Cleanup): update_option() no-ops on a brand-new
		// `false` value if the option row doesn't exist yet. Seed it first.
		add_option( self::OPTION_KEY, true );
		update_option( self::OPTION_KEY, $enabled );

		if ( self::is_enabled() ) {
			self::schedule_backfill();
		} else {
			self::clear_scheduled_events();
		}

		return self::is_enabled();
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
	 * AVIF compresses smaller than WebP at the same visual quality when a
	 * host supports it; WebP is the safe, far more common fallback; if
	 * neither is available, images are left in their original format
	 * (still quality-capped, just not format-converted).
	 */
	public static function preferred_image_format() {
		$caps = self::capabilities();

		if ( ! empty( $caps['avif'] ) ) {
			return 'avif';
		}

		if ( ! empty( $caps['webp'] ) ) {
			return 'webp';
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

		if ( $preferred ) {
			$items[] = [
				'key'   => 'image_format',
				'label' => sprintf(
					/* translators: %s: AVIF or WEBP */
					__( 'New image uploads automatically get %s versions generated alongside the original — your server supports it', 'reloadify-frontend-sync' ),
					strtoupper( $preferred )
				),
			];
		} else {
			$items[] = [
				'key'   => 'image_format',
				'label' => __( 'Your server\'s image library doesn\'t support WebP or AVIF, so format conversion is skipped — quality-capped compression below still applies', 'reloadify-frontend-sync' ),
			];
		}

		$items[] = [
			'key'   => 'image_quality',
			'label' => __( 'Image quality is capped at a visually-lossless level — only ever tightens an unusually high setting, never loosens one you already chose', 'reloadify-frontend-sync' ),
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

	public static function init() {
		add_filter( 'cron_schedules', [ __CLASS__, 'add_five_minute_schedule' ] );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'image_editor_output_format', [ __CLASS__, 'set_output_format' ], 10, 1 );
		add_filter( 'wp_editor_set_quality', [ __CLASS__, 'cap_quality' ], 10, 2 );
		add_filter( 'wp_generate_attachment_metadata', [ __CLASS__, 'record_image_stats' ], 20, 2 );

		add_action( 'add_attachment', [ __CLASS__, 'maybe_schedule_video_compression' ] );
		add_action( self::CRON_HOOK_VIDEO, [ __CLASS__, 'compress_video' ] );
		add_action( self::CRON_HOOK_BACKFILL, [ __CLASS__, 'backfill_existing_images' ] );
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
		wp_clear_scheduled_hook( self::CRON_HOOK_BACKFILL );
		wp_clear_scheduled_hook( self::CRON_HOOK_VIDEO_BACKFILL );

		$timestamp = wp_next_scheduled( self::CRON_HOOK_VIDEO );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK_VIDEO );
			$timestamp = wp_next_scheduled( self::CRON_HOOK_VIDEO );
		}
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
	 * Caps quality at 82 -- WordPress's own long-standing default JPEG
	 * quality, and a level broadly considered visually indistinguishable
	 * from higher settings. Only ever lowers a value above that; never
	 * raises a lower value someone (a theme, another plugin, the host)
	 * already configured on purpose.
	 */
	public static function cap_quality( $quality, $mime_type ) {
		$capped_mimes = [ 'image/jpeg', 'image/webp', 'image/avif' ];

		if ( ! in_array( $mime_type, $capped_mimes, true ) ) {
			return $quality;
		}

		return min( (int) $quality, 82 );
	}

	/**
	 * Runs a few existing (pre-dating this feature, or added while it was
	 * off) images through the normal WordPress thumbnail regenerator --
	 * which, with the filters above active, produces WebP/AVIF sizes for
	 * them too -- a handful at a time so this can never become one long
	 * blocking job on a live site. Marks each one processed so it's never
	 * repeated.
	 */
	public static function backfill_existing_images() {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$query = new WP_Query( [
			'post_type'      => 'attachment',
			'post_mime_type' => [ 'image/jpeg', 'image/png' ],
			'post_status'    => 'inherit',
			'posts_per_page' => self::BACKFILL_BATCH_SIZE,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- there is no non-meta way to ask "which attachments are missing this meta key"; capped batch size (posts_per_page above), runs only in a deferred WP-Cron job.
			'meta_query'     => [
				[
					'key'     => self::BACKFILL_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		foreach ( $query->posts as $attachment_id ) {
			$file = get_attached_file( $attachment_id );

			if ( $file && file_exists( $file ) ) {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
				if ( $metadata ) {
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}

			update_post_meta( $attachment_id, self::BACKFILL_META_KEY, 1 );
		}

		return count( $query->posts );
	}

	/**
	 * How many existing images are still waiting on the background job.
	 */
	public static function count_pending( $type ) {
		$args = [
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- there is no non-meta way to ask "which attachments are missing this meta key"; posts_per_page => 1 above bounds this to a single-row lookup.
			'meta_query'     => [
				[
					'key'     => ( 'video' === $type ) ? self::VIDEO_BACKFILL_META_KEY : self::BACKFILL_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		];

		$args['post_mime_type'] = ( 'video' === $type ) ? 'video' : [ 'image/jpeg', 'image/png' ];

		$query = new WP_Query( $args );
		return (int) $query->found_posts;
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

		$images_processed = self::backfill_existing_images();
		self::backfill_one_video_now();

		$video_status = self::video_backfill_status();

		return [
			'enabled'            => true,
			'images_processed'   => $images_processed,
			'images_remaining'   => self::count_pending( 'image' ),
			'videos_remaining'   => $video_status['remaining'],
			'videos_unavailable' => $video_status['unavailable'],
			'ffmpeg_available'   => (bool) self::ffmpeg_path(),
		];
	}

	/**
	 * Measures a REAL before/after size, not an estimate. Runs once per
	 * image, right after WordPress finishes generating its thumbnail sizes
	 * (with the WebP/AVIF filters above already applied to that process).
	 * To get a genuine comparison, it re-saves ONE representative size
	 * (medium, or the largest available) in the image's original format,
	 * purely to measure it, then immediately deletes that comparison file
	 * -- it's never kept or served. This is one extra resize per upload
	 * (not per size, and not per page load), which is why it's limited to
	 * a single representative size rather than every size.
	 */
	public static function record_image_stats( $metadata, $attachment_id ) {
		$preferred = self::preferred_image_format();

		if ( ! $preferred || empty( $metadata['sizes'] ) || empty( $metadata['file'] ) ) {
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
		$saved          = $editor->save( $comparison_path, $mime );

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
			return;
		}

		$original_size = filesize( $file );

		if ( ! $original_size ) {
			return;
		}

		$tmp_out       = $file . '.reloadify-optimized.mp4';

		// -crf 26 with libx264 is a standard "visually near-lossless" web
		// encode setting; -preset veryfast trades a little compression
		// efficiency for a much shorter run time, which matters more here
		// since this runs on the live server, not offline; +faststart
		// moves metadata to the front of the file so it starts playing
		// before it's fully downloaded.
		$cmd = sprintf(
			'nice -n 19 %s -y -i %s -c:v libx264 -crf 26 -preset veryfast -c:a aac -b:a 128k -movflags +faststart %s 2>&1',
			escapeshellcmd( $ffmpeg ),
			escapeshellarg( $file ),
			escapeshellarg( $tmp_out )
		);

		$output    = [];
		$exit_code = 1;

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- exec() is required to invoke ffmpeg; guarded by ffmpeg_path()'s own feature/availability detection, runs only inside a deferred WP-Cron request, and only ever compresses this plugin's own uploaded video files with fully-escaped arguments.
		@exec( $cmd, $output, $exit_code );

		$succeeded = ( 0 === $exit_code )
			&& file_exists( $tmp_out )
			&& filesize( $tmp_out ) > 0
			&& filesize( $tmp_out ) < $original_size;

		if ( $succeeded ) {
			$optimized_size = filesize( $tmp_out );

			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
			global $wp_filesystem;

			$moved = $wp_filesystem && $wp_filesystem->move( $tmp_out, $file, true );

			if ( $moved ) {
				clean_attachment_cache( $attachment_id );

				update_post_meta( $attachment_id, self::STATS_META_KEY, [
					'type'              => 'video',
					'original_bytes'    => $original_size,
					'optimized_bytes'   => $optimized_size,
					'percent_saved'     => round( ( 1 - ( $optimized_size / $original_size ) ) * 100 ),
					'measured_at'       => time(),
				] );
			} elseif ( file_exists( $tmp_out ) ) {
				// The compressed file was produced but couldn't replace the
				// original (permissions, disk, etc.) -- leave the original
				// video untouched and just clean up the leftover temp file.
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
		$remaining = new WP_Query( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'video',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- there is no non-meta way to ask "which attachments are missing this meta key"; posts_per_page => 1 above bounds this to a single-row lookup.
			'meta_query'     => [
				[
					'key'     => self::VIDEO_BACKFILL_META_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		$unavailable = new WP_Query( [
			'post_type'      => 'attachment',
			'post_mime_type' => 'video',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- there is no non-meta way to ask "which attachments are missing this meta key"; posts_per_page => 1 above bounds this to a single-row lookup.
			'meta_query'     => [
				[
					'key'     => self::VIDEO_BACKFILL_META_KEY,
					'value'   => 'unavailable',
					'compare' => '=',
				],
			],
		] );

		return [
			'remaining'   => (int) $remaining->found_posts,
			'unavailable' => (int) $unavailable->found_posts,
		];
	}

	/**
	 * Compresses ONE pending video right now, synchronously, instead of
	 * deferring to WP-Cron. Only reachable from the explicit
	 * "Optimize existing media now" admin action (see
	 * run_backfill_batch_now() below) -- never from a visitor request or
	 * an automatic hook -- so a several-second ffmpeg run here is an
	 * admin-initiated wait, not something a site visitor is stuck behind.
	 * New-upload video compression still goes through WP-Cron as before.
	 */
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
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- there is no non-meta way to ask "which attachments are missing this meta key"; only reachable from an explicit admin click (never a visitor request or automatic hook), and posts_per_page above bounds the cost.
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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- there is no non-meta way to ask "which attachments are missing this meta key"; posts_per_page => 1 above bounds this to a single-row lookup.
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

	/**
	 * Same gradual, batched approach as backfill_existing_images(), for
	 * video that predates this feature (or was uploaded while ffmpeg
	 * wasn't available / the toggle was off). Only schedules compression
	 * jobs -- the actual work still happens in compress_video() above, via
	 * WP-Cron, never blocking a live request.
	 */
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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- there is no non-meta way to ask "which attachments are missing this meta key"; capped batch size (posts_per_page above), runs only in a deferred WP-Cron job.
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
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- read-only PATH lookup, gated by the exec()/disable_functions checks above.
			@exec( $lookup, $output, $exit_code );

			if ( 0 === $exit_code && ! empty( $output[0] ) ) {
				$path = trim( $output[0] );
				return $path;
			}
		}

		return $path;
	}

	/* ---------------- Lazy loading ---------------- */

	/**
	 * WordPress's native loading="lazy" (forced on above via
	 * wp_lazy_loading_enabled) only ever applies to <img> tags -- it does
	 * not touch <iframe> embeds (YouTube, Vimeo, etc. added via the block
	 * editor's embed feature), which are very often the single heaviest
	 * element on a page. This adds the same attribute to any iframe that
	 * doesn't already specify one, without touching anything else about it.
	 */
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
					__( 'Video compressed \u2013 %d%% smaller', 'reloadify-frontend-sync' ),
					max( 0, $percent )
				)
				: sprintf(
					/* translators: 1: WEBP or AVIF, 2: percent smaller */
					__( '%1$s \u2013 about %2$d%% smaller', 'reloadify-frontend-sync' ),
					strtoupper( $stats['format'] ),
					max( 0, $percent )
				);
			echo '<span class="reloadify-media-badge reloadify-media-badge--ok">' . esc_html( $label ) . '</span>';
			return;
		}

		if ( 0 === strpos( (string) $mime, 'image/jpeg' ) || 0 === strpos( (string) $mime, 'image/png' ) ) {
			if ( ! self::preferred_image_format() ) {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Original kept \u2013 server has no WebP/AVIF support', 'reloadify-frontend-sync' ) . '</span>';
			} else {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Pending \u2013 optimizes in the background shortly', 'reloadify-frontend-sync' ) . '</span>';
			}
			return;
		}

		if ( 0 === strpos( (string) $mime, 'video/' ) ) {
			if ( ! self::ffmpeg_path() ) {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Not compressed \u2013 ffmpeg unavailable on this server', 'reloadify-frontend-sync' ) . '</span>';
			} else {
				echo '<span class="reloadify-media-badge">' . esc_html__( 'Pending \u2013 compresses in the background shortly', 'reloadify-frontend-sync' ) . '</span>';
			}
			return;
		}

		echo '<span class="reloadify-media-badge reloadify-media-badge--muted">' . esc_html__( 'Not an image or video', 'reloadify-frontend-sync' ) . '</span>';
	}
}
