/**
 * ContainerTree - Hierarchical navigation for Collections, Folders, Lightboxes
 */

import { useState } from 'react';

const ContainerTree = ({ assetGroups, currentContainer, onContainerSelect, onReload, isReloading, ajaxUrl, nonce }) => {
  const [expandedNodes, setExpandedNodes] = useState(new Set());
  const [loadingNodes, setLoadingNodes] = useState(new Set());
  const [nodeChildren, setNodeChildren] = useState({}); // Cache loaded children

  // Section collapse state (stored in localStorage)
  const getStoredSections = () => {
    try {
      const stored = localStorage.getItem('mediagraph_collapsed_sections');
      return stored ? new Set(JSON.parse(stored)) : new Set();
    } catch {
      return new Set();
    }
  };

  const [collapsedSections, setCollapsedSections] = useState(getStoredSections());

  const toggleSection = (sectionKey) => {
    const newCollapsed = new Set(collapsedSections);
    if (newCollapsed.has(sectionKey)) {
      newCollapsed.delete(sectionKey);
    } else {
      newCollapsed.add(sectionKey);
    }
    setCollapsedSections(newCollapsed);

    // Store in localStorage
    try {
      localStorage.setItem('mediagraph_collapsed_sections', JSON.stringify([...newCollapsed]));
    } catch (err) {
      console.error('Failed to save collapsed sections:', err);
    }
  };

  /**
   * Toggle node expansion (and load children if needed)
   */
  const toggleNode = async (node) => {
    const nodeId = node.id;
    const newExpanded = new Set(expandedNodes);

    if (newExpanded.has(nodeId)) {
      // Collapse
      newExpanded.delete(nodeId);
      setExpandedNodes(newExpanded);
    } else {
      // Expand - load children if not already loaded
      newExpanded.add(nodeId);
      setExpandedNodes(newExpanded);

      // Check if we need to load children
      if (node.has_children && !nodeChildren[nodeId]) {
        await loadChildren(nodeId, node.type, node.sub_type);
      }
    }
  };

  /**
   * Load children for a node
   */
  const loadChildren = async (parentId, parentType, subType) => {
    // Mark as loading
    const newLoading = new Set(loadingNodes);
    newLoading.add(parentId);
    setLoadingNodes(newLoading);

    try {
      const response = await fetch(ajaxUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          action: 'mediagraph_get_asset_group_children',
          nonce: nonce,
          parent_id: parentId,
          parent_type: parentType,
          sub_type: subType || '',
        }),
      });

      const data = await response.json();

      if (data.success) {
        // Store children - API returns the appropriate type based on parent_type
        const children = parentType === 'Collection'
          ? data.data.collections
          : parentType === 'StorageFolder'
          ? data.data.folders
          : data.data.lightboxes;

        setNodeChildren(prev => ({
          ...prev,
          [parentId]: children || []
        }));
      } else {
        console.error('Failed to load children:', data.data?.message);
      }
    } catch (err) {
      console.error('Failed to load children:', err);
    } finally {
      // Remove loading state
      const newLoading = new Set(loadingNodes);
      newLoading.delete(parentId);
      setLoadingNodes(newLoading);
    }
  };

  /**
   * Render a single tree node recursively
   */
  const renderNode = (node, depth = 0) => {
    const hasChildren = node.has_children || (node.children && node.children.length > 0);
    const isExpanded = expandedNodes.has(node.id);
    const isActive = currentContainer?.id === node.id;
    const isLoading = loadingNodes.has(node.id);

    // Get children - either pre-loaded or async loaded
    const children = nodeChildren[node.id] || node.children || [];

    return (
      <li key={node.id} className="mediagraph-container-item">
        {hasChildren ? (
          <button
            className="mediagraph-container-toggle"
            onClick={() => toggleNode(node)}
            aria-label={isExpanded ? 'Collapse' : 'Expand'}
            disabled={isLoading}
          >
            {isLoading ? '⏳' : (isExpanded ? '▼' : '▶')}
          </button>
        ) : (
          <span className="mediagraph-container-toggle" />
        )}

        <button
          className={`mediagraph-container-button ${isActive ? 'active' : ''}`}
          onClick={() => onContainerSelect(node)}
        >
          <span className="mediagraph-container-icon">
            {getContainerIcon(node.type, node.organizer)}
          </span>
          <span className="mediagraph-container-name">
            {node.name}
          </span>
          {node.visible_assets_count !== undefined && node.visible_assets_count !== null && (
            <span className="mediagraph-container-count">
              {' '}({node.visible_assets_count || 0})
            </span>
          )}
        </button>

        {hasChildren && isExpanded && (
          <ul className="mediagraph-container-children">
            {isLoading ? (
              <li className="mediagraph-container-loading">Loading...</li>
            ) : children.length > 0 ? (
              children.map(child => renderNode(child, depth + 1))
            ) : (
              <li className="mediagraph-container-empty">No items</li>
            )}
          </ul>
        )}
      </li>
    );
  };

  /**
   * Get icon for container type
   * @param {string} type - Container type (Collection, StorageFolder, Lightbox)
   * @param {boolean} isOrganizer - Whether this is an organizer container
   */
  const getContainerIcon = (type, isOrganizer = false) => {
    switch (type) {
      case 'Collection':
        // Organizer collections use file cabinet icon
        return isOrganizer ? '🗄️' : '📁';
      case 'StorageFolder':
        // Organizer storage folders use card file box
        return isOrganizer ? '🗃️' : '🗂️';
      case 'Lightbox':
        // Organizer lightboxes use clipboard
        return isOrganizer ? '📋' : '💡';
      default:
        return '📄';
    }
  };

  /**
   * Render a section (Collections, Folders, or Lightboxes)
   */
  const renderSection = (title, items, emptyMessage, sectionKey) => {
    const isCollapsed = collapsedSections.has(sectionKey);
    const hasItems = items && items.length > 0;

    return (
      <div className="mediagraph-container-section">
        <h3
          className="mediagraph-section-header"
          onClick={() => toggleSection(sectionKey)}
          style={{ cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}
        >
          <span>{title}</span>
          <span className="mediagraph-section-toggle">
            {isCollapsed ? '▶' : '▼'}
          </span>
        </h3>

        {!isCollapsed && (
          hasItems ? (
            <ul className="mediagraph-container-tree">
              {items.map(item => renderNode(item))}
            </ul>
          ) : (
            <p className="mediagraph-empty-message">{emptyMessage}</p>
          )
        )}
      </div>
    );
  };

  return (
    <div className="mediagraph-picker-sidebar">
      {/* Sidebar Header with Reload */}
      <div className="mediagraph-sidebar-header">
        <span className="mediagraph-sidebar-title">Browse</span>
        <button
          className="mediagraph-reload-button"
          onClick={onReload}
          disabled={isReloading}
          title="Reload asset groups"
        >
          {isReloading ? (
            <span className="mediagraph-reload-spinner"></span>
          ) : (
            <span className="mediagraph-reload-icon">↻</span>
          )}
        </button>
      </div>

      {isReloading ? (
        <div className="mediagraph-sidebar-loading">
          <span className="mediagraph-spinner"></span>
          <div>Loading...</div>
        </div>
      ) : (
        <>
          {/* All Assets */}
          <div className="mediagraph-container-section">
            <button
              className={`mediagraph-container-button mediagraph-all-assets ${!currentContainer ? 'active' : ''}`}
              onClick={() => onContainerSelect(null)}
            >
              <span className="mediagraph-container-icon">📂</span>
              <span className="mediagraph-container-name">All Assets</span>
            </button>
          </div>

          {/* Storage Folders */}
          {renderSection(
            'Storage Folders',
            assetGroups.folders,
            'No folders available',
            'folders'
          )}

          {/* Collections */}
          {renderSection(
            'Collections',
            assetGroups.collections,
            'No collections available',
            'collections'
          )}

          {/* Lightboxes */}
          {renderSection(
            'Lightboxes',
            assetGroups.lightboxes,
            'No lightboxes available',
            'lightboxes'
          )}
        </>
      )}
    </div>
  );
};

export default ContainerTree;
