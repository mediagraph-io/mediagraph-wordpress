<?php
/**
 * Editor integration: script loading, block wiring, classic editor button.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the picker wherever media can be chosen.
 */
class Mediagraph_Editor {

	/**
	 * Credential store.
	 *
	 * @var Mediagraph_Credentials
	 */
	private $credentials;

	/**
	 * Repository.
	 *
	 * @var Mediagraph_Repository
	 */
	private $repository;

	/**
	 * Guard so the assets are only enqueued once per request.
	 *
	 * @var bool
	 */
	private $enqueued = false;

	/**
	 * Constructor.
	 *
	 * @param Mediagraph_Credentials $credentials Credential store.
	 * @param Mediagraph_Repository  $repository  Repository.
	 */
	public function __construct( Mediagraph_Credentials $credentials, Mediagraph_Repository $repository ) {
		$this->credentials = $credentials;
		$this->repository  = $repository;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
		add_action( 'media_buttons', array( $this, 'classic_editor_button' ), 15 );

		// Front end and editor styles for legacy 1.x markup.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_asset_styles' ) );
		add_action( 'enqueue_block_assets', array( $this, 'enqueue_asset_styles' ) );
	}

	/**
	 * The core blocks the picker plugs into.
	 *
	 * `types` narrows the grid so a Video block only ever offers videos, which
	 * is what makes "insert" safe from inside a typed block.
	 *
	 * @return array
	 */
	public function block_support() {
		$blocks = array(
			'core/image'     => array(
				'types'    => array( 'image' ),
				'multiple' => false,
			),
			'core/gallery'   => array(
				'types'    => array( 'image' ),
				'multiple' => true,
			),
			'core/audio'     => array(
				'types'    => array( 'audio' ),
				'multiple' => false,
			),
			'core/video'     => array(
				'types'    => array( 'video' ),
				'multiple' => false,
			),
			'core/cover'     => array(
				'types'    => array( 'image', 'video' ),
				'multiple' => false,
			),
			'core/media-text' => array(
				'types'    => array( 'image', 'video' ),
				'multiple' => false,
			),
			'core/file'      => array(
				'types'    => array(),
				'multiple' => false,
			),
			'core/site-logo' => array(
				'types'    => array( 'image' ),
				'multiple' => false,
			),
			// `core/icon` is intentionally omitted. It is filed under the
			// editor's "media" category but holds no uploaded file — its only
			// content attribute is `icon`, naming an entry in WordPress's
			// built-in SVG icon library. There is nowhere to put an attachment.
		);

		/**
		 * Filter which blocks get a Mediagraph option.
		 *
		 * Keys are block names; each value takes `types` (asset kinds to allow)
		 * and `multiple` (whether multi-select is offered).
		 *
		 * @param array $blocks Block support map.
		 */
		return apply_filters( 'mediagraph_supported_blocks', $blocks );
	}

	/**
	 * Enqueue on admin screens where media gets chosen.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function maybe_enqueue( $hook ) {
		$screens = array( 'post.php', 'post-new.php', 'upload.php', 'widgets.php', 'site-editor.php', 'customize.php' );

		/**
		 * Filter the admin screens that load the Mediagraph picker.
		 *
		 * @param array  $screens Admin page hooks.
		 * @param string $hook    Current hook.
		 */
		$screens = (array) apply_filters( 'mediagraph_admin_screens', $screens, $hook );

		if ( ! in_array( $hook, $screens, true ) ) {
			return;
		}

		$this->enqueue();
	}

	/**
	 * Enqueue the picker bundle, block integration, and styles.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( $this->enqueued ) {
			return;
		}

		$capability = (string) apply_filters( 'mediagraph_required_capability', 'edit_posts' );

		if ( ! current_user_can( $capability ) ) {
			return;
		}

		$this->enqueued = true;

		// The media modal integration needs core's media scripts present.
		if ( ! did_action( 'wp_enqueue_media' ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style(
			'mediagraph-picker',
			MEDIAGRAPH_PLUGIN_URL . 'admin/css/mediagraph-picker.css',
			array(),
			$this->asset_version( 'admin/css/mediagraph-picker.css' )
		);

		wp_enqueue_script(
			'mediagraph-picker',
			MEDIAGRAPH_PLUGIN_URL . 'admin/js/dist/mediagraph-picker.bundle.js',
			array( 'wp-element', 'wp-i18n' ),
			$this->asset_version( 'admin/js/dist/mediagraph-picker.bundle.js' ),
			true
		);

		wp_set_script_translations( 'mediagraph-picker', 'mediagraph-assets', MEDIAGRAPH_PLUGIN_DIR . 'languages' );

		// wp_localize_script() casts every top-level value to a string, which
		// turns booleans into '1' and ''. Those happen to coerce correctly in
		// JavaScript, but the contract is then a lie. Emit real JSON instead so
		// `connected` is a boolean on both sides.
		wp_add_inline_script(
			'mediagraph-picker',
			'var mediagraphSettings = ' . wp_json_encode( $this->script_data() ) . ';',
			'before'
		);

		// Block editor integration: toolbar buttons plus a Mediagraph option
		// alongside Upload and Media Library in every media placeholder.
		wp_enqueue_script(
			'mediagraph-blocks',
			MEDIAGRAPH_PLUGIN_URL . 'admin/js/dist/mediagraph-blocks.bundle.js',
			// wp-core-data registers the `core` store the metadata panel reads
			// and writes attachments through.
			array( 'mediagraph-picker', 'wp-hooks', 'wp-compose', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-blocks', 'wp-data', 'wp-core-data', 'wp-i18n' ),
			$this->asset_version( 'admin/js/dist/mediagraph-blocks.bundle.js' ),
			true
		);

		wp_set_script_translations( 'mediagraph-blocks', 'mediagraph-assets', MEDIAGRAPH_PLUGIN_DIR . 'languages' );

		// Legacy mediagraph/asset-picker block, so 1.x posts still edit.
		wp_enqueue_script(
			'mediagraph-legacy-block',
			MEDIAGRAPH_PLUGIN_URL . 'admin/js/blocks/legacy-block.js',
			array( 'mediagraph-picker', 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			$this->asset_version( 'admin/js/blocks/legacy-block.js' ),
			true
		);

		// Mediagraph tab inside the classic WordPress media modal.
		wp_enqueue_script(
			'mediagraph-media-modal',
			MEDIAGRAPH_PLUGIN_URL . 'admin/js/media-modal.js',
			array( 'mediagraph-picker', 'jquery', 'media-views', 'media-models', 'wp-i18n' ),
			$this->asset_version( 'admin/js/media-modal.js' ),
			true
		);

		// Classic editor "Add from Mediagraph" button.
		wp_enqueue_script(
			'mediagraph-classic-editor',
			MEDIAGRAPH_PLUGIN_URL . 'admin/js/classic-editor.js',
			array( 'mediagraph-picker', 'wp-i18n' ),
			$this->asset_version( 'admin/js/classic-editor.js' ),
			true
		);

		$this->enqueue_asset_styles();
	}

	/**
	 * Styles for assets inserted by 1.x.
	 *
	 * Kept on the front end so existing posts keep their alignment after the
	 * upgrade.
	 *
	 * @return void
	 */
	public function enqueue_asset_styles() {
		wp_enqueue_style(
			'mediagraph-asset',
			MEDIAGRAPH_PLUGIN_URL . 'admin/css/mediagraph-asset.css',
			array(),
			$this->asset_version( 'admin/css/mediagraph-asset.css' )
		);
	}

	/**
	 * Cache-busting version for a bundled asset.
	 *
	 * Using the plugin version alone means a file can change while its URL does
	 * not — every browser that already cached it keeps the stale copy until the
	 * next version bump. Keying on the file's modification time makes the URL
	 * change exactly when the bytes do.
	 *
	 * @param string $relative_path Path relative to the plugin directory.
	 * @return string Version string.
	 */
	private function asset_version( $relative_path ) {
		$file = MEDIAGRAPH_PLUGIN_DIR . ltrim( $relative_path, '/' );

		if ( is_readable( $file ) ) {
			$mtime = filemtime( $file );

			if ( $mtime ) {
				return MEDIAGRAPH_VERSION . '.' . $mtime;
			}
		}

		return MEDIAGRAPH_VERSION;
	}

	/**
	 * Data handed to the JavaScript.
	 *
	 * @return array
	 */
	private function script_data() {
		return array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( Mediagraph_Ajax::NONCE ),
			// The real Mediagraph mark, referenced from the shipped file so
			// there is one source of truth for the brand asset.
			'logoUrl'      => MEDIAGRAPH_PLUGIN_URL . 'admin/images/mg-avatar-black.svg',
			'connected'    => $this->credentials->is_connected(),
			'expired'      => $this->credentials->is_expired(),
			'organization' => $this->credentials->organization_name(),
			'settingsUrl'  => Mediagraph_Settings::url(),
			'canConnect'   => current_user_can( 'manage_options' ),
			'canUpload'    => current_user_can( 'upload_files' ),
			'blocks'       => $this->block_support(),
			'defaults'     => array(
				'perPage'   => 40,
				'rendition' => (string) apply_filters( 'mediagraph_default_rendition', 'full' ),
			),
			'rightsClasses' => array_map(
				static function ( $code, $label ) {
					return array(
						'value' => $code,
						'label' => $label,
					);
				},
				array_keys( Mediagraph_Repository::rights_classes() ),
				array_values( Mediagraph_Repository::rights_classes() )
			),
			'sortOptions'  => array(
				array(
					'value' => 'created_at:desc',
					'label' => __( 'Date added (newest)', 'mediagraph-assets' ),
				),
				array(
					'value' => 'created_at:asc',
					'label' => __( 'Date added (oldest)', 'mediagraph-assets' ),
				),
				array(
					'value' => 'captured_at:desc',
					'label' => __( 'Date created (newest)', 'mediagraph-assets' ),
				),
				array(
					'value' => 'captured_at:asc',
					'label' => __( 'Date created (oldest)', 'mediagraph-assets' ),
				),
				array(
					'value' => 'filename:asc',
					'label' => __( 'Filename (A–Z)', 'mediagraph-assets' ),
				),
				array(
					'value' => 'filename:desc',
					'label' => __( 'Filename (Z–A)', 'mediagraph-assets' ),
				),
			),
			// These match the sizes Mediagraph's download endpoint accepts. The
			// picker only offers the ones the connected account may actually
			// download for a given asset.
			'renditions'   => array(
				array(
					'value' => 'original',
					'label' => __( 'Original file', 'mediagraph-assets' ),
					'help'  => __( 'The untouched file as stored in Mediagraph.', 'mediagraph-assets' ),
				),
				array(
					'value' => 'full',
					'label' => __( 'Full size (recommended)', 'mediagraph-assets' ),
					'help'  => __( 'Web-ready full resolution. WordPress generates its own thumbnail sizes from this.', 'mediagraph-assets' ),
				),
				array(
					'value' => 'medium',
					'label' => __( 'Medium (1200px)', 'mediagraph-assets' ),
					'help'  => '',
				),
				array(
					'value' => 'small',
					'label' => __( 'Small (640px)', 'mediagraph-assets' ),
					'help'  => '',
				),
			),
		);
	}

	/**
	 * Classic editor media button.
	 *
	 * @param string $editor_id Editor instance ID.
	 * @return void
	 */
	public function classic_editor_button( $editor_id ) {
		if ( ! $this->credentials->is_connected() ) {
			return;
		}

		printf(
			'<button type="button" class="button mediagraph-classic-button" data-editor="%1$s"><span class="dashicons dashicons-format-gallery" aria-hidden="true"></span> %2$s</button>',
			esc_attr( $editor_id ),
			esc_html__( 'Add from Mediagraph', 'mediagraph-assets' )
		);
	}
}
