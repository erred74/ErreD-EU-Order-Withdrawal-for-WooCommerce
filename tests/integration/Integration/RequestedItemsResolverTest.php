<?php
/**
 * Integration tests for the shared requested-items resolver.
 *
 * The resolver is the single source of truth shared by the durable PDF, the acknowledgement email and
 * the frontend confirmation/done screens, so it must itemise a request identically for all of them —
 * including the legacy "whole line" marker and the partial-by-quantity clamp.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Integration;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Integration\RequestedItemsResolver;

final class RequestedItemsResolverTest extends TestCase {

	private int $order_id   = 0;
	private int $line_one   = 0;
	private int $line_three = 0;

	protected function setUp(): void {
		parent::setUp();

		$single = new \WC_Product_Simple();
		$single->set_name( 'Single Unit Product' );
		$single->set_regular_price( '10' );
		$single->save();

		$multi = new \WC_Product_Simple();
		$multi->set_name( 'Multi Unit Product' );
		$multi->set_regular_price( '15' );
		$multi->save();

		$order            = wc_create_order();
		$this->line_one   = $order->add_product( wc_get_product( $single->get_id() ), 1 );
		$this->line_three = $order->add_product( wc_get_product( $multi->get_id() ), 3 );
		$order->set_status( 'completed' );
		$order->save();
		$this->order_id = $order->get_id();
	}

	protected function tearDown(): void {
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		parent::tearDown();
	}

	private function request_with( array $items ): WithdrawalRequest {
		return WithdrawalRequest::from_row(
			array(
				'id'              => 1,
				'order_id'        => $this->order_id,
				'requested_items' => (string) wp_json_encode( (object) $items ),
				'created_at_gmt'  => '2026-06-21 10:00:00',
			)
		);
	}

	public function test_partial_by_quantity_clamps_and_excludes_unselected_lines(): void {
		$order   = wc_get_order( $this->order_id );
		$request = $this->request_with( array( $this->line_three => 2 ) );

		$items = RequestedItemsResolver::resolve( $request, $order );

		$this->assertCount( 1, $items, 'Only the selected line is itemised.' );
		$this->assertSame( $this->line_three, $items[0]['line_id'] );
		$this->assertSame( 'Multi Unit Product', $items[0]['name'] );
		$this->assertSame( 2, $items[0]['quantity'] );
		$this->assertArrayNotHasKey( 'thumbnail_html', $items[0], 'No thumbnail unless requested.' );
	}

	public function test_quantity_is_clamped_to_the_ordered_amount(): void {
		$order   = wc_get_order( $this->order_id );
		$request = $this->request_with( array( $this->line_three => 99 ) );

		$items = RequestedItemsResolver::resolve( $request, $order );

		$this->assertSame( 3, $items[0]['quantity'], 'The withdrawn quantity never exceeds the ordered units.' );
	}

	public function test_empty_selection_means_the_whole_order(): void {
		$order   = wc_get_order( $this->order_id );
		$request = $this->request_with( array() );

		$items = RequestedItemsResolver::resolve( $request, $order );

		$this->assertCount( 2, $items, 'A whole-order withdrawal itemises every product line.' );
		$quantities = array();
		foreach ( $items as $item ) {
			$quantities[ $item['line_id'] ] = $item['quantity'];
		}
		$this->assertSame( 1, $quantities[ $this->line_one ] );
		$this->assertSame( 3, $quantities[ $this->line_three ] );
	}

	public function test_legacy_whole_line_marker_falls_back_to_full_quantity(): void {
		$order = wc_get_order( $this->order_id );
		// Legacy shape: a JSON list of line ids (quantity implicitly the whole line).
		$request = WithdrawalRequest::from_row(
			array(
				'id'              => 1,
				'order_id'        => $this->order_id,
				'requested_items' => (string) wp_json_encode( array( $this->line_three ) ),
				'created_at_gmt'  => '2026-06-21 10:00:00',
			)
		);

		$items = RequestedItemsResolver::resolve( $request, $order );

		$this->assertCount( 1, $items );
		$this->assertSame( 3, $items[0]['quantity'], 'A stored 0 (legacy whole line) withdraws the full ordered quantity.' );
	}

	public function test_thumbnail_markup_is_included_on_request(): void {
		$order   = wc_get_order( $this->order_id );
		$request = $this->request_with( array( $this->line_one => 1 ) );

		$items = RequestedItemsResolver::resolve( $request, $order, true );

		$this->assertArrayHasKey( 'thumbnail_html', $items[0] );
		$this->assertIsString( $items[0]['thumbnail_html'] );
	}
}
