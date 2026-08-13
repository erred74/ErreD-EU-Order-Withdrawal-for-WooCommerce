<?php
/**
 * Withdrawal rejection email (HTML).
 *
 * @var \Recesso54bis\Domain\WithdrawalRequest|null                  $request
 * @var array<int, array{line_id: int, name: string, quantity: int}> $items
 * @var string                                                       $order_date
 * @var bool                                                         $is_partial
 * @var string                                                       $reason
 * @var string                                                       $intro
 * @var string                                                       $email_heading
 * @var \WC_Email                                                    $email
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php echo esc_html( $intro ); ?>
</p>

<?php require __DIR__ . '/partials/request-summary.php'; ?>

<?php if ( '' !== trim( $reason ) ) : ?>
	<p><strong><?php esc_html_e( 'Reason:', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></strong></p>
	<p><?php echo esc_html( $reason ); ?></p>
<?php endif; ?>

<p>
	<?php esc_html_e( 'If you believe this decision is incorrect, please reply to this email so we can review it. This message does not affect any statutory right you may have.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
</p>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_footer', $email );
