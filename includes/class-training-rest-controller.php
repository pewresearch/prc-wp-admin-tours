<?php
/**
 * REST routes for builder training modules.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET  /prc-api/v3/wp-admin-tours/training
 * POST /prc-api/v3/wp-admin-tours/training/start
 * POST /prc-api/v3/wp-admin-tours/training/complete
 */
class Training_REST_Controller {
	public const NAMESPACE = 'prc-api/v3';
	public const ROUTE     = 'wp-admin-tours/training';

	/**
	 * Tour registry.
	 *
	 * @var Tour_Registry
	 */
	private Tour_Registry $tours;

	/**
	 * Module registry.
	 *
	 * @var Training_Module_Registry
	 */
	private Training_Module_Registry $modules;

	/**
	 * Constructor.
	 *
	 * @param Loader                   $loader  Loader.
	 * @param Tour_Registry            $tours   Tour registry.
	 * @param Training_Module_Registry $modules Module registry.
	 */
	public function __construct( Loader $loader, Tour_Registry $tours, Training_Module_Registry $modules ) {
		$this->tours   = $tours;
		$this->modules = $modules;
		$loader->add_action( 'rest_api_init', $this, 'register_rest_endpoints' );
	}

	/**
	 * Base URL of the training routes.
	 */
	public static function rest_url(): string {
		return rest_url( self::NAMESPACE . '/' . self::ROUTE );
	}

	/**
	 * Register the list, start, and complete routes.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_endpoints(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_modules' ),
					'permission_callback' => array( $this, 'list_permission' ),
					'args'                => array(
						'user_id' => array(
							'type'     => 'integer',
							'required' => false,
							'minimum'  => 1,
						),
					),
				),
			)
		);

		$module_args = array(
			'moduleId' => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => __NAMESPACE__ . '\\sanitize_tour_id',
			),
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/start',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start_module' ),
					'permission_callback' => array( $this, 'self_permission' ),
					'args'                => $module_args,
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE . '/complete',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'complete_module' ),
					'permission_callback' => array( $this, 'self_permission' ),
					'args'                => $module_args,
				),
			)
		);
	}

	/**
	 * Training rolled out to the current user. Another user's progress needs `edit_user`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function list_permission( WP_REST_Request $request ) {
		$allowed = $this->self_permission();
		if ( true !== $allowed ) {
			return $allowed;
		}

		$user_id = self::requested_user_id( $request );
		if ( get_current_user_id() !== $user_id && ! current_user_can( 'edit_user', $user_id ) ) {
			return self::forbidden();
		}

		return true;
	}

	/**
	 * Logged in, with training rolled out to the current user.
	 *
	 * @return true|WP_Error
	 */
	public function self_permission() {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || ! Training_Gate::is_enabled( $user_id ) ) {
			return self::forbidden();
		}

		return true;
	}

	/**
	 * List every module with the user's progress and each builder's gate state.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_modules( WP_REST_Request $request ) {
		$user_id = self::requested_user_id( $request );
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error(
				'prc_training_unknown_user',
				__( 'Unknown user.', 'prc-wp-admin-tours' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( self::list_payload( $user_id, $this->modules ) );
	}

	/**
	 * Start a module for the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_module( WP_REST_Request $request ) {
		$started = $this->start_for_current_user( (string) $request->get_param( 'moduleId' ) );
		return is_wp_error( $started ) ? $started : rest_ensure_response( $started );
	}

	/**
	 * Start a module for the current user: reset its tour so it runs from the
	 * first step, and create or reuse the user's sandbox draft on this site.
	 *
	 * @param string $module_id Module id.
	 * @return array{module: array<string, mixed>, progress: array<string, mixed>, sandbox: array<string, mixed>}|WP_Error
	 */
	public function start_for_current_user( string $module_id ) {
		$user_id  = (int) get_current_user_id();
		$resolved = $this->resolve_module( $module_id );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		list( $module, $tour ) = $resolved;

		$post_type = get_post_type_object( $module['sandbox']['post_type'] );
		if ( null === $post_type ) {
			return new WP_Error(
				'prc_training_unknown_post_type',
				__( 'This training module uses a post type that is not registered on this site.', 'prc-wp-admin-tours' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( $tour['capability'] ) || ! current_user_can( $post_type->cap->create_posts ) ) {
			return self::forbidden();
		}

		$missing = self::missing_requirements( $user_id, $module );
		if ( array() !== $missing ) {
			return new WP_Error(
				'prc_training_requires',
				__( 'Finish the required training modules first.', 'prc-wp-admin-tours' ),
				array(
					'status'   => 409,
					'requires' => $missing,
				)
			);
		}

		$sandbox = Training_Sandbox::get_or_create( $user_id, $module );
		if ( is_wp_error( $sandbox ) ) {
			return $sandbox;
		}

		Progress::apply( $user_id, $tour['id'], array( 'status' => 'not_started' ), (int) $tour['version'] );

		return array(
			'module'   => Training_Module_Registry::to_public( $module ),
			'progress' => Training_Progress::start( $user_id, $module['id'] ),
			'sandbox'  => $sandbox,
		);
	}

	/**
	 * Complete a started module once its tour is complete.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function complete_module( WP_REST_Request $request ) {
		$user_id  = (int) get_current_user_id();
		$resolved = $this->resolve_module( (string) $request->get_param( 'moduleId' ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		list( $module, $tour ) = $resolved;

		if ( 'not_started' === Training_Progress::get( $user_id, $module['id'] )['status'] ) {
			return new WP_Error(
				'prc_training_not_started',
				__( 'Start this training module first.', 'prc-wp-admin-tours' ),
				array( 'status' => 409 )
			);
		}

		$tour_progress = Progress::get_tour( $user_id, $tour['id'], (int) $tour['version'] );
		if ( 'completed' !== $tour_progress['status'] ) {
			return new WP_Error(
				'prc_training_tour_incomplete',
				__( 'Finish the training tour first.', 'prc-wp-admin-tours' ),
				array( 'status' => 409 )
			);
		}

		$progress = Training_Progress::complete( $user_id, $module['id'] );

		return rest_ensure_response(
			array(
				'module'   => Training_Module_Registry::to_public( $module ),
				'progress' => $progress,
				'builder'  => Training_Gate::builder_status(
					$user_id,
					$module['builder'],
					$this->modules->for_builder( $module['builder'] )
				),
			)
		);
	}

	/**
	 * The list route payload.
	 *
	 * @param int                      $user_id  User id.
	 * @param Training_Module_Registry $registry Module registry.
	 * @return array{userId: int, modules: array<int, array<string, mixed>>, builders: array<string, array<string, mixed>>}
	 */
	public static function list_payload( int $user_id, Training_Module_Registry $registry ): array {
		$modules = array();
		foreach ( $registry->all() as $module ) {
			$modules[] = array_merge(
				Training_Module_Registry::to_public( $module ),
				array(
					'available' => array() === self::missing_requirements( $user_id, $module ),
					'progress'  => Training_Progress::get( $user_id, $module['id'] ),
				)
			);
		}

		$builders = array();
		foreach ( $registry->builders() as $builder ) {
			$builders[ $builder ] = Training_Gate::builder_status( $user_id, $builder, $registry->for_builder( $builder ) );
		}

		return array(
			'userId'   => $user_id,
			'modules'  => $modules,
			'builders' => $builders,
		);
	}

	/**
	 * Required module ids the user has not finished.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $module  Parsed module.
	 * @return string[]
	 */
	public static function missing_requirements( int $user_id, array $module ): array {
		return array_values(
			array_filter(
				(array) $module['requires'],
				static fn( string $required_id ): bool => ! Training_Progress::is_complete( $user_id, $required_id )
			)
		);
	}

	/**
	 * Find a module and its tour.
	 *
	 * @param string $module_id Module id.
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}|WP_Error
	 */
	private function resolve_module( string $module_id ) {
		$module = $this->modules->get( $module_id );
		if ( null === $module ) {
			return new WP_Error(
				'prc_training_unknown_module',
				__( 'Unknown training module.', 'prc-wp-admin-tours' ),
				array( 'status' => 404 )
			);
		}

		$tour = $this->tours->get( $module['tour'] );
		if ( null === $tour ) {
			return new WP_Error(
				'prc_training_unknown_tour',
				__( 'This training module has no registered tour.', 'prc-wp-admin-tours' ),
				array( 'status' => 404 )
			);
		}

		return array( $module, $tour );
	}

	/**
	 * The user id the list route reads, defaulting to the current user.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private static function requested_user_id( WP_REST_Request $request ): int {
		$user_id = (int) $request->get_param( 'user_id' );
		return $user_id > 0 ? $user_id : (int) get_current_user_id();
	}

	/**
	 * The error a caller gets without access.
	 */
	private static function forbidden(): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to do that.', 'prc-wp-admin-tours' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
