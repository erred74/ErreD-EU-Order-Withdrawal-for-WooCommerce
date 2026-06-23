<?php
/**
 * Settings registration and screen.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Frontend\FlowPage;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin settings (exposed in REST for a future React admin) and renders a minimal,
 * accessible settings screen using the WordPress Settings API.
 */
final class SettingsPage {

	private const GROUP           = 'recesso_dig';
	private const SECTION_GENERAL = 'recesso_dig_general';
	private const SECTION         = 'recesso_dig_main';
	private const SECTION_EXCLUDE = 'recesso_dig_exclusions';
	private const SECTION_NOTICE  = 'recesso_dig_notice';
	private const SECTION_CONSENT = 'recesso_dig_consent';
	private const SECTION_TRADER  = 'recesso_dig_trader';
	private const SECTION_VISIBLE = 'recesso_dig_visibility';
	private const SECTION_DATA    = 'recesso_dig_data';
	public const MENU_SLUG        = 'recesso-digitale-settings';

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
				'default'           => Settings::POLICY_UNCONFIGURED,
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
		foreach ( array( Settings::OPT_CONSENT_DIGITAL_ENABLED, Settings::OPT_CONSENT_DIGITAL_REQUIRED, Settings::OPT_CONSENT_SERVICE_ENABLED ) as $recesso_dig_bool_opt ) {
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

		add_settings_section(
			self::SECTION_GENERAL,
			__( 'General', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_general_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_ADMIN_RECIPIENTS, __( 'Notification email', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_admin_recipients' ), self::MENU_SLUG, self::SECTION_GENERAL );
		add_settings_field( FlowPage::OPTION, __( 'Withdrawal page', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_flow_page' ), self::MENU_SLUG, self::SECTION_GENERAL );

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
		add_settings_field( Settings::OPT_CONSENT_DIGITAL_ENABLED, __( 'Digital content consent (Art. 16(m))', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_digital_enabled' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_DIGITAL_REQUIRED, __( 'Require digital-content consent', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_digital_required' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_DIGITAL_TEXT, __( 'Digital-content consent text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_digital_text' ), self::MENU_SLUG, self::SECTION_CONSENT );
		add_settings_field( Settings::OPT_CONSENT_SERVICE_ENABLED, __( 'Service-start consent (Art. 14(4)(a))', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_consent_service_enabled' ), self::MENU_SLUG, self::SECTION_CONSENT );
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
		add_settings_field( Settings::OPT_PRODUCT_NOTICE_TEXT, __( 'Exclusion notice text', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_product_notice_text' ), self::MENU_SLUG, self::SECTION_NOTICE );

		add_settings_section(
			self::SECTION_VISIBLE,
			__( 'Withdrawal link visibility', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( $this, 'section_visibility_intro' ),
			self::MENU_SLUG
		);
		add_settings_field( Settings::OPT_FOOTER_LINK_ENABLED, __( 'Show withdrawal link in footer', 'erred-eu-order-withdrawal-for-woocommerce' ), array( $this, 'field_footer_link_enabled' ), self::MENU_SLUG, self::SECTION_VISIBLE );

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
	 * Intro for the visibility section.
	 */
	public function section_visibility_intro(): void {
		echo '<p>' . esc_html__( 'Reinforce that the withdrawal function is continuously available and easily accessible (art. 54-bis).', 'erred-eu-order-withdrawal-for-woocommerce' ) . '</p>';
	}

	/**
	 * Render the settings screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Recesso Digitale — Settings', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
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
	}

	/**
	 * Render the product-notice text field.
	 */
	public function field_product_notice_text(): void {
		$value = (string) get_option( Settings::OPT_PRODUCT_NOTICE_TEXT, '' );
		printf(
			'<textarea name="%s" id="%s" rows="2" class="large-text" placeholder="%s">%s</textarea>',
			esc_attr( Settings::OPT_PRODUCT_NOTICE_TEXT ),
			esc_attr( Settings::OPT_PRODUCT_NOTICE_TEXT ),
			esc_attr__( 'For this product the right of withdrawal does not apply (art. 59 of the Italian Consumer Code).', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_textarea( $value )
		);
	}

	/**
	 * Render the digital-content consent enable checkbox.
	 */
	public function field_consent_digital_enabled(): void {
		$this->checkbox_option( Settings::OPT_CONSENT_DIGITAL_ENABLED, __( 'Show the digital-content consent at checkout.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
	}

	/**
	 * Render the digital-content "required" checkbox.
	 */
	public function field_consent_digital_required(): void {
		$this->checkbox_option( Settings::OPT_CONSENT_DIGITAL_REQUIRED, __( 'The order cannot be placed until this consent is given.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
	}

	/**
	 * Render the digital-content consent text field.
	 */
	public function field_consent_digital_text(): void {
		$this->textarea_option( Settings::OPT_CONSENT_DIGITAL_TEXT, esc_attr( ( new Settings() )->consent_digital_text() ) );
	}

	/**
	 * Render the service-start consent enable checkbox.
	 */
	public function field_consent_service_enabled(): void {
		$this->checkbox_option( Settings::OPT_CONSENT_SERVICE_ENABLED, __( 'Show the optional service-start consent at checkout.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
	}

	/**
	 * Render the service-start consent text field.
	 */
	public function field_consent_service_text(): void {
		$this->textarea_option( Settings::OPT_CONSENT_SERVICE_TEXT, esc_attr( ( new Settings() )->consent_service_text() ) );
	}

	/**
	 * Render the footer-link enable checkbox.
	 */
	public function field_footer_link_enabled(): void {
		$this->checkbox_option( Settings::OPT_FOOTER_LINK_ENABLED, __( 'Add a persistent «recedere dal contratto qui» link to the site footer.', 'erred-eu-order-withdrawal-for-woocommerce' ) );
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

		return in_array( $value, array( Settings::POLICY_ALLOW, Settings::POLICY_EXCLUDE, Settings::POLICY_UNCONFIGURED ), true )
			? $value
			: Settings::POLICY_UNCONFIGURED;
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
