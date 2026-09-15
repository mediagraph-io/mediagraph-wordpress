/**
 * Translation between Mediagraph assets, WordPress attachments, and the shapes
 * core's media components expect.
 */

/**
 * Turn a block's allowedTypes into Mediagraph asset kinds.
 *
 * allowedTypes entries can be broad ("image") or a full MIME type
 * ("application/pdf"), and may be absent entirely, which means "anything".
 *
 * @param {Array} allowedTypes Types the block accepts.
 * @return {Array} Asset kinds. Empty means no restriction.
 */
export function kindsFromAllowedTypes( allowedTypes ) {
	if ( ! Array.isArray( allowedTypes ) || allowedTypes.length === 0 ) {
		return [];
	}

	const kinds = [];

	allowedTypes.forEach( ( type ) => {
		const base = String( type ).split( '/' )[ 0 ].toLowerCase();
		const kind = [ 'image', 'video', 'audio', 'text' ].includes( base ) ? base : 'document';

		if ( ! kinds.includes( kind ) ) {
			kinds.push( kind );
		}
	} );

	return kinds;
}

/**
 * Shape an imported attachment the way core's media components expect.
 *
 * @param {Object} attachment Payload from the import endpoint.
 * @return {Object} Media object.
 */
export function toMediaObject( attachment ) {
	const mime = attachment.mime || '';
	const [ type = '', subtype = '' ] = mime.split( '/' );

	return {
		id: attachment.id,
		url: attachment.url,
		link: attachment.link,
		alt: attachment.alt || '',
		caption: attachment.caption || '',
		title: attachment.title || '',
		description: attachment.description || '',
		mime,
		type,
		subtype,
		width: attachment.width || undefined,
		height: attachment.height || undefined,
		sizes: attachment.sizes || {},
		media_type: type === 'image' ? 'image' : 'file',
		media_details: {
			width: attachment.width || undefined,
			height: attachment.height || undefined,
			sizes: attachment.sizes || {},
		},
	};
}

/**
 * Attributes to set on a core block for a chosen media object.
 *
 * Returning a plain object keeps this testable without a block editor.
 *
 * @param {string} blockName Block name.
 * @param {Object} media     Media object.
 * @return {Object} Attributes to apply.
 */
export function attributesForBlock( blockName, media ) {
	switch ( blockName ) {
		case 'core/image':
		case 'core/site-logo':
			return {
				id: media.id,
				url: media.url,
				alt: media.alt,
				caption: media.caption,
			};

		case 'core/cover':
			return {
				id: media.id,
				url: media.url,
				alt: media.alt,
				backgroundType: media.type === 'video' ? 'video' : 'image',
				useFeaturedImage: false,
			};

		case 'core/media-text':
			return {
				mediaId: media.id,
				mediaUrl: media.url,
				mediaAlt: media.alt,
				mediaType: media.type === 'video' ? 'video' : 'image',
			};

		case 'core/audio':
		case 'core/video':
			return {
				id: media.id,
				src: media.url,
				caption: media.caption,
			};

		case 'core/file':
			return {
				id: media.id,
				href: media.url,
				fileName: media.title || media.url,
				textLinkHref: media.url,
			};

		default:
			return {
				id: media.id,
				url: media.url,
			};
	}
}

/**
 * Read the attachment ID a block is pointing at.
 *
 * Most core media blocks store it as `id`; Media & Text uses `mediaId`.
 *
 * @param {string} blockName  Block name.
 * @param {Object} attributes Block attributes.
 * @return {number} Attachment ID, or 0 when the block has no media.
 */
export function attachmentIdFor( blockName, attributes ) {
	if ( ! attributes ) {
		return 0;
	}

	const raw = blockName === 'core/media-text' ? attributes.mediaId : attributes.id;
	const id = Number( raw );

	return Number.isFinite( id ) ? id || 0 : 0;
}

/**
 * Blocks that get a Mediagraph toolbar button, mapped to the asset kinds each
 * one accepts.
 *
 * `core/icon` is deliberately absent. Despite sitting in the editor's "media"
 * category, it does not hold an uploaded file: its only content attribute is
 * `icon`, a string naming an entry in WordPress's built-in SVG icon library.
 * There is no `id` or `url` to hand an attachment to, so a Mediagraph option
 * there could only ever set attributes the block does not have.
 */
export const TOOLBAR_BLOCKS = {
	'core/image': [ 'image' ],
	'core/gallery': [ 'image' ],
	'core/audio': [ 'audio' ],
	'core/video': [ 'video' ],
	'core/cover': [ 'image', 'video' ],
	'core/media-text': [ 'image', 'video' ],
	'core/file': [],
	'core/site-logo': [ 'image' ],
};
