<?php
/**
 * Non-schema upgrade tasks.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Activation;

use Recesso54bis\Frontend\AccountEndpoint;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the housekeeping an in-place update needs that is not a database migration — currently only
 * the rewrite-rule flush the My Account endpoint requires the first time it appears. Schema changes
 * stay in {@see Migrations}; this class exists so a version bump can carry such tasks without
 * pretending the database changed.
 */
final class Upgrades {

	/**
	 * Option storing the plugin version the upgrade tasks last ran for.
	 */
	public const VERSION_OPTION = 'recesso_dig_plugin_version';

	/**
	 * Run the upgrade tasks once per plugin version. Cheap when already current (one option read).
	 */
	public static function maybe_run(): void {
		// Read through constant() so the lookup stays a runtime one: the constant is defined by the
		// main plugin file, which is not part of the analysed source graph.
		$current   = defined( 'RECESSO_DIG_VERSION' ) ? (string) constant( 'RECESSO_DIG_VERSION' ) : '';
		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( '' === $current || $current === $installed ) {
			return;
		}

		self::run();

		update_option( self::VERSION_OPTION, $current );
	}

	/**
	 * The upgrade tasks themselves. Idempotent: safe to run more than once.
	 */
	public static function run(): void {
		// The My Account endpoint adds a rewrite rule. Rather than flushing here — an expensive
		// operation to perform mid-request, and one that would run before the endpoint is registered
		// on an activation — flag it so the flush happens on the next `init`, right after
		// {@see AccountEndpoint::add_endpoint()} has declared the rule.
		update_option( AccountEndpoint::FLUSH_FLAG, '1' );
	}
}
