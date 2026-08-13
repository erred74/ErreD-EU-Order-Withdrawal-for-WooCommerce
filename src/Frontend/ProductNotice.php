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
	 * Hook the notice, only when enabled by the merchant. The shortcode is always registered, so a
	 * page builder can place the notice even where the standard hook does not fire; it renders
	 * nothing unless the notice is enabled and the product is actually excluded.
	 */
	public function register(): void {
		add_shortcode( 'recesso_digitale_avviso_esclusione', array( $this, 'shortcode' ) );

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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from an escaped template.
		echo $this->notice_html();
	}

	/**
	 * Shortcode bridge, for themes and page builders (Divi, Elementor, Bricks and the like) whose
	 * product templates do not fire `woocommerce_single_product_summary`.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes (unused).
	 *
	 * @return string
	 */
	public function shortcode( $atts = array() ): string {
		unset( $atts );

		if ( ! $this->settings->product_notice_enabled() ) {
			return '';
		}

		return $this->notice_html();
	}

	/**
	 * Build the notice markup for the current product, or an empty string when it does not apply.
	 */
	private function notice_html(): string {
		global $product;

		$product_obj = $product instanceof \WC_Product ? $product : wc_get_product( get_the_ID() );
		if ( ! $product_obj instanceof \WC_Product ) {
			return '';
		}

		$exclusion = $this->eligibility->product_exclusion( $product_obj->get_id() );
		if ( true !== ( $exclusion['excluded'] ?? false ) ) {
			return '';
		}

		// The wording follows the specific exception the merchant classified the product under, so a
		// digital download and a made-to-measure item do not carry the same explanation.
		$notice = $this->settings->exclusion_notice( $this->eligibility->product_status( $product_obj->get_id() ) );

		return Templates::render(
			'product-exclusion-notice',
			array(
				'title' => $notice['title'],
				'body'  => $this->expand_placeholders( $notice['body'] ),
			)
		);
	}

	/**
	 * Expand the placeholders a merchant may use in the notice body. Currently only
	 * `{withdrawal_page_link}`, which becomes a link to the configured withdrawal page — or is
	 * removed entirely when no page is configured, so the notice never shows a dangling placeholder.
	 *
	 * @param string $body The configured body text.
	 */
	private function expand_placeholders( string $body ): string {
		$url = FlowPage::url();

		if ( '' === $url ) {
			return trim( str_replace( '{withdrawal_page_link}', '', $body ) );
		}

		$link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Read about the right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' )
		);

		return trim( str_replace( '{withdrawal_page_link}', $link, $body ) );
	}
}
