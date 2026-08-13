<?php
/**
 * Withdrawal status-update email.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Notifies the consumer of a positive change in their withdrawal request — accepted, refunded or
 * completed — so they are kept informed after confirmation. Templatable and translatable like the
 * other plugin emails; its subject and heading are editable under WooCommerce → Settings → Emails.
 */
class WithdrawalStatusUpdateEmail extends \WC_Email {

	use RequestContext;

	/**
	 * The request (set per send).
	 *
	 * @var WithdrawalRequest|null
	 */
	private ?WithdrawalRequest $request = null;

	/**
	 * The new status (set per send).
	 *
	 * @var string
	 */
	private string $status = '';

	/**
	 * Construct the email.
	 */
	public function __construct() {
		$this->id             = 'recesso_dig_status_update';
		$this->customer_email = true;
		$this->title          = __( 'Withdrawal status update (recesso)', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->description    = __( 'Sent to the consumer when the merchant accepts, refunds or completes a withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' );
		$this->template_html  = 'emails/withdrawal-status-update.php';
		$this->template_plain = 'emails/plain/withdrawal-status-update.php';
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
		return __( 'Your withdrawal request has been updated', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Whether this email handles the given status.
	 *
	 * @param string $status Candidate status.
	 */
	public static function handles( string $status ): bool {
		return in_array( $status, array( RequestStatus::ACCEPTED, RequestStatus::REFUNDED, RequestStatus::COMPLETED ), true );
	}

	/**
	 * Send the status-update notice.
	 *
	 * @param WithdrawalRequest $request The request.
	 * @param string            $status  The new status.
	 */
	public function trigger_for( WithdrawalRequest $request, string $status ): bool {
		$this->request   = $request;
		$this->status    = $status;
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
	 * The sentence describing a status. The merchant can replace the "accepted" and "completed"
	 * wordings on the plugin's settings screen; an unset field keeps the bundled sentence, which is
	 * translated with the rest of the plugin.
	 *
	 * @param string $status The status.
	 */
	private function status_message( string $status ): string {
		$settings = new Settings();

		switch ( $status ) {
			case RequestStatus::ACCEPTED:
				return $settings->status_email_text( 'accepted' );
			case RequestStatus::REFUNDED:
			case RequestStatus::COMPLETED:
				return $settings->status_email_text( 'completed' );
			default:
				return __( 'Your withdrawal request has been updated.', 'erred-eu-order-withdrawal-for-woocommerce' );
		}
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
				'request'        => $this->request,
				'status_message' => $this->status_message( $this->status ),
				'email_heading'  => $this->get_heading(),
				'sent_to_admin'  => false,
				'plain_text'     => false,
				'email'          => $this,
			)
		);
	}
}
