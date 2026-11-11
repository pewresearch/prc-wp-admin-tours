<?php
/**
 * Resolve tour step media from the primary site's media library.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Step media is registered as attachment slugs. Slugs survive staging and dev data
 * syncs, but attachment ids do not. Every site reads the primary site's copy.
 */
class Tour_Media {
	public const CACHE_GROUP = 'prc_wp_admin_tours_media';

	private const HIT_TTL  = HOUR_IN_SECONDS;
	private const MISS_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'add_attachment', $this, 'flush_attachment' );
		$loader->add_action( 'edit_attachment', $this, 'flush_attachment' );
		$loader->add_action( 'delete_attachment', $this, 'flush_attachment' );
		// After core's check_upload_mimes(), which limits uploads to the network list.
		$loader->add_filter( 'upload_mimes', $this, 'allow_captions_upload', 20 );
	}

	/**
	 * Let primary-site authors upload WebVTT caption files for tour videos.
	 *
	 * @hook upload_mimes
	 *
	 * @param array<string, string> $mimes Allowed extensions and MIME types.
	 * @return array<string, string>
	 */
	public function allow_captions_upload( $mimes ): array {
		$mimes = is_array( $mimes ) ? $mimes : array();
		if ( is_primary_site() ) {
			$mimes['vtt'] = 'text/vtt';
		}
		return $mimes;
	}

	/**
	 * Drop the cached lookup when a primary-site attachment changes.
	 *
	 * @hook add_attachment
	 * @hook edit_attachment
	 * @hook delete_attachment
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public function flush_attachment( $attachment_id ): void {
		if ( ! is_primary_site() ) {
			return;
		}
		$slug = (string) get_post_field( 'post_name', (int) $attachment_id );
		if ( '' !== $slug ) {
			self::add_global_group();
			wp_cache_delete( $slug, self::CACHE_GROUP );
		}
	}

	/**
	 * Replace step media slugs with URLs. A step whose poster or video is missing keeps
	 * its copy and loses its media.
	 *
	 * @param array<int, array<string, mixed>>    $tours  Parsed tours.
	 * @param callable(string): (array|null)|null $lookup Slug lookup. Defaults to the primary-site media library.
	 * @return array<int, array<string, mixed>>
	 */
	public static function resolve_tours( array $tours, ?callable $lookup = null ): array {
		$lookup = $lookup ?? array( self::class, 'attachment_by_slug' );

		foreach ( $tours as $t => $tour ) {
			foreach ( $tour['steps'] ?? array() as $s => $step ) {
				if ( ! isset( $step['media'] ) ) {
					continue;
				}
				$resolved = self::resolve_media( $step['media'], (string) $step['title'], $lookup );
				if ( null === $resolved ) {
					unset( $tours[ $t ]['steps'][ $s ]['media'] );
				} else {
					$tours[ $t ]['steps'][ $s ]['media'] = $resolved;
				}
			}
		}

		return $tours;
	}

	/**
	 * Resolve one parsed media payload.
	 *
	 * @param array<string, string>          $media  Parsed media (`poster`, `video`, `alt`, optional `captions`).
	 * @param string                         $title  Step title, the last alt text fallback.
	 * @param callable(string): (array|null) $lookup Slug lookup.
	 * @return array<string, mixed>|null
	 */
	public static function resolve_media( array $media, string $title, callable $lookup ): ?array {
		$poster = self::lookup_typed( $lookup, (string) ( $media['poster'] ?? '' ), 'image/' );
		$video  = self::lookup_typed( $lookup, (string) ( $media['video'] ?? '' ), 'video/' );
		if ( null === $poster || null === $video ) {
			return null;
		}

		$alt = (string) ( $media['alt'] ?? '' );
		if ( '' === $alt ) {
			$alt = '' !== $poster['alt'] ? $poster['alt'] : $title;
		}

		$resolved = array(
			'posterUrl'    => $poster['url'],
			'posterWidth'  => $poster['width'],
			'posterHeight' => $poster['height'],
			'videoUrl'     => $video['url'],
			'videoMime'    => $video['mime'],
			'alt'          => $alt,
		);

		if ( ! empty( $media['captions'] ) ) {
			$captions = self::lookup_typed( $lookup, (string) $media['captions'], 'text/vtt' );
			if ( null !== $captions ) {
				$resolved['captionsUrl'] = $captions['url'];
			}
		}

		return $resolved;
	}

	/**
	 * Attachment on the primary site by slug, cached network-wide.
	 *
	 * @param string $slug Attachment slug (`post_name`).
	 * @return array{url: string, mime: string, width: int, height: int, alt: string}|null
	 */
	public static function attachment_by_slug( string $slug ): ?array {
		if ( '' === $slug ) {
			return null;
		}

		self::add_global_group();
		$cached = wp_cache_get( $slug, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array() === $cached ? null : $cached;
		}

		$switched = is_multisite() && get_current_blog_id() !== primary_site_id();
		if ( $switched ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.switch_to_blog_switch_to_blog
			switch_to_blog( primary_site_id() );
		}

		$found = self::query_attachment( $slug );

		if ( $switched ) {
			restore_current_blog();
		}

		// phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- Both TTLs are at least five minutes.
		wp_cache_set( $slug, $found ?? array(), self::CACHE_GROUP, null === $found ? self::MISS_TTL : self::HIT_TTL );
		return $found;
	}

	/**
	 * Look up a slug and check its MIME type prefix. Logs a miss once per request.
	 *
	 * @param callable(string): (array|null) $lookup Slug lookup.
	 * @param string                         $slug   Attachment slug.
	 * @param string                         $mime   Required MIME type prefix.
	 * @return array<string, mixed>|null
	 */
	private static function lookup_typed( callable $lookup, string $slug, string $mime ): ?array {
		$found = '' !== $slug ? $lookup( $slug ) : null;
		if ( is_array( $found ) && '' !== ( $found['url'] ?? '' ) && str_starts_with( (string) ( $found['mime'] ?? '' ), $mime ) ) {
			return $found + array(
				'width'  => 0,
				'height' => 0,
				'alt'    => '',
			);
		}

		log_once(
			'media-missing:' . $slug,
			sprintf( 'dropped tour media: no %s attachment with slug "%s" on the primary site.', rtrim( $mime, '/' ), $slug )
		);
		return null;
	}

	/**
	 * Query the current site for an attachment slug.
	 *
	 * @param string $slug Attachment slug.
	 * @return array{url: string, mime: string, width: int, height: int, alt: string}|null
	 */
	private static function query_attachment( string $slug ): ?array {
		$ids = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'inherit',
				'name'             => $slug,
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$id  = (int) ( $ids[0] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}

		$url = (string) wp_get_attachment_url( $id );
		if ( '' === $url ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $id );

		return array(
			'url'    => $url,
			'mime'   => (string) get_post_mime_type( $id ),
			'width'  => (int) ( $meta['width'] ?? 0 ),
			'height' => (int) ( $meta['height'] ?? 0 ),
			'alt'    => sanitize_text_field( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ),
		);
	}

	/**
	 * Share the cache across sites, since every site reads the primary site's attachments.
	 */
	private static function add_global_group(): void {
		if ( function_exists( 'wp_cache_add_global_groups' ) ) {
			wp_cache_add_global_groups( array( self::CACHE_GROUP ) );
		}
	}
}
