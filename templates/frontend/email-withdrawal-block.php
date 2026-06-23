<?php
/**
 * Right-of-withdrawal block injected into the WooCommerce order emails.
 *
 * Override: copy to `recesso-digitale/email-withdrawal-block.php` in your theme.
 *
 * @var array{url: string, label: string, heading: string, intro: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<div class="wp-block-recesso-digitale-email-withdrawal" style="margin:24px 0;">
	<h2 style="margin:0 0 8px; font-size:18px;"><?php echo esc_html( $args['heading'] ); ?></h2>
	<p style="margin:0 0 16px;"><?php echo esc_html( $args['intro'] ); ?></p>
	<p style="margin:0;">
		<a href="<?php echo esc_url( $args['url'] ); ?>" style="display:inline-block; padding:12px 24px; background-color:#2c6cb0; color:#ffffff; text-decoration:none; border-radius:4px; font-weight:bold;">
			<?php echo esc_html( $args['label'] ); ?>
		</a>
	</p>
</div>
