<?php
/**
 * Eligibility engine.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain\Eligibility;

defined( 'ABSPATH' ) || exit;

/**
 * Pure, WordPress-free decision core for whether an order can be withdrawn and which lines are
 * eligible. It is deliberately conservative: any uncertainty (missing product configuration,
 * undeterminable window) results in denial, never an "always on" default.
 *
 * The mapping of the art. 59 statutory exceptions to concrete product configuration is performed by
 * the adapter and must be validated by a legal professional — this engine only consumes the flags.
 */
final class EligibilityEngine {

	/**
	 * Evaluate eligibility for an order.
	 *
	 * Precedence: order state → existing request → product configuration → statutory exclusions →
	 * eligible lines. The 14-day timing window is **advisory**, not a gate: it is computed and
	 * reported (so the merchant can see whether the ordinary period has elapsed) but it does not hide
	 * the withdrawal function — the merchant accepts or rejects each request. This keeps the function
	 * continuously available and avoids denying a right on the basis of a proxy delivery date.
	 *
	 * @param EligibilityInput $input Assembled, WordPress-free input.
	 */
	public function evaluate( EligibilityInput $input ): EligibilityResult {
		if ( ! $input->order_withdrawable ) {
			return EligibilityResult::ineligible( Reason::ORDER_NOT_WITHDRAWABLE );
		}

		// Compute the ordinary window for information only (it never blocks here).
		$window_start  = $input->window_start;
		$window_end    = $window_start instanceof \DateTimeImmutable
			? $this->window_end( $window_start, $input->window_days )
			: null;
		$within_window = $window_start instanceof \DateTimeImmutable
			&& $input->now >= $window_start
			&& ( ! $window_end instanceof \DateTimeImmutable || $input->now <= $window_end );

		// Strict mode (opt-in): the window becomes a hard gate. The default stays advisory so the
		// function remains continuously available and the merchant decides each request.
		if ( $input->strict ) {
			if ( ! $window_start instanceof \DateTimeImmutable || $input->now < $window_start ) {
				return EligibilityResult::ineligible( Reason::NOT_STARTED, $window_start, $window_end );
			}
			if ( $window_end instanceof \DateTimeImmutable ) {
				$deadline = $window_end->add( new \DateInterval( 'P' . max( 0, $input->grace_days ) . 'D' ) );
				if ( $input->now > $deadline ) {
					return EligibilityResult::ineligible( Reason::WINDOW_CLOSED, $window_start, $window_end );
				}
			}
		}

		// Fail closed: a single unconfigured line blocks the whole request until the merchant
		// resolves its art. 59 status. These items are surfaced to the admin for configuration.
		foreach ( $input->lines as $line ) {
			if ( ! $line->configured ) {
				return EligibilityResult::ineligible( Reason::NEEDS_CONFIG, $window_start, $window_end );
			}
		}

		// A line offers as many units as remain after subtracting the quantity already reserved by open
		// requests. Statutorily-excluded lines offer nothing; the rest of the order stays available, so
		// concurrent partial withdrawals can share a line's units (e.g. 2 of 4 now, 2 of 4 later).
		$available    = array();
		$withdrawable = array();
		foreach ( $input->lines as $line ) {
			if ( $line->excluded ) {
				continue;
			}
			$withdrawable[] = $line->line_id;

			$claimed = (int) ( $input->claimed_quantities[ $line->line_id ] ?? 0 );
			$free    = $line->quantity - $claimed;
			if ( $free > 0 ) {
				$available[ $line->line_id ] = $free;
			}
		}

		$eligible_line_ids = array_keys( $available );

		if ( array() === $eligible_line_ids ) {
			$reason = $this->no_lines_reason( $input->lines, $withdrawable );

			return EligibilityResult::ineligible( $reason, $window_start, $window_end );
		}

		return new EligibilityResult( true, Reason::ELIGIBLE, $window_start, $window_end, $eligible_line_ids, $available, $within_window );
	}

	/**
	 * Decide why no line is eligible: nothing in the order, every otherwise-withdrawable line already
	 * claimed by an open request, or all lines statutorily excluded.
	 *
	 * @param EligibilityLine[] $lines        All order lines.
	 * @param int[]             $withdrawable Line ids that are not statutorily excluded.
	 */
	private function no_lines_reason( array $lines, array $withdrawable ): string {
		if ( array() === $lines ) {
			return Reason::NO_ELIGIBLE_ITEMS;
		}

		// There are withdrawable lines, but every one of them is already being withdrawn.
		if ( array() !== $withdrawable ) {
			return Reason::DUPLICATE_OPEN;
		}

		return Reason::EXCLUDED_ART59;
	}

	/**
	 * Compute the inclusive end of the withdrawal window.
	 *
	 * @param \DateTimeImmutable $start Window start.
	 * @param int                $days  Window length in days.
	 */
	private function window_end( \DateTimeImmutable $start, int $days ): \DateTimeImmutable {
		$days = max( 0, $days );

		return $start->add( new \DateInterval( 'P' . $days . 'D' ) );
	}
}
