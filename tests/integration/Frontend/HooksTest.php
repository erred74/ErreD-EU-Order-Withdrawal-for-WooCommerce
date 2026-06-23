<?php
/**
 * Integration tests for the frontend entry points.
 *
 * Verifies that guest-facing surfaces (order emails, order-received page) expose a withdrawal link
 * carrying a signed token that actually authorises the flow — the path a guest-checkout customer
 * relies on, since a bare order id is never sufficient.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Frontend\FlowUrls;
use Recesso54bis\Frontend\Hooks;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class HooksTest extends TestCase {

	private int $order_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		parent::tearDown();
	}

	private function make_completed_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Eligible Goods' );
		$product->set_regular_price( '20' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_billing_email( 'guest@example.test' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		$this->order_id = $order->get_id();

		return $order;
	}

	public function test_email_button_emits_a_token_link_that_authorises_a_guest(): void {
		$container = new Container();
		$order     = $this->make_completed_order();
		$hooks     = new Hooks( $container->eligibility_adapter(), $container->flow_urls(), $container->settings() );

		// As a guest (no logged-in user) the ordinary ownership check must not authorise.
		wp_set_current_user( 0 );
		$this->assertFalse(
			$container->permission_gate()->can_act_on_order( $this->order_id, '', time() ),
			'A bare order id must not authorise a guest.'
		);

		ob_start();
		$hooks->email_button( $order, false, false, null );
		$html = (string) ob_get_clean();

		// The email exposes the full «Right of withdrawal» block: heading, explanatory text, the mandated
		// label and a tokenised entry link.
		$this->assertStringContainsString( 'Right of withdrawal', $html );
		$this->assertStringContainsString( 'without giving any reason', $html );
		$this->assertStringContainsString( 'recedere dal contratto qui', $html );
		$this->assertMatchesRegularExpression( '/recesso_dig_token=/', $html );

		// Extract the token and confirm it authorises the flow for this order.
		$parsed = array();
		preg_match( '/' . FlowUrls::QV_TOKEN . '=([^"&]+)/', $html, $parsed );
		$token = isset( $parsed[1] ) ? urldecode( $parsed[1] ) : '';

		$this->assertNotSame( '', $token );
		$this->assertTrue(
			$container->permission_gate()->can_act_on_order( $this->order_id, $token, time() ),
			'The emailed token must authorise the guest withdrawal flow.'
		);
	}

	public function test_email_button_is_skipped_for_admin_copy(): void {
		$container = new Container();
		$order     = $this->make_completed_order();
		$hooks     = new Hooks( $container->eligibility_adapter(), $container->flow_urls(), $container->settings() );

		ob_start();
		$hooks->email_button( $order, true, false, null );
		$this->assertSame( '', (string) ob_get_clean() );
	}
}
