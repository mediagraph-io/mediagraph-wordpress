<?php
/**
 * Mediagraph OAuth 2.0 Handler
 *
 * Handles OAuth 2.0 authentication flow with Mediagraph API.
 *
 * @package MediagraphAssets
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Mediagraph OAuth handler class
 */
class Mediagraph_OAuth {

    /**
     * Shared public OAuth client ID
     * This is a public client that uses PKCE (no secret required)
     *
     * @var string
     */
    const PUBLIC_CLIENT_ID = 'wordpress-plugin-public-client';

    /**
     * API base URL
     *
     * @var string
     */
    private $api_base_url;

    /**
     * OAuth authorize URL
     *
     * @var string
     */
    private $authorize_url;

    /**
     * OAuth token URL
     *
     * @var string
     */
    private $token_url;

    /**
     * Redirect URI
     *
     * @var string
     */
    private $redirect_uri;

    /**
     * Constructor
     */
    public function __construct() {
        // Use configurable API base URL, defaulting to production
        $this->api_base_url = get_option( 'mediagraph_api_base_url', 'https://mediagraph.io' );

        // Build OAuth URLs
        // For authorize URL (browser-facing), replace host.docker.internal with localhost
        // so the user's browser can access it
        $browser_api_url = str_replace( 'host.docker.internal', 'localhost', $this->api_base_url );
        $this->authorize_url = trailingslashit( $browser_api_url ) . 'oauth/authorize';

        // For token URL (server-to-server), use the original API URL
        // which might be host.docker.internal for Docker environments
        $this->token_url = trailingslashit( $this->api_base_url ) . 'oauth/token';
        $this->redirect_uri = admin_url( 'admin.php?page=mediagraph-callback' );

        // Register callback handler
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
    }

    /**
     * Check if user is connected to Mediagraph
     *
     * @return bool True if connected
     */
    public function is_connected() {
        $access_token = get_option( 'mediagraph_access_token', '' );
        return ! empty( $access_token );
    }

    /**
     * Generate PKCE code verifier and challenge
     *
     * @return array Array with code_verifier and code_challenge
     */
    private function generate_pkce_params() {
        // Generate random code_verifier (43-128 characters)
        $code_verifier = $this->base64_url_encode( wp_generate_password( 32, false, false ) );

        // Store code_verifier temporarily for token exchange
        set_transient( 'mediagraph_pkce_verifier', $code_verifier, 10 * MINUTE_IN_SECONDS );

        // Generate code_challenge using S256 method
        $code_challenge = $this->base64_url_encode(
            hash( 'sha256', $code_verifier, true )
        );

        return array(
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => 'S256',
        );
    }

    /**
     * Base64 URL encode (RFC 4648)
     *
     * @param string $data Data to encode
     * @return string Base64 URL-encoded string
     */
    private function base64_url_encode( $data ) {
        return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
    }

    /**
     * Get OAuth authorization URL with PKCE
     *
     * @return string Authorization URL
     */
    public function get_authorization_url() {
        // Generate state for CSRF protection
        $state = wp_generate_password( 32, false );
        set_transient( 'mediagraph_oauth_state', $state, 10 * MINUTE_IN_SECONDS );

        // Generate PKCE parameters
        $pkce_params = $this->generate_pkce_params();

        $params = array_merge(
            array(
                'client_id'     => self::PUBLIC_CLIENT_ID,
                'redirect_uri'  => $this->redirect_uri,
                'response_type' => 'code',
                'scope'         => 'read write',
                'state'         => $state,
            ),
            $pkce_params
        );

        return add_query_arg( $params, $this->authorize_url );
    }

    /**
     * Render OAuth callback page (placeholder)
     *
     * The actual callback handling happens in handle_oauth_callback() during admin_init,
     * which redirects to the settings page. This method should never be called.
     */
    public function render_callback_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'Connecting to Mediagraph...', 'mediagraph-assets' ) . '</h1>';
        echo '<p>' . esc_html__( 'Please wait while we complete the connection.', 'mediagraph-assets' ) . '</p>';
        echo '</div>';
    }

    /**
     * Handle OAuth callback
     */
    public function handle_oauth_callback() {
        // Check if this is the OAuth callback page
        if ( ! isset( $_GET['page'] ) || 'mediagraph-callback' !== $_GET['page'] ) {
            return;
        }

        // Check for errors
        if ( isset( $_GET['error'] ) ) {
            $error_description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : __( 'OAuth authorization failed', 'mediagraph-assets' );
            wp_die( esc_html( $error_description ) );
        }

        // Get authorization code
        if ( ! isset( $_GET['code'] ) ) {
            wp_die( esc_html__( 'Authorization code not received', 'mediagraph-assets' ) );
        }

        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

        // Verify state for CSRF protection
        if ( ! isset( $_GET['state'] ) ) {
            wp_die( esc_html__( 'State parameter missing', 'mediagraph-assets' ) );
        }

        $state = sanitize_text_field( wp_unslash( $_GET['state'] ) );
        $saved_state = get_transient( 'mediagraph_oauth_state' );

        if ( $state !== $saved_state ) {
            delete_transient( 'mediagraph_oauth_state' );
            wp_die( esc_html__( 'Invalid state parameter', 'mediagraph-assets' ) );
        }

        delete_transient( 'mediagraph_oauth_state' );

        // Exchange code for tokens
        $tokens = $this->exchange_code_for_tokens( $code );

        if ( is_wp_error( $tokens ) ) {
            wp_die( esc_html( $tokens->get_error_message() ) );
        }

        // Store tokens
        $this->store_tokens( $tokens );

        // Get user info and organization
        $this->fetch_and_store_user_info();

        // Redirect to settings page with success message
        wp_safe_redirect( admin_url( 'options-general.php?page=mediagraph-settings&connected=1' ) );
        exit;
    }

    /**
     * Exchange authorization code for access token (with PKCE)
     *
     * @param string $code Authorization code
     * @return array|WP_Error Token data or error
     */
    private function exchange_code_for_tokens( $code ) {
        // Retrieve the code_verifier from transient
        $code_verifier = get_transient( 'mediagraph_pkce_verifier' );
        delete_transient( 'mediagraph_pkce_verifier' );

        if ( empty( $code_verifier ) ) {
            return new WP_Error( 'pkce_verifier_missing', __( 'PKCE verifier not found. Please try connecting again.', 'mediagraph-assets' ) );
        }

        $response = wp_remote_post( $this->token_url, array(
            'body' => array(
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirect_uri,
                'client_id'     => self::PUBLIC_CLIENT_ID,
                'code_verifier' => $code_verifier, // PKCE proof instead of client_secret
            ),
            'timeout' => 30,
        ));

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code !== 200 ) {
            $error_message = isset( $data['error_description'] ) ? $data['error_description'] : __( 'Token exchange failed', 'mediagraph-assets' );

            // Log detailed error for debugging
            error_log( 'Mediagraph token exchange failed:' );
            error_log( '  Response code: ' . $response_code );
            error_log( '  Response body: ' . $response_body );
            error_log( '  Token URL: ' . $this->token_url );
            error_log( '  Redirect URI: ' . $this->redirect_uri );

            return new WP_Error( 'token_exchange_failed', $error_message );
        }

        return $data;
    }

    /**
     * Store OAuth tokens
     *
     * @param array $tokens Token data
     */
    private function store_tokens( $tokens ) {
        update_option( 'mediagraph_access_token', $tokens['access_token'] );

        if ( isset( $tokens['refresh_token'] ) ) {
            update_option( 'mediagraph_refresh_token', $tokens['refresh_token'] );
        }

        if ( isset( $tokens['expires_in'] ) ) {
            $expires_at = time() + intval( $tokens['expires_in'] );
            update_option( 'mediagraph_token_expires_at', $expires_at );
        }

        update_option( 'mediagraph_token_created_at', time() );
    }

    /**
     * Fetch and store user info from Mediagraph
     */
    private function fetch_and_store_user_info() {
        $api = new Mediagraph_API();
        $user_info = $api->test_connection();

        if ( is_wp_error( $user_info ) ) {
            error_log( 'Mediagraph fetch_and_store_user_info error: ' . $user_info->get_error_message() );
            return;
        }

        // Debug: Log the API response structure
        error_log( 'Mediagraph user_info: ' . wp_json_encode( $user_info ) );

        // Store user info (user data is at root level in API response)
        if ( isset( $user_info['id'] ) ) {
            update_option( 'mediagraph_user_id', $user_info['id'] );
        }
        if ( isset( $user_info['email'] ) ) {
            update_option( 'mediagraph_user_email', $user_info['email'] );
        }
        if ( isset( $user_info['name'] ) ) {
            update_option( 'mediagraph_user_name', $user_info['name'] );
        }

        // Store organization info (use 'organization' which reflects the current OAuth context)
        // Falls back to 'last_organization' if 'organization' is not present
        $org_data = isset( $user_info['organization'] ) ? $user_info['organization'] : ( isset( $user_info['last_organization'] ) ? $user_info['last_organization'] : null );

        if ( $org_data ) {
            if ( isset( $org_data['id'] ) ) {
                update_option( 'mediagraph_organization_id', $org_data['id'] );
            }
            if ( isset( $org_data['title'] ) ) {
                update_option( 'mediagraph_organization_name', $org_data['title'] );
            }
        }

        // Store membership role if available
        if ( isset( $user_info['membership']['role_level'] ) ) {
            update_option( 'mediagraph_membership_role', $user_info['membership']['role_level'] );
        }

        // Store available organizations if user has multiple
        if ( isset( $user_info['organizations'] ) && is_array( $user_info['organizations'] ) ) {
            update_option( 'mediagraph_available_organizations', $user_info['organizations'] );
        }
    }

    /**
     * Refresh access token using refresh token (PKCE - no secret required)
     *
     * @return bool Success
     */
    public function refresh_token() {
        $refresh_token = get_option( 'mediagraph_refresh_token', '' );

        if ( empty( $refresh_token ) ) {
            return false;
        }

        $response = wp_remote_post( $this->token_url, array(
            'body' => array(
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh_token,
                'client_id'     => self::PUBLIC_CLIENT_ID,
                // No client_secret needed with PKCE
            ),
            'timeout' => 30,
        ));

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data = json_decode( $response_body, true );

        if ( $response_code !== 200 || ! isset( $data['access_token'] ) ) {
            return false;
        }

        // Store new tokens
        $this->store_tokens( $data );

        return true;
    }

    /**
     * Check if token is expired and refresh if needed
     *
     * @return bool True if token is valid
     */
    public function ensure_valid_token() {
        $expires_at = get_option( 'mediagraph_token_expires_at', 0 );

        // Check if token is expired or will expire in next 5 minutes
        if ( $expires_at > 0 && ( time() + 300 ) >= $expires_at ) {
            return $this->refresh_token();
        }

        return true;
    }

    /**
     * Disconnect from Mediagraph
     */
    public function disconnect() {
        // Delete all OAuth-related options
        delete_option( 'mediagraph_access_token' );
        delete_option( 'mediagraph_refresh_token' );
        delete_option( 'mediagraph_token_expires_at' );
        delete_option( 'mediagraph_token_created_at' );
        delete_option( 'mediagraph_user_id' );
        delete_option( 'mediagraph_user_email' );
        delete_option( 'mediagraph_user_name' );
        delete_option( 'mediagraph_organization_id' );
        delete_option( 'mediagraph_organization_name' );
        delete_option( 'mediagraph_membership_role' );
        delete_option( 'mediagraph_available_organizations' );

        // Clear cached data
        delete_transient( 'mediagraph_asset_groups' );
        delete_transient( 'mediagraph_api_status' );
    }

    /**
     * Switch to different organization (for multi-org users)
     *
     * @param int $organization_id Organization ID
     * @return bool Success
     */
    public function switch_organization( $organization_id ) {
        $available_orgs = get_option( 'mediagraph_available_organizations', array() );

        // Find the organization
        $selected_org = null;
        foreach ( $available_orgs as $org ) {
            if ( intval( $org['id'] ) === intval( $organization_id ) ) {
                $selected_org = $org;
                break;
            }
        }

        if ( ! $selected_org ) {
            return false;
        }

        // Update organization info
        update_option( 'mediagraph_organization_id', $selected_org['id'] );
        update_option( 'mediagraph_organization_name', $selected_org['name'] );

        // Clear cached data for new organization
        delete_transient( 'mediagraph_asset_groups' );

        return true;
    }

    /**
     * Get connection status and info
     *
     * @return array Connection details
     */
    public function get_connection_info() {
        return array(
            'connected'         => $this->is_connected(),
            'user_name'         => get_option( 'mediagraph_user_name', '' ),
            'user_email'        => get_option( 'mediagraph_user_email', '' ),
            'organization_name' => get_option( 'mediagraph_organization_name', '' ),
            'organization_id'   => get_option( 'mediagraph_organization_id', '' ),
            'membership_role'   => get_option( 'mediagraph_membership_role', '' ),
            'token_created_at'  => get_option( 'mediagraph_token_created_at', 0 ),
            'token_expires_at'  => get_option( 'mediagraph_token_expires_at', 0 ),
            'available_orgs'    => get_option( 'mediagraph_available_organizations', array() ),
        );
    }
}
