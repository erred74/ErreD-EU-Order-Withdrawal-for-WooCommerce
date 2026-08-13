<?php
/**
 * Activation handler.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Activation;

use Recesso54bis\Frontend\FlowPage;
use Recesso54bis\Support\Capabilities;
use Recesso54bis\Support\Hashing;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin activation: grants capabilities, provisions the schema, generates the signing
 * secret (kept in the DB, never in code) and seeds default options. Fails closed and idempotently.
 */
final class Activator {

	/**
	 * Option holding the HMAC secret used to sign per-order withdrawal tokens.
	 */
	public const SECRET_OPTION = 'recesso_dig_token_secret';

	/**
	 * Transient flagging a just-activated plugin, so the admin sees the one-time welcome notice on the
	 * next page load. Short-lived: it is a UI cue, not state.
	 */
	public const ACTIVATED_TRANSIENT = 'recesso_dig_activated';

	/**
	 * Activation entry point.
	 */
	public static function activate(): void {
		Capabilities::add();
		Migrations::run();
		Upgrades::run();
		self::ensure_secret();
		self::seed_options();
		FlowPage::ensure();

		set_transient( self::ACTIVATED_TRANSIENT, '1', MINUTE_IN_SECONDS );
	}

	/**
	 * Create the token-signing secret once, with cryptographically secure randomness. Never
	 * regenerate it on reactivation (that would invalidate every outstanding token).
	 */
	private static function ensure_secret(): void {
		if ( false === get_option( self::SECRET_OPTION, false ) ) {
			// Not autoloaded: the secret is only needed on the token paths.
			add_option( self::SECRET_OPTION, Hashing::random_secret(), '', false );
		}
	}

	/**
	 * Seed default options without overwriting merchant choices on reactivation.
	 */
	private static function seed_options(): void {
		add_option( 'recesso_dig_delete_data_on_uninstall', '0' );
	}
}
