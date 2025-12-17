/**
 * Mediagraph Save Handler
 *
 * Saves window._mediagraphAssets to post meta before WordPress saves the post
 */
(function($) {
    'use strict';

    /**
     * Collect Mediagraph assets from Gutenberg blocks
     * @returns {Array} Array of asset objects with metadata
     */
    function collectGutenbergBlockAssets() {
        const blockAssets = [];

        // Check if Gutenberg is available
        if (typeof wp === 'undefined' || !wp.data || !wp.data.select('core/block-editor')) {
            return blockAssets;
        }

        const blocks = wp.data.select('core/block-editor').getBlocks();

        // Recursively find all Mediagraph blocks
        function findMediagraphBlocks(blockList) {
            blockList.forEach(function(block) {
                if (block.name === 'mediagraph/asset-picker' && block.attributes.assetId) {
                    const attrs = block.attributes;

                    // Build metadata object from block attributes
                    const metadata = {
                        title: attrs.title || '',
                        byline: attrs.byline || '',
                        headline: attrs.headline || '',
                        description: attrs.description || '',
                        alt_text: attrs.altText || '',
                        extended_description: attrs.extendedDescription || '',
                        keywords: attrs.keywords || '',
                        usage_rights: attrs.usageRights || ''
                    };

                    // Also include legacy metadata object if present
                    if (attrs.metadata && typeof attrs.metadata === 'object') {
                        Object.assign(metadata, attrs.metadata);
                    }

                    blockAssets.push({
                        id: attrs.assetId,
                        guid: attrs.assetGuid || '',
                        filename: attrs.assetTitle || '',
                        url: attrs.assetUrl || '',
                        asset_type: attrs.assetType || 'image',
                        usage_type: attrs.alignment === 'none' ? 'body_photo' : 'body_photo',
                        metadata: metadata
                    });
                }

                // Recursively check inner blocks
                if (block.innerBlocks && block.innerBlocks.length > 0) {
                    findMediagraphBlocks(block.innerBlocks);
                }
            });
        }

        findMediagraphBlocks(blocks);
        return blockAssets;
    }

    /**
     * Save Mediagraph assets to post meta
     */
    function saveMediagraphAssets() {
        // Get assets from global variable (classic editor / picker modal)
        let assets = window._mediagraphAssets || [];

        // Also collect assets from Gutenberg blocks
        const blockAssets = collectGutenbergBlockAssets();

        // Merge assets, preferring block assets (they have most current metadata)
        const assetMap = new Map();

        // Add classic/picker assets first
        assets.forEach(function(asset) {
            if (asset.id) {
                assetMap.set(asset.id, asset);
            }
        });

        // Add/update with Gutenberg block assets (these are more up-to-date)
        blockAssets.forEach(function(asset) {
            if (asset.id) {
                assetMap.set(asset.id, asset);
            }
        });

        // Convert map back to array
        assets = Array.from(assetMap.values());

        if (assets.length === 0) {
            return;
        }

        // Get post ID
        let postId = $('#post_ID').val();

        // For Gutenberg, try to get post ID from editor
        if (!postId && typeof wp !== 'undefined' && wp.data && wp.data.select('core/editor')) {
            postId = wp.data.select('core/editor').getCurrentPostId();
        }

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
