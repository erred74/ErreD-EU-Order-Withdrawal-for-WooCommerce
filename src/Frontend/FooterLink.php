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
 *
 * The same link is available as the `[recesso_digitale_link]` shortcode, for themes whose footer is
 * a menu or a template part rather than something `wp_footer` can be appended to.
 */
final class FooterLink {

	/**
	 * Shortcode placing the withdrawal link anywhere a shortcode is accepted.
	 */
	public const SHORTCODE = 'recesso_digitale_link';

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
	 * Hook the footer link when enabled, and always register the shortcode.
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );

		if ( ! $this->settings->footer_link_enabled() ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'render' ) );
	}

	/**
	 * Render the footer link.
	 */
	public function render(): void {
		$html = $this->link_html( $this->default_label(), '' );
		if ( '' === $html ) {
			return;
		}

		printf(
			'<div class="wp-block-recesso-digitale-footer-link">%s</div>',
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link_html() escapes the URL, the label and the class.
			$html
		);
	}

	/**
	 * Shortcode rendering the withdrawal link, with an optional custom label and class so it can be
	 * dropped into a footer menu, a widget or a block-theme template part.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: `text`, `class`.
	 *
	 * @return string
	 */
	public function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'text'  => '',
				'class' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);

		$label = '' !== trim( (string) $atts['text'] ) ? (string) $atts['text'] : $this->default_label();

		return $this->link_html( $label, (string) $atts['class'] );
	}

	/**
	 * Build the anchor, or an empty string when no withdrawal page is configured.
	 *
	 * @param string $label Link text.
	 * @param string $extra_class Extra class names.
	 */
	private function link_html( string $label, string $extra_class ): string {
		$url = FlowPage::url();
		if ( '' === $url ) {
			return '';
		}

		$classes = trim( 'wp-block-recesso-digitale-footer-link__link ' . $extra_class );

		return sprintf(
			'<a class="%s" href="%s">%s</a>',
			esc_attr( $classes ),
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * The legally-fixed Italian label for the withdrawal function.
	 */
	private function default_label(): string {
		return __( 'recedere dal contratto qui', 'erred-eu-order-withdrawal-for-woocommerce' );
	}
}
