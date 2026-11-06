<?php
/**
 * REST routes for tour progress and welcome setup.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /prc-api/v3/wp-admin-tours/progress
 * POST /prc-api/v3/wp-admin-tours/setup
 */
class REST_Controller {
	/**
	 * Tour registry.
	 *
	 * @var Tour_Registry
	 */
	private Tour_Registry $registry;

	/**
	 * Constructor.
	 *
	 * @param Loader        $loader   Loader.
	 * @param Tour_Registry $registry Tour registry.
	 */
	public function __construct( Loader $loader, Tour_Registry $registry ) {
		$this->registry = $registry;
		$loader->add_action( 'rest_api_init', $this, 'register_rest_endpoints' );
	}

	/**
	 * Register the progress and setup routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_endpoints(): void {
		register_rest_route(
			'prc-api/v3',
			'wp-admin-tours/progress',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_progress' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'tourId'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => __NAMESPACE__ . '\\sanitize_tour_id',
						),
						'status'    => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => allowed_progress_statuses(),
						),
						'stepIndex' => array(
							'type'     => 'integer',
							'required' => false,
							'minimum'  => 0,
						),
						'snooze'    => array(
							'type'     => 'boolean',
							'required' => false,
						),
					),
				),
			)
		);

		register_rest_route(
			'prc-api/v3',
			'wp-admin-tours/setup',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_setup' ),
					'permission_callback' => array( $this, 'setup_permission' ),
					'args'                => array(
						'values' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * Logged in plus the tour capability.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function permission( WP_REST_Request $request ): bool {
		return $this->can_access_tour( (string) $request->get_param( 'tourId' ) );
	}

	/**
	 * Same check as the progress route, for the welcome splash.
	 */
	public function setup_permission(): bool {
		return $this->can_access_tour( Welcome::TOUR_ID );
	}

	/**
	 * Save setup values for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_setup( WP_REST_Request $request ) {
		$values = $request->get_param( 'values' );

		return rest_ensure_response(
			Setup_Fields::save( wp_get_current_user(), is_array( $values ) ? $values : array() )
		);
	}

	/**
	 * Whether the current user is logged in and has the tour capability.
	 *
	 * @param string $tour_id Tour id.
	 */
	private function can_access_tour( string $tour_id ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$tour = $this->registry->get( $tour_id );
		if ( null === $tour ) {
			return false;
		}

		return current_user_can( $tour['capability'] );
	}

	/**
	 * How long a `snooze: true` progress write hides the welcome splash.
	 */
	public static function snooze_seconds(): int {
		/**
		 * Filters how long "Skip for now" hides the welcome splash while setup
		 * fields are incomplete.
		 *
		 * @param int $seconds Snooze length in seconds. Default one day.
		 */
		return max( 0, (int) apply_filters( 'prc_wp_admin_tours_setup_snooze_seconds', DAY_IN_SECONDS ) );
	}

	/**
	 * Store progress for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_progress( WP_REST_Request $request ) {
		$tour_id = sanitize_tour_id( (string) $request->get_param( 'tourId' ) );
		$tour    = $this->registry->get( $tour_id );
		if ( null === $tour ) {
			return new WP_Error(
				'prc_wp_admin_tours_unknown_tour',
				__( 'Unknown tour.', 'prc-wp-admin-tours' ),
				array( 'status' => 404 )
			);
		}

		$incoming = array(
			'status' => (string) $request->get_param( 'status' ),
		);
		if ( null !== $request->get_param( 'stepIndex' ) ) {
			$incoming['stepIndex'] = (int) $request->get_param( 'stepIndex' );
		}
		if ( true === $request->get_param( 'snooze' ) ) {
			$incoming['snoozedUntil'] = time() + self::snooze_seconds();
		}

		$progress = Progress::apply(
			(int) get_current_user_id(),
			$tour_id,
			$incoming,
			(int) $tour['version']
		);

		if (
			Welcome::TOUR_ID === $tour_id
			&& in_array( $incoming['status'], array( 'completed', 'dismissed' ), true )
		) {
			Setup_Fields::clear_review( wp_get_current_user() );
		}

		return rest_ensure_response(
			array(
				'tourId'   => $tour_id,
				'progress' => $progress,
			)
		);
	}
}
