/**
 * The picker: sidebar, results grid, and details panel.
 */

import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	AbortedError,
	fetchChildren,
	fetchContainers,
	fetchCreators,
	fetchCustomFields,
	importAsset,
	importAssets,
	searchAssets,
} from './lib/api';
import { defaultPerPage, defaultRendition, settings } from './lib/settings';

import Sidebar from './components/Sidebar';
import Toolbar from './components/Toolbar';
import FilterBar from './components/FilterBar';
import AssetGrid from './components/AssetGrid';
import DetailsPanel from './components/DetailsPanel';
import SelectionFooter from './components/SelectionFooter';
import Notice from './components/Notice';
import Pagination from './components/Pagination';

const EMPTY_CONTAINERS = { folders: [], collections: [], lightboxes: [], errors: {} };

/**
 * Read the post ID being edited, if any.
 *
 * @return {number} Post ID, or 0.
 */
function currentPostId() {
	if ( window.wp?.data?.select ) {
		try {
			const id = window.wp.data.select( 'core/editor' )?.getCurrentPostId();

			if ( id ) {
				return Number( id );
			}
		} catch ( error ) {
			// Not in a block editor context.
		}
	}

	const field = document.getElementById( 'post_ID' );

	if ( field && field.value ) {
		return Number( field.value );
	}

	const param = new URLSearchParams( window.location.search ).get( 'post' );

	return param ? Number( param ) : 0;
}

/**
 * Main picker component.
 *
 * @param {Object}   props              Component props.
 * @param {Array}    props.types        Asset kinds the caller accepts.
 * @param {boolean}  props.multiple     Whether several assets may be chosen.
 * @param {string}   props.title        Modal heading.
 * @param {string}   props.confirmLabel Label for the insert button.
 * @param {Function} props.onSelect     Receives the imported attachments.
 * @param {Function} props.onClose      Called when the picker should close.
 * @return {JSX.Element} Picker.
 */
export default function Picker( {
	types = [],
	multiple = false,
	title = '',
	confirmLabel = '',
	onSelect,
	onClose,
} ) {
	const config = settings();

	const [ containers, setContainers ] = useState( EMPTY_CONTAINERS );
	const [ containersLoading, setContainersLoading ] = useState( true );
	const [ container, setContainer ] = useState( null );

	const [ search, setSearch ] = useState( '' );
	const [ sort, setSort ] = useState( 'created_at:desc' );
	const [ kindFilter, setKindFilter ] = useState( [] );
	const [ customMeta, setCustomMeta ] = useState( {} );
	const [ rights, setRights ] = useState( [] );
	const [ creator, setCreator ] = useState( 0 );
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ showAll, setShowAll ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ filtersOpen, setFiltersOpen ] = useState( false );

	const [ customFields, setCustomFields ] = useState( [] );
	const [ creators, setCreators ] = useState( [] );

	const [ results, setResults ] = useState( {
		assets: [],
		total: 0,
		totalPages: 0,
		perPage: defaultPerPage(),
	} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const [ selection, setSelection ] = useState( [] );
	const [ detailAsset, setDetailAsset ] = useState( null );
	// Edits made in the details panel, keyed by asset ID, so annotating one
	// asset in a multi-select does not get lost when the batch is inserted.
	const [ annotations, setAnnotations ] = useState( {} );
	// Download quality is chosen in the details panel but applies to the whole
	// insert, so it lives here rather than in a panel that unmounts whenever the
	// user looks at a different asset.
	const [ rendition, setRendition ] = useState( defaultRendition );
	const [ importing, setImporting ] = useState( false );
	const [ importError, setImportError ] = useState( null );
	const [ importProgress, setImportProgress ] = useState( null );

	const searchRequest = useRef( null );
	const postId = useRef( currentPostId() );

	// A block that only accepts certain kinds locks the filter; otherwise the
	// user picks from the chips.
	const lockedTypes = useMemo( () => types.filter( Boolean ), [ types ] );
	const effectiveTypes = lockedTypes.length ? lockedTypes : kindFilter;

	const perPage = results.perPage || defaultPerPage();

	// Arrays and objects change identity every render, so the effect below
	// keys off their serialized form instead.
	const typesKey = JSON.stringify( effectiveTypes );
	const customMetaKey = JSON.stringify( customMeta );
	const rightsKey = JSON.stringify( rights );

	/**
	 * Load the container tree.
	 */
	const loadContainers = useCallback( async ( refresh = false ) => {
		setContainersLoading( true );

		try {
			const data = await fetchContainers( refresh );
			setContainers( { ...EMPTY_CONTAINERS, ...data } );
		} catch ( err ) {
			if ( ! ( err instanceof AbortedError ) ) {
				setContainers( {
					...EMPTY_CONTAINERS,
					errors: { all: err.message },
				} );
			}
		} finally {
			setContainersLoading( false );
		}
	}, [] );

	/**
	 * Load the custom metadata fields offered in the filter drawer.
	 */
	const loadCustomFields = useCallback( () => {
		fetchCustomFields()
			.then( ( data ) => setCustomFields( data.fields || [] ) )
			.catch( () => setCustomFields( [] ) );

		fetchCreators()
			.then( ( data ) => setCreators( data.creators || [] ) )
			.catch( () => setCreators( [] ) );
	}, [] );

	useEffect( () => {
		loadContainers( false );
		loadCustomFields();
	}, [ loadContainers, loadCustomFields ] );

	/**
	 * Reload everything the server caches.
	 *
	 * A forced container load flushes the whole plugin cache server side, so the
	 * filter fields must be re-read too. Without this the Reload button cleared
	 * the cached field list but left the drawer showing the old one until the
	 * picker was reopened.
	 */
	const refreshFromMediagraph = useCallback( () => {
		loadContainers( true );
		loadCustomFields();
	}, [ loadContainers, loadCustomFields ] );

	/**
	 * Run a search whenever the query changes.
	 *
	 * The previous request is aborted first, so results always match the
	 * controls on screen.
	 */
	useEffect( () => {
		if ( searchRequest.current ) {
			searchRequest.current.abort();
		}

		const controller = new AbortController();
		searchRequest.current = controller;

		setLoading( true );
		setError( null );

		searchAssets(
			{
				search,
				container,
				types: effectiveTypes,
				customMeta,
				rights,
				creator,
				dateFrom,
				dateTo,
				sort,
				showAll,
				page,
				perPage,
			},
			{ signal: controller.signal }
		)
			.then( ( data ) => {
				setResults( {
					assets: data.assets || [],
					total: data.total || 0,
					totalPages: data.total_pages || 0,
					perPage: data.per_page || perPage,
					partialFilter: Boolean( data.partial_filter ),
				} );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( err instanceof AbortedError ) {
					return;
				}

				setError( err );
				setLoading( false );
			} );

		return () => controller.abort();
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [
		search,
		container,
		sort,
		showAll,
		page,
		perPage,
		typesKey,
		customMetaKey,
		rightsKey,
		creator,
		dateFrom,
		dateTo,
	] );

	// Any change to the query resets to the first page.
	const resetPage = useCallback( () => setPage( 1 ), [] );

	const handleContainerSelect = useCallback(
		( node ) => {
			setContainer( node );
			resetPage();
		},
		[ resetPage ]
	);

	const handleSearch = useCallback(
		( value ) => {
			setSearch( value );
			resetPage();
		},
		[ resetPage ]
	);

	const handleKindFilter = useCallback(
		( kinds ) => {
			setKindFilter( kinds );
			resetPage();
		},
		[ resetPage ]
	);

	const handleCustomMeta = useCallback(
		( next ) => {
			setCustomMeta( next );
			resetPage();
		},
		[ resetPage ]
	);

	const handleRights = useCallback(
		( next ) => {
			setRights( next );
			resetPage();
		},
		[ resetPage ]
	);

	const handleCreator = useCallback(
		( value ) => {
			setCreator( value );
			resetPage();
		},
		[ resetPage ]
	);

	const handleDates = useCallback(
		( from, to ) => {
			setDateFrom( from );
			setDateTo( to );
			resetPage();
		},
		[ resetPage ]
	);

	const clearFilters = useCallback( () => {
		setSearch( '' );
		setKindFilter( [] );
		setCustomMeta( {} );
		setRights( [] );
		setCreator( 0 );
		setDateFrom( '' );
		setDateTo( '' );
		setShowAll( false );
		setContainer( null );
		resetPage();
	}, [ resetPage ] );

	const hasFilters =
		Boolean( search ) ||
		Boolean( container ) ||
		kindFilter.length > 0 ||
		Object.keys( customMeta ).length > 0 ||
		rights.length > 0 ||
		creator > 0 ||
		Boolean( dateFrom && dateTo ) ||
		showAll;

	/**
	 * Toggle an asset in the selection.
	 */
	const toggleSelection = useCallback(
		( asset ) => {
			if ( ! asset.downloadable ) {
				return;
			}

			// Read membership from current state rather than from inside the
			// updater: React defers the updater until render, so anything it
			// assigns is still unset on the next line.
			const exists = selection.some( ( item ) => item.id === asset.id );

			if ( exists ) {
				setSelection( selection.filter( ( item ) => item.id !== asset.id ) );
				setDetailAsset( null );

				return;
			}

			setSelection( multiple ? [ ...selection, asset ] : [ asset ] );

			// Selecting opens the details straight away: confirming you picked
			// the right frame, and captioning it, both belong here rather than
			// after the fact.
			setDetailAsset( asset );
		},
		[ multiple, selection ]
	);

	const clearSelection = useCallback( () => {
		setSelection( [] );
		setAnnotations( {} );
		setDetailAsset( null );
	}, [] );

	/**
	 * Record the details panel's edits for one asset.
	 *
	 * Referentially stable so the panel's fetch effect can depend on it without
	 * re-requesting the asset on every render of this component.
	 */
	const annotate = useCallback( ( assetId, fields ) => {
		setAnnotations( ( current ) => ( { ...current, [ assetId ]: fields } ) );
	}, [] );

	/**
	 * Import the chosen assets and hand them to the caller.
	 */
	const insert = useCallback(
		async ( assets, overrides = {} ) => {
			const chosen = assets.filter( ( asset ) => asset.downloadable );

			if ( ! chosen.length ) {
				return;
			}

			setImporting( true );
			setImportError( null );
			setImportProgress(
				chosen.length > 1
					? sprintf(
							/* translators: %d: number of assets. */
							__( 'Adding %d assets to your media library…', 'mediagraph-assets' ),
							chosen.length
					  )
					: __( 'Adding to your media library…', 'mediagraph-assets' )
			);

			try {
				const chosenRendition = overrides.rendition || rendition || defaultRendition();

				if ( chosen.length === 1 ) {
					const assetId = chosen[ 0 ].id;

					const attachment = await importAsset( {
						assetId,
						rendition: chosenRendition,
						postId: postId.current,
						metadata: overrides.metadata || annotations[ assetId ] || {},
					} );

					onSelect( [ attachment ] );
				} else {
					const total = chosen.length;
					const attachments = [];
					const failures = [];
					let pending = chosen.map( ( asset ) => asset.id );

					// Importing is slow — a full-size download plus thumbnail
					// generation per asset — so the server works to a time
					// budget and hands back whatever it could not reach. Keep
					// asking until nothing is left, which keeps a large gallery
					// selection from dying on PHP's execution limit.
					while ( pending.length ) {
						const data = await importAssets( {
							assetIds: pending,
							rendition: chosenRendition,
							postId: postId.current,
							// Anything annotated in the details panel travels
							// with the batch, keyed by asset.
							metadata: annotations,
						} );

						attachments.push( ...( data.attachments || [] ) );
						failures.push( ...( data.failures || [] ) );

						const left = data.remaining || [];

						// Guard against a server that keeps returning the same
						// work: better to stop with a partial result than spin.
						if ( left.length >= pending.length ) {
							break;
						}

						pending = left;

						if ( pending.length ) {
							setImportProgress(
								sprintf(
									/* translators: 1: assets done, 2: total assets. */
									__( 'Added %1$d of %2$d to your media library…', 'mediagraph-assets' ),
									attachments.length + failures.length,
									total
								)
							);
						}
					}

					// Partial success still inserts what worked; the caller is
					// told about the rest rather than losing the whole batch.
					if ( failures.length ) {
						window.console?.warn(
							'[Mediagraph] Some assets could not be imported',
							failures
						);
					}

					onSelect( attachments, failures );
				}
			} catch ( err ) {
				if ( ! ( err instanceof AbortedError ) ) {
					setImportError( err );
				}
			} finally {
				setImporting( false );
				setImportProgress( null );
			}
		},
		[ onSelect, annotations, rendition ]
	);

	/**
	 * Double-click / Enter on a card inserts immediately in single mode.
	 */
	const handleActivate = useCallback(
		( asset ) => {
			if ( multiple ) {
				toggleSelection( asset );
				return;
			}

			insert( [ asset ] );
		},
		[ insert, multiple, toggleSelection ]
	);

	const heading =
		title ||
		( config.organization
			? sprintf(
					/* translators: %s: organization name. */
					__( 'Mediagraph — %s', 'mediagraph-assets' ),
					config.organization
			  )
			: __( 'Mediagraph', 'mediagraph-assets' ) );

	const insertLabel =
		confirmLabel ||
		( selection.length > 1
			? sprintf(
					/* translators: %d: number of assets. */
					_n(
						'Insert %d asset',
						'Insert %d assets',
						selection.length,
						'mediagraph-assets'
					),
					selection.length
			  )
			: __( 'Insert', 'mediagraph-assets' ) );

	return (
		<div className="mg-picker">
			<header className="mg-picker__header">
				<h2 className="mg-picker__heading">
					{ config.logoUrl && (
						<img className="mg-picker__logo" src={ config.logoUrl } alt="" width="24" height="24" />
					) }
					<span>{ heading }</span>
				</h2>

				{ /* Refresh lives in the header, not the sidebar: the sidebar
				     scrolls, and this is the control people reach for when
				     something they just changed in Mediagraph is missing. */ }
				<div className="mg-picker__actions">
					<button
						type="button"
						className="mg-button mg-button--ghost"
						onClick={ refreshFromMediagraph }
						disabled={ containersLoading }
					>
						<span
							className={ `dashicons dashicons-update ${ containersLoading ? 'mg-spin' : '' }` }
							aria-hidden="true"
						/>
						{ containersLoading
							? __( 'Refreshing…', 'mediagraph-assets' )
							: __( 'Refresh', 'mediagraph-assets' ) }
					</button>

					<button
						type="button"
						className="mg-picker__close"
						onClick={ onClose }
						aria-label={ __( 'Close the Mediagraph picker', 'mediagraph-assets' ) }
					>
						<span aria-hidden="true">×</span>
					</button>
				</div>
			</header>

			<Toolbar
				search={ search }
				onSearch={ handleSearch }
				sort={ sort }
				onSort={ ( value ) => {
					setSort( value );
					resetPage();
				} }
				sortOptions={ config.sortOptions }
				kindFilter={ kindFilter }
				onKindFilter={ handleKindFilter }
				lockedTypes={ lockedTypes }
				filtersOpen={ filtersOpen }
				onToggleFilters={ () => setFiltersOpen( ( open ) => ! open ) }
				filterCount={
					Object.keys( customMeta ).length +
					rights.length +
					( creator > 0 ? 1 : 0 ) +
					( dateFrom && dateTo ? 1 : 0 )
				}
				hasFilters={ hasFilters }
				onClearFilters={ clearFilters }
			/>

			{ filtersOpen && (
				<FilterBar
					fields={ customFields }
					values={ customMeta }
					onChange={ handleCustomMeta }
					rightsClasses={ config.rightsClasses }
					rights={ rights }
					onRightsChange={ handleRights }
					creators={ creators }
					creator={ creator }
					onCreatorChange={ handleCreator }
					dateFrom={ dateFrom }
					dateTo={ dateTo }
					onDatesChange={ handleDates }
					showAll={ showAll }
					onShowAll={ ( value ) => {
						setShowAll( value );
						resetPage();
					} }
				/>
			) }

			<div className="mg-picker__body">
				<Sidebar
					containers={ containers }
					loading={ containersLoading }
					current={ container }
					onSelect={ handleContainerSelect }
					loadChildren={ fetchChildren }
				/>

				<main className="mg-picker__main">
					{ importError && (
						<Notice
							type="error"
							onDismiss={ () => setImportError( null ) }
							action={
								importError.isAuthError && config.settingsUrl
									? {
											label: __( 'Open settings', 'mediagraph-assets' ),
											href: config.settingsUrl,
									  }
									: null
							}
						>
							{ importError.message }
						</Notice>
					) }

					{ error && (
						<Notice
							type="error"
							action={
								error.isAuthError && config.settingsUrl
									? {
											label: __( 'Open settings', 'mediagraph-assets' ),
											href: config.settingsUrl,
									  }
									: {
											label: __( 'Try again', 'mediagraph-assets' ),
											onClick: () => setPage( ( value ) => value ),
									  }
							}
						>
							{ error.message }
						</Notice>
					) }

					{ results.partialFilter && (
						<Notice type="info">
							{ __(
								'Some results were hidden because this block only accepts certain file types.',
								'mediagraph-assets'
							) }
						</Notice>
					) }

					<AssetGrid
						assets={ results.assets }
						loading={ loading }
						total={ results.total }
						page={ page }
						perPage={ perPage }
						selection={ selection }
						multiple={ multiple }
						hasFilters={ hasFilters }
						onToggle={ toggleSelection }
						onActivate={ handleActivate }
						onClearFilters={ clearFilters }
					/>

					<Pagination
						page={ page }
						totalPages={ results.totalPages }
						onChange={ setPage }
						disabled={ loading }
					/>
				</main>

				{ detailAsset && (
					<DetailsPanel
						key={ detailAsset.id }
						asset={ detailAsset }
						annotation={ annotations[ detailAsset.id ] }
						onAnnotate={ annotate }
						onClose={ () => setDetailAsset( null ) }
						renditions={ config.renditions }
						rendition={ rendition }
						onRenditionChange={ setRendition }
						multiple={ multiple }
						selectedCount={ selection.length }
					/>
				) }
			</div>

			<SelectionFooter
				selection={ selection }
				multiple={ multiple }
				busy={ importing }
				progress={ importProgress }
				insertLabel={ insertLabel }
				onClear={ clearSelection }
				onInsert={ () => insert( selection ) }
				onCancel={ onClose }
			/>
		</div>
	);
}
