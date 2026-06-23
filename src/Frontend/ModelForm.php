<?php
/**
 * Annex I.B model withdrawal form.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the statutory model withdrawal form (Directive 2011/83/EU Annex I.B, as transposed) available
 * to the consumer: a printable block populated with the trader's contact details. It is shown
 * collapsibly below the public withdrawal form and is also placeable anywhere via the
 * `[recesso_digitale_modulo]` shortcode. Providing the model form is informational; using it is never
 * required (the online withdrawal flow is the primary channel).
 */
final class ModelForm {

	/**
	 * Settings reader (trader contact details).
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
	 * Hook the shortcode and the after-declaration insertion point. The shortcode is always available;
	 * the automatic placement below the declaration form is gated by the merchant's "show model form"
	 * setting (on by default).
	 */
	public function register(): void {
		add_shortcode( 'recesso_digitale_modulo', array( $this, 'shortcode' ) );

		if ( $this->settings->model_form_enabled() ) {
			add_action( 'recesso_dig_after_declaration_form', array( $this, 'render' ) );
		}
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes (unused).
	 */
	public function shortcode( $atts = array() ): string {
		unset( $atts );

		return $this->html();
	}

	/**
	 * Echo the model form (used at the after-declaration action).
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from an escaped template.
		echo $this->html();
	}

	/**
	 * Render the model form markup from the (overridable) template.
	 */
	public function html(): string {
		return Templates::render(
			'model-form',
			array(
				'trader_name'    => $this->settings->trader_name(),
				'trader_address' => $this->settings->trader_address(),
				'trader_phone'   => $this->settings->trader_phone(),
				'trader_email'   => $this->settings->trader_email(),
			)
		);
	}
}
