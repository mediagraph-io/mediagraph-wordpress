<?php
/**
 * WordPress metadata mapper.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds WordPress taxonomy and SEO context to the article payload.
 */
class Mediagraph_WordPress_Mapper extends Mediagraph_Metadata_Mapper {

	/**
	 * Article fields plus WordPress specifics.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	protected function article_properties( $post ) {
		$article = parent::article_properties( $post );

		$metadata = isset( $article['metadata'] ) && is_array( $article['metadata'] ) ? $article['metadata'] : array();

		$metadata['post_type'] = $post->post_type;
		$metadata['post_id']   = (int) $post->ID;

		$categories = $this->term_names( $post, 'category' );

		if ( ! empty( $categories ) ) {
			$metadata['categories'] = $categories;
		}

		$tags = $this->term_names( $post, 'post_tag' );

		if ( ! empty( $tags ) ) {
			$metadata['tags'] = $tags;
		}

		$comments = wp_count_comments( $post->ID );

		$metadata['comments'] = array(
			'count'   => isset( $comments->approved ) ? (int) $comments->approved : 0,
			'enabled' => comments_open( $post->ID ),
		);

		$inbound = $this->inbound_links( $post );

		if ( ! empty( $inbound ) ) {
			$metadata['inbound_links'] = $inbound;
		}

		$seo = $this->seo_fields( $post );

		if ( ! empty( $seo ) ) {
			$metadata = array_merge( $metadata, $seo );
		}

		$article['metadata'] = $metadata;

		return $article;
	}

	/**
	 * Excerpt fallback for the subhead.
	 *
	 * @param WP_Post $post Post object.
	 * @return string
	 */
	protected function subhead( $post ) {
		$subhead = parent::subhead( $post );

		if ( '' !== $subhead ) {
			return $subhead;
		}

		return has_excerpt( $post->ID ) ? get_the_excerpt( $post->ID ) : '';
	}

	/**
	 * Term names for a taxonomy.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $taxonomy Taxonomy name.
	 * @return array
	 */
	private function term_names( $post, $taxonomy ) {
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return array();
		}

		$terms = get_the_terms( $post->ID, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return array_values( wp_list_pluck( $terms, 'name' ) );
	}

	/**
	 * Trackbacks and pingbacks.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function inbound_links( $post ) {
		$pingbacks = get_comments(
			array(
				'post_id' => $post->ID,
				'type__in' => array( 'pingback', 'trackback' ),
				'status'  => 'approve',
			)
		);

		$links = array();

		foreach ( $pingbacks as $pingback ) {
			$links[] = array(
				'url'   => $pingback->comment_author_url,
				'title' => $pingback->comment_author,
				'date'  => $pingback->comment_date,
			);
		}

		return $links;
	}

	/**
	 * Common SEO plugin fields, when present.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	private function seo_fields( $post ) {
		$map = array(
			'meta_description'   => '_yoast_wpseo_metadesc',
			'focus_keyword'      => '_yoast_wpseo_focuskw',
			'social_title'       => '_yoast_wpseo_opengraph-title',
			'social_description' => '_yoast_wpseo_opengraph-description',
		);

		/**
		 * Filter the post meta keys collected as SEO metadata.
		 *
		 * @param array $map Payload key => post meta key.
		 */
		$map = (array) apply_filters( 'mediagraph_seo_meta_map', $map );

		$fields = array();

		foreach ( $map as $key => $meta_key ) {
			$value = get_post_meta( $post->ID, $meta_key, true );

			if ( ! empty( $value ) && is_string( $value ) ) {
				$fields[ $key ] = $value;
			}
		}

		return $fields;
	}
}
