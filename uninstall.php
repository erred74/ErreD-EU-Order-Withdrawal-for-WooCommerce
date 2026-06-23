<?php
/**
 * Uninstall routine.
 *
 * Runs only when the user deletes the plugin. Destructive cleanup is gated behind an explicit,
 * user-set opt-in option so that legal withdrawal records are never removed by accident.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Fail closed: only proceed if the merchant explicitly opted in to data deletion.
if ( '1' !== (string) get_option( 'recesso_dig_delete_data_on_uninstall', '0' ) ) {
	return;
}

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require __DIR__ . '/vendor/autoload.php';

	if ( class_exists( \Recesso54bis\Support\Capabilities::class ) ) {
		\Recesso54bis\Support\Capabilities::remove();
	}

	// Drop the custom tables.
	if ( class_exists( \Recesso54bis\Persistence\Schema::class ) ) {
		global $wpdb;
		$recesso_dig_requests_table = \Recesso54bis\Persistence\Schema::requests_table();
		$recesso_dig_log_table      = \Recesso54bis\Persistence\Schema::log_table();
		$recesso_dig_claims_table   = \Recesso54bis\Persistence\Schema::claims_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $recesso_dig_requests_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $recesso_dig_log_table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $recesso_dig_claims_table ) );
	}

	// Remove the auto-created flow page, if present.
	if ( class_exists( \Recesso54bis\Frontend\FlowPage::class ) ) {
		$recesso_dig_flow_page = (int) get_option( \Recesso54bis\Frontend\FlowPage::OPTION, 0 );
		if ( $recesso_dig_flow_page > 0 ) {
			wp_delete_post( $recesso_dig_flow_page, true );
		}
	}
}

// Remove plugin options.
$recesso_dig_options = array(
	'recesso_dig_db_version',
	'recesso_dig_token_secret',
	'recesso_dig_delete_data_on_uninstall',
	'recesso_dig_window_days',
	'recesso_dig_start_trigger',
	'recesso_dig_default_policy',
	'recesso_dig_excluded_products',
	'recesso_dig_allowed_products',
	'recesso_dig_excluded_categories',
	'recesso_dig_allowed_categories',
	'recesso_dig_flow_page_id',
	'recesso_dig_product_notice_enabled',
	'recesso_dig_product_notice_text',
	'recesso_dig_consent_digital_enabled',
	'recesso_dig_consent_digital_required',
	'recesso_dig_consent_digital_text',
	'recesso_dig_consent_service_enabled',
	'recesso_dig_consent_service_text',
	'recesso_dig_trader_name',
	'recesso_dig_trader_address',
	'recesso_dig_trader_email',
	'recesso_dig_admin_recipients',
	'recesso_dig_footer_link_enabled',
	'recesso_dig_enforcement_mode',
	'recesso_dig_grace_days',
);

foreach ( $recesso_dig_options as $recesso_dig_option ) {
	delete_option( $recesso_dig_option );
}
