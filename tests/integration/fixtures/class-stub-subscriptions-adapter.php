<?php
/**
 * Test double for the Subscriptions adapter.
 *
 * Bypasses feature detection and injects fake subscriptions so the cancellation path is exercised
 * without the (paid, separate) WooCommerce Subscriptions plugin.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration\Integration;

use Recesso54bis\Integration\SubscriptionsAdapter;
use Recesso54bis\Persistence\LogRepository;

/**
 * Overrides the availability check and subscription lookup with test-controlled values.
 */
class StubSubscriptionsAdapter extends SubscriptionsAdapter {

	/**
	 * Subscriptions to return from the lookup.
	 *
	 * @var array<int, \WC_Subscription>
	 */
	private array $subscriptions;

	/**
	 * @param LogRepository                 $log           Audit log repository.
	 * @param array<int, \WC_Subscription> $subscriptions Fakes to return.
	 */
	public function __construct( LogRepository $log, array $subscriptions ) {
		parent::__construct( $log );
		$this->subscriptions = $subscriptions;
	}

	public function is_available(): bool {
		return true;
	}

	protected function get_subscriptions_for_order( int $order_id ): array {
		return $this->subscriptions;
	}
}
