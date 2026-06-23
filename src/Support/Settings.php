<?php
/**
 * Plugin settings reader.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Typed, defensive reader for the plugin's configuration options. Defaults are conservative: the
 * art. 59 default policy is "unconfigured", so products the merchant has not classified fail closed.
 */
final class Settings {

	public const OPT_WINDOW_DAYS         = 'recesso_dig_window_days';
	public const OPT_START_TRIGGER       = 'recesso_dig_start_trigger';
	public const OPT_DEFAULT_POLICY      = 'recesso_dig_default_policy';
	public const OPT_EXCLUDED_PRODUCTS   = 'recesso_dig_excluded_products';
	public const OPT_ALLOWED_PRODUCTS    = 'recesso_dig_allowed_products';
	public const OPT_EXCLUDED_CATEGORIES = 'recesso_dig_excluded_categories';
	public const OPT_ALLOWED_CATEGORIES  = 'recesso_dig_allowed_categories';

	public const OPT_PRODUCT_NOTICE_ENABLED = 'recesso_dig_product_notice_enabled';
	public const OPT_PRODUCT_NOTICE_TEXT    = 'recesso_dig_product_notice_text';

	public const OPT_CONSENT_DIGITAL_ENABLED  = 'recesso_dig_consent_digital_enabled';
	public const OPT_CONSENT_DIGITAL_REQUIRED = 'recesso_dig_consent_digital_required';
	public const OPT_CONSENT_DIGITAL_TEXT     = 'recesso_dig_consent_digital_text';
	public const OPT_CONSENT_SERVICE_ENABLED  = 'recesso_dig_consent_service_enabled';
	public const OPT_CONSENT_SERVICE_TEXT     = 'recesso_dig_consent_service_text';

	public const OPT_TRADER_NAME    = 'recesso_dig_trader_name';
	public const OPT_TRADER_ADDRESS = 'recesso_dig_trader_address';
	public const OPT_TRADER_PHONE   = 'recesso_dig_trader_phone';
	public const OPT_TRADER_EMAIL   = 'recesso_dig_trader_email';

	public const OPT_MODEL_FORM_ENABLED = 'recesso_dig_model_form_enabled';

	public const OPT_ADMIN_RECIPIENTS    = 'recesso_dig_admin_recipients';
	public const OPT_FOOTER_LINK_ENABLED = 'recesso_dig_footer_link_enabled';
	public const OPT_ENFORCEMENT_MODE    = 'recesso_dig_enforcement_mode';
	public const OPT_GRACE_DAYS          = 'recesso_dig_grace_days';

	public const ENFORCEMENT_ADVISORY = 'advisory';
	public const ENFORCEMENT_STRICT   = 'strict';

	/**
	 * Order meta recording the checkout consents and the moment each was given (GMT).
	 */
	public const META_CONSENT_DIGITAL    = '_recesso_dig_consent_digital';
	public const META_CONSENT_DIGITAL_AT = '_recesso_dig_consent_digital_at';
	public const META_CONSENT_SERVICE    = '_recesso_dig_consent_service';
	public const META_CONSENT_SERVICE_AT = '_recesso_dig_consent_service_at';

	/**
	 * Per-product / per-category art. 59 withdrawal status. The product meta is a post meta on the
	 * product; the category status is a term meta on the product_cat term. The empty value means
	 * "inherit" (fall through to the next, less specific level).
	 */
	public const META_PRODUCT_STATUS = '_recesso_dig_withdrawal';
	public const META_TERM_STATUS    = 'recesso_dig_withdrawal';

	public const STATUS_INHERIT = '';

	/**
	 * Legacy two-state values, still recognised when reading meta written by earlier versions so the
	 * mapping stays backwards-compatible. New configuration uses the art. 59 / Directive classifications
	 * below.
	 */
	public const STATUS_ALLOW   = 'allow';
	public const STATUS_EXCLUDE = 'exclude';

	/**
	 * Per-product / per-category withdrawal classification (the values offered in the product and
	 * category editors). Each maps to "withdrawal applies" or "excluded" for the eligibility engine; a
	 * few also signal the relevant checkout consent (art. 16(m) digital content, art. 14(4)(a) services).
	 */
	public const STATUS_STANDARD             = 'standard';
	public const STATUS_ART16M_DIGITAL       = 'art16m_digital';
	public const STATUS_ART14_4A_SERVICE     = 'art14_4a_service';
	public const STATUS_ART16L_ACCOMMODATION = 'art16l_accommodation';
	public const STATUS_ART16_OTHER          = 'art16_other';

	/**
	 * Statuses that exclude the line from the right of withdrawal (art. 59 / art. 16 exceptions).
	 *
	 * @return string[]
	 */
	public static function excluding_statuses(): array {
		return array(
			self::STATUS_EXCLUDE,
			self::STATUS_ART16M_DIGITAL,
			self::STATUS_ART16L_ACCOMMODATION,
			self::STATUS_ART16_OTHER,
		);
	}

	/**
	 * Statuses under which the right of withdrawal applies.
	 *
	 * @return string[]
	 */
	public static function allowing_statuses(): array {
		return array(
			self::STATUS_ALLOW,
			self::STATUS_STANDARD,
			self::STATUS_ART14_4A_SERVICE,
		);
	}

	public const TRIGGER_DELIVERY   = 'delivery';
	public const TRIGGER_CONCLUSION = 'conclusion';

	public const POLICY_ALLOW        = 'allow';
	public const POLICY_EXCLUDE      = 'exclude';
	public const POLICY_UNCONFIGURED = 'unconfigured';

	/**
	 * Withdrawal window length in days (art. 52 default: 14).
	 */
	public function window_days(): int {
		$days = (int) get_option( self::OPT_WINDOW_DAYS, 14 );

		return $days > 0 ? $days : 14;
	}

	/**
	 * When the window starts: at delivery (goods) or at conclusion (services).
	 */
	public function start_trigger(): string {
		$trigger = (string) get_option( self::OPT_START_TRIGGER, self::TRIGGER_DELIVERY );

		return self::TRIGGER_CONCLUSION === $trigger ? self::TRIGGER_CONCLUSION : self::TRIGGER_DELIVERY;
	}

	/**
	 * Default art. 59 policy for products without explicit configuration. Defaults to "allow" so the
	 * withdrawal function stays continuously available; the merchant excludes specific products or
	 * categories (or switches to the fail-closed "unconfigured" policy) as their art. 59 review
	 * requires. The merchant remains the decision-maker on each individual request.
	 */
	public function default_policy(): string {
		$policy = (string) get_option( self::OPT_DEFAULT_POLICY, self::POLICY_ALLOW );

		return in_array( $policy, array( self::POLICY_ALLOW, self::POLICY_EXCLUDE, self::POLICY_UNCONFIGURED ), true )
			? $policy
			: self::POLICY_ALLOW;
	}

	/**
	 * Product ids explicitly excluded from withdrawal (art. 59).
	 *
	 * @return int[]
	 */
	public function excluded_product_ids(): array {
		return $this->id_list( self::OPT_EXCLUDED_PRODUCTS );
	}

	/**
	 * Product ids explicitly allowed for withdrawal.
	 *
	 * @return int[]
	 */
	public function allowed_product_ids(): array {
		return $this->id_list( self::OPT_ALLOWED_PRODUCTS );
	}

	/**
	 * Category ids explicitly excluded from withdrawal (art. 59).
	 *
	 * @return int[]
	 */
	public function excluded_category_ids(): array {
		return $this->id_list( self::OPT_EXCLUDED_CATEGORIES );
	}

	/**
	 * Category ids explicitly allowed for withdrawal.
	 *
	 * @return int[]
	 */
	public function allowed_category_ids(): array {
		return $this->id_list( self::OPT_ALLOWED_CATEGORIES );
	}

	/**
	 * Whether to show a public "excluded from withdrawal" notice on the single-product page for
	 * products the merchant has marked as excluded under art. 59. Opt-in (off by default).
	 */
	public function product_notice_enabled(): bool {
		return '1' === (string) get_option( self::OPT_PRODUCT_NOTICE_ENABLED, '0' );
	}

	/**
	 * The notice text shown on excluded products' pages. Falls back to a neutral default the merchant
	 * should review against their art. 59 assessment.
	 */
	public function product_notice_text(): string {
		$text = (string) get_option( self::OPT_PRODUCT_NOTICE_TEXT, '' );
		if ( '' !== trim( $text ) ) {
			return $text;
		}

		// Neutral default wording for an art. 59 exclusion; the merchant adapts it to the specific
		// exception (made-to-order, sealed goods, digital content, etc.).
		return __( 'For this product the right of withdrawal does not apply (art. 59 of the Italian Consumer Code).', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Whether the mandatory digital-content consent (art. 16(m)) is shown at checkout.
	 */
	public function consent_digital_enabled(): bool {
		return '1' === (string) get_option( self::OPT_CONSENT_DIGITAL_ENABLED, '0' );
	}

	/**
	 * Whether the digital-content consent must be ticked to place the order. Off by default: the
	 * merchant opts in, since blocking checkout is a deliberate, catalogue-specific choice.
	 */
	public function consent_digital_required(): bool {
		return '1' === (string) get_option( self::OPT_CONSENT_DIGITAL_REQUIRED, '0' );
	}

	/**
	 * The digital-content consent label. Falls back to a neutral default the merchant should review.
	 */
	public function consent_digital_text(): string {
		$text = (string) get_option( self::OPT_CONSENT_DIGITAL_TEXT, '' );
		if ( '' !== trim( $text ) ) {
			return $text;
		}

		// art. 16(m) — express request that performance of digital content begins during the
		// withdrawal period and acknowledgement that the right of withdrawal is thereby lost.
		// Neutral default wording the merchant adapts.
		return __( 'I request immediate access to the digital content and acknowledge that I therefore lose my right of withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Whether the optional services consent (art. 14(4)(a)) is shown at checkout.
	 */
	public function consent_service_enabled(): bool {
		return '1' === (string) get_option( self::OPT_CONSENT_SERVICE_ENABLED, '0' );
	}

	/**
	 * The optional services consent label. Falls back to a neutral default the merchant should review.
	 */
	public function consent_service_text(): string {
		$text = (string) get_option( self::OPT_CONSENT_SERVICE_TEXT, '' );
		if ( '' !== trim( $text ) ) {
			return $text;
		}

		// art. 14(4)(a) — request that the service begins during the withdrawal period, with the
		// consumer owing a proportionate amount if they later withdraw. Neutral default wording the
		// merchant adapts.
		return __( 'I request that the service begins during the withdrawal period.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * The trader/merchant name for the Annex I.B model withdrawal form. Falls back to the site name.
	 */
	public function trader_name(): string {
		$value = (string) get_option( self::OPT_TRADER_NAME, '' );

		return '' !== trim( $value ) ? $value : (string) get_bloginfo( 'name' );
	}

	/**
	 * The trader postal address for the model form. Falls back to the WooCommerce store address.
	 */
	public function trader_address(): string {
		$value = (string) get_option( self::OPT_TRADER_ADDRESS, '' );
		if ( '' !== trim( $value ) ) {
			return $value;
		}

		$parts = array(
			(string) get_option( 'woocommerce_store_address', '' ),
			(string) get_option( 'woocommerce_store_address_2', '' ),
			trim( (string) get_option( 'woocommerce_store_postcode', '' ) . ' ' . (string) get_option( 'woocommerce_store_city', '' ) ),
		);

		return trim( implode( ', ', array_filter( array_map( 'trim', $parts ) ) ) );
	}

	/**
	 * The trader telephone for the model form. Optional; empty when not configured.
	 */
	public function trader_phone(): string {
		return trim( (string) get_option( self::OPT_TRADER_PHONE, '' ) );
	}

	/**
	 * The trader email for the model form. Falls back to the admin email.
	 */
	public function trader_email(): string {
		$value = (string) get_option( self::OPT_TRADER_EMAIL, '' );

		return is_email( $value ) ? $value : (string) get_option( 'admin_email', '' );
	}

	/**
	 * Whether the Annex I.B model withdrawal form is shown below the public withdrawal form. On by
	 * default (the statutory model form is informational and free to display).
	 */
	public function model_form_enabled(): bool {
		return '0' !== (string) get_option( self::OPT_MODEL_FORM_ENABLED, '1' );
	}

	/**
	 * Whether the withdrawal window is enforced strictly (a hard gate) rather than advisory. Defaults to
	 * advisory: the function stays continuously available and the merchant decides each request.
	 */
	public function enforcement_strict(): bool {
		return self::ENFORCEMENT_STRICT === (string) get_option( self::OPT_ENFORCEMENT_MODE, self::ENFORCEMENT_ADVISORY );
	}

	/**
	 * Extra days added to the window end before it closes in strict mode.
	 */
	public function grace_days(): int {
		return max( 0, (int) get_option( self::OPT_GRACE_DAYS, 0 ) );
	}

	/**
	 * Whether to render a persistent withdrawal link in the site footer (opt-in), reinforcing that the
	 * withdrawal function is continuously available and easily accessible.
	 */
	public function footer_link_enabled(): bool {
		return '1' === (string) get_option( self::OPT_FOOTER_LINK_ENABLED, '0' );
	}

	/**
	 * Admin notification recipients (valid email addresses). Falls back to the site admin email.
	 *
	 * @return string[]
	 */
	public function admin_recipients(): array {
		$raw   = (string) get_option( self::OPT_ADMIN_RECIPIENTS, '' );
		$parts = preg_split( '/[,\n]/', $raw );
		if ( ! is_array( $parts ) ) {
			$parts = array();
		}

		$emails = array();
		foreach ( $parts as $candidate ) {
			$candidate = sanitize_email( trim( (string) $candidate ) );
			if ( is_email( $candidate ) ) {
				$emails[] = $candidate;
			}
		}

		if ( array() === $emails ) {
			$fallback = sanitize_email( (string) get_option( 'admin_email', '' ) );
			if ( is_email( $fallback ) ) {
				$emails[] = $fallback;
			}
		}

		return array_values( array_unique( $emails ) );
	}

	/**
	 * Read an option as a list of positive integer ids.
	 *
	 * @param string $option Option name.
	 *
	 * @return int[]
	 */
	private function id_list( string $option ): array {
		$value = get_option( $option, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();
		foreach ( $value as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
