<?php
/**
 * HTTP client for the Mediagraph API.
 *
 * Owns the whole token lifecycle: it refreshes proactively when the stored
 * token is near expiry and retries exactly once on a 401. In 1.x the refresh
 * code existed but nothing ever called it, so every install silently died when
 * its access token aged out.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin, authenticated wrapper around the WordPress HTTP API.
 */
class Mediagraph_Client {

	/**
	 * Credential store.
	 *
	 * @var Mediagraph_Credentials
	 */
	private $credentials;

	/**
	 * OAuth handler, used for refreshes.
	 *
	 * @var Mediagraph_OAuth
	 */
	private $oauth;

	/**
	 * Constructor.
	 *
	 * @param Mediagraph_Credentials $credentials Credential store.
	 * @param Mediagraph_OAuth       $oauth       OAuth handler.
	 */
	public function __construct( Mediagraph_Credentials $credentials, Mediagraph_OAuth $oauth ) {
		$this->credentials = $credentials;
		$this->oauth       = $oauth;
	}

	/**
	 * GET request.
	 *
	 * @param string $endpoint Path relative to the API base URL.
	 * @param array  $query    Query parameters.
	 * @return array|WP_Error
	 */
	public function get( $endpoint, array $query = array() ) {
		return $this->request( 'GET', $endpoint, $query );
	}

	/**
	 * POST request with a JSON body.
	 *
	 * @param string $endpoint Path relative to the API base URL.
	 * @param array  $body     Payload.
	 * @return array|WP_Error
	 */
	public function post( $endpoint, array $body = array() ) {
		return $this->request( 'POST', $endpoint, $body );
	}

	/**
	 * Stream a binary endpoint to a temporary file.
	 *
	 * WordPress's download_url() cannot send an Authorization header, so the
	 * HTTP API is used directly with stream => true.
	 *
	 * @param string $endpoint Path relative to the API base URL.
	 * @param array  $query    Query parameters.
	 * @return string|WP_Error Temp file path on success.
	 */
	public function download( $endpoint, array $query = array() ) {
		$prepared = $this->prepare();

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$temp_file = wp_tempnam( 'mediagraph' );

		if ( ! $temp_file ) {
			return new WP_Error(
				'mediagraph_temp_file',
				__( 'WordPress could not create a temporary file for the download.', 'mediagraph-assets' )
			);
		}

		$response = $this->raw_download( $endpoint, $query, $temp_file );

		// A 401 here usually means the token aged out mid-session.
		if ( $this->is_unauthorized( $response ) && $this->oauth->refresh() ) {
			$response = $this->raw_download( $endpoint, $query, $temp_file );
		}

		if ( is_wp_error( $response ) ) {
			$this->cleanup( $temp_file );

			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( $status >= 400 ) {
			$this->cleanup( $temp_file );

			return $this->error_from_status( $status, array() );
		}

		if ( ! file_exists( $temp_file ) || 0 === filesize( $temp_file ) ) {
			$this->cleanup( $temp_file );

			return new WP_Error(
				'mediagraph_empty_download',
				__( 'Mediagraph returned an empty file.', 'mediagraph-assets' )
			);
		}

		return $temp_file;
	}

	/**
	 * Absolute URL for an API path.
	 *
	 * @param string $endpoint Path relative to the API base URL.
	 * @return string
	 */
	public function url_for( $endpoint ) {
		return trailingslashit( $this->credentials->api_base_url() ) . ltrim( (string) $endpoint, '/' );
	}

	/**
	 * Perform an authenticated request.
	 *
	 * @param string $method   HTTP verb.
	 * @param string $endpoint Path relative to the API base URL.
	 * @param array  $args     Query parameters (GET) or body (POST).
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, array $args = array() ) {
		$prepared = $this->prepare();

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$response = $this->raw_request( $method, $endpoint, $args );

		if ( $this->is_unauthorized( $response ) && $this->oauth->refresh() ) {
			$response = $this->raw_request( $method, $endpoint, $args );
		}

		return $this->parse( $response, $endpoint );
	}

	/**
	 * Ensure we hold a usable token before spending a request.
	 *
	 * @return true|WP_Error
	 */
	private function prepare() {
		if ( ! $this->credentials->has_token() ) {
			return new WP_Error(
				'mediagraph_not_connected',
				__( 'This site is not connected to Mediagraph. Connect it under Settings → Mediagraph.', 'mediagraph-assets' ),
				array( 'status' => 401 )
			);
		}

		if ( $this->credentials->is_expired() && ! $this->oauth->refresh() ) {
			return new WP_Error(
				'mediagraph_session_expired',
				__( 'The Mediagraph connection has expired. Reconnect under Settings → Mediagraph.', 'mediagraph-assets' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Issue the HTTP request.
	 *
	 * @param string $method   HTTP verb.
	 * @param string $endpoint Path relative to the API base URL.
	 * @param array  $args     Query parameters or body.
	 * @return array|WP_Error
	 */
	private function raw_request( $method, $endpoint, array $args ) {
		$url = $this->url_for( $endpoint );

		$request = array(
			'method'  => $method,
			'headers' => $this->headers(),
			'timeout' => 30,
		);

		if ( 'GET' === $method ) {
			$url = $this->build_url( $endpoint, $args );
		} else {
			$request['body'] = wp_json_encode( $args );
		}

		return wp_remote_request( $url, $request );
	}

	/**
	 * Issue a streaming download request.
	 *
	 * @param string $endpoint  Path relative to the API base URL.
	 * @param array  $query     Query parameters.
	 * @param string $temp_file Destination path.
	 * @return array|WP_Error
	 */
	private function raw_download( $endpoint, array $query, $temp_file ) {
		$url = $this->build_url( $endpoint, $query );

		/**
		 * Filter the timeout used when streaming an asset from Mediagraph.
		 *
		 * @param int $timeout Seconds.
		 */
		$timeout = (int) apply_filters( 'mediagraph_download_timeout', 300 );

		return wp_remote_get(
			$url,
			array(
				'headers'  => $this->headers(),
				'timeout'  => $timeout,
				'stream'   => true,
				'filename' => $temp_file,
			)
		);
	}

	/**
	 * Request headers, including the current bearer token.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array(
			'Authorization' => 'Bearer ' . $this->credentials->access_token(),
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'MediagraphWordPress/' . MEDIAGRAPH_VERSION . '; ' . home_url( '/' ),
		);

		$organization_id = $this->credentials->organization_id();

		if ( '' !== $organization_id ) {
			$headers['OrganizationId'] = $organization_id;
		}

		return $headers;
	}

	/**
	 * Turn a response into data or a WP_Error.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @param string         $endpoint Endpoint, for logging.
	 * @return array|WP_Error
	 */
	private function parse( $response, $endpoint ) {
		if ( is_wp_error( $response ) ) {
			Mediagraph_Logger::error(
				'Transport error',
				array(
					'endpoint' => $endpoint,
					'error'    => $response->get_error_message(),
				)
			);

			return new WP_Error(
				'mediagraph_unreachable',
				sprintf(
					/* translators: %s: underlying transport error. */
					__( 'Could not reach Mediagraph: %s', 'mediagraph-assets' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status >= 400 ) {
			Mediagraph_Logger::error(
				'API error',
				array(
					'endpoint' => $endpoint,
					'status'   => $status,
				)
			);

			return $this->error_from_status( $status, is_array( $data ) ? $data : array() );
		}

		if ( '' === trim( (string) $body ) ) {
			return array();
		}

		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'mediagraph_bad_json',
				__( 'Mediagraph returned a response WordPress could not read.', 'mediagraph-assets' )
			);
		}

		return is_array( $data ) ? $data : array( 'value' => $data );
	}

	/**
	 * Build a helpful error for an HTTP status.
	 *
	 * Generic "API request failed" text was one of the most confusing parts of
	 * 1.x; each status now says what to actually do.
	 *
	 * @param int   $status HTTP status code.
	 * @param array $data   Decoded body, when available.
	 * @return WP_Error
	 */
	private function error_from_status( $status, array $data ) {
		$detail = '';

		foreach ( array( 'error', 'message', 'error_description' ) as $key ) {
			if ( ! empty( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$detail = $data[ $key ];
				break;
			}
		}

		if ( '' === $detail && ! empty( $data['errors'] ) ) {
			$errors = $data['errors'];
			$detail = is_array( $errors ) ? implode( ' ', array_map( 'strval', (array) reset( $errors ) ) ) : (string) $errors;
		}

		switch ( true ) {
			case 401 === $status:
				$code    = 'mediagraph_session_expired';
				$message = __( 'The Mediagraph connection has expired. Reconnect under Settings → Mediagraph.', 'mediagraph-assets' );
				break;

			case 403 === $status:
				$code    = 'mediagraph_forbidden';
				$message = __( 'Your Mediagraph account does not have access to this. Check the permissions on the connected account.', 'mediagraph-assets' );
				break;

			case 404 === $status:
				$code    = 'mediagraph_not_found';
				$message = __( 'That asset is no longer available in Mediagraph.', 'mediagraph-assets' );
				break;

			case 429 === $status:
				$code    = 'mediagraph_rate_limited';
				$message = __( 'Mediagraph is rate limiting this site. Wait a moment and try again.', 'mediagraph-assets' );
				break;

			case $status >= 500:
				$code    = 'mediagraph_server_error';
				$message = __( 'Mediagraph had a server error. Try again shortly.', 'mediagraph-assets' );
				break;

			default:
				$code    = 'mediagraph_request_failed';
				$message = __( 'Mediagraph rejected the request.', 'mediagraph-assets' );
				break;
		}

		if ( '' !== $detail ) {
			$message .= ' (' . $detail . ')';
		}

		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Whether a response is a 401.
	 *
	 * @param array|WP_Error $response Raw response.
	 * @return bool
	 */
	private function is_unauthorized( $response ) {
		return ! is_wp_error( $response ) && 401 === (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Build a query string the Rails API parses the way we intend.
	 *
	 * Two array shapes mean different things and must not be encoded alike:
	 *
	 * - A **list** (rights_code, ids) has to use `key[]=a&key[]=b`. Rack only
	 *   produces a Ruby Array for that form; `key[0]=a` yields a Hash keyed
	 *   "0"/"1", and JSON-encoding the whole thing yields the literal string
	 *   `["a"]`, which matches nothing.
	 * - An **associative** array (custom_meta) is expected as a JSON blob,
	 *   because the endpoint runs JSON.parse on it.
	 *
	 * @param string $endpoint Path relative to the API base URL.
	 * @param array  $args     Query parameters.
	 * @return string
	 */
	private function build_url( $endpoint, array $args ) {
		$url   = $this->url_for( $endpoint );
		$pairs = array();

		foreach ( $args as $key => $value ) {
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}

			if ( is_bool( $value ) ) {
				$pairs[] = rawurlencode( $key ) . '=' . ( $value ? 'true' : 'false' );
				continue;
			}

			if ( is_array( $value ) ) {
				if ( $this->is_list( $value ) ) {
					foreach ( $value as $item ) {
						$pairs[] = rawurlencode( $key ) . '[]=' . rawurlencode( (string) $item );
					}
				} else {
					$pairs[] = rawurlencode( $key ) . '=' . rawurlencode( (string) wp_json_encode( $value ) );
				}

				continue;
			}

			$pairs[] = rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
		}

		if ( empty( $pairs ) ) {
			return $url;
		}

		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
	}

	/**
	 * Whether an array is a sequential list rather than a map.
	 *
	 * array_is_list() is PHP 8.1+; the plugin supports 7.4.
	 *
	 * @param array $value Array to test.
	 * @return bool
	 */
	private function is_list( array $value ) {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Remove a temporary file.
	 *
	 * @param string $file Path.
	 * @return void
	 */
	private function cleanup( $file ) {
		if ( $file && file_exists( $file ) ) {
			wp_delete_file( $file );
		}
	}
}
