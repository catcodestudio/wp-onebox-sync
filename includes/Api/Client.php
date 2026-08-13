<?php
/**
 * OneBox OS API v2 HTTP client.
 *
 * Every OneBox OS account lives on its own domain (mybox.crm-onebox.com or a
 * custom one). Auth is two-step:
 *   POST https://{domain}/api/v2/token/get/  { login, restapipassword }
 *     -> { status: 1, dataArray: { token } }
 * and every data call passes that token in a `token` HTTP header.
 *
 * Order create/update is a single upsert endpoint (matched by orderid or
 * externalid), body is an ARRAY of orders:
 *   POST /api/v2/order/set/   [ { externalid, name, products[], ... } ]
 *     -> { status: 1, dataArray: [ orderid ] }
 *
 * Docs: https://1b.app/ua/api/ + https://swagger.1b.app/
 *
 * @package CcOneboxSync
 */

namespace CatCode\OneboxSync\Api;

use CatCode\OneboxSync\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Client {

	/** Transient that caches the API token per (domain, login, password) hash. */
	private const TOKEN_TRANSIENT = 'cc_onebox_api_token';

	/** Token cache lifetime, seconds. */
	private const TOKEN_TTL = 3000;

	/** @var string Account domain, e.g. mybox.crm-onebox.com (no scheme). */
	private $domain;

	/** @var string API login. */
	private $login;

	/** @var string API password (as entered; md5 fallback is tried automatically). */
	private $password;

	public function __construct( ?string $domain = null, ?string $login = null, ?string $password = null ) {
		$this->domain   = self::normalize_domain( null !== $domain ? $domain : (string) Settings::get( 'domain', '' ) );
		$this->login    = trim( null !== $login ? $login : (string) Settings::get( 'api_login', '' ) );
		$this->password = trim( null !== $password ? $password : (string) Settings::get( 'api_password', '' ) );
	}

	/** Strip scheme/trailing slash so users may paste a full URL. */
	private static function normalize_domain( string $raw ): string {
		$raw = trim( $raw );
		$raw = preg_replace( '#^https?://#i', '', $raw );
		return rtrim( (string) $raw, "/ \t" );
	}

	private function base(): string {
		return 'https://' . $this->domain . '/api/v2';
	}

	private function is_configured(): bool {
		return '' !== $this->domain && '' !== $this->login && '' !== $this->password;
	}

	/**
	 * Create or update orders in OneBox (upsert by orderid/externalid).
	 *
	 * The root of the body must be a numeric-keyed OBJECT ({"0":{...}}), not a
	 * JSON array — OneBox rejects a plain array with "Відсутня перший елемент
	 * масиву процесів". Nested arrays (products etc.) stay arrays.
	 *
	 * @param array $orders Array of orderSet objects.
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	public function set_orders( array $orders ): array {
		return $this->request( '/order/set/', (object) array_values( $orders ) );
	}

	/**
	 * Look up an order by the externalid we send with every order.
	 *
	 * Used after a request that never came back: the process may exist in
	 * OneBox already, and creating a second one would duplicate the deal.
	 * Read endpoint is POST /order/get/ with a filter body.
	 *
	 * @param string $externalid External id, e.g. wc-123.
	 * @return array{ok:bool,id:int,status:int}
	 */
	public function find_order_by_external( string $externalid ): array {
		$res = $this->request(
			'/order/get/',
			array(
				'filter' => array( 'externalid' => array( $externalid ) ),
				'fields' => array( 'id', 'externalid' ),
			)
		);

		$id = 0;
		if ( $res['ok'] && isset( $res['json']['dataArray'] ) && is_array( $res['json']['dataArray'] ) ) {
			foreach ( $res['json']['dataArray'] as $row ) {
				// The filter is server-side, but never adopt a process that
				// belongs to a different order because of a loose match.
				if ( is_array( $row ) && (string) ( $row['externalid'] ?? '' ) === $externalid ) {
					$id = (int) ( $row['id'] ?? 0 );
					break;
				}
			}
		}

		return array(
			'ok'     => $res['ok'],
			'id'     => $id,
			'status' => $res['status'],
		);
	}

	/**
	 * Business process list — used for the workflowid fallback.
	 *
	 * @return array List of ['id' =>, 'name' =>] rows (may be empty).
	 */
	public function get_workflows(): array {
		$res = $this->request( '/workflow/get/', array() );
		if ( $res['ok'] && isset( $res['json']['dataArray'] ) && is_array( $res['json']['dataArray'] ) ) {
			return $res['json']['dataArray'];
		}
		return array();
	}

	/**
	 * Authenticated read used by the "test connection" button — the business
	 * process list is readable by any valid token.
	 *
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	public function test_connection(): array {
		delete_transient( self::TOKEN_TRANSIENT ); // Force a fresh token for the test.
		return $this->request( '/workflow/get/', array() );
	}

	/**
	 * Obtain (or reuse) the API token.
	 *
	 * OneBox expects `restapipassword`; classic setups use an md5 hash, so on a
	 * failed attempt with the raw value the md5 of it is tried once as well.
	 *
	 * @return array{token:string,error:string,status:int}
	 */
	private function token(): array {
		$cache_key = md5( $this->domain . '|' . $this->login . '|' . $this->password );
		$cached    = get_transient( self::TOKEN_TRANSIENT );
		if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === $cache_key && '' !== ( $cached['token'] ?? '' ) ) {
			return array(
				'token'  => (string) $cached['token'],
				'error'  => '',
				'status' => 200,
			);
		}

		$last = array(
			'token'  => '',
			'error'  => 'HTTP 0',
			'status' => 0,
		);
		$candidates = array( $this->password );
		if ( ! preg_match( '/^[a-f0-9]{32}$/i', $this->password ) ) {
			$candidates[] = md5( $this->password );
		}

		foreach ( $candidates as $secret ) {
			$res = $this->raw_post(
				$this->base() . '/token/get/',
				array(
					'login'           => $this->login,
					'restapipassword' => $secret,
				),
				array()
			);

			$token = '';
			if ( $res['ok'] && is_array( $res['json'] ) && 1 === (int) ( $res['json']['status'] ?? 0 ) ) {
				$token = (string) ( $res['json']['dataArray']['token'] ?? '' );
			}
			if ( '' !== $token ) {
				set_transient(
					self::TOKEN_TRANSIENT,
					array(
						'key'   => $cache_key,
						'token' => $token,
					),
					self::TOKEN_TTL
				);
				return array(
					'token'  => $token,
					'error'  => '',
					'status' => $res['status'],
				);
			}

			$last = array(
				'token'  => '',
				'error'  => '' !== $res['error'] ? $res['error'] : self::extract_error( $res['json'], $res['status'] ),
				'status' => $res['status'],
			);
		}

		return $last;
	}

	/**
	 * Send an authenticated API v2 request and normalise the response.
	 *
	 * @param string $path Path after /api/v2 (with trailing slash).
	 * @param array  $body JSON body.
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	private function request( string $path, $body ): array {
		if ( ! $this->is_configured() ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => '',
				'json'   => null,
				'error'  => __( 'The OneBox connection is not configured (domain, API login and password).', 'catcode-order-sync-with-onebox-for-woocommerce' ),
			);
		}

		$auth = $this->token();
		if ( '' === $auth['token'] ) {
			return array(
				'ok'     => false,
				'status' => $auth['status'],
				'body'   => '',
				'json'   => null,
				/* translators: %s — error details. */
				'error'  => sprintf( __( 'Could not obtain a OneBox API token: %s', 'catcode-order-sync-with-onebox-for-woocommerce' ), $auth['error'] ),
			);
		}

		$res = $this->raw_post( $this->base() . $path, $body, array( 'token' => $auth['token'] ) );

		// A stale cached token: refresh once and retry.
		if ( ! self::body_ok( $res ) && self::looks_like_auth_error( $res ) ) {
			delete_transient( self::TOKEN_TRANSIENT );
			$auth = $this->token();
			if ( '' !== $auth['token'] ) {
				$res = $this->raw_post( $this->base() . $path, $body, array( 'token' => $auth['token'] ) );
			}
		}

		$ok    = self::body_ok( $res );
		$error = $ok ? '' : ( '' !== $res['error'] ? $res['error'] : self::extract_error( $res['json'], $res['status'] ) );

		return array(
			'ok'     => $ok,
			'status' => $res['status'],
			'body'   => $res['body'],
			'json'   => $res['json'],
			'error'  => $error,
		);
	}

	/** Success = HTTP 2xx AND body {"status":1}. */
	private static function body_ok( array $res ): bool {
		return $res['ok'] && is_array( $res['json'] ) && 1 === (int) ( $res['json']['status'] ?? 0 );
	}

	private static function looks_like_auth_error( array $res ): bool {
		if ( 401 === $res['status'] || 403 === $res['status'] ) {
			return true;
		}
		$err = mb_strtolower( self::extract_error( $res['json'], $res['status'] ) );
		return false !== strpos( $err, 'token' ) || false !== strpos( $err, 'auth' );
	}

	/**
	 * Plain JSON POST, no token logic.
	 *
	 * @param string $url     Absolute URL.
	 * @param array  $body    JSON body.
	 * @param array  $headers Extra headers.
	 * @return array{ok:bool,status:int,body:string,json:?array,error:string}
	 */
	private function raw_post( string $url, $body, array $headers ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 30,
				'headers' => array_merge(
					array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					$headers
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'body'   => '',
				'json'   => null,
				'error'  => $response->get_error_message(),
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$raw     = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $raw, true );

		return array(
			'ok'     => $status >= 200 && $status < 300,
			'status' => $status,
			'body'   => $raw,
			'json'   => is_array( $decoded ) ? $decoded : null,
			'error'  => '',
		);
	}

	/** Build a human-readable error string from a OneBox errorArray body. */
	private static function extract_error( ?array $json, int $status ): string {
		if ( is_array( $json ) && ! empty( $json['errorArray'] ) ) {
			$parts = array();
			foreach ( (array) $json['errorArray'] as $field => $item ) {
				if ( is_scalar( $item ) ) {
					$parts[] = ( is_int( $field ) ? '' : $field . ': ' ) . (string) $item;
					continue;
				}
				if ( is_array( $item ) ) {
					$msg = '';
					foreach ( array( 'message', 'error', 'text' ) as $k ) {
						if ( ! empty( $item[ $k ] ) && is_scalar( $item[ $k ] ) ) {
							$msg = (string) $item[ $k ];
							break;
						}
					}
					if ( '' === $msg ) {
						$msg = wp_json_encode( $item, JSON_UNESCAPED_UNICODE );
					}
					$parts[] = ( is_int( $field ) ? '' : $field . ': ' ) . $msg;
				}
			}
			if ( $parts ) {
				return implode( '; ', array_slice( $parts, 0, 5 ) );
			}
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'Wrong API login or password.', 'catcode-order-sync-with-onebox-for-woocommerce' );
		}
		return 'HTTP ' . $status;
	}
}
