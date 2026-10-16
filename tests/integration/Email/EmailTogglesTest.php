<?php
/**
 * Integration tests for the WooCommerce enable/disable switches on this plugin's emails.
 *
 * Two of the five ignore that switch on purpose, and the code used to justify it with a claim about
 * how WooCommerce treats an email whose settings have never been saved. The claim was wrong. These
 * tests pin what actually happens, so the next person to reason about it starts from a fact: nobody
 * should have to re-derive this from the WooCommerce source, and a merchant who unticks a box is
 * entitled to know whether it did anything.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Email;

use PHPUnit\Framework\TestCase;

final class EmailTogglesTest extends TestCase {

	/**
	 * Settings keys emptied before and after, so each test starts from a never-configured install.
	 */
	private const SETTINGS_KEYS = array(
		'woocommerce_recesso_dig_acknowledgement_settings',
		'woocommerce_recesso_dig_link_settings',
		'woocommerce_recesso_dig_rejection_settings',
		'woocommerce_recesso_dig_status_update_settings',
		'woocommerce_recesso_dig_admin_notification_settings',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->forget_email_settings();
		WC()->mailer();
	}

	protected function tearDown(): void {
		$this->forget_email_settings();
		parent::tearDown();
	}

	private function forget_email_settings(): void {
		foreach ( self::SETTINGS_KEYS as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * On a site where nobody has opened WooCommerce → Settings → Emails, every one of the plugin's
	 * emails must already be live. The merchant notification in particular: the plugin offers a
	 * "Notification email" recipients field, and a recipients field for an email that never sends
	 * would be a setting that lies.
	 *
	 * @dataProvider provide_email_classes
	 *
	 * @param string $email_class Fully-qualified email class.
	 */
	public function test_every_email_is_enabled_before_anything_is_saved( string $email_class ): void {
		$email = new $email_class();

		$this->assertTrue(
			$email->is_enabled(),
			sprintf( '%s must be enabled on a site that has never saved its email settings.', $email_class )
		);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_email_classes(): array {
		return array(
			'acknowledgement'    => array( \Recesso54bis\Email\WithdrawalAcknowledgementEmail::class ),
			'link'               => array( \Recesso54bis\Email\WithdrawalLinkEmail::class ),
			'rejection'          => array( \Recesso54bis\Email\WithdrawalRejectionEmail::class ),
			'status update'      => array( \Recesso54bis\Email\WithdrawalStatusUpdateEmail::class ),
			'admin notification' => array( \Recesso54bis\Email\WithdrawalAdminNotificationEmail::class ),
		);
	}

	/**
	 * All five must be registered with WooCommerce, or the merchant cannot reach their subject and
	 * heading at all — which is the thing they are most likely to want to change.
	 */
	public function test_all_five_emails_reach_the_woocommerce_emails_screen(): void {
		$registered = array_keys( WC()->mailer()->get_emails() );

		foreach ( array( 'recesso_dig_acknowledgement', 'recesso_dig_link', 'recesso_dig_rejection', 'recesso_dig_status_update', 'recesso_dig_admin_notification' ) as $id ) {
			$this->assertContains( $id, $registered, sprintf( '%s is missing from WooCommerce → Settings → Emails.', $id ) );
		}
	}

	/**
	 * The two that ignore the switch must say so where the switch is, otherwise the screen shows a
	 * control that does nothing and gives no hint of it.
	 */
	public function test_the_emails_that_ignore_the_switch_say_so_in_their_description(): void {
		foreach ( array( \Recesso54bis\Email\WithdrawalAcknowledgementEmail::class, \Recesso54bis\Email\WithdrawalLinkEmail::class ) as $class ) {
			$email = new $class();

			$this->assertStringContainsString(
				'Enable switch does not apply',
				(string) $email->get_description(),
				sprintf( '%s bypasses is_enabled() without telling the merchant.', $class )
			);
		}
	}
}
