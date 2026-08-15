<?php
/**
 * Admin warning for a withdrawal page that can no longer host the flow.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Frontend\FlowPage;
use Recesso54bis\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Tells the merchant when the page hosting the withdrawal form has gone missing, been unpublished or
 * lost the form itself.
 *
 * This is not a configuration nicety. Art. 54-bis requires the withdrawal function to stay available
 * for the whole period the right can be exercised, and a deleted page silently suppresses every
 * withdrawal link the plugin renders (see {@see FlowPage::url()}). Waiting for someone to open the
 * settings screen would mean the function stays gone in the meantime, so the warning follows the
 * merchant around the screens they actually use.
 *
 * The message strings live here rather than in the settings screen so the notice and the settings
 * panel can never drift apart.
 */
final class FlowPageNotice {

	/**
	 * Hook the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render the warning on the screens a merchant is likely to be on.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_REQUESTS ) || ! $this->on_relevant_screen() ) {
			return;
		}

		$health = FlowPage::health();

		// The advisory NO_FORM state is reported in the settings panel only: it is a heuristic that
		// misfires on page builders, and a warning a merchant cannot make go away is worse than none.
		if ( ! self::is_blocking( $health ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p>%s</p></div>',
			esc_html__( 'Right of withdrawal:', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_html( self::message( $health ) ),
			wp_kses_post( $this->actions() )
		);
	}

	/**
	 * Whether the state means no customer can reach the withdrawal form at all.
	 *
	 * @param string $health One of the FlowPage::HEALTH_* constants.
	 */
	public static function is_blocking( string $health ): bool {
		return in_array(
			$health,
			array( FlowPage::HEALTH_NOT_SET, FlowPage::HEALTH_MISSING, FlowPage::HEALTH_NOT_PUBLISHED ),
			true
		);
	}

	/**
	 * The merchant-facing explanation for a health state ('' when the page is fine).
	 *
	 * @param string $health One of the FlowPage::HEALTH_* constants.
	 */
	public static function message( string $health ): string {
		switch ( $health ) {
			case FlowPage::HEALTH_NOT_SET:
				return __( 'No page is selected to host the withdrawal form, so the withdrawal function is not reachable and no withdrawal link is being shown. Choose one under General.', 'erred-eu-order-withdrawal-for-woocommerce' );

			case FlowPage::HEALTH_MISSING:
				return __( 'The page selected to host the withdrawal form no longer exists. Every withdrawal link is suppressed until you select another page under General.', 'erred-eu-order-withdrawal-for-woocommerce' );

			case FlowPage::HEALTH_NOT_PUBLISHED:
				return __( 'The page selected to host the withdrawal form is not published — it is in the trash, or saved as a draft. Every withdrawal link is suppressed until you restore and publish it.', 'erred-eu-order-withdrawal-for-woocommerce' );

			case FlowPage::HEALTH_NO_FORM:
				return sprintf(
					/* translators: 1: shortcode, e.g. [recesso_digitale]; 2: block name. */
					__( 'The withdrawal page does not appear to contain the form. Add the %1$s shortcode or the %2$s block to it. If you build that page with a page builder, or the form sits inside a synced pattern, this check cannot see it and you can ignore this.', 'erred-eu-order-withdrawal-for-woocommerce' ),
					'[' . FlowPage::SHORTCODE . ']',
					FlowPage::BLOCK
				);
		}

		return '';
	}

	/**
	 * Action links: the settings screen, plus the page itself when there is one to edit.
	 */
	private function actions(): string {
		$links = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG ) ),
				esc_html__( 'Open withdrawal settings', 'erred-eu-order-withdrawal-for-woocommerce' )
			),
		);

		$page_id = (int) get_option( FlowPage::OPTION, 0 );
		if ( $page_id > 0 ) {
			$edit_url = get_edit_post_link( $page_id, 'url' );
			if ( is_string( $edit_url ) && '' !== $edit_url ) {
				$links[] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $edit_url ),
					esc_html__( 'Edit the page', 'erred-eu-order-withdrawal-for-woocommerce' )
				);
			}
		}

		return implode( ' | ', $links );
	}

	/**
	 * Whether to warn on the current screen.
	 *
	 * Deliberately not every admin page: the dashboard, the plugins screen and the WooCommerce screens
	 * are where a merchant works, and that is enough to be seen without turning into a nag. The
	 * plugin's own settings screen is excluded because it states the same thing in place, next to the
	 * field that fixes it.
	 */
	private function on_relevant_screen(): bool {
		$screen = get_current_screen();
		if ( ! $screen instanceof \WP_Screen ) {
			return false;
		}

		if ( str_contains( $screen->id, SettingsPage::MENU_SLUG ) ) {
			return false;
		}

		return in_array( $screen->id, array( 'dashboard', 'plugins' ), true )
			|| str_contains( $screen->id, 'woocommerce' )
			|| str_contains( $screen->id, 'recesso-digitale' );
	}
}
