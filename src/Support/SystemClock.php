<?php
/**
 * System clock.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Real clock backed by the system time, normalised to UTC.
 */
final class SystemClock implements Clock {

	/**
	 * {@inheritDoc}
	 */
	public function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function now_gmt(): string {
		return $this->now()->format( 'Y-m-d H:i:s' );
	}
}
