<?php
/**
 * Single-product "excluded from withdrawal" notice.
 *
 * Override: copy to `recesso-digitale/product-exclusion-notice.php` in your theme.
 *
 * @var array{title: string, body: string} $args
 * @package Recesso54bis
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

// The body is merchant copy sanitised on save (so it carries no markup of its own) into which the
// plugin may have expanded the {withdrawal_page_link} placeholder. Only that anchor is allowed
// through, rather than escaping the whole string and printing the link as visible markup.
$recesso_dig_allowed = array(
	'a'      => array(
		'href'   => array(),
		'title'  => array(),
		'rel'    => array(),
		'target' => array(),
	),
	'strong' => array(),
	'em'     => array(),
	'br'     => array(),
);
?>
<?php
// Reuse WooCommerce's themed info-notice styling so the notice looks native without loading extra CSS.
?>
<div class="wp-block-recesso-digitale-product-notice woocommerce-info" role="note">
	<?php if ( '' !== trim( $args['title'] ) ) : ?>
		<strong class="wp-block-recesso-digitale-product-notice__title"><?php echo esc_html( $args['title'] ); ?></strong>
	<?php endif; ?>
	<span class="wp-block-recesso-digitale-product-notice__body"><?php echo wp_kses( $args['body'], $recesso_dig_allowed ); ?></span>
</div>
