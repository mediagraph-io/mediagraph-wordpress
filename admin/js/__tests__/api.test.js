import { ApiError, request, searchAssets } from '../src/lib/api';

/**
 * Read the URL-encoded body Jest captured from fetch.
 *
 * @return {URLSearchParams} Body.
 */
function lastBody() {
	return global.fetch.mock.calls[ 0 ][ 1 ].body;
}

describe( 'request', () => {
	beforeEach( () => {
		window.mediagraphSettings = {
			ajaxUrl: '/wp-admin/admin-ajax.php',
			nonce: 'test-nonce',
			connected: true,
		};

		global.fetch = jest.fn( () =>
			Promise.resolve( {
				status: 200,
				json: () => Promise.resolve( { success: true, data: { ok: true } } ),
			} )
		);
	} );

	afterEach( () => {
		delete window.mediagraphSettings;
		delete global.fetch;
	} );

	it( 'prefixes the action and sends the nonce', async () => {
		await request( 'search', {} );

		expect( lastBody().get( 'action' ) ).toBe( 'mediagraph_search' );
		expect( lastBody().get( 'nonce' ) ).toBe( 'test-nonce' );
	} );

	it( 'JSON-encodes object values so PHP can decode them', async () => {
		await request( 'search', { types: [ 'image', 'video' ] } );

		expect( lastBody().get( 'types' ) ).toBe( '["image","video"]' );
	} );

	it( 'omits null and undefined values instead of sending "null"', async () => {
		await request( 'search', { q: null, page: undefined, sort: 'filename' } );

		expect( lastBody().has( 'q' ) ).toBe( false );
		expect( lastBody().has( 'page' ) ).toBe( false );
		expect( lastBody().get( 'sort' ) ).toBe( 'filename' );
	} );

	it( 'sends credentials so the nonce is checked against the right user', async () => {
		await request( 'search', {} );

		expect( global.fetch.mock.calls[ 0 ][ 1 ].credentials ).toBe( 'same-origin' );
	} );

	it( 'raises the server message on a failure response', async () => {
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				status: 403,
				json: () =>
					Promise.resolve( {
						success: false,
						data: { message: 'Nope', code: 'mediagraph_forbidden' },
					} ),
			} )
		);

		await expect( request( 'search', {} ) ).rejects.toThrow( 'Nope' );
	} );

	it( 'flags auth failures so the UI can offer a reconnect link', async () => {
		global.fetch = jest.fn( () =>
			Promise.resolve( {
				status: 401,
				json: () =>
					Promise.resolve( {
						success: false,
						data: { message: 'Expired', code: 'mediagraph_session_expired' },
					} ),
			} )
		);

		await expect( request( 'search', {} ) ).rejects.toMatchObject( { code: 'mediagraph_session_expired' } );
	} );

	it( 'fails clearly when the plugin never localized its settings', async () => {
		delete window.mediagraphSettings;

		await expect( request( 'search', {} ) ).rejects.toBeInstanceOf( ApiError );
		expect( global.fetch ).not.toHaveBeenCalled();
	} );
} );

describe( 'searchAssets', () => {
	beforeEach( () => {
		window.mediagraphSettings = { ajaxUrl: '/ajax', nonce: 'n', connected: true };

		global.fetch = jest.fn( () =>
			Promise.resolve( {
				status: 200,
				json: () => Promise.resolve( { success: true, data: { assets: [] } } ),
			} )
		);
	} );

	afterEach( () => {
		delete window.mediagraphSettings;
		delete global.fetch;
	} );

	it( 'splits the combined sort value into sort and order', async () => {
		await searchAssets( { sort: 'filename:asc' } );

		expect( lastBody().get( 'sort' ) ).toBe( 'filename' );
		expect( lastBody().get( 'order' ) ).toBe( 'asc' );
	} );

	it( 'defaults to newest first', async () => {
		await searchAssets( {} );

		expect( lastBody().get( 'sort' ) ).toBe( 'created_at' );
		expect( lastBody().get( 'order' ) ).toBe( 'desc' );
	} );

	it( 'sends the container id and type together', async () => {
		await searchAssets( { container: { id: 12, type: 'Lightbox', sub_type: 'folder' } } );

		expect( lastBody().get( 'container_id' ) ).toBe( '12' );
		expect( lastBody().get( 'container' ) ).toBe( 'Lightbox' );
		expect( lastBody().get( 'sub_type' ) ).toBe( 'folder' );
	} );

	it( 'passes custom metadata filters as a JSON map', async () => {
		await searchAssets( { customMeta: { Region: 'North' } } );

		expect( lastBody().get( 'custom_meta' ) ).toBe( '{"Region":"North"}' );
	} );
} );
