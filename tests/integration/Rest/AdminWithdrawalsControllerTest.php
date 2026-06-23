<?php
/**
 * Integration tests for the admin withdrawals REST controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Rest;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Capabilities;
use Recesso54bis\Support\Settings;

final class AdminWithdrawalsControllerTest extends TestCase {

	private int $order_id         = 0;
	private int $request_id       = 0;
	private int $admin_id         = 0;
	private ?Container $container = null;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		Capabilities::add();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		rest_get_server();

		$this->admin_id = wp_insert_user(
			array(
				'user_login' => 'rd_admin_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);

		$this->container = new Container();
		$order           = $this->make_order();
		$this->order_id  = $order->get_id();

		$request          = $this->container->withdrawal_service()->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'mario@example.org',
			),
			null
		);
		$this->request_id = $request->id;
		// Confirm via the repository so setUp does not generate the (memory-heavy) durable PDF that the
		// service's confirmation hook now builds synchronously; these tests exercise admin actions, and
		// the one that needs a receipt generates it explicitly. Sync-on-confirm is covered by ReceiptTest.
		$this->container->request_repository()->confirm( $request->id, gmdate( 'Y-m-d H:i:s' ) );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );

		if ( null !== $this->container ) {
			$record = $this->container->request_repository()->find_by_id( $this->request_id );
			if ( null !== $record && null !== $record->receipt_path && is_file( $record->receipt_path ) ) {
				wp_delete_file( $record->receipt_path );
			}
		}

		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), $this->order_id ) );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		if ( $this->admin_id > 0 ) {
			wp_delete_user( $this->admin_id );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		parent::tearDown();
	}

	private function make_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Admin Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}

	public function test_list_denied_for_non_admin(): void {
		wp_set_current_user( 0 );
		$request = new \WP_REST_Request( 'GET', '/recesso-digitale/v1/admin/withdrawals' );

		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}

	public function test_list_returns_items_for_admin(): void {
		wp_set_current_user( $this->admin_id );
		$request  = new \WP_REST_Request( 'GET', '/recesso-digitale/v1/admin/withdrawals' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
		$this->assertArrayHasKey( 'X-WP-Total', $response->get_headers() );
	}

	public function test_detail_includes_timeline(): void {
		// Generate the receipt only here (PDF rendering is memory-heavy; keep it out of every setUp).
		$this->container->receipt_scheduler()->generate( $this->request_id );

		wp_set_current_user( $this->admin_id );
		$request = new \WP_REST_Request( 'GET', '/recesso-digitale/v1/admin/withdrawals/' . $this->request_id );
		$request->set_param( 'id', $this->request_id );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'timeline', $data );
		$this->assertNotEmpty( $data['timeline'] );
		// The admin detail exposes the withdrawn items and the partial/whole flag.
		$this->assertArrayHasKey( 'items', $data );
		$this->assertArrayHasKey( 'is_partial', $data );
		$this->assertSame( array( 'Admin Test' ), $data['items'] );
		$this->assertFalse( $data['is_partial'] );
		// A receipt was generated above, so the admin payload exposes an inline view URL and the receipt
		// verification code (hash) for the detail card.
		$this->assertArrayHasKey( 'receipt_url', $data );
		$this->assertNotEmpty( $data['receipt_url'] );
		$this->assertStringContainsString( 'recesso_dig_receipt', (string) $data['receipt_url'] );
		$this->assertArrayHasKey( 'receipt_hash', $data );
		$this->assertSame( 64, strlen( (string) $data['receipt_hash'] ), 'The receipt hash (SHA-256) must be exposed to the admin detail card.' );
	}

	public function test_process_reject_with_reason_transitions_and_logs(): void {
		wp_set_current_user( $this->admin_id );
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/admin/withdrawals/' . $this->request_id . '/status' );
		$request->set_param( 'id', $this->request_id );
		$request->set_param( 'action', 'reject' );
		$request->set_param( 'reason', 'Outside the ordinary withdrawal period.' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( RequestStatus::REJECTED, $response->get_data()['status'] );

		// The reason is recorded in the audit trail.
		$reasons = array();
		foreach ( $this->container->log_repository()->for_request( $this->request_id ) as $row ) {
			$payload = json_decode( (string) $row['payload'], true );
			if ( is_array( $payload ) && isset( $payload['reason'] ) ) {
				$reasons[] = $payload['reason'];
			}
		}
		$this->assertContains( 'Outside the ordinary withdrawal period.', $reasons );
	}

	public function test_process_reject_without_reason_is_rejected_400(): void {
		wp_set_current_user( $this->admin_id );
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/admin/withdrawals/' . $this->request_id . '/status' );
		$request->set_param( 'id', $this->request_id );
		$request->set_param( 'action', 'reject' );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		// The request must not have been transitioned.
		$this->assertSame( RequestStatus::CONFIRMED, $this->container->request_repository()->find_by_id( $this->request_id )->status );
	}

	public function test_set_status_accepted_transitions_and_reports_admin_status(): void {
		wp_set_current_user( $this->admin_id );
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/admin/withdrawals/' . $this->request_id . '/status' );
		$request->set_param( 'id', $this->request_id );
		$request->set_param( 'action', 'set_status' );
		$request->set_param( 'status', RequestStatus::ACCEPTED );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( RequestStatus::ACCEPTED, $data['status'] );
		$this->assertSame( RequestStatus::ACCEPTED, $data['admin_status'] );
		$this->assertSame( RequestStatus::ACCEPTED, $this->container->request_repository()->find_by_id( $this->request_id )->status );
	}

	public function test_set_status_rejected_without_reason_is_rejected_400(): void {
		wp_set_current_user( $this->admin_id );
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/admin/withdrawals/' . $this->request_id . '/status' );
		$request->set_param( 'id', $this->request_id );
		$request->set_param( 'action', 'set_status' );
		$request->set_param( 'status', RequestStatus::REJECTED );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( RequestStatus::CONFIRMED, $this->container->request_repository()->find_by_id( $this->request_id )->status );
	}

	public function test_set_status_pending_returns_a_decided_request_to_its_resting_state(): void {
		$repo = $this->container->request_repository();
		$repo->transition_status( $this->request_id, RequestStatus::ACCEPTED );

		wp_set_current_user( $this->admin_id );
		$request = new \WP_REST_Request( 'POST', '/recesso-digitale/v1/admin/withdrawals/' . $this->request_id . '/status' );
		$request->set_param( 'id', $this->request_id );
		$request->set_param( 'action', 'set_status' );
		$request->set_param( 'status', RequestStatus::PENDING );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		// No receipt was generated, so the resting state is "confirmed", which reads back as "pending".
		$this->assertSame( RequestStatus::CONFIRMED, $repo->find_by_id( $this->request_id )->status );
		$this->assertSame( RequestStatus::PENDING, $response->get_data()['admin_status'] );
	}

	public function test_latest_for_order_returns_the_request(): void {
		$found = $this->container->request_repository()->latest_for_order( $this->order_id );
		$this->assertNotNull( $found );
		$this->assertSame( $this->request_id, $found->id );
	}
}
