<?php
/**
 * Withdrawal-link (magic link) email.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

defined( 'ABSPATH' ) || exit;

/**
 * Sent when a consumer requests a withdrawal link from the on-page lookup form (order number +
 * email). It carries the signed, single-purpose entry link to the store's order email — never to a
 * browser-supplied address — so a guest who lost the original email can still reach the flow without
 * exposing orders to enumeration. Templatable and translatable like the other plugin emails; its
 * subject and heading are editable under WooCommerce → Settings → Emails.
 */
class WithdrawalLinkEmail extends \WC_Email {

	/**
	 * The signed entry URL (set per send).
	 *
	 * @var string
	 */
	private string $url = '';

	/**
	 * Construct the email.
	 */
	public function __construct() {
		$this->id             = 'recesso_dig_link';
		$this->customer_email = true;
		$this->title          = __( 'Withdrawal link request (recesso)', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->description    = __( 'Sent to the order email when a consumer requests a withdrawal link from the on-page lookup form.', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->template_html  = 'emails/withdrawal-link.php';
		$this->template_plain = 'emails/plain/withdrawal-link.php';
		$this->template_base  = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'templates/';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject(): string {
		return __( 'Your withdrawal link for order {order_number}', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading(): string {
		return __( 'Exercise your right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Send the withdrawal-link email for an order.
	 *
	 * @param \WC_Order $order The order the link authorises.
	 * @param string    $url   The signed entry URL.
	 */
	public function trigger_for( \WC_Order $order, string $url ): bool {
		$this->setup_locale();

		$this->object    = $order;
		$this->url       = $url;
		$this->recipient = $order->get_billing_email();

		$created                              = $order->get_date_created();
		$this->placeholders['{order_number}'] = $order->get_order_number();
		$this->placeholders['{order_date}']   = $created instanceof \WC_DateTime ? wc_format_datetime( $created ) : '';

		// Sent whenever there is a recipient and is NOT gated by the WC email enable/disable toggle: a
		// custom WC_Email with no saved settings reports is_enabled() === false, which would silently
		// swallow the link the consumer explicitly requested. The toggle still governs subject/heading
		// customisation in WooCommerce → Settings → Emails.
		$sent = false;
		if ( '' !== $this->get_recipient() ) {
			$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), array() );
		}

		$this->restore_locale();

		return (bool) $sent;
	}

	/**
	 * HTML body.
	 */
	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->template_args(), '', $this->template_base );
	}

	/**
	 * Plain-text body.
	 */
	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->template_args(), '', $this->template_base );
	}

	/**
	 * Shared template arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function template_args(): array {
		return array(
			'order'         => $this->object instanceof \WC_Order ? $this->object : null,
			'url'           => $this->url,
			'label'         => __( 'recedere dal contratto qui', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'email_heading' => $this->get_heading(),
			'sent_to_admin' => false,
			'plain_text'    => false,
			'email'         => $this,
		);
	}
}
