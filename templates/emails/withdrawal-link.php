<?php
/**
 * Withdrawal-link (magic link) email (HTML).
 *
 * @var \WC_Order|null $order
 * @var string         $url
 * @var string         $label
 * @var string         $email_heading
 * @var \WC_Email      $email
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
	<?php
	if ( $order instanceof \WC_Order ) {
		printf(
			/* translators: %s: order number. */
			esc_html__( 'You requested a link to exercise your right of withdrawal for order %s. Use the button below to continue.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'<strong>' . esc_html( $order->get_order_number() ) . '</strong>'
		);
	} else {
		esc_html_e( 'You requested a link to exercise your right of withdrawal. Use the button below to continue.', 'erred-eu-order-withdrawal-for-woocommerce' );
	}
	?>
</p>

<p>
	<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:12px 20px;background:#2b6cb0;color:#ffffff;text-decoration:none;border-radius:4px;">
		<?php echo esc_html( $label ); ?>
	</a>
</p>

<p><?php esc_html_e( 'If you did not request this, you can safely ignore this email.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></p>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_footer', $email );
