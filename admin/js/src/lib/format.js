/**
 * Display formatting helpers.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * Human readable file size.
 *
 * @param {number} bytes Size in bytes.
 * @return {string} Formatted size, or an empty string.
 */
export function fileSize( bytes ) {
	const value = Number( bytes );

	if ( ! value || value < 0 ) {
		return '';
	}

	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	let index = 0;
	let size = value;

	while ( size >= 1024 && index < units.length - 1 ) {
		size /= 1024;
		index += 1;
	}

	const rounded = index === 0 || size >= 100 ? Math.round( size ) : Math.round( size * 10 ) / 10;

	return `${ rounded } ${ units[ index ] }`;
}

/**
 * Human readable duration.
 *
 * @param {number} seconds Duration in seconds.
 * @return {string} Formatted duration, or an empty string.
 */
export function duration( seconds ) {
	const total = Math.floor( Number( seconds ) || 0 );

	if ( total <= 0 ) {
		return '';
	}

	const hours = Math.floor( total / 3600 );
	const minutes = Math.floor( ( total % 3600 ) / 60 );
	const secs = total % 60;
	const pad = ( n ) => String( n ).padStart( 2, '0' );

	if ( hours > 0 ) {
		return `${ hours }:${ pad( minutes ) }:${ pad( secs ) }`;
	}

	return `${ minutes }:${ pad( secs ) }`;
}

/**
 * Pixel dimensions.
 *
 * @param {number} width  Width.
 * @param {number} height Height.
 * @return {string} Formatted dimensions, or an empty string.
 */
export function dimensions( width, height ) {
	const w = Number( width );
	const h = Number( height );

	if ( ! w || ! h ) {
		return '';
	}

	return `${ w.toLocaleString() } × ${ h.toLocaleString() }`;
}

/**
 * Localized date.
 *
 * @param {string} value ISO date string.
 * @return {string} Formatted date, or an empty string.
 */
export function date( value ) {
	if ( ! value ) {
		return '';
	}

	const parsed = new Date( value );

	if ( Number.isNaN( parsed.getTime() ) ) {
		return '';
	}

	return parsed.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
}

/**
 * "1–40 of 320 assets" style range label.
 *
 * @param {number} page    Current page.
 * @param {number} perPage Page size.
 * @param {number} total   Total results.
 * @return {string} Range label.
 */
export function resultRange( page, perPage, total ) {
	if ( ! total ) {
		return __( 'No assets', 'mediagraph-assets' );
	}

	if ( total <= perPage ) {
		return sprintf(
			/* translators: %s: number of assets. */
			__( '%s assets', 'mediagraph-assets' ),
			total.toLocaleString()
		);
	}

	const first = ( page - 1 ) * perPage + 1;
	const last = Math.min( page * perPage, total );

	return sprintf(
		/* translators: 1: first result, 2: last result, 3: total results. */
		__( '%1$s–%2$s of %3$s assets', 'mediagraph-assets' ),
		first.toLocaleString(),
		last.toLocaleString(),
		total.toLocaleString()
	);
}

/**
 * Label for an asset kind.
 *
 * @param {string} kind Asset kind.
 * @return {string} Label.
 */
export function kindLabel( kind ) {
	switch ( kind ) {
		case 'image':
			return __( 'Image', 'mediagraph-assets' );
		case 'video':
			return __( 'Video', 'mediagraph-assets' );
		case 'audio':
			return __( 'Audio', 'mediagraph-assets' );
		case 'font':
			return __( 'Font', 'mediagraph-assets' );
		case 'text':
			return __( 'Text', 'mediagraph-assets' );
		default:
			return __( 'Document', 'mediagraph-assets' );
	}
}
