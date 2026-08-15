<?php
/**
 * Integration tests for the receipt URL the admin REST controller hands to the React app.
 *
 * Regression: the URL was built with wp_nonce_url(), which HTML-escapes what it returns. That is
 * correct when printing into markup, but this value is delivered as JSON and set straight onto an
 * anchor's href, so the escaped separators reached the browser literally: it requested "amp;request"
 * instead of "request", the endpoint saw no request id, and every receipt opened from the detail
 * panel failed with "You are not authorized to perform this action."
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Rest;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class ReceiptUrlTest extends TestCase {

	private Container $container;
	private int $order_id   = 0;
	private int $request_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );

		$this->container = new Container();

		$product = new \WC_Product_Simple();
		$product->set_name( 'Receipt URL fixture' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_billing_email( 'receipt-url@example.test' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		$service = $this->container->withdrawal_service();
		$request = $service->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'receipt-url@example.test',
			),
			null
		);
		$service->confirm( $request->id, 'consumer' );
		$this->request_id = $request->id;
	}

	protected function tearDown(): void {
		global $wpdb;

		$record = $this->container->request_repository()->find_by_id( $this->request_id );
		if ( null !== $record && null !== $record->receipt_path && is_file( $record->receipt_path ) ) {
			wp_delete_file( $record->receipt_path );
		}

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}

		delete_option( Settings::OPT_DEFAULT_POLICY );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_the_receipt_url_carries_query_parameters_a_browser_will_actually_send(): void {
		$url = $this->receipt_url();

		$this->assertNotSame( '', $url, 'A confirmed request with a receipt must expose a download URL.' );
		$this->assertStringNotContainsString(
			'&amp;',
			$url,
			'The URL is delivered as JSON, not markup: an HTML-escaped separator would be sent literally by the browser.'
		);

		// Parse it exactly as a browser would, then assert the endpoint receives what it reads.
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $params );

		$this->assertSame( 'recesso_dig_receipt', $params['action'] ?? null );
		$this->assertSame( (string) $this->request_id, (string) ( $params['request'] ?? '' ) );
		$this->assertSame( 'inline', $params['disposition'] ?? null );
		$this->assertNotEmpty( $params['_recesso_dig_receipt'] ?? '', 'The download nonce must survive into the query string.' );
	}

	public function test_the_nonce_in_the_receipt_url_verifies(): void {
		parse_str( (string) wp_parse_url( $this->receipt_url(), PHP_URL_QUERY ), $params );

		$this->assertNotFalse(
			wp_verify_nonce( (string) ( $params['_recesso_dig_receipt'] ?? '' ), 'recesso_dig_receipt' ),
			'The nonce is created for the current admin and must verify for them.'
		);
	}

	/**
	 * The receipt URL the REST controller presents for the fixture request, as an administrator.
	 */
	private function receipt_url(): string {
		wp_set_current_user( $this->admin_id() );

		$response = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $response );

		$controller = new \Recesso54bis\Rest\AdminWithdrawalsController(
			$this->container->permission_gate(),
			$this->container->rate_limiter(),
			$this->container->log_repository(),
			$this->container->request_repository(),
			$this->container->receipt_scheduler()
		);

		$rest = new \WP_REST_Request( 'GET', '/recesso-digitale/v1/admin/withdrawals/' . $this->request_id );
		$rest->set_param( 'id', $this->request_id );

		$result = $controller->get_item( $rest );
		$this->assertInstanceOf( \WP_REST_Response::class, $result );

		$data = $result->get_data();

		return is_array( $data ) ? (string) ( $data['receipt_url'] ?? '' ) : '';
	}

	/**
	 * An administrator user id.
	 */
	private function admin_id(): int {
		$existing = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);

		if ( array() !== $existing ) {
			return (int) $existing[0];
		}

		return (int) wp_insert_user(
			array(
				'user_login' => 'recesso_receipt_url_admin',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'administrator',
			)
		);
	}
}
