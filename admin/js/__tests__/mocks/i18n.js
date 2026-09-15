/**
 * Minimal stand-in for @wordpress/i18n.
 */

const interpolate = ( format, args ) => {
	let index = 0;

	return String( format )
		.replace( /%(\d+)\$s/g, ( _match, position ) => String( args[ Number( position ) - 1 ] ) )
		.replace( /%[sd]/g, () => {
			const value = args[ index ];
			index += 1;

			return String( value );
		} );
};

module.exports = {
	__: ( text ) => text,
	_n: ( single, plural, count ) => ( count === 1 ? single : plural ),
	sprintf: ( format, ...args ) => interpolate( format, args ),
};
