<?php
/**
 * Checkout consents for the WooCommerce Checkout block.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Support\Clock;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the two withdrawal consents inside the WooCommerce Checkout *block*, mirroring what
 * {@see CheckoutConsents} does for the classic shortcode checkout.
 *
 * The consents are registered through WooCommerce's Additional Checkout Fields API rather than a
 * custom slot fill, so the checkboxes are rendered, validated, stored and shown on the order
 * confirmation by WooCommerce itself — accessible markup and block-theme styling included, with no
 * JavaScript bundle of our own to keep in step with WooCommerce Blocks.
 *
 * Conditional display (the merchant's "show only when the cart contains a matching product" option)
 * is expressed as a JSON-Schema `hidden` rule evaluated against the Store API document object. The
 * rule reads a small piece of cart extension data this class also registers, so the decision is made
 * from the live cart on every request and stays in step with the classic checkout.
 *
 * The whole class is inert unless WooCommerce exposes the Additional Checkout Fields API (added in
 * WooCommerce 8.9; our floor is 8.2). On older versions the block checkout behaves exactly as it did
 * before, and the classic checkout is unaffected either way.
 */
final class BlockCheckoutConsents {

	/**
	 * Store API extension namespace, also the field-id namespace.
	 */
	private const NAMESPACE_KEY = 'recesso-digitale';

	/**
	 * Field ids as registered with WooCommerce (namespace/name).
	 */
	private const FIELD_DIGITAL = self::NAMESPACE_KEY . '/consent-digital';
	private const FIELD_SERVICE = self::NAMESPACE_KEY . '/consent-service';

	/**
	 * Settings reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Clock (for the consent timestamps).
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Shared applicability resolver, so the block and classic checkouts agree.
	 *
	 * @var CheckoutConsents
	 */
	private CheckoutConsents $consents;

	/**
	 * Construct the provider.
	 *
	 * @param Settings         $settings Settings reader.
	 * @param Clock            $clock    Clock.
	 * @param CheckoutConsents $consents Applicability resolver shared with the classic checkout.
	 */
	public function __construct( Settings $settings, Clock $clock, CheckoutConsents $consents ) {
		$this->settings = $settings;
		$this->clock    = $clock;
		$this->consents = $consents;
	}

	/**
	 * Hook the block-checkout consents, only when at least one is enabled and the host WooCommerce
	 * supports the Additional Checkout Fields API.
	 */
	public function register(): void {
		if ( ! $this->settings->consent_digital_enabled() && ! $this->settings->consent_service_enabled() ) {
			return;
		}
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		add_action( 'woocommerce_init', array( $this, 'register_fields' ) );
		add_action( 'woocommerce_init', array( $this, 'register_cart_data' ) );
		add_action( 'woocommerce_set_additional_field_value', array( $this, 'mirror_value' ), 10, 4 );
	}

	/**
	 * Register the enabled consents as additional checkout fields.
	 */
	public function register_fields(): void {
		if ( $this->settings->consent_digital_enabled() ) {
			$this->register_field(
				self::FIELD_DIGITAL,
				$this->settings->consent_digital_text(),
				'digital_consent',
				$this->settings->consent_digital_required()
			);
		}

		if ( $this->settings->consent_service_enabled() ) {
			// The art. 14(4)(a) consent is never a condition of placing the order: it only entitles the
			// merchant to a proportionate payment, so declining it must not block checkout.
			$this->register_field(
				self::FIELD_SERVICE,
				$this->settings->consent_service_text(),
				'service_consent',
				false
			);
		}
	}

	/**
	 * Register one consent checkbox.
	 *
	 * @param string $field_id  Namespaced field id.
	 * @param string $label     Consent label shown to the customer.
	 * @param string $flag      Key of the cart-extension flag gating the field in conditional mode.
	 * @param bool   $required  Whether the order cannot be placed without the consent.
	 */
	private function register_field( string $field_id, string $label, string $flag, bool $required ): void {
		$options = array(
			'id'       => $field_id,
			'label'    => $label,
			'location' => 'order',
			'type'     => 'checkbox',
			'required' => $required,
		);

		if ( $this->settings->consents_conditional() ) {
			$options['hidden'] = $this->hidden_rule( $flag );
		}

		try {
			woocommerce_register_additional_checkout_field( $options );
		} catch ( \Exception $e ) {
			// Registration only throws on a malformed definition. Never let it break the checkout:
			// the classic path and the rest of the plugin must keep working.
			unset( $e );
		}
	}

	/**
	 * The JSON-Schema rule that hides a consent when its flag is not set on the current cart.
	 * Evaluated by WooCommerce against the Store API document object, whose `cart.extensions` carries
	 * the data registered in {@see self::register_cart_data()}.
	 *
	 * @param string $flag Extension-data key to test.
	 *
	 * @return array<string, mixed>
	 */
	private function hidden_rule( string $flag ): array {
		return array(
			'cart' => array(
				'type'       => 'object',
				'properties' => array(
					'extensions' => array(
						'type'       => 'object',
						'properties' => array(
							self::NAMESPACE_KEY => array(
								'type'       => 'object',
								'properties' => array(
									$flag => array( 'const' => false ),
								),
								'required'   => array( $flag ),
							),
						),
						'required'   => array( self::NAMESPACE_KEY ),
					),
				),
				'required'   => array( 'extensions' ),
			),
		);
	}

	/**
	 * Expose, as Store API cart extension data, whether each consent applies to the current cart.
	 * This is what the `hidden` rules above read; it is also useful to other integrations.
	 */
	public function register_cart_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				// The Store API cart endpoint identifier. Kept as a literal rather than referencing an
				// internal WooCommerce class constant, so no internal namespace is coupled to.
				'endpoint'        => 'cart',
				'namespace'       => self::NAMESPACE_KEY,
				'data_callback'   => array( $this, 'cart_data' ),
				'schema_callback' => array( $this, 'cart_data_schema' ),
				'schema_type'     => ARRAY_A,
			)
		);
	}

	/**
	 * The consent applicability flags for the current cart.
	 *
	 * @return array<string, bool>
	 */
	public function cart_data(): array {
		return array(
			'digital_consent' => $this->consents->show_digital(),
			'service_consent' => $this->consents->show_service(),
		);
	}

	/**
	 * Schema for {@see self::cart_data()}.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function cart_data_schema(): array {
		return array(
			'digital_consent' => array(
				'description' => __( 'Whether the digital-content consent (Article 16(m)) applies to this cart.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'service_consent' => array(
				'description' => __( 'Whether the service-start consent (Article 14(4)(a)) applies to this cart.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}

	/**
	 * Mirror a persisted consent into the plugin's own order meta, so the block checkout produces the
	 * same durable evidence as the classic one: the choice, the moment it was made and an order note.
	 *
	 * @param string $key       The field id.
	 * @param mixed  $value     The submitted value.
	 * @param string $group     The field group ('other' for order-location fields).
	 * @param mixed  $wc_object The object the value was persisted on.
	 */
	public function mirror_value( $key, $value, $group, $wc_object ): void {
		if ( 'other' !== $group || ! $wc_object instanceof \WC_Order ) {
			return;
		}

		$key = (string) $key;
		if ( self::FIELD_DIGITAL === $key ) {
			$meta    = Settings::META_CONSENT_DIGITAL;
			$meta_at = Settings::META_CONSENT_DIGITAL_AT;
			$label   = __( 'Digital-content consent (art. 16(m))', 'erred-eu-order-withdrawal-for-woocommerce' );
		} elseif ( self::FIELD_SERVICE === $key ) {
			$meta    = Settings::META_CONSENT_SERVICE;
			$meta_at = Settings::META_CONSENT_SERVICE_AT;
			$label   = __( 'Service-start consent (art. 14(4)(a))', 'erred-eu-order-withdrawal-for-woocommerce' );
		} else {
			return;
		}

		$given    = ( true === $value || '1' === $value || 1 === $value );
		$recorded = (string) $wc_object->get_meta( $meta );

		// The action can fire again when the order is re-saved. The consent and the moment it was
		// given are evidence: record them once and never rewrite them with an identical value.
		if ( ( $given ? '1' : '0' ) === $recorded ) {
			return;
		}

		$wc_object->update_meta_data( $meta, $given ? '1' : '0' );
		if ( $given ) {
			$wc_object->update_meta_data( $meta_at, $this->clock->now_gmt() );
		}

		$wc_object->add_order_note(
			sprintf(
				/* translators: 1: consent label, 2: given/declined. */
				esc_html__( 'Recesso: %1$s — %2$s.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				$label,
				$given ? esc_html__( 'given', 'erred-eu-order-withdrawal-for-woocommerce' ) : esc_html__( 'declined', 'erred-eu-order-withdrawal-for-woocommerce' )
			)
		);
	}
}
