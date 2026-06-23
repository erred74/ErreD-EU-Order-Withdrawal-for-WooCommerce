<?php
/**
 * Withdrawal completed screen.
 *
 * Override: copy to `recesso-digitale/done.php` in your theme.
 *
 * @var array{contract_reference: string, confirmation_email: string, items: array<int, array{line_id: int, name: string, quantity: int, thumbnail_html?: string}>} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--done">
	<h2 class="wp-block-recesso-digitale-flow__title"><?php esc_html_e( 'Withdrawal confirmed', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h2>

	<p class="wp-block-recesso-digitale-flow__success" role="status">
		<?php
		printf(
			/* translators: 1: order/contract reference, 2: email address. */
			esc_html__( 'Your withdrawal for %1$s has been recorded. An acknowledgement of receipt has been sent to %2$s on a durable medium.', 'erred-eu-order-withdrawal-for-woocommerce' ),
			'<strong>' . esc_html( $args['contract_reference'] ) . '</strong>',
			'<strong>' . esc_html( $args['confirmation_email'] ) . '</strong>'
		);
		?>
	</p>

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
