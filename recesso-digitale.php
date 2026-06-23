<?php
/**
 * Plugin Name:       ErreD EU Order Withdrawal for WooCommerce
 * Description:        Digital withdrawal function for WooCommerce: the EU "easy withdrawal" duty (Directive 2023/2673, in force 19 June 2026) and its Italian transposition (art. 54-bis Codice del Consumo). HPOS-native, security-first.
 * Version:           0.5.2
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Requires Plugins:  woocommerce
 * Author:            ErreD
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       erred-eu-order-withdrawal-for-woocommerce
 * Domain Path:       /languages
 * WC requires at least: 8.2
 * WC tested up to:   10.9
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// Single, canonical plugin-file path constant. Used for asset URLs, activation hooks, etc.
define( 'RECESSO_DIG_PLUGIN_FILE', __FILE__ );
define( 'RECESSO_DIG_VERSION', '0.5.2' );

/**
 * Guard: minimum PHP version. Fail closed with an admin notice rather than fataling.
 */
if ( version_compare( PHP_VERSION, '8.2', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'ErreD EU Order Withdrawal for WooCommerce requires PHP 8.2 or newer and has been deactivated.', 'erred-eu-order-withdrawal-for-woocommerce' );
			echo '</p></div>';
		}
	);
	return;
}

/**
 * Guard: Composer autoloader must be present (the plugin ships its own vendor/ in the build).
 */
$recesso_dig_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $recesso_dig_autoload ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'ErreD EU Order Withdrawal for WooCommerce is missing its autoloader. Run "composer install" or reinstall the plugin.', 'erred-eu-order-withdrawal-for-woocommerce' );
			echo '</p></div>';
		}
	);
	return;
}

require $recesso_dig_autoload;

/**
 * Declare HPOS (custom order tables) compatibility before WooCommerce initialises.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RECESSO_DIG_PLUGIN_FILE, true );
		}
	}
);

// Activation / deactivation hooks (handlers live in src/Activation, added in the data-layer step).
register_activation_hook( __FILE__, array( \Recesso54bis\Activation\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Recesso54bis\Activation\Deactivator::class, 'deactivate' ) );

// Boot the plugin. All business logic lives behind this single call.
add_action(
	'plugins_loaded',
	static function (): void {
		\Recesso54bis\Plugin::boot();
	}
);
