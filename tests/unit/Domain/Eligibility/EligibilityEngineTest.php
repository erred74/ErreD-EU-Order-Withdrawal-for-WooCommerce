<?php
/**
 * Unit tests for the eligibility engine.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Unit\Domain\Eligibility;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Domain\Eligibility\EligibilityEngine;
use Recesso54bis\Domain\Eligibility\EligibilityInput;
use Recesso54bis\Domain\Eligibility\EligibilityLine;
use Recesso54bis\Domain\Eligibility\Reason;
use Recesso54bis\Domain\Eligibility\WindowBasis;

/**
 * @covers \Recesso54bis\Domain\Eligibility\EligibilityEngine
 */
final class EligibilityEngineTest extends TestCase {

	private EligibilityEngine $engine;

	protected function setUp(): void {
		parent::setUp();
		$this->engine = new EligibilityEngine();
	}

	private function dt( string $value ): \DateTimeImmutable {
		return new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * @param array<int, EligibilityLine> $lines
	 * @param array<int, int>             $claimed_quantities Map line_id => quantity already reserved.
	 */
	private function input(
		string $now,
		bool $order_withdrawable = true,
		array $claimed_quantities = array(),
		int $window_days = 14,
		?string $window_start = '2026-06-01 00:00:00',
		array $lines = array()
	): EligibilityInput {
		if ( array() === $lines ) {
			$lines = array( new EligibilityLine( 10, true, false ) );
		}

		return new EligibilityInput(
			$this->dt( $now ),
			$order_withdrawable,
			$claimed_quantities,
			$window_days,
			null === $window_start ? null : $this->dt( $window_start ),
			$lines
		);
	}

	public function test_eligible_within_window(): void {
		$result = $this->engine->evaluate( $this->input( '2026-06-05 12:00:00' ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
		$this->assertSame( array( 10 ), $result->eligible_line_ids );
		$this->assertEquals( $this->dt( '2026-06-15 00:00:00' ), $result->window_ends_at );
		$this->assertTrue( $result->within_window );
	}

	public function test_order_not_withdrawable(): void {
		$result = $this->engine->evaluate( $this->input( '2026-06-05 12:00:00', order_withdrawable: false ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::ORDER_NOT_WITHDRAWABLE, $result->reason );
	}

	public function test_duplicate_open_request_when_only_line_is_fully_claimed(): void {
		// The single (qty 1) line is already fully reserved by an open request → nothing available.
		$result = $this->engine->evaluate( $this->input( '2026-06-05 12:00:00', claimed_quantities: array( 10 => 1 ) ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::DUPLICATE_OPEN, $result->reason );
	}

	public function test_fully_claimed_lines_drop_out_while_the_rest_stay_eligible(): void {
		$lines = array(
			new EligibilityLine( 10, true, false ),
			new EligibilityLine( 11, true, false ),
			new EligibilityLine( 12, true, false ),
		);
		// Line 11 (qty 1) is fully reserved: it drops out, the order stays eligible.
		$result = $this->engine->evaluate(
			$this->input( '2026-06-05 12:00:00', claimed_quantities: array( 11 => 1 ), lines: $lines )
		);

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
		$this->assertSame( array( 10, 12 ), $result->eligible_line_ids );
		$this->assertSame(
			array(
				10 => 1,
				12 => 1,
			),
			$result->available_quantities
		);
	}

	public function test_partial_quantity_reduces_availability_without_removing_the_line(): void {
		// A line of 4 units with 1 already reserved still offers 3.
		$lines  = array( new EligibilityLine( 10, true, false, 4 ) );
		$result = $this->engine->evaluate(
			$this->input( '2026-06-05 12:00:00', claimed_quantities: array( 10 => 1 ), lines: $lines )
		);

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( array( 10 ), $result->eligible_line_ids );
		$this->assertSame( array( 10 => 3 ), $result->available_quantities );
	}

	public function test_window_undeterminable_is_eligible_but_advisory(): void {
		// No window start (e.g. order not yet completed): the function is still offered, but the
		// advisory "within window" flag is false so the merchant can see the period is not running.
		$result = $this->engine->evaluate( $this->input( '2026-06-05 12:00:00', window_start: null ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
		$this->assertFalse( $result->within_window );
	}

	public function test_before_window_start_is_eligible_but_advisory(): void {
		$result = $this->engine->evaluate( $this->input( '2026-05-30 12:00:00' ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertFalse( $result->within_window );
	}

	public function test_after_window_end_is_eligible_but_advisory(): void {
		// The ordinary 14 days have elapsed: the function stays available (the merchant decides),
		// but within_window is false so the admin sees the period has passed.
		$result = $this->engine->evaluate( $this->input( '2026-06-16 00:00:01' ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
		$this->assertFalse( $result->within_window );
	}

	public function test_window_boundaries_are_inclusive_for_the_advisory_flag(): void {
		$at_start = $this->engine->evaluate( $this->input( '2026-06-01 00:00:00' ) );
		$at_end   = $this->engine->evaluate( $this->input( '2026-06-15 00:00:00' ) );

		$this->assertTrue( $at_start->within_window, 'Start instant should be within the window.' );
		$this->assertTrue( $at_end->within_window, 'End instant should be within the window.' );
	}

	public function test_unconfigured_line_fails_closed(): void {
		$lines  = array(
			new EligibilityLine( 10, true, false ),
			new EligibilityLine( 11, false, false ),
		);
		$result = $this->engine->evaluate( $this->input( '2026-06-05 12:00:00', lines: $lines ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::NEEDS_CONFIG, $result->reason );
	}

	public function test_all_lines_excluded(): void {
		$lines  = array(
			new EligibilityLine( 10, true, true ),
			new EligibilityLine( 11, true, true ),
		);
		$result = $this->engine->evaluate( $this->input( '2026-06-05 12:00:00', lines: $lines ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::EXCLUDED_ART59, $result->reason );
	}

	public function test_no_lines(): void {
		$input = new EligibilityInput(
			$this->dt( '2026-06-05 12:00:00' ),
			true,
			array(),
			14,
			$this->dt( '2026-06-01 00:00:00' ),
			array()
		);

		$result = $this->engine->evaluate( $input );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::NO_ELIGIBLE_ITEMS, $result->reason );
	}

	/**
	 * Build a strict-mode input (the window becomes a hard gate).
	 *
	 * @param string $now          Current instant.
	 * @param string $window_start Window start.
	 * @param int    $grace_days   Grace days added before closure.
	 */
	private function strict_input( string $now, string $window_start, int $grace_days = 0 ): EligibilityInput {
		return new EligibilityInput(
			$this->dt( $now ),
			true,
			array(),
			14,
			$this->dt( $window_start ),
			array( new EligibilityLine( 10, true, false ) ),
			true,
			$grace_days
		);
	}

	public function test_strict_mode_blocks_before_window_start(): void {
		$result = $this->engine->evaluate( $this->strict_input( '2026-05-30 12:00:00', '2026-06-01 00:00:00' ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::NOT_STARTED, $result->reason );
	}

	public function test_strict_mode_closes_after_window_end(): void {
		$result = $this->engine->evaluate( $this->strict_input( '2026-06-16 00:00:01', '2026-06-01 00:00:00' ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::WINDOW_CLOSED, $result->reason );
	}

	public function test_strict_mode_grace_days_extend_the_deadline(): void {
		// Two days past the 14-day end, but three grace days keep it open.
		$result = $this->engine->evaluate( $this->strict_input( '2026-06-17 00:00:00', '2026-06-01 00:00:00', 3 ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
	}

	public function test_strict_mode_within_window_is_eligible(): void {
		$result = $this->engine->evaluate( $this->strict_input( '2026-06-05 12:00:00', '2026-06-01 00:00:00' ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
	}

	public function test_partial_withdrawal_returns_only_eligible_lines(): void {
		$lines  = array(
			new EligibilityLine( 10, true, false ),
			new EligibilityLine( 11, true, true ),
			new EligibilityLine( 12, true, false ),
		);
		$result = $this->engine->evaluate( $this->input( '2026-06-05 12:00:00', lines: $lines ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
		$this->assertSame( array( 10, 12 ), $result->eligible_line_ids );
	}

	/**
	 * An order holding a T-shirt (goods, counted from delivery) and a football-school enrolment (a
	 * service, counted from the purchase).
	 *
	 * @param string      $now          Current instant.
	 * @param bool        $strict       Strict mode.
	 * @param string|null $delivered_at Completion date standing for delivery; null while undelivered.
	 * @param bool        $tee_excluded Whether the T-shirt is statutorily excluded.
	 */
	private function mixed_input( string $now, bool $strict, ?string $delivered_at, bool $tee_excluded = false ): EligibilityInput {
		$delivered = null === $delivered_at ? null : $this->dt( $delivered_at );

		return new EligibilityInput(
			$this->dt( $now ),
			true,
			array(),
			14,
			$delivered,
			array(
				new EligibilityLine( 10, true, $tee_excluded, 1, WindowBasis::DELIVERY ),
				new EligibilityLine( 20, true, false, 1, WindowBasis::CONCLUSION ),
			),
			$strict,
			0,
			array(
				WindowBasis::DELIVERY   => $delivered,
				WindowBasis::CONCLUSION => $this->dt( '2026-06-01 00:00:00' ),
			)
		);
	}

	public function test_each_line_is_counted_from_its_own_basis(): void {
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-12 12:00:00', false, '2026-06-10 00:00:00' ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertEquals( $this->dt( '2026-06-24 00:00:00' ), $result->line_windows[10]->ends_at, 'Goods: 14 days from delivery.' );
		$this->assertEquals( $this->dt( '2026-06-15 00:00:00' ), $result->line_windows[20]->ends_at, 'Service: 14 days from the purchase.' );
		$this->assertSame( WindowBasis::DELIVERY, $result->line_windows[10]->basis );
		$this->assertSame( WindowBasis::CONCLUSION, $result->line_windows[20]->basis );
	}

	public function test_order_summary_spans_its_lines(): void {
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-12 12:00:00', false, '2026-06-10 00:00:00' ) );

		$this->assertEquals( $this->dt( '2026-06-01 00:00:00' ), $result->window_starts_at );
		$this->assertEquals( $this->dt( '2026-06-24 00:00:00' ), $result->window_ends_at );
		$this->assertTrue( $result->within_window );
	}

	public function test_an_undelivered_line_leaves_the_order_without_an_end(): void {
		// The service period is running, the goods one has not begun: there is no last day to state yet.
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-05 12:00:00', false, null ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertNull( $result->window_ends_at );
		$this->assertNull( $result->line_windows[10]->starts_at );
		$this->assertEquals( $this->dt( '2026-06-15 00:00:00' ), $result->line_windows[20]->ends_at );
		$this->assertTrue( $result->within_window, 'The service period is running.' );
	}

	public function test_advisory_mode_keeps_an_expired_line_available(): void {
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-20 12:00:00', false, '2026-06-10 00:00:00' ) );

		$this->assertSame( array( 10, 20 ), $result->eligible_line_ids );
		$this->assertFalse( $result->line_windows[20]->within_window );
	}

	public function test_strict_mode_drops_only_the_expired_line(): void {
		// Service bought 1 June (closed 15 June), T-shirt delivered 10 June (open until 24 June).
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-20 12:00:00', true, '2026-06-10 00:00:00' ) );

		$this->assertTrue( $result->is_eligible, 'The T-shirt is still in its period.' );
		$this->assertSame( array( 10 ), $result->eligible_line_ids );
		$this->assertSame( array( 10 => 1 ), $result->available_quantities );
	}

	public function test_strict_mode_offers_the_service_before_the_goods_arrive(): void {
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-05 12:00:00', true, null ) );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( array( 20 ), $result->eligible_line_ids );
	}

	public function test_strict_mode_prefers_not_started_when_nothing_is_open(): void {
		// The service closed on 15 June and the T-shirt has not been delivered: it will open, so say so.
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-20 12:00:00', true, null ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::NOT_STARTED, $result->reason );
		$this->assertCount( 2, $result->line_windows, 'Per-line periods are reported on a refusal too.' );
	}

	public function test_strict_mode_closes_when_every_line_has_expired(): void {
		$result = $this->engine->evaluate( $this->mixed_input( '2026-07-01 12:00:00', true, '2026-06-10 00:00:00' ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::WINDOW_CLOSED, $result->reason );
	}

	public function test_an_excluded_line_does_not_hold_a_strict_order_open(): void {
		// The T-shirt is excluded (art. 59) and within its period; the service has expired. The order
		// must close, not be kept alive by a line that could never be withdrawn anyway.
		$result = $this->engine->evaluate( $this->mixed_input( '2026-06-20 12:00:00', true, '2026-06-10 00:00:00', true ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::WINDOW_CLOSED, $result->reason );
		$this->assertEquals( $this->dt( '2026-06-15 00:00:00' ), $result->window_ends_at, 'The excluded line must not stretch the deadline.' );
	}

	public function test_a_line_without_a_basis_follows_the_order_window(): void {
		$input = new EligibilityInput(
			$this->dt( '2026-06-05 12:00:00' ),
			true,
			array(),
			14,
			$this->dt( '2026-06-01 00:00:00' ),
			array( new EligibilityLine( 10, true, false ) ),
			false,
			0,
			array( WindowBasis::CONCLUSION => $this->dt( '2026-05-01 00:00:00' ) )
		);

		$result = $this->engine->evaluate( $input );

		$this->assertSame( '', $result->line_windows[10]->basis );
		$this->assertEquals( $this->dt( '2026-06-15 00:00:00' ), $result->line_windows[10]->ends_at );
	}
}
