<?php
/**
 * Integration tests for the WooCommerce Subscriptions adapter.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Integration;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Integration\SubscriptionsAdapter;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\Schema;

require_once __DIR__ . '/../fixtures/class-fake-wc-subscription.php';
require_once __DIR__ . '/../fixtures/class-stub-subscriptions-adapter.php';

final class SubscriptionsAdapterTest extends TestCase {

	private int $request_id = 700100;

	protected function setUp(): void {
		parent::setUp();
		Migrations::run();
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE request_id = %d', Schema::log_table(), $this->request_id ) );
		parent::tearDown();
	}

	private function make_request(): WithdrawalRequest {
		return new WithdrawalRequest(
			$this->request_id,
			999001,
			RequestStatus::CONFIRMED,
			'Marco Verdi',
			'#999001',
			'marco@example.test',
			array(),
			'2026-06-19 10:00:00',
			'2026-06-19 10:05:00',
			null,
			null,
			null,
			'2026-06-19 10:00:00'
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function subscription_events(): array {
		$events = ( new LogRepository() )->for_request( $this->request_id );

		return array_values(
			array_filter(
				$events,
				static function ( array $row ): bool {
					$payload = json_decode( (string) $row['payload'], true );
					return is_array( $payload ) && isset( $payload['subscription'] );
				}
			)
		);
	}

	public function test_inert_when_subscriptions_unavailable(): void {
		// The real adapter in the test environment: WooCommerce Subscriptions is not installed.
		$adapter = new SubscriptionsAdapter( new LogRepository() );

		$this->assertFalse( $adapter->is_available() );

		// Firing the confirmation must be a harmless no-op (no fatal, no subscription log rows).
		$adapter->on_confirmed( $this->make_request() );

		$this->assertSame( array(), $this->subscription_events() );
	}

	public function test_cancels_active_subscription_and_logs(): void {
		$subscription = new \WC_Subscription( 5501, 'active' );
		$adapter      = new StubSubscriptionsAdapter( new LogRepository(), array( $subscription ) );

		$adapter->on_confirmed( $this->make_request() );

		// Status transitioned to cancelled (transition, not deletion).
		$this->assertSame( 'cancelled', $subscription->get_status() );
		$this->assertCount( 1, $subscription->transitions );
		$this->assertSame( 'cancelled', $subscription->transitions[0]['status'] );

		$events = $this->subscription_events();
		$this->assertCount( 1, $events );
		$payload = json_decode( (string) $events[0]['payload'], true );
		$this->assertSame( 'cancelled', $payload['subscription'] );
		$this->assertSame( 5501, $payload['subscription_id'] );
		$this->assertSame( 'system', $events[0]['actor'] );
	}

	public function test_skips_already_cancelled_subscription(): void {
		$subscription = new \WC_Subscription( 5502, 'cancelled' );
		$adapter      = new StubSubscriptionsAdapter( new LogRepository(), array( $subscription ) );

		$adapter->on_confirmed( $this->make_request() );

		// No further transition recorded on the subscription itself.
		$this->assertSame( array(), $subscription->transitions );

		$events = $this->subscription_events();
		$this->assertCount( 1, $events );
		$payload = json_decode( (string) $events[0]['payload'], true );
		$this->assertSame( 'already_inactive', $payload['subscription'] );
	}
}
