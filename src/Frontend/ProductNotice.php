<?php
/**
 * Single-product "excluded from withdrawal" notice.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a public notice on the single-product page when the product is excluded from the right of
 * withdrawal under art. 59, so the consumer is informed before purchase. Opt-in (off by default) and
 * driven by the same art. 59 configuration the eligibility engine uses, so the storefront and the
 * withdrawal flow never disagree.
 */
final class ProductNotice {

	/**
	 * Eligibility adapter (art. 59 resolution).
	 *
	 * @var EligibilityAdapter
	 */
	private EligibilityAdapter $eligibility;

	/**
	 * Settings reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construct the provider.
	 *
	 * @param EligibilityAdapter $eligibility Eligibility adapter.
	 * @param Settings           $settings    Settings reader.
	 */
	public function __construct( EligibilityAdapter $eligibility, Settings $settings ) {
		$this->eligibility = $eligibility;
		$this->settings    = $settings;
	}

	/**
	 * Hook the notice, only when enabled by the merchant.
	 */
	public function register(): void {
		if ( ! $this->settings->product_notice_enabled() ) {
			return;
		}

		// After the price/short description, before/around the add-to-cart area.
		add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 25 );
	}

	/**
	 * Render the notice for the current product when it is excluded from withdrawal.
	 */
	public function render(): void {
		global $product;

		$product_obj = $product instanceof \WC_Product ? $product : wc_get_product( get_the_ID() );
		if ( ! $product_obj instanceof \WC_Product ) {
			return;
		}

		$status = $this->eligibility->product_exclusion( $product_obj->get_id() );
		if ( true !== ( $status['excluded'] ?? false ) ) {
			return;
		}

		$html = Templates::render(
			'product-exclusion-notice',
			array( 'text' => $this->settings->product_notice_text() )
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from an escaped template.
		echo $html;
	}
}
