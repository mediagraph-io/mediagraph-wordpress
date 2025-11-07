/**
 * MediaPicker - Main Picker Component
 *
 * Manages state and orchestrates all child components
 */

import { useState, useEffect } from 'react';
import ContainerTree from './ContainerTree';
import AssetGrid from './AssetGrid';
import AssetDetail from './AssetDetail';
import SearchBar from './SearchBar';

const MediaPicker = ({ editorId }) => {
  // State management
  const [isOpen, setIsOpen] = useState(true);
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingAssets, setIsLoadingAssets] = useState(true);
  const [error, setError] = useState(null);

  // Container tree state
  const [assetGroups, setAssetGroups] = useState({ collections: [], folders: [], lightboxes: [] });
  const [currentContainer, setCurrentContainer] = useState(null);
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false);

  // Assets state
  const [assets, setAssets] = useState([]);
  const [totalAssets, setTotalAssets] = useState(0);
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage] = useState(50);

  // Search and filter state
  const [searchQuery, setSearchQuery] = useState('');
  const [sortBy, setSortBy] = useState('created_at');
  const [showAll, setShowAll] = useState(false);

  // Selected asset for detail view
  const [selectedAsset, setSelectedAsset] = useState(null);

  // Get localized data from WordPress
  const pickerData = window.mediagraphPicker || {};
  const { ajaxUrl, nonce, isConnected } = pickerData;

  // Load asset groups on mount
  useEffect(() => {
    if (isOpen && isConnected) {
      loadAssetGroups();
    }
  }, [isOpen, isConnected]);

  // Load assets when container or filters change
  useEffect(() => {
    if (isOpen && isConnected) {
      loadAssets();
    }
  }, [currentContainer, searchQuery, sortBy, showAll, currentPage]);

  /**
   * Load asset groups (Collections, Folders, Lightboxes)
   */
  const loadAssetGroups = async () => {
    setIsLoading(true);
    setError(null);

    try {
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'mediagraph_get_asset_groups',
          nonce: nonce,
        }),
      });

      const data = await response.json();

      if (data.success) {
        setAssetGroups(data.data);
      } else {
        setError(data.data?.message || 'Failed to load asset groups');
      }
    } catch (err) {
      setError('Network error: ' + err.message);
    } finally {
      setIsLoading(false);
    }
  };

  /**
   * Load assets based on current filters
   */
  const loadAssets = async () => {
    setIsLoadingAssets(true);
    setError(null);

    try {
      const params = {
        action: 'mediagraph_search_assets',
        nonce: nonce,
        q: searchQuery,
        sort: sortBy,
        show_all: showAll ? '1' : '0',
        page: currentPage.toString(),
        per_page: perPage.toString(),
      };

      // Add asset group filter if a container is selected
      if (currentContainer?.id) {
        params.asset_group_id = currentContainer.id;
        params.asset_group_type = currentContainer.type; // Collection, StorageFolder, or Lightbox
      }

      const response = await fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams(params),
      });

      const data = await response.json();

      if (data.success) {
        setAssets(data.data.assets || []);
        setTotalAssets(data.data.total || 0);
      } else {
        setError(data.data?.message || 'Failed to load assets');
      }
    } catch (err) {
      setError('Network error: ' + err.message);
    } finally {
      setIsLoadingAssets(false);
    }
  };

  /**
   * Handle container selection
   */
  const handleContainerSelect = (container) => {
    setCurrentContainer(container);
    setCurrentPage(1);
  };

  /**
   * Handle asset selection (double-click for detail view)
   */
  const handleAssetSelect = (asset) => {
    setSelectedAsset(asset);
  };

  /**
   * Handle asset insertion into post
   */
  const handleAssetInsert = async (asset, metadata, displaySettings) => {
    try {
      // Get download URL
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'mediagraph_get_download_url',
          nonce: nonce,
          asset_id: asset.id,
          size: displaySettings.size || 'original',
        }),
      });

      const data = await response.json();

      if (!data.success) {
        throw new Error(data.data?.message || 'Failed to get download URL');
      }

      const downloadUrl = data.data.url;

      // Build media HTML (image or video)
      const mediaHtml = buildMediaHtml(asset, downloadUrl, metadata, displaySettings);

      // Check if this was opened from a Gutenberg block
      if (window.mediagraphCurrentBlock && window.mediagraphCurrentBlock.setAttributes) {
        // Update Gutenberg block attributes
        window.mediagraphCurrentBlock.setAttributes({
          assetId: asset.id,
          assetUrl: downloadUrl,
          assetTitle: metadata.title || asset.title,
          assetHtml: mediaHtml
        });
        // Clear the block reference
        window.mediagraphCurrentBlock = null;
      } else {
        // Insert into classic editor
        if (window.wp && window.wp.media && window.wp.media.editor) {
          window.wp.media.editor.insert(mediaHtml);
        } else {
          // Fallback for classic editor
          const editor = window.tinymce?.get(editorId);
          if (editor) {
            editor.insertContent(mediaHtml);
          }
        }
      }

      // Store asset metadata in post meta (for write-back on publish)
      storeAssetMetadata(asset, metadata, displaySettings);

      // Close modal
      setIsOpen(false);
    } catch (err) {
      setError('Failed to insert asset: ' + err.message);
    }
  };

  /**
   * Build HTML for media insertion (image or video)
   */
  const buildMediaHtml = (asset, url, metadata, displaySettings) => {
    const { alignment, linkTo, size } = displaySettings;
    const isVideo = asset.type === 'Video' || asset.mime_type?.startsWith('video/');
    const isAudio = asset.type === 'Audio' || asset.mime_type?.startsWith('audio/');

    let html = '';

    if (isVideo) {
      // Build video element
      html = `<video src="${url}" controls`;

      if (asset.preview_image_url) {
        html += ` poster="${asset.preview_image_url}"`;
      }

      if (alignment && alignment !== 'none') {
        html += ` class="align${alignment}"`;
      }

      html += ' style="max-width: 100%;">';
      html += '</video>';
    } else if (isAudio) {
      // Build audio element
      html = `<audio src="${url}" controls`;

      if (alignment && alignment !== 'none') {
        html += ` class="align${alignment}"`;
      }

      html += ' style="max-width: 100%;">';
      html += '</audio>';
    } else {
      // Build image element
      html = `<img src="${url}" alt="${metadata.alt_text || ''}"`;

      if (metadata.title) {
        html += ` title="${metadata.title}"`;
      }

      if (alignment && alignment !== 'none') {
        html += ` class="align${alignment}"`;
      }

      html += ' />';

      // Wrap in link if specified (images only)
      if (linkTo && linkTo !== 'none') {
        html = `<a href="${url}">${html}</a>`;
      }
    }

    // Add caption if specified
    if (metadata.caption) {
      html = `<figure>${html}<figcaption>${metadata.caption}</figcaption></figure>`;
    }

    return html;
  };

  /**
   * Store asset metadata for write-back on publish
   */
  const storeAssetMetadata = (asset, metadata, displaySettings) => {
    // Get existing assets from post meta
    const existingAssets = window._mediagraphAssets || [];

    // Add this asset
    existingAssets.push({
      id: asset.id,
      guid: asset.guid,
      filename: asset.filename,
      url: asset.url,
      usage_type: displaySettings.usage_type || 'body_photo',
      metadata: metadata,
    });

    // Store in global variable (will be saved via AJAX on save/publish)
    window._mediagraphAssets = existingAssets;
  };

  /**
   * Close modal
   */
  const handleClose = () => {
    setIsOpen(false);
  };

  // Don't render if not connected
  if (!isConnected) {
    return null;
  }

  // Don't render if closed
  if (!isOpen) {
    return null;
  }

  return (
    <div id="mediagraph-picker-modal" className="active">
      <div className="mediagraph-picker-content">
        {/* Header */}
        <div className="mediagraph-picker-header">
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
            <svg width="32" height="32" viewBox="0 0 486.45 486.45" xmlns="http://www.w3.org/2000/svg">
              <circle cx="243.22" cy="243.22" r="243.22" fill="#000"/>
              <g fill="#fff">
                <path d="M405.87,322.82l-59.34-173.19c-2.99-8.72-11.19-14.59-20.42-14.59h-38.43c-2.7-5.68-8.47-9.61-15.18-9.61-9.29,0-16.82,7.53-16.82,16.82s7.53,16.82,16.82,16.82c6.71,0,12.48-3.93,15.18-9.61h38.43c3.06,0,5.79,1.95,6.78,4.84l59.34,173.19c.76,2.22,.41,4.58-.95,6.49s-3.49,3-5.83,3h-33.5c-3.06,0-5.79-1.95-6.78-4.84l-36.01-105.1c3.13-3.05,5.08-7.31,5.08-12.03,0-9.29-7.53-16.82-16.82-16.82s-16.82,7.53-16.82,16.82c0,8.65,6.53,15.77,14.93,16.71l36.01,105.1c2.99,8.72,11.19,14.59,20.42,14.59h33.5c6.95,0,13.52-3.38,17.56-9.04,4.04-5.66,5.11-12.96,2.86-19.54Z"/>
                <path d="M144.73,159.07c6.71,0,12.48-3.93,15.18-9.61h38.43c3.06,0,5.79,1.95,6.78,4.84l26.34,76.89c-3.13,3.05-5.08,7.31-5.08,12.03,0,9.29,7.53,16.82,16.82,16.82s16.82-7.53,16.82-16.82c0-8.65-6.53-15.77-14.93-16.71l-26.34-76.88c-2.99-8.73-11.19-14.59-20.42-14.59h-38.43c-2.7-5.68-8.47-9.61-15.18-9.61-9.29,0-16.82,7.53-16.82,16.82s7.53,16.82,16.82,16.82Z"/>
                <path d="M278.01,327.37c-6.71,0-12.48,3.93-15.18,9.61h-37.23c-3.9,0-7.38-2.48-8.64-6.17l-40.08-116.97c-3.3-9.62-12-15.84-22.16-15.84h0c-10.17,0-18.87,6.21-22.17,15.83l-37.44,109.08c-2.25,6.67-1.19,13.77,2.91,19.49,4.1,5.72,10.5,9,17.53,9h28.67c2.7,5.68,8.47,9.61,15.18,9.61,9.29,0,16.82-7.53,16.82-16.82s-7.53-16.82-16.82-16.82c-6.71,0-12.48,3.93-15.18,9.61h-28.67c-2.34,0-4.46-1.09-5.82-2.99-1.36-1.9-1.71-4.26-.98-6.44l37.43-109.05c1.94-5.65,7.02-6.09,8.53-6.09h0c1.51,0,6.59,.44,8.53,6.1l40.08,116.97c3.26,9.52,12.22,15.92,22.28,15.92h37.23c2.7,5.68,8.47,9.61,15.18,9.61,9.29,0,16.82-7.53,16.82-16.82s-7.53-16.82-16.82-16.82Z"/>
              </g>
            </svg>
            <h2 style={{ margin: 0 }}>Mediagraph Picker</h2>
          </div>
          <button
            className="mediagraph-picker-close"
            onClick={handleClose}
            aria-label="Close"
          >
            ×
          </button>
        </div>

        {/* Search Bar */}
        <SearchBar
          searchQuery={searchQuery}
          onSearchChange={setSearchQuery}
          sortBy={sortBy}
          onSortChange={setSortBy}
          showAll={showAll}
          onShowAllChange={setShowAll}
          sidebarCollapsed={sidebarCollapsed}
          onToggleSidebar={() => setSidebarCollapsed(!sidebarCollapsed)}
          currentContainer={currentContainer}
          totalAssets={totalAssets}
          visibleAssets={assets.length}
        />

        {/* Main Content */}
        <div className="mediagraph-picker-body">
          {/* Sidebar - Container Tree */}
          {!sidebarCollapsed && (
            <ContainerTree
              assetGroups={assetGroups}
              currentContainer={currentContainer}
              onContainerSelect={handleContainerSelect}
              ajaxUrl={ajaxUrl}
              nonce={nonce}
            />
          )}

          {/* Asset Grid */}
          <AssetGrid
            assets={assets}
            isLoading={isLoadingAssets}
            error={error}
            onAssetSelect={handleAssetSelect}
            currentPage={currentPage}
            totalPages={Math.ceil(totalAssets / perPage)}
            onPageChange={setCurrentPage}
          />
        </div>

        {/* Asset Detail Modal */}
        {selectedAsset && (
          <AssetDetail
            asset={selectedAsset}
            onClose={() => setSelectedAsset(null)}
            onInsert={handleAssetInsert}
            ajaxUrl={ajaxUrl}
            nonce={nonce}
          />
        )}
      </div>
    </div>
  );
};

export default MediaPicker;
