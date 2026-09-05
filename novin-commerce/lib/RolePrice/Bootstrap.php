<?php

namespace MobinDev\Novin_Commerce\RolePrice;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Bootstrap {
    private static $booted = false;

    public static function boot() {
        if ( self::$booted || ! class_exists( '\\WooCommerce' ) ) {
            return;
        }

        // During migration, do not load a second copy of the old standalone plugin.
        if ( defined( 'WCPBR_VERSION' ) ) {
            return;
        }

        self::$booted = true;

        $files = array(
            'class-wcpbr-roles.php',
            'class-wcpbr-settings.php',
            'class-wcpbr-admin-fields.php',
            'class-wcpbr-price-engine.php',
            'class-wcpbr-accounting-sync.php',
            'class-wcpbr-access-control.php',
            'class-wcpbr-multi-unit-stock.php',
        );

        foreach ( $files as $file ) {
            $path = dirname( __FILE__ ) . '/' . $file;
            if ( is_readable( $path ) ) {
                require_once $path;
            }
        }

        $classes = array(
            '\\NovinCommerce_RolePrice_Settings',
            '\\NovinCommerce_RolePrice_Admin_Fields',
            '\\NovinCommerce_RolePrice_Price_Engine',
            '\\NovinCommerce_RolePrice_Accounting_Sync',
            '\\NovinCommerce_RolePrice_Access_Control',
            '\\NovinCommerce_RolePrice_Multi_Unit_Stock',
        );

        foreach ( $classes as $class ) {
            if ( class_exists( $class ) ) {
                new $class();
            }
        }
    }
}
