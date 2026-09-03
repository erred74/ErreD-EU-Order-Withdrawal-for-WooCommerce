<?php
/**
 * Settings registration and screen.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Frontend\FlowPage;
use Recesso54bis\Frontend\FooterLink;
use Recesso54bis\Support\Capabilities;
use Recesso54bis\Support\Color;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin settings (exposed in REST for a future React admin) and renders a minimal,
 * accessible settings screen using the WordPress Settings API.
 */
final class SettingsPage {

	private const GROUP            = 'recesso_dig';
	private const SECTION_GENERAL  = 'recesso_dig_general';
	private const SECTION          = 'recesso_dig_main';
	private const SECTION_EXCLUDE  = 'recesso_dig_exclusions';
	private const SECTION_NOTICE   = 'recesso_dig_notice';
	private const SECTION_CONSENT  = 'recesso_dig_consent';
	private const SECTION_TRADER   = 'recesso_dig_trader';
	private const SECTION_VISIBLE  = 'recesso_dig_visibility';
	private const SECTION_STATUSES = 'recesso_dig_statuses';
	private const SECTION_EMAILS   = 'recesso_dig_emails';
	private const SECTION_FORM     = 'recesso_dig_form';
	private const SECTION_LOOKUP   = 'recesso_dig_lookup';
	private const SECTION_APPEAR   = 'recesso_dig_appearance';
	private const SECTION_ROLES    = 'recesso_dig_roles';
	private const SECTION_DATA     = 'recesso_dig_data';
	public const MENU_SLUG         = 'recesso-digitale-settings';

	/**
	 * Importance tags shown at the start of every field description.
	 */
	private const TAG_MANDATORY   = 'mandatory';
	private const TAG_RECOMMENDED = 'recommended';
	private const TAG_OPTIONAL    = 'optional';

	/**
	 * Hook settings registration.
	 */
	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register settings, the section and fields.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPT_WINDOW_DAYS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'default'           => 14,
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_START_TRIGGER,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_trigger' ),
				'show_in_rest'      => true,
				'default'           => Settings::TRIGGER_DELIVERY,
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_ENFORCEMENT_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_enforcement' ),
				'show_in_rest'      => true,
				'default'           => Settings::ENFORCEMENT_ADVISORY,
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_GRACE_DAYS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'default'           => 0,
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_DEFAULT_POLICY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_policy' ),
				'show_in_rest'      => true,
				// Must match Settings::default_policy(), which is what actually runs when the option
				// has never been saved. Declaring "unconfigured" here contradicted it and would make
				// every unconfigured product ineligible on sites that open the settings screen —
				// removing the legally-required function rather than merely narrowing it.
				'default'           => Settings::POLICY_ALLOW,
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_PRODUCT_NOTICE_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'show_in_rest'      => true,
				'default'           => '0',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_PRODUCT_NOTICE_TEXT,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		foreach ( array( Settings::OPT_CONSENT_DIGITAL_ENABLED, Settings::OPT_CONSENT_DIGITAL_REQUIRED, Settings::OPT_CONSENT_SERVICE_ENABLED, Settings::OPT_CONSENT_SERVICE_REQUIRED, Settings::OPT_CONSENTS_CONDITIONAL ) as $recesso_dig_bool_opt ) {
			register_setting(
				self::GROUP,
				$recesso_dig_bool_opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_bool' ),
					'show_in_rest'      => true,
					'default'           => '0',
				)
			);
		}
		foreach ( array( Settings::OPT_CONSENT_DIGITAL_TEXT, Settings::OPT_CONSENT_SERVICE_TEXT ) as $recesso_dig_text_opt ) {
			register_setting(
				self::GROUP,
				$recesso_dig_text_opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'show_in_rest'      => true,
					'default'           => '',
				)
			);
		}
		register_setting(
			self::GROUP,
			FlowPage::OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'show_in_rest'      => true,
				'default'           => 0,
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_MODEL_FORM_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'show_in_rest'      => true,
				'default'           => '1',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_TRADER_PHONE,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_TRADER_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_TRADER_ADDRESS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_TRADER_EMAIL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_ADMIN_RECIPIENTS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_FOOTER_LINK_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'show_in_rest'      => true,
				'default'           => '0',
			)
		);
		register_setting(
			self::GROUP,
			'recesso_dig_delete_data_on_uninstall',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'show_in_rest'      => true,
				'default'           => '0',
			)
		);
		// Booleans defaulting to on: the intro paragraph and the My Account tab.
		foreach ( array( Settings::OPT_FORM_INTRO_ENABLED, Settings::OPT_ACCOUNT_ENDPOINT ) as $recesso_dig_on_opt ) {
			register_setting(
				self::GROUP,
				$recesso_dig_on_opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => array( $this, 'sanitize_bool' ),
					'show_in_rest'      => true,
					'default'           => '1',
				)
			);
		}
		register_setting(
			self::GROUP,
			Settings::OPT_CONSUMER_DECLARATION_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_bool' ),
				'show_in_rest'      => true,
				'default'           => '0',
			)
		);
		// Merchant-editable customer-facing copy. Stored empty when it matches the bundled default, so
		// the shipped wording keeps following each visitor's language.
		$recesso_dig_copy_opts = array(
			Settings::OPT_FORM_INTRO_TEXT,
			Settings::OPT_CONSUMER_DECLARATION_TEXT,
			Settings::OPT_EMAIL_ACCEPTED_TEXT,
			Settings::OPT_EMAIL_REJECTED_TEXT,
			Settings::OPT_EMAIL_COMPLETED_TEXT,
			Settings::OPT_NOTICE_DIGITAL_TITLE,
			Settings::OPT_NOTICE_DIGITAL_BODY,
			Settings::OPT_NOTICE_DATED_TITLE,
			Settings::OPT_NOTICE_DATED_BODY,
			Settings::OPT_NOTICE_OTHER_TITLE,
			Settings::OPT_NOTICE_OTHER_BODY,
			Settings::OPT_LOOKUP_TITLE,
			Settings::OPT_LOOKUP_INTRO,
			Settings::OPT_LOOKUP_EMAIL_HINT,
			Settings::OPT_LOOKUP_SUBMIT,
		);
		foreach ( $recesso_dig_copy_opts as $recesso_dig_copy_opt ) {
			register_setting(
				self::GROUP,
				$recesso_dig_copy_opt,
				array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_textarea_field',
					'show_in_rest'      => true,
					'default'           => '',
				)
			);
		}
		// The accent ends up inside a stylesheet, so it gets a hex-only validator rather than a text
		// sanitiser: sanitize_text_field would pass ";}body{…}" through untouched. Empty means "use
		// the bundled colour", which is why the registered default is '' and not the colour itself.
		register_setting(
			self::GROUP,
			Settings::OPT_BUTTON_ACCENT,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_button_accent' ),
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_BUTTON_STYLE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_button_style' ),
				'show_in_rest'      => true,
				'default'           => Settings::BUTTON_STYLE_PLUGIN,
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_EMAIL_FROM_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_EMAIL_FROM_ADDRESS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'show_in_rest'      => true,
				'default'           => '',
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_ELIGIBLE_STATUSES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_statuses' ),
				// Not exposed in REST: the React admin does not read it, and an array option would
				// need a schema there for no benefit.
				'show_in_rest'      => false,
				'default'           => array( 'processing', 'completed' ),
			)
		);
		register_setting(
			self::GROUP,
			Settings::OPT_MANAGE_ROLES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_roles' ),
				'show_in_rest'      => false,
				'default'           => array( 'shop_manager' ),
			)
		);

		// Granting or revoking access must take effect the moment the roles are saved, not at the next
		// activation, so the capability is synced from this option whenever it changes.
		add_action( 'update_option_' . Settings::OPT_MANAGE_ROLES, array( $this, 'sync_roles' ), 10, 0 );
		add_action( 'add_option_' . Settings::OPT_MANAGE_ROLES, array( $this, 'sync_roles' ), 10, 0 );

		add_settings_section(
			self::SECTION_GENERAL,
			__( 'General', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_general_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_ADMIN_RECIPIENTS, __( 'Notification email', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_admin_recipients' ), self::MENU_SLUG, self::SECTION_GENERAL );
		add_settings_field( FlowPage::OPTION, __( 'Withdrawal page', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_flow_page' ), self::MENU_SLUG, self::SECTION_GENERAL );

		add_settings_section(
			self::SECTION_STATUSES,
			__( 'Eligible order statuses', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_statuses_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_ELIGIBLE_STATUSES, __( 'Offer withdrawal for', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_eligible_statuses' ), self::MENU_SLUG, self::SECTION_STATUSES );

		add_settings_section(
			self::SECTION,
			__( 'Withdrawal deadline', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_deadline_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_WINDOW_DAYS, __( 'Withdrawal window (days)', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_window_days' ), self::MENU_SLUG, self::SECTION );
		add_settings_field( Settings::OPT_START_TRIGGER, __( 'Calculate deadline from', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_start_trigger' ), self::MENU_SLUG, self::SECTION );
		add_settings_field( Settings::OPT_GRACE_DAYS, __( 'Grace days', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_grace_days' ), self::MENU_SLUG, self::SECTION );
		add_settings_field( Settings::OPT_ENFORCEMENT_MODE, __( 'Deadline enforcement', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_enforcement_mode' ), self::MENU_SLUG, self::SECTION );

		add_settings_section(
			self::SECTION_EXCLUDE,
			__( 'Article 16 exclusions', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_exclusions_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_DEFAULT_POLICY, __( 'Default policy for unconfigured products', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_default_policy' ), self::MENU_SLUG, self::SECTION_EXCLUDE );

		add_settings_section(
			self::SECTION_CONSENT,
			__( 'Checkout consents', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_consent_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_CONSENTS_CONDITIONAL, __( 'When to show the consents', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consents_conditional' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_DIGITAL_ENABLED, __( 'Digital content consent (Art. 16(m))', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_digital_enabled' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_DIGITAL_REQUIRED, __( 'Require digital-content consent', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_digital_required' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_DIGITAL_TEXT, __( 'Digital-content consent text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_digital_text' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_SERVICE_ENABLED, __( 'Service-start consent (Art. 14(4)(a))', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_service_enabled' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_SERVICE_REQUIRED, __( 'Require service-start consent', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_service_required' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_SERVICE_TEXT, __( 'Service-start consent text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_service_text' ), self::MENU_SLUG, self::SECTION_CONSENT );

		add_settings_section(
			self::SECTION_TRADER,
			__( 'Model withdrawal form', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_model_form_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_MODEL_FORM_ENABLED, __( 'Show model form', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_model_form_enabled' ), self::MENU_SLUG, self::SECTION_TRADER );
		add_settings_field( Settings::OPT_TRADER_NAME, __( 'Trader name', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_trader_name' ), self::MENU_SLUG, self::SECTION_TRADER );
		add_settings_field( Settings::OPT_TRADER_ADDRESS, __( 'Trader postal address', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_trader_address' ), self::MENU_SLUG, self::SECTION_TRADER );
		add_settings_field( Settings::OPT_TRADER_PHONE, __( 'Trader phone (optional)', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_trader_phone' ), self::MENU_SLUG, self::SECTION_TRADER );
		add_settings_field( Settings::OPT_TRADER_EMAIL, __( 'Trader contact email', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_trader_email' ), self::MENU_SLUG, self::SECTION_TRADER );

		add_settings_section(
			self::SECTION_NOTICE,
			__( 'Excluded products notice', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_notice_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_PRODUCT_NOTICE_ENABLED, __( 'Show notice on excluded products', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_product_notice_enabled' ), self::MENU_SLUG, self::SECTION_NOTICE );
		add_settings_field( Settings::OPT_NOTICE_DIGITAL_TITLE, __( 'Notice for digital content (Art. 16(m))', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_notice_digital' ), self::MENU_SLUG, self::SECTION_NOTICE );
		add_settings_field( Settings::OPT_NOTICE_DATED_TITLE, __( 'Notice for dated services (Art. 16(l))', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_notice_dated' ), self::MENU_SLUG, self::SECTION_NOTICE );
		add_settings_field( Settings::OPT_NOTICE_OTHER_TITLE, __( 'Notice for other Article 16 exceptions', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_notice_other' ), self::MENU_SLUG, self::SECTION_NOTICE );

		add_settings_section(
			self::SECTION_EMAILS,
			__( 'Withdrawal emails', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_emails_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_EMAIL_FROM_NAME, __( 'From name', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_email_from_name' ), self::MENU_SLUG, self::SECTION_EMAILS );
		add_settings_field( Settings::OPT_EMAIL_FROM_ADDRESS, __( 'From address', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_email_from_address' ), self::MENU_SLUG, self::SECTION_EMAILS );
		add_settings_field( Settings::OPT_EMAIL_ACCEPTED_TEXT, __( 'Accepted email text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_email_accepted_text' ), self::MENU_SLUG, self::SECTION_EMAILS );
		add_settings_field( Settings::OPT_EMAIL_REJECTED_TEXT, __( 'Rejected email text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_email_rejected_text' ), self::MENU_SLUG, self::SECTION_EMAILS );
		add_settings_field( Settings::OPT_EMAIL_COMPLETED_TEXT, __( 'Completed email text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_email_completed_text' ), self::MENU_SLUG, self::SECTION_EMAILS );

		add_settings_section(
			self::SECTION_LOOKUP,
			__( 'Order lookup screen', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_lookup_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_LOOKUP_TITLE, __( 'Page title', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_lookup_title' ), self::MENU_SLUG, self::SECTION_LOOKUP, array( 'label_for' => Settings::OPT_LOOKUP_TITLE ) );
		add_settings_field( Settings::OPT_LOOKUP_INTRO, __( 'Intro paragraph', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_lookup_intro' ), self::MENU_SLUG, self::SECTION_LOOKUP, array( 'label_for' => Settings::OPT_LOOKUP_INTRO ) );
		add_settings_field( Settings::OPT_LOOKUP_EMAIL_HINT, __( 'Email field hint', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_lookup_email_hint' ), self::MENU_SLUG, self::SECTION_LOOKUP, array( 'label_for' => Settings::OPT_LOOKUP_EMAIL_HINT ) );
		add_settings_field( Settings::OPT_LOOKUP_SUBMIT, __( 'Submit button label', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_lookup_submit' ), self::MENU_SLUG, self::SECTION_LOOKUP, array( 'label_for' => Settings::OPT_LOOKUP_SUBMIT ) );

		add_settings_section(
			self::SECTION_APPEAR,
			__( 'Withdrawal page appearance', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_appearance_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_BUTTON_STYLE, __( 'Button style', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_button_style' ), self::MENU_SLUG, self::SECTION_APPEAR, array( 'label_for' => Settings::OPT_BUTTON_STYLE ) );
		add_settings_field( Settings::OPT_BUTTON_ACCENT, __( 'Button colour', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_button_accent' ), self::MENU_SLUG, self::SECTION_APPEAR, array( 'label_for' => Settings::OPT_BUTTON_ACCENT ) );

		add_settings_section(
			self::SECTION_FORM,
			__( 'Public withdrawal form', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_form_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_FORM_INTRO_ENABLED, __( 'Intro text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_form_intro' ), self::MENU_SLUG, self::SECTION_FORM );
		add_settings_field( Settings::OPT_CONSUMER_DECLARATION_ENABLED, __( 'Consumer self-declaration', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consumer_declaration' ), self::MENU_SLUG, self::SECTION_FORM );

		add_settings_section(
			self::SECTION_ROLES,
			__( 'Permissions', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_roles_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_MANAGE_ROLES, __( 'Roles that manage requests', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_manage_roles' ), self::MENU_SLUG, self::SECTION_ROLES );

		add_settings_section(
			self::SECTION_VISIBLE,
			__( 'Withdrawal link visibility', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_visibility_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_FOOTER_LINK_ENABLED, __( 'Show withdrawal link in footer', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_footer_link_enabled' ), self::MENU_SLUG, self::SECTION_VISIBLE );
		add_settings_field( Settings::OPT_ACCOUNT_ENDPOINT, __( 'My Account tab', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_account_endpoint' ), self::MENU_SLUG, self::SECTION_VISIBLE );

		add_settings_section(
			self::SECTION_DATA,
			__( 'Data', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'__return_false',
			self::MENU_SLUG
		);
		add_settings_field( 'recesso_dig_delete_data_on_uninstall', __( 'Delete all data on uninstall', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_delete_data' ), self::MENU_SLUG, self::SECTION_DATA );
	}

	/**
	 * Intro for the General section.
	 */
	public function section_general_intro(): void {
		echo '<p>' . esc_html__( 'Configure where notifications are sent and which page hosts the withdrawal form.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the deadline section.
	 */
	public function section_deadline_intro(): void {
		echo '<p>' . esc_html__( 'EU Directive 2023/2673 keeps the withdrawal function continuously available. These settings tune the advisory deadline shown to the merchant; in Advisory mode the function is never hidden.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the article 16 exclusions section.
	 */
	public function section_exclusions_intro(): void {
		echo '<p>' . esc_html__( 'Mark individual products or whole categories as excluded from the right of withdrawal in the product and category editors (Withdrawal status). This default applies to products without an explicit status.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the checkout consents section.
	 */
	public function section_consent_intro(): void {
		echo '<p>' . esc_html__( 'Optional consents shown at checkout for products excluded under Article 16(m) (digital content) or starting early under Article 14(4)(a) (services).', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the model form section.
	 */
	public function section_model_form_intro(): void {
		echo '<p>' . esc_html__( 'The statutory model withdrawal form (Annex I.B of Directive 2011/83/EU), shown below the public withdrawal form and via the [recesso_digitale_modulo] shortcode. The contact details below populate its header.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the excluded-products notice section.
	 */
	public function section_notice_intro(): void {
		echo '<p>' . esc_html__( 'Optionally show a notice on the product page of items excluded from the right of withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the eligible-statuses section.
	 */
	public function section_statuses_intro(): void {
		echo '<p>' . esc_html__( 'Pick the WooCommerce order statuses from which a customer may declare a withdrawal. The choice applies to the button below the order details, the block in the order emails and the My Account tab alike. Unticking every status silences the prompt without deactivating the plugin.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the emails section.
	 */
	public function section_emails_intro(): void {
		echo '<p>' . esc_html__( 'Choose who the plugin\'s emails come from and how the status-change messages to your customers are worded. Subjects and headings stay under WooCommerce → Settings → Emails, alongside every other store email.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the order-lookup section.
	 */
	public function section_lookup_intro(): void {
		echo '<p>' . esc_html__( 'The wording of the screen a customer meets when they reach the withdrawal page without a signed link — from the footer link, from a bookmark, or after a link has expired. It asks for the order number and the order email, then sends the withdrawal link to that order\'s own address.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the appearance section.
	 */
	public function section_appearance_intro(): void {
		echo '<p>' . esc_html__( 'How the buttons on the withdrawal page itself look: the lookup screen, the declaration form and the confirmation step. The «recedere dal contratto qui» link in My Account and in your order emails is not affected — it sits inside your theme\'s own pages and has always used your theme\'s button style.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the public-form section.
	 */
	public function section_form_intro(): void {
		echo '<p>' . esc_html__( 'Fine-tune the public withdrawal form. The two-step confirmation, the itemised summary and the acknowledgement of receipt are fixed: they are what the law requires of the function.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the permissions section.
	 */
	public function section_roles_intro(): void {
		echo '<p>' . esc_html__( 'Choose which roles, besides the administrator, can view and manage withdrawal requests. Each request holds personal data (name, email, IP address), so access is scoped to the roles you pick here rather than to everyone who can edit the shop.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Intro for the visibility section.
	 */
	public function section_visibility_intro(): void {
		echo '<p>' . esc_html__( 'The withdrawal function must be clearly identifiable and accessible throughout the period it can be exercised (art. 54-bis). The usual practice is a permanent link in the site footer, next to the privacy policy and the terms of sale. Nothing is injected into your theme automatically — pick whichever of these routes suits it.', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';

		echo '<ol class="recesso-dig-help">';
		printf(
			'<li><strong>%s</strong> %s</li>',
			esc_html__( 'The setting below.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_html__( 'Appends the link to the footer on every page, wherever your theme prints wp_footer.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		printf(
			'<li><strong>%s</strong> %s</li>',
			esc_html__( 'Classic themes (Appearance → Menus).', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_html__( 'Open the menu assigned to your footer, add the withdrawal page from the left column and save.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		printf(
			'<li><strong>%s</strong> %s</li>',
			esc_html__( 'Block themes (Appearance → Editor).', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_html__( 'Edit the Footer template part, insert a Navigation or Page List block and point it at the withdrawal page.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		printf(
			'<li><strong>%s</strong> <code>[%s]</code> — %s <code>text="%s"</code>, <code>class="my-footer-link"</code></li>',
			esc_html__( 'Shortcode, anywhere a shortcode is accepted.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_html( FooterLink::SHORTCODE ),
			esc_html__( 'optional attributes:', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_attr__( 'Right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		echo '</ol>';
	}

	/**
	 * Render the header shown above the settings form: where the public form actually lives, and the
	 * shortcodes that place its parts elsewhere. A merchant should be able to answer "where is my
	 * withdrawal page?" without leaving this screen.
	 */
	private function render_header(): void {
		$health = FlowPage::health();

		if ( FlowPage::HEALTH_OK !== $health ) {
			printf(
				'<div class="notice notice-%s inline"><p>%s</p></div>',
				FlowPageNotice::is_blocking( $health ) ? 'error' : 'warning',
				esc_html( FlowPageNotice::message( $health ) )
			);
		}

		$url = FlowPage::url();
		if ( '' !== $url ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Public withdrawal form:', 'erred-eu-order-withdrawal-for-woocommerce' ),
				esc_url( $url ),
				esc_html( $url )
			);
		}
	}

	/**
	 * Render the how-to and legal-disclaimer panels shown below the settings form.
	 */
	private function render_footer_help(): void {
		echo '<hr />';

		printf( '<h2>%s</h2>', esc_html__( 'Placing the withdrawal function', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		echo '<ul class="recesso-dig-help">';
		printf(
			'<li><code>[recesso_digitale]</code> — %s</li>',
			esc_html__( 'the full two-step withdrawal form. The page selected under General already contains it.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		printf(
			'<li><code>[%s]</code> — %s</li>',
			esc_html( FooterLink::SHORTCODE ),
			esc_html__( 'a link to that page, for footers, widgets and menus.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		printf(
			'<li><code>[recesso_digitale_modulo]</code> — %s</li>',
			esc_html__( 'the Annex I.B model withdrawal form.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		printf(
			'<li><code>[recesso_digitale_avviso_esclusione]</code> — %s</li>',
			esc_html__( 'the "excluded from withdrawal" notice, for product templates built with a page builder.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		echo '</ul>';

		printf( '<h2>%s</h2>', esc_html__( 'Legal note', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'This plugin provides the technical tools for the online withdrawal function required by Directive (EU) 2023/2673, transposed in Italy as art. 54-bis of the Codice del Consumo. It does not guarantee legal compliance, and it does not decide whether a withdrawal is valid — you do. Which products fall under the art. 59 exceptions, and how the durable-medium receipt is worded, depend on your catalogue and your jurisdiction: have them reviewed by a lawyer specialised in consumer law.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the eligible order statuses as a checkbox list of every registered WooCommerce status.
	 */
	public function field_eligible_statuses(): void {
		$selected = ( new Settings() )->eligible_statuses();
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

		echo '<fieldset>';
		printf(
			'<legend class="screen-reader-text">%s</legend>',
			esc_html__( 'Order statuses eligible for withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' )
		);

		foreach ( $statuses as $recesso_dig_key => $recesso_dig_label ) {
			// wc_get_order_statuses() keys carry the `wc-` prefix; the engine compares bare statuses.
			$slug = (string) preg_replace( '/^wc-/', '', (string) $recesso_dig_key );

			printf(
				'<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s <code>%2$s</code></label>',
				esc_attr( Settings::OPT_ELIGIBLE_STATUSES ),
				esc_attr( $slug ),
				checked( in_array( $slug, $selected, true ), true, false ),
				esc_html( (string) $recesso_dig_label )
			);
		}
		echo '</fieldset>';

		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Defaults to Processing and Completed. Statuses registered by other plugins (a shipping extension, for example) appear here automatically.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the roles-that-manage-requests checkbox matrix.
	 */
	public function field_manage_roles(): void {
		$selected = ( new Settings() )->manage_roles();
		$roles    = function_exists( 'wp_roles' ) ? wp_roles()->get_names() : array();

		echo '<fieldset>';
		printf(
			'<legend class="screen-reader-text">%s</legend>',
			esc_html__( 'Roles allowed to manage withdrawal requests', 'erred-eu-order-withdrawal-for-woocommerce' )
		);

		// The administrator always has access; showing it disabled and ticked makes that explicit
		// without letting anyone lock themselves out of the screen.
		printf(
			'<label style="display:block"><input type="checkbox" checked disabled /> %s</label>',
			esc_html__( 'Administrator (always allowed)', 'erred-eu-order-withdrawal-for-woocommerce' )
		);

		foreach ( $roles as $recesso_dig_role => $recesso_dig_name ) {
			// The administrator is shown above; the customer-facing roles are never offered, because
			// withdrawal requests hold other people's personal data.
			if ( 'administrator' === $recesso_dig_role || in_array( (string) $recesso_dig_role, Capabilities::NEVER_GRANT, true ) ) {
				continue;
			}

			printf(
				'<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( Settings::OPT_MANAGE_ROLES ),
				esc_attr( (string) $recesso_dig_role ),
				checked( in_array( (string) $recesso_dig_role, $selected, true ), true, false ),
				esc_html( translate_user_role( (string) $recesso_dig_name ) )
			);
		}
		echo '</fieldset>';

		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Ticking a role lets it view and manage requests; unticking it revokes access as soon as you save. Existing sites start with the shop manager, which is the role the plugin granted on activation.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the plugin email "From name" field.
	 */
	public function field_email_from_name(): void {
		$this->text_option( Settings::OPT_EMAIL_FROM_NAME, (string) get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ) );
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Sender name for the acknowledgement, the admin notification and the status updates. Leave empty to use the WooCommerce sender, so all your store emails stay consistent.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the plugin email "From address" field.
	 */
	public function field_email_from_address(): void {
		$this->text_option( Settings::OPT_EMAIL_FROM_ADDRESS, (string) get_option( 'woocommerce_email_from_address', get_option( 'admin_email', '' ) ) );
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Sender address for this plugin\'s emails only. An address on your own domain improves deliverability. Leave empty to use the WooCommerce sender.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the "accepted" status-email body.
	 */
	public function field_email_accepted_text(): void {
		$this->textarea_option( Settings::OPT_EMAIL_ACCEPTED_TEXT, ( new Settings() )->status_email_text( 'accepted' ) );
		$this->hint( self::TAG_OPTIONAL, $this->status_email_hint() );
	}

	/**
	 * Render the "rejected" status-email body.
	 */
	public function field_email_rejected_text(): void {
		$this->textarea_option( Settings::OPT_EMAIL_REJECTED_TEXT, ( new Settings() )->status_email_text( 'rejected' ) );
		$this->hint( self::TAG_OPTIONAL, $this->status_email_hint() );
	}

	/**
	 * Render the "completed" status-email body.
	 */
	public function field_email_completed_text(): void {
		$this->textarea_option( Settings::OPT_EMAIL_COMPLETED_TEXT, ( new Settings() )->status_email_text( 'completed' ) );
		$this->hint( self::TAG_OPTIONAL, $this->status_email_hint() );
	}

	/**
	 * The shared explanation under each status-email body field.
	 */
	private function status_email_hint(): string {
		return __( 'Leave empty to use the bundled text shown in the field. The order reference and the reason you record when rejecting are always appended automatically.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Render the lookup screen's page title.
	 */
	public function field_lookup_title(): void {
		$this->text_option( Settings::OPT_LOOKUP_TITLE, ( new Settings() )->lookup_title() );
		$this->hint( self::TAG_OPTIONAL, $this->bundled_text_hint() );
	}

	/**
	 * Render the lookup screen's intro paragraph.
	 */
	public function field_lookup_intro(): void {
		$this->textarea_option( Settings::OPT_LOOKUP_INTRO, ( new Settings() )->lookup_intro() );
		$this->hint( self::TAG_OPTIONAL, $this->bundled_text_hint() );
	}

	/**
	 * Render the hint shown under the lookup screen's email field.
	 */
	public function field_lookup_email_hint(): void {
		$this->textarea_option( Settings::OPT_LOOKUP_EMAIL_HINT, ( new Settings() )->lookup_email_hint() );
		$this->hint( self::TAG_OPTIONAL, $this->bundled_text_hint() );
	}

	/**
	 * Render the lookup screen's submit button label.
	 */
	public function field_lookup_submit(): void {
		$this->text_option( Settings::OPT_LOOKUP_SUBMIT, ( new Settings() )->lookup_submit_label() );
		$this->hint( self::TAG_OPTIONAL, $this->bundled_text_hint() );
	}

	/**
	 * Render the button style choice.
	 */
	public function field_button_style(): void {
		$this->render_select(
			Settings::OPT_BUTTON_STYLE,
			array(
				Settings::BUTTON_STYLE_PLUGIN => __( 'Use the plugin\'s own button style (recommended)', 'erred-eu-order-withdrawal-for-woocommerce' ),
				Settings::BUTTON_STYLE_THEME  => __( 'Inherit my theme\'s button style', 'erred-eu-order-withdrawal-for-woocommerce' ),
			),
			( new Settings() )->button_style()
		);
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'The plugin styles these buttons itself because the withdrawal page is an ordinary page, where your theme\'s button styles are not guaranteed to load — without it the control can render as bare text. Choose to inherit only if your theme styles its buttons everywhere, and check the withdrawal page afterwards.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the button accent colour.
	 */
	public function field_button_accent(): void {
		// Re-validated on read as well as on save: the stylesheet must never see anything but a hex
		// colour, whatever route put the value in the options table.
		$value = Color::hex( (string) get_option( Settings::OPT_BUTTON_ACCENT, '' ) );

		printf(
			'<input type="text" class="recesso-dig-color-field" name="%1$s" id="%1$s" value="%2$s" data-default-color="%3$s" />',
			esc_attr( Settings::OPT_BUTTON_ACCENT ),
			esc_attr( $value ),
			esc_attr( Settings::DEFAULT_ACCENT )
		);
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Leave empty to use the bundled colour. The hover shade and the label colour are worked out from your choice, so the label stays readable whatever colour you pick. Has no effect when the buttons inherit your theme\'s style.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the form intro toggle and text together.
	 */
	public function field_form_intro(): void {
		$this->checkbox_option( Settings::OPT_FORM_INTRO_ENABLED, __( 'Show the intro paragraph above the form.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		echo '<p></p>';
		$this->textarea_option( Settings::OPT_FORM_INTRO_TEXT, ( new Settings() )->form_intro_text( '#1234' ) );
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Untick the box to hide the intro entirely. Leave the text empty to use the bundled sentence shown in the field, which names the order and follows the language of each visitor.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the consumer self-declaration toggle and text together.
	 */
	public function field_consumer_declaration(): void {
		$this->checkbox_option( Settings::OPT_CONSUMER_DECLARATION_ENABLED, __( 'Require a "bought as a consumer" self-declaration on the form.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		echo '<p></p>';
		$this->textarea_option( Settings::OPT_CONSUMER_DECLARATION_TEXT, ( new Settings() )->consumer_declaration_text() );
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'The right of withdrawal protects consumers — natural persons acting outside their trade or profession — not business buyers. When enabled, the form shows a required checkbox and the declaration is recorded in the durable-medium receipt. It is a good-faith declaration, not a legal guarantee: review business purchases yourself.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the My Account tab toggle.
	 */
	public function field_account_endpoint(): void {
		$this->checkbox_option( Settings::OPT_ACCOUNT_ENDPOINT, __( 'Add a "Right of withdrawal" tab to My Account.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'The tab lists the customer\'s orders that are currently eligible and offers the withdrawal control for each, so a logged-in customer can find the function without hunting through past orders.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the digital-content exclusion notice pair.
	 */
	public function field_notice_digital(): void {
		$this->notice_pair( Settings::OPT_NOTICE_DIGITAL_TITLE, Settings::OPT_NOTICE_DIGITAL_BODY, Settings::STATUS_ART16M_DIGITAL );
	}

	/**
	 * Render the dated-service exclusion notice pair.
	 */
	public function field_notice_dated(): void {
		$this->notice_pair( Settings::OPT_NOTICE_DATED_TITLE, Settings::OPT_NOTICE_DATED_BODY, Settings::STATUS_ART16L_ACCOMMODATION );
	}

	/**
	 * Render the catch-all exclusion notice pair.
	 */
	public function field_notice_other(): void {
		$this->notice_pair( Settings::OPT_NOTICE_OTHER_TITLE, Settings::OPT_NOTICE_OTHER_BODY, Settings::STATUS_ART16_OTHER );
	}

	/**
	 * Render one exclusion-notice title/body pair, using the bundled wording for that exception as the
	 * placeholder so the merchant can see exactly what an empty field will show.
	 *
	 * @param string $title_option Option holding the heading.
	 * @param string $body_option  Option holding the body.
	 * @param string $status       The classification the pair belongs to.
	 */
	private function notice_pair( string $title_option, string $body_option, string $status ): void {
		$defaults = ( new Settings() )->exclusion_notice( $status );

		printf( '<p><label for="%s">%s</label></p>', esc_attr( $title_option ), esc_html__( 'Title', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->text_option( $title_option, $defaults['title'] );

		printf( '<p><label for="%s">%s</label></p>', esc_attr( $body_option ), esc_html__( 'Body', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->textarea_option( $body_option, $defaults['body'] );

		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Leave a field empty to use the bundled wording shown in it, which follows the language of each visitor. The body accepts the {withdrawal_page_link} placeholder, which expands to a link to your withdrawal page.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Grant the manage capability to exactly the configured roles (plus the administrator, which is
	 * never configurable), so a change on the settings screen takes effect immediately.
	 */
	public function sync_roles(): void {
		Capabilities::sync( ( new Settings() )->manage_roles() );
	}

	/**
	 * Sanitise the eligible-statuses option against the statuses WooCommerce actually knows about.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	public function sanitize_statuses( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$known = function_exists( 'wc_get_order_statuses' )
			? array_map(
				static fn( string $key ): string => (string) preg_replace( '/^wc-/', '', $key ),
				array_keys( wc_get_order_statuses() )
			)
			: array();

		$statuses = array();
		foreach ( $value as $status ) {
			$status = sanitize_key( (string) $status );
			if ( '' !== $status && ( array() === $known || in_array( $status, $known, true ) ) ) {
				$statuses[] = $status;
			}
		}

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Sanitise the manage-roles option against the roles that actually exist. The administrator is
	 * dropped: it always has access and must never depend on a stored value.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string[]
	 */
	public function sanitize_roles( $value ): array {
		$value = is_array( $value ) ? $value : array();
		$known = function_exists( 'wp_roles' ) ? array_keys( wp_roles()->get_names() ) : array();

		$roles = array();
		foreach ( $value as $role ) {
			$role = sanitize_key( (string) $role );
			if ( '' !== $role && 'administrator' !== $role && ! in_array( $role, Capabilities::NEVER_GRANT, true ) && in_array( $role, $known, true ) ) {
				$roles[] = $role;
			}
		}

		return array_values( array_unique( $roles ) );
	}

	/**
	 * Render the settings screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( Capabilities::MANAGE_REQUESTS ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order Withdrawal — Settings', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h1>
			<?php $this->render_header(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
			<?php $this->render_footer_help(); ?>
		</div>
		<?php
	}

	/**
	 * Render the window-days field.
	 */
	public function field_window_days(): void {
		$value = (int) get_option( Settings::OPT_WINDOW_DAYS, 14 );
		printf(
			'<input type="number" min="1" name="%s" id="%s" value="%s" class="small-text" />',
			esc_attr( Settings::OPT_WINDOW_DAYS ),
			esc_attr( Settings::OPT_WINDOW_DAYS ),
			esc_attr( (string) $value )
		);
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Fourteen days is the statutory minimum (art. 52). Raise it if you offer a longer return window; lowering it below fourteen has no legal effect.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the start-trigger field.
	 */
	public function field_start_trigger(): void {
		$value   = (string) get_option( Settings::OPT_START_TRIGGER, Settings::TRIGGER_DELIVERY );
		$options = array(
			Settings::TRIGGER_DELIVERY   => __( 'Delivery (goods)', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::TRIGGER_CONCLUSION => __( 'Conclusion of contract (services)', 'erred-eu-order-withdrawal-for-woocommerce' ),
		);
		$this->render_select( Settings::OPT_START_TRIGGER, $options, $value );
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'For goods the period runs from delivery, which WooCommerce cannot know: the order completion date is used as the proxy. For services it runs from the conclusion of the contract, so the order date is used. This only affects the advisory deadline shown to you.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the enforcement-mode field.
	 */
	public function field_enforcement_mode(): void {
		$value   = (string) get_option( Settings::OPT_ENFORCEMENT_MODE, Settings::ENFORCEMENT_ADVISORY );
		$options = array(
			Settings::ENFORCEMENT_ADVISORY => __( 'Advisory — always available, merchant decides (recommended)', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::ENFORCEMENT_STRICT   => __( 'Strict — hide the function outside the window', 'erred-eu-order-withdrawal-for-woocommerce' ),
		);
		$this->render_select( Settings::OPT_ENFORCEMENT_MODE, $options, $value );
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Keep Advisory. Because the real period runs from delivery, strict mode can turn away a consumer who is still within their actual window — and the function is supposed to stay continuously available. Use it only where the order or completion date is a reliable proxy for delivery.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the grace-days field.
	 */
	public function field_grace_days(): void {
		$value = (int) get_option( Settings::OPT_GRACE_DAYS, 0 );
		printf(
			'<input type="number" min="0" name="%s" id="%s" value="%s" class="small-text" />',
			esc_attr( Settings::OPT_GRACE_DAYS ),
			esc_attr( Settings::OPT_GRACE_DAYS ),
			esc_attr( (string) $value )
		);
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Extra days added on top of the window when computing the advisory deadline. Useful when your real return policy is more generous than the statutory minimum.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the default-policy field.
	 */
	public function field_default_policy(): void {
		$value   = (string) get_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		$options = array(
			Settings::POLICY_ALLOW        => __( 'Allow withdrawal (recommended)', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::POLICY_EXCLUDE      => __( 'Exclude from withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ),
			Settings::POLICY_UNCONFIGURED => __( 'Unconfigured — fail closed', 'erred-eu-order-withdrawal-for-woocommerce' ),
		);
		$this->render_select( Settings::OPT_DEFAULT_POLICY, $options, $value );
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Applies to products with no explicit "Withdrawal status" of their own or on their category. Keep "Allow": the right of withdrawal is the rule and the exceptions are the exception, and you still decide each request. "Unconfigured" makes any unclassified product block the whole order — safe, but it hides the function until your catalogue is fully classified.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the product-notice enable checkbox.
	 */
	public function field_product_notice_enabled(): void {
		$value = (string) get_option( Settings::OPT_PRODUCT_NOTICE_ENABLED, '0' );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( Settings::OPT_PRODUCT_NOTICE_ENABLED ),
			checked( '1', $value, false ),
			esc_html__( 'Display a notice on the product page for products excluded from withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Rendered between the price and the add-to-cart button. Not required by law as such, but it tells the consumer before they buy that this item cannot be returned, which heads off the dispute later.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the conditional-consent checkbox.
	 */
	public function field_consents_conditional(): void {
		$this->checkbox_option(
			Settings::OPT_CONSENTS_CONDITIONAL,
			__( 'Show each consent only when the cart contains a product classified for it.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'The consent then follows the "Withdrawal status" you set on the product or category: the digital-content box appears only with Article 16(m) items in the cart, the service box only with Article 14(4)(a) items. Left unticked, every enabled consent is shown on every checkout — the behaviour of earlier versions, kept as the default so updating changes nothing.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the digital-content consent enable checkbox.
	 */
	public function field_consent_digital_enabled(): void {
		$this->checkbox_option( Settings::OPT_CONSENT_DIGITAL_ENABLED, __( 'Show the digital-content consent at checkout.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->hint(
			self::TAG_MANDATORY,
			__( 'Mandatory if you sell digital content. Required for downloads, online courses, software and eBooks when you want to rely on the Article 16(m) exception. Without this consent given and recorded, the consumer keeps the 14-day right of withdrawal even after accessing the content. Otherwise optional.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the digital-content "required" checkbox.
	 */
	public function field_consent_digital_required(): void {
		$this->checkbox_option( Settings::OPT_CONSENT_DIGITAL_REQUIRED, __( 'The order cannot be placed until this consent is given.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Blocking checkout is a deliberate, catalogue-specific choice, so it is off by default. Turn it on only where the Article 16(m) exception is the basis of your sale.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the digital-content consent text field.
	 */
	public function field_consent_digital_text(): void {
		$this->textarea_option( Settings::OPT_CONSENT_DIGITAL_TEXT, esc_attr( ( new Settings() )->consent_digital_text() ) );
		$this->hint( self::TAG_OPTIONAL, $this->bundled_text_hint() );
	}

	/**
	 * Render the service-start consent enable checkbox.
	 */
	public function field_consent_service_enabled(): void {
		$this->checkbox_option( Settings::OPT_CONSENT_SERVICE_ENABLED, __( 'Show the optional service-start consent at checkout.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Useful for ongoing services (maintenance plans, consultancy, subscriptions). With this consent recorded you may charge a proportionate amount for the work already done if the consumer withdraws within the window; without it, an early withdrawal means a full refund regardless of the work delivered.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Field: require the service-start consent to place the order.
	 */
	public function field_consent_service_required(): void {
		$this->checkbox_option( Settings::OPT_CONSENT_SERVICE_REQUIRED, __( 'The order cannot be placed until this consent is given.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Leave this off unless your service always begins inside the withdrawal window — a live session, a booking for the next few days — where an order without the request is one you cannot fulfil. Asking for early performance is the consumer\'s choice to make, and requiring it takes that choice away, so it is off by default.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the service-start consent text field.
	 */
	public function field_consent_service_text(): void {
		$this->textarea_option( Settings::OPT_CONSENT_SERVICE_TEXT, esc_attr( ( new Settings() )->consent_service_text() ) );
		$this->hint( self::TAG_OPTIONAL, $this->bundled_text_hint() );
	}

	/**
	 * The shared wording explaining how an empty customer-facing text field behaves. Leaving a field
	 * empty keeps the bundled string, which is translated per visitor; a merchant's own text is shown
	 * exactly as written, in every language.
	 */
	private function bundled_text_hint(): string {
		return __( 'Leave empty to use the bundled text shown in the field, which follows the language of each customer. Your own text is shown exactly as written, in every language.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}

	/**
	 * Render the footer-link enable checkbox.
	 */
	public function field_footer_link_enabled(): void {
		$this->checkbox_option( Settings::OPT_FOOTER_LINK_ENABLED, __( 'Add a persistent «recedere dal contratto qui» link to the site footer.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'The simplest way to satisfy the "continuously available" requirement. If your theme builds its footer from a menu or a template part, use one of the other routes above instead.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the admin-recipients field.
	 */
	public function field_admin_recipients(): void {
		$value = (string) get_option( Settings::OPT_ADMIN_RECIPIENTS, '' );
		printf(
			'<input type="text" name="%s" id="%s" value="%s" placeholder="%s" class="regular-text" /> <p class="description">%s</p>',
			esc_attr( Settings::OPT_ADMIN_RECIPIENTS ),
			esc_attr( Settings::OPT_ADMIN_RECIPIENTS ),
			esc_attr( $value ),
			esc_attr( (string) get_option( 'admin_email', '' ) ),
			esc_html__( 'Comma-separated email addresses notified when a withdrawal is confirmed. Defaults to the site admin email.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Someone has to see a confirmed withdrawal: the acknowledgement to the consumer is automatic, but the refund is not. Separate several addresses with commas.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the withdrawal-page selector. Lets the merchant choose which page hosts the flow
	 * shortcode/block; the entry links across My Account and emails resolve to it.
	 */
	public function field_flow_page(): void {
		$dropdown = wp_dropdown_pages(
			array(
				'name'              => esc_attr( FlowPage::OPTION ),
				'id'                => esc_attr( FlowPage::OPTION ),
				'selected'          => (int) get_option( FlowPage::OPTION, 0 ),
				'show_option_none'  => esc_html__( '— Select a page —', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'option_none_value' => '0',
				'echo'              => false,
			)
		);
		// wp_dropdown_pages() returns markup escaped by core; allow the form controls it produces.
		echo wp_kses(
			$dropdown,
			array(
				'select' => array(
					'name' => array(),
					'id'   => array(),
				),
				'option' => array(
					'value'    => array(),
					'selected' => array(),
					'class'    => array(),
				),
			)
		);
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'The page that contains the withdrawal form. Make sure it includes the [recesso_digitale] shortcode. The plugin creates one automatically on activation.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		$this->hint(
			self::TAG_MANDATORY,
			__( 'Without it the withdrawal function has nowhere to live: the buttons in order emails and in My Account have no destination.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the "show model form" checkbox.
	 */
	public function field_model_form_enabled(): void {
		$value = (string) get_option( Settings::OPT_MODEL_FORM_ENABLED, '1' );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( Settings::OPT_MODEL_FORM_ENABLED ),
			checked( '1', $value, false ),
			esc_html__( 'Display the model form below the public withdrawal form.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		$this->hint(
			self::TAG_RECOMMENDED,
			__( 'Providing the Annex I.B model form is a separate, pre-contractual obligation (art. 6(1)(h)): the online function complements it, it does not replace it. The model is generated from the trader details below, so it works as soon as they are filled in.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render the trader-name field.
	 */
	public function field_trader_name(): void {
		$this->text_option( Settings::OPT_TRADER_NAME, esc_attr( ( new Settings() )->trader_name() ) );
	}

	/**
	 * Render the trader-phone field.
	 */
	public function field_trader_phone(): void {
		$this->text_option( Settings::OPT_TRADER_PHONE, '' );
	}

	/**
	 * Render the trader-address field.
	 */
	public function field_trader_address(): void {
		$this->textarea_option( Settings::OPT_TRADER_ADDRESS, esc_attr( ( new Settings() )->trader_address() ) );
	}

	/**
	 * Render the trader-email field.
	 */
	public function field_trader_email(): void {
		$this->text_option( Settings::OPT_TRADER_EMAIL, esc_attr( ( new Settings() )->trader_email() ) );
	}

	/**
	 * Render a field description prefixed with how important the setting is, so the merchant can scan
	 * the screen and see at a glance what must be configured, what is advisable and what is optional.
	 *
	 * @param string $tag  One of {@see self::TAG_MANDATORY}, {@see self::TAG_RECOMMENDED},
	 *                     {@see self::TAG_OPTIONAL}.
	 * @param string $text The description, already translated.
	 */
	private function hint( string $tag, string $text ): void {
		$label = match ( $tag ) {
			self::TAG_MANDATORY   => __( 'Mandatory.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			self::TAG_RECOMMENDED => __( 'Recommended.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			default               => __( 'Optional.', 'erred-eu-order-withdrawal-for-woocommerce' ),
		};

		printf(
			'<p class="description"><strong>%s</strong> %s</p>',
			esc_html( $label ),
			esc_html( $text )
		);
	}

	/**
	 * Render a text option as a single-line input.
	 *
	 * @param string $option      Option name.
	 * @param string $placeholder Placeholder shown when empty (already escaped).
	 */
	private function text_option( string $option, string $placeholder ): void {
		$value = (string) get_option( $option, '' );
		printf(
			'<input type="text" name="%s" id="%s" value="%s" placeholder="%s" class="regular-text" />',
			esc_attr( $option ),
			esc_attr( $option ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Render a boolean option as a labelled checkbox.
	 *
	 * @param string $option      Option name.
	 * @param string $description Inline label shown next to the checkbox.
	 */
	private function checkbox_option( string $option, string $description ): void {
		$value = (string) get_option( $option, '0' );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( $option ),
			checked( '1', $value, false ),
			esc_html( $description )
		);
	}

	/**
	 * Render a text option as a textarea.
	 *
	 * @param string $option      Option name.
	 * @param string $placeholder Placeholder shown when empty (already escaped).
	 */
	private function textarea_option( string $option, string $placeholder ): void {
		$value = (string) get_option( $option, '' );
		printf(
			'<textarea name="%s" id="%s" rows="2" class="large-text" placeholder="%s">%s</textarea>',
			esc_attr( $option ),
			esc_attr( $option ),
			esc_attr( $placeholder ),
			esc_textarea( $value )
		);
	}

	/**
	 * Render the delete-data checkbox.
	 */
	public function field_delete_data(): void {
		$value = (string) get_option( 'recesso_dig_delete_data_on_uninstall', '0' );
		printf(
			'<label><input type="checkbox" name="%s" value="1" %s /> %s</label>',
			esc_attr( 'recesso_dig_delete_data_on_uninstall' ),
			checked( '1', $value, false ),
			esc_html__( 'Remove tables and options when the plugin is deleted.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		$this->hint(
			self::TAG_OPTIONAL,
			__( 'Off by default, and deliberately so: withdrawal records and their receipts are legal evidence. Only tick this if you are sure you no longer need them.', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Render a select control.
	 *
	 * @param string                $name    Option name.
	 * @param array<string, string> $options Value => label map.
	 * @param string                $current Current value.
	 */
	private function render_select( string $name, array $options, string $current ): void {
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Sanitise the start-trigger option.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_trigger( $value ): string {
		return Settings::TRIGGER_CONCLUSION === $value ? Settings::TRIGGER_CONCLUSION : Settings::TRIGGER_DELIVERY;
	}

	/**
	 * Sanitise the enforcement-mode option.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_enforcement( $value ): string {
		return Settings::ENFORCEMENT_STRICT === $value ? Settings::ENFORCEMENT_STRICT : Settings::ENFORCEMENT_ADVISORY;
	}

	/**
	 * Sanitise the default-policy option.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_policy( $value ): string {
		$value = is_string( $value ) ? $value : '';

		// An unrecognised value can only reach here through tampering (the control is a select over
		// the three valid options). Fall back to the documented default rather than silently
		// switching the merchant to a stricter policy that would hide the withdrawal function.
		return in_array( $value, array( Settings::POLICY_ALLOW, Settings::POLICY_EXCLUDE, Settings::POLICY_UNCONFIGURED ), true )
			? $value
			: Settings::POLICY_ALLOW;
	}

	/**
	 * Sanitise the button accent to a hex colour, or to the empty string meaning "use the bundled
	 * colour". This value is interpolated into a stylesheet, so nothing that is not `#rrggbb` may
	 * survive: a text sanitiser would happily preserve a payload that closes the CSS rule.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_button_accent( $value ): string {
		return Color::hex( is_string( $value ) ? $value : '' );
	}

	/**
	 * Sanitise the button style choice.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_button_style( $value ): string {
		return Settings::BUTTON_STYLE_THEME === $value
			? Settings::BUTTON_STYLE_THEME
			: Settings::BUTTON_STYLE_PLUGIN;
	}

	/**
	 * Sanitise a checkbox/bool option to '0' or '1'.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_bool( $value ): string {
		return ( '1' === $value || 1 === $value || true === $value ) ? '1' : '0';
	}
}
