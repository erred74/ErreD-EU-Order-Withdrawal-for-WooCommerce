<?php
/**
 * Withdrawal flow block registration.
 *
 * @package Recesso54bis
 */

declare( strict_types=1 );

namespace Recesso54bis\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the dynamic `recesso-digitale/withdrawal-button` block. Rendering is server-side (the
 * same flow as the shortcode), so there is no client-side withdrawal logic to trust; the editor
 * script only provides a placeholder in the block editor.
 */
final class Block {

	/**
	 * Flow controller.
	 *
	 * @var FlowController
	 */
	private FlowController $flow;

	/**
	 * Construct the block provider.
	 *
	 * @param FlowController $flow Flow controller.
	 */
	public function __construct( FlowController $flow ) {
		$this->flow = $flow;
	}

	/**
	 * Hook block registration.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Register the block type from its compiled metadata, if the build is present.
	 */
	public function register_block(): void {
		$dir = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'build/frontend';
		if ( ! is_readable( $dir . '/block.json' ) ) {
			return;
		}

		$block_type = register_block_type(
			$dir,
			array( 'render_callback' => array( $this, 'render' ) )
		);

		// Point the editor script at the plugin's bundled JSON translations; core's automatic
		// registration only looks in the global languages directory.
		if ( $block_type instanceof \WP_Block_Type ) {
			$languages = plugin_dir_path( RECESSO_DIG_PLUGIN_FILE ) . 'languages';
			foreach ( $block_type->editor_script_handles as $handle ) {
				wp_set_script_translations( $handle, 'erred-eu-order-withdrawal-for-woocommerce', $languages );
			}
		}
	}

	/**
	 * Server render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes (unused).
	 * @param string               $content    Inner content (unused).
	 */
	public function render( array $attributes = array(), string $content = '' ): string {
		unset( $attributes, $content );

		return $this->flow->render();
	}
}
