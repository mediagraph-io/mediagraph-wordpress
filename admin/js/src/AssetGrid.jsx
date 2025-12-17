/**
 * AssetGrid - Display assets in a grid with thumbnails
 */

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

const AssetGrid = ({
  assets,
  isLoading,
  error,
  onAssetSelect,
  currentPage,
  totalPages,
  onPageChange,
}) => {
  /**
   * Render loading state
   */
  if (isLoading) {
    return (
      <div className="mediagraph-picker-main">
        <div className="mediagraph-loading">
          <span className="mediagraph-spinner"></span>
          <div>Loading assets...</div>
        </div>
      </div>
    );
  }

  /**
   * Render error state
   */
  if (error) {
    return (
      <div className="mediagraph-picker-main">
        <div className="mediagraph-error">
          <strong>Error:</strong> {error}
        </div>
      </div>
    );
  }

  /**
   * Render empty state
   */
  if (!assets || assets.length === 0) {
    return (
      <div className="mediagraph-picker-main">
        <div className="mediagraph-empty">
          <div className="mediagraph-empty-icon">📁</div>
          <div className="mediagraph-empty-message">
            No assets found. Try adjusting your search or filters.
          </div>
        </div>
      </div>
    );
  }

  /**
   * Render single asset item
   */
  const renderAsset = (asset) => {
    const isRestricted = asset.downloadable === false;
    const isVideo = asset.type === 'Video' || asset.mime_type?.startsWith('video/');
    const isAudio = asset.type === 'Audio' || asset.mime_type?.startsWith('audio/');

    // Document types that don't have image previews
    const documentMimeTypes = [
      'application/pdf',
      'application/msword',
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.ms-powerpoint',
      'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'text/plain',
      'text/csv',
      'application/zip',
      'application/x-rar-compressed',
    ];
    const isDocument = asset.type === 'Document' ||
                      documentMimeTypes.some(type => asset.mime_type?.includes(type));

    // Rails API returns: thumb_url, grid_url, small_url, permalink_url
    // preview_image_url may be a relative path that needs the API host prepended
    const thumbnailUrl = asset.grid_url || asset.thumb_url || asset.small_url;
    const previewUrl = asset.permalink_url || asset.small_url;
    const posterUrl = resolveUrl(asset.preview_image_url) || asset.thumb_url;

    return (
      <div
        key={asset.id}
        className={`mediagraph-asset-item ${isRestricted ? 'restricted' : ''}`}
        onClick={() => !isRestricted && onAssetSelect(asset)}
        title={isRestricted ? 'You do not have download permission for this asset' : asset.filename}
      >
        {/* Thumbnail */}
        {isVideo ? (
          <div className="mediagraph-asset-thumbnail mediagraph-asset-video">
            {(posterUrl || thumbnailUrl) ? (
              <img
                src={posterUrl || thumbnailUrl}
                alt={asset.alt_text || asset.filename}
                loading="lazy"
              />
            ) : (
              <div className="mediagraph-video-placeholder">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                  <rect x="2" y="4" width="20" height="16" rx="2" stroke="#666" strokeWidth="2"/>
                  <path d="M10 8.5L16 12L10 15.5V8.5Z" fill="#666"/>
                </svg>
              </div>
            )}
            <span className="mediagraph-asset-type-badge">▶ Video</span>
          </div>
        ) : isAudio ? (
          <div className="mediagraph-asset-thumbnail mediagraph-asset-audio">
            <div className="mediagraph-audio-placeholder">🎵</div>
            <span className="mediagraph-asset-type-badge">🎵 Audio</span>
          </div>
        ) : isDocument ? (
          <div className="mediagraph-asset-thumbnail mediagraph-asset-document">
            <div className="mediagraph-document-placeholder">
              <svg width="48" height="48" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M14 2H6C5.46957 2 4.96086 2.21071 4.58579 2.58579C4.21071 2.96086 4 3.46957 4 4V20C4 20.5304 4.21071 21.0391 4.58579 21.4142C4.96086 21.7893 5.46957 22 6 22H18C18.5304 22 19.0391 21.7893 19.4142 21.4142C19.7893 21.0391 20 20.5304 20 20V8L14 2Z" stroke="#666" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                <path d="M14 2V8H20" stroke="#666" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                <path d="M16 13H8" stroke="#666" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                <path d="M16 17H8" stroke="#666" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
                <path d="M10 9H9H8" stroke="#666" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/>
              </svg>
            </div>
            <span className="mediagraph-asset-type-badge">📄 Document</span>
          </div>
        ) : (
          <img
            src={thumbnailUrl}
            alt={asset.alt_text || asset.filename}
            className="mediagraph-asset-thumbnail"
            loading="lazy"
          />
        )}

        {/* Asset Info */}
        <div className="mediagraph-asset-info">
          <div className="mediagraph-asset-name" title={asset.filename}>
            {asset.filename || 'Untitled'}
          </div>
          {asset.file_size && (
            <div className="mediagraph-asset-size">
              {formatFileSize(asset.file_size)}
            </div>
          )}
        </div>

        {/* Restricted indicator */}
        {isRestricted && (
          <div className="mediagraph-asset-restricted-badge">
            🔒 No Download Permission
          </div>
        )}
      </div>
    );
  };

  /**
   * Format file size in human-readable format
   */
  const formatFileSize = (bytes) => {
    if (!bytes) return '';

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
   * Render pagination
   */
  const renderPagination = () => {
    if (totalPages <= 1) return null;

    const pages = [];
    const maxVisiblePages = 7;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);

    // Adjust start page if we're near the end
    if (endPage - startPage < maxVisiblePages - 1) {
      startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }

    // Previous button
    if (currentPage > 1) {
      pages.push(
        <button
          key="prev"
          onClick={() => onPageChange(currentPage - 1)}
          className="mediagraph-button mediagraph-button-secondary"
        >
          ← Previous
        </button>
      );
    }

    // First page and ellipsis
    if (startPage > 1) {
      pages.push(
        <button
          key={1}
          onClick={() => onPageChange(1)}
          className="mediagraph-button mediagraph-button-secondary"
        >
          1
        </button>
      );
      if (startPage > 2) {
        pages.push(
          <span key="ellipsis1" className="mediagraph-pagination-ellipsis">
            ...
          </span>
        );
      }
    }

    // Page numbers
    for (let i = startPage; i <= endPage; i++) {
      pages.push(
        <button
          key={i}
          onClick={() => onPageChange(i)}
          className={`mediagraph-button ${
            i === currentPage ? 'mediagraph-button-primary' : 'mediagraph-button-secondary'
          }`}
        >
          {i}
        </button>
      );
    }

    // Last page and ellipsis
    if (endPage < totalPages) {
      if (endPage < totalPages - 1) {
        pages.push(
          <span key="ellipsis2" className="mediagraph-pagination-ellipsis">
            ...
          </span>
        );
      }
      pages.push(
        <button
          key={totalPages}
          onClick={() => onPageChange(totalPages)}
          className="mediagraph-button mediagraph-button-secondary"
        >
          {totalPages}
        </button>
      );
    }

    // Next button
    if (currentPage < totalPages) {
      pages.push(
        <button
          key="next"
          onClick={() => onPageChange(currentPage + 1)}
          className="mediagraph-button mediagraph-button-secondary"
        >
          Next →
        </button>
      );
    }

    return (
      <div className="mediagraph-pagination">
        {pages}
      </div>
    );
  };

  return (
    <div className="mediagraph-picker-main">
      {/* Asset Grid */}
      <div className="mediagraph-asset-grid">
        {assets.map(asset => renderAsset(asset))}
      </div>

      {/* Pagination */}
      {renderPagination()}
    </div>
  );
};

export default AssetGrid;
