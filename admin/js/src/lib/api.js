/**
 * AJAX transport.
 *
 * Every call is abortable, so switching folders or typing in search can never
 * leave a slow earlier response to land last and overwrite fresher results.
 */

import { __ } from '@wordpress/i18n';
import { settings } from './settings';

/**
 * Error carrying the server's message and code.
 */
export class ApiError extends Error {
	/**
	 * @param {string} message Human readable message.
	 * @param {string} code    Machine readable code.
	 * @param {number} status  HTTP status.
	 */
	constructor( message, code = 'mediagraph_error', status = 0 ) {
		super( message );
		this.name = 'ApiError';
		this.code = code;
		this.status = status;
	}

	/**
	 * Whether the failure means the site needs to reconnect.
	 *
	 * @return {boolean} True for auth failures.
	 */
	get isAuthError() {
		return (
			this.status === 401 ||
			this.code === 'mediagraph_session_expired' ||
			this.code === 'mediagraph_not_connected'
		);
	}
}

/**
 * Thrown when a request was deliberately cancelled.
 */
export class AbortedError extends Error {
	constructor() {
		super( 'aborted' );
		this.name = 'AbortedError';
	}
}

/**
 * Perform an admin-ajax request.
 *
 * @param {string} action        Action suffix, e.g. "search".
 * @param {Object} data          Payload.
 * @param {Object} options       Options.
 * @param {AbortSignal} options.signal Abort signal.
 * @return {Promise<Object>} Resolved payload.
 */
export async function request( action, data = {}, { signal } = {} ) {
	const { ajaxUrl, nonce } = settings();

	if ( ! ajaxUrl || ! nonce ) {
		throw new ApiError(
			__(
				'The Mediagraph plugin did not load correctly. Reload the page and try again.',
				'mediagraph-assets'
			),
			'mediagraph_not_loaded'
		);
	}

	const body = new URLSearchParams();
	body.set( 'action', `mediagraph_${ action }` );
	body.set( 'nonce', nonce );

	Object.entries( data ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null ) {
			return;
		}

		body.set(
			key,
			typeof value === 'object' ? JSON.stringify( value ) : String( value )
		);
	} );

	let response;

	try {
		response = await fetch( ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body,
			signal,
		} );
	} catch ( error ) {
		if ( error && error.name === 'AbortError' ) {
			throw new AbortedError();
		}

		throw new ApiError(
			__(
				'WordPress could not reach the server. Check your connection and try again.',
				'mediagraph-assets'
			),
			'mediagraph_network'
		);
	}

	let payload;

	try {
		payload = await response.json();
	} catch ( error ) {
		throw new ApiError(
			__(
				'The server returned a response WordPress could not read.',
				'mediagraph-assets'
			),
			'mediagraph_bad_response',
			response.status
		);
	}

	if ( ! payload || payload.success !== true ) {
		const detail = payload && payload.data ? payload.data : {};

		throw new ApiError(
			detail.message ||
				__( 'Something went wrong. Please try again.', 'mediagraph-assets' ),
			detail.code || 'mediagraph_error',
			response.status
		);
	}

	return payload.data;
}

/**
 * Connection status.
 *
 * @param {Object} options Request options.
 * @return {Promise<Object>} Status payload.
 */
export function fetchStatus( options ) {
	return request( 'status', {}, options );
}

/**
 * Root containers.
 *
 * @param {boolean} refresh Bypass the server cache.
 * @param {Object}  options Request options.
 * @return {Promise<Object>} Containers grouped by kind.
 */
export function fetchContainers( refresh, options ) {
	return request( 'containers', { refresh: refresh ? '1' : '0' }, options );
}

/**
 * Children of a container.
 *
 * @param {Object} node    Container node.
 * @param {Object} options Request options.
 * @return {Promise<Array>} Child nodes.
 */
export function fetchChildren( node, options ) {
	return request(
		'container_children',
		{
			parent_id: node.id,
			parent_type: node.type,
			sub_type: node.sub_type || '',
		},
		options
	);
}

/**
 * Asset search.
 *
 * @param {Object} query   Search state.
 * @param {Object} options Request options.
 * @return {Promise<Object>} Results.
 */
export function searchAssets( query, options ) {
	const [ sort, order ] = ( query.sort || 'created_at:desc' ).split( ':' );

	return request(
		'search',
		{
			q: query.search || '',
			container_id: query.container ? query.container.id : 0,
			container: query.container ? query.container.type : '',
			sub_type: query.container ? query.container.sub_type || '' : '',
			types: query.types || [],
			custom_meta: query.customMeta || {},
			rights: query.rights || [],
			creator_id: query.creator || 0,
			date_from: query.dateFrom || '',
			date_to: query.dateTo || '',
			sort,
			order,
			show_all: query.showAll ? '1' : '0',
			page: query.page || 1,
			per_page: query.perPage || 40,
		},
		options
	);
}

/**
 * Single asset detail.
 *
 * @param {number} assetId Asset ID.
 * @param {Object} options Request options.
 * @return {Promise<Object>} Asset.
 */
export function fetchAsset( assetId, options ) {
	return request( 'asset', { asset_id: assetId }, options );
}

/**
 * Filterable custom metadata fields.
 *
 * @param {Object} options Request options.
 * @return {Promise<Object>} Fields payload.
 */
export function fetchCustomFields( options ) {
	return request( 'custom_fields', {}, options );
}

/**
 * Creators available for filtering.
 *
 * @param {Object} options Request options.
 * @return {Promise<Object>} Creators payload.
 */
export function fetchCreators( options ) {
	return request( 'creators', {}, options );
}

/**
 * Import one asset.
 *
 * @param {Object} payload Import arguments.
 * @param {Object} options Request options.
 * @return {Promise<Object>} Attachment payload.
 */
export function importAsset( payload, options ) {
	return request(
		'import',
		{
			asset_id: payload.assetId,
			rendition: payload.rendition,
			post_id: payload.postId || 0,
			metadata: payload.metadata || {},
		},
		options
	);
}

/**
 * Import several assets.
 *
 * @param {Object} payload Import arguments.
 * @param {Object} options Request options.
 * @return {Promise<Object>} Attachments and failures.
 */
export function importAssets( payload, options ) {
	return request(
		'import_batch',
		{
			asset_ids: payload.assetIds,
			rendition: payload.rendition,
			post_id: payload.postId || 0,
			// Per-asset edits, keyed by asset ID. Omitting this dropped every
			// caption typed during a multi-select before the batch ran.
			metadata: payload.metadata || {},
		},
		options
	);
}
