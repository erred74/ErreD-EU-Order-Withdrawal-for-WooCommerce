<?php
/**
 * Runtime fake for WooCommerce Subscriptions, used only by the adapter integration test.
 *
 * WooCommerce Subscriptions is a separate paid plugin and is not installed in the test environment.
 * This minimal stand-in lets us exercise the cancellation path. It intentionally defines ONLY
 * WC_Subscription (not the WC_Subscriptions marker class nor wcs_get_subscriptions_for_order), so
 * SubscriptionsAdapter::is_available() still reports false — keeping the "inert when absent" test
 * honest. The adapter's lookup is injected via a test subclass instead.
 *
 * @package Recesso54bis
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable

if ( ! class_exists( 'WC_Subscription' ) ) {
	/**
	 * Records status transitions without touching the database.
	 */
	class WC_Subscription {
		private int $id;
		private string $status;
		/** @var array<int, array{status:string, note:string}> */
		public array $transitions = array();

		public function __construct( int $id, string $status = 'active' ) {
			$this->id     = $id;
			$this->status = $status;
		}

		public function get_id() {
			return $this->id;
		}

		public function get_status() {
			return $this->status;
		}

		/**
		 * @param string|string[] $status
		 */
		public function has_status( $status ) {
			return in_array( $this->status, (array) $status, true );
		}

		/**
		 * Active subscriptions may be cancelled; inactive ones may not.
		 *
		 * @param string $status
		 */
		public function can_be_updated_to( $status ) {
			return 'cancelled' === $status && 'active' === $this->status;
		}

		public function update_status( $status, $note = '' ) {
			$this->status        = $status;
			$this->transitions[] = array(
				'status' => (string) $status,
				'note'   => (string) $note,
			);
		}
	}
}
