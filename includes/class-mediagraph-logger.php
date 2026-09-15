<?php
/**
 * Debug logging.
 *
 * Everything here is a no-op unless WP_DEBUG is on. 1.x wrote full API
 * responses (including token endpoint bodies) to error_log on every request.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guarded logger.
 */
class Mediagraph_Logger {

	/**
	 * Whether debug logging is enabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		/**
		 * Filter whether Mediagraph writes debug output to the PHP error log.
		 *
		 * @param bool $enabled Defaults to WP_DEBUG.
		 */
		return (bool) apply_filters( 'mediagraph_debug_logging', defined( 'WP_DEBUG' ) && WP_DEBUG );
	}

	/**
	 * Log a message with optional context.
	 *
	 * @param string $message Human readable message.
	 * @param array  $context Extra values; redacted before output.
	 * @return void
	 */
	public static function debug( $message, array $context = array() ) {
		if ( ! self::enabled() ) {
			return;
		}

		$line = '[Mediagraph] ' . $message;

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/**
	 * Log an error. Errors are always recorded, but still redacted.
	 *
	 * @param string $message Human readable message.
	 * @param array  $context Extra values.
	 * @return void
	 */
	public static function error( $message, array $context = array() ) {
		$line = '[Mediagraph] ' . $message;

		if ( ! empty( $context ) ) {
			$line .= ' ' . wp_json_encode( self::redact( $context ) );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $line );
	}

	/**
	 * Strip anything that looks like a credential.
	 *
	 * @param array $context Context array.
	 * @return array
	 */
	private static function redact( array $context ) {
		$secret_keys = array(
			'access_token',
			'refresh_token',
			'code',
			'code_verifier',
			'client_secret',
			'authorization',
			'token',
			'password',
		);

		$clean = array();

		foreach ( $context as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $secret_keys, true ) ) {
				$clean[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::redact( $value );
				continue;
			}

			if ( is_string( $value ) && strlen( $value ) > 500 ) {
				$clean[ $key ] = substr( $value, 0, 500 ) . '…';
				continue;
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}
}
