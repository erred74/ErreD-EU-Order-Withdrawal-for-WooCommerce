<?php
/**
 * Duplicate open request exception.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Persistence;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a second open withdrawal request is attempted for an order that already has one.
 * The duplicate is blocked atomically by the UNIQUE active-claim index, never by a read-then-write
 * race in PHP.
 */
final class DuplicateOpenRequestException extends \RuntimeException {}
