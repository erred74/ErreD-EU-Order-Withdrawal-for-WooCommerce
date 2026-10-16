<?php
/**
 * Order-reference helpers for the public lookup form.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reconciles the order reference a consumer types with the one the store holds.
 *
 * WooCommerce prints the order number with a leading hash — `#1234` is exactly what the customer
 * reads in their confirmation email — so a consumer who copies it pastes the hash too. The lookup
 * used to run that string straight through `absint()`, which yields 0 for `#1234`, so the match
 * failed at the first guard and the consumer was told a link had been sent that never arrived. The
 * same goes for a reference read off a screen and retyped with a stray space.
 *
 * Deliberately conservative: these helpers remove only formatting a person would not consider part
 * of the number, and never strip digits, so they cannot widen a match to a different order.
 */
final class OrderNumber {

	/**
	 * Tidy a typed or pasted order reference into the form the comparisons expect.
	 *
	 * @param string $order_number The reference as the consumer entered it.
	 *
	 * @return string The reference without a leading hash, surrounding or inner whitespace.
	 */
	public static function normalise( string $order_number ): string {
		$normalised = ltrim( trim( $order_number ), '#' );
		$normalised = (string) preg_replace( '/\s+/u', '', $normalised );

		return trim( $normalised );
	}

	/**
	 * Whether two references are the same number written with different zero padding.
	 *
	 * Both sides must be entirely digits, so this never collapses two distinct custom references into
	 * a match — it only forgives padding, in either direction. The padding itself is never stripped
	 * from the stored reference: for a store using a sequential-numbering plugin those zeros are part
	 * of the number the customer was shown.
	 *
	 * @param string $one   First reference, already normalised.
	 * @param string $other Second reference, already normalised.
	 */
	public static function same_number( string $one, string $other ): bool {
		if ( 1 !== preg_match( '/^\d+$/', $one ) || 1 !== preg_match( '/^\d+$/', $other ) ) {
			return false;
		}

		return ltrim( $one, '0' ) === ltrim( $other, '0' );
	}
}
