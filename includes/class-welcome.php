<?php
/**
 * Host-owned first-login welcome splash.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Registers the welcome splash and builds its localized payload.
 */
class Welcome {
	public const TOUR_ID = 'prc-wp-admin-tours/welcome';

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( Tour_Registry::ACTION_REGISTER, $this, 'register_tours', 5 );
	}

	/**
	 * Register the welcome splash before domain tours.
	 *
	 * @param object $registry Tour registry.
	 */
	public function register_tours( $registry ): void {
		if ( ! is_object( $registry ) || ! method_exists( $registry, 'register' ) ) {
			return;
		}

		$registry->register( $this->tour_config() );
	}

	/**
	 * Welcome splash definition.
	 *
	 * @return array<string, mixed>
	 */
	public function tour_config(): array {
		return array(
			'id'         => self::TOUR_ID,
			'title'      => __( 'Welcome to PRC Platform', 'prc-wp-admin-tours' ),
			'version'    => 1,
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
					'id'          => 'welcome',
					'title'       => __( 'PRC Platform', 'prc-wp-admin-tours' ),
					'description' => __( 'WordPress, at enterprise scale.', 'prc-wp-admin-tours' ),
					'selector'    => null,
				),
			),
		);
	}

	/**
	 * Localized splash payload for the engine.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload(): array {
		return array(
			'logoUrl'     => content_url( 'images/logos/primary-light.svg' ),
			'logoDarkUrl' => content_url( 'images/logos/primary-white.svg' ),
			'actions'     => self::actions(),
		);
	}

	/**
	 * Capability-gated start actions.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function actions(): array {
		$actions = array();

		if ( self::can_create( 'post' ) ) {
			$actions[] = array(
				'id'    => 'post',
				'label' => __( 'Create a post', 'prc-wp-admin-tours' ),
				'url'   => admin_url( 'post-new.php' ),
			);
		}

		if ( self::can_create( 'chart' ) ) {
			$actions[] = array(
				'id'    => 'chart',
				'label' => __( 'Create a chart', 'prc-wp-admin-tours' ),
				'url'   => admin_url( 'post-new.php?post_type=chart' ),
			);
		}

		return $actions;
	}

	/**
	 * Whether the current user can create a post of this type.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function can_create( string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		if ( ! $object || ! isset( $object->cap->create_posts ) ) {
			return false;
		}

		return current_user_can( $object->cap->create_posts );
	}
}
