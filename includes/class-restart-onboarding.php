<?php
/**
 * "Restart Onboarding" entry points: admin bar link and profile button.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

use WP_Admin_Bar;
use WP_User;

/**
 * Reopens the welcome splash with every setup field listed again.
 *
 * The admin bar also renders on the front end, where the tours bundle does not
 * load. So the action runs on the server through admin-post.php, then sends the
 * user into wp-admin, where the splash starts.
 */
class Restart_Onboarding {
	public const ACTION = 'prc_wp_admin_tours_restart_onboarding';

	public const ADMIN_BAR_NODE_ID = 'prc-wp-admin-tours-restart-onboarding';

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
		$loader->add_action( 'admin_bar_menu', $this, 'add_admin_bar_node', 100 );
		$loader->add_action( 'show_user_profile', $this, 'render_profile_section' );
		$loader->add_action( 'admin_post_' . self::ACTION, $this, 'handle_request' );
	}

	/**
	 * Nonce-protected URL that restarts onboarding for the current user.
	 */
	public static function url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION ),
			self::ACTION
		);
	}

	/**
	 * Add the link to the profile dropdown in the admin bar.
	 *
	 * @hook admin_bar_menu
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar.
	 */
	public function add_admin_bar_node( $admin_bar ): void {
		if ( ! $admin_bar instanceof WP_Admin_Bar || ! current_user_can( Welcome::CAPABILITY ) ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'parent' => 'user-actions',
				'id'     => self::ADMIN_BAR_NODE_ID,
				'title'  => __( 'Restart Onboarding', 'prc-wp-admin-tours' ),
				'href'   => self::url(),
			)
		);
	}

	/**
	 * Print the Onboarding section on a user's own profile screen.
	 *
	 * @hook show_user_profile
	 *
	 * @param WP_User $user Profile user.
	 */
	public function render_profile_section( $user ): void {
		if (
			! $user instanceof WP_User
			|| is_network_admin()
			|| get_current_user_id() !== $user->ID
			|| ! current_user_can( Welcome::CAPABILITY )
		) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Onboarding', 'prc-wp-admin-tours' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr class="prc-wp-admin-tours-restart-onboarding-wrap">
				<th scope="row"><?php esc_html_e( 'Welcome screen', 'prc-wp-admin-tours' ); ?></th>
				<td>
					<a class="button" href="<?php echo esc_url( self::url() ); ?>">
						<?php esc_html_e( 'Restart Onboarding', 'prc-wp-admin-tours' ); ?>
					</a>
					<p class="description">
						<?php esc_html_e( 'Reopen the welcome screen to update your name, editor preferences, Slack, and ORCID.', 'prc-wp-admin-tours' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Reset onboarding for the current user, then open wp-admin.
	 *
	 * @hook admin_post_prc_wp_admin_tours_restart_onboarding
	 */
	public function handle_request(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( Welcome::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You are not allowed to restart onboarding.', 'prc-wp-admin-tours' ),
				'',
				array( 'response' => 403 )
			);
		}

		self::reset( wp_get_current_user(), $this->registry );

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Mark the welcome splash not started and list every setup field again.
	 *
	 * @param WP_User       $user     User.
	 * @param Tour_Registry $registry Tour registry.
	 */
	public static function reset( WP_User $user, Tour_Registry $registry ): void {
		$tour = $registry->get( Welcome::TOUR_ID );
		if ( null !== $tour ) {
			Progress::apply(
				$user->ID,
				Welcome::TOUR_ID,
				array( 'status' => 'not_started' ),
				(int) $tour['version']
			);
		}

		Setup_Fields::set_review( $user );
	}
}
