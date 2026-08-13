<?php
/**
 * Integration tests for the settings sanitisers and the role-capability sync.
 *
 * The roles matrix is a privileged path: it decides who can read other people's withdrawal
 * requests, so the negative cases matter more than the positive one.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Admin\SettingsPage;
use Recesso54bis\Support\Capabilities;
use Recesso54bis\Support\Settings;

final class SettingsPageTest extends TestCase {

	private SettingsPage $page;

	protected function setUp(): void {
		parent::setUp();
		$this->page = new SettingsPage();
	}

	protected function tearDown(): void {
		delete_option( Settings::OPT_MANAGE_ROLES );
		delete_option( Settings::OPT_ELIGIBLE_STATUSES );

		// Leave the roles as the plugin grants them on activation.
		Capabilities::sync( array( 'shop_manager' ) );

		parent::tearDown();
	}

	public function test_status_sanitiser_keeps_known_statuses_and_drops_the_rest(): void {
		$clean = $this->page->sanitize_statuses( array( 'processing', 'completed', 'not-a-status', '', 'on-hold' ) );

		$this->assertContains( 'processing', $clean );
		$this->assertContains( 'completed', $clean );
		$this->assertContains( 'on-hold', $clean );
		$this->assertNotContains( 'not-a-status', $clean, 'A status WooCommerce does not know about is discarded.' );
		$this->assertNotContains( '', $clean );
	}

	public function test_status_sanitiser_accepts_an_empty_selection(): void {
		// Unticking every status is a valid choice: it silences the prompt without deactivating the
		// plugin, so it must not silently fall back to the defaults.
		$this->assertSame( array(), $this->page->sanitize_statuses( array() ) );
	}

	public function test_role_sanitiser_drops_administrator_and_customer_facing_roles(): void {
		$clean = $this->page->sanitize_roles( array( 'shop_manager', 'administrator', 'subscriber', 'customer', 'nope' ) );

		$this->assertSame( array( 'shop_manager' ), $clean );
	}

	public function test_syncing_roles_grants_and_revokes_the_manage_capability(): void {
		Capabilities::sync( array( 'editor' ) );

		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::MANAGE_REQUESTS ), 'A ticked role gains access.' );
		$this->assertFalse( get_role( 'shop_manager' )->has_cap( Capabilities::MANAGE_REQUESTS ), 'A role left unticked loses access immediately.' );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_REQUESTS ), 'The administrator always keeps access.' );

		// Clean up the role we borrowed for the test.
		get_role( 'editor' )->remove_cap( Capabilities::MANAGE_REQUESTS );
	}

	public function test_syncing_never_grants_the_capability_to_customer_facing_roles(): void {
		// Even asked directly, below-staff roles are refused: withdrawal requests hold personal data.
		Capabilities::sync( array( 'subscriber', 'customer' ) );

		$this->assertFalse( get_role( 'subscriber' )->has_cap( Capabilities::MANAGE_REQUESTS ) );
		$this->assertFalse( get_role( 'customer' )->has_cap( Capabilities::MANAGE_REQUESTS ) );
	}

	public function test_eligible_statuses_default_to_processing_and_completed(): void {
		delete_option( Settings::OPT_ELIGIBLE_STATUSES );

		$this->assertSame( array( 'processing', 'completed' ), ( new Settings() )->eligible_statuses() );
	}

	public function test_stored_eligible_statuses_replace_the_defaults(): void {
		update_option( Settings::OPT_ELIGIBLE_STATUSES, array( 'on-hold' ) );

		$this->assertSame( array( 'on-hold' ), ( new Settings() )->eligible_statuses() );
	}
}
