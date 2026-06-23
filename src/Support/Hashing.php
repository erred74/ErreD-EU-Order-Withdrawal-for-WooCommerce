<?php
/**
 * Cryptographic helpers.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Thin, audited wrapper around the cryptographic primitives the plugin relies on. Centralising
 * these guarantees we always use a CSPRNG and constant-time comparison, never rand()/uniqid().
 */
final class Hashing {

	/**
	 * Generate a cryptographically secure random secret, hex-encoded.
	 *
	 * @param int $bytes Number of random bytes (default 32 → 256 bits).
	 *
	 * @return string Hex-encoded secret (twice the byte length in characters).
	 */
	public static function random_secret( int $bytes = 32 ): string {
		if ( $bytes < 16 ) {
			$bytes = 16;
		}

		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Keyed HMAC-SHA256 of the given message.
	 *
	 * @param string $message Message to authenticate.
	 * @param string $secret  Shared secret key.
	 */
	public static function hmac( string $message, string $secret ): string {
		return hash_hmac( 'sha256', $message, $secret );
	}

	/**
	 * SHA-256 digest of the given payload (used for tamper-evident receipt hashes).
	 *
	 * @param string $payload Canonical payload.
	 */
	public static function sha256( string $payload ): string {
		return hash( 'sha256', $payload );
	}

	/**
	 * Constant-time string comparison.
	 *
	 * @param string $known     The known/expected value.
	 * @param string $candidate The user-supplied value.
	 */
	public static function equals( string $known, string $candidate ): bool {
		return hash_equals( $known, $candidate );
	}
}
