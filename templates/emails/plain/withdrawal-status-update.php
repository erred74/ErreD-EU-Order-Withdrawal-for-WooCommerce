<?php
/**
 * Withdrawal status-update email (plain text).
 *
 * @var \Recesso54bis\Domain\WithdrawalRequest|null                  $request
 * @var array<int, array{line_id: int, name: string, quantity: int}> $items
 * @var string                                                       $order_date
 * @var bool                                                         $is_partial
 * @var string                                                       $status_message
 * @var string                                                       $email_heading
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n\n";
echo esc_html( $status_message ) . "\n\n";

require __DIR__ . '/../partials/request-summary-plain.php';
