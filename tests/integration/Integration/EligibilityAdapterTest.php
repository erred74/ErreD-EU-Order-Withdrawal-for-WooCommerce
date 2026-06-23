<?php
/**
 * Integration tests for the WooCommerce eligibility adapter.
 *
 * Focuses on the advisory-window behaviour: the withdrawal function stays available even after the
 * ordinary 14-day period has elapsed (the merchant decides), while the result still reports whether
 * "now" falls within that period for the admin's information.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Integration;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Domain\Eligibility\Reason;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

final class EligibilityAdapterTest extends TestCase {

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

	private function make_completed_order( int $completed_ts ): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Eligible Goods' );
		$product->set_regular_price( '20' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( $completed_ts );
		$order->save();

		$this->order_id = $order->get_id();

		return $order;
	}

	public function test_recent_order_is_eligible_and_within_window(): void {
		$order  = $this->make_completed_order( time() );
		$result = ( new Container() )->eligibility_adapter()->for_order( $order );

		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
		$this->assertTrue( $result->within_window );
	}

	public function test_old_order_stays_eligible_but_outside_window(): void {
		// Completed 60 days ago: the ordinary 14-day period has elapsed.
		$order  = $this->make_completed_order( time() - ( 60 * DAY_IN_SECONDS ) );
		$result = ( new Container() )->eligibility_adapter()->for_order( $order );

		// The function remains available (the merchant decides), but the advisory flag is false.
		$this->assertTrue( $result->is_eligible );
		$this->assertSame( Reason::ELIGIBLE, $result->reason );
		$this->assertFalse( $result->within_window );
	}

	public function test_product_meta_exclusion_overrides_the_allow_default(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Sealed Goods' );
		$product->set_regular_price( '20' );
		$product->update_meta_data( Settings::META_PRODUCT_STATUS, Settings::STATUS_EXCLUDE );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		$adapter = ( new Container() )->eligibility_adapter();

		$result = $adapter->for_order( $order );
		$this->assertFalse( $result->is_eligible, 'A product marked excluded must make the order ineligible.' );
		$this->assertSame( Reason::EXCLUDED_ART59, $result->reason );

		$exclusion = $adapter->product_exclusion( $product->get_id() );
		$this->assertTrue( $exclusion['excluded'] );
		$this->assertTrue( $exclusion['configured'] );

		$product->delete( true );
	}

	/**
	 * @dataProvider provide_classification_statuses
	 *
	 * @param string $status        Stored product withdrawal status.
	 * @param bool   $expect_eligible Whether the order should remain eligible.
	 */
	public function test_article_classification_statuses_map_to_eligibility( string $status, bool $expect_eligible ): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Classified Goods' );
		$product->set_regular_price( '20' );
		$product->update_meta_data( Settings::META_PRODUCT_STATUS, $status );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		$result = ( new Container() )->eligibility_adapter()->for_order( $order );
		$this->assertSame( $expect_eligible, $result->is_eligible, "Status {$status} eligibility mismatch." );

		$product->delete( true );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function provide_classification_statuses(): array {
		return array(
			'standard allows'            => array( Settings::STATUS_STANDARD, true ),
			'service-start allows'       => array( Settings::STATUS_ART14_4A_SERVICE, true ),
			'digital content excludes'   => array( Settings::STATUS_ART16M_DIGITAL, false ),
			'accommodation excludes'     => array( Settings::STATUS_ART16L_ACCOMMODATION, false ),
			'other art.16 excludes'      => array( Settings::STATUS_ART16_OTHER, false ),
			'legacy allow still allows'  => array( Settings::STATUS_ALLOW, true ),
			'legacy exclude still hides' => array( Settings::STATUS_EXCLUDE, false ),
		);
	}

	public function test_category_meta_exclusion_is_honoured_for_the_product_notice(): void {
		$term    = wp_insert_term( 'Made to order', 'product_cat' );
		$term_id = is_array( $term ) ? (int) $term['term_id'] : 0;
		update_term_meta( $term_id, Settings::META_TERM_STATUS, Settings::STATUS_EXCLUDE );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Category Excluded' );
		$product->set_regular_price( '20' );
		$product->set_category_ids( array( $term_id ) );
		$product->save();

		$exclusion = ( new Container() )->eligibility_adapter()->product_exclusion( $product->get_id() );
		$this->assertTrue( $exclusion['excluded'], 'A category marked excluded must exclude its products.' );

		$product->delete( true );
		wp_delete_term( $term_id, 'product_cat' );
	}
}
