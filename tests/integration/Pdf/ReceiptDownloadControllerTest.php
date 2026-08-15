<?php
/**
 * Integration tests for the receipt download controller's path hardening.
 *
 * Focuses on the directory-boundary check that prevents a stored path in a sibling directory
 * with a similar prefix (e.g. `recesso-digitale-private-x`) from being served as if it lived
 * inside the protected receipts directory.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Pdf;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Pdf\ReceiptBuilder;
use Recesso54bis\Pdf\ReceiptDownloadController;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Rest\PermissionGate;
use Recesso54bis\Support\OrderToken;

final class ReceiptDownloadControllerTest extends TestCase {

	private string $base_dir    = '';
	private string $sibling_dir = '';

	protected function setUp(): void {
		parent::setUp();
		$uploads           = wp_upload_dir();
		$this->base_dir    = trailingslashit( $uploads['basedir'] ) . ReceiptBuilder::PRIVATE_DIR;
		$this->sibling_dir = $this->base_dir . '-public';

		wp_mkdir_p( $this->base_dir );
		wp_mkdir_p( $this->sibling_dir );
		file_put_contents( $this->base_dir . '/legit.pdf', '%PDF-1.4 test' );
		file_put_contents( $this->sibling_dir . '/sneaky.pdf', '%PDF-1.4 test' );
	}

	protected function tearDown(): void {
		foreach ( array( $this->base_dir . '/legit.pdf', $this->sibling_dir . '/sneaky.pdf' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		parent::tearDown();
	}

	private function is_within_private_dir( string $path ): bool {
		$controller = new ReceiptDownloadController( new RequestRepository(), new PermissionGate( new OrderToken() ) );
		$method     = new \ReflectionMethod( $controller, 'is_within_private_dir' );
		$method->setAccessible( true );

		return (bool) $method->invoke( $controller, $path );
	}

	public function test_file_inside_private_dir_is_accepted(): void {
		$this->assertTrue( $this->is_within_private_dir( $this->base_dir . '/legit.pdf' ) );
	}

	public function test_file_in_similarly_prefixed_sibling_dir_is_rejected(): void {
		// `recesso-digitale-private-public` shares the prefix but is outside the protected dir.
		$this->assertFalse( $this->is_within_private_dir( $this->sibling_dir . '/sneaky.pdf' ) );
	}

	public function test_empty_path_is_rejected(): void {
		$this->assertFalse( $this->is_within_private_dir( '' ) );
	}

	public function test_an_unreadable_record_is_reported_as_missing_not_as_a_permissions_problem(): void {
		// Regression: a request row that cannot be read — most often because a schema migration has
		// not completed, so every SELECT fails — was reported as "You are not authorized", sending
		// merchants to hunt through capabilities for a database problem.
		wp_set_current_user( $this->admin_id() );

		$_GET['request'] = 99999999;

		$message = $this->capture_wp_die();

		$this->assertStringNotContainsStringIgnoringCase( 'not authorized', $message );
		$this->assertStringContainsStringIgnoringCase( 'could not be read', $message );
	}

	public function test_a_visitor_is_still_told_nothing_about_a_missing_record(): void {
		// The helpful wording is for merchants only: to anyone else the endpoint must stay uniform,
		// or it becomes a way to discover which request ids exist.
		wp_set_current_user( 0 );

		$_GET['request'] = 99999999;

		$this->assertStringContainsStringIgnoringCase( 'not authorized', $this->capture_wp_die() );
	}

	/*
	 * The My Account tab links to the receipt with no token and no nonce, so that the durable medium
	 * stays reachable after the emailed link's token expires. These two pin down why that is safe: the
	 * endpoint authorises the order's owner from their session, and refuses everyone else.
	 */

	public function test_the_order_owner_is_authorised_without_a_token(): void {
		$fixture = $this->seed_request_with_missing_receipt();
		wp_set_current_user( $fixture['customer_id'] );

		$_GET['request'] = $fixture['request_id'];

		// Authorisation is what is under test, so the receipt file is deliberately absent: getting as
		// far as "not available" proves the owner passed the gate, without the handler reaching
		// readfile() and exit, which would take the test process with it.
		$message = $this->capture_wp_die();

		$this->assertStringNotContainsStringIgnoringCase( 'not authorized', $message );
		$this->assertStringContainsStringIgnoringCase( 'not available', $message );

		$this->cleanup_fixture( $fixture );
	}

	public function test_another_logged_in_customer_is_refused(): void {
		$fixture = $this->seed_request_with_missing_receipt();

		$stranger = (int) wp_insert_user(
			array(
				'user_login' => 'recesso_receipt_stranger_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'customer',
			)
		);
		wp_set_current_user( $stranger );

		$_GET['request'] = $fixture['request_id'];

		$this->assertStringContainsStringIgnoringCase(
			'not authorized',
			$this->capture_wp_die(),
			'Being logged in is not ownership: a tokenless link must be useless to anyone else.'
		);

		wp_delete_user( $stranger );
		$this->cleanup_fixture( $fixture );
	}

	/**
	 * A confirmed request owned by a customer, with its receipt file removed from disk.
	 *
	 * @return array{customer_id: int, order_id: int, request_id: int}
	 */
	private function seed_request_with_missing_receipt(): array {
		\Recesso54bis\Activation\Migrations::run();
		update_option( \Recesso54bis\Support\Settings::OPT_DEFAULT_POLICY, \Recesso54bis\Support\Settings::POLICY_ALLOW );

		$container   = new \Recesso54bis\Container();
		$customer_id = (int) wp_insert_user(
			array(
				'user_login' => 'recesso_receipt_owner_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'customer',
			)
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Receipt owner fixture' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( 'receipt-owner@example.test' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		$service = $container->withdrawal_service();
		$request = $service->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $order->get_id(),
				'confirmation_email' => 'receipt-owner@example.test',
			),
			null
		);
		$service->confirm( $request->id, 'consumer' );

		$stored = $container->request_repository()->find_by_id( $request->id );
		if ( null !== $stored && null !== $stored->receipt_path && is_file( $stored->receipt_path ) ) {
			wp_delete_file( $stored->receipt_path );
		}

		return array(
			'customer_id' => $customer_id,
			'order_id'    => (int) $order->get_id(),
			'request_id'  => $request->id,
		);
	}

	/**
	 * Remove a fixture created by {@see self::seed_request_with_missing_receipt()}.
	 *
	 * @param array{customer_id: int, order_id: int, request_id: int} $fixture The fixture.
	 */
	private function cleanup_fixture( array $fixture ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', \Recesso54bis\Persistence\Schema::requests_table(), $fixture['order_id'] ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', \Recesso54bis\Persistence\Schema::claims_table(), $fixture['order_id'] ) );

		$order = wc_get_order( $fixture['order_id'] );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}

		wp_delete_user( $fixture['customer_id'] );
		delete_option( \Recesso54bis\Support\Settings::OPT_DEFAULT_POLICY );
		wp_set_current_user( 0 );
	}

	/**
	 * Run the download handler and return the message it died with.
	 */
	private function capture_wp_die(): string {
		$controller = new ReceiptDownloadController( new RequestRepository(), new PermissionGate( new OrderToken() ) );

		$handler = static function (): callable {
			return static function ( $message ): void {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test harness: the message is captured and asserted on, never rendered.
				throw new \RuntimeException( is_string( $message ) ? $message : 'non-string wp_die message' );
			};
		};
		add_filter( 'wp_die_handler', $handler );

		try {
			$controller->handle();
			return '';
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		} finally {
			remove_filter( 'wp_die_handler', $handler );
			unset( $_GET['request'] );
		}
	}

	/**
	 * An administrator user id, created once for the suite.
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
				'user_login' => 'recesso_receipt_admin',
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'administrator',
			)
		);
	}
}
