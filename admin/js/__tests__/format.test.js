import { date, dimensions, duration, fileSize, resultRange } from '../src/lib/format';

describe( 'fileSize', () => {
	it( 'returns an empty string for missing or zero sizes', () => {
		expect( fileSize( 0 ) ).toBe( '' );
		expect( fileSize( undefined ) ).toBe( '' );
		expect( fileSize( null ) ).toBe( '' );
	} );

	it( 'formats bytes, kilobytes, and megabytes', () => {
		expect( fileSize( 512 ) ).toBe( '512 B' );
		expect( fileSize( 2048 ) ).toBe( '2 KB' );
		expect( fileSize( 1536 * 1024 ) ).toBe( '1.5 MB' );
	} );

	it( 'steps up to gigabytes for very large originals', () => {
		expect( fileSize( 3 * 1024 * 1024 * 1024 ) ).toBe( '3 GB' );
	} );
} );

describe( 'duration', () => {
	it( 'returns an empty string when there is no duration', () => {
		expect( duration( 0 ) ).toBe( '' );
		expect( duration( undefined ) ).toBe( '' );
	} );

	it( 'formats minutes and seconds with a zero-padded seconds field', () => {
		expect( duration( 65 ) ).toBe( '1:05' );
	} );

	it( 'includes hours for long video', () => {
		expect( duration( 3725 ) ).toBe( '1:02:05' );
	} );
} );

describe( 'dimensions', () => {
	it( 'formats width and height from the top-level fields', () => {
		expect( dimensions( 1920, 1080 ) ).toBe( '1,920 × 1,080' );
	} );

	it( 'returns an empty string when either side is missing', () => {
		// The API has no `dimensions` object, so a missing width is the normal
		// signal that this asset has no pixel size (audio, documents).
		expect( dimensions( 1920, undefined ) ).toBe( '' );
		expect( dimensions( 0, 0 ) ).toBe( '' );
	} );
} );

describe( 'date', () => {
	it( 'returns an empty string for missing or unparseable values', () => {
		expect( date( '' ) ).toBe( '' );
		expect( date( 'not a date' ) ).toBe( '' );
	} );

	it( 'formats a valid ISO date', () => {
		expect( date( '2026-03-14T10:00:00Z' ) ).toMatch( /2026/ );
	} );
} );

describe( 'resultRange', () => {
	it( 'reports an empty result set', () => {
		expect( resultRange( 1, 40, 0 ) ).toBe( 'No assets' );
	} );

	it( 'reports a plain total when everything fits on one page', () => {
		expect( resultRange( 1, 40, 12 ) ).toBe( '12 assets' );
	} );

	it( 'reports the visible range when there are several pages', () => {
		expect( resultRange( 2, 40, 320 ) ).toBe( '41–80 of 320 assets' );
	} );

	it( 'clamps the upper bound on the final page', () => {
		expect( resultRange( 3, 40, 90 ) ).toBe( '81–90 of 90 assets' );
	} );
} );
