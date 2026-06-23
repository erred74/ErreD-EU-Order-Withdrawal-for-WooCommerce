<?php
/**
 * Frontend flow URL helper.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Support\OrderToken;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and parses the URLs for the server-rendered withdrawal flow. The flow is reachable on any
 * page that hosts the block or shortcode; state is carried in query args (order id, signed token and
 * step), never in a guessable order key alone.
 */
final class FlowUrls {

	public const QV_ACTION = 'recesso_dig_action';
	public const QV_ORDER  = 'recesso_dig_order';
	public const QV_TOKEN  = 'recesso_dig_token';
	public const QV_STEP   = 'recesso_dig_step';
	public const QV_ID     = 'recesso_dig_request';

	public const STEP_DECLARE = 'declare';
	public const STEP_CONFIRM = 'confirm';
	public const STEP_DONE    = 'done';

	/**
	 * Order token issuer/verifier.
	 *
	 * @var OrderToken
	 */
	private OrderToken $token;

	/**
	 * Construct the helper.
	 *
	 * @param OrderToken $token Order token service.
	 */
	public function __construct( OrderToken $token ) {
		$this->token = $token;
	}

	/**
	 * Build the entry URL for the declaration step of an order, optionally signed with a token for
	 * guests (logged-in owners do not need one).
	 *
	 * @param string   $base_url  Page URL hosting the flow.
	 * @param int      $order_id  Order id.
	 * @param int|null $expiry_ts Token expiry (Unix); when null no token is added (logged-in flow).
	 */
	public function declaration_url( string $base_url, int $order_id, ?int $expiry_ts = null ): string {
		$args = array(
			self::QV_ACTION => 'recesso',
			self::QV_ORDER  => $order_id,
			self::QV_STEP   => self::STEP_DECLARE,
		);

		if ( null !== $expiry_ts ) {
			$args[ self::QV_TOKEN ] = $this->token->issue( $order_id, $expiry_ts );
		}

		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $base_url );
	}
}
