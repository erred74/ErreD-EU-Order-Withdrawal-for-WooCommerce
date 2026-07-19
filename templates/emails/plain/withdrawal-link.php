<?php
/**
 * Withdrawal-link (magic link) email (plain text).
 *
 * @var \WC_Order|null $order
 * @var string         $url
 * @var string         $label
 * @var string         $email_heading
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

if ( $order instanceof \WC_Order ) {
	printf(
		/* translators: %s: order number. */
		esc_html__( 'You requested a link to exercise your right of withdrawal for order %s.', 'erred-eu-order-withdrawal-for-woocommerce' ),
		esc_html( $order->get_order_number() )
	);
	echo "\n\n";
} else {
	echo esc_html__( 'You requested a link to exercise your right of withdrawal.', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n\n";
}

echo esc_html( $label ) . ":\n";
echo esc_url_raw( $url ) . "\n\n";

echo esc_html__( 'If you did not request this, you can safely ignore this email.', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n";
