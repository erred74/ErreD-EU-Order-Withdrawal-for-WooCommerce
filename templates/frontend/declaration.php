<?php
/**
 * Withdrawal declaration form (step 1).
 *
 * Override: copy to `recesso-digitale/declaration.php` in your theme.
 *
 * @var array{action_url: string, nonce_action: string, nonce_name: string, order_id: int, token: string, contract_reference: string, consumer_name: string, confirmation_email: string, lines: array<int, array{id: int, label: string, quantity: int, available: int, thumbnail: string, selected?: bool, selected_qty?: int}>, error: string, intro: string, declaration_text: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--declare">
	<h2 class="wp-block-recesso-digitale-flow__title"><?php esc_html_e( 'Withdrawal declaration', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h2>

	<?php if ( '' !== $args['error'] ) : ?>
		<?php
		// Focusable so the enhancement script can move focus here on re-render: role="alert" alone is
		// announced unreliably when the message is already in the markup at page load.
		?>
		<div class="wp-block-recesso-digitale-flow__error" id="recesso-dig-error" role="alert" tabindex="-1">
			<?php echo esc_html( $args['error'] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $args['intro'] ) : ?>
		<p class="wp-block-recesso-digitale-flow__intro">
			<?php echo esc_html( $args['intro'] ); ?>
		</p>
	<?php endif; ?>

	<form
		class="wp-block-recesso-digitale-flow__form"
		method="post"
		action="<?php echo esc_url( $args['action_url'] ); ?>"
		<?php echo '' !== $args['error'] ? 'aria-describedby="recesso-dig-error"' : ''; ?>
	>
		<?php wp_nonce_field( $args['nonce_action'], $args['nonce_name'] ); ?>
		<input type="hidden" name="action" value="recesso_dig_declare" />
		<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $args['order_id'] ); ?>" />
		<input type="hidden" name="token" value="<?php echo esc_attr( $args['token'] ); ?>" />
		<input type="hidden" name="flow_url" value="<?php echo esc_url( $args['flow_url'] ); ?>" />

		<?php // Honeypot: an off-screen field only automated bots fill in; hidden from assistive tech. ?>
		<p class="wp-block-recesso-digitale-flow__hp" aria-hidden="true">
			<label for="recesso-dig-hp"><?php esc_html_e( 'Leave this field empty', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<input type="text" id="recesso-dig-hp" name="recesso_dig_hp" value="" tabindex="-1" autocomplete="off" />
		</p>

		<p class="wp-block-recesso-digitale-flow__field">
			<label for="recesso-dig-name"><?php esc_html_e( 'Your name', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<input
				type="text"
				id="recesso-dig-name"
				name="consumer_name"
				value="<?php echo esc_attr( $args['consumer_name'] ); ?>"
				required
				aria-required="true"
			/>
		</p>

		<p class="wp-block-recesso-digitale-flow__field">
			<label for="recesso-dig-email"><?php esc_html_e( 'Email address for the confirmation', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<input
				type="email"
				id="recesso-dig-email"
				name="confirmation_email"
				value="<?php echo esc_attr( $args['confirmation_email'] ); ?>"
				required
				aria-required="true"
				aria-describedby="recesso-dig-email-hint"
			/>
			<span id="recesso-dig-email-hint" class="wp-block-recesso-digitale-flow__hint">
				<?php esc_html_e( 'The acknowledgement of receipt will be sent here.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</span>
		</p>

		<p class="wp-block-recesso-digitale-flow__field">
			<label for="recesso-dig-iban"><?php esc_html_e( 'IBAN for the refund (optional)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<input
				type="text"
				id="recesso-dig-iban"
				name="refund_iban"
				value=""
				autocomplete="off"
				inputmode="latin"
			/>
		</p>

		<p class="wp-block-recesso-digitale-flow__field">
			<label for="recesso-dig-reason"><?php esc_html_e( 'Reason for withdrawal (optional)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></label>
			<textarea id="recesso-dig-reason" name="withdrawal_reason" rows="2"></textarea>
		</p>

		<?php if ( ! empty( $args['lines'] ) ) : ?>
			<fieldset class="wp-block-recesso-digitale-flow__items" aria-describedby="recesso-dig-items-hint">
				<legend><?php esc_html_e( 'Items to withdraw', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></legend>
				<span id="recesso-dig-items-hint" class="wp-block-recesso-digitale-flow__hint">
					<?php esc_html_e( 'Select the products you want to withdraw. For products bought in several units you can also choose how many to withdraw.', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
				</span>
				<ul class="wp-block-recesso-digitale-flow__item-list">
					<?php foreach ( $args['lines'] as $recesso_dig_line ) : ?>
						<?php
						$recesso_dig_line_id   = (int) $recesso_dig_line['id'];
						$recesso_dig_check_id  = 'recesso-dig-line-' . $recesso_dig_line_id;
						$recesso_dig_qty_id    = 'recesso-dig-qty-' . $recesso_dig_line_id;
						$recesso_dig_available = (int) $recesso_dig_line['available'];
						$recesso_dig_has_qty   = $recesso_dig_available > 1;
						// Set when the consumer is amending a declaration they have not confirmed, so the
						// form comes back with their earlier choices rather than blank.
						$recesso_dig_checked = ! empty( $recesso_dig_line['selected'] );
						$recesso_dig_qty_val = (int) ( $recesso_dig_line['selected_qty'] ?? $recesso_dig_available );
						?>
						<li class="wp-block-recesso-digitale-flow__item">
							<input
								type="checkbox"
								id="<?php echo esc_attr( $recesso_dig_check_id ); ?>"
								class="wp-block-recesso-digitale-flow__item-check"
								name="requested_lines[]"
								value="<?php echo esc_attr( (string) $recesso_dig_line_id ); ?>"
								<?php checked( $recesso_dig_checked ); ?>
								<?php echo $recesso_dig_has_qty ? 'aria-controls="' . esc_attr( $recesso_dig_qty_id ) . '"' : ''; ?>
							/>
							<label for="<?php echo esc_attr( $recesso_dig_check_id ); ?>" class="wp-block-recesso-digitale-flow__item-label">
								<?php if ( '' !== $recesso_dig_line['thumbnail'] ) : ?>
									<span class="wp-block-recesso-digitale-flow__item-thumb">
										<?php
										// WC_Product::get_image() returns already-escaped <img> markup (built via
										// wp_get_attachment_image) and loads no external resource; escaping it would break the tag.
										echo $recesso_dig_line['thumbnail']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
										?>
									</span>
								<?php endif; ?>
								<span class="wp-block-recesso-digitale-flow__item-name"><?php echo esc_html( $recesso_dig_line['label'] ); ?></span>
							</label>
							<?php if ( $recesso_dig_has_qty ) : ?>
								<span class="wp-block-recesso-digitale-flow__item-qty">
									<label for="<?php echo esc_attr( $recesso_dig_qty_id ); ?>" class="wp-block-recesso-digitale-flow__qty-label">
										<?php
										printf(
											/* translators: %d: total units of this product available to withdraw. */
											esc_html__( 'Quantity (max %d)', 'erred-eu-order-withdrawal-for-woocommerce' ),
											(int) $recesso_dig_available
										);
										?>
									</label>
									<input
										type="number"
										id="<?php echo esc_attr( $recesso_dig_qty_id ); ?>"
										class="wp-block-recesso-digitale-flow__qty-input"
										name="requested_qty[<?php echo esc_attr( (string) $recesso_dig_line_id ); ?>]"
										min="1"
										max="<?php echo esc_attr( (string) $recesso_dig_available ); ?>"
										step="1"
										value="<?php echo esc_attr( (string) $recesso_dig_qty_val ); ?>"
										inputmode="numeric"
									/>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</fieldset>
		<?php endif; ?>

		<?php if ( '' !== $args['declaration_text'] ) : ?>
			<?php // The right of withdrawal protects consumers, not business buyers. The merchant can ask for this good-faith declaration; it is recorded in the durable-medium receipt. ?>
			<p class="wp-block-recesso-digitale-flow__field wp-block-recesso-digitale-flow__field--check">
				<?php
				// The label is the accessible name; it must not also be pointed at by aria-describedby,
				// which made screen readers read the whole declaration twice — once as the name, once as
				// a description that added nothing.
				?>
				<input
					type="checkbox"
					id="recesso-dig-consumer"
					name="consumer_declaration"
					value="1"
					required
					aria-required="true"
				/>
				<label for="recesso-dig-consumer">
					<?php echo esc_html( $args['declaration_text'] ); ?>
				</label>
			</p>
		<?php endif; ?>

		<p class="wp-block-recesso-digitale-flow__actions">
			<button type="submit" class="button wp-block-recesso-digitale-flow__submit">
				<?php esc_html_e( 'Continue', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
			</button>
		</p>
	</form>

	<?php
	/**
	 * Fires below the declaration form, inside the flow container. The Annex I.B model withdrawal
	 * form attaches here so it is shown (collapsibly) beneath the public withdrawal form.
	 */
	do_action( 'recesso_dig_after_declaration_form' );
	?>
</div>
