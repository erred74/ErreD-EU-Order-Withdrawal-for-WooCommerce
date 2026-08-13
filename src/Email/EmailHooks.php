<?php
/**
 * Email registration.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's emails (withdrawal acknowledgement and rejection) with WooCommerce so they
 * appear under WooCommerce → Settings → Emails and are constructed through the store's mailer.
 */
final class EmailHooks {

	/**
	 * Email ids belonging to this plugin. Used to scope the sender override, so the merchant's choice
	 * never leaks into the store's other emails.
	 *
	 * @var string[]
	 */
	private const OWN_EMAIL_IDS = array(
		'recesso_dig_acknowledgement',
		'recesso_dig_rejection',
		'recesso_dig_status_update',
		'recesso_dig_admin_notification',
		'recesso_dig_link',
	);

	/**
	 * Hook into WooCommerce email registration and the admin-notification trigger.
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'register_email' ) );
		add_action( 'recesso_dig_request_confirmed', array( $this, 'notify_admin' ), 30, 1 );
		add_filter( 'woocommerce_email_from_name', array( $this, 'from_name' ), 10, 2 );
		add_filter( 'woocommerce_email_from_address', array( $this, 'from_address' ), 10, 2 );
	}

	/**
	 * Override the sender name for this plugin's emails only.
	 *
	 * @param mixed $name  The sender name WooCommerce resolved.
	 * @param mixed $email The email being sent.
	 *
	 * @return mixed
	 */
	public function from_name( $name, $email = null ) {
		$configured = ( new Settings() )->email_from_name();

		return ( '' !== $configured && $this->is_own_email( $email ) ) ? $configured : $name;
	}

	/**
	 * Override the sender address for this plugin's emails only.
	 *
	 * @param mixed $address The sender address WooCommerce resolved.
	 * @param mixed $email   The email being sent.
	 *
	 * @return mixed
	 */
	public function from_address( $address, $email = null ) {
		$configured = ( new Settings() )->email_from_address();

		return ( '' !== $configured && $this->is_own_email( $email ) ) ? $configured : $address;
	}

	/**
	 * Whether the email currently being sent is one of this plugin's.
	 *
	 * @param mixed $email The email object passed by WooCommerce.
	 */
	private function is_own_email( $email ): bool {
		return $email instanceof \WC_Email && in_array( (string) $email->id, self::OWN_EMAIL_IDS, true );
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
