<?php
/**
 * What a withdrawal period is counted from.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * The event a line's withdrawal period runs from (art. 52(2) Codice del Consumo / art. 9(2) Directive
 * 2011/83/EU). WordPress-free: the adapter decides which basis a product has and which instant stands
 * for it; the engine only looks the instant up.
 *
 * Two values, not a richer contract-type model, because two are all the store can tell apart: the
 * "last of several goods" and "first of a regular delivery" rules both need delivery dates WooCommerce
 * does not record per line, so they collapse onto the order's completion date like every other
 * delivery.
 */
final class WindowBasis {

	/**
	 * Sales of goods: counted from the consumer's taking physical possession of them.
	 */
	public const DELIVERY = 'delivery';

	/**
	 * Services, and digital content not supplied on a tangible medium: counted from the conclusion of
	 * the contract.
	 */
	public const CONCLUSION = 'conclusion';

	/**
	 * Every known basis.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array( self::DELIVERY, self::CONCLUSION );
	}

	/**
	 * Whether a value is a known basis.
	 *
	 * @param string $value Candidate value.
	 */
	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
