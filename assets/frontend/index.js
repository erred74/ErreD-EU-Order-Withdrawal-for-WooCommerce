/**
 * Editor registration for the Recesso Digitale withdrawal flow block.
 *
 * The block is dynamic and rendered entirely on the server (mirroring the shortcode), so there is no
 * client-side withdrawal logic. This script only registers an editor placeholder.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

function Edit() {
	const blockProps = useBlockProps();

	return (
		<p { ...blockProps }>
			{ __(
				'Recesso Digitale: the withdrawal flow («recedere dal contratto qui») renders here on the front end.',
				'erred-eu-order-withdrawal-for-woocommerce'
			) }
		</p>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save() {
		return null;
	},
} );
