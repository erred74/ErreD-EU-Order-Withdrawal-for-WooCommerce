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
		delete_option( Settings::OPT_CONSENT_SERVICE_REQUIRED );
		delete_option( Settings::OPT_CONSENTS_CONDITIONAL );

		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}

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

	/*
	 * The art. 14(4)(a) consent used to be unconditionally non-blocking, hardcoded in three places.
	 * It is now a merchant setting — still off by default, because asking for the service to start
	 * early is the consumer's choice to make.
	 */

	public function test_the_service_consent_does_not_block_the_order_by_default(): void {
		$this->fill_cart( $this->product( '' ) );

		$this->assertFalse(
			$this->consents()->is_required( CheckoutConsents::CONSENT_SERVICE ),
			'Declining early performance must not stop someone buying, unless the merchant says otherwise.'
		);
	}

	public function test_an_unticked_required_service_consent_blocks_the_order(): void {
		update_option( Settings::OPT_CONSENT_SERVICE_REQUIRED, '1' );
		$this->fill_cart( $this->product( '' ) );

		$consents = $this->consents();
		$this->assertTrue( $consents->is_required( CheckoutConsents::CONSENT_SERVICE ) );

		$_POST = array();
		$consents->validate();

		$this->assertTrue( wc_notice_count( 'error' ) > 0, 'A required consent left unticked stops the order.' );
		wc_clear_notices();

		// ...and ticking it lets the order through.
		$_POST = array( 'recesso_dig_consent_service' => '1' );
		$consents->validate();

		$this->assertSame( 0, wc_notice_count( 'error' ), 'A required consent that was given raises nothing.' );

		delete_option( Settings::OPT_CONSENT_SERVICE_REQUIRED );
	}

	public function test_a_consent_that_is_not_offered_is_never_required(): void {
		// Requiring a consent the cart never showed would be an order nobody could ever place.
		update_option( Settings::OPT_CONSENTS_CONDITIONAL, '1' );
		update_option( Settings::OPT_CONSENT_SERVICE_REQUIRED, '1' );
		$this->fill_cart( $this->product( '' ) );

		$_POST = array();
		$this->consents()->validate();

		$this->assertSame( 0, wc_notice_count( 'error' ), 'An unclassified cart is not asked for the consent, so it cannot be blocked by it.' );

		delete_option( Settings::OPT_CONSENT_SERVICE_REQUIRED );
	}

	/*
	 * The four public filters. src/Frontend/ exposed none before this release.
	 */

	public function test_the_applies_filter_can_suppress_a_consent(): void {
		$this->fill_cart( $this->product( '' ) );

		$filter = static function ( bool $applies, string $consent ): bool {
			return CheckoutConsents::CONSENT_SERVICE === $consent ? false : $applies;
		};
		add_filter( 'recesso_dig_consent_applies', $filter, 10, 2 );

		$consents = $this->consents();

		$this->assertFalse( $consents->show_service(), 'An integrator can decide a consent does not apply to this cart.' );
		$this->assertTrue( $consents->show_digital(), 'The other consent is untouched.' );

		remove_filter( 'recesso_dig_consent_applies', $filter, 10 );
	}

	public function test_the_required_filter_can_make_a_consent_blocking(): void {
		$this->fill_cart( $this->product( '' ) );

		$filter = static function ( bool $required, string $consent ): bool {
			return CheckoutConsents::CONSENT_SERVICE === $consent ? true : $required;
		};
		add_filter( 'recesso_dig_consent_required', $filter, 10, 2 );

		$this->assertTrue(
			$this->consents()->is_required( CheckoutConsents::CONSENT_SERVICE ),
			'The filter is the last word on whether a consent blocks the order.'
		);

		remove_filter( 'recesso_dig_consent_required', $filter, 10 );
	}

	public function test_the_render_hook_can_be_moved_or_suppressed(): void {
		$moved = static fn(): string => 'woocommerce_checkout_before_terms_and_conditions';
		add_filter( 'recesso_dig_consent_render_hook', $moved );

		$consents = $this->consents();
		$consents->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_before_terms_and_conditions', array( $consents, 'render' ) ),
			'The checkboxes render on the hook the integrator chose.'
		);
		$this->assertFalse(
			has_action( 'woocommerce_review_order_before_submit', array( $consents, 'render' ) ),
			'...and not on the default one.'
		);

		remove_action( 'woocommerce_checkout_before_terms_and_conditions', array( $consents, 'render' ), 10 );
		remove_action( 'woocommerce_checkout_process', array( $consents, 'validate' ), 10 );
		remove_action( 'woocommerce_checkout_create_order', array( $consents, 'save' ), 10 );
		remove_filter( 'recesso_dig_consent_render_hook', $moved );

		// An empty hook suppresses the render entirely, for merchants placing the boxes themselves.
		$suppress = static fn(): string => '';
		add_filter( 'recesso_dig_consent_render_hook', $suppress );

		$other = $this->consents();
		$other->register();

		$this->assertFalse(
			has_action( 'woocommerce_review_order_before_submit', array( $other, 'render' ) ),
			'An empty hook name renders nothing anywhere.'
		);

		remove_action( 'woocommerce_checkout_process', array( $other, 'validate' ), 10 );
		remove_action( 'woocommerce_checkout_create_order', array( $other, 'save' ), 10 );
		remove_filter( 'recesso_dig_consent_render_hook', $suppress );
	}

	public function test_the_render_priority_can_be_changed(): void {
		$priority = static fn(): int => 42;
		add_filter( 'recesso_dig_consent_render_priority', $priority );

		$consents = $this->consents();
		$consents->register();

		$this->assertSame(
			42,
			has_action( 'woocommerce_review_order_before_submit', array( $consents, 'render' ) ),
			'The integrator controls where among other checkout output the consents land.'
		);

		remove_action( 'woocommerce_review_order_before_submit', array( $consents, 'render' ), 42 );
		remove_action( 'woocommerce_checkout_process', array( $consents, 'validate' ), 10 );
		remove_action( 'woocommerce_checkout_create_order', array( $consents, 'save' ), 10 );
		remove_filter( 'recesso_dig_consent_render_priority', $priority );
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
