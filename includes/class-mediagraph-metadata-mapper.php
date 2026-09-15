<?php
/**
 * Base metadata mapper.
 *
 * Converts a WordPress post plus its Mediagraph usages into the payload the
 * /api/published_assets endpoint expects.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared mapping behaviour.
 */
abstract class Mediagraph_Metadata_Mapper {

	/**
	 * Build the full payload.
	 *
	 * @param WP_Post $post   Post object.
	 * @param array   $assets Usage records.
	 * @return array
	 */
	public function build_publish_payload( $post, array $assets ) {
		$article = array_merge(
			$this->publisher_properties(),
			$this->article_properties( $post )
		);

		$images = array();
		$videos = array();

		foreach ( $assets as $asset ) {
			$record = $this->published_asset_record( $asset, $post );

			if ( ! empty( $asset['is_video'] ) ) {
				$videos[] = $record;
			}

			// published_images is the only collection the API currently
			// processes, so time-based media goes here too. Without this,
			// every video and audio usage is silently dropped.
			$images[] = $record;
		}

		// Nest the article fields under `published_asset` explicitly rather
		// than relying on Rails' ParamsWrapper to do it. The wrapper key is
		// derived from the controller and cached, and under dev-mode class
		// reloading it can come back as the parent controller's key ("api")
		// instead of "published_asset" — which makes the endpoint reject the
		// request with "param is missing: published_asset". Sending the key
		// ourselves is correct either way, because ParamsWrapper skips
		// wrapping when the key is already present.
		$payload = array(
			'published_asset'  => $article,
			'published_images' => $images,
			'published_videos' => $videos,
		);

		/**
		 * Filter the write-back payload before it is sent.
		 *
		 * @param array   $payload Payload.
		 * @param WP_Post $post    Post object.
		 * @param array   $assets  Usage records.
		 */
		return apply_filters( 'mediagraph_publish_payload', $payload, $post, $assets );
	}

	/**
	 * Stable identifier for this WordPress site.
	 *
	 * @return string
	 */
	protected function publisher_uid() {
		return 'wp_' . md5( get_site_url() );
	}

	/**
	 * Stable identifier for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	protected function article_uid( $post ) {
		return $this->publisher_uid() . '_post_' . $post->ID;
	}

	/**
	 * Publisher-level fields.
	 *
	 * @return array
	 */
	protected function publisher_properties() {
		$site_name = get_bloginfo( 'name' );

		return array(
			'publisher_uid'    => $this->publisher_uid(),
			'publisher_name'   => $site_name,
			'publication_name' => $site_name,
			'edition'          => $this->edition(),
		);
	}

	/**
	 * Edition identifier.
	 *
	 * @return string
	 */
	protected function edition() {
		return 'online';
	}

	/**
	 * Article-level fields.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	protected function article_properties( $post ) {
		$author = get_userdata( $post->post_author );

		return array(
			'article_uid'      => $this->article_uid( $post ),
			'article_name'     => get_the_title( $post->ID ),
			'publication_urls' => array( get_permalink( $post->ID ) ),
			'publication_date' => get_the_date( 'c', $post->ID ),
			'headline'         => get_the_title( $post->ID ),
			'subhead'          => $this->subhead( $post ),
			'byline'           => $author ? $author->display_name : '',
			'abstract'         => $this->abstract( $post ),
			'article_text'     => $this->article_text( $post ),
			'metadata'         => array(),
		);
	}

	/**
	 * Subhead, if the site stores one.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	protected function subhead( $post ) {
		foreach ( array( 'subheading', 'subtitle', 'deck' ) as $key ) {
			$value = get_post_meta( $post->ID, $key, true );

			if ( ! empty( $value ) && is_string( $value ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Short summary.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	protected function abstract( $post ) {
		if ( has_excerpt( $post->ID ) ) {
			return get_the_excerpt( $post->ID );
		}

		return wp_trim_words( wp_strip_all_tags( $post->post_content ), 55, '…' );
	}

	/**
	 * Plain-text article body.
	 *
	 * Capped so a very long post cannot produce a multi-megabyte request.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	protected function article_text( $post ) {
		/**
		 * Filter the maximum article body length sent to Mediagraph.
		 *
		 * Zero or less omits the body entirely, which is the opt-out for a site
		 * that does not want post text leaving WordPress at all. To send a very
		 * long body, raise the number rather than clearing it.
		 *
		 * @param int $limit Characters. Default 100000.
		 */
		$limit = (int) apply_filters( 'mediagraph_article_text_limit', 100000 );

		if ( $limit <= 0 ) {
			return '';
		}

		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > $limit ) {
			$text = mb_substr( $text, 0, $limit ) . '…';
		}

		return $text;
	}

	/**
	 * One published-asset record.
	 *
	 * @param array   $asset Usage record.
	 * @param WP_Post $post  Post object.
	 * @return array
	 */
	protected function published_asset_record( array $asset, $post ) {
		return array(
			'asset_guid'   => isset( $asset['guid'] ) ? (string) $asset['guid'] : '',
			'published_in' => $this->article_uid( $post ),
			'url'          => isset( $asset['url'] ) ? (string) $asset['url'] : '',
			'filename'     => isset( $asset['filename'] ) ? (string) $asset['filename'] : '',
			'cdn_link'     => isset( $asset['url'] ) ? (string) $asset['url'] : '',
			'usage_type'   => $this->usage_type( $asset ),
			'credit_line'  => isset( $asset['credit'] ) ? (string) $asset['credit'] : '',
			'caption'      => isset( $asset['caption'] ) ? (string) $asset['caption'] : '',
			'alt_text'     => isset( $asset['alt_text'] ) ? (string) $asset['alt_text'] : '',
			'restrictions' => isset( $asset['restrictions'] ) ? (string) $asset['restrictions'] : '',
			'metadata'     => $this->record_metadata( $asset ),
		);
	}

	/**
	 * Extra per-asset metadata.
	 *
	 * @param array $asset Usage record.
	 * @return array
	 */
	protected function record_metadata( array $asset ) {
		$metadata = array();

		foreach ( array( 'title', 'caption', 'alt_text' ) as $key ) {
			if ( ! empty( $asset[ $key ] ) ) {
				$metadata[ $key ] = (string) $asset[ $key ];
			}
		}

		if ( ! empty( $asset['attachment_id'] ) ) {
			$metadata['wordpress_attachment_id'] = (int) $asset['attachment_id'];
		}

		return $metadata;
	}

	/**
	 * Usage type for a record.
	 *
	 * @param array $asset Usage record.
	 * @return string
	 */
	protected function usage_type( array $asset ) {
		if ( ! empty( $asset['usage_type'] ) && 'body_photo' !== $asset['usage_type'] ) {
			return (string) $asset['usage_type'];
		}

		return ! empty( $asset['is_video'] ) ? 'body_video' : 'body_photo';
	}
}
