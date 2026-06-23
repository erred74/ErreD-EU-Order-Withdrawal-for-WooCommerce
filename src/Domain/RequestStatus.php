<?php
/**
 * Withdrawal request status.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * The lifecycle states of a withdrawal request and the rules about which states keep the order
 * "claimed" (blocking a duplicate open request). WordPress-free so the domain stays unit-testable.
 */
final class RequestStatus {

	public const PENDING      = 'pending';
	public const CONFIRMED    = 'confirmed';
	public const ACKNOWLEDGED = 'acknowledged';
	public const ACCEPTED     = 'accepted';
	public const COMPLETED    = 'completed';
	public const REFUNDED     = 'refunded';
	public const REJECTED     = 'rejected';
	public const EXPIRED      = 'expired';

	/**
	 * All valid status values.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::PENDING,
			self::CONFIRMED,
			self::ACKNOWLEDGED,
			self::ACCEPTED,
			self::COMPLETED,
			self::REFUNDED,
			self::REJECTED,
			self::EXPIRED,
		);
	}

	/**
	 * Whether the given status is a known value.
	 *
	 * @param string $status Candidate status.
	 */
	public static function is_valid( string $status ): bool {
		return in_array( $status, self::all(), true );
	}

	/**
	 * Whether a status keeps the order claimed (i.e. blocks a new open request). Rejected and
	 * expired requests release the claim so the consumer may try again; all other states hold it.
	 *
	 * @param string $status Status to test.
	 */
	public static function holds_claim( string $status ): bool {
		return ! in_array( $status, array( self::REJECTED, self::EXPIRED ), true );
	}

	/**
	 * The simplified set of statuses the merchant sets from the admin detail panel (the "set status"
	 * dropdown). A subset of {@see self::all()}: the lifecycle's automatic states all read as "pending"
	 * for the merchant, who then decides accepted / rejected / completed.
	 *
	 * @return string[]
	 */
	public static function admin_statuses(): array {
		return array( self::PENDING, self::ACCEPTED, self::REJECTED, self::COMPLETED );
	}

	/**
	 * Map a lifecycle status to the simplified admin decision shown in the orders column and the detail
	 * dropdown. Anything that is not an explicit merchant decision (pending / confirmed / acknowledged /
	 * expired) reads as "pending"; a refunded request reads as "completed".
	 *
	 * @param string $status Lifecycle status.
	 */
	public static function to_admin( string $status ): string {
		return match ( $status ) {
			self::ACCEPTED                  => self::ACCEPTED,
			self::REJECTED                  => self::REJECTED,
			self::COMPLETED, self::REFUNDED => self::COMPLETED,
			default                         => self::PENDING,
		};
	}
}
