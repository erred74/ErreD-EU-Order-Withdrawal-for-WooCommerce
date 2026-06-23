<?php
/**
 * Simple transient-backed rate limiter.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Throttles abuse of the public withdrawal endpoints. Counters are keyed per bucket (e.g. per IP and
 * per order) and stored as transients (object-cache backed where available). After too many failures
 * within the window the bucket is locked, which the callers translate into a uniform, generic error
 * so order existence is never leaked.
 */
final class RateLimiter {

	/**
	 * Transient key prefix.
	 */
	private const PREFIX = 'recesso_dig_rl_';

	/**
	 * Default maximum failures permitted within the window before locking.
	 */
	public const DEFAULT_MAX = 10;

	/**
	 * Default window length in seconds.
	 */
	public const DEFAULT_WINDOW = 900;

	/**
	 * Whether the bucket has reached the failure threshold and is locked.
	 *
	 * @param string $bucket Opaque bucket identifier.
	 * @param int    $max    Failure threshold.
	 */
	public function too_many_attempts( string $bucket, int $max = self::DEFAULT_MAX ): bool {
		return $this->count( $bucket ) >= $max;
	}

	/**
	 * Record a failed attempt against the bucket and return the new count.
	 *
	 * @param string $bucket Opaque bucket identifier.
	 * @param int    $window Window length in seconds.
	 */
	public function hit( string $bucket, int $window = self::DEFAULT_WINDOW ): int {
		$key   = $this->key( $bucket );
		$count = $this->count( $bucket ) + 1;

		set_transient( $key, $count, $window );

		return $count;
	}

	/**
	 * Clear the counter for a bucket (e.g. after a successful, authorised action).
	 *
	 * @param string $bucket Opaque bucket identifier.
	 */
	public function clear( string $bucket ): void {
		delete_transient( $this->key( $bucket ) );
	}

	/**
	 * Current failure count for a bucket.
	 *
	 * @param string $bucket Opaque bucket identifier.
	 */
	private function count( string $bucket ): int {
		$value = get_transient( $this->key( $bucket ) );

		return false === $value ? 0 : (int) $value;
	}

	/**
	 * Build the transient key for a bucket.
	 *
	 * @param string $bucket Opaque bucket identifier.
	 */
	private function key( string $bucket ): string {
		return self::PREFIX . md5( $bucket );
	}
}
