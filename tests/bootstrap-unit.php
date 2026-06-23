<?php
/**
 * Bootstrap for the WordPress-free unit test suite.
 *
 * The domain and support classes guard themselves with `defined( 'ABSPATH' ) || exit;`. Defining
 * ABSPATH here lets those files load under plain PHPUnit without a full WordPress bootstrap.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';
