<?php
/**
 * Shortcode bridge for the withdrawal flow.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the server-rendered withdrawal flow as the [recesso_digitale] shortcode, so it works in
 * classic themes and anywhere shortcodes are accepted. The shortcode returns markup (never echoes),
 * matching the shortcode contract.
 */
final class Shortcode {

	/**
	 * Flow controller.
	 *
	 * @var FlowController
	 */
	private FlowController $flow;

	/**
	 * Construct the bridge.
	 *
	 * @param FlowController $flow Flow controller.
	 */
	public function __construct( FlowController $flow ) {
		$this->flow = $flow;
	}

	/**
	 * Register the shortcode.
	 */
	public function register(): void {
		add_shortcode( 'recesso_digitale', array( $this, 'render' ) );
	}

	/**
	 * Render the flow.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes (unused).
	 */
	public function render( $atts = array() ): string {
		unset( $atts );

		return $this->flow->render();
	}
}
