<?php
/**
 * Withdrawal coordination service.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

use Recesso54bis\Domain\Eligibility\Reason;
use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates the withdrawal lifecycle across the eligibility adapter, the repositories and the
 * audit log. Keeps the legal rules in one place: declarations are only created for eligible orders,
 * confirmation is idempotent, and every state change is logged. The durable-medium step hooks the
 * `recesso_dig_request_confirmed` action fired here.
 */
final class WithdrawalService {

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Audit log repository.
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Eligibility adapter.
	 *
	 * @var EligibilityAdapter
	 */
	private EligibilityAdapter $eligibility;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Construct the service.
	 *
	 * @param RequestRepository  $requests    Request repository.
	 * @param LogRepository      $log         Audit log repository.
	 * @param EligibilityAdapter $eligibility Eligibility adapter.
	 * @param Clock              $clock       Clock.
	 */
	public function __construct(
		RequestRepository $requests,
		LogRepository $log,
		EligibilityAdapter $eligibility,
		Clock $clock
	) {
		$this->requests    = $requests;
		$this->log         = $log;
		$this->eligibility = $eligibility;
		$this->clock       = $clock;
	}

	/**
	 * Create a withdrawal declaration for an eligible order.
	 *
	 * @param \WC_Order            $order      The order.
	 * @param array<string, mixed> $data       Consumer-confirmed fields (consumer_name, contract_reference,
	 *                                          confirmation_email) and an optional `requested_items` list of
	 *                                          line ids for a partial withdrawal.
	 * @param string|null          $ip_packed  Packed client IP (inet_pton) or null.
	 *
	 * @throws NotEligibleException When the order is not eligible or no requested line is eligible.
	 */
	public function create_declaration( \WC_Order $order, array $data, ?string $ip_packed ): WithdrawalRequest {
		// An unconfirmed declaration for this order is the consumer's own abandoned or amended draft. It
		// reserves units, so evaluating eligibility with its claim still in place would refuse the very
		// consumer who is replacing it: on a single-unit order, closing the page before confirming used
		// to lock the withdrawal function shut for good. Evaluate as if the draft were already gone —
		// it is discarded a few lines below, once there is something to replace it with.
		$draft = $this->pending_draft( $order );

		$eligibility = $draft instanceof WithdrawalRequest
			? $this->eligibility->for_order_ignoring_claim( $order, $draft )
			: $this->eligibility->for_order( $order );

		if ( ! $eligibility->is_eligible ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reason is a controlled enum string, never rendered as output.
			throw new NotEligibleException( $eligibility->reason );
		}

		// Determine which lines and quantities to withdraw: the consumer's selection, clamped to the
		// units still available per line (fail closed — anything not eligible or beyond the available
		// quantity is dropped/clamped, never trusted). With no explicit selection, default to all
		// available units of every eligible line.
		$requested_items = $this->resolve_requested_items( $data, $eligibility->available_quantities );
		if ( array() === $requested_items ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- reason is a controlled enum string, never rendered as output.
			throw new NotEligibleException( Reason::NO_ELIGIBLE_ITEMS );
		}

		// Release the abandoned/amended draft's reservation now that the replacement is about to be
		// written. Doing it here rather than before validation means a submission that fails leaves the
		// consumer's earlier draft intact. Confirmed requests are never touched.
		$this->requests->discard_pending_for_order( $order->get_id() );

		$request = $this->requests->create_declaration(
			$order->get_id(),
			array(
				'consumer_name'        => $data['consumer_name'] ?? '',
				'contract_reference'   => $data['contract_reference'] ?? '',
				'confirmation_email'   => $data['confirmation_email'] ?? '',
				'requested_items'      => $requested_items,
				'refund_iban'          => $data['refund_iban'] ?? '',
				'withdrawal_reason'    => $data['withdrawal_reason'] ?? '',
				'consumer_declaration' => $data['consumer_declaration'] ?? '',
				'line_totals'          => $this->line_totals( $order ),
				'request_ip'           => $ip_packed,
			),
			$this->clock->now_gmt()
		);

		$this->log->record(
			$request->id,
			LogRepository::EVENT_CREATED,
			'consumer',
			array( 'order_id' => $order->get_id() )
		);

		/**
		 * Fires when a withdrawal declaration is first created (still pending confirmation).
		 *
		 * @param WithdrawalRequest $request The created request.
		 */
		do_action( 'recesso_dig_request_created', $request );

		return $request;
	}

	/**
	 * Resolve the line => quantity map to withdraw from the consumer's selection, clamped to the units
	 * available per line. An absent or empty selection means all available units of every eligible
	 * line (whole-order withdrawal). Ineligible lines and non-positive quantities are dropped.
	 *
	 * @param array<string, mixed> $data      The submitted data (may carry `requested_items`).
	 * @param array<int, int>      $available Map line_id => units still available to withdraw.
	 *
	 * @return array<int, int> Map line_id => quantity to withdraw.
	 */
	private function resolve_requested_items( array $data, array $available ): array {
		$selected = $data['requested_items'] ?? null;
		if ( ! is_array( $selected ) || array() === $selected ) {
			return $available;
		}

		// Normalise the selection's keys/values to integers (REST/JSON may deliver string keys).
		$normalised = array();
		foreach ( $selected as $line_id => $quantity ) {
			$normalised[ (int) $line_id ] = (int) $quantity;
		}

		$out = array();
		foreach ( $available as $line_id => $available_qty ) {
			if ( ! array_key_exists( $line_id, $normalised ) ) {
				continue;
			}
			$qty = $normalised[ $line_id ];
			if ( $qty <= 0 ) {
				continue;
			}
			$out[ $line_id ] = min( $qty, (int) $available_qty );
		}

		return $out;
	}

	/**
	 * The order's unconfirmed declaration, if it has one.
	 *
	 * Only a pending request qualifies. Once confirmed, a request is a legal record whose reservation
	 * stands until the merchant decides it.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function pending_draft( \WC_Order $order ): ?WithdrawalRequest {
		$latest = $this->requests->latest_for_order( $order->get_id() );

		return $latest instanceof WithdrawalRequest && RequestStatus::PENDING === $latest->status
			? $latest
			: null;
	}

	/**
	 * The total ordered quantity of each product line, keyed by line id — the ceiling enforced when
	 * reserving units in the claim ledger.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array<int, int>
	 */
	private function line_totals( \WC_Order $order ): array {
		$totals = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$totals[ (int) $item_id ] = max( 1, (int) $item->get_quantity() );
			}
		}

		return $totals;
	}

	/**
	 * Confirm a request (step 2). Idempotent: a second confirmation returns the existing record
	 * without re-logging or re-firing the durable-medium action.
	 *
	 * @param int    $request_id Request id.
	 * @param string $actor      Actor descriptor (consumer | admin:{id} | system).
	 */
	public function confirm( int $request_id, string $actor ): ?WithdrawalRequest {
		$existing = $this->requests->find_by_id( $request_id );
		if ( null === $existing ) {
			return null;
		}

		if ( $existing->is_confirmed() ) {
			return $existing;
		}

		$confirmed = $this->requests->confirm( $request_id, $this->clock->now_gmt() );
		if ( null !== $confirmed && $confirmed->is_confirmed() ) {
			$this->log->record( $request_id, LogRepository::EVENT_CONFIRMED, $actor );

			/**
			 * Fires once when a withdrawal request is first confirmed (the legal dies a quo).
			 * The durable-medium subsystem hooks this to generate and send the receipt.
			 *
			 * @param WithdrawalRequest $confirmed The confirmed request.
			 */
			do_action( 'recesso_dig_request_confirmed', $confirmed );
		}

		return $confirmed;
	}
}
