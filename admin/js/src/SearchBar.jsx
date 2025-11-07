/**
 * SearchBar - Search and filter controls
 */

import { useState, useEffect } from 'react';

const SearchBar = ({
  searchQuery,
  onSearchChange,
  sortBy,
  onSortChange,
  showAll,
  onShowAllChange,
  sidebarCollapsed,
  onToggleSidebar,
  currentContainer,
  totalAssets,
  visibleAssets,
}) => {
  const [localQuery, setLocalQuery] = useState(searchQuery);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      if (localQuery !== searchQuery) {
        onSearchChange(localQuery);
      }
    }, 300);

    return () => clearTimeout(timer);
  }, [localQuery]);

  /**
   * Render breadcrumb
   */
  const renderBreadcrumb = () => {
    if (!currentContainer) {
      return <span className="mediagraph-breadcrumb-item">All Assets</span>;
    }

    // Build breadcrumb trail
    const breadcrumbs = [];
    let current = currentContainer;

    // For now, just show the current container
    // In a more complex implementation, you could traverse parent_id to build full path
    breadcrumbs.unshift(current.name);

    return (
      <div className="mediagraph-picker-breadcrumb">
        <span className="mediagraph-breadcrumb-item">
          {breadcrumbs.join(' / ')}
        </span>
      </div>
    );
  };

  return (
    <div className="mediagraph-picker-nav">
      {/* Toggle Sidebar Button */}
      <button
        className="mediagraph-button mediagraph-button-secondary"
        onClick={onToggleSidebar}
        title={sidebarCollapsed ? 'Show sidebar' : 'Hide sidebar'}
      >
        {sidebarCollapsed ? '▶' : '◀'}
      </button>

      {/* Breadcrumb */}
      {renderBreadcrumb()}

      {/* File Counter */}
      <div className="mediagraph-file-counter">
        Showing {visibleAssets} of {totalAssets} assets
      </div>

      {/* Search Input */}
      <div className="mediagraph-picker-search">
        <input
          type="search"
          placeholder="Search assets..."
          value={localQuery}
          onChange={(e) => setLocalQuery(e.target.value)}
          aria-label="Search assets"
        />
      </div>

      {/* Sort Dropdown */}
      <div className="mediagraph-picker-sort">
        <select
          value={sortBy}
          onChange={(e) => onSortChange(e.target.value)}
          aria-label="Sort by"
        >
          <option value="created_at">Date Uploaded</option>
          <option value="captured_at">Date Taken</option>
          <option value="filename">Filename</option>
        </select>
      </div>

      {/* Visibility Toggle */}
      <div className="mediagraph-picker-visibility">
        <label title="Show all files including those you don't have download permission for">
          <input
            type="checkbox"
            checked={showAll}
            onChange={(e) => onShowAllChange(e.target.checked)}
          />
          <span style={{ marginLeft: '6px' }}>
            👁️ Show all files
          </span>
        </label>
      </div>
    </div>
  );
};

export default SearchBar;
