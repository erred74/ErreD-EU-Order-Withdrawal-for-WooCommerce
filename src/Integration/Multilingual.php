<?php
/**
 * Multilingual (WPML / Polylang) lookups for per-product configuration.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the original of a translated product or product category, so the withdrawal configuration a
 * merchant set once — the withdrawal status and what the period is counted from — also governs its
 * translations.
 *
 * On a WPML or Polylang site a translated product is a separate post and a translated category a
 * separate term. Neither plugin copies our meta to them by default, so a translation used to fall
 * through to the store-wide defaults: its exclusion notice and checkout consent silently disappeared,
 * and a service could be counted as goods. The translation's own value still wins when it has one,
 * because an exception can genuinely differ by language (a product sold sealed in one market only).
 *
 * No multilingual plugin is required, loaded or called unless one is active: every lookup degrades
 * to "this id is its own original".
 */
final class Multilingual {

	/**
	 * The original of a translated product, or the id itself when it is not a translation.
	 *
	 * "Original" is the element the translation was made from under WPML, and the default-language
	 * version under Polylang, which keeps no source relation.
	 *
	 * @param int $product_id Product id.
	 */
	public static function original_product( int $product_id ): int {
		$original = $product_id;

		if ( has_filter( 'wpml_original_element_id' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API.
			$found    = apply_filters( 'wpml_original_element_id', null, $product_id, 'post_product' );
			$original = is_numeric( $found ) && (int) $found > 0 ? (int) $found : $product_id;
		} elseif ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
			$default  = pll_default_language();
			$found    = is_string( $default ) && '' !== $default ? pll_get_post( $product_id, $default ) : 0;
			$original = is_numeric( $found ) && (int) $found > 0 ? (int) $found : $product_id;
		}

		/**
		 * Filter the original of a translated product, for multilingual plugins other than WPML and
		 * Polylang. Return the id itself when the product is not a translation.
		 *
		 * @param int $original   The resolved original product id.
		 * @param int $product_id The product being looked up.
		 */
		return (int) apply_filters( 'recesso_dig_original_product_id', $original, $product_id );
	}

	/**
	 * The original of a translated product category, or the id itself.
	 *
	 * @param int $term_id Product category term id.
	 */
	public static function original_category( int $term_id ): int {
		$original = $term_id;

		if ( has_filter( 'wpml_original_element_id' ) ) {
			// WPML keys taxonomy elements by term_taxonomy_id, not term_id.
			$term = get_term( $term_id, 'product_cat' );
			if ( $term instanceof \WP_Term ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API.
				$found_tt = apply_filters( 'wpml_original_element_id', null, $term->term_taxonomy_id, 'tax_product_cat' );
				if ( is_numeric( $found_tt ) && (int) $found_tt > 0 ) {
					$original_term = get_term_by( 'term_taxonomy_id', (int) $found_tt, 'product_cat' );
					$original      = $original_term instanceof \WP_Term ? (int) $original_term->term_id : $term_id;
				}
			}
		} elseif ( function_exists( 'pll_get_term' ) && function_exists( 'pll_default_language' ) ) {
			$default  = pll_default_language();
			$found    = is_string( $default ) && '' !== $default ? pll_get_term( $term_id, $default ) : 0;
			$original = is_numeric( $found ) && (int) $found > 0 ? (int) $found : $term_id;
		}

		/**
		 * Filter the original of a translated product category. Return the id itself when the category
		 * is not a translation.
		 *
		 * @param int $original The resolved original term id.
		 * @param int $term_id  The category being looked up.
		 */
		return (int) apply_filters( 'recesso_dig_original_category_id', $original, $term_id );
	}

	/**
	 * A product's own meta value when it passes $accept, else its original's.
	 *
	 * @param int                   $product_id Product id.
	 * @param string                $key        Meta key.
	 * @param callable(mixed): bool $accept     Whether a raw value counts as set.
	 *
	 * @return mixed The accepted value, or '' when neither has one.
	 */
	public static function product_meta( int $product_id, string $key, callable $accept ) {
		$own = get_post_meta( $product_id, $key, true );
		if ( $accept( $own ) ) {
			return $own;
		}

		$original = self::original_product( $product_id );
		if ( $original === $product_id ) {
			return '';
		}

		$inherited = get_post_meta( $original, $key, true );

		return $accept( $inherited ) ? $inherited : '';
	}

	/**
	 * A category's own meta value when it passes $accept, else its original's.
	 *
	 * @param int                   $term_id Product category term id.
	 * @param string                $key     Meta key.
	 * @param callable(mixed): bool $accept  Whether a raw value counts as set.
	 *
	 * @return mixed The accepted value, or '' when neither has one.
	 */
	public static function category_meta( int $term_id, string $key, callable $accept ) {
		$own = get_term_meta( $term_id, $key, true );
		if ( $accept( $own ) ) {
			return $own;
		}

		$original = self::original_category( $term_id );
		if ( $original === $term_id ) {
			return '';
		}

		$inherited = get_term_meta( $original, $key, true );

		return $accept( $inherited ) ? $inherited : '';
	}

	/**
	 * A product's category ids, falling back to its original's when the translation has none — a
	 * translation saved before its categories were translated would otherwise lose every
	 * category-level setting at once.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int[]
	 */
	public static function category_ids( int $product_id ): array {
		$own = self::product_category_ids( $product_id );
		if ( array() !== $own ) {
			return $own;
		}

		$original = self::original_product( $product_id );

		return $original === $product_id ? array() : self::product_category_ids( $original );
	}

	/**
	 * Category ids of one product, defensively.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int[]
	 */
	private static function product_category_ids( int $product_id ): array {
		$product = wc_get_product( $product_id );

		return $product instanceof \WC_Product ? array_map( 'intval', $product->get_category_ids() ) : array();
	}
}
