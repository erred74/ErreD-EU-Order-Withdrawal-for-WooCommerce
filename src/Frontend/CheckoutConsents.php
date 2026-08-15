<?php
/**
 * Checkout consents for the art. 59 exceptions.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Support\Clock;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Captures, at checkout, the consents that bear on the right of withdrawal: the mandatory
 * digital-content consent (art. 16(m), optionally required to place the order) and the optional
 * services-started-early consent (art. 14(4)(a)). Each choice and the moment it was made are stored
 * as order meta and noted on the order, so the merchant has durable evidence. Reading $_POST in these
 * handlers is safe: WooCommerce verifies the checkout nonce before they run.
 *
 * A consent is shown when it is enabled and — if the merchant turned on the conditional mode — the
 * cart actually contains a product classified for that exception. Render, validation and persistence
 * all consult the same decision, so a consent is never required or recorded when it was not offered.
 */
final class CheckoutConsents {

	private const FIELD_DIGITAL = 'recesso_dig_consent_digital';
	private const FIELD_SERVICE = 'recesso_dig_consent_service';

	/**
	 * The two consents, as passed to the public filters. Short, stable keys rather than field names or
	 * classification constants, so an integrator's code does not break if either is renamed.
	 */
	public const CONSENT_DIGITAL = 'digital';
	public const CONSENT_SERVICE = 'service';

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
	 * Art. 59 classification resolver (product/category withdrawal status).
	 *
	 * @var EligibilityAdapter
	 */
	private EligibilityAdapter $eligibility;

	/**
	 * Construct the provider.
	 *
	 * @param Settings           $settings    Settings reader.
	 * @param Clock              $clock       Clock.
	 * @param EligibilityAdapter $eligibility Classification resolver.
	 */
	public function __construct( Settings $settings, Clock $clock, EligibilityAdapter $eligibility ) {
		$this->settings    = $settings;
		$this->clock       = $clock;
		$this->eligibility = $eligibility;
	}

	/**
	 * Hook the checkout consents, only when at least one is enabled.
	 */
	public function register(): void {
		if ( ! $this->settings->consent_digital_enabled() && ! $this->settings->consent_service_enabled() ) {
			return;
		}

		/**
		 * Filter the classic-checkout hook the consent checkboxes render on.
		 *
		 * Applies to the classic (shortcode) checkout only — the Checkout block places its fields
		 * through the Additional Checkout Fields API, which decides their position itself. Return an
		 * empty string to suppress the render entirely and place the checkboxes yourself.
		 *
		 * @param string $hook Hook name (default `woocommerce_review_order_before_submit`).
		 */
		$hook = (string) apply_filters( 'recesso_dig_consent_render_hook', 'woocommerce_review_order_before_submit' );

		if ( '' !== $hook ) {
			/**
			 * Filter the priority the consent checkboxes render at (classic checkout only).
			 *
			 * @param int    $priority Hook priority (default 10).
			 * @param string $hook     The hook being attached to.
			 */
			$priority = (int) apply_filters( 'recesso_dig_consent_render_priority', 10, $hook );

			add_action( $hook, array( $this, 'render' ), $priority );
		}

		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
	}

	/**
	 * Render the applicable consent checkboxes before the place-order button.
	 */
	public function render(): void {
		if ( $this->show_digital() ) {
			$this->render_checkbox( self::FIELD_DIGITAL, $this->settings->consent_digital_text(), $this->is_required( self::CONSENT_DIGITAL ) );
		}
		if ( $this->show_service() ) {
			$this->render_checkbox( self::FIELD_SERVICE, $this->settings->consent_service_text(), $this->is_required( self::CONSENT_SERVICE ) );
		}
	}

	/**
	 * Whether a consent must be ticked before the order can be placed.
	 *
	 * The art. 14(4)(a) consent used to be unconditionally optional, on the reasoning that it only
	 * entitles the merchant to a proportionate payment and so cannot be a condition of buying. That
	 * remains the default. It is now a merchant setting because for a shop whose service always begins
	 * inside the withdrawal window — a live session, a booking for tomorrow — an order placed without
	 * that request is one the shop cannot actually fulfil.
	 *
	 * TODO(legal): requiring it removes the consumer's choice about early performance. Confirm with a
	 * legal advisor before recommending it to merchants; it stays off by default until then.
	 *
	 * @param string $consent One of the CONSENT_* keys.
	 */
	public function is_required( string $consent ): bool {
		$required = self::CONSENT_SERVICE === $consent
			? $this->settings->consent_service_required()
			: $this->settings->consent_digital_required();

		/**
		 * Filter whether a checkout consent is required to place the order.
		 *
		 * Must not depend on cart contents: the Checkout block registers its fields once per request,
		 * before any cart is known, so a cart-dependent answer would apply inconsistently across the
		 * two checkouts. Use `recesso_dig_consent_applies` to vary by cart.
		 *
		 * @param bool   $required Whether the consent is required.
		 * @param string $consent  One of `digital` | `service`.
		 */
		return (bool) apply_filters( 'recesso_dig_consent_required', $required, $consent );
	}

	/**
	 * Whether the digital-content consent (art. 16(m)) applies to the current cart.
	 */
	public function show_digital(): bool {
		return $this->settings->consent_digital_enabled()
			&& $this->applies_to_cart( Settings::STATUS_ART16M_DIGITAL );
	}

	/**
	 * Whether the service-start consent (art. 14(4)(a)) applies to the current cart.
	 */
	public function show_service(): bool {
		return $this->settings->consent_service_enabled()
			&& $this->applies_to_cart( Settings::STATUS_ART14_4A_SERVICE );
	}

	/**
	 * Whether a consent's classification is present in the cart. In the default (non-conditional) mode
	 * an enabled consent always applies, preserving the behaviour of earlier versions.
	 *
	 * @param string $status The classification the consent belongs to.
	 */
	private function applies_to_cart( string $status ): bool {
		$applies = $this->settings->consents_conditional()
			? isset( $this->cart_statuses()[ $status ] )
			: true;

		/**
		 * Filter whether a checkout consent applies to the current cart.
		 *
		 * The single decision point for both checkouts: the Checkout block reads it through the Store
		 * API cart data that drives its `hidden` rule, so classic and block behave identically.
		 *
		 * @param bool   $applies Whether the consent is offered for this cart.
		 * @param string $consent One of `digital` | `service`.
		 */
		return (bool) apply_filters(
			'recesso_dig_consent_applies',
			$applies,
			Settings::STATUS_ART14_4A_SERVICE === $status ? self::CONSENT_SERVICE : self::CONSENT_DIGITAL
		);
	}

	/**
	 * The set of art. 59 classifications present in the current cart. Deliberately not memoised: the
	 * cart can change within a single request (a Store API add-item response is built after the cart
	 * was modified), and the lookups it performs are served from the WordPress meta cache.
	 *
	 * Returns an empty set when the cart is unavailable (e.g. a context without a customer session),
	 * which in conditional mode means no consent is offered — the visible, fail-closed choice.
	 *
	 * @return array<string, bool>
	 */
	private function cart_statuses(): array {
		$statuses = array();

		if ( ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return $statuses;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			// The classification lives on the parent product (and its categories), so a variation
			// line resolves through its parent id — the same id the eligibility engine uses.
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( $product_id < 1 ) {
				continue;
			}

			$status = $this->eligibility->product_status( $product_id );
			if ( '' !== $status ) {
				$statuses[ $status ] = true;
			}
		}

		return $statuses;
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
	 * Block checkout when a consent that is required has not been given.
	 */
	public function validate(): void {
		if ( $this->show_digital() && $this->is_required( self::CONSENT_DIGITAL ) ) {
			$this->require_field( self::FIELD_DIGITAL );
		}

		if ( $this->show_service() && $this->is_required( self::CONSENT_SERVICE ) ) {
			$this->require_field( self::FIELD_SERVICE );
		}
	}

	/**
	 * Add a checkout error when a required consent checkbox was not ticked.
	 *
	 * @param string $field The consent field name.
	 */
	private function require_field( string $field ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before woocommerce_checkout_process.
		if ( ! isset( $_POST[ $field ] ) ) {
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

		// This hook belongs to the classic checkout, which posts a form. During a REST request the
		// order comes from the Store API (the Checkout block), where the consents are captured by
		// {@see BlockCheckoutConsents} instead — reading $_POST here would record a false "declined".
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$now = $this->clock->now_gmt();

		if ( $this->show_digital() ) {
			$this->record( $order, self::FIELD_DIGITAL, Settings::META_CONSENT_DIGITAL, Settings::META_CONSENT_DIGITAL_AT, $now, __( 'Digital-content consent (art. 16(m))', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		}
		if ( $this->show_service() ) {
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
