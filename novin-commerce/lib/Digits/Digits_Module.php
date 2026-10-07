<?php

namespace MobinDev\Novin_Commerce\Digits;

use MobinDev\Novin_Commerce\Digits\Admin\Digits_Setting_Menu;
use MobinDev\Novin_Commerce\Digits\Common\Digits_Activator;
use MobinDev\Novin_Commerce\Digits\Frontend\Mobile_Auth;
use MobinDev\Novin_Commerce\Digits\Frontend\Woocommerce_Integration;
use MobinDev\Novin_Commerce\Plugin;

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

		$woocommerce_integration = new Woocommerce_Integration( $plugin );
		$woocommerce_integration->hooks();

		$mobile_auth = new Mobile_Auth( $plugin );
		$mobile_auth->hooks();
	}
}
