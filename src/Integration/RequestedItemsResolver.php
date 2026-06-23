<?php
/**
 * Shared resolver for the items a withdrawal request concerns.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

use Recesso54bis\Domain\WithdrawalRequest;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth that turns a {@see WithdrawalRequest} (a map of line id => quantity) into the
 * human-readable lines it withdraws, against the live order. Used by the durable-medium PDF, the
 * acknowledgement email and the frontend confirmation/done screens, so all of them itemise the
 * withdrawal identically. WordPress-light (needs only the order CRUD), HPOS-safe.
 */
final class RequestedItemsResolver {

	/**
	 * Resolve the items the withdrawal concerns: the named lines for a partial withdrawal, or every
	 * order line when no specific lines were recorded (whole-order withdrawal). A stored quantity of 0
	 * is the legacy "whole line" marker and falls back to the full ordered quantity.
	 *
	 * The `name` and `quantity` keys are byte-for-byte what the receipt hash is built from; the
	 * optional `thumbnail_html` is presentation-only and never enters the canonical payload.
	 *
	 * @param WithdrawalRequest $request        The request.
	 * @param \WC_Order         $order          The related order.
	 * @param bool              $with_thumbnail Whether to include the product thumbnail markup.
	 *
	 * @return array<int, array{line_id: int, name: string, quantity: int, thumbnail_html?: string}>
	 */
	public static function resolve( WithdrawalRequest $request, \WC_Order $order, bool $with_thumbnail = false ): array {
		$quantities = $request->requested_items;

		$items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$line_id = (int) $item_id;
			if ( array() !== $quantities && ! array_key_exists( $line_id, $quantities ) ) {
				continue;
			}

			// The withdrawn quantity (a stored 0 is the legacy "whole line" marker).
			$requested = (int) ( $quantities[ $line_id ] ?? 0 );
			$withdrawn = $requested > 0 ? min( $requested, (int) $item->get_quantity() ) : (int) $item->get_quantity();

			$row = array(
				'line_id'  => $line_id,
				'name'     => $item->get_name(),
				'quantity' => $withdrawn,
			);
			if ( $with_thumbnail ) {
				$row['thumbnail_html'] = self::thumbnail( $item );
			}

			$items[] = $row;
		}

		return $items;
	}

	/**
	 * Whether the request withdraws only some of the order's lines (a partial withdrawal), as opposed
	 * to every product unit (whole-order withdrawal). The single source of truth shared by the
	 * durable-medium PDF and the plugin emails, so all of them describe the scope identically. A stored
	 * quantity of 0 is the legacy "whole line" marker.
	 *
	 * @param WithdrawalRequest $request The request.
	 * @param \WC_Order         $order   The related order.
	 */
	public static function is_partial( WithdrawalRequest $request, \WC_Order $order ): bool {
		$quantities = $request->requested_items;
		if ( array() === $quantities ) {
			return false;
		}

		$total_units     = 0;
		$withdrawn_units = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$line_qty     = (int) $item->get_quantity();
			$total_units += $line_qty;

			$line_id = (int) $item_id;
			if ( array_key_exists( $line_id, $quantities ) ) {
				$requested        = (int) $quantities[ $line_id ];
				$withdrawn_units += $requested > 0 ? min( $requested, $line_qty ) : $line_qty;
			}
		}

		return $withdrawn_units < $total_units;
	}

	/**
	 * Safe `<img>` markup for an order line's product thumbnail, or an empty string when the product is
	 * no longer available. {@see \WC_Product::get_image()} returns already-escaped markup (built via
	 * `wp_get_attachment_image`) and falls back to the WooCommerce placeholder; it loads no external
	 * resource.
	 *
	 * @param \WC_Order_Item_Product $item The order line item.
	 */
	public static function thumbnail( \WC_Order_Item_Product $item ): string {
		$product = $item->get_product();
		if ( ! $product instanceof \WC_Product ) {
			return '';
		}

		return $product->get_image(
			'woocommerce_thumbnail',
			array(
				'class'   => 'wp-block-recesso-digitale-flow__thumb-img',
				'loading' => 'lazy',
			),
			false
		);
	}
}
