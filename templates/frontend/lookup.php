<?php
/**
 * Order lookup form (default landing screen for the flow page / footer link).
 *
 * A consumer who reaches the withdrawal page without a signed link enters their order number and the
 * email used on the order. On submit the plugin emails a signed withdrawal link to that order's email
 * address (never rendering the flow inline), so orders cannot be enumerated. The response is always
 * uniform, so it never reveals whether an order exists.
 *
 * Override: copy to `recesso-digitale/lookup.php` in your theme.
 *
 * @var array{action_url: string, nonce_action: string, nonce_name: string, flow_url: string, notice: string, notice_type: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$recesso_dig_notice      = isset( $args['notice'] ) ? (string) $args['notice'] : '';
$recesso_dig_notice_type = isset( $args['notice_type'] ) && in_array( $args['notice_type'], array( 'info', 'error', 'success' ), true )
	? $args['notice_type']
	: 'info';
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--lookup">
	<h2 class="wp-block-recesso-digitale-flow__title"><?php esc_html_e( 'Exercise your right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h2>

	<?php if ( '' !== $recesso_dig_notice ) : ?>
		<div
			class="wp-block-recesso-digitale-flow__notice wp-block-recesso-digitale-flow--<?php echo esc_attr( $recesso_dig_notice_type ); ?>"
			role="<?php echo 'error' === $recesso_dig_notice_type ? 'alert' : 'status'; ?>"
		>
			<p><?php echo esc_html( $recesso_dig_notice ); ?></p>
		</div>
	<?php endif; ?>

	<p class="wp-block-recesso-digitale-flow__intro">
		<?php esc_html_e( 'Enter your order number and the email address you used for the order. We will send a secure withdrawal link to that email address.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
	</p>

	<form class="wp-block-recesso-digitale-flow__form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>">
		<?php wp_nonce_field( $args['nonce_action'], $args['nonce_name'] ); ?>
		<input type="hidden" name="action" value="recesso_dig_lookup" />
		<input type="hidden" name="flow_url" value="<?php echo esc_url( $args['flow_url'] ); ?>" />

		<?php // Honeypot: an off-screen field only automated bots fill in; hidden from assistive tech. ?>
		<p class="wp-block-recesso-digitale-flow__hp" aria-hidden="true">
			<label for="recesso-dig-lookup-hp"><?php esc_html_e( 'Leave this field empty', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<input type="text" id="recesso-dig-lookup-hp" name="recesso_dig_hp" value="" tabindex="-1" autocomplete="off" />
		</p>

		<p class="wp-block-recesso-digitale-flow__field">
			<label for="recesso-dig-lookup-order"><?php esc_html_e( 'Order number', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<input
				type="text"
				id="recesso-dig-lookup-order"
				name="order_number"
				value=""
				required
				aria-required="true"
				autocomplete="off"
				inputmode="numeric"
			/>
		</p>

		<p class="wp-block-recesso-digitale-flow__field">
			<label for="recesso-dig-lookup-email"><?php esc_html_e( 'Email address used for the order', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<input
				type="email"
				id="recesso-dig-lookup-email"
				name="order_email"
				value=""
				required
				aria-required="true"
				autocomplete="email"
			/>
		</p>

		<p class="wp-block-recesso-digitale-flow__actions">
			<button type="submit" class="button wp-block-recesso-digitale-flow__submit">
				<?php esc_html_e( 'Send me the withdrawal link', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</button>
		</p>
	</form>
</div>
