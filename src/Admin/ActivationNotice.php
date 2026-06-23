<?php
/**
 * One-time post-activation welcome notice.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Activation\Activator;
use Recesso54bis\Frontend\FlowPage;

defined( 'ABSPATH' ) || exit;

/**
 * Shows a dismissible "plugin is active" notice once, right after activation, pointing the merchant at
 * the settings screen and the auto-created withdrawal page. Gated by a short-lived transient (a UI cue,
 * not persistent state) and the WooCommerce-management capability.
 */
final class ActivationNotice {

	/**
	 * Hook the notice.
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Render the welcome notice once, then clear the flag so it never repeats.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( false === get_transient( Activator::ACTIVATED_TRANSIENT ) ) {
			return;
		}

		delete_transient( Activator::ACTIVATED_TRANSIENT );

		$settings_url = admin_url( 'admin.php?page=' . SettingsPage::MENU_SLUG );
		$page_id      = (int) get_option( FlowPage::OPTION, 0 );
		$edit_url     = $page_id > 0 ? get_edit_post_link( $page_id, 'url' ) : '';
		?>
		<div class="notice notice-success is-dismissible">
			<p><strong><?php esc_html_e( 'ErreD EU Order Withdrawal for WooCommerce is active.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></strong></p>
			<p>
				<?php esc_html_e( 'A "Withdrawal (recesso)" page with the statutory flow was created automatically. Review the settings, link the page from your footer or menu, and configure the notification email.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>">
					<?php esc_html_e( 'Open settings', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
				</a>
				<?php if ( is_string( $edit_url ) && '' !== $edit_url ) : ?>
					<a class="button" href="<?php echo esc_url( $edit_url ); ?>">
						<?php esc_html_e( 'Edit withdrawal page', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}
