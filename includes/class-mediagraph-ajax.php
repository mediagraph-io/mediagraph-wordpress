<?php
/**
 * AJAX endpoints for the picker.
 *
 * Every handler verifies the nonce *and* a capability. A nonce only proves the
 * request came from our own UI; it says nothing about whether the user is
 * allowed to browse a media library or write files to the server.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the picker's AJAX routes.
 */
class Mediagraph_Ajax {

	const NONCE = 'mediagraph_picker';

	/**
	 * Repository.
	 *
	 * @var Mediagraph_Repository
	 */
	private $repository;

	/**
	 * Media library importer.
	 *
	 * @var Mediagraph_Media_Library
	 */
	private $media_library;

	/**
	 * Credential store.
	 *
	 * @var Mediagraph_Credentials
	 */
	private $credentials;

	/**
	 * Wire up the routes.
	 *
	 * @param Mediagraph_Repository    $repository    Repository.
	 * @param Mediagraph_Media_Library $media_library Importer.
	 * @param Mediagraph_Credentials   $credentials   Credential store.
	 * @return void
	 */
	public static function register( Mediagraph_Repository $repository, Mediagraph_Media_Library $media_library, Mediagraph_Credentials $credentials ) {
		$instance = new self( $repository, $media_library, $credentials );

		$routes = array(
			'containers'         => 'containers',
			'container_children' => 'container_children',
			'search'             => 'search',
			'asset'              => 'asset',
			'custom_fields'      => 'custom_fields',
			'creators'           => 'creators',
			'import'             => 'import',
			'import_batch'       => 'import_batch',
			'status'             => 'status',
		);

		foreach ( $routes as $action => $method ) {
			add_action( 'wp_ajax_mediagraph_' . $action, array( $instance, 'handle_' . $method ) );
		}
	}

	/**
	 * Constructor.
	 *
	 * @param Mediagraph_Repository    $repository    Repository.
	 * @param Mediagraph_Media_Library $media_library Importer.
	 * @param Mediagraph_Credentials   $credentials   Credential store.
	 */
	private function __construct( Mediagraph_Repository $repository, Mediagraph_Media_Library $media_library, Mediagraph_Credentials $credentials ) {
		$this->repository    = $repository;
		$this->media_library = $media_library;
		$this->credentials   = $credentials;
	}

	/**
	 * Connection health, so the picker can explain itself instead of showing
	 * an empty grid.
	 *
	 * @return void
	 */
	public function handle_status() {
		$this->guard( 'read' );

		wp_send_json_success(
			array(
				'connected'    => $this->credentials->is_connected(),
				'expired'      => $this->credentials->is_expired(),
				'organization' => $this->credentials->organization_name(),
				'settingsUrl'  => Mediagraph_Settings::url(),
			)
		);
	}

	/**
	 * Root containers.
	 *
	 * @return void
	 */
	public function handle_containers() {
		$this->guard();

		$force = isset( $_POST['refresh'] ) && '1' === $_POST['refresh']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.

		wp_send_json_success( $this->repository->containers( $force ) );
	}

	/**
	 * Children of one container.
	 *
	 * @return void
	 */
	public function handle_container_children() {
		$this->guard();

		$parent_id = $this->post_int( 'parent_id' );
		$type      = $this->post_text( 'parent_type' );
		$sub_type  = $this->post_text( 'sub_type' );

		if ( $parent_id <= 0 || '' === $type ) {
			$this->fail( __( 'A container ID and type are required.', 'mediagraph-assets' ) );
		}

		$children = $this->repository->container_children( $parent_id, $type, $sub_type );

		$this->respond( $children );
	}

	/**
	 * Asset search.
	 *
	 * @return void
	 */
	public function handle_search() {
		$this->guard();

		$results = $this->repository->search(
			array(
				'q'            => $this->post_text( 'q' ),
				'container_id' => $this->post_int( 'container_id' ),
				'container'    => $this->post_text( 'container' ),
				'sub_type'     => $this->post_text( 'sub_type' ),
				'types'        => $this->post_types(),
				'sort'         => $this->post_text( 'sort', 'created_at' ),
				'order'        => $this->post_text( 'order', 'desc' ),
				'show_all'     => '1' === $this->post_text( 'show_all' ),
				'custom_meta'  => $this->post_custom_meta(),
				'rights'       => $this->post_rights(),
				'creator_id'   => $this->post_int( 'creator_id' ),
				'date_from'    => $this->post_text( 'date_from' ),
				'date_to'      => $this->post_text( 'date_to' ),
				'page'         => max( 1, $this->post_int( 'page' ) ),
				'per_page'     => $this->post_int( 'per_page' ) ?: 40,
			)
		);

		$this->respond( $results );
	}

	/**
	 * Single asset detail.
	 *
	 * @return void
	 */
	public function handle_asset() {
		$this->guard();

		$asset_id = $this->post_int( 'asset_id' );

		if ( $asset_id <= 0 ) {
			$this->fail( __( 'An asset ID is required.', 'mediagraph-assets' ) );
		}

		$this->respond( $this->repository->asset( $asset_id ) );
	}

	/**
	 * Filterable custom metadata fields.
	 *
	 * @return void
	 */
	public function handle_custom_fields() {
		$this->guard();

		wp_send_json_success( array( 'fields' => $this->repository->filterable_custom_fields() ) );
	}

	/**
	 * Creators available for filtering.
	 *
	 * @return void
	 */
	public function handle_creators() {
		$this->guard();

		wp_send_json_success( array( 'creators' => $this->repository->creators() ) );
	}

	/**
	 * Import one asset into the media library.
	 *
	 * @return void
	 */
	public function handle_import() {
		$this->guard( 'upload_files' );

		$asset_id = $this->post_int( 'asset_id' );

		if ( $asset_id <= 0 ) {
			$this->fail( __( 'An asset ID is required.', 'mediagraph-assets' ) );
		}

		$result = $this->media_library->import( $asset_id, $this->import_args() );

		$this->respond( $result );
	}

	/**
	 * Import several assets at once (gallery insertion).
	 *
	 * Partial success is reported explicitly rather than failing the whole
	 * batch, so one restricted asset cannot cost the editor the other nine.
	 *
	 * @return void
	 */
	public function handle_import_batch() {
		$this->guard( 'upload_files' );

		$raw = isset( $_POST['asset_ids'] ) ? wp_unslash( $_POST['asset_ids'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidationSanitization.InputNotSanitized -- decoded and cast below.
		$ids = json_decode( (string) $raw, true );

		if ( ! is_array( $ids ) ) {
			$this->fail( __( 'No assets were selected.', 'mediagraph-assets' ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		/**
		 * Filter the maximum number of assets importable in one batch.
		 *
		 * @param int $limit Default 50.
		 */
		$limit = (int) apply_filters( 'mediagraph_batch_import_limit', 50 );

		if ( count( $ids ) > $limit ) {
			$this->fail(
				sprintf(
					/* translators: %d: maximum number of assets. */
					__( 'Select %d assets or fewer at a time.', 'mediagraph-assets' ),
					$limit
				)
			);
		}

		$args      = $this->import_args();
		$per_asset = $this->post_metadata_map();
		$imported  = array();
		$failures  = array();
		$remaining = array();

		// Each import downloads a full-size original and generates every
		// registered thumbnail size, so a large selection can outrun PHP's
		// execution limit. Work until the budget is spent, then hand the rest
		// back for the caller to request in another round rather than dying
		// mid-batch with no result at all.
		$started = microtime( true );
		$budget  = $this->time_budget();

		foreach ( array_values( $ids ) as $index => $asset_id ) {
			if ( $index > 0 && ( microtime( true ) - $started ) >= $budget ) {
				$remaining = array_slice( array_values( $ids ), $index );
				break;
			}

			// Edits made in the details panel are keyed by asset, so annotating
			// one picture in a multi-select is not lost when the batch runs.
			$args['metadata'] = isset( $per_asset[ $asset_id ] ) ? $per_asset[ $asset_id ] : array();

			$result = $this->media_library->import( $asset_id, $args );

			if ( is_wp_error( $result ) ) {
				$failures[] = array(
					'asset_id' => $asset_id,
					'message'  => $result->get_error_message(),
				);
				continue;
			}

			$imported[] = $result;
		}

		// Only a hard failure when nothing at all landed and nothing is left to
		// try. A spent budget with work outstanding is a normal partial result.
		if ( empty( $imported ) && empty( $remaining ) ) {
			$this->fail(
				! empty( $failures )
					? $failures[0]['message']
					: __( 'None of the selected assets could be imported.', 'mediagraph-assets' )
			);
		}

		wp_send_json_success(
			array(
				'attachments' => $imported,
				'failures'    => $failures,
				'remaining'   => array_values( $remaining ),
			)
		);
	}

	/**
	 * Seconds to spend importing before handing the rest back.
	 *
	 * Deliberately a fraction of PHP's limit: the response still has to be
	 * assembled and sent, and hosts often enforce a shorter web-server timeout
	 * than max_execution_time. A limit of 0 means unlimited, which is common on
	 * CLI but not something to trust in a web request.
	 *
	 * @return float
	 */
	private function time_budget() {
		$limit = (int) ini_get( 'max_execution_time' );

		if ( $limit <= 0 ) {
			$limit = 30;
		}

		/**
		 * Filter the seconds spent per batch-import request.
		 *
		 * @param float $budget Seconds.
		 * @param int   $limit  PHP's max_execution_time.
		 */
		return (float) apply_filters( 'mediagraph_batch_time_budget', max( 5, $limit * 0.6 ), $limit );
	}

	/**
	 * Editorial fields the picker may set on an import.
	 *
	 * @var array
	 */
	const METADATA_FIELDS = array( 'title', 'caption', 'alt_text', 'credit' );

	/**
	 * Sanitize one set of editorial fields.
	 *
	 * @param mixed $raw Decoded field set.
	 * @return array
	 */
	private function clean_metadata( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::METADATA_FIELDS as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) ) {
				$clean[ $key ] = sanitize_textarea_field( $raw[ $key ] );
			}
		}

		return $clean;
	}

	/**
	 * Per-asset metadata for a batch import, keyed by asset ID.
	 *
	 * @return array
	 */
	private function post_metadata_map() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.
		$raw = isset( $_POST['metadata'] ) ? wp_unslash( $_POST['metadata'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized below.

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$map = array();

		foreach ( $decoded as $asset_id => $fields ) {
			$asset_id = (int) $asset_id;

			if ( $asset_id <= 0 ) {
				continue;
			}

			$clean = $this->clean_metadata( $fields );

			if ( ! empty( $clean ) ) {
				$map[ $asset_id ] = $clean;
			}
		}

		return $map;
	}

	/**
	 * Shared import arguments from the request.
	 *
	 * @return array
	 */
	private function import_args() {
		$raw = isset( $_POST['metadata'] ) ? wp_unslash( $_POST['metadata'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized below.

		$metadata = $this->clean_metadata( json_decode( (string) $raw, true ) );

		$post_id = $this->post_int( 'post_id' );

		// Only attach to a post the user may actually edit.
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			$post_id = 0;
		}

		return array(
			'rendition' => $this->post_text( 'rendition', 'full' ),
			'post_id'   => $post_id,
			'metadata'  => $metadata,
			'force'     => '1' === $this->post_text( 'force' ),
		);
	}

	/**
	 * Verify nonce and capability.
	 *
	 * @param string $capability Required capability.
	 * @return void
	 */
	private function guard( $capability = 'edit_posts' ) {
		check_ajax_referer( self::NONCE, 'nonce' );

		/**
		 * Filter the capability required to use the Mediagraph picker.
		 *
		 * @param string $capability Capability name.
		 */
		$capability = (string) apply_filters( 'mediagraph_required_capability', $capability );

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You do not have permission to use Mediagraph here.', 'mediagraph-assets' ) ),
				403
			);
		}
	}

	/**
	 * Send a repository result, translating WP_Error into a JSON failure.
	 *
	 * @param mixed $result Result or error.
	 * @return void
	 */
	private function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$status = (int) $result->get_error_data( 'status' );

			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'code'    => $result->get_error_code(),
				),
				$status >= 400 && $status < 600 ? $status : 400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Send a failure with a message.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private function fail( $message ) {
		wp_send_json_error( array( 'message' => $message ), 400 );
	}

	/**
	 * Read a sanitized text field from the request.
	 *
	 * @param string $key     Field name.
	 * @param string $default Fallback.
	 * @return string
	 */
	private function post_text( $key, $default = '' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.
		if ( ! isset( $_POST[ $key ] ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );

		return '' !== $value ? $value : $default;
	}

	/**
	 * Read an integer from the request.
	 *
	 * @param string $key Field name.
	 * @return int
	 */
	private function post_int( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.
		return isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : 0;
	}

	/**
	 * Read the requested asset kinds.
	 *
	 * @return array
	 */
	private function post_types() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.
		$raw = isset( $_POST['types'] ) ? wp_unslash( $_POST['types'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- validated below.

		$types = json_decode( (string) $raw, true );

		if ( ! is_array( $types ) ) {
			return array();
		}

		// Validated against every kind the normalizer produces, not just the
		// server-filterable ones — Text has no API filter value but is still a
		// legitimate thing to ask for.
		return array_values( array_intersect( array_map( 'strval', $types ), Mediagraph_Repository::KINDS ) );
	}

	/**
	 * Read the requested rights classes.
	 *
	 * @return array
	 */
	private function post_rights() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.
		$raw = isset( $_POST['rights'] ) ? wp_unslash( $_POST['rights'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- validated below.

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array_values( array_intersect(
			array_map( 'strval', $decoded ),
			array_keys( Mediagraph_Repository::rights_classes() )
		) );
	}

	/**
	 * Read the custom metadata filter map.
	 *
	 * @return array
	 */
	private function post_custom_meta() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() verified.
		$raw = isset( $_POST['custom_meta'] ) ? wp_unslash( $_POST['custom_meta'] ) : ''; // phpcs:ignore WordPress.Security.ValidationSanitization.InputNotSanitized -- sanitized below.

		$decoded = json_decode( (string) $raw, true );

		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$clean = array();

		foreach ( $decoded as $name => $value ) {
			if ( ! is_string( $name ) || ! is_scalar( $value ) || '' === $value ) {
				continue;
			}

			$clean[ sanitize_text_field( $name ) ] = sanitize_text_field( (string) $value );
		}

		return $clean;
	}
}
