<?php
/**
 * Integration tests for the Annex I.B model withdrawal form.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Frontend;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Frontend\ModelForm;
use Recesso54bis\Support\Settings;

final class ModelFormTest extends TestCase {

	protected function tearDown(): void {
		delete_option( Settings::OPT_TRADER_NAME );
		delete_option( Settings::OPT_TRADER_EMAIL );
		delete_option( Settings::OPT_TRADER_PHONE );
		delete_option( Settings::OPT_MODEL_FORM_ENABLED );
		parent::tearDown();
	}

	public function test_html_contains_trader_details_and_model_fields(): void {
		update_option( Settings::OPT_TRADER_NAME, 'ACME Srl' );
		update_option( Settings::OPT_TRADER_EMAIL, 'recesso@acme.test' );
		update_option( Settings::OPT_TRADER_PHONE, '+39 06 1234567' );

		$html = ( new ModelForm( new Settings() ) )->html();

		$this->assertStringContainsString( 'ACME Srl', $html );
		$this->assertStringContainsString( 'recesso@acme.test', $html );
		$this->assertStringContainsString( '+39 06 1234567', $html );
		$this->assertStringContainsString( 'Annex I.B', $html );
		$this->assertStringContainsString( 'Name of consumer', $html );
		$this->assertStringContainsString( 'Directive 2011/83/EU', $html );
	}

	public function test_model_form_is_enabled_by_default_and_can_be_disabled(): void {
		$settings = new Settings();
		$this->assertTrue( $settings->model_form_enabled(), 'The model form is shown by default.' );

		update_option( Settings::OPT_MODEL_FORM_ENABLED, '0' );
		$this->assertFalse( $settings->model_form_enabled(), 'The merchant can hide the model form.' );

		// The after-declaration placement must not be hooked when the form is disabled.
		remove_all_actions( 'recesso_dig_after_declaration_form' );
		( new ModelForm( $settings ) )->register();
		$this->assertFalse( has_action( 'recesso_dig_after_declaration_form' ), 'Disabled: no automatic placement.' );

		update_option( Settings::OPT_MODEL_FORM_ENABLED, '1' );
		( new ModelForm( new Settings() ) )->register();
		$this->assertNotFalse( has_action( 'recesso_dig_after_declaration_form' ), 'Enabled: placement is hooked.' );
	}

	public function test_trader_name_falls_back_to_the_site_name(): void {
		delete_option( Settings::OPT_TRADER_NAME );

		$this->assertSame( (string) get_bloginfo( 'name' ), ( new Settings() )->trader_name() );
	}
}
