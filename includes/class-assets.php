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
	public const SCRIPT_HANDLE  = 'prc-wp-admin-tours';
	public const RESIZER_HANDLE = 'prc-platform-iframe-embeds-resizer-script';

	/**
	 * Tour registry.
	 *
	 * @var Tour_Registry
	 */
	private Tour_Registry $registry;

	/**
	 * Training module registry.
	 *
	 * @var Training_Module_Registry
	 */
	private Training_Module_Registry $training_modules;

	/**
	 * Constructor.
	 *
	 * @param Loader                   $loader           Loader.
	 * @param Tour_Registry            $registry         Tour registry.
	 * @param Training_Module_Registry $training_modules Training module registry.
	 */
	public function __construct( Loader $loader, Tour_Registry $registry, Training_Module_Registry $training_modules ) {
		$this->registry         = $registry;
		$this->training_modules = $training_modules;
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
		$tours = Tour_Media::resolve_tours( $tours );

		$dependencies = (array) ( $asset['dependencies'] ?? array() );
		if ( in_array( 'announcement', array_column( $tours, 'mode' ), true ) && self::register_resizer() ) {
			$dependencies[] = self::RESIZER_HANDLE;
		}

		wp_enqueue_script(
			$handle,
			plugins_url( 'build/index.js', PRC_WP_ADMIN_TOURS_FILE ),
			$dependencies,
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
				'training' => self::training_payload( $this->training_modules, $tours, (int) get_current_user_id() ),
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

	/**
	 * Training modules whose tour runs on this screen, and the module named by
	 * `?prc-training=` or by the user's sandbox open in the editor.
	 *
	 * Null when training is not rolled out to the user. `resumeOnly` is true when
	 * the module comes from the open sandbox, not from the query arg: a reload,
	 * where a skipped tour stays skipped.
	 *
	 * @param Training_Module_Registry         $registry Module registry.
	 * @param array<int, array<string, mixed>> $tours    Tours localized on this screen.
	 * @param int                              $user_id  User id.
	 * @return array{restUrl: string, activeModuleId: string, resumeOnly: bool, modules: array<int, array<string, mixed>>}|null
	 */
	public static function training_payload( Training_Module_Registry $registry, array $tours, int $user_id ): ?array {
		if ( ! Training_Gate::is_enabled( $user_id ) ) {
			return null;
		}

		$tour_ids = array_column( $tours, 'id' );
		$modules  = array();
		foreach ( $registry->all() as $module ) {
			if ( in_array( $module['tour'], $tour_ids, true ) ) {
				$modules[] = array_merge(
					Training_Module_Registry::to_public( $module ),
					array( 'status' => Training_Progress::get( $user_id, $module['id'] )['status'] )
				);
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only; sanitize_tour_id() sanitizes.
		$active     = isset( $_GET[ TRAINING_QUERY_ARG ] ) ? sanitize_tour_id( wp_unslash( (string) $_GET[ TRAINING_QUERY_ARG ] ) ) : '';
		$from_query = '' !== $active;
		if ( ! $from_query ) {
			$active = Training_Sandbox::editor_module( $user_id );
		}
		if ( ! in_array( $active, array_column( $modules, 'id' ), true ) ) {
			$active = '';
		}

		return array(
			'restUrl'        => Training_REST_Controller::rest_url(),
			'activeModuleId' => $active,
			'resumeOnly'     => '' !== $active && ! $from_query,
			'modules'        => $modules,
		);
	}

	/**
	 * Register the iframe-resizer parent script where prc-embeds is not active.
	 *
	 * @return bool Whether the handle is registered.
	 */
	private static function register_resizer(): bool {
		if ( wp_script_is( self::RESIZER_HANDLE, 'registered' ) ) {
			return true;
		}

		$build      = WP_PLUGIN_DIR . '/prc-embeds/build/';
		$asset_file = $build . 'iframe-resizer.asset.php';
		if ( ! file_exists( $build . 'iframe-resizer.js' ) || ! file_exists( $asset_file ) ) {
			return false;
		}

		$asset = include $asset_file;
		return wp_register_script(
			self::RESIZER_HANDLE,
			plugins_url( 'build/iframe-resizer.js', WP_PLUGIN_DIR . '/prc-embeds/prc-embeds.php' ),
			(array) ( $asset['dependencies'] ?? array() ),
			(string) ( $asset['version'] ?? PRC_WP_ADMIN_TOURS_VERSION ),
			true
		);
	}
}
