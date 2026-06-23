<?php
/**
 * Minimal WooCommerce Subscriptions stubs for static analysis only.
 *
 * WooCommerce Subscriptions is a separate, optional plugin not present in woocommerce-stubs. The
 * adapter feature-detects it at runtime; these declarations only let PHPStan type-check the guarded
 * code paths. They are never shipped or executed (scanned by PHPStan, excluded from the dist build).
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

// phpcs:disable

if ( ! class_exists( 'WC_Subscriptions' ) ) {
	/**
	 * Marker class used only for feature detection.
	 */
	class WC_Subscriptions {}
}

if ( ! class_exists( 'WC_Subscription' ) ) {
	/**
	 * A subscription behaves like an order with status helpers.
	 */
	class WC_Subscription extends WC_Order {
		/**
		 * @param string|string[] $status Status or list of statuses.
		 * @return bool
		 */
		public function has_status( $status ) {
			return false;
		}

		/**
		 * @param string $status Target status.
		 * @return bool
		 */
		public function can_be_updated_to( $status ) {
			return false;
		}
	}
}

if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
	/**
	 * @param int|WC_Order         $order Order id or object.
	 * @param array<string, mixed> $args  Query args.
	 * @return array<int, WC_Subscription>
	 */
	function wcs_get_subscriptions_for_order( $order, $args = array() ) {
		return array();
	}
}
