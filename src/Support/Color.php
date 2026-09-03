<?php
/**
 * Colour helpers for the merchant-configurable button accent.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and derives the colours used by the withdrawal flow's buttons.
 *
 * Two jobs, both deliberately kept WordPress-free so they are unit-testable in isolation:
 *
 * 1. {@see self::hex()} is the single gate a merchant-supplied colour must pass before it can reach
 *    a stylesheet. It is applied on save *and* again on read, so a value written straight to the
 *    options table — by a hand edit, a bad migration or another plugin — can never be interpolated
 *    into CSS. Anything that is not a plain hex colour becomes the empty string.
 * 2. The hover shade and the label colour are *derived*, never configured. A merchant choosing a
 *    pale accent must not be able to push the button label below the WCAG 2.2 AA contrast floor,
 *    so {@see self::readable_text()} picks whichever of white or black actually reads on it.
 */
final class Color {

	/**
	 * The dark label colour. Pure black rather than a softer near-black: around the mid-grey
	 * crossover (roughly #767676) white scores about 4.54:1 and black about 4.62:1, so both clear
	 * 4.5:1 — but a lifted black such as #1e1e1e drops to about 3.7:1 there and would leave a band
	 * of accents with no compliant label at all.
	 */
	private const DARK_TEXT = '#000000';

	/**
	 * Below this relative luminance an accent is too dark to darken further, so the hover shade is
	 * produced by lightening instead. Without it a black accent would have no hover feedback.
	 */
	private const NEAR_BLACK = 0.12;

	/**
	 * Validate and normalise a colour, the only route by which merchant input may reach a stylesheet.
	 *
	 * @param string $value Raw value, with or without the leading hash, 3 or 6 digits.
	 *
	 * @return string Normalised '#rrggbb' in lower case, or '' when the value is not a hex colour.
	 */
	public static function hex( string $value ): string {
		$candidate = ltrim( trim( $value ), '#' );

		if ( 1 !== preg_match( '/^(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $candidate ) ) {
			return '';
		}

		if ( 3 === strlen( $candidate ) ) {
			$candidate = $candidate[0] . $candidate[0] . $candidate[1] . $candidate[1] . $candidate[2] . $candidate[2];
		}

		return '#' . strtolower( $candidate );
	}

	/**
	 * WCAG relative luminance of a colour.
	 *
	 * @param string $hex Colour, in any form {@see self::hex()} accepts.
	 *
	 * @return float Luminance between 0.0 (black) and 1.0 (white); 0.0 for an invalid colour.
	 */
	public static function relative_luminance( string $hex ): float {
		$rgb = self::to_rgb( $hex );
		if ( array() === $rgb ) {
			return 0.0;
		}

		$weights = array( 0.2126, 0.7152, 0.0722 );
		$total   = 0.0;

		foreach ( $rgb as $index => $channel ) {
			$srgb   = $channel / 255;
			$linear = $srgb <= 0.04045 ? $srgb / 12.92 : ( ( $srgb + 0.055 ) / 1.055 ) ** 2.4;
			$total += $weights[ $index ] * $linear;
		}

		return $total;
	}

	/**
	 * WCAG contrast ratio between two colours, from 1.0 (identical) to 21.0 (black on white).
	 *
	 * @param string $one   First colour.
	 * @param string $other Second colour.
	 *
	 * @return float The ratio, order-independent.
	 */
	public static function contrast_ratio( string $one, string $other ): float {
		$a = self::relative_luminance( $one );
		$b = self::relative_luminance( $other );

		$lighter = max( $a, $b );
		$darker  = min( $a, $b );

		return ( $lighter + 0.05 ) / ( $darker + 0.05 );
	}

	/**
	 * The label colour that reads best on the given background.
	 *
	 * @param string $background Button background colour.
	 *
	 * @return string '#ffffff' or the dark label colour, whichever has the higher contrast.
	 */
	public static function readable_text( string $background ): string {
		$white = '#ffffff';

		return self::contrast_ratio( $background, $white ) >= self::contrast_ratio( $background, self::DARK_TEXT )
			? $white
			: self::DARK_TEXT;
	}

	/**
	 * The hover shade for an accent: darker, or lighter when the accent is already near black.
	 *
	 * @param string $accent Accent colour.
	 *
	 * @return string Normalised '#rrggbb', or '' when the accent is not a hex colour.
	 */
	public static function hover( string $accent ): string {
		$rgb = self::to_rgb( $accent );
		if ( array() === $rgb ) {
			return '';
		}

		$lighten = self::relative_luminance( $accent ) < self::NEAR_BLACK;

		$shifted = array_map(
			static function ( int $channel ) use ( $lighten ): int {
				$value = $lighten
					? $channel + ( ( 255 - $channel ) * 0.25 )
					: $channel * 0.82;

				return (int) max( 0, min( 255, (int) round( $value ) ) );
			},
			$rgb
		);

		return sprintf( '#%02x%02x%02x', $shifted[0], $shifted[1], $shifted[2] );
	}

	/**
	 * Split a colour into its three channels.
	 *
	 * @param string $hex Colour, in any form {@see self::hex()} accepts.
	 *
	 * @return int[] Three channel values 0-255, or an empty array for an invalid colour.
	 */
	private static function to_rgb( string $hex ): array {
		$normalised = self::hex( $hex );
		if ( '' === $normalised ) {
			return array();
		}

		return array(
			(int) hexdec( substr( $normalised, 1, 2 ) ),
			(int) hexdec( substr( $normalised, 3, 2 ) ),
			(int) hexdec( substr( $normalised, 5, 2 ) ),
		);
	}
}
