<?php
/**
 * Schema migrations.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Activation;

use Recesso54bis\Persistence\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and forward-migrates the custom tables idempotently via dbDelta, tracking the installed
 * schema version in an option so upgrades only run when needed.
 */
final class Migrations {

	/**
	 * Option storing the installed schema version.
	 */
	public const VERSION_OPTION = 'recesso_dig_db_version';

	/**
	 * Current schema version. Bump when {@see Schema::statements()} changes.
	 *
	 * Version 2 added the per-line claims table ({@see Schema::CLAIMS_TABLE}) for concurrent partial
	 * withdrawals. Version 3 restructured that table into a per-line quantity ledger (claimed_qty;
	 * dropped request_id) for partial-by-quantity withdrawals. Version 4 added the optional
	 * `refund_iban` and `withdrawal_reason` columns to the requests table (dbDelta adds them in place).
	 */
	public const CURRENT_VERSION = '4';

	/**
	 * Ensure the schema is at the current version. Safe to call repeatedly (dbDelta is idempotent).
	 */
	public static function run(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::drop_legacy_claims_table();

		foreach ( Schema::statements() as $statement ) {
			dbDelta( $statement );
		}

		// Only mark the schema as current once the expected columns are actually present. If a dbDelta
		// silently failed to apply them (a transient error, an interrupted update), the version is left
		// behind so {@see self::maybe_upgrade()} retries on the next admin_init — rather than masking a
		// half-migrated schema that would make every requests query fail until a manual re-activation.
		if ( self::requests_columns_present() ) {
			update_option( self::VERSION_OPTION, self::CURRENT_VERSION );
		}
	}

	/**
	 * Run migrations only if the installed version is behind the current one. Cheap enough to call
	 * on admin_init (a single option read when already up to date).
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '0' );
		if ( self::CURRENT_VERSION === $installed ) {
			return;
		}

		self::run();
	}

	/**
	 * Whether the requests table has the columns the current schema requires. Used to confirm a
	 * migration actually applied before the installed version is advanced.
	 */
	private static function requests_columns_present(): bool {
		global $wpdb;

		$table    = Schema::requests_table();
		$required = array( 'refund_iban', 'withdrawal_reason' );

		foreach ( $required as $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
					$table,
					$column
				)
			);

			if ( $exists < 1 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drop the claims table when it still has the legacy per-(request,line) structure (a `request_id`
	 * column), so dbDelta can recreate the per-line quantity ledger — dbDelta cannot drop columns/keys
	 * in place. The claims table is transient lock state (not a legal record — those live in the
	 * requests/log tables), so dropping it only releases in-flight reservations. Once recreated without
	 * `request_id`, this is a no-op, so live reservations are never wiped during normal operation.
	 */
	private static function drop_legacy_claims_table(): void {
		global $wpdb;

		$claims = Schema::claims_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$has_legacy_column = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$claims,
				'request_id'
			)
		);

		if ( $has_legacy_column > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $claims ) );
		}
	}
}
