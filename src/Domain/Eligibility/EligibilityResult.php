<?php
/**
 * Eligibility engine result.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * The rich, immutable outcome of an eligibility evaluation: whether withdrawal is available, a
 * machine-readable {@see Reason}, the computed window bounds and the eligible line ids.
 */
final class EligibilityResult {

	/**
	 * Construct a result.
	 *
	 * @param bool                    $is_eligible         Whether the withdrawal function is offered.
	 * @param string                  $reason              One of the {@see Reason} constants.
	 * @param \DateTimeImmutable|null $window_starts_at    When the ordinary window opens (if known).
	 * @param \DateTimeImmutable|null $window_ends_at      When the ordinary window closes (if known).
	 * @param int[]                   $eligible_line_ids   Line ids with at least one unit available for withdrawal.
	 * @param array<int, int>         $available_quantities Map line_id => units still available to withdraw.
	 * @param bool                    $within_window       Whether "now" falls inside the ordinary window of at
	 *                                                     least one withdrawable line (advisory only — the
	 *                                                     merchant decides).
	 * @param array<int, LineWindow>  $line_windows        Map line_id => that line's own period. On an order
	 *                                                     mixing goods and services the lines differ, and the
	 *                                                     order-level bounds above are only a summary of them.
	 */
	public function __construct(
		public readonly bool $is_eligible,
		public readonly string $reason,
		public readonly ?\DateTimeImmutable $window_starts_at,
		public readonly ?\DateTimeImmutable $window_ends_at,
		public readonly array $eligible_line_ids,
		public readonly array $available_quantities = array(),
		public readonly bool $within_window = false,
		public readonly array $line_windows = array()
	) {}

	/**
	 * Convenience factory for an ineligible result.
	 *
	 * @param string                  $reason           Machine-readable reason.
	 * @param \DateTimeImmutable|null $window_starts_at Window start, when known.
	 * @param \DateTimeImmutable|null $window_ends_at   Window end, when known.
	 * @param array<int, LineWindow>  $line_windows     Per-line periods, when computed.
	 */
	public static function ineligible(
		string $reason,
		?\DateTimeImmutable $window_starts_at = null,
		?\DateTimeImmutable $window_ends_at = null,
		array $line_windows = array()
	): self {
		return new self( false, $reason, $window_starts_at, $window_ends_at, array(), array(), false, $line_windows );
	}
}
