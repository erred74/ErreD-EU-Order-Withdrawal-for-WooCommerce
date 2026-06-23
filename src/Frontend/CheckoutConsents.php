<?php
/**
 * Checkout consents for the art. 59 exceptions.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Support\Clock;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Captures, at checkout, the consents that bear on the right of withdrawal: the mandatory
 * digital-content consent (art. 16(m), optionally required to place the order) and the optional
 * services-started-early consent (art. 14(4)(a)). Each choice and the moment it was made are stored
 * as order meta and noted on the order, so the merchant has durable evidence. Reading $_POST in these
 * handlers is safe: WooCommerce verifies the checkout nonce before they run.
 */
final class CheckoutConsents {

	private const FIELD_DIGITAL = 'recesso_dig_consent_digital';
	private const FIELD_SERVICE = 'recesso_dig_consent_service';

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
	 * Construct the provider.
	 *
	 * @param Settings $settings Settings reader.
	 * @param Clock    $clock    Clock.
	 */
	public function __construct( Settings $settings, Clock $clock ) {
		$this->settings = $settings;
		$this->clock    = $clock;
	}

	/**
	 * Hook the checkout consents, only when at least one is enabled.
	 */
	public function register(): void {
		if ( ! $this->settings->consent_digital_enabled() && ! $this->settings->consent_service_enabled() ) {
			return;
		}

		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Render the enabled consent checkboxes before the place-order button.
	 */
	public function render(): void {
		if ( $this->settings->consent_digital_enabled() ) {
			$this->render_checkbox( self::FIELD_DIGITAL, $this->settings->consent_digital_text(), $this->settings->consent_digital_required() );
		}
		if ( $this->settings->consent_service_enabled() ) {
			$this->render_checkbox( self::FIELD_SERVICE, $this->settings->consent_service_text(), false );
		}
	}

	/**
	 * Render one accessible consent checkbox via the WooCommerce form-field helper.
	 *
	 * @param string $field    Field name.
	 * @param string $label    Consent label.
	 * @param bool   $required Whether the field is required to place the order.
	 */
	private function render_checkbox( string $field, string $label, bool $required ): void {
		woocommerce_form_field(
			$field,
			array(
				'type'     => 'checkbox',
				'class'    => array( 'recesso-dig-consent' ),
				'label'    => $label,
				'required' => $required,
			),
			''
		);
	}

	/**
	 * Block checkout when the digital-content consent is required but not given.
	 */
	public function validate(): void {
		if ( ! $this->settings->consent_digital_enabled() || ! $this->settings->consent_digital_required() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before woocommerce_checkout_process.
		if ( ! isset( $_POST[ self::FIELD_DIGITAL ] ) ) {
			wc_add_notice( __( 'Please confirm the required consent to place your order.', 'erred-eu-order-withdrawal-for-woocommerce' ), 'error' );
		}
	}

	/**
	 * Persist the consent choices and timestamps onto the order, and note them for the audit trail.
	 *
	 * @param \WC_Order            $order The order being created.
	 * @param array<string, mixed> $data  Posted checkout data (unused).
	 */
	public function save( \WC_Order $order, array $data = array() ): void {
		unset( $data );

		$now = $this->clock->now_gmt();

		if ( $this->settings->consent_digital_enabled() ) {
			$this->record( $order, self::FIELD_DIGITAL, Settings::META_CONSENT_DIGITAL, Settings::META_CONSENT_DIGITAL_AT, $now, __( 'Digital-content consent (art. 16(m))', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		}
		if ( $this->settings->consent_service_enabled() ) {
			$this->record( $order, self::FIELD_SERVICE, Settings::META_CONSENT_SERVICE, Settings::META_CONSENT_SERVICE_AT, $now, __( 'Service-start consent (art. 14(4)(a))', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		}
	}

	/**
	 * Record one consent on the order: a '1'/'0' meta, a timestamp when given, and an order note.
	 *
	 * @param \WC_Order $order    The order.
	 * @param string    $field    Posted field name.
	 * @param string    $meta     Meta key for the choice.
	 * @param string    $meta_at  Meta key for the timestamp.
	 * @param string    $now_gmt  Current GMT timestamp.
	 * @param string    $label    Human label for the order note.
	 */
	private function record( \WC_Order $order, string $field, string $meta, string $meta_at, string $now_gmt, string $label ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before woocommerce_checkout_create_order.
		$given = isset( $_POST[ $field ] );

		$order->update_meta_data( $meta, $given ? '1' : '0' );
		if ( $given ) {
			$order->update_meta_data( $meta_at, $now_gmt );
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: consent label, 2: given/declined. */
				esc_html__( 'Recesso: %1$s — %2$s.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				$label,
				$given ? esc_html__( 'given', 'erred-eu-order-withdrawal-for-woocommerce' ) : esc_html__( 'declined', 'erred-eu-order-withdrawal-for-woocommerce' )
			)
		);
	}
}
