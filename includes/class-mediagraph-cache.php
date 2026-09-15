<?php
/**
 * Namespaced transient cache.
 *
 * Every cached value is stored under a key derived from a version counter, so
 * flush() is a single option bump that invalidates everything at once. 1.x
 * wrote hashed keys but deleted an unhashed one, which meant the cache was
 * never actually cleared by disconnect, org switch, or the picker's Reload
 * button.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache helper.
 */
class Mediagraph_Cache {

	const VERSION_OPTION = 'mediagraph_cache_version';
	const PREFIX         = 'mg_c_';

	/**
	 * Default lifetime in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 300;

	/**
	 * Read a cached value.
	 *
	 * @param string $key Logical cache key.
	 * @return mixed|false Cached value, or false when absent.
	 */
	public static function get( $key ) {
		return get_transient( self::transient_name( $key ) );
	}

	/**
	 * Write a cached value.
	 *
	 * @param string $key   Logical cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	public static function set( $key, $value, $ttl = self::DEFAULT_TTL ) {
		set_transient( self::transient_name( $key ), $value, max( 1, (int) $ttl ) );
	}

	/**
	 * Remember the result of a callback.
	 *
	 * WP_Error results are never cached.
	 *
	 * @param string   $key      Logical cache key.
	 * @param int      $ttl      Lifetime in seconds.
	 * @param callable $callback Producer.
	 * @return mixed
	 */
	public static function remember( $key, $ttl, callable $callback ) {
		$cached = self::get( $key );

		if ( false !== $cached ) {
			return $cached;
		}

		$value = call_user_func( $callback );

		if ( ! is_wp_error( $value ) ) {
			self::set( $key, $value, $ttl );
		}

		return $value;
	}

	/**
	 * Invalidate everything by bumping the namespace.
	 *
	 * Old transients are left to expire on their own; they can no longer be
	 * addressed.
	 *
	 * @return void
	 */
	public static function flush() {
		update_option( self::VERSION_OPTION, self::version() + 1, false );
	}

	/**
	 * Current namespace version.
	 *
	 * @return int
	 */
	private static function version() {
		return (int) get_option( self::VERSION_OPTION, 1 );
	}

	/**
	 * Build the real transient name.
	 *
	 * Transient names are capped at 172 characters, so the logical key is
	 * hashed rather than embedded.
	 *
	 * @param string $key Logical cache key.
	 * @return string
	 */
	private static function transient_name( $key ) {
		return self::PREFIX . self::version() . '_' . md5( (string) $key );
	}
}
