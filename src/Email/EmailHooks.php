<?php
/**
 * Email registration.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's emails (withdrawal acknowledgement and rejection) with WooCommerce so they
 * appear under WooCommerce → Settings → Emails and are constructed through the store's mailer.
 */
final class EmailHooks {

	/**
	 * Hook into WooCommerce email registration and the admin-notification trigger.
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email' ) );
		add_action( 'recesso_dig_request_confirmed', array( $this, 'notify_admin' ), 30, 1 );
	}

	/**
	 * Email the configured recipients when a withdrawal is confirmed.
	 *
	 * @param \Recesso54bis\Domain\WithdrawalRequest $request The confirmed request.
	 */
	public function notify_admin( \Recesso54bis\Domain\WithdrawalRequest $request ): void {
		if ( ! function_exists( 'WC' ) || ! class_exists( '\WC_Email' ) ) {
			return;
		}

		WC()->mailer();
		( new WithdrawalAdminNotificationEmail() )->trigger_for( $request );
	}

	/**
	 * Add the plugin emails to the WooCommerce email classes.
	 *
	 * @param array<string, \WC_Email> $emails Registered email classes.
	 *
	 * @return array<string, \WC_Email>
	 */
	public function register_email( array $emails ): array {
		$emails['recesso_dig_acknowledgement']    = new WithdrawalAcknowledgementEmail();
		$emails['recesso_dig_rejection']          = new WithdrawalRejectionEmail();
		$emails['recesso_dig_status_update']      = new WithdrawalStatusUpdateEmail();
		$emails['recesso_dig_admin_notification'] = new WithdrawalAdminNotificationEmail();
		$emails['recesso_dig_link']               = new WithdrawalLinkEmail();

		return $emails;
	}
}
