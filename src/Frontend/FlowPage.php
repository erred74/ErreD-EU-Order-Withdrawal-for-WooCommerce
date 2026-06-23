<?php
/**
 * Flow host page resolver.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves (and on activation creates) the page that hosts the withdrawal flow shortcode/block. The
 * entry links across My Account and emails point here, carrying the order id and — for guests — a
 * signed token.
 */
final class FlowPage {

	/**
	 * Option holding the flow page id.
	 */
	public const OPTION = 'recesso_dig_flow_page_id';

	/**
	 * Ensure a published flow page exists, creating it if necessary. Returns its id (0 on failure).
	 */
	public static function ensure(): int {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			return $id;
		}

		$created = wp_insert_post(
			array(
				'post_title'   => __( 'Withdrawal (recesso)', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'post_content' => '<!-- wp:shortcode -->[recesso_digitale]<!-- /wp:shortcode -->',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( is_int( $created ) && $created > 0 ) {
			update_option( self::OPTION, $created );

			return $created;
		}

		return 0;
	}

	/**
	 * The URL of the flow page (falls back to the site home if unavailable).
	 */
	public static function url(): string {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id > 0 ) {
			$permalink = get_permalink( $id );
			if ( is_string( $permalink ) && '' !== $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/' );
	}
}
