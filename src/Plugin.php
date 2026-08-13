<?php
/**
 * Plugin bootstrap / container.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates plugin bootstrap. Contains no business logic and no SQL: it only wires hook
 * providers together once the host environment (WordPress + WooCommerce) is known to be sane.
 */
final class Plugin {

	/**
	 * Single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Private constructor — use {@see Plugin::boot()}.
	 */
	private function __construct() {}

	/**
	 * Boot the plugin exactly once.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register();
	}

	/**
	 * Register hooks. Fails closed: if WooCommerce is unavailable, the plugin degrades to an
	 * admin notice and wires nothing else.
	 */
	private function register(): void {
		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'render_woocommerce_missing_notice' ) );
			return;
		}

		// Forward-migrate the schema when the plugin is updated in place (cheap when already current),
		// then run the non-schema upgrade tasks for the new version.
		add_action( 'admin_init', array( \Recesso54bis\Activation\Migrations::class, 'maybe_upgrade' ) );
		add_action( 'admin_init', array( \Recesso54bis\Activation\Upgrades::class, 'maybe_run' ) );

		$container = new Container();

		// REST API (public withdrawal flow + eligibility + admin endpoints).
		( new \Recesso54bis\Rest\RouteRegistrar( $container ) )->register();

		// Frontend: server-rendered two-step flow, shortcode bridge, block, My Account entry points.
		$flow = $container->flow_controller();
		$flow->register();
		( new \Recesso54bis\Frontend\Shortcode( $flow ) )->register();
		( new \Recesso54bis\Frontend\Block( $flow ) )->register();
		( new \Recesso54bis\Frontend\Hooks( $container->eligibility_adapter(), $container->flow_urls(), $container->settings() ) )->register();
		( new \Recesso54bis\Frontend\AccountEndpoint( $container->eligibility_adapter(), $container->flow_urls(), $container->settings() ) )->register();
		( new \Recesso54bis\Frontend\ProductNotice( $container->eligibility_adapter(), $container->settings() ) )->register();
		// Checkout consents: the classic (shortcode) checkout and the Checkout block share one
		// applicability resolver, so both surfaces show exactly the same consents for a given cart.
		$consents = new \Recesso54bis\Frontend\CheckoutConsents( $container->settings(), $container->clock(), $container->eligibility_adapter() );
		$consents->register();
		( new \Recesso54bis\Frontend\BlockCheckoutConsents( $container->settings(), $container->clock(), $consents ) )->register();
		( new \Recesso54bis\Frontend\ModelForm( $container->settings() ) )->register();
		( new \Recesso54bis\Frontend\FooterLink( $container->settings() ) )->register();

		// Durable medium: acknowledgement email, async receipt generation, secure download.
		( new \Recesso54bis\Email\EmailHooks() )->register();
		$container->receipt_scheduler()->register();
		$container->receipt_download_controller()->register();

		// Mirror the withdrawal lifecycle into the order's private notes.
		( new \Recesso54bis\Integration\OrderNotes( $container->request_repository() ) )->register();

		// GDPR: personal-data exporter, eraser and the suggested privacy-policy snippet.
		( new \Recesso54bis\Privacy\PrivacyHooks( $container->request_repository() ) )->register();

		// Optional: cancel WooCommerce Subscriptions on a confirmed withdrawal (inert if absent).
		$container->subscriptions_adapter()->register();

		// Admin: read-only requests list + settings (loaded only in the admin context).
		if ( is_admin() ) {
			( new \Recesso54bis\Admin\Menu( $container->request_repository(), new \Recesso54bis\Admin\SettingsPage() ) )->register();
			( new \Recesso54bis\Admin\WithdrawalStatusFields() )->register();
			( new \Recesso54bis\Admin\CsvExporter( $container->request_repository() ) )->register();
			( new \Recesso54bis\Admin\ActivationNotice() )->register();
			( new \Recesso54bis\Admin\OrderColumn( $container->request_repository() ) )->register();
		}
	}

	/**
	 * Whether WooCommerce is active and loaded.
	 */
	private function is_woocommerce_active(): bool {
		return class_exists( \WooCommerce::class );
	}

	/**
	 * Render the admin notice shown when WooCommerce is not active.
	 */
	public function render_woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__(
			'ErreD EU Order Withdrawal for WooCommerce requires WooCommerce to be installed and active. The withdrawal function is currently inactive.',
			'erred-eu-order-withdrawal-for-woocommerce'
		);
		echo '</p></div>';
	}
}
