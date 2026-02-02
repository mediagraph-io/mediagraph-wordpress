/**
 * Mediagraph Media Modal Integration
 *
 * Extends the WordPress Media Modal to include a "Mediagraph" tab
 * allowing users to browse and insert Mediagraph assets from the
 * native WordPress media interface.
 */

(function($, wp) {
    'use strict';

    // Bail if wp.media doesn't exist
    if (!wp || !wp.media) {
        return;
    }

    // Store reference to original media frame
    var originalMediaFrame = wp.media.view.MediaFrame.Select;

    /**
     * Custom Mediagraph state for the media modal
     */
    wp.media.controller.Mediagraph = wp.media.controller.State.extend({
        defaults: {
            id: 'mediagraph',
            title: 'Mediagraph',
            content: 'mediagraph',
            menu: 'default',
            toolbar: 'mediagraph-toolbar',
            priority: 200
        },

        initialize: function() {
            this.props = new Backbone.Model({
                selectedAsset: null
            });
        },

        activate: function() {
            wp.media.controller.State.prototype.activate.apply(this, arguments);
        }
    });

    /**
     * Custom Mediagraph content view
     * This mounts the React picker inside the media modal
     */
    wp.media.view.Mediagraph = wp.media.View.extend({
        className: 'mediagraph-modal-content',
        template: false,

        initialize: function() {
            this.controller = this.options.controller;
            this.createContainer();
        },

        createContainer: function() {
            // Create container for React picker
            this.$el.html(
                '<div id="mediagraph-media-modal-picker" class="mediagraph-media-modal-picker">' +
                    '<div class="mediagraph-modal-loading">' +
                        '<span class="spinner is-active"></span>' +
                        '<p>Loading Mediagraph...</p>' +
                    '</div>' +
                '</div>'
            );
        },

        render: function() {
            var self = this;

            // Wait for the container to be in the DOM
            setTimeout(function() {
                self.mountReactPicker();
            }, 100);

            return this;
        },

        mountReactPicker: function() {
            var self = this;
            var container = document.getElementById('mediagraph-media-modal-picker');

            if (!container) {
                console.error('Mediagraph: Container not found');
                return;
            }

            // Check if connected to Mediagraph
            if (!window.mediagraphPicker || !window.mediagraphPicker.isConnected) {
                container.innerHTML =
                    '<div class="mediagraph-not-connected">' +
                        '<h3>Not Connected</h3>' +
                        '<p>Please connect to Mediagraph in <a href="' + window.mediagraphPicker.settingsUrl + '">Settings &rarr; Mediagraph</a></p>' +
                    '</div>';
                return;
            }

            // Create inline picker UI
            this.createInlinePicker(container);
        },

        createInlinePicker: function(container) {
            var self = this;

            // Create simplified picker interface
            container.innerHTML =
                '<div class="mediagraph-inline-picker">' +
                    '<div class="mediagraph-inline-header">' +
                        '<div class="mediagraph-search-container">' +
                            '<input type="text" id="mediagraph-modal-search" placeholder="Search assets..." />' +
                            '<button type="button" class="button" id="mediagraph-modal-search-btn">Search</button>' +
                        '</div>' +
                        '<div class="mediagraph-sort-container">' +
                            '<select id="mediagraph-modal-sort">' +
                                '<option value="created_at_desc">Newest First</option>' +
                                '<option value="created_at_asc">Oldest First</option>' +
                                '<option value="filename_asc">Filename (A-Z)</option>' +
                                '<option value="filename_desc">Filename (Z-A)</option>' +
                            '</select>' +
                        '</div>' +
                    '</div>' +
                    '<div class="mediagraph-inline-body">' +
                        '<div id="mediagraph-modal-assets" class="mediagraph-modal-assets">' +
                            '<div class="mediagraph-modal-loading"><span class="spinner is-active"></span></div>' +
                        '</div>' +
                    '</div>' +
                    '<div class="mediagraph-inline-footer">' +
                        '<div id="mediagraph-modal-pagination" class="mediagraph-modal-pagination"></div>' +
                    '</div>' +
                '</div>';

            // Bind events
            $('#mediagraph-modal-search').on('keypress', function(e) {
                if (e.which === 13) {
                    self.searchAssets();
                }
            });

            $('#mediagraph-modal-search-btn').on('click', function() {
                self.searchAssets();
            });

            $('#mediagraph-modal-sort').on('change', function() {
                self.searchAssets();
            });

            // Load initial assets
            this.searchAssets();
        },

        searchAssets: function(page) {
            var self = this;
            var query = $('#mediagraph-modal-search').val() || '';
            var sort = $('#mediagraph-modal-sort').val() || 'created_at_desc';
            var sortParts = sort.match(/^(.+)_(asc|desc)$/);
            var sortField = sortParts ? sortParts[1] : sort;
            var sortOrder = sortParts ? sortParts[2] : 'desc';
            page = page || 1;

            $('#mediagraph-modal-assets').html('<div class="mediagraph-modal-loading"><span class="spinner is-active"></span></div>');

            $.ajax({
                url: window.mediagraphPicker.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'mediagraph_search_assets',
                    nonce: window.mediagraphPicker.nonce,
                    q: query,
                    sort: sortField,
                    order: sortOrder,
                    page: page,
                    per_page: 40
                },
                success: function(response) {
                    if (response.success) {
                        self.renderAssets(response.data.assets || [], response.data.total || 0, page);
                    } else {
                        self.showError(response.data?.message || 'Failed to load assets');
                    }
                },
                error: function() {
                    self.showError('Network error loading assets');
                }
            });
        },

        renderAssets: function(assets, total, currentPage) {
            var self = this;
            var container = $('#mediagraph-modal-assets');
            var apiBaseUrl = window.mediagraphPicker.apiBaseUrl || '';

            if (!assets || assets.length === 0) {
                container.html('<div class="mediagraph-no-assets">No assets found</div>');
                return;
            }

            var html = '<div class="mediagraph-asset-grid">';

            assets.forEach(function(asset) {
                var thumbUrl = asset.thumb_url || asset.grid_url || '';
                // Resolve relative URLs
                if (thumbUrl && !thumbUrl.startsWith('http')) {
                    thumbUrl = apiBaseUrl.replace(/\/$/, '') + thumbUrl;
                }

                var isRestricted = !asset.downloadable;
                var isVideo = asset.type === 'Video' || (asset.mime_type && asset.mime_type.startsWith('video/'));
                var isAudio = asset.type === 'Audio' || (asset.mime_type && asset.mime_type.startsWith('audio/'));

                html += '<div class="mediagraph-modal-asset' + (isRestricted ? ' restricted' : '') + '" data-asset-id="' + asset.id + '">';

                if (isVideo) {
                    html += '<div class="mediagraph-asset-thumb video-thumb">';
                    if (thumbUrl) {
                        html += '<img src="' + thumbUrl + '" alt="" />';
                    }
                    html += '<span class="asset-type-badge">VIDEO</span>';
                    html += '</div>';
                } else if (isAudio) {
                    html += '<div class="mediagraph-asset-thumb audio-thumb">';
                    html += '<span class="dashicons dashicons-format-audio"></span>';
                    html += '<span class="asset-type-badge">AUDIO</span>';
                    html += '</div>';
                } else {
                    html += '<div class="mediagraph-asset-thumb">';
                    if (thumbUrl) {
                        html += '<img src="' + thumbUrl + '" alt="" loading="lazy" />';
                    } else {
                        html += '<span class="dashicons dashicons-format-image"></span>';
                    }
                    html += '</div>';
                }

                html += '<div class="mediagraph-asset-name">' + (asset.filename || 'Untitled') + '</div>';

                if (isRestricted) {
                    html += '<span class="restricted-badge" title="Not downloadable">Restricted</span>';
                }

                html += '</div>';
            });

            html += '</div>';
            container.html(html);

            // Bind click handlers
            container.find('.mediagraph-modal-asset:not(.restricted)').on('click', function() {
                var assetId = $(this).data('asset-id');
                var asset = assets.find(function(a) { return a.id == assetId; });
                if (asset) {
                    self.selectAsset(asset);
                }
            });

            // Render pagination
            this.renderPagination(total, currentPage);
        },

        renderPagination: function(total, currentPage) {
            var self = this;
            var perPage = 40;
            var totalPages = Math.ceil(total / perPage);

            if (totalPages <= 1) {
                $('#mediagraph-modal-pagination').html('');
                return;
            }

            var html = '<span class="pagination-info">' + total + ' assets</span>';
            html += '<div class="pagination-buttons">';

            if (currentPage > 1) {
                html += '<button type="button" class="button page-btn" data-page="' + (currentPage - 1) + '">&laquo; Previous</button>';
            }

            html += '<span class="page-indicator">Page ' + currentPage + ' of ' + totalPages + '</span>';

            if (currentPage < totalPages) {
                html += '<button type="button" class="button page-btn" data-page="' + (currentPage + 1) + '">Next &raquo;</button>';
            }

            html += '</div>';
            $('#mediagraph-modal-pagination').html(html);

            // Bind pagination
            $('.page-btn').on('click', function() {
                self.searchAssets($(this).data('page'));
            });
        },

        selectAsset: function(asset) {
            var self = this;
            var controller = this.controller;

            // Show loading state
            $('.mediagraph-modal-asset[data-asset-id="' + asset.id + '"]').addClass('loading');

            // Download asset to WordPress media library
            $.ajax({
                url: window.mediagraphPicker.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'mediagraph_download_asset',
                    nonce: window.mediagraphPicker.nonce,
                    asset_id: asset.id,
                    size: 'full',
                    metadata: JSON.stringify({
                        title: asset.title || asset.filename,
                        description: asset.description || '',
                        alt_text: asset.alt_text || '',
                        caption: asset.description || ''
                    })
                },
                success: function(response) {
                    if (response.success) {
                        var attachmentId = response.data.attachment_id;

                        // Fetch the new attachment
                        var attachment = wp.media.attachment(attachmentId);
                        attachment.fetch().then(function() {
                            // Try to find a state with a selection
                            // Common state names: 'library', 'insert', 'featured-image', 'gallery'
                            var stateNames = ['library', 'insert', 'featured-image', 'gallery', 'embed'];
                            var targetState = null;
                            var selection = null;

                            // First, check if controller has a selection directly
                            if (controller.state() && controller.state().get('selection')) {
                                targetState = controller.state();
                                selection = targetState.get('selection');
                            } else {
                                // Try to find a state with selection
                                for (var i = 0; i < stateNames.length; i++) {
                                    try {
                                        var state = controller.state(stateNames[i]);
                                        if (state && state.get('selection')) {
                                            targetState = state;
                                            selection = state.get('selection');
                                            break;
                                        }
                                    } catch (e) {
                                        // State doesn't exist, continue
                                    }
                                }
                            }

                            if (selection) {
                                // Add attachment to selection
                                selection.reset([attachment]);

                                // Switch to the target state if different from current
                                if (targetState && targetState.id && controller.state().id !== targetState.id) {
                                    controller.setState(targetState.id);
                                }

                                // Trigger selection change to update UI
                                selection.trigger('selection:single');

                                // Show visual feedback - mark the asset as selected
                                $('.mediagraph-modal-asset').removeClass('selected');
                                $('.mediagraph-modal-asset[data-asset-id="' + asset.id + '"]').addClass('selected');
                            } else {
                                // Fallback: Just switch to library view to show the new item
                                // and let the user select it manually
                                try {
                                    controller.setState('library');
                                } catch (e) {
                                    // If library state doesn't exist, just close
                                    controller.close();
                                }

                                // Alert user where to find their image
                                alert('Image uploaded successfully! You can find it in the Media Library tab.');
                            }
                        }).fail(function() {
                            alert('Error loading the uploaded attachment. Please select it from the Media Library tab.');
                        });
                    } else {
                        alert('Error: ' + (response.data?.message || 'Failed to download asset'));
                    }
                },
                error: function() {
                    alert('Network error downloading asset');
                },
                complete: function() {
                    $('.mediagraph-modal-asset').removeClass('loading');
                }
            });
        },

        showError: function(message) {
            $('#mediagraph-modal-assets').html('<div class="mediagraph-error">' + message + '</div>');
        }
    });

    /**
     * Extend the standard media frame to add Mediagraph tab
     */
    wp.media.view.MediaFrame.Select = originalMediaFrame.extend({
        initialize: function() {
            originalMediaFrame.prototype.initialize.apply(this, arguments);

            // Add Mediagraph state
            this.states.add([
                new wp.media.controller.Mediagraph({
                    id: 'mediagraph',
                    title: 'Mediagraph',
                    priority: 200
                })
            ]);

            // Bind our custom render handlers
            this.on('content:render:mediagraph', this.mediagraphContent, this);
        },

        /**
         * Render Mediagraph content
         */
        mediagraphContent: function() {
            var view = new wp.media.view.Mediagraph({
                controller: this,
                model: this.state()
            });

            this.content.set(view);
        },

        /**
         * Extend browse router to add Mediagraph tab
         */
        browseRouter: function(routerView) {
            originalMediaFrame.prototype.browseRouter.apply(this, arguments);

            // Add Mediagraph menu item
            routerView.set({
                mediagraph: {
                    text: 'Mediagraph',
                    priority: 60
                }
            });
        }
    });

    // Also extend the Post media frame (used for featured images, etc.)
    if (wp.media.view.MediaFrame.Post) {
        var originalPostFrame = wp.media.view.MediaFrame.Post;

        wp.media.view.MediaFrame.Post = originalPostFrame.extend({
            initialize: function() {
                originalPostFrame.prototype.initialize.apply(this, arguments);

                // Add Mediagraph state
                this.states.add([
                    new wp.media.controller.Mediagraph({
                        id: 'mediagraph',
                        title: 'Mediagraph',
                        priority: 200
                    })
                ]);

                // Bind our custom render handlers
                this.on('content:render:mediagraph', this.mediagraphContent, this);
            },

            mediagraphContent: function() {
                var view = new wp.media.view.Mediagraph({
                    controller: this,
                    model: this.state()
                });

                this.content.set(view);
            },

            browseRouter: function(routerView) {
                originalPostFrame.prototype.browseRouter.apply(this, arguments);

                routerView.set({
                    mediagraph: {
                        text: 'Mediagraph',
                        priority: 60
                    }
                });
            }
        });
    }

})(jQuery, window.wp);
