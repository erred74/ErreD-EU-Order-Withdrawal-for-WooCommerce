<?php
/**
 * Integration tests for the withdrawals REST controller.
 *
 * Focuses on the security-critical paths: token authorisation, uniform denial / anti-enumeration,
 * the duplicate guard, and idempotent confirmation.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Rest;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\OrderToken;
use Recesso54bis\Support\Settings;

final class WithdrawalsControllerTest extends TestCase {

	private int $order_id = 0;
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		wp_set_current_user( 0 );

		// Ensure the REST routes are registered.
		rest_get_server();

		$this->clear_rate_limiter();
		$order          = $this->make_order();
		$this->order_id = $order->get_id();
		$this->token    = ( new OrderToken() )->issue( $this->order_id, time() + 3600 );
	}

	protected function tearDown(): void {
		$this->delete_requests( $this->order_id );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		$this->clear_rate_limiter();
		parent::tearDown();
	}

	private function make_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Recesso REST Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}

	private function make_multi_order(): \WC_Order {
		$order = wc_create_order();
		foreach ( array( 'A', 'B' ) as $suffix ) {
			$product = new \WC_Product_Simple();
			$product->set_name( 'Recesso Multi ' . $suffix );
			$product->set_regular_price( '10' );
			$product->save();
			$order->add_product( wc_get_product( $product->get_id() ), 1 );
		}
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}

	/**
	 * @return int[]
	 */
	private function line_ids( \WC_Order $order ): array {
		return array_map( 'intval', array_keys( $order->get_items() ) );
	}

	private function delete_requests( int $order_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $order_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), $order_id ) );
	}

	private function clear_rate_limiter(): void {
		delete_transient( 'recesso_dig_rl_' . md5( 'order_action:unknown' ) );
	}

	private function create_request( ?string $token ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/withdrawals' );
		$request->set_param( 'order_id', $this->order_id );
		if ( null !== $token ) {
			$request->set_param( 'token', $token );
		}
		$request->set_param( 'consumer_name', 'Mario Rossi' );
		$request->set_param( 'contract_reference', '#' . $this->order_id );
		$request->set_param( 'confirmation_email', 'mario@example.org' );

		return rest_do_request( $request );
	}

	public function test_routes_are_registered(): void {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/recesso-digitale/v1/withdrawals', $routes );
		$this->assertArrayHasKey( '/recesso-digitale/v1/eligibility/(?P<order>\d+)', $routes );
	}

	public function test_create_without_token_is_forbidden(): void {
		$response = $this->create_request( null );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_create_with_invalid_token_is_forbidden(): void {
		$response = $this->create_request( 'not-a-valid-token' );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_create_with_valid_token_succeeds(): void {
		$response = $this->create_request( $this->token );
		$data     = $response->get_data();

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'pending', $data['status'] );
		$this->assertGreaterThan( 0, $data['id'] );
	}

	public function test_contract_reference_is_derived_from_order_not_client(): void {
		// A consumer with a valid token POSTs a contract_reference that does not match their order.
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/withdrawals' );
		$request->set_param( 'order_id', $this->order_id );
		$request->set_param( 'token', $this->token );
		$request->set_param( 'consumer_name', 'Mario Rossi' );
		$request->set_param( 'contract_reference', 'TAMPERED-9999' );
		$request->set_param( 'confirmation_email', 'mario@example.org' );

		$response = rest_do_request( $request );
		$this->assertSame( 201, $response->get_status() );

		// The persisted legal record must use the authoritative order number, never the client value.
		$saved = ( new \Recesso54bis\Persistence\RequestRepository() )->find_by_id( (int) $response->get_data()['id'] );
		$this->assertNotNull( $saved );
		$this->assertSame( wc_get_order( $this->order_id )->get_order_number(), $saved->contract_reference );
		$this->assertNotSame( 'TAMPERED-9999', $saved->contract_reference );
	}

	public function test_duplicate_open_request_conflicts(): void {
		$this->assertSame( 201, $this->create_request( $this->token )->get_status() );

		$second = $this->create_request( $this->token );
		$this->assertSame( 409, $second->get_status() );
	}

	public function test_partial_requests_on_disjoint_lines_coexist(): void {
		$order = $this->make_multi_order();
		$token = ( new OrderToken() )->issue( $order->get_id(), time() + 3600 );
		$lines = $this->line_ids( $order );

		$first  = $this->create_partial_request( $order->get_id(), $token, array( $lines[0] => 1 ) );
		$second = $this->create_partial_request( $order->get_id(), $token, array( $lines[1] => 1 ) );

		$this->assertSame( 201, $first->get_status() );
		$this->assertSame( 201, $second->get_status() );

		// A third request overlapping the first (now fully reserved) line must conflict.
		$third = $this->create_partial_request( $order->get_id(), $token, array( $lines[0] => 1 ) );
		$this->assertSame( 409, $third->get_status() );

		$this->delete_requests( $order->get_id() );
		$order->delete( true );
	}

	public function test_request_with_only_ineligible_lines_is_unprocessable(): void {
		$order = $this->make_multi_order();
		$token = ( new OrderToken() )->issue( $order->get_id(), time() + 3600 );

		// A line id that does not belong to this order is dropped, leaving nothing to withdraw.
		$response = $this->create_partial_request( $order->get_id(), $token, array( 999999 => 1 ) );
		$this->assertSame( 422, $response->get_status() );

		$this->delete_requests( $order->get_id() );
		$order->delete( true );
	}

	public function test_quantity_reservations_share_a_line_up_to_its_total(): void {
		$order = $this->make_qty_order( 4 );
		$token = ( new OrderToken() )->issue( $order->get_id(), time() + 3600 );
		$line  = $this->line_ids( $order )[0];

		// Two requests each reserve 2 of the 4 units → both succeed; a third (1 more) has nothing left.
		$this->assertSame( 201, $this->create_partial_request( $order->get_id(), $token, array( $line => 2 ) )->get_status() );
		$this->assertSame( 201, $this->create_partial_request( $order->get_id(), $token, array( $line => 2 ) )->get_status() );
		$this->assertSame( 409, $this->create_partial_request( $order->get_id(), $token, array( $line => 1 ) )->get_status() );

		$this->delete_requests( $order->get_id() );
		$order->delete( true );
	}

	private function make_qty_order( int $quantity ): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Recesso Qty Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), $quantity );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}

	/**
	 * @param array<int, int> $quantities Map line_id => quantity.
	 */
	private function create_partial_request( int $order_id, string $token, array $quantities ): \WP_REST_Response {
		$this->clear_rate_limiter();
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/withdrawals' );
		$request->set_param( 'order_id', $order_id );
		$request->set_param( 'token', $token );
		$request->set_param( 'consumer_name', 'Mario Rossi' );
		$request->set_param( 'contract_reference', '#' . $order_id );
		$request->set_param( 'confirmation_email', 'mario@example.org' );
		$request->set_param( 'requested_items', $quantities );

		return rest_do_request( $request );
	}

	public function test_confirm_is_idempotent(): void {
		$created = $this->create_request( $this->token )->get_data();
		$id      = (int) $created['id'];

		$first = $this->confirm_request( $id, $this->token );
		$this->assertSame( 200, $first->get_status() );
		$confirmed_at = $first->get_data()['confirmed_at_gmt'];
		$this->assertNotNull( $confirmed_at );

		$second = $this->confirm_request( $id, $this->token );
		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( $confirmed_at, $second->get_data()['confirmed_at_gmt'] );
	}

	public function test_get_with_wrong_token_is_forbidden(): void {
		$created = $this->create_request( $this->token )->get_data();
		$id      = (int) $created['id'];

		$request = new \WP_REST_Request( 'GET', '/recesso-digitale/v1/withdrawals/' . $id );
		$request->set_param( 'id', $id );
		$request->set_param( 'token', 'wrong-token' );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_unknown_request_id_is_denied_uniformly(): void {
		$request = new \WP_REST_Request( 'GET', '/recesso-digitale/v1/withdrawals/99999999' );
		$request->set_param( 'id', 99999999 );
		$request->set_param( 'token', $this->token );

		// Uniform 403 — never a 404 — so request existence cannot be enumerated.
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_eligibility_endpoint_with_token(): void {
		$request = new \WP_REST_Request( 'GET', '/recesso-digitale/v1/eligibility/' . $this->order_id );
		$request->set_param( 'order', $this->order_id );
		$request->set_param( 'token', $this->token );

		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['is_eligible'] );
	}

	private function confirm_request( int $id, string $token ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/withdrawals/' . $id . '/confirm' );
		$request->set_param( 'id', $id );
		$request->set_param( 'token', $token );

		return rest_do_request( $request );
	}
}
