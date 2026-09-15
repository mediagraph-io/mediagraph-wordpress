/**
 * Footer bar: selection summary and the insert action.
 */

import { __, sprintf } from '@wordpress/i18n';

/**
 * @param {Object}   props             Component props.
 * @param {Array}    props.selection   Selected assets.
 * @param {boolean}  props.multiple    Whether multi-select is active.
 * @param {boolean}  props.busy        Whether an import is running.
 * @param {string}   props.progress    Progress message.
 * @param {string}   props.insertLabel Insert button label.
 * @param {Function} props.onClear     Clear-selection handler.
 * @param {Function} props.onInsert    Insert handler.
 * @param {Function} props.onCancel    Cancel handler.
 * @return {JSX.Element} Footer.
 */
export default function SelectionFooter( {
	selection,
	multiple,
	busy,
	progress,
	insertLabel,
	onClear,
	onInsert,
	onCancel,
} ) {
	const count = selection.length;

	return (
		<footer className="mg-footer">
			<div className="mg-footer__status" aria-live="polite">
				{ busy && progress ? (
					<span className="mg-footer__progress">
						<span className="dashicons dashicons-update mg-spin" aria-hidden="true" />
						{ progress }
					</span>
				) : count > 0 ? (
					<>
						<span>
							{ sprintf(
								/* translators: %d: number of selected assets. */
								__( '%d selected', 'mediagraph-assets' ),
								count
							) }
						</span>
						{ multiple && (
							<button type="button" className="mg-button mg-button--link" onClick={ onClear }>
								{ __( 'Clear selection', 'mediagraph-assets' ) }
							</button>
						) }
					</>
				) : (
					<span className="mg-footer__hint">
						{ multiple
							? __( 'Select the assets you want to add.', 'mediagraph-assets' )
							: __( 'Select an asset to continue.', 'mediagraph-assets' ) }
					</span>
				) }
			</div>

			<div className="mg-footer__actions">
				<button
					type="button"
					className="mg-button mg-button--secondary"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ __( 'Cancel', 'mediagraph-assets' ) }
				</button>

				<button
					type="button"
					className="mg-button mg-button--primary"
					onClick={ onInsert }
					disabled={ busy || count === 0 }
				>
					{ insertLabel }
				</button>
			</div>
		</footer>
	);
}
