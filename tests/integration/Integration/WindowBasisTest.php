<?php
/**
 * Integration tests for per-product withdrawal-period bases.
 *
 * The case that motivated them: a store selling T-shirts (goods, counted from delivery) and
 * football-school enrolments (a service, counted from the purchase) used to count both from one
 * store-wide event, so one of the two always got the wrong deadline — and in strict mode a
 * legitimate withdrawal could be refused.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Integration;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Admin\WithdrawalStatusFields;
use Recesso54bis\Container;
use Recesso54bis\Domain\Eligibility\WindowBasis;
use Recesso54bis\Email\WithdrawalAcknowledgementEmail;
use Recesso54bis\Frontend\Hooks;
use Recesso54bis\Integration\WindowBasisResolver;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Capabilities;
use Recesso54bis\Support\Settings;

final class WindowBasisTest extends TestCase {

	private int $order_id = 0;

	/**
	 * Products created by a test.
	 *
	 * @var int[]
	 */
	private array $products = array();

	/**
	 * Categories created by a test.
	 *
	 * @var int[]
	 */
	private array $terms = array();

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
		foreach ( $this->products as $product_id ) {
			wp_delete_post( $product_id, true );
		}
		foreach ( $this->terms as $term_id ) {
			wp_delete_term( $term_id, 'product_cat' );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		delete_option( Settings::OPT_START_TRIGGER );
		delete_option( Settings::OPT_ENFORCEMENT_MODE );
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create a product.
	 *
	 * @param string   $name       Product name.
	 * @param string   $basis      Stored period basis ('' for none).
	 * @param int[]    $categories Category ids.
	 */
	private function product( string $name, string $basis = '', array $categories = array() ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( '20' );
		$product->set_category_ids( $categories );
		if ( '' !== $basis ) {
			$product->update_meta_data( Settings::META_PRODUCT_WINDOW_BASIS, $basis );
		}
		$product->save();
		$this->products[] = $product->get_id();

		return $product;
	}

	/**
	 * Create a product category.
	 *
	 * @param string $basis Stored period basis ('' for none).
	 */
	private function category( string $basis = '' ): int {
		$term    = wp_insert_term( 'Cat ' . wp_generate_password( 6, false ), 'product_cat' );
		$term_id = (int) $term['term_id'];
		if ( '' !== $basis ) {
			update_term_meta( $term_id, Settings::META_TERM_WINDOW_BASIS, $basis );
		}
		$this->terms[] = $term_id;

		return $term_id;
	}

	/**
	 * A T-shirt and a course, bought 20 days ago, the order completed (delivered) 2 days ago.
	 *
	 * @return array{0: \WC_Order, 1: int, 2: int} The order, the T-shirt line id, the course line id.
	 */
	private function mixed_order(): array {
		$tee    = $this->product( 'T-shirt' );
		$course = $this->product( 'Football school', WindowBasis::CONCLUSION );

		$order       = wc_create_order();
		$tee_line    = $order->add_product( $tee, 1 );
		$course_line = $order->add_product( $course, 1 );
		$order->set_billing_email( 'parent@example.test' );
		$order->set_date_created( time() - ( 20 * DAY_IN_SECONDS ) );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() - ( 2 * DAY_IN_SECONDS ) );
		$order->save();
		$this->order_id = $order->get_id();

		return array( wc_get_order( $order->get_id() ), (int) $tee_line, (int) $course_line );
	}

	public function test_product_setting_wins_over_category_and_store(): void {
		update_option( Settings::OPT_START_TRIGGER, Settings::TRIGGER_DELIVERY );
		$category = $this->category( WindowBasis::DELIVERY );
		$product  = $this->product( 'Course', WindowBasis::CONCLUSION, array( $category ) );

		$this->assertSame( WindowBasis::CONCLUSION, ( new WindowBasisResolver( new Settings() ) )->for_product( $product->get_id() ) );
	}

	public function test_product_inherits_from_its_category(): void {
		$category = $this->category( WindowBasis::CONCLUSION );
		$product  = $this->product( 'Course', '', array( $category ) );

		$this->assertSame( WindowBasis::CONCLUSION, ( new WindowBasisResolver( new Settings() ) )->for_product( $product->get_id() ) );
	}

	public function test_unconfigured_product_follows_the_store_setting(): void {
		update_option( Settings::OPT_START_TRIGGER, Settings::TRIGGER_CONCLUSION );
		$product = $this->product( 'Anything' );

		$this->assertSame( WindowBasis::CONCLUSION, ( new WindowBasisResolver( new Settings() ) )->for_product( $product->get_id() ) );
	}

	public function test_disagreeing_categories_resolve_to_delivery(): void {
		// Delivery is never earlier than the purchase, so it is the reading that cannot cost the
		// consumer their right. Both orders of the categories must give the same answer.
		$service = $this->category( WindowBasis::CONCLUSION );
		$goods   = $this->category( WindowBasis::DELIVERY );

		$first  = $this->product( 'Ambiguous A', '', array( $service, $goods ) );
		$second = $this->product( 'Ambiguous B', '', array( $goods, $service ) );

		$resolver = new WindowBasisResolver( new Settings() );
		$this->assertSame( WindowBasis::DELIVERY, $resolver->for_product( $first->get_id() ) );
		$this->assertSame( WindowBasis::DELIVERY, $resolver->for_product( $second->get_id() ) );
	}

	public function test_an_unknown_stored_value_is_ignored(): void {
		update_option( Settings::OPT_START_TRIGGER, Settings::TRIGGER_DELIVERY );
		$product = $this->product( 'Tampered' );
		update_post_meta( $product->get_id(), Settings::META_PRODUCT_WINDOW_BASIS, 'tomorrow' );

		$this->assertSame( WindowBasis::DELIVERY, ( new WindowBasisResolver( new Settings() ) )->for_product( $product->get_id() ) );
	}

	public function test_each_line_of_a_mixed_order_gets_its_own_period(): void {
		list( $order, $tee_line, $course_line ) = $this->mixed_order();

		$result = ( new Container() )->eligibility_adapter()->for_order( $order );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( WindowBasis::DELIVERY, $result->line_windows[ $tee_line ]->basis );
		$this->assertSame( WindowBasis::CONCLUSION, $result->line_windows[ $course_line ]->basis );
		$this->assertTrue( $result->line_windows[ $tee_line ]->within_window, 'Delivered two days ago.' );
		$this->assertFalse( $result->line_windows[ $course_line ]->within_window, 'Bought twenty days ago.' );
		$this->assertSame(
			$order->get_date_created()->getTimestamp() + ( 14 * DAY_IN_SECONDS ),
			$result->line_windows[ $course_line ]->ends_at->getTimestamp()
		);
	}

	public function test_advisory_mode_still_offers_the_expired_service(): void {
		list( $order, $tee_line, $course_line ) = $this->mixed_order();

		$result = ( new Container() )->eligibility_adapter()->for_order( $order );

		$this->assertEqualsCanonicalizing( array( $tee_line, $course_line ), $result->eligible_line_ids );
	}

	public function test_strict_mode_withdraws_the_tee_but_not_the_expired_service(): void {
		update_option( Settings::OPT_ENFORCEMENT_MODE, Settings::ENFORCEMENT_STRICT );
		list( $order, $tee_line ) = $this->mixed_order();

		$result = ( new Container() )->eligibility_adapter()->for_order( $order );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( array( $tee_line ), $result->eligible_line_ids );
	}

	public function test_order_email_names_both_events_for_a_mixed_order(): void {
		list( $order ) = $this->mixed_order();
		$container     = new Container();
		$hooks         = new Hooks( $container->eligibility_adapter(), $container->flow_urls(), $container->settings() );

		ob_start();
		$hooks->email_button( $order, false, true, null );
		$text = (string) ob_get_clean();

		$this->assertStringContainsString( 'from receipt for goods, and from the conclusion of the contract for services', $text );
	}

	public function test_order_email_keeps_the_single_sentence_for_goods_only(): void {
		$tee   = $this->product( 'T-shirt' );
		$order = wc_create_order();
		$order->add_product( $tee, 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		$container = new Container();
		$hooks     = new Hooks( $container->eligibility_adapter(), $container->flow_urls(), $container->settings() );

		ob_start();
		$hooks->email_button( wc_get_order( $order->get_id() ), false, true, null );
		$text = (string) ob_get_clean();

		$this->assertStringContainsString( 'days from receipt to exercise your withdrawal right', $text );
	}

	public function test_acknowledgement_wording_follows_the_withdrawn_items(): void {
		$goods = WithdrawalAcknowledgementEmail::period_terms( array( WindowBasis::DELIVERY ), 14 );
		$svc   = WithdrawalAcknowledgementEmail::period_terms( array( WindowBasis::CONCLUSION ), 14 );
		$mixed = WithdrawalAcknowledgementEmail::period_terms( array( WindowBasis::DELIVERY, WindowBasis::CONCLUSION ), 14 );

		$this->assertStringContainsString( 'for goods, counted from delivery', $goods );
		$this->assertStringContainsString( 'counted from the conclusion of the contract', $svc );
		$this->assertStringNotContainsString( 'for goods', $svc, 'A service alone must not be described as goods.' );
		$this->assertStringContainsString( 'for goods, counted from delivery; for services and digital content, from the conclusion of the contract', $mixed );
	}

	public function test_bases_can_be_narrowed_to_the_withdrawn_lines(): void {
		list( $order, $tee_line, $course_line ) = $this->mixed_order();
		$resolver                               = new WindowBasisResolver( new Settings() );

		$this->assertSame( array( WindowBasis::DELIVERY, WindowBasis::CONCLUSION ), $resolver->for_order( $order ) );
		$this->assertSame( array( WindowBasis::CONCLUSION ), $resolver->for_order( $order, array( $course_line ) ) );
		$this->assertSame( array( WindowBasis::DELIVERY ), $resolver->for_order( $order, array( $tee_line ) ) );
	}

	public function test_rest_eligibility_reports_each_line_period(): void {
		Capabilities::add();
		rest_get_server();
		list( $order, $tee_line, $course_line ) = $this->mixed_order();

		$admin = wp_insert_user(
			array(
				'user_login' => 'rd_wb_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( (int) $admin );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/recesso-digitale/v1/eligibility/' . $order->get_id() ) );
		$data     = (array) $response->get_data();
		$windows  = (array) $data['line_windows'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( WindowBasis::DELIVERY, $windows[ $tee_line ]['basis'] );
		$this->assertSame( WindowBasis::CONCLUSION, $windows[ $course_line ]['basis'] );
		$this->assertFalse( $windows[ $course_line ]['within_window'] );

		wp_delete_user( (int) $admin );
	}

	public function test_product_editor_saves_and_clears_the_basis(): void {
		$product = $this->product( 'Edited' );
		$admin   = wp_insert_user(
			array(
				'user_login' => 'rd_wb_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( (int) $admin );
		$fields = new WithdrawalStatusFields();

		$_POST = array(
			'woocommerce_meta_nonce'            => wp_create_nonce( 'woocommerce_save_data' ),
			Settings::META_PRODUCT_WINDOW_BASIS => WindowBasis::CONCLUSION,
		);
		$fields->save_product_field( $product );
		$product->save();
		$this->assertSame( WindowBasis::CONCLUSION, get_post_meta( $product->get_id(), Settings::META_PRODUCT_WINDOW_BASIS, true ) );

		// Anything outside the two known values means "inherit", which removes the override.
		$_POST[ Settings::META_PRODUCT_WINDOW_BASIS ] = 'yesterday';
		$fields->save_product_field( $product );
		$product->save();
		$this->assertSame( '', get_post_meta( $product->get_id(), Settings::META_PRODUCT_WINDOW_BASIS, true ) );

		wp_delete_user( (int) $admin );
	}

	public function test_product_editor_refuses_a_save_without_the_nonce(): void {
		$product = $this->product( 'Forged' );
		$admin   = wp_insert_user(
			array(
				'user_login' => 'rd_wb_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( (int) $admin );

		$_POST = array( Settings::META_PRODUCT_WINDOW_BASIS => WindowBasis::CONCLUSION );
		( new WithdrawalStatusFields() )->save_product_field( $product );
		$product->save();

		$this->assertSame( '', get_post_meta( $product->get_id(), Settings::META_PRODUCT_WINDOW_BASIS, true ) );

		wp_delete_user( (int) $admin );
	}

	public function test_category_editor_saves_the_basis(): void {
		$term_id = $this->category();
		$admin   = wp_insert_user(
			array(
				'user_login' => 'rd_wb_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( (int) $admin );

		$_POST = array(
			'_recesso_dig_term_nonce'        => wp_create_nonce( 'recesso_dig_term_status' ),
			Settings::META_TERM_WINDOW_BASIS => WindowBasis::CONCLUSION,
		);
		( new WithdrawalStatusFields() )->save_category_field( $term_id );

		$this->assertSame( WindowBasis::CONCLUSION, get_term_meta( $term_id, Settings::META_TERM_WINDOW_BASIS, true ) );

		wp_delete_user( (int) $admin );
	}

	/**
	 * Confirm a whole-order withdrawal of the mixed order and read its admin detail.
	 *
	 * @param string $order_status Status to move the order to before reading (e.g. after a refund).
	 *
	 * @return array{0: array<int, array<string, string>>, 1: int, 2: int} item_periods, tee line, course line.
	 */
	private function admin_periods( string $order_status = '' ): array {
		Capabilities::add();
		rest_get_server();
		list( $order, $tee_line, $course_line ) = $this->mixed_order();

		$container = new Container();
		$request   = $container->withdrawal_service()->create_declaration(
			$order,
			array(
				'consumer_name'      => 'Anna Blu',
				'contract_reference' => (string) $order->get_order_number(),
				'confirmation_email' => 'parent@example.test',
			),
			null
		);
		$container->withdrawal_service()->confirm( $request->id, 'consumer' );

		if ( '' !== $order_status ) {
			$order = wc_get_order( $order->get_id() );
			$order->set_status( $order_status );
			$order->save();
		}

		$admin = wp_insert_user(
			array(
				'user_login' => 'rd_wb_' . wp_generate_password( 6, false ),
				'user_pass'  => wp_generate_password(),
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( (int) $admin );

		$response = rest_do_request( new \WP_REST_Request( 'GET', '/recesso-digitale/v1/admin/withdrawals/' . $request->id ) );
		wp_delete_user( (int) $admin );

		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();

		return array( (array) $data['item_periods'], $tee_line, $course_line );
	}

	public function test_admin_detail_states_each_item_period_and_timing(): void {
		list( $periods ) = $this->admin_periods();

		$this->assertCount( 2, $periods );
		$by_basis = array_column( $periods, null, 'basis' );

		$this->assertSame( 'within', $by_basis[ WindowBasis::DELIVERY ]['timing'], 'The T-shirt was delivered two days ago.' );
		$this->assertSame( 'late', $by_basis[ WindowBasis::CONCLUSION ]['timing'], 'The course was bought twenty days ago.' );
		$this->assertNotSame( '', $by_basis[ WindowBasis::CONCLUSION ]['ends_at_gmt'] );
		$this->assertStringContainsString( 'Football school', $by_basis[ WindowBasis::CONCLUSION ]['label'] );
	}

	public function test_admin_detail_counts_grace_days_separately(): void {
		update_option( Settings::OPT_GRACE_DAYS, 10 );
		list( $periods ) = $this->admin_periods();
		delete_option( Settings::OPT_GRACE_DAYS );

		$by_basis = array_column( $periods, null, 'basis' );
		$this->assertSame( 'grace', $by_basis[ WindowBasis::CONCLUSION ]['timing'], 'Six days late, inside ten extra days.' );
	}

	public function test_admin_detail_keeps_the_periods_after_a_refund(): void {
		// A refunded order is no longer withdrawable, yet "was it sent in time?" still needs an answer.
		list( $periods ) = $this->admin_periods( 'refunded' );

		$this->assertCount( 2, $periods );
		$this->assertNotContains( 'unknown', array_column( $periods, 'timing' ) );
	}
}
