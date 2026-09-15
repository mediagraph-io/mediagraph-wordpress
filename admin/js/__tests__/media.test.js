import {
	TOOLBAR_BLOCKS,
	attachmentIdFor,
	attributesForBlock,
	kindsFromAllowedTypes,
	toMediaObject,
} from '../src/lib/media';

describe( 'kindsFromAllowedTypes', () => {
	it( 'returns no restriction when the block accepts anything', () => {
		expect( kindsFromAllowedTypes( undefined ) ).toEqual( [] );
		expect( kindsFromAllowedTypes( [] ) ).toEqual( [] );
	} );

	it( 'maps broad types straight through', () => {
		expect( kindsFromAllowedTypes( [ 'image' ] ) ).toEqual( [ 'image' ] );
		expect( kindsFromAllowedTypes( [ 'video' ] ) ).toEqual( [ 'video' ] );
		expect( kindsFromAllowedTypes( [ 'audio' ] ) ).toEqual( [ 'audio' ] );
	} );

	it( 'maps full MIME types to their base kind', () => {
		expect( kindsFromAllowedTypes( [ 'image/jpeg', 'image/png' ] ) ).toEqual( [ 'image' ] );
	} );

	it( 'treats anything else as a document', () => {
		expect( kindsFromAllowedTypes( [ 'application/pdf' ] ) ).toEqual( [ 'document' ] );
	} );

	it( 'maps text/* to its own kind', () => {
		// Mediagraph classifies .txt and friends as "Text", a class distinct
		// from "Document". The search API has no filter value for it, so
		// conflating the two would make a Document filter silently drop them.
		expect( kindsFromAllowedTypes( [ 'text/plain' ] ) ).toEqual( [ 'text' ] );
	} );

	it( 'keeps every distinct kind for blocks that accept a mix', () => {
		expect( kindsFromAllowedTypes( [ 'image', 'video' ] ) ).toEqual( [ 'image', 'video' ] );
	} );
} );

describe( 'toMediaObject', () => {
	const attachment = {
		id: 42,
		url: 'https://example.com/wp-content/uploads/photo.jpg',
		link: 'https://example.com/photo/',
		mime: 'image/jpeg',
		title: 'Harbour at dawn',
		caption: 'Shot on the north pier',
		alt: 'Fishing boats in fog',
		description: 'Jane Doe / Mediagraph',
		width: 2400,
		height: 1600,
		sizes: { thumbnail: { url: 'https://example.com/thumb.jpg', width: 150, height: 150 } },
	};

	it( 'splits the MIME type into type and subtype', () => {
		const media = toMediaObject( attachment );

		expect( media.type ).toBe( 'image' );
		expect( media.subtype ).toBe( 'jpeg' );
	} );

	it( 'carries the attachment ID through so blocks get a real attachment', () => {
		expect( toMediaObject( attachment ).id ).toBe( 42 );
	} );

	it( 'exposes sizes under both keys core reads', () => {
		const media = toMediaObject( attachment );

		expect( media.sizes.thumbnail.url ).toBe( 'https://example.com/thumb.jpg' );
		expect( media.media_details.sizes.thumbnail.width ).toBe( 150 );
	} );

	it( 'defaults missing text fields to empty strings rather than undefined', () => {
		const media = toMediaObject( { id: 1, url: 'x', mime: 'image/png' } );

		expect( media.alt ).toBe( '' );
		expect( media.caption ).toBe( '' );
		expect( media.title ).toBe( '' );
	} );
} );

describe( 'attributesForBlock', () => {
	const image = toMediaObject( {
		id: 7,
		url: 'https://example.com/a.jpg',
		mime: 'image/jpeg',
		alt: 'Alt text',
		caption: 'A caption',
		title: 'A title',
	} );

	const video = toMediaObject( {
		id: 8,
		url: 'https://example.com/a.mp4',
		mime: 'video/mp4',
	} );

	it( 'sets id and url on an image block so core owns rendering', () => {
		expect( attributesForBlock( 'core/image', image ) ).toEqual( {
			id: 7,
			url: 'https://example.com/a.jpg',
			alt: 'Alt text',
			caption: 'A caption',
		} );
	} );

	it( 'uses the mediaId family of attributes for Media & Text', () => {
		expect( attributesForBlock( 'core/media-text', image ) ).toEqual( {
			mediaId: 7,
			mediaUrl: 'https://example.com/a.jpg',
			mediaAlt: 'Alt text',
			mediaType: 'image',
		} );
	} );

	it( 'switches Media & Text to video when the asset is a video', () => {
		expect( attributesForBlock( 'core/media-text', video ).mediaType ).toBe( 'video' );
	} );

	it( 'sets the cover background type from the asset kind', () => {
		expect( attributesForBlock( 'core/cover', video ).backgroundType ).toBe( 'video' );
		expect( attributesForBlock( 'core/cover', image ).backgroundType ).toBe( 'image' );
	} );

	it( 'clears useFeaturedImage so a chosen cover asset actually shows', () => {
		expect( attributesForBlock( 'core/cover', image ).useFeaturedImage ).toBe( false );
	} );

	it( 'uses src rather than url for audio and video blocks', () => {
		expect( attributesForBlock( 'core/video', video ) ).toEqual( {
			id: 8,
			src: 'https://example.com/a.mp4',
			caption: '',
		} );
	} );

	it( 'sets both href and textLinkHref on a file block', () => {
		const attributes = attributesForBlock( 'core/file', image );

		expect( attributes.href ).toBe( 'https://example.com/a.jpg' );
		expect( attributes.textLinkHref ).toBe( 'https://example.com/a.jpg' );
		expect( attributes.fileName ).toBe( 'A title' );
	} );

	it( 'falls back to id and url for an unrecognised block', () => {
		expect( attributesForBlock( 'acme/custom', image ) ).toEqual( {
			id: 7,
			url: 'https://example.com/a.jpg',
		} );
	} );
} );

describe( 'attachmentIdFor', () => {
	it( 'reads the standard id attribute', () => {
		expect( attachmentIdFor( 'core/image', { id: 42 } ) ).toBe( 42 );
	} );

	it( 'reads mediaId for Media & Text, which does not use id', () => {
		expect( attachmentIdFor( 'core/media-text', { mediaId: 7, id: 99 } ) ).toBe( 7 );
	} );

	it( 'returns 0 when the block has no media yet', () => {
		expect( attachmentIdFor( 'core/image', {} ) ).toBe( 0 );
		expect( attachmentIdFor( 'core/image', { id: undefined } ) ).toBe( 0 );
		expect( attachmentIdFor( 'core/image', null ) ).toBe( 0 );
	} );

	it( 'ignores a non-numeric id rather than producing NaN', () => {
		expect( attachmentIdFor( 'core/image', { id: 'abc' } ) ).toBe( 0 );
	} );
} );

describe( 'TOOLBAR_BLOCKS', () => {
	const image = toMediaObject( {
		id: 7,
		url: 'https://example.com/a.jpg',
		mime: 'image/jpeg',
		alt: 'Alt text',
		caption: 'A caption',
	} );

	// core/icon sits in the editor's "media" category but stores a string
	// naming a built-in SVG, with no id or url attribute to receive an
	// attachment. Offering Mediagraph there could only set attributes the block
	// does not have.
	it( 'leaves out the Icon block, which holds no uploaded file', () => {
		expect( TOOLBAR_BLOCKS ).not.toHaveProperty( 'core/icon' );
	} );

	it( 'covers the media blocks that do take an attachment', () => {
		expect( Object.keys( TOOLBAR_BLOCKS ).sort() ).toEqual( [
			'core/audio',
			'core/cover',
			'core/file',
			'core/gallery',
			'core/image',
			'core/media-text',
			'core/site-logo',
			'core/video',
		] );
	} );

	it( 'does not map Icon onto the image attribute set', () => {
		// Falls through to the default branch rather than the core/image case.
		expect( attributesForBlock( 'core/icon', image ) ).toEqual( {
			id: 7,
			url: 'https://example.com/a.jpg',
		} );
	} );
} );
