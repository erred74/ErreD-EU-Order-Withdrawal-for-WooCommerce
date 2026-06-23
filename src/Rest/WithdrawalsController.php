<?php
/**
 * Withdrawals REST controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Rest;

use Recesso54bis\Integration\NotEligibleException;
use Recesso54bis\Integration\WithdrawalService;
use Recesso54bis\Persistence\DuplicateOpenRequestException;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\ClientIp;
use Recesso54bis\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Public withdrawal flow endpoints: create the declaration (step 1), confirm it (step 2) and read
 * a single request. Every route declares a full args schema with validate/sanitize callbacks and a
 * real permission callback; guest access is authorised solely by a valid signed token and is
 * rate-limited with uniform errors to prevent order enumeration.
 */
final class WithdrawalsController extends Controller {

	/**
	 * Withdrawal coordination service.
	 *
	 * @var WithdrawalService
	 */
	private WithdrawalService $service;

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Construct the controller.
	 *
	 * @param PermissionGate    $gate     Permission gate.
	 * @param RateLimiter       $rate     Rate limiter.
	 * @param LogRepository     $log      Audit log repository.
	 * @param WithdrawalService $service  Withdrawal coordination service.
	 * @param RequestRepository $requests Request repository.
	 */
	public function __construct(
		PermissionGate $gate,
		RateLimiter $rate,
		LogRepository $log,
		WithdrawalService $service,
		RequestRepository $requests
	) {
		parent::__construct( $gate, $rate, $log );
		$this->service  = $service;
		$this->requests = $requests;
	}

	/**
	 * Register the routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/withdrawals',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_item' ),
				'permission_callback' => array( $this, 'create_permission' ),
				'args'                => array(
					'order_id'           => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'token'              => $this->token_arg(),
					'consumer_name'      => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn( $value ): bool => '' !== trim( (string) $value ),
					),
					// Optional and non-authoritative: any client value is ignored. The
					// canonical contract reference is derived server-side from the order
					// (see create_item()), so a consumer cannot register a declaration
					// with a reference that is not aligned to their order.
					'contract_reference' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'confirmation_email' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => static fn( $value ): bool => false !== is_email( (string) $value ),
					),
					'requested_items'    => array(
						'type'                 => 'object',
						'required'             => false,
						'additionalProperties' => array( 'type' => 'integer' ),
						'sanitize_callback'    => static function ( $value ): array {
							if ( ! is_array( $value ) ) {
								return array();
							}
							$out = array();
							foreach ( $value as $line_id => $quantity ) {
								$out[ (int) $line_id ] = absint( $quantity );
							}
							return $out;
						},
						'validate_callback'    => static fn( $value ): bool => is_array( $value ) || is_object( $value ),
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/withdrawals/(?P<id>\d+)/confirm',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'confirm_item' ),
				'permission_callback' => array( $this, 'request_permission' ),
				'args'                => array(
					'id'    => $this->id_arg(),
					'token' => $this->token_arg(),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/withdrawals/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_item' ),
				'permission_callback' => array( $this, 'request_permission' ),
				'args'                => array(
					'id'    => $this->id_arg(),
					'token' => $this->token_arg(),
				),
			)
		);
	}

	/**
	 * Permission callback for creating a declaration: authorise against the posted order id.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return true|\WP_Error
	 */
	public function create_permission( \WP_REST_Request $request ) {
		return $this->authorize_for_order( (int) $request->get_param( 'order_id' ), $request );
	}

	/**
	 * Permission callback for routes addressing an existing request by id.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return true|\WP_Error
	 */
	public function request_permission( \WP_REST_Request $request ) {
		$record = $this->requests->find_by_id( (int) $request->get_param( 'id' ) );
		if ( null === $record ) {
			// Uniform denial: never reveal whether the request id exists.
			if ( $this->gate->can_manage() ) {
				return true;
			}

			return $this->authorize_for_order( 0, $request );
		}

		if ( $this->gate->can_manage() ) {
			return true;
		}

		return $this->authorize_for_order( $record->order_id, $request );
	}

	/**
	 * Create a withdrawal declaration (step 1).
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_param( 'order_id' ) );
		if ( ! $order instanceof \WC_Order ) {
			return $this->denied();
		}

		$requested_items = $request->get_param( 'requested_items' );

		try {
			$created = $this->service->create_declaration(
				$order,
				array(
					'consumer_name'      => (string) $request->get_param( 'consumer_name' ),
					// Authoritative value derived from the order; client input is ignored.
					'contract_reference' => $order->get_order_number(),
					'confirmation_email' => (string) $request->get_param( 'confirmation_email' ),
					'requested_items'    => is_array( $requested_items ) ? $requested_items : array(),
				),
				ClientIp::packed()
			);
		} catch ( DuplicateOpenRequestException $e ) {
			return new \WP_Error(
				'recesso_dig_duplicate',
				__( 'A withdrawal request is already in progress for this order.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				array( 'status' => 409 )
			);
		} catch ( NotEligibleException $e ) {
			return new \WP_Error(
				'recesso_dig_not_eligible',
				__( 'This order is not eligible for withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				array( 'status' => 422 )
			);
		}

		return new \WP_REST_Response( $this->present_request( $created ), 201 );
	}

	/**
	 * Confirm a withdrawal request (step 2). Idempotent.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function confirm_item( \WP_REST_Request $request ) {
		$confirmed = $this->service->confirm( (int) $request->get_param( 'id' ), 'consumer' );
		if ( null === $confirmed ) {
			return $this->denied();
		}

		return new \WP_REST_Response( $this->present_request( $confirmed ), 200 );
	}

	/**
	 * Read a single withdrawal request.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( \WP_REST_Request $request ) {
		$record = $this->requests->find_by_id( (int) $request->get_param( 'id' ) );
		if ( null === $record ) {
			return $this->denied();
		}

		return new \WP_REST_Response( $this->present_request( $record ), 200 );
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
