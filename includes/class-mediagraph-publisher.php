<?php
/**
 * Sends published-asset metadata back to Mediagraph.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Write-back on publish.
 */
class Mediagraph_Publisher {

	const RESULT_META = '_mediagraph_writeback';

	/**
	 * HTTP client.
	 *
	 * @var Mediagraph_Client
	 */
	private $client;

	/**
	 * Credential store.
	 *
	 * @var Mediagraph_Credentials
	 */
	private $credentials;

	/**
	 * Usage tracker.
	 *
	 * @var Mediagraph_Usage_Tracker
	 */
	private $tracker;

	/**
	 * Constructor.
	 *
	 * @param Mediagraph_Client      $client      HTTP client.
	 * @param Mediagraph_Credentials $credentials Credential store.
	 */
	public function __construct( Mediagraph_Client $client, Mediagraph_Credentials $credentials ) {
		$this->client      = $client;
		$this->credentials = $credentials;
		$this->tracker     = new Mediagraph_Usage_Tracker();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_after_insert_post', array( $this, 'maybe_publish' ), 20, 4 );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	/**
	 * Post types eligible for write-back.
	 *
	 * @return array
	 */
	public function post_types() {
		/**
		 * Filter which post types report usage back to Mediagraph.
		 *
		 * 1.x hard-coded post and page, so custom post types silently never
		 * reported even though the picker worked in them.
		 *
		 * @param array $post_types Post type names.
		 */
		return (array) apply_filters( 'mediagraph_publishable_post_types', array( 'post', 'page' ) );
	}

	/**
	 * Send metadata when a post is published or updated.
	 *
	 * @param int          $post_id     Post ID.
	 * @param WP_Post      $post        Post object.
	 * @param bool         $update      Whether this is an update.
	 * @param WP_Post|null $post_before Previous post state.
	 * @return void
	 */
	public function maybe_publish( $post_id, $post, $update, $post_before ) {
		unset( $update, $post_before );

		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, $this->post_types(), true ) ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $this->credentials->is_connected() ) {
			return;
		}

		$this->publish( $post );
	}

	/**
	 * Build and send the payload.
	 *
	 * @param WP_Post $post Post object.
	 * @return array|WP_Error Result summary.
	 */
	public function publish( $post ) {
		$assets = $this->tracker->assets_for( $post );

		if ( empty( $assets ) ) {
			delete_post_meta( $post->ID, self::RESULT_META );

			return array(
				'sent'    => 0,
				'skipped' => 0,
			);
		}

		$mapper  = $this->mapper();
		$payload = $mapper->build_publish_payload( $post, $assets );

		$response = $this->client->post( 'api/published_assets', $payload );

		if ( is_wp_error( $response ) ) {
			$this->record( $post->ID, array(
				'status'  => 'error',
				'message' => $response->get_error_message(),
				'sent'    => count( $assets ),
				'skipped' => 0,
				'time'    => time(),
			) );

			Mediagraph_Logger::error(
				'Write-back failed',
				array(
					'post_id' => $post->ID,
					'error'   => $response->get_error_message(),
				)
			);

			return $response;
		}

		// The API answers 200 even when it could not match some assets, and
		// says why in skipped_images. 1.x threw that away and always claimed
		// success.
		$skipped = isset( $response['skipped_images'] ) && is_array( $response['skipped_images'] )
			? $response['skipped_images']
			: array();

		$result = array(
			'status'  => empty( $skipped ) ? 'success' : 'partial',
			'sent'    => count( $assets ),
			'skipped' => count( $skipped ),
			'reasons' => $this->summarize_skips( $skipped ),
			'time'    => time(),
		);

		$this->record( $post->ID, $result );

		if ( ! empty( $skipped ) ) {
			Mediagraph_Logger::error(
				'Write-back skipped assets',
				array(
					'post_id' => $post->ID,
					'skipped' => $skipped,
				)
			);
		}

		/**
		 * Fires after usage has been reported to Mediagraph.
		 *
		 * @param array   $result Result summary.
		 * @param WP_Post $post   Post object.
		 */
		do_action( 'mediagraph_usage_reported', $result, $post );

		return $result;
	}

	/**
	 * Show the outcome of the last write-back on the post edit screen.
	 *
	 * 1.x registered an admin notice from inside wp_after_insert_post, which
	 * fires during the REST save; it could never render. Storing the result and
	 * rendering it on the next screen load actually reaches the user.
	 *
	 * @return void
	 */
	public function render_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || 'post' !== $screen->base ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display.

		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$result = get_post_meta( $post_id, self::RESULT_META, true );

		if ( ! is_array( $result ) || empty( $result['status'] ) ) {
			return;
		}

		delete_post_meta( $post_id, self::RESULT_META );

		if ( 'success' === $result['status'] ) {
			$class   = 'notice-success';
			$message = sprintf(
				/* translators: %d: number of assets. */
				_n(
					'Reported %d Mediagraph asset used in this post.',
					'Reported %d Mediagraph assets used in this post.',
					(int) $result['sent'],
					'mediagraph-assets'
				),
				(int) $result['sent']
			);
		} elseif ( 'partial' === $result['status'] ) {
			$class   = 'notice-warning';
			$message = sprintf(
				/* translators: 1: skipped count, 2: total count, 3: reasons. */
				__( 'Mediagraph could not record %1$d of %2$d assets used in this post. %3$s', 'mediagraph-assets' ),
				(int) $result['skipped'],
				(int) $result['sent'],
				isset( $result['reasons'] ) ? (string) $result['reasons'] : ''
			);
		} else {
			$class   = 'notice-error';
			$message = sprintf(
				/* translators: %s: error message. */
				__( 'Mediagraph usage reporting failed: %s', 'mediagraph-assets' ),
				isset( $result['message'] ) ? (string) $result['message'] : ''
			);
		}

		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	/**
	 * Store the last result for the notice.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $result  Result summary.
	 * @return void
	 */
	private function record( $post_id, array $result ) {
		update_post_meta( $post_id, self::RESULT_META, $result );
	}

	/**
	 * Turn skip records into one readable sentence.
	 *
	 * @param array $skipped Skip records from the API.
	 * @return string
	 */
	private function summarize_skips( array $skipped ) {
		$reasons = array();

		foreach ( $skipped as $skip ) {
			if ( is_array( $skip ) && ! empty( $skip['reason'] ) ) {
				$reasons[] = (string) $skip['reason'];
			}
		}

		if ( empty( $reasons ) ) {
			return '';
		}

		$counts = array_count_values( $reasons );
		$parts  = array();

		foreach ( $counts as $reason => $count ) {
			$parts[] = sprintf( '%s (%d)', $reason, $count );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Metadata mapper for the configured platform.
	 *
	 * @return Mediagraph_Metadata_Mapper
	 */
	private function mapper() {
		$platform = $this->credentials->platform();

		/**
		 * Filter the metadata mapper used for write-back.
		 *
		 * @param Mediagraph_Metadata_Mapper|null $mapper   Mapper instance.
		 * @param string                          $platform Configured platform.
		 */
		$mapper = apply_filters( 'mediagraph_metadata_mapper', null, $platform );

		if ( $mapper instanceof Mediagraph_Metadata_Mapper ) {
			return $mapper;
		}

		return new Mediagraph_WordPress_Mapper();
	}
}
