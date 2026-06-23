<?php
/**
 * Frozen clock for deterministic tests.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Clock that always returns a fixed instant. Used by the unit tests for the eligibility engine
 * and write-once timestamp logic.
 */
final class FrozenClock implements Clock {

	/**
	 * The fixed instant, in UTC.
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $fixed;

	/**
	 * Construct the frozen clock.
	 *
	 * @param \DateTimeImmutable $fixed The instant to freeze at (will be normalised to UTC).
	 */
	public function __construct( \DateTimeImmutable $fixed ) {
		$this->fixed = $fixed->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function now(): \DateTimeImmutable {
		return $this->fixed;
	}

	/**
	 * {@inheritDoc}
	 */
	public function now_gmt(): string {
		return $this->fixed->format( 'Y-m-d H:i:s' );
	}
}
