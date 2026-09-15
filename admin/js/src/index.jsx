/**
 * Entry point.
 *
 * Exposes window.Mediagraph, the single API every other script in the plugin
 * uses to open the picker. The host container is created on demand, so there
 * is no PHP-printed div to be missing — 1.x bailed out entirely when its root
 * element was absent, which silently disabled the picker on the site editor,
 * widgets screen, and anywhere else the markup was not printed.
 */

import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Picker from './Picker';
import Modal from './components/Modal';
import NotConnected from './components/NotConnected';
import { isConnected } from './lib/settings';

const CONTAINER_ID = 'mediagraph-picker-root';

let root = null;
let container = null;

/**
 * Get (or create) the DOM node the picker renders into.
 *
 * @return {HTMLElement} Container element.
 */
function ensureContainer() {
	if ( container && document.body.contains( container ) ) {
		return container;
	}

	container = document.getElementById( CONTAINER_ID );

	if ( ! container ) {
		container = document.createElement( 'div' );
		container.id = CONTAINER_ID;
		document.body.appendChild( container );
	}

	root = null;

	return container;
}

/**
 * Get (or create) the React root.
 *
 * @return {Object} React root.
 */
function ensureRoot() {
	const node = ensureContainer();

	if ( ! root ) {
		root = createRoot( node );
	}

	return root;
}

/**
 * Close the picker and clear the tree.
 *
 * @return {void}
 */
function close() {
	if ( root ) {
		root.render( null );
	}
}

/**
 * Open the picker.
 *
 * @param {Object}   options              Options.
 * @param {Array}    options.types        Asset kinds to allow. Empty means all.
 * @param {boolean}  options.multiple     Allow selecting several assets.
 * @param {string}   options.title        Modal heading.
 * @param {string}   options.confirmLabel Insert button label.
 * @param {Function} options.onSelect     Receives (attachments, failures).
 * @param {Function} options.onCancel     Called when the user closes without choosing.
 * @return {void}
 */
function open( options = {} ) {
	const {
		types = [],
		multiple = false,
		title = '',
		confirmLabel = '',
		onSelect = () => {},
		onCancel = () => {},
	} = options;

	const instance = ensureRoot();

	const handleClose = () => {
		close();
		onCancel();
	};

	if ( ! isConnected() ) {
		instance.render(
			<Modal onClose={ handleClose } label={ __( 'Mediagraph', 'mediagraph-assets' ) }>
				<NotConnected onClose={ handleClose } />
			</Modal>
		);

		return;
	}

	instance.render(
		<Modal onClose={ handleClose } label={ title || __( 'Mediagraph assets', 'mediagraph-assets' ) }>
			<Picker
				types={ types }
				multiple={ multiple }
				title={ title }
				confirmLabel={ confirmLabel }
				onSelect={ ( attachments, failures ) => {
					close();
					onSelect( attachments, failures );
				} }
				onClose={ handleClose }
			/>
		</Modal>
	);
}

/**
 * Mount the picker inline inside a host container, e.g. the media modal tab.
 *
 * @param {HTMLElement} node    Host element.
 * @param {Object}      options Picker options.
 * @return {Object} Handle with an unmount method.
 */
function mountInline( node, options = {} ) {
	if ( ! node ) {
		return { unmount: () => {} };
	}

	const inlineRoot = createRoot( node );

	const render = () => {
		if ( ! isConnected() ) {
			inlineRoot.render( <NotConnected onClose={ options.onCancel || ( () => {} ) } /> );

			return;
		}

		inlineRoot.render(
			<Picker
				types={ options.types || [] }
				multiple={ Boolean( options.multiple ) }
				title={ options.title || '' }
				confirmLabel={ options.confirmLabel || '' }
				onSelect={ options.onSelect || ( () => {} ) }
				onClose={ options.onCancel || ( () => {} ) }
			/>
		);
	};

	render();

	return {
		unmount: () => {
			try {
				inlineRoot.unmount();
			} catch ( error ) {
				// The host removed the node first; nothing to do.
			}
		},
	};
}

window.Mediagraph = {
	open,
	close,
	mountInline,
	isConnected,
	version: '2.0.0',
};
