<?php
/**
 * Withdrawal status-update email (HTML).
 *
 * @var \Recesso54bis\Domain\WithdrawalRequest|null                  $request
 * @var array<int, array{line_id: int, name: string, quantity: int}> $items
 * @var string                                                       $order_date
 * @var bool                                                         $is_partial
 * @var string                                                       $status_message
 * @var string                                                       $email_heading
 * @var \WC_Email                                                    $email
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo esc_html( $status_message ); ?></p>

<?php require __DIR__ . '/partials/request-summary.php'; ?>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_footer', $email );
