<?php
/**
 * My Account "Right of withdrawal" endpoint.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Right of withdrawal" tab to the WooCommerce My Account area, listing the customer's orders
 * that are currently eligible and offering the mandated «recedere dal contratto qui» control for each.
 *
 * The per-order button below the order details table remains the primary entry point; this tab makes
 * the function discoverable on its own, which is what "clearly identifiable and easily accessible
 * throughout the withdrawal period" asks for. Members reach the flow through ownership, so the links
 * here carry no token: nothing is exposed that the customer could not already see.
 */
final class AccountEndpoint {

	/**
	 * The rewrite endpoint slug (also the query var and the `woocommerce_account_*_endpoint` suffix).
	 */
	public const ENDPOINT = 'withdrawal';

	/**
	 * Option flagging that the rewrite rules still need flushing for this endpoint.
	 */
	public const FLUSH_FLAG = 'recesso_dig_flush_rewrites';

	/**
	 * Eligibility adapter.
	 *
	 * @var EligibilityAdapter
	 */
	private EligibilityAdapter $eligibility;

	/**
	 * Flow URL builder.
	 *
	 * @var FlowUrls
	 */
	private FlowUrls $urls;

	/**
	 * Settings reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construct the provider.
	 *
	 * @param EligibilityAdapter $eligibility Eligibility adapter.
	 * @param FlowUrls           $urls        Flow URL builder.
	 * @param Settings           $settings    Settings reader.
	 */
	public function __construct( EligibilityAdapter $eligibility, FlowUrls $urls, Settings $settings ) {
		$this->eligibility = $eligibility;
		$this->urls        = $urls;
		$this->settings    = $settings;
	}

	/**
	 * Register the endpoint, its query var, the menu item and the renderer.
	 */
	public function register(): void {
		if ( ! $this->settings->account_endpoint_enabled() ) {
			return;
		}

		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
	}

	/**
	 * Add the rewrite endpoint, flushing the rules once after the endpoint first appears (on
	 * activation, or on the update that introduced it) so the tab is reachable without the merchant
	 * having to re-save permalinks.
	 */
	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		if ( '1' === (string) get_option( self::FLUSH_FLAG, '0' ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Declare the endpoint to WooCommerce so it resolves inside My Account.
	 *
	 * @param array<string, string> $vars WooCommerce query vars.
	 *
	 * @return array<string, string>
	 */
	public function add_query_var( $vars ): array {
		$vars = is_array( $vars ) ? $vars : array();

		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Insert the tab into the My Account navigation, just before "Log out" so it never displaces the
	 * items the customer is used to finding at the top.
	 *
	 * @param array<string, string> $items Menu items.
	 *
	 * @return array<string, string>
	 */
	public function add_menu_item( $items ): array {
		$items = is_array( $items ) ? $items : array();

		$logout = null;
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}

		$items[ self::ENDPOINT ] = __( 'Right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );

		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Render the tab: the customer's currently eligible orders, each with the withdrawal control.
	 */
	public function render(): void {
		$html = Templates::render(
			'account-withdrawal',
			array(
				'orders'    => $this->eligible_orders(),
				'label'     => __( 'recedere dal contratto qui', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'flow_url'  => FlowPage::url(),
				'lookup_ok' => '' !== FlowPage::url(),
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from an escaped template.
		echo $html;
	}

	/**
	 * The current customer's orders that are eligible for withdrawal right now.
	 *
	 * Bounded by design: only the orders that could still be within a withdrawal window are worth
	 * listing, so the query is capped rather than paginating over a customer's whole order history.
	 *
	 * @return array<int, array{number: string, date: string, url: string}>
	 */
	private function eligible_orders(): array {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => 25,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		$eligible = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( ! $this->eligibility->for_order( $order )->is_eligible ) {
				continue;
			}

			$created = $order->get_date_created();

			$eligible[] = array(
				'number' => $order->get_order_number(),
				'date'   => $created instanceof \WC_DateTime ? wc_format_datetime( $created ) : '',
				'url'    => $this->urls->declaration_url( FlowPage::url(), $order->get_id(), null ),
			);
		}

		return $eligible;
	}
}
