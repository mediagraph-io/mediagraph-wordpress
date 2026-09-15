/**
 * Classic editor support.
 *
 * The "Add from Mediagraph" button imports the asset and inserts standard
 * WordPress markup through wp.media.editor, so the result is identical to
 * inserting from the media library.
 */

( function () {
	'use strict';

	/**
	 * Build the markup for an inserted attachment.
	 *
	 * Everything is escaped: 1.x concatenated raw metadata into an HTML string,
	 * so a caption containing a quote produced broken markup.
	 *
	 * @param {Object} attachment Imported attachment.
	 * @return {string} HTML.
	 */
	function buildMarkup( attachment ) {
		var escapeHtml = function ( value ) {
			return String( value === undefined || value === null ? '' : value )
				.replace( /&/g, '&amp;' )
				.replace( /</g, '&lt;' )
				.replace( />/g, '&gt;' )
				.replace( /"/g, '&quot;' )
				.replace( /'/g, '&#039;' );
		};

		var url = escapeHtml( attachment.url );
		var caption = attachment.caption ? escapeHtml( attachment.caption ) : '';
		var kind = attachment.kind;
		var media;

		if ( kind === 'video' ) {
			media =
				'<video controls src="' +
				url +
				'"' +
				( attachment.poster ? ' poster="' + escapeHtml( attachment.poster ) + '"' : '' ) +
				' class="wp-video-shortcode"></video>';
		} else if ( kind === 'audio' ) {
			media = '<audio controls src="' + url + '" class="wp-audio-shortcode"></audio>';
		} else if ( kind === 'image' ) {
			media =
				'<img src="' +
				url +
				'" alt="' +
				escapeHtml( attachment.alt ) +
				'" class="wp-image-' +
				parseInt( attachment.id, 10 ) +
				'"' +
				( attachment.width ? ' width="' + parseInt( attachment.width, 10 ) + '"' : '' ) +
				( attachment.height ? ' height="' + parseInt( attachment.height, 10 ) + '"' : '' ) +
				' />';
		} else {
			media = '<a href="' + url + '">' + escapeHtml( attachment.title || attachment.url ) + '</a>';
		}

		if ( ! caption ) {
			return media;
		}

		return (
			'<figure class="wp-caption aligncenter">' +
			media +
			'<figcaption class="wp-caption-text">' +
			caption +
			'</figcaption></figure>'
		);
	}

	/**
	 * Insert markup into the active editor.
	 *
	 * @param {string} html     Markup.
	 * @param {string} editorId Target editor ID.
	 * @return {void}
	 */
	function insert( html, editorId ) {
		if ( window.wp && window.wp.media && window.wp.media.editor ) {
			window.wp.media.editor.insert( html );

			return;
		}

		var editor = window.tinymce && window.tinymce.get( editorId );

		if ( editor ) {
			editor.insertContent( html );

			return;
		}

		var textarea = document.getElementById( editorId );

		if ( textarea ) {
			textarea.value += '\n' + html;
		}
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest ? event.target.closest( '.mediagraph-classic-button' ) : null;

		if ( ! button ) {
			return;
		}

		event.preventDefault();

		if ( ! window.Mediagraph ) {
			return;
		}

		var editorId = button.getAttribute( 'data-editor' ) || 'content';

		window.Mediagraph.open( {
			multiple: true,
			title: window.wp.i18n.__( 'Add from Mediagraph', 'mediagraph-assets' ),
			onSelect: function ( attachments ) {
				( attachments || [] ).forEach( function ( attachment ) {
					insert( buildMarkup( attachment ), editorId );
				} );
			},
		} );
	} );
} )();
