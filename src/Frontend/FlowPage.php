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
	 * Post meta flagging pages the plugin itself created. Uninstall must only ever delete pages
	 * carrying this marker — never a pre-existing page the merchant selected in settings.
	 */
	public const CREATED_META = '_recesso_dig_auto_created';

	/**
	 * Content of the auto-created flow page.
	 */
	public const DEFAULT_CONTENT = '<!-- wp:shortcode -->[recesso_digitale]<!-- /wp:shortcode -->';

	/**
	 * Ensure a published flow page exists, creating it if necessary. Returns its id (0 on failure).
	 */
	public static function ensure(): int {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id > 0 && 'publish' === get_post_status( $id ) ) {
			self::backfill_created_marker( $id );

			return $id;
		}

		$created = wp_insert_post(
			array(
				'post_title'   => __( 'Withdrawal (recesso)', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'post_content' => self::DEFAULT_CONTENT,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'meta_input'   => array( self::CREATED_META => '1' ),
			)
		);

		if ( is_int( $created ) && $created > 0 ) {
			update_option( self::OPTION, $created );

			return $created;
		}

		return 0;
	}

	/**
	 * Add the created-by-plugin marker to pages auto-created before the marker existed. Only pages
	 * whose content is still exactly the auto-created default qualify — anything the merchant
	 * customised or selected themselves is left unmarked (fail closed: uninstall keeps it).
	 *
	 * @param int $id Flow page id from the option.
	 */
	private static function backfill_created_marker( int $id ): void {
		if ( '' !== (string) get_post_meta( $id, self::CREATED_META, true ) ) {
			return;
		}

		$post = get_post( $id );
		if ( $post instanceof \WP_Post && self::DEFAULT_CONTENT === $post->post_content ) {
			update_post_meta( $id, self::CREATED_META, '1' );
		}
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
