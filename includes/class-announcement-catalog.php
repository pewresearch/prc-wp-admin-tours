<?php
/**
 * Network projection of published announcements.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * The primary site writes the projection. Every site reads it without switch_to_blog().
 */
class Announcement_Catalog {
	public const OPTION_KEY     = 'prc_wp_admin_tours_announcements';
	public const TOUR_ID_PREFIX = 'prc-wp-admin-tours/announcement/';
	public const MAX            = 10;

	/**
	 * Rewrite the projection from the newest published announcements.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function rebuild(): array {
		if ( ! is_primary_site() ) {
			return self::get();
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Announcement_Post_Type::POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => self::MAX,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			$rows[] = self::post_to_row( $post, self::row_context( $post ) );
		}

		update_site_option( self::OPTION_KEY, $rows );
		return $rows;
	}

	/**
	 * Stored projection rows, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get(): array {
		$rows = get_site_option( self::OPTION_KEY, array() );
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * One projection row.
	 *
	 * @param \WP_Post             $post    Published announcement.
	 * @param array<string, mixed> $context `permalink`, `version`, `imageUrl`, and `imageAlt`, read outside the post row.
	 * @return array<string, mixed>
	 */
	public static function post_to_row( \WP_Post $post, array $context ): array {
		return array(
			'id'          => (int) $post->ID,
			'title'       => self::plain_text( $post->post_title ),
			'summary'     => self::plain_text( $post->post_excerpt ),
			'imageUrl'    => $context['imageUrl'],
			'imageAlt'    => $context['imageAlt'],
			// The edge cache is not purged for the /iframe/ path, so the modified time busts it.
			'iframeUrl'   => add_query_arg(
				array(
					'iframeTemplate' => 'content',
					'v'              => self::gmt_timestamp( $post->post_modified_gmt ),
				),
				trailingslashit( $context['permalink'] ) . 'iframe/'
			),
			'version'     => $context['version'],
			'publishedAt' => self::gmt_timestamp( $post->post_date_gmt ),
		);
	}

	/**
	 * Projection rows as registerable tours.
	 *
	 * @param array<int, array<string, mixed>> $rows Projection rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_to_tours( array $rows ): array {
		$tours = array();
		foreach ( $rows as $row ) {
			$title   = (string) ( $row['title'] ?? '' );
			$summary = (string) ( $row['summary'] ?? '' );

			$tours[] = array(
				'id'           => self::TOUR_ID_PREFIX . (int) ( $row['id'] ?? 0 ),
				'title'        => $title,
				'version'      => max( 1, (int) ( $row['version'] ?? 1 ) ),
				'autoStart'    => true,
				'capability'   => 'edit_posts',
				'mode'         => 'announcement',
				'screens'      => array(
					array(
						'kind' => 'anyAdmin',
					),
				),
				'steps'        => array(
					array(
						'id'          => 'announcement',
						'title'       => $title,
						'description' => '' !== $summary ? $summary : $title,
						'selector'    => null,
					),
				),
				'announcement' => array(
					'summary'     => $summary,
					'imageUrl'    => (string) ( $row['imageUrl'] ?? '' ),
					'imageAlt'    => (string) ( $row['imageAlt'] ?? '' ),
					'iframeUrl'   => (string) ( $row['iframeUrl'] ?? '' ),
					'publishedAt' => (int) ( $row['publishedAt'] ?? 0 ),
				),
			);
		}

		return $tours;
	}

	/**
	 * Accounts created after an announcement was published do not see it.
	 *
	 * @param int $user_registered_ts User registered unix (GMT).
	 * @param int $published_at       Announcement published unix (GMT).
	 */
	public static function is_user_eligible( int $user_registered_ts, int $published_at ): bool {
		return $published_at <= 0 || $user_registered_ts <= $published_at;
	}

	/**
	 * Values for post_to_row() that need other queries.
	 *
	 * @param \WP_Post $post Published announcement.
	 * @return array{permalink: string, version: int, imageUrl: string, imageAlt: string}
	 */
	private static function row_context( \WP_Post $post ): array {
		$thumbnail_id = (int) get_post_thumbnail_id( $post );

		return array(
			'permalink' => (string) get_permalink( $post ),
			'version'   => Announcement_Post_Type::version( (int) $post->ID ),
			'imageUrl'  => $thumbnail_id ? (string) wp_get_attachment_image_url( $thumbnail_id, 'medium' ) : '',
			'imageAlt'  => $thumbnail_id ? (string) get_post_meta( $thumbnail_id, '_wp_attachment_image_alt', true ) : '',
		);
	}

	/**
	 * Kses stores `&` as `&amp;` for authors without unfiltered_html, and React prints text literally.
	 *
	 * @param string $value Stored title or excerpt.
	 */
	private static function plain_text( string $value ): string {
		return trim( wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/**
	 * Unix time for a `*_gmt` post column.
	 *
	 * @param string $gmt MySQL datetime in GMT.
	 */
	private static function gmt_timestamp( string $gmt ): int {
		$timestamp = strtotime( $gmt . ' UTC' );
		return false === $timestamp ? 0 : max( 0, $timestamp );
	}
}
