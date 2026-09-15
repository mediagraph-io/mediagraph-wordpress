/**
 * Search, sort, and type filtering.
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { kindLabel } from '../lib/format';

const KINDS = [ 'image', 'video', 'audio', 'document' ];

/**
 * @param {Object}   props                 Component props.
 * @param {string}   props.search          Current search text.
 * @param {Function} props.onSearch        Search handler.
 * @param {string}   props.sort            Current sort value.
 * @param {Function} props.onSort          Sort handler.
 * @param {Array}    props.sortOptions     Available sort options.
 * @param {Array}    props.kindFilter      Selected kinds.
 * @param {Function} props.onKindFilter    Kind filter handler.
 * @param {Array}    props.lockedTypes     Kinds forced by the calling block.
 * @param {boolean}  props.filtersOpen     Whether the filter drawer is open.
 * @param {Function} props.onToggleFilters Drawer toggle.
 * @param {number}   props.filterCount     Active metadata filter count.
 * @param {boolean}  props.hasFilters      Whether anything is filtered.
 * @param {Function} props.onClearFilters  Clear-all handler.
 * @return {JSX.Element} Toolbar.
 */
export default function Toolbar( {
	search,
	onSearch,
	sort,
	onSort,
	sortOptions = [],
	kindFilter = [],
	onKindFilter,
	lockedTypes = [],
	filtersOpen,
	onToggleFilters,
	filterCount = 0,
	hasFilters,
	onClearFilters,
} ) {
	const [ draft, setDraft ] = useState( search );
	const timer = useRef( null );

	// Keep the input in step when the query is cleared from elsewhere.
	useEffect( () => {
		setDraft( search );
	}, [ search ] );

	useEffect( () => {
		if ( draft === search ) {
			return undefined;
		}

		timer.current = window.setTimeout( () => onSearch( draft ), 300 );

		return () => window.clearTimeout( timer.current );
	}, [ draft, search, onSearch ] );

	const toggleKind = ( kind ) => {
		const next = kindFilter.includes( kind )
			? kindFilter.filter( ( item ) => item !== kind )
			: [ ...kindFilter, kind ];

		onKindFilter( next );
	};

	const locked = lockedTypes.length > 0;

	return (
		<div className="mg-toolbar">
			{ /* The wrapper carries the border and lays its children out in a
			     row. Nothing is absolutely positioned, so the icon cannot end
			     up sitting on top of the text no matter what padding the host
			     admin stylesheet puts on the input. */ }
			<div className="mg-toolbar__search">
				<span className="mg-toolbar__search-icon dashicons dashicons-search" aria-hidden="true" />
				<input
					type="search"
					value={ draft }
					onChange={ ( event ) => setDraft( event.target.value ) }
					placeholder={ __( 'Search assets…', 'mediagraph-assets' ) }
					aria-label={ __( 'Search assets', 'mediagraph-assets' ) }
					className="mg-toolbar__input"
				/>
				{ draft && (
					<button
						type="button"
						className="mg-toolbar__clear"
						onClick={ () => setDraft( '' ) }
						aria-label={ __( 'Clear search', 'mediagraph-assets' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				) }
			</div>

			<div className="mg-toolbar__kinds" role="group" aria-label={ __( 'File type', 'mediagraph-assets' ) }>
				{ locked ? (
					<span className="mg-chip mg-chip--locked">
						{ sprintf(
							/* translators: %s: list of file types. */
							__( 'Showing %s only', 'mediagraph-assets' ),
							lockedTypes.map( kindLabel ).join( ', ' )
						) }
					</span>
				) : (
					KINDS.map( ( kind ) => (
						<button
							key={ kind }
							type="button"
							className={ `mg-chip ${ kindFilter.includes( kind ) ? 'is-active' : '' }` }
							onClick={ () => toggleKind( kind ) }
							aria-pressed={ kindFilter.includes( kind ) }
						>
							{ kindLabel( kind ) }
						</button>
					) )
				) }
			</div>

			<div className="mg-toolbar__controls">
				<button
					type="button"
					className={ `mg-button mg-button--ghost ${ filtersOpen ? 'is-active' : '' }` }
					onClick={ onToggleFilters }
					aria-expanded={ filtersOpen }
				>
					<span className="dashicons dashicons-filter" aria-hidden="true" />
					{ __( 'Filters', 'mediagraph-assets' ) }
					{ filterCount > 0 && <span className="mg-badge">{ filterCount }</span> }
				</button>

				<label className="screen-reader-text" htmlFor="mg-sort">
					{ __( 'Sort by', 'mediagraph-assets' ) }
				</label>
				<select
					id="mg-sort"
					className="mg-toolbar__select"
					value={ sort }
					onChange={ ( event ) => onSort( event.target.value ) }
				>
					{ sortOptions.map( ( option ) => (
						<option key={ option.value } value={ option.value }>
							{ option.label }
						</option>
					) ) }
				</select>

				{ hasFilters && (
					<button type="button" className="mg-button mg-button--link" onClick={ onClearFilters }>
						{ __( 'Clear all', 'mediagraph-assets' ) }
					</button>
				) }
			</div>
		</div>
	);
}
