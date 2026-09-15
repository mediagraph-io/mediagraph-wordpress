/**
 * Filter drawer.
 *
 * Every filter is a labelled cell in one grid, so the drawer reads as a single
 * orderly panel rather than a run of stacked rows, and it reflows to however
 * much width the picker has. Order runs from the questions an editor asks most
 * often to the most specialised: what may we publish, who shot it, when, then
 * whatever custom metadata the library defines, then options.
 *
 * Controlled custom meta fields (those with a defined value list) become
 * dropdowns. Free-text fields are excluded server side because there is no
 * sensible list to offer.
 */

import { __ } from '@wordpress/i18n';

const BLANK = '_blank';

/**
 * One labelled cell in the filter grid.
 *
 * @param {Object}  props          Component props.
 * @param {string}  props.label    Field label.
 * @param {string}  props.htmlFor  ID of the control the label points at.
 * @param {string}  props.hint     Optional help text below the control.
 * @param {boolean} props.wide     Whether the cell spans two columns.
 * @param {*}       props.children Control.
 * @return {JSX.Element} Cell.
 */
function Cell( { label, htmlFor = null, hint = '', wide = false, children } ) {
	return (
		<div className={ `mg-filter ${ wide ? 'mg-filter--wide' : '' }` }>
			{ htmlFor ? (
				<label className="mg-filter__label" htmlFor={ htmlFor }>
					{ label }
				</label>
			) : (
				<span className="mg-filter__label">{ label }</span>
			) }

			{ children }

			{ hint && <span className="mg-filter__hint">{ hint }</span> }
		</div>
	);
}

/**
 * @param {Object}   props                Component props.
 * @param {Array}    props.fields         Filterable field definitions.
 * @param {Object}   props.values         Current selections.
 * @param {Function} props.onChange       Change handler.
 * @param {Array}    props.rightsClasses  Available rights classes.
 * @param {Array}    props.rights         Selected rights codes.
 * @param {Function} props.onRightsChange Rights change handler.
 * @param {Array}    props.creators       Available creators.
 * @param {number}   props.creator        Selected creator ID.
 * @param {Function} props.onCreatorChange Creator change handler.
 * @param {string}   props.dateFrom       Range start (YYYY-MM-DD).
 * @param {string}   props.dateTo         Range end (YYYY-MM-DD).
 * @param {Function} props.onDatesChange  Date range handler.
 * @param {boolean}  props.showAll        Whether restricted assets are shown.
 * @param {Function} props.onShowAll      Show-all handler.
 * @return {JSX.Element} Filter drawer.
 */
export default function FilterBar( {
	fields = [],
	values = {},
	onChange,
	rightsClasses = [],
	rights = [],
	onRightsChange,
	creators = [],
	creator = 0,
	onCreatorChange,
	dateFrom = '',
	dateTo = '',
	onDatesChange,
	showAll,
	onShowAll,
} ) {
	const update = ( name, value ) => {
		const next = { ...values };

		if ( ! value ) {
			delete next[ name ];
		} else {
			next[ name ] = value;
		}

		onChange( next );
	};

	const toggleRight = ( code ) => {
		onRightsChange(
			rights.includes( code ) ? rights.filter( ( item ) => item !== code ) : [ ...rights, code ]
		);
	};

	// Only one date entered is not a filter the API can apply, so say so where
	// the user is looking rather than silently ignoring it.
	const halfRange = Boolean( dateFrom ) !== Boolean( dateTo );

	return (
		<div className="mg-filters">
			{ rightsClasses.length > 0 && (
				<div className="mg-filter mg-filter--wide">
					<fieldset className="mg-filter__group">
						<legend className="mg-filter__label">{ __( 'Rights status', 'mediagraph-assets' ) }</legend>
						<div className="mg-filter__checks">
							{ rightsClasses.map( ( option ) => (
								<label className="mg-filter__check" key={ option.value }>
									<input
										type="checkbox"
										checked={ rights.includes( option.value ) }
										onChange={ () => toggleRight( option.value ) }
									/>
									<span>{ option.label }</span>
								</label>
							) ) }
						</div>
						<span className="mg-filter__hint">
							{ __( 'Selecting more than one matches any of them.', 'mediagraph-assets' ) }
						</span>
					</fieldset>
				</div>
			) }

			{ creators.length > 0 && (
				<Cell label={ __( 'Creator', 'mediagraph-assets' ) } htmlFor="mg-filter-creator">
					<select
						id="mg-filter-creator"
						value={ creator || '' }
						onChange={ ( event ) => onCreatorChange( Number( event.target.value ) || 0 ) }
					>
						<option value="">{ __( 'Anyone', 'mediagraph-assets' ) }</option>
						{ creators.map( ( option ) => (
							<option key={ option.value } value={ option.value }>
								{ option.count === null
									? option.label
									: `${ option.label } (${ option.count.toLocaleString() })` }
							</option>
						) ) }
					</select>
				</Cell>
			) }

			<Cell
				label={ __( 'Date created', 'mediagraph-assets' ) }
				htmlFor="mg-filter-date-from"
				hint={
					halfRange
						? __( 'Set both dates to apply this filter.', 'mediagraph-assets' )
						: __( 'Assets with no capture date are excluded.', 'mediagraph-assets' )
				}
			>
				<div className="mg-filter__range">
					<input
						id="mg-filter-date-from"
						type="date"
						value={ dateFrom }
						max={ dateTo || undefined }
						onChange={ ( event ) => onDatesChange( event.target.value, dateTo ) }
						aria-label={ __( 'Created on or after', 'mediagraph-assets' ) }
					/>
					<span className="mg-filter__range-sep" aria-hidden="true">
						–
					</span>
					<input
						type="date"
						value={ dateTo }
						min={ dateFrom || undefined }
						onChange={ ( event ) => onDatesChange( dateFrom, event.target.value ) }
						aria-label={ __( 'Created on or before', 'mediagraph-assets' ) }
					/>
				</div>
			</Cell>

			<Cell label={ __( 'Options', 'mediagraph-assets' ) }>
				<label className="mg-filter__check">
					<input
						type="checkbox"
						checked={ showAll }
						onChange={ ( event ) => onShowAll( event.target.checked ) }
					/>
					<span>{ __( 'Include assets I cannot download', 'mediagraph-assets' ) }</span>
				</label>
			</Cell>

			{ /* Custom metadata is kept in its own section below everything
			     else. A library can define a great many of these, and mixing
			     them in with the standard filters made it impossible to find
			     the ones that are always there. */ }
			{ fields.length > 0 && (
				<section className="mg-filters__custom">
					<h4 className="mg-filters__custom-title">
						{ __( 'Custom metadata', 'mediagraph-assets' ) }
						<span className="mg-filters__custom-count">{ fields.length }</span>
					</h4>

					<div className="mg-filters__custom-grid">
						{ fields.map( ( field ) => {
							const id = `mg-filter-${ field.name.replace( /\W+/g, '-' ) }`;

							return (
								<Cell label={ field.label } htmlFor={ id } key={ field.name }>
									<select
										id={ id }
										value={ values[ field.name ] || '' }
										onChange={ ( event ) => update( field.name, event.target.value ) }
									>
										<option value="">{ __( 'Any', 'mediagraph-assets' ) }</option>
										<option value={ BLANK }>{ __( '— Not set —', 'mediagraph-assets' ) }</option>
										{ field.values.map( ( value ) => (
											<option key={ value } value={ value }>
												{ value }
											</option>
										) ) }
									</select>
								</Cell>
							);
						} ) }
					</div>
				</section>
			) }
		</div>
	);
}
