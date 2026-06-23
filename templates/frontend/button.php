<?php
/**
 * Entry button/link for the withdrawal flow.
 *
 * Override: copy to `recesso-digitale/button.php` in your theme.
 *
 * @var array{url: string, label: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<p class="wp-block-recesso-digitale-withdrawal-button">
	<a class="wp-block-recesso-digitale-withdrawal-button__link button" href="<?php echo esc_url( $args['url'] ); ?>">
		<?php echo esc_html( $args['label'] ); ?>
	</a>
</p>
