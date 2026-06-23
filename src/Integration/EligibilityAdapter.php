<?php
/**
 * WooCommerce eligibility adapter.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Integration;

use Recesso54bis\Domain\Eligibility\EligibilityEngine;
use Recesso54bis\Domain\Eligibility\EligibilityInput;
use Recesso54bis\Domain\Eligibility\EligibilityLine;
use Recesso54bis\Domain\Eligibility\EligibilityResult;
use Recesso54bis\Persistence\RequestRepository;
use Recesso54bis\Support\Clock;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bridges WooCommerce to the WordPress-free {@see EligibilityEngine}. It is the only place that
 * reads order/product data; it assembles the engine input, resolves the art. 59 configuration for
 * each line, memoises per request, and exposes the result to a filter for integrators.
 */
final class EligibilityAdapter {

	/**
	 * Decision core.
	 *
	 * @var EligibilityEngine
	 */
	private EligibilityEngine $engine;

	/**
	 * Configuration reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Request repository (for the duplicate-open check).
	 *
	 * @var RequestRepository
	 */
	private RequestRepository $requests;

	/**
	 * Time source.
	 *
	 * @var Clock
	 */
	private Clock $clock;

	/**
	 * Per-request memoisation keyed by order id.
	 *
	 * @var array<int, EligibilityResult>
	 */
	private array $cache = array();

	/**
	 * Construct the adapter.
	 *
	 * @param EligibilityEngine $engine   Decision core.
	 * @param Settings          $settings Configuration reader.
	 * @param RequestRepository $requests Request repository (for the duplicate-open check).
	 * @param Clock             $clock    Time source.
	 */
	public function __construct(
		EligibilityEngine $engine,
		Settings $settings,
		RequestRepository $requests,
		Clock $clock
	) {
		$this->engine   = $engine;
		$this->settings = $settings;
		$this->requests = $requests;
		$this->clock    = $clock;
	}

	/**
	 * Evaluate eligibility for an order, with per-request caching and a filterable result.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function for_order( \WC_Order $order ): EligibilityResult {
		$order_id = $order->get_id();

		if ( isset( $this->cache[ $order_id ] ) ) {
			return $this->cache[ $order_id ];
		}

		$result = $this->engine->evaluate( $this->build_input( $order ) );

		/**
		 * Filter the eligibility result for an order, allowing integrators to refine the decision.
		 *
		 * @param EligibilityResult $result The computed result.
		 * @param \WC_Order         $order  The order being evaluated.
		 */
		$result = apply_filters( 'recesso_dig_is_eligible', $result, $order );

		$this->cache[ $order_id ] = $result;

		return $result;
	}

	/**
	 * Assemble the WordPress-free engine input from a WooCommerce order.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function build_input( \WC_Order $order ): EligibilityInput {
		return new EligibilityInput(
			$this->clock->now(),
			$this->is_order_withdrawable( $order ),
			$this->requests->claimed_quantities( $order->get_id() ),
			$this->settings->window_days(),
			$this->window_start( $order ),
			$this->build_lines( $order ),
			$this->settings->enforcement_strict(),
			$this->settings->grace_days()
		);
	}

	/**
	 * Whether the order is in a state from which a withdrawal makes sense.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function is_order_withdrawable( \WC_Order $order ): bool {
		/**
		 * Filter the order statuses considered withdrawable.
		 *
		 * @param string[]  $statuses Order statuses (without the wc- prefix).
		 * @param \WC_Order $order    The order.
		 */
		$statuses = apply_filters(
			'recesso_dig_withdrawable_statuses',
			array( 'processing', 'completed' ),
			$order
		);

		return in_array( $order->get_status(), (array) $statuses, true );
	}

	/**
	 * Compute when the withdrawal window opens, based on the configured trigger.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function window_start( \WC_Order $order ): ?\DateTimeImmutable {
		if ( Settings::TRIGGER_CONCLUSION === $this->settings->start_trigger() ) {
			// "Conclusion" trigger: the order creation date is used as the proxy for the
			// conclusion of the contract.
			return $this->to_utc( $order->get_date_created() );
		}

		// "Delivery" trigger: WooCommerce has no native delivery date, so the order completion
		// date is used as a proxy. Until the order is completed the window has not opened
		// (fail closed → NOT_STARTED).
		return $this->to_utc( $order->get_date_completed() );
	}

	/**
	 * Build per-line eligibility inputs by resolving each product's art. 59 configuration.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return EligibilityLine[]
	 */
	private function build_lines( \WC_Order $order ): array {
		$excluded_products   = $this->settings->excluded_product_ids();
		$allowed_products    = $this->settings->allowed_product_ids();
		$excluded_categories = $this->settings->excluded_category_ids();
		$allowed_categories  = $this->settings->allowed_category_ids();
		$default_policy      = $this->settings->default_policy();

		$lines = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id   = $item->get_product_id();
			$category_ids = $this->category_ids( $product_id );

			list( $configured, $excluded ) = $this->resolve_line(
				$product_id,
				$category_ids,
				$excluded_products,
				$allowed_products,
				$excluded_categories,
				$allowed_categories,
				$default_policy
			);

			$lines[] = new EligibilityLine( $item->get_id(), $configured, $excluded, max( 1, (int) $item->get_quantity() ) );
		}

		return $lines;
	}

	/**
	 * Resolve the art. 59 (configured, excluded) flags for a single product, independent of any order.
	 * Used by the public product-page exclusion notice.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return array{configured: bool, excluded: bool}
	 */
	public function product_exclusion( int $product_id ): array {
		list( $configured, $excluded ) = $this->resolve_line(
			$product_id,
			$this->category_ids( $product_id ),
			$this->settings->excluded_product_ids(),
			$this->settings->allowed_product_ids(),
			$this->settings->excluded_category_ids(),
			$this->settings->allowed_category_ids(),
			$this->settings->default_policy()
		);

		return array(
			'configured' => $configured,
			'excluded'   => $excluded,
		);
	}

	/**
	 * Resolve a line's (configured, excluded) flags from the merchant configuration, most specific
	 * first: per-product status (product editor meta, then the legacy id list) → per-category status
	 * (category term meta, then the legacy id list) → global default policy.
	 *
	 * @param int    $product_id          Product id.
	 * @param int[]  $category_ids        Product category ids.
	 * @param int[]  $excluded_products   Excluded product ids.
	 * @param int[]  $allowed_products    Allowed product ids.
	 * @param int[]  $excluded_categories Excluded category ids.
	 * @param int[]  $allowed_categories  Allowed category ids.
	 * @param string $default_policy      Default policy when nothing matches.
	 *
	 * @return array{0: bool, 1: bool} Tuple of [configured, excluded].
	 */
	private function resolve_line(
		int $product_id,
		array $category_ids,
		array $excluded_products,
		array $allowed_products,
		array $excluded_categories,
		array $allowed_categories,
		string $default_policy
	): array {
		// Most specific: a per-product status set in the product editor.
		$product_meta = $this->meta_status( get_post_meta( $product_id, Settings::META_PRODUCT_STATUS, true ) );
		if ( null !== $product_meta ) {
			return array( true, $product_meta );
		}
		if ( in_array( $product_id, $excluded_products, true ) ) {
			return array( true, true );
		}
		if ( in_array( $product_id, $allowed_products, true ) ) {
			return array( true, false );
		}

		// Per-category status from the category term meta, then the legacy id lists.
		foreach ( $category_ids as $category_id ) {
			$category_meta = $this->meta_status( get_term_meta( $category_id, Settings::META_TERM_STATUS, true ) );
			if ( null !== $category_meta ) {
				return array( true, $category_meta );
			}
		}
		if ( array() !== array_intersect( $category_ids, $excluded_categories ) ) {
			return array( true, true );
		}
		if ( array() !== array_intersect( $category_ids, $allowed_categories ) ) {
			return array( true, false );
		}

		return match ( $default_policy ) {
			Settings::POLICY_ALLOW   => array( true, false ),
			Settings::POLICY_EXCLUDE => array( true, true ),
			default                  => array( false, false ),
		};
	}

	/**
	 * Map a stored per-product/per-category withdrawal status to an excluded flag, or null to inherit.
	 *
	 * @param mixed $value Raw meta value.
	 */
	private function meta_status( $value ): ?bool {
		$value = is_string( $value ) ? $value : '';

		if ( in_array( $value, Settings::excluding_statuses(), true ) ) {
			return true;
		}
		if ( in_array( $value, Settings::allowing_statuses(), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Category ids for a product, defensively.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int[]
	 */
	private function category_ids( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return array();
		}

		return array_map( 'intval', $product->get_category_ids() );
	}

	/**
	 * Normalise a WooCommerce date to a UTC immutable instant.
	 *
	 * @param \WC_DateTime|null $date WooCommerce date, if any.
	 */
	private function to_utc( ?\WC_DateTime $date ): ?\DateTimeImmutable {
		if ( ! $date instanceof \WC_DateTime ) {
			return null;
		}

		return ( new \DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}
}
