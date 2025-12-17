/**
 * AssetDetail - Modal for viewing asset details and editing metadata
 */

import { useState, useEffect } from 'react';

/**
 * Resolve a URL to a full URL using the API base URL if it's a relative path
 */
const resolveUrl = (url) => {
  if (!url) return null;
  // If it's already a full URL, return as-is
  if (url.startsWith('http://') || url.startsWith('https://')) {
    return url;
  }
  // Prepend the API base URL for relative paths
  const apiBaseUrl = window.mediagraphPicker?.apiBaseUrl || '';
  return apiBaseUrl ? `${apiBaseUrl.replace(/\/$/, '')}${url}` : url;
};

const AssetDetail = ({ asset, onClose, onInsert, ajaxUrl, nonce }) => {
  const [isLoading, setIsLoading] = useState(true);
  const [isInserting, setIsInserting] = useState(false);
  const [error, setError] = useState(null);
  const [assetData, setAssetData] = useState(null);

  // Editable metadata (local to WordPress)
  const [metadata, setMetadata] = useState({
    title: '',
    byline: '',
    headline: '',
    description: '',
    alt_text: '',
    extended_description: '',
    keywords: '',
    usage_rights: '',
  });

  // Display settings
  const [displaySettings, setDisplaySettings] = useState({
    alignment: 'none',
    linkTo: 'none',
    size: 'medium',
  });

  // Load full asset details on mount
  useEffect(() => {
    loadAssetDetails();
  }, [asset.id]);

  // Handle Escape key to close modal
  useEffect(() => {
    const handleEscapeKey = (event) => {
      if (event.key === 'Escape' && !isInserting) {
        onClose();
      }
    };

    document.addEventListener('keydown', handleEscapeKey);
    return () => {
      document.removeEventListener('keydown', handleEscapeKey);
    };
  }, [isInserting, onClose]);

  /**
   * Load full asset details from API
   */
  const loadAssetDetails = async () => {
    setIsLoading(true);
    setError(null);

    try {
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'mediagraph_get_asset',
          nonce: nonce,
          asset_id: asset.id,
        }),
      });

      const data = await response.json();

      if (data.success) {
        setAssetData(data.data);

        // Pre-fill metadata from asset
        // Map Rails API fields to WordPress metadata fields
        const creatorString = Array.isArray(data.data.creator)
          ? data.data.creator.join(', ')
          : data.data.creator || '';

        // Extract keywords from tags array
        const keywordsString = Array.isArray(data.data.tags)
          ? data.data.tags.map(tag => tag.name).join(', ')
          : '';

        // Pre-fill metadata from API
        setMetadata({
          title: data.data.title || '',
          byline: data.data.credit_line || creatorString || '',
          headline: data.data.headline || data.data.title || '',
          description: data.data.description || '',
          alt_text: data.data.alt_text || '',
          extended_description: data.data.extended_description || data.data.description || '',
          keywords: keywordsString,
          usage_rights: data.data.usage_terms || data.data.rights_package?.meta_field_text || data.data.iptc_rights || '',
        });
      } else {
        setError(data.data?.message || 'Failed to load asset details');
      }
    } catch (err) {
      setError('Network error: ' + err.message);
    } finally {
      setIsLoading(false);
    }
  };

  /**
   * Handle metadata field change
   */
  const handleMetadataChange = (field, value) => {
    setMetadata({
      ...metadata,
      [field]: value,
    });
  };

  /**
   * Handle display setting change
   */
  const handleDisplayChange = (field, value) => {
    setDisplaySettings({
      ...displaySettings,
      [field]: value,
    });
  };

  /**
   * Handle insert button click
   */
  const handleInsertClick = async () => {
    setIsInserting(true);
    try {
      await onInsert(assetData || asset, metadata, displaySettings);
    } catch (err) {
      // Error will be handled by parent component
      setIsInserting(false);
    }
  };

  /**
   * Format file size
   */
  const formatFileSize = (bytes) => {
    if (!bytes) return 'Unknown';

    const kb = bytes / 1024;
    if (kb < 1024) {
      return `${Math.round(kb)} KB`;
    }

    const mb = kb / 1024;
    if (mb < 1024) {
      return `${Math.round(mb * 10) / 10} MB`;
    }

    const gb = mb / 1024;
    return `${Math.round(gb * 10) / 10} GB`;
  };

  /**
   * Format duration (for video/audio)
   */
  const formatDuration = (seconds) => {
    if (!seconds) return null;

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hours > 0) {
      return `${hours}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
    }
    return `${minutes}:${String(secs).padStart(2, '0')}`;
  };

  // Loading state
  if (isLoading) {
    return (
      <div className="mediagraph-asset-detail">
        <div className="mediagraph-asset-detail-header">
          <h2>Loading...</h2>
          <button onClick={onClose} className="mediagraph-picker-close">
            ×
          </button>
        </div>
        <div className="mediagraph-loading">
          <span className="mediagraph-spinner"></span>
          Loading asset details...
        </div>
      </div>
    );
  }

  // Error state
  if (error) {
    return (
      <div className="mediagraph-asset-detail">
        <div className="mediagraph-asset-detail-header">
          <h2>Error</h2>
          <button onClick={onClose} className="mediagraph-picker-close">
            ×
          </button>
        </div>
        <div className="mediagraph-error">{error}</div>
      </div>
    );
  }

  const data = assetData || asset;
  const isVideo = data.type === 'Video' || data.mime_type?.startsWith('video/');
  const isAudio = data.type === 'Audio' || data.mime_type?.startsWith('audio/');
  const isRestricted = data.downloadable === false;

  // Rails API returns: thumb_url, grid_url, small_url, permalink_url, preview_image_url
  // preview_image_url may be a relative path that needs the API host prepended
  const thumbnailUrl = data.thumb_url || data.grid_url || data.small_url;
  const previewUrl = data.permalink_url || data.small_url;
  const posterUrl = resolveUrl(data.preview_image_url) || data.thumb_url;

  return (
    <div className="mediagraph-asset-detail">
      {/* Header */}
      <div className="mediagraph-asset-detail-header">
        <h2>{data.filename || 'Asset Details'}</h2>
        <button onClick={onClose} className="mediagraph-picker-close">
          ×
        </button>
      </div>

      {/* Body */}
      <div className="mediagraph-asset-detail-body">
        {/* Preview */}
        <div className="mediagraph-asset-detail-preview">
          {isVideo ? (
            <video
              src={previewUrl}
              controls
              poster={posterUrl}
              style={{ maxWidth: '100%', maxHeight: '100%' }}
            />
          ) : isAudio ? (
            <audio src={previewUrl} controls style={{ width: '100%' }} />
          ) : (
            <img
              src={previewUrl}
              alt={data.alt_text || data.filename}
              style={{ maxWidth: '100%', maxHeight: '100%' }}
            />
          )}
        </div>

        {/* Metadata Sidebar */}
        <div className="mediagraph-asset-detail-metadata">
          {/* Properties (Read-only) */}
          <div className="mediagraph-metadata-section">
            <h3>Properties</h3>

            <div className="mediagraph-metadata-field">
              <label>File Name</label>
              <input
                type="text"
                value={data.filename || ''}
                readOnly
              />
            </div>

            {data.guid && (
              <div className="mediagraph-metadata-field">
                <label>GUID</label>
                <input
                  type="text"
                  value={data.guid}
                  readOnly
                />
              </div>
            )}

            {data.date_created && (
              <div className="mediagraph-metadata-field">
                <label>Date</label>
                <input
                  type="text"
                  value={new Date(data.date_created).toLocaleDateString()}
                  readOnly
                />
              </div>
            )}

            <div className="mediagraph-metadata-field">
              <label>Size</label>
              <input
                type="text"
                value={formatFileSize(data.file_size)}
                readOnly
              />
            </div>

            {data.duration && (
              <div className="mediagraph-metadata-field">
                <label>Duration</label>
                <input
                  type="text"
                  value={formatDuration(data.duration)}
                  readOnly
                />
              </div>
            )}

            {data.creator && (
              <div className="mediagraph-metadata-field">
                <label>Creator</label>
                <input
                  type="text"
                  value={data.creator}
                  readOnly
                />
              </div>
            )}

            {data.dimensions && (
              <div className="mediagraph-metadata-field">
                <label>Dimensions</label>
                <input
                  type="text"
                  value={`${data.dimensions.width} × ${data.dimensions.height}`}
                  readOnly
                />
              </div>
            )}
          </div>

          {/* Metadata (Editable) */}
          <div className="mediagraph-metadata-section">
            <h3>Metadata (WordPress)</h3>
            <p className="description">
              Edit these fields to customize how the asset appears in WordPress.
              Changes are local to WordPress and won't affect the original in Mediagraph.
            </p>

            <div className="mediagraph-metadata-field">
              <label>Title</label>
              <input
                type="text"
                value={metadata.title}
                onChange={(e) => handleMetadataChange('title', e.target.value)}
              />
            </div>

            <div className="mediagraph-metadata-field">
              <label>Byline</label>
              <input
                type="text"
                value={metadata.byline}
                onChange={(e) => handleMetadataChange('byline', e.target.value)}
              />
            </div>

            <div className="mediagraph-metadata-field">
              <label>Headline</label>
              <input
                type="text"
                value={metadata.headline}
                onChange={(e) => handleMetadataChange('headline', e.target.value)}
              />
            </div>

            <div className="mediagraph-metadata-field">
              <label>Description</label>
              <textarea
                value={metadata.description}
                onChange={(e) => handleMetadataChange('description', e.target.value)}
                rows="3"
              />
            </div>

            <div className="mediagraph-metadata-field">
              <label>Alt Text</label>
              <input
                type="text"
                value={metadata.alt_text}
                onChange={(e) => handleMetadataChange('alt_text', e.target.value)}
              />
            </div>

            <div className="mediagraph-metadata-field">
              <label>Extended Description</label>
              <textarea
                value={metadata.extended_description}
                onChange={(e) => handleMetadataChange('extended_description', e.target.value)}
                rows="3"
              />
            </div>

            <div className="mediagraph-metadata-field">
              <label>Keywords</label>
              <input
                type="text"
                value={metadata.keywords}
                onChange={(e) => handleMetadataChange('keywords', e.target.value)}
                placeholder="Comma-separated"
              />
            </div>

            <div className="mediagraph-metadata-field">
              <label>Usage Rights Statement</label>
              <textarea
                value={metadata.usage_rights}
                onChange={(e) => handleMetadataChange('usage_rights', e.target.value)}
                rows="2"
              />
            </div>
          </div>
        </div>
      </div>

      {/* Footer */}
      <div className="mediagraph-asset-detail-footer">
        <div className="mediagraph-display-settings">
          <div>
            <label>Alignment:</label>
            <select
              value={displaySettings.alignment}
              onChange={(e) => handleDisplayChange('alignment', e.target.value)}
            >
              <option value="none">None</option>
              <option value="left">Left</option>
              <option value="center">Center</option>
              <option value="right">Right</option>
            </select>
          </div>

          <div>
            <label>Link To:</label>
            <select
              value={displaySettings.linkTo}
              onChange={(e) => handleDisplayChange('linkTo', e.target.value)}
            >
              <option value="none">None</option>
              <option value="media">Media File</option>
              <option value="attachment">Attachment Page</option>
            </select>
          </div>

          <div>
            <label>Size:</label>
            <select
              value={displaySettings.size}
              onChange={(e) => handleDisplayChange('size', e.target.value)}
            >
              <option value="thumbnail">Thumbnail</option>
              <option value="medium">Medium</option>
              <option value="large">Large</option>
              <option value="full">Full Size</option>
            </select>
          </div>
        </div>

        <div>
          <button
            onClick={onClose}
            className="mediagraph-button mediagraph-button-secondary"
          >
            Cancel
          </button>
          <button
            onClick={handleInsertClick}
            className="mediagraph-button mediagraph-button-primary"
            disabled={isRestricted || isInserting}
            title={isRestricted ? 'You do not have download permission' : 'Insert into post'}
          >
            {isInserting ? (
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: '8px' }}>
                <span className="mediagraph-spinner" style={{ width: '14px', height: '14px', borderWidth: '2px' }}></span>
                <span>Downloading...</span>
              </span>
            ) : (
              isRestricted ? '🔒 No Permission' : 'Insert into Post'
            )}
          </button>
        </div>
      </div>
    </div>
  );
};

export default AssetDetail;
