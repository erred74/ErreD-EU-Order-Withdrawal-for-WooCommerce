<?php
/**
 * CSV export of withdrawal requests.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Persistence\RequestRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Streams a CSV export of withdrawal requests (optionally filtered by status/search) for the admin.
 * Served through admin-post.php with a capability check and a nonce; each cell is CSV-escaped and
 * guarded against spreadsheet formula injection.
 */
final class CsvExporter {

	public const ACTION = 'recesso_dig_export';

	private const BATCH = 200;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Construct the exporter.
	 *
	 * @param RequestRepository $requests Request repository.
	 */
	public function __construct( RequestRepository $requests ) {
		$this->requests = $requests;
	}

	/**
	 * Hook the admin-post handler.
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The capability-gated, nonced export URL for a given filter.
	 *
	 * @param string $status Status filter.
	 * @param string $search Search filter.
	 */
	public static function url( string $status = '', string $search = '' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'status' => $status,
					'search' => $search,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * Stream the CSV.
	 */
	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not authorized to perform this action.', 'erred-eu-order-withdrawal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by check_admin_referer above.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by check_admin_referer above.
		$search = isset( $_GET['search'] ) ? sanitize_text_field( wp_unslash( $_GET['search'] ) ) : '';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="recesso-digitale-export.csv"' );

		$csv = $this->row(
			array( 'id', 'order_id', 'status', 'consumer_name', 'confirmation_email', 'contract_reference', 'submitted_at_gmt', 'confirmed_at_gmt', 'acknowledged_at_gmt', 'receipt_hash' )
		);

		$page = 1;
		do {
			$rows = $this->requests->query_for_admin(
				array(
					'status'   => $status,
					'search'   => $search,
					'page'     => $page,
					'per_page' => self::BATCH,
				)
			);
			foreach ( $rows as $request ) {
				$csv .= $this->request_row( $request );
			}
			$batch_count = count( $rows );
			++$page;
		} while ( self::BATCH === $batch_count );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download: every cell is CSV-escaped in cell(); HTML escaping would corrupt the file.
		echo $csv;
		exit;
	}

	/**
	 * Build a CSV line for a request.
	 *
	 * @param WithdrawalRequest $request The request.
	 */
	private function request_row( WithdrawalRequest $request ): string {
		return $this->row(
			array(
				(string) $request->id,
				(string) $request->order_id,
				$request->status,
				$request->consumer_name,
				$request->confirmation_email,
				$request->contract_reference,
				(string) $request->submitted_at_gmt,
				(string) $request->confirmed_at_gmt,
				(string) $request->acknowledged_at_gmt,
				(string) $request->receipt_hash,
			)
		);
	}

	/**
	 * Join CSV-escaped cells into a CRLF-terminated line.
	 *
	 * @param string[] $fields The fields.
	 */
	private function row( array $fields ): string {
		return implode( ',', array_map( array( $this, 'cell' ), $fields ) ) . "\r\n";
	}

	/**
	 * Escape one CSV cell and neutralise leading formula characters.
	 *
	 * @param string $value The cell value.
	 */
	private function cell( string $value ): string {
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
			$value = "'" . $value;
		}

		return '"' . str_replace( '"', '""', $value ) . '"';
	}
}
