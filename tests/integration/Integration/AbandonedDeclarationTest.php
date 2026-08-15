<?php
/**
 * Integration tests for replacing an unconfirmed declaration.
 *
 * Regression: a declaration that was never confirmed still reserves the units it names. Eligibility
 * was evaluated with that reservation in place and *then* the abandoned draft was discarded — so on a
 * single-unit order the check always refused first and the discard was never reached. A consumer who
 * closed the page before confirming could not start again: the withdrawal function, which the law
 * requires to stay available for the whole period, was shut for that order until the merchant
 * intervened.
 *
 * The same ordering is what makes the "Edit your details" link on the review step work.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Integration;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class AbandonedDeclarationTest extends TestCase {

	private Container $container;
	private int $order_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );

		$this->container = new Container();

		$product = new \WC_Product_Simple();
		$product->set_name( 'Abandoned declaration fixture' );
		$product->set_regular_price( '10' );
		$product->save();

		// One line, one unit: the case where the draft's reservation consumes everything.
		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_billing_email( 'abandoned@example.test' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();
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

	public function test_a_consumer_can_replace_a_declaration_they_never_confirmed(): void {
		$first = $this->declare( 'Prima Versione' );
		$this->assertSame( RequestStatus::PENDING, $first->status, 'Sanity: step one alone leaves the request pending.' );

		// A fresh container, because the eligibility adapter memoises per request: this is a new page
		// load, as it would be for a consumer coming back to the form.
		$second = $this->declare( 'Seconda Versione', new Container() );

		$this->assertNotSame( $first->id, $second->id, 'The amended declaration is a new record.' );
		$this->assertSame( 'Seconda Versione', $second->consumer_name );

		$this->assertNull(
			$this->container->request_repository()->find_by_id( $first->id ),
			'The unconfirmed draft is discarded, not left behind holding a reservation.'
		);
	}

	public function test_replacing_a_draft_leaves_exactly_one_reservation(): void {
		$this->declare( 'Prima Versione' );
		$this->declare( 'Seconda Versione', new Container() );

		$claims = $this->container->request_repository()->claimed_quantities( $this->order_id );

		$this->assertCount( 1, $claims, 'One line is reserved.' );
		$this->assertSame(
			1,
			(int) reset( $claims ),
			'One unit is reserved: the replaced draft released its own before the new one took it.'
		);
	}

	public function test_a_confirmed_request_is_never_replaced(): void {
		$first = $this->declare( 'Prima Versione' );
		$this->container->withdrawal_service()->confirm( $first->id, 'consumer' );

		// Confirmed is a legal record. A second declaration must be refused, not silently swallow it.
		$this->expectException( \Recesso54bis\Integration\NotEligibleException::class );
		$this->declare( 'Seconda Versione', new Container() );
	}

	/**
	 * Submit step one for the fixture order.
	 *
	 * @param string         $name      Consumer name.
	 * @param Container|null $container Container to use (defaults to the fixture's).
	 */
	private function declare( string $name, ?Container $container = null ): \Recesso54bis\Domain\WithdrawalRequest {
		$container = $container ?? $this->container;

		return $container->withdrawal_service()->create_declaration(
			wc_get_order( $this->order_id ),
			array(
				'consumer_name'      => $name,
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'abandoned@example.test',
			),
			null
		);
	}
}
