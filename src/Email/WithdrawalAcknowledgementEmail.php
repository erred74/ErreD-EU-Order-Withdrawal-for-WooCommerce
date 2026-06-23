<?php
/**
 * Withdrawal acknowledgement email.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The durable-medium acknowledgement, delivered as a WooCommerce email with the PDF receipt
 * attached. Subclassing WC_Email makes it templatable, translatable and routed through the store's
 * configured mailer, and lists it under WooCommerce → Settings → Emails.
 */
class WithdrawalAcknowledgementEmail extends \WC_Email {

	use RequestContext;

	/**
	 * The request being acknowledged (set per send).
	 *
	 * @var WithdrawalRequest|null
	 */
	private ?WithdrawalRequest $request = null;

	/**
	 * Secure download URL for the receipt (set per send).
	 *
	 * @var string
	 */
	private string $download_url = '';

	/**
	 * Construct the email.
	 */
	public function __construct() {
		$this->id             = 'recesso_dig_acknowledgement';
		$this->customer_email = true;
		$this->title          = __( 'Withdrawal acknowledgement (recesso)', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->description    = __( 'Durable-medium acknowledgement sent to the consumer when a withdrawal is confirmed.', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->template_html  = 'emails/withdrawal-acknowledgement.php';
		$this->template_plain = 'emails/plain/withdrawal-acknowledgement.php';
		$this->template_base  = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'templates/';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject(): string {
		return __( 'Acknowledgement of your withdrawal request', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading(): string {
		return __( 'Acknowledgement of withdrawal receipt', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Send the acknowledgement for a confirmed request, attaching the PDF receipt.
	 *
	 * @param WithdrawalRequest $request      The confirmed request.
	 * @param string            $pdf_path     Absolute path to the stored PDF receipt.
	 * @param string            $download_url Secure receipt download URL.
	 */
	public function trigger_for( WithdrawalRequest $request, string $pdf_path, string $download_url ): bool {
		$this->request      = $request;
		$this->download_url = $download_url;
		$this->recipient    = $request->confirmation_email;

		$this->setup_locale();

		// The acknowledgement of receipt on a durable medium is legally required (art. 11-bis(4)); it is
		// sent whenever there is a recipient and is NOT gated by the WC email enable/disable toggle (a
		// custom WC_Email with no saved settings reports is_enabled() === false, which previously
		// silenced it). The toggle still governs subject/heading customisation in WooCommerce → Emails.
		$sent = false;
		if ( '' !== $this->get_recipient() ) {
			$attachments = is_readable( $pdf_path ) ? array( $pdf_path ) : array();
			$sent        = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $attachments );
		}

		$this->restore_locale();

		return (bool) $sent;
	}

	/**
	 * HTML body.
	 */
	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			$this->template_args(),
			'',
			$this->template_base
		);
	}

	/**
	 * Plain-text body.
	 */
	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			$this->template_args(),
			'',
			$this->template_base
		);
	}

	/**
	 * Shared template arguments.
	 *
	 * @return array<string, mixed>
	 */
	private function template_args(): array {
		// The itemised products, order date and partial/full scope are resolved from the live order
		// through the same resolver the PDF uses, so the email describes exactly what the durable receipt
		// records. Presentation only — never feeds the receipt hash.
		$context  = $this->request_context( $this->request );
		$settings = new Settings();

		return array_merge(
			$context,
			array(
				'request'       => $this->request,
				'download_url'  => $this->download_url,
				'site_name'     => get_bloginfo( 'name' ),
				'window_days'   => $settings->window_days(),
				'start_trigger' => $settings->start_trigger(),
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			)
		);
	}
}
