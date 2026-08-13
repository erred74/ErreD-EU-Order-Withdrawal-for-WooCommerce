<?php
/**
 * Integration tests for the versioning of the canonical receipt payload.
 *
 * The receipt hash is tamper-evidence: a merchant must be able to recompute it years later and get
 * the stored value back. Adding a field to the payload therefore may not change the hash of a
 * receipt that does not carry that field.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Pdf;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class ReceiptPayloadVersionTest extends TestCase {

	private Container $container;

	/**
	 * Order ids created by a test, removed in tearDown.
	 *
	 * @var int[]
	 */
	private array $orders = array();

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		$this->container = new Container();
	}

	protected function tearDown(): void {
		global $wpdb;

		foreach ( $this->orders as $order_id ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $order_id ) );
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}
		$this->orders = array();

		delete_option( Settings::OPT_DEFAULT_POLICY );
		parent::tearDown();
	}

	public function test_a_request_without_a_declaration_still_hashes_as_schema_v1(): void {
		// Every receipt issued before the consumer self-declaration existed was hashed as v1. Those
		// receipts must keep recomputing to their stored hash, so the payload shape may not move.
		$result = $this->build_receipt( '' );

		$this->assertSame( 'recesso-digitale/1', $result['payload']['receipt_schema'] );
		$this->assertArrayNotHasKey( 'consumer_declaration', $result['payload'] );
	}

	public function test_a_request_carrying_a_declaration_hashes_as_schema_v2(): void {
		$declaration = 'I confirm that I bought as a consumer.';
		$result      = $this->build_receipt( $declaration );

		$this->assertSame( 'recesso-digitale/2', $result['payload']['receipt_schema'] );
		$this->assertSame( $declaration, $result['payload']['consumer_declaration'] );
	}

	public function test_the_declaration_changes_the_hash_it_is_recorded_in(): void {
		$without = $this->build_receipt( '' );
		$with    = $this->build_receipt( 'I confirm that I bought as a consumer.' );

		$this->assertNotSame(
			$without['hash'],
			$with['hash'],
			'The declaration is part of the evidence, so it must be covered by the tamper-evident hash.'
		);
	}

	/**
	 * Create an order, declare and confirm a withdrawal on it, and build its receipt.
	 *
	 * @param string $declaration The consumer self-declaration to record, or '' for none.
	 *
	 * @return array{payload: array<string, mixed>, hash: string}
	 */
	private function build_receipt( string $declaration ): array {
		$order          = $this->make_order();
		$this->orders[] = $order->get_id();

		$service = $this->container->withdrawal_service();
		$request = $service->create_declaration(
			$order,
			array(
				'consumer_name'        => 'Mario Rossi',
				'contract_reference'   => '#' . $order->get_id(),
				'confirmation_email'   => 'mario@example.org',
				'consumer_declaration' => $declaration,
			),
			null
		);
		$service->confirm( $request->id, 'consumer' );

		$stored = $this->container->request_repository()->find_by_id( $request->id );
		$this->assertNotNull( $stored );
		$this->assertSame( $declaration, $stored->consumer_declaration, 'The declaration is persisted verbatim.' );

		$built = $this->container->receipt_builder()->build( $stored, $order );
		if ( isset( $built['path'] ) && is_file( (string) $built['path'] ) ) {
			wp_delete_file( (string) $built['path'] );
		}

		return array(
			'payload' => is_array( $built['payload'] ?? null ) ? $built['payload'] : array(),
			'hash'    => (string) ( $built['hash'] ?? '' ),
		);
	}

	/**
	 * A completed, withdrawable order with one line.
	 */
	private function make_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Receipt payload fixture' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_billing_email( 'mario@example.org' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}
}
