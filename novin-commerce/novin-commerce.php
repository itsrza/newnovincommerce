<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * Dashboard. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              http://npwp.ir
 * @since             1.0.0
 * @package           Novin_Commerce
 *
 * @wordpress-plugin
 * Plugin Name:       Novin Commerce
 * Plugin URI:        https://npwp.ir/
 * Description:       Connect novin accounting app to woocommerce.
 * Version:           1.14.0
 * Requires PHP:      7.4
 * Requires at least: 6.1
 * Requires Plugins:  woocommerce
 * Author:            Mohammad Hosein Mohaddes
 * Author URI:        https://npwp.ir/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       novin-commerce
 * Domain Path:       /languages
 */

if ( file_exists( dirname( __FILE__ ) . '/vendor/autoload.php' ) ) {
	require_once dirname( __FILE__ ) . '/vendor/autoload.php';
}

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The code that runs during plugin activation.
 * This action is documented in lib/Activator.php
 */
\register_activation_hook( __FILE__, '\MobinDev\Novin_Commerce\Activator::activate' );

/**
 * The code that runs during plugin deactivation.
 * This action is documented in lib/Deactivator.php
 */
\register_deactivation_hook( __FILE__, '\MobinDev\Novin_Commerce\Deactivator::deactivate' );

add_action( 'wp_ajax_remove_guid', function () {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
    }

    if ( empty( $_POST['id'] ) || empty( $_POST['_wpnonce'] ) ) {
        wp_send_json_error( array( 'message' => 'درخواست نامعتبر' ), 400 );
    }

    $item_id   = absint( wp_unslash( $_POST['id'] ) );
    $item_type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'product';
    $nonce     = sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );

    if ( ! $item_id || ! wp_verify_nonce( $nonce, 'remove_guid_' . $item_id ) ) {
        wp_send_json_error( array( 'message' => 'دسترسی غیرمجاز' ), 403 );
    }

    $meta_keys = array( 'guid', '_np-api-sync-date', 'WebPrd' );

    switch ( $item_type ) {
        case 'category':
            if ( ! current_user_can( 'manage_product_terms' ) ) {
                wp_send_json_error( array( 'message' => 'دسترسی به این مورد مجاز نیست' ), 403 );
            }
            foreach ( $meta_keys as $key ) {
                delete_term_meta( $item_id, $key );
            }
            break;

        case 'user':
            if ( ! current_user_can( 'edit_user', $item_id ) ) {
                wp_send_json_error( array( 'message' => 'دسترسی به این مورد مجاز نیست' ), 403 );
            }
            foreach ( $meta_keys as $key ) {
                delete_user_meta( $item_id, $key );
            }
            break;

        case 'order':
            if ( ! current_user_can( 'edit_shop_order', $item_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
                wp_send_json_error( array( 'message' => 'دسترسی به این مورد مجاز نیست' ), 403 );
            }
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( $item_id ) : false;
            if ( $order ) {
                foreach ( $meta_keys as $key ) {
                    $order->delete_meta_data( $key );
                }
                $order->save();
            } else {
                foreach ( $meta_keys as $key ) {
                    delete_post_meta( $item_id, $key );
                }
            }
            break;

        case 'product':
        case 'variation':
        default:
            if ( ! current_user_can( 'edit_post', $item_id ) ) {
                wp_send_json_error( array( 'message' => 'دسترسی به این مورد مجاز نیست' ), 403 );
            }
            foreach ( $meta_keys as $key ) {
                delete_post_meta( $item_id, $key );
            }
            break;
    }

    if ( class_exists( '\MobinDev\Novin_Commerce\Common\SyncLog' ) ) {
        \MobinDev\Novin_Commerce\Common\SyncLog::add( 'disconnect', 'success', $item_type, $item_id, 'ارتباط مورد با حسابداری قطع شد.' );
    }
    delete_transient( 'novin_commerce_health_v1' );
    delete_transient( 'novin_commerce_health_v2' );

    if ( class_exists( '\MobinDev\Novin_Commerce\Admin\Mismatch_Page' ) ) {
        \MobinDev\Novin_Commerce\Admin\Mismatch_Page::clearCache();
    }

    wp_send_json_success( array( 'message' => 'ارتباط با حسابداری قطع شد و متاها حذف شدند.' ) );
} );

/**
 * جلوگیری از کپی شدن متاهای خاص هنگام تکثیر محصول در ووکامرس
 */
add_action('woocommerce_product_duplicate', function ($duplicate, $product) {
    $blocked_meta_keys = ['guid', '_np-api-sync-date', 'WebPrd'];
    $id = $duplicate->get_id();

    foreach ($blocked_meta_keys as $meta_key) {
        if (metadata_exists('post', $id, $meta_key)) {
            delete_post_meta($id, $meta_key);
        }
    }
}, 10, 2);




/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
\add_action( 'plugins_loaded', function () {
	/**
	 * @var $_novin_commerce \MobinDev\Novin_Commerce\Plugin
	 */
	global $_novin_commerce;
    $_novin_commerce = new \MobinDev\Novin_Commerce\Plugin();
    $_novin_commerce->run();
} );
