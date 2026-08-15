<?php
/**
 * Integration tests for the My Account "Right of withdrawal" tab.
 *
 * Regression: the tab listed only orders that were *eligible*. An order stops being eligible the
 * moment a request claims it, so sending a withdrawal made the order disappear and left the customer
 * reading "None of your orders is currently eligible for withdrawal" — the exact opposite of what had
 * just happened, and no way to see the request, its status or its receipt.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Frontend\AccountEndpoint;
use Recesso54bis\Frontend\FlowPage;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class AccountEndpointTest extends TestCase {

	private Container $container;
	private int $customer_id = 0;
	private int $order_id    = 0;
	private int $request_id  = 0;
	private int $page_id     = 0;

	/**
	 * The flow page option before the test.
	 *
	 * @var mixed
	 */
	private $previous_page;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );

		$this->container     = new Container();
		$this->previous_page = get_option( FlowPage::OPTION, 0 );

		// A published page hosting the flow, so the withdrawal links are offered at all.
		$this->page_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Withdrawal (account test)',
				'post_content' => FlowPage::DEFAULT_CONTENT,
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
		update_option( FlowPage::OPTION, $this->page_id );

		$this->customer_id = (int) wp_insert_user(
			array(
				'user_login' => 'recesso_account_customer_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'customer',
			)
		);

		$product = new \WC_Product_Simple();
		$product->set_name( 'Account tab fixture' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_customer_id( $this->customer_id );
		$order->set_billing_email( 'account-tab@example.test' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();
	}

	protected function tearDown(): void {
		global $wpdb;

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

		wp_delete_post( $this->page_id, true );
		update_option( FlowPage::OPTION, $this->previous_page );

		if ( $this->customer_id > 0 ) {
			wp_delete_user( $this->customer_id );
		}

		delete_option( Settings::OPT_DEFAULT_POLICY );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	public function test_an_eligible_order_offers_the_mandated_control(): void {
		wp_set_current_user( $this->customer_id );

		$html = $this->render();

		$this->assertStringContainsString( 'recedere dal contratto qui', $html, 'An order still within its window offers the statutory control.' );
		$this->assertStringNotContainsString(
			'None of your orders is currently eligible',
			$html,
			'Sanity: the empty state must not show when there is a row.'
		);
	}

	public function test_an_order_with_a_request_stays_listed_with_its_status(): void {
		$this->create_confirmed_request();
		wp_set_current_user( $this->customer_id );

		$html = $this->render();

		$this->assertStringNotContainsString(
			'None of your orders is currently eligible',
			$html,
			'The order is claimed by the request, so it is no longer eligible — but it must not vanish.'
		);
		$this->assertStringContainsString(
			(string) $this->order_number(),
			$html,
			'The order that was withdrawn from must still be listed.'
		);
		$this->assertStringContainsString( 'Registered', $html, 'The customer must be told the state of their request.' );
	}

	public function test_the_receipt_is_offered_with_its_verification_code(): void {
		$this->create_confirmed_request();
		wp_set_current_user( $this->customer_id );

		$request = $this->container->request_repository()->find_by_id( $this->request_id );
		$this->assertNotNull( $request );
		$this->assertTrue( $request->has_receipt(), 'Sanity: confirming the request produces the durable receipt.' );

		$html = $this->render();

		$this->assertStringContainsString( (string) $request->receipt_hash, $html, 'The receipt verification code is shown.' );
		$this->assertStringContainsString( 'action=recesso_dig_receipt', $html, 'A link to the receipt PDF is offered.' );
		$this->assertStringContainsString( 'request=' . $this->request_id, $html );
	}

	public function test_the_receipt_link_needs_no_token_because_ownership_authorises_it(): void {
		$this->create_confirmed_request();
		wp_set_current_user( $this->customer_id );

		$html = $this->render();
		$this->assertStringNotContainsString(
			'token=',
			$html,
			'The account link deliberately carries no token: it must keep working after the emailed one expires.'
		);

		// That is only safe because the download endpoint authorises the order's owner directly.
		$gate = $this->container->permission_gate();
		$this->assertTrue(
			$gate->can_act_on_order( $this->order_id, '', time() ),
			'The logged-in owner is authorised with no token at all.'
		);

		$stranger = (int) wp_insert_user(
			array(
				'user_login' => 'recesso_account_stranger_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'customer',
			)
		);
		wp_set_current_user( $stranger );

		$this->assertFalse(
			$gate->can_act_on_order( $this->order_id, '', time() ),
			'A logged-in customer who does not own the order must be refused — this is what keeps the tokenless link safe.'
		);

		wp_delete_user( $stranger );
	}

	public function test_the_merchants_decision_note_reaches_the_customer(): void {
		$this->create_confirmed_request();

		$this->container->log_repository()->record(
			$this->request_id,
			LogRepository::EVENT_STATUS_CHANGE,
			'admin:1',
			array(
				'to'     => 'rejected',
				'reason' => 'The seal on the box was broken.',
			)
		);

		wp_set_current_user( $this->customer_id );
		$html = $this->render();

		$this->assertStringContainsString(
			'The seal on the box was broken.',
			$html,
			'The note the merchant wrote when deciding is read back from the append-only log.'
		);
	}

	public function test_a_customer_with_nothing_to_show_sees_the_empty_state(): void {
		$stranger = (int) wp_insert_user(
			array(
				'user_login' => 'recesso_account_empty_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password( 24 ),
				'role'       => 'customer',
			)
		);
		wp_set_current_user( $stranger );

		$this->assertStringContainsString( 'None of your orders is currently eligible', $this->render() );

		wp_delete_user( $stranger );
	}

	/**
	 * Render the tab and return its markup.
	 *
	 * Deliberately built from a fresh container: the eligibility adapter memoises per order for the
	 * life of the request (by design — the tab evaluates every order in one page load), so reusing the
	 * fixture's container would answer from a result computed before the request claimed the order and
	 * quietly stop the test from exercising the case it exists for.
	 */
	private function render(): string {
		$container = new Container();

		$endpoint = new AccountEndpoint(
			$container->eligibility_adapter(),
			$container->flow_urls(),
			$container->settings(),
			$container->request_repository(),
			$container->log_repository()
		);

		ob_start();
		$endpoint->render();

		return (string) ob_get_clean();
	}

	/**
	 * Create and confirm a withdrawal request for the fixture order.
	 */
	private function create_confirmed_request(): void {
		$order   = wc_get_order( $this->order_id );
		$service = $this->container->withdrawal_service();

		$request = $service->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'account-tab@example.test',
			),
			null
		);
		$service->confirm( $request->id, 'consumer' );

		$this->request_id = $request->id;
	}

	/**
	 * The fixture order's display number.
	 */
	private function order_number(): string {
		$order = wc_get_order( $this->order_id );

		return $order instanceof \WC_Order ? $order->get_order_number() : '';
	}
}
