<?php
/**
 * REST permission helpers.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Rest;

use Recesso54bis\Support\Capabilities;
use Recesso54bis\Support\OrderToken;

defined( 'ABSPATH' ) || exit;

/**
 * Shared authorisation checks for the REST controllers. Authorisation to act on a specific order is
 * granted only to the logged-in order owner or the bearer of a valid signed token — never to anyone
 * presenting a bare order id/key. Non-existent orders and bad tokens fail identically so order
 * existence cannot be enumerated.
 */
final class PermissionGate {

	/**
	 * Order token verifier.
	 *
	 * @var OrderToken
	 */
	private OrderToken $token;

	/**
	 * Construct the gate.
	 *
	 * @param OrderToken $token Order token verifier.
	 */
	public function __construct( OrderToken $token ) {
		$this->token = $token;
	}

	/**
	 * Whether the current user may manage withdrawal requests in the admin.
	 */
	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE_REQUESTS );
	}

	/**
	 * Whether the current logged-in user owns the given order.
	 *
	 * @param int $order_id Order id.
	 */
	public function is_order_owner( int $order_id ): bool {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		return $order->get_customer_id() === $user_id;
	}

	/**
	 * Whether a token authorises action on the given order.
	 *
	 * @param int    $order_id Order id.
	 * @param string $token    Presented token.
	 * @param int    $now_ts   Current Unix timestamp.
	 */
	public function token_valid_for_order( int $order_id, string $token, int $now_ts ): bool {
		if ( '' === $token ) {
			return false;
		}

		return $this->token->verify( $order_id, $token, $now_ts );
	}

	/**
	 * Whether the requester may act on the order, by ownership or a valid token.
	 *
	 * @param int    $order_id Order id.
	 * @param string $token    Presented token (empty for logged-in flows).
	 * @param int    $now_ts   Current Unix timestamp.
	 */
	public function can_act_on_order( int $order_id, string $token, int $now_ts ): bool {
		return $this->is_order_owner( $order_id ) || $this->token_valid_for_order( $order_id, $token, $now_ts );
	}
}
