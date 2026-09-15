/**
 * The 1.x mediagraph/asset-picker block, kept editable.
 *
 * Posts published with 1.x still contain this block. It renders server side
 * exactly as before, and the editor offers a one-click conversion to a native
 * Image/Video/Audio block so the content can move onto the 2.0 path when
 * someone next touches it.
 *
 * No new blocks of this type are ever created.
 */

( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var BlockControls = wp.blockEditor.BlockControls;
	var ToolbarGroup = wp.components.ToolbarGroup;
	var ToolbarButton = wp.components.ToolbarButton;
	var Notice = wp.components.Notice;
	var __ = wp.i18n.__;

	var STRING = { type: 'string', default: '' };

	var attributes = {
		assetId: { type: 'number' },
		assetGuid: STRING,
		assetUrl: STRING,
		assetTitle: STRING,
		assetHtml: STRING,
		assetType: STRING,
		attachmentId: { type: 'number' },
		posterUrl: STRING,
		title: STRING,
		byline: STRING,
		headline: STRING,
		description: STRING,
		altText: STRING,
		extendedDescription: STRING,
		keywords: STRING,
		usageRights: STRING,
		alignment: { type: 'string', default: 'none' },
		linkTo: { type: 'string', default: 'none' },
		size: { type: 'string', default: 'full' },
		metadata: { type: 'object', default: {} },
		displaySettings: { type: 'object', default: {} },
	};

	/**
	 * Block name for an asset type.
	 *
	 * @param {string} assetType Legacy asset type.
	 * @return {string} Core block name.
	 */
	function targetBlock( assetType ) {
		if ( assetType === 'video' ) {
			return 'core/video';
		}

		if ( assetType === 'audio' ) {
			return 'core/audio';
		}

		return 'core/image';
	}

	/**
	 * Replace a legacy block with the equivalent native block.
	 *
	 * The asset is re-imported so the result is backed by a real attachment
	 * rather than a bare URL.
	 *
	 * @param {Object} props Block edit props.
	 * @return {void}
	 */
	function convert( props ) {
		var attrs = props.attributes;

		if ( ! attrs.assetId || ! window.Mediagraph ) {
			return;
		}

		window.Mediagraph.open( {
			types: attrs.assetType ? [ attrs.assetType ] : [],
			multiple: false,
			title: __( 'Convert to a native block', 'mediagraph-assets' ),
			confirmLabel: __( 'Convert', 'mediagraph-assets' ),
			onSelect: function ( attachments ) {
				var attachment = attachments && attachments[ 0 ];

				if ( ! attachment ) {
					return;
				}

				var name = targetBlock( attrs.assetType );
				var newAttributes = {
					id: attachment.id,
					caption: attrs.description || attachment.caption || '',
				};

				if ( name === 'core/image' ) {
					newAttributes.url = attachment.url;
					newAttributes.alt = attrs.altText || attachment.alt || '';
					newAttributes.align =
						attrs.alignment && attrs.alignment !== 'none' ? attrs.alignment : undefined;
				} else {
					newAttributes.src = attachment.url;
				}

				wp.data
					.dispatch( 'core/block-editor' )
					.replaceBlocks( props.clientId, wp.blocks.createBlock( name, newAttributes ) );
			},
		} );
	}

	registerBlockType( 'mediagraph/asset-picker', {
		apiVersion: 2,
		title: __( 'Mediagraph asset (legacy)', 'mediagraph-assets' ),
		description: __(
			'An asset inserted by an older version of the Mediagraph plugin. Convert it to a standard block to use the normal editing controls.',
			'mediagraph-assets'
		),
		category: 'media',
		icon: 'format-gallery',
		// Hidden from the inserter: 2.0 inserts native blocks instead.
		supports: { inserter: false, html: false },
		attributes: attributes,

		edit: function ( props ) {
			var attrs = props.attributes;
			var blockProps = useBlockProps( {
				className:
					attrs.alignment && attrs.alignment !== 'none' ? 'align' + attrs.alignment : undefined,
			} );

			return el(
				Fragment,
				null,
				el(
					BlockControls,
					{ group: 'other' },
					el(
						ToolbarGroup,
						null,
						el( ToolbarButton, {
							icon: 'update',
							label: __( 'Convert to a standard block', 'mediagraph-assets' ),
							onClick: function () {
								convert( props );
							},
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						Notice,
						{ status: 'info', isDismissible: false },
						__(
							'Legacy Mediagraph asset. Convert it to a standard block to get cropping, sizes, and the usual image controls.',
							'mediagraph-assets'
						)
					),
					el( 'div', {
						className: 'mediagraph-legacy-preview',
						dangerouslySetInnerHTML: { __html: attrs.assetHtml || '' },
					} )
				)
			);
		},

		save: function () {
			// Rendered server side by Mediagraph_Legacy_Block::render().
			return null;
		},

		deprecated: [
			{
				attributes: attributes,
				save: function ( props ) {
					if ( ! props.attributes.assetHtml ) {
						return null;
					}

					return el( 'div', {
						dangerouslySetInnerHTML: { __html: props.attributes.assetHtml },
					} );
				},
			},
		],
	} );
} )( window.wp );
