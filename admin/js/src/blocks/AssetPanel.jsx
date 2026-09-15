/**
 * Mediagraph metadata panel for a block that already holds an asset.
 *
 * Editors need to caption a picture after it is placed, not only while they are
 * choosing it. Caption and alt text are written straight onto the block so the
 * change is visible in the post immediately; all four fields are saved back to
 * the WordPress media item so the next post that reuses the asset inherits
 * them.
 */

import { useEffect, useState } from '@wordpress/element';
import { useDispatch, useSelect } from '@wordpress/data';
import { Button, PanelBody, Notice, TextControl, TextareaControl } from '@wordpress/components';
import { InspectorControls } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import { attachmentIdFor } from '../lib/media';

/**
 * Prefer the editable value, fall back to the rendered one.
 *
 * @param {Object|string} field REST field, which may be {raw, rendered}.
 * @return {string} Text.
 */
function plain( field ) {
	if ( ! field ) {
		return '';
	}

	if ( typeof field === 'string' ) {
		return field;
	}

	if ( typeof field.raw === 'string' ) {
		return field.raw;
	}

	// `rendered` is HTML; strip tags so it round-trips as plain text.
	if ( typeof field.rendered === 'string' ) {
		return field.rendered.replace( /<[^>]*>/g, '' ).trim();
	}

	return '';
}

/**
 * @param {Object} props            Component props.
 * @param {Object} props.blockProps Block edit props.
 * @return {JSX.Element|null} Panel.
 */
export default function AssetPanel( { blockProps } ) {
	const { name, attributes, setAttributes } = blockProps;
	const attachmentId = attachmentIdFor( name, attributes );

	const record = useSelect(
		( select ) =>
			attachmentId
				? select( 'core' ).getEntityRecord( 'postType', 'attachment', attachmentId, {
						context: 'edit',
				  } )
				: null,
		[ attachmentId ]
	);

	const { saveEntityRecord } = useDispatch( 'core' );

	const [ fields, setFields ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ status, setStatus ] = useState( null );

	// Seed the form once the attachment arrives, and re-seed when the block is
	// pointed at a different one.
	useEffect( () => {
		if ( ! record ) {
			setFields( null );

			return;
		}

		setFields( {
			title: plain( record.title ),
			caption: plain( record.caption ),
			alt: record.alt_text || '',
			credit: plain( record.description ),
		} );
		setStatus( null );
	}, [ record, attachmentId ] );

	if ( ! attachmentId || ! record || ! fields ) {
		return null;
	}

	// Only Mediagraph-sourced attachments get this panel; a plain upload is
	// already fully served by core's own controls.
	const isMediagraph = Boolean( record.meta && record.meta._mediagraph_asset_id );

	if ( ! isMediagraph ) {
		return null;
	}

	const update = ( key, value ) => {
		setFields( ( current ) => ( { ...current, [ key ]: value } ) );
		setStatus( null );

		// Mirror onto the block so the post reflects the edit right away.
		if ( key === 'caption' && 'caption' in attributes ) {
			setAttributes( { caption: value } );
		}

		if ( key === 'alt' ) {
			if ( name === 'core/media-text' ) {
				setAttributes( { mediaAlt: value } );
			} else if ( 'alt' in attributes ) {
				setAttributes( { alt: value } );
			}
		}
	};

	const save = async () => {
		setSaving( true );
		setStatus( null );

		try {
			await saveEntityRecord( 'postType', 'attachment', {
				id: attachmentId,
				title: fields.title,
				caption: fields.caption,
				description: fields.credit,
				alt_text: fields.alt,
			} );

			setStatus( { type: 'success', text: __( 'Saved to the media library.', 'mediagraph-assets' ) } );
		} catch ( error ) {
			setStatus( {
				type: 'error',
				text: error?.message || __( 'Could not save. Please try again.', 'mediagraph-assets' ),
			} );
		} finally {
			setSaving( false );
		}
	};

	return (
		<InspectorControls>
			<PanelBody title={ __( 'Mediagraph', 'mediagraph-assets' ) } initialOpen={ true }>
				<TextControl
					label={ __( 'Title', 'mediagraph-assets' ) }
					value={ fields.title }
					onChange={ ( value ) => update( 'title', value ) }
				/>

				<TextareaControl
					label={ __( 'Caption', 'mediagraph-assets' ) }
					help={ __( 'Shown beneath the asset in the post.', 'mediagraph-assets' ) }
					rows={ 3 }
					value={ fields.caption }
					onChange={ ( value ) => update( 'caption', value ) }
				/>

				<TextareaControl
					label={ __( 'Alt text', 'mediagraph-assets' ) }
					help={ __( 'Describes the asset for screen readers.', 'mediagraph-assets' ) }
					rows={ 2 }
					value={ fields.alt }
					onChange={ ( value ) => update( 'alt', value ) }
				/>

				<TextControl
					label={ __( 'Credit', 'mediagraph-assets' ) }
					value={ fields.credit }
					onChange={ ( value ) => update( 'credit', value ) }
				/>

				{ status && (
					<Notice status={ status.type } isDismissible={ false } className="mediagraph-panel__notice">
						{ status.text }
					</Notice>
				) }

				<Button variant="secondary" onClick={ save } disabled={ saving } __next40pxDefaultSize>
					{ saving
						? __( 'Saving…', 'mediagraph-assets' )
						: __( 'Save to media library', 'mediagraph-assets' ) }
				</Button>

				<p className="mediagraph-panel__help">
					{ __(
						'Caption and alt text update this post as you type. Saving also writes all four fields onto the media item, so the next post that uses this asset starts from them.',
						'mediagraph-assets'
					) }
				</p>
			</PanelBody>
		</InspectorControls>
	);
}
