/**
 * Minimal stand-in for @wordpress/element.
 *
 * Only the pieces the unit-tested modules touch are provided; component
 * rendering is not exercised here.
 */

module.exports = {
	createElement: ( type, props, ...children ) => ( { type, props, children } ),
	Fragment: 'Fragment',
	createRoot: () => ( { render: () => {}, unmount: () => {} } ),
	useState: () => [ undefined, () => {} ],
	useEffect: () => {},
	useRef: () => ( { current: null } ),
	useCallback: ( fn ) => fn,
	useMemo: ( fn ) => fn(),
};
