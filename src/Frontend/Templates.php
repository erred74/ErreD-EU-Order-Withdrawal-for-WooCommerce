<?php
/**
 * Frontend template loader.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Renders overridable frontend templates. A theme may override any template by placing a file at
 * `recesso-digitale/{name}.php` in the (child) theme. Variables are passed explicitly and the
 * templates are responsible for escaping their own output.
 */
final class Templates {

	/**
	 * Render a template to a string. The template receives the variables as a single `$args` array
	 * (no extract(), per the project coding standards).
	 *
	 * @param string               $name Template name without extension (e.g. 'declaration').
	 * @param array<string, mixed> $args Variables made available to the template as $args.
	 */
	public static function render( string $name, array $args = array() ): string {
		$located = self::locate( $name );
		if ( '' === $located ) {
			return '';
		}

		ob_start();
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $args is consumed by the included template.
		( static function ( string $recesso_dig_template, array $args ): void {
			include $recesso_dig_template;
		} )( $located, $args );

		return (string) ob_get_clean();
	}

	/**
	 * Locate a template, preferring a theme override.
	 *
	 * @param string $name Template name without extension.
	 */
	private static function locate( string $name ): string {
		$name = sanitize_file_name( $name );

		$theme = locate_template( array( 'recesso-digitale/' . $name . '.php' ) );
		if ( '' !== $theme ) {
			return $theme;
		}

		$plugin = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'templates/frontend/' . $name . '.php';

		return is_readable( $plugin ) ? $plugin : '';
	}
}
