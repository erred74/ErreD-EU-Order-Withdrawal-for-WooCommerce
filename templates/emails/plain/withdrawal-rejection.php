<?php
/**
 * Withdrawal rejection email (plain text).
 *
 * @var \Recesso54bis\Domain\WithdrawalRequest|null                  $request
 * @var array<int, array{line_id: int, name: string, quantity: int}> $items
 * @var string                                                       $order_date
 * @var bool                                                         $is_partial
 * @var string                                                       $reason
 * @var string                                                       $intro
 * @var string                                                       $email_heading
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( $intro ) . "\n\n";

require __DIR__ . '/../partials/request-summary-plain.php';

if ( '' !== trim( $reason ) ) {
	echo "\n\n" . esc_html__( 'Reason:', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n" . esc_html( $reason ) . "\n";
}

echo "\n" . esc_html__( 'If you believe this decision is incorrect, please reply to this email so we can review it. This message does not affect any statutory right you may have.', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n";
