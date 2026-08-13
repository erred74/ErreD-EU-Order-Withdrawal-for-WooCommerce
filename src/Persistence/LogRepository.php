<?php
/**
 * Audit log repository.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only writer for the audit log table. The log is never updated or deleted: every event is a
 * new row, providing a tamper-evident trail of the request lifecycle and access decisions.
 */
final class LogRepository {

	public const EVENT_CREATED       = 'created';
	public const EVENT_CONFIRMED     = 'confirmed';
	public const EVENT_RECEIPT_SENT  = 'receipt_sent';
	public const EVENT_STATUS_CHANGE = 'status_change';
	public const EVENT_ACCESS_DENIED = 'access_denied';

	/**
	 * A lookup whose order number and email matched no order. Recorded so the merchant can spot a
	 * customer who mistyped their reference, without any withdrawal record being created: the request
	 * table only ever holds declarations bound to a real, authorised order.
	 */
	public const EVENT_LOOKUP_UNMATCHED = 'lookup_unmatched';

	/**
	 * WordPress database handle.
	 *
	 * @var \wpdb
	 */
	private \wpdb $wpdb;

	/**
	 * Construct the repository.
	 *
	 * @param \wpdb|null $db Database handle (defaults to the global $wpdb).
	 */
	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * Append an event to the audit log.
	 *
	 * @param int                  $request_id Related request id (0 when not yet known).
	 * @param string               $event      One of the EVENT_* constants.
	 * @param string               $actor      Actor descriptor: consumer | admin:{user_id} | system.
	 * @param array<string, mixed> $payload    Structured, non-sensitive event context.
	 */
	public function record( int $request_id, string $event, string $actor, array $payload = array() ): void {
		$encoded = wp_json_encode( $payload );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->wpdb->insert(
			Schema::log_table(),
			array(
				'request_id'     => $request_id,
				'event'          => $event,
				'actor'          => $actor,
				'payload'        => false === $encoded ? '' : $encoded,
				'created_at_gmt' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Fetch the audit trail for a request, oldest first.
	 *
	 * @param int $request_id Request id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_request( int $request_id ): array {
		$sql = (string) $this->wpdb->prepare(
			'SELECT id, request_id, event, actor, payload, created_at_gmt FROM %i WHERE request_id = %d ORDER BY id ASC',
			Schema::log_table(),
			$request_id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The most recent events of one kind, newest first. Used by the read-only "unmatched lookups"
	 * panel; the limit is clamped so the query stays bounded whatever the caller asks for.
	 *
	 * @param string $event One of the EVENT_* constants.
	 * @param int    $limit Maximum rows to return.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_by_event( string $event, int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );

		$sql = (string) $this->wpdb->prepare(
			'SELECT id, event, actor, payload, created_at_gmt FROM %i WHERE event = %s ORDER BY id DESC LIMIT %d',
			Schema::log_table(),
			$event,
			$limit
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}
}
