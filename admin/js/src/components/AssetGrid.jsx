/**
 * Results grid.
 *
 * The result count bar stays mounted through loading and empty states so the
 * layout never jumps and the controls never disappear underneath the user.
 */

import { __ } from '@wordpress/i18n';

import AssetCard from './AssetCard';
import { resultRange } from '../lib/format';

/**
 * Placeholder tiles shown while a search is in flight.
 *
 * @param {Object} props       Component props.
 * @param {number} props.count Number of tiles.
 * @return {JSX.Element} Skeleton list.
 */
function Skeleton( { count } ) {
	return (
		<ul className="mg-grid" aria-hidden="true">
			{ Array.from( { length: count } ).map( ( _, index ) => (
				// eslint-disable-next-line react/no-array-index-key
				<li className="mg-card mg-card--skeleton" key={ index }>
					<div className="mg-card__thumb" />
					<div className="mg-card__body">
						<span className="mg-skeleton-line" />
						<span className="mg-skeleton-line mg-skeleton-line--short" />
					</div>
				</li>
			) ) }
		</ul>
	);
}

/**
 * @param {Object}   props                Component props.
 * @param {Array}    props.assets         Assets.
 * @param {boolean}  props.loading        Whether a search is running.
 * @param {number}   props.total          Total results.
 * @param {number}   props.page           Current page.
 * @param {number}   props.perPage        Page size.
 * @param {Array}    props.selection      Selected assets.
 * @param {boolean}  props.multiple       Whether multi-select is active.
 * @param {boolean}  props.hasFilters     Whether filters are applied.
 * @param {Function} props.onToggle       Selection handler.
 * @param {Function} props.onActivate     Activation handler.
 * @param {Function} props.onClearFilters Clear-filters handler.
 * @return {JSX.Element} Grid.
 */
export default function AssetGrid( {
	assets,
	loading,
	total,
	page,
	perPage,
	selection,
	multiple,
	hasFilters,
	onToggle,
	onActivate,
	onClearFilters,
} ) {
	const selectedIds = new Set( selection.map( ( asset ) => asset.id ) );

	return (
		<div className="mg-results">
			<div className="mg-results__bar">
				<span className="mg-results__count" aria-live="polite">
					{ loading ? __( 'Searching…', 'mediagraph-assets' ) : resultRange( page, perPage, total ) }
				</span>

				{ multiple && (
					<span className="mg-results__hint">
						{ __( 'Click to select, double-click to insert.', 'mediagraph-assets' ) }
					</span>
				) }
			</div>

			{ loading && assets.length === 0 ? (
				<Skeleton count={ 12 } />
			) : assets.length === 0 ? (
				<div className="mg-empty">
					<span className="dashicons dashicons-search" aria-hidden="true" />
					<p className="mg-empty__title">{ __( 'No assets found', 'mediagraph-assets' ) }</p>
					<p className="mg-empty__text">
						{ hasFilters
							? __(
									'Nothing matches the current search and filters.',
									'mediagraph-assets'
							  )
							: __(
									'This location does not contain any assets you can use.',
									'mediagraph-assets'
							  ) }
					</p>
					{ hasFilters && (
						<button type="button" className="mg-button mg-button--secondary" onClick={ onClearFilters }>
							{ __( 'Clear filters', 'mediagraph-assets' ) }
						</button>
					) }
				</div>
			) : (
				<ul className={ `mg-grid ${ loading ? 'is-stale' : '' }` }>
					{ assets.map( ( asset ) => (
						<AssetCard
							key={ asset.id }
							asset={ asset }
							selected={ selectedIds.has( asset.id ) }
							multiple={ multiple }
							onToggle={ onToggle }
							onActivate={ onActivate }
						/>
					) ) }
				</ul>
			) }
		</div>
	);
}
