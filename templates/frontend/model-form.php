<?php
/**
 * Annex I.B model withdrawal form (printable).
 *
 * Override: copy to `recesso-digitale/model-form.php` in your theme.
 *
 * The wording reproduces the statutory model withdrawal form (Directive 2011/83/EU, Annex I, Part B,
 * carried over by Directive 2023/2673). It is passed through translation so the it_IT catalogue
 * carries the official Italian text.
 *
 * @var array{trader_name: string, trader_address: string, trader_phone: string, trader_email: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<details class="wp-block-recesso-digitale-model-form" open>
	<summary class="wp-block-recesso-digitale-model-form__summary">
		<?php esc_html_e( 'Model withdrawal form (Annex I.B of Directive 2011/83/EU)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
	</summary>

	<div class="wp-block-recesso-digitale-model-form__body">
		<p class="wp-block-recesso-digitale-model-form__intro">
			<?php esc_html_e( 'Complete and return this form only if you wish to withdraw from the contract.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
		</p>

		<div class="wp-block-recesso-digitale-model-form__trader">
			<p class="wp-block-recesso-digitale-model-form__trader-label"><?php esc_html_e( 'To:', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></p>
			<p class="wp-block-recesso-digitale-model-form__trader-details">
				<?php echo esc_html( $args['trader_name'] ); ?>
				<?php if ( '' !== trim( $args['trader_address'] ) ) : ?>
					<br /><?php echo esc_html( $args['trader_address'] ); ?>
				<?php endif; ?>
				<?php if ( '' !== trim( $args['trader_phone'] ) ) : ?>
					<br /><?php echo esc_html( $args['trader_phone'] ); ?>
				<?php endif; ?>
				<?php if ( '' !== trim( $args['trader_email'] ) ) : ?>
					<br />
					<?php
					/* translators: %s: trader email address. */
					printf( esc_html__( 'Email: %s', 'erred-eu-order-withdrawal-for-woocommerce' ), esc_html( $args['trader_email'] ) );
					?>
				<?php endif; ?>
			</p>
		</div>

		<p>
			<?php esc_html_e( 'I/We hereby give notice that I/we withdraw from my/our contract of sale of the following goods / for the supply of the following service:', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
		</p>

		<dl class="wp-block-recesso-digitale-model-form__fields">
			<dt class="wp-block-recesso-digitale-model-form__rule" aria-hidden="true"></dt>

			<dd><?php esc_html_e( 'Ordered on / received on:', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></dd>
			<dt class="wp-block-recesso-digitale-model-form__rule" aria-hidden="true"></dt>

			<dd><?php esc_html_e( 'Name of consumer(s):', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></dd>
			<dt class="wp-block-recesso-digitale-model-form__rule" aria-hidden="true"></dt>

			<dd><?php esc_html_e( 'Address of consumer(s):', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></dd>
			<dt class="wp-block-recesso-digitale-model-form__rule" aria-hidden="true"></dt>

			<dd><?php esc_html_e( 'Signature of consumer(s) (only if this form is notified on paper):', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></dd>
			<dt class="wp-block-recesso-digitale-model-form__rule" aria-hidden="true"></dt>

			<dd><?php esc_html_e( 'Date:', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></dd>
			<dt class="wp-block-recesso-digitale-model-form__rule" aria-hidden="true"></dt>
		</dl>

		<p class="wp-block-recesso-digitale-model-form__source">
			<?php esc_html_e( 'Source: Annex I, Part B of Directive 2011/83/EU of the European Parliament and of the Council on consumer rights.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
		</p>

		<p class="wp-block-recesso-digitale-model-form__print">
			<button type="button" class="wp-block-recesso-digitale-model-form__print-button">
				<?php esc_html_e( 'Open printable version', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</button>
		</p>
	</div>
</details>
