<?php
/**
 * Register and enqueue the tours engine.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Enqueue the admin tour engine when the built assets exist.
 */
class Assets {
	public const SCRIPT_HANDLE = 'prc-wp-admin-tours';

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
		$loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_admin_assets' );
		$loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_admin_assets' );
	}

	/**
	 * Enqueue the engine on wp-admin screens, including the block editor.
	 *
	 * @hook admin_enqueue_scripts
	 * @hook enqueue_block_editor_assets
	 */
	public function enqueue_admin_assets(): void {
		if ( ! is_user_logged_in() || is_network_admin() ) {
			return;
		}

		$asset_file = PRC_WP_ADMIN_TOURS_DIR . '/build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;
		if ( ! is_array( $asset ) ) {
			return;
		}

		$handle = self::SCRIPT_HANDLE;
		if ( wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		$tours = $this->registry->matching( Screen::current(), (int) get_current_user_id() );
		if ( array() === $tours ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/index.js', PRC_WP_ADMIN_TOURS_FILE ),
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? PRC_WP_ADMIN_TOURS_VERSION ),
			true
		);

		wp_localize_script(
			$handle,
			'prcWpAdminTours',
			array(
				'tours'    => $tours,
				'progress' => Progress::for_tours( (int) get_current_user_id(), $tours ),
				'screen'   => Screen::current(),
				'welcome'  => Welcome::payload(),
				'restUrl'  => rest_url( 'prc-api/v3/wp-admin-tours/progress' ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);

		$driver_css = PRC_WP_ADMIN_TOURS_DIR . '/build/index.css';
		if ( file_exists( $driver_css ) ) {
			wp_enqueue_style(
				$handle . '-driver',
				plugins_url( 'build/index.css', PRC_WP_ADMIN_TOURS_FILE ),
				array(),
				(string) ( $asset['version'] ?? PRC_WP_ADMIN_TOURS_VERSION )
			);
		}

		$style_path = PRC_WP_ADMIN_TOURS_DIR . '/build/style-index.css';
		if ( file_exists( $style_path ) ) {
			$style_deps = array( 'wp-components' );
			if ( wp_style_is( $handle . '-driver', 'enqueued' ) ) {
				$style_deps[] = $handle . '-driver';
			}
			wp_enqueue_style(
				$handle,
				plugins_url( 'build/style-index.css', PRC_WP_ADMIN_TOURS_FILE ),
				$style_deps,
				(string) ( $asset['version'] ?? PRC_WP_ADMIN_TOURS_VERSION )
			);
		}

		if (
			function_exists( 'wp_enqueue_command_palette_assets' ) &&
			! has_action( 'admin_enqueue_scripts', 'wp_enqueue_command_palette_assets' )
		) {
			wp_enqueue_command_palette_assets();
		}
	}
}
