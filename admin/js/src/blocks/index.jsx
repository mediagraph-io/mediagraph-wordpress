/**
 * Mediagraph in the block editor.
 *
 * Three integration points:
 *
 * 1. editor.MediaPlaceholder — puts a Mediagraph button next to Upload and
 *    Media Library in every empty media block. Core routes Image, Gallery,
 *    Audio, Video, Cover, File, Media & Text, and Site Logo through this one
 *    component, so a single filter covers all of them, plus any third-party
 *    block built on the same placeholder.
 *
 * 2. editor.MediaReplaceFlow — adds Mediagraph to the Replace menu on a block
 *    that already holds media, beside Open Media Library, Upload and Reset.
 *    That menu is where people look to swap a file, so leaving it out made
 *    Mediagraph feel available only at the moment a block was created.
 *
 * 3. editor.BlockEdit — a toolbar button, which also carries the metadata panel
 *    for an asset that is already placed.
 *
 * Chosen assets come back as real WordPress attachments and are handed to the
 * block's own onSelect, so every native control keeps working.
 */

import { cloneElement, createElement as el, Fragment, isValidElement } from '@wordpress/element';
import { addFilter } from '@wordpress/hooks';
import { createHigherOrderComponent } from '@wordpress/compose';
import { BlockControls } from '@wordpress/block-editor';
import { Button, MenuItem, ToolbarButton, ToolbarGroup } from '@wordpress/components';
import { createBlock } from '@wordpress/blocks';
import { dispatch, select } from '@wordpress/data';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	TOOLBAR_BLOCKS,
	attributesForBlock,
	kindsFromAllowedTypes,
	toMediaObject,
} from '../lib/media';
import { logoUrl } from '../lib/settings';
import AssetPanel from './AssetPanel';

/**
 * The Mediagraph mark, from the SVG shipped with the plugin.
 *
 * Deliberately a <span> with a background image rather than an <img>:
 *
 * - The block editor canvas is an iframe, and the plugin's admin stylesheet is
 *   not loaded inside it, so anything relying on our CSS would be unstyled
 *   there.
 * - Core styles media blocks with rules like
 *   `.wp-block-media-text__media img { width: 100% }`, which outrank a plain
 *   class selector and blow an <img> icon up to the full column width.
 *
 * A background image is untouched by `img` rules, and the geometry is inline
 * so it holds without any stylesheet at all.
 */
const ICON = el( 'span', {
	className: 'mediagraph-icon',
	'aria-hidden': 'true',
	style: {
		display: 'block',
		flex: '0 0 auto',
		width: '20px',
		height: '20px',
		minWidth: '20px',
		borderRadius: '50%',
		backgroundImage: `url("${ logoUrl() }")`,
		backgroundSize: 'contain',
		backgroundRepeat: 'no-repeat',
		backgroundPosition: 'center',
	},
} );

/**
 * Open the picker, guarding against the bundle not being present.
 *
 * @param {Object} options Picker options.
 * @return {void}
 */
function openPicker( options ) {
	if ( ! window.Mediagraph || typeof window.Mediagraph.open !== 'function' ) {
		// eslint-disable-next-line no-console
		console.error( '[Mediagraph] The picker bundle did not load.' );

		return;
	}

	window.Mediagraph.open( options );
}

/**
 * Surface partial batch failures in the editor.
 *
 * @param {Array} failures Failure records.
 * @return {void}
 */
function reportFailures( failures ) {
	if ( ! failures || failures.length === 0 ) {
		return;
	}

	const message = sprintf(
		/* translators: %d: number of assets. */
		_n(
			'%d Mediagraph asset could not be added.',
			'%d Mediagraph assets could not be added.',
			failures.length,
			'mediagraph-assets'
		),
		failures.length
	);

	const notices = dispatch( 'core/notices' );

	if ( notices && notices.createWarningNotice ) {
		notices.createWarningNotice( `${ message } ${ failures[ 0 ].message }`, { type: 'snackbar' } );
	} else {
		// eslint-disable-next-line no-console
		console.warn( `[Mediagraph] ${ message }`, failures );
	}
}

/**
 * The Mediagraph button inside a media placeholder.
 *
 * @param {Object} props MediaPlaceholder props.
 * @return {JSX.Element} Button.
 */
function PlaceholderButton( props ) {
	const multiple = Boolean( props.multiple );
	const kinds = kindsFromAllowedTypes( props.allowedTypes );

	return el(
		Button,
		{
			className: 'mediagraph-placeholder-button',
			variant: 'secondary',
			icon: ICON,
			// Core's placeholder buttons opt into the 40px control size. Without
			// this ours renders at the legacy 36px and sits visibly short next
			// to Upload / Media Library / Insert from URL.
			__next40pxDefaultSize: true,
			onClick: () =>
				openPicker( {
					types: kinds,
					multiple,
					title: __( 'Add from Mediagraph', 'mediagraph-assets' ),
					onSelect: selectHandler( props, multiple ),
				} ),
		},
		__( 'Mediagraph', 'mediagraph-assets' )
	);
}

addFilter( 'editor.MediaPlaceholder', 'mediagraph/media-placeholder', ( MediaPlaceholder ) => {
	const WithMediagraph = ( props ) => {
		if ( ! window.Mediagraph ) {
			return el( MediaPlaceholder, props );
		}

		const button = el( PlaceholderButton, props );

		// Some blocks — core/image most importantly — pass their own
		// `placeholder` render function. MediaPlaceholder then renders whatever
		// that returns and never touches its own children, so appending
		// children would silently do nothing. Wrap the renderer instead and
		// append the button inside the element it produced.
		if ( typeof props.placeholder === 'function' ) {
			const renderPlaceholder = ( content ) => {
				const rendered = props.placeholder( content );

				if ( ! isValidElement( rendered ) ) {
					return rendered;
				}

				return cloneElement( rendered, undefined, rendered.props.children, button );
			};

			return el( MediaPlaceholder, { ...props, placeholder: renderPlaceholder } );
		}

		return el( MediaPlaceholder, props, props.children, button );
	};

	WithMediagraph.displayName = 'MediagraphMediaPlaceholder';

	return WithMediagraph;
} );

/**
 * Hand chosen assets to a core component's own onSelect.
 *
 * Shared by the placeholder and the Replace menu, which take the same shape:
 * core wants an array when the block accepts several files and a bare object
 * otherwise.
 *
 * @param {Object}  props    Component props carrying onSelect.
 * @param {boolean} multiple Whether the block takes several files.
 * @return {Function} Picker onSelect handler.
 */
function selectHandler( props, multiple ) {
	return ( attachments, failures ) => {
		if ( ! attachments || attachments.length === 0 ) {
			return;
		}

		reportFailures( failures );

		const media = attachments.map( toMediaObject );

		props.onSelect( multiple ? media : media[ 0 ] );
	};
}

addFilter( 'editor.MediaReplaceFlow', 'mediagraph/media-replace-flow', ( MediaReplaceFlow ) => {
	const WithMediagraph = ( props ) => {
		if ( ! window.Mediagraph ) {
			return el( MediaReplaceFlow, props );
		}

		const multiple = Boolean( props.multiple );
		const kinds = kindsFromAllowedTypes( props.allowedTypes );
		const label = __( 'Mediagraph', 'mediagraph-assets' );

		// Core renders these children inside its own NavigableMenu, between
		// "Use featured image" and "Reset", and supports the function form so a
		// menu item can dismiss the dropdown. That is why this reads as a
		// native option rather than something bolted underneath the menu.
		const renderChildren = ( args ) => {
			const existing =
				typeof props.children === 'function' ? props.children( args ) : props.children;

			return el(
				Fragment,
				null,
				existing,
				el(
					MenuItem,
					{
						icon: ICON,
						onClick: () => {
							// Close first: the picker opens its own modal, and
							// leaving the dropdown behind traps focus.
							args?.onClose?.();

							openPicker( {
								types: kinds,
								multiple,
								title: __( 'Replace from Mediagraph', 'mediagraph-assets' ),
								onSelect: selectHandler( props, multiple ),
							} );
						},
					},
					label
				)
			);
		};

		return el( MediaReplaceFlow, { ...props, children: renderChildren } );
	};

	WithMediagraph.displayName = 'MediagraphMediaReplaceFlow';

	return WithMediagraph;
} );

/**
 * Append images to a gallery as inner image blocks.
 *
 * @param {Object} props Block edit props.
 * @param {Array}  media Media objects.
 * @return {void}
 */
function appendToGallery( props, media ) {
	const editor = select( 'core/block-editor' );
	const block = editor ? editor.getBlock( props.clientId ) : null;
	const index = block && block.innerBlocks ? block.innerBlocks.length : 0;

	const blocks = media.map( ( item ) =>
		createBlock( 'core/image', {
			id: item.id,
			url: item.url,
			alt: item.alt,
			caption: item.caption,
		} )
	);

	dispatch( 'core/block-editor' ).insertBlocks( blocks, index, props.clientId, false );
}

/**
 * Apply chosen assets to a block that already has media.
 *
 * @param {Object} props       Block edit props.
 * @param {Array}  attachments Imported attachments.
 * @return {void}
 */
function applyToBlock( props, attachments ) {
	const media = attachments.map( toMediaObject );

	if ( media.length === 0 ) {
		return;
	}

	if ( props.name === 'core/gallery' ) {
		appendToGallery( props, media );

		return;
	}

	props.setAttributes( attributesForBlock( props.name, media[ 0 ] ) );
}

const withMediagraphToolbar = createHigherOrderComponent( ( BlockEdit ) => {
	const Wrapped = ( props ) => {
		const supported = TOOLBAR_BLOCKS[ props.name ];

		if ( ! supported || ! props.isSelected || ! window.Mediagraph ) {
			return el( BlockEdit, props );
		}

		const isGallery = props.name === 'core/gallery';
		const label = isGallery
			? __( 'Add from Mediagraph', 'mediagraph-assets' )
			: __( 'Insert from Mediagraph', 'mediagraph-assets' );

		return el(
			Fragment,
			null,
			el( BlockEdit, props ),
			// Lets an editor re-caption or re-credit an asset that is already
			// placed, without replacing it.
			el( AssetPanel, { blockProps: props } ),
			el(
				BlockControls,
				{ group: 'other' },
				el(
					ToolbarGroup,
					null,
					el( ToolbarButton, {
						icon: ICON,
						label,
						onClick: () =>
							openPicker( {
								types: supported,
								multiple: isGallery,
								title: label,
								onSelect: ( attachments, failures ) => {
									if ( ! attachments || attachments.length === 0 ) {
										return;
									}

									reportFailures( failures );
									applyToBlock( props, attachments );
								},
							} ),
					} )
				)
			)
		);
	};

	Wrapped.displayName = 'MediagraphBlockToolbar';

	return Wrapped;
}, 'withMediagraphToolbar' );

addFilter( 'editor.BlockEdit', 'mediagraph/block-toolbar', withMediagraphToolbar );
