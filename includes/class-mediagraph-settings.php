<?php
/**
 * Settings screen.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and handles Settings → Mediagraph.
 */
class Mediagraph_Settings {

	const PAGE            = 'mediagraph-settings';
	const SETTINGS_GROUP  = 'mediagraph_settings';

	/**
	 * Credential store.
	 *
	 * @var Mediagraph_Credentials
	 */
	private $credentials;

	/**
	 * OAuth handler.
	 *
	 * @var Mediagraph_OAuth
	 */
	private $oauth;

	/**
	 * HTTP client.
	 *
	 * @var Mediagraph_Client
	 */
	private $client;

	/**
	 * Repository.
	 *
	 * @var Mediagraph_Repository
	 */
	private $repository;

	/**
	 * Constructor.
	 *
	 * @param Mediagraph_Credentials $credentials Credential store.
	 * @param Mediagraph_OAuth       $oauth       OAuth handler.
	 * @param Mediagraph_Client      $client      HTTP client.
	 * @param Mediagraph_Repository  $repository  Repository.
	 */
	public function __construct( Mediagraph_Credentials $credentials, Mediagraph_OAuth $oauth, Mediagraph_Client $client, Mediagraph_Repository $repository ) {
		$this->credentials = $credentials;
		$this->oauth       = $oauth;
		$this->client      = $client;
		$this->repository  = $repository;
	}

	/**
	 * Settings screen URL.
	 *
	 * @return string
	 */
	public static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_mediagraph_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_mediagraph_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_mediagraph_clear_cache', array( $this, 'handle_clear_cache' ) );
		add_action( 'admin_notices', array( $this, 'connection_warning' ) );
	}

	/**
	 * Add the menu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'Mediagraph', 'mediagraph-assets' ),
			__( 'Mediagraph', 'mediagraph-assets' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the stored settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			Mediagraph_Credentials::API_BASE_URL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_base_url' ),
				'default'           => Mediagraph_Credentials::DEFAULT_API_BASE_URL,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			Mediagraph_Credentials::PLATFORM,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_platform' ),
				'default'           => 'wordpress',
			)
		);
	}

	/**
	 * Validate the API base URL.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_base_url( $value ) {
		$value = untrailingslashit( esc_url_raw( trim( (string) $value ) ) );

		if ( '' === $value ) {
			return Mediagraph_Credentials::DEFAULT_API_BASE_URL;
		}

		if ( ! preg_match( '#^https?://#i', $value ) ) {
			add_settings_error(
				Mediagraph_Credentials::API_BASE_URL,
				'mediagraph_bad_url',
				__( 'The Mediagraph URL must start with http:// or https://.', 'mediagraph-assets' )
			);

			return $this->credentials->api_base_url();
		}

		// Pointing at a different install invalidates the current token.
		if ( $value !== $this->credentials->api_base_url() && $this->credentials->has_token() ) {
			$this->credentials->forget();

			add_settings_error(
				Mediagraph_Credentials::API_BASE_URL,
				'mediagraph_url_changed',
				__( 'The Mediagraph URL changed, so the previous connection was cleared. Connect again below.', 'mediagraph-assets' ),
				'warning'
			);
		}

		return $value;
	}

	/**
	 * Validate the platform selection.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_platform( $value ) {
		$value = sanitize_text_field( (string) $value );

		return in_array( $value, array_keys( $this->platforms() ), true ) ? $value : 'wordpress';
	}

	/**
	 * Available publishing platforms.
	 *
	 * @return array
	 */
	private function platforms() {
		return array(
			'wordpress' => __( 'WordPress (standard)', 'mediagraph-assets' ),
		);
	}

	/**
	 * Warn on every admin screen when the connection needs attention.
	 *
	 * The picker silently failing while the settings screen claimed
	 * "Connected" was the single most confusing behaviour in 1.x.
	 *
	 * @return void
	 */
	public function connection_warning() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'settings_page_' . self::PAGE === $screen->id ) {
			return;
		}

		if ( ! $this->credentials->has_token() || $this->credentials->is_connected() ) {
			return;
		}

		// Dismissible because this shows on every admin screen. WordPress.org
		// guideline 11 requires that of any sitewide notice, and an editor who
		// has seen it once should be able to get it out of the way.
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'The Mediagraph connection has expired, so the asset picker is unavailable.', 'mediagraph-assets' ),
			esc_url( self::url() ),
			esc_html__( 'Reconnect', 'mediagraph-assets' )
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mediagraph-assets' ) );
		}

		$info = $this->credentials->connection_info();
		$logo = MEDIAGRAPH_PLUGIN_URL . 'admin/images/mg-avatar-black.svg';
		?>
		<div class="wrap mediagraph-settings">
			<h1 class="mediagraph-settings__title">
				<img src="<?php echo esc_url( $logo ); ?>" alt="" width="32" height="32" />
				<?php esc_html_e( 'Mediagraph', 'mediagraph-assets' ); ?>
			</h1>

			<?php $this->render_notices(); ?>
			<?php settings_errors(); ?>

			<div class="mediagraph-settings__card">
				<?php if ( $info['connected'] ) : ?>
					<?php $this->render_connected( $info ); ?>
				<?php else : ?>
					<?php $this->render_disconnected( $info ); ?>
				<?php endif; ?>
			</div>

			<form method="post" action="options.php" class="mediagraph-settings__card">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<h2><?php esc_html_e( 'Configuration', 'mediagraph-assets' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="mediagraph_api_base_url"><?php esc_html_e( 'Mediagraph URL', 'mediagraph-assets' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								id="mediagraph_api_base_url"
								name="<?php echo esc_attr( Mediagraph_Credentials::API_BASE_URL ); ?>"
								value="<?php echo esc_attr( $this->credentials->api_base_url() ); ?>"
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Leave as https://mediagraph.io unless you use a dedicated instance. Changing this clears the current connection.', 'mediagraph-assets' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="mediagraph_platform"><?php esc_html_e( 'Publishing platform', 'mediagraph-assets' ); ?></label>
						</th>
						<td>
							<select id="mediagraph_platform" name="<?php echo esc_attr( Mediagraph_Credentials::PLATFORM ); ?>">
								<?php foreach ( $this->platforms() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->credentials->platform(), $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Controls how article metadata is shaped when usage is reported back to Mediagraph.', 'mediagraph-assets' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<div class="mediagraph-settings__card">
				<h2><?php esc_html_e( 'How assets are inserted', 'mediagraph-assets' ); ?></h2>
				<p>
					<?php esc_html_e( 'Assets chosen from Mediagraph are copied into your WordPress media library and inserted as standard blocks. That means every native control — cropping, alignment, sizes, captions, replace — works exactly as it does for uploaded files.', 'mediagraph-assets' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Choosing the same asset twice reuses the file already in your library instead of creating a duplicate.', 'mediagraph-assets' ); ?>
				</p>
				<p class="mediagraph-settings__meta">
					<?php
					printf(
						/* translators: %s: version number. */
						esc_html__( 'Plugin version %s', 'mediagraph-assets' ),
						esc_html( MEDIAGRAPH_VERSION )
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Connected state.
	 *
	 * @param array $info Connection info.
	 * @return void
	 */
	private function render_connected( array $info ) {
		?>
		<h2 class="mediagraph-status mediagraph-status--ok">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<?php esc_html_e( 'Connected to Mediagraph', 'mediagraph-assets' ); ?>
		</h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Account', 'mediagraph-assets' ); ?></th>
				<td>
					<?php echo esc_html( $info['user_name'] ? $info['user_name'] : __( 'Unknown', 'mediagraph-assets' ) ); ?>
					<?php if ( $info['user_email'] ) : ?>
						<br /><span class="description"><?php echo esc_html( $info['user_email'] ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Organization', 'mediagraph-assets' ); ?></th>
				<td>
					<?php echo esc_html( $info['organization_name'] ? $info['organization_name'] : __( 'Unknown', 'mediagraph-assets' ) ); ?>
					<?php if ( $info['membership_role'] ) : ?>
						<br /><span class="description">
							<?php
							printf(
								/* translators: %s: membership role. */
								esc_html__( 'Role: %s', 'mediagraph-assets' ),
								esc_html( ucwords( str_replace( '_', ' ', $info['membership_role'] ) ) )
							);
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Access token', 'mediagraph-assets' ); ?></th>
				<td><?php echo esc_html( $this->token_description( $info ) ); ?></td>
			</tr>
		</table>

		<p class="mediagraph-settings__actions">
			<?php $this->action_button( 'mediagraph_test', __( 'Test connection', 'mediagraph-assets' ), 'secondary' ); ?>
			<?php $this->action_button( 'mediagraph_clear_cache', __( 'Refresh asset list', 'mediagraph-assets' ), 'secondary' ); ?>
			<?php
			$this->action_button(
				'mediagraph_disconnect',
				__( 'Disconnect', 'mediagraph-assets' ),
				'delete',
				__( 'Disconnect this site from Mediagraph?', 'mediagraph-assets' )
			);
			?>
		</p>
		<?php
	}

	/**
	 * Disconnected (or expired) state.
	 *
	 * @param array $info Connection info.
	 * @return void
	 */
	private function render_disconnected( array $info ) {
		$expired = $info['has_token'] && $info['expired'];
		?>
		<h2 class="mediagraph-status <?php echo $expired ? 'mediagraph-status--warn' : ''; ?>">
			<span class="dashicons <?php echo $expired ? 'dashicons-warning' : 'dashicons-admin-links'; ?>" aria-hidden="true"></span>
			<?php
			echo $expired
				? esc_html__( 'The Mediagraph connection expired', 'mediagraph-assets' )
				: esc_html__( 'Connect to Mediagraph', 'mediagraph-assets' );
			?>
		</h2>

		<p>
			<?php
			echo $expired
				? esc_html__( 'Reconnect to keep browsing and inserting assets. Nothing already inserted into your posts is affected.', 'mediagraph-assets' )
				: esc_html__( 'Authorize this site so editors can browse your Mediagraph library from inside the WordPress editor.', 'mediagraph-assets' );
			?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mediagraph_connect" />
			<?php wp_nonce_field( 'mediagraph_connect' ); ?>
			<?php
			submit_button(
				$expired ? __( 'Reconnect to Mediagraph', 'mediagraph-assets' ) : __( 'Connect to Mediagraph', 'mediagraph-assets' ),
				'primary',
				'submit',
				false
			);
			?>
		</form>
		<?php
	}

	/**
	 * Describe token freshness in plain language.
	 *
	 * @param array $info Connection info.
	 * @return string
	 */
	private function token_description( array $info ) {
		if ( empty( $info['token_expires_at'] ) ) {
			return __( 'Active. Mediagraph did not set an expiry for this token.', 'mediagraph-assets' );
		}

		$remaining = (int) $info['token_expires_at'] - time();

		if ( $remaining <= 0 ) {
			return $info['can_refresh']
				? __( 'Expired. It will renew automatically on the next request.', 'mediagraph-assets' )
				: __( 'Expired, and it cannot be renewed. Reconnect below.', 'mediagraph-assets' );
		}

		return sprintf(
			/* translators: %s: human readable duration, e.g. "1 hour". */
			__( 'Active, renews automatically. Expires in %s.', 'mediagraph-assets' ),
			human_time_diff( time(), (int) $info['token_expires_at'] )
		);
	}

	/**
	 * Render a small admin-post form button.
	 *
	 * @param string $action  admin-post action name.
	 * @param string $label   Button label.
	 * @param string $type    Button style.
	 * @param string $confirm Optional confirmation message.
	 * @return void
	 */
	private function action_button( $action, $label, $type = 'secondary', $confirm = '' ) {
		printf(
			'<form method="post" action="%1$s" class="mediagraph-inline-form"%2$s>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : ''
		);

		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( $action ) );
		wp_nonce_field( $action );
		submit_button( $label, $type, 'submit', false );

		echo '</form>';
	}

	/**
	 * Handle disconnect.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		$this->authorize( 'mediagraph_disconnect' );

		$this->oauth->disconnect();

		$this->redirect( 'disconnected' );
	}

	/**
	 * Handle a connection test.
	 *
	 * @return void
	 */
	public function handle_test() {
		$this->authorize( 'mediagraph_test' );

		$result = $this->oauth->refresh_identity();

		if ( is_wp_error( $result ) ) {
			$this->redirect( 'test_failed', $result->get_error_message() );
		}

		$this->redirect( 'test_ok' );
	}

	/**
	 * Handle a cache clear.
	 *
	 * @return void
	 */
	public function handle_clear_cache() {
		$this->authorize( 'mediagraph_clear_cache' );

		Mediagraph_Cache::flush();

		$this->redirect( 'cache_cleared' );
	}

	/**
	 * Verify nonce and capability for an admin-post action.
	 *
	 * @param string $action Action name.
	 * @return void
	 */
	private function authorize( $action ) {
		check_admin_referer( $action );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'mediagraph-assets' ) );
		}
	}

	/**
	 * Redirect back with a notice code.
	 *
	 * @param string $notice Notice key.
	 * @param string $detail Optional detail text.
	 * @return void
	 */
	private function redirect( $notice, $detail = '' ) {
		$args = array( 'mg_notice' => $notice );

		if ( '' !== $detail ) {
			$args['mg_detail'] = rawurlencode( $detail );
		}

		wp_safe_redirect( add_query_arg( $args, self::url() ) );
		exit;
	}

	/**
	 * Render queued notices.
	 *
	 * @return void
	 */
	private function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['mg_notice'] ) ) {
			return;
		}

		$notice = sanitize_key( wp_unslash( $_GET['mg_notice'] ) );
		$detail = isset( $_GET['mg_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['mg_detail'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'connected'      => array( 'success', __( 'Connected to Mediagraph.', 'mediagraph-assets' ) ),
			'disconnected'   => array( 'success', __( 'Disconnected from Mediagraph.', 'mediagraph-assets' ) ),
			'test_ok'        => array( 'success', __( 'Connection is working.', 'mediagraph-assets' ) ),
			'cache_cleared'  => array( 'success', __( 'Cached collections, folders, and lightboxes were cleared.', 'mediagraph-assets' ) ),
			'test_failed'    => array( 'error', __( 'Connection test failed.', 'mediagraph-assets' ) ),
			'connect_failed' => array( 'error', __( 'Could not finish connecting to Mediagraph.', 'mediagraph-assets' ) ),
		);

		if ( ! isset( $notices[ $notice ] ) ) {
			return;
		}

		list( $type, $message ) = $notices[ $notice ];

		if ( '' !== $detail ) {
			$message .= ' ' . $detail;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( $message )
		);
	}
}
