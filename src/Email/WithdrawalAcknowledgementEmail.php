<?php
/**
 * Withdrawal acknowledgement email.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

use Recesso54bis\Domain\Eligibility\WindowBasis;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Integration\WindowBasisResolver;
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
		$this->description    = __( 'Durable-medium acknowledgement sent to the consumer when a withdrawal is confirmed. Required by law, so the Enable switch does not apply to it: this email is always sent. The subject, heading and content below are yours to change.', 'erred-eu-order-withdrawal-for-woocommerce' );
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

		// The acknowledgement of receipt on a durable medium is legally required (art. 11-bis(4)), so it
		// is sent whenever there is a recipient and is NOT gated by the WC email enable/disable toggle:
		// a merchant must not be able to switch off the one message the law obliges them to send. The
		// toggle still governs subject/heading customisation in WooCommerce → Settings → Emails, and the
		// email's description there says the switch does not apply, so it is not a control that lies.
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
		$bases    = $this->window_bases( $settings );

		return array_merge(
			$context,
			array(
				'request'       => $this->request,
				'download_url'  => $this->download_url,
				'site_name'     => get_bloginfo( 'name' ),
				'window_days'   => $settings->window_days(),
				// Kept for theme overrides of the template written before per-product bases: the
				// request's own basis when it has one, the store's otherwise.
				'start_trigger' => 1 === count( $bases ) ? $bases[0] : $settings->start_trigger(),
				'period_terms'  => self::period_terms( $bases, $settings->window_days() ),
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			)
		);
	}

	/**
	 * What the withdrawn items' periods are counted from, per their product configuration.
	 *
	 * @param Settings $settings Configuration reader.
	 *
	 * @return string[] Distinct {@see \Recesso54bis\Domain\Eligibility\WindowBasis} values.
	 */
	private function window_bases( Settings $settings ): array {
		if ( ! $this->request instanceof WithdrawalRequest ) {
			return array( $settings->start_trigger() );
		}

		$order = wc_get_order( $this->request->order_id );
		if ( ! $order instanceof \WC_Order ) {
			return array( $settings->start_trigger() );
		}

		$line_ids = array_map( 'intval', array_keys( $this->request->requested_items ) );
		$bases    = ( new WindowBasisResolver( $settings ) )->for_order( $order, array() === $line_ids ? null : $line_ids );

		return array() === $bases ? array( $settings->start_trigger() ) : $bases;
	}

	/**
	 * The sentence stating the deadlines and conditions a withdrawal is subject to, worded for what
	 * the withdrawn items' periods are counted from.
	 *
	 * The goods-only wording is the one this email has always carried. A request for a service alone
	 * used to be told its period ran "for goods" from the conclusion of the contract; a request mixing
	 * the two now names both events, since one would be wrong for half of it.
	 *
	 * @param string[] $bases Distinct bases of the withdrawn items.
	 * @param int      $days  Window length in days.
	 */
	public static function period_terms( array $bases, int $days ): string {
		$delivery   = in_array( WindowBasis::DELIVERY, $bases, true );
		$conclusion = in_array( WindowBasis::CONCLUSION, $bases, true );

		if ( $delivery && $conclusion ) {
			return sprintf(
				/* translators: %d: number of days in the withdrawal window. */
				__( 'Your withdrawal is subject to the legal deadlines and conditions: the %d-day period (for goods, counted from delivery; for services and digital content, from the conclusion of the contract) and the statutory exceptions to the right of withdrawal. We verify these before confirming, so submitting a request does not by itself guarantee its acceptance.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				$days
			);
		}

		if ( $conclusion ) {
			return sprintf(
				/* translators: %d: number of days in the withdrawal window. */
				__( 'Your withdrawal is subject to the legal deadlines and conditions: the %d-day period, counted from the conclusion of the contract, and the statutory exceptions to the right of withdrawal. We verify these before confirming, so submitting a request does not by itself guarantee its acceptance.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				$days
			);
		}

		return sprintf(
			/* translators: 1: number of days in the withdrawal window, 2: when the window starts (e.g. delivery or the conclusion of the contract). */
			__( 'Your withdrawal is subject to the legal deadlines and conditions: the %1$d-day period (for goods, counted from %2$s; for digital content, from the start of the download) and the statutory exceptions to the right of withdrawal. We verify these before confirming, so submitting a request does not by itself guarantee its acceptance.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			$days,
			__( 'delivery', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}
}
