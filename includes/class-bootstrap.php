<?php
/**
 * Bootstrap class.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */

declare( strict_types=1 );

namespace PRC\Platform\Wp_Admin_Tours;

/**
 * Bootstrap class.
 *
 * @package PRC\Platform\Wp_Admin_Tours
 */
class Bootstrap {
	public const TEST_TOURS_OPTION = 'prc_wp_admin_tours_test_tours';

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * Tour registry.
	 *
	 * @var Tour_Registry
	 */
	protected Tour_Registry $registry;

	/**
	 * Training module registry.
	 *
	 * @var Training_Module_Registry
	 */
	protected Training_Module_Registry $training_modules;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the platform as initialized by hooks.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = '1.0.0';
		$this->plugin_name = 'prc-wp-admin-tours';

		$this->load_dependencies();
		$this->init_dependencies();
	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';

		$this->loader = new Loader();

		require_once plugin_dir_path( __DIR__ ) . '/includes/class-tour-parser.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-tour-media.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-tour-registry.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-progress.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-screen.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-rest-controller.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-training-module-registry.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-training-progress.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-training-gate.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-training-rest-controller.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-training-sandbox.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-training-profile.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-assets.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-setup-fields.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-welcome.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-restart-onboarding.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-announcement-catalog.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-announcement-post-type.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-legacy-splash-conversion.php';
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->registry         = new Tour_Registry( $this->get_loader() );
		$this->training_modules = new Training_Module_Registry( $this->get_loader() );
		new Training_Gate( $this->get_loader() );
		new Welcome( $this->get_loader() );
		new REST_Controller( $this->get_loader(), $this->registry );
		$training_rest = new Training_REST_Controller( $this->get_loader(), $this->registry, $this->training_modules );
		new Training_Sandbox( $this->get_loader(), $training_rest );
		new Training_Profile( $this->get_loader(), $this->training_modules );
		new Assets( $this->get_loader(), $this->registry, $this->training_modules );
		new Restart_Onboarding( $this->get_loader(), $this->registry );
		new Announcement_Post_Type( $this->get_loader() );
		new Legacy_Splash_Conversion( $this->get_loader() );
		new Tour_Media( $this->get_loader() );
		$this->maybe_load_test_fixtures();
	}

	/**
	 * In PRC_PLATFORM_TESTING_MODE, register tours that Playwright specs write to an option.
	 *
	 * The VIP dev-env does not mount `tests/`, so specs send their fixture tours over
	 * `POST /wp/v2/settings` instead of a PHP fixture file.
	 */
	private function maybe_load_test_fixtures(): void {
		if ( ! defined( 'PRC_PLATFORM_TESTING_MODE' ) || ! PRC_PLATFORM_TESTING_MODE ) {
			return;
		}
		$this->loader->add_action( 'init', $this, 'register_test_tours_setting' );
		$this->loader->add_action( Tour_Registry::ACTION_REGISTER, $this, 'register_test_tours' );
	}

	/**
	 * Expose the fixture option to the settings REST route.
	 *
	 * @hook init
	 */
	public function register_test_tours_setting(): void {
		register_setting(
			'prc_wp_admin_tours_testing',
			self::TEST_TOURS_OPTION,
			array(
				'type'         => 'string',
				'default'      => '',
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Register the fixture tours, a JSON list of raw tour arrays.
	 *
	 * @hook prc_wp_admin_tours_register
	 *
	 * @param Tour_Registry $registry Tour registry.
	 */
	public function register_test_tours( $registry ): void {
		$tours = json_decode( (string) get_option( self::TEST_TOURS_OPTION, '' ), true );
		foreach ( is_array( $tours ) ? $tours : array() as $tour ) {
			if ( is_array( $tour ) ) {
				$registry->register( $tour );
			}
		}
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

	/**
	 * Tour registry.
	 *
	 * @return Tour_Registry
	 */
	public function get_registry(): Tour_Registry {
		return $this->registry;
	}

	/**
	 * Training module registry.
	 *
	 * @return Training_Module_Registry
	 */
	public function get_training_modules(): Training_Module_Registry {
		return $this->training_modules;
	}
}
