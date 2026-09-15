/**
 * A single asset tile.
 */

import { __ } from '@wordpress/i18n';

import { duration, fileSize, kindLabel } from '../lib/format';

/**
 * Icon shown when there is no usable thumbnail.
 *
 * @param {Object} props      Component props.
 * @param {string} props.kind Asset kind.
 * @return {JSX.Element} Placeholder.
 */
function Placeholder( { kind } ) {
	const icon = {
		video: 'dashicons-video-alt3',
		audio: 'dashicons-format-audio',
		font: 'dashicons-editor-textcolor',
		text: 'dashicons-media-text',
		document: 'dashicons-media-document',
		image: 'dashicons-format-image',
	}[ kind ] || 'dashicons-media-default';

	return (
		<div className="mg-card__placeholder">
			<span className={ `dashicons ${ icon }` } aria-hidden="true" />
			<span className="mg-card__placeholder-label">{ kindLabel( kind ) }</span>
		</div>
	);
}

/**
 * @param {Object}   props           Component props.
 * @param {Object}   props.asset     Asset.
 * @param {boolean}  props.selected  Whether the asset is selected.
 * @param {boolean}  props.multiple  Whether multi-select is active.
 * @param {Function} props.onToggle  Selection handler.
 * @param {Function} props.onActivate Double-click / Enter handler.
 * @return {JSX.Element} Card.
 */
export default function AssetCard( { asset, selected, multiple, onToggle, onActivate } ) {
	const restricted = ! asset.downloadable;
	const meta = [ fileSize( asset.file_size ), duration( asset.duration ) ].filter( Boolean );

	const handleKeyDown = ( event ) => {
		if ( event.key === 'Enter' ) {
			event.preventDefault();
			if ( ! restricted ) {
				onActivate( asset );
			}
		}

		if ( event.key === ' ' ) {
			event.preventDefault();
			if ( ! restricted ) {
				onToggle( asset );
			}
		}
	};

	return (
		<li
			className={ `mg-card ${ selected ? 'is-selected' : '' } ${ restricted ? 'is-restricted' : '' }` }
		>
			<div
				className="mg-card__hit"
				role="button"
				tabIndex={ 0 }
				aria-pressed={ selected }
				aria-disabled={ restricted }
				aria-label={ asset.filename }
				onClick={ () => ! restricted && onToggle( asset ) }
				onDoubleClick={ () => ! restricted && onActivate( asset ) }
				onKeyDown={ handleKeyDown }
			>
				<div className="mg-card__thumb">
					{ asset.thumb_url ? (
						<img src={ asset.thumb_url } alt="" loading="lazy" />
					) : (
						<Placeholder kind={ asset.kind } />
					) }

					{ asset.kind !== 'image' && (
						<span className="mg-card__kind">{ kindLabel( asset.kind ) }</span>
					) }

					{ multiple && ! restricted && (
						<span className={ `mg-card__check ${ selected ? 'is-checked' : '' }` } aria-hidden="true">
							{ selected && <span className="dashicons dashicons-yes" /> }
						</span>
					) }

					{ restricted && (
						<span className="mg-card__lock" title={ __( 'You cannot download this asset', 'mediagraph-assets' ) }>
							<span className="dashicons dashicons-lock" aria-hidden="true" />
						</span>
					) }
				</div>

				<div className="mg-card__body">
					<span className="mg-card__name" title={ asset.filename }>
						{ asset.filename || __( 'Untitled', 'mediagraph-assets' ) }
					</span>
					{ meta.length > 0 && <span className="mg-card__meta">{ meta.join( ' · ' ) }</span> }
				</div>
			</div>
		</li>
	);
}
