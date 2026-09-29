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

        // The bundled module uses NovinCommerce_* class names and can safely
        // coexist with an older standalone WCPBR installation. Do not skip
        // the admin fields just because that legacy plugin defines
        // WCPBR_VERSION; imported products still need this module's fields
        // and Festi-compatible price reader.
        self::$booted = true;
        if ( class_exists( '\\MobinDev\\Novin_Commerce\\Common\\Currency_Conversion' ) ) {
            \\MobinDev\\Novin_Commerce\\Common\\Currency_Conversion::boot();
        }

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
