<?php
/**
 * Persistent footer withdrawal link.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a persistent link to the withdrawal page in the site footer (opt-in), so the mandated
 * «recedere dal contratto qui» function is continuously visible and easily accessible across the
 * storefront, not only from order emails and the My Account area.
 */
final class FooterLink {

	/**
	 * Settings reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construct the provider.
	 *
	 * @param Settings $settings Settings reader.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hook the footer link, only when enabled.
	 */
	public function register(): void {
		if ( ! $this->settings->footer_link_enabled() ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Render the footer link.
	 */
	public function render(): void {
		$url = FlowPage::url();
		if ( '' === $url ) {
			return;
		}

		printf(
			'<div class="wp-block-recesso-digitale-footer-link"><a href="%s">%s</a></div>',
			esc_url( $url ),
			esc_html__( 'recedere dal contratto qui', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}
}
