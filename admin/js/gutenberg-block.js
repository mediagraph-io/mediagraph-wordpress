(function(wp) {
    const { registerBlockType } = wp.blocks;
    const { Button, ToolbarButton, ToolbarGroup, PanelBody, TextControl, TextareaControl, SelectControl } = wp.components;
    const { useBlockProps, BlockControls, InspectorControls } = wp.blockEditor;
    const { createElement: el, useEffect, useRef } = wp.element;

    registerBlockType('mediagraph/asset-picker', {
        apiVersion: 2,
        title: 'Mediagraph Asset',
        icon: el('svg', {
            width: 24,
            height: 24,
            viewBox: '0 0 486.45 486.45',
            xmlns: 'http://www.w3.org/2000/svg'
        },
            el('circle', { cx: '243.22', cy: '243.22', r: '243.22', fill: '#000' }),
            el('g', { fill: '#fff' },
                el('path', { d: 'M405.87,322.82l-59.34-173.19c-2.99-8.72-11.19-14.59-20.42-14.59h-38.43c-2.7-5.68-8.47-9.61-15.18-9.61-9.29,0-16.82,7.53-16.82,16.82s7.53,16.82,16.82,16.82c6.71,0,12.48-3.93,15.18-9.61h38.43c3.06,0,5.79,1.95,6.78,4.84l59.34,173.19c.76,2.22,.41,4.58-.95,6.49s-3.49,3-5.83,3h-33.5c-3.06,0-5.79-1.95-6.78-4.84l-36.01-105.1c3.13-3.05,5.08-7.31,5.08-12.03,0-9.29-7.53-16.82-16.82-16.82s-16.82,7.53-16.82,16.82c0,8.65,6.53,15.77,14.93,16.71l36.01,105.1c2.99,8.72,11.19,14.59,20.42,14.59h33.5c6.95,0,13.52-3.38,17.56-9.04,4.04-5.66,5.11-12.96,2.86-19.54Z' }),
                el('path', { d: 'M144.73,159.07c6.71,0,12.48-3.93,15.18-9.61h38.43c3.06,0,5.79,1.95,6.78,4.84l26.34,76.89c-3.13,3.05-5.08,7.31-5.08,12.03,0,9.29,7.53,16.82,16.82,16.82s16.82-7.53,16.82-16.82c0-8.65-6.53-15.77-14.93-16.71l-26.34-76.88c-2.99-8.73-11.19-14.59-20.42-14.59h-38.43c-2.7-5.68-8.47-9.61-15.18-9.61-9.29,0-16.82,7.53-16.82,16.82s7.53,16.82,16.82,16.82Z' }),
                el('path', { d: 'M278.01,327.37c-6.71,0-12.48,3.93-15.18,9.61h-37.23c-3.9,0-7.38-2.48-8.64-6.17l-40.08-116.97c-3.3-9.62-12-15.84-22.16-15.84h0c-10.17,0-18.87,6.21-22.17,15.83l-37.44,109.08c-2.25,6.67-1.19,13.77,2.91,19.49,4.1,5.72,10.5,9,17.53,9h28.67c2.7,5.68,8.47,9.61,15.18,9.61,9.29,0,16.82-7.53,16.82-16.82s-7.53-16.82-16.82-16.82c-6.71,0-12.48,3.93-15.18,9.61h-28.67c-2.34,0-4.46-1.09-5.82-2.99-1.36-1.9-1.71-4.26-.98-6.44l37.43-109.05c1.94-5.65,7.02-6.09,8.53-6.09h0c1.51,0,6.59,.44,8.53,6.1l40.08,116.97c3.26,9.52,12.22,15.92,22.28,15.92h37.23c2.7,5.68,8.47,9.61,15.18,9.61,9.29,0,16.82-7.53,16.82-16.82s-7.53-16.82-16.82-16.82Z' })
            )
        ),
        category: 'media',
        attributes: {
            assetId: {
                type: 'string'
            },
            assetUrl: {
                type: 'string'
            },
            assetTitle: {
                type: 'string'
            },
            assetHtml: {
                type: 'string'
            },
            // Metadata fields
            title: {
                type: 'string',
                default: ''
            },
            byline: {
                type: 'string',
                default: ''
            },
            headline: {
                type: 'string',
                default: ''
            },
            description: {
                type: 'string',
                default: ''
            },
            altText: {
                type: 'string',
                default: ''
            },
            extendedDescription: {
                type: 'string',
                default: ''
            },
            keywords: {
                type: 'string',
                default: ''
            },
            usageRights: {
                type: 'string',
                default: ''
            },
            // Display settings
            alignment: {
                type: 'string',
                default: 'none'
            },
            linkTo: {
                type: 'string',
                default: 'none'
            },
            size: {
                type: 'string',
                default: 'medium'
            },
            // Legacy - for backwards compatibility
            metadata: {
                type: 'object',
                default: {}
            },
            displaySettings: {
                type: 'object',
                default: {}
            }
        },
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const blockProps = useBlockProps();
            const hasOpenedPicker = useRef(false);

            function openMediagraphPicker() {
                // Store the current block's setAttributes function globally
                window.mediagraphCurrentBlock = {
                    setAttributes: setAttributes,
                    clientId: props.clientId,
                    // Flag to indicate this is a Gutenberg block
                    isGutenbergBlock: true
                };

                // Trigger the existing Mediagraph modal
                if (window.MediagraphPicker) {
                    window.MediagraphPicker.open();
                } else {
                    // Fallback: trigger click on the classic editor button if it exists
                    const button = document.querySelector('.mediagraph-picker-button');
                    if (button) {
                        button.click();
                    }
                }
            }

            // Rebuild HTML when metadata or display settings change
            function rebuildAssetHtml() {
                if (!attributes.assetUrl) return;

                const metadata = {
                    title: attributes.title,
                    byline: attributes.byline,
                    headline: attributes.headline,
                    description: attributes.description,
                    alt_text: attributes.altText,
                    extended_description: attributes.extendedDescription,
                    keywords: attributes.keywords,
                    usage_rights: attributes.usageRights
                };

                const displaySettings = {
                    alignment: attributes.alignment,
                    linkTo: attributes.linkTo,
                    size: attributes.size
                };

                // Call the global HTML builder if available
                if (window.mediagraphBuildHtml) {
                    const newHtml = window.mediagraphBuildHtml(attributes.assetUrl, metadata, displaySettings);
                    setAttributes({ assetHtml: newHtml });
                }
            }

            // Automatically open picker when block is first inserted
            useEffect(() => {
                if (!attributes.assetHtml && !hasOpenedPicker.current) {
                    hasOpenedPicker.current = true;
                    // Small delay to ensure the block is fully mounted
                    setTimeout(() => {
                        openMediagraphPicker();
                    }, 100);
                }
            }, []);

            // If we have an asset, show it
            if (attributes.assetHtml) {
                return el('div', blockProps,
                    // Add toolbar controls
                    el(BlockControls, {},
                        el(ToolbarGroup, {},
                            el(ToolbarButton, {
                                icon: 'format-image',
                                label: 'Replace Asset',
                                onClick: openMediagraphPicker
                            })
                        )
                    ),
                    // Add sidebar inspector controls
                    el(InspectorControls, {},
                        // Metadata Panel
                        el(PanelBody, { title: 'Asset Metadata', initialOpen: true },
                            el(TextControl, {
                                label: 'Title',
                                value: attributes.title,
                                onChange: (value) => {
                                    setAttributes({ title: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(TextControl, {
                                label: 'Byline',
                                value: attributes.byline,
                                onChange: (value) => {
                                    setAttributes({ byline: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(TextControl, {
                                label: 'Headline',
                                value: attributes.headline,
                                onChange: (value) => {
                                    setAttributes({ headline: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(TextareaControl, {
                                label: 'Description',
                                value: attributes.description,
                                onChange: (value) => {
                                    setAttributes({ description: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(TextControl, {
                                label: 'Alt Text',
                                value: attributes.altText,
                                onChange: (value) => {
                                    setAttributes({ altText: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(TextareaControl, {
                                label: 'Extended Description',
                                value: attributes.extendedDescription,
                                onChange: (value) => {
                                    setAttributes({ extendedDescription: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(TextControl, {
                                label: 'Keywords',
                                value: attributes.keywords,
                                placeholder: 'Comma-separated',
                                onChange: (value) => {
                                    setAttributes({ keywords: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(TextareaControl, {
                                label: 'Usage Rights',
                                value: attributes.usageRights,
                                onChange: (value) => {
                                    setAttributes({ usageRights: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            })
                        ),
                        // Display Settings Panel
                        el(PanelBody, { title: 'Display Settings', initialOpen: true },
                            el(SelectControl, {
                                label: 'Alignment',
                                value: attributes.alignment,
                                options: [
                                    { label: 'None', value: 'none' },
                                    { label: 'Left', value: 'left' },
                                    { label: 'Center', value: 'center' },
                                    { label: 'Right', value: 'right' }
                                ],
                                onChange: (value) => {
                                    setAttributes({ alignment: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(SelectControl, {
                                label: 'Link To',
                                value: attributes.linkTo,
                                options: [
                                    { label: 'None', value: 'none' },
                                    { label: 'Media File', value: 'media' },
                                    { label: 'Attachment Page', value: 'attachment' }
                                ],
                                onChange: (value) => {
                                    setAttributes({ linkTo: value });
                                    setTimeout(rebuildAssetHtml, 0);
                                }
                            }),
                            el(SelectControl, {
                                label: 'Size',
                                value: attributes.size,
                                options: [
                                    { label: 'Thumbnail', value: 'thumbnail' },
                                    { label: 'Medium', value: 'medium' },
                                    { label: 'Large', value: 'large' },
                                    { label: 'Full Size', value: 'full' }
                                ],
                                onChange: (value) => {
                                    setAttributes({ size: value });
                                    // Size change requires re-download
                                    // TODO: Implement re-download logic
                                }
                            })
                        )
                    ),
                    // Show the asset
                    el('div', {
                        dangerouslySetInnerHTML: { __html: attributes.assetHtml }
                    })
                );
            }

            // Otherwise show the picker button
            return el('div', blockProps,
                el('div', {
                    className: 'mediagraph-block-placeholder',
                    style: {
                        border: '2px dashed #ddd',
                        borderRadius: '4px',
                        padding: '40px',
                        textAlign: 'center',
                        backgroundColor: '#f9f9f9'
                    }
                },
                    el('p', { style: { marginBottom: '20px', color: '#666' } },
                        'Select an asset from Mediagraph'
                    ),
                    el(Button, {
                        variant: 'primary',
                        onClick: openMediagraphPicker
                    }, 'Open Mediagraph Picker')
                )
            );
        },
        save: function(props) {
            const { attributes } = props;

            // In the saved content, just output the raw HTML
            if (attributes.assetHtml) {
                return el('div', {
                    dangerouslySetInnerHTML: { __html: attributes.assetHtml }
                });
            }

            return null;
        }
    });
})(window.wp);
