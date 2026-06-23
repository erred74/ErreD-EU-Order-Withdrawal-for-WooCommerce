<?php
/**
 * Withdrawal-status column on the WooCommerce orders list.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Persistence\RequestRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Withdrawal" column to the WooCommerce orders screen — both the HPOS orders table
 * (page=wc-orders) and the legacy posts table — showing each order's current withdrawal decision
 * (pending / accepted / rejected / completed) as a colour-coded badge.
 */
final class OrderColumn {

	private const COLUMN_KEY = 'recesso_dig_status';

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Per-request memo of the resolved admin status per order id (the list renders columns in one pass).
	 *
	 * @var array<int, string|null>
	 */
	private array $cache = array();

	/**
	 * Construct the provider.
	 *
	 * @param RequestRepository $requests Request repository.
	 */
	public function __construct( RequestRepository $requests ) {
		$this->requests = $requests;
	}

	/**
	 * Hook the column on both the HPOS and the legacy orders screens.
	 */
	public function register(): void {
		// HPOS orders table (page=wc-orders).
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );

		// Legacy posts-based orders table.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_legacy_column' ), 10, 2 );
	}

	/**
	 * Insert the withdrawal column after the order-status column (or at the end as a fallback).
	 *
	 * @param array<string, string> $columns Existing columns.
	 *
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$with_column = array();
		foreach ( $columns as $key => $label ) {
			$with_column[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$with_column[ self::COLUMN_KEY ] = __( 'Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );
			}
		}

		if ( ! isset( $with_column[ self::COLUMN_KEY ] ) ) {
			$with_column[ self::COLUMN_KEY ] = __( 'Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );
		}

		return $with_column;
	}

	/**
	 * Render the column on the HPOS orders table.
	 *
	 * @param string              $column Column key.
	 * @param \WC_Order|int|mixed $order  The order (object on HPOS).
	 */
	public function render_column( string $column, $order ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		$order_id = $order instanceof \WC_Order ? $order->get_id() : (int) $order;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes every dynamic part.
		echo $this->badge_for( $order_id );
	}

	/**
	 * Render the column on the legacy posts-based orders table.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id The order post id.
	 */
	public function render_legacy_column( string $column, $post_id ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge() escapes every dynamic part.
		echo $this->badge_for( (int) $post_id );
	}

	/**
	 * Build the badge (or an em dash) for an order's latest withdrawal request.
	 *
	 * @param int $order_id Order id.
	 */
	private function badge_for( int $order_id ): string {
		if ( ! array_key_exists( $order_id, $this->cache ) ) {
			$request                  = $this->requests->latest_for_order( $order_id );
			$this->cache[ $order_id ] = null === $request ? null : RequestStatus::to_admin( $request->status );
		}

		$admin = $this->cache[ $order_id ];
		if ( null === $admin ) {
			return '<span aria-hidden="true">&mdash;</span>';
		}

		return $this->badge( $admin );
	}

	/**
	 * A colour-coded inline badge for an admin status. Colours never carry meaning alone — the label is
	 * always present (WCAG 1.4.1).
	 *
	 * @param string $admin One of {@see RequestStatus::admin_statuses()}.
	 */
	private function badge( string $admin ): string {
		$styles = array(
			RequestStatus::PENDING   => array( __( 'Pending', 'erred-eu-order-withdrawal-for-woocommerce' ), '#dcdcde', '#1d2327' ),
			RequestStatus::ACCEPTED  => array( __( 'Accepted', 'erred-eu-order-withdrawal-for-woocommerce' ), '#c6e1c6', '#14502a' ),
			RequestStatus::REJECTED  => array( __( 'Rejected', 'erred-eu-order-withdrawal-for-woocommerce' ), '#f1adad', '#7a1c1c' ),
			RequestStatus::COMPLETED => array( __( 'Completed', 'erred-eu-order-withdrawal-for-woocommerce' ), '#c8d7e1', '#0a4b78' ),
		);

		$style = $styles[ $admin ] ?? $styles[ RequestStatus::PENDING ];

		return sprintf(
			'<span style="display:inline-block;padding:2px 8px;border-radius:4px;font-weight:600;background:%s;color:%s;">%s</span>',
			esc_attr( $style[1] ),
			esc_attr( $style[2] ),
			esc_html( $style[0] )
		);
	}
}
