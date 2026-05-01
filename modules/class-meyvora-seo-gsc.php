<?php
/**
 * Google Search Console: OAuth2, top keywords per post, dashboard widget (last 28 days).
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_GSC {

	const OAUTH_SCOPE        = 'https://www.googleapis.com/auth/webmasters.readonly';
	const OAUTH_AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
	const OAUTH_TOKEN_URL    = 'https://oauth2.googleapis.com/token';
	const API_SITES          = 'https://www.googleapis.com/webmasters/v3/sites';
	const CACHE_DASHBOARD    = 'meyvora_gsc_dashboard';
	const CACHE_ACCESS_TOKEN = 'meyvora_gsc_access_token';
	const CACHE_KEYWORDS     = 'meyvora_gsc_keywords_';
	const CACHE_EXPIRY       = DAY_IN_SECONDS;
	const TOKEN_EXPIRY       = 3300; // 55 min

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
		if ( ! is_admin() ) {
			return;
		}
		$this->loader->add_action( 'add_meta_boxes', $this, 'add_gsc_sidebar_box', 10, 0 );
		$this->loader->add_action( 'wp_dashboard_setup', $this, 'add_dashboard_widget', 10, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_gsc_meta_box_script', 10, 1 );
		add_action( 'wp_ajax_meyvora_gsc_oauth_callback', array( $this, 'ajax_oauth_callback' ) );
		add_action( 'wp_ajax_meyvora_gsc_keywords', array( $this, 'ajax_keywords' ) );
		add_action( 'admin_init', array( $this, 'handle_oauth_redirect' ), 1 );
	}

	/**
	 * Enqueue script for GSC sidebar (fetch keywords via AJAX when not cached).
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue_gsc_meta_box_script( string $hook_suffix ): void {
		if ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) {
			return;
		}
		$screen = get_current_screen();
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		if ( ! $screen || ! in_array( $screen->post_type ?? '', $post_types, true ) ) {
			return;
		}
		$js_path = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-gsc-keywords-sidebar.js';
		if ( ! file_exists( $js_path ) ) {
			return;
		}
		wp_enqueue_script(
			'meyvora-gsc-keywords-sidebar',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-gsc-keywords-sidebar.js',
			array(),
			defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0',
			true
		);
		wp_localize_script(
			'meyvora-gsc-keywords-sidebar',
			'meyvoraGscSidebar',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'meyvora_gsc_keywords' ),
				'i18n'    => array(
					'empty' => __( 'No Search Console data for this page yet.', 'meyvora-seo' ),
					'error' => __( 'Could not load Search Console data.', 'meyvora-seo' ),
				),
			)
		);
	}

	/**
	 * Handle OAuth redirect: exchange code for tokens, then output closer script.
	 */
	public function handle_oauth_redirect(): void {
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'meyvora-seo-settings' ) {
			return;
		}
		if ( empty( $_GET['meyvora_gsc_oauth_callback'] ) || empty( $_GET['code'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( $state === '' || ! wp_verify_nonce( $state, 'meyvora_gsc_state' ) ) {
			$url = add_query_arg( array(
				'page'                  => 'meyvora-seo-settings',
				'meyvora_gsc_connected' => '0',
			), admin_url( 'admin.php' ) );
			$url .= '#tab-integrations';
			wp_safe_redirect( $url );
			exit;
		}
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
		$tokens = $this->exchange_code_for_tokens( $code );
		if ( ! empty( $tokens['refresh_token'] ) ) {
			$this->store_refresh_token( $tokens['refresh_token'] );
		}
		$url = add_query_arg( array(
			'page'                  => 'meyvora-seo-settings',
			'meyvora_gsc_connected' => $tokens ? '1' : '0',
		), admin_url( 'admin.php' ) );
		$url .= '#tab-integrations';
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * OAuth: exchange authorization code for tokens.
	 *
	 * @param string $code Authorization code from Google.
	 * @return array{access_token?: string, refresh_token?: string}
	 */
	protected function exchange_code_for_tokens( string $code ): array {
		$client_id     = $this->options->get( 'gsc_client_id', '' );
		$client_secret = $this->options->get( 'gsc_client_secret', '' );
		if ( $client_id === '' || $client_secret === '' ) {
			return array();
		}
		$redirect_uri = $this->get_redirect_uri();
		$response = wp_remote_post( self::OAUTH_TOKEN_URL, array(
			'timeout' => 15,
			'body'    => array(
				'code'          => $code,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'redirect_uri'  => $redirect_uri,
				'grant_type'    => 'authorization_code',
			),
		) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : array();
	}

	/**
	 * Get OAuth redirect URI (must match Google Cloud Console).
	 *
	 * @return string
	 */
	public function get_redirect_uri(): string {
		return add_query_arg( array(
			'page'                      => 'meyvora-seo-settings',
			'meyvora_gsc_oauth_callback' => '1',
		), admin_url( 'admin.php' ) );
	}

	/**
	 * Get OAuth authorization URL for "Connect Google" button.
	 *
	 * @return string
	 */
	public function get_auth_url(): string {
		$client_id = $this->options->get( 'gsc_client_id', '' );
		if ( $client_id === '' ) {
			return '';
		}
		return add_query_arg( array(
			'client_id'     => $client_id,
			'redirect_uri'  => $this->get_redirect_uri(),
			'response_type' => 'code',
			'scope'         => self::OAUTH_SCOPE,
			'access_type'   => 'offline',
			'prompt'        => 'consent',
			'state'         => wp_create_nonce( 'meyvora_gsc_state' ),
		), self::OAUTH_AUTH_URL );
	}

	public static function encrypt_token( string $value ): string {
		if ( $value === '' ) {
			return '';
		}
		$key = hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'meyvora_seo_fallback_key', true );
		$iv  = substr( hash( 'sha256', 'meyvora_seo_gsc_iv_' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ), true ), 0, 16 );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $cipher !== false ? base64_encode( $cipher ) : '';
	}

	public static function decrypt_token( string $encrypted ): string {
		if ( $encrypted === '' ) {
			return '';
		}
		$raw = base64_decode( $encrypted, true );
		if ( $raw === false ) {
			return '';
		}
		$key = hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'meyvora_seo_fallback_key', true );
		$iv  = substr( hash( 'sha256', 'meyvora_seo_gsc_iv_' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ), true ), 0, 16 );
		$dec = openssl_decrypt( $raw, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $dec !== false ? $dec : '';
	}

	protected function store_refresh_token( string $refresh_token ): void {
		update_option( MEYVORA_SEO_GSC_REFRESH_TOKEN_OPTION, self::encrypt_token( $refresh_token ), true );
		delete_transient( self::CACHE_ACCESS_TOKEN );
	}

	/**
	 * Check if GSC is connected (refresh token stored).
	 *
	 * @return bool
	 */
	public function is_connected(): bool {
		$enc = get_option( MEYVORA_SEO_GSC_REFRESH_TOKEN_OPTION, '' );
		return is_string( $enc ) && $enc !== '' && self::decrypt_token( $enc ) !== '';
	}

	/**
	 * Disconnect: clear stored token.
	 */
	public function disconnect(): void {
		delete_option( MEYVORA_SEO_GSC_REFRESH_TOKEN_OPTION );
		delete_transient( self::CACHE_ACCESS_TOKEN );
		delete_transient( self::CACHE_DASHBOARD );
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		foreach ( $post_types as $pt ) {
			// Clear keyword transients by pattern (we don't have a global list; optional flush).
		}
	}

	/**
	 * Get valid access token (from transient or refresh).
	 *
	 * @return string
	 */
	protected function get_access_token(): string {
		$cached = get_transient( self::CACHE_ACCESS_TOKEN );
		if ( is_string( $cached ) && $cached !== '' ) {
			return $cached;
		}
		return $this->refresh_access_token();
	}

	/**
	 * Refresh access token using stored refresh token. Call after 401 to retry.
	 *
	 * @return string New access token or empty on failure.
	 */
	protected function refresh_access_token(): string {
		$enc     = get_option( MEYVORA_SEO_GSC_REFRESH_TOKEN_OPTION, '' );
		$refresh = is_string( $enc ) ? self::decrypt_token( $enc ) : '';
		if ( $refresh === '' ) {
			return '';
		}
		$client_id     = $this->options->get( 'gsc_client_id', '' );
		$client_secret = $this->options->get( 'gsc_client_secret', '' );
		if ( $client_id === '' || $client_secret === '' ) {
			return '';
		}
		$response = wp_remote_post( self::OAUTH_TOKEN_URL, array(
			'timeout' => 15,
			'body'    => array(
				'refresh_token' => $refresh,
				'client_id'     => $client_id,
				'client_secret' => $client_secret,
				'grant_type'    => 'refresh_token',
			),
		) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '';
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$access = isset( $body['access_token'] ) && is_string( $body['access_token'] ) ? $body['access_token'] : '';
		if ( $access !== '' ) {
			set_transient( self::CACHE_ACCESS_TOKEN, $access, self::TOKEN_EXPIRY );
		}
		return $access;
	}

	/**
	 * Make authenticated request to Google API. On 401, refreshes the access token and retries once.
	 *
	 * @param string $url    Full URL.
	 * @param array  $body   Optional JSON body for POST.
	 * @param bool   $retried Internal: true when this is the retry after a 401 to prevent infinite loops.
	 * @return array|null Decoded JSON or null.
	 */
	protected function api_request( string $url, array $body = [], bool $retried = false ): ?array {
		$token = $this->get_access_token();
		if ( $token === '' ) {
			return null;
		}
		$args = array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( ! empty( $body ) ) {
			$args['body']   = wp_json_encode( $body );
			$args['method'] = 'POST';
		}
		$response = wp_remote_request( $url, $args );
		$code     = wp_remote_retrieve_response_code( $response );

		if ( $code === 401 && ! $retried ) {
			delete_transient( self::CACHE_ACCESS_TOKEN );
			$new_token = $this->refresh_access_token();
			if ( $new_token !== '' ) {
				return $this->api_request( $url, $body, true );
			}
			return null;
		}

		if ( is_wp_error( $response ) || $code !== 200 ) {
			return null;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Sort API rows by clicks descending.
	 *
	 * @param array<int, array> $rows
	 * @return array<int, array>
	 */
	protected function sort_rows_by_clicks( array $rows ): array {
		usort( $rows, function ( $a, $b ) {
			$c_a = isset( $a['clicks'] ) ? (int) $a['clicks'] : 0;
			$c_b = isset( $b['clicks'] ) ? (int) $b['clicks'] : 0;
			return $c_b <=> $c_a;
		} );
		return array_slice( $rows, 0, 10 );
	}

	/**
	 * Get first verified site URL from GSC (sc-domain or URL).
	 *
	 * @return string
	 */
	protected function get_site_url(): string {
		$data = $this->api_request( self::API_SITES );
		if ( ! isset( $data['siteEntry'] ) || ! is_array( $data['siteEntry'] ) ) {
			return '';
		}
		foreach ( $data['siteEntry'] as $entry ) {
			$url = isset( $entry['siteUrl'] ) ? $entry['siteUrl'] : '';
			if ( $url !== '' && ( strpos( $url, 'sc-domain:' ) === 0 || strpos( $url, 'https://' ) === 0 ) ) {
				return $url;
			}
		}
		return '';
	}

	/**
	 * Query Search Analytics (last 28 days by default, or custom date range).
	 *
	 * @param string   $site_url Site URL (e.g. https://example.com/).
	 * @param string[] $dimensions Dimensions, e.g. ['query'], ['page'].
	 * @param int      $row_limit Row limit.
	 * @param string   $page_filter Optional page filter (contains).
	 * @param string   $start_date Optional start date (Y-m-d). Empty = 28 days ago.
	 * @param string   $end_date Optional end date (Y-m-d). Empty = today.
	 * @return array{rows?: array}
	 */
	protected function search_analytics_query( string $site_url, array $dimensions, int $row_limit = 10, string $page_filter = '', string $start_date = '', string $end_date = '' ): array {
		$encoded = rawurlencode( $site_url );
		$url = self::API_SITES . '/' . $encoded . '/searchAnalytics/query';
		if ( $start_date !== '' && $end_date !== '' ) {
			$start = $start_date;
			$end   = $end_date;
		} else {
			$end_dt   = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
			$start_dt = clone $end_dt;
			$start_dt->modify( '-28 days' );
			$start = $start_dt->format( 'Y-m-d' );
			$end   = $end_dt->format( 'Y-m-d' );
		}
		$body = array(
			'startDate'  => $start,
			'endDate'    => $end,
			'dimensions' => $dimensions,
			'rowLimit'   => $row_limit,
		);
		if ( $page_filter !== '' ) {
			$body['dimensionFilterGroups'] = array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'contains',
							'expression' => $page_filter,
						),
					),
				),
			);
		}
		$result = $this->api_request( $url, $body );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Get search appearance types for a specific page URL (last 28 days).
	 * Uses searchAnalytics with dimensions searchAppearance and page filter.
	 *
	 * @param string $page_url Full page URL (e.g. https://example.com/post/).
	 * @return string[] Array of searchAppearance type strings (e.g. RICH_SNIPPET, VIDEO).
	 */
	public function get_search_appearance_for_page( string $page_url ): array {
		$site_url = $this->get_site_url();
		if ( $site_url === '' ) {
			return array();
		}
		$encoded = rawurlencode( $site_url );
		$url     = self::API_SITES . '/' . $encoded . '/searchAnalytics/query';
		$end     = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$start   = ( clone $end )->modify( '-28 days' );
		$body    = array(
			'startDate'  => $start->format( 'Y-m-d' ),
			'endDate'    => $end->format( 'Y-m-d' ),
			'dimensions' => array( 'searchAppearance' ),
			'rowLimit'   => 25,
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $page_url,
						),
					),
				),
			),
		);
		$result = $this->api_request( $url, $body );
		if ( ! is_array( $result ) || empty( $result['rows'] ) ) {
			return array();
		}
		return array_map(
			function ( $r ) {
				return (string) ( $r['keys'][0] ?? '' );
			},
			$result['rows']
		);
	}

	/**
	 * Dashboard widget data: top pages, top queries, totals (avg CTR, avg position).
	 *
	 * @return array{top_pages: array, top_queries: array, avg_ctr: float, avg_position: float, totals: array}
	 */
	public function get_dashboard_data(): array {
		$cached = get_transient( self::CACHE_DASHBOARD );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		$site_url = $this->get_site_url();
		if ( $site_url === '' ) {
			return array( 'top_pages' => array(), 'top_queries' => array(), 'avg_ctr' => 0.0, 'avg_position' => 0.0, 'totals' => array() );
		}
		$top_pages   = $this->search_analytics_query( $site_url, array( 'page' ), 10 );
		$top_queries = $this->search_analytics_query( $site_url, array( 'query' ), 10 );
		$totals_res  = $this->search_analytics_query( $site_url, array(), 1 );
		$totals = isset( $totals_res['rows'][0] ) ? $totals_res['rows'][0] : array();
		$impressions = isset( $totals['impressions'] ) ? (float) $totals['impressions'] : 0;
		$clicks = isset( $totals['clicks'] ) ? (float) $totals['clicks'] : 0;
		$ctr = isset( $totals['ctr'] ) ? (float) $totals['ctr'] : ( $impressions > 0 ? $clicks / $impressions : 0 );
		$position = isset( $totals['position'] ) ? (float) $totals['position'] : 0;
		$top_pages_list = isset( $top_pages['rows'] ) && is_array( $top_pages['rows'] ) ? $this->sort_rows_by_clicks( $top_pages['rows'] ) : array();
		$top_queries_list = isset( $top_queries['rows'] ) && is_array( $top_queries['rows'] ) ? $this->sort_rows_by_clicks( $top_queries['rows'] ) : array();
		$data = array(
			'top_pages'     => $top_pages_list,
			'top_queries'   => $top_queries_list,
			'avg_ctr'       => $ctr,
			'avg_position'  => $position,
			'totals'        => $totals,
		);
		set_transient( self::CACHE_DASHBOARD, $data, self::CACHE_EXPIRY );
		return $data;
	}

	/**
	 * Pages ranked 4–20 with impressions above threshold and CTR below 5% (CTR opportunities).
	 *
	 * @param int $limit Max number of opportunities to return.
	 * @return array<int, array{url: string, position: float, impressions: int, ctr: float, clicks: int}>
	 */
	public function get_ctr_opportunities( int $limit = 10 ): array {
		if ( ! $this->is_connected() ) {
			return array();
		}
		$site_url = $this->get_site_url();
		if ( $site_url === '' ) {
			return array();
		}
		$result = $this->search_analytics_query( $site_url, array( 'page' ), 50 );
		$rows   = isset( $result['rows'] ) && is_array( $result['rows'] ) ? $result['rows'] : array();
		$opportunities = array();
		foreach ( $rows as $row ) {
			$pos  = (float) ( $row['position'] ?? 0 );
			$impr = (int) ( $row['impressions'] ?? 0 );
			$ctr  = (float) ( $row['ctr'] ?? 0 );
			$url  = (string) ( $row['keys'][0] ?? '' );
			$clicks = (int) ( $row['clicks'] ?? 0 );
			if ( $pos >= 4 && $pos <= 20 && $impr >= 200 && $ctr < 0.05 ) {
				$opportunities[] = array(
					'url'         => $url,
					'position'    => round( $pos, 1 ),
					'impressions' => $impr,
					'ctr'         => round( $ctr * 100, 2 ),
					'clicks'      => $clicks,
				);
			}
		}
		usort( $opportunities, function ( $a, $b ) {
			return $b['impressions'] <=> $a['impressions'];
		} );
		return array_slice( $opportunities, 0, $limit );
	}

	/**
	 * Pages with ≥30% click drop vs previous 90-day window (current 0–90 days vs previous 91–181 days).
	 *
	 * @param int $limit Max number of decaying pages to return.
	 * @return array<int, array{url: string, curr: int, prev: int, drop_pct: float}>
	 */
	public function get_decaying_pages( int $limit = 20 ): array {
		if ( ! $this->is_connected() ) {
			return array();
		}
		$site_url = $this->get_site_url();
		if ( $site_url === '' ) {
			return array();
		}
		$now        = new DateTime( 'today', new DateTimeZone( 'UTC' ) );
		$start_curr = ( clone $now )->modify( '-90 days' );
		$end_curr   = clone $now;
		$start_prev = ( clone $now )->modify( '-181 days' );
		$end_prev   = ( clone $now )->modify( '-91 days' );
		$fetch = function ( DateTime $start, DateTime $end ) use ( $site_url ): array {
			$result = $this->search_analytics_query(
				$site_url,                    // 1: correct site URL
				array( 'page' ),              // 2: dimension = page
				200,                          // 3: row_limit — fetch up to 200 pages
				'',                          // 4: page_filter — none
				$start->format( 'Y-m-d' ),   // 5: start_date
				$end->format( 'Y-m-d' )      // 6: end_date
			);
			$map = array();
			foreach ( $result['rows'] ?? array() as $row ) {
				$url = (string) ( $row['keys'][0] ?? '' );
				if ( $url !== '' ) {
					$map[ $url ] = (int) ( $row['clicks'] ?? 0 );
				}
			}
			return $map;
		};
		$curr = $fetch( $start_curr, $end_curr );
		$prev = $fetch( $start_prev, $end_prev );
		$decaying = array();
		foreach ( $prev as $url => $prev_clicks ) {
			if ( $prev_clicks < 10 ) {
				continue;
			}
			$curr_clicks = $curr[ $url ] ?? 0;
			if ( $prev_clicks > 0 ) {
				$drop_pct = ( $prev_clicks - $curr_clicks ) / $prev_clicks * 100;
				if ( $drop_pct >= 30 ) {
					$decaying[] = array(
						'url'      => $url,
						'curr'     => $curr_clicks,
						'prev'     => $prev_clicks,
						'drop_pct' => round( $drop_pct, 1 ),
					);
				}
			}
		}
		usort( $decaying, function ( $a, $b ) {
			return $b['drop_pct'] <=> $a['drop_pct'];
		} );
		return array_slice( $decaying, 0, $limit );
	}

	/**
	 * Get clicks, impressions, and avg position for a single page (last 28 days).
	 * Used by Reports to show per-post GSC data.
	 *
	 * @param string $page_url Full URL or path (e.g. permalink or /my-post/).
	 * @return array{clicks: int, impressions: int, position: float}
	 */
	public function get_metrics_for_page( string $page_url ): array {
		$site_url = $this->get_site_url();
		if ( $site_url === '' ) {
			return array( 'clicks' => 0, 'impressions' => 0, 'position' => 0.0 );
		}
		$expression = $page_url;
		if ( strpos( $expression, 'http' ) !== 0 ) {
			$expression = rtrim( home_url( $expression ), '/' );
			if ( substr( $page_url, -1 ) === '/' ) {
				$expression .= '/';
			}
		}
		$res = $this->search_analytics_query_page_totals( $site_url, $expression );
		$row = isset( $res['rows'][0] ) ? $res['rows'][0] : array();
		return array(
			'clicks'      => isset( $row['clicks'] ) ? (int) $row['clicks'] : 0,
			'impressions' => isset( $row['impressions'] ) ? (int) $row['impressions'] : 0,
			'position'    => isset( $row['position'] ) ? (float) $row['position'] : 0.0,
		);
	}

	/**
	 * Query Search Analytics for one page's totals (no dimensions, filter by page equals).
	 *
	 * @param string $site_url   Site URL in GSC (e.g. https://example.com/).
	 * @param string $page_expr Full page URL for equals filter.
	 * @return array{rows?: array}
	 */
	protected function search_analytics_query_page_totals( string $site_url, string $page_expr ): array {
		$encoded = rawurlencode( $site_url );
		$url = self::API_SITES . '/' . $encoded . '/searchAnalytics/query';
		$end = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$start = clone $end;
		$start->modify( '-28 days' );
		$body = array(
			'startDate'  => $start->format( 'Y-m-d' ),
			'endDate'    => $end->format( 'Y-m-d' ),
			'dimensions' => array(),
			'rowLimit'   => 1,
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array(
							'dimension'  => 'page',
							'operator'   => 'equals',
							'expression' => $page_expr,
						),
					),
				),
			),
		);
		$result = $this->api_request( $url, $body );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * Get average position for a specific page URL and query (for Rank Tracker).
	 *
	 * @param string $page_url Full page URL (e.g. permalink).
	 * @param string $query    Search query (e.g. focus keyword).
	 * @param int    $days     Number of days to aggregate (default 7).
	 * @return float|null Average position or null if no data.
	 */
	public function get_position_for_page_and_query( string $page_url, string $query, int $days = 7 ): ?float {
		$site_url = $this->get_site_url();
		if ( $site_url === '' || $query === '' ) {
			return null;
		}
		$encoded = rawurlencode( $site_url );
		$url     = self::API_SITES . '/' . $encoded . '/searchAnalytics/query';
		$end     = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$start   = clone $end;
		$start->modify( '-' . max( 1, $days ) . ' days' );
		$body    = array(
			'startDate'  => $start->format( 'Y-m-d' ),
			'endDate'    => $end->format( 'Y-m-d' ),
			'dimensions' => array( 'page', 'query' ),
			'rowLimit'   => 1,
			'dimensionFilterGroups' => array(
				array(
					'filters' => array(
						array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => $page_url ),
						array( 'dimension' => 'query', 'operator' => 'equals', 'expression' => $query ),
					),
				),
			),
		);
		$result = $this->api_request( $url, $body );
		if ( ! isset( $result['rows'][0]['position'] ) ) {
			return null;
		}
		return (float) $result['rows'][0]['position'];
	}

	/**
	 * Top keywords for a given URL path (for current post).
	 *
	 * @param string $page_path Path or full URL to filter (e.g. /my-post/ or current permalink path).
	 * @return array<int, array{keys: array, clicks: int, impressions: int, ctr: float, position: float}>
	 */
	public function get_keywords_for_page( string $page_path ): array {
		$site_url = $this->get_site_url();
		if ( $site_url === '' ) {
			return array();
		}
		$path_for_filter = $page_path;
		if ( strpos( $path_for_filter, 'http' ) === 0 ) {
			$path_for_filter = wp_parse_url( $page_path, PHP_URL_PATH );
			$path_for_filter = $path_for_filter ?: $page_path;
		}
		$res = $this->search_analytics_query( $site_url, array( 'query' ), 10, $path_for_filter );
		return isset( $res['rows'] ) && is_array( $res['rows'] ) ? $res['rows'] : array();
	}

	/**
	 * All keywords the site ranks for (site-wide, no page filter).
	 *
	 * @param int $limit Max number of keywords to return.
	 * @return array<string, array{clicks: int, impressions: int, position: float}> Key = keyword (lowercase).
	 */
	public function get_all_keywords( int $limit = 500 ): array {
		if ( ! $this->is_connected() ) {
			return array();
		}
		$site_url = $this->get_site_url();
		if ( $site_url === '' ) {
			return array();
		}
		$result  = $this->search_analytics_query( $site_url, array( 'query' ), $limit );
		$keywords = array();
		foreach ( $result['rows'] ?? array() as $row ) {
			$q = strtolower( (string) ( $row['keys'][0] ?? '' ) );
			if ( $q !== '' ) {
				$keywords[ $q ] = array(
					'clicks'      => (int) ( $row['clicks'] ?? 0 ),
					'impressions' => (int) ( $row['impressions'] ?? 0 ),
					'position'    => (float) ( $row['position'] ?? 0 ),
				);
			}
		}
		return $keywords;
	}

	public function add_gsc_sidebar_box(): void {
		$screens = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'meyvora_seo_gsc',
				__( 'Search Console', 'meyvora-seo' ),
				array( $this, 'render_gsc_sidebar' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param WP_Post $post
	 */
	public function render_gsc_sidebar( WP_Post $post ): void {
		if ( ! $this->is_connected() ) {
			echo '<p class="mev-gsc-sidebar-text">' . esc_html__( 'Connect Google Search Console in Settings → Integrations to see top keywords here.', 'meyvora-seo' ) . '</p>';
			return;
		}
		$permalink = get_permalink( $post );
		if ( ! $permalink || $post->post_status === 'draft' || $post->post_status === 'private' ) {
			echo '<p class="mev-gsc-sidebar-text">' . esc_html__( 'Publish the post to see Search Console data for this URL.', 'meyvora-seo' ) . '</p>';
			return;
		}
		$cache_key = self::CACHE_KEYWORDS . $post->ID;
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$this->render_keywords_list( $cached );
			return;
		}
		echo '<div id="mev-gsc-keywords-wrap" data-post-id="' . (int) $post->ID . '" data-url="' . esc_attr( $permalink ) . '">';
		echo '<p class="mev-gsc-loading">' . esc_html__( 'Loading…', 'meyvora-seo' ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param array<int, array{keys?: array, clicks?: int, impressions?: int, ctr?: float, position?: float}> $rows
	 */
	protected function render_keywords_list( array $rows ): void {
		if ( empty( $rows ) ) {
			echo '<p class="mev-gsc-sidebar-text">' . esc_html__( 'No Search Console data for this page yet.', 'meyvora-seo' ) . '</p>';
			return;
		}
		echo '<ul class="mev-gsc-keywords-list">';
		foreach ( array_slice( $rows, 0, 10 ) as $row ) {
			$query = isset( $row['keys'][0] ) ? $row['keys'][0] : '';
			$clicks = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
			$impressions = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
			$position = isset( $row['position'] ) ? round( (float) $row['position'], 1 ) : '';
			echo '<li><span class="mev-gsc-kw-query">' . esc_html( $query ) . '</span>';
			echo ' <span class="mev-gsc-kw-meta">' . (int) $clicks . ' clicks, ' . (int) $impressions . ' impr.';
			if ( $position !== '' ) {
				echo ', pos. ' . esc_html( (string) $position );
			}
			echo '</span></li>';
		}
		echo '</ul>';
	}

	public function ajax_keywords(): void {
		check_ajax_referer( 'meyvora_gsc_keywords', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( $post_id <= 0 || $url === '' ) {
			wp_send_json_error( array( 'message' => 'Invalid post or URL' ) );
		}
		$cache_key = self::CACHE_KEYWORDS . $post_id;
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			wp_send_json_success( array( 'rows' => $cached ) );
		}
		$rows = $this->get_keywords_for_page( $url );
		set_transient( $cache_key, $rows, self::CACHE_EXPIRY );
		wp_send_json_success( array( 'rows' => $rows ) );
	}

	public function add_dashboard_widget(): void {
		if ( ! $this->is_connected() ) {
			return;
		}
		wp_add_dashboard_widget(
			'meyvora_gsc_widget',
			__( 'Search Console (last 28 days)', 'meyvora-seo' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget(): void {
		$data = $this->get_dashboard_data();
		$top_pages   = $data['top_pages'];
		$top_queries = $data['top_queries'];
		$avg_ctr     = $data['avg_ctr'];
		$avg_position = $data['avg_position'];
		?>
		<div class="mev-gsc-widget">
			<div class="mev-gsc-widget-totals">
				<span><?php echo esc_html( sprintf( /* translators: %s: average CTR percentage */ __( 'Avg. CTR: %s%%', 'meyvora-seo' ), number_format_i18n( $avg_ctr * 100, 2 ) ) ); ?></span>
				<span><?php echo esc_html( sprintf( /* translators: %s: average position number */ __( 'Avg. position: %s', 'meyvora-seo' ), number_format_i18n( $avg_position, 1 ) ) ); ?></span>
			</div>
			<div class="mev-gsc-widget-section">
				<h4><?php esc_html_e( 'Top 10 pages by clicks', 'meyvora-seo' ); ?></h4>
				<?php if ( empty( $top_pages ) ) : ?>
					<p><?php esc_html_e( 'No data yet.', 'meyvora-seo' ); ?></p>
				<?php else : ?>
					<ol>
						<?php foreach ( $top_pages as $row ) : ?>
							<li>
								<?php echo esc_html( isset( $row['keys'][0] ) ? $row['keys'][0] : '' ); ?>
								(<?php echo esc_html( (string) (int) ( $row['clicks'] ?? 0 ) ); ?> clicks)
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>
			<div class="mev-gsc-widget-section">
				<h4><?php esc_html_e( 'Top 10 queries', 'meyvora-seo' ); ?></h4>
				<?php if ( empty( $top_queries ) ) : ?>
					<p><?php esc_html_e( 'No data yet.', 'meyvora-seo' ); ?></p>
				<?php else : ?>
					<ol>
						<?php foreach ( $top_queries as $row ) : ?>
							<li>
								<?php echo esc_html( isset( $row['keys'][0] ) ? $row['keys'][0] : '' ); ?>
								(<?php echo esc_html( (string) (int) ( $row['clicks'] ?? 0 ) ); ?> clicks, pos. <?php echo esc_html( number_format_i18n( (float) ( $row['position'] ?? 0 ), 1 ) ); ?>)
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX oauth callback (optional; redirect flow is used).
	 */
	public function ajax_oauth_callback(): void {
		wp_send_json_error( array( 'message' => 'Use redirect flow' ) );
	}
}
