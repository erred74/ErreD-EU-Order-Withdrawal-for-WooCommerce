<?php
/**
 * WooCommerce order notes for withdrawal lifecycle events.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Persistence\RequestRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Mirrors the withdrawal lifecycle into the order's private notes, so the merchant sees withdrawal
 * activity in the familiar order timeline without leaving WooCommerce. The order's own status is left
 * untouched — the withdrawal state lives in the plugin's tables — so fulfilment workflows are never
 * disturbed.
 */
final class OrderNotes {

	/**
	 * Request repository (to resolve the order for processed events).
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Construct the provider.
	 *
	 * @param RequestRepository $requests Request repository.
	 */
	public function __construct( RequestRepository $requests ) {
		$this->requests = $requests;
	}

	/**
	 * Hook the lifecycle events.
	 */
	public function register(): void {
		add_action( 'recesso_dig_request_created', array( $this, 'on_created' ), 10, 1 );
		add_action( 'recesso_dig_request_confirmed', array( $this, 'on_confirmed' ), 20, 1 );
		add_action( 'recesso_dig_request_processed', array( $this, 'on_processed' ), 10, 2 );
	}

	/**
	 * Note a newly created (pending) declaration.
	 *
	 * @param WithdrawalRequest $request The created request.
	 */
	public function on_created( WithdrawalRequest $request ): void {
		$this->note( $request->order_id, __( 'Recesso: withdrawal request received (pending confirmation).', 'erred-eu-order-withdrawal-for-woocommerce' ) );
	}

	/**
	 * Note a confirmed withdrawal (the legal dies a quo).
	 *
	 * @param WithdrawalRequest $request The confirmed request.
	 */
	public function on_confirmed( WithdrawalRequest $request ): void {
		$this->note(
			$request->order_id,
			sprintf(
				/* translators: %s: confirmation date and time (GMT). */
				__( 'Recesso: withdrawal confirmed by the consumer on %s (GMT).', 'erred-eu-order-withdrawal-for-woocommerce' ),
				(string) $request->confirmed_at_gmt
			)
		);
	}

	/**
	 * Note an admin action on the request (rejection or refund).
	 *
	 * @param int    $request_id The request id.
	 * @param string $action     The action performed.
	 */
	public function on_processed( int $request_id, string $action ): void {
		$request = $this->requests->find_by_id( $request_id );
		if ( ! $request instanceof WithdrawalRequest ) {
			return;
		}

		$message = match ( $action ) {
			'reject'     => __( 'Recesso: withdrawal request rejected by the merchant.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'accept'     => __( 'Recesso: withdrawal request accepted by the merchant.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'refunded'   => __( 'Recesso: withdrawal marked as refunded.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'complete'   => __( 'Recesso: withdrawal completed.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			// The "set status" dropdown in the request detail panel is the primary decision path. It
			// reports a single action name, so the note is derived from the status the request now
			// carries (the record is re-read above, after the transition).
			'set_status' => $this->message_for_status( $request->status ),
			default      => '',
		};

		if ( '' !== $message ) {
			$this->note( $request->order_id, $message );
		}
	}

	/**
	 * The order-note wording for a request's resulting status.
	 *
	 * @param string $status The request status after the transition.
	 */
	private function message_for_status( string $status ): string {
		return match ( $status ) {
			RequestStatus::ACCEPTED     => __( 'Recesso: withdrawal request accepted by the merchant.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			RequestStatus::REJECTED     => __( 'Recesso: withdrawal request rejected by the merchant.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			RequestStatus::REFUNDED     => __( 'Recesso: withdrawal marked as refunded.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			RequestStatus::COMPLETED    => __( 'Recesso: withdrawal completed.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			// The dropdown's "Pending" entry undoes a decision: the request returns to its resting
			// state (confirmed, or acknowledged once a receipt exists) and awaits a fresh decision.
			RequestStatus::CONFIRMED, RequestStatus::ACKNOWLEDGED => __( 'Recesso: the withdrawal decision was reset; the request is awaiting a decision again.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			default                     => '',
		};
	}

	/**
	 * Add a private order note, defensively.
	 *
	 * @param int    $order_id The order id.
	 * @param string $text     The note text.
	 */
	private function note( int $order_id, string $text ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note( $text );
		}
	}
}
