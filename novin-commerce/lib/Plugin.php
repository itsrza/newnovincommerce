<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the dashboard.
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Novin_Commerce
 * @subpackage Novin_Commerce/includes
 */

namespace MobinDev\Novin_Commerce;

use As247\WpEloquent\Application;
use MobinDev\Novin_Commerce\Admin\AdminNotice;
use MobinDev\Novin_Commerce\Admin\Menu;
use MobinDev\Novin_Commerce\Common\Currency_Conversion;
use MobinDev\Novin_Commerce\Common\Woocommerce;
use MobinDev\Novin_Commerce\Common\Sync;
use MobinDev\Novin_Commerce\Common\Accounting\Product_Health_Snapshot;
use MobinDev\Novin_Commerce\Digits\Digits_Module;
use MobinDev\Novin_Commerce\Frontend\Shortcode;
use MobinDev\Novin_Commerce\Frontend\Woocommerce_Menu;

/**
 * The core plugin class.
 *
 * This is used to define internationalization, dashboard-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Novin_Commerce
 * @subpackage Novin_Commerce/includes
 * @author     MobinDev <mobin7332@gmail.com>
 */
class Plugin {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader $loader Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $pluginname The string used to uniquely identify this plugin.
	 */
	protected $pluginname = 'novin-commerce';

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string $version The current version of the plugin.
	 */
	protected $version = '1.20.0';

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->loader = new Loader();
	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the I18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new I18n();
		$plugin_i18n->set_domain( $this->get_plugin_name() );
		$plugin_i18n->load_plugin_textdomain();

	}

	/**
	 * Register all the hooks related to the dashboard functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

//		$plugin_admin = new Admin( $this );
//
//		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
//		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		new Menu( $this );
		new Admin\Woocommerce($this);
		AdminNotice::hook();

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_frontend_hooks() {

//		$plugin_frontend = new Frontend( $this );
//
//		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_frontend, 'enqueue_styles' );
//		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_frontend, 'enqueue_scripts' );

		new Woocommerce( $this );
		Product_Health_Snapshot::boot();
		// Keep the automatic queue hooks active on both the public site and
		// admin requests. Manual table actions are only a fallback; product,
		// order, customer, category and variation changes must be queued too.
		new Sync( $this );
		new Shortcode( $this );
		new Woocommerce_Menu( $this );

		if ( class_exists( '\WooCommerce' ) ) {
			// Register unit conversion before role pricing. The conversion
			// callback runs at the final priority, after any role price has
			// been selected, so role prices are divided exactly once.
			Currency_Conversion::boot();
			\MobinDev\Novin_Commerce\RolePrice\Bootstrap::boot();
		}

	}

	/**
	 * Run the loader to execute all the hooks with WordPress.
	 *
	 * Load the dependencies, define the locale, and set the hooks for the Dashboard and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		Application::bootWp();
		// Schema upgrades are explicit activation/admin work. A frontend or
		// REST read must not schedule events or mutate plugin tables/options.
		if ( is_admin() ) {
			add_action( 'admin_init', [ Activator::class, 'maybeUpgrade' ], 1 );
		}
		$this->set_locale();
		$this->loader->add_action( 'novin_commerce_daily_maintenance', Activator::class, 'run_maintenance', 10, 0 );
		$this->loader->add_action( 'novin_commerce_run_migration', Activator::class, 'run_migration', 10, 0 );
		$this->define_admin_hooks();
		$this->define_frontend_hooks();
		Digits_Module::boot( $this );
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @return    string    The name of the plugin.
	 * @since     1.0.0
	 */
	public function get_plugin_name() {
		return $this->pluginname;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @return    Loader    Orchestrates the hooks of the plugin.
	 * @since     1.0.0
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @return    string    The version number of the plugin.
	 * @since     1.0.0
	 */
	public function get_version() {
		return $this->version;
	}


	public function getPluginFile() {
		$novin = \plugin_dir_path( dirname( __FILE__ ) ) . 'novin-commerce.php';

		return $novin;
	}

	public function getAdminScriptUrl() {
		return \plugin_dir_url( dirname( __FILE__ ) ) . 'dist/scripts/admin/';
	}

	public function getAdminStyleUrl() {
		return \plugin_dir_url( dirname( __FILE__ ) ) . 'dist/styles/admin/';
	}

	public function getFrontScriptUrl() {
		return \plugin_dir_url( dirname( __FILE__ ) ) . 'dist/scripts/frontend/';
	}

	public function getFrontStyleUrl() {
		return \plugin_dir_url( dirname( __FILE__ ) ) . 'dist/styles/frontend/';
	}

	public function getScriptUrl() {
		return \plugin_dir_url( dirname( __FILE__ ) ) . 'dist/scripts/';
	}

	public function getStyleUrl() {
		return \plugin_dir_url( dirname( __FILE__ ) ) . 'dist/styles/';
	}


}
