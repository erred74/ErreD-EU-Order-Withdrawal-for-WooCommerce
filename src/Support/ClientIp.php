<?php
/**
 * Client IP resolution.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the client IP from the request for rate-limiting and minimal, retention-aware logging.
 * Only the direct connection address (REMOTE_ADDR) is trusted; forwarded headers are ignored to
 * avoid spoofed throttle/audit keys.
 */
final class ClientIp {

	/**
	 * The validated client IP as a string, or an empty string when unavailable.
	 */
	public static function get(): string {
		$raw = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip  = filter_var( $raw, FILTER_VALIDATE_IP );

		return false === $ip ? '' : (string) $ip;
	}

	/**
	 * The client IP packed into its binary representation (for VARBINARY storage), or null.
	 */
	public static function packed(): ?string {
		$ip = self::get();
		if ( '' === $ip ) {
			return null;
		}

		$packed = inet_pton( $ip );

		return false === $packed ? null : $packed;
	}
}
