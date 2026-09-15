/**
 * Access to the values PHP localized onto the page.
 *
 * Everything is read through these helpers so a missing global degrades into a
 * readable message instead of a TypeError. 1.x dereferenced
 * window.mediagraphPicker inside the very branch that checked it was missing.
 */

const FALLBACK = {
	ajaxUrl: '',
	nonce: '',
	logoUrl: '',
	connected: false,
	expired: false,
	organization: '',
	settingsUrl: '',
	canConnect: false,
	canUpload: false,
	blocks: {},
	defaults: { perPage: 40, rendition: 'full' },
	sortOptions: [],
	rightsClasses: [],
	renditions: [],
};

/**
 * The localized settings object, or a safe default.
 *
 * @return {Object} Settings.
 */
export function settings() {
	if ( typeof window === 'undefined' || ! window.mediagraphSettings ) {
		return FALLBACK;
	}

	return { ...FALLBACK, ...window.mediagraphSettings };
}

/**
 * Whether the plugin has everything it needs to talk to the API.
 *
 * @return {boolean} True when a request can be attempted.
 */
export function isConfigured() {
	const { ajaxUrl, nonce } = settings();

	return Boolean( ajaxUrl && nonce );
}

/**
 * Whether the site currently has a usable Mediagraph connection.
 *
 * @return {boolean} True when connected.
 */
export function isConnected() {
	return Boolean( settings().connected );
}

/**
 * Support configuration for a block name.
 *
 * @param {string} blockName Block name, e.g. "core/image".
 * @return {Object|null} Support config, or null when unsupported.
 */
export function blockSupport( blockName ) {
	const { blocks } = settings();

	return blocks && blocks[ blockName ] ? blocks[ blockName ] : null;
}

/**
 * Default rendition to download.
 *
 * @return {string} Rendition name.
 */
export function defaultRendition() {
	return settings().defaults?.rendition || 'full';
}

/**
 * Default page size.
 *
 * @return {number} Assets per page.
 */
export function defaultPerPage() {
	return Number( settings().defaults?.perPage ) || 40;
}

/**
 * URL of the Mediagraph mark shipped with the plugin.
 *
 * @return {string} Logo URL.
 */
export function logoUrl() {
	return settings().logoUrl || '';
}
