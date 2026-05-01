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
     * This mounts the full React picker inside the media modal
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

        // Unmount the React tree when the view is removed so we don't leak roots
        // each time the user reopens the media modal or switches tabs.
        remove: function() {
            if (this.pickerHandle && typeof this.pickerHandle.unmount === 'function') {
                this.pickerHandle.unmount();
                this.pickerHandle = null;
            }
            wp.media.View.prototype.remove.apply(this, arguments);
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

            // Mount the same full-featured React picker (sidebar tree, filters, search,
            // detail modal) used by the Gutenberg block flow. The picker downloads the
            // selected asset to the WP media library and hands the attachment back via
            // onAssetReady; we then drive the WP media frame's selection model.
            if (!window.MediagraphPicker || typeof window.MediagraphPicker.mountInline !== 'function') {
                container.innerHTML =
                    '<div class="mediagraph-error">' +
                        'Mediagraph picker bundle did not load. Try a hard refresh.' +
                    '</div>';
                return;
            }

            // Clear loading placeholder before React takes over.
            container.innerHTML = '';

            this.pickerHandle = window.MediagraphPicker.mountInline(container, {
                onAssetReady: function(asset, payload) {
                    if (!payload || !payload.attachmentId) {
                        return;
                    }
                    var attachment = wp.media.attachment(payload.attachmentId);
                    attachment.fetch().then(function() {
                        self.handleAttachmentReady(self.controller, attachment, asset);
                    }).fail(function() {
                        alert('Error loading the uploaded attachment. Please select it from the Media Library tab.');
                    });
                }
            });
        },

        /**
         * Handle a successfully downloaded and fetched attachment.
         * Determines the correct state to switch to and sets the selection.
         */
        handleAttachmentReady: function(controller, attachment, asset) {
            // Use _lastState to determine where the user came from before
            // switching to the Mediagraph tab. This correctly handles:
            // - "Set Featured Image" flow (_lastState = 'featured-image')
            // - "Add Media" / "Insert Media" flow (_lastState = 'insert' or 'library')
            var previousStateId = controller._lastState;
            var targetState = null;
            var selection = null;

            // First, try to return to the previous state
            if (previousStateId) {
                try {
                    targetState = controller.state(previousStateId);
                    if (targetState && typeof targetState.get === 'function') {
                        selection = targetState.get('selection');
                    }
                    if (!selection) {
                        targetState = null;
                    }
                } catch (e) {
                    targetState = null;
                }
            }

            // Fallback: try common states in order
            if (!selection) {
                var fallbackStates = ['library', 'insert', 'featured-image', 'gallery'];
                for (var i = 0; i < fallbackStates.length; i++) {
                    try {
                        var state = controller.state(fallbackStates[i]);
                        if (state && typeof state.get === 'function') {
                            var sel = state.get('selection');
                            if (sel) {
                                targetState = state;
                                selection = sel;
                                break;
                            }
                        }
                    } catch (e) {
                        // State doesn't exist, continue
                    }
                }
            }

            if (selection) {
                // Set the attachment as the selection
                selection.reset([attachment]);

                // Switch to the target state
                if (targetState.id && controller.state().id !== targetState.id) {
                    controller.setState(targetState.id);
                }

                // Trigger selection change to update toolbar buttons
                selection.trigger('selection:single');

                // Visual feedback in the Mediagraph tab
                $('.mediagraph-modal-asset').removeClass('selected');
                $('.mediagraph-modal-asset[data-asset-id="' + asset.id + '"]').addClass('selected');
            } else {
                // Last resort: switch to library if possible
                try {
                    controller.setState('library');
                } catch (e) {
                    controller.close();
                }
                alert('Image uploaded successfully! You can find it in the Media Library tab.');
            }
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
