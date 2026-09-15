<?php
/**
 * Works out which Mediagraph assets a post actually uses.
 *
 * 1.x tracked this in a browser global that was pushed to post meta by a
 * separate AJAX call racing the real save. That produced three failure modes:
 * the write-back read the previous save's list, deleting a block never removed
 * the asset, and reloading the editor lost everything not yet saved.
 *
 * 2.0 derives the list from the saved content on the server. It cannot race,
 * removals propagate for free, and there is nothing to keep in sync.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans post content for Mediagraph-sourced media.
 */
class Mediagraph_Usage_Tracker {

	/**
	 * Block attributes that hold a single attachment ID.
	 *
	 * @var array
	 */
	const ID_ATTRIBUTES = array( 'id', 'mediaId', 'mediaID' );

	/**
	 * Collect every Mediagraph asset used by a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array List of usage records keyed by Mediagraph asset ID.
	 */
	public function assets_for( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return array();
		}

		$attachment_ids = $this->attachment_ids( $post );
		$featured_id    = (int) get_post_thumbnail_id( $post->ID );

		if ( $featured_id > 0 ) {
			$attachment_ids[] = $featured_id;
		}

		$attachment_ids = array_values( array_unique( array_filter( array_map( 'intval', $attachment_ids ) ) ) );

		$usages = array();

		foreach ( $attachment_ids as $attachment_id ) {
			$asset_id = (int) get_post_meta( $attachment_id, Mediagraph_Media_Library::META_ASSET_ID, true );

			if ( $asset_id <= 0 ) {
				continue;
			}

			$guid = (string) get_post_meta( $attachment_id, Mediagraph_Media_Library::META_GUID, true );

			if ( '' === $guid ) {
				// Without a GUID the API cannot match the asset, so reporting
				// it would be a guaranteed silent skip.
				continue;
			}

			$mime       = (string) get_post_mime_type( $attachment_id );
			$usage_type = $attachment_id === $featured_id ? 'lead_photo' : 'body_photo';

			// One Mediagraph asset can appear in a post more than once — most
			// often as both the featured image and a body image, sometimes via
			// two attachments of different renditions. The API keys published
			// images by asset, so we report one record per asset. Being the
			// lead photo is the more significant role, so never let a later
			// body usage downgrade it.
			if ( isset( $usages[ $asset_id ] ) && 'lead_photo' === $usages[ $asset_id ]['usage_type'] ) {
				continue;
			}

			$usages[ $asset_id ] = array(
				'asset_id'      => $asset_id,
				'guid'          => $guid,
				'attachment_id' => $attachment_id,
				'filename'      => wp_basename( (string) get_attached_file( $attachment_id ) ),
				'url'           => (string) wp_get_attachment_url( $attachment_id ),
				'mime_type'     => $mime,
				'is_video'      => 0 === strpos( $mime, 'video/' ) || 0 === strpos( $mime, 'audio/' ),
				'usage_type'    => $usage_type,
				'title'         => get_the_title( $attachment_id ),
				'caption'       => (string) wp_get_attachment_caption( $attachment_id ),
				'alt_text'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				'credit'        => (string) get_post_field( 'post_content', $attachment_id ),
			);
		}

		// Assets still embedded as 1.x blocks, which have no attachment behind
		// them. Reported so upgrading a site does not drop usage that was
		// being reported before.
		foreach ( $this->legacy_block_assets( $post ) as $asset_id => $usage ) {
			if ( ! isset( $usages[ $asset_id ] ) ) {
				$usages[ $asset_id ] = $usage;
			}
		}

		/**
		 * Filter the Mediagraph assets reported as used by a post.
		 *
		 * @param array   $usages Usage records.
		 * @param WP_Post $post   Post object.
		 */
		return apply_filters( 'mediagraph_post_assets', array_values( $usages ), $post );
	}

	/**
	 * Every attachment ID referenced by the post content.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function attachment_ids( $post ) {
		$ids = array();

		if ( function_exists( 'parse_blocks' ) && has_blocks( $post->post_content ) ) {
			$ids = $this->walk_blocks( parse_blocks( $post->post_content ) );
		}

		// Classic content, and block content whose markup carries the class
		// but not a parsed attribute.
		if ( preg_match_all( '/wp-image-(\d+)/', (string) $post->post_content, $matches ) ) {
			$ids = array_merge( $ids, $matches[1] );
		}

		return $ids;
	}

	/**
	 * Recursively collect attachment IDs from parsed blocks.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private function walk_blocks( array $blocks ) {
		$ids = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();

			foreach ( self::ID_ATTRIBUTES as $key ) {
				if ( ! empty( $attributes[ $key ] ) && is_numeric( $attributes[ $key ] ) ) {
					$ids[] = (int) $attributes[ $key ];
				}
			}

			// Legacy galleries store an ids array.
			if ( ! empty( $attributes['ids'] ) && is_array( $attributes['ids'] ) ) {
				foreach ( $attributes['ids'] as $id ) {
					if ( is_numeric( $id ) ) {
						$ids[] = (int) $id;
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, $this->walk_blocks( $block['innerBlocks'] ) );
			}
		}

		return $ids;
	}

	/**
	 * Assets embedded via the legacy mediagraph/asset-picker block.
	 *
	 * @param WP_Post $post Post object.
	 * @return array Keyed by asset ID.
	 */
	private function legacy_block_assets( $post ) {
		if ( ! function_exists( 'parse_blocks' ) || ! has_blocks( $post->post_content ) ) {
			return array();
		}

		$found = array();

		$walk = function ( array $blocks ) use ( &$walk, &$found ) {
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}

				if ( isset( $block['blockName'] ) && Mediagraph_Legacy_Block::NAME === $block['blockName'] ) {
					$attributes = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
					$asset_id   = isset( $attributes['assetId'] ) ? (int) $attributes['assetId'] : 0;
					$guid       = isset( $attributes['assetGuid'] ) ? (string) $attributes['assetGuid'] : '';

					if ( $asset_id > 0 && '' !== $guid ) {
						$found[ $asset_id ] = array(
							'asset_id'      => $asset_id,
							'guid'          => $guid,
							'attachment_id' => isset( $attributes['attachmentId'] ) ? (int) $attributes['attachmentId'] : 0,
							'filename'      => isset( $attributes['assetTitle'] ) ? (string) $attributes['assetTitle'] : '',
							'url'           => isset( $attributes['assetUrl'] ) ? (string) $attributes['assetUrl'] : '',
							'mime_type'     => '',
							'is_video'      => in_array( isset( $attributes['assetType'] ) ? $attributes['assetType'] : '', array( 'video', 'audio' ), true ),
							'usage_type'    => 'body_photo',
							'title'         => isset( $attributes['title'] ) ? (string) $attributes['title'] : '',
							'caption'       => isset( $attributes['description'] ) ? (string) $attributes['description'] : '',
							'alt_text'      => isset( $attributes['altText'] ) ? (string) $attributes['altText'] : '',
							'credit'        => isset( $attributes['byline'] ) ? (string) $attributes['byline'] : '',
						);
					}
				}

				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};

		$walk( parse_blocks( $post->post_content ) );

		return $found;
	}
}
