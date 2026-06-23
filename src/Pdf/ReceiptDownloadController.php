<?php
/**
 * Secure receipt download endpoint.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Pdf;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Rest\PermissionGate;

defined( 'ABSPATH' ) || exit;

/**
 * Streams the stored receipt PDF only to an authorised requester (the order owner, a valid token
 * bearer, or an admin). The file lives outside any guessable public path; this endpoint is the only
 * way to read it, and it validates that the stored path is within the protected directory.
 */
final class ReceiptDownloadController {

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Permission gate.
	 *
	 * @var PermissionGate
	 */
	private PermissionGate $gate;

	/**
	 * Construct the controller.
	 *
	 * @param RequestRepository $requests Request repository.
	 * @param PermissionGate    $gate     Permission gate.
	 */
	public function __construct( RequestRepository $requests, PermissionGate $gate ) {
		$this->requests = $requests;
		$this->gate     = $gate;
	}

	/**
	 * Register the download handler.
	 */
	public function register(): void {
		add_action( 'admin_post_recesso_dig_receipt', array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_recesso_dig_receipt', array( $this, 'handle' ) );
	}

	/**
	 * Handle a receipt download request (token/owner/admin authorised; read-only).
	 */
	public function handle(): void {
		$nonce = isset( $_GET['_recesso_dig_receipt'] ) ? sanitize_text_field( wp_unslash( $_GET['_recesso_dig_receipt'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- consumer path is authorised by a signed, rate-limited order token (verified below); the admin path additionally verifies $nonce.
		$request_id = isset( $_GET['request'] ) ? absint( wp_unslash( $_GET['request'] ) ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- single-purpose signed order token, verified in constant time below.
		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display preference, not a state change.
		$inline = isset( $_GET['disposition'] ) && 'inline' === sanitize_key( wp_unslash( $_GET['disposition'] ) );

		$request = $this->requests->find_by_id( $request_id );

		// Authorise via EITHER a valid signed order token (consumer/owner link) OR an admin with a
		// valid receipt nonce. The admin link now carries this nonce (see RequestsListTable and the
		// admin REST controller).
		$token_auth = $request instanceof WithdrawalRequest
			&& $this->gate->can_act_on_order( $request->order_id, $token, time() );
		$admin_auth = $this->gate->can_manage() && (bool) wp_verify_nonce( $nonce, 'recesso_dig_receipt' );
		$allowed    = $request instanceof WithdrawalRequest && ( $token_auth || $admin_auth );

		if ( ! $allowed || ! $request instanceof WithdrawalRequest ) {
			wp_die( esc_html__( 'You are not authorized to perform this action.', 'erred-eu-order-withdrawal-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$path = (string) $request->receipt_path;
		if ( '' === $path || ! $this->is_within_private_dir( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The receipt is not available.', 'erred-eu-order-withdrawal-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( sprintf( 'Content-Disposition: %s; filename="%s"', $inline ? 'inline' : 'attachment', basename( $path ) ) );
		header( 'Content-Length: ' . (string) filesize( $path ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a validated, access-controlled binary file to the browser.
		readfile( $path );
		exit;
	}

	/**
	 * Whether the path resolves inside the protected receipts directory (defence against traversal).
	 *
	 * @param string $path Candidate path.
	 */
	private function is_within_private_dir( string $path ): bool {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return false;
		}

		$base = realpath( trailingslashit( $uploads['basedir'] ) . ReceiptBuilder::PRIVATE_DIR );
		$real = realpath( $path );

		return false !== $base && false !== $real
			&& ( $real === $base || str_starts_with( $real, $base . DIRECTORY_SEPARATOR ) );
	}
}
