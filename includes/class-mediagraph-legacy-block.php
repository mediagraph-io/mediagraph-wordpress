<?php
/**
 * Backwards compatibility for the 1.x mediagraph/asset-picker block.
 *
 * 2.0 inserts native core blocks, but posts published with 1.x still contain
 * mediagraph/asset-picker blocks holding a pre-rendered HTML string. This keeps
 * them registered and rendering exactly as before, so upgrading never changes
 * a published page. The editor offers a one-click conversion to a native block
 * (see admin/js/blocks/legacy-block.js).
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the legacy block.
 */
class Mediagraph_Legacy_Block {

	const NAME = 'mediagraph/asset-picker';

	/**
	 * Register the block.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Register the dynamic block type.
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) || WP_Block_Type_Registry::get_instance()->is_registered( self::NAME ) ) {
			return;
		}

		register_block_type(
			self::NAME,
			array(
				'api_version'     => 2,
				'editor_script'   => 'mediagraph-legacy-block',
				'render_callback' => array( __CLASS__, 'render' ),
				'attributes'      => self::attributes(),
			)
		);
	}

	/**
	 * Attribute schema, matching what 1.x saved.
	 *
	 * @return array
	 */
	public static function attributes() {
		$string = array(
			'type'    => 'string',
			'default' => '',
		);

		return array(
			'assetId'             => array( 'type' => 'number' ),
			'assetGuid'           => $string,
			'assetUrl'            => $string,
			'assetTitle'          => $string,
			'assetHtml'           => $string,
			'assetType'           => $string,
			'attachmentId'        => array( 'type' => 'number' ),
			'posterUrl'           => $string,
			'title'               => $string,
			'byline'              => $string,
			'headline'            => $string,
			'description'         => $string,
			'altText'             => $string,
			'extendedDescription' => $string,
			'keywords'            => $string,
			'usageRights'         => $string,
			'alignment'           => array(
				'type'    => 'string',
				'default' => 'none',
			),
			'linkTo'              => array(
				'type'    => 'string',
				'default' => 'none',
			),
			'size'                => array(
				'type'    => 'string',
				'default' => 'full',
			),
			'metadata'            => array(
				'type'    => 'object',
				'default' => array(),
			),
			'displaySettings'     => array(
				'type'    => 'object',
				'default' => array(),
			),
		);
	}

	/**
	 * Render the stored markup.
	 *
	 * The saved HTML is escaped through wp_kses_post on output. 1.x echoed it
	 * verbatim, so a caption containing a quote could break out of the markup.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		if ( empty( $attributes['assetHtml'] ) ) {
			return '';
		}

		$classes = 'wp-block-mediagraph-asset';

		$alignment = isset( $attributes['alignment'] ) ? (string) $attributes['alignment'] : 'none';

		if ( '' !== $alignment && 'none' !== $alignment ) {
			$classes .= ' align' . sanitize_html_class( $alignment );
		}

		$wrapper = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => $classes ) )
			: 'class="' . esc_attr( $classes ) . '"';

		return sprintf(
			'<div %1$s>%2$s</div>',
			$wrapper,
			wp_kses_post( $attributes['assetHtml'] )
		);
	}
}
