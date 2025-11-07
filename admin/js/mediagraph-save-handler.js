/**
 * Mediagraph Save Handler
 *
 * Saves window._mediagraphAssets to post meta before WordPress saves the post
 */
(function($) {
    'use strict';

    /**
     * Save Mediagraph assets to post meta
     */
    function saveMediagraphAssets() {
        // Get assets from global variable
        const assets = window._mediagraphAssets || [];

        if (assets.length === 0) {
            return;
        }

        // Get post ID
        const postId = $('#post_ID').val();

        if (!postId) {
            return;
        }

        // Send AJAX request to save assets
        $.ajax({
            url: mediagraphPicker.ajaxUrl,
            type: 'POST',
            async: false, // Block until saved to ensure it happens before post save
            data: {
                action: 'mediagraph_save_assets',
                nonce: mediagraphPicker.nonce,
                post_id: postId,
                assets: JSON.stringify(assets)
            },
            success: function(response) {
                if (response.success) {
                    console.log('Mediagraph assets saved successfully');
                } else {
                    console.error('Failed to save Mediagraph assets:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.error('Error saving Mediagraph assets:', error);
            }
        });
    }

    /**
     * Initialize for Classic Editor
     */
    function initClassicEditor() {
        // Hook into post form submission
        $('#post').on('submit', function() {
            saveMediagraphAssets();
        });

        // Also hook into autosave
        $(document).on('heartbeat-send.autosave', function() {
            saveMediagraphAssets();
        });
    }

    /**
     * Initialize for Gutenberg (Block Editor)
     */
    function initGutenberg() {
        // Check if wp.data exists (Gutenberg)
        if (typeof wp !== 'undefined' && wp.data) {
            // Subscribe to editor changes
            let previousIsSaving = false;

            wp.data.subscribe(function() {
                const editor = wp.data.select('core/editor');

                if (!editor) {
                    return;
                }

                const isSaving = editor.isSavingPost();
                const isAutosaving = editor.isAutosavingPost();

                // Detect when save starts (transition from not saving to saving)
                if (isSaving && !isAutosaving && !previousIsSaving) {
                    saveMediagraphAssets();
                }

                previousIsSaving = isSaving;
            });
        }
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Detect which editor is being used
        if (typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            // Gutenberg
            initGutenberg();
        } else if ($('#post').length > 0) {
            // Classic Editor
            initClassicEditor();
        }
    });

})(jQuery);
