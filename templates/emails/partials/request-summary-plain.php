<?php
/**
 * Shared "Content of your declaration" summary for the withdrawal emails (plain text).
 *
 * @var \Recesso54bis\Domain\WithdrawalRequest|null                  $request
 * @var array<int, array{line_id: int, name: string, quantity: int}> $items
 * @var string                                                       $order_date
 * @var bool                                                         $is_partial
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! $request instanceof \Recesso54bis\Domain\WithdrawalRequest ) {
	return;
}

$recesso_dig_scope = $is_partial
	? __( 'Partial withdrawal (specific products only)', 'erred-eu-order-withdrawal-for-woocommerce' )
	: __( 'Full withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );

$recesso_dig_products = array();
foreach ( $items as $recesso_dig_item ) {
	$recesso_dig_qty        = (int) $recesso_dig_item['quantity'];
	$recesso_dig_products[] = $recesso_dig_qty > 1
		? $recesso_dig_item['name'] . ' × ' . $recesso_dig_qty
		: (string) $recesso_dig_item['name'];
}
$recesso_dig_affected = implode( ', ', $recesso_dig_products );
if ( '' !== trim( (string) $request->withdrawal_reason ) ) {
	$recesso_dig_affected = '' !== $recesso_dig_affected
		? $recesso_dig_affected . ' — ' . $request->withdrawal_reason
		: $request->withdrawal_reason;
}

echo esc_html__( 'Content of your declaration:', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n";
echo '- ' . esc_html__( 'Name', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( $request->consumer_name ) . "\n";
echo '- ' . esc_html__( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( $request->contract_reference ) . "\n";
if ( '' !== $order_date ) {
	echo '- ' . esc_html__( 'Order date', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( $order_date ) . "\n";
}
echo '- ' . esc_html__( 'Scope', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( $recesso_dig_scope ) . "\n";
if ( '' !== $recesso_dig_affected ) {
	echo '- ' . esc_html__( 'Affected products / additional information', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( $recesso_dig_affected ) . "\n";
}
echo "\n";
echo esc_html__( 'Date and time of submission (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( (string) $request->submitted_at_gmt ) . "\n";
echo esc_html__( 'Date and time of transmission (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ) . ': ' . esc_html( (string) $request->confirmed_at_gmt ) . "\n";

if ( null !== $request->receipt_hash ) {
	echo "\n----\n";
	echo esc_html__( 'Receipt verification code (keep this email as proof of submission):', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n";
	echo esc_html( $request->receipt_hash ) . "\n";
}
