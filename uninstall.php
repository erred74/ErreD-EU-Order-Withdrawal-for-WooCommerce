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

	// Remove only pages the plugin itself auto-created, identified by the created-by-plugin post
	// meta marker. The flow page option may point to any page the merchant selected in settings,
	// so it must never be used as a deletion criterion. As a second guard, a marked page is only
	// deleted while it still hosts the withdrawal shortcode; a repurposed page is left untouched.
	if ( class_exists( \Recesso54bis\Frontend\FlowPage::class ) ) {
		$recesso_dig_created_pages = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => 'any',
				'numberposts' => 20,
				'meta_key'    => \Recesso54bis\Frontend\FlowPage::CREATED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-off bounded lookup during uninstall.
				'meta_value'  => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- One-off bounded lookup during uninstall.
			)
		);
		foreach ( $recesso_dig_created_pages as $recesso_dig_created_page ) {
			if ( false !== strpos( (string) $recesso_dig_created_page->post_content, '[recesso_digitale]' ) ) {
				wp_delete_post( (int) $recesso_dig_created_page->ID, true );
			}
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
	'recesso_dig_consent_service_required',
	'recesso_dig_consent_service_text',
	'recesso_dig_trader_name',
	'recesso_dig_trader_address',
	'recesso_dig_trader_phone',
	'recesso_dig_trader_email',
	'recesso_dig_model_form_enabled',
	'recesso_dig_admin_recipients',
	'recesso_dig_footer_link_enabled',
	'recesso_dig_enforcement_mode',
	'recesso_dig_grace_days',
	'recesso_dig_eligible_statuses',
	'recesso_dig_manage_roles',
	'recesso_dig_email_from_name',
	'recesso_dig_email_from_address',
	'recesso_dig_email_accepted_text',
	'recesso_dig_email_rejected_text',
	'recesso_dig_email_completed_text',
	'recesso_dig_form_intro_enabled',
	'recesso_dig_form_intro_text',
	'recesso_dig_consumer_declaration_enabled',
	'recesso_dig_consumer_declaration_text',
	'recesso_dig_consents_conditional',
	'recesso_dig_notice_digital_title',
	'recesso_dig_notice_digital_body',
	'recesso_dig_notice_dated_title',
	'recesso_dig_notice_dated_body',
	'recesso_dig_notice_other_title',
	'recesso_dig_notice_other_body',
	'recesso_dig_account_endpoint_enabled',
	'recesso_dig_lookup_title',
	'recesso_dig_lookup_intro',
	'recesso_dig_lookup_email_hint',
	'recesso_dig_lookup_submit',
	'recesso_dig_button_accent',
	'recesso_dig_button_style',
	'recesso_dig_plugin_version',
);

foreach ( $recesso_dig_options as $recesso_dig_option ) {
	delete_option( $recesso_dig_option );
}
