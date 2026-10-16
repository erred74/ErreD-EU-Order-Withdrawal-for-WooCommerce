<?php
/**
 * Order lookup form (default landing screen for the flow page / footer link).
 *
 * A consumer who reaches the withdrawal page without a signed link enters their order number and the
 * email used on the order. On submit the plugin emails a signed withdrawal link to that order's email
 * address (never rendering the flow inline), so orders cannot be enumerated. The response is always
 * uniform, so it never reveals whether an order exists.
 *
 * Override: copy to `recesso-digitale/lookup.php` in your theme. A copy made before 0.8.0 keeps its
 * own hardcoded wording and will not pick up the four texts configured under
 * WooCommerce → Order Withdrawal: settings → Order lookup screen; re-copy this file to get them.
 *
 * @var array{action_url: string, nonce_action: string, nonce_name: string, flow_url: string, notice: string, notice_type: string, title?: string, intro?: string, email_hint?: string, order_hint?: string, submit_label?: string, show_intro?: bool, show_email_hint?: bool, show_order_hint?: bool} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$recesso_dig_notice      = isset( $args['notice'] ) ? (string) $args['notice'] : '';
$recesso_dig_notice_type = isset( $args['notice_type'] ) && in_array( $args['notice_type'], array( 'info', 'error', 'success' ), true )
	? $args['notice_type']
	: 'info';

// The merchant may configure each of these; the bundled sentence is repeated here rather than read
// from the settings so that this template stays usable on its own, which is what makes it safe to
// override. Keep the wording byte-identical to the matching getter in src/Support/Settings.php.
$recesso_dig_title = isset( $args['title'] ) && '' !== trim( (string) $args['title'] )
	? (string) $args['title']
	: __( 'Exercise your right of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );

$recesso_dig_intro = isset( $args['intro'] ) && '' !== trim( (string) $args['intro'] )
	? (string) $args['intro']
	: __( 'Enter your order number and the email address you used for the order. We will send a secure withdrawal link to that email address.', 'erred-eu-order-withdrawal-for-woocommerce' );

$recesso_dig_email_hint = isset( $args['email_hint'] ) && '' !== trim( (string) $args['email_hint'] )
	? (string) $args['email_hint']
	: __( 'The link is sent to this address only, so it must be the one on the order.', 'erred-eu-order-withdrawal-for-woocommerce' );

$recesso_dig_order_hint = isset( $args['order_hint'] ) && '' !== trim( (string) $args['order_hint'] )
	? (string) $args['order_hint']
	: __( 'You will find it in your order confirmation email. The # is optional.', 'erred-eu-order-withdrawal-for-woocommerce' );

$recesso_dig_submit_label = isset( $args['submit_label'] ) && '' !== trim( (string) $args['submit_label'] )
	? (string) $args['submit_label']
	: __( 'Send me the withdrawal link', 'erred-eu-order-withdrawal-for-woocommerce' );

// Whether each optional line is shown at all. Separate from its text: because this template falls
// back to the bundled sentence whenever the text is empty, emptying the setting could never hide a
// line — which is what a merchant reasonably expects it to do. A hidden hint must also drop the
// aria-describedby that points at it, or assistive tech is left following a dangling reference.
$recesso_dig_show_intro      = ! isset( $args['show_intro'] ) || (bool) $args['show_intro'];
$recesso_dig_show_email_hint = ! isset( $args['show_email_hint'] ) || (bool) $args['show_email_hint'];
$recesso_dig_show_order_hint = ! isset( $args['show_order_hint'] ) || (bool) $args['show_order_hint'];
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--lookup">
	<h2 class="wp-block-recesso-digitale-flow__title"><?php echo esc_html( $recesso_dig_title ); ?></h2>

	<?php if ( '' !== $recesso_dig_notice ) : ?>
		<div
			class="wp-block-recesso-digitale-flow__notice wp-block-recesso-digitale-flow--<?php echo esc_attr( $recesso_dig_notice_type ); ?>"
			role="<?php echo 'error' === $recesso_dig_notice_type ? 'alert' : 'status'; ?>"
		>
			<p><?php echo esc_html( $recesso_dig_notice ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $recesso_dig_show_intro ) : ?>
		<p class="wp-block-recesso-digitale-flow__intro">
			<?php echo esc_html( $recesso_dig_intro ); ?>
		</p>
	<?php endif; ?>

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
				<?php if ( $recesso_dig_show_order_hint ) : ?>
					aria-describedby="recesso-dig-lookup-order-hint"
				<?php endif; ?>
			/>
			<?php if ( $recesso_dig_show_order_hint ) : ?>
				<span id="recesso-dig-lookup-order-hint" class="wp-block-recesso-digitale-flow__hint">
					<?php echo esc_html( $recesso_dig_order_hint ); ?>
				</span>
			<?php endif; ?>
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
				<?php if ( $recesso_dig_show_email_hint ) : ?>
					aria-describedby="recesso-dig-lookup-email-hint"
				<?php endif; ?>
			/>
			<?php if ( $recesso_dig_show_email_hint ) : ?>
				<span id="recesso-dig-lookup-email-hint" class="wp-block-recesso-digitale-flow__hint">
					<?php echo esc_html( $recesso_dig_email_hint ); ?>
				</span>
			<?php endif; ?>
		</p>

		<p class="wp-block-recesso-digitale-flow__actions">
			<button type="submit" class="button wp-block-recesso-digitale-flow__submit">
				<?php echo esc_html( $recesso_dig_submit_label ); ?>
			</button>
		</p>
	</form>
</div>
