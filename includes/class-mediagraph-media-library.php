<?php
/**
 * Imports Mediagraph assets into the WordPress media library.
 *
 * This is the pivot of the 2.0 design. An imported asset becomes an ordinary
 * attachment, so core blocks, the media modal, image editing, srcset, and
 * every registered image size work exactly as they do for uploaded files.
 *
 * Imports are deduplicated per (asset, rendition): re-inserting the same asset
 * reuses the existing attachment instead of piling up copies, which is what
 * 1.x did every single time the size control changed.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sideloads assets and tracks their provenance.
 */
class Mediagraph_Media_Library {

	const META_ASSET_ID  = '_mediagraph_asset_id';
	const META_GUID      = '_mediagraph_guid';
	const META_RENDITION = '_mediagraph_rendition';
	const META_IMPORTED  = '_mediagraph_imported_at';
	const META_SOURCE    = '_mediagraph_source';

	/**
	 * Sizes the download endpoint accepts, largest first.
	 *
	 * These are the values Download validates against; "permalink" is the
	 * name of the 1200px *preview*, not a downloadable size, and passing it
	 * would fail validation.
	 *
	 * @var array
	 */
	const RENDITIONS = array( 'original', 'full', 'medium', 'small' );

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
	 * @param Mediagraph_Client     $client     HTTP client.
	 * @param Mediagraph_Repository $repository Repository.
	 */
	public function __construct( Mediagraph_Client $client, Mediagraph_Repository $repository ) {
		$this->client     = $client;
		$this->repository = $repository;
	}

	/**
	 * Import an asset, reusing an existing attachment when possible.
	 *
	 * @param int   $asset_id  Mediagraph asset ID.
	 * @param array $args      { Optional.
	 *     @type string $rendition Requested rendition. Default 'full'.
	 *     @type int    $post_id   Post to attach to.
	 *     @type array  $metadata  Editor overrides for title/caption/alt.
	 *     @type bool   $force     Re-download even if already imported.
	 * }
	 * @return array|WP_Error Attachment payload.
	 */
	public function import( $asset_id, array $args = array() ) {
		$asset_id = (int) $asset_id;

		if ( $asset_id <= 0 ) {
			return new WP_Error( 'mediagraph_bad_asset', __( 'Missing asset ID.', 'mediagraph-assets' ) );
		}

		$args = wp_parse_args(
			$args,
			array(
				'rendition' => 'full',
				'post_id'   => 0,
				'metadata'  => array(),
				'force'     => false,
			)
		);

		$asset = $this->repository->asset( $asset_id );

		if ( is_wp_error( $asset ) ) {
			return $asset;
		}

		if ( empty( $asset['downloadable'] ) ) {
			return new WP_Error(
				'mediagraph_not_downloadable',
				__( 'You do not have permission to download this asset from Mediagraph.', 'mediagraph-assets' )
			);
		}

		$rendition = $this->pick_rendition( $args['rendition'], $asset );

		if ( ! $args['force'] ) {
			$existing = $this->find_existing( $asset_id, $rendition );

			if ( $existing ) {
				$this->apply_metadata( $existing, $asset, $args['metadata'] );

				return $this->payload( $existing, $asset );
			}
		}

		$attachment_id = $this->sideload( $asset, $rendition, (int) $args['post_id'] );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::META_ASSET_ID, (string) $asset_id );
		update_post_meta( $attachment_id, self::META_GUID, $asset['guid'] );
		update_post_meta( $attachment_id, self::META_RENDITION, $rendition );
		update_post_meta( $attachment_id, self::META_IMPORTED, current_time( 'mysql' ) );
		update_post_meta( $attachment_id, self::META_SOURCE, 'mediagraph' );

		$this->apply_metadata( $attachment_id, $asset, $args['metadata'] );

		/**
		 * Fires after a Mediagraph asset is imported into the media library.
		 *
		 * @param int   $attachment_id Attachment post ID.
		 * @param array $asset         Normalized asset payload.
		 * @param string $rendition    Rendition that was downloaded.
		 */
		do_action( 'mediagraph_asset_imported', $attachment_id, $asset, $rendition );

		return $this->payload( $attachment_id, $asset );
	}

	/**
	 * Build the payload the editor needs to populate a core block.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $asset         Normalized asset.
	 * @return array
	 */
	public function payload( $attachment_id, array $asset = array() ) {
		$attachment = get_post( $attachment_id );

		if ( ! $attachment ) {
			return new WP_Error(
				'mediagraph_missing_attachment',
				__( 'The imported file could not be found in the media library.', 'mediagraph-assets' )
			);
		}

		$meta  = wp_get_attachment_metadata( $attachment_id );
		$sizes = array();

		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_name => $size ) {
				$src = wp_get_attachment_image_src( $attachment_id, $size_name );

				if ( $src ) {
					$sizes[ $size_name ] = array(
						'url'    => $src[0],
						'width'  => (int) $src[1],
						'height' => (int) $src[2],
					);
				}
			}
		}

		$full = wp_get_attachment_image_src( $attachment_id, 'full' );

		$sizes['full'] = array(
			'url'    => $full ? $full[0] : wp_get_attachment_url( $attachment_id ),
			'width'  => $full ? (int) $full[1] : 0,
			'height' => $full ? (int) $full[2] : 0,
		);

		return array(
			'id'          => (int) $attachment_id,
			'url'         => wp_get_attachment_url( $attachment_id ),
			'link'        => get_attachment_link( $attachment_id ),
			'mime'        => get_post_mime_type( $attachment_id ),
			'kind'        => $this->kind_from_mime( get_post_mime_type( $attachment_id ) ),
			'title'       => $attachment->post_title,
			'caption'     => $attachment->post_excerpt,
			'description' => $attachment->post_content,
			'alt'         => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'width'       => $sizes['full']['width'],
			'height'      => $sizes['full']['height'],
			'sizes'       => $sizes,
			'rendition'   => (string) get_post_meta( $attachment_id, self::META_RENDITION, true ),
			'asset'       => array(
				'id'          => isset( $asset['id'] ) ? (int) $asset['id'] : (int) get_post_meta( $attachment_id, self::META_ASSET_ID, true ),
				'guid'        => isset( $asset['guid'] ) ? $asset['guid'] : (string) get_post_meta( $attachment_id, self::META_GUID, true ),
				'filename'    => isset( $asset['filename'] ) ? $asset['filename'] : '',
				'kind'        => isset( $asset['kind'] ) ? $asset['kind'] : '',
				'credit_line' => isset( $asset['credit_line'] ) ? $asset['credit_line'] : '',
				'usage_terms' => isset( $asset['usage_terms'] ) ? $asset['usage_terms'] : '',
			),
		);
	}

	/**
	 * Find an already-imported attachment.
	 *
	 * @param int    $asset_id  Mediagraph asset ID.
	 * @param string $rendition Rendition name.
	 * @return int Attachment ID, or 0.
	 */
	private function find_existing( $asset_id, $rendition ) {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_ASSET_ID,
						'value' => (string) $asset_id,
					),
					array(
						'key'   => self::META_RENDITION,
						'value' => $rendition,
					),
				),
			)
		);

		if ( empty( $query->posts ) ) {
			return 0;
		}

		$attachment_id = (int) $query->posts[0];

		// Guard against a stale row whose file was deleted from disk.
		$file = get_attached_file( $attachment_id );

		if ( ! $file || ! file_exists( $file ) ) {
			return 0;
		}

		return $attachment_id;
	}

	/**
	 * Download and sideload the asset.
	 *
	 * @param array  $asset     Normalized asset.
	 * @param string $rendition Rendition name.
	 * @param int    $post_id   Post to attach to.
	 * @return int|WP_Error Attachment ID.
	 */
	private function sideload( array $asset, $rendition, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$temp_file = $this->client->download(
			'api/assets/' . (int) $asset['id'] . '/download',
			array(
				'size' => $rendition,
				'via'  => 'wordpress',
			)
		);

		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		$file_array = array(
			'name'     => $this->filename_for( $asset, $rendition, $temp_file ),
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload(
			$file_array,
			$post_id,
			null,
			array(
				'post_title' => $this->best_title( $asset ),
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $temp_file ) ) {
				wp_delete_file( $temp_file );
			}

			Mediagraph_Logger::error(
				'Sideload failed',
				array(
					'asset_id' => $asset['id'],
					'error'    => $attachment_id->get_error_message(),
				)
			);

			return new WP_Error(
				'mediagraph_sideload_failed',
				sprintf(
					/* translators: %s: underlying WordPress error. */
					__( 'WordPress could not add the file to the media library: %s', 'mediagraph-assets' ),
					$attachment_id->get_error_message()
				)
			);
		}

		return (int) $attachment_id;
	}

	/**
	 * Write editorial metadata onto the attachment.
	 *
	 * Values typed in the picker win; otherwise Mediagraph's own metadata is
	 * used. Existing non-empty values are never silently overwritten by an
	 * empty incoming value.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $asset         Normalized asset.
	 * @param array $overrides     Editor-supplied values.
	 * @return void
	 */
	private function apply_metadata( $attachment_id, array $asset, array $overrides ) {
		$title   = $this->first_non_empty( array( $overrides['title'] ?? '', $this->best_title( $asset ) ) );
		$caption = $this->first_non_empty( array( $overrides['caption'] ?? '', $asset['description'] ?? '' ) );
		$alt     = $this->first_non_empty( array( $overrides['alt_text'] ?? '', $asset['alt_text'] ?? '', $asset['title'] ?? '' ) );
		$credit  = $this->first_non_empty( array( $overrides['credit'] ?? '', $asset['credit_line'] ?? '' ) );

		$update = array( 'ID' => $attachment_id );

		if ( '' !== $title ) {
			$update['post_title'] = $title;
		}

		if ( '' !== $caption ) {
			$update['post_excerpt'] = $caption;
		}

		if ( '' !== $credit ) {
			$update['post_content'] = $credit;
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		if ( '' !== $alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		}
	}

	/**
	 * Choose the rendition to download.
	 *
	 * Callers ask in WordPress terms; this maps onto what the account is
	 * actually allowed to download, so a viewer without original rights
	 * quietly gets the largest preview instead of a hard failure.
	 *
	 * @param string $requested Requested rendition.
	 * @param array  $asset     Normalized asset.
	 * @return string
	 */
	private function pick_rendition( $requested, array $asset ) {
		$available = ! empty( $asset['renditions'] ) && is_array( $asset['renditions'] )
			? $asset['renditions']
			: array();

		$requested = in_array( $requested, self::RENDITIONS, true ) ? $requested : 'full';

		if ( empty( $available ) ) {
			// The detail endpoint did not report renditions; trust the request.
			return $requested;
		}

		if ( in_array( $requested, $available, true ) ) {
			return $requested;
		}

		// Walk down from the requested rendition to the best allowed one.
		$order = self::RENDITIONS;
		$start = array_search( $requested, $order, true );

		for ( $i = (int) $start; $i < count( $order ); $i++ ) {
			if ( in_array( $order[ $i ], $available, true ) ) {
				return $order[ $i ];
			}
		}

		return (string) end( $available );
	}

	/**
	 * Build a filename that matches the bytes we actually downloaded.
	 *
	 * A preview rendition of a TIFF or PSD arrives as JPEG, so the original
	 * extension would make WordPress reject the sideload.
	 *
	 * @param array  $asset     Normalized asset.
	 * @param string $rendition Rendition name.
	 * @param string $temp_file Downloaded file.
	 * @return string
	 */
	private function filename_for( array $asset, $rendition, $temp_file ) {
		$filename = ! empty( $asset['filename'] ) ? $asset['filename'] : 'mediagraph-asset';
		$filename = sanitize_file_name( $filename );
		$basename = pathinfo( $filename, PATHINFO_FILENAME );

		if ( '' === $basename ) {
			$basename = 'mediagraph-asset-' . (int) $asset['id'];
		}

		$mime = $this->detect_mime( $temp_file );

		// Originals keep their own extension; the bytes are the real file.
		if ( 'original' === $rendition && ! $mime ) {
			return $filename;
		}

		$declared = wp_check_filetype( $filename );

		if ( $mime && ! empty( $declared['type'] ) && $declared['type'] === $mime ) {
			return $filename;
		}

		$extension = $mime ? $this->extension_for_mime( $mime ) : '';

		if ( '' === $extension ) {
			return $filename;
		}

		return sanitize_file_name( $basename . '.' . $extension );
	}

	/**
	 * Sniff the MIME type of a downloaded file.
	 *
	 * @param string $file Path.
	 * @return string Empty string when unknown.
	 */
	private function detect_mime( $file ) {
		if ( ! file_exists( $file ) ) {
			return '';
		}

		if ( function_exists( 'wp_get_image_mime' ) ) {
			$mime = wp_get_image_mime( $file );

			if ( $mime ) {
				return $mime;
			}
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $file );

			if ( $mime && 'application/octet-stream' !== $mime ) {
				return $mime;
			}
		}

		return '';
	}

	/**
	 * First WordPress-registered extension for a MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function extension_for_mime( $mime ) {
		foreach ( wp_get_mime_types() as $extensions => $registered ) {
			if ( $registered === $mime ) {
				$parts = explode( '|', $extensions );

				return $parts[0];
			}
		}

		return '';
	}

	/**
	 * Pick the most useful title available.
	 *
	 * @param array $asset Normalized asset.
	 * @return string
	 */
	private function best_title( array $asset ) {
		return $this->first_non_empty(
			array(
				$asset['title'] ?? '',
				$asset['headline'] ?? '',
				$asset['filename'] ?? '',
			)
		);
	}

	/**
	 * First non-empty trimmed string in a list.
	 *
	 * @param array $candidates Candidate values.
	 * @return string
	 */
	private function first_non_empty( array $candidates ) {
		foreach ( $candidates as $candidate ) {
			$candidate = trim( (string) $candidate );

			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Map a MIME type onto the picker's asset kinds.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function kind_from_mime( $mime ) {
		if ( 0 === strpos( (string) $mime, 'image/' ) ) {
			return 'image';
		}

		if ( 0 === strpos( (string) $mime, 'video/' ) ) {
			return 'video';
		}

		if ( 0 === strpos( (string) $mime, 'audio/' ) ) {
			return 'audio';
		}

		return 'document';
	}
}
