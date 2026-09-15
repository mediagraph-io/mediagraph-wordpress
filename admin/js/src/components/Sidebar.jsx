/**
 * Container tree: storage folders, collections, and lightboxes.
 */

import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Sidebar sections.
 *
 * The labels are the customer-facing names for these containers. The `key`
 * values still match the API's storage folders / collections / lightboxes,
 * so only the wording changes here.
 */
const SECTIONS = [
	{ key: 'folders', label: __( 'File Vault', 'mediagraph-assets' ) },
	{ key: 'collections', label: __( 'Libraries', 'mediagraph-assets' ) },
	{ key: 'lightboxes', label: __( 'My Projects', 'mediagraph-assets' ) },
];

const STORAGE_KEY = 'mediagraph_collapsed_sections';

/**
 * Read collapsed sections from local storage.
 *
 * @return {Array} Section keys.
 */
function storedCollapsed() {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );

		return raw ? JSON.parse( raw ) : [];
	} catch ( error ) {
		return [];
	}
}

/**
 * @param {Object}   props              Component props.
 * @param {Object}   props.containers   Containers grouped by kind.
 * @param {boolean}  props.loading      Whether the tree is loading.
 * @param {Object}   props.current      Selected container.
 * @param {Function} props.onSelect     Selection handler.
 * @param {Function} props.loadChildren Lazy child loader.
 * @return {JSX.Element} Sidebar.
 */
export default function Sidebar( {
	containers,
	loading,
	current,
	onSelect,
	loadChildren,
} ) {
	const [ expanded, setExpanded ] = useState( () => new Set() );
	const [ busyNodes, setBusyNodes ] = useState( () => new Set() );
	const [ children, setChildren ] = useState( {} );
	const [ collapsed, setCollapsed ] = useState( () => new Set( storedCollapsed() ) );

	const toggleSection = useCallback( ( key ) => {
		setCollapsed( ( current_ ) => {
			const next = new Set( current_ );

			if ( next.has( key ) ) {
				next.delete( key );
			} else {
				next.add( key );
			}

			try {
				window.localStorage.setItem( STORAGE_KEY, JSON.stringify( [ ...next ] ) );
			} catch ( error ) {
				// Storage unavailable; collapse state is per-session only.
			}

			return next;
		} );
	}, [] );

	/**
	 * Expand or collapse a node, loading its children on first expand.
	 *
	 * Whether the node is currently open is read from state, not from a flag set
	 * inside the setExpanded updater. React defers that updater until render, so
	 * such a flag is still false on the next line — which meant the load below
	 * was always skipped and expanding a folder revealed nothing.
	 */
	const toggleNode = useCallback(
		async ( node ) => {
			const key = `${ node.type }:${ node.id }`;
			const isOpen = expanded.has( key );

			setExpanded( ( current_ ) => {
				const next = new Set( current_ );

				if ( isOpen ) {
					next.delete( key );
				} else {
					next.add( key );
				}

				return next;
			} );

			// Only fetch on the way open, and only the first time.
			if ( isOpen || children[ key ] || ! node.has_children ) {
				return;
			}

			setBusyNodes( ( current_ ) => new Set( current_ ).add( key ) );

			try {
				const loaded = await loadChildren( node );
				setChildren( ( current_ ) => ( { ...current_, [ key ]: loaded || [] } ) );
			} catch ( error ) {
				setChildren( ( current_ ) => ( { ...current_, [ key ]: [] } ) );
			} finally {
				setBusyNodes( ( current_ ) => {
					const next = new Set( current_ );
					next.delete( key );

					return next;
				} );
			}
		},
		[ expanded, children, loadChildren ]
	);

	const renderNode = ( node, depth = 0 ) => {
		const key = `${ node.type }:${ node.id }`;
		const isExpanded = expanded.has( key );
		const isBusy = busyNodes.has( key );
		const isActive = current && current.id === node.id && current.type === node.type;
		const loaded = children[ key ];
		const nodeChildren = loaded || node.children || [];

		// A node can claim children and then load none — the count comes from
		// a different query than the tree itself. Once we know it is actually
		// empty, drop the disclosure rather than leaving an arrow that expands
		// to nothing every time.
		const showDisclosure = node.has_children && ! ( Array.isArray( loaded ) && loaded.length === 0 );

		return (
			<li key={ key } className="mg-tree__item">
				<div className="mg-tree__row" style={ { paddingInlineStart: `${ depth * 14 }px` } }>
					{ showDisclosure ? (
						<button
							type="button"
							className="mg-tree__disclosure"
							onClick={ () => toggleNode( node ) }
							aria-expanded={ isExpanded }
							aria-label={
								isExpanded
									? __( 'Collapse', 'mediagraph-assets' )
									: __( 'Expand', 'mediagraph-assets' )
							}
						>
							<span
								className={ `dashicons ${
									isBusy
										? 'dashicons-update mg-spin'
										: isExpanded
										? 'dashicons-arrow-down-alt2'
										: 'dashicons-arrow-right-alt2'
								}` }
								aria-hidden="true"
							/>
						</button>
					) : (
						<span className="mg-tree__disclosure mg-tree__disclosure--empty" />
					) }

					<button
						type="button"
						className={ `mg-tree__label ${ isActive ? 'is-active' : '' }` }
						onClick={ () => onSelect( node ) }
						aria-current={ isActive ? 'true' : undefined }
					>
						<span className="mg-tree__name">{ node.name }</span>
						{ typeof node.count === 'number' && (
							<span className="mg-tree__count">{ node.count.toLocaleString() }</span>
						) }
					</button>
				</div>

				{ isExpanded && showDisclosure && (
					<ul className="mg-tree__children">
						{ isBusy && nodeChildren.length === 0 ? (
							<li className="mg-tree__hint">{ __( 'Loading…', 'mediagraph-assets' ) }</li>
						) : (
							nodeChildren.map( ( child ) => renderNode( child, depth + 1 ) )
						) }
					</ul>
				) }
			</li>
		);
	};

	return (
		<nav className="mg-sidebar" aria-label={ __( 'Browse Mediagraph', 'mediagraph-assets' ) }>
			<div className="mg-sidebar__header">
				<span>{ __( 'Browse', 'mediagraph-assets' ) }</span>
			</div>

			<button
				type="button"
				className={ `mg-tree__label mg-sidebar__all ${ ! current ? 'is-active' : '' }` }
				onClick={ () => onSelect( null ) }
			>
				{ __( 'All assets', 'mediagraph-assets' ) }
			</button>

			{ containers.errors?.all && (
				<p className="mg-sidebar__error">{ containers.errors.all }</p>
			) }

			{ SECTIONS.map( ( section ) => {
				const items = containers[ section.key ] || [];
				const sectionError = containers.errors?.[ section.key ];
				const isCollapsed = collapsed.has( section.key );

				return (
					<section className="mg-sidebar__section" key={ section.key }>
						<h3>
							<button
								type="button"
								className="mg-sidebar__section-toggle"
								onClick={ () => toggleSection( section.key ) }
								aria-expanded={ ! isCollapsed }
							>
								<span
									className={ `dashicons ${
										isCollapsed ? 'dashicons-arrow-right-alt2' : 'dashicons-arrow-down-alt2'
									}` }
									aria-hidden="true"
								/>
								{ section.label }
							</button>
						</h3>

						{ ! isCollapsed && (
							<>
								{ sectionError ? (
									<p className="mg-sidebar__error">{ sectionError }</p>
								) : loading && items.length === 0 ? (
									<p className="mg-tree__hint">{ __( 'Loading…', 'mediagraph-assets' ) }</p>
								) : items.length === 0 ? (
									<p className="mg-tree__hint">{ __( 'None available', 'mediagraph-assets' ) }</p>
								) : (
									<ul className="mg-tree">{ items.map( ( node ) => renderNode( node ) ) }</ul>
								) }
							</>
						) }
					</section>
				);
			} ) }
		</nav>
	);
}
