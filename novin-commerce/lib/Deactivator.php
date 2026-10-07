<?php

/**
 * Fired during plugin deactivation
 *
 * @link       http://example.com
 * @since      1.0.0
 *
 * @package    Novin_Commerce
 * @subpackage Novin_Commerce/includes
 */

namespace MobinDev\Novin_Commerce;

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Novin_Commerce
 * @subpackage Novin_Commerce/includes
 * @author     MobinDev <mobin7332@gmail.com>
 */
class Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		Activator::unscheduleMaintenance();
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( 'novin_commerce_run_migration' );
		}
	}

}
