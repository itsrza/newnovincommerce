<?php

namespace MobinDev\Novin_Commerce\Admin;

use MobinDev\Novin_Commerce\Common\SettingAPI;
use MobinDev\Novin_Commerce\Plugin;

class Woocommerce {
	/**
	 * The plugin's instance.
	 *
	 * @since  1.0.0
	 * @access private
	 * @var    Plugin $plugin This plugin's instance.
	 */
	private $plugin;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @param Plugin $plugin This plugin's instance.
	 *
	 * @since 1.0.0
	 *
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		$this->hooks();
	}

	public function hooks() {
		$this->plugin->get_loader()->add_filter( 'woocommerce_admin_disabled', $this, 'disableAnalytics' );
	}

	public function disableAnalytics() {
		if ( SettingAPI::get( 'woocommerce_analytics', 'off' ) === 'on' ) {
			return false;
		}

		return true;
	}
}
