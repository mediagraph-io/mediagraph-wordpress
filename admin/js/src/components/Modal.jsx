/**
 * Modal shell: backdrop, focus trap, and Escape handling.
 */

import { useEffect, useRef } from '@wordpress/element';

const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * @param {Object}   props          Component props.
 * @param {Function} props.onClose  Close handler.
 * @param {string}   props.label    Accessible label.
 * @param {*}        props.children Modal contents.
 * @return {JSX.Element} Modal.
 */
export default function Modal( { onClose, label, children } ) {
	const dialog = useRef( null );
	const returnFocus = useRef( null );

	useEffect( () => {
		returnFocus.current = document.activeElement;

		const previousOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';

		// Move focus into the dialog so keyboard users are not left behind on
		// the page underneath.
		const first = dialog.current?.querySelector( FOCUSABLE );
		( first || dialog.current )?.focus();

		const onKeyDown = ( event ) => {
			if ( event.key === 'Escape' ) {
				event.stopPropagation();
				onClose();

				return;
			}

			if ( event.key !== 'Tab' || ! dialog.current ) {
				return;
			}

			const focusable = Array.from( dialog.current.querySelectorAll( FOCUSABLE ) ).filter(
				( node ) => node.offsetParent !== null
			);

			if ( focusable.length === 0 ) {
				return;
			}

			const firstNode = focusable[ 0 ];
			const lastNode = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === firstNode ) {
				event.preventDefault();
				lastNode.focus();
			} else if ( ! event.shiftKey && document.activeElement === lastNode ) {
				event.preventDefault();
				firstNode.focus();
			}
		};

		document.addEventListener( 'keydown', onKeyDown, true );

		return () => {
			document.removeEventListener( 'keydown', onKeyDown, true );
			document.body.style.overflow = previousOverflow;

			if ( returnFocus.current && typeof returnFocus.current.focus === 'function' ) {
				returnFocus.current.focus();
			}
		};
	}, [ onClose ] );

	return (
		<div
			className="mg-overlay"
			onMouseDown={ ( event ) => {
				if ( event.target === event.currentTarget ) {
					onClose();
				}
			} }
		>
			<div
				className="mg-dialog"
				role="dialog"
				aria-modal="true"
				aria-label={ label }
				tabIndex={ -1 }
				ref={ dialog }
			>
				{ children }
			</div>
		</div>
	);
}
