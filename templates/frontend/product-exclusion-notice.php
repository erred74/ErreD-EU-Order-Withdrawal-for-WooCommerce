<?php
/**
 * Single-product "excluded from withdrawal" notice.
 *
 * Override: copy to `recesso-digitale/product-exclusion-notice.php` in your theme.
 *
 * @var array{text: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;
?>
<?php
// Reuse WooCommerce's themed info-notice styling so the notice looks native without loading extra CSS.
?>
<div class="wp-block-recesso-digitale-product-notice woocommerce-info" role="note">
	<?php echo esc_html( $args['text'] ); ?>
</div>
