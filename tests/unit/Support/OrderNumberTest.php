<?php
/**
 * Unit tests for the order-reference helpers.
 *
 * The lookup form is the only route a guest has to the withdrawal function, and it answers
 * identically whether or not an order matched. A reference the plugin fails to recognise therefore
 * produces silence, not an error — which is why the accepted formats are pinned here explicitly,
 * and why the negative cases (two different orders must never collapse into one) matter more than
 * the positive ones.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Support\OrderNumber;

/**
 * @covers \Recesso54bis\Support\OrderNumber
 */
final class OrderNumberTest extends TestCase {

	/**
	 * @dataProvider provide_references
	 *
	 * @param string $typed    What the consumer entered.
	 * @param string $expected The normalised form.
	 */
	public function test_normalise_strips_only_formatting( string $typed, string $expected ): void {
		$this->assertSame( $expected, OrderNumber::normalise( $typed ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function provide_references(): array {
		return array(
			// The case a consumer actually hits: WooCommerce shows "#1234" in the confirmation email.
			'leading hash'       => array( '#1234', '1234' ),
			'hash and spaces'    => array( '  #1234  ', '1234' ),
			'inner space'        => array( '1 234', '1234' ),
			'non-breaking space' => array( "1\u{00a0}234", '1234' ),
			'tab and newline'    => array( "\t1234\n", '1234' ),
			'plain'              => array( '1234', '1234' ),
			'zero padded kept'   => array( '#01234', '01234' ),
			'custom scheme kept' => array( 'ORD-1234', 'ORD-1234' ),
			'letters kept'       => array( '#INV/2026/1234', 'INV/2026/1234' ),
			'empty'              => array( '', '' ),
			'hash only'          => array( '#', '' ),
		);
	}

	public function test_normalise_never_removes_digits(): void {
		foreach ( array( '#1234', '1 234', '  01234 ', "1\u{00a0}2\u{00a0}3\u{00a0}4" ) as $typed ) {
			$this->assertSame(
				preg_replace( '/\D/', '', $typed ),
				preg_replace( '/\D/', '', OrderNumber::normalise( $typed ) ),
				sprintf( 'Normalising %s changed which digits it contains.', $typed )
			);
		}
	}

	public function test_same_number_forgives_padding_in_both_directions(): void {
		$this->assertTrue( OrderNumber::same_number( '1234', '01234' ) );
		$this->assertTrue( OrderNumber::same_number( '01234', '1234' ) );
		$this->assertTrue( OrderNumber::same_number( '0001234', '1234' ) );
		$this->assertTrue( OrderNumber::same_number( '1234', '1234' ) );
	}

	/**
	 * The guard that keeps the forgiveness from becoming a security problem: padding is forgiven, but
	 * two references that are not the same number must never match.
	 */
	public function test_same_number_never_matches_different_orders(): void {
		$this->assertFalse( OrderNumber::same_number( '1234', '1235' ) );
		$this->assertFalse( OrderNumber::same_number( '1234', '12340' ) );
		$this->assertFalse( OrderNumber::same_number( '123', '1234' ) );

		// Anything that is not purely digits is left to the exact comparison, so a custom scheme is
		// never loosened: these must not match even though they look similar.
		$this->assertFalse( OrderNumber::same_number( 'ORD-1234', 'ORD-01234' ) );
		$this->assertFalse( OrderNumber::same_number( 'ORD-1234', '1234' ) );

		// An empty reference is not a number, so it matches nothing — including another empty one.
		$this->assertFalse( OrderNumber::same_number( '', '' ) );
	}
}
