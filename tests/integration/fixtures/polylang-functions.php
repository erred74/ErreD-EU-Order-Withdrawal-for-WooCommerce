<?php
/**
 * Stand-ins for Polylang's public API, driven by a test-controlled map.
 *
 * Polylang is not installed in the test environment. These define only the three functions the
 * plugin calls, and with an empty map every product and category is its own original — exactly what
 * the plugin assumes without a multilingual plugin — so loading them changes nothing for other tests.
 *
 * Set `$GLOBALS['recesso_dig_test_pll']` to `array( 'posts' => array( translation => original ),
 * 'terms' => array( translation => original ) )` to declare translations.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- stand-ins for Polylang's own API, which must keep Polylang's names.

if ( ! function_exists( 'pll_default_language' ) ) {
	/**
	 * The default language slug.
	 */
	function pll_default_language(): string {
		return 'it';
	}
}

if ( ! function_exists( 'pll_get_post' ) ) {
	/**
	 * The default-language translation of a post, or 0 when the map declares none.
	 *
	 * @param int    $post_id Post id.
	 * @param string $lang    Language slug (ignored: the map is default-language only).
	 */
	function pll_get_post( int $post_id, string $lang = '' ): int {
		unset( $lang );

		return (int) ( $GLOBALS['recesso_dig_test_pll']['posts'][ $post_id ] ?? 0 );
	}
}

if ( ! function_exists( 'pll_get_term' ) ) {
	/**
	 * The default-language translation of a term, or 0 when the map declares none.
	 *
	 * @param int    $term_id Term id.
	 * @param string $lang    Language slug (ignored: the map is default-language only).
	 */
	function pll_get_term( int $term_id, string $lang = '' ): int {
		unset( $lang );

		return (int) ( $GLOBALS['recesso_dig_test_pll']['terms'][ $term_id ] ?? 0 );
	}
}
