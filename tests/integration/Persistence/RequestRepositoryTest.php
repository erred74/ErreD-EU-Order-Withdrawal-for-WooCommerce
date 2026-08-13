<?php
/**
 * Integration tests for the withdrawal request repository.
 *
 * Exercises the legal invariants against a real database: the atomic duplicate-open guard, the
 * write-once confirmation timestamp (legal dies a quo), the write-once receipt, and claim release.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Persistence\DuplicateOpenRequestException;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Persistence\Schema;

final class RequestRepositoryTest extends TestCase {

	private const ORDER_ID = 999777;

	private RequestRepository $repo;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		$this->repo = new RequestRepository();
		$this->cleanup();
	}

	protected function tearDown(): void {
		$this->cleanup();
		parent::tearDown();
	}

	private function cleanup(): void {
		global $wpdb;
		$table = Schema::requests_table();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', $table, self::ORDER_ID ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), self::ORDER_ID ) );
	}

	/**
	 * Build declaration data for the given line => quantity selection. Line totals default to the
	 * requested quantity (so each line is fully reserved) unless overridden.
	 *
	 * @param array<int, int> $items  Map line_id => requested quantity.
	 * @param array<int, int> $totals Map line_id => total order quantity (defaults to $items).
	 */
	private function data( array $items = array(
		10 => 1,
		11 => 1,
	), array $totals = array() ): array {
		return array(
			'consumer_name'      => 'Mario Rossi',
			'contract_reference' => '#' . self::ORDER_ID,
			'confirmation_email' => 'mario@example.org',
			'requested_items'    => $items,
			'line_totals'        => array() === $totals ? $items : $totals,
		);
	}

	private function declaration_data(): array {
		return $this->data();
	}

	public function test_admin_search_matches_text_fields_and_order_id(): void {
		$request = $this->repo->create_declaration(
			self::ORDER_ID,
			array(
				'consumer_name'      => 'Searchable Tester ' . self::ORDER_ID,
				'contract_reference' => '#' . self::ORDER_ID,
				'confirmation_email' => 'searchable+' . self::ORDER_ID . '@example.test',
				'requested_items'    => array(),
				'line_totals'        => array(),
			),
			'2026-06-19 10:00:00'
		);
		// Confirm it so it is a real (listed) request: the admin list excludes unconfirmed pending ones.
		$this->repo->confirm( $request->id, '2026-06-19 11:00:00' );

		// Free-text search matches the consumer name, the confirmation email and the order id.
		$this->assertSame( 1, $this->repo->count_for_admin( array( 'search' => 'Searchable Tester' ) ) );
		$this->assertSame( 1, $this->repo->count_for_admin( array( 'search' => 'searchable+' . self::ORDER_ID ) ) );
		$this->assertSame( 1, $this->repo->count_for_admin( array( 'search' => (string) self::ORDER_ID ) ) );
		$this->assertCount( 1, $this->repo->query_for_admin( array( 'search' => 'Searchable Tester' ) ) );

		// A non-matching term returns nothing.
		$this->assertSame( 0, $this->repo->count_for_admin( array( 'search' => 'definitely-absent-zzz' ) ) );

		// LIKE wildcards in the term are escaped (esc_like), so a bare "%" matches nothing literal.
		$this->assertSame( 0, $this->repo->count_for_admin( array( 'search' => '%' ) ) );
	}

	public function test_create_persists_pending_declaration(): void {
		$request = $this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:00:00' );

		$this->assertGreaterThan( 0, $request->id );
		$this->assertSame( self::ORDER_ID, $request->order_id );
		$this->assertSame( RequestStatus::PENDING, $request->status );
		$this->assertSame( 'Mario Rossi', $request->consumer_name );
		$this->assertSame(
			array(
				10 => 1,
				11 => 1,
			),
			$request->requested_items
		);
		$this->assertNull( $request->confirmed_at_gmt );
	}

	public function test_count_awaiting_action_includes_confirmed_and_acknowledged(): void {
		// The count is global (all orders), so measure deltas against a baseline.
		$baseline = $this->repo->count_awaiting_action();

		$request = $this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:00:00' );

		// A pending (not yet confirmed) request is awaiting the consumer, not the merchant.
		$this->assertSame( $baseline, $this->repo->count_awaiting_action() );

		// Once confirmed it is open and awaiting the merchant's decision → counts for the badge.
		$this->repo->confirm( $request->id, '2026-06-19 11:00:00' );
		$this->assertSame( $baseline + 1, $this->repo->count_awaiting_action() );

		// Issuing the durable-medium receipt moves it to "acknowledged"; it is still open and must
		// keep counting (counting only "confirmed" made the menu badge vanish once the receipt ran).
		$this->repo->attach_receipt( $request->id, str_repeat( 'a', 64 ), '/protected/a.pdf', '2026-06-19 11:01:00' );
		$this->assertSame( $baseline + 1, $this->repo->count_awaiting_action() );
	}

	public function test_pending_requests_are_excluded_from_the_admin_listing(): void {
		$baseline_count = $this->repo->count_for_admin( array() );

		$request = $this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:00:00' );

		// While pending it must not appear in the admin list or its total. The listing is searched by
		// this fixture's own order id rather than paged through: the table is shared with the rest of
		// the suite, and the fixture's back-dated timestamp would otherwise sort off the first page.
		$this->assertSame( $baseline_count, $this->repo->count_for_admin( array() ) );
		$this->assertNotContains( $request->id, $this->listed_ids() );

		// Once confirmed it becomes a real request and is listed.
		$this->repo->confirm( $request->id, '2026-06-19 11:00:00' );
		$this->assertSame( $baseline_count + 1, $this->repo->count_for_admin( array() ) );
		$this->assertContains( $request->id, $this->listed_ids() );
	}

	/**
	 * The ids the admin listing returns for this fixture's order.
	 *
	 * @return int[]
	 */
	private function listed_ids(): array {
		$rows = $this->repo->query_for_admin(
			array(
				'search'   => (string) self::ORDER_ID,
				'per_page' => 100,
			)
		);

		return array_map( static fn( $row ): int => $row->id, $rows );
	}

	public function test_discard_pending_for_order_removes_the_row_and_frees_the_reservation(): void {
		// An abandoned, unconfirmed declaration reserves units; discarding it must free them so a
		// fresh declaration for the same units succeeds.
		$abandoned = $this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 1 ), array( 10 => 1 ) ), '2026-06-19 10:00:00' );

		$this->repo->discard_pending_for_order( self::ORDER_ID );

		$this->assertNull( $this->repo->find_by_id( $abandoned->id ), 'The abandoned pending request should be removed.' );

		// The reservation is released, so a new declaration for the same single unit is not blocked.
		$fresh = $this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 1 ), array( 10 => 1 ) ), '2026-06-19 10:05:00' );
		$this->assertGreaterThan( 0, $fresh->id );

		// A confirmed request, by contrast, is not discarded.
		$this->repo->confirm( $fresh->id, '2026-06-19 10:06:00' );
		$this->repo->discard_pending_for_order( self::ORDER_ID );
		$this->assertNotNull( $this->repo->find_by_id( $fresh->id ), 'A confirmed request must never be discarded.' );
	}

	public function test_duplicate_open_request_is_blocked_atomically(): void {
		$this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:00:00' );

		$this->expectException( DuplicateOpenRequestException::class );
		$this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:05:00' );
	}

	public function test_confirm_is_write_once(): void {
		$request = $this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:00:00' );

		$confirmed = $this->repo->confirm( $request->id, '2026-06-19 11:00:00' );
		$this->assertNotNull( $confirmed );
		$this->assertSame( '2026-06-19 11:00:00', $confirmed->confirmed_at_gmt );
		$this->assertSame( RequestStatus::CONFIRMED, $confirmed->status );

		// A second confirmation must not overwrite the legal dies a quo.
		$reconfirmed = $this->repo->confirm( $request->id, '2026-06-20 09:00:00' );
		$this->assertNotNull( $reconfirmed );
		$this->assertSame( '2026-06-19 11:00:00', $reconfirmed->confirmed_at_gmt );
	}

	public function test_receipt_is_write_once(): void {
		$request = $this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:00:00' );
		$this->repo->confirm( $request->id, '2026-06-19 11:00:00' );

		$first = $this->repo->attach_receipt( $request->id, str_repeat( 'a', 64 ), '/protected/a.pdf', '2026-06-19 11:01:00' );
		$this->assertNotNull( $first );
		$this->assertSame( str_repeat( 'a', 64 ), $first->receipt_hash );
		$this->assertSame( RequestStatus::ACKNOWLEDGED, $first->status );

		$second = $this->repo->attach_receipt( $request->id, str_repeat( 'b', 64 ), '/protected/b.pdf', '2026-06-20 09:00:00' );
		$this->assertNotNull( $second );
		$this->assertSame( str_repeat( 'a', 64 ), $second->receipt_hash );
		$this->assertSame( '/protected/a.pdf', $second->receipt_path );
	}

	public function test_rejecting_releases_reserved_quantities_and_allows_a_new_request(): void {
		$request = $this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 10:00:00' );

		$this->assertSame(
			array(
				10 => 1,
				11 => 1,
			),
			$this->repo->claimed_quantities( self::ORDER_ID )
		);

		$this->repo->transition_status( $request->id, RequestStatus::REJECTED );
		$this->assertSame( array(), $this->repo->claimed_quantities( self::ORDER_ID ) );

		// The consumer may now submit a fresh request for the same lines.
		$fresh = $this->repo->create_declaration( self::ORDER_ID, $this->declaration_data(), '2026-06-19 12:00:00' );
		$this->assertGreaterThan( $request->id, $fresh->id );
	}

	public function test_partial_quantities_of_the_same_line_coexist_up_to_the_total(): void {
		// Line 10 has 4 units. Two open requests each reserve 2 → the line is fully reserved.
		$first  = $this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 2 ), array( 10 => 4 ) ), '2026-06-19 10:00:00' );
		$second = $this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 2 ), array( 10 => 4 ) ), '2026-06-19 10:05:00' );

		$this->assertGreaterThan( $first->id, $second->id );
		$this->assertSame( array( 10 => 4 ), $this->repo->claimed_quantities( self::ORDER_ID ) );
	}

	public function test_reserving_beyond_the_available_quantity_is_blocked(): void {
		// Line 10 has 4 units, 3 already reserved; a request for 2 more (only 1 free) must fail.
		$this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 3 ), array( 10 => 4 ) ), '2026-06-19 10:00:00' );

		$this->expectException( DuplicateOpenRequestException::class );
		$this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 2 ), array( 10 => 4 ) ), '2026-06-19 10:05:00' );
	}

	public function test_releasing_one_request_frees_only_its_units(): void {
		// Line 10 has 4 units: request A reserves 1, request B reserves 2 (3 reserved total).
		$a = $this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 1 ), array( 10 => 4 ) ), '2026-06-19 10:00:00' );
		$this->repo->create_declaration( self::ORDER_ID, $this->data( array( 10 => 2 ), array( 10 => 4 ) ), '2026-06-19 10:05:00' );
		$this->assertSame( array( 10 => 3 ), $this->repo->claimed_quantities( self::ORDER_ID ) );

		// Rejecting A returns only its 1 unit; B's 2 stay reserved.
		$this->repo->transition_status( $a->id, RequestStatus::REJECTED );
		$this->assertSame( array( 10 => 2 ), $this->repo->claimed_quantities( self::ORDER_ID ) );

		// A second rejection of the same request must not double-release.
		$this->repo->transition_status( $a->id, RequestStatus::REJECTED );
		$this->assertSame( array( 10 => 2 ), $this->repo->claimed_quantities( self::ORDER_ID ) );
	}
}
