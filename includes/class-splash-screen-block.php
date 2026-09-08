<?php
/**
 * Docs-site splash screen authoring block.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Registers the splash-screen block on the docs site only.
 */
class Splash_Screen_Block {
	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register_block' );
		$loader->add_filter( 'allowed_block_types_all', $this, 'filter_allowed_block_types', 10, 2 );
	}

	/**
	 * Register the block on the docs site.
	 *
	 * @hook init
	 */
	public function register_block(): void {
		if ( ! Splash_Catalog::is_docs_site() ) {
			return;
		}

		$block_dir = PRC_WP_ADMIN_TOURS_DIR . '/build/splash-screen';
		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			$block_dir = PRC_WP_ADMIN_TOURS_DIR . '/src/splash-screen';
		}

		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type_from_metadata( $block_dir );
	}

	/**
	 * Keep the inserter on docs-site posts only.
	 *
	 * @hook allowed_block_types_all
	 *
	 * @param bool|string[] $allowed_block_types Allowed block types.
	 * @param object        $editor_context      Editor context.
	 * @return bool|string[]
	 */
	public function filter_allowed_block_types( $allowed_block_types, $editor_context ) {
		if ( ! Splash_Catalog::is_docs_site() ) {
			return $allowed_block_types;
		}

		$post_type = '';
		if ( is_object( $editor_context ) && isset( $editor_context->post ) && is_object( $editor_context->post ) ) {
			$post_type = (string) ( $editor_context->post->post_type ?? '' );
		}

		if ( Splash_Catalog::POST_TYPE === $post_type ) {
			return $allowed_block_types;
		}

		if ( false === $allowed_block_types ) {
			return $allowed_block_types;
		}

		if ( true === $allowed_block_types ) {
			if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
				return $allowed_block_types;
			}
			$allowed_block_types = array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
		}

		if ( ! is_array( $allowed_block_types ) ) {
			return $allowed_block_types;
		}

		return array_values(
			array_filter(
				$allowed_block_types,
				static function ( $name ): bool {
					return Splash_Catalog::BLOCK_NAME !== $name;
				}
			)
		);
	}
}
