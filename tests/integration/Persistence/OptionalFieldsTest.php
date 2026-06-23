<?php
/**
 * Integration tests for the optional refund IBAN and withdrawal reason.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class OptionalFieldsTest extends TestCase {

	private Container $container;
	private int $order_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		$this->container = new Container();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), $this->order_id ) );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		parent::tearDown();
	}

	public function test_iban_and_reason_persist_and_reach_the_receipt_payload(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Optional Fields Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		$request = $this->container->withdrawal_service()->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'mario@example.org',
				'refund_iban'        => 'IT60X0542811101000000123456',
				'withdrawal_reason'  => 'Changed my mind',
			),
			null
		);

		$record = $this->container->request_repository()->find_by_id( $request->id );
		$this->assertNotNull( $record );
		$this->assertSame( 'IT60X0542811101000000123456', $record->refund_iban );
		$this->assertSame( 'Changed my mind', $record->withdrawal_reason );

		// The optional fields are carried into the durable receipt payload.
		$builder = $this->container->receipt_builder();
		$method  = new \ReflectionMethod( $builder, 'canonical_payload' );
		$method->setAccessible( true );
		$payload = $method->invoke( $builder, $record, $order );

		$this->assertSame( 'IT60X0542811101000000123456', $payload['refund_iban'] );
		$this->assertSame( 'Changed my mind', $payload['withdrawal_reason'] );
	}
}
