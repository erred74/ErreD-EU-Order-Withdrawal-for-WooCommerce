<?php
/**
 * Integration tests for the GDPR exporter and eraser.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Privacy;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Privacy\PrivacyHooks;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class PrivacyHooksTest extends TestCase {

	private Container $container;
	private int $order_id   = 0;
	private int $request_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );

		$this->container = new Container();

		$product = new \WC_Product_Simple();
		$product->set_name( 'Privacy Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		$service = $this->container->withdrawal_service();
		$request = $service->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Privacy Person',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'priv@example.org',
			),
			null
		);
		$service->confirm( $request->id, 'consumer' );
		$this->request_id = $request->id;
	}

	protected function tearDown(): void {
		$record = $this->container->request_repository()->find_by_id( $this->request_id );
		if ( null !== $record && null !== $record->receipt_path && is_file( $record->receipt_path ) ) {
			wp_delete_file( $record->receipt_path );
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		parent::tearDown();
	}

	public function test_export_then_erase_anonymises_but_retains_the_legal_record(): void {
		$privacy = new PrivacyHooks( $this->container->request_repository() );

		$export = $privacy->export( 'priv@example.org' );
		$this->assertTrue( $export['done'] );
		$this->assertNotEmpty( $export['data'], 'The exporter returns the consumer data.' );

		$erase = $privacy->erase( 'priv@example.org' );
		$this->assertTrue( $erase['items_removed'], 'The eraser anonymised at least one record.' );
		$this->assertTrue( $erase['items_retained'], 'The legal record is retained.' );

		// The PII is gone from a re-export, but the legal facts remain on the row.
		$this->assertEmpty( $privacy->export( 'priv@example.org' )['data'] );

		$record = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $record );
		$this->assertSame( '', $record->consumer_name, 'The name was anonymised.' );
		$this->assertSame( '', $record->confirmation_email, 'The email was anonymised.' );
		$this->assertNotNull( $record->confirmed_at_gmt, 'The legal confirmation timestamp is retained.' );
		$this->assertNotNull( $record->receipt_hash, 'The tamper-evident receipt hash is retained.' );
	}
}
