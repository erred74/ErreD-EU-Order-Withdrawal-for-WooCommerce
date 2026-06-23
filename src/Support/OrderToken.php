<?php
/**
 * Signed, single-purpose order token.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

use Recesso54bis\Activation\Activator;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and verifies per-order withdrawal tokens. A token is an HMAC of the order id and an expiry
 * (keyed by a secret stored in the database, never in code), so a bare order id or order key can
 * never authorise a submission. Verification is constant-time and the raw token is never stored.
 */
final class OrderToken {

	/**
	 * Issue a token for an order, valid until the given expiry.
	 *
	 * @param int $order_id  Order id.
	 * @param int $expiry_ts Expiry as a Unix timestamp (tie this to the withdrawal window end).
	 */
	public function issue( int $order_id, int $expiry_ts ): string {
		$signature = $this->sign( $order_id, $expiry_ts );

		return $expiry_ts . '.' . $this->base64url_encode( $signature );
	}

	/**
	 * Verify a token for an order in constant time.
	 *
	 * @param int    $order_id Order id the token must belong to.
	 * @param string $token    Token presented by the consumer.
	 * @param int    $now_ts   Current Unix timestamp (for expiry checking).
	 */
	public function verify( int $order_id, string $token, int $now_ts ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return false;
		}

		$expiry_ts = (int) $parts[0];
		if ( (string) $expiry_ts !== $parts[0] || $expiry_ts < $now_ts ) {
			return false;
		}

		$provided = $this->base64url_decode( $parts[1] );
		if ( '' === $provided ) {
			return false;
		}

		$expected = $this->sign( $order_id, $expiry_ts );

		return hash_equals( $expected, $provided );
	}

	/**
	 * Raw HMAC-SHA256 signature over the order id and expiry.
	 *
	 * @param int $order_id  Order id.
	 * @param int $expiry_ts Expiry timestamp.
	 */
	private function sign( int $order_id, int $expiry_ts ): string {
		return hash_hmac( 'sha256', $order_id . ':' . $expiry_ts, $this->secret(), true );
	}

	/**
	 * Fetch the signing secret, creating it defensively if a non-activation upgrade left it unset.
	 */
	private function secret(): string {
		$secret = get_option( Activator::SECRET_OPTION, '' );
		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = Hashing::random_secret();
			add_option( Activator::SECRET_OPTION, $secret, '', false );
		}

		return $secret;
	}

	/**
	 * URL-safe base64 encode without padding.
	 *
	 * @param string $data Raw bytes.
	 */
	private function base64url_encode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe token encoding, not obfuscation.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base64 decode. Returns an empty string on malformed input.
	 *
	 * @param string $data Encoded value.
	 */
	private function base64url_decode( string $data ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own URL-safe token, not obfuscation.
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );

		return false === $decoded ? '' : $decoded;
	}
}
