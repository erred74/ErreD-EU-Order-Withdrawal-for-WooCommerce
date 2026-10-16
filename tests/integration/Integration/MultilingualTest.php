<?php
/**
 * Integration tests for withdrawal configuration on WPML / Polylang translations.
 *
 * The defect: a translated product is a separate post and a translated category a separate term, and
 * neither plugin copies our meta to them. A translation therefore fell through to the store-wide
 * defaults — its art. 59 exclusion, exclusion notice and checkout consent silently disappeared, and
 * a service could be counted from delivery like goods. Neither plugin is installed here: WPML is
 * simulated through its public filter, Polylang through its three API functions.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Integration;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Admin\WithdrawalStatusFields;
use Recesso54bis\Container;
use Recesso54bis\Domain\Eligibility\Reason;
use Recesso54bis\Domain\Eligibility\WindowBasis;
use Recesso54bis\Integration\Multilingual;
use Recesso54bis\Integration\WindowBasisResolver;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\Settings;

require_once __DIR__ . '/../fixtures/polylang-functions.php';

final class MultilingualTest extends TestCase {

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

	/**
	 * WPML stand-in map: element id => original element id, per element type.
	 *
	 * @var array<string, array<int, int>>
	 */
	private array $wpml = array();

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		$GLOBALS['recesso_dig_test_pll'] = array();
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
		remove_all_filters( 'wpml_original_element_id' );
		remove_all_filters( 'recesso_dig_original_product_id' );
		$GLOBALS['recesso_dig_test_pll'] = array();
		delete_option( Settings::OPT_DEFAULT_POLICY );
		delete_option( Settings::OPT_EXCLUDED_PRODUCTS );
		delete_option( Settings::OPT_START_TRIGGER );
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Create a product.
	 *
	 * @param array<string, string> $meta       Meta to store on it.
	 * @param int[]                 $categories Category ids.
	 */
	private function product( array $meta = array(), array $categories = array() ): \WC_Product {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Product ' . wp_generate_password( 6, false ) );
		$product->set_regular_price( '20' );
		$product->set_category_ids( $categories );
		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}
		$product->save();
		$this->products[] = $product->get_id();

		return $product;
	}

	/**
	 * Create a product category.
	 *
	 * @param array<string, string> $meta Term meta to store on it.
	 */
	private function category( array $meta = array() ): \WP_Term {
		$created = wp_insert_term( 'Cat ' . wp_generate_password( 6, false ), 'product_cat' );
		$term_id = (int) $created['term_id'];
		foreach ( $meta as $key => $value ) {
			update_term_meta( $term_id, $key, $value );
		}
		$this->terms[] = $term_id;

		return get_term( $term_id, 'product_cat' );
	}

	/**
	 * Simulate WPML: declare $translation a translation of $original.
	 *
	 * @param string $type        WPML element type ('post_product' or 'tax_product_cat').
	 * @param int    $translation Translation element id (term_taxonomy_id for a category).
	 * @param int    $original    Original element id.
	 */
	private function wpml( string $type, int $translation, int $original ): void {
		$this->wpml[ $type ][ $translation ] = $original;

		if ( ! has_filter( 'wpml_original_element_id' ) ) {
			add_filter(
				'wpml_original_element_id',
				fn( $unused, $id, $element_type ) => $this->wpml[ $element_type ][ (int) $id ] ?? (int) $id,
				10,
				3
			);
		}
	}

	/**
	 * A completed order of one product.
	 *
	 * @param \WC_Product $product The product.
	 */
	private function order_of( \WC_Product $product ): \WC_Order {
		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$this->order_id = $order->get_id();

		return wc_get_order( $order->get_id() );
	}

	public function test_wpml_translation_inherits_the_original_exclusion(): void {
		$original    = $this->product( array( Settings::META_PRODUCT_STATUS => Settings::STATUS_ART16_OTHER ) );
		$translation = $this->product();
		$this->wpml( 'post_product', $translation->get_id(), $original->get_id() );

		$result = ( new Container() )->eligibility_adapter()->for_order( $this->order_of( $translation ) );

		$this->assertFalse( $result->is_eligible );
		$this->assertSame( Reason::EXCLUDED_ART59, $result->reason );
	}

	public function test_wpml_translation_inherits_the_original_consent_classification(): void {
		$original    = $this->product( array( Settings::META_PRODUCT_STATUS => Settings::STATUS_ART16M_DIGITAL ) );
		$translation = $this->product();
		$this->wpml( 'post_product', $translation->get_id(), $original->get_id() );

		$this->assertSame(
			Settings::STATUS_ART16M_DIGITAL,
			( new Container() )->eligibility_adapter()->product_status( $translation->get_id() ),
			'The checkout consent and the product notice read this: they must not vanish on a translation.'
		);
	}

	public function test_wpml_translation_inherits_the_original_period_basis(): void {
		update_option( Settings::OPT_START_TRIGGER, Settings::TRIGGER_DELIVERY );
		$original    = $this->product( array( Settings::META_PRODUCT_WINDOW_BASIS => WindowBasis::CONCLUSION ) );
		$translation = $this->product();
		$this->wpml( 'post_product', $translation->get_id(), $original->get_id() );

		$this->assertSame( WindowBasis::CONCLUSION, ( new WindowBasisResolver( new Settings() ) )->for_product( $translation->get_id() ) );
	}

	public function test_a_translation_own_value_still_wins(): void {
		$original    = $this->product(
			array(
				Settings::META_PRODUCT_STATUS       => Settings::STATUS_ART16_OTHER,
				Settings::META_PRODUCT_WINDOW_BASIS => WindowBasis::CONCLUSION,
			)
		);
		$translation = $this->product(
			array(
				Settings::META_PRODUCT_STATUS       => Settings::STATUS_STANDARD,
				Settings::META_PRODUCT_WINDOW_BASIS => WindowBasis::DELIVERY,
			)
		);
		$this->wpml( 'post_product', $translation->get_id(), $original->get_id() );

		$result = ( new Container() )->eligibility_adapter()->for_order( $this->order_of( $translation ) );

		$this->assertTrue( $result->is_eligible, 'A language-specific exception set on the translation must win.' );
		$this->assertSame( WindowBasis::DELIVERY, ( new WindowBasisResolver( new Settings() ) )->for_product( $translation->get_id() ) );
	}

	public function test_wpml_translated_category_inherits_the_original_category(): void {
		$original_cat   = $this->category(
			array(
				Settings::META_TERM_STATUS       => Settings::STATUS_ART16_OTHER,
				Settings::META_TERM_WINDOW_BASIS => WindowBasis::CONCLUSION,
			)
		);
		$translated_cat = $this->category();
		$this->wpml( 'tax_product_cat', (int) $translated_cat->term_taxonomy_id, (int) $original_cat->term_taxonomy_id );
		$product = $this->product( array(), array( $translated_cat->term_id ) );

		$result = ( new Container() )->eligibility_adapter()->for_order( $this->order_of( $product ) );

		$this->assertSame( Reason::EXCLUDED_ART59, $result->reason );
		$this->assertSame( WindowBasis::CONCLUSION, ( new WindowBasisResolver( new Settings() ) )->for_product( $product->get_id() ) );
	}

	public function test_legacy_excluded_list_matches_a_translation(): void {
		$original    = $this->product();
		$translation = $this->product();
		update_option( Settings::OPT_EXCLUDED_PRODUCTS, array( $original->get_id() ) );
		$this->wpml( 'post_product', $translation->get_id(), $original->get_id() );

		$result = ( new Container() )->eligibility_adapter()->for_order( $this->order_of( $translation ) );

		$this->assertSame( Reason::EXCLUDED_ART59, $result->reason );
	}

	public function test_translation_without_categories_uses_the_original_categories(): void {
		$category    = $this->category( array( Settings::META_TERM_WINDOW_BASIS => WindowBasis::CONCLUSION ) );
		$original    = $this->product( array(), array( $category->term_id ) );
		$translation = $this->product();
		// WooCommerce files a product with no category under the default one, so strip the relation
		// directly: a translation saved before its categories were translated has none.
		wp_delete_object_term_relationships( $translation->get_id(), 'product_cat' );
		clean_post_cache( $translation->get_id() );
		$this->wpml( 'post_product', $translation->get_id(), $original->get_id() );

		$this->assertSame( array(), wc_get_product( $translation->get_id() )->get_category_ids(), 'Fixture premise: the translation has no category.' );
		$this->assertSame( WindowBasis::CONCLUSION, ( new WindowBasisResolver( new Settings() ) )->for_product( $translation->get_id() ) );
	}

	public function test_polylang_translation_inherits_from_the_default_language(): void {
		$original_cat   = $this->category( array( Settings::META_TERM_WINDOW_BASIS => WindowBasis::CONCLUSION ) );
		$translated_cat = $this->category();
		$original       = $this->product( array( Settings::META_PRODUCT_STATUS => Settings::STATUS_ART16_OTHER ) );
		$translation    = $this->product( array(), array( $translated_cat->term_id ) );

		$GLOBALS['recesso_dig_test_pll'] = array(
			'posts' => array( $translation->get_id() => $original->get_id() ),
			'terms' => array( $translated_cat->term_id => $original_cat->term_id ),
		);

		$this->assertFalse( has_filter( 'wpml_original_element_id' ), 'Fixture premise: no WPML.' );
		$this->assertSame( $original->get_id(), Multilingual::original_product( $translation->get_id() ) );

		$result = ( new Container() )->eligibility_adapter()->for_order( $this->order_of( $translation ) );
		$this->assertSame( Reason::EXCLUDED_ART59, $result->reason );
		$this->assertSame( WindowBasis::CONCLUSION, ( new WindowBasisResolver( new Settings() ) )->for_product( $translation->get_id() ) );
	}

	public function test_other_multilingual_plugins_can_declare_the_original(): void {
		$original    = $this->product( array( Settings::META_PRODUCT_STATUS => Settings::STATUS_ART16_OTHER ) );
		$translation = $this->product();
		add_filter(
			'recesso_dig_original_product_id',
			static fn( int $resolved, int $product_id ): int => $product_id === $translation->get_id() ? $original->get_id() : $resolved,
			10,
			2
		);

		$result = ( new Container() )->eligibility_adapter()->for_order( $this->order_of( $translation ) );

		$this->assertSame( Reason::EXCLUDED_ART59, $result->reason );
	}

	public function test_no_multilingual_plugin_means_every_product_is_its_own_original(): void {
		$product = $this->product();

		$this->assertSame( $product->get_id(), Multilingual::original_product( $product->get_id() ) );
	}

	public function test_translation_editor_says_the_original_value_applies(): void {
		$original    = $this->product(
			array(
				Settings::META_PRODUCT_STATUS       => Settings::STATUS_ART16_OTHER,
				Settings::META_PRODUCT_WINDOW_BASIS => WindowBasis::CONCLUSION,
			)
		);
		$translation = $this->product();
		$this->wpml( 'post_product', $translation->get_id(), $original->get_id() );

		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

		global $post;
		$previous = $post;
		$post     = get_post( $translation->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the product editor field reads the global post, as on the edit screen.
		ob_start();
		( new WithdrawalStatusFields() )->render_product_field();
		$html = (string) ob_get_clean();
		$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring it.

		$this->assertSame( 2, substr_count( $html, 'Same as the original language' ) );
		$this->assertStringContainsString( 'Purchase — services and digital content', $html );
	}
}
