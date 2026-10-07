<?php

namespace Novinwp\Novin_Commerce\Digits;

use Novinwp\Novin_Commerce\Digits\Admin\Digits_Setting_Menu;
use Novinwp\Novin_Commerce\Digits\Common\Digits_Activator;
use Novinwp\Novin_Commerce\Digits\Common\Digits_Settings;
use Novinwp\Novin_Commerce\Digits\Frontend\Mobile_Auth;
use Novinwp\Novin_Commerce\Digits\Frontend\Woocommerce_Integration;
use Novinwp\Novin_Commerce\Plugin;

/**
 * Single entry point for the Digits (mobile signup/login) module.
 *
 * Boot this from Plugin::define_admin_hooks()/define_frontend_hooks()
 * with `Digits_Module::boot( $this )`. Everything the module needs
 * (its own DB tables, its own settings option, its own admin page) is
 * self-contained under lib/Digits/, so the module can be removed by
 * deleting this call plus the lib/Digits/ folder, without touching any
 * of the plugin's accounting-sync code.
 */
class Digits_Module {

	public static function boot( Plugin $plugin ) {
		Digits_Activator::maybe_upgrade();

		$settings_menu = new Digits_Setting_Menu( $plugin );
		$settings_menu->hooks();

		// Keep the settings page and migration available while the optional
		// mobile-auth feature is disabled. Do not register frontend auth,
		// WooCommerce phone filters, AJAX endpoints or page-capture fallbacks
		// until the site owner explicitly enables Digits.
		if ( 'on' !== Digits_Settings::get( 'enabled', 'off' ) ) {
			return;
		}

		$woocommerce_integration = new Woocommerce_Integration( $plugin );
		$woocommerce_integration->hooks();

		$mobile_auth = new Mobile_Auth( $plugin );
		$mobile_auth->hooks();
	}
}
