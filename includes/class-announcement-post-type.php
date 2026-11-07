<?php
/**
 * Announcement post type on the primary site.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Registers `prc_announcement` and keeps the network projection current.
 */
class Announcement_Post_Type {
	public const POST_TYPE             = 'prc_announcement';
	public const CAPABILITY            = 'manage_options';
	public const VERSION_META          = '_prc_announcement_version';
	public const SHOW_AGAIN_META       = '_prc_announcement_show_again';
	public const ALLOWED_BLOCKS_FILTER = 'prc_wp_admin_tours_announcement_allowed_blocks';
	public const EDITOR_HANDLE         = 'prc-wp-admin-tours-announcement-editor';

	public const ALLOWED_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/image',
		'core/video',
		'core/buttons',
		'core/button',
		'core/details',
		'core/accordion',
		'core/accordion-item',
		'core/accordion-heading',
		'core/accordion-panel',
		'core/tabs',
		'core/tab-list',
		'core/tab-panels',
		'core/tab-panel',
	);

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register' );
		$loader->add_filter( 'allowed_block_types_all', $this, 'restrict_blocks', 20, 2 );
		$loader->add_filter( 'wp_robots', $this, 'noindex' );
		$loader->add_action( 'wp_head', $this, 'print_base_target', 1 );
		$loader->add_action( 'prc_iframe_head', $this, 'hide_corner_logo' );
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_editor_assets' );
		$loader->add_action( 'save_post_' . self::POST_TYPE, $this, 'rebuild_catalog' );
		$loader->add_action( 'rest_after_insert_' . self::POST_TYPE, $this, 'apply_show_again' );
		$loader->add_action( 'after_delete_post', $this, 'rebuild_after_delete', 10, 2 );
	}

	/**
	 * Register the post type, its meta, and its single template.
	 *
	 * @hook init
	 */
	public function register(): void {
		if ( ! is_primary_site() ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Announcements', 'prc-wp-admin-tours' ),
					'singular_name'      => __( 'Announcement', 'prc-wp-admin-tours' ),
					'all_items'          => __( 'Announcements', 'prc-wp-admin-tours' ),
					'add_new_item'       => __( 'Add New Announcement', 'prc-wp-admin-tours' ),
					'edit_item'          => __( 'Edit Announcement', 'prc-wp-admin-tours' ),
					'new_item'           => __( 'New Announcement', 'prc-wp-admin-tours' ),
					'view_item'          => __( 'View Announcement', 'prc-wp-admin-tours' ),
					'search_items'       => __( 'Search Announcements', 'prc-wp-admin-tours' ),
					'not_found'          => __( 'No announcements found.', 'prc-wp-admin-tours' ),
					'not_found_in_trash' => __( 'No announcements found in Trash.', 'prc-wp-admin-tours' ),
					'item_published'     => __( 'Announcement published.', 'prc-wp-admin-tours' ),
					'item_updated'       => __( 'Announcement updated.', 'prc-wp-admin-tours' ),
				),
				'public'              => false,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => 'options-general.php',
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => array(
					'slug'       => 'announcement',
					'with_front' => false,
				),
				'query_var'           => true,
				'supports'            => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				// With map_meta_cap off, edit_post, read_post, and delete_post resolve straight to manage_options.
				'map_meta_cap'        => false,
				'capabilities'        => array_fill_keys(
					array(
						'edit_post',
						'read_post',
						'delete_post',
						'edit_posts',
						'edit_others_posts',
						'delete_posts',
						'publish_posts',
						'read_private_posts',
						'create_posts',
					),
					self::CAPABILITY
				),
			)
		);

		$meta = array(
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => static fn(): bool => current_user_can( self::CAPABILITY ),
		);
		register_post_meta(
			self::POST_TYPE,
			self::VERSION_META,
			$meta + array(
				'type'    => 'integer',
				'default' => 1,
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::SHOW_AGAIN_META,
			$meta + array(
				'type'    => 'boolean',
				'default' => false,
			)
		);

		if ( function_exists( 'register_block_template' ) ) {
			register_block_template(
				'prc-wp-admin-tours//single-' . self::POST_TYPE,
				array(
					'title'       => __( 'Single Announcement', 'prc-wp-admin-tours' ),
					'description' => __( 'Renders an announcement the way the wp-admin modal shows it.', 'prc-wp-admin-tours' ),
					'content'     => '<!-- wp:template-part {"slug":"iframed-content"} /-->',
				)
			);
		}
	}

	/**
	 * Announcement version, at least 1.
	 *
	 * @param int $post_id Post id.
	 */
	public static function version( int $post_id ): int {
		return max( 1, (int) get_post_meta( $post_id, self::VERSION_META, true ) );
	}

	/**
	 * Limit the inserter to blocks that read well in the modal.
	 *
	 * Priority 20: filters in prc-block-library and prc-block-forms replace the
	 * list with every registered block at priority 10.
	 *
	 * @hook allowed_block_types_all
	 * @param bool|string[]            $allowed_block_types Allowed block names, or true for all.
	 * @param \WP_Block_Editor_Context $editor_context      Editor context.
	 * @return bool|string[]
	 */
	public function restrict_blocks( $allowed_block_types, $editor_context ) {
		if (
			! isset( $editor_context->post )
			|| self::POST_TYPE !== $editor_context->post->post_type
		) {
			return $allowed_block_types;
		}

		/**
		 * Filter the blocks allowed in announcements.
		 *
		 * @param string[] $blocks Default allowed block names.
		 */
		return apply_filters( self::ALLOWED_BLOCKS_FILTER, self::ALLOWED_BLOCKS );
	}

	/**
	 * Announcements are reachable by URL but never indexed.
	 *
	 * @hook wp_robots
	 * @param array<string, bool|string> $robots Robots directives.
	 * @return array<string, bool|string>
	 */
	public function noindex( array $robots ): array {
		if ( is_singular( self::POST_TYPE ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * Links in the modal iframe must leave the iframe.
	 *
	 * @hook wp_head
	 */
	public function print_base_target(): void {
		if ( is_singular( self::POST_TYPE ) ) {
			echo '<base target="_blank">' . "\n";
		}
	}

	/**
	 * Hide the prc-embeds corner logo in the modal iframe.
	 *
	 * The logo credits Pew on other sites. In the admin modal it covers the
	 * bottom-left of the content.
	 *
	 * @hook prc_iframe_head
	 */
	public function hide_corner_logo(): void {
		if ( is_singular( self::POST_TYPE ) ) {
			echo '<style>.prc-platform__iframe__corner-logo{display:none}</style>' . "\n";
		}
	}

	/**
	 * Load the "Show again" document panel in the announcement editor.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$asset_file = PRC_WP_ADMIN_TOURS_DIR . '/build/announcement-editor.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;
		wp_enqueue_script(
			self::EDITOR_HANDLE,
			plugins_url( 'build/announcement-editor.js', PRC_WP_ADMIN_TOURS_FILE ),
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? PRC_WP_ADMIN_TOURS_VERSION ),
			true
		);
	}

	/**
	 * Rebuild the projection after any save, including trash and untrash.
	 *
	 * @hook save_post_prc_announcement
	 */
	public function rebuild_catalog(): void {
		Announcement_Catalog::rebuild();
	}

	/**
	 * Bump the version when the author asked to show the post again.
	 *
	 * REST writes meta after save_post, so the projection is rebuilt here too.
	 *
	 * @hook rest_after_insert_prc_announcement
	 * @param \WP_Post $post Saved post.
	 */
	public function apply_show_again( \WP_Post $post ): void {
		if ( get_post_meta( $post->ID, self::SHOW_AGAIN_META, true ) ) {
			delete_post_meta( $post->ID, self::SHOW_AGAIN_META );
			if ( 'publish' === $post->post_status ) {
				update_post_meta( $post->ID, self::VERSION_META, self::version( $post->ID ) + 1 );
			}
		}

		Announcement_Catalog::rebuild();
	}

	/**
	 * Drop a permanently deleted announcement from the projection.
	 *
	 * Core clears the post cache after deleted_post, so a rebuild there still
	 * finds the deleted post.
	 *
	 * @hook after_delete_post
	 * @param int           $post_id Post id.
	 * @param \WP_Post|null $post    Deleted post.
	 */
	public function rebuild_after_delete( int $post_id, $post ): void {
		if ( $post instanceof \WP_Post && self::POST_TYPE === $post->post_type ) {
			Announcement_Catalog::rebuild();
		}
	}
}
