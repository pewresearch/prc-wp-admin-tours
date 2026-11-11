<?php
/**
 * Per-builder training gate and the `training-modules` rollout flag.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use PRC\Primitives\Feature_Flags\VisitorContext;
use function PRC\Primitives\Feature_Flags\is_feature_enabled;
use function PRC\Primitives\Feature_Flags\register_feature_flag;

/**
 * A gated user cannot create content in a builder until they finish its required modules.
 */
class Training_Gate {
	public const FLAG = 'training-modules';

	public const FILTER_GATED_ROLES      = 'prc_wp_admin_tours_training_gated_roles';
	public const FILTER_REQUIRED_MODULES = 'prc_wp_admin_tours_training_required_modules';
	public const FILTER_BUILDER_UNLOCKED = 'prc_wp_admin_tours_training_builder_unlocked';

	public const DEFAULT_GATED_ROLES      = array( 'editor', 'author', 'contributor' );
	public const DEFAULT_REQUIRED_MODULES = 1;

	/**
	 * Whether the flag is registered for this request.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Constructor.
	 *
	 * @param Loader $loader Loader.
	 */
	public function __construct( Loader $loader ) {
		$loader->add_action( 'init', $this, 'register_flag', 5 );
	}

	/**
	 * Register the rollout flag. It defaults off.
	 *
	 * @hook init 5
	 */
	public function register_flag(): void {
		if ( ! function_exists( '\PRC\Primitives\Feature_Flags\register_feature_flag' ) ) {
			return;
		}

		register_feature_flag(
			self::FLAG,
			array(
				'label'       => __( 'Builder training modules', 'prc-wp-admin-tours' ),
				'description' => __( 'Turns on builder training: sandbox drafts with guided tours, and the Add New gate for editors and below until they finish a builder\'s training.', 'prc-wp-admin-tours' ),
			)
		);
		self::$registered = true;
	}

	/**
	 * Whether training is rolled out to a user.
	 *
	 * @param int $user_id User id.
	 */
	public static function is_enabled( int $user_id ): bool {
		if ( ! self::$registered || $user_id <= 0 ) {
			return false;
		}

		$visitor = get_current_user_id() === $user_id ? null : VisitorContext::for_user( $user_id );
		return is_feature_enabled( self::FLAG, $visitor );
	}

	/**
	 * Roles that must train before they create content in a builder.
	 *
	 * @param string $builder Builder key.
	 * @return string[]
	 */
	public static function gated_roles( string $builder ): array {
		/**
		 * Filters the roles that must finish a builder's training before they create content in it.
		 *
		 * Administrators and super admins are never gated, even when listed here.
		 *
		 * @param string[] $roles   Role slugs. Default editor, author, contributor.
		 * @param string   $builder Builder key, such as `chart`.
		 */
		$roles = apply_filters( self::FILTER_GATED_ROLES, self::DEFAULT_GATED_ROLES, $builder );
		return array_values( array_filter( array_map( 'sanitize_key', (array) $roles ) ) );
	}

	/**
	 * How many modules a gated user must finish to unlock a builder.
	 *
	 * The count never exceeds the number of modules the builder registers.
	 *
	 * @param string $builder Builder key.
	 * @param int    $available Modules registered for the builder.
	 */
	public static function required_modules( string $builder, int $available ): int {
		/**
		 * Filters how many of a builder's modules a gated user must finish.
		 *
		 * @param int    $count   Required module count. Default 1.
		 * @param string $builder Builder key, such as `chart`.
		 */
		$count = (int) apply_filters( self::FILTER_REQUIRED_MODULES, self::DEFAULT_REQUIRED_MODULES, $builder );
		return max( 0, min( $count, $available ) );
	}

	/**
	 * Whether the user's role is gated for a builder.
	 *
	 * @param int    $user_id User id.
	 * @param string $builder Builder key.
	 */
	public static function is_user_gated( int $user_id, string $builder ): bool {
		if ( $user_id <= 0 || is_super_admin( $user_id ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$roles = array_values( (array) $user->roles );
		if ( in_array( 'administrator', $roles, true ) ) {
			return false;
		}

		return array() !== array_intersect( $roles, self::gated_roles( $builder ) );
	}

	/**
	 * Gate state for one builder.
	 *
	 * @param int                              $user_id User id.
	 * @param string                           $builder Builder key.
	 * @param array<int, array<string, mixed>> $modules The builder's modules.
	 * @return array{unlocked: bool, gated: bool, required: int, completed: int}
	 */
	public static function builder_status( int $user_id, string $builder, array $modules ): array {
		$builder   = sanitize_key( $builder );
		$required  = self::required_modules( $builder, count( $modules ) );
		$completed = count(
			array_filter(
				$modules,
				static fn( array $module ): bool => Training_Progress::is_complete( $user_id, (string) $module['id'] )
			)
		);
		$gated     = self::is_enabled( $user_id ) && self::is_user_gated( $user_id, $builder );
		$unlocked  = $user_id > 0 && ( ! $gated || $completed >= $required );

		/**
		 * Filters whether a user may create content in a builder.
		 *
		 * @param bool   $unlocked Whether the builder is unlocked.
		 * @param int    $user_id  User id.
		 * @param string $builder  Builder key, such as `chart`.
		 */
		$unlocked = (bool) apply_filters( self::FILTER_BUILDER_UNLOCKED, $unlocked, $user_id, $builder );

		return array(
			'unlocked'  => $unlocked,
			'gated'     => $gated,
			'required'  => $required,
			'completed' => $completed,
		);
	}

	/**
	 * Whether a user may create content in a builder.
	 *
	 * Unlocked when training is not rolled out to the user, the user's role is
	 * not gated, or the user finished the builder's required modules.
	 *
	 * @param int    $user_id User id.
	 * @param string $builder Builder key.
	 */
	public static function is_builder_unlocked( int $user_id, string $builder ): bool {
		$registry = Training_Module_Registry::instance();
		$modules  = null === $registry ? array() : $registry->for_builder( $builder );
		return self::builder_status( $user_id, $builder, $modules )['unlocked'];
	}
}
