<?php
/**
 * Withdrawal completed screen.
 *
 * Override: copy to `recesso-digitale/done.php` in your theme.
 *
 * @var array{contract_reference: string, confirmation_email: string, items: array<int, array{line_id: int, name: string, quantity: int, thumbnail_html?: string}>, already_confirmed?: bool, confirmed_on?: string, account_url?: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$recesso_dig_already = ! empty( $args['already_confirmed'] );
$recesso_dig_on      = (string) ( $args['confirmed_on'] ?? '' );
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--done">
	<h2 class="wp-block-recesso-digitale-flow__title">
		<?php
		echo esc_html(
			$recesso_dig_already
				? __( 'You have already sent this withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' )
				: __( 'Withdrawal confirmed', 'erred-eu-order-withdrawal-for-woocommerce' )
		);
		?>
	</h2>

	<p class="wp-block-recesso-digitale-flow__success" role="status">
		<?php
		if ( $recesso_dig_already && '' !== $recesso_dig_on ) {
			printf(
				/* translators: 1: order/contract reference, 2: date and time it was sent, 3: email address. */
				esc_html__( 'Your withdrawal for %1$s was recorded on %2$s. There is nothing else you need to do — the acknowledgement of receipt was sent to %3$s on a durable medium.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'<strong>' . esc_html( $args['contract_reference'] ) . '</strong>',
				'<strong>' . esc_html( $recesso_dig_on ) . '</strong>',
				'<strong>' . esc_html( $args['confirmation_email'] ) . '</strong>'
			);
		} elseif ( $recesso_dig_already ) {
			printf(
				/* translators: 1: order/contract reference, 2: email address. */
				esc_html__( 'Your withdrawal for %1$s has already been recorded. There is nothing else you need to do — the acknowledgement of receipt was sent to %2$s on a durable medium.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'<strong>' . esc_html( $args['contract_reference'] ) . '</strong>',
				'<strong>' . esc_html( $args['confirmation_email'] ) . '</strong>'
			);
		} else {
			printf(
				/* translators: 1: order/contract reference, 2: email address. */
				esc_html__( 'Your withdrawal for %1$s has been recorded. An acknowledgement of receipt has been sent to %2$s on a durable medium.', 'erred-eu-order-withdrawal-for-woocommerce' ),
				'<strong>' . esc_html( $args['contract_reference'] ) . '</strong>',
				'<strong>' . esc_html( $args['confirmation_email'] ) . '</strong>'
			);
		}
		?>
	</p>

	<?php if ( '' !== (string) ( $args['account_url'] ?? '' ) ) : ?>
		<p class="wp-block-recesso-digitale-flow__account">
			<a href="<?php echo esc_url( (string) $args['account_url'] ); ?>">
				<?php esc_html_e( 'Follow this request in your account', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</a>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $args['items'] ) ) : ?>
		<p class="wp-block-recesso-digitale-flow__summary-label"><?php esc_html_e( 'Products being withdrawn', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>:</p>
		<ul class="wp-block-recesso-digitale-flow__summary-items">
			<?php foreach ( $args['items'] as $recesso_dig_item ) : ?>
				<li class="wp-block-recesso-digitale-flow__summary-item">
					<?php
					echo esc_html(
						(int) $recesso_dig_item['quantity'] > 1
							? $recesso_dig_item['name'] . ' × ' . (int) $recesso_dig_item['quantity']
							: $recesso_dig_item['name']
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
