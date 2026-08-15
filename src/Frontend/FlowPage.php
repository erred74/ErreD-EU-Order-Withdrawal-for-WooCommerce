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
	 * The shortcode that hosts the flow, and the block that does the same job.
	 */
	public const SHORTCODE = 'recesso_digitale';
	public const BLOCK     = 'recesso-digitale/withdrawal-button';

	/**
	 * Content of the auto-created flow page.
	 */
	public const DEFAULT_CONTENT = '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->';

	/**
	 * Outcomes of {@see self::health()}, worst-first in severity order.
	 *
	 * The first three mean the withdrawal function is unreachable and {@see self::url()} returns an
	 * empty string. NO_FORM is advisory only: the page is published and reachable, but the flow was not
	 * found in its content — which is also what a page builder storing its content in meta looks like,
	 * so it warns the merchant without withdrawing a link that may well work.
	 */
	public const HEALTH_NOT_SET       = 'not_set';
	public const HEALTH_MISSING       = 'missing';
	public const HEALTH_NOT_PUBLISHED = 'not_published';
	public const HEALTH_NO_FORM       = 'no_form';
	public const HEALTH_OK            = 'ok';

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
	 * The URL of the flow page, or an empty string when there is no page a customer could actually
	 * use.
	 *
	 * It used to fall back to the site home. That was worse than returning nothing: a merchant who
	 * trashed or unpublished the page kept getting links everywhere — in order emails, in My Account,
	 * in the footer — that dropped the customer on the shop front page carrying a valid signed token,
	 * with no form and no explanation. Callers must treat an empty string as "do not offer the link",
	 * and the admin is warned through {@see self::health()} so the page gets fixed rather than the
	 * breakage staying invisible.
	 */
	public static function url(): string {
		$post = self::published_page();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		$permalink = get_permalink( $post );

		return is_string( $permalink ) ? $permalink : '';
	}

	/**
	 * Whether the configured page can host the flow, and if not, what is wrong with it.
	 *
	 * Art. 54-bis requires the withdrawal function to stay available for the whole period the right
	 * can be exercised, so a page that has gone missing is not a cosmetic misconfiguration: it removes
	 * a mandated function. This is what the settings panel and the admin notice report.
	 *
	 * @return string One of the HEALTH_* constants.
	 */
	public static function health(): string {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id < 1 ) {
			return self::HEALTH_NOT_SET;
		}

		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return self::HEALTH_MISSING;
		}

		if ( 'publish' !== $post->post_status ) {
			return self::HEALTH_NOT_PUBLISHED;
		}

		return self::hosts_flow( $post ) ? self::HEALTH_OK : self::HEALTH_NO_FORM;
	}

	/**
	 * The configured page, but only when it exists and is published — the two conditions under which a
	 * customer following a link would actually reach it.
	 */
	private static function published_page(): ?\WP_Post {
		$id = (int) get_option( self::OPTION, 0 );
		if ( $id < 1 ) {
			return null;
		}

		$post = get_post( $id );

		return $post instanceof \WP_Post && 'publish' === $post->post_status ? $post : null;
	}

	/**
	 * Whether the page's own content renders the flow, via either the shortcode or the block.
	 *
	 * A negative answer is a hint, not a verdict: page builders keep their content in post meta, and
	 * a synced pattern hides the block from `has_block()`, so plenty of working pages answer false
	 * here. That is why the caller only ever warns on it.
	 *
	 * The shortcode is matched textually rather than with has_shortcode(), which answers false for a
	 * tag that has not been registered yet and would make this diagnostic depend on when it runs. The
	 * negative lookahead keeps the plugin's other shortcodes — the model form, the exclusion notice —
	 * from counting as the flow.
	 *
	 * @param \WP_Post $post The configured page.
	 */
	private static function hosts_flow( \WP_Post $post ): bool {
		if ( has_block( self::BLOCK, $post ) ) {
			return true;
		}

		return 1 === preg_match( '/\[' . preg_quote( self::SHORTCODE, '/' ) . '(?![\w-])/', $post->post_content );
	}
}
