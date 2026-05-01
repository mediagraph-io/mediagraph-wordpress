/**
 * Mediagraph Picker - Main Entry Point
 *
 * This file initializes the React application and mounts it into WordPress
 */

import { createRoot } from 'react-dom/client';
import MediaPicker from './MediaPicker';

// Global picker state
let pickerRoot = null;
let currentPickerRef = null;

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  // Get the root element
  const rootElement = document.getElementById('mediagraph-picker-modal-root');

  if (!rootElement) {
    console.error('Mediagraph picker root element not found');
    return;
  }

  // Create React root
  pickerRoot = createRoot(rootElement);

  // Expose global API for opening the picker
  window.MediagraphPicker = {
    open: (editorId = 'content') => {
      if (!pickerRoot) {
        console.error('Mediagraph picker not initialized');
        return;
      }

      // Render the picker modal with a ref to control it
      pickerRoot.render(
        <MediaPicker
          editorId={editorId}
          key={Date.now()} // Force new instance each time
        />
      );
    },

    close: () => {
      if (pickerRoot) {
        pickerRoot.render(null);
      }
    },

    /**
     * Mount the picker inline inside an arbitrary container (e.g. the WP media modal's
     * Mediagraph tab). The host supplies onAssetReady to receive the downloaded
     * attachment and apply its own selection logic.
     *
     * @param {HTMLElement} container DOM node to mount into
     * @param {Object}      options
     * @param {Function}    options.onAssetReady  (asset, { attachmentId, url, assetType, metadata, displaySettings, html }) => void
     * @returns {{ unmount: Function }}
     */
    mountInline: (container, options = {}) => {
      if (!container) {
        console.error('Mediagraph picker: mountInline requires a container element');
        return { unmount: () => {} };
      }

      const root = createRoot(container);
      root.render(
        <MediaPicker
          inline
          onAssetReady={options.onAssetReady || null}
          key={Date.now()}
        />
      );

      return {
        unmount: () => {
          try {
            root.unmount();
          } catch (e) {
            // Container already gone; ignore
          }
        }
      };
    }
  };

  // Listen for the classic editor Mediagraph button click
  document.addEventListener('click', (event) => {
    if (event.target.id === 'mediagraph-picker-button' ||
        event.target.closest('#mediagraph-picker-button')) {
      event.preventDefault();

      // Get the editor ID from the button
      const button = event.target.closest('#mediagraph-picker-button') || event.target;
      const editorId = button.getAttribute('data-editor') || 'content';

      // Open the picker
      window.MediagraphPicker.open(editorId);
    }
  });

  console.log('Mediagraph Assets initialized');
});
