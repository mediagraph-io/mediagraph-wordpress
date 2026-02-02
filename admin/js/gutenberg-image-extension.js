/**
 * Mediagraph Image Block Extension
 *
 * Adds a "Mediagraph" button to the toolbar of the core/image block,
 * allowing users to replace images with assets from Mediagraph.
 */

(function(wp) {
    'use strict';

    const { addFilter } = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;
    const { Fragment, createElement: el } = wp.element;
    const { BlockControls } = wp.blockEditor;
    const { ToolbarGroup, ToolbarButton } = wp.components;

    // Mediagraph icon as SVG
    const mediagraphIcon = el('svg', {
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
     * Higher-order component that adds Mediagraph button to image block toolbar
     */
    const withMediagraphButton = createHigherOrderComponent(function(BlockEdit) {
        return function(props) {
            // Only add to core/image block
            if (props.name !== 'core/image') {
                return el(BlockEdit, props);
            }

            // Check if Mediagraph is connected
            var isConnected = window.mediagraphPicker && window.mediagraphPicker.isConnected;

            /**
             * Open Mediagraph picker and handle asset selection
             */
            function openMediagraphPicker() {
                if (!window.MediagraphPicker) {
                    console.error('Mediagraph picker not available');
                    return;
                }

                // Store reference to this block for asset insertion
                window.mediagraphImageBlockTarget = {
                    clientId: props.clientId,
                    setAttributes: props.setAttributes,
                    attributes: props.attributes
                };

                // Open the picker
                window.MediagraphPicker.open();

                // Listen for asset selection
                var originalInsert = window.mediagraphCurrentBlock;
                window.mediagraphCurrentBlock = {
                    setAttributes: function(attrs) {
                        // Update the core/image block with Mediagraph asset
                        props.setAttributes({
                            url: attrs.assetUrl,
                            alt: attrs.altText || '',
                            title: attrs.title || '',
                            caption: attrs.description || ''
                        });

                        // Store Mediagraph metadata for write-back
                        if (attrs.assetId) {
                            var postMeta = window._mediagraphAssets || [];
                            postMeta.push({
                                id: attrs.assetId,
                                guid: attrs.assetGuid || '',
                                usage_type: 'body_photo',
                                metadata: {
                                    title: attrs.title || '',
                                    description: attrs.description || '',
                                    alt_text: attrs.altText || ''
                                }
                            });
                            window._mediagraphAssets = postMeta;
                        }

                        // Clean up
                        window.mediagraphImageBlockTarget = null;
                    },
                    isGutenbergBlock: true,
                    targetBlockType: 'core/image',
                    clientId: props.clientId
                };
            }

            return el(Fragment, {},
                el(BlockEdit, props),
                isConnected && el(BlockControls, { group: 'other' },
                    el(ToolbarGroup, {},
                        el(ToolbarButton, {
                            icon: mediagraphIcon,
                            label: 'Replace with Mediagraph Asset',
                            onClick: openMediagraphPicker
                        })
                    )
                )
            );
        };
    }, 'withMediagraphButton');

    // Add the filter to extend block controls
    addFilter(
        'editor.BlockEdit',
        'mediagraph/image-block-extension',
        withMediagraphButton
    );

})(window.wp);
