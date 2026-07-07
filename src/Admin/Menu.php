<?php
/**
 * Admin menu.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Admin;

use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's admin pages under the WooCommerce menu: a read-only requests list and the
 * settings screen. Access requires the custom manage capability (granted to admins/shop managers).
 */
final class Menu {

	private const LIST_SLUG = 'recesso-digitale';

	/**
	 * Transient caching the count of requests awaiting admin action (for the menu badge).
	 */
	private const COUNT_TRANSIENT = 'recesso_dig_action_count';

	/**
	 * Request repository.
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Settings screen.
	 *
	 * @var SettingsPage
	 */
	private SettingsPage $settings;

	/**
	 * The list page hook suffix (for targeted asset enqueue).
	 *
	 * @var string
	 */
	private string $list_hook = '';

	/**
	 * Construct the menu provider.
	 *
	 * @param RequestRepository $requests Request repository.
	 * @param SettingsPage      $settings Settings screen.
	 */
	public function __construct( RequestRepository $requests, SettingsPage $settings ) {
		$this->requests = $requests;
		$this->settings = $settings;
	}

	/**
	 * Hook menu registration and settings.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Keep the menu badge fresh: clear the cached count when a request is confirmed (count rises)
		// or when an admin processes one (count falls).
		add_action( 'recesso_dig_request_confirmed', array( $this, 'flush_count_cache' ) );
		add_action( 'recesso_dig_request_processed', array( $this, 'flush_count_cache' ) );

		$this->settings->register();
	}

	/**
	 * Register the submenu pages.
	 */
	public function register_menu(): void {
		$this->list_hook = (string) add_submenu_page(
			'woocommerce',
			__( 'Order Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ),
			$this->menu_title(),
			Capabilities::MANAGE_REQUESTS,
			self::LIST_SLUG,
			array( $this, 'render_list' )
		);

		add_submenu_page(
			'woocommerce',
			__( 'Order Withdrawal — Settings', 'erred-eu-order-withdrawal-for-woocommerce' ),
			__( 'Order Withdrawal: settings', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'manage_woocommerce',
			SettingsPage::MENU_SLUG,
			array( $this->settings, 'render_page' )
		);
	}

	/**
	 * The submenu title, with a count bubble for requests awaiting admin action (confirmed but not
	 * yet processed). Uses the same `menu-counter` markup WooCommerce gives its Orders count, so the
	 * badge matches the other WooCommerce/WordPress menu bubbles (colour included).
	 */
	private function menu_title(): string {
		$base  = __( 'Order Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );
		$count = $this->action_count();

		if ( $count < 1 ) {
			return $base;
		}

		return sprintf(
			/* translators: %1$s: menu label, %2$s: count badge markup. */
			_x( '%1$s %2$s', 'admin menu item with count badge', 'erred-eu-order-withdrawal-for-woocommerce' ),
			$base,
			'<span class="menu-counter count-' . absint( $count ) . '"><span class="pending-count">' . esc_html( number_format_i18n( $count ) ) . '</span></span>'
		);
	}

	/**
	 * Count of requests awaiting admin action (confirmed or acknowledged — i.e. confirmed by the
	 * consumer and not yet processed by the merchant), cached briefly to avoid a query on every admin
	 * page load. Invalidated on confirmation and on admin processing.
	 */
	private function action_count(): int {
		$cached = get_transient( self::COUNT_TRANSIENT );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = $this->requests->count_awaiting_action();
		set_transient( self::COUNT_TRANSIENT, $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/**
	 * Clear the cached action count (hooked on confirmation and processing events).
	 */
	public function flush_count_cache(): void {
		delete_transient( self::COUNT_TRANSIENT );
	}

	/**
	 * Enqueue the React admin bundle on the list page only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->list_hook || '' === $this->list_hook ) {
			return;
		}

		$asset_path = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'build/admin/index.asset.php';
		if ( ! is_readable( $asset_path ) ) {
			return;
		}

		$asset = require $asset_path;

		wp_enqueue_script(
			'recesso-dig-admin',
			plugins_url( 'build/admin/index.js', RECESSO_DIG_PLUGIN_FILE ),
			$asset['dependencies'],
			$asset['version'],
			true
		);
		wp_set_script_translations(
			'recesso-dig-admin',
			'erred-eu-order-withdrawal-for-woocommerce',
			plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'languages'
		);
		wp_add_inline_script(
			'recesso-dig-admin',
			'window.recessoDigAdmin = ' . wp_json_encode( array( 'exportUrl' => CsvExporter::url() ) ) . ';',
			'before'
		);
		wp_enqueue_style( 'wp-components' );
	}

	/**
	 * Render the requests list page. The React app mounts over the server-rendered table, which
	 * remains as a no-JavaScript fallback.
	 */
	public function render_list(): void {
		if ( ! current_user_can( Capabilities::MANAGE_REQUESTS ) ) {
			return;
		}

		$table = new RequestsListTable( $this->requests );
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Order Withdrawal — Requests', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h1>
			<div id="recesso-dig-admin-app">
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::LIST_SLUG ); ?>" />
					<?php
						$table->search_box( __( 'Search requests', 'erred-eu-order-withdrawal-for-woocommerce' ), 'recesso-request' );
						$table->display();
					?>
				</form>
			</div>
		</div>
		<?php
	}
}
