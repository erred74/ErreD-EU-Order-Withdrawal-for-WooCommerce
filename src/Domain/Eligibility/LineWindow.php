<?php
/**
 * One order line's withdrawal period.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * The ordinary withdrawal period of a single line, as computed by {@see EligibilityEngine}. An order
 * mixing goods and services has one per line, because the two are counted from different events.
 */
final class LineWindow {

	/**
	 * Construct a line window.
	 *
	 * @param string                  $basis         One of the {@see WindowBasis} constants, or '' when the
	 *                                               line was given none and follows the order's window.
	 * @param \DateTimeImmutable|null $starts_at     When the period opens; null while it has not (goods
	 *                                               not yet delivered).
	 * @param \DateTimeImmutable|null $ends_at       When the ordinary period closes; null while it has not
	 *                                               opened. Grace days are not included: this is the
	 *                                               statutory date, the one shown to the consumer.
	 * @param bool                    $within_window Whether "now" falls inside [starts_at, ends_at].
	 */
	public function __construct(
		public readonly string $basis,
		public readonly ?\DateTimeImmutable $starts_at,
		public readonly ?\DateTimeImmutable $ends_at,
		public readonly bool $within_window
	) {}
}
