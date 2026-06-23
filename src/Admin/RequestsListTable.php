<?php
/**
 * Admin list table for withdrawal requests.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Persistence\RequestRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read-only, server-side-paginated list of withdrawal requests. A richer React DataViews admin is a
 * planned enhancement; this provides an accessible baseline so the legal records are visible.
 */
final class RequestsListTable extends \WP_List_Table {

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Construct the table.
	 *
	 * @param RequestRepository $requests Request repository.
	 */
	public function __construct( RequestRepository $requests ) {
		$this->requests = $requests;
		parent::__construct(
			array(
				'singular' => 'recesso_request',
				'plural'   => 'recesso_requests',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Define the columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'id'                  => __( 'ID', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'order_id'            => __( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'status'              => __( 'Status', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'consumer_name'       => __( 'Consumer', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'confirmed_at_gmt'    => __( 'Confirmed (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'acknowledged_at_gmt' => __( 'Acknowledged (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'receipt'             => __( 'Receipt', 'erred-eu-order-withdrawal-for-woocommerce' ),
		);
	}

	/**
	 * Prepare the items with server-side pagination and an optional status filter.
	 */
	public function prepare_items(): void {
		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list paging/filtering.
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list paging/filtering.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list search.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$args  = array(
			'per_page' => $per_page,
			'page'     => $paged,
			'status'   => $status,
			'search'   => $search,
		);
		$total = $this->requests->count_for_admin(
			array(
				'status' => $status,
				'search' => $search,
			)
		);

		$this->items           = $this->requests->query_for_admin( $args );
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Default column renderer.
	 *
	 * @param WithdrawalRequest $item        The request.
	 * @param string            $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'id':
				return esc_html( (string) $item->id );
			case 'order_id':
				return esc_html( (string) $item->order_id );
			case 'status':
				return esc_html( $item->status );
			case 'consumer_name':
				return esc_html( $item->consumer_name );
			case 'confirmed_at_gmt':
				return esc_html( (string) $item->confirmed_at_gmt );
			case 'acknowledged_at_gmt':
				return esc_html( (string) $item->acknowledged_at_gmt );
			default:
				return '';
		}
	}

	/**
	 * Receipt column: a download link when a receipt exists.
	 *
	 * @param WithdrawalRequest $item The request.
	 */
	public function column_receipt( WithdrawalRequest $item ): string {
		if ( ! $item->has_receipt() ) {
			return '&mdash;';
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'recesso_dig_receipt',
					'request' => $item->id,
				),
				admin_url( 'admin-post.php' )
			),
			'recesso_dig_receipt',
			'_recesso_dig_receipt'
		);

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Download', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
	}

	/**
	 * Message shown when there are no requests.
	 */
	public function no_items(): void {
		esc_html_e( 'No withdrawal requests yet.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}
}
