<?php
/**
 * Integration tests for the server-rendered withdrawal flow.
 *
 * Verifies the two-step screens render correctly (the path that must work without JavaScript),
 * including the mandated wording and authorisation gating.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Frontend\FlowController;
use Recesso54bis\Frontend\FlowUrls;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\OrderToken;
use Recesso54bis\Support\Settings;

final class FlowControllerTest extends TestCase {

	private FlowController $flow;
	private int $order_id = 0;
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		wp_set_current_user( 0 );

		$container      = new Container();
		$this->flow     = $container->flow_controller();
		$order          = $this->make_order();
		$this->order_id = $order->get_id();
		$this->token    = ( new OrderToken() )->issue( $this->order_id, time() + 3600 );
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
		$_GET = array();
		parent::tearDown();
	}

	private function make_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Recesso Flow Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_billing_first_name( 'Mario' );
		$order->set_billing_last_name( 'Rossi' );
		$order->set_billing_email( 'mario@example.org' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}

	public function test_declaration_renders_for_authorised_eligible_order(): void {
		$_GET = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DECLARE,
			FlowUrls::QV_ORDER => (string) $this->order_id,
			FlowUrls::QV_TOKEN => $this->token,
		);

		$html = $this->flow->render();

		$this->assertStringContainsString( 'name="consumer_name"', $html );
		$this->assertStringContainsString( 'recesso_dig_declare', $html );
		$this->assertStringContainsString( 'mario@example.org', $html );
		// Each eligible line is selectable with a checkbox carrying the line id.
		$this->assertStringContainsString( 'name="requested_lines[]"', $html );
		// A single-unit line shows no quantity selector (selection implies one unit).
		$this->assertStringNotContainsString( 'name="requested_qty[', $html );
	}

	public function test_declaration_shows_quantity_selector_only_for_multi_unit_lines(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Recesso Qty Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 3 );
		$order->set_billing_email( 'mario@example.org' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$order_id = $order->get_id();
		$token    = ( new OrderToken() )->issue( $order_id, time() + 3600 );

		$_GET = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DECLARE,
			FlowUrls::QV_ORDER => (string) $order_id,
			FlowUrls::QV_TOKEN => $token,
		);

		$html = $this->flow->render();

		try {
			$this->assertStringContainsString( 'name="requested_lines[]"', $html );
			// The line was ordered in 3 units, so the quantity selector appears with that maximum.
			$this->assertStringContainsString( 'name="requested_qty[', $html );
			$this->assertStringContainsString( 'max="3"', $html );
		} finally {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $order_id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), $order_id ) );
			$order->delete( true );
		}
	}

	public function test_declaration_denies_invalid_token(): void {
		$_GET = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DECLARE,
			FlowUrls::QV_ORDER => (string) $this->order_id,
			FlowUrls::QV_TOKEN => 'invalid-token',
		);

		$html = $this->flow->render();

		$this->assertStringNotContainsString( 'name="consumer_name"', $html );
		$this->assertStringContainsString( 'not valid or has expired', $html );
	}

	public function test_confirm_then_done_render(): void {
		$container = new Container();
		$request   = $container->withdrawal_service()->create_declaration(
			wc_get_order( $this->order_id ),
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'mario@example.org',
			),
			null
		);

		$_GET         = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_CONFIRM,
			FlowUrls::QV_ID    => (string) $request->id,
			FlowUrls::QV_TOKEN => $this->token,
		);
		$confirm_html = $this->flow->render();
		$this->assertStringContainsString( 'recesso_dig_confirm', $confirm_html );
		$this->assertStringContainsString( 'Confirm withdrawal', $confirm_html );

		// Confirm the request, then the done screen should render.
		$container->withdrawal_service()->confirm( $request->id, 'consumer' );

		$_GET      = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DONE,
			FlowUrls::QV_ID    => (string) $request->id,
			FlowUrls::QV_TOKEN => $this->token,
		);
		$done_html = $this->flow->render();
		$this->assertStringContainsString( 'has been recorded', $done_html );
	}
}
