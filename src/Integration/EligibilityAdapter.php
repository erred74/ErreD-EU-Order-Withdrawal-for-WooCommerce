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
use Recesso54bis\Domain\Eligibility\WindowBasis;
use Recesso54bis\Domain\WithdrawalRequest;
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
	 * Per-product period basis.
	 *
	 * @var WindowBasisResolver
	 */
	private WindowBasisResolver $bases;

	/**
	 * Per-request memoisation keyed by order id.
	 *
	 * @var array<int, EligibilityResult>
	 */
	private array $cache = array();

	/**
	 * Construct the adapter.
	 *
	 * @param EligibilityEngine        $engine   Decision core.
	 * @param Settings                 $settings Configuration reader.
	 * @param RequestRepository        $requests Request repository (for the duplicate-open check).
	 * @param Clock                    $clock    Time source.
	 * @param WindowBasisResolver|null $bases    Per-product period basis (built from $settings when omitted).
	 */
	public function __construct(
		EligibilityEngine $engine,
		Settings $settings,
		RequestRepository $requests,
		Clock $clock,
		?WindowBasisResolver $bases = null
	) {
		$this->engine   = $engine;
		$this->settings = $settings;
		$this->requests = $requests;
		$this->clock    = $clock;
		$this->bases    = $bases ?? new WindowBasisResolver( $settings );
	}

	/**
	 * The resolver this adapter counts periods with, for surfaces that describe the period in words
	 * and must describe the same one.
	 */
	public function window_bases(): WindowBasisResolver {
		return $this->bases;
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
	 * Drop the memoised result for an order.
	 *
	 * The memo lives for the whole PHP request, and an order can change within one: WooCommerce sends
	 * the "order completed" email in the request that completes the order, and that email renders the
	 * withdrawal block. A result computed earlier in the request — before the completion date existed —
	 * would then describe an order that no longer exists. Hooked to every order save.
	 *
	 * @param int $order_id Order id.
	 */
	public function forget( int $order_id ): void {
		unset( $this->cache[ $order_id ] );
	}

	/**
	 * Hook the memo invalidation to order saves and status changes.
	 */
	public function register(): void {
		add_action( 'woocommerce_update_order', array( $this, 'forget_order' ) );
		add_action( 'woocommerce_order_status_changed', array( $this, 'forget_order' ) );
	}

	/**
	 * Hook callback for {@see self::forget()}; WooCommerce passes the order id first.
	 *
	 * @param mixed $order_id Order id as passed by WooCommerce.
	 */
	public function forget_order( $order_id ): void {
		if ( is_numeric( $order_id ) ) {
			$this->forget( (int) $order_id );
		}
	}

	/**
	 * Evaluate eligibility as if one request's claim had already been released.
	 *
	 * For the consumer who goes back from the review step to amend a declaration they have not
	 * confirmed: their own pending request is precisely what makes the order look ineligible, so
	 * without this the "edit" link would answer "a withdrawal request is already in progress" — the
	 * consumer being told they cannot correct their own unsent form.
	 *
	 * Read-only and presentation-only: nothing is released here. The claim is genuinely given up when
	 * the amended declaration is submitted, where {@see \Recesso54bis\Integration\WithdrawalService::create_declaration()}
	 * discards the pending request and re-reserves atomically. Abandoning the edit leaves the original
	 * request, and its claim, exactly as they were.
	 *
	 * Deliberately not memoised: it answers a different question from {@see self::for_order()} and must
	 * never be served to a caller asking whether the order is plainly withdrawable.
	 *
	 * @param \WC_Order         $order  The order.
	 * @param WithdrawalRequest $ignore The request whose reservation to disregard.
	 */
	public function for_order_ignoring_claim( \WC_Order $order, WithdrawalRequest $ignore ): EligibilityResult {
		$claims = $this->requests->claimed_quantities( $order->get_id() );

		foreach ( $ignore->requested_items as $line_id => $quantity ) {
			$line_id = (int) $line_id;
			if ( isset( $claims[ $line_id ] ) ) {
				$claims[ $line_id ] = max( 0, $claims[ $line_id ] - (int) $quantity );
			}
		}

		$result = $this->engine->evaluate( $this->build_input( $order, $claims ) );

		/** This filter is documented in src/Integration/EligibilityAdapter.php */
		return apply_filters( 'recesso_dig_is_eligible', $result, $order );
	}

	/**
	 * Each line's withdrawal period, whatever the order's state.
	 *
	 * For the merchant reviewing a request: an order that has since been refunded or cancelled is no
	 * longer withdrawable, and {@see self::for_order()} then stops before computing any period, but
	 * the question "was this sent in time?" still needs one. Computed in advisory mode with no claims,
	 * so nothing about the order's current eligibility leaks into the answer. Not filtered: it reports
	 * the configured periods, not a decision.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return array<int, \Recesso54bis\Domain\Eligibility\LineWindow> Map line_id => period.
	 */
	public function line_windows( \WC_Order $order ): array {
		$input = $this->build_input( $order, array() );

		return $this->engine->evaluate(
			new EligibilityInput(
				$input->now,
				true,
				array(),
				$input->window_days,
				$input->window_start,
				$input->lines,
				false,
				$input->grace_days,
				$input->basis_starts
			)
		)->line_windows;
	}

	/**
	 * Assemble the WordPress-free engine input from a WooCommerce order.
	 *
	 * @param \WC_Order            $order  The order.
	 * @param array<int, int>|null $claims Claim map override (defaults to the live ledger).
	 */
	private function build_input( \WC_Order $order, ?array $claims = null ): EligibilityInput {
		return new EligibilityInput(
			$this->clock->now(),
			$this->is_order_withdrawable( $order ),
			$claims ?? $this->requests->claimed_quantities( $order->get_id() ),
			$this->settings->window_days(),
			$this->window_start( $order ),
			$this->build_lines( $order ),
			$this->settings->enforcement_strict(),
			$this->settings->grace_days(),
			array(
				WindowBasis::DELIVERY   => $this->delivered_at( $order ),
				WindowBasis::CONCLUSION => $this->concluded_at( $order ),
			)
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
			$this->settings->eligible_statuses(),
			$order
		);

		return in_array( $order->get_status(), (array) $statuses, true );
	}

	/**
	 * When the store-wide period opens: the order's start for the configured trigger. Lines carry
	 * their own basis since per-product overrides exist, so this only matters for an order with no
	 * product lines, and to callers reading the order-level window.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function window_start( \WC_Order $order ): ?\DateTimeImmutable {
		return Settings::TRIGGER_CONCLUSION === $this->settings->start_trigger()
			? $this->concluded_at( $order )
			: $this->delivered_at( $order );
	}

	/**
	 * The instant standing for delivery. WooCommerce has no native delivery date, so the order
	 * completion date is used as a proxy. Until the order is completed the period has not opened
	 * (fail closed → NOT_STARTED in strict mode).
	 *
	 * @param \WC_Order $order The order.
	 */
	private function delivered_at( \WC_Order $order ): ?\DateTimeImmutable {
		return $this->to_utc( $order->get_date_completed() );
	}

	/**
	 * The instant standing for the conclusion of the contract: the order creation date.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function concluded_at( \WC_Order $order ): ?\DateTimeImmutable {
		return $this->to_utc( $order->get_date_created() );
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

			$lines[] = new EligibilityLine(
				$item->get_id(),
				$configured,
				$excluded,
				max( 1, (int) $item->get_quantity() ),
				$this->bases->for_product( $product_id )
			);
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
	 * Resolve the raw art. 59 / Directive classification configured for a product, following the same
	 * specificity chain as {@see self::resolve_line()} but preserving *which* exception applies rather
	 * than reducing it to an excluded flag. Used by the checkout consents, which must know whether the
	 * cart holds digital content (art. 16(m)) or an early-started service (art. 14(4)(a)) specifically.
	 *
	 * The legacy id lists and the global default policy carry no classification, so an unclassified
	 * product resolves to an empty string.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return string One of the Settings::STATUS_* classifications, or '' when unclassified.
	 */
	public function product_status( int $product_id ): string {
		$known = static fn( $value ): bool => is_string( $value ) && in_array( $value, Settings::known_statuses(), true );

		$product_meta = Multilingual::product_meta( $product_id, Settings::META_PRODUCT_STATUS, $known );
		if ( is_string( $product_meta ) && '' !== $product_meta ) {
			return $product_meta;
		}

		foreach ( $this->category_ids( $product_id ) as $category_id ) {
			$category_meta = Multilingual::category_meta( $category_id, Settings::META_TERM_STATUS, $known );
			if ( is_string( $category_meta ) && '' !== $category_meta ) {
				return $category_meta;
			}
		}

		return '';
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
		$known = fn( $value ): bool => null !== $this->meta_status( $value );

		$product_meta = $this->meta_status( Multilingual::product_meta( $product_id, Settings::META_PRODUCT_STATUS, $known ) );
		if ( null !== $product_meta ) {
			return array( true, $product_meta );
		}

		// The legacy id lists name the product the merchant picked, which on a multilingual site is the
		// original: a translation matches through it.
		$product_ids = array_unique( array( $product_id, Multilingual::original_product( $product_id ) ) );
		if ( array() !== array_intersect( $product_ids, $excluded_products ) ) {
			return array( true, true );
		}
		if ( array() !== array_intersect( $product_ids, $allowed_products ) ) {
			return array( true, false );
		}

		// Per-category status from the category term meta, then the legacy id lists.
		foreach ( $category_ids as $category_id ) {
			$category_meta = $this->meta_status( Multilingual::category_meta( $category_id, Settings::META_TERM_STATUS, $known ) );
			if ( null !== $category_meta ) {
				return array( true, $category_meta );
			}
		}
		$category_ids = array_values(
			array_unique( array_merge( $category_ids, array_map( array( Multilingual::class, 'original_category' ), $category_ids ) ) )
		);
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
	 * Category ids for a product, defensively — the original's when a translation has none.
	 *
	 * @param int $product_id Product id.
	 *
	 * @return int[]
	 */
	private function category_ids( int $product_id ): array {
		return Multilingual::category_ids( $product_id );
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
