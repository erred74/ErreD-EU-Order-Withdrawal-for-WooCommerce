<?php
/**
 * Generic notice (ineligible order, expired link, etc.).
 *
 * Override: copy to `recesso-digitale/message.php` in your theme.
 *
 * @var array{message: string, type: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$recesso_dig_type = in_array( $args['type'], array( 'info', 'error', 'success' ), true ) ? $args['type'] : 'info';
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--message wp-block-recesso-digitale-flow--<?php echo esc_attr( $recesso_dig_type ); ?>" role="<?php echo 'error' === $recesso_dig_type ? 'alert' : 'status'; ?>">
	<p><?php echo esc_html( $args['message'] ); ?></p>
</div>
