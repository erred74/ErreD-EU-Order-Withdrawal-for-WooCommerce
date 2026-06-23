<?php
/**
 * Clock abstraction.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the current time. Abstracted behind an interface so the WordPress-free domain layer
 * can be unit-tested deterministically (see {@see FrozenClock}). All times are produced in GMT/UTC.
 */
interface Clock {

	/**
	 * Current instant, always in the UTC timezone.
	 */
	public function now(): \DateTimeImmutable;

	/**
	 * Current instant formatted as a MySQL DATETIME in GMT ('Y-m-d H:i:s').
	 */
	public function now_gmt(): string;
}
