<?php
/**
 * Shared template context for the withdrawal request emails.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Email;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Integration\RequestedItemsResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the presentation context the acknowledgement, status-update and rejection emails all share:
 * the itemised products, the order date and whether the withdrawal is partial. Resolved from the live
 * order through the same {@see RequestedItemsResolver} the durable-medium PDF uses, so every email and
 * the receipt describe the withdrawal identically. This is presentation only and never feeds the
 * receipt hash.
 */
trait RequestContext {

	/**
	 * Resolve the shared template context for a request.
	 *
	 * @param WithdrawalRequest|null $request The request (null before a send is set up).
	 *
	 * @return array{items: array<int, array{line_id: int, name: string, quantity: int}>, order_date: string, is_partial: bool}
	 */
	private function request_context( ?WithdrawalRequest $request ): array {
		$items      = array();
		$order_date = '';
		$is_partial = false;

		if ( $request instanceof WithdrawalRequest ) {
			$order = wc_get_order( $request->order_id );
			if ( $order instanceof \WC_Order ) {
				$items      = RequestedItemsResolver::resolve( $request, $order );
				$is_partial = RequestedItemsResolver::is_partial( $request, $order );

				$created = $order->get_date_created();
				if ( $created instanceof \WC_DateTime ) {
					// The order/contract date shown to the consumer (GMT, date only).
					$order_date = gmdate( 'Y-m-d', $created->getTimestamp() );
				}
			}
		}

		return array(
			'items'      => $items,
			'order_date' => $order_date,
			'is_partial' => $is_partial,
		);
	}
}
