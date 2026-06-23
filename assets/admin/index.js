/**
 * Recesso Digitale admin entry point. Registers the data store and mounts the React app over the
 * server-rendered fallback table.
 */
import { createRoot } from '@wordpress/element';
import { registerStore } from './store';
import App from './app';

registerStore();

const node = document.getElementById( 'recesso-dig-admin-app' );
if ( node ) {
	createRoot( node ).render( <App /> );
}
