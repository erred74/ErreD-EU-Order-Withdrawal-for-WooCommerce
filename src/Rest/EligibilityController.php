<?php
/**
 * Eligibility REST controller.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Rest;

use Recesso54bis\Domain\Eligibility\Reason;
use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Persistence\LogRepository;
use Recesso54bis\Support\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes whether withdrawal is available for an order and why, for both the order owner (or token
 * bearer) and admins. Reason codes are translated to human-readable labels at this edge, keeping the
 * domain WordPress-free.
 */
final class EligibilityController extends Controller {

	/**
	 * Eligibility adapter.
	 *
	 * @var EligibilityAdapter
	 */
	private EligibilityAdapter $eligibility;

	/**
	 * Construct the controller.
	 *
	 * @param PermissionGate     $gate        Permission gate.
	 * @param RateLimiter        $rate        Rate limiter.
	 * @param LogRepository      $log         Audit log repository.
	 * @param EligibilityAdapter $eligibility Eligibility adapter.
	 */
	public function __construct(
		PermissionGate $gate,
		RateLimiter $rate,
		LogRepository $log,
		EligibilityAdapter $eligibility
	) {
		parent::__construct( $gate, $rate, $log );
		$this->eligibility = $eligibility;
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/eligibility/(?P<order>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_eligibility' ),
				'permission_callback' => array( $this, 'eligibility_permission' ),
				'args'                => array(
					'order' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'token' => $this->token_arg(),
				),
			)
		);
	}

	/**
	 * Permission callback: order owner / valid token, or an admin.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return true|\WP_Error
	 */
	public function eligibility_permission( \WP_REST_Request $request ) {
		if ( $this->gate->can_manage() ) {
			return true;
		}

		return $this->authorize_for_order( (int) $request->get_param( 'order' ), $request );
	}

	/**
	 * Return the eligibility result for an order.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_eligibility( \WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_param( 'order' ) );
		if ( ! $order instanceof \WC_Order ) {
			return $this->denied();
		}

		$result = $this->eligibility->for_order( $order );

		return new \WP_REST_Response(
			array(
				'is_eligible'          => $result->is_eligible,
				'reason'               => $result->reason,
				'reason_label'         => self::reason_label( $result->reason ),
				'window_starts_at'     => $result->window_starts_at?->format( 'Y-m-d\TH:i:s\Z' ),
				'window_ends_at'       => $result->window_ends_at?->format( 'Y-m-d\TH:i:s\Z' ),
				'eligible_line_ids'    => $result->eligible_line_ids,
				'available_quantities' => (object) $result->available_quantities,
			),
			200
		);
	}

	/**
	 * Human-readable, translatable label for an eligibility reason.
	 *
	 * @param string $reason Reason code.
	 */
	public static function reason_label( string $reason ): string {
		switch ( $reason ) {
			case Reason::ELIGIBLE:
				return __( 'Withdrawal is available for this order.', 'erred-eu-order-withdrawal-for-woocommerce' );
			case Reason::NOT_STARTED:
				return __( 'The withdrawal period has not started yet.', 'erred-eu-order-withdrawal-for-woocommerce' );
			case Reason::WINDOW_CLOSED:
				return __( 'The withdrawal period has ended.', 'erred-eu-order-withdrawal-for-woocommerce' );
			case Reason::EXCLUDED_ART59:
				return __( 'This order is excluded from the right of withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' );
			case Reason::NEEDS_CONFIG:
				return __( 'Withdrawal availability has not been configured for these products.', 'erred-eu-order-withdrawal-for-woocommerce' );
			case Reason::DUPLICATE_OPEN:
				return __( 'A withdrawal request is already in progress for this order.', 'erred-eu-order-withdrawal-for-woocommerce' );
			case Reason::ORDER_NOT_WITHDRAWABLE:
				return __( 'This order cannot be withdrawn in its current state.', 'erred-eu-order-withdrawal-for-woocommerce' );
			case Reason::NO_ELIGIBLE_ITEMS:
				return __( 'No items in this order are eligible for withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' );
			default:
				return __( 'Withdrawal is not available for this order.', 'erred-eu-order-withdrawal-for-woocommerce' );
		}
	}
}
