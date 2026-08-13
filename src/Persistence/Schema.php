<?php
/**
 * Database schema definitions.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the table names and the canonical dbDelta schema for the plugin's custom tables.
 *
 * Legal records live in custom tables (not order meta) so they can be indexed, audited and kept
 * append-only without overloading the (HPOS) order tables.
 */
final class Schema {

	/**
	 * Unprefixed name of the append-only withdrawal requests table.
	 */
	public const REQUESTS_TABLE = 'recesso_dig_requests';

	/**
	 * Unprefixed name of the append-only audit log table.
	 */
	public const LOG_TABLE = 'recesso_dig_log';

	/**
	 * Unprefixed name of the per-line claim ledger. One row per claimed order line holds the total
	 * quantity currently being withdrawn across all open requests (`claimed_qty`). The UNIQUE
	 * (order_id, line_id) key plus an atomic conditional increment guarantee the claimed quantity
	 * never exceeds the line's total quantity, while letting concurrent partial requests share a
	 * line's units (e.g. 2 of 4 now, 2 of 4 later).
	 */
	public const CLAIMS_TABLE = 'recesso_dig_claims';

	/**
	 * Fully-qualified (prefixed) requests table name.
	 */
	public static function requests_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::REQUESTS_TABLE;
	}

	/**
	 * Fully-qualified (prefixed) log table name.
	 */
	public static function log_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::LOG_TABLE;
	}

	/**
	 * Fully-qualified (prefixed) claims table name.
	 */
	public static function claims_table(): string {
		global $wpdb;

		return $wpdb->prefix . self::CLAIMS_TABLE;
	}

	/**
	 * The dbDelta CREATE TABLE statements for every custom table.
	 *
	 * @return string[] One CREATE TABLE statement per table.
	 */
	public static function statements(): array {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$requests        = self::requests_table();
		$log             = self::log_table();
		$claims          = self::claims_table();

		// `active_claim` is a legacy order-level claim column, retained for backward compatibility but
		// left NULL on new rows: the per-line claims table ({@see self::CLAIMS_TABLE}) is now the
		// atomic duplicate guard, which lets concurrent partial requests coexist on disjoint lines.
		// MySQL allows multiple NULLs in a UNIQUE index, so the legacy UNIQUE key never blocks them.
		$requests_sql = "CREATE TABLE {$requests} (
  id bigint(20) unsigned NOT NULL auto_increment,
  order_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL default 'pending',
  active_claim varchar(191) default NULL,
  consumer_name varchar(191) NOT NULL default '',
  contract_reference varchar(191) NOT NULL default '',
  confirmation_email varchar(191) NOT NULL default '',
  requested_items longtext,
  refund_iban varchar(34) NOT NULL default '',
  withdrawal_reason text,
  consumer_declaration text,
  submitted_at_gmt datetime default NULL,
  confirmed_at_gmt datetime default NULL,
  acknowledged_at_gmt datetime default NULL,
  receipt_hash char(64) default NULL,
  receipt_path varchar(255) default NULL,
  request_ip varbinary(16) default NULL,
  created_at_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY active_claim (active_claim),
  KEY order_id (order_id),
  KEY status (status),
  KEY confirmed_at_gmt (confirmed_at_gmt)
) {$charset_collate};";

		$log_sql = "CREATE TABLE {$log} (
  id bigint(20) unsigned NOT NULL auto_increment,
  request_id bigint(20) unsigned NOT NULL,
  event varchar(40) NOT NULL,
  actor varchar(60) NOT NULL,
  payload longtext,
  created_at_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY request_id (request_id),
  KEY event (event)
) {$charset_collate};";

		// Per-line claim ledger: one row per (order_id, line_id) tracks the total quantity currently
		// being withdrawn across all open requests. The UNIQUE (order_id, line_id) key plus an atomic
		// conditional increment (claimed_qty + n <= line total) prevent over-claiming under
		// concurrency; the per-request quantities live in the request's requested_items JSON and are
		// subtracted back here when a request is rejected/expired.
		$claims_sql = "CREATE TABLE {$claims} (
  id bigint(20) unsigned NOT NULL auto_increment,
  order_id bigint(20) unsigned NOT NULL,
  line_id bigint(20) unsigned NOT NULL,
  claimed_qty int(10) unsigned NOT NULL default 0,
  created_at_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_line (order_id, line_id)
) {$charset_collate};";

		return array( $requests_sql, $log_sql, $claims_sql );
	}
}
