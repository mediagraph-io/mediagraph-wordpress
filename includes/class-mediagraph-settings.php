<?php
/**
 * Mediagraph Settings Page
 *
 * Manages plugin settings and OAuth configuration.
 *
 * @package MediagraphAssets
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Mediagraph settings class
 */
class Mediagraph_Settings {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_mediagraph_disconnect', array( $this, 'handle_disconnect' ) );
        add_action( 'admin_post_mediagraph_switch_org', array( $this, 'handle_switch_organization' ) );
        add_action( 'admin_post_mediagraph_test_connection', array( $this, 'handle_test_connection' ) );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API Base URL (for self-hosted instances)
        register_setting( 'mediagraph_settings', 'mediagraph_api_base_url', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => 'https://mediagraph.io',
        ));

        // Platform selection
        register_setting( 'mediagraph_settings', 'mediagraph_platform', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'wordpress',
        ));

        // Add settings sections
        add_settings_section(
            'mediagraph_advanced_section',
            __( 'Advanced Settings', 'mediagraph-assets' ),
            array( $this, 'render_advanced_section' ),
            'mediagraph-settings'
        );

        add_settings_section(
            'mediagraph_platform_section',
            __( 'Platform Settings', 'mediagraph-assets' ),
            array( $this, 'render_platform_section' ),
            'mediagraph-settings'
        );

        // Add settings fields
        add_settings_field(
            'mediagraph_api_base_url',
            __( 'API Base URL', 'mediagraph-assets' ),
            array( $this, 'render_api_base_url_field' ),
            'mediagraph-settings',
            'mediagraph_advanced_section'
        );

        add_settings_field(
            'mediagraph_platform',
            __( 'Publishing Platform', 'mediagraph-assets' ),
            array( $this, 'render_platform_field' ),
            'mediagraph-settings',
            'mediagraph_platform_section'
        );
    }

    /**
     * Render advanced section description
     */
    public function render_advanced_section() {
        echo '<p>';
        echo esc_html__( 'Advanced settings for self-hosted or custom Mediagraph instances.', 'mediagraph-assets' );
        echo '</p>';
    }

    /**
     * Render platform section description
     */
    public function render_platform_section() {
        echo '<p>';
        echo esc_html__( 'Select your publishing platform to configure the correct metadata mapping.', 'mediagraph-assets' );
        echo '</p>';
    }

    /**
     * Render API Base URL field
     */
    public function render_api_base_url_field() {
        $value = get_option( 'mediagraph_api_base_url', 'https://mediagraph.io' );
        printf(
            '<input type="url" name="mediagraph_api_base_url" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
        echo '<p class="description">';
        echo esc_html__( 'Base URL for Mediagraph API (use default unless you have a custom instance)', 'mediagraph-assets' );
        echo '</p>';
    }

    /**
     * Render Platform field
     */
    public function render_platform_field() {
        $value = get_option( 'mediagraph_platform', 'wordpress' );
        $platforms = array(
            'wordpress' => __( 'WordPress (Standard)', 'mediagraph-assets' ),
            'newspack'  => __( 'Newspack', 'mediagraph-assets' ) . ' ' . __( '(Coming soon)', 'mediagraph-assets' ),
            'blox'      => __( 'Blox CMS', 'mediagraph-assets' ) . ' ' . __( '(Coming soon)', 'mediagraph-assets' ),
        );

        echo '<select name="mediagraph_platform" class="regular-text">';
        foreach ( $platforms as $platform_id => $platform_name ) {
            printf(
                '<option value="%s" %s %s>%s</option>',
                esc_attr( $platform_id ),
                selected( $value, $platform_id, false ),
                in_array( $platform_id, array( 'newspack', 'blox' ), true ) ? 'disabled' : '',
                esc_html( $platform_name )
            );
        }
        echo '</select>';
        echo '<p class="description">';
        echo esc_html__( 'Determines how article metadata is formatted when sent back to Mediagraph', 'mediagraph-assets' );
        echo '</p>';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mediagraph-assets' ) );
        }

        $oauth = new Mediagraph_OAuth();
        $connection_info = $oauth->get_connection_info();

        $logo_url = plugins_url( 'admin/images/mg-avatar-black.svg', dirname( __FILE__ ) );
        ?>
        <div class="wrap">
            <div style="display: flex; align-items: center; margin-bottom: 20px;">
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="Mediagraph" style="width: 40px; height: 40px; margin-right: 15px;" />
                <h1 style="margin: 0;"><?php echo esc_html( get_admin_page_title() ); ?></h1>
            </div>

            <?php $this->render_notices(); ?>

            <?php if ( $connection_info['connected'] ) : ?>
                <?php $this->render_connection_status( $connection_info ); ?>
            <?php else : ?>
                <?php $this->render_setup_form(); ?>
            <?php endif; ?>

            <hr />

            <h2><?php esc_html_e( 'Plugin Information', 'mediagraph-assets' ); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Plugin Version', 'mediagraph-assets' ); ?></th>
                    <td><?php echo esc_html( MEDIAGRAPH_PICKER_VERSION ); ?></td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render admin notices
     */
    private function render_notices() {
        // Show success message after connecting
        if ( isset( $_GET['connected'] ) && '1' === $_GET['connected'] ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html__( 'Successfully connected to Mediagraph!', 'mediagraph-assets' ) . '</p>';
            echo '</div>';
        }

        // Show success message after disconnecting
        if ( isset( $_GET['disconnected'] ) && '1' === $_GET['disconnected'] ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html__( 'Disconnected from Mediagraph.', 'mediagraph-assets' ) . '</p>';
            echo '</div>';
        }

        // Show success message after switching organization
        if ( isset( $_GET['org_switched'] ) && '1' === $_GET['org_switched'] ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html__( 'Organization switched successfully.', 'mediagraph-assets' ) . '</p>';
            echo '</div>';
        }

        // Show test connection results
        if ( isset( $_GET['test_result'] ) ) {
            $result = sanitize_text_field( wp_unslash( $_GET['test_result'] ) );
            if ( 'success' === $result ) {
                echo '<div class="notice notice-success is-dismissible">';
                echo '<p>' . esc_html__( 'Connection test successful!', 'mediagraph-assets' ) . '</p>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p>' . esc_html__( 'Connection test failed. Please check your credentials.', 'mediagraph-assets' ) . '</p>';
                echo '</div>';
            }
        }

        // Show settings saved message
        if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>' . esc_html__( 'Settings saved.', 'mediagraph-assets' ) . '</p>';
            echo '</div>';
        }
    }

    /**
     * Render connection status
     *
     * @param array $info Connection info
     */
    private function render_connection_status( $info ) {
        ?>
        <div class="card">
            <h2><?php esc_html_e( 'Connection Status', 'mediagraph-assets' ); ?></h2>
            <p class="description">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                <?php esc_html_e( 'Connected to Mediagraph', 'mediagraph-assets' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'User', 'mediagraph-assets' ); ?></th>
                    <td>
                        <?php echo esc_html( $info['user_name'] ); ?>
                        <br />
                        <span class="description"><?php echo esc_html( $info['user_email'] ); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Organization', 'mediagraph-assets' ); ?></th>
                    <td>
                        <?php echo esc_html( $info['organization_name'] ); ?>
                        <br />
                        <span class="description">
                            <?php
                            /* translators: %s: membership role */
                            printf( esc_html__( 'Role: %s', 'mediagraph-assets' ), esc_html( ucfirst( $info['membership_role'] ) ) );
                            ?>
                        </span>
                    </td>
                </tr>
                <?php if ( count( $info['available_orgs'] ) > 1 ) : ?>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Switch Organization', 'mediagraph-assets' ); ?></th>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="mediagraph_switch_org" />
                            <?php wp_nonce_field( 'mediagraph_switch_org', 'mediagraph_switch_org_nonce' ); ?>
                            <select name="organization_id">
                                <?php foreach ( $info['available_orgs'] as $org ) : ?>
                                    <option value="<?php echo esc_attr( $org['id'] ); ?>" <?php selected( $org['id'], $info['organization_id'] ); ?>>
                                        <?php echo esc_html( $org['name'] ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php submit_button( __( 'Switch', 'mediagraph-assets' ), 'secondary', 'submit', false ); ?>
                        </form>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <p>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin-right: 10px;">
                    <input type="hidden" name="action" value="mediagraph_test_connection" />
                    <?php wp_nonce_field( 'mediagraph_test_connection', 'mediagraph_test_nonce' ); ?>
                    <?php submit_button( __( 'Test Connection', 'mediagraph-assets' ), 'secondary', 'submit', false ); ?>
                </form>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure you want to disconnect?', 'mediagraph-assets' ) ); ?>');">
                    <input type="hidden" name="action" value="mediagraph_disconnect" />
                    <?php wp_nonce_field( 'mediagraph_disconnect', 'mediagraph_disconnect_nonce' ); ?>
                    <?php submit_button( __( 'Disconnect', 'mediagraph-assets' ), 'delete', 'submit', false ); ?>
                </form>
            </p>
        </div>

        <hr />

        <h2><?php esc_html_e( 'Settings', 'mediagraph-assets' ); ?></h2>
        <?php
        $this->render_settings_form();
    }

    /**
     * Render setup form (not connected)
     */
    private function render_setup_form() {
        ?>
        <div class="card">
            <h2><?php esc_html_e( 'Connect to Mediagraph', 'mediagraph-assets' ); ?></h2>
            <p><?php esc_html_e( 'Connect to Mediagraph to start browsing and inserting media assets into your WordPress posts.', 'mediagraph-assets' ); ?></p>

            <p>
                <a href="<?php echo esc_url( ( new Mediagraph_OAuth() )->get_authorization_url() ); ?>" class="button button-primary button-hero">
                    <?php esc_html_e( 'Connect to Mediagraph', 'mediagraph-assets' ); ?>
                </a>
            </p>

            <hr />

            <?php $this->render_settings_form(); ?>
        </div>
        <?php
    }

    /**
     * Render settings form
     */
    private function render_settings_form() {
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( 'mediagraph_settings' );
            do_settings_sections( 'mediagraph-settings' );
            submit_button();
            ?>
        </form>
        <?php
    }

    /**
     * Handle disconnect action
     */
    public function handle_disconnect() {
        check_admin_referer( 'mediagraph_disconnect', 'mediagraph_disconnect_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'mediagraph-assets' ) );
        }

        $oauth = new Mediagraph_OAuth();
        $oauth->disconnect();

        wp_safe_redirect( admin_url( 'options-general.php?page=mediagraph-settings&disconnected=1' ) );
        exit;
    }

    /**
     * Handle switch organization action
     */
    public function handle_switch_organization() {
        check_admin_referer( 'mediagraph_switch_org', 'mediagraph_switch_org_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'mediagraph-assets' ) );
        }

        $organization_id = isset( $_POST['organization_id'] ) ? intval( $_POST['organization_id'] ) : 0;

        $oauth = new Mediagraph_OAuth();
        $success = $oauth->switch_organization( $organization_id );

        $redirect_url = admin_url( 'options-general.php?page=mediagraph-settings' );
        if ( $success ) {
            $redirect_url = add_query_arg( 'org_switched', '1', $redirect_url );
        }

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Handle test connection action
     */
    public function handle_test_connection() {
        check_admin_referer( 'mediagraph_test_connection', 'mediagraph_test_nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'mediagraph-assets' ) );
        }

        $api = new Mediagraph_API();
        $result = $api->test_connection();

        // If successful, update stored user and organization info
        if ( ! is_wp_error( $result ) ) {
            $oauth = new Mediagraph_OAuth();
            // Use reflection to call private method
            $reflection = new ReflectionClass( $oauth );
            $method = $reflection->getMethod( 'fetch_and_store_user_info' );
            $method->setAccessible( true );
            $method->invoke( $oauth );
        }

        $redirect_url = admin_url( 'options-general.php?page=mediagraph-settings' );
        $test_result = is_wp_error( $result ) ? 'error' : 'success';
        $redirect_url = add_query_arg( 'test_result', $test_result, $redirect_url );

        wp_safe_redirect( $redirect_url );
        exit;
    }
}
