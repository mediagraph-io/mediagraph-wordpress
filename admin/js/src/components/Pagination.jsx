/**
 * Pager.
 *
 * Rendered whenever there is more than one page, including while a search is
 * loading and when the current page came back empty — 1.x returned early
 * before the pager, which left users stranded on an empty page 5.
 */

import { __, sprintf } from '@wordpress/i18n';

const WINDOW = 5;

/**
 * Page numbers to show around the current page.
 *
 * @param {number} page       Current page.
 * @param {number} totalPages Total pages.
 * @return {Array} Page numbers.
 */
function pageWindow( page, totalPages ) {
	let start = Math.max( 1, page - Math.floor( WINDOW / 2 ) );
	const end = Math.min( totalPages, start + WINDOW - 1 );

	start = Math.max( 1, Math.min( start, end - WINDOW + 1 ) );

	const pages = [];

	for ( let i = start; i <= end; i += 1 ) {
		pages.push( i );
	}

	return pages;
}

/**
 * @param {Object}   props            Component props.
 * @param {number}   props.page       Current page.
 * @param {number}   props.totalPages Total pages.
 * @param {Function} props.onChange   Page change handler.
 * @param {boolean}  props.disabled   Whether controls are disabled.
 * @return {JSX.Element|null} Pager.
 */
export default function Pagination( { page, totalPages, onChange, disabled } ) {
	if ( ! totalPages || totalPages <= 1 ) {
		return null;
	}

	const pages = pageWindow( page, totalPages );

	return (
		<nav className="mg-pagination" aria-label={ __( 'Asset pages', 'mediagraph-assets' ) }>
			<button
				type="button"
				className="mg-button mg-button--secondary"
				onClick={ () => onChange( page - 1 ) }
				disabled={ disabled || page <= 1 }
			>
				{ __( 'Previous', 'mediagraph-assets' ) }
			</button>

			{ pages[ 0 ] > 1 && (
				<>
					<button
						type="button"
						className="mg-button mg-button--secondary"
						onClick={ () => onChange( 1 ) }
						disabled={ disabled }
					>
						1
					</button>
					{ pages[ 0 ] > 2 && <span className="mg-pagination__gap">…</span> }
				</>
			) }

			{ pages.map( ( number ) => (
				<button
					key={ number }
					type="button"
					className={ `mg-button ${
						number === page ? 'mg-button--primary' : 'mg-button--secondary'
					}` }
					onClick={ () => onChange( number ) }
					disabled={ disabled }
					aria-current={ number === page ? 'page' : undefined }
					aria-label={ sprintf(
						/* translators: %d: page number. */
						__( 'Page %d', 'mediagraph-assets' ),
						number
					) }
				>
					{ number }
				</button>
			) ) }

			{ pages[ pages.length - 1 ] < totalPages && (
				<>
					{ pages[ pages.length - 1 ] < totalPages - 1 && (
						<span className="mg-pagination__gap">…</span>
					) }
					<button
						type="button"
						className="mg-button mg-button--secondary"
						onClick={ () => onChange( totalPages ) }
						disabled={ disabled }
					>
						{ totalPages }
					</button>
				</>
			) }

			<button
				type="button"
				className="mg-button mg-button--secondary"
				onClick={ () => onChange( page + 1 ) }
				disabled={ disabled || page >= totalPages }
			>
				{ __( 'Next', 'mediagraph-assets' ) }
			</button>
		</nav>
	);
}
