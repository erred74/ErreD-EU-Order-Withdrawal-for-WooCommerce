<?php
/**
 * Durable-medium receipt PDF template.
 *
 * Override: copy to `recesso-digitale/pdf/receipt.php` in your theme (loading is handled by the
 * builder; this default lives in the plugin).
 *
 * @var array<string, mixed> $args The canonical receipt payload.
 * @var string               $hash The SHA-256 of the canonical payload.
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$recesso_dig_item_list = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$recesso_dig_items     = implode(
	', ',
	array_map(
		static function ( $recesso_dig_item ): string {
			$recesso_dig_name = isset( $recesso_dig_item['name'] ) ? (string) $recesso_dig_item['name'] : '';
			$recesso_dig_qty  = isset( $recesso_dig_item['quantity'] ) ? (int) $recesso_dig_item['quantity'] : 1;

			return $recesso_dig_qty > 1 ? $recesso_dig_name . ' × ' . $recesso_dig_qty : $recesso_dig_name;
		},
		$recesso_dig_item_list
	)
);
?>
<?php
// Dompdf renders this HTML to a PDF server-side; styling is expressed with inline `style`
// attributes only (no embedded CSS block, no enqueued stylesheet — neither applies to a PDF).
$recesso_dig_th = 'text-align:left;padding:6px 4px;border-bottom:1px solid #ddd;vertical-align:top;width:38%;';
$recesso_dig_td = 'text-align:left;padding:6px 4px;border-bottom:1px solid #ddd;vertical-align:top;';
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8" />
</head>
<body style="font-family:'DejaVu Sans', sans-serif;font-size:12px;color:#1a1a1a;">
	<h1 style="font-size:18px;margin-bottom:0;"><?php esc_html_e( 'Acknowledgement of withdrawal receipt', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></h1>
	<p style="color:#555;font-size:11px;"><?php echo esc_html( (string) $args['merchant_name'] ); ?></p>

	<table style="width:100%;border-collapse:collapse;margin-top:16px;">
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Date and time of transmission (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['transmitted_at_gmt'] ); ?></td>
		</tr>
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Order / contract reference', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['contract_reference'] ); ?></td>
		</tr>
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Consumer', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['consumer_name'] ); ?></td>
		</tr>
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Confirmation email', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['confirmation_email'] ); ?></td>
		</tr>
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Type of withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>">
				<?php
				echo ! empty( $args['is_partial'] )
					? esc_html__( 'Partial (selected items)', 'erred-eu-order-withdrawal-for-woocommerce' )
					: esc_html__( 'Whole order', 'erred-eu-order-withdrawal-for-woocommerce' );
				?>
			</td>
		</tr>
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Items', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( $recesso_dig_items ); ?></td>
		</tr>
		<?php if ( '' !== trim( (string) ( $args['withdrawal_reason'] ?? '' ) ) ) : ?>
			<tr>
				<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Reason for withdrawal', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
				<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['withdrawal_reason'] ); ?></td>
			</tr>
		<?php endif; ?>
		<?php if ( '' !== trim( (string) ( $args['refund_iban'] ?? '' ) ) ) : ?>
			<tr>
				<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Refund IBAN', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
				<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['refund_iban'] ); ?></td>
			</tr>
		<?php endif; ?>
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Declaration submitted (GMT)', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['submitted_at_gmt'] ); ?></td>
		</tr>
		<tr>
			<th style="<?php echo esc_attr( $recesso_dig_th ); ?>"><?php esc_html_e( 'Order total', 'erred-eu-order-withdrawal-for-woocommerce' ); ?></th>
			<td style="<?php echo esc_attr( $recesso_dig_td ); ?>"><?php echo esc_html( (string) $args['order_total'] . ' ' . (string) $args['order_currency'] ); ?></td>
		</tr>
	</table>

	<p style="margin-top:24px;font-size:10px;word-break:break-all;color:#555;">
		<?php esc_html_e( 'Integrity hash (SHA-256):', 'erred-eu-order-withdrawal-for-woocommerce' ); ?>
		<?php echo esc_html( $hash ); ?>
	</p>
</body>
</html>
