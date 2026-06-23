<?php
/**
 * Per-line eligibility input.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * The withdrawal-relevant facts about a single order line. The art. 59 mapping (which products are
 * excluded) is resolved by the adapter from merchant configuration; the domain only consumes the
 * resulting flags. An unconfigured line is treated conservatively (fail closed) by the engine.
 */
final class EligibilityLine {

	/**
	 * Construct a per-line input.
	 *
	 * @param int  $line_id    Order line item id.
	 * @param bool $configured Whether the merchant has configured the art. 59 status for this line's product.
	 * @param bool $excluded   Whether the product/category is flagged as excluded from withdrawal (art. 59).
	 * @param int  $quantity   The line's total ordered quantity (the ceiling for a partial-by-quantity withdrawal).
	 */
	public function __construct(
		public readonly int $line_id,
		public readonly bool $configured,
		public readonly bool $excluded,
		public readonly int $quantity = 1
	) {}
}
