<?php
/**
 * Domain queries against the Mediagraph API.
 *
 * This is the only layer that knows the shape of the Rails responses. It
 * normalizes everything into a stable contract for the AJAX layer and the
 * React picker, so a field rename upstream is a one-file change here.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asset, container, and metadata queries.
 */
class Mediagraph_Repository {

	/**
	 * Container types the picker understands.
	 *
	 * @var array
	 */
	const CONTAINERS = array(
		'Collection'    => 'api/collections/tree',
		'StorageFolder' => 'api/storage_folders/tree',
		'Lightbox'      => 'api/lightboxes/tree',
	);

	/**
	 * Asset kinds mapped to the search API's `type` filter value.
	 *
	 * Assets are classified server side as Image, Video, Audio, Text, Font, or
	 * Document. The search API's `type` parameter covers all of those except
	 * **Text**, which has no filter value at all — so a kind absent from this
	 * map can be displayed and inserted, but cannot be filtered server side.
	 * `search()` falls back to narrowing the page client side in that case.
	 *
	 * @var array
	 */
	const TYPE_FILTERS = array(
		'image'    => 'images',
		'video'    => 'videos',
		'audio'    => 'audio',
		'document' => 'documents',
		'font'     => 'fonts',
	);

	/**
	 * Every kind the normalizer can produce, including unfilterable ones.
	 *
	 * @var array
	 */
	const KINDS = array( 'image', 'video', 'audio', 'text', 'font', 'document' );

	/**
	 * Rights classes, in descending order of what a publisher may do.
	 *
	 * These mirror RightsPackage::CLASSES on the Mediagraph side, which is the
	 * source of truth for both the codes and the wording. The list is small and
	 * stable, so it is repeated here rather than fetched — but it is worth
	 * re-checking against that constant when upgrading Mediagraph. Sites can
	 * adjust the wording through the `mediagraph_rights_classes` filter.
	 *
	 * The keys are the `rights_code` values on the search index.
	 *
	 * @return array Code => label.
	 */
	public static function rights_classes() {
		$classes = array(
			'owned'             => __( 'Owned by organization', 'mediagraph-assets' ),
			'unlimited'         => __( 'Unlimited use', 'mediagraph-assets' ),
			'some'              => __( 'Limited use', 'mediagraph-assets' ),
			'library'           => __( 'Library rights only', 'mediagraph-assets' ),
			'none'              => __( 'No rights', 'mediagraph-assets' ),
			'unknown_searched'  => __( 'Rights unknown (searched)', 'mediagraph-assets' ),
			'unknown_no_search' => __( 'Rights unknown (not searched)', 'mediagraph-assets' ),
		);

		/**
		 * Filter the rights classes offered in the picker.
		 *
		 * @param array $classes Code => label.
		 */
		return apply_filters( 'mediagraph_rights_classes', $classes );
	}

	/**
	 * HTTP client.
	 *
	 * @var Mediagraph_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Mediagraph_Client $client HTTP client.
	 */
	public function __construct( Mediagraph_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Root-level containers, grouped by kind.
	 *
	 * The three trees are fetched independently and a failure in one does not
	 * blank the sidebar; the failing section reports itself instead.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array
	 */
	public function containers( $force = false ) {
		if ( $force ) {
			Mediagraph_Cache::flush();
		} else {
			$cached = Mediagraph_Cache::get( 'containers:root' );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		$result = array(
			'folders'     => array(),
			'collections' => array(),
			'lightboxes'  => array(),
			'errors'      => array(),
		);

		$sections = array(
			'folders'     => 'StorageFolder',
			'collections' => 'Collection',
			'lightboxes'  => 'Lightbox',
		);

		foreach ( $sections as $key => $type ) {
			$items = $this->client->get( self::CONTAINERS[ $type ] );

			if ( is_wp_error( $items ) ) {
				$result['errors'][ $key ] = $items->get_error_message();
				continue;
			}

			$result[ $key ] = $this->normalize_containers( $items, $type );
		}

		// A partial result is never cached: a transient failure in one section
		// would otherwise stick around for the full cache lifetime, and the
		// user's obvious next move — pressing Reload — would keep returning
		// the same stale error.
		if ( empty( $result['errors'] ) ) {
			Mediagraph_Cache::set( 'containers:root', $result, MINUTE_IN_SECONDS * 5 );
		}

		return $result;
	}

	/**
	 * Children of a single container.
	 *
	 * @param int    $parent_id Parent container ID.
	 * @param string $type      Container type.
	 * @param string $sub_type  Optional sub type ("folder" for lightbox bins).
	 * @return array|WP_Error
	 */
	public function container_children( $parent_id, $type, $sub_type = '' ) {
		if ( ! isset( self::CONTAINERS[ $type ] ) ) {
			return new WP_Error(
				'mediagraph_bad_container',
				__( 'Unknown container type.', 'mediagraph-assets' )
			);
		}

		$key = sprintf( 'containers:%s:%d:%s', $type, (int) $parent_id, $sub_type );

		return Mediagraph_Cache::remember(
			$key,
			MINUTE_IN_SECONDS * 5,
			function () use ( $parent_id, $type, $sub_type ) {
				$query = array( 'parent_id' => (int) $parent_id );

				if ( '' !== $sub_type ) {
					$query['sub_type'] = $sub_type;
				}

				$items = $this->client->get( self::CONTAINERS[ $type ], $query );

				if ( is_wp_error( $items ) ) {
					return $items;
				}

				return $this->normalize_containers( $items, $type );
			}
		);
	}

	/**
	 * Search assets.
	 *
	 * @param array $args Search arguments.
	 * @return array|WP_Error
	 */
	public function search( array $args ) {
		$defaults = array(
			'q'             => '',
			'container_id'  => 0,
			'container'     => '',
			'sub_type'      => '',
			'types'         => array(),
			'sort'          => 'created_at',
			'order'         => 'desc',
			'show_all'      => false,
			'custom_meta'   => array(),
			'rights'        => array(),
			'creator_id'    => 0,
			'date_from'     => '',
			'date_to'       => '',
			'page'          => 1,
			'per_page'      => 40,
		);

		$args = wp_parse_args( $args, $defaults );

		$query = array(
			'page'     => max( 1, (int) $args['page'] ),
			'per_page' => min( 100, max( 1, (int) $args['per_page'] ) ),
			'sort'     => $args['sort'],
			'order'    => 'asc' === $args['order'] ? 'asc' : 'desc',
		);

		if ( '' !== trim( (string) $args['q'] ) ) {
			$query['q'] = trim( (string) $args['q'] );
		}

		$container_param = $this->container_query_param( $args['container'], $args['sub_type'] );

		if ( $container_param && (int) $args['container_id'] > 0 ) {
			$query[ $container_param ] = (int) $args['container_id'];
		}

		// The search API takes a single asset class, so it can express exactly
		// one requested kind — and only one it has a filter value for. Any
		// other combination (a block that accepts image *or* video, or the
		// unfilterable Text kind) is narrowed client side instead.
		$types = array_values( array_filter( array_map( 'strval', (array) $args['types'] ) ) );

		$server_filterable = 1 === count( $types ) && isset( self::TYPE_FILTERS[ $types[0] ] );

		if ( $server_filterable ) {
			$query['type'] = self::TYPE_FILTERS[ $types[0] ];
		}

		if ( ! empty( $args['show_all'] ) ) {
			$query['show_all'] = 1;
		}

		if ( ! empty( $args['custom_meta'] ) && is_array( $args['custom_meta'] ) ) {
			$query['custom_meta'] = $args['custom_meta'];
		}

		// rights_code is an indexed keyword holding the rights *class*, so an
		// array becomes an OR — "show me anything we own or have unlimited use
		// of". Filtering by a specific named rights package is a different
		// parameter (`rights`) and deliberately not offered here: package names
		// are org-specific, whereas these classes are universal.
		$rights = array_values( array_intersect(
			array_map( 'strval', (array) $args['rights'] ),
			array_keys( self::rights_classes() )
		) );

		if ( ! empty( $rights ) ) {
			$query['rights_code'] = $rights;
		}

		if ( (int) $args['creator_id'] > 0 ) {
			$query['creator_tag_id'] = (int) $args['creator_id'];
		}

		// The API takes the capture-date window as one "start,finish" value and
		// only applies it when both ends parse, so a half-filled range is sent
		// as no range at all rather than as an open-ended one.
		$from = $this->normalize_date( $args['date_from'] );
		$to   = $this->normalize_date( $args['date_to'] );

		if ( '' !== $from && '' !== $to ) {
			$query['captured_at'] = $from . ',' . $to;
		}

		$response = $this->client->get( 'api/assets/search', $query );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$assets = isset( $response['assets'] ) && is_array( $response['assets'] ) ? $response['assets'] : array();
		$assets = array_map( array( $this, 'normalize_asset' ), $assets );

		$filtered_client_side = false;

		if ( ! empty( $types ) && ! $server_filterable ) {
			$before = count( $assets );

			$assets = array_values( array_filter(
				$assets,
				static function ( $asset ) use ( $types ) {
					return in_array( $asset['kind'], $types, true );
				}
			) );

			$filtered_client_side = count( $assets ) !== $before;
		}

		$total = isset( $response['total_entries'] ) ? (int) $response['total_entries'] : count( $assets );

		return array(
			'assets'          => $assets,
			'total'           => $total,
			'page'            => (int) $query['page'],
			'per_page'        => (int) $query['per_page'],
			'total_pages'     => (int) ceil( $total / max( 1, (int) $query['per_page'] ) ),
			'partial_filter'  => $filtered_client_side,
		);
	}

	/**
	 * Fetch one asset with full detail.
	 *
	 * @param int $asset_id Asset ID.
	 * @return array|WP_Error
	 */
	public function asset( $asset_id ) {
		$asset_id = (int) $asset_id;

		if ( $asset_id <= 0 ) {
			return new WP_Error( 'mediagraph_bad_asset', __( 'Missing asset ID.', 'mediagraph-assets' ) );
		}

		$response = $this->client->get( 'api/assets/' . $asset_id, array( 'include_renditions' => 'true' ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$asset = isset( $response['asset'] ) && is_array( $response['asset'] ) ? $response['asset'] : $response;

		return $this->normalize_asset( $asset, true );
	}

	/**
	 * Normalize a date from the picker into something the API will parse.
	 *
	 * Only ISO calendar dates are accepted. The endpoint runs the value through
	 * Chronic, which would happily interpret arbitrary prose, and letting that
	 * through would make the filter behave differently depending on what an
	 * editor typed.
	 *
	 * @param mixed $value Raw value.
	 * @return string YYYY-MM-DD, or an empty string.
	 */
	private function normalize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Creators (creator tags) available to filter by.
	 *
	 * Ordered by how many assets each one has, so the people an editor actually
	 * reaches for are at the top of the list.
	 *
	 * @return array List of id/label pairs.
	 */
	public function creators() {
		return Mediagraph_Cache::remember(
			'creator_tags',
			MINUTE_IN_SECONDS * 15,
			function () {
				/**
				 * Filter how many creators the picker offers.
				 *
				 * @param int $limit Default 200.
				 */
				$limit = (int) apply_filters( 'mediagraph_creator_limit', 200 );

				$response = $this->client->get(
					'api/creator_tags',
					array(
						'sortField' => 'assets_count',
						'sortOrder' => 'descend',
						'current'   => 1,
						'pageSize'  => max( 1, $limit ),
					)
				);

				if ( is_wp_error( $response ) ) {
					return array();
				}

				$tags = isset( $response['creator_tags'] ) && is_array( $response['creator_tags'] )
					? $response['creator_tags']
					: $response;

				if ( ! is_array( $tags ) ) {
					return array();
				}

				$creators = array();

				foreach ( $tags as $tag ) {
					if ( ! is_array( $tag ) || empty( $tag['id'] ) || empty( $tag['name'] ) ) {
						continue;
					}

					$creators[] = array(
						'value' => (int) $tag['id'],
						'label' => (string) $tag['name'],
						'count' => isset( $tag['assets_count'] ) ? (int) $tag['assets_count'] : null,
					);
				}

				return $creators;
			}
		);
	}

	/**
	 * Custom meta fields available for filtering.
	 *
	 * Deliberately limited to enabled fields with a controlled vocabulary. Two
	 * kinds are excluded on purpose, not by omission:
	 *
	 * - **Free-text fields.** There is no value list to offer, so they could
	 *   only ever be a text box — which is the search box the picker already
	 *   has, with fuzzier semantics. They stay out of the filter panel.
	 * - **Controlled fields with no values defined yet.** Nothing to pick.
	 *
	 * A consequence worth knowing: an organization that has configured twenty
	 * fields may see fewer here, and that is correct.
	 *
	 * @return array
	 */
	public function filterable_custom_fields() {
		return Mediagraph_Cache::remember(
			'custom_meta_fields',
			MINUTE_IN_SECONDS * 15,
			function () {
				$response = $this->client->get( 'api/custom_meta_fields' );

				if ( is_wp_error( $response ) ) {
					return array();
				}

				$fields = isset( $response['custom_meta_fields'] ) && is_array( $response['custom_meta_fields'] )
					? $response['custom_meta_fields']
					: $response;

				if ( ! is_array( $fields ) ) {
					return array();
				}

				$filterable = array();

				foreach ( $fields as $field ) {
					if ( ! is_array( $field ) || empty( $field['name'] ) ) {
						continue;
					}

					if ( isset( $field['enabled'] ) && ! $field['enabled'] ) {
						continue;
					}

					// Free-text fields have no value list to choose from.
					if ( ! empty( $field['free'] ) ) {
						continue;
					}

					$values = array();

					if ( ! empty( $field['custom_meta_values_attributes'] ) && is_array( $field['custom_meta_values_attributes'] ) ) {
						foreach ( $field['custom_meta_values_attributes'] as $value ) {
							if ( ! empty( $value['text'] ) ) {
								$values[] = (string) $value['text'];
							}
						}
					}

					if ( empty( $values ) ) {
						continue;
					}

					sort( $values, SORT_NATURAL | SORT_FLAG_CASE );

					// Organizations arrange their fields into groups and give
					// both the groups and the fields an explicit position. With
					// twenty-odd fields that ordering is the difference between
					// a navigable panel and a wall of dropdowns, so carry it
					// through instead of re-sorting alphabetically.
					$group          = null;
					$group_position = PHP_INT_MAX;

					if ( ! empty( $field['custom_meta_groups'] ) && is_array( $field['custom_meta_groups'] ) ) {
						$first = reset( $field['custom_meta_groups'] );

						if ( is_array( $first ) && ! empty( $first['name'] ) ) {
							$group          = (string) $first['name'];
							$group_position = isset( $first['position'] ) ? (int) $first['position'] : PHP_INT_MAX;
						}
					}

					$filterable[] = array(
						'name'           => (string) $field['name'],
						'label'          => (string) $field['name'],
						'values'         => array_values( array_unique( $values ) ),
						'group'          => $group,
						'position'       => isset( $field['position'] ) ? (int) $field['position'] : PHP_INT_MAX,
						'group_position' => $group_position,
					);
				}

				usort(
					$filterable,
					static function ( $a, $b ) {
						if ( $a['group_position'] !== $b['group_position'] ) {
							return $a['group_position'] <=> $b['group_position'];
						}

						if ( $a['position'] !== $b['position'] ) {
							return $a['position'] <=> $b['position'];
						}

						return strcasecmp( $a['label'], $b['label'] );
					}
				);

				/**
				 * Filter the custom metadata fields offered in the picker.
				 *
				 * @param array $filterable Normalized field definitions.
				 */
				return apply_filters( 'mediagraph_filterable_custom_fields', $filterable );
			}
		);
	}

	/**
	 * Map a container type to its search query parameter.
	 *
	 * @param string $type     Container type.
	 * @param string $sub_type Optional sub type.
	 * @return string Empty string when unmapped.
	 */
	private function container_query_param( $type, $sub_type = '' ) {
		switch ( $type ) {
			case 'Collection':
				return 'collection_id';

			case 'StorageFolder':
				return 'storage_folder_id';

			case 'Lightbox':
				// Bins are lightbox sub-folders and filter differently.
				return 'folder' === $sub_type ? 'lightbox_folder_id' : 'lightbox_id';
		}

		return '';
	}

	/**
	 * Normalize a tree response into picker container nodes.
	 *
	 * @param mixed  $items Raw response.
	 * @param string $type  Container type.
	 * @return array
	 */
	private function normalize_containers( $items, $type ) {
		if ( ! is_array( $items ) ) {
			return array();
		}

		// Some endpoints wrap the array, some return it bare.
		if ( isset( $items['asset_groups'] ) && is_array( $items['asset_groups'] ) ) {
			$items = $items['asset_groups'];
		}

		$nodes = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['id'] ) ) {
				continue;
			}

			$node = array(
				'id'           => (int) $item['id'],
				'name'         => isset( $item['name'] ) ? (string) $item['name'] : __( '(untitled)', 'mediagraph-assets' ),
				'type'         => $type,
				'sub_type'     => isset( $item['sub_type'] ) ? (string) $item['sub_type'] : '',
				'organizer'    => ! empty( $item['organizer'] ),
				'has_children' => ! empty( $item['has_children'] ),
				'count'        => isset( $item['visible_assets_count'] ) && null !== $item['visible_assets_count']
					? (int) $item['visible_assets_count']
					: null,
			);

			if ( ! empty( $item['children'] ) && is_array( $item['children'] ) ) {
				$node['children']     = $this->normalize_containers( $item['children'], $type );
				$node['has_children'] = $node['has_children'] || ! empty( $node['children'] );
			}

			$nodes[] = $node;
		}

		return $nodes;
	}

	/**
	 * Normalize an asset payload.
	 *
	 * Field notes worth keeping straight:
	 * - Dimensions are top level `width`/`height`; there is no `dimensions`
	 *   object, which is why 1.x never rendered them.
	 * - There is no `full_url`; the largest preview is `permalink_url`. Actual
	 *   full-size bytes come from the download endpoint, not a preview URL.
	 * - `permalink_url` may already be watermarked based on the caller's
	 *   permissions.
	 *
	 * @param array $asset  Raw asset.
	 * @param bool  $detail Whether this came from the detail endpoint.
	 * @return array
	 */
	private function normalize_asset( $asset, $detail = false ) {
		if ( ! is_array( $asset ) ) {
			return array();
		}

		$type = isset( $asset['type'] ) ? (string) $asset['type'] : '';
		$mime = isset( $asset['mime_type'] ) ? (string) $asset['mime_type'] : '';
		$kind = $this->asset_kind( $type, $mime );

		$normalized = array(
			'id'           => isset( $asset['id'] ) ? (int) $asset['id'] : 0,
			'guid'         => isset( $asset['guid'] ) ? (string) $asset['guid'] : '',
			'filename'     => isset( $asset['filename'] ) ? (string) $asset['filename'] : '',
			'kind'         => $kind,
			'mime_type'    => $mime,
			'ext'          => isset( $asset['ext'] ) ? (string) $asset['ext'] : '',
			'file_size'    => isset( $asset['file_size'] ) ? (int) $asset['file_size'] : 0,
			'width'        => isset( $asset['width'] ) ? (int) $asset['width'] : 0,
			'height'       => isset( $asset['height'] ) ? (int) $asset['height'] : 0,
			'duration'     => isset( $asset['duration'] ) ? (float) $asset['duration'] : 0,
			'downloadable' => ! isset( $asset['downloadable'] ) || (bool) $asset['downloadable'],
			'thumb_url'    => $this->absolute( isset( $asset['grid_url'] ) ? $asset['grid_url'] : ( isset( $asset['thumb_url'] ) ? $asset['thumb_url'] : '' ) ),
			'preview_url'  => $this->absolute( isset( $asset['permalink_url'] ) ? $asset['permalink_url'] : ( isset( $asset['small_url'] ) ? $asset['small_url'] : '' ) ),
			'poster_url'   => $this->absolute( isset( $asset['preview_image_url'] ) ? $asset['preview_image_url'] : '' ),
			'title'        => isset( $asset['title'] ) ? (string) $asset['title'] : '',
			'headline'     => isset( $asset['headline'] ) ? (string) $asset['headline'] : '',
			'description'  => isset( $asset['description'] ) ? (string) $asset['description'] : '',
			'alt_text'     => isset( $asset['alt_text'] ) ? (string) $asset['alt_text'] : '',
			'credit_line'  => isset( $asset['credit_line'] ) ? (string) $asset['credit_line'] : '',
			'usage_terms'  => isset( $asset['usage_terms'] ) ? (string) $asset['usage_terms'] : '',
			'event'        => isset( $asset['event'] ) ? (string) $asset['event'] : '',
			'location'     => $this->location( $asset ),
			'captured_at'  => isset( $asset['captured_at'] ) ? (string) $asset['captured_at'] : '',
			'created_at'   => isset( $asset['created_at'] ) ? (string) $asset['created_at'] : '',
		);

		// `creator` is the IPTC string, which is often blank even when the asset
		// is attributed — organizations attribute via creator *tags*, which is
		// also what the creator filter matches on. Fall back to the tag so the
		// panel agrees with the filter instead of showing an empty Creator for
		// an asset you just filtered by name.
		$normalized['creator'] = $this->join_values( isset( $asset['creator'] ) ? $asset['creator'] : '' );

		if ( '' === $normalized['creator'] && ! empty( $asset['creator_tag']['name'] ) ) {
			$normalized['creator'] = (string) $asset['creator_tag']['name'];
		}

		$normalized['keywords'] = $this->tag_names( isset( $asset['tags'] ) ? $asset['tags'] : array() );

		if ( $detail ) {
			$normalized['custom_meta'] = $this->custom_meta_values( isset( $asset['custom_meta_values'] ) ? $asset['custom_meta_values'] : array() );
			$normalized['renditions']  = $this->download_sizes( $asset );

			// The detail endpoint exposes a true full-size URL when the caller
			// is allowed to see one. Search results never include it, which is
			// why a preview URL is the best a grid row can offer.
			if ( ! empty( $asset['full_url'] ) ) {
				$normalized['full_url'] = $this->absolute( $asset['full_url'] );
			}
		}

		return $normalized;
	}

	/**
	 * Sizes the connected account may download for this asset.
	 *
	 * `download_sizes` is computed server side from the caller's membership and
	 * is the authoritative answer. `renditions` is only present when the
	 * request asked for it, so it is the fallback.
	 *
	 * @param array $asset Raw asset.
	 * @return array
	 */
	private function download_sizes( array $asset ) {
		if ( ! empty( $asset['download_sizes'] ) && is_array( $asset['download_sizes'] ) ) {
			return array_values( array_filter( array_map( 'strval', $asset['download_sizes'] ) ) );
		}

		// The renditions list names the 1200px preview "permalink"; the
		// download endpoint calls the same thing "medium".
		$names = $this->rendition_names( isset( $asset['renditions'] ) ? $asset['renditions'] : array() );

		return array_values(
			array_map(
				static function ( $name ) {
					return 'permalink' === $name ? 'medium' : $name;
				},
				$names
			)
		);
	}

	/**
	 * Classify an asset into the kinds the picker filters on.
	 *
	 * @param string $type Mediagraph asset class.
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function asset_kind( $type, $mime ) {
		switch ( $type ) {
			case 'Image':
				return 'image';
			case 'Video':
				return 'video';
			case 'Audio':
				return 'audio';
			case 'Font':
				return 'font';
			case 'Text':
				return 'text';
			case 'Document':
				return 'document';
		}

		if ( 0 === strpos( $mime, 'text/' ) ) {
			return 'text';
		}

		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}

		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}

		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}

		return 'document';
	}

	/**
	 * Compose a readable place from the IPTC location fields.
	 *
	 * Mediagraph stores these separately (sublocation, city, state, country).
	 * Ordered narrowest to widest, the way a caption would read, and skipping
	 * whichever parts are blank rather than leaving stray commas.
	 *
	 * @param array $asset Raw asset.
	 * @return string
	 */
	private function location( array $asset ) {
		$parts = array();

		foreach ( array( 'sublocation', 'city', 'state', 'country' ) as $key ) {
			if ( ! empty( $asset[ $key ] ) && is_scalar( $asset[ $key ] ) ) {
				$parts[] = trim( (string) $asset[ $key ] );
			}
		}

		return implode( ', ', array_filter( array_unique( $parts ) ) );
	}

	/**
	 * Flatten a scalar-or-array field into a comma separated string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	private function join_values( $value ) {
		if ( is_array( $value ) ) {
			return implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}

		return (string) $value;
	}

	/**
	 * Extract tag names.
	 *
	 * @param mixed $tags Raw tags array.
	 * @return string Comma separated names.
	 */
	private function tag_names( $tags ) {
		if ( ! is_array( $tags ) ) {
			return '';
		}

		$names = array();

		foreach ( $tags as $tag ) {
			if ( is_array( $tag ) && ! empty( $tag['name'] ) ) {
				$names[] = (string) $tag['name'];
			} elseif ( is_string( $tag ) ) {
				$names[] = $tag;
			}
		}

		return implode( ', ', array_unique( $names ) );
	}

	/**
	 * Reduce custom meta values to name/value pairs for display.
	 *
	 * @param mixed $values Raw custom_meta_values array.
	 * @return array
	 */
	private function custom_meta_values( $values ) {
		if ( ! is_array( $values ) ) {
			return array();
		}

		// A multi-value field arrives as one entry per value, all sharing the
		// same field name. Collect them under that name so the panel shows one
		// row listing every value, rather than repeating the field label once
		// per value.
		$grouped = array();

		foreach ( $values as $value ) {
			if ( ! is_array( $value ) || empty( $value['name'] ) ) {
				continue;
			}

			$text = '';

			foreach ( array( 'text', 'date', 'number' ) as $key ) {
				if ( isset( $value[ $key ] ) && '' !== $value[ $key ] && null !== $value[ $key ] ) {
					$text = (string) $value[ $key ];
					break;
				}
			}

			if ( '' === $text ) {
				continue;
			}

			$name = (string) $value['name'];

			if ( ! isset( $grouped[ $name ] ) ) {
				$grouped[ $name ] = array();
			}

			$grouped[ $name ][] = $text;
		}

		$pairs = array();

		// Field order follows first appearance, which is the order the API
		// returned them in.
		foreach ( $grouped as $name => $texts ) {
			$pairs[] = array(
				'name'  => $name,
				'value' => implode( ', ', array_unique( $texts ) ),
			);
		}

		return $pairs;
	}

	/**
	 * Rendition names the current account may download.
	 *
	 * @param mixed $renditions Raw renditions array.
	 * @return array
	 */
	private function rendition_names( $renditions ) {
		if ( ! is_array( $renditions ) ) {
			return array();
		}

		$names = array();

		foreach ( $renditions as $rendition ) {
			if ( is_array( $rendition ) && ! empty( $rendition['name'] ) ) {
				$names[] = (string) $rendition['name'];
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Make a possibly-relative URL absolute against the API host.
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	private function absolute( $url ) {
		$url = (string) $url;

		if ( '' === $url || preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}

		return $this->client->url_for( ltrim( $url, '/' ) );
	}
}
