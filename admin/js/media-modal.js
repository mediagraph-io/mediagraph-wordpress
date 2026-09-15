/**
 * Mediagraph tab inside the WordPress media modal.
 *
 * Registered through wp.media.view.MediaFrame.Select.prototype so the frame
 * constructor is left alone. 1.x reassigned the constructor itself, which
 * conflicts with any other plugin doing the same and breaks depending on
 * script order.
 *
 * The tab imports the chosen asset and then hands the resulting attachment to
 * the frame's own selection, so "Insert into post", "Set featured image", and
 * every other frame behaviour work unchanged.
 */

( function ( $, wp ) {
	'use strict';

	if ( ! wp || ! wp.media || ! wp.media.view || ! wp.media.view.MediaFrame ) {
		return;
	}

	var __ = wp.i18n.__;
	var STATE_ID = 'mediagraph';

	/**
	 * The media library tab WordPress should treat as the default.
	 *
	 * Captured once at load. If a previous session persisted our own tab, that
	 * value is discarded so affected users are repaired rather than stuck.
	 */
	var libraryMode = ( function () {
		var saved = typeof window.getUserSetting === 'function'
			? window.getUserSetting( 'libraryContent' )
			: '';

		if ( ! saved || saved === STATE_ID ) {
			// 'upload' is core's own fallback for this setting.
			saved = 'upload';

			if ( typeof window.setUserSetting === 'function' ) {
				window.setUserSetting( 'libraryContent', saved );
			}
		}

		return saved;
	} )();

	/**
	 * Put the remembered library tab back after our tab has been shown.
	 *
	 * @return {void}
	 */
	function restoreLibraryMode() {
		if ( typeof window.setUserSetting === 'function' ) {
			window.setUserSetting( 'libraryContent', libraryMode );
		}
	}

	/**
	 * Content view that mounts the React picker.
	 */
	wp.media.view.Mediagraph = wp.media.View.extend( {
		className: 'mediagraph-modal-tab',

		initialize: function () {
			this.frame = this.options.frame;
			this.handle = null;
		},

		render: function () {
			this.mount();

			return this;
		},

		/**
		 * Mount the picker into this view's own element.
		 *
		 * The node is already attached by the time render() runs, so there is
		 * no need for the setTimeout guesswork 1.x relied on.
		 */
		mount: function () {
			var self = this;

			if ( this.handle ) {
				return;
			}

			if ( ! window.Mediagraph || typeof window.Mediagraph.mountInline !== 'function' ) {
				this.$el.html(
					$( '<div class="mediagraph-modal-error"></div>' ).text(
						__(
							'The Mediagraph picker did not load. Reload the page and try again.',
							'mediagraph-assets'
						)
					)
				);

				return;
			}

			this.handle = window.Mediagraph.mountInline( this.el, {
				types: this.allowedKinds(),
				multiple: this.allowsMultiple(),
				onSelect: function ( attachments ) {
					self.applySelection( attachments || [] );
				},
				onCancel: function () {
					self.frame.close();
				},
			} );
		},

		/**
		 * Asset kinds the current frame accepts.
		 *
		 * @return {Array} Kinds.
		 */
		allowedKinds: function () {
			var library = this.frame.state() && this.frame.state().get( 'library' );
			var type = library && library.props ? library.props.get( 'type' ) : null;

			if ( ! type ) {
				return [];
			}

			var types = Array.isArray( type ) ? type : [ type ];

			return types
				.map( function ( value ) {
					var base = String( value ).split( '/' )[ 0 ];

					return [ 'image', 'video', 'audio' ].indexOf( base ) !== -1 ? base : 'document';
				} )
				.filter( function ( value, index, list ) {
					return list.indexOf( value ) === index;
				} );
		},

		/**
		 * Whether the frame accepts more than one item.
		 *
		 * @return {boolean} True when multiple selection is allowed.
		 */
		allowsMultiple: function () {
			var state = this.frame.state();
			var selection = state && state.get( 'selection' );

			return Boolean( selection && selection.multiple );
		},

		/**
		 * Put the imported attachments into the frame's selection.
		 *
		 * @param {Array} attachments Imported attachments.
		 * @return {void}
		 */
		applySelection: function ( attachments ) {
			if ( ! attachments.length ) {
				return;
			}

			var frame = this.frame;
			var target = this.targetState();

			if ( ! target ) {
				frame.close();

				return;
			}

			var selection = target.get( 'selection' );

			var models = attachments.map( function ( attachment ) {
				var model = wp.media.attachment( attachment.id );
				model.fetch();

				return model;
			} );

			selection.reset( models );

			if ( frame.state().id !== target.id ) {
				frame.setState( target.id );
			}

			selection.trigger( 'selection:single' );
		},

		/**
		 * The state to hand the selection back to.
		 *
		 * @return {Object|null} State.
		 */
		targetState: function () {
			var frame = this.frame;
			var candidates = [ frame._lastState, 'library', 'insert', 'featured-image', 'gallery' ];

			for ( var i = 0; i < candidates.length; i++ ) {
				if ( ! candidates[ i ] || candidates[ i ] === STATE_ID ) {
					continue;
				}

				try {
					var state = frame.state( candidates[ i ] );

					if ( state && typeof state.get === 'function' && state.get( 'selection' ) ) {
						return state;
					}
				} catch ( error ) {
					// State not registered on this frame; try the next one.
				}
			}

			return null;
		},

		remove: function () {
			if ( this.handle ) {
				this.handle.unmount();
				this.handle = null;
			}

			return wp.media.View.prototype.remove.apply( this, arguments );
		},
	} );

	/**
	 * Render the Mediagraph tab's content into a frame.
	 *
	 * @param {Object} frame Media frame.
	 * @return {void}
	 */
	function renderMediagraphContent( frame ) {
		frame.content.set( new wp.media.view.Mediagraph( { frame: frame } ) );

		// WordPress remembers the last media-modal tab in the libraryContent
		// user setting and reuses it as the default content for future states.
		// Letting ours be remembered makes every later modal — including "Set
		// featured image" — open on the Mediagraph tab, which is surprising and
		// leaves no obvious way back if the picker is slow or fails. Core saves
		// the mode on content:activate, so restore the previous value after.
		window.setTimeout( restoreLibraryMode, 0 );
	}

	/**
	 * Make sure a frame will render our tab when it is selected.
	 *
	 * Bound per frame instance, and idempotent.
	 *
	 * @param {Object} frame Media frame.
	 * @return {void}
	 */
	function bindMediagraphContent( frame ) {
		if ( ! frame || frame._mediagraphBound ) {
			return;
		}

		frame._mediagraphBound = true;

		frame.on( 'content:render:' + STATE_ID, function () {
			renderMediagraphContent( frame );
		} );
	}

	/**
	 * Add the Mediagraph tab to a media frame prototype.
	 *
	 * The listener is bound from inside browseRouter rather than createStates.
	 * That is deliberate: the block editor builds media frames from its own
	 * MediaFrame subclasses, which define their own createStates and so shadow
	 * anything patched onto Select or Post. Those subclasses still inherit
	 * browseRouter, which is exactly where our tab gets added — so binding here
	 * guarantees the invariant that matters: wherever the tab exists, something
	 * is listening to render it. Patching createStates left the tab clickable
	 * but inert in the editor's own modals.
	 *
	 * @param {Object} Frame Media frame constructor.
	 * @return {void}
	 */
	function extendFrame( Frame ) {
		if ( ! Frame || ! Frame.prototype ) {
			return;
		}

		// An *own* flag, not an inherited one: Post extends Select, so an
		// inherited flag would silently skip Post.
		if ( Object.prototype.hasOwnProperty.call( Frame.prototype, 'mediagraphInstalled' ) ) {
			return;
		}

		var originalBrowseRouter = Frame.prototype.browseRouter;

		if ( typeof originalBrowseRouter !== 'function' ) {
			return;
		}

		Frame.prototype.mediagraphInstalled = true;

		Frame.prototype.browseRouter = function ( routerView ) {
			originalBrowseRouter.apply( this, arguments );

			bindMediagraphContent( this );

			routerView.set( {
				mediagraph: {
					text: __( 'Mediagraph', 'mediagraph-assets' ),
					priority: 60,
				},
			} );
		};
	}

	// Patch the base first so any subclass that does not override browseRouter
	// inherits the tab, then the two concrete frames core ships.
	extendFrame( wp.media.view.MediaFrame );
	extendFrame( wp.media.view.MediaFrame.Select );
	extendFrame( wp.media.view.MediaFrame.Post );
} )( jQuery, window.wp );
