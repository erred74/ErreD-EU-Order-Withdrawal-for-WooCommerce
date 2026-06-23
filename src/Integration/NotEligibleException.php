<?php
/**
 * Not-eligible exception.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a withdrawal declaration is attempted for an order that is not eligible. Carries the
 * machine-readable eligibility reason so callers can respond without re-evaluating.
 */
final class NotEligibleException extends \RuntimeException {

	/**
	 * Eligibility reason code.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Construct the exception.
	 *
	 * @param string $reason One of the {@see \Recesso54bis\Domain\Eligibility\Reason} constants.
	 */
	public function __construct( string $reason ) {
		parent::__construct( 'Order is not eligible for withdrawal: ' . $reason );
		$this->reason = $reason;
	}

	/**
	 * The eligibility reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
