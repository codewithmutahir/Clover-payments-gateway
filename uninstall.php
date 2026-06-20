<?php
/**
 * Uninstall: remove sensitive data when the plugin is deleted.
 *
 * @package Clover_Payment_Gateway
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Stop scheduled license checks.
wp_clear_scheduled_hook( 'clover_license_weekly_verify' );

// Remove licensing options and activation redirect transient.
delete_option( 'clover_license_data' );
delete_option( 'clover_license_verify_fail_streak' );
delete_option( 'clover_license_invalid' );
delete_option( 'clover_license_setup_pending' );
delete_transient( 'clover_gw_license_activation_redirect' );

// Clear stored API credentials from gateway settings (production-safe uninstall).
$option_key = 'woocommerce_clover_gateway_settings';
$settings   = get_option( $option_key, array() );

if ( is_array( $settings ) ) {
	$settings['api_token']   = '';
	$settings['private_key'] = '';
	$settings['public_key']  = '';
	update_option( $option_key, $settings );
}
