<?php
/**
 * Resolves what each product's withdrawal period is counted from.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

use Recesso54bis\Domain\Eligibility\WindowBasis;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the per-product and per-category "period starts from" setting, falling back to the store-wide
 * one. The single place that answers the question, so the eligibility engine, the order emails and the
 * acknowledgement all describe the same period for the same product.
 *
 * TODO(legal): the mapping of a product to "delivery" or "conclusion" is the merchant's, and assumes
 * art. 52(2) Codice del Consumo reduces, for what a store can record, to those two events: goods from
 * delivery (the last of several, or the first of a regular supply — both approximated by the order's
 * completion date), services and digital content not on a tangible medium from the conclusion of the
 * contract. A mixed contract of goods and services follows the goods rule under the Directive, which a
 * per-line setting cannot express; such products should be configured as goods.
 */
final class WindowBasisResolver {

	/**
	 * Configuration reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Per-request memo keyed by product id.
	 *
	 * @var array<int, string>
	 */
	private array $cache = array();

	/**
	 * Construct the resolver.
	 *
	 * @param Settings $settings Configuration reader.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * The basis that applies to a product: its own setting, else its categories', else the store's.
	 * On a multilingual site each level falls back to the original's value when a translation has
	 * none of its own ({@see Multilingual}).
	 *
	 * When two of the product's categories disagree, delivery wins. A store cannot complete an order
	 * before creating it, so a period counted from delivery never ends earlier than one counted from
	 * the purchase: a contradictory configuration then costs the merchant a few days, never the
	 * consumer their right.
	 *
	 * @param int $product_id Product id (the parent for a variation).
	 *
	 * @return string One of the {@see WindowBasis} constants.
	 */
	public function for_product( int $product_id ): string {
		if ( isset( $this->cache[ $product_id ] ) ) {
			return $this->cache[ $product_id ];
		}

		$known = static fn( $value ): bool => '' !== self::stored( $value );

		// A translation's own setting wins; without one it takes its original's (WPML / Polylang).
		$basis = self::stored( Multilingual::product_meta( $product_id, Settings::META_PRODUCT_WINDOW_BASIS, $known ) );

		if ( '' === $basis ) {
			foreach ( Multilingual::category_ids( $product_id ) as $term_id ) {
				$category = self::stored( Multilingual::category_meta( $term_id, Settings::META_TERM_WINDOW_BASIS, $known ) );
				if ( WindowBasis::DELIVERY === $category ) {
					$basis = WindowBasis::DELIVERY;
					break;
				}
				if ( '' !== $category ) {
					$basis = $category;
				}
			}
		}

		if ( '' === $basis ) {
			$basis = $this->settings->start_trigger();
		}

		$this->cache[ $product_id ] = $basis;

		return $basis;
	}

	/**
	 * The distinct bases among an order's product lines, optionally narrowed to some of them.
	 *
	 * @param \WC_Order  $order    The order.
	 * @param int[]|null $line_ids Only these line ids; null for every product line.
	 *
	 * @return string[] Distinct {@see WindowBasis} values, in a stable order (delivery first).
	 */
	public function for_order( \WC_Order $order, ?array $line_ids = null ): array {
		$found = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			if ( null !== $line_ids && ! in_array( (int) $item_id, $line_ids, true ) ) {
				continue;
			}
			$found[ $this->for_product( $item->get_product_id() ) ] = true;
		}

		return array_values( array_filter( WindowBasis::all(), static fn( string $basis ): bool => isset( $found[ $basis ] ) ) );
	}

	/**
	 * Narrow a raw meta value to a known basis, or '' for "inherit".
	 *
	 * @param mixed $value Raw meta value.
	 */
	public static function stored( $value ): string {
		$value = is_string( $value ) ? $value : '';

		return WindowBasis::is_valid( $value ) ? $value : '';
	}
}
