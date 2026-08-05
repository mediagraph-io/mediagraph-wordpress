<?php
/**
 * Mediagraph API Client
 *
 * Handles all communication with the Mediagraph API including authentication,
 * asset retrieval, search, and metadata write-back.
 *
 * @package MediagraphAssets
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
            $error_message = isset( $data['error'] ) ? $data['error'] : __( 'API request failed', 'mediagraph-assets' );
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
            return new WP_Error( 'invalid_asset_id', __( 'Asset ID is required', 'mediagraph-assets' ) );
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
            return new WP_Error( 'invalid_asset_id', __( 'Asset ID is required', 'mediagraph-assets' ) );
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
                // Use full_url for actual full-size image (larger than permalink_url which is 1200px max)
                // Fall back to permalink_url if full_url is not available
                $url = isset( $asset['full_url'] ) ? $asset['full_url'] : null;
                if ( ! $url ) {
                    $url = isset( $asset['permalink_url'] ) ? $asset['permalink_url'] : null;
                }
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
            return new WP_Error( 'no_download_url', __( 'No preview URL available for this asset', 'mediagraph-assets' ) );
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
            return new WP_Error( 'empty_metadata', __( 'Metadata is required', 'mediagraph-assets' ) );
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
     * Build a WordPress sideload file array from the downloaded rendition.
     *
     * Preview renditions may not share the original asset's extension or MIME
     * type (for example, a TIFF or video thumbnail may be delivered as JPEG).
     * WordPress validates the filename against the file contents, so describe
     * the downloaded bytes rather than blindly reusing the original metadata.
     *
     * @param string $original_filename Original asset filename.
     * @param string $original_mime     Original asset MIME type.
     * @param string $download_url      Selected rendition URL.
     * @param string $temp_file         Downloaded temporary file path.
     * @return array Sideload-compatible file data.
     */
    private function build_sideload_file_data( $original_filename, $original_mime, $download_url, $temp_file ) {
        $filename = sanitize_file_name( $original_filename );
        $url_path = wp_parse_url( $download_url, PHP_URL_PATH );
        $url_extension = $url_path ? strtolower( pathinfo( rawurldecode( $url_path ), PATHINFO_EXTENSION ) ) : '';
        $url_extension = preg_replace( '/[^a-z0-9]+/', '', $url_extension );

        $current_filetype = wp_check_filetype( $filename );
        $url_filetype = $url_extension ? wp_check_filetype( 'rendition.' . $url_extension ) : array();
        $mime_type = '';

        if ( function_exists( 'wp_get_image_mime' ) ) {
            $mime_type = wp_get_image_mime( $temp_file );
        }

        if ( ! $mime_type && function_exists( 'mime_content_type' ) ) {
            $mime_type = mime_content_type( $temp_file );
            if ( 'application/octet-stream' === $mime_type ) {
                $mime_type = '';
            }
        }

        if ( ! $mime_type && ! empty( $url_filetype['type'] ) ) {
            $mime_type = $url_filetype['type'];
        }

        if ( ! $mime_type ) {
            $mime_type = $original_mime ? $original_mime : $current_filetype['type'];
        }

        // Keep the original extension when it already matches the bytes. When
        // it does not, prefer the rendition URL's extension and finally the
        // first WordPress-registered extension for the detected MIME type.
        $extension = '';
        if ( $mime_type && $current_filetype['type'] === $mime_type ) {
            $extension = pathinfo( $filename, PATHINFO_EXTENSION );
        } elseif ( $mime_type && ! empty( $url_filetype['type'] ) && $url_filetype['type'] === $mime_type ) {
            $extension = $url_extension;
        } elseif ( $mime_type ) {
            $extension = $this->get_extension_for_mime_type( $mime_type );
        } elseif ( $url_extension ) {
            $extension = $url_extension;
        }

        if ( $extension ) {
            $basename = pathinfo( $filename, PATHINFO_FILENAME );
            $filename = sanitize_file_name( $basename . '.' . $extension );
        }

        return array(
            'name'     => $filename,
            'tmp_name' => $temp_file,
            'type'     => $mime_type,
        );
    }

    /**
     * Return a WordPress-registered extension for a MIME type.
     *
     * @param string $mime_type MIME type.
     * @return string File extension, or an empty string when unknown.
     */
    private function get_extension_for_mime_type( $mime_type ) {
        foreach ( wp_get_mime_types() as $extensions => $registered_mime ) {
            if ( $registered_mime === $mime_type ) {
                return explode( '|', $extensions )[0];
            }
        }

        return '';
    }

    /**
     * Download asset to WordPress media library
     *
     * @param string $asset_id Asset ID
     * @param array  $metadata Optional metadata
     * @param int    $post_id  Optional post ID to associate attachment with
     * @param string $size     Size variant to download (original, large, medium, thumbnail)
     * @return int|WP_Error Attachment ID or error
     */
    public function download_to_media_library( $asset_id, $metadata = array(), $post_id = 0, $size = 'original' ) {
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
            return new WP_Error( 'no_filename', __( 'Could not determine filename for asset', 'mediagraph-assets' ) );
        }

        // Get download URL for the requested size variant
        $download_url = $this->get_download_url( $asset_id, $size );

        if ( is_wp_error( $download_url ) ) {
            return $download_url;
        }

        // Download file
        $temp_file = download_url( $download_url );

        if ( is_wp_error( $temp_file ) ) {
            return $temp_file;
        }

        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        $file_array = $this->build_sideload_file_data(
            $filename,
            isset( $asset['mime_type'] ) ? $asset['mime_type'] : '',
            $download_url,
            $temp_file
        );

        // Determine caption: prefer explicit caption, fall back to description
        $caption = '';
        if ( ! empty( $metadata['caption'] ) ) {
            $caption = $metadata['caption'];
        } elseif ( ! empty( $metadata['description'] ) ) {
            $caption = $metadata['description'];
        }

        $attachment_id = media_handle_sideload( $file_array, $post_id, null, array(
            'post_title'   => isset( $metadata['title'] ) ? $metadata['title'] : '',
            'post_content' => isset( $metadata['description'] ) ? $metadata['description'] : '',
            'post_excerpt' => $caption, // WordPress uses post_excerpt as the caption field
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
