/**
 * Inline notice.
 *
 * Notices sit above the grid rather than replacing it, so an error never
 * discards results the user was already looking at.
 */

import { __ } from '@wordpress/i18n';

/**
 * @param {Object}   props           Component props.
 * @param {string}   props.type      "error", "warning", or "info".
 * @param {Object}   props.action    Optional action with label plus href or onClick.
 * @param {Function} props.onDismiss Optional dismiss handler.
 * @param {*}        props.children  Message.
 * @return {JSX.Element} Notice.
 */
export default function Notice( { type = 'info', action = null, onDismiss = null, children } ) {
	return (
		<div className={ `mg-notice mg-notice--${ type }` } role={ type === 'error' ? 'alert' : 'status' }>
			<span className="mg-notice__text">{ children }</span>

			{ action && action.href && (
				<a className="mg-notice__action" href={ action.href }>
					{ action.label }
				</a>
			) }

			{ action && ! action.href && action.onClick && (
				<button type="button" className="mg-notice__action" onClick={ action.onClick }>
					{ action.label }
				</button>
			) }

			{ onDismiss && (
				<button
					type="button"
					className="mg-notice__dismiss"
					onClick={ onDismiss }
					aria-label={ __( 'Dismiss', 'mediagraph-assets' ) }
				>
					<span aria-hidden="true">×</span>
				</button>
			) }
		</div>
	);
}
