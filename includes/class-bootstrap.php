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
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-splash-catalog.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-tour-registry.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-progress.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-screen.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-rest-controller.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-assets.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-welcome.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-settings.php';
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->registry = new Tour_Registry( $this->get_loader() );
		new Welcome( $this->get_loader() );
		new Settings( $this->get_loader() );
		new REST_Controller( $this->get_loader(), $this->registry );
		new Assets( $this->get_loader(), $this->registry );
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
}
