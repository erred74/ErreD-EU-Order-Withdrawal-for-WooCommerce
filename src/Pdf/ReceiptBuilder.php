<?php
/**
 * Durable-medium receipt builder.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Integration\RequestedItemsResolver;
use Recesso54bis\Support\Hashing;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the durable-medium PDF receipt for a confirmed withdrawal. The receipt contains the content
 * of the request and the date and time of transmission; a SHA-256 of the canonical payload is
 * returned for tamper-evidence. The PDF is stored in a protected uploads subdirectory (deny-all
 * .htaccess) with an unguessable filename, never a public, guessable URL.
 */
final class ReceiptBuilder {

	/**
	 * Result of building a receipt: the stored path and the canonical-payload hash.
	 */
	public const PRIVATE_DIR = 'recesso-digitale-private';

	/**
	 * Build and store the receipt PDF for a confirmed request.
	 *
	 * @param WithdrawalRequest $request The confirmed request.
	 * @param \WC_Order         $order   The related order.
	 *
	 * @return array{path: string, hash: string}
	 *
	 * @throws \RuntimeException When the receipt cannot be written.
	 */
	public function build( WithdrawalRequest $request, \WC_Order $order ): array {
		$payload = $this->canonical_payload( $request, $order );
		$hash    = Hashing::sha256( (string) wp_json_encode( $payload ) );
		$html    = $this->render_html( $payload, $hash );
		$path    = $this->store( $this->render_pdf( $html ), $request->id );

		return array(
			'path' => $path,
			'hash' => $hash,
		);
	}

	/**
	 * The canonical, deterministically-ordered receipt payload that is hashed.
	 *
	 * @param WithdrawalRequest $request The request.
	 * @param \WC_Order         $order   The order.
	 *
	 * @return array<string, mixed>
	 */
	private function canonical_payload( WithdrawalRequest $request, \WC_Order $order ): array {
		return array(
			'consumer_name'      => $request->consumer_name,
			'confirmation_email' => $request->confirmation_email,
			'contract_reference' => $request->contract_reference,
			'order_id'           => $request->order_id,
			'refund_iban'        => $request->refund_iban,
			'withdrawal_reason'  => $request->withdrawal_reason,
			'requested_items'    => array_values( $request->requested_items ),
			'items'              => $this->resolve_items( $request, $order ),
			'is_partial'         => RequestedItemsResolver::is_partial( $request, $order ),
			'submitted_at_gmt'   => (string) $request->submitted_at_gmt,
			'transmitted_at_gmt' => (string) $request->confirmed_at_gmt,
			'merchant_name'      => $this->merchant_name(),
			'order_total'        => $order->get_total(),
			'order_currency'     => $order->get_currency(),
			'receipt_schema'     => 'recesso-digitale/1',
		);
	}

	/**
	 * Resolve the human-readable items the withdrawal concerns: the named lines for a partial
	 * withdrawal, or every order line when no specific lines were recorded (whole-order withdrawal).
	 *
	 * Delegates to the shared {@see RequestedItemsResolver} (the single source of truth shared with the
	 * acknowledgement email and the frontend screens) and projects each row to exactly the `name` and
	 * `quantity` keys, in order — freezing the canonical-payload shape so the receipt hash is unaffected
	 * by any future presentation field the resolver may add.
	 *
	 * @param WithdrawalRequest $request The request.
	 * @param \WC_Order         $order   The order.
	 *
	 * @return array<int, array{name: string, quantity: int}>
	 */
	private function resolve_items( WithdrawalRequest $request, \WC_Order $order ): array {
		$items = array();
		foreach ( RequestedItemsResolver::resolve( $request, $order ) as $row ) {
			$items[] = array(
				'name'     => $row['name'],
				'quantity' => $row['quantity'],
			);
		}

		return $items;
	}

	/**
	 * Render the receipt HTML from the (overridable) PDF template.
	 *
	 * @param array<string, mixed> $payload The canonical payload.
	 * @param string               $hash    The payload hash.
	 *
	 * @throws \RuntimeException When the template is missing.
	 */
	private function render_html( array $payload, string $hash ): string {
		$template = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'templates/pdf/receipt.php';
		if ( ! is_readable( $template ) ) {
			throw new \RuntimeException( 'Receipt template is missing.' );
		}

		ob_start();
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $args and $hash are consumed by the included template.
		( static function ( string $recesso_dig_template, array $args, string $hash ): void {
			include $recesso_dig_template;
		} )( $template, $payload, $hash );

		return (string) ob_get_clean();
	}

	/**
	 * Render HTML to PDF bytes with remote resource loading disabled.
	 *
	 * @param string $html The receipt HTML.
	 */
	private function render_pdf( string $html ): string {
		$options = new Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'A4' );
		$dompdf->render();

		return (string) $dompdf->output();
	}

	/**
	 * Store the PDF bytes in the protected uploads subdirectory with an unguessable filename.
	 *
	 * @param string $pdf        PDF bytes.
	 * @param int    $request_id Request id (for a human-recognisable filename prefix).
	 *
	 * @throws \RuntimeException When the directory or file cannot be created.
	 */
	private function store( string $pdf, int $request_id ): string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( 'Uploads directory is not available.' );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . self::PRIVATE_DIR;
		wp_mkdir_p( $dir );

		$fs = $this->filesystem();
		$this->protect_directory( $fs, $dir );

		$filename = sprintf( 'receipt-%d-%s.pdf', $request_id, wp_generate_password( 20, false ) );
		$path     = $dir . '/' . $filename;

		if ( ! $fs->put_contents( $path, $pdf, FS_CHMOD_FILE ) ) {
			throw new \RuntimeException( 'Failed to write the receipt PDF.' );
		}

		return $path;
	}

	/**
	 * Ensure the storage directory denies direct web access.
	 *
	 * @param \WP_Filesystem_Base $fs  Filesystem.
	 * @param string              $dir Directory path.
	 */
	private function protect_directory( \WP_Filesystem_Base $fs, string $dir ): void {
		$htaccess = $dir . '/.htaccess';
		if ( ! $fs->exists( $htaccess ) ) {
			$fs->put_contents( $htaccess, "Require all denied\nDeny from all\n", FS_CHMOD_FILE );
		}

		$index = $dir . '/index.html';
		if ( ! $fs->exists( $index ) ) {
			$fs->put_contents( $index, '', FS_CHMOD_FILE );
		}
	}

	/**
	 * Initialise and return the WP_Filesystem instance.
	 *
	 * The receipt is written during the consumer-facing confirmation request, which carries no
	 * filesystem credentials. On hosts where the auto-detected method is not "direct" (e.g. file
	 * ownership differs from the web-server user), a plain `WP_Filesystem()` returns false and leaves
	 * `$wp_filesystem` null — which previously surfaced as a receipt build failure. We force the
	 * "direct" method (the uploads directory is writable by the web server by design) so the durable
	 * receipt is always stored, regardless of request context.
	 *
	 * @throws \RuntimeException When the filesystem cannot be initialised.
	 */
	private function filesystem(): \WP_Filesystem_Base {
		global $wp_filesystem;

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$force_direct = static fn(): string => 'direct';
			add_filter( 'filesystem_method', $force_direct, 99 );
			WP_Filesystem();
			remove_filter( 'filesystem_method', $force_direct, 99 );
		}

		if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			throw new \RuntimeException( 'Filesystem is not available for receipt storage.' );
		}

		return $wp_filesystem;
	}

	/**
	 * The merchant/store display name.
	 */
	private function merchant_name(): string {
		$name = get_bloginfo( 'name' );

		return '' !== $name ? $name : home_url();
	}
}
