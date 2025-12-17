<?php
/**
 * Plugin Name: Mediagraph File Picker
 * Plugin URI: https://www.mediagraph.io/wordpress-plugin
 * Description: Integrates Mediagraph's media asset management system into WordPress media library. Browse, search, and insert assets from Mediagraph Collections, Storage Folders, and Lightboxes.
 * Version: 1.1.0
 * Author: Mediagraph
 * Author URI: https://www.mediagraph.io
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: mediagraph-picker
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package MediagraphPicker
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'MEDIAGRAPH_PICKER_VERSION', '1.1.0' );
define( 'MEDIAGRAPH_PICKER_PLUGIN_FILE', __FILE__ );
define( 'MEDIAGRAPH_PICKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIAGRAPH_PICKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEDIAGRAPH_PICKER_INCLUDES_DIR', MEDIAGRAPH_PICKER_PLUGIN_DIR . 'includes/' );
define( 'MEDIAGRAPH_PICKER_ADMIN_DIR', MEDIAGRAPH_PICKER_PLUGIN_DIR . 'admin/' );

/**
 * Main plugin class
 */
class Mediagraph_Picker {

    /**
     * Single instance of the class
     *
     * @var Mediagraph_Picker
     */
    private static $instance = null;

    /**
     * API client instance
     *
     * @var Mediagraph_API
     */
    public $api = null;

    /**
     * OAuth handler instance
     *
     * @var Mediagraph_OAuth
     */
    public $oauth = null;

    /**
     * Settings instance
     *
     * @var Mediagraph_Settings
     */
    public $settings = null;

    /**
     * Get single instance of the class
     *
     * @return Mediagraph_Picker
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize the plugin
     */
    private function __construct() {
        // Load dependencies
        $this->load_dependencies();

        // Initialize components
        $this->api = new Mediagraph_API();
        $this->oauth = new Mediagraph_OAuth();
        $this->settings = new Mediagraph_Settings();

        // Register hooks
        $this->register_hooks();
    }

    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core classes
        require_once MEDIAGRAPH_PICKER_INCLUDES_DIR . 'class-mediagraph-api.php';
        require_once MEDIAGRAPH_PICKER_INCLUDES_DIR . 'class-mediagraph-oauth.php';
        require_once MEDIAGRAPH_PICKER_INCLUDES_DIR . 'class-mediagraph-settings.php';
        require_once MEDIAGRAPH_PICKER_INCLUDES_DIR . 'class-mediagraph-metadata-mapper.php';
        require_once MEDIAGRAPH_PICKER_INCLUDES_DIR . 'class-mediagraph-wordpress-mapper.php';
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks() {
        // Activation and deactivation hooks
        register_activation_hook( MEDIAGRAPH_PICKER_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( MEDIAGRAPH_PICKER_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Admin hooks
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'media_buttons', array( $this, 'add_media_button' ), 15 );
        add_action( 'admin_footer', array( $this, 'render_picker_modal' ) );

        // Gutenberg block support
        add_action( 'init', array( $this, 'register_gutenberg_block' ) );
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );

        // AJAX handlers
        add_action( 'wp_ajax_mediagraph_get_asset_groups', array( $this, 'ajax_get_asset_groups' ) );
        add_action( 'wp_ajax_mediagraph_get_asset_group_children', array( $this, 'ajax_get_asset_group_children' ) );
        add_action( 'wp_ajax_mediagraph_search_assets', array( $this, 'ajax_search_assets' ) );
        add_action( 'wp_ajax_mediagraph_get_asset', array( $this, 'ajax_get_asset' ) );
        add_action( 'wp_ajax_mediagraph_get_download_url', array( $this, 'ajax_get_download_url' ) );
        add_action( 'wp_ajax_mediagraph_download_asset', array( $this, 'ajax_download_asset' ) );
        add_action( 'wp_ajax_mediagraph_save_assets', array( $this, 'ajax_save_assets' ) );

        // Post publish hook for write-back
        add_action( 'publish_post', array( $this, 'handle_post_publish' ), 10, 2 );
        add_action( 'publish_page', array( $this, 'handle_post_publish' ), 10, 2 );

        // Add Mediagraph fields to attachment details
        add_filter( 'attachment_fields_to_edit', array( $this, 'add_mediagraph_fields_to_attachment' ), 10, 2 );

        // Internationalization
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create custom database tables if needed
        $this->create_tables();

        // Set default options
        add_option( 'mediagraph_picker_version', MEDIAGRAPH_PICKER_VERSION );
        add_option( 'mediagraph_picker_activated', current_time( 'mysql' ) );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up temporary data
        delete_transient( 'mediagraph_asset_groups' );
        delete_transient( 'mediagraph_api_status' );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create custom database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'mediagraph_published_assets';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            asset_id varchar(255) NOT NULL,
            asset_guid varchar(255) NOT NULL,
            usage_type varchar(50) DEFAULT 'body_photo',
            published_at datetime DEFAULT CURRENT_TIMESTAMP,
            metadata text,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY asset_id (asset_id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_scripts( $hook ) {
        // Only load on post editor pages
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }

        // Check if user is connected to Mediagraph
        $is_connected = $this->oauth->is_connected();

        // Enqueue React picker bundle
        wp_enqueue_script(
            'mediagraph-picker',
            MEDIAGRAPH_PICKER_PLUGIN_URL . 'admin/js/dist/mediagraph-picker.bundle.js',
            array( 'jquery', 'wp-element' ),
            MEDIAGRAPH_PICKER_VERSION,
            true
        );

        // Enqueue save handler (for metadata write-back)
        wp_enqueue_script(
            'mediagraph-save-handler',
            MEDIAGRAPH_PICKER_PLUGIN_URL . 'admin/js/mediagraph-save-handler.js',
            array( 'jquery', 'mediagraph-picker' ),
            MEDIAGRAPH_PICKER_VERSION,
            true
        );

        // Enqueue styles
        wp_enqueue_style(
            'mediagraph-picker',
            MEDIAGRAPH_PICKER_PLUGIN_URL . 'admin/css/mediagraph-picker.css',
            array(),
            MEDIAGRAPH_PICKER_VERSION
        );

        // Localize script with settings
        wp_localize_script(
            'mediagraph-picker',
            'mediagraphPicker',
            array(
                'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
                'nonce'            => wp_create_nonce( 'mediagraph_picker_nonce' ),
                'isConnected'      => $is_connected,
                'organizationId'   => get_option( 'mediagraph_organization_id', '' ),
                'organizationName' => get_option( 'mediagraph_organization_name', '' ),
                'apiBaseUrl'       => get_option( 'mediagraph_api_base_url', 'https://mediagraph.io' ),
                'platform'         => get_option( 'mediagraph_platform', 'wordpress' ),
                'strings'          => array(
                    'notConnected' => __( 'Please connect to Mediagraph in Settings > Mediagraph', 'mediagraph-picker' ),
                    'loading'      => __( 'Loading...', 'mediagraph-picker' ),
                    'error'        => __( 'An error occurred. Please try again.', 'mediagraph-picker' ),
                    'noAssets'     => __( 'No assets found.', 'mediagraph-picker' ),
                ),
            )
        );
    }

    /**
     * Add admin menu item
     */
    public function add_admin_menu() {
        add_options_page(
            __( 'Mediagraph Settings', 'mediagraph-picker' ),
            __( 'Mediagraph', 'mediagraph-picker' ),
            'manage_options',
            'mediagraph-settings',
            array( $this->settings, 'render_settings_page' )
        );

        // Register hidden OAuth callback page
        // Using null as parent hides it from the admin menu
        // The actual callback handling is done in Mediagraph_OAuth::handle_oauth_callback()
        add_submenu_page(
            null, // null parent = hidden page
            __( 'Mediagraph OAuth Callback', 'mediagraph-picker' ),
            __( 'Mediagraph OAuth Callback', 'mediagraph-picker' ),
            'read', // Any logged-in user can access this page (OAuth state verification provides security)
            'mediagraph-callback',
            array( $this->oauth, 'render_callback_page' )
        );
    }

    /**
     * Add Mediagraph button to media buttons
     *
     * @param string $editor_id Editor ID
     */
    public function add_media_button( $editor_id ) {
        if ( ! $this->oauth->is_connected() ) {
            return;
        }

        printf(
            '<button type="button" id="mediagraph-picker-button" class="button" data-editor="%s">
                <span class="dashicons dashicons-format-image" style="vertical-align: middle;"></span>
                %s
            </button>',
            esc_attr( $editor_id ),
            esc_html__( 'Add from Mediagraph', 'mediagraph-picker' )
        );
    }

    /**
     * Render picker modal in admin footer
     */
    public function render_picker_modal() {
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
            return;
        }

        if ( ! $this->oauth->is_connected() ) {
            return;
        }

        echo '<div id="mediagraph-picker-modal-root"></div>';
    }

    /**
     * AJAX: Get asset groups (Collections, Folders, Lightboxes)
     */
    public function ajax_get_asset_groups() {
        check_ajax_referer( 'mediagraph_picker_nonce', 'nonce' );

        $asset_groups = $this->api->get_asset_groups();

        if ( is_wp_error( $asset_groups ) ) {
            wp_send_json_error( array( 'message' => $asset_groups->get_error_message() ) );
        }

        wp_send_json_success( $asset_groups );
    }

    /**
     * AJAX: Get asset group children (for lazy loading tree nodes)
     */
    public function ajax_get_asset_group_children() {
        check_ajax_referer( 'mediagraph_picker_nonce', 'nonce' );

        $parent_id = isset( $_POST['parent_id'] ) ? intval( $_POST['parent_id'] ) : null;
        $parent_type = isset( $_POST['parent_type'] ) ? sanitize_text_field( wp_unslash( $_POST['parent_type'] ) ) : null;
        $sub_type = isset( $_POST['sub_type'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_type'] ) ) : null;

        if ( empty( $parent_id ) || empty( $parent_type ) ) {
            wp_send_json_error( array( 'message' => __( 'Parent ID and type are required', 'mediagraph-picker' ) ) );
        }

        $args = array(
            'parent_id'   => $parent_id,
            'parent_type' => $parent_type,
        );

        // Add sub_type for lightbox folder navigation
        if ( ! empty( $sub_type ) ) {
            $args['sub_type'] = $sub_type;
        }

        $children = $this->api->get_asset_groups( $args );

        if ( is_wp_error( $children ) ) {
            wp_send_json_error( array( 'message' => $children->get_error_message() ) );
        }

        wp_send_json_success( $children );
    }

    /**
     * AJAX: Search assets
     */
    public function ajax_search_assets() {
        check_ajax_referer( 'mediagraph_picker_nonce', 'nonce' );

        $params = array(
            'q'                    => isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '',
            'asset_group_id'       => isset( $_POST['asset_group_id'] ) ? intval( $_POST['asset_group_id'] ) : null,
            'asset_group_type'     => isset( $_POST['asset_group_type'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_group_type'] ) ) : null,
            'asset_group_sub_type' => isset( $_POST['asset_group_sub_type'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_group_sub_type'] ) ) : null,
            'sort'                 => isset( $_POST['sort'] ) ? sanitize_text_field( wp_unslash( $_POST['sort'] ) ) : 'created_at',
            'order'                => isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : null,
            'show_all'             => isset( $_POST['show_all'] ) ? filter_var( wp_unslash( $_POST['show_all'] ), FILTER_VALIDATE_BOOLEAN ) : false,
            'page'                 => isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1,
            'per_page'             => isset( $_POST['per_page'] ) ? intval( $_POST['per_page'] ) : 50,
        );

        $result = $this->api->search_assets( $params );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        // Normalize Rails API response for React component
        // Rails returns 'total_entries', React expects 'total'
        if ( isset( $result['total_entries'] ) && ! isset( $result['total'] ) ) {
            $result['total'] = $result['total_entries'];
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Get single asset details
     */
    public function ajax_get_asset() {
        check_ajax_referer( 'mediagraph_picker_nonce', 'nonce' );

        $asset_id = isset( $_POST['asset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_id'] ) ) : '';

        if ( empty( $asset_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Asset ID is required', 'mediagraph-picker' ) ) );
        }

        $asset = $this->api->get_asset( $asset_id );

        if ( is_wp_error( $asset ) ) {
            wp_send_json_error( array( 'message' => $asset->get_error_message() ) );
        }

        wp_send_json_success( $asset );
    }

    /**
     * AJAX: Get download URL for asset
     */
    public function ajax_get_download_url() {
        check_ajax_referer( 'mediagraph_picker_nonce', 'nonce' );

        $asset_id = isset( $_POST['asset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_id'] ) ) : '';
        $size = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : 'original';

        if ( empty( $asset_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Asset ID is required', 'mediagraph-picker' ) ) );
        }

        $download_url = $this->api->get_download_url( $asset_id, $size );

        if ( is_wp_error( $download_url ) ) {
            wp_send_json_error( array( 'message' => $download_url->get_error_message() ) );
        }

        wp_send_json_success( array( 'url' => $download_url ) );
    }

    /**
     * AJAX: Download asset to WordPress media library
     */
    public function ajax_download_asset() {
        check_ajax_referer( 'mediagraph_picker_nonce', 'nonce' );

        $asset_id = isset( $_POST['asset_id'] ) ? sanitize_text_field( wp_unslash( $_POST['asset_id'] ) ) : '';
        $size = isset( $_POST['size'] ) ? sanitize_text_field( wp_unslash( $_POST['size'] ) ) : 'original';
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $metadata = isset( $_POST['metadata'] ) ? json_decode( wp_unslash( $_POST['metadata'] ), true ) : array();

        if ( empty( $asset_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Asset ID is required', 'mediagraph-picker' ) ) );
        }

        // Download asset to media library and associate with post
        $attachment_id = $this->api->download_to_media_library( $asset_id, $metadata, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ) );
        }

        // Get WordPress attachment URL
        $attachment_url = wp_get_attachment_url( $attachment_id );

        if ( ! $attachment_url ) {
            wp_send_json_error( array( 'message' => __( 'Failed to get attachment URL', 'mediagraph-picker' ) ) );
        }

        wp_send_json_success( array(
            'url' => $attachment_url,
            'attachment_id' => $attachment_id
        ) );
    }

    /**
     * AJAX: Save Mediagraph assets to post meta
     */
    public function ajax_save_assets() {
        check_ajax_referer( 'mediagraph_picker_nonce', 'nonce' );

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $assets_json = isset( $_POST['assets'] ) ? wp_unslash( $_POST['assets'] ) : '';

        if ( empty( $post_id ) || empty( $assets_json ) ) {
            wp_send_json_error( array( 'message' => __( 'Post ID and assets are required', 'mediagraph-picker' ) ) );
        }

        // Check permissions
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to edit this post', 'mediagraph-picker' ) ) );
        }

        // Decode JSON
        $assets = json_decode( $assets_json, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            wp_send_json_error( array( 'message' => __( 'Invalid JSON data', 'mediagraph-picker' ) ) );
        }

        // Save to post meta
        update_post_meta( $post_id, '_mediagraph_assets', $assets );

        wp_send_json_success( array( 'message' => __( 'Assets saved successfully', 'mediagraph-picker' ) ) );
    }

    /**
     * Handle post publish - send metadata to Mediagraph
     *
     * @param int     $post_id Post ID
     * @param WP_Post $post    Post object
     */
    public function handle_post_publish( $post_id, $post ) {
        // Skip if not connected
        if ( ! $this->oauth->is_connected() ) {
            return;
        }

        // Skip autosaves and revisions
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }

        // Get Mediagraph assets used in this post
        $mediagraph_assets = $this->get_post_mediagraph_assets( $post_id );

        if ( empty( $mediagraph_assets ) ) {
            return;
        }

        // Get appropriate metadata mapper
        $platform = get_option( 'mediagraph_platform', 'wordpress' );
        $mapper = $this->get_metadata_mapper( $platform );

        // Build metadata payload
        $metadata = $mapper->build_publish_payload( $post, $mediagraph_assets );

        // Send to Mediagraph
        $result = $this->api->send_publish_metadata( $metadata );

        if ( is_wp_error( $result ) ) {
            // Log error but don't block publish
            error_log( 'Mediagraph write-back failed: ' . $result->get_error_message() );
            return;
        }

        // Store published asset records
        $this->store_published_assets( $post_id, $mediagraph_assets );

        // Show admin notice
        add_action( 'admin_notices', function() {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html__( 'Mediagraph metadata updated successfully.', 'mediagraph-picker' )
            );
        });
    }

    /**
     * Get Mediagraph assets used in post content
     *
     * @param int $post_id Post ID
     * @return array Asset IDs and metadata
     */
    private function get_post_mediagraph_assets( $post_id ) {
        $assets = array();
        $post_meta = get_post_meta( $post_id, '_mediagraph_assets', true );

        if ( ! empty( $post_meta ) && is_array( $post_meta ) ) {
            $assets = $post_meta;
        }

        return $assets;
    }

    /**
     * Get metadata mapper for platform
     *
     * @param string $platform Platform name
     * @return Mediagraph_Metadata_Mapper
     */
    private function get_metadata_mapper( $platform ) {
        switch ( $platform ) {
            case 'newspack':
                // Future: return new Mediagraph_Newspack_Mapper();
            case 'blox':
                // Future: return new Mediagraph_Blox_Mapper();
            default:
                return new Mediagraph_WordPress_Mapper();
        }
    }

    /**
     * Store published asset records in database
     *
     * @param int   $post_id Post ID
     * @param array $assets  Assets array
     */
    private function store_published_assets( $post_id, $assets ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'mediagraph_published_assets';

        foreach ( $assets as $asset ) {
            $wpdb->insert(
                $table_name,
                array(
                    'post_id'      => $post_id,
                    'asset_id'     => $asset['id'],
                    'asset_guid'   => isset( $asset['guid'] ) ? $asset['guid'] : '',
                    'usage_type'   => isset( $asset['usage_type'] ) ? $asset['usage_type'] : 'body_photo',
                    'published_at' => current_time( 'mysql' ),
                    'metadata'     => wp_json_encode( $asset ),
                ),
                array( '%d', '%s', '%s', '%s', '%s', '%s' )
            );
        }
    }

    /**
     * Register Gutenberg block
     */
    public function register_gutenberg_block() {
        // Register the block
        register_block_type( 'mediagraph/asset-picker', array(
            'editor_script'   => 'mediagraph-gutenberg-block',
            'render_callback' => array( $this, 'render_gutenberg_block' ),
        ));
    }

    /**
     * Render Gutenberg block on frontend
     */
    public function render_gutenberg_block( $attributes ) {
        if ( isset( $attributes['assetHtml'] ) ) {
            return $attributes['assetHtml'];
        }
        return '';
    }

    /**
     * Enqueue Gutenberg block editor assets
     */
    public function enqueue_block_editor_assets() {
        if ( ! $this->oauth->is_connected() ) {
            return;
        }

        // Enqueue the block JavaScript
        wp_enqueue_script(
            'mediagraph-gutenberg-block',
            MEDIAGRAPH_PICKER_PLUGIN_URL . 'admin/js/gutenberg-block.js',
            array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ),
            MEDIAGRAPH_PICKER_VERSION,
            true
        );

        // Also enqueue the main picker assets (modal, CSS, etc.)
        $this->enqueue_admin_scripts( 'post.php' );
    }

    /**
     * Add Mediagraph fields to attachment edit screen
     *
     * @param array   $form_fields Array of form fields
     * @param WP_Post $post        Attachment post object
     * @return array Modified form fields
     */
    public function add_mediagraph_fields_to_attachment( $form_fields, $post ) {
        // Get Mediagraph GUID
        $asset_guid = get_post_meta( $post->ID, '_mediagraph_guid', true );

        // Only show if this is a Mediagraph asset
        if ( ! empty( $asset_guid ) ) {
            $form_fields['mediagraph_guid'] = array(
                'label' => __( 'Mediagraph GUID', 'mediagraph-picker' ),
                'input' => 'html',
                'html'  => '<input type="text" class="text" readonly="readonly" value="' . esc_attr( $asset_guid ) . '" />',
                'helps' => __( 'The globally unique identifier for this asset', 'mediagraph-picker' ),
            );
        }

        return $form_fields;
    }

    /**
     * Load plugin text domain for internationalization
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'mediagraph-picker',
            false,
            dirname( plugin_basename( MEDIAGRAPH_PICKER_PLUGIN_FILE ) ) . '/languages'
        );
    }
}

/**
 * Initialize the plugin
 */
function mediagraph_picker() {
    return Mediagraph_Picker::get_instance();
}

// Start the plugin
mediagraph_picker();
