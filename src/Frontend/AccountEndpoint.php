<?php
/**
 * My Account "Right of withdrawal" endpoint.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Integration\RequestedItemsResolver;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Right of withdrawal" tab to the WooCommerce My Account area: the customer's orders that can
 * still be withdrawn from, and the requests they have already sent.
 *
 * Listing the requests is not decoration. It used to list eligible orders only, and an order stops
 * being eligible the moment a request claims it — so filing a request made the order vanish from the
 * tab and left the customer reading "none of your orders is currently eligible", the exact opposite
 * of what had just happened. The tab now also carries the receipt code and a permanent link to the
 * receipt PDF, which until now was reachable only through the emailed link and its expiring token.
 *
 * The per-order button below the order details table remains the primary entry point. Members reach
 * the flow through ownership, so the links here carry no token: nothing is exposed that the customer
 * could not already see.
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
	 * How many of the customer's most recent orders the tab considers.
	 */
	private const ORDER_LIMIT = 25;

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
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Audit log repository (source of the merchant's decision note).
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Per-request memo for the orders-list actions filter, which is called once per row.
	 *
	 * @var array<int, WithdrawalRequest|null>
	 */
	private array $request_cache = array();

	/**
	 * Construct the provider.
	 *
	 * @param EligibilityAdapter $eligibility Eligibility adapter.
	 * @param FlowUrls           $urls        Flow URL builder.
	 * @param Settings           $settings    Settings reader.
	 * @param RequestRepository  $requests    Request repository.
	 * @param LogRepository      $log         Audit log repository.
	 */
	public function __construct(
		EligibilityAdapter $eligibility,
		FlowUrls $urls,
		Settings $settings,
		RequestRepository $requests,
		LogRepository $log
	) {
		$this->eligibility = $eligibility;
		$this->urls        = $urls;
		$this->settings    = $settings;
		$this->requests    = $requests;
		$this->log         = $log;
	}

	/**
	 * Register the endpoint, its query var, the menu item, the renderer and the orders-list action.
	 */
	public function register(): void {
		if ( ! $this->settings->account_endpoint_enabled() ) {
			return;
		}

		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_actions' ), 10, 2 );
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
	 * Add a withdrawal action to the My Account orders list.
	 *
	 * An order with a request in progress previously showed nothing here at all, so the customer had
	 * no way to tell an order they had withdrawn from apart from one they had not. Both variants are
	 * real links — the status one points at this tab — so nothing inert is placed in an actions cell.
	 *
	 * @param array<string, array{url: string, name: string}> $actions Row actions.
	 * @param \WC_Order|mixed                                 $order   The order.
	 *
	 * @return array<string, array{url: string, name: string}>
	 */
	public function order_actions( $actions, $order ): array {
		$actions = is_array( $actions ) ? $actions : array();

		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}

		$request = $this->request_for( $order->get_id() );

		if ( $request instanceof WithdrawalRequest && RequestStatus::PENDING !== $request->status ) {
			$actions['recesso_dig_withdrawal'] = array(
				'url'  => wc_get_account_endpoint_url( self::ENDPOINT ),
				'name' => sprintf(
					/* translators: %s: withdrawal request status, e.g. "Registered". */
					__( 'Withdrawal: %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
					$this->status_label( $request->status )
				),
			);

			return $actions;
		}

		$url = $this->declaration_url( $order );
		if ( '' !== $url && $this->eligibility->for_order( $order )->is_eligible ) {
			$actions['recesso_dig_withdrawal'] = array(
				'url'  => $url,
				'name' => $this->label(),
			);
		}

		return $actions;
	}

	/**
	 * Render the tab: the customer's withdrawable orders and the requests they have already sent.
	 */
	public function render(): void {
		$html = Templates::render(
			'account-withdrawal',
			array(
				'rows'      => $this->rows(),
				'label'     => $this->label(),
				'flow_url'  => FlowPage::url(),
				'lookup_ok' => '' !== FlowPage::url(),
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from an escaped template.
		echo $html;
	}

	/**
	 * One row per order that is either withdrawable now or already has a request.
	 *
	 * Bounded by design: the query is capped at the customer's most recent orders rather than
	 * paginating their whole history, and the merchant's decision notes for every row are fetched in a
	 * single query. Documented consequence: the tab shows the latest request per order, within that
	 * window.
	 *
	 * @return array<int, array{number: string, date: string, url: string, action_label: string, has_request: bool, status: string, status_label: string, sent_on: string, scope: string, receipt_code: string, receipt_url: string, note: string}>
	 */
	private function rows(): array {
		$user_id = get_current_user_id();
		if ( $user_id < 1 || ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => self::ORDER_LIMIT,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'type'        => 'shop_order',
			)
		);

		if ( ! is_array( $orders ) ) {
			return array();
		}

		// First pass: decide what each order contributes, and collect the request ids so the notes
		// behind them can be read in one query instead of one per row.
		$pairs       = array();
		$request_ids = array();
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			$request  = $this->request_for( $order->get_id() );
			$eligible = $this->eligibility->for_order( $order )->is_eligible;

			// A rejected or expired request releases its claim, so an order can legitimately carry a
			// past request *and* be withdrawable again. Such a row shows both.
			if ( ! $request instanceof WithdrawalRequest && ! $eligible ) {
				continue;
			}

			$pairs[] = array( $order, $request, $eligible );
			if ( $request instanceof WithdrawalRequest ) {
				$request_ids[] = $request->id;
			}
		}

		$notes = $this->log->latest_status_notes( $request_ids );
		$rows  = array();

		foreach ( $pairs as $pair ) {
			list( $order, $request, $eligible ) = $pair;
			$rows[]                             = $this->row( $order, $request, (bool) $eligible, $notes );
		}

		return $rows;
	}

	/**
	 * Build one view row.
	 *
	 * @param \WC_Order              $order    The order.
	 * @param WithdrawalRequest|null $request  The latest request for it, if any.
	 * @param bool                   $eligible Whether the order can be withdrawn from now.
	 * @param array<int, string>     $notes    Decision notes keyed by request id.
	 *
	 * @return array{number: string, date: string, url: string, action_label: string, has_request: bool, status: string, status_label: string, sent_on: string, scope: string, receipt_code: string, receipt_url: string, note: string}
	 */
	private function row( \WC_Order $order, ?WithdrawalRequest $request, bool $eligible, array $notes ): array {
		$created = $order->get_date_created();
		$pending = $request instanceof WithdrawalRequest && RequestStatus::PENDING === $request->status;

		// A pending request is a declaration that was never confirmed, so it is not yet a legal record:
		// offer the way back into the flow. Submitting step one again discards it (see
		// WithdrawalService::create_declaration()), so there is nothing to clean up first.
		$url = ( $eligible || $pending ) ? $this->declaration_url( $order ) : '';

		$row = array(
			'number'       => $order->get_order_number(),
			'date'         => $created instanceof \WC_DateTime ? wc_format_datetime( $created ) : '',
			'url'          => $url,
			'action_label' => $pending
				? __( 'Continue your withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' )
				: $this->label(),
			'has_request'  => false,
			'status'       => '',
			'status_label' => '',
			'sent_on'      => '',
			'scope'        => '',
			'receipt_code' => '',
			'receipt_url'  => '',
			'note'         => '',
		);

		if ( ! $request instanceof WithdrawalRequest ) {
			return $row;
		}

		$row['has_request']  = true;
		$row['status']       = $request->status;
		$row['status_label'] = $this->status_label( $request->status );
		$row['scope']        = RequestedItemsResolver::is_partial( $request, $order )
			? __( 'Partial withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' )
			: __( 'Full withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );
		$row['note']         = (string) ( $notes[ $request->id ] ?? '' );

		// The moment of communication (the dies a quo) once confirmed; before that, all there is to
		// show is when the declaration was started.
		$row['sent_on'] = $this->local_datetime(
			'' !== (string) $request->confirmed_at_gmt ? (string) $request->confirmed_at_gmt : (string) $request->submitted_at_gmt
		);

		if ( $request->has_receipt() ) {
			$row['receipt_code'] = (string) $request->receipt_hash;
			$row['receipt_url']  = $this->receipt_url( $request->id );
		}

		return $row;
	}

	/**
	 * The permanent receipt link for the logged-in owner of the order.
	 *
	 * Deliberately carries neither a token nor a nonce: the download endpoint authorises the order's
	 * owner directly (see PermissionGate::can_act_on_order()), so a customer reading their own account
	 * needs no credential in the URL — and, unlike the emailed link, this one does not stop working
	 * when the token expires. A durable medium the consumer can no longer open is not durable.
	 *
	 * @param int $request_id Request id.
	 */
	private function receipt_url( int $request_id ): string {
		return add_query_arg(
			array(
				'action'  => 'recesso_dig_receipt',
				'request' => $request_id,
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * The entry link into the flow for an order, or '' when no usable withdrawal page is configured.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function declaration_url( \WC_Order $order ): string {
		$flow_url = FlowPage::url();
		if ( '' === $flow_url ) {
			return '';
		}

		return $this->urls->declaration_url( $flow_url, $order->get_id(), null );
	}

	/**
	 * The latest request for an order, memoised for the orders-list filter (called once per row).
	 *
	 * @param int $order_id Order id.
	 */
	private function request_for( int $order_id ): ?WithdrawalRequest {
		if ( ! array_key_exists( $order_id, $this->request_cache ) ) {
			$this->request_cache[ $order_id ] = $this->requests->latest_for_order( $order_id );
		}

		return $this->request_cache[ $order_id ];
	}

	/**
	 * The customer-facing name of a lifecycle status.
	 *
	 * Deliberately not RequestStatus::to_admin(): the merchant's four-state view collapses "confirmed"
	 * and "acknowledged" into "pending", which would tell a consumer their registered withdrawal is
	 * still waiting to be registered.
	 *
	 * @param string $status Lifecycle status.
	 */
	private function status_label( string $status ): string {
		switch ( $status ) {
			case RequestStatus::PENDING:
				return __( 'Not confirmed yet', 'erred-eu-order-withdrawal-for-woocommerce' );
			case RequestStatus::CONFIRMED:
				return __( 'Registered', 'erred-eu-order-withdrawal-for-woocommerce' );
			case RequestStatus::ACKNOWLEDGED:
				return __( 'Registered — acknowledgement sent', 'erred-eu-order-withdrawal-for-woocommerce' );
			case RequestStatus::ACCEPTED:
				return __( 'Accepted', 'erred-eu-order-withdrawal-for-woocommerce' );
			case RequestStatus::COMPLETED:
				return __( 'Completed', 'erred-eu-order-withdrawal-for-woocommerce' );
			case RequestStatus::REFUNDED:
				return __( 'Refunded', 'erred-eu-order-withdrawal-for-woocommerce' );
			case RequestStatus::REJECTED:
				return __( 'Rejected', 'erred-eu-order-withdrawal-for-woocommerce' );
			case RequestStatus::EXPIRED:
				return __( 'Expired', 'erred-eu-order-withdrawal-for-woocommerce' );
		}

		return $status;
	}

	/**
	 * Render a stored GMT datetime in the site's timezone and format.
	 *
	 * @param string $gmt Datetime in GMT ('' when absent).
	 */
	private function local_datetime( string $gmt ): string {
		$gmt = trim( $gmt );
		if ( '' === $gmt ) {
			return '';
		}

		$timestamp = strtotime( $gmt . ' UTC' );
		if ( false === $timestamp ) {
			return '';
		}

		return (string) wp_date(
			(string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * The mandated, legally-fixed Italian label for the withdrawal function.
	 */
	private function label(): string {
		return __( 'recedere dal contratto qui', 'erred-eu-order-withdrawal-for-woocommerce' );
	}
}
