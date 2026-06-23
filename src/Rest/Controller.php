<?php
/**
 * Base REST controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Rest;

use Recesso54bis\Domain\WithdrawalRequest;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Support\ClientIp;
use Recesso54bis\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Shared behaviour for the plugin's REST controllers: the API namespace, a uniform "forbidden"
 * response (so order existence is never leaked), the rate-limited per-order authorisation routine,
 * and a presenter that exposes only non-sensitive request fields.
 */
abstract class Controller {

	/**
	 * REST namespace.
	 */
	public const API_NAMESPACE = 'recesso-digitale/v1';

	/**
	 * Permission gate.
	 *
	 * @var PermissionGate
	 */
	protected PermissionGate $gate;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	protected RateLimiter $rate;

	/**
	 * Audit log repository.
	 *
	 * @var LogRepository
	 */
	protected LogRepository $log;

	/**
	 * Construct the base controller.
	 *
	 * @param PermissionGate $gate Permission gate.
	 * @param RateLimiter    $rate Rate limiter.
	 * @param LogRepository  $log  Audit log repository.
	 */
	public function __construct( PermissionGate $gate, RateLimiter $rate, LogRepository $log ) {
		$this->gate = $gate;
		$this->rate = $rate;
		$this->log  = $log;
	}

	/**
	 * Register this controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * Authorise a request to act on an order, with IP-based rate limiting and uniform denial.
	 *
	 * @param int              $order_id Order id (0 when unknown — always denied).
	 * @param \WP_REST_Request $request  The request (token read from its 'token' param).
	 *
	 * @return true|\WP_Error
	 */
	protected function authorize_for_order( int $order_id, \WP_REST_Request $request ) {
		$ip     = ClientIp::get();
		$bucket = 'order_action:' . ( '' === $ip ? 'unknown' : $ip );

		if ( $this->rate->too_many_attempts( $bucket ) ) {
			return $this->denied();
		}

		$token = (string) $request->get_param( 'token' );
		if ( $order_id > 0 && $this->gate->can_act_on_order( $order_id, $token, time() ) ) {
			return true;
		}

		$this->rate->hit( $bucket );
		$this->log->record( 0, LogRepository::EVENT_ACCESS_DENIED, 'consumer', array( 'order_id' => $order_id ) );

		return $this->denied();
	}

	/**
	 * A uniform 403 used for every authorisation failure (including unknown orders).
	 */
	protected function denied(): \WP_Error {
		return new \WP_Error(
			'recesso_dig_forbidden',
			__( 'You are not authorized to perform this action.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Present a withdrawal request as a non-sensitive public payload.
	 *
	 * @param WithdrawalRequest $request The request.
	 *
	 * @return array<string, mixed>
	 */
	protected function present_request( WithdrawalRequest $request ): array {
		return array(
			'id'                  => $request->id,
			'order_id'            => $request->order_id,
			'status'              => $request->status,
			'submitted_at_gmt'    => $request->submitted_at_gmt,
			'confirmed_at_gmt'    => $request->confirmed_at_gmt,
			'acknowledged_at_gmt' => $request->acknowledged_at_gmt,
			'has_receipt'         => $request->has_receipt(),
		);
	}

	/**
	 * Shared REST args for an optional per-order token parameter.
	 *
	 * @return array<string, mixed>
	 */
	protected function token_arg(): array {
		return array(
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'sanitize_text_field',
		);
	}
}
