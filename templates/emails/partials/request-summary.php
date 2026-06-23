<?php
/**
 * Shared "Content of your declaration" summary for the withdrawal emails (HTML).
 *
 * Included by the acknowledgement, status-update and rejection email templates so they describe the
 * request — and the receipt verification code (hash) — identically.
 *
 * @var \Recesso54bis\Domain\WithdrawalRequest|null                  $request
 * @var array<int, array{line_id: int, name: string, quantity: int}> $items
 * @var string                                                       $order_date
 * @var bool                                                         $is_partial
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! $request instanceof \Recesso54bis\Domain\WithdrawalRequest ) {
	return;
}

$recesso_dig_scope = $is_partial
	? __( 'Partial withdrawal (specific products only)', 'erred-eu-order-withdrawal-for-woocommerce' )
	: __( 'Full withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' );

// "Affected products / additional information": the named lines plus any free-text note the consumer added.
$recesso_dig_products = array();
foreach ( $items as $recesso_dig_item ) {
	$recesso_dig_qty        = (int) $recesso_dig_item['quantity'];
	$recesso_dig_products[] = $recesso_dig_qty > 1
		? $recesso_dig_item['name'] . ' × ' . $recesso_dig_qty
		: (string) $recesso_dig_item['name'];
}
$recesso_dig_affected = implode( ', ', $recesso_dig_products );
if ( '' !== trim( (string) $request->withdrawal_reason ) ) {
	$recesso_dig_affected = '' !== $recesso_dig_affected
		? $recesso_dig_affected . ' — ' . $request->withdrawal_reason
		: $request->withdrawal_reason;
}
?>
<h3><?php esc_html_e( 'Content of your declaration', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h3>
<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #e5e5e5;" border="1">
	<tr>
		<th style="text-align:left;"><?php esc_html_e( 'Name', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
		<td><?php echo esc_html( $request->consumer_name ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;"><?php esc_html_e( 'Order', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
		<td><?php echo esc_html( $request->contract_reference ); ?></td>
	</tr>
	<?php if ( '' !== $order_date ) : ?>
		<tr>
			<th style="text-align:left;"><?php esc_html_e( 'Order date', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td><?php echo esc_html( $order_date ); ?></td>
		</tr>
	<?php endif; ?>
	<tr>
		<th style="text-align:left;"><?php esc_html_e( 'Scope', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
		<td><?php echo esc_html( $recesso_dig_scope ); ?></td>
	</tr>
	<?php if ( '' !== $recesso_dig_affected ) : ?>
		<tr>
			<th style="text-align:left;"><?php esc_html_e( 'Affected products / additional information', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td><?php echo esc_html( $recesso_dig_affected ); ?></td>
		</tr>
	<?php endif; ?>
	<tr>
		<th style="text-align:left;"><?php esc_html_e( 'Date and time of submission (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
		<td><?php echo esc_html( (string) $request->submitted_at_gmt ); ?></td>
	</tr>
	<tr>
		<th style="text-align:left;"><?php esc_html_e( 'Date and time of transmission (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
		<td><?php echo esc_html( (string) $request->confirmed_at_gmt ); ?></td>
	</tr>
</table>

<?php if ( null !== $request->receipt_hash ) : ?>
	<p style="margin-top:16px;">
		<?php esc_html_e( 'Receipt verification code (keep this email as proof of submission):', 'erred-eu-order-withdrawal-for-woocommerce' ); ?><br />
		<strong style="font-family:monospace; word-break:break-all;"><?php echo esc_html( $request->receipt_hash ); ?></strong>
	</p>
<?php endif; ?>
