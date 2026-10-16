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
	 * The window is computed per line, because goods and services in the same order are counted from
	 * different events (art. 52(2)). In strict mode a line outside its own window drops out on its own,
	 * the way a fully claimed line does, instead of taking the rest of the order with it: a T-shirt
	 * delivered yesterday stays withdrawable next to a course bought three weeks ago. An order whose
	 * lines all share one window behaves exactly as it did when the window was per order.
	 *
	 * @param EligibilityInput $input Assembled, WordPress-free input.
	 */
	public function evaluate( EligibilityInput $input ): EligibilityResult {
		if ( ! $input->order_withdrawable ) {
			return EligibilityResult::ineligible( Reason::ORDER_NOT_WITHDRAWABLE );
		}

		$windows = array();
		foreach ( $input->lines as $line ) {
			$windows[ $line->line_id ] = $this->line_window( $input, $line );
		}

		// The lines that decide the order-level summary and the strict gate: the withdrawable ones. An
		// excluded line's period is irrelevant to whether anything can be withdrawn, so it must not keep
		// a strict order open, nor stretch the deadline shown for the rest. When every line is excluded
		// they are all that is left to describe; with no lines at all the order's own window stands in.
		$deciding = array();
		foreach ( $input->lines as $line ) {
			if ( ! $line->excluded ) {
				$deciding[ $line->line_id ] = $windows[ $line->line_id ];
			}
		}
		if ( array() === $deciding ) {
			$deciding = array() !== $windows
				? $windows
				: array( 0 => $this->window( '', $input->window_start, $input ) );
		}

		list( $window_start, $window_end, $within_window ) = $this->summarise( $deciding );

		// Strict mode (opt-in): the window becomes a hard gate. The default stays advisory so the
		// function remains continuously available and the merchant decides each request.
		if ( $input->strict ) {
			$open = array_filter(
				$deciding,
				fn( LineWindow $window ): bool => $this->is_open( $window, $input )
			);

			if ( array() === $open ) {
				// Nothing open. "Not started" wins over "closed" when both are true, because it is the
				// one that will change: the goods line becomes withdrawable on delivery, the expired
				// service line never will.
				foreach ( $deciding as $window ) {
					if ( ! $window->starts_at instanceof \DateTimeImmutable || $input->now < $window->starts_at ) {
						return EligibilityResult::ineligible( Reason::NOT_STARTED, $window_start, $window_end, $windows );
					}
				}

				return EligibilityResult::ineligible( Reason::WINDOW_CLOSED, $window_start, $window_end, $windows );
			}
		}

		// Fail closed: a single unconfigured line blocks the whole request until the merchant
		// resolves its art. 59 status. These items are surfaced to the admin for configuration.
		foreach ( $input->lines as $line ) {
			if ( ! $line->configured ) {
				return EligibilityResult::ineligible( Reason::NEEDS_CONFIG, $window_start, $window_end, $windows );
			}
		}

		// A line offers as many units as remain after subtracting the quantity already reserved by open
		// requests. Statutorily-excluded lines offer nothing; the rest of the order stays available, so
		// concurrent partial withdrawals can share a line's units (e.g. 2 of 4 now, 2 of 4 later). In
		// strict mode a line outside its own window offers nothing either.
		$available    = array();
		$withdrawable = array();
		foreach ( $input->lines as $line ) {
			if ( $line->excluded ) {
				continue;
			}
			if ( $input->strict && ! $this->is_open( $windows[ $line->line_id ], $input ) ) {
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

			return EligibilityResult::ineligible( $reason, $window_start, $window_end, $windows );
		}

		return new EligibilityResult( true, Reason::ELIGIBLE, $window_start, $window_end, $eligible_line_ids, $available, $within_window, $windows );
	}

	/**
	 * Compute one line's period from its basis.
	 *
	 * @param EligibilityInput $input The engine input.
	 * @param EligibilityLine  $line  The line.
	 */
	private function line_window( EligibilityInput $input, EligibilityLine $line ): LineWindow {
		$basis = $line->window_basis ?? '';
		$start = '' !== $basis && array_key_exists( $basis, $input->basis_starts )
			? $input->basis_starts[ $basis ]
			: $input->window_start;

		return $this->window( $basis, $start, $input );
	}

	/**
	 * Build a period from its start.
	 *
	 * @param string                  $basis The basis the start stands for ('' for the order's own).
	 * @param \DateTimeImmutable|null $start When the period opens, if it has.
	 * @param EligibilityInput        $input The engine input.
	 */
	private function window( string $basis, ?\DateTimeImmutable $start, EligibilityInput $input ): LineWindow {
		if ( ! $start instanceof \DateTimeImmutable ) {
			return new LineWindow( $basis, null, null, false );
		}

		$end = $this->window_end( $start, $input->window_days );

		return new LineWindow( $basis, $start, $end, $input->now >= $start && $input->now <= $end );
	}

	/**
	 * Whether a period still admits a withdrawal in strict mode: opened, and not past its end plus the
	 * grace days.
	 *
	 * @param LineWindow       $window The period.
	 * @param EligibilityInput $input  The engine input.
	 */
	private function is_open( LineWindow $window, EligibilityInput $input ): bool {
		if ( ! $window->starts_at instanceof \DateTimeImmutable || ! $window->ends_at instanceof \DateTimeImmutable ) {
			return false;
		}
		if ( $input->now < $window->starts_at ) {
			return false;
		}

		return $input->now <= $window->ends_at->add( new \DateInterval( 'P' . max( 0, $input->grace_days ) . 'D' ) );
	}

	/**
	 * Reduce several line periods to the order-level bounds kept on the result for existing callers.
	 *
	 * The earliest known start, and the latest end — unless a line has not started yet, in which case
	 * the order has no end to state: its last period has not even begun. "Within window" is true while
	 * any of them is running. For a single period this returns that period unchanged.
	 *
	 * @param array<int, LineWindow> $windows Non-empty set of periods.
	 *
	 * @return array{0: \DateTimeImmutable|null, 1: \DateTimeImmutable|null, 2: bool}
	 */
	private function summarise( array $windows ): array {
		$start      = null;
		$end        = null;
		$open_ended = false;
		$within     = false;

		foreach ( $windows as $window ) {
			$within = $within || $window->within_window;

			if ( ! $window->starts_at instanceof \DateTimeImmutable || ! $window->ends_at instanceof \DateTimeImmutable ) {
				$open_ended = true;
				continue;
			}
			if ( null === $start || $window->starts_at < $start ) {
				$start = $window->starts_at;
			}
			if ( null === $end || $window->ends_at > $end ) {
				$end = $window->ends_at;
			}
		}

		return array( $start, $open_ended ? null : $end, $within );
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
