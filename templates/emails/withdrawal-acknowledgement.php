<?php
/**
 * Withdrawal acknowledgement email (HTML).
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
 * @var \WC_Email                                                    $email
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_header', $email_heading, $email );

// The window-start wording maps the configured start trigger (delivery vs conclusion) to
// consumer-facing text.
$recesso_dig_from = \Recesso54bis\Support\Settings::TRIGGER_CONCLUSION === $start_trigger
	? __( 'the conclusion of the contract', 'erred-eu-order-withdrawal-for-woocommerce' )
	: __( 'delivery', 'erred-eu-order-withdrawal-for-woocommerce' );
?>

<?php if ( $request instanceof \Recesso54bis\Domain\WithdrawalRequest && '' !== $request->consumer_name ) : ?>
	<p>
		<?php
		printf(
			/* translators: %s: consumer name. */
			esc_html__( 'Hi %s,', 'erred-eu-order-withdrawal-for-woocommerce' ),
			esc_html( $request->consumer_name )
		);
		?>
	</p>
<?php endif; ?>

<p>
	<?php esc_html_e( 'We have received and registered your withdrawal request. This message, with the attached receipt, is your acknowledgement of receipt on a durable medium, as required by EU consumer law. Please keep it as proof.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
</p>

<?php require __DIR__ . '/partials/request-summary.php'; ?>

<p>
	<?php
	printf(
		/* translators: 1: number of days in the withdrawal window, 2: when the window starts (e.g. delivery or the conclusion of the contract). */
		esc_html__( 'Your withdrawal is subject to the legal deadlines and conditions: the %1$d-day period (for goods, counted from %2$s; for digital content, from the start of the download) and the statutory exceptions to the right of withdrawal. We verify these before confirming, so submitting a request does not by itself guarantee its acceptance.', 'erred-eu-order-withdrawal-for-woocommerce' ),
		(int) $window_days,
		esc_html( $recesso_dig_from )
	);
	?>
</p>

<p>
	<?php esc_html_e( 'We will review the request and confirm next steps within 24 hours. If you do not hear from us, please reply to this email.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
</p>

<?php if ( '' !== $download_url ) : ?>
	<p>
		<a href="<?php echo esc_url( $download_url ); ?>">
			<?php esc_html_e( 'Download your withdrawal receipt (PDF)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
		</a>
	</p>
<?php endif; ?>

<p>
	<?php
	printf(
		/* translators: %s: store/site name. */
		esc_html__( 'Thanks, %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
		esc_html( $site_name )
	);
	?>
</p>

<?php
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core email template hook.
do_action( 'woocommerce_email_footer', $email );
