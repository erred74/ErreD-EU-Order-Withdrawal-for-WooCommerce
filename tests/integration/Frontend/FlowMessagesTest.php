<?php
/**
 * Integration tests for the flow's message screen and its repeat-visit wording.
 *
 * Two defects are pinned down here.
 *
 * 1. The message screen printed whatever text the URL carried. Escaped, so never an injection, but
 *    it let anyone hand out a link that displayed wording of their choosing inside the store's own
 *    withdrawal page — on a page about refunds and legal deadlines, a ready-made phishing surface.
 *    Only codes the plugin defines may travel in the URL now.
 *
 * 2. Returning to step two on an already-confirmed request re-rendered the plain success screen, so
 *    a second visit was indistinguishable from the first and left the consumer wondering whether
 *    they had just sent the withdrawal twice.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Frontend\FlowUrls;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class FlowMessagesTest extends TestCase {

	private Container $container;
	private int $customer_id = 0;
	private int $order_id    = 0;
	private int $request_id  = 0;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );

		$this->container = new Container();
		$_GET            = array();
	}

	protected function tearDown(): void {
		global $wpdb;

		$_GET = array();

		if ( $this->order_id > 0 ) {
			if ( $this->request_id > 0 ) {
				$record = $this->container->request_repository()->find_by_id( $this->request_id );
				if ( null !== $record && null !== $record->receipt_path && is_file( $record->receipt_path ) ) {
					wp_delete_file( $record->receipt_path );
				}
			}

			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );

			$order = wc_get_order( $this->order_id );
			if ( $order instanceof \WC_Order ) {
				$order->delete( true );
			}
		}

		if ( $this->customer_id > 0 ) {
			wp_delete_user( $this->customer_id );
		}

		delete_option( Settings::OPT_DEFAULT_POLICY );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_arbitrary_text_in_the_url_renders_nothing(): void {
		$_GET[ FlowUrls::QV_STEP ] = 'message';
		$_GET['recesso_dig_msg']   = 'Call 555-0100 now to claim your refund';

		$html = $this->container->flow_controller()->render();

		$this->assertStringNotContainsString( '555-0100', $html, 'Wording supplied by the URL must never reach the page.' );
		$this->assertStringNotContainsString( 'claim your refund', $html );
	}

	public function test_an_unknown_code_renders_nothing(): void {
		$_GET[ FlowUrls::QV_STEP ] = 'message';
		$_GET['recesso_dig_msg']   = 'not_a_real_code';

		$this->assertSame( '', trim( $this->container->flow_controller()->render() ) );
	}

	public function test_a_known_code_renders_its_own_wording(): void {
		$_GET[ FlowUrls::QV_STEP ] = 'message';
		$_GET['recesso_dig_msg']   = 'duplicate';

		$this->assertStringContainsString(
			'already in progress',
			$this->container->flow_controller()->render(),
			'A code the plugin defines resolves to the plugin\'s own text.'
		);
	}

	public function test_a_reason_code_resolves_only_for_a_real_reason(): void {
		$_GET[ FlowUrls::QV_STEP ] = 'message';
		$_GET['recesso_dig_msg']   = 'reason_window_closed';

		$this->assertStringContainsString(
			'withdrawal period has ended',
			$this->container->flow_controller()->render(),
			'A reason the domain defines resolves to its label.'
		);

		$_GET['recesso_dig_msg'] = 'reason_made_up_by_an_attacker';

		$this->assertSame(
			'',
			trim( $this->container->flow_controller()->render() ),
			'A reason the domain does not define resolves to nothing.'
		);
	}

	public function test_an_expired_link_offers_the_way_to_get_a_new_one(): void {
		$_GET[ FlowUrls::QV_STEP ] = 'message';
		$_GET['recesso_dig_msg']   = 'link_expired';

		$html = $this->container->flow_controller()->render();

		$this->assertStringContainsString( 'no longer valid', $html );
		$this->assertStringContainsString(
			'recesso_dig_lookup',
			$html,
			'A dead link must come with the lookup form, not leave the consumer at a dead end.'
		);
	}

	public function test_revisiting_a_confirmed_request_says_it_was_already_sent(): void {
		$this->create_confirmed_request();
		wp_set_current_user( $this->customer_id );

		$_GET[ FlowUrls::QV_STEP ] = FlowUrls::STEP_CONFIRM;
		$_GET[ FlowUrls::QV_ID ]   = $this->request_id;

		$html = ( new Container() )->flow_controller()->render();

		$this->assertStringContainsString(
			'already sent this withdrawal',
			$html,
			'A return visit must be told the withdrawal is already on record.'
		);
		$this->assertStringNotContainsString(
			'Confirm your withdrawal',
			$html,
			'The confirmation form must not be offered again for a request already confirmed.'
		);
	}

	/**
	 * Create a completed order owned by a customer, with a confirmed withdrawal request.
	 */
	private function create_confirmed_request(): void {
		$this->customer_id = (int) wp_insert_user(
			array(
				'user_login' => 'recesso_flow_msg_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'customer',
			)
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Flow message fixture' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_customer_id( $this->customer_id );
		$order->set_billing_email( 'flow-msg@example.test' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		$service = $this->container->withdrawal_service();
		$request = $service->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'flow-msg@example.test',
			),
			null
		);
		$service->confirm( $request->id, 'consumer' );

		$this->request_id = $request->id;
	}
}
