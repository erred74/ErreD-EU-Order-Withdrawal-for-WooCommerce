<?php
/**
 * Withdrawal request repository.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Persistence;

use Recesso54bis\Domain\RequestStatus;
use Recesso54bis\Domain\WithdrawalRequest;

defined( 'ABSPATH' ) || exit;

/**
 * The single audited surface for all SQL against the requests table. Every query is parameterised
 * with $wpdb->prepare (table and column identifiers via the %i placeholder); the legal facts are
 * write-once and enforced atomically in the database (conditional UPDATEs), never by a
 * read-then-write race in PHP.
 */
final class RequestRepository {

	/**
	 * Columns selected for hydration. A literal string, safe to embed in a prepared statement.
	 */
	private const COLUMNS = 'id, order_id, status, active_claim, consumer_name, contract_reference, confirmation_email, requested_items, refund_iban, withdrawal_reason, submitted_at_gmt, confirmed_at_gmt, acknowledged_at_gmt, receipt_hash, receipt_path, created_at_gmt';

	/**
	 * Whitelisted ORDER BY columns for the admin list.
	 *
	 * @var string[]
	 */
	private const ORDERABLE = array( 'id', 'created_at_gmt', 'confirmed_at_gmt', 'status' );

	/**
	 * The free-text search predicate. A literal string with four %s placeholders, one per searchable
	 * field; the same escaped LIKE value is bound to each.
	 */
	private const SEARCH_PREDICATE = '( consumer_name LIKE %s OR contract_reference LIKE %s OR confirmation_email LIKE %s OR CAST( order_id AS CHAR ) LIKE %s )';

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
	 * Create a pending declaration, atomically reserving the requested quantity of each selected line
	 * in the per-line claim ledger. Concurrent partial requests share a line's units: the reservation
	 * is enforced by a conditional increment (claimed_qty + n <= line total), never by a
	 * read-then-write race in PHP.
	 *
	 * @param int                  $order_id Order id.
	 * @param array<string, mixed> $data     Keys: consumer_name, contract_reference, confirmation_email,
	 *                                        requested_items (map line_id => quantity), line_totals
	 *                                        (map line_id => total order quantity), request_ip (packed|null).
	 * @param string               $now_gmt  Current GMT timestamp ('Y-m-d H:i:s').
	 *
	 * @throws DuplicateOpenRequestException When the requested quantity exceeds the units still available.
	 * @throws \RuntimeException             When the row cannot be persisted or re-read.
	 */
	public function create_declaration( int $order_id, array $data, string $now_gmt ): WithdrawalRequest {
		$items   = isset( $data['requested_items'] ) && is_array( $data['requested_items'] ) ? $data['requested_items'] : array();
		$items   = $this->normalise_quantities( $items );
		$totals  = isset( $data['line_totals'] ) && is_array( $data['line_totals'] ) ? $data['line_totals'] : array();
		$encoded = wp_json_encode( array() === $items ? (object) array() : $items );

		$row = array(
			'order_id'           => $order_id,
			'status'             => RequestStatus::PENDING,
			'consumer_name'      => (string) ( $data['consumer_name'] ?? '' ),
			'contract_reference' => (string) ( $data['contract_reference'] ?? '' ),
			'confirmation_email' => (string) ( $data['confirmation_email'] ?? '' ),
			'requested_items'    => false === $encoded ? '{}' : $encoded,
			'refund_iban'        => (string) ( $data['refund_iban'] ?? '' ),
			'withdrawal_reason'  => (string) ( $data['withdrawal_reason'] ?? '' ),
			'submitted_at_gmt'   => $now_gmt,
			'request_ip'         => $data['request_ip'] ?? null,
			'created_at_gmt'     => $now_gmt,
		);

		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $this->wpdb->insert( Schema::requests_table(), $row, $formats );

		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to persist the withdrawal declaration.' );
		}

		$request_id = (int) $this->wpdb->insert_id;

		// Atomically reserve the requested quantity of each line. On a shortfall (the requested units
		// exceed those still available) the partial reservation is rolled back, the orphaned pending
		// row removed, and the conflict surfaced.
		if ( ! $this->claim_quantities( $order_id, $items, $totals, $now_gmt ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->delete( Schema::requests_table(), array( 'id' => $request_id ), array( '%d' ) );

			throw new DuplicateOpenRequestException( 'The requested quantity exceeds the units available for one or more lines.' );
		}

		$created = $this->find_by_id( $request_id );
		if ( null === $created ) {
			throw new \RuntimeException( 'Withdrawal declaration could not be re-read after insertion.' );
		}

		return $created;
	}

	/**
	 * Atomically reserve a quantity of each requested line in the ledger. Each line is reserved with a
	 * conditional increment that never lets the claimed quantity exceed the line total, so over-claims
	 * cannot occur under concurrency. If any line cannot be fully reserved, every reservation made in
	 * this call is rolled back and the method returns false.
	 *
	 * @param int             $order_id    Order id.
	 * @param array<int, int> $quantities  Map line_id => requested quantity (positive).
	 * @param array<int, int> $totals      Map line_id => total order quantity for that line.
	 * @param string          $now_gmt     Current GMT timestamp.
	 *
	 * @return bool True when every line was fully reserved (or there were none); false on a shortfall.
	 */
	private function claim_quantities( int $order_id, array $quantities, array $totals, string $now_gmt ): bool {
		$reserved = array();
		foreach ( $quantities as $line_id => $qty ) {
			$line_id = (int) $line_id;
			$qty     = (int) $qty;
			$total   = (int) ( $totals[ $line_id ] ?? 0 );

			if ( $qty <= 0 || $qty > $total ) {
				$this->release_quantities( $order_id, $reserved );
				return false;
			}

			$this->ensure_ledger_row( $order_id, $line_id, $now_gmt );

			// Atomic conditional increment: succeeds only while the line still has the units free.
			$sql = (string) $this->wpdb->prepare(
				'UPDATE %i SET claimed_qty = claimed_qty + %d WHERE order_id = %d AND line_id = %d AND claimed_qty + %d <= %d',
				Schema::claims_table(),
				$qty,
				$order_id,
				$line_id,
				$qty,
				$total
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->query( $sql );

			if ( 1 !== (int) $this->wpdb->rows_affected ) {
				$this->release_quantities( $order_id, $reserved );
				return false;
			}

			$reserved[ $line_id ] = $qty;
		}

		return true;
	}

	/**
	 * Ensure a ledger row exists for a line (idempotent via the UNIQUE order_line key).
	 *
	 * @param int    $order_id Order id.
	 * @param int    $line_id  Order line id.
	 * @param string $now_gmt  Current GMT timestamp.
	 */
	private function ensure_ledger_row( int $order_id, int $line_id, string $now_gmt ): void {
		$sql = (string) $this->wpdb->prepare(
			'INSERT IGNORE INTO %i (order_id, line_id, claimed_qty, created_at_gmt) VALUES (%d, %d, 0, %s)',
			Schema::claims_table(),
			$order_id,
			$line_id,
			$now_gmt
		);

		$suppressed = $this->wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $sql );
		$this->wpdb->suppress_errors( $suppressed );
	}

	/**
	 * Return reserved quantities to the ledger (on rollback or when a request is released), clamping at
	 * zero and removing ledger rows that fall back to zero.
	 *
	 * @param int             $order_id   Order id.
	 * @param array<int, int> $quantities Map line_id => quantity to return.
	 */
	private function release_quantities( int $order_id, array $quantities ): void {
		foreach ( $quantities as $line_id => $qty ) {
			$line_id = (int) $line_id;
			$qty     = (int) $qty;
			if ( $qty <= 0 ) {
				continue;
			}

			$sql = (string) $this->wpdb->prepare(
				'UPDATE %i SET claimed_qty = GREATEST(0, claimed_qty - %d) WHERE order_id = %d AND line_id = %d',
				Schema::claims_table(),
				$qty,
				$order_id,
				$line_id
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$this->wpdb->query( $sql );
		}

		// Tidy up: drop ledger rows for this order that no longer reserve any units.
		$sql = (string) $this->wpdb->prepare(
			'DELETE FROM %i WHERE order_id = %d AND claimed_qty = 0',
			Schema::claims_table(),
			$order_id
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $sql );
	}

	/**
	 * Normalise a raw requested-items structure to a map of positive line_id => positive quantity.
	 *
	 * @param array<int|string, mixed> $items Raw requested items (map line_id => quantity).
	 *
	 * @return array<int, int>
	 */
	private function normalise_quantities( array $items ): array {
		$out = array();
		foreach ( $items as $line_id => $qty ) {
			$lid = (int) $line_id;
			$q   = (int) $qty;
			if ( $lid > 0 && $q > 0 ) {
				$out[ $lid ] = $q;
			}
		}

		return $out;
	}

	/**
	 * Find a request by primary key.
	 *
	 * @param int $id Request id.
	 */
	public function find_by_id( int $id ): ?WithdrawalRequest {
		$sql = (string) $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COLUMNS is a literal constant; identifiers use %i.
			'SELECT ' . self::COLUMNS . ' FROM %i WHERE id = %d',
			Schema::requests_table(),
			$id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? WithdrawalRequest::from_row( $row ) : null;
	}

	/**
	 * The most recent request for an order (highest id), or null when the order has none. Used by the
	 * orders-list column to show the order's current withdrawal status.
	 *
	 * @param int $order_id Order id.
	 */
	public function latest_for_order( int $order_id ): ?WithdrawalRequest {
		$sql = (string) $this->wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- COLUMNS is a literal constant; identifiers use %i.
			'SELECT ' . self::COLUMNS . ' FROM %i WHERE order_id = %d ORDER BY id DESC LIMIT 1',
			Schema::requests_table(),
			$order_id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row( $sql, ARRAY_A );

		return is_array( $row ) ? WithdrawalRequest::from_row( $row ) : null;
	}

	/**
	 * The quantity currently reserved (being withdrawn) for each line of an order, keyed by line id.
	 * Lines with no open reservation are absent. Used to compute the units still available.
	 *
	 * @param int $order_id Order id.
	 *
	 * @return array<int, int> Map line_id => reserved quantity.
	 */
	public function claimed_quantities( int $order_id ): array {
		$sql = (string) $this->wpdb->prepare(
			'SELECT line_id, claimed_qty FROM %i WHERE order_id = %d',
			Schema::claims_table(),
			$order_id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['line_id'] ] = (int) $row['claimed_qty'];
		}

		return $map;
	}

	/**
	 * Confirm a request (step 2). Write-once: only the first confirmation sets confirmed_at_gmt.
	 * Re-confirming is idempotent and returns the already-confirmed record without changes.
	 *
	 * @param int    $id               Request id.
	 * @param string $confirmed_at_gmt Confirmation timestamp (GMT) — the legal dies a quo.
	 */
	public function confirm( int $id, string $confirmed_at_gmt ): ?WithdrawalRequest {
		// Conditional, atomic write-once: the WHERE clause guarantees we never overwrite a prior
		// confirmation, so concurrent confirmations cannot race.
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i SET confirmed_at_gmt = %s, status = %s WHERE id = %d AND confirmed_at_gmt IS NULL',
			Schema::requests_table(),
			$confirmed_at_gmt,
			RequestStatus::CONFIRMED,
			$id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $sql );

		return $this->find_by_id( $id );
	}

	/**
	 * Attach the durable-medium receipt. Write-once on receipt_hash; transitions to acknowledged.
	 *
	 * @param int    $id                  Request id.
	 * @param string $receipt_hash        SHA-256 of the canonical receipt payload.
	 * @param string $receipt_path        Stored receipt path (protected location).
	 * @param string $acknowledged_at_gmt Receipt issuance timestamp (GMT).
	 */
	public function attach_receipt( int $id, string $receipt_hash, string $receipt_path, string $acknowledged_at_gmt ): ?WithdrawalRequest {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i SET receipt_hash = %s, receipt_path = %s, acknowledged_at_gmt = %s, status = %s WHERE id = %d AND receipt_hash IS NULL',
			Schema::requests_table(),
			$receipt_hash,
			$receipt_path,
			$acknowledged_at_gmt,
			RequestStatus::ACKNOWLEDGED,
			$id
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $sql );

		return $this->find_by_id( $id );
	}

	/**
	 * Count requests confirmed at or after the given GMT timestamp (for the admin stats dashboard).
	 *
	 * @param string $since_gmt Lower bound GMT timestamp ('Y-m-d H:i:s').
	 */
	public function count_confirmed_since( string $since_gmt ): int {
		$sql = (string) $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE confirmed_at_gmt IS NOT NULL AND confirmed_at_gmt >= %s',
			Schema::requests_table(),
			$since_gmt
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Personal-data rows tied to a confirmation email, for the WordPress privacy exporter.
	 *
	 * @param string $email The consumer email to match.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function export_for_email( string $email ): array {
		$sql = (string) $this->wpdb->prepare(
			'SELECT id, order_id, status, consumer_name, confirmation_email, contract_reference, refund_iban, withdrawal_reason, request_ip, submitted_at_gmt, confirmed_at_gmt, acknowledged_at_gmt FROM %i WHERE confirmation_email = %s ORDER BY id ASC',
			Schema::requests_table(),
			$email
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Anonymise the personal data tied to a confirmation email, for the WordPress privacy eraser. The
	 * consumer's name, email and IP are cleared; the legal facts (confirmation timestamp, receipt hash
	 * and stored receipt) are deliberately retained, as the acknowledgement is a mandatory record.
	 *
	 * @param string $email The consumer email to match.
	 *
	 * @return int Number of rows anonymised.
	 */
	public function anonymise_for_email( string $email ): int {
		$sql = (string) $this->wpdb->prepare(
			'UPDATE %i SET consumer_name = %s, confirmation_email = %s, refund_iban = %s, withdrawal_reason = %s, request_ip = NULL WHERE confirmation_email = %s',
			Schema::requests_table(),
			'',
			'',
			'',
			'',
			$email
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $sql );

		return (int) $this->wpdb->rows_affected;
	}

	/**
	 * Transition a request to a new status, releasing the order claim when the status permits a
	 * fresh request. The legal timestamps are never touched here.
	 *
	 * @param int    $id     Request id.
	 * @param string $status Target status (one of {@see RequestStatus}).
	 *
	 * @throws \InvalidArgumentException When the target status is not recognised.
	 */
	public function transition_status( int $id, string $status ): ?WithdrawalRequest {
		if ( ! RequestStatus::is_valid( $status ) ) {
			throw new \InvalidArgumentException( 'Unknown withdrawal request status.' );
		}

		// Capture the prior state so the ledger is released exactly once, on the holding -> released
		// edge (a second transition to an already-released state must not double-decrement).
		$before      = $this->find_by_id( $id );
		$was_holding = null !== $before && RequestStatus::holds_claim( $before->status );

		if ( RequestStatus::holds_claim( $status ) ) {
			$sql = (string) $this->wpdb->prepare(
				'UPDATE %i SET status = %s WHERE id = %d',
				Schema::requests_table(),
				$status,
				$id
			);
		} else {
			// Release the claim so the consumer may submit again.
			$sql = (string) $this->wpdb->prepare(
				'UPDATE %i SET status = %s, active_claim = NULL WHERE id = %d',
				Schema::requests_table(),
				$status,
				$id
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->wpdb->query( $sql );

		// Releasing a request returns its reserved quantities to the ledger so those units can be
		// withdrawn again.
		if ( $was_holding && ! RequestStatus::holds_claim( $status ) && null !== $before ) {
			$this->release_quantities( $before->order_id, $before->requested_items );
		}

		return $this->find_by_id( $id );
	}

	/**
	 * Paginated admin listing with an optional status filter and free-text search.
	 *
	 * @param array{status?: string, search?: string, orderby?: string, order?: string, per_page?: int, page?: int} $args Query args.
	 *
	 * @return WithdrawalRequest[]
	 */
	public function query_for_admin( array $args = array() ): array {
		$orderby   = in_array( $args['orderby'] ?? '', self::ORDERABLE, true ) ? (string) $args['orderby'] : 'created_at_gmt';
		$direction = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? ' ASC' : ' DESC';
		$per_page  = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset    = ( $page - 1 ) * $per_page;
		$table     = Schema::requests_table();

		$where  = $this->admin_where( $args );
		$params = array_merge(
			array( $table ),
			$this->admin_where_params( $args ),
			array( $orderby, $per_page, $offset )
		);

		// Every SQL fragment is a literal: COLUMNS and the WHERE clause come from class constants /
		// match arms (literal-string), $direction is one of two literal strings, $orderby is
		// whitelisted, and all identifiers use %i. Every variable value is bound through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- only literals concatenated; values bound via the $params array.
		$sql = (string) $this->wpdb->prepare(
			'SELECT ' . self::COLUMNS . ' FROM %i' . $where . ' ORDER BY %i' . $direction . ' LIMIT %d OFFSET %d',
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static fn( array $row ): WithdrawalRequest => WithdrawalRequest::from_row( $row ),
			$rows
		);
	}

	/**
	 * Count requests for the admin list, with the same status + search filters (for pagination).
	 *
	 * @param array{status?: string, search?: string} $args Query args.
	 */
	public function count_for_admin( array $args = array() ): int {
		$table  = Schema::requests_table();
		$where  = $this->admin_where( $args );
		$params = array_merge( array( $table ), $this->admin_where_params( $args ) );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- WHERE is a literal match arm; values bound via the $params array.
		$sql = (string) $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i' . $where,
			$params
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Count requests awaiting an admin decision (for the menu badge): a request is "open" once the
	 * consumer has confirmed it and until the merchant processes it. This spans both `confirmed`
	 * (receipt pending) and `acknowledged` (receipt issued), since the durable-medium receipt is
	 * generated automatically right after confirmation — counting only `confirmed` made the badge
	 * vanish as soon as the receipt was issued.
	 */
	public function count_awaiting_action(): int {
		$sql = (string) $this->wpdb->prepare(
			'SELECT COUNT(*) FROM %i WHERE status IN ( %s, %s )',
			Schema::requests_table(),
			RequestStatus::CONFIRMED,
			RequestStatus::ACKNOWLEDGED
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * Discard any abandoned, unconfirmed (pending) requests for an order, releasing their reserved
	 * quantities. A pending request is not a legal record (no confirmation, no dies a quo), so a
	 * consumer who closed the page mid-flow can start a fresh declaration without being blocked by the
	 * abandoned attempt's reservation. Confirmed (or later) requests are never touched.
	 *
	 * @param int $order_id Order id.
	 */
	public function discard_pending_for_order( int $order_id ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- only the literal COLUMNS const is concatenated; values bound via prepare().
		$sql = (string) $this->wpdb->prepare(
			'SELECT ' . self::COLUMNS . ' FROM %i WHERE order_id = %d AND status = %s',
			Schema::requests_table(),
			$order_id,
			RequestStatus::PENDING
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$request = WithdrawalRequest::from_row( $row );
			$this->release_quantities( $order_id, $request->requested_items );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$this->wpdb->delete( Schema::requests_table(), array( 'id' => $request->id ), array( '%d' ) );
		}
	}

	/**
	 * The admin-list WHERE clause for the active status + search filters. Returns a literal string
	 * (one of a fixed set of match arms) so it is safe to embed in a prepared statement; the bound
	 * values are produced in lock-step by {@see admin_where_params()}.
	 *
	 * @param array{status?: string, search?: string} $args Query args.
	 *
	 * @return literal-string
	 */
	private function admin_where( array $args ): string {
		$has_status = $this->valid_status( $args ) !== '';
		$has_search = $this->search_term( $args ) !== '';

		// Unconfirmed (pending) declarations are abandoned or in-progress attempts, not real
		// requests: they never appear in the admin list or its counts. The leading `status <> %s`
		// (bound to "pending" by admin_where_params) is always present.
		if ( $has_status && $has_search ) {
			return ' WHERE status <> %s AND status = %s AND ' . self::SEARCH_PREDICATE;
		}
		if ( $has_status ) {
			return ' WHERE status <> %s AND status = %s';
		}
		if ( $has_search ) {
			return ' WHERE status <> %s AND ' . self::SEARCH_PREDICATE;
		}

		return ' WHERE status <> %s';
	}

	/**
	 * The bound values matching {@see admin_where()}, in placeholder order: the excluded "pending"
	 * status first, then the filter status (when set), then the escaped LIKE value repeated once per
	 * searchable field.
	 *
	 * @param array{status?: string, search?: string} $args Query args.
	 *
	 * @return list<string>
	 */
	private function admin_where_params( array $args ): array {
		// The leading `status <> %s` in every admin_where() arm excludes pending declarations.
		$params = array( RequestStatus::PENDING );

		$status = $this->valid_status( $args );
		if ( '' !== $status ) {
			$params[] = $status;
		}

		$search = $this->search_term( $args );
		if ( '' !== $search ) {
			$like   = '%' . $this->wpdb->esc_like( $search ) . '%';
			$params = array_merge( $params, array( $like, $like, $like, $like ) );
		}

		return $params;
	}

	/**
	 * The requested status if it is a recognised value, otherwise an empty string.
	 *
	 * @param array{status?: string} $args Query args.
	 */
	private function valid_status( array $args ): string {
		$status = (string) ( $args['status'] ?? '' );

		return ( '' !== $status && RequestStatus::is_valid( $status ) ) ? $status : '';
	}

	/**
	 * The trimmed free-text search term.
	 *
	 * @param array{search?: string} $args Query args.
	 */
	private function search_term( array $args ): string {
		return trim( (string) ( $args['search'] ?? '' ) );
	}
}
