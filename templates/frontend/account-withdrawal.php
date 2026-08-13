<?php
/**
 * My Account "Right of withdrawal" tab.
 *
 * Override: copy to `recesso-digitale/account-withdrawal.php` in your theme.
 *
 * @var array{orders: array<int, array{number: string, date: string, url: string}>, label: string, flow_url: string, lookup_ok: bool} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wp-block-recesso-digitale-account">
	<p class="wp-block-recesso-digitale-account__intro">
		<?php esc_html_e( 'You may withdraw from a distance contract without giving any reason, within the legal period. Choose the order you wish to withdraw from; you will be asked to confirm, and you will receive an acknowledgement of receipt on a durable medium.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
	</p>

	<?php if ( array() === $args['orders'] ) : ?>
		<p class="wp-block-recesso-digitale-account__empty">
			<?php esc_html_e( 'None of your orders is currently eligible for withdrawal. If you believe an order should be, please contact us.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
		</p>
		<?php if ( $args['lookup_ok'] ) : ?>
			<p class="wp-block-recesso-digitale-account__lookup">
				<a href="<?php echo esc_url( $args['flow_url'] ); ?>">
					<?php esc_html_e( 'Open the withdrawal form', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
				</a>
			</p>
		<?php endif; ?>
	<?php else : ?>
		<table class="woocommerce-orders-table wp-block-recesso-digitale-account__table">
			<caption class="screen-reader-text">
				<?php esc_html_e( 'Orders eligible for withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $args['orders'] as $recesso_dig_order ) : ?>
					<tr>
						<td data-title="<?php esc_attr_e( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>">
							<?php echo esc_html( $recesso_dig_order['number'] ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Date', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>">
							<?php echo esc_html( $recesso_dig_order['date'] ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>">
							<a class="button wp-block-recesso-digitale-withdrawal-button__link" href="<?php echo esc_url( $recesso_dig_order['url'] ); ?>">
								<?php
								echo esc_html( $args['label'] );
								printf(
									'<span class="screen-reader-text"> %s</span>',
									esc_html(
										sprintf(
											/* translators: %s: order number. */
											__( 'for order %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
											$recesso_dig_order['number']
										)
									)
								);
								?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
