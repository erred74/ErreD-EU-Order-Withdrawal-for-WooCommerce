<?php
/**
 * Unit tests for the Color helper.
 *
 * The accent colour is merchant input that ends up inside a stylesheet, so the validator is a
 * security boundary and is tested with hostile values, not just malformed ones. The contrast tests
 * pin the accessibility promise: whatever accent is configured, the button label stays readable.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Support\Color;

/**
 * @covers \Recesso54bis\Support\Color
 */
final class ColorTest extends TestCase {

	public function test_hex_normalises_shorthand_and_case(): void {
		$this->assertSame( '#aabbcc', Color::hex( '#ABC' ) );
		$this->assertSame( '#c8102e', Color::hex( '#C8102E' ) );
		$this->assertSame( '#c8102e', Color::hex( '  #c8102e  ' ) );
		$this->assertSame( '#c8102e', Color::hex( 'c8102e' ) );
	}

	/**
	 * Every value here must come back as the empty string. The first few are merely malformed; the
	 * rest are what an attacker would write to break out of the CSS declaration we build.
	 *
	 * @dataProvider provide_rejected_values
	 *
	 * @param string $value The value that must be rejected.
	 */
	public function test_hex_rejects_anything_that_is_not_a_hex_colour( string $value ): void {
		$this->assertSame( '', Color::hex( $value ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function provide_rejected_values(): array {
		return array(
			'empty'             => array( '' ),
			'named colour'      => array( 'red' ),
			'five digits'       => array( '#12345' ),
			'seven digits'      => array( '#1234567' ),
			'non-hex letters'   => array( '#gggggg' ),
			'rgb function'      => array( 'rgb(1,2,3)' ),
			'css variable'      => array( 'var(--x)' ),
			'legacy expression' => array( 'expression(alert(1))' ),
			'rule break-out'    => array( '#c8102e;}body{display:none}' ),
			'attribute escape'  => array( '#c8102e" onload="' ),
			'comment escape'    => array( '#c8102e/*' ),
			'trailing newline'  => array( "#c8102e\nbody{display:none}" ),
			'url import'        => array( '#c8102e;@import url(//evil.test)' ),
		);
	}

	public function test_hover_darkens_a_light_accent_and_lightens_a_near_black_one(): void {
		$accent = '#c8102e';
		$this->assertLessThan(
			Color::relative_luminance( $accent ),
			Color::relative_luminance( Color::hover( $accent ) ),
			'A mid-tone accent should darken on hover.'
		);

		$this->assertNotSame( '#000000', Color::hover( '#000000' ) );
		$this->assertGreaterThan(
			Color::relative_luminance( '#000000' ),
			Color::relative_luminance( Color::hover( '#000000' ) ),
			'A black accent has nothing left to darken, so it must lighten instead.'
		);

		$this->assertLessThan(
			Color::relative_luminance( '#ffffff' ),
			Color::relative_luminance( Color::hover( '#ffffff' ) )
		);

		$this->assertSame( '', Color::hover( 'not a colour' ) );
	}

	/**
	 * Sweeps the whole gamut rather than a handful of samples: the merchant may pick any colour, and
	 * the label must clear WCAG AA on every one of them.
	 */
	public function test_readable_text_meets_wcag_aa_for_every_accent(): void {
		$worst       = 21.0;
		$worst_color = '';

		for ( $r = 0; $r <= 255; $r += 17 ) {
			for ( $g = 0; $g <= 255; $g += 17 ) {
				for ( $b = 0; $b <= 255; $b += 17 ) {
					$accent = sprintf( '#%02x%02x%02x', $r, $g, $b );
					$ratio  = Color::contrast_ratio( $accent, Color::readable_text( $accent ) );

					if ( $ratio < $worst ) {
						$worst       = $ratio;
						$worst_color = $accent;
					}
				}
			}
		}

		$this->assertGreaterThanOrEqual(
			4.5,
			$worst,
			sprintf( 'Worst contrast was %.3f:1 on accent %s.', $worst, $worst_color )
		);
	}

	public function test_contrast_ratio_matches_known_pairs(): void {
		$this->assertEqualsWithDelta( 21.0, Color::contrast_ratio( '#000000', '#ffffff' ), 0.01 );
		$this->assertEqualsWithDelta( 1.0, Color::contrast_ratio( '#c8102e', '#c8102e' ), 0.001 );
		$this->assertEqualsWithDelta(
			Color::contrast_ratio( '#000000', '#ffffff' ),
			Color::contrast_ratio( '#ffffff', '#000000' ),
			0.001,
			'The ratio must not depend on argument order.'
		);
		$this->assertGreaterThan( 4.5, Color::contrast_ratio( '#c8102e', '#ffffff' ) );
	}
}
