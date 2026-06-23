<?php
/**
 * Withdrawal acknowledgement email (plain text).
 *
 * @var \Recesso54bis\Domain\WithdrawalRequest|null                  $request
 * @var array<int, array{line_id: int, name: string, quantity: int}> $items
 * @var string                                                       $order_date
 * @var bool                                                         $is_partial
 * @var string                                                       $download_url
 * @var string                                                       $site_name
 * @var int                                                          $window_days
 * @var string                                                       $start_trigger
 * @var string                                                       $email_heading
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// The window-start wording maps from the configured start trigger.
$recesso_dig_from = \Recesso54bis\Support\Settings::TRIGGER_CONCLUSION === $start_trigger
	? __( 'the conclusion of the contract', 'erred-eu-order-withdrawal-for-woocommerce' )
	: __( 'delivery', 'erred-eu-order-withdrawal-for-woocommerce' );

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";

if ( $request instanceof \Recesso54bis\Domain\WithdrawalRequest && '' !== $request->consumer_name ) {
	/* translators: %s: consumer name. */
	echo esc_html( sprintf( __( 'Hi %s,', 'erred-eu-order-withdrawal-for-woocommerce' ), $request->consumer_name ) ) . "\n\n";
}

echo esc_html__( 'We have received and registered your withdrawal request. This message, with the attached receipt, is your acknowledgement of receipt on a durable medium, as required by EU consumer law. Please keep it as proof.', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n\n";

require __DIR__ . '/../partials/request-summary-plain.php';

echo "\n\n";
echo esc_html(
	sprintf(
		/* translators: 1: number of days in the withdrawal window, 2: when the window starts (e.g. delivery or the conclusion of the contract). */
		__( 'Your withdrawal is subject to the legal deadlines and conditions: the %1$d-day period (for goods, counted from %2$s; for digital content, from the start of the download) and the statutory exceptions to the right of withdrawal. We verify these before confirming, so submitting a request does not by itself guarantee its acceptance.', 'erred-eu-order-withdrawal-for-woocommerce' ),
		(int) $window_days,
		$recesso_dig_from
	)
) . "\n\n";

echo esc_html__( 'We will review the request and confirm next steps within 24 hours. If you do not hear from us, please reply to this email.', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n\n";

if ( '' !== $download_url ) {
	echo esc_html__( 'Download your withdrawal receipt (PDF):', 'erred-eu-order-withdrawal-for-woocommerce' ) . "\n" . esc_url_raw( $download_url ) . "\n\n";
}

/* translators: %s: store/site name. */
echo esc_html( sprintf( __( 'Thanks, %s', 'erred-eu-order-withdrawal-for-woocommerce' ), $site_name ) ) . "\n";
