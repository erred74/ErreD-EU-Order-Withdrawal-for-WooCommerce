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

	public const OPT_WINDOW_DAYS    = 'recesso_dig_window_days';
	public const OPT_START_TRIGGER  = 'recesso_dig_start_trigger';
	public const OPT_DEFAULT_POLICY = 'recesso_dig_default_policy';

	/**
	 * Legacy allow/exclude id lists, superseded by the per-product and per-category "Withdrawal
	 * status" dropdowns and therefore no longer offered on the settings screen. They are still read
	 * — and still sit between the product status and the category status in the resolution chain —
	 * so a site configured before the dropdowns existed, or one that sets them from code, keeps
	 * behaving exactly as it did. Nothing writes them any more.
	 */
	public const OPT_EXCLUDED_PRODUCTS   = 'recesso_dig_excluded_products';
	public const OPT_ALLOWED_PRODUCTS    = 'recesso_dig_allowed_products';
	public const OPT_EXCLUDED_CATEGORIES = 'recesso_dig_excluded_categories';
	public const OPT_ALLOWED_CATEGORIES  = 'recesso_dig_allowed_categories';

	public const OPT_PRODUCT_NOTICE_ENABLED = 'recesso_dig_product_notice_enabled';
	public const OPT_PRODUCT_NOTICE_TEXT    = 'recesso_dig_product_notice_text';

	/**
	 * Per-exception wording for the product-page exclusion notice. Each pair is optional: an empty
	 * field falls back to the bundled default for that exception, which follows the visitor's language.
	 */
	public const OPT_NOTICE_DIGITAL_TITLE = 'recesso_dig_notice_digital_title';
	public const OPT_NOTICE_DIGITAL_BODY  = 'recesso_dig_notice_digital_body';
	public const OPT_NOTICE_DATED_TITLE   = 'recesso_dig_notice_dated_title';
	public const OPT_NOTICE_DATED_BODY    = 'recesso_dig_notice_dated_body';
	public const OPT_NOTICE_OTHER_TITLE   = 'recesso_dig_notice_other_title';
	public const OPT_NOTICE_OTHER_BODY    = 'recesso_dig_notice_other_body';

	/**
	 * Sender identity and status-email wording.
	 */
	public const OPT_EMAIL_FROM_NAME      = 'recesso_dig_email_from_name';
	public const OPT_EMAIL_FROM_ADDRESS   = 'recesso_dig_email_from_address';
	public const OPT_EMAIL_ACCEPTED_TEXT  = 'recesso_dig_email_accepted_text';
	public const OPT_EMAIL_REJECTED_TEXT  = 'recesso_dig_email_rejected_text';
	public const OPT_EMAIL_COMPLETED_TEXT = 'recesso_dig_email_completed_text';

	/**
	 * Public withdrawal form: the optional intro paragraph and the optional consumer self-declaration.
	 */
	public const OPT_FORM_INTRO_ENABLED           = 'recesso_dig_form_intro_enabled';
	public const OPT_FORM_INTRO_TEXT              = 'recesso_dig_form_intro_text';
	public const OPT_CONSUMER_DECLARATION_ENABLED = 'recesso_dig_consumer_declaration_enabled';
	public const OPT_CONSUMER_DECLARATION_TEXT    = 'recesso_dig_consumer_declaration_text';

	/**
	 * Wording of the order-lookup screen — the first thing a consumer sees when they reach the
	 * withdrawal page without a signed link. Each field is optional: an empty one falls back to the
	 * bundled sentence, which follows the visitor's language.
	 */
	public const OPT_LOOKUP_TITLE      = 'recesso_dig_lookup_title';
	public const OPT_LOOKUP_INTRO      = 'recesso_dig_lookup_intro';
	public const OPT_LOOKUP_EMAIL_HINT = 'recesso_dig_lookup_email_hint';
	public const OPT_LOOKUP_SUBMIT     = 'recesso_dig_lookup_submit';

	/**
	 * Appearance of the flow's own buttons. The accent is validated as a hex colour on save and again
	 * on read ({@see \Recesso54bis\Support\Color::hex()}), because it ends up inside a stylesheet.
	 */
	public const OPT_BUTTON_ACCENT = 'recesso_dig_button_accent';
	public const OPT_BUTTON_STYLE  = 'recesso_dig_button_style';

	public const BUTTON_STYLE_PLUGIN = 'plugin';
	public const BUTTON_STYLE_THEME  = 'theme';

	/**
	 * The bundled accent. Must stay identical to `--recesso-dig-accent` in
	 * `assets/frontend/style.css`: when the two agree, an unconfigured site needs no inline CSS at all.
	 */
	public const DEFAULT_ACCENT = '#c8102e';

	public const OPT_CONSENT_DIGITAL_ENABLED  = 'recesso_dig_consent_digital_enabled';
	public const OPT_CONSENT_DIGITAL_REQUIRED = 'recesso_dig_consent_digital_required';
	public const OPT_CONSENT_DIGITAL_TEXT     = 'recesso_dig_consent_digital_text';
	public const OPT_CONSENT_SERVICE_ENABLED  = 'recesso_dig_consent_service_enabled';
	public const OPT_CONSENT_SERVICE_REQUIRED = 'recesso_dig_consent_service_required';
	public const OPT_CONSENT_SERVICE_TEXT     = 'recesso_dig_consent_service_text';
	public const OPT_CONSENTS_CONDITIONAL     = 'recesso_dig_consents_conditional';

	public const OPT_TRADER_NAME    = 'recesso_dig_trader_name';
	public const OPT_TRADER_ADDRESS = 'recesso_dig_trader_address';
	public const OPT_TRADER_PHONE   = 'recesso_dig_trader_phone';
	public const OPT_TRADER_EMAIL   = 'recesso_dig_trader_email';

	public const OPT_MODEL_FORM_ENABLED = 'recesso_dig_model_form_enabled';

	public const OPT_ADMIN_RECIPIENTS    = 'recesso_dig_admin_recipients';
	public const OPT_FOOTER_LINK_ENABLED = 'recesso_dig_footer_link_enabled';
	public const OPT_ACCOUNT_ENDPOINT    = 'recesso_dig_account_endpoint_enabled';
	public const OPT_ENFORCEMENT_MODE    = 'recesso_dig_enforcement_mode';
	public const OPT_GRACE_DAYS          = 'recesso_dig_grace_days';
	public const OPT_ELIGIBLE_STATUSES   = 'recesso_dig_eligible_statuses';
	public const OPT_MANAGE_ROLES        = 'recesso_dig_manage_roles';

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
	 * Every recognised per-product / per-category classification, legacy values included. A value
	 * outside this list (including the empty string) means "inherit from the next level".
	 *
	 * @return string[]
	 */
	public static function known_statuses(): array {
		return array_merge( self::excluding_statuses(), self::allowing_statuses() );
	}

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
	 * The exclusion-notice heading and body for a given art. 16 exception. Each merchant-set field
	 * wins; an empty one falls back to the bundled wording, which follows the visitor's language.
	 *
	 * The generic pair configured under {@see self::OPT_PRODUCT_NOTICE_TEXT} by earlier versions is
	 * still honoured as the body for the "other exception" case, so an existing configuration keeps
	 * showing exactly the text the merchant wrote.
	 *
	 * @param string $status The product's resolved classification (a Settings::STATUS_* value).
	 *
	 * @return array{title: string, body: string}
	 */
	public function exclusion_notice( string $status ): array {
		if ( self::STATUS_ART16M_DIGITAL === $status ) {
			return array(
				'title' => $this->text_or( self::OPT_NOTICE_DIGITAL_TITLE, __( 'Digital content — the right of withdrawal is lost on access', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
				'body'  => $this->text_or( self::OPT_NOTICE_DIGITAL_BODY, __( 'This is digital content under Article 16(m) of Directive 2011/83/EU. By starting to access, download or view it after purchase you expressly request its immediate supply and acknowledge that you thereby lose the right of withdrawal. {withdrawal_page_link}', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
			);
		}

		if ( self::STATUS_ART16L_ACCOMMODATION === $status ) {
			return array(
				'title' => $this->text_or( self::OPT_NOTICE_DATED_TITLE, __( 'Dated service — excluded from the right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
				'body'  => $this->text_or( self::OPT_NOTICE_DATED_BODY, __( 'This service is excluded from the right of withdrawal under Article 16(l) of Directive 2011/83/EU, which covers accommodation other than for residential purposes, transport of goods, vehicle rental, catering and leisure services when the contract sets a specific date or period of performance. Please check the booking conditions before purchasing. {withdrawal_page_link}', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
			);
		}

		// Any other art. 16 / art. 59 exception: perishable, made to measure, hygiene-sealed, sealed
		// audio/video/software unsealed after delivery, and the rest.
		$legacy = (string) get_option( self::OPT_PRODUCT_NOTICE_TEXT, '' );

		return array(
			'title' => $this->text_or( self::OPT_NOTICE_OTHER_TITLE, __( 'Excluded from the right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
			'body'  => $this->text_or(
				self::OPT_NOTICE_OTHER_BODY,
				'' !== trim( $legacy )
					? $legacy
					: __( 'This product is excluded from the right of withdrawal under one of the exceptions in Article 16 of Directive 2011/83/EU (perishable goods, made-to-measure or personalised items, hygiene-sealed goods, sealed audio, video or software media unsealed after delivery, and similar). Please check the conditions before purchasing. {withdrawal_page_link}', 'erred-eu-order-withdrawal-for-woocommerce' )
			),
		);
	}

	/**
	 * The sender name for the plugin's own emails, or an empty string to use the WooCommerce default.
	 */
	public function email_from_name(): string {
		return trim( (string) get_option( self::OPT_EMAIL_FROM_NAME, '' ) );
	}

	/**
	 * The sender address for the plugin's own emails, or an empty string to use the WooCommerce
	 * default. Only a valid address is ever returned, so a typo can never break delivery.
	 */
	public function email_from_address(): string {
		$value = sanitize_email( (string) get_option( self::OPT_EMAIL_FROM_ADDRESS, '' ) );

		return is_email( $value ) ? $value : '';
	}

	/**
	 * The merchant's wording for a status-change email, or the bundled default when unset.
	 *
	 * @param string $status The request status the email announces.
	 */
	public function status_email_text( string $status ): string {
		return match ( $status ) {
			'accepted' => $this->text_or( self::OPT_EMAIL_ACCEPTED_TEXT, __( 'We have accepted your withdrawal request and will proceed with the refund within the legal deadline.', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
			'rejected' => $this->text_or( self::OPT_EMAIL_REJECTED_TEXT, __( 'We have reviewed your withdrawal request and are unable to accept it.', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
			default    => $this->text_or( self::OPT_EMAIL_COMPLETED_TEXT, __( 'Your withdrawal has been processed and the refund issued. The funds may take a few business days to reach your account.', 'erred-eu-order-withdrawal-for-woocommerce' ) ),
		};
	}

	/**
	 * Whether the intro paragraph is shown above the public withdrawal form. On by default: it tells
	 * the consumer what submitting the form does, which is worth saying.
	 */
	public function form_intro_enabled(): bool {
		return '0' !== (string) get_option( self::OPT_FORM_INTRO_ENABLED, '1' );
	}

	/**
	 * The intro paragraph shown above the public withdrawal form.
	 *
	 * @param string $contract_reference The order/contract reference, interpolated into the default.
	 */
	public function form_intro_text( string $contract_reference ): string {
		$custom = (string) get_option( self::OPT_FORM_INTRO_TEXT, '' );
		if ( '' !== trim( $custom ) ) {
			return $custom;
		}

		return sprintf(
			/* translators: %s: order/contract reference. */
			__( 'You are exercising your right of withdrawal for order %s. Please confirm your details below.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			$contract_reference
		);
	}

	/**
	 * Whether the consumer must tick a "I bought as a consumer" self-declaration on the form. Off by
	 * default: it is a good-faith declaration, not a legal guarantee, and it adds a required field.
	 */
	public function consumer_declaration_enabled(): bool {
		return '1' === (string) get_option( self::OPT_CONSUMER_DECLARATION_ENABLED, '0' );
	}

	/**
	 * The wording of the consumer self-declaration checkbox.
	 */
	public function consumer_declaration_text(): string {
		return $this->text_or(
			self::OPT_CONSUMER_DECLARATION_TEXT,
			__( 'I confirm that I made this purchase as a consumer, that is, as a natural person acting for purposes outside my trade, business, craft or profession.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * The heading of the order-lookup screen.
	 *
	 * The bundled defaults of the four lookup getters are repeated verbatim in
	 * `templates/frontend/lookup.php`, which must stay self-contained because a theme may override it.
	 * gettext folds the duplicates into a single catalogue entry, so keep the wording byte-identical.
	 */
	public function lookup_title(): string {
		return $this->text_or(
			self::OPT_LOOKUP_TITLE,
			__( 'Exercise your right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * The intro paragraph of the order-lookup screen.
	 */
	public function lookup_intro(): string {
		return $this->text_or(
			self::OPT_LOOKUP_INTRO,
			__( 'Enter your order number and the email address you used for the order. We will send a secure withdrawal link to that email address.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * The hint shown under the email field of the order-lookup screen.
	 */
	public function lookup_email_hint(): string {
		return $this->text_or(
			self::OPT_LOOKUP_EMAIL_HINT,
			__( 'The link is sent to this address only, so it must be the one on the order.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * The label of the order-lookup screen's submit button.
	 */
	public function lookup_submit_label(): string {
		return $this->text_or(
			self::OPT_LOOKUP_SUBMIT,
			__( 'Send me the withdrawal link', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * The accent colour of the flow's buttons, re-validated on read: a value written straight to the
	 * options table, bypassing the settings screen's sanitiser, must never reach the stylesheet.
	 */
	public function button_accent(): string {
		$accent = Color::hex( (string) get_option( self::OPT_BUTTON_ACCENT, '' ) );

		return '' !== $accent ? $accent : self::DEFAULT_ACCENT;
	}

	/**
	 * Whether the flow's buttons carry the plugin's own styling or inherit the theme's. An
	 * unrecognised stored value falls back to the plugin's own styling, which is what the settings
	 * screen documents as the default — and the only one guaranteed to render a visible control on a
	 * plain page where the theme's `.button` rules may not load.
	 */
	public function button_style(): string {
		$style = (string) get_option( self::OPT_BUTTON_STYLE, self::BUTTON_STYLE_PLUGIN );

		return self::BUTTON_STYLE_THEME === $style ? self::BUTTON_STYLE_THEME : self::BUTTON_STYLE_PLUGIN;
	}

	/**
	 * Whether the flow's buttons are left to the theme.
	 */
	public function button_uses_theme_style(): bool {
		return self::BUTTON_STYLE_THEME === $this->button_style();
	}

	/**
	 * Read a text option, falling back to a bundled default when the merchant left it empty.
	 *
	 * @param string $option  Option name.
	 * @param string $bundled Bundled default, already translated.
	 */
	private function text_or( string $option, string $bundled ): string {
		$value = (string) get_option( $option, '' );

		return '' !== trim( $value ) ? $value : $bundled;
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
	 * Whether the services consent (art. 14(4)(a)) must be ticked to place the order.
	 *
	 * Off by default, and deliberately so: asking for the service to start inside the withdrawal
	 * period is the consumer's request to make, and it only entitles the merchant to a proportionate
	 * payment if they later withdraw. Shops whose service always begins inside that window — live
	 * sessions, bookings for the next few days — can turn it on, because for them an order placed
	 * without that request is one they cannot fulfil.
	 *
	 * TODO(legal): confirm the framing above with a legal advisor before this is recommended rather
	 * than merely offered.
	 */
	public function consent_service_required(): bool {
		return '1' === (string) get_option( self::OPT_CONSENT_SERVICE_REQUIRED, '0' );
	}

	/**
	 * Whether each enabled consent is shown only when the cart actually contains a product classified
	 * for it (art. 16(m) digital content / art. 14(4)(a) early-started service), instead of on every
	 * checkout. Off by default so updating the plugin never changes what an existing checkout shows;
	 * merchants opt in from the settings screen.
	 */
	public function consents_conditional(): bool {
		return '1' === (string) get_option( self::OPT_CONSENTS_CONDITIONAL, '0' );
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
	 * Whether the "Right of withdrawal" tab is added to the WooCommerce My Account area. On by
	 * default: the withdrawal function must be easily accessible for the whole period it can be
	 * exercised, and the tab is the discoverable route for logged-in customers.
	 */
	public function account_endpoint_enabled(): bool {
		return '0' !== (string) get_option( self::OPT_ACCOUNT_ENDPOINT, '1' );
	}

	/**
	 * The WooCommerce order statuses (without the `wc-` prefix) from which a withdrawal may be
	 * declared. Defaults to processing and completed — the states in which a distance contract has
	 * actually been concluded and, usually, performed.
	 *
	 * @return string[]
	 */
	public function eligible_statuses(): array {
		$stored = get_option( self::OPT_ELIGIBLE_STATUSES, null );
		if ( ! is_array( $stored ) ) {
			return array( 'processing', 'completed' );
		}

		$statuses = array();
		foreach ( $stored as $status ) {
			$status = sanitize_key( (string) $status );
			if ( '' !== $status ) {
				$statuses[] = $status;
			}
		}

		// An explicitly emptied list is a valid choice: it silences the prompt without deactivating
		// the plugin, so it is returned as-is rather than falling back to the defaults.
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * The roles, besides the administrator, allowed to view and manage withdrawal requests.
	 *
	 * @return string[]
	 */
	public function manage_roles(): array {
		$stored = get_option( self::OPT_MANAGE_ROLES, null );
		if ( ! is_array( $stored ) ) {
			// Not yet configured: keep the roles granted at activation, so updating never revokes
			// access a shop manager already had.
			return array( 'shop_manager' );
		}

		$roles = array();
		foreach ( $stored as $role ) {
			$role = sanitize_key( (string) $role );
			if ( '' !== $role && 'administrator' !== $role ) {
				$roles[] = $role;
			}
		}

		return array_values( array_unique( $roles ) );
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
