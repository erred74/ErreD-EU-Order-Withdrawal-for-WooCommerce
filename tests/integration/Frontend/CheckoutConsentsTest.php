<?php
/**
 * Integration tests for the checkout consents.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Domain\Eligibility\EligibilityEngine;
use Recesso54bis\Frontend\CheckoutConsents;
use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\Settings;
use Recesso54bis\Support\SystemClock;

final class CheckoutConsentsTest extends TestCase {

	/**
	 * Product ids created by a test, removed in tearDown.
	 *
	 * @var int[]
	 */
	private array $products = array();

	protected function setUp(): void {
		parent::setUp();
		update_option( Settings::OPT_CONSENT_DIGITAL_ENABLED, '1' );
		update_option( Settings::OPT_CONSENT_SERVICE_ENABLED, '1' );
	}

	protected function tearDown(): void {
		delete_option( Settings::OPT_CONSENT_DIGITAL_ENABLED );
		delete_option( Settings::OPT_CONSENT_SERVICE_ENABLED );
		delete_option( Settings::OPT_CONSENTS_CONDITIONAL );

		if ( function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			WC()->cart->empty_cart();
		}

		foreach ( $this->products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		$this->products = array();

		$_POST = array();
		parent::tearDown();
	}

	public function test_save_records_given_and_declined_consents_with_timestamp(): void {
		$order = wc_create_order();
		// The consumer ticked the digital-content consent but not the service one.
		$_POST = array( 'recesso_dig_consent_digital' => '1' );

		$this->consents()->save( $order );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( '1', (string) $reloaded->get_meta( Settings::META_CONSENT_DIGITAL ) );
		$this->assertNotEmpty( $reloaded->get_meta( Settings::META_CONSENT_DIGITAL_AT ), 'A given consent records when it was made.' );
		$this->assertSame( '0', (string) $reloaded->get_meta( Settings::META_CONSENT_SERVICE ) );
		$this->assertSame( '', (string) $reloaded->get_meta( Settings::META_CONSENT_SERVICE_AT ), 'A declined consent records no timestamp.' );

		$order->delete( true );
	}

	public function test_enabled_consents_show_on_every_checkout_by_default(): void {
		$this->fill_cart( $this->product( '' ) );

		$consents = $this->consents();

		$this->assertTrue( $consents->show_digital(), 'Without conditional mode an enabled consent always applies.' );
		$this->assertTrue( $consents->show_service(), 'Without conditional mode an enabled consent always applies.' );
	}

	public function test_conditional_mode_shows_only_the_consent_the_cart_calls_for(): void {
		update_option( Settings::OPT_CONSENTS_CONDITIONAL, '1' );
		$this->fill_cart( $this->product( Settings::STATUS_ART16M_DIGITAL ) );

		$consents = $this->consents();

		$this->assertTrue( $consents->show_digital(), 'A cart holding an art. 16(m) product asks for the digital-content consent.' );
		$this->assertFalse( $consents->show_service(), 'The service consent does not apply to a cart with no art. 14(4)(a) product.' );
	}

	public function test_conditional_mode_shows_nothing_for_an_unclassified_cart(): void {
		update_option( Settings::OPT_CONSENTS_CONDITIONAL, '1' );
		$this->fill_cart( $this->product( '' ) );

		$consents = $this->consents();

		$this->assertFalse( $consents->show_digital() );
		$this->assertFalse( $consents->show_service() );
	}

	public function test_conditional_mode_does_not_record_a_consent_it_never_offered(): void {
		update_option( Settings::OPT_CONSENTS_CONDITIONAL, '1' );
		$this->fill_cart( $this->product( '' ) );

		// A stale or forged post value must not produce evidence of a consent the form never showed.
		$_POST = array( 'recesso_dig_consent_digital' => '1' );

		$order = wc_create_order();
		$this->consents()->save( $order );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( '', (string) $reloaded->get_meta( Settings::META_CONSENT_DIGITAL ), 'A consent that was never offered is never recorded.' );

		$order->delete( true );
	}

	/**
	 * A consents provider wired the way the plugin wires it.
	 */
	private function consents(): CheckoutConsents {
		$settings = new Settings();

		return new CheckoutConsents(
			$settings,
			new SystemClock(),
			new EligibilityAdapter( new EligibilityEngine(), $settings, new RequestRepository(), new SystemClock() )
		);
	}

	/**
	 * Create a published product carrying the given withdrawal classification.
	 *
	 * @param string $status A Settings::STATUS_* value, or '' to leave it unclassified.
	 */
	private function product( string $status ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Consent fixture' );
		$product->set_regular_price( '10' );
		$product->set_status( 'publish' );
		$product_id = (int) $product->save();

		if ( '' !== $status ) {
			update_post_meta( $product_id, Settings::META_PRODUCT_STATUS, $status );
		}

		$this->products[] = $product_id;

		return $product_id;
	}

	/**
	 * Put a single unit of the product in the customer's cart.
	 *
	 * @param int $product_id Product id.
	 */
	private function fill_cart( int $product_id ): void {
		if ( ! WC()->cart instanceof \WC_Cart ) {
			wc_load_cart();
		}

		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product_id );
	}
}
