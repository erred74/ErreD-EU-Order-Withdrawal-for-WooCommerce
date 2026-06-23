<?php
/**
 * Durable-medium receipt scheduler.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Email\WithdrawalAcknowledgementEmail;
use Recesso54bis\Pdf\ReceiptBuilder;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\Clock;
use Recesso54bis\Support\OrderToken;

defined( 'ABSPATH' ) || exit;

/**
 * Generates and delivers the durable-medium receipt the moment a request is confirmed. The receipt
 * is legally required to exist as soon as the consumer confirms, so it is built and sent
 * **synchronously** on confirmation; only if that build fails (a transient error) is the work retried
 * asynchronously via Action Scheduler (bundled with WooCommerce). A failed email is logged and
 * surfaced — never silently dropped.
 */
final class ReceiptScheduler {

	private const HOOK = 'recesso_dig_generate_receipt';

	/**
	 * Receipt builder.
	 *
	 * @var ReceiptBuilder
	 */
	private ReceiptBuilder $builder;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Audit log repository.
	 *
	 * @var LogRepository
	 */
	private LogRepository $log;

	/**
	 * Order token issuer (for the secure download link).
	 *
	 * @var OrderToken
	 */
	private OrderToken $token;

	/**
	 * Clock.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Construct the scheduler.
	 *
	 * @param ReceiptBuilder    $builder  Receipt builder.
	 * @param RequestRepository $requests Request repository.
	 * @param LogRepository     $log      Audit log repository.
	 * @param OrderToken        $token    Order token issuer.
	 * @param Clock             $clock    Clock.
	 */
	public function __construct(
		ReceiptBuilder $builder,
		RequestRepository $requests,
		LogRepository $log,
		OrderToken $token,
		Clock $clock
	) {
		$this->builder  = $builder;
		$this->requests = $requests;
		$this->log      = $log;
		$this->token    = $token;
		$this->clock    = $clock;
	}

	/**
	 * Register the confirmation hook and the async worker.
	 */
	public function register(): void {
		add_action( 'recesso_dig_request_confirmed', array( $this, 'on_confirmed' ), 10, 1 );
		add_action( self::HOOK, array( $this, 'generate' ), 10, 1 );
	}

	/**
	 * Generate and deliver the receipt immediately when a request is confirmed. The durable document
	 * must exist as soon as the consumer confirms, so this runs synchronously. If the build throws a
	 * transient error it is retried asynchronously via Action Scheduler (when available); `generate()`
	 * is idempotent, so a retry that runs after a successful build is a no-op.
	 *
	 * @param WithdrawalRequest $request The confirmed request.
	 */
	public function on_confirmed( WithdrawalRequest $request ): void {
		try {
			$this->generate( $request->id );
		} catch ( \Throwable $e ) {
			// A failed synchronous build must not break the confirmation response. Record it and retry
			// asynchronously so the legally-required receipt is still produced.
			$this->log->record(
				$request->id,
				LogRepository::EVENT_STATUS_CHANGE,
				'system',
				array(
					'receipt' => 'sync_build_failed',
					'error'   => $e->getMessage(),
				)
			);

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK, array( 'request_id' => $request->id ), 'erred-eu-order-withdrawal-for-woocommerce' );
			}
		}
	}

	/**
	 * Generate the receipt, attach it (write-once) and send the acknowledgement email. Idempotent: a
	 * request that already has a receipt is skipped.
	 *
	 * @param int $request_id Request id.
	 */
	public function generate( int $request_id ): void {
		$request = $this->requests->find_by_id( $request_id );
		if ( null === $request ) {
			$this->log->record( $request_id, LogRepository::EVENT_STATUS_CHANGE, 'system', array( 'receipt' => 'request_not_found' ) );
			return;
		}

		if ( $request->has_receipt() ) {
			return;
		}

		$order = wc_get_order( $request->order_id );
		if ( ! $order instanceof \WC_Order ) {
			$this->log->record( $request_id, LogRepository::EVENT_STATUS_CHANGE, 'system', array( 'receipt' => 'order_not_found' ) );
			return;
		}

		// Build the durable record. May throw on a transient failure; the caller (on_confirmed) or
		// Action Scheduler will retry.
		$receipt = $this->builder->build( $request, $order );

		// Commit the receipt (write-once) BEFORE attempting delivery: the stored PDF + hash are the
		// durable legal record and must survive even if e-mail delivery fails or throws. Previously the
		// attach happened after the send, so a delivery error discarded the receipt entirely.
		$acknowledged = $this->requests->attach_receipt( $request_id, $receipt['hash'], $receipt['path'], $this->clock->now_gmt() );

		// Deliver from the post-attach snapshot so the acknowledgement email carries the receipt hash
		// (verification code) and acknowledged timestamp; fall back to the pre-attach request if the
		// write-once attach was a no-op (idempotent re-run). A delivery failure is logged but never
		// discards the stored receipt.
		$sent = $this->deliver( $acknowledged ?? $request, $receipt['path'], $request_id );

		$this->log->record(
			$request_id,
			LogRepository::EVENT_RECEIPT_SENT,
			'system',
			array( 'email_sent' => $sent )
		);
	}

	/**
	 * Deliver the acknowledgement email, isolating any failure so it never discards the already-stored
	 * durable receipt. Returns whether the email was sent.
	 *
	 * @param WithdrawalRequest $request    The confirmed request.
	 * @param string            $pdf_path   Absolute path to the stored PDF receipt.
	 * @param int               $request_id Request id.
	 */
	private function deliver( WithdrawalRequest $request, string $pdf_path, int $request_id ): bool {
		try {
			// Initialise WooCommerce's mailer (email classes + header/footer hooks + CSS inliner) before
			// sending outside the usual email-triggering flow. The receipt is generated from the
			// consumer-facing confirmation request, where WC()->mailer() has not run; without this,
			// sending a WC_Email there fails — while the admin "regenerate" path (mailer already up)
			// worked. Mirrors the rejection email in the admin controller.
			if ( function_exists( 'WC' ) ) {
				WC()->mailer();
			}

			$download = $this->download_url( $request->order_id, $request_id );

			return ( new WithdrawalAcknowledgementEmail() )->trigger_for( $request, $pdf_path, $download );
		} catch ( \Throwable $e ) {
			$this->log->record(
				$request_id,
				LogRepository::EVENT_STATUS_CHANGE,
				'system',
				array( 'email_error' => $e->getMessage() )
			);

			return false;
		}
	}

	/**
	 * Build the secure, tokenised receipt download URL.
	 *
	 * @param int $order_id   Order id.
	 * @param int $request_id Request id.
	 */
	private function download_url( int $order_id, int $request_id ): string {
		$token = $this->token->issue( $order_id, time() + ( 60 * DAY_IN_SECONDS ) );

		return add_query_arg(
			array(
				'action'  => 'recesso_dig_receipt',
				'request' => $request_id,
				'token'   => rawurlencode( $token ),
			),
			admin_url( 'admin-post.php' )
		);
	}
}
