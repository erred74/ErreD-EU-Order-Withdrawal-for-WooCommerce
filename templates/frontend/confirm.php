<?php
/**
 * Withdrawal confirmation step (step 2, «conferma recesso»).
 *
 * Override: copy to `recesso-digitale/confirm.php` in your theme.
 *
 * @var array{action_url: string, nonce_action: string, nonce_name: string, request_id: int, token: string, contract_reference: string, consumer_name: string, confirmation_email: string, items: array<int, array{line_id: int, name: string, quantity: int, thumbnail_html?: string}>, confirm_label: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--confirm">
	<h2 class="wp-block-recesso-digitale-flow__title"><?php esc_html_e( 'Confirm your withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h2>

	<p class="wp-block-recesso-digitale-flow__intro">
		<?php esc_html_e( 'Please review your withdrawal request and confirm. After confirmation you will receive an acknowledgement of receipt on a durable medium.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
	</p>

	<ul class="wp-block-recesso-digitale-flow__summary">
		<li>
			<span class="wp-block-recesso-digitale-flow__summary-label"><?php esc_html_e( 'Order/contract', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>:</span>
			<span><?php echo esc_html( $args['contract_reference'] ); ?></span>
		</li>
		<li>
			<span class="wp-block-recesso-digitale-flow__summary-label"><?php esc_html_e( 'Name', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>:</span>
			<span><?php echo esc_html( $args['consumer_name'] ); ?></span>
		</li>
		<li>
			<span class="wp-block-recesso-digitale-flow__summary-label"><?php esc_html_e( 'Confirmation email', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>:</span>
			<span><?php echo esc_html( $args['confirmation_email'] ); ?></span>
		</li>
	</ul>

	<?php if ( ! empty( $args['items'] ) ) : ?>
		<p class="wp-block-recesso-digitale-flow__summary-label"><?php esc_html_e( 'Products being withdrawn', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>:</p>
		<ul class="wp-block-recesso-digitale-flow__summary-items">
			<?php foreach ( $args['items'] as $recesso_dig_item ) : ?>
				<li class="wp-block-recesso-digitale-flow__summary-item">
					<?php if ( ! empty( $recesso_dig_item['thumbnail_html'] ) ) : ?>
						<span class="wp-block-recesso-digitale-flow__item-thumb">
							<?php
							// Already-escaped <img> markup from WC_Product::get_image(); no external resource.
							echo $recesso_dig_item['thumbnail_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
						</span>
					<?php endif; ?>
					<span class="wp-block-recesso-digitale-flow__item-name">
						<?php
						echo esc_html(
							(int) $recesso_dig_item['quantity'] > 1
								? $recesso_dig_item['name'] . ' × ' . (int) $recesso_dig_item['quantity']
								: $recesso_dig_item['name']
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<form class="wp-block-recesso-digitale-flow__form" method="post" action="<?php echo esc_url( $args['action_url'] ); ?>">
		<?php wp_nonce_field( $args['nonce_action'], $args['nonce_name'] ); ?>
		<input type="hidden" name="action" value="recesso_dig_confirm" />
		<input type="hidden" name="request_id" value="<?php echo esc_attr( (string) $args['request_id'] ); ?>" />
		<input type="hidden" name="token" value="<?php echo esc_attr( $args['token'] ); ?>" />
		<input type="hidden" name="flow_url" value="<?php echo esc_url( $args['flow_url'] ); ?>" />

		<p class="wp-block-recesso-digitale-flow__actions">
			<button type="submit" class="button wp-block-recesso-digitale-flow__confirm">
				<?php echo esc_html( $args['confirm_label'] ); ?>
			</button>
		</p>
	</form>
</div>
