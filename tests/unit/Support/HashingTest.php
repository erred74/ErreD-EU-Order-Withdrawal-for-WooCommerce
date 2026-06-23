<?php
/**
 * Unit tests for the Hashing helper.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Support\Hashing;

/**
 * @covers \Recesso54bis\Support\Hashing
 */
final class HashingTest extends TestCase {

	public function test_random_secret_is_unique_and_hex(): void {
		$a = Hashing::random_secret();
		$b = Hashing::random_secret();

		$this->assertNotSame( $a, $b );
		$this->assertSame( 64, strlen( $a ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $a );
	}

	public function test_hmac_is_deterministic_for_same_inputs(): void {
		$this->assertSame(
			Hashing::hmac( 'order:42', 'secret' ),
			Hashing::hmac( 'order:42', 'secret' )
		);
		$this->assertNotSame(
			Hashing::hmac( 'order:42', 'secret' ),
			Hashing::hmac( 'order:43', 'secret' )
		);
	}

	public function test_equals_uses_value_comparison(): void {
		$digest = Hashing::sha256( 'payload' );

		$this->assertTrue( Hashing::equals( $digest, Hashing::sha256( 'payload' ) ) );
		$this->assertFalse( Hashing::equals( $digest, Hashing::sha256( 'tampered' ) ) );
	}
}
