<?php
/**
 * Training section on the user profile screen.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use WP_User;

/**
 * Lists every training module with the profile user's network-wide progress.
 *
 * The user sees Start buttons on their own profile. A viewer with `edit_user`
 * sees the same list on another user's profile, read-only.
 */
class Training_Profile {
	public const SCRIPT_HANDLE = 'prc-wp-admin-tours-training-profile';
	public const ROOT_ID       = 'prc-wp-admin-tours-training-profile';

	/**
	 * Training module registry.
	 *
	 * @var Training_Module_Registry
	 */
	private Training_Module_Registry $modules;

	/**
	 * Constructor.
	 *
	 * @param Loader                   $loader  Loader.
	 * @param Training_Module_Registry $modules Training module registry.
	 */
	public function __construct( Loader $loader, Training_Module_Registry $modules ) {
		$this->modules = $modules;
		$loader->add_action( 'show_user_profile', $this, 'render' );
		$loader->add_action( 'edit_user_profile', $this, 'render' );
	}

	/**
	 * Whether the current user sees the Training section on a profile.
	 *
	 * Training must be rolled out to both the viewer and the profile user, and
	 * at least one module must be registered.
	 *
	 * @param WP_User                  $user     Profile user.
	 * @param Training_Module_Registry $registry Module registry.
	 */
	public static function is_visible( WP_User $user, Training_Module_Registry $registry ): bool {
		$viewer_id = (int) get_current_user_id();
		if ( $viewer_id <= 0 || is_network_admin() || array() === $registry->all() ) {
			return false;
		}

		if ( $viewer_id !== $user->ID && ! current_user_can( 'edit_user', $user->ID ) ) {
			return false;
		}

		return Training_Gate::is_enabled( $viewer_id ) && Training_Gate::is_enabled( $user->ID );
	}

	/**
	 * Data the panel boots with.
	 *
	 * @param WP_User                  $user     Profile user.
	 * @param Training_Module_Registry $registry Module registry.
	 * @return array{restUrl: string, userId: int, canStart: bool, builders: array<string, string>}
	 */
	public static function config( WP_User $user, Training_Module_Registry $registry ): array {
		return array(
			'restUrl'  => Training_REST_Controller::rest_url(),
			'userId'   => $user->ID,
			'canStart' => (int) get_current_user_id() === $user->ID,
			'builders' => self::builder_labels( $registry ),
		);
	}

	/**
	 * A heading for each builder: the plural label of its sandbox post type,
	 * or the builder key when that post type is not registered on this site.
	 *
	 * @param Training_Module_Registry $registry Module registry.
	 * @return array<string, string>
	 */
	public static function builder_labels( Training_Module_Registry $registry ): array {
		$labels = array();
		foreach ( $registry->builders() as $builder ) {
			$modules   = $registry->for_builder( $builder );
			$post_type = get_post_type_object( (string) ( $modules[0]['sandbox']['post_type'] ?? '' ) );

			$labels[ $builder ] = null !== $post_type && ! empty( $post_type->labels->name )
				? (string) $post_type->labels->name
				: ucwords( str_replace( array( '-', '_' ), ' ', $builder ) );
		}
		return $labels;
	}

	/**
	 * Print the Training section and load the panel that fills it.
	 *
	 * @hook show_user_profile
	 * @hook edit_user_profile
	 *
	 * @param WP_User $user Profile user.
	 */
	public function render( $user ): void {
		if ( ! $user instanceof WP_User || ! self::is_visible( $user, $this->modules ) || ! $this->enqueue() ) {
			return;
		}
		?>
		<h2 id="<?php echo esc_attr( self::ROOT_ID ); ?>-heading"><?php esc_html_e( 'Training', 'prc-wp-admin-tours' ); ?></h2>
		<div
			id="<?php echo esc_attr( self::ROOT_ID ); ?>"
			class="prc-wp-admin-tours-training-profile"
			aria-labelledby="<?php echo esc_attr( self::ROOT_ID ); ?>-heading"
			data-config="<?php echo esc_attr( (string) wp_json_encode( self::config( $user, $this->modules ) ) ); ?>"
		></div>
		<?php
	}

	/**
	 * Enqueue the panel bundle in the footer.
	 *
	 * @return bool Whether the built assets exist.
	 */
	private function enqueue(): bool {
		$asset_file = PRC_WP_ADMIN_TOURS_DIR . '/build/training-profile.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return false;
		}

		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return false;
		}

		$version = (string) ( $asset['version'] ?? PRC_WP_ADMIN_TOURS_VERSION );
		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/training-profile.js', PRC_WP_ADMIN_TOURS_FILE ),
			(array) ( $asset['dependencies'] ?? array() ),
			$version,
			true
		);

		if ( file_exists( PRC_WP_ADMIN_TOURS_DIR . '/build/training-profile.css' ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'build/training-profile.css', PRC_WP_ADMIN_TOURS_FILE ),
				array( 'wp-components' ),
				$version
			);
		}

		return true;
	}
}
