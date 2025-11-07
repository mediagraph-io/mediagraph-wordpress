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

  console.log('Mediagraph Picker initialized');
});
