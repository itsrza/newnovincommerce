<?php
/**
 * Remove NovinCommerce data when the plugin is uninstalled.
 *
 * The cleanup is deliberately limited to tables/options and metadata owned by
 * this plugin. Shared WooCommerce fields and the legacy festiUserRolePrices
 * compatibility metadata are left intact.
 *
 * @package Novin_Commerce
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

global $wpdb;

$network_wide = false;
if ( is_multisite() ) {
	$network_wide = ! empty( $_REQUEST['networkwide'] )
		|| ( function_exists( 'is_network_admin' ) && is_network_admin() );
	if ( function_exists( 'is_plugin_active_for_network' ) ) {
		$network_wide = $network_wide || is_plugin_active_for_network( plugin_basename( WP_UNINSTALL_PLUGIN ) );
	}
}

$blog_ids = array( get_current_blog_id() );
if ( $network_wide ) {
	$blog_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
}

foreach ( $blog_ids as $blog_id ) {
	$switched = is_multisite() && (int) $blog_id !== (int) get_current_blog_id();
	if ( $switched ) {
		switch_to_blog( (int) $blog_id );
	}

	global $wpdb;

	// Stop future maintenance runs before removing the data they use.
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( 'novin_commerce_daily_maintenance' );
	} else {
		wp_clear_scheduled_hook( 'novin_commerce_daily_maintenance' );
	}

	$options = array(
		'novin_commerce_settings',
		'novin_commerce_schema_version',
		'novin_commerce_notice',
		'novin_commerce_digits_settings',
		'novin_commerce_digits_sms_gateways',
		'novin_commerce_digits_schema_version',
		'wcpbr_roles_config',
		'wcpbr_general_settings',
		'wcpbr_mismatch_scan_result',
		'wcpbr_mismatch_scan_time',
		'wcpbr_admin_access',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$transients = array(
		'novin_commerce_health_v1',
		'novin_commerce_health_v2',
		'novin_commerce_health_v3',
		'novin_guid_mismatch_count',
		'novin_guid_mismatch_rows',
	);
	foreach ( $transients as $transient ) {
		delete_transient( $transient );
	}

	// OTP IP buckets and sync locks have dynamic transient names.
	$transient_prefixes = array(
		'_transient_novin_digits_otp_ip_',
		'_transient_timeout_novin_digits_otp_ip_',
		'_transient_novin_commerce_sync_lock_',
		'_transient_timeout_novin_commerce_sync_lock_',
	);
	foreach ( $transient_prefixes as $prefix ) {
		$like = $wpdb->esc_like( $prefix ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned option prefix.
	}

	$tables = array(
		$wpdb->prefix . 'novin_commerce_syncs',
		$wpdb->prefix . 'novin_commerce_sync_logs',
		$wpdb->prefix . 'novin_commerce_digits_otp',
		$wpdb->prefix . 'novin_commerce_digits_sms_log',
	);
	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table names.
	}

	// Remove only plugin-owned role-price/stock metadata. Do not remove the
	// shared GUID, WebPrd, billing, shipping, or festiUserRolePrices fields.
	$wcpbr_like = $wpdb->esc_like( '_wcpbr_' ) . '%';
	$wpdb->query(
		$wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wcpbr_like )
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
			'digits_phone',
			'digits_phone_no'
		)
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned Digits identity keys.

	if ( $switched ) {
		restore_current_blog();
	}
}
