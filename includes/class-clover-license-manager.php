<?php
/**
 * Clover licensing API client and option storage.
 *
 * Customers install the plugin and enter a license key only. The production API URL is built in;
 * no wp-config constant is required. Optional overrides:
 *
 * - {@see CLOVER_LICENSE_API_BASE} constant — staging or self-hosted licensing (highest priority).
 * - `clover_license_api_base` filter — programmatic override without wp-config.
 *
 * @package Clover_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages license activation, verification, and wp_options persistence.
 */
class Clover_License_Manager {

	/**
	 * Built-in production licensing API (no trailing slash). Change when you ship a new infrastructure URL.
	 *
	 * @var string
	 */
	private const BUILTIN_LICENSE_API_BASE = 'https://lightgoldenrodyellow-ape-709274.hostingersite.com/api';

	/**
	 * Licensing API base URL (no trailing slash). Set in constructor.
	 *
	 * @var string
	 */
	protected $api_base = '';

	/**
	 * wp_options key for stored license payload.
	 *
	 * @var string
	 */
	protected $option_key = 'clover_license_data';

	/**
	 * Option key: consecutive failed weekly verifications.
	 *
	 * @var string
	 */
	protected $fail_streak_option = 'clover_license_verify_fail_streak';

	/**
	 * Option key: set when weekly verify fails 3 times in a row.
	 *
	 * @var string
	 */
	protected $invalid_flag_option = 'clover_license_invalid';

	/**
	 * Constructor.
	 *
	 * Resolves API base: optional CLOVER_LICENSE_API_BASE constant, else `clover_license_api_base` filter on built-in URL.
	 */
	public function __construct() {
		if ( defined( 'CLOVER_LICENSE_API_BASE' ) && CLOVER_LICENSE_API_BASE ) {
			$this->api_base = untrailingslashit( (string) CLOVER_LICENSE_API_BASE );
			return;
		}
		/**
		 * Filters the licensing REST API base URL (no trailing slash).
		 *
		 * Only runs when {@see CLOVER_LICENSE_API_BASE} is not defined. Use for white-label or custom endpoints.
		 *
		 * @param string $url Default production URL bundled with the plugin.
		 */
		$this->api_base = untrailingslashit(
			(string) apply_filters( 'clover_license_api_base', self::BUILTIN_LICENSE_API_BASE )
		);
	}

	/**
	 * Activate a license key for this site.
	 *
	 * @param string $license_key License key.
	 * @return array{success:bool,message:string}
	 */
	public function activate( $license_key ) {
		$license_key = is_string( $license_key ) ? trim( $license_key ) : '';
		if ( '' === $license_key ) {
			return array(
				'success' => false,
				'message' => __( 'License key is required.', 'clover-gateway' ),
			);
		}

		$result = $this->request_json(
			'/license/activate',
			array(
				'license_key' => $license_key,
				'domain'      => $this->get_domain(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		$code = $result['code'];
		$body = $result['body'];

		if ( $code >= 200 && $code < 300 && ! empty( $body['success'] ) && ! empty( $body['token'] ) ) {
			$data = array(
				'key'             => $license_key,
				'token'           => $body['token'],
				'plan'            => isset( $body['plan'] ) ? $body['plan'] : '',
				'status'          => 'active',
				'last_verify_ok'  => true,
			);
			update_option( $this->option_key, $data, false );
			delete_option( $this->invalid_flag_option );
			update_option( $this->fail_streak_option, 0, false );

			$message = isset( $body['message'] ) ? (string) $body['message'] : __( 'License activated.', 'clover-gateway' );

			return array(
				'success' => true,
				'message' => $message,
			);
		}

		$message = isset( $body['error'] ) ? (string) $body['error'] : __( 'Activation failed.', 'clover-gateway' );

		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * Deactivate the stored license on this domain.
	 *
	 * @return array{success:bool,message:string}
	 */
	public function deactivate() {
		$data = $this->get_license_data();
		if ( empty( $data ) || empty( $data['key'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'No license is stored for this site.', 'clover-gateway' ),
			);
		}

		$result = $this->request_json(
			'/license/deactivate',
			array(
				'license_key' => $data['key'],
				'domain'      => $this->get_domain(),
			)
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'message' => $result->get_error_message(),
			);
		}

		$code = $result['code'];
		$body = $result['body'];

		if ( $code >= 200 && $code < 300 && ! empty( $body['success'] ) ) {
			delete_option( $this->option_key );
			delete_option( $this->invalid_flag_option );
			delete_option( $this->fail_streak_option );

			$message = isset( $body['message'] ) ? (string) $body['message'] : __( 'License deactivated.', 'clover-gateway' );

			return array(
				'success' => true,
				'message' => $message,
			);
		}

		$message = isset( $body['error'] ) ? (string) $body['error'] : __( 'Deactivation failed.', 'clover-gateway' );

		return array(
			'success' => false,
			'message' => $message,
		);
	}

	/**
	 * Verify the stored license with the API.
	 *
	 * @return bool True when the API returns valid: true.
	 */
	public function verify() {
		$data = $this->get_license_data();
		if ( empty( $data ) || empty( $data['key'] ) ) {
			return false;
		}

		$result = $this->request_json(
			'/license/verify',
			array(
				'license_key' => $data['key'],
				'domain'      => $this->get_domain(),
			)
		);

		if ( is_wp_error( $result ) ) {
			$this->persist_verify_result( $data, false );
			return false;
		}

		$body = $result['body'];
		$ok   = ! empty( $body['valid'] );
		$this->persist_verify_result( $data, $ok, $body );

		if ( $ok ) {
			delete_option( $this->invalid_flag_option );
			update_option( $this->fail_streak_option, 0, false );
		}

		return $ok;
	}

	/**
	 * Weekly cron: verify silently; track consecutive failures.
	 *
	 * @return void
	 */
	public function run_weekly_verify() {
		$data = $this->get_license_data();
		if ( empty( $data ) || empty( $data['key'] ) ) {
			return;
		}

		$ok = $this->verify();
		if ( $ok ) {
			return;
		}

		$streak = (int) get_option( $this->fail_streak_option, 0 );
		$streak++;
		update_option( $this->fail_streak_option, $streak, false );

		if ( $streak >= 3 ) {
			update_option( $this->invalid_flag_option, true, false );
		}
	}

	/**
	 * Stored license data or null.
	 *
	 * @return array<string,mixed>|null
	 */
	public function get_license_data() {
		$data = get_option( $this->option_key, null );
		if ( ! is_array( $data ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Whether the license is considered active for local checks.
	 *
	 * @return bool
	 */
	public function is_active() {
		$data = $this->get_license_data();
		if ( empty( $data ) ) {
			return false;
		}
		if ( ! empty( get_option( $this->invalid_flag_option, false ) ) ) {
			return false;
		}
		return ! empty( $data['last_verify_ok'] );
	}

	/**
	 * Merge verify outcome into stored license data.
	 *
	 * @param array<string,mixed>|null $data    Existing option data.
	 * @param bool                     $success Whether verification succeeded.
	 * @param array<string,mixed>      $body    Optional response body for plan updates.
	 * @return void
	 */
	protected function persist_verify_result( $data, $success, $body = array() ) {
		if ( null === $data ) {
			return;
		}
		$data['last_verify_ok'] = $success;
		if ( $success && isset( $body['plan'] ) ) {
			$data['plan'] = $body['plan'];
		}
		update_option( $this->option_key, $data, false );
	}

	/**
	 * Site identity for activation/verification (hostname only, lowercase).
	 * Matches the licensing API / Postman convention (e.g. example.com), not a full home_url().
	 *
	 * @return string
	 */
	protected function get_domain() {
		$home = home_url();
		$host  = wp_parse_url( $home, PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			return strtolower( $host );
		}
		$fallback = parse_url( $home, PHP_URL_HOST );
		if ( is_string( $fallback ) && '' !== $fallback ) {
			return strtolower( $fallback );
		}
		return strtolower( untrailingslashit( $home ) );
	}

	/**
	 * POST JSON to the licensing API.
	 *
	 * @param string               $path Relative path (e.g. /license/verify).
	 * @param array<string,mixed>  $data Request body.
	 * @return array{code:int,body:array<string,mixed>}|WP_Error
	 */
	protected function request_json( $path, $data ) {
		$base = untrailingslashit( (string) $this->api_base );
		$path = '/' . ltrim( (string) $path, '/' );
		$url  = $base . $path;

		$payload = wp_json_encode( $data );
		if ( false === $payload ) {
			return new WP_Error(
				'clover_license_encode',
				__( 'Could not build JSON for licensing request.', 'clover-gateway' )
			);
		}

		$args = array(
			'timeout' => 25,
			'headers' => array(
				'Content-Type'   => 'application/json; charset=utf-8',
				'Accept'         => 'application/json',
				// Avoid Brotli/br: many PHP/cURL stacks return binary bodies WP cannot decode → json_decode fails.
				'Accept-Encoding' => 'identity',
				'User-Agent'     => sprintf(
					'WordPress/%s Clover-Gateway (+https://wordpress.org/)',
					sanitize_text_field( (string) get_bloginfo( 'version' ) )
				),
			),
			'body'      => $payload,
			'sslverify' => apply_filters( 'clover_license_sslverify', true ),
		);

		// Ngrok free tier may return an HTML interstitial unless this header is sent; breaks JSON parsing.
		if ( false !== stripos( $this->api_base, 'ngrok' ) ) {
			$args['headers']['ngrok-skip-browser-warning'] = 'true';
		}

		/*
		 * WP’s cURL stack may advertise br (Brotli); some PHP builds then leave the body compressed,
		 * so json_decode fails. Restrict encodings for this URL only.
		 */
		$curl_encoding_fix = static function ( $handle, $r, $req_url ) use ( $url ) {
			if ( $req_url !== $url || ! function_exists( 'curl_setopt' ) ) {
				return;
			}
			$ok = ( is_resource( $handle ) || ( class_exists( 'CurlHandle', false ) && $handle instanceof \CurlHandle ) );
			if ( ! $ok ) {
				return;
			}
			// gzip + deflate + identity; omit br.
			@curl_setopt( $handle, \CURLOPT_ENCODING, 'gzip, deflate, identity' );
		};
		add_filter( 'http_api_curl', $curl_encoding_fix, 10, 3 );
		try {
			$response = wp_remote_post( $url, $args );
		} finally {
			remove_filter( 'http_api_curl', $curl_encoding_fix, 10 );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$ctype  = wp_remote_retrieve_header( $response, 'content-type' );
		$ctype  = is_string( $ctype ) ? $ctype : '';
		$raw    = wp_remote_retrieve_body( $response );
		$raw    = is_string( $raw ) ? $raw : '';
		$raw    = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		$raw    = trim( $raw );
		$body   = json_decode( $raw, true );
		$jerr   = json_last_error();

		if ( ! is_array( $body ) ) {
			$hint = '';
			if ( '' !== $raw ) {
				if ( preg_match( '/^\s*</i', $raw ) ) {
					$hint = ' ' . __( '(Server returned HTML, not JSON — check CLOVER_LICENSE_API_BASE ends with /api and points to the Express licensing API.)', 'clover-gateway' );
				}
			} elseif ( 0 === $code ) {
				$hint = ' ' . __( '(Empty response — connection or firewall issue.)', 'clover-gateway' );
			}
			$message = sprintf(
				/* translators: 1: HTTP status code, 2: Content-Type header or "unknown", 3: json_last_error_msg() */
				__( 'Invalid response from licensing server (HTTP %1$d, type: %2$s). %3$s', 'clover-gateway' ),
				$code,
				'' !== $ctype ? $ctype : __( 'unknown', 'clover-gateway' ),
				function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : (string) $jerr
			);
			$message .= $hint;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && '' !== $raw ) {
				$message .= ' ' . substr( preg_replace( '/\s+/', ' ', $raw ), 0, 200 );
			}
			return new WP_Error(
				'clover_license_bad_response',
				$message
			);
		}

		return array(
			'code' => $code,
			'body' => $body,
		);
	}
}
