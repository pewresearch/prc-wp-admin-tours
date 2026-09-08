<?php
/**
 * Network catalog of editor-authored splash screens.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Rebuilds a site-option catalog from docs-site posts and maps rows to tours.
 */
class Splash_Catalog {
	public const OPTION_KEY     = 'prc_wp_admin_tours_splash_catalog';
	public const BLOCK_NAME     = 'prc-wp-admin-tours/splash-screen';
	public const PUBLISHED_META = '_prc_wp_admin_tours_splash_published_at';
	public const TOUR_ID_PREFIX = 'prc-wp-admin-tours/splash/';
	public const POST_TYPE      = 'post';
	public const QUERY_LIMIT    = 50;

	/**
	 * Docs site blog id.
	 */
	public static function docs_site_id(): int {
		$default = defined( 'PRC_DOCS_SITE_ID' ) ? (int) PRC_DOCS_SITE_ID : 1;
		if ( function_exists( 'apply_filters' ) ) {
			return (int) apply_filters( 'prc_wp_admin_tours_docs_site_id', $default );
		}

		return $default;
	}

	/**
	 * Whether the current blog is the docs authoring site.
	 */
	public static function is_docs_site(): bool {
		if ( function_exists( 'get_current_blog_id' ) && self::docs_site_id() === (int) get_current_blog_id() ) {
			return true;
		}

		return function_exists( 'get_stylesheet' ) && 'docspress' === get_stylesheet();
	}

	/**
	 * Stored catalog rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function get(): array {
		if ( ! function_exists( 'get_site_option' ) ) {
			return array();
		}

		$raw = get_site_option( self::OPTION_KEY, array() );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	/**
	 * Whether a tour id came from the docs-site splash catalog.
	 *
	 * @param string $id Tour id.
	 */
	public static function is_catalog_tour_id( string $id ): bool {
		return str_starts_with( $id, self::TOUR_ID_PREFIX );
	}

	/**
	 * Users created after the splash was published do not see it.
	 *
	 * @param int $user_registered_ts User registered unix (GMT).
	 * @param int $published_at       Splash published unix (GMT).
	 */
	public static function is_user_eligible( int $user_registered_ts, int $published_at ): bool {
		if ( $published_at <= 0 ) {
			return true;
		}

		return $user_registered_ts <= $published_at;
	}

	/**
	 * Convert catalog rows into registerable tour configs.
	 *
	 * @param array<int, array<string, mixed>> $rows Catalog rows.
	 * @return array<int, array<string, mixed>>
	 */
	public static function rows_to_tours( array $rows ): array {
		$tours = array();
		foreach ( $rows as $row ) {
			$tour = self::row_to_tour( is_array( $row ) ? $row : array() );
			if ( null !== $tour ) {
				$tours[] = $tour;
			}
		}

		return $tours;
	}

	/**
	 * Convert one catalog row into a tour config.
	 *
	 * @param array<string, mixed> $row Catalog row.
	 * @return array<string, mixed>|null
	 */
	public static function row_to_tour( array $row ): ?array {
		$splash_id = sanitize_tour_id( (string) ( $row['splashId'] ?? '' ) );
		if ( '' === $splash_id ) {
			return null;
		}

		$title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
		if ( '' === $title ) {
			return null;
		}

		$version = isset( $row['version'] ) ? (int) $row['version'] : 1;
		if ( $version < 1 ) {
			$version = 1;
		}

		$content_html = isset( $row['contentHtml'] ) ? (string) $row['contentHtml'] : '';
		$description  = self::step_description( $content_html, $title );

		return array(
			'id'         => self::TOUR_ID_PREFIX . $splash_id,
			'title'      => $title,
			'version'    => $version,
			'autoStart'  => true,
			'capability' => 'edit_posts',
			'mode'       => 'splash',
			'screens'    => array(
				array(
					'kind' => 'anyAdmin',
				),
			),
			'steps'      => array(
				array(
					'id'          => 'splash',
					'title'       => $title,
					'description' => $description,
					'selector'    => null,
				),
			),
			'splash'     => array(
				'contentHtml' => $content_html,
				'permalink'   => isset( $row['permalink'] ) ? (string) $row['permalink'] : '',
				'showLogo'    => ! array_key_exists( 'showLogo', $row ) || ! empty( $row['showLogo'] ),
				'publishedAt' => isset( $row['publishedAt'] ) ? (int) $row['publishedAt'] : 0,
			),
		);
	}

	/**
	 * Extract catalog rows from parsed block lists.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<string, mixed>             $post   Post context (title, permalink, publishedAt).
	 * @return array<int, array<string, mixed>>
	 */
	public static function extract_rows( array $blocks, array $post ): array {
		$rows = array();
		foreach ( self::find_splash_blocks( $blocks ) as $block ) {
			$row = self::row_from_block( $block, $post );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Recursively find splash blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	public static function find_splash_blocks( array $blocks ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( self::BLOCK_NAME === ( $block['blockName'] ?? '' ) ) {
				$found[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::find_splash_blocks( $block['innerBlocks'] ) );
			}
		}

		return $found;
	}

	/**
	 * Build inner HTML from a splash block without using public render.php.
	 *
	 * @param array<string, mixed> $block Parsed splash block.
	 */
	public static function inner_html_from_block( array $block ): string {
		$html = '';
		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			if ( ! is_array( $inner ) ) {
				continue;
			}
			if ( function_exists( 'serialize_block' ) ) {
				$html .= serialize_block( $inner );
				continue;
			}
			foreach ( $inner['innerContent'] ?? array() as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				}
			}
		}

		if ( '' === $html ) {
			foreach ( $block['innerContent'] ?? array() as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				}
			}
		}

		if ( function_exists( 'do_blocks' ) && '' !== $html ) {
			$html = do_blocks( $html );
		}

		if ( function_exists( 'wp_kses_post' ) ) {
			return wp_kses_post( $html );
		}

		return $html;
	}

	/**
	 * Rebuild the network catalog from published docs-site posts.
	 *
	 * @hook save_post
	 * @hook transition_post_status
	 *
	 * @param mixed $arg1 Post id or new status.
	 * @param mixed $arg2 Post object or old status.
	 * @param mixed $arg3 Post object on transition_post_status. Unused on save_post.
	 */
	public function maybe_rebuild( $arg1 = null, $arg2 = null, $arg3 = null ): void {
		if ( ! self::is_docs_site() ) {
			return;
		}

		$post = self::post_from_hook_args( $arg1, $arg2, $arg3 );
		if ( null === $post ) {
			return;
		}

		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post ) ) {
			return;
		}

		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post ) ) {
			return;
		}

		$this->rebuild();
	}

	/**
	 * Scan published posts and write the site option.
	 */
	public function rebuild(): void {
		if ( ! function_exists( 'update_site_option' ) ) {
			return;
		}

		update_site_option( self::OPTION_KEY, self::catalog_rows_from_posts( $this->published_posts() ) );
	}

	/**
	 * Build catalog rows from posts. Draft, trash, and posts without the block are skipped.
	 *
	 * @param array<int, object> $posts Post-like objects.
	 * @return array<int, array<string, mixed>>
	 */
	public static function catalog_rows_from_posts( array $posts ): array {
		$rows = array();
		foreach ( $posts as $post ) {
			if ( ! is_object( $post ) ) {
				continue;
			}
			if ( 'publish' !== (string) ( $post->post_status ?? '' ) ) {
				continue;
			}
			if ( ! self::post_has_splash( $post ) ) {
				continue;
			}

			$parsed = function_exists( 'parse_blocks' )
				? parse_blocks( (string) ( $post->post_content ?? '' ) )
				: array();

			$permalink = (string) ( $post->permalink ?? '' );
			if ( '' === $permalink && function_exists( 'get_permalink' ) ) {
				$permalink = (string) get_permalink( $post );
			}

			$extracted = self::extract_rows(
				is_array( $parsed ) ? $parsed : array(),
				array(
					'title'       => (string) ( $post->post_title ?? '' ),
					'permalink'   => $permalink,
					'publishedAt' => self::published_at_for_post( $post ),
				)
			);

			foreach ( $extracted as $row ) {
				$rows[] = $row;
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return ( (int) ( $b['publishedAt'] ?? 0 ) ) <=> ( (int) ( $a['publishedAt'] ?? 0 ) );
			}
		);

		return $rows;
	}

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'save_post', $this, 'maybe_rebuild', 20, 3 );
		$loader->add_action( 'transition_post_status', $this, 'maybe_rebuild', 20, 3 );
		$loader->add_action( 'wp_trash_post', $this, 'maybe_rebuild', 20, 1 );
		$loader->add_action( 'deleted_post', $this, 'maybe_rebuild', 20, 2 );
	}

	/**
	 * Map hook args to a post object.
	 *
	 * @param mixed $arg1 First hook arg.
	 * @param mixed $arg2 Second hook arg.
	 * @param mixed $arg3 Third hook arg.
	 */
	private static function post_from_hook_args( $arg1, $arg2, $arg3 = null ): ?\WP_Post {
		foreach ( array( $arg2, $arg1, $arg3 ) as $arg ) {
			if ( $arg instanceof \WP_Post ) {
				return $arg;
			}
		}

		$post_id = 0;
		if ( is_numeric( $arg1 ) ) {
			$post_id = (int) $arg1;
		} elseif ( is_numeric( $arg2 ) ) {
			$post_id = (int) $arg2;
		}

		if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
			return null;
		}

		$post = get_post( $post_id );
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Published posts that can carry a splash block.
	 *
	 * @return array<int, \WP_Post>
	 */
	private function published_posts(): array {
		if ( ! class_exists( '\WP_Query' ) ) {
			return array();
		}

		$block_needle = 'wp:' . self::BLOCK_NAME;
		$where_filter = static function ( $where, $query ) use ( $block_needle ) {
			if ( ! is_string( $where ) || ! $query instanceof \WP_Query ) {
				return $where;
			}
			if ( true !== $query->get( 'prc_wp_admin_tours_has_splash' ) ) {
				return $where;
			}

			global $wpdb;
			$like = '%' . $wpdb->esc_like( $block_needle ) . '%';
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.post_content LIKE %s", $like );
		};

		add_filter( 'posts_where', $where_filter, 10, 2 );
		try {
			$query = new \WP_Query(
				array(
					'post_type'                     => self::POST_TYPE,
					'post_status'                   => 'publish',
					'posts_per_page'                => self::QUERY_LIMIT,
					'orderby'                       => 'date',
					'order'                         => 'DESC',
					'no_found_rows'                 => true,
					'update_post_meta_cache'        => true,
					'update_post_term_cache'        => false,
					'ep_integrate'                  => false,
					'prc_wp_admin_tours_has_splash' => true,
				)
			);
		} finally {
			remove_filter( 'posts_where', $where_filter, 10 );
		}

		$posts = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}

		return $posts;
	}

	/**
	 * Whether a post contains the splash block.
	 *
	 * @param object $post Post-like object.
	 */
	private static function post_has_splash( object $post ): bool {
		if ( function_exists( 'has_block' ) && $post instanceof \WP_Post ) {
			return has_block( self::BLOCK_NAME, $post );
		}

		return str_contains( (string) ( $post->post_content ?? '' ), 'wp:' . self::BLOCK_NAME );
	}

	/**
	 * First-publish unix timestamp. Stored once and not moved on later edits.
	 *
	 * @param object $post Post-like object.
	 */
	public static function published_at_for_post( object $post ): int {
		$stored  = 0;
		$post_id = isset( $post->ID ) ? (int) $post->ID : 0;
		if ( $post_id > 0 && function_exists( 'get_post_meta' ) ) {
			$stored = (int) get_post_meta( $post_id, self::PUBLISHED_META, true );
		}

		if ( $stored > 0 ) {
			return $stored;
		}

		$gmt = (string) ( $post->post_date_gmt ?? '' );
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			$gmt = (string) ( $post->post_date ?? '' );
		}

		$timestamp = strtotime( $gmt . ' UTC' );
		if ( ! is_int( $timestamp ) || $timestamp <= 0 ) {
			$timestamp = time();
		}

		if ( $post_id > 0 && function_exists( 'update_post_meta' ) ) {
			update_post_meta( $post_id, self::PUBLISHED_META, $timestamp );
		}

		return $timestamp;
	}

	/**
	 * Catalog row from one splash block.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param array<string, mixed> $post  Post context.
	 * @return array<string, mixed>|null
	 */
	private static function row_from_block( array $block, array $post ): ?array {
		$attrs     = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$splash_id = sanitize_tour_id( (string) ( $attrs['splashId'] ?? '' ) );
		if ( '' === $splash_id ) {
			return null;
		}

		$version = isset( $attrs['version'] ) ? (int) $attrs['version'] : 1;
		if ( $version < 1 ) {
			$version = 1;
		}

		$title = isset( $post['title'] ) ? sanitize_text_field( (string) $post['title'] ) : '';
		if ( '' === $title ) {
			return null;
		}

		$permalink = '';
		if ( isset( $post['permalink'] ) && function_exists( 'esc_url_raw' ) ) {
			$permalink = esc_url_raw( (string) $post['permalink'] );
		} elseif ( isset( $post['permalink'] ) ) {
			$permalink = (string) $post['permalink'];
		}

		return array(
			'splashId'    => $splash_id,
			'title'       => $title,
			'version'     => $version,
			'showLogo'    => ! array_key_exists( 'showLogo', $attrs ) || ! empty( $attrs['showLogo'] ),
			'contentHtml' => self::inner_html_from_block( $block ),
			'permalink'   => $permalink,
			'publishedAt' => isset( $post['publishedAt'] ) ? (int) $post['publishedAt'] : 0,
		);
	}

	/**
	 * Dummy step description for the parser.
	 *
	 * @param string $content_html Inner HTML.
	 * @param string $title        Post title.
	 */
	private static function step_description( string $content_html, string $title ): string {
		$stripped = function_exists( 'wp_strip_all_tags' )
			? wp_strip_all_tags( $content_html )
			: trim( (string) preg_replace( '/<[^>]*>/', '', $content_html ) );
		$stripped = trim( preg_replace( '/\s+/', ' ', $stripped ) ?? '' );

		return '' !== $stripped ? $stripped : $title;
	}
}
