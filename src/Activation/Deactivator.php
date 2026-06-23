<?php
/**
 * Deactivation handler.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Activation;

defined( 'ABSPATH' ) || exit;

/**
 * Runs on plugin deactivation. Performs no destructive data operations — capabilities, tables and
 * legal records persist until an explicit, opted-in uninstall. Scheduled jobs are unscheduled here
 * once the durable-medium step adds them (see PROGRESS.md, Step 6).
 */
final class Deactivator {

	/**
	 * Deactivation entry point.
	 */
	public static function deactivate(): void {
		// Intentionally a no-op in the M0 skeleton: nothing destructive on deactivation.
	}
}
