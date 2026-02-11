/**
 * Mediagraph Block Extensions
 *
 * Adds a "Mediagraph" toolbar button to supported native blocks
 * (Image, Gallery, Cover, Media & Text), allowing users to insert
 * Mediagraph assets that are downloaded to the WP Media Library
 * with proper attachment IDs.
 */

(function(wp) {
    'use strict';

    var addFilter = wp.hooks.addFilter;
    var createHigherOrderComponent = wp.compose.createHigherOrderComponent;
    var Fragment = wp.element.Fragment;
    var el = wp.element.createElement;
    var BlockControls = wp.blockEditor.BlockControls;
    var ToolbarGroup = wp.components.ToolbarGroup;
    var ToolbarButton = wp.components.ToolbarButton;

    // Mediagraph icon as SVG
    var mediagraphIcon = el('svg', {
        width: 20,
        height: 20,
        viewBox: '0 0 486.45 486.45',
        xmlns: 'http://www.w3.org/2000/svg'
    },
        el('circle', { cx: '243.22', cy: '243.22', r: '243.22', fill: 'currentColor' }),
        el('g', { fill: '#fff' },
            el('path', { d: 'M405.87,322.82l-59.34-173.19c-2.99-8.72-11.19-14.59-20.42-14.59h-38.43c-2.7-5.68-8.47-9.61-15.18-9.61-9.29,0-16.82,7.53-16.82,16.82s7.53,16.82,16.82,16.82c6.71,0,12.48-3.93,15.18-9.61h38.43c3.06,0,5.79,1.95,6.78,4.84l59.34,173.19c.76,2.22,.41,4.58-.95,6.49s-3.49,3-5.83,3h-33.5c-3.06,0-5.79-1.95-6.78-4.84l-36.01-105.1c3.13-3.05,5.08-7.31,5.08-12.03,0-9.29-7.53-16.82-16.82-16.82s-16.82,7.53-16.82,16.82c0,8.65,6.53,15.77,14.93,16.71l36.01,105.1c2.99,8.72,11.19,14.59,20.42,14.59h33.5c6.95,0,13.52-3.38,17.56-9.04,4.04-5.66,5.11-12.96,2.86-19.54Z' }),
            el('path', { d: 'M144.73,159.07c6.71,0,12.48-3.93,15.18-9.61h38.43c3.06,0,5.79,1.95,6.78,4.84l26.34,76.89c-3.13,3.05-5.08,7.31-5.08,12.03,0,9.29,7.53,16.82,16.82,16.82s16.82-7.53,16.82-16.82c0-8.65-6.53-15.77-14.93-16.71l-26.34-76.88c-2.99-8.73-11.19-14.59-20.42-14.59h-38.43c-2.7-5.68-8.47-9.61-15.18-9.61-9.29,0-16.82,7.53-16.82,16.82s7.53,16.82,16.82,16.82Z' }),
            el('path', { d: 'M278.01,327.37c-6.71,0-12.48,3.93-15.18,9.61h-37.23c-3.9,0-7.38-2.48-8.64-6.17l-40.08-116.97c-3.3-9.62-12-15.84-22.16-15.84h0c-10.17,0-18.87,6.21-22.17,15.83l-37.44,109.08c-2.25,6.67-1.19,13.77,2.91,19.49,4.1,5.72,10.5,9,17.53,9h28.67c2.7,5.68,8.47,9.61,15.18,9.61,9.29,0,16.82-7.53,16.82-16.82s-7.53-16.82-16.82-16.82c-6.71,0-12.48,3.93-15.18,9.61h-28.67c-2.34,0-4.46-1.09-5.82-2.99-1.36-1.9-1.71-4.26-.98-6.44l37.43-109.05c1.94-5.65,7.02-6.09,8.53-6.09h0c1.51,0,6.59,.44,8.53,6.1l40.08,116.97c3.26,9.52,12.22,15.92,22.28,15.92h37.23c2.7,5.68,8.47,9.61,15.18,9.61,9.29,0,16.82-7.53,16.82-16.82s-7.53-16.82-16.82-16.82Z' })
        )
    );

    /**
     * Configuration map for supported block types.
     * Each entry defines how to apply a Mediagraph asset to that block.
     */
    var SUPPORTED_BLOCKS = {
        'core/image': {
            label: 'Replace with Mediagraph Asset',
            applyAsset: function(props, attrs) {
                props.setAttributes({
                    id: attrs.attachmentId || undefined,
                    url: attrs.assetUrl,
                    alt: attrs.altText || '',
                    title: attrs.title || '',
                    caption: attrs.description || ''
                });
            }
        },
        'core/cover': {
            label: 'Replace with Mediagraph Asset',
            applyAsset: function(props, attrs) {
                props.setAttributes({
                    id: attrs.attachmentId || undefined,
                    url: attrs.assetUrl,
                    alt: attrs.altText || ''
                });
            }
        },
        'core/media-text': {
            label: 'Replace with Mediagraph Asset',
            applyAsset: function(props, attrs) {
                var isVideo = attrs.assetType === 'video';
                props.setAttributes({
                    mediaId: attrs.attachmentId || undefined,
                    mediaUrl: attrs.assetUrl,
                    mediaAlt: attrs.altText || '',
                    mediaType: isVideo ? 'video' : 'image'
                });
            }
        },
        'core/gallery': {
            label: 'Add from Mediagraph',
            applyAsset: function(props, attrs) {
                // Modern galleries (WP 5.9+) use inner blocks
                if (wp.data && wp.blocks) {
                    var dispatch = wp.data.dispatch('core/block-editor');
                    var select = wp.data.select('core/block-editor');
                    var block = select.getBlock(props.clientId);
                    var innerBlocks = block ? block.innerBlocks : [];

                    var newImageBlock = wp.blocks.createBlock('core/image', {
                        id: attrs.attachmentId || undefined,
                        url: attrs.assetUrl,
                        alt: attrs.altText || '',
                        caption: attrs.description || ''
                    });

                    dispatch.insertBlock(newImageBlock, innerBlocks.length, props.clientId);
                }
            }
        }
    };

    /**
     * Get the block config if it's a supported block.
     */
    function getBlockConfig(blockName) {
        return SUPPORTED_BLOCKS[blockName] || null;
    }

    /**
     * Higher-order component that adds Mediagraph button to supported block toolbars.
     */
    var withMediagraphButton = createHigherOrderComponent(function(BlockEdit) {
        return function(props) {
            var blockConfig = getBlockConfig(props.name);

            if (!blockConfig) {
                return el(BlockEdit, props);
            }

            var isConnected = window.mediagraphPicker && window.mediagraphPicker.isConnected;

            function openMediagraphPicker() {
                if (!window.MediagraphPicker) {
                    console.error('Mediagraph picker not available');
                    return;
                }

                // Set up the callback for asset selection.
                // The React picker downloads the asset to the WP Media Library,
                // then calls setAttributes with the attachment ID and URL.
                window.mediagraphCurrentBlock = {
                    isGutenbergBlock: true,
                    targetBlockType: props.name,
                    clientId: props.clientId,
                    setAttributes: function(attrs) {
                        blockConfig.applyAsset(props, attrs);
                        window.mediagraphCurrentBlock = null;
                    }
                };

                window.MediagraphPicker.open();
            }

            return el(Fragment, {},
                el(BlockEdit, props),
                isConnected && el(BlockControls, { group: 'other' },
                    el(ToolbarGroup, {},
                        el(ToolbarButton, {
                            icon: mediagraphIcon,
                            label: blockConfig.label,
                            onClick: openMediagraphPicker
                        })
                    )
                )
            );
        };
    }, 'withMediagraphButton');

    // Register the filter
    addFilter(
        'editor.BlockEdit',
        'mediagraph/block-extension',
        withMediagraphButton
    );

})(window.wp);
