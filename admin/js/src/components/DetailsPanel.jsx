/**
 * Asset details side panel.
 *
 * 1.x stacked a second full modal on top of the picker, which hid the grid and
 * made "go back and pick a different one" a multi-step operation. This is a
 * dismissible panel beside the grid instead.
 *
 * The editable fields are deliberately only the ones that land somewhere in
 * WordPress: title, caption, alt text, and credit. 1.x also asked for
 * "Extended Description", which had no home in WordPress and was populated
 * from the plain description because the API has no such field — it looked
 * like a real input but changed nothing.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { fetchAsset } from '../lib/api';
import { date, dimensions, duration, fileSize, kindLabel } from '../lib/format';

/**
 * One read-only row.
 *
 * @param {Object} props       Component props.
 * @param {string} props.label Field label.
 * @param {string} props.value Field value.
 * @return {JSX.Element|null} Row.
 */
function Property( { label, value } ) {
	if ( ! value ) {
		return null;
	}

	return (
		<div className="mg-panel__property">
			<dt>{ label }</dt>
			<dd>{ value }</dd>
		</div>
	);
}

/**
 * @param {Object}   props                  Component props.
 * @param {Object}   props.asset            Asset summary from the grid.
 * @param {Object}   props.annotation       Previously entered edits for this asset.
 * @param {Function} props.onAnnotate       Reports edits up, as (assetId, fields), so they
 *                                          survive the insert. Must be referentially stable:
 *                                          the fetch effect depends on it.
 * @param {Function} props.onClose          Close handler.
 * @param {Array}    props.renditions       Available rendition options.
 * @param {string}   props.rendition        Chosen download quality.
 * @param {Function} props.onRenditionChange Download-quality handler.
 * @param {boolean}  props.multiple         Whether multi-select is active.
 * @param {number}   props.selectedCount    How many assets are selected.
 * @return {JSX.Element} Panel.
 */
export default function DetailsPanel( {
	asset,
	annotation = null,
	onAnnotate = () => {},
	onClose,
	renditions = [],
	rendition = 'full',
	onRenditionChange = () => {},
	multiple = false,
	selectedCount = 0,
} ) {
	// The panel is keyed by asset ID, so it remounts when the asset changes and
	// this only ever holds the annotation for the asset on screen. Kept in a
	// ref so typing does not re-trigger the fetch effect below.
	const initialAnnotation = useRef( annotation );

	const [ detail, setDetail ] = useState( asset );
	const [ error, setError ] = useState( null );
	const [ fields, setFields ] = useState( {
		title: '',
		caption: '',
		alt_text: '',
		credit: '',
	} );

	useEffect( () => {
		const controller = new AbortController();

		setError( null );
		setDetail( asset );

		fetchAsset( asset.id, { signal: controller.signal } )
			.then( ( data ) => {
				setDetail( data );

				const prefilled = initialAnnotation.current || {
					title: data.title || data.headline || '',
					caption: data.description || '',
					alt_text: data.alt_text || data.title || '',
					credit: data.credit_line || data.creator || '',
				};

				setFields( prefilled );

				// Report the prefilled values up even though the user has not
				// typed anything. Insert happens from the picker's footer, which
				// reads the stored annotation — without this, opening an asset
				// and inserting it straight away would send no metadata at all,
				// and the Mediagraph title, caption and credit shown here would
				// silently fail to reach the attachment.
				onAnnotate( asset.id, prefilled );
			} )
			.catch( ( err ) => {
				if ( err.name !== 'AbortedError' ) {
					setError( err );
				}
			} );

		return () => controller.abort();
	}, [ asset, onAnnotate ] );

	const update = ( key, value ) => {
		setFields( ( current ) => {
			const next = { ...current, [ key ]: value };
			onAnnotate( asset.id, next );

			return next;
		} );
	};

	const restricted = ! detail.downloadable;
	const availableRenditions = detail.renditions?.length
		? renditions.filter( ( option ) => detail.renditions.includes( option.value ) )
		: renditions;

	// The choice lives in the picker so it survives moving between assets and
	// applies to the whole batch. An asset that does not offer the chosen size
	// falls back for display only; the server maps any request down to what the
	// account may actually download.
	const selectedRendition = availableRenditions.some( ( option ) => option.value === rendition )
		? rendition
		: availableRenditions[ 0 ]?.value || rendition;

	return (
		<aside className="mg-panel" aria-label={ __( 'Asset details', 'mediagraph-assets' ) }>
			<header className="mg-panel__header">
				<h3 className="mg-panel__title" title={ detail.filename }>
					{ detail.filename || __( 'Asset', 'mediagraph-assets' ) }
				</h3>
				<button
					type="button"
					className="mg-panel__close"
					onClick={ onClose }
					aria-label={ __( 'Close details', 'mediagraph-assets' ) }
				>
					<span aria-hidden="true">×</span>
				</button>
			</header>

			<div className="mg-panel__scroll">
				<div className="mg-panel__preview">
					{ detail.kind === 'video' && detail.preview_url ? (
						<video src={ detail.preview_url } poster={ detail.poster_url } controls />
					) : detail.kind === 'audio' && detail.preview_url ? (
						<audio src={ detail.preview_url } controls />
					) : detail.preview_url || detail.thumb_url ? (
						<img src={ detail.preview_url || detail.thumb_url } alt="" />
					) : (
						<div className="mg-panel__preview-empty">
							<span className="dashicons dashicons-media-document" aria-hidden="true" />
						</div>
					) }
				</div>

				{ error && <p className="mg-panel__error">{ error.message }</p> }

				{ /* Just the physical facts about the file, so the identity of
				     what you are looking at is settled before anything else. */ }
				<dl className="mg-panel__properties mg-panel__properties--brief">
					<Property label={ __( 'Type', 'mediagraph-assets' ) } value={ kindLabel( detail.kind ) } />
					<Property
						label={ __( 'Dimensions', 'mediagraph-assets' ) }
						value={ dimensions( detail.width, detail.height ) }
					/>
					<Property label={ __( 'Size', 'mediagraph-assets' ) } value={ fileSize( detail.file_size ) } />
					<Property label={ __( 'Duration', 'mediagraph-assets' ) } value={ duration( detail.duration ) } />
				</dl>

				<div className="mg-panel__section mg-panel__section--edit">
					<h4>{ __( 'How it appears in WordPress', 'mediagraph-assets' ) }</h4>
					<p className="mg-panel__help">
						{ __(
							'These are saved onto the WordPress media item. Editing them here does not change anything in Mediagraph.',
							'mediagraph-assets'
						) }
					</p>

					<label className="mg-field">
						<span>{ __( 'Title', 'mediagraph-assets' ) }</span>
						<input
							type="text"
							value={ fields.title }
							onChange={ ( event ) => update( 'title', event.target.value ) }
						/>
					</label>

					<label className="mg-field">
						<span>{ __( 'Caption', 'mediagraph-assets' ) }</span>
						<textarea
							rows="2"
							value={ fields.caption }
							onChange={ ( event ) => update( 'caption', event.target.value ) }
						/>
					</label>

					<label className="mg-field">
						<span>{ __( 'Alt text', 'mediagraph-assets' ) }</span>
						<textarea
							rows="2"
							value={ fields.alt_text }
							onChange={ ( event ) => update( 'alt_text', event.target.value ) }
						/>
					</label>

					<label className="mg-field">
						<span>{ __( 'Credit', 'mediagraph-assets' ) }</span>
						<input
							type="text"
							value={ fields.credit }
							onChange={ ( event ) => update( 'credit', event.target.value ) }
						/>
					</label>

					{ availableRenditions.length > 1 && (
						<label className="mg-field">
							<span>{ __( 'Download quality', 'mediagraph-assets' ) }</span>
							<select
								value={ selectedRendition }
								onChange={ ( event ) => onRenditionChange( event.target.value ) }
							>
								{ availableRenditions.map( ( option ) => (
									<option key={ option.value } value={ option.value }>
										{ option.label }
									</option>
								) ) }
							</select>
							<span className="mg-field__help">
								{ __(
									'WordPress generates its own thumbnail and medium sizes from whatever you choose here.',
									'mediagraph-assets'
								) }
							</span>
						</label>
					) }
				</div>

				<div className="mg-panel__section">
					<h4>{ __( 'Mediagraph details', 'mediagraph-assets' ) }</h4>
					<p className="mg-panel__help">
						{ __( 'Read-only. Edit these in Mediagraph.', 'mediagraph-assets' ) }
					</p>

					<dl className="mg-panel__properties">
						<Property label={ __( 'Headline', 'mediagraph-assets' ) } value={ detail.headline } />
						<Property label={ __( 'Creator', 'mediagraph-assets' ) } value={ detail.creator } />
						<Property label={ __( 'Credit', 'mediagraph-assets' ) } value={ detail.credit_line } />
						<Property label={ __( 'Event', 'mediagraph-assets' ) } value={ detail.event } />
						<Property label={ __( 'Location', 'mediagraph-assets' ) } value={ detail.location } />
						<Property label={ __( 'Date created', 'mediagraph-assets' ) } value={ date( detail.captured_at ) } />
						<Property label={ __( 'Date added', 'mediagraph-assets' ) } value={ date( detail.created_at ) } />
						<Property label={ __( 'Keywords', 'mediagraph-assets' ) } value={ detail.keywords } />
						<Property label={ __( 'Usage terms', 'mediagraph-assets' ) } value={ detail.usage_terms } />

						{ ( detail.custom_meta || [] ).map( ( item ) => (
							<Property key={ item.name } label={ item.name } value={ item.value } />
						) ) }
					</dl>
				</div>
			</div>

			{ /* No insert button here. The picker's own footer carries the only
			     Insert action, so there is exactly one way to commit a choice
			     no matter which panel is open. This footer is informational. */ }
			{ ( restricted || ( multiple && selectedCount > 1 ) ) && (
				<footer className="mg-panel__footer">
					{ restricted ? (
						<p className="mg-panel__restricted">
							<span className="dashicons dashicons-lock" aria-hidden="true" />
							{ __( 'You do not have download permission for this asset.', 'mediagraph-assets' ) }
						</p>
					) : (
						<p className="mg-panel__note">
							{ sprintf(
								/* translators: %d: number of selected assets. */
								_n(
									'%d asset selected. Your edits are kept for each one.',
									'%d assets selected. Your edits are kept for each one.',
									selectedCount,
									'mediagraph-assets'
								),
								selectedCount
							) }
						</p>
					) }
				</footer>
			) }
		</aside>
	);
}
