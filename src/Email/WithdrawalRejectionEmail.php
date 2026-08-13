<?php
/**
 * Withdrawal rejection email.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

use Recesso54bis\Domain\WithdrawalRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Notifies the consumer, on a durable medium, that the merchant has not accepted their withdrawal
 * request, including the reason. The withdrawal itself remains recorded (the request and its
 * confirmation timestamp are immutable); this message communicates the merchant's decision and the
 * grounds for it, for transparency.
 */
class WithdrawalRejectionEmail extends \WC_Email {

	use RequestContext;

	/**
	 * The rejected request (set per send).
	 *
	 * @var WithdrawalRequest|null
	 */
	private ?WithdrawalRequest $request = null;

	/**
	 * The merchant's reason for rejection (set per send).
	 *
	 * @var string
	 */
	private string $reason = '';

	/**
	 * Construct the email.
	 */
	public function __construct() {
		$this->id             = 'recesso_dig_rejection';
		$this->customer_email = true;
		$this->title          = __( 'Withdrawal rejection (recesso)', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->description    = __( 'Sent to the consumer when the merchant does not accept a withdrawal request, with the reason.', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->template_html  = 'emails/withdrawal-rejection.php';
		$this->template_plain = 'emails/plain/withdrawal-rejection.php';
		$this->template_base  = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'templates/';

		parent::__construct();
	}

	/**
	 * Default subject.
	 */
	public function get_default_subject(): string {
		return __( 'Update on your withdrawal request', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Default heading.
	 */
	public function get_default_heading(): string {
		return __( 'Your withdrawal request was not accepted', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Send the rejection notice for a request.
	 *
	 * @param WithdrawalRequest $request The rejected request.
	 * @param string            $reason  The merchant's reason.
	 */
	public function trigger_for( WithdrawalRequest $request, string $reason ): bool {
		$this->request   = $request;
		$this->reason    = $reason;
		$this->recipient = $request->confirmation_email;

		$this->setup_locale();

		$sent = false;
		if ( $this->is_enabled() && '' !== $this->get_recipient() ) {
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
		return array_merge(
			$this->request_context( $this->request ),
			array(
				'request'       => $this->request,
				'reason'        => $this->reason,
				// The merchant may reword the opening sentence on the plugin's settings screen; the
				// reason and the closing note below it are always kept.
				'intro'         => ( new \Recesso54bis\Support\Settings() )->status_email_text( 'rejected' ),
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			)
		);
	}
}
