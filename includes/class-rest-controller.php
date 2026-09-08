<?php
/**
 * REST route for tour progress.
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
	 * Register the progress route.
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
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$tour = $this->registry->get( (string) $request->get_param( 'tourId' ) );
		if ( null === $tour ) {
			return false;
		}

		return current_user_can( $tour['capability'] );
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

		$progress = Progress::apply(
			(int) get_current_user_id(),
			$tour_id,
			$incoming,
			(int) $tour['version']
		);

		return rest_ensure_response(
			array(
				'tourId'   => $tour_id,
				'progress' => $progress,
			)
		);
	}
}
