<?php
/**
 * Google Analytics 4: simple (gtag.js) and advanced (Data API + Views column).
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_GA4 {

	const GA4_DATA_API_URL = 'https://analyticsdata.googleapis.com/v1beta/properties/%s:runReport';
	const CACHE_VIEWS_MAP  = 'meyvora_ga4_views_map';
	const CACHE_EXPIRY     = DAY_IN_SECONDS;

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		$this->loader->add_action( 'wp_enqueue_scripts', $this, 'output_gtag', 10, 0 );
		if ( is_admin() ) {
			$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
			foreach ( $post_types as $post_type ) {
				$this->loader->add_filter( "manage_{$post_type}_posts_columns", $this, 'add_views_column', 10, 1 );
				$this->loader->add_action( "manage_{$post_type}_posts_custom_column", $this, 'render_views_column', 10, 2 );
			}
		}
	}

	/**
	 * Output gtag.js in wp_head (simple mode).
	 */
	public function output_gtag(): void {
		$mode = $this->options->get( 'ga4_mode', 'simple' );
		if ( $mode !== 'simple' ) {
			return;
		}
		$measurement_id = $this->options->get( 'ga4_measurement_id', '' );
		$measurement_id = is_string( $measurement_id ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', $measurement_id ) : '';
		if ( $measurement_id === '' ) {
			return;
		}
		if ( $this->options->get( 'ga4_exclude_admins', true ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = 'https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $measurement_id );
		wp_enqueue_script(
			'meyvora-seo-gtag',
			$url,
			array(),
			defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0',
			false
		);
		wp_script_add_data( 'meyvora-seo-gtag', 'async', true );
		$config_options = array(
			'anonymize_ip' => true,
		);
		$config_options = apply_filters( 'meyvora_seo_ga4_config_options', $config_options, $measurement_id );
		$config_json    = wp_json_encode( $config_options );
		$inline_script  = 'window.dataLayer = window.dataLayer || [];'
			. 'function gtag(){dataLayer.push(arguments);}'
			. 'gtag("js", new Date());'
			. 'gtag("consent", "default", {'
			. '"analytics_storage": "denied",'
			. '"ad_storage": "denied",'
			. '"wait_for_update": 500'
			. '});'
			. 'gtag("config", "' . esc_js( $measurement_id ) . '", ' . $config_json . ');';
		wp_add_inline_script( 'meyvora-seo-gtag', $inline_script, 'after' );
	}

	/**
	 * Check if GA4 advanced mode is active and has credentials.
	 *
	 * @return bool
	 */
	public function is_advanced_connected(): bool {
		if ( $this->options->get( 'ga4_mode', 'simple' ) !== 'advanced' ) {
			return false;
		}
		$prop = $this->options->get( 'ga4_property_id', '' );
		$creds = $this->options->get( 'ga4_credentials_encrypted', '' );
		return $prop !== '' && $creds !== '';
	}

	/**
	 * Add "Views (30d)" column when advanced mode.
	 *
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_views_column( array $columns ): array {
		if ( ! $this->is_advanced_connected() ) {
			return $columns;
		}
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['meyvora_ga4_views'] = __( 'Views (30d)', 'meyvora-seo' );
			}
		}
		if ( ! isset( $new['meyvora_ga4_views'] ) ) {
			$new['meyvora_ga4_views'] = __( 'Views (30d)', 'meyvora-seo' );
		}
		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_views_column( string $column, int $post_id ): void {
		if ( $column !== 'meyvora_ga4_views' ) {
			return;
		}
		$map = $this->get_views_map();
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			echo '<span style="color:var(--mev-gray-400);">—</span>';
			return;
		}
		$path = wp_parse_url( get_permalink( $post_id ), PHP_URL_PATH );
		$path = $path ?: '/';
		if ( substr( $path, -1 ) !== '/' && $path !== '/' ) {
			$path_trailing = $path . '/';
		} else {
			$path_trailing = $path;
		}
		$views = isset( $map[ $path ] ) ? (int) $map[ $path ] : ( isset( $map[ $path_trailing ] ) ? (int) $map[ $path_trailing ] : 0 );
		echo $views > 0 ? (int) $views : '<span style="color:var(--mev-gray-400);">0</span>';
	}

	/**
	 * Get top pages by GA4 pageviews (for dashboard).
	 *
	 * @param int $limit Maximum number of items to return.
	 * @return array<int, array{path: string, views: int}>
	 */
	public function get_top_posts_by_views( int $limit = 5 ): array {
		if ( ! $this->is_advanced_connected() ) {
			return array();
		}
		$map = $this->get_views_map();
		$list = array();
		foreach ( $map as $path => $views ) {
			$list[] = array( 'path' => $path, 'views' => (int) $views );
		}
		usort( $list, function ( $a, $b ) {
			return $b['views'] <=> $a['views'];
		} );
		return array_slice( $list, 0, $limit );
	}

	/**
	 * Get path => views map from cache or API.
	 *
	 * @return array<string, int>
	 */
	protected function get_views_map(): array {
		$cached = get_transient( self::CACHE_VIEWS_MAP );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$map = $this->fetch_views_from_api();
		set_transient( self::CACHE_VIEWS_MAP, $map, self::CACHE_EXPIRY );
		return $map;
	}

	/**
	 * Call GA4 Data API runReport (pagePath + screenPageViews, last 30 days).
	 *
	 * @return array<string, int>
	 */
	protected function fetch_views_from_api(): array {
		$property_id = $this->options->get( 'ga4_property_id', '' );
		$enc = $this->options->get( 'ga4_credentials_encrypted', '' );
		if ( $property_id === '' || $enc === '' ) {
			return array();
		}
		$json = $this->decrypt_credentials( $enc );
		if ( $json === '' ) {
			return array();
		}
		$creds = json_decode( $json, true );
		if ( ! is_array( $creds ) || empty( $creds['client_email'] ) || empty( $creds['private_key'] ) ) {
			return array();
		}
		$access_token = $this->get_service_account_token( $creds );
		if ( $access_token === '' ) {
			return array();
		}
		$url = sprintf( self::GA4_DATA_API_URL, $property_id );
		$end = new DateTime( 'today', new DateTimeZone( 'UTC' ) );
		$start = clone $end;
		$start->modify( '-30 days' );
		$body = array(
			'dimensions' => array( array( 'name' => 'pagePath' ) ),
			'metrics'    => array( array( 'name' => 'screenPageViews' ) ),
			'dateRanges' => array(
				array(
					'startDate' => $start->format( 'Y-m-d' ),
					'endDate'   => $end->format( 'Y-m-d' ),
				),
			),
		);
		$response = wp_remote_post( $url, array(
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['rows'] ) ) {
			return array();
		}
		$map = array();
		foreach ( $data['rows'] as $row ) {
			$path = isset( $row['dimensionValues'][0]['value'] ) ? $row['dimensionValues'][0]['value'] : '';
			$val = isset( $row['metricValues'][0]['value'] ) ? (int) $row['metricValues'][0]['value'] : 0;
			if ( $path !== '' ) {
				$map[ $path ] = ( $map[ $path ] ?? 0 ) + $val;
			}
		}
		return $map;
	}

	/**
	 * Get JWT access token for service account (GA4 Data API).
	 *
	 * @param array{client_email: string, private_key: string} $creds
	 * @return string
	 */
	protected function get_service_account_token( array $creds ): string {
		$email = $creds['client_email'] ?? '';
		$key = $creds['private_key'] ?? '';
		if ( $email === '' || $key === '' ) {
			return '';
		}
		$now = time();
		$payload = array(
			'iss'   => $email,
			'sub'   => $email,
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + 3600,
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
		);
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
		$segments = array(
			$this->base64_url_encode( wp_json_encode( $header ) ),
			$this->base64_url_encode( wp_json_encode( $payload ) ),
		);
		$signature_input = implode( '.', $segments );
		$sig = '';
		$key_parsed = openssl_pkey_get_private( $key );
		if ( $key_parsed === false ) {
			return '';
		}
		$ok = openssl_sign( $signature_input, $sig, $key_parsed, OPENSSL_ALGO_SHA256 );
		// openssl_free_key() deprecated in PHP 8.0, removed in 8.3.
		// In PHP 8+, key resources are freed automatically when the variable goes out of scope.
		if ( PHP_MAJOR_VERSION < 8 ) {
			openssl_free_key( $key_parsed ); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
		}
		unset( $key_parsed );
		if ( ! $ok ) {
			return '';
		}
		$segments[] = $this->base64_url_encode( $sig );
		$jwt = implode( '.', $segments );
		$response = wp_remote_post( 'https://oauth2.googleapis.com/token', array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $body['access_token'] ) ? $body['access_token'] : '';
	}

	private function base64_url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	public static function encrypt_credentials( string $json ): string {
		if ( $json === '' ) {
			return '';
		}
		$key = hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'meyvora_seo_fallback_key', true );
		$iv  = substr( hash( 'sha256', 'meyvora_seo_ga4_iv_' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ), true ), 0, 16 );
		$cipher = openssl_encrypt( $json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $cipher !== false ? base64_encode( $cipher ) : '';
	}

	protected function decrypt_credentials( string $encrypted ): string {
		if ( $encrypted === '' ) {
			return '';
		}
		$raw = base64_decode( $encrypted, true );
		if ( $raw === false ) {
			return '';
		}
		$key = hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'meyvora_seo_fallback_key', true );
		$iv  = substr( hash( 'sha256', 'meyvora_seo_ga4_iv_' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ), true ), 0, 16 );
		$dec = openssl_decrypt( $raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $dec !== false ? $dec : '';
	}

	/**
	 * Clear GA4 advanced cache and optionally credentials.
	 */
	public function disconnect(): void {
		delete_transient( self::CACHE_VIEWS_MAP );
	}
}
