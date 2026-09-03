<?php
/**
 * Integration test for the uninstall option list.
 *
 * "Delete all data on uninstall" is a promise to the merchant and a wordpress.org guideline, and the
 * list backing it is hand-maintained: an option added to the settings screen and forgotten here
 * survives a deletion that was meant to be clean. This test is what makes forgetting visible — it
 * caught two exclusion-notice options that were named wrongly and had therefore never been removed.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Recesso54bis\Activation\Migrations;
use Recesso54bis\Activation\Upgrades;
use Recesso54bis\Support\Settings;
use ReflectionClass;

final class UninstallOptionsTest extends TestCase {

	public function test_every_registered_option_is_listed_for_deletion(): void {
		$listed = $this->options_in_uninstall_script();
		$this->assertNotEmpty( $listed, 'The uninstall script must declare an option list.' );

		$missing = array();
		foreach ( $this->option_names() as $option ) {
			if ( ! in_array( $option, $listed, true ) ) {
				$missing[] = $option;
			}
		}

		$this->assertSame(
			array(),
			$missing,
			'These options are written by the plugin but never deleted on uninstall: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Every option the plugin writes: the settings, plus the two schema/version markers, which are
	 * not Settings constants but are just as much the plugin's own rows.
	 *
	 * @return string[]
	 */
	private function option_names(): array {
		$names = array( Migrations::VERSION_OPTION, Upgrades::VERSION_OPTION );

		foreach ( ( new ReflectionClass( Settings::class ) )->getConstants() as $name => $value ) {
			if ( str_starts_with( $name, 'OPT_' ) && is_string( $value ) ) {
				$names[] = $value;
			}
		}

		return $names;
	}

	/**
	 * The option names the uninstall script actually deletes. Read from the source rather than by
	 * running it: the script deletes the site's data and guards on WP_UNINSTALL_PLUGIN.
	 *
	 * @return string[]
	 */
	private function options_in_uninstall_script(): array {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		preg_match_all( "/'(recesso_dig_[a-z0-9_]+)'/", $source, $matches );

		return array_values( array_unique( $matches[1] ) );
	}
}
