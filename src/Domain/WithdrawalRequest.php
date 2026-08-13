<?php
/**
 * Withdrawal request value object.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable snapshot of a withdrawal request row. WordPress-free, so it can be asserted on in pure
 * unit tests. The legal facts ({@see $confirmed_at_gmt}, {@see $receipt_hash}) are write-once at the
 * persistence layer; this object never mutates them.
 */
final class WithdrawalRequest {

	/**
	 * Construct an immutable withdrawal request snapshot.
	 *
	 * @param int             $id                  Primary key.
	 * @param int             $order_id            WooCommerce order id.
	 * @param string          $status              One of {@see RequestStatus} constants.
	 * @param string          $consumer_name       Name as confirmed by the consumer.
	 * @param string          $contract_reference  Order/contract reference shown to the consumer.
	 * @param string          $confirmation_email  Address where the receipt is sent.
	 * @param array<int, int> $requested_items     Map of order line id => quantity being withdrawn (partial
	 *                                             by line and by quantity). A quantity of 0 is a legacy
	 *                                             marker meaning "the whole line".
	 * @param string|null     $submitted_at_gmt    First declaration timestamp (GMT).
	 * @param string|null     $confirmed_at_gmt    Legal moment of communication / dies a quo (GMT).
	 * @param string|null     $acknowledged_at_gmt Durable receipt issuance timestamp (GMT).
	 * @param string|null     $receipt_hash        SHA-256 of the canonical receipt payload.
	 * @param string|null     $receipt_path        Stored receipt path (protected location).
	 * @param string|null     $created_at_gmt      Row creation timestamp (GMT).
	 * @param string          $refund_iban         Optional IBAN the consumer provided for the refund.
	 * @param string          $withdrawal_reason   Optional reason the consumer gave for withdrawing.
	 * @param string          $consumer_declaration The exact "bought as a consumer" wording the consumer
	 *                                             agreed to, when the merchant asks for it. Empty when
	 *                                             the declaration was not requested.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $order_id,
		public readonly string $status,
		public readonly string $consumer_name,
		public readonly string $contract_reference,
		public readonly string $confirmation_email,
		public readonly array $requested_items,
		public readonly ?string $submitted_at_gmt,
		public readonly ?string $confirmed_at_gmt,
		public readonly ?string $acknowledged_at_gmt,
		public readonly ?string $receipt_hash,
		public readonly ?string $receipt_path,
		public readonly ?string $created_at_gmt,
		public readonly string $refund_iban = '',
		public readonly string $withdrawal_reason = '',
		public readonly string $consumer_declaration = ''
	) {}

	/**
	 * Build a value object from a raw database row.
	 *
	 * @param array<string, mixed> $row Associative row from the requests table.
	 */
	public static function from_row( array $row ): self {
		$items = array();
		if ( isset( $row['requested_items'] ) && '' !== (string) $row['requested_items'] ) {
			$decoded = json_decode( (string) $row['requested_items'], true );
			if ( is_array( $decoded ) ) {
				if ( array_is_list( $decoded ) ) {
					// Legacy shape: a list of line ids (whole-line withdrawal). Quantity 0 marks "whole line".
					foreach ( $decoded as $line_id ) {
						$items[ (int) $line_id ] = 0;
					}
				} else {
					// Current shape: a map of line id => quantity.
					foreach ( $decoded as $line_id => $quantity ) {
						$items[ (int) $line_id ] = max( 0, (int) $quantity );
					}
				}
			}
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(int) ( $row['order_id'] ?? 0 ),
			(string) ( $row['status'] ?? RequestStatus::PENDING ),
			(string) ( $row['consumer_name'] ?? '' ),
			(string) ( $row['contract_reference'] ?? '' ),
			(string) ( $row['confirmation_email'] ?? '' ),
			$items,
			self::nullable_string( $row['submitted_at_gmt'] ?? null ),
			self::nullable_string( $row['confirmed_at_gmt'] ?? null ),
			self::nullable_string( $row['acknowledged_at_gmt'] ?? null ),
			self::nullable_string( $row['receipt_hash'] ?? null ),
			self::nullable_string( $row['receipt_path'] ?? null ),
			self::nullable_string( $row['created_at_gmt'] ?? null ),
			(string) ( $row['refund_iban'] ?? '' ),
			(string) ( $row['withdrawal_reason'] ?? '' ),
			(string) ( $row['consumer_declaration'] ?? '' )
		);
	}

	/**
	 * Whether the request has been confirmed (step 2 completed).
	 */
	public function is_confirmed(): bool {
		return null !== $this->confirmed_at_gmt;
	}

	/**
	 * Whether a durable receipt has been attached.
	 */
	public function has_receipt(): bool {
		return null !== $this->receipt_hash;
	}

	/**
	 * Normalise a possibly-empty value to a trimmed string or null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = (string) $value;

		return '' === $value ? null : $value;
	}
}
