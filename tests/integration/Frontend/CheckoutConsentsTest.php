<?php
/**
 * Integration tests for the checkout consents.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Frontend\CheckoutConsents;
use Recesso54bis\Support\Settings;
use Recesso54bis\Support\SystemClock;

final class CheckoutConsentsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( Settings::OPT_CONSENT_DIGITAL_ENABLED, '1' );
		update_option( Settings::OPT_CONSENT_SERVICE_ENABLED, '1' );
	}

	protected function tearDown(): void {
		delete_option( Settings::OPT_CONSENT_DIGITAL_ENABLED );
		delete_option( Settings::OPT_CONSENT_SERVICE_ENABLED );
		$_POST = array();
		parent::tearDown();
	}

	public function test_save_records_given_and_declined_consents_with_timestamp(): void {
		$order = wc_create_order();
		// The consumer ticked the digital-content consent but not the service one.
		$_POST = array( 'recesso_dig_consent_digital' => '1' );

		( new CheckoutConsents( new Settings(), new SystemClock() ) )->save( $order );
		$order->save();

		$reloaded = wc_get_order( $order->get_id() );

		$this->assertSame( '1', (string) $reloaded->get_meta( Settings::META_CONSENT_DIGITAL ) );
		$this->assertNotEmpty( $reloaded->get_meta( Settings::META_CONSENT_DIGITAL_AT ), 'A given consent records when it was made.' );
		$this->assertSame( '0', (string) $reloaded->get_meta( Settings::META_CONSENT_SERVICE ) );
		$this->assertSame( '', (string) $reloaded->get_meta( Settings::META_CONSENT_SERVICE_AT ), 'A declined consent records no timestamp.' );

		$order->delete( true );
	}
}
