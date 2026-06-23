<?php
/**
 * WooCommerce Subscriptions adapter.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Persistence\LogRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Optional bridge to WooCommerce Subscriptions. When a confirmed withdrawal concerns an order that
 * carries subscriptions, the right is exercised by *cancelling* the subscription — never by deleting
 * subscription data (§14, §16). The integration is feature-detected: with WooCommerce Subscriptions
 * absent the adapter is inert, so the plugin keeps no hard dependency on it.
 *
 * The plugin records and triggers the cancellation and logs it to the audit trail; it does not
 * reimplement billing. Refund execution remains WooCommerce's / the gateway's responsibility.
 *
 * Not final: the subscription lookup is an overridable seam (`get_subscriptions_for_order`) so the
 * cancellation path can be integration-tested without the separate WooCommerce Subscriptions plugin.
 */
class SubscriptionsAdapter {

	/**
	 * Audit log repository.
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Construct the adapter.
	 *
	 * @param LogRepository $log Audit log repository.
	 */
	public function __construct( LogRepository $log ) {
		$this->log = $log;
	}

	/**
	 * Register the confirmation hook (after receipt generation; cancellation is a side effect).
	 */
	public function register(): void {
		add_action( 'recesso_dig_request_confirmed', array( $this, 'on_confirmed' ), 20, 1 );
	}

	/**
	 * Whether WooCommerce Subscriptions is active and exposes the API we rely on.
	 */
	public function is_available(): bool {
		return class_exists( 'WC_Subscriptions' ) && function_exists( 'wcs_get_subscriptions_for_order' );
	}

	/**
	 * On a confirmed withdrawal, cancel any subscription tied to the order.
	 *
	 * @param WithdrawalRequest $request The confirmed request.
	 */
	public function on_confirmed( WithdrawalRequest $request ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		// Exercising the withdrawal on a subscription order maps to cancellation of the active
		// subscription(s); already-supplied billing periods are left untouched.
		$subscriptions = $this->get_subscriptions_for_order( $request->order_id );

		if ( array() === $subscriptions ) {
			return;
		}

		$note = __( 'Subscription cancelled following a confirmed withdrawal (recesso, art. 54-bis).', 'erred-eu-order-withdrawal-for-woocommerce' );

		foreach ( $subscriptions as $subscription ) {
			if ( ! $subscription instanceof \WC_Subscription ) {
				continue;
			}

			$this->cancel_subscription( $request->id, $subscription, $note );
		}
	}

	/**
	 * Fetch the subscriptions attached to an order (parent + renewal). A thin, overridable wrapper
	 * around the WooCommerce Subscriptions API so the cancellation path can be tested without it.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return array<int, \WC_Subscription>
	 */
	protected function get_subscriptions_for_order( int $order_id ): array {
		$subscriptions = wcs_get_subscriptions_for_order(
			$order_id,
			array( 'order_type' => array( 'parent', 'renewal' ) )
		);

		return is_array( $subscriptions ) ? $subscriptions : array();
	}

	/**
	 * Cancel a single subscription if it can transition, recording the outcome to the audit trail.
	 *
	 * @param int              $request_id   Withdrawal request id.
	 * @param \WC_Subscription $subscription The subscription.
	 * @param string           $note         Order note to attach to the cancellation.
	 */
	private function cancel_subscription( int $request_id, \WC_Subscription $subscription, string $note ): void {
		$subscription_id = (int) $subscription->get_id();

		// Already cancelled/ended: nothing to do, but record that we considered it (idempotent).
		if ( $subscription->has_status( array( 'cancelled', 'expired', 'pending-cancel' ) ) ) {
			$this->log->record(
				$request_id,
				LogRepository::EVENT_STATUS_CHANGE,
				'system',
				array(
					'subscription_id' => $subscription_id,
					'subscription'    => 'already_inactive',
					'status'          => $subscription->get_status(),
				)
			);
			return;
		}

		if ( ! $subscription->can_be_updated_to( 'cancelled' ) ) {
			$this->log->record(
				$request_id,
				LogRepository::EVENT_STATUS_CHANGE,
				'system',
				array(
					'subscription_id' => $subscription_id,
					'subscription'    => 'cancel_not_allowed',
					'status'          => $subscription->get_status(),
				)
			);
			return;
		}

		// Transition status only — subscription data is preserved (append-only legal posture).
		$subscription->update_status( 'cancelled', $note );

		$this->log->record(
			$request_id,
			LogRepository::EVENT_STATUS_CHANGE,
			'system',
			array(
				'subscription_id' => $subscription_id,
				'subscription'    => 'cancelled',
			)
		);
	}
}
