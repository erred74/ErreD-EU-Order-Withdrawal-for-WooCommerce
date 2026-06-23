<?php
/**
 * Eligibility outcome reasons.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * Machine-readable reasons explaining an eligibility outcome. WordPress-free: the human-readable,
 * translatable labels are produced at the edge (adapter/REST), never inside the domain.
 */
final class Reason {

	/**
	 * Withdrawal is available.
	 */
	public const ELIGIBLE = 'eligible';

	/**
	 * The withdrawal window has not opened yet (e.g. goods not delivered).
	 */
	public const NOT_STARTED = 'not_started';

	/**
	 * The withdrawal window has closed.
	 */
	public const WINDOW_CLOSED = 'window_closed';

	/**
	 * Excluded by a statutory exception (art. 59) configured on the product/category.
	 */
	public const EXCLUDED_ART59 = 'excluded_art59';

	/**
	 * Product configuration is missing, so eligibility cannot be determined — fail closed.
	 */
	public const NEEDS_CONFIG = 'needs_config';

	/**
	 * An open request already exists for the order; no duplicate is allowed.
	 */
	public const DUPLICATE_OPEN = 'duplicate_open';

	/**
	 * The order itself is not in a state that can be withdrawn (e.g. cancelled/failed).
	 */
	public const ORDER_NOT_WITHDRAWABLE = 'order_not_withdrawable';

	/**
	 * No line in the order is eligible for withdrawal.
	 */
	public const NO_ELIGIBLE_ITEMS = 'no_eligible_items';

	/**
	 * All known reasons.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::ELIGIBLE,
			self::NOT_STARTED,
			self::WINDOW_CLOSED,
			self::EXCLUDED_ART59,
			self::NEEDS_CONFIG,
			self::DUPLICATE_OPEN,
			self::ORDER_NOT_WITHDRAWABLE,
			self::NO_ELIGIBLE_ITEMS,
		);
	}
}
