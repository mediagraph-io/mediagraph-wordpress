<?php
/**
 * Mediagraph Metadata Mapper Base Class
 *
 * Abstract base class for platform-specific metadata mappers.
 * Each mapper handles converting platform-specific article data
 * into Mediagraph's expected schema format.
 *
 * @package MediagraphPicker
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract metadata mapper class
 */
abstract class Mediagraph_Metadata_Mapper {

    /**
     * Build publish metadata payload
     *
     * @param WP_Post $post              Post object
     * @param array   $mediagraph_assets Assets used in post
     * @return array Metadata payload
     */
    abstract public function build_publish_payload( $post, $mediagraph_assets );

    /**
     * Get publisher properties
     *
     * @return array Publisher data
     */
    protected function get_publisher_properties() {
        $site_url = get_site_url();
        $site_name = get_bloginfo( 'name' );

        return array(
            'publisher_uid'    => $this->generate_publisher_uid(),
            'publisher_name'   => $site_name,
            'publication_name' => $site_name,
            'edition'          => $this->get_edition(),
        );
    }

    /**
     * Generate publisher UID
     *
     * @return string Publisher UID
     */
    protected function generate_publisher_uid() {
        // Use site URL hash as consistent UID
        return 'wp_' . md5( get_site_url() );
    }

    /**
     * Get edition (can be overridden by platform)
     *
     * @return string Edition identifier
     */
    protected function get_edition() {
        return 'online';
    }

    /**
     * Generate article UID
     *
     * @param WP_Post $post Post object
     * @return string Article UID
     */
    protected function generate_article_uid( $post ) {
        return $this->generate_publisher_uid() . '_post_' . $post->ID;
    }

    /**
     * Get article properties from post
     *
     * @param WP_Post $post Post object
     * @return array Article data
     */
    protected function get_article_properties( $post ) {
        $article_url = get_permalink( $post->ID );
        $publish_date = get_the_date( 'c', $post->ID );

        // Get post author
        $author = get_userdata( $post->post_author );
        $byline = $author ? $author->display_name : '';

        // Get excerpt or generate from content
        $abstract = has_excerpt( $post->ID ) ? get_the_excerpt( $post->ID ) : wp_trim_words( $post->post_content, 55, '...' );

        return array(
            'article_uid'        => $this->generate_article_uid( $post ),
            'article_name'       => get_the_title( $post->ID ),
            'publication_urls'   => array( $article_url ),
            'publication_date'   => $publish_date,
            'headline'           => get_the_title( $post->ID ),
            'subhead'            => $this->get_subhead( $post ),
            'byline'             => $byline,
            'abstract'           => $abstract,
            'article_text'       => $this->prepare_article_text( $post->post_content ),
            'published_images'   => array(),
            'published_videos'   => array(),
        );
    }

    /**
     * Get subhead (can be overridden by platform)
     *
     * @param WP_Post $post Post object
     * @return string Subhead
     */
    protected function get_subhead( $post ) {
        // Check for common subhead meta fields
        $subhead_fields = array( 'subheading', 'subtitle', 'deck' );

        foreach ( $subhead_fields as $field ) {
            $value = get_post_meta( $post->ID, $field, true );
            if ( ! empty( $value ) ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * Prepare article text (strip HTML, clean up)
     *
     * @param string $content Post content
     * @return string Cleaned article text
     */
    protected function prepare_article_text( $content ) {
        // Strip shortcodes
        $content = strip_shortcodes( $content );

        // Convert HTML to plain text
        $content = wp_strip_all_tags( $content );

        // Clean up whitespace
        $content = trim( preg_replace( '/\s+/', ' ', $content ) );

        return $content;
    }

    /**
     * Build published image metadata
     *
     * @param array   $asset      Asset data
     * @param WP_Post $post       Post object
     * @param string  $usage_type Usage type (lead_photo, body_photo, thumbnail)
     * @return array Published image data
     */
    protected function build_published_image( $asset, $post, $usage_type = 'body_photo' ) {
        $article_uid = $this->generate_article_uid( $post );

        // Extract article-specific metadata edited by user in WordPress
        $metadata = isset( $asset['metadata'] ) ? $asset['metadata'] : array();

        // Get article-specific fields (caption and alt_text go in main fields)
        $caption = isset( $metadata['description'] ) ? $metadata['description'] :
                   ( isset( $asset['caption'] ) ? $asset['caption'] : '' );
        $alt_text = isset( $metadata['alt_text'] ) ? $metadata['alt_text'] :
                    ( isset( $asset['alt_text'] ) ? $asset['alt_text'] : '' );

        // Build metadata JSON for all article-specific Asset Metadata fields
        $article_metadata = array();

        // Title
        if ( isset( $metadata['title'] ) && ! empty( $metadata['title'] ) ) {
            $article_metadata['title'] = $metadata['title'];
        }

        // Byline
        if ( isset( $metadata['byline'] ) && ! empty( $metadata['byline'] ) ) {
            $article_metadata['byline'] = $metadata['byline'];
        }

        // Headline
        if ( isset( $metadata['headline'] ) && ! empty( $metadata['headline'] ) ) {
            $article_metadata['headline'] = $metadata['headline'];
        }

        // Description
        if ( isset( $metadata['description'] ) && ! empty( $metadata['description'] ) ) {
            $article_metadata['description'] = $metadata['description'];
        }

        // Alt Text
        if ( isset( $metadata['alt_text'] ) && ! empty( $metadata['alt_text'] ) ) {
            $article_metadata['alt_text'] = $metadata['alt_text'];
        }

        // Extended Description
        if ( isset( $metadata['extended_description'] ) && ! empty( $metadata['extended_description'] ) ) {
            $article_metadata['extended_description'] = $metadata['extended_description'];
        }

        // Keywords
        if ( isset( $metadata['keywords'] ) && ! empty( $metadata['keywords'] ) ) {
            $article_metadata['keywords'] = $metadata['keywords'];
        }

        // Usage Rights
        if ( isset( $metadata['usage_rights'] ) && ! empty( $metadata['usage_rights'] ) ) {
            $article_metadata['usage_rights'] = $metadata['usage_rights'];
        }

        return array(
            'asset_guid'    => isset( $asset['guid'] ) ? $asset['guid'] : '',
            'published_in'  => $article_uid,
            'url'           => isset( $asset['url'] ) ? $asset['url'] : '',
            'filename'      => isset( $asset['filename'] ) ? $asset['filename'] : '',
            'cdn_link'      => isset( $asset['cdn_url'] ) ? $asset['cdn_url'] : '',
            'usage_type'    => $usage_type,
            'credit_line'   => isset( $metadata['byline'] ) ? $metadata['byline'] :
                               ( isset( $asset['credit'] ) ? $asset['credit'] : '' ),
            'caption'       => $caption,
            'alt_text'      => $alt_text,
            'restrictions'  => isset( $asset['restrictions'] ) ? $asset['restrictions'] : '',
            'metadata'      => $article_metadata,
        );
    }

    /**
     * Build published video metadata
     *
     * @param array   $asset      Asset data
     * @param WP_Post $post       Post object
     * @param string  $usage_type Usage type (body_video, featured_video)
     * @return array Published video data
     */
    protected function build_published_video( $asset, $post, $usage_type = 'body_video' ) {
        // Similar structure to images
        return $this->build_published_image( $asset, $post, $usage_type );
    }

    /**
     * Categorize asset by usage type
     *
     * @param array   $asset Asset data
     * @param WP_Post $post  Post object
     * @return string Usage type
     */
    protected function determine_usage_type( $asset, $post ) {
        // Check if it's the featured image
        $featured_image_id = get_post_thumbnail_id( $post->ID );
        if ( $featured_image_id ) {
            $featured_asset_id = get_post_meta( $featured_image_id, '_mediagraph_asset_id', true );
            if ( $featured_asset_id === $asset['id'] ) {
                return 'lead_photo';
            }
        }

        // Check if specified in asset data
        if ( isset( $asset['usage_type'] ) ) {
            return $asset['usage_type'];
        }

        // Default to body photo/video
        if ( isset( $asset['mime_type'] ) && strpos( $asset['mime_type'], 'video' ) !== false ) {
            return 'body_video';
        }

        return 'body_photo';
    }

    /**
     * Get WordPress attachment metadata if asset was downloaded
     *
     * @param string $asset_id Mediagraph asset ID
     * @return array|null Attachment metadata or null
     */
    protected function get_wordpress_attachment_for_asset( $asset_id ) {
        $args = array(
            'post_type'   => 'attachment',
            'meta_key'    => '_mediagraph_asset_id',
            'meta_value'  => $asset_id,
            'numberposts' => 1,
        );

        $attachments = get_posts( $args );

        if ( empty( $attachments ) ) {
            return null;
        }

        $attachment = $attachments[0];

        return array(
            'id'        => $attachment->ID,
            'url'       => wp_get_attachment_url( $attachment->ID ),
            'alt_text'  => get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
            'caption'   => $attachment->post_excerpt,
            'title'     => $attachment->post_title,
        );
    }
}
