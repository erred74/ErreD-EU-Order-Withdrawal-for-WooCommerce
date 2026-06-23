<?php
/**
 * Frontend insertion points.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

use Recesso54bis\Integration\EligibilityAdapter;
use Recesso54bis\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Surfaces the mandated «recedere dal contratto qui» control wherever an eligible order is shown:
 * the My Account orders list and single order view (logged-in owner — no token needed), and the
 * order-received page plus the customer order emails (signed, expiring token, so guest-checkout
 * customers can reach the flow without exposing orders to enumeration).
 */
final class Hooks {

	/**
	 * Eligibility adapter.
	 *
	 * @var EligibilityAdapter
	 */
	private EligibilityAdapter $eligibility;

	/**
	 * Flow URL builder.
	 *
	 * @var FlowUrls
	 */
	private FlowUrls $urls;

	/**
	 * Plugin settings reader.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Construct the hooks provider.
	 *
	 * @param EligibilityAdapter $eligibility Eligibility adapter.
	 * @param FlowUrls           $urls        Flow URL builder.
	 * @param Settings           $settings    Plugin settings reader.
	 */
	public function __construct( EligibilityAdapter $eligibility, FlowUrls $urls, Settings $settings ) {
		$this->eligibility = $eligibility;
		$this->urls        = $urls;
		$this->settings    = $settings;
	}

	/**
	 * Register the frontend hooks.
	 */
	public function register(): void {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_actions' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_view_button' ), 20, 1 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_button' ), 20, 4 );
	}

	/**
	 * Add a withdrawal action to the My Account orders list for eligible orders.
	 *
	 * @param array<string, array{url: string, name: string}> $actions Existing row actions.
	 * @param \WC_Order                                       $order   The order for the row.
	 *
	 * @return array<string, array{url: string, name: string}>
	 */
	public function order_actions( array $actions, \WC_Order $order ): array {
		if ( $this->is_eligible( $order ) ) {
			// WooCommerce escapes url/name when rendering these actions.
			$actions['recesso_dig'] = array(
				'url'  => $this->declaration_link( $order ),
				'name' => $this->label(),
			);
		}

		return $actions;
	}

	/**
	 * Render the withdrawal button below the single order details for eligible orders.
	 *
	 * @param \WC_Order $order The order.
	 */
	public function order_view_button( \WC_Order $order ): void {
		if ( ! $this->is_eligible( $order ) ) {
			return;
		}

		// This view is also the guest order-received page, so carry a signed token.
		$html = Templates::render(
			'button',
			array(
				'url'   => $this->declaration_link( $order, true ),
				'label' => $this->label(),
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from an escaped template.
		echo $html;
	}

	/**
	 * Add the «Right of withdrawal» block to the customer's order emails (heading, explanatory text and
	 * the mandated control), carrying a signed token so guest-checkout customers can reach the flow
	 * without exposing orders to enumeration. Rendered before the customer/address details and the
	 * footer (the `woocommerce_email_after_order_table` hook fires there). Skipped for admin copies and
	 * orders that are not eligible for withdrawal.
	 *
	 * @param \WC_Order $order         The order.
	 * @param bool      $sent_to_admin Whether the email is the admin copy.
	 * @param bool      $plain_text    Whether the email is plain text.
	 * @param \WC_Email $email         The email object (unused).
	 */
	public function email_button( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );

		if ( $sent_to_admin || ! $order instanceof \WC_Order || ! $this->is_eligible( $order ) ) {
			return;
		}

		$url   = $this->declaration_link( $order, true );
		$intro = $this->withdrawal_intro();

		if ( $plain_text ) {
			echo "\n" . esc_html__( 'Right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n";
			echo esc_html( $intro ) . "\n";
			echo esc_html( $this->label() ) . ': ' . esc_url_raw( $url ) . "\n";
			return;
		}

		$html = Templates::render(
			'email-withdrawal-block',
			array(
				'url'     => $url,
				'label'   => $this->label(),
				'heading' => __( 'Right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'intro'   => $intro,
			)
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from an escaped template.
		echo $html;
	}

	/**
	 * The explanatory sentence shown above the withdrawal control in the order emails. Reflects the
	 * configured window length and start trigger (delivery for goods vs conclusion for services).
	 */
	private function withdrawal_intro(): string {
		$days = $this->settings->window_days();

		if ( Settings::TRIGGER_CONCLUSION === $this->settings->start_trigger() ) {
			return sprintf(
				/* translators: %d: number of days in the withdrawal window. */
				_n(
					'You have %d day from the conclusion of the contract to exercise your withdrawal right without giving any reason.',
					'You have %d days from the conclusion of the contract to exercise your withdrawal right without giving any reason.',
					$days,
					'erred-eu-order-withdrawal-for-woocommerce'
				),
				$days
			);
		}

		return sprintf(
			/* translators: %d: number of days in the withdrawal window. */
			_n(
				'You have %d day from receipt to exercise your withdrawal right without giving any reason.',
				'You have %d days from receipt to exercise your withdrawal right without giving any reason.',
				$days,
				'erred-eu-order-withdrawal-for-woocommerce'
			),
			$days
		);
	}

	/**
	 * Whether the order is eligible for withdrawal.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function is_eligible( \WC_Order $order ): bool {
		return $this->eligibility->for_order( $order )->is_eligible;
	}

	/**
	 * Build the declaration entry link to the flow page for an order. With a token for guest-facing
	 * surfaces (emails, order-received page); without one for the logged-in owner (ownership
	 * authorises the flow).
	 *
	 * @param \WC_Order $order      The order.
	 * @param bool      $with_token Whether to sign the link with an expiring order token.
	 */
	private function declaration_link( \WC_Order $order, bool $with_token = false ): string {
		$expiry = $with_token ? $this->token_expiry() : null;

		return $this->urls->declaration_url( FlowPage::url(), $order->get_id(), $expiry );
	}

	/**
	 * The expiry (Unix timestamp) for a guest withdrawal-link token. Generous by default and
	 * filterable, so the link stays usable for the whole period the consumer might act.
	 */
	private function token_expiry(): int {
		/**
		 * Filter the lifetime, in seconds, of a guest withdrawal-link token.
		 *
		 * @param int $ttl Token lifetime in seconds (default 60 days).
		 */
		$ttl = (int) apply_filters( 'recesso_dig_entry_token_ttl', 60 * DAY_IN_SECONDS );

		return time() + max( DAY_IN_SECONDS, $ttl );
	}

	/**
	 * The mandated, legally-fixed Italian label for the withdrawal function.
	 */
	private function label(): string {
		return __( 'recedere dal contratto qui', 'erred-eu-order-withdrawal-for-woocommerce' );
	}
}
