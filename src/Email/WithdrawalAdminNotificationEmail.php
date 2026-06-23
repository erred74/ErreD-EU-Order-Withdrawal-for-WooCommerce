<?php
/**
 * Admin notification of a confirmed withdrawal.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Alerts the store (one or more configured recipients) when a consumer confirms a withdrawal, so the
 * merchant can act promptly. Built inline (no template file) and routed through the store mailer with
 * the WooCommerce email header/footer; subject and heading are editable under WooCommerce → Emails.
 */
class WithdrawalAdminNotificationEmail extends \WC_Email {

	/**
	 * The confirmed request (set per send).
	 *
	 * @var WithdrawalRequest|null
	 */
	private ?WithdrawalRequest $request = null;

	/**
	 * Construct the email.
	 */
	public function __construct() {
		$this->id          = 'recesso_dig_admin_notification';
		$this->title       = __( 'Withdrawal received (admin)', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->description = __( 'Sent to the store when a consumer confirms a withdrawal request.', 'erred-eu-order-withdrawal-for-woocommerce' );

		parent::__construct();

		$this->recipient = implode( ',', ( new Settings() )->admin_recipients() );
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject(): string {
		return __( 'New withdrawal request confirmed', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading(): string {
		return __( 'A withdrawal request was confirmed', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Send the admin notification for a confirmed request.
	 *
	 * @param WithdrawalRequest $request The confirmed request.
	 */
	public function trigger_for( WithdrawalRequest $request ): bool {
		$this->request   = $request;
		$this->recipient = implode( ',', ( new Settings() )->admin_recipients() );

		$this->setup_locale();

		$sent = false;
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), array() );
		}

		$this->restore_locale();

		return (bool) $sent;
	}

	/**
	 * HTML body (built inline, wrapped in the WooCommerce header/footer).
	 */
	public function get_content_html(): string {
		ob_start();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
		do_action( 'woocommerce_email_header', $this->get_heading(), $this );

		if ( $this->request instanceof WithdrawalRequest ) {
			echo '<p>' . esc_html__( 'A consumer has confirmed a withdrawal request.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
			echo '<ul>';
			echo '<li>' . esc_html__( 'Order / contract', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( $this->request->contract_reference ) . '</li>';
			echo '<li>' . esc_html__( 'Consumer', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( $this->request->consumer_name ) . '</li>';
			echo '<li>' . esc_html__( 'Confirmed (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( (string) $this->request->confirmed_at_gmt ) . '</li>';
			echo '</ul>';
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
		do_action( 'woocommerce_email_footer', $this );

		return (string) ob_get_clean();
	}

	/**
	 * Plain-text body.
	 */
	public function get_content_plain(): string {
		$lines = array( wp_strip_all_tags( $this->get_heading() ), '' );

		if ( $this->request instanceof WithdrawalRequest ) {
			$lines[] = __( 'A consumer has confirmed a withdrawal request.', 'erred-eu-order-withdrawal-for-woocommerce' );
			$lines[] = '';
			$lines[] = __( 'Order / contract', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . $this->request->contract_reference;
			$lines[] = __( 'Consumer', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . $this->request->consumer_name;
			$lines[] = __( 'Confirmed (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . (string) $this->request->confirmed_at_gmt;
		}

		return implode( "\n", $lines ) . "\n";
	}
}
