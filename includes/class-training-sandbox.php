<?php
/**
 * Sandbox drafts for training modules: create, reuse, hide, and expire.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use WP_Error;
use WP_Query;

/**
 * A sandbox is a draft owned by one user on one site. Its state lives on the
 * post, not in user meta, because drafts are per site.
 */
class Training_Sandbox {
	public const META_MODULE  = '_prc_training_module';
	public const META_EXPIRES = '_prc_training_expires';

	public const EXPIRE_HOOK = 'prc_wp_admin_tours_training_expire_sandbox';
	public const SWEEP_HOOK  = 'prc_wp_admin_tours_training_sweep_sandboxes';
	public const AS_GROUP    = 'prc-wp-admin-tours';

	public const START_ACTION = 'prc_wp_admin_tours_training_start';

	public const WELCOME_GUIDE_HANDLE = 'prc-wp-admin-tours-training-welcome-guide';

	/**
	 * Query var that lets a query see sandbox drafts.
	 */
	public const QUERY_VAR = 'prc_training_sandboxes';

	public const FILTER_TTL     = 'prc_wp_admin_tours_training_sandbox_ttl';
	public const FILTER_POSTARR = 'prc_wp_admin_tours_training_sandbox_postarr';

	/**
	 * Expired sandboxes one sweep deletes.
	 */
	public const SWEEP_BATCH = 100;

	/**
	 * Training REST controller, for the start link.
	 *
	 * @var Training_REST_Controller
	 */
	private Training_REST_Controller $controller;

	/**
	 * Constructor.
	 *
	 * @param Loader                   $loader     Loader.
	 * @param Training_REST_Controller $controller Training REST controller.
	 */
	public function __construct( Loader $loader, Training_REST_Controller $controller ) {
		$this->controller = $controller;
		$loader->add_action( self::EXPIRE_HOOK, $this, 'expire' );
		$loader->add_action( self::SWEEP_HOOK, $this, 'sweep' );
		$loader->add_action( 'pre_get_posts', $this, 'exclude_from_query' );
		$loader->add_filter( 'prc_wp_admin_dataview_query_args', $this, 'exclude_from_query_args' );
		$loader->add_action( 'admin_post_' . self::START_ACTION, $this, 'handle_start_request' );
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'suppress_welcome_guide' );
	}

	/**
	 * Seconds from creation until a sandbox is deleted.
	 */
	public static function ttl(): int {
		/**
		 * Filters how long a training sandbox draft lives.
		 *
		 * @param int $seconds Seconds after creation. Default three days.
		 */
		return max( HOUR_IN_SECONDS, (int) apply_filters( self::FILTER_TTL, 3 * DAY_IN_SECONDS ) );
	}

	/**
	 * Reuse the user's live sandbox for a module on this site, or create one.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $module  Parsed module.
	 * @return array{postId: int, editUrl: string, expiresAt: int, reused: bool}|WP_Error
	 */
	public static function get_or_create( int $user_id, array $module ) {
		$post_id = self::find( $user_id, $module );
		$reused  = null !== $post_id;
		if ( ! $reused ) {
			$post_id = self::create( $user_id, $module );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}
		}

		return array(
			'postId'    => $post_id,
			'editUrl'   => self::edit_url( $post_id, (string) $module['id'] ),
			'expiresAt' => (int) get_post_meta( $post_id, self::META_EXPIRES, true ),
			'reused'    => $reused,
		);
	}

	/**
	 * The user's newest unexpired sandbox for a module on this site.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $module  Parsed module.
	 */
	public static function find( int $user_id, array $module ): ?int {
		$query = new WP_Query(
			array(
				'post_type'              => $module['sandbox']['post_type'],
				'post_status'            => 'draft',
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Author-scoped, one row.
					array(
						'key'   => self::META_MODULE,
						'value' => $module['id'],
					),
					array(
						'key'     => self::META_EXPIRES,
						'value'   => time(),
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
				self::QUERY_VAR          => true,
			)
		);

		return array() === $query->posts ? null : (int) $query->posts[0];
	}

	/**
	 * Create a sandbox draft from the module template and schedule its deletion.
	 *
	 * @param int                  $user_id User id.
	 * @param array<string, mixed> $module  Parsed module.
	 * @return int|WP_Error Post id.
	 */
	public static function create( int $user_id, array $module ) {
		$postarr = array(
			'post_type'    => $module['sandbox']['post_type'],
			'post_status'  => 'draft',
			'post_author'  => $user_id,
			/* translators: %s: training module title. */
			'post_title'   => sprintf( __( 'Training: %s', 'prc-wp-admin-tours' ), $module['title'] ),
			'post_content' => self::template_to_content( $module['sandbox']['template'] ),
		);

		/**
		 * Filters the post a training module creates as its sandbox draft.
		 *
		 * The status, author, and sandbox meta are always set by the host.
		 *
		 * @param array<string, mixed> $postarr Arguments for wp_insert_post().
		 * @param array<string, mixed> $module  Parsed module.
		 * @param int                  $user_id User id.
		 */
		$postarr = (array) apply_filters( self::FILTER_POSTARR, $postarr, $module, $user_id );

		$postarr['post_status'] = 'draft';
		$postarr['post_author'] = $user_id;

		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$expires = time() + self::ttl();
		update_post_meta( $post_id, self::META_MODULE, (string) $module['id'] );
		update_post_meta( $post_id, self::META_EXPIRES, $expires );

		self::schedule_expiry( $post_id, $expires );
		self::schedule_sweep();

		return $post_id;
	}

	/**
	 * Schedule one sandbox deletion through Action Scheduler, or wp-cron when
	 * Action Scheduler is not loaded or cannot store the action.
	 *
	 * @param int $post_id   Post id.
	 * @param int $timestamp When to delete it.
	 */
	public static function schedule_expiry( int $post_id, int $timestamp ): void {
		if (
			function_exists( 'as_schedule_single_action' )
			&& 0 < (int) as_schedule_single_action( $timestamp, self::EXPIRE_HOOK, array( $post_id ), self::AS_GROUP )
		) {
			return;
		}

		wp_schedule_single_event( $timestamp, self::EXPIRE_HOOK, array( $post_id ) );
	}

	/**
	 * Schedule the daily sweep on this site if it is not scheduled yet.
	 */
	public static function schedule_sweep(): void {
		if ( false === wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::SWEEP_HOOK );
		}
	}

	/**
	 * Force-delete an expired sandbox. Anything else is left alone, so a repeat run is a no-op.
	 *
	 * @hook prc_wp_admin_tours_training_expire_sandbox
	 *
	 * @param int|string $post_id Post id.
	 * @return bool Whether the post was deleted.
	 */
	public static function expire( $post_id ): bool {
		$post_id = (int) $post_id;
		if ( ! self::is_sandbox( $post_id ) ) {
			return false;
		}

		if ( (int) get_post_meta( $post_id, self::META_EXPIRES, true ) > time() ) {
			return false;
		}

		return (bool) wp_delete_post( $post_id, true );
	}

	/**
	 * Delete expired sandboxes that a scheduled deletion missed.
	 *
	 * @hook prc_wp_admin_tours_training_sweep_sandboxes
	 *
	 * @return int Deleted count.
	 */
	public static function sweep(): int {
		$query = new WP_Query(
			array(
				'post_type'              => array_values( get_post_types() ),
				'post_status'            => array_keys( get_post_stati() ),
				'posts_per_page'         => self::SWEEP_BATCH,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Daily cron, capped batch.
					array(
						'key'     => self::META_EXPIRES,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
				self::QUERY_VAR          => true,
			)
		);

		$deleted = 0;
		foreach ( $query->posts as $post_id ) {
			if ( self::expire( (int) $post_id ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Whether a post is a training sandbox.
	 *
	 * @param int $post_id Post id.
	 */
	public static function is_sandbox( int $post_id ): bool {
		return $post_id > 0
			&& null !== get_post( $post_id )
			&& '' !== (string) get_post_meta( $post_id, self::META_MODULE, true );
	}

	/**
	 * Module id of a sandbox the user owns, or an empty string for any other post.
	 *
	 * @param mixed $post    Post object.
	 * @param int   $user_id User id.
	 */
	public static function module_of( $post, int $user_id ): string {
		if ( ! is_object( $post ) || empty( $post->ID ) || $user_id <= 0 || (int) ( $post->post_author ?? 0 ) !== $user_id ) {
			return '';
		}
		return (string) get_post_meta( (int) $post->ID, self::META_MODULE, true );
	}

	/**
	 * Module id of the user's sandbox open in the block editor, or an empty string.
	 *
	 * The block editor drops `?prc-training=` from the address bar after load,
	 * so a reload reads the module from the post instead.
	 *
	 * @param int $user_id User id.
	 */
	public static function editor_module( int $user_id ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || 'post' !== ( $screen->base ?? '' ) ) {
			return '';
		}
		return self::module_of( get_post(), $user_id );
	}

	/**
	 * Keep core's editor welcome guide closed in a sandbox, where it would cover the module tour.
	 *
	 * This sets the preference default, not the stored preference, so the guide
	 * still opens in other posts for a user who never closed it.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public static function suppress_welcome_guide(): void {
		if ( '' === self::editor_module( (int) get_current_user_id() ) ) {
			return;
		}

		wp_register_script(
			self::WELCOME_GUIDE_HANDLE,
			false,
			array( 'wp-edit-post', 'wp-preferences', 'wp-data', 'wp-dom-ready' ),
			PRC_WP_ADMIN_TOURS_VERSION,
			true
		);
		// Runs after the wp.domReady callback that initializes the editor and sets its defaults.
		wp_add_inline_script(
			self::WELCOME_GUIDE_HANDLE,
			"wp.domReady( function () { wp.data.dispatch( 'core/preferences' ).setDefaults( 'core/edit-post', { welcomeGuide: false } ); } );"
		);
		wp_enqueue_script( self::WELCOME_GUIDE_HANDLE );
	}

	/**
	 * Editor URL that opens a sandbox and runs its module tour.
	 *
	 * @param int    $post_id   Post id.
	 * @param string $module_id Module id.
	 */
	public static function edit_url( int $post_id, string $module_id ): string {
		return add_query_arg(
			array(
				'post'             => $post_id,
				'action'           => 'edit',
				TRAINING_QUERY_ARG => $module_id,
			),
			admin_url( 'post.php' )
		);
	}

	/**
	 * Link that starts a module for the current user and opens its sandbox,
	 * for screens and redirects without the REST client. The URL is raw:
	 * escape it for HTML output.
	 *
	 * @param string $module_id Module id.
	 */
	public static function start_url( string $module_id ): string {
		$module_id = sanitize_tour_id( $module_id );
		return add_query_arg(
			array(
				'action'   => self::START_ACTION,
				'module'   => $module_id,
				'_wpnonce' => wp_create_nonce( self::START_ACTION . ':' . $module_id ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Start a module from the start link, then open its sandbox.
	 *
	 * @hook admin_post_prc_wp_admin_tours_training_start
	 */
	public function handle_start_request(): void {
		$module_id = isset( $_GET['module'] ) ? sanitize_tour_id( wp_unslash( (string) $_GET['module'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce checked next; sanitize_tour_id() sanitizes.
		check_admin_referer( self::START_ACTION . ':' . $module_id );

		$started = Training_Gate::is_enabled( (int) get_current_user_id() )
			? $this->controller->start_for_current_user( $module_id )
			: new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'prc-wp-admin-tours' ), array( 'status' => 403 ) );

		if ( is_wp_error( $started ) ) {
			$data = $started->get_error_data();
			wp_die(
				esc_html( $started->get_error_message() ),
				'',
				array(
					'response'  => (int) ( is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 ),
					'back_link' => true,
				)
			);
		}

		wp_safe_redirect( $started['sandbox']['editUrl'] );
		exit;
	}

	/**
	 * Hide sandboxes from front-end and REST queries that could return drafts.
	 *
	 * Other queries never return drafts, so they are left alone. wp-admin
	 * screens and queries that set `prc_training_sandboxes` see sandboxes.
	 *
	 * @hook pre_get_posts
	 *
	 * @param WP_Query $query Query.
	 */
	public static function exclude_from_query( $query ): void {
		if ( ! $query instanceof WP_Query || is_admin() || $query->get( self::QUERY_VAR ) ) {
			return;
		}

		$status   = $query->get( 'post_status' );
		$statuses = is_array( $status ) ? $status : explode( ',', (string) $status );
		if ( array() === array_intersect( array_map( 'trim', $statuses ), array( 'draft', 'any' ) ) ) {
			return;
		}

		$query->set( 'meta_query', self::with_exclusion( $query->get( 'meta_query' ) ) );
	}

	/**
	 * Hide sandboxes from DataViews lists.
	 *
	 * @hook prc_wp_admin_dataview_query_args
	 *
	 * @param array<string, mixed> $args WP_Query arguments.
	 * @return array<string, mixed>
	 */
	public static function exclude_from_query_args( $args ): array {
		$args               = (array) $args;
		$args['meta_query'] = self::with_exclusion( $args['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- NOT EXISTS on a key only sandboxes have.
		return $args;
	}

	/**
	 * Turn a module template into post content.
	 *
	 * A string is block markup and is used as it is. An array is a block
	 * template: `[ name, attributes, inner template ]` entries.
	 *
	 * @param array<int, mixed>|string $template Template.
	 */
	public static function template_to_content( $template ): string {
		if ( is_string( $template ) ) {
			return $template;
		}

		return serialize_blocks( self::template_to_blocks( (array) $template ) );
	}

	/**
	 * Convert template entries into parsed-block arrays for serialize_blocks().
	 *
	 * @param array<int, mixed> $template Template entries.
	 * @return array<int, array<string, mixed>>
	 */
	private static function template_to_blocks( array $template ): array {
		$blocks = array();
		foreach ( $template as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry[0] ) || ! is_string( $entry[0] ) || '' === $entry[0] ) {
				continue;
			}

			$inner    = self::template_to_blocks( isset( $entry[2] ) && is_array( $entry[2] ) ? $entry[2] : array() );
			$blocks[] = array(
				'blockName'    => $entry[0],
				'attrs'        => isset( $entry[1] ) && is_array( $entry[1] ) ? $entry[1] : array(),
				'innerBlocks'  => $inner,
				'innerHTML'    => '',
				'innerContent' => array_fill( 0, count( $inner ), null ),
			);
		}
		return $blocks;
	}

	/**
	 * Add a "no sandbox meta" clause to a meta query.
	 *
	 * @param mixed $meta_query Existing meta query.
	 * @return array<int|string, mixed>
	 */
	private static function with_exclusion( $meta_query ): array {
		$clause = array(
			'key'     => self::META_MODULE,
			'compare' => 'NOT EXISTS',
		);

		if ( ! is_array( $meta_query ) || array() === $meta_query ) {
			return array( $clause );
		}

		return array(
			'relation' => 'AND',
			$clause,
			$meta_query,
		);
	}
}
