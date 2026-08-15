<?php
/**
 * My Account "Right of withdrawal" tab.
 *
 * Override: copy to `recesso-digitale/account-withdrawal.php` in your theme.
 *
 * @var array{rows: array<int, array{number: string, date: string, url: string, action_label: string, has_request: bool, status: string, status_label: string, sent_on: string, scope: string, receipt_code: string, receipt_url: string, note: string}>, label: string, flow_url: string, lookup_ok: bool} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wp-block-recesso-digitale-account">
	<p class="wp-block-recesso-digitale-account__intro">
		<?php esc_html_e( 'You may withdraw from a distance contract without giving any reason, within the legal period. Choose the order you wish to withdraw from; you will be asked to confirm, and you will receive an acknowledgement of receipt on a durable medium.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
	</p>

	<?php if ( array() === $args['rows'] ) : ?>
		<p class="wp-block-recesso-digitale-account__empty">
			<?php esc_html_e( 'None of your orders is currently eligible for withdrawal, and you have not sent a withdrawal request. If you believe an order should be eligible, please contact us.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
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
				<?php esc_html_e( 'Your orders eligible for withdrawal, and the withdrawal requests you have sent', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</caption>
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Withdrawal request', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $args['rows'] as $recesso_dig_row ) : ?>
					<tr class="wp-block-recesso-digitale-account__row">
						<td data-title="<?php esc_attr_e( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>">
							<?php echo esc_html( $recesso_dig_row['number'] ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Date', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>">
							<?php echo esc_html( $recesso_dig_row['date'] ); ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Withdrawal request', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>">
							<?php if ( ! $recesso_dig_row['has_request'] ) : ?>
								<span aria-hidden="true">&mdash;</span>
								<span class="screen-reader-text"><?php esc_html_e( 'No withdrawal request sent', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></span>
							<?php else : ?>
								<span class="wp-block-recesso-digitale-account__status wp-block-recesso-digitale-account__status--<?php echo esc_attr( $recesso_dig_row['status'] ); ?>">
									<?php echo esc_html( $recesso_dig_row['status_label'] ); ?>
								</span>

								<?php if ( '' !== $recesso_dig_row['sent_on'] ) : ?>
									<span class="wp-block-recesso-digitale-account__meta">
										<?php
										printf(
											/* translators: %s: date and time the request was sent. */
											esc_html__( 'Sent on %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
											esc_html( $recesso_dig_row['sent_on'] )
										);
										?>
									</span>
								<?php endif; ?>

								<?php if ( '' !== $recesso_dig_row['scope'] ) : ?>
									<span class="wp-block-recesso-digitale-account__meta">
										<?php echo esc_html( $recesso_dig_row['scope'] ); ?>
									</span>
								<?php endif; ?>

								<?php if ( '' !== $recesso_dig_row['note'] ) : ?>
									<span class="wp-block-recesso-digitale-account__note">
										<?php
										printf(
											/* translators: %s: the note the merchant wrote when deciding on the request. */
											esc_html__( 'Our note: %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
											esc_html( $recesso_dig_row['note'] )
										);
										?>
									</span>
								<?php endif; ?>

								<?php if ( '' !== $recesso_dig_row['receipt_code'] ) : ?>
									<span class="wp-block-recesso-digitale-account__receipt">
										<?php esc_html_e( 'Receipt verification code:', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
										<code><?php echo esc_html( $recesso_dig_row['receipt_code'] ); ?></code>
									</span>
								<?php endif; ?>

								<?php if ( '' !== $recesso_dig_row['receipt_url'] ) : ?>
									<span class="wp-block-recesso-digitale-account__receipt-link">
										<a href="<?php echo esc_url( $recesso_dig_row['receipt_url'] ); ?>">
											<?php esc_html_e( 'Download the receipt (PDF)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
											<?php
											printf(
												'<span class="screen-reader-text"> %s</span>',
												esc_html(
													sprintf(
														/* translators: %s: order number. */
														__( 'for order %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
														$recesso_dig_row['number']
													)
												)
											);
											?>
										</a>
									</span>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td data-title="<?php esc_attr_e( 'Withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>">
							<?php if ( '' === $recesso_dig_row['url'] ) : ?>
								<span aria-hidden="true">&mdash;</span>
							<?php else : ?>
								<a class="button wp-block-recesso-digitale-withdrawal-button__link" href="<?php echo esc_url( $recesso_dig_row['url'] ); ?>">
									<?php
									echo esc_html( $recesso_dig_row['action_label'] );
									printf(
										'<span class="screen-reader-text"> %s</span>',
										esc_html(
											sprintf(
												/* translators: %s: order number. */
												__( 'for order %s', 'erred-eu-order-withdrawal-for-woocommerce' ),
												$recesso_dig_row['number']
											)
										)
									);
									?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
