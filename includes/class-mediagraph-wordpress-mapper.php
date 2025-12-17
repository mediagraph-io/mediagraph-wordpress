<?php
/**
 * Mediagraph WordPress Metadata Mapper
 *
 * WordPress-specific implementation of metadata mapper.
 * Handles standard WordPress post and media metadata.
 *
 * @package MediagraphPicker
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WordPress metadata mapper class
 */
class Mediagraph_WordPress_Mapper extends Mediagraph_Metadata_Mapper {

    /**
     * Build publish metadata payload
     *
     * @param WP_Post $post              Post object
     * @param array   $mediagraph_assets Assets used in post
     * @return array Metadata payload
     */
    public function build_publish_payload( $post, $mediagraph_assets ) {
        // Get publisher properties
        $publisher = $this->get_publisher_properties();

        // Get article properties
        $article = $this->get_article_properties( $post );

        // Process published images and videos
        $published_images = array();
        $published_videos = array();

        foreach ( $mediagraph_assets as $asset ) {
            $usage_type = $this->determine_usage_type( $asset, $post );

            // Check if it's an image or video
            $is_video = isset( $asset['asset_type'] ) && in_array( $asset['asset_type'], array( 'video', 'audio' ), true );

            if ( $is_video ) {
                $published_videos[] = $this->build_published_video( $asset, $post, $usage_type );
            } else {
                $published_images[] = $this->build_published_image( $asset, $post, $usage_type );
            }
        }

        $article['published_images'] = $published_images;
        $article['published_videos'] = $published_videos;

        // Add lead photo if specified
        $article['lead_photo'] = $this->get_lead_photo( $post, $published_images );

        // Build complete payload
        return array_merge( $publisher, $article );
    }

    /**
     * Get lead photo from published images
     *
     * @param WP_Post $post             Post object
     * @param array   $published_images Published images array
     * @return array|null Lead photo data or null
     */
    private function get_lead_photo( $post, $published_images ) {
        // Find the lead photo from published images
        foreach ( $published_images as $image ) {
            if ( isset( $image['usage_type'] ) && 'lead_photo' === $image['usage_type'] ) {
                return $image;
            }
        }

        return null;
    }

    /**
     * Get article properties with WordPress-specific enhancements
     *
     * @param WP_Post $post Post object
     * @return array Article data
     */
    protected function get_article_properties( $post ) {
        $article = parent::get_article_properties( $post );

        // Get WordPress-specific data
        $categories = $this->get_categories( $post );
        $tags = $this->get_tags( $post );

        // Add WordPress-specific fields to article metadata (JSON field)
        // These go in metadata since they're not standard published_asset columns
        $article_metadata = isset( $article['metadata'] ) ? $article['metadata'] : array();

        // Add categories to metadata
        if ( ! empty( $categories ) ) {
            $article_metadata['categories'] = $categories;
        }

        // Add tags to metadata
        if ( ! empty( $tags ) ) {
            $article_metadata['tags'] = $tags;
        }

        // Add comments info to metadata
        $comments = $this->get_comments_data( $post );
        if ( ! empty( $comments ) ) {
            $article_metadata['comments'] = $comments;
        }

        // Add inbound links to metadata
        $inbound_links = $this->get_inbound_links( $post );
        if ( ! empty( $inbound_links ) ) {
            $article_metadata['inbound_links'] = $inbound_links;
        }

        // Add custom fields to metadata if they exist
        $article_metadata = $this->add_custom_fields_to_metadata( $article_metadata, $post );

        $article['metadata'] = $article_metadata;

        return $article;
    }

    /**
     * Add custom fields to metadata array
     *
     * @param array   $metadata Metadata array
     * @param WP_Post $post     Post object
     * @return array Updated metadata
     */
    private function add_custom_fields_to_metadata( $metadata, $post ) {
        // Look for common SEO and editorial meta fields
        $meta_fields = array(
            'meta_description'   => '_yoast_wpseo_metadesc', // Yoast SEO
            'focus_keyword'      => '_yoast_wpseo_focuskw',
            'social_title'       => '_yoast_wpseo_opengraph-title',
            'social_description' => '_yoast_wpseo_opengraph-description',
        );

        foreach ( $meta_fields as $key => $meta_key ) {
            $value = get_post_meta( $post->ID, $meta_key, true );
            if ( ! empty( $value ) ) {
                $metadata[ $key ] = $value;
            }
        }

        return $metadata;
    }

    /**
     * Get comments data
     *
     * @param WP_Post $post Post object
     * @return array Comments info
     */
    private function get_comments_data( $post ) {
        $comments_count = wp_count_comments( $post->ID );

        return array(
            'count'    => $comments_count->approved,
            'enabled'  => comments_open( $post->ID ),
        );
    }

    /**
     * Get inbound links (trackbacks/pingbacks)
     *
     * @param WP_Post $post Post object
     * @return array Inbound links
     */
    private function get_inbound_links( $post ) {
        $pingbacks = get_comments( array(
            'post_id' => $post->ID,
            'type'    => array( 'pingback', 'trackback' ),
            'status'  => 'approve',
        ));

        $links = array();
        foreach ( $pingbacks as $pingback ) {
            $links[] = array(
                'url'    => $pingback->comment_author_url,
                'title'  => $pingback->comment_author,
                'date'   => $pingback->comment_date,
            );
        }

        return $links;
    }

    /**
     * Get post categories
     *
     * @param WP_Post $post Post object
     * @return array Categories
     */
    private function get_categories( $post ) {
        $categories = get_the_category( $post->ID );
        $category_names = array();

        foreach ( $categories as $category ) {
            $category_names[] = $category->name;
        }

        return $category_names;
    }

    /**
     * Get post tags
     *
     * @param WP_Post $post Post object
     * @return array Tags
     */
    private function get_tags( $post ) {
        $tags = get_the_tags( $post->ID );
        $tag_names = array();

        if ( $tags ) {
            foreach ( $tags as $tag ) {
                $tag_names[] = $tag->name;
            }
        }

        return $tag_names;
    }

    /**
     * Get subhead with WordPress-specific fallbacks
     *
     * @param WP_Post $post Post object
     * @return string Subhead
     */
    protected function get_subhead( $post ) {
        // Try parent implementation first
        $subhead = parent::get_subhead( $post );

        if ( ! empty( $subhead ) ) {
            return $subhead;
        }

        // WordPress-specific: try excerpt as subhead if available
        if ( has_excerpt( $post->ID ) ) {
            return get_the_excerpt( $post->ID );
        }

        return '';
    }

    /**
     * Determine usage type with WordPress-specific logic
     *
     * @param array   $asset Asset data
     * @param WP_Post $post  Post object
     * @return string Usage type
     */
    protected function determine_usage_type( $asset, $post ) {
        // Check if it's the featured/thumbnail image
        $thumbnail_id = get_post_thumbnail_id( $post->ID );
        if ( $thumbnail_id ) {
            $thumbnail_asset_id = get_post_meta( $thumbnail_id, '_mediagraph_asset_id', true );
            if ( $thumbnail_asset_id && $thumbnail_asset_id === $asset['id'] ) {
                return 'lead_photo';
            }
        }

        // Use parent implementation for other cases
        return parent::determine_usage_type( $asset, $post );
    }
}
