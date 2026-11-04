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
	 * Every role, subscribers included, needs the splash to set a display name and connect Slack.
	 */
	public const CAPABILITY = 'read';

	private const VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( Tour_Registry::ACTION_REGISTER, $this, 'register_tours', 5 );
		$loader->add_action( 'init', $this, 'register_setup_meta' );
	}

	/**
	 * Expose acknowledged setup field ids in REST so a user's setup step can be reset.
	 */
	public function register_setup_meta(): void {
		register_meta(
			'user',
			Setup_Fields::ACKNOWLEDGED_META_KEY,
			array(
				'type'              => 'array',
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => static function ( $value ): array {
					return is_array( $value ) ? array_values( array_filter( array_map( 'sanitize_key', $value ) ) ) : array();
				},
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);
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
			'version'    => self::VERSION,
			'autoStart'  => true,
			'capability' => self::CAPABILITY,
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
		$progress = Progress::get_tour( (int) get_current_user_id(), self::TOUR_ID, self::VERSION );

		return array(
			'logoUrl'           => content_url( 'images/logos/primary-light.svg' ),
			'logoDarkUrl'       => content_url( 'images/logos/primary-white.svg' ),
			'actions'           => self::actions(),
			'setupFields'       => Setup_Fields::incomplete_for( wp_get_current_user() ),
			'setupSnoozedUntil' => (int) ( $progress['snoozedUntil'] ?? 0 ),
			'now'               => time(),
			'setupUrl'          => rest_url( 'prc-api/v3/wp-admin-tours/setup' ),
			'slackUrl'          => rest_url( 'prc-slack/v1/connect-user' ),
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
