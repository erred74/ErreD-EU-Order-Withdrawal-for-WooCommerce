<?php
/**
 * Admin withdrawals REST controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Rest;

use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Email\WithdrawalRejectionEmail;
use Recesso54bis\Email\WithdrawalStatusUpdateEmail;
use Recesso54bis\Integration\ReceiptScheduler;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-only endpoints powering the React admin: a paginated, filterable list, a single request with
 * its audit timeline, and processing actions (reject, mark refunded, (re)generate receipt). Every
 * route requires the manage capability and the REST cookie nonce (supplied automatically by
 *
 * @wordpress/api-fetch).
 */
final class AdminWithdrawalsController extends Controller {

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Receipt scheduler (for the regenerate action).
	 *
	 * @var ReceiptScheduler
	 */
	private ReceiptScheduler $receipts;

	/**
	 * Construct the controller.
	 *
	 * @param PermissionGate    $gate     Permission gate.
	 * @param RateLimiter       $rate     Rate limiter.
	 * @param LogRepository     $log      Audit log repository.
	 * @param RequestRepository $requests Request repository.
	 * @param ReceiptScheduler  $receipts Receipt scheduler.
	 */
	public function __construct(
		PermissionGate $gate,
		RateLimiter $rate,
		LogRepository $log,
		RequestRepository $requests,
		ReceiptScheduler $receipts
	) {
		parent::__construct( $gate, $rate, $log );
		$this->requests = $requests;
		$this->receipts = $receipts;
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/admin/withdrawals',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'search'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'     => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/withdrawals/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/withdrawals/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array( 'id' => $this->id_arg() ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/admin/withdrawals/(?P<id>\d+)/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'process' ),
				'permission_callback' => array( $this, 'admin_permission' ),
				'args'                => array(
					'id'     => $this->id_arg(),
					'action' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool => in_array( $value, array( 'set_status', 'reject', 'accept', 'refunded', 'complete', 'regenerate' ), true ),
					),
					'status' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ): bool => '' === $value || in_array( $value, RequestStatus::admin_statuses(), true ),
					),
					'reason' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: requires the manage capability (and the REST cookie nonce).
	 *
	 * @return true|\WP_Error
	 */
	public function admin_permission() {
		if ( $this->gate->can_manage() ) {
			return true;
		}

		return $this->denied();
	}

	/**
	 * List requests with pagination and an optional status filter.
	 *
	 * @param \WP_REST_Request $request The request.
	 */
	public function get_items( \WP_REST_Request $request ): \WP_REST_Response {
		$status   = (string) $request->get_param( 'status' );
		$search   = (string) $request->get_param( 'search' );
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

		$items = $this->requests->query_for_admin(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$total = $this->requests->count_for_admin(
			array(
				'status' => $status,
				'search' => $search,
			)
		);

		$data = array_map(
			fn( WithdrawalRequest $item ): array => $this->present_admin( $item ),
			$items
		);

		$response = new \WP_REST_Response( $data, 200 );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / $per_page ) );

		return $response;
	}

	/**
	 * At-a-glance stats for the admin dashboard: requests awaiting action, acknowledged, the total, and
	 * the number confirmed in the last 30 days.
	 */
	public function stats(): \WP_REST_Response {
		$since = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		return new \WP_REST_Response(
			array(
				'open'             => $this->requests->count_for_admin( array( 'status' => RequestStatus::CONFIRMED ) ),
				'acknowledged'     => $this->requests->count_for_admin( array( 'status' => RequestStatus::ACKNOWLEDGED ) ),
				'total'            => $this->requests->count_for_admin( array() ),
				'recent_confirmed' => $this->requests->count_confirmed_since( $since ),
			),
			200
		);
	}

	/**
	 * Read a single request with its audit timeline.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		$record = $this->requests->find_by_id( (int) $request->get_param( 'id' ) );
		if ( null === $record ) {
			return new \WP_Error( 'recesso_dig_not_found', __( 'Request not found.', 'erred-eu-order-withdrawal-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$data             = $this->present_admin( $record );
		$data['timeline'] = $this->log->for_request( $record->id );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Process an admin action against a request.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function process( \WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$action = (string) $request->get_param( 'action' );
		$target = (string) $request->get_param( 'status' );
		$reason = trim( (string) $request->get_param( 'reason' ) );

		$record = $this->requests->find_by_id( $id );
		if ( null === $record ) {
			return new \WP_Error( 'recesso_dig_not_found', __( 'Request not found.', 'erred-eu-order-withdrawal-for-woocommerce' ), array( 'status' => 404 ) );
		}

		// A rejection must carry a reason: it is communicated to the consumer and recorded. This holds
		// whether the rejection comes via the legacy "reject" action or the "set_status" dropdown.
		$rejecting = ( 'reject' === $action ) || ( 'set_status' === $action && RequestStatus::REJECTED === $target );
		if ( $rejecting && '' === $reason ) {
			return new \WP_Error(
				'recesso_dig_reason_required',
				__( 'A reason is required to reject a withdrawal request.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$actor = 'admin:' . get_current_user_id();

		switch ( $action ) {
			case 'set_status':
				$this->apply_admin_status( $record, $target, $reason, $actor );
				break;
			case 'reject':
				$this->requests->transition_status( $id, RequestStatus::REJECTED );
				$this->log->record(
					$id,
					LogRepository::EVENT_STATUS_CHANGE,
					$actor,
					array(
						'to'     => RequestStatus::REJECTED,
						'reason' => $reason,
					)
				);
				$this->notify_rejection( $record, $reason );
				break;
			case 'accept':
				$this->requests->transition_status( $id, RequestStatus::ACCEPTED );
				$this->log->record( $id, LogRepository::EVENT_STATUS_CHANGE, $actor, array( 'to' => RequestStatus::ACCEPTED ) );
				$this->notify_status_update( $record, RequestStatus::ACCEPTED );
				break;
			case 'refunded':
				$this->requests->transition_status( $id, RequestStatus::REFUNDED );
				$this->log->record( $id, LogRepository::EVENT_STATUS_CHANGE, $actor, array( 'to' => RequestStatus::REFUNDED ) );
				$this->notify_status_update( $record, RequestStatus::REFUNDED );
				break;
			case 'complete':
				$this->requests->transition_status( $id, RequestStatus::COMPLETED );
				$this->log->record( $id, LogRepository::EVENT_STATUS_CHANGE, $actor, array( 'to' => RequestStatus::COMPLETED ) );
				$this->notify_status_update( $record, RequestStatus::COMPLETED );
				break;
			case 'regenerate':
				$this->receipts->generate( $id );
				$this->log->record( $id, LogRepository::EVENT_STATUS_CHANGE, $actor, array( 'action' => 'regenerate' ) );
				break;
		}

		/**
		 * Fires after an admin processes a withdrawal request (reject / refunded / regenerate).
		 *
		 * @param int    $id     Request id.
		 * @param string $action The processing action.
		 */
		do_action( 'recesso_dig_request_processed', $id, $action );

		$updated = $this->requests->find_by_id( $id );
		if ( null === $updated ) {
			return new \WP_Error( 'recesso_dig_not_found', __( 'Request not found.', 'erred-eu-order-withdrawal-for-woocommerce' ), array( 'status' => 404 ) );
		}

		$data             = $this->present_admin( $updated );
		$data['timeline'] = $this->log->for_request( $id );

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Apply one of the simplified admin decisions (pending / accepted / rejected / completed) from the
	 * detail panel's "set status" dropdown: transition the lifecycle status, record it in the audit log
	 * and send the matching customer email. "Pending" returns the request to its resting state
	 * (acknowledged when a receipt exists, otherwise confirmed) without emailing.
	 *
	 * @param WithdrawalRequest $record The request.
	 * @param string            $target One of {@see RequestStatus::admin_statuses()}.
	 * @param string            $reason Reason (required, already validated, when rejecting).
	 * @param string            $actor  Audit actor string.
	 */
	private function apply_admin_status( WithdrawalRequest $record, string $target, string $reason, string $actor ): void {
		$id = $record->id;

		switch ( $target ) {
			case RequestStatus::ACCEPTED:
				$this->requests->transition_status( $id, RequestStatus::ACCEPTED );
				$this->log->record( $id, LogRepository::EVENT_STATUS_CHANGE, $actor, array( 'to' => RequestStatus::ACCEPTED ) );
				$this->notify_status_update( $record, RequestStatus::ACCEPTED );
				break;
			case RequestStatus::COMPLETED:
				$this->requests->transition_status( $id, RequestStatus::COMPLETED );
				$this->log->record( $id, LogRepository::EVENT_STATUS_CHANGE, $actor, array( 'to' => RequestStatus::COMPLETED ) );
				$this->notify_status_update( $record, RequestStatus::COMPLETED );
				break;
			case RequestStatus::REJECTED:
				$this->requests->transition_status( $id, RequestStatus::REJECTED );
				$this->log->record(
					$id,
					LogRepository::EVENT_STATUS_CHANGE,
					$actor,
					array(
						'to'     => RequestStatus::REJECTED,
						'reason' => $reason,
					)
				);
				$this->notify_rejection( $record, $reason );
				break;
			case RequestStatus::PENDING:
			default:
				// "Pending" undoes a decision: return to the natural resting state without emailing. The
				// legal facts (confirmed_at_gmt, receipt_hash) are write-once and untouched.
				$resting = '' !== (string) $record->acknowledged_at_gmt ? RequestStatus::ACKNOWLEDGED : RequestStatus::CONFIRMED;
				$this->requests->transition_status( $id, $resting );
				$this->log->record(
					$id,
					LogRepository::EVENT_STATUS_CHANGE,
					$actor,
					array(
						'to'   => $resting,
						'note' => 'reset_to_pending',
					)
				);
				break;
		}
	}

	/**
	 * Notify the consumer that their withdrawal was not accepted, with the reason, and record the
	 * delivery outcome. A failed email never breaks the admin action — the decision is already logged.
	 *
	 * @param WithdrawalRequest $request The rejected request.
	 * @param string            $reason  The merchant's reason.
	 */
	private function notify_rejection( WithdrawalRequest $request, string $reason ): void {
		if ( ! function_exists( 'WC' ) || ! class_exists( '\WC_Email' ) ) {
			return;
		}

		// Ensure WooCommerce's mailer (email classes + header/footer hooks) is initialised before
		// we send outside the usual email-triggering flow.
		WC()->mailer();

		$sent = ( new WithdrawalRejectionEmail() )->trigger_for( $request, $reason );

		$this->log->record(
			$request->id,
			LogRepository::EVENT_STATUS_CHANGE,
			'system',
			array( 'rejection_email_sent' => $sent )
		);
	}

	/**
	 * Notify the consumer of a positive status change (accepted / refunded / completed) and record the
	 * delivery outcome. A failed email never breaks the admin action — the transition is already logged.
	 *
	 * @param WithdrawalRequest $request The request.
	 * @param string            $status  The new status.
	 */
	private function notify_status_update( WithdrawalRequest $request, string $status ): void {
		if ( ! function_exists( 'WC' ) || ! class_exists( '\WC_Email' ) || ! WithdrawalStatusUpdateEmail::handles( $status ) ) {
			return;
		}

		WC()->mailer();

		$sent = ( new WithdrawalStatusUpdateEmail() )->trigger_for( $request, $status );

		$this->log->record(
			$request->id,
			LogRepository::EVENT_STATUS_CHANGE,
			'system',
			array( 'status_email_sent' => $sent )
		);
	}

	/**
	 * Present a request for the admin, adding a capability-gated inline receipt view URL when a
	 * receipt exists.
	 *
	 * @param WithdrawalRequest $request The request.
	 *
	 * @return array<string, mixed>
	 */
	private function present_admin( WithdrawalRequest $request ): array {
		$data = $this->present_request( $request );

		$data['receipt_url'] = $request->has_receipt()
			? wp_nonce_url(
				add_query_arg(
					array(
						'action'      => 'recesso_dig_receipt',
						'request'     => $request->id,
						'disposition' => 'inline',
					),
					admin_url( 'admin-post.php' )
				),
				'recesso_dig_receipt',
				'_recesso_dig_receipt'
			)
			: '';

		list( $items, $is_partial ) = $this->withdrawal_items( $request );
		$data['items']              = $items;
		$data['is_partial']         = $is_partial;
		$data['refund_iban']        = $request->refund_iban;
		$data['withdrawal_reason']  = $request->withdrawal_reason;
		$data['admin_status']       = RequestStatus::to_admin( $request->status );

		// The receipt verification code (SHA-256 of the canonical payload) — the same code the consumer
		// receives in the acknowledgement email — shown in the admin detail card for cross-checking.
		$data['receipt_hash'] = (string) $request->receipt_hash;

		return $data;
	}

	/**
	 * Resolve the human-readable items a request concerns and whether it is a partial withdrawal
	 * (fewer lines than the order has). Returns a list of "name × qty" labels and the partial flag.
	 *
	 * @param WithdrawalRequest $request The request.
	 *
	 * @return array{0: string[], 1: bool}
	 */
	private function withdrawal_items( WithdrawalRequest $request ): array {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $request->order_id ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return array( array(), false );
		}

		$quantities      = $request->requested_items;
		$labels          = array();
		$total_units     = 0;
		$withdrawn_units = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$line_qty     = (int) $item->get_quantity();
			$total_units += $line_qty;

			$line_id = (int) $item_id;
			if ( array() !== $quantities && ! array_key_exists( $line_id, $quantities ) ) {
				continue;
			}

			$requested        = (int) ( $quantities[ $line_id ] ?? 0 );
			$withdrawn        = $requested > 0 ? min( $requested, $line_qty ) : $line_qty;
			$withdrawn_units += $withdrawn;
			$labels[]         = $withdrawn > 1 ? $item->get_name() . ' × ' . $withdrawn : $item->get_name();
		}

		$is_partial = array() !== $quantities && $withdrawn_units < $total_units;

		return array( $labels, $is_partial );
	}

	/**
	 * REST arg schema for the request id path parameter.
	 *
	 * @return array<string, mixed>
	 */
	private function id_arg(): array {
		return array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
		);
	}
}
