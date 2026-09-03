<?php
/**
 * Integration tests for the server-rendered withdrawal flow.
 *
 * Verifies the two-step screens render correctly (the path that must work without JavaScript),
 * including the mandated wording and authorisation gating.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Container;
use Recesso54bis\Frontend\FlowController;
use Recesso54bis\Frontend\FlowUrls;
use Recesso54bis\Persistence\Schema;
use Recesso54bis\Support\OrderToken;
use Recesso54bis\Support\Settings;

final class FlowControllerTest extends TestCase {

	private FlowController $flow;
	private int $order_id = 0;
	private string $token = '';

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
		update_option( Settings::OPT_DEFAULT_POLICY, Settings::POLICY_ALLOW );
		wp_set_current_user( 0 );

		$container      = new Container();
		$this->flow     = $container->flow_controller();
		$order          = $this->make_order();
		$this->order_id = $order->get_id();
		$this->token    = ( new OrderToken() )->issue( $this->order_id, time() + 3600 );
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $this->order_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), $this->order_id ) );
		$order = wc_get_order( $this->order_id );
		if ( $order instanceof \WC_Order ) {
			$order->delete( true );
		}
		delete_option( Settings::OPT_DEFAULT_POLICY );
		foreach ( self::LOOKUP_TEXT_OPTIONS as $option ) {
			delete_option( $option );
		}
		delete_option( Settings::OPT_BUTTON_ACCENT );
		delete_option( Settings::OPT_BUTTON_STYLE );
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * The four merchant-configurable texts on the lookup screen.
	 */
	private const LOOKUP_TEXT_OPTIONS = array(
		Settings::OPT_LOOKUP_TITLE,
		Settings::OPT_LOOKUP_INTRO,
		Settings::OPT_LOOKUP_EMAIL_HINT,
		Settings::OPT_LOOKUP_SUBMIT,
	);

	/**
	 * Register a stand-in for the block's viewStyle handle and start from a clean queue, so the
	 * inline-style assertions exercise the real enqueue path even when build/ is absent.
	 */
	private function reset_styles(): string {
		$handle = generate_block_asset_handle( 'recesso-digitale/withdrawal-button', 'viewStyle' );
		if ( ! wp_style_is( $handle, 'registered' ) ) {
			wp_register_style( $handle, false, array(), '1.0' );
		}
		wp_styles()->add_data( $handle, 'after', array() );

		return $handle;
	}

	/**
	 * The inline CSS attached to the flow's stylesheet, as one string.
	 */
	private function inline_style_for( string $handle ): string {
		$data = wp_styles()->get_data( $handle, 'after' );

		return is_array( $data ) ? implode( '', $data ) : '';
	}

	private function make_order(): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Recesso Flow Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 1 );
		$order->set_billing_first_name( 'Mario' );
		$order->set_billing_last_name( 'Rossi' );
		$order->set_billing_email( 'mario@example.org' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();

		return $order;
	}

	public function test_declaration_renders_for_authorised_eligible_order(): void {
		$_GET = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DECLARE,
			FlowUrls::QV_ORDER => (string) $this->order_id,
			FlowUrls::QV_TOKEN => $this->token,
		);

		$html = $this->flow->render();

		$this->assertStringContainsString( 'name="consumer_name"', $html );
		$this->assertStringContainsString( 'recesso_dig_declare', $html );
		$this->assertStringContainsString( 'mario@example.org', $html );
		// Each eligible line is selectable with a checkbox carrying the line id.
		$this->assertStringContainsString( 'name="requested_lines[]"', $html );
		// A single-unit line shows no quantity selector (selection implies one unit).
		$this->assertStringNotContainsString( 'name="requested_qty[', $html );
	}

	public function test_declaration_shows_quantity_selector_only_for_multi_unit_lines(): void {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Recesso Qty Test' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( wc_get_product( $product->get_id() ), 3 );
		$order->set_billing_email( 'mario@example.org' );
		$order->set_status( 'completed' );
		$order->set_date_completed( time() );
		$order->save();
		$order_id = $order->get_id();
		$token    = ( new OrderToken() )->issue( $order_id, time() + 3600 );

		$_GET = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DECLARE,
			FlowUrls::QV_ORDER => (string) $order_id,
			FlowUrls::QV_TOKEN => $token,
		);

		$html = $this->flow->render();

		try {
			$this->assertStringContainsString( 'name="requested_lines[]"', $html );
			// The line was ordered in 3 units, so the quantity selector appears with that maximum.
			$this->assertStringContainsString( 'name="requested_qty[', $html );
			$this->assertStringContainsString( 'max="3"', $html );
		} finally {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::requests_table(), $order_id ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE order_id = %d', Schema::claims_table(), $order_id ) );
			$order->delete( true );
		}
	}

	public function test_lookup_form_renders_without_a_signed_link(): void {
		// A direct visit (e.g. the footer link) carries no step: the order-lookup form must appear,
		// never the flow itself — otherwise orders would be enumerable.
		$_GET = array();

		$html = $this->flow->render();

		$this->assertStringContainsString( 'name="order_number"', $html );
		$this->assertStringContainsString( 'name="order_email"', $html );
		$this->assertStringContainsString( 'recesso_dig_lookup', $html );
		$this->assertStringNotContainsString( 'name="consumer_name"', $html );
	}

	public function test_lookup_result_notice_is_uniform(): void {
		// The post-submit notice must be identical whether or not an order matched (anti-enumeration).
		$_GET = array( 'recesso_dig_lookup' => 'sent' );

		$html = $this->flow->render();

		$this->assertStringContainsString( 'sent a withdrawal link', $html );
		$this->assertStringContainsString( 'name="order_number"', $html );
	}

	public function test_lookup_screen_uses_the_merchant_wording_when_set(): void {
		update_option( Settings::OPT_LOOKUP_TITLE, 'Annullare un ordine' );
		update_option( Settings::OPT_LOOKUP_INTRO, 'Scrivici il numero dell ordine e ti richiamiamo.' );
		update_option( Settings::OPT_LOOKUP_EMAIL_HINT, 'Solo la mail usata per l ordine.' );
		update_option( Settings::OPT_LOOKUP_SUBMIT, 'Procedi' );
		$_GET = array();

		$html = $this->flow->render();

		$this->assertStringContainsString( 'Annullare un ordine', $html );
		$this->assertStringContainsString( 'Scrivici il numero dell ordine e ti richiamiamo.', $html );
		$this->assertStringContainsString( 'Solo la mail usata per l ordine.', $html );
		$this->assertStringContainsString( 'Procedi', $html );

		$this->assertStringNotContainsString( 'Exercise your right of withdrawal', $html );
		$this->assertStringNotContainsString( 'We will send a secure withdrawal link', $html );
		$this->assertStringNotContainsString( 'The link is sent to this address only', $html );
		$this->assertStringNotContainsString( 'Send me the withdrawal link', $html );
	}

	/**
	 * Whitespace, not an empty string: a merchant who clears a field by selecting its contents and
	 * hitting space must still get the bundled sentence, not a blank heading.
	 *
	 * Two guards deliver this — `Settings::text_or()` and the template's own fallback — and they are
	 * deliberately redundant, because each must stand on its own: the template is overridable and may
	 * be rendered with raw args, and the getter has callers besides the template. So this test pins
	 * the promise, not either guard: it only fails when both are gone.
	 */
	public function test_lookup_screen_falls_back_to_the_bundled_wording_when_the_options_are_empty(): void {
		foreach ( self::LOOKUP_TEXT_OPTIONS as $option ) {
			update_option( $option, '   ' );
		}
		$_GET = array();

		$html = $this->flow->render();

		$this->assertStringContainsString( 'Exercise your right of withdrawal', $html );
		$this->assertStringContainsString( 'We will send a secure withdrawal link', $html );
		$this->assertStringContainsString( 'The link is sent to this address only', $html );
		$this->assertStringContainsString( 'Send me the withdrawal link', $html );
	}

	public function test_merchant_wording_is_escaped_on_the_lookup_screen(): void {
		update_option( Settings::OPT_LOOKUP_TITLE, '<script>alert(1)</script>' );
		$_GET = array();

		$html = $this->flow->render();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_flow_is_wrapped_for_theme_button_style_only_when_chosen(): void {
		$_GET = array();

		$this->assertStringNotContainsString( 'recesso-dig-theme-buttons', $this->flow->render() );

		update_option( Settings::OPT_BUTTON_STYLE, Settings::BUTTON_STYLE_PLUGIN );
		$this->assertStringNotContainsString( 'recesso-dig-theme-buttons', $this->flow->render() );

		update_option( Settings::OPT_BUTTON_STYLE, Settings::BUTTON_STYLE_THEME );
		$html = $this->flow->render();
		$this->assertStringContainsString( '<div class="recesso-dig-theme-buttons">', $html );
		// The wrapper must be an ancestor of the flow, or the stylesheet's negation never matches.
		$this->assertLessThan(
			(int) strpos( $html, 'wp-block-recesso-digitale-flow' ),
			(int) strpos( $html, 'recesso-dig-theme-buttons' )
		);
	}

	/**
	 * The accent is merchant input that ends up inside a stylesheet. Nothing but a validated hex
	 * colour may be emitted — including when the stored value never went through the settings
	 * screen's sanitiser, which is why the read side validates again.
	 */
	public function test_button_accent_emits_only_whitelisted_hex(): void {
		$_GET = array();

		// An unconfigured site adds no inline CSS at all: the stylesheet already carries the default.
		$handle = $this->reset_styles();
		$this->flow->render();
		$this->assertSame( '', $this->inline_style_for( $handle ) );

		// A configured accent emits exactly three custom properties, and nothing else.
		update_option( Settings::OPT_BUTTON_ACCENT, '#7b2cbf' );
		$handle = $this->reset_styles();
		$this->flow->render();
		$this->assertMatchesRegularExpression(
			'/^\.wp-block-recesso-digitale-flow\{--recesso-dig-accent:#7b2cbf;--recesso-dig-accent-hover:#[0-9a-f]{6};--recesso-dig-accent-text:#[0-9a-f]{6};\}$/',
			$this->inline_style_for( $handle )
		);

		// Written straight to the options table, bypassing the sanitiser: a hand edit, a bad
		// migration, or another plugin. The read side must still refuse it.
		update_option( Settings::OPT_BUTTON_ACCENT, '#c8102e;}body{display:none}' );
		$handle = $this->reset_styles();
		$this->flow->render();
		$this->assertSame( '', $this->inline_style_for( $handle ) );

		// In theme mode the plugin colours nothing, whatever accent is stored.
		update_option( Settings::OPT_BUTTON_ACCENT, '#7b2cbf' );
		update_option( Settings::OPT_BUTTON_STYLE, Settings::BUTTON_STYLE_THEME );
		$handle = $this->reset_styles();
		$this->flow->render();
		$this->assertSame( '', $this->inline_style_for( $handle ) );
	}

	public function test_declaration_denies_invalid_token(): void {
		$_GET = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DECLARE,
			FlowUrls::QV_ORDER => (string) $this->order_id,
			FlowUrls::QV_TOKEN => 'invalid-token',
		);

		$html = $this->flow->render();

		$this->assertStringNotContainsString( 'name="consumer_name"', $html );
		$this->assertStringContainsString( 'not valid or has expired', $html );
	}

	public function test_confirm_then_done_render(): void {
		$container = new Container();
		$request   = $container->withdrawal_service()->create_declaration(
			wc_get_order( $this->order_id ),
			array(
				'consumer_name'      => 'Mario Rossi',
				'contract_reference' => '#' . $this->order_id,
				'confirmation_email' => 'mario@example.org',
			),
			null
		);

		$_GET         = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_CONFIRM,
			FlowUrls::QV_ID    => (string) $request->id,
			FlowUrls::QV_TOKEN => $this->token,
		);
		$confirm_html = $this->flow->render();
		$this->assertStringContainsString( 'recesso_dig_confirm', $confirm_html );
		$this->assertStringContainsString( 'Confirm withdrawal', $confirm_html );

		// Confirm the request, then the done screen should render.
		$container->withdrawal_service()->confirm( $request->id, 'consumer' );

		$_GET      = array(
			FlowUrls::QV_STEP  => FlowUrls::STEP_DONE,
			FlowUrls::QV_ID    => (string) $request->id,
			FlowUrls::QV_TOKEN => $this->token,
		);
		$done_html = $this->flow->render();
		$this->assertStringContainsString( 'has been recorded', $done_html );
	}
}
