/**
 * Shown instead of the picker when the site has no usable connection.
 *
 * 1.x rendered nothing at all in this case, so clicking the button looked like
 * a dead control.
 */

import { __ } from '@wordpress/i18n';

import { settings } from '../lib/settings';

/**
 * @param {Object}   props         Component props.
 * @param {Function} props.onClose Close handler.
 * @return {JSX.Element} Message.
 */
export default function NotConnected( { onClose } ) {
	const { expired, settingsUrl, canConnect } = settings();

	return (
		<div className="mg-standalone">
			<span className="dashicons dashicons-admin-links mg-standalone__icon" aria-hidden="true" />

			<h2 className="mg-standalone__title">
				{ expired
					? __( 'The Mediagraph connection expired', 'mediagraph-assets' )
					: __( 'Mediagraph is not connected', 'mediagraph-assets' ) }
			</h2>

			<p className="mg-standalone__text">
				{ canConnect
					? __(
							'Reconnect this site to Mediagraph to browse and insert assets.',
							'mediagraph-assets'
					  )
					: __(
							'Ask a site administrator to reconnect this site to Mediagraph.',
							'mediagraph-assets'
					  ) }
			</p>

			<div className="mg-standalone__actions">
				{ canConnect && settingsUrl && (
					<a className="mg-button mg-button--primary" href={ settingsUrl }>
						{ __( 'Open Mediagraph settings', 'mediagraph-assets' ) }
					</a>
				) }

				<button type="button" className="mg-button mg-button--secondary" onClick={ onClose }>
					{ __( 'Close', 'mediagraph-assets' ) }
				</button>
			</div>
		</div>
	);
}
