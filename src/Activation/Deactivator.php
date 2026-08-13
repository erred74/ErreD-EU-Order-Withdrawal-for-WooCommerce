<?php
/**
 * Deactivation handler.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation. Performs no destructive data operations — capabilities, tables and
 * legal records persist until an explicit, opted-in uninstall.
 */
final class Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate(): void {
		// The My Account endpoint's rewrite rule is gone with the plugin, so the cached rules must be
		// rebuilt — otherwise the stale rule keeps matching a URL nothing answers any more. Nothing
		// destructive: rewrite rules are a cache, not data.
		delete_option( \Recesso54bis\Frontend\AccountEndpoint::FLUSH_FLAG );
		flush_rewrite_rules( false );
	}
}
