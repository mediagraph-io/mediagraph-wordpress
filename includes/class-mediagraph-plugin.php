<?php
/**
 * Plugin container.
 *
 * Builds the service objects once and wires up every WordPress hook in a
 * single, readable place.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class Mediagraph_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Mediagraph_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Credential store.
	 *
	 * @var Mediagraph_Credentials
	 */
	public $credentials;

	/**
	 * OAuth handler.
	 *
	 * @var Mediagraph_OAuth
	 */
	public $oauth;

	/**
	 * HTTP client.
	 *
	 * @var Mediagraph_Client
	 */
	public $client;

	/**
	 * Domain repository.
	 *
	 * @var Mediagraph_Repository
	 */
	public $repository;

	/**
	 * Media library importer.
	 *
	 * @var Mediagraph_Media_Library
	 */
	public $media_library;

	/**
	 * Editor integration.
	 *
	 * @var Mediagraph_Editor
	 */
	public $editor;

	/**
	 * Settings screen.
	 *
	 * @var Mediagraph_Settings
	 */
	public $settings;

	/**
	 * Publish write-back.
	 *
	 * @var Mediagraph_Publisher
	 */
	public $publisher;

	/**
	 * Get the singleton.
	 *
	 * @return Mediagraph_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build services and register hooks.
	 */
	private function __construct() {
		$this->credentials   = new Mediagraph_Credentials();
		$this->oauth         = new Mediagraph_OAuth( $this->credentials );
		$this->client        = new Mediagraph_Client( $this->credentials, $this->oauth );
		$this->repository    = new Mediagraph_Repository( $this->client );
		$this->media_library = new Mediagraph_Media_Library( $this->client, $this->repository );
		$this->publisher     = new Mediagraph_Publisher( $this->client, $this->credentials );
		$this->editor        = new Mediagraph_Editor( $this->credentials, $this->repository );
		$this->settings      = new Mediagraph_Settings( $this->credentials, $this->oauth, $this->client, $this->repository );

		$this->register_hooks();
	}

	/**
	 * Register every WordPress hook the plugin uses.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'register_attachment_meta' ) );
		add_action( 'admin_init', array( Mediagraph_Upgrade::class, 'maybe_upgrade' ) );

		$this->oauth->register_hooks();
		$this->settings->register_hooks();
		$this->editor->register_hooks();
		$this->publisher->register_hooks();

		Mediagraph_Ajax::register( $this->repository, $this->media_library, $this->credentials );
		Mediagraph_Legacy_Block::register();

		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( MEDIAGRAPH_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'mediagraph-assets',
			false,
			dirname( plugin_basename( MEDIAGRAPH_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Expose provenance meta to the REST API.
	 *
	 * These keys are underscore-prefixed and therefore protected, so they need
	 * an explicit auth callback. The block editor reads them to tell whether an
	 * attachment came from Mediagraph and should offer the metadata panel.
	 *
	 * Both are read-only from REST: they are written by the importer, and
	 * letting a client rewrite an asset's provenance would corrupt usage
	 * reporting.
	 *
	 * @return void
	 */
	public function register_attachment_meta() {
		$keys = array(
			Mediagraph_Media_Library::META_ASSET_ID,
			Mediagraph_Media_Library::META_GUID,
		);

		foreach ( $keys as $key ) {
			register_post_meta(
				'attachment',
				$key,
				array(
					'type'              => 'string',
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
					// Readable, never writable over REST. The importer sets
					// these directly with update_post_meta, which this does not
					// affect; rewriting an asset's provenance from a client
					// would quietly break usage reporting.
					'auth_callback'     => '__return_false',
				)
			);
		}
	}

	/**
	 * Surface Mediagraph provenance on the attachment edit screen.
	 *
	 * @param array   $form_fields Attachment form fields.
	 * @param WP_Post $post        Attachment post.
	 * @return array
	 */
	public function attachment_fields( $form_fields, $post ) {
		$guid = get_post_meta( $post->ID, '_mediagraph_guid', true );

		if ( empty( $guid ) ) {
			return $form_fields;
		}

		$link = $this->credentials->asset_url( $guid );

		$html = '<input type="text" class="text" readonly="readonly" value="' . esc_attr( $guid ) . '" />';

		if ( $link ) {
			$html .= '<br /><a href="' . esc_url( $link ) . '" target="_blank" rel="noopener noreferrer">' .
				esc_html__( 'Open in Mediagraph', 'mediagraph-assets' ) . '</a>';
		}

		$form_fields['mediagraph_guid'] = array(
			'label' => __( 'Mediagraph GUID', 'mediagraph-assets' ),
			'input' => 'html',
			'html'  => $html,
			'helps' => __( 'This file was imported from Mediagraph.', 'mediagraph-assets' ),
		);

		return $form_fields;
	}

	/**
	 * Add a Settings shortcut on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( Mediagraph_Settings::url() ),
			esc_html__( 'Settings', 'mediagraph-assets' )
		);

		array_unshift( $links, $settings );

		return $links;
	}
}
