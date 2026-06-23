<?php
/**
 * Eligibility engine input.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * The complete, WordPress-free input to {@see EligibilityEngine::evaluate()}. An adapter assembles
 * this from a WooCommerce order and merchant configuration; the engine never fetches anything itself.
 */
final class EligibilityInput {

	/**
	 * Construct the engine input.
	 *
	 * @param \DateTimeImmutable      $now                Current instant (UTC).
	 * @param bool                    $order_withdrawable Whether the order is in a state that can be withdrawn.
	 * @param array<int, int>         $claimed_quantities Map line_id => quantity already reserved by open
	 *                                                    requests; subtracted from each line's total to give
	 *                                                    the units still available, without blocking the order.
	 * @param int                     $window_days        Withdrawal window length in days (default 14, art. 52).
	 * @param \DateTimeImmutable|null $window_start       When the window opens (delivery for goods, conclusion
	 *                                                    for services); null when not yet determinable.
	 * @param EligibilityLine[]       $lines              Per-line inputs.
	 * @param bool                    $strict             Strict mode: the window is a hard gate (the function is
	 *                                                    hidden outside the period). Default false (advisory).
	 * @param int                     $grace_days         Extra days added to the window end before strict closure.
	 */
	public function __construct(
		public readonly \DateTimeImmutable $now,
		public readonly bool $order_withdrawable,
		public readonly array $claimed_quantities,
		public readonly int $window_days,
		public readonly ?\DateTimeImmutable $window_start,
		public readonly array $lines,
		public readonly bool $strict = false,
		public readonly int $grace_days = 0
	) {}
}
