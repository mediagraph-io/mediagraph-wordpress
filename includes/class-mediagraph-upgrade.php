<?php
/**
 * Activation, deactivation, and version migrations.
 *
 * 1.x created its tables on activation only and never updated the stored
 * version, so there was no upgrade path at all.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs one-time work when the plugin version changes.
 */
class Mediagraph_Upgrade {

	const VERSION_OPTION = 'mediagraph_version';

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	public static function activate() {
		self::run_migrations( self::installed_version() );

		update_option( self::VERSION_OPTION, MEDIAGRAPH_VERSION, false );
		add_option( 'mediagraph_activated_at', current_time( 'mysql' ), '', false );
	}

	/**
	 * Deactivation hook.
	 *
	 * Only caches are cleared; credentials survive so deactivating and
	 * reactivating does not force everyone to reconnect.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Mediagraph_Cache::flush();
	}

	/**
	 * Run migrations when the code is newer than the stored version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		$installed = self::installed_version();

		if ( version_compare( $installed, MEDIAGRAPH_VERSION, '>=' ) ) {
			return;
		}

		self::run_migrations( $installed );

		update_option( self::VERSION_OPTION, MEDIAGRAPH_VERSION, false );

		Mediagraph_Logger::debug(
			'Upgraded',
			array(
				'from' => $installed,
				'to'   => MEDIAGRAPH_VERSION,
			)
		);
	}

	/**
	 * The version currently recorded for this install.
	 *
	 * @return string
	 */
	private static function installed_version() {
		$version = get_option( self::VERSION_OPTION, '' );

		if ( '' !== $version ) {
			return (string) $version;
		}

		// 1.x used a different option name.
		$legacy = get_option( 'mediagraph_picker_version', '' );

		return '' !== $legacy ? (string) $legacy : '0';
	}

	/**
	 * Apply migrations for everything newer than $from.
	 *
	 * @param string $from Previously installed version.
	 * @return void
	 */
	private static function run_migrations( $from ) {
		if ( version_compare( $from, '2.0.0', '<' ) ) {
			self::migrate_to_2_0();
		}

		Mediagraph_Cache::flush();
	}

	/**
	 * Prepare a 1.x install for 2.0.
	 *
	 * @return void
	 */
	private static function migrate_to_2_0() {
		self::backfill_renditions();

		// 1.x tracked usage in this per-post option; 2.0 derives usage from
		// the post content instead. The old meta is left in place rather than
		// deleted so a downgrade is still possible.
		delete_transient( 'mediagraph_asset_groups' );
		delete_transient( 'mediagraph_api_status' );
	}

	/**
	 * Tag attachments imported by 1.x with a rendition.
	 *
	 * Without this the 2.0 deduplication cannot recognise them and the first
	 * re-insert of an old asset would create a second copy.
	 *
	 * @return void
	 */
	private static function backfill_renditions() {
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 500,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => Mediagraph_Media_Library::META_ASSET_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Mediagraph_Media_Library::META_RENDITION,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $attachment_id ) {
			// 1.x downloaded the 1200px preview and called it "full".
			update_post_meta( $attachment_id, Mediagraph_Media_Library::META_RENDITION, 'permalink' );
			update_post_meta( $attachment_id, Mediagraph_Media_Library::META_SOURCE, 'mediagraph' );
		}
	}
}
