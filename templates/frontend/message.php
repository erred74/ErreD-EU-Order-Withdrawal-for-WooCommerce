<?php
/**
 * Generic notice (ineligible order, expired link, etc.).
 *
 * Override: copy to `recesso-digitale/message.php` in your theme.
 *
 * @var array{message: string, type: string, link_url?: string, link_text?: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$recesso_dig_type = in_array( $args['type'], array( 'info', 'error', 'success' ), true ) ? $args['type'] : 'info';
$recesso_dig_url  = (string) ( $args['link_url'] ?? '' );
$recesso_dig_text = (string) ( $args['link_text'] ?? '' );
?>
<div class="wp-block-recesso-digitale-flow wp-block-recesso-digitale-flow--message wp-block-recesso-digitale-flow--<?php echo esc_attr( $recesso_dig_type ); ?>" role="<?php echo 'error' === $recesso_dig_type ? 'alert' : 'status'; ?>">
	<p><?php echo esc_html( $args['message'] ); ?></p>
	<?php if ( '' !== $recesso_dig_url && '' !== $recesso_dig_text ) : ?>
		<p class="wp-block-recesso-digitale-flow__message-action">
			<a href="<?php echo esc_url( $recesso_dig_url ); ?>"><?php echo esc_html( $recesso_dig_text ); ?></a>
		</p>
	<?php endif; ?>
</div>
