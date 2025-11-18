<?php
/**
 * Mediagraph API Client
 *
 * Handles all communication with the Mediagraph API including authentication,
 * asset retrieval, search, and metadata write-back.
 *
 * @package MediagraphPicker
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Mediagraph API client class
 */
class Mediagraph_API {

    /**
     * API base URL
     *
     * @var string
     */
    private $api_base_url;

    /**
     * Access token
     *
     * @var string
     */
    private $access_token;

    /**
     * Organization ID
     *
     * @var string
     */
    private $organization_id;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_base_url = get_option( 'mediagraph_api_base_url', 'https://mediagraph.io' );
        $this->access_token = get_option( 'mediagraph_access_token', '' );
        $this->organization_id = get_option( 'mediagraph_organization_id', '' );
    }

    /**
     * Make API request
     *
     * @param string $endpoint API endpoint
     * @param array  $args     Request arguments
     * @param string $method   HTTP method (GET, POST, PUT, DELETE)
     * @return array|WP_Error Response data or error
     */
    private function make_request( $endpoint, $args = array(), $method = 'GET' ) {
        // Build URL
        $url = trailingslashit( $this->api_base_url ) . ltrim( $endpoint, '/' );

        // Default headers
        $headers = array(
            'Authorization' => 'Bearer ' . $this->access_token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        );

        // Add OrganizationId header
        if ( ! empty( $this->organization_id ) ) {
            $headers['OrganizationId'] = $this->organization_id;
        }

        // Build request arguments
        $request_args = array(
            'method'  => $method,
            'headers' => $headers,
            'timeout' => 30,
        );

        // Add body for POST/PUT requests
        if ( in_array( $method, array( 'POST', 'PUT' ), true ) && ! empty( $args ) ) {
            $request_args['body'] = wp_json_encode( $args );
        }

        // Add query parameters for GET requests
        if ( 'GET' === $method && ! empty( $args ) ) {
            $url = add_query_arg( $args, $url );
        }

        // Make request
        $response = wp_remote_request( $url, $request_args );

        // Handle errors
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        // Get response code
        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        // Parse JSON response
        $data = json_decode( $response_body, true );

        // Handle error responses
        if ( $response_code >= 400 ) {
            $error_message = isset( $data['error'] ) ? $data['error'] : __( 'API request failed', 'mediagraph-picker' );
            return new WP_Error( 'mediagraph_api_error', $error_message, array( 'status' => $response_code ) );
        }

        return $data;
    }

    /**
     * Test API connection
     *
     * @return array|WP_Error User info or error
     */
    public function test_connection() {
        $result = $this->make_request( 'api/whoami' );

        // Debug: Log the raw response
        if ( ! is_wp_error( $result ) ) {
            error_log( 'Mediagraph /api/whoami response: ' . wp_json_encode( $result, JSON_PRETTY_PRINT ) );
        } else {
            error_log( 'Mediagraph /api/whoami error: ' . $result->get_error_message() );
        }

        return $result;
    }

    /**
     * Get asset groups (Collections, Storage Folders, Lightboxes)
     *
     * @param array $args Query arguments (including 'parent_id' and 'parent_type' for loading children)
     * @return array|WP_Error Asset groups or error
     */
    public function get_asset_groups( $args = array() ) {
        // Check cache first
        $cache_key = 'mediagraph_asset_groups_' . md5( wp_json_encode( $args ) );
        $cached = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        // Build query params
        $query_params = array();
        if ( ! empty( $args['parent_id'] ) ) {
            $query_params['parent_id'] = $args['parent_id'];
        }

        // Add sub_type for lightbox folder navigation
        if ( ! empty( $args['sub_type'] ) ) {
            $query_params['sub_type'] = $args['sub_type'];
        }

        // If loading children of a specific parent, only fetch that type
        if ( ! empty( $args['parent_id'] ) && ! empty( $args['parent_type'] ) ) {
            $parent_type = $args['parent_type'];

            if ( $parent_type === 'Collection' ) {
                $items = $this->make_request( 'api/collections/tree', $query_params );
                if ( is_wp_error( $items ) ) {
                    return $items;
                }
                return array(
                    'collections' => is_array( $items ) ? $items : array(),
                    'folders'     => array(),
                    'lightboxes'  => array(),
                );
            } elseif ( $parent_type === 'StorageFolder' ) {
                $items = $this->make_request( 'api/storage_folders/tree', $query_params );
                if ( is_wp_error( $items ) ) {
                    return $items;
                }
                return array(
                    'collections' => array(),
                    'folders'     => is_array( $items ) ? $items : array(),
                    'lightboxes'  => array(),
                );
            } elseif ( $parent_type === 'Lightbox' ) {
                $items = $this->make_request( 'api/lightboxes/tree', $query_params );
                if ( is_wp_error( $items ) ) {
                    return $items;
                }
                return array(
                    'collections' => array(),
                    'folders'     => array(),
                    'lightboxes'  => is_array( $items ) ? $items : array(),
                );
            }
        }

        // Fetch all three types of asset groups (root level)
        $collections = $this->make_request( 'api/collections/tree', $query_params );
        $folders = $this->make_request( 'api/storage_folders/tree', $query_params );
        $lightboxes = $this->make_request( 'api/lightboxes/tree', $query_params );

        // Check for errors
        if ( is_wp_error( $collections ) ) {
            return $collections;
        }
        if ( is_wp_error( $folders ) ) {
            return $folders;
        }
        if ( is_wp_error( $lightboxes ) ) {
            return $lightboxes;
        }

        // Rails API returns arrays directly
        $result = array(
            'collections' => is_array( $collections ) ? $collections : array(),
            'folders'     => is_array( $folders ) ? $folders : array(),
            'lightboxes'  => is_array( $lightboxes ) ? $lightboxes : array(),
        );

        // Only cache root level (no parent_id) for 5 minutes
        if ( empty( $args['parent_id'] ) ) {
            set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        }

        return $result;
    }

    /**
     * Build hierarchical tree from flat array with ancestry
     *
     * @param array $items Flat array of items with ancestry
     * @return array Hierarchical tree
     */
    private function build_tree( $items ) {
        if ( ! is_array( $items ) ) {
            return array();
        }

        $tree = array();
        $lookup = array();

        // Index items by ID
        foreach ( $items as $item ) {
            $item['children'] = array();
            $lookup[ $item['id'] ] = $item;
        }

        // Build tree
        foreach ( $lookup as $id => $item ) {
            if ( empty( $item['parent_id'] ) ) {
                // Root level item
                $tree[] = &$lookup[ $id ];
            } else {
                // Child item
                $parent_id = $item['parent_id'];
                if ( isset( $lookup[ $parent_id ] ) ) {
                    $lookup[ $parent_id ]['children'][] = &$lookup[ $id ];
                }
            }
        }

        return $tree;
    }

    /**
     * Search assets
     *
     * @param array $params Search parameters
     * @return array|WP_Error Search results or error
     */
    public function search_assets( $params = array() ) {
        $default_params = array(
            'q'                    => '',
            'asset_group_id'       => null,
            'asset_group_type'     => null, // 'Collection', 'StorageFolder', or 'Lightbox'
            'asset_group_sub_type' => null, // 'folder' for bins (Lightbox sub-folders)
            'sort'                 => 'created_at',
            'order'                => null,
            'page'                 => 1,
            'per_page'             => 50,
        );

        $params = wp_parse_args( $params, $default_params );

        // Build query parameters
        $query_params = array(
            'page'     => $params['page'],
            'per_page' => $params['per_page'],
        );

        // Add search query
        if ( ! empty( $params['q'] ) ) {
            $query_params['q'] = $params['q'];
        }

        // Add asset group filter with correct parameter name based on type
        if ( ! empty( $params['asset_group_id'] ) && ! empty( $params['asset_group_type'] ) ) {
            $type = $params['asset_group_type'];
            $sub_type = isset( $params['asset_group_sub_type'] ) ? $params['asset_group_sub_type'] : null;

            // Map to correct Rails parameter name
            if ( $type === 'Collection' ) {
                $query_params['collection_id'] = $params['asset_group_id'];
            } elseif ( $type === 'StorageFolder' ) {
                $query_params['storage_folder_id'] = $params['asset_group_id'];
            } elseif ( $type === 'Lightbox' ) {
                // Bins (Lightbox sub-folders) use lightbox_folder_id
                if ( $sub_type === 'folder' ) {
                    $query_params['lightbox_folder_id'] = $params['asset_group_id'];
                } else {
                    $query_params['lightbox_id'] = $params['asset_group_id'];
                }
            }
        }

        // Add sort parameter
        if ( ! empty( $params['sort'] ) ) {
            $query_params['sort'] = $params['sort'];
            // Use provided order, or default based on sort field
            if ( ! empty( $params['order'] ) ) {
                $query_params['order'] = $params['order'];
            } else {
                // Filename should sort ascending (A-Z), others descending (newest first)
                $query_params['order'] = ( $params['sort'] === 'filename' ) ? 'asc' : 'desc';
            }
        }

        // Show all files or only downloadable
        if ( ! empty( $params['show_all'] ) ) {
            $query_params['show_all'] = 1;
        }

        return $this->make_request( 'api/assets/search', $query_params );
    }

    /**
     * Get single asset details
     *
     * @param string $asset_id Asset ID
     * @return array|WP_Error Asset data or error
     */
    public function get_asset( $asset_id ) {
        if ( empty( $asset_id ) ) {
            return new WP_Error( 'invalid_asset_id', __( 'Asset ID is required', 'mediagraph-picker' ) );
        }

        return $this->make_request( "api/assets/{$asset_id}" );
    }

    /**
     * Get download URL for asset
     *
     * Uses the appropriate preview URL based on requested size:
     * - thumbnail: thumb_url (100px)
     * - small/medium: small_url (640px)
     * - large/full: permalink_url (1200px) or original
     *
     * @param string $asset_id Asset ID
     * @param string $size     Size variant (original, large, medium, thumbnail)
     * @return string|WP_Error Download URL or error
     */
    public function get_download_url( $asset_id, $size = 'original' ) {
        if ( empty( $asset_id ) ) {
            return new WP_Error( 'invalid_asset_id', __( 'Asset ID is required', 'mediagraph-picker' ) );
        }

        // Get asset details which include preview URLs
        $asset = $this->get_asset( $asset_id );

        if ( is_wp_error( $asset ) ) {
            return $asset;
        }

        // Map size to appropriate URL field from Rails API
        // Rails provides: thumb_url (100px), grid_url (300px), small_url (640px), permalink_url (1200px)
        $url = null;

        switch ( $size ) {
            case 'thumbnail':
                // Use thumb_url for thumbnails (100px)
                $url = isset( $asset['thumb_url'] ) ? $asset['thumb_url'] : null;
                if ( ! $url && isset( $asset['grid_url'] ) ) {
                    $url = $asset['grid_url'];
                }
                break;

            case 'small':
            case 'medium':
                // Use small_url for small/medium (640px)
                $url = isset( $asset['small_url'] ) ? $asset['small_url'] : null;
                if ( ! $url && isset( $asset['permalink_url'] ) ) {
                    $url = $asset['permalink_url'];
                }
                break;

            case 'large':
                // Use permalink_url for large (1200px)
                $url = isset( $asset['permalink_url'] ) ? $asset['permalink_url'] : null;
                break;

            case 'full':
            case 'original':
            default:
                // Use permalink_url for full size (these are ActiveStorage URLs, not actual originals)
                $url = isset( $asset['permalink_url'] ) ? $asset['permalink_url'] : null;
                break;
        }

        // Fallback chain if specific URL not available
        if ( ! $url ) {
            $url = isset( $asset['permalink_url'] ) ? $asset['permalink_url'] :
                   ( isset( $asset['small_url'] ) ? $asset['small_url'] :
                   ( isset( $asset['thumb_url'] ) ? $asset['thumb_url'] :
                   ( isset( $asset['grid_url'] ) ? $asset['grid_url'] : null ) ) );
        }

        if ( ! $url ) {
            return new WP_Error( 'no_download_url', __( 'No preview URL available for this asset', 'mediagraph-picker' ) );
        }

        return $url;
    }

    /**
     * Send publish metadata to Mediagraph
     *
     * @param array $metadata Metadata payload
     * @return array|WP_Error Response or error
     */
    public function send_publish_metadata( $metadata ) {
        if ( empty( $metadata ) ) {
            return new WP_Error( 'empty_metadata', __( 'Metadata is required', 'mediagraph-picker' ) );
        }

        return $this->make_request( 'api/published_assets', $metadata, 'POST' );
    }

    /**
     * Get asset metadata fields
     *
     * @param string $asset_id Asset ID
     * @return array|WP_Error Metadata or error
     */
    public function get_asset_metadata( $asset_id ) {
        $asset = $this->get_asset( $asset_id );

        if ( is_wp_error( $asset ) ) {
            return $asset;
        }

        // Extract IPTC metadata fields
        $metadata = array(
            'filename'            => isset( $asset['filename'] ) ? $asset['filename'] : '',
            'title'               => isset( $asset['title'] ) ? $asset['title'] : '',
            'description'         => isset( $asset['description'] ) ? $asset['description'] : '',
            'caption'             => isset( $asset['caption'] ) ? $asset['caption'] : '',
            'alt_text'            => isset( $asset['alt_text'] ) ? $asset['alt_text'] : '',
            'creator'             => isset( $asset['creator'] ) ? $asset['creator'] : '',
            'byline'              => isset( $asset['byline'] ) ? $asset['byline'] : '',
            'credit'              => isset( $asset['credit'] ) ? $asset['credit'] : '',
            'copyright'           => isset( $asset['copyright'] ) ? $asset['copyright'] : '',
            'keywords'            => isset( $asset['keywords'] ) ? $asset['keywords'] : array(),
            'usage_rights'        => isset( $asset['usage_rights'] ) ? $asset['usage_rights'] : '',
            'date_created'        => isset( $asset['date_created'] ) ? $asset['date_created'] : '',
            'date_uploaded'       => isset( $asset['created_at'] ) ? $asset['created_at'] : '',
            'file_size'           => isset( $asset['file_size'] ) ? $asset['file_size'] : 0,
            'mime_type'           => isset( $asset['mime_type'] ) ? $asset['mime_type'] : '',
            'dimensions'          => isset( $asset['dimensions'] ) ? $asset['dimensions'] : array(),
            'duration'            => isset( $asset['duration'] ) ? $asset['duration'] : null,
            'downloadable'        => isset( $asset['downloadable'] ) ? $asset['downloadable'] : false,
            'download_url'        => isset( $asset['download_url'] ) ? $asset['download_url'] : '',
            'thumbnail_url'       => isset( $asset['thumbnail_url'] ) ? $asset['thumbnail_url'] : '',
            'preview_url'         => isset( $asset['preview_url'] ) ? $asset['preview_url'] : '',
        );

        return $metadata;
    }

    /**
     * Download asset to WordPress media library
     *
     * @param string $asset_id Asset ID
     * @param array  $metadata Optional metadata
     * @param int    $post_id  Optional post ID to associate attachment with
     * @return int|WP_Error Attachment ID or error
     */
    public function download_to_media_library( $asset_id, $metadata = array(), $post_id = 0 ) {
        // Get asset details to get the proper filename
        $asset = $this->get_asset( $asset_id );

        if ( is_wp_error( $asset ) ) {
            return $asset;
        }

        // Get filename from asset details
        $filename = isset( $asset['filename'] ) ? $asset['filename'] : '';
        if ( empty( $filename ) && isset( $metadata['filename'] ) ) {
            $filename = $metadata['filename'];
        }

        if ( empty( $filename ) ) {
            return new WP_Error( 'no_filename', __( 'Could not determine filename for asset', 'mediagraph-picker' ) );
        }

        // Get download URL
        $download_url = $this->get_download_url( $asset_id );

        if ( is_wp_error( $download_url ) ) {
            return $download_url;
        }

        // Download file
        $temp_file = download_url( $download_url );

        if ( is_wp_error( $temp_file ) ) {
            return $temp_file;
        }

        // Get mime type from filename or asset
        $mime_type = null;
        if ( isset( $asset['mime_type'] ) ) {
            $mime_type = $asset['mime_type'];
        } else {
            // Try to determine from file extension
            $wp_filetype = wp_check_filetype( $filename );
            if ( $wp_filetype['type'] ) {
                $mime_type = $wp_filetype['type'];
            }
        }

        // Prepare file array for upload with correct filename
        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $temp_file,
            'type'     => $mime_type,
        );

        // Sideload into media library and associate with post
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        $attachment_id = media_handle_sideload( $file_array, $post_id, null, array(
            'post_title'   => isset( $metadata['title'] ) ? $metadata['title'] : '',
            'post_content' => isset( $metadata['description'] ) ? $metadata['description'] : '',
            'post_excerpt' => isset( $metadata['caption'] ) ? $metadata['caption'] : '',
        ));

        // Clean up temp file
        if ( file_exists( $temp_file ) ) {
            @unlink( $temp_file );
        }

        if ( is_wp_error( $attachment_id ) ) {
            return $attachment_id;
        }

        // Update attachment metadata
        // Alt text
        if ( ! empty( $metadata['alt_text'] ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $metadata['alt_text'] );
        }

        // Store Mediagraph asset ID
        update_post_meta( $attachment_id, '_mediagraph_asset_id', $asset_id );

        // Store Mediagraph GUID
        if ( ! empty( $asset['guid'] ) ) {
            update_post_meta( $attachment_id, '_mediagraph_guid', $asset['guid'] );
        }

        // Store additional metadata
        if ( ! empty( $metadata ) ) {
            update_post_meta( $attachment_id, '_mediagraph_metadata', $metadata );
        }

        return $attachment_id;
    }

    /**
     * Refresh access token
     *
     * @return bool Success
     */
    public function refresh_access_token() {
        $oauth = new Mediagraph_OAuth();
        return $oauth->refresh_token();
    }
}
