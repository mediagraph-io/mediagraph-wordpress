<?php
/**
 * Credential store.
 *
 * Single source of truth for the connection: tokens, expiry, and the cached
 * identity we got back from /api/whoami. Nothing else in the plugin reads the
 * mediagraph_* options directly, so token state can never go stale in one
 * place while being fresh in another.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin's connection state.
 */
class Mediagraph_Credentials {

	const ACCESS_TOKEN  = 'mediagraph_access_token';
	const REFRESH_TOKEN = 'mediagraph_refresh_token';
	const EXPIRES_AT    = 'mediagraph_token_expires_at';
	const CREATED_AT    = 'mediagraph_token_created_at';
	const SCOPE         = 'mediagraph_token_scope';

	const USER_ID   = 'mediagraph_user_id';
	const USER_NAME = 'mediagraph_user_name';
	const USER_MAIL = 'mediagraph_user_email';

	const ORG_ID   = 'mediagraph_organization_id';
	const ORG_NAME = 'mediagraph_organization_name';
	const ORG_SLUG = 'mediagraph_organization_slug';
	const ROLE     = 'mediagraph_membership_role';

	const API_BASE_URL = 'mediagraph_api_base_url';
	const PLATFORM     = 'mediagraph_platform';

	const DEFAULT_API_BASE_URL = 'https://mediagraph.io';

	/**
	 * Seconds before real expiry at which we treat a token as expired.
	 *
	 * @var int
	 */
	const EXPIRY_GRACE = 300;

	/**
	 * In-request memo so repeated get_option calls do not add up.
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * Configured API base URL, always without a trailing slash.
	 *
	 * @return string
	 */
	public function api_base_url() {
		$url = get_option( self::API_BASE_URL, self::DEFAULT_API_BASE_URL );
		$url = is_string( $url ) && '' !== trim( $url ) ? trim( $url ) : self::DEFAULT_API_BASE_URL;

		return untrailingslashit( $url );
	}

	/**
	 * Browser-facing base URL.
	 *
	 * Docker development points the server at host.docker.internal, which the
	 * user's browser cannot resolve.
	 *
	 * @return string
	 */
	public function browser_base_url() {
		return str_replace( 'host.docker.internal', 'localhost', $this->api_base_url() );
	}

	/**
	 * Current access token.
	 *
	 * Always read through this method; never cache the value in a constructor.
	 *
	 * @return string
	 */
	public function access_token() {
		return (string) get_option( self::ACCESS_TOKEN, '' );
	}

	/**
	 * Current refresh token.
	 *
	 * @return string
	 */
	public function refresh_token() {
		return (string) get_option( self::REFRESH_TOKEN, '' );
	}

	/**
	 * Whether the site has any stored token at all.
	 *
	 * @return bool
	 */
	public function has_token() {
		return '' !== $this->access_token();
	}

	/**
	 * Whether the connection is usable right now.
	 *
	 * A stored-but-expired token that can still be refreshed counts as
	 * connected: the client will refresh transparently on the next call.
	 *
	 * @return bool
	 */
	public function is_connected() {
		if ( ! $this->has_token() ) {
			return false;
		}

		return ! $this->is_expired() || '' !== $this->refresh_token();
	}

	/**
	 * Whether the access token is expired (or within the grace window).
	 *
	 * Tokens with no recorded expiry are assumed live; the 401 retry in the
	 * client is the backstop.
	 *
	 * @return bool
	 */
	public function is_expired() {
		$expires_at = (int) get_option( self::EXPIRES_AT, 0 );

		if ( $expires_at <= 0 ) {
			return false;
		}

		return ( time() + self::EXPIRY_GRACE ) >= $expires_at;
	}

	/**
	 * Whether a refresh is even possible.
	 *
	 * @return bool
	 */
	public function can_refresh() {
		return '' !== $this->refresh_token();
	}

	/**
	 * Unix timestamp the token expires at, or 0 when unknown.
	 *
	 * @return int
	 */
	public function expires_at() {
		return (int) get_option( self::EXPIRES_AT, 0 );
	}

	/**
	 * Persist a token response from the OAuth server.
	 *
	 * Doorkeeper rotates refresh tokens, so an absent refresh_token in a
	 * refresh response must not wipe the one we already hold.
	 *
	 * @param array $tokens Decoded token endpoint response.
	 * @return void
	 */
	public function store_tokens( array $tokens ) {
		if ( empty( $tokens['access_token'] ) ) {
			return;
		}

		update_option( self::ACCESS_TOKEN, (string) $tokens['access_token'], false );

		if ( ! empty( $tokens['refresh_token'] ) ) {
			update_option( self::REFRESH_TOKEN, (string) $tokens['refresh_token'], false );
		}

		if ( ! empty( $tokens['scope'] ) ) {
			update_option( self::SCOPE, (string) $tokens['scope'], false );
		}

		$created_at = ! empty( $tokens['created_at'] ) ? (int) $tokens['created_at'] : time();

		if ( ! empty( $tokens['expires_in'] ) ) {
			update_option( self::EXPIRES_AT, $created_at + (int) $tokens['expires_in'], false );
		} else {
			delete_option( self::EXPIRES_AT );
		}

		update_option( self::CREATED_AT, $created_at, false );

		$this->memo = array();
	}

	/**
	 * Store the identity returned by /api/whoami.
	 *
	 * @param array $whoami Decoded whoami response.
	 * @return void
	 */
	public function store_identity( array $whoami ) {
		$this->set_if( self::USER_ID, isset( $whoami['id'] ) ? $whoami['id'] : null );
		$this->set_if( self::USER_NAME, isset( $whoami['name'] ) ? $whoami['name'] : null );
		$this->set_if( self::USER_MAIL, isset( $whoami['email'] ) ? $whoami['email'] : null );

		$org = isset( $whoami['organization'] ) && is_array( $whoami['organization'] )
			? $whoami['organization']
			: array();

		// Organizations expose "title" as their display name; there is no
		// "name" column on the model.
		$this->set_if( self::ORG_ID, isset( $org['id'] ) ? $org['id'] : null );
		$this->set_if( self::ORG_NAME, isset( $org['title'] ) ? $org['title'] : null );
		$this->set_if( self::ORG_SLUG, isset( $org['slug'] ) ? $org['slug'] : null );

		if ( isset( $whoami['membership']['role_level'] ) ) {
			$this->set_if( self::ROLE, $whoami['membership']['role_level'] );
		}

		$this->memo = array();
	}

	/**
	 * Mediagraph organization ID this site is bound to.
	 *
	 * @return string
	 */
	public function organization_id() {
		return (string) get_option( self::ORG_ID, '' );
	}

	/**
	 * Mediagraph organization display name.
	 *
	 * @return string
	 */
	public function organization_name() {
		return (string) get_option( self::ORG_NAME, '' );
	}

	/**
	 * Mediagraph organization slug, which every in-app URL is scoped by.
	 *
	 * @return string
	 */
	public function organization_slug() {
		return (string) get_option( self::ORG_SLUG, '' );
	}

	/**
	 * Browser URL for an asset inside the Mediagraph app.
	 *
	 * The app scopes everything by organization slug and addresses a single
	 * asset through a hash route under /explore, so the shape is:
	 *
	 *     https://mediagraph.io/<org-slug>/explore#/assets/<guid>
	 *
	 * Built from the browser base URL rather than the API one, because a person
	 * clicks this — a Docker host.docker.internal API address would not resolve
	 * for them.
	 *
	 * Returns an empty string when the slug is unknown, so callers can omit the
	 * link instead of offering one that 404s.
	 *
	 * @param string $guid Asset GUID.
	 * @return string
	 */
	public function asset_url( $guid ) {
		$guid = trim( (string) $guid );
		$slug = $this->organization_slug();

		if ( '' === $guid || '' === $slug ) {
			return '';
		}

		return sprintf(
			'%s/%s/explore#/assets/%s',
			untrailingslashit( $this->browser_base_url() ),
			rawurlencode( $slug ),
			rawurlencode( $guid )
		);
	}

	/**
	 * Configured publishing platform.
	 *
	 * @return string
	 */
	public function platform() {
		$platform = (string) get_option( self::PLATFORM, 'wordpress' );

		return '' !== $platform ? $platform : 'wordpress';
	}

	/**
	 * Everything the settings screen needs to describe the connection.
	 *
	 * @return array
	 */
	public function connection_info() {
		return array(
			'connected'         => $this->is_connected(),
			'has_token'         => $this->has_token(),
			'expired'           => $this->is_expired(),
			'can_refresh'       => $this->can_refresh(),
			'user_name'         => (string) get_option( self::USER_NAME, '' ),
			'user_email'        => (string) get_option( self::USER_MAIL, '' ),
			'organization_id'   => $this->organization_id(),
			'organization_name' => $this->organization_name(),
			'membership_role'   => (string) get_option( self::ROLE, '' ),
			'token_created_at'  => (int) get_option( self::CREATED_AT, 0 ),
			'token_expires_at'  => $this->expires_at(),
			'scope'             => (string) get_option( self::SCOPE, '' ),
		);
	}

	/**
	 * Forget the connection entirely.
	 *
	 * @return void
	 */
	public function forget() {
		$options = array(
			self::ACCESS_TOKEN,
			self::REFRESH_TOKEN,
			self::EXPIRES_AT,
			self::CREATED_AT,
			self::SCOPE,
			self::USER_ID,
			self::USER_NAME,
			self::USER_MAIL,
			self::ORG_ID,
			self::ORG_NAME,
			self::ORG_SLUG,
			self::ROLE,
		);

		foreach ( $options as $option ) {
			delete_option( $option );
		}

		Mediagraph_Cache::flush();

		$this->memo = array();
	}

	/**
	 * Write an option only when the incoming value is meaningful.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Candidate value.
	 * @return void
	 */
	private function set_if( $option, $value ) {
		if ( null === $value || '' === $value ) {
			return;
		}

		update_option( $option, is_scalar( $value ) ? (string) $value : $value, false );
	}
}
