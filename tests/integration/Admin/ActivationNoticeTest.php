<?php
/**
 * Integration tests for the one-time post-activation welcome notice.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Admin;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Activator;
use Recesso54bis\Admin\ActivationNotice;

final class ActivationNoticeTest extends TestCase {

	private int $admin_id = 0;

	protected function setUp(): void {
		parent::setUp();
		$this->admin_id = wp_insert_user(
			array(
				'user_login' => 'recesso_admin_notice',
				'user_pass'  => 'pw',
				'role'       => 'administrator',
			)
		);
		wp_set_current_user( $this->admin_id );
	}

	protected function tearDown(): void {
		wp_set_current_user( 0 );
		if ( $this->admin_id > 0 ) {
			wp_delete_user( $this->admin_id );
		}
		delete_transient( Activator::ACTIVATED_TRANSIENT );
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		( new ActivationNotice() )->render();

		return (string) ob_get_clean();
	}

	public function test_notice_shows_once_then_clears(): void {
		set_transient( Activator::ACTIVATED_TRANSIENT, '1', MINUTE_IN_SECONDS );

		$first = $this->render();
		$this->assertStringContainsString( 'is active', $first );
		$this->assertStringContainsString( 'Open settings', $first );

		// One-shot: the transient is cleared, so a second render is empty.
		$this->assertFalse( get_transient( Activator::ACTIVATED_TRANSIENT ) );
		$this->assertSame( '', $this->render() );
	}

	public function test_notice_is_silent_without_the_flag(): void {
		$this->assertSame( '', $this->render() );
	}
}
