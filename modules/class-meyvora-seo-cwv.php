<?php
/**
 * Core Web Vitals via Google PageSpeed Insights API v5.
 * Fetches LCP, CLS, TBT (proxy for INP), FCP and stores in post meta.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_CWV
 */
class Meyvora_SEO_CWV {

	const API_URL      = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
	const CACHE_PREFIX = 'meyvora_cwv_';
	const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	const AJAX_ACTION  = 'meyvora_seo_cwv_test';
	const NONCE_ACTION = 'meyvora_seo_cwv_test';

	const LCP_PASS_MS = 2500;
	const CLS_PASS    = 0.1;
	const TBT_PASS_MS = 200;

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
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_cwv_test' ) );
	}

	/**
	 * Fetch Core Web Vitals from PageSpeed Insights API.
	 * Uses transient keyed by md5(url+strategy). Returns normalized result or null on failure.
	 *
	 * @param string $url      Full page URL to test.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @return array{lcp: float, cls: float, tbt: float, fcp: float, performance_score: int, passed: bool}|null
	 */
	public function fetch( string $url, string $strategy = 'mobile' ): ?array {
		$url = trim( $url );
		if ( $url === '' ) {
			return null;
		}
		$strategy = $strategy === 'desktop' ? 'desktop' : 'mobile';
		$cache_key = self::CACHE_PREFIX . md5( $url . $strategy );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['lcp'], $cached['passed'] ) ) {
			return $cached;
		}

		$api_url = add_query_arg(
			array(
				'url'      => $url,
				'strategy' => $strategy,
			),
			self::API_URL
		);
		$api_key = $this->options->get( 'pagespeed_api_key', '' );
		if ( is_string( $api_key ) && trim( $api_key ) !== '' ) {
			$api_url = add_query_arg( 'key', trim( $api_key ), $api_url );
		}

		$response = wp_remote_get( $api_url, array( 'timeout' => 60 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['lighthouseResult']['audits'] ) ) {
			return null;
		}

		$audits = $data['lighthouseResult']['audits'];
		$lcp_ms = isset( $audits['largest-contentful-paint']['numericValue'] ) ? (float) $audits['largest-contentful-paint']['numericValue'] : 0.0;
		$cls    = isset( $audits['cumulative-layout-shift']['numericValue'] ) ? (float) $audits['cumulative-layout-shift']['numericValue'] : 0.0;
		$tbt_ms = isset( $audits['total-blocking-time']['numericValue'] ) ? (float) $audits['total-blocking-time']['numericValue'] : 0.0;
		$fcp_ms = isset( $audits['first-contentful-paint']['numericValue'] ) ? (float) $audits['first-contentful-paint']['numericValue'] : 0.0;
		$score  = 0;
		if ( isset( $data['lighthouseResult']['categories']['performance']['score'] ) ) {
			$score = (int) round( (float) $data['lighthouseResult']['categories']['performance']['score'] * 100 );
		}
		$passed = $lcp_ms <= self::LCP_PASS_MS && $cls <= self::CLS_PASS && $tbt_ms <= self::TBT_PASS_MS;

		$result = array(
			'lcp'               => round( $lcp_ms, 2 ),
			'cls'               => round( $cls, 4 ),
			'tbt'               => round( $tbt_ms, 2 ),
			'fcp'               => round( $fcp_ms, 2 ),
			'performance_score' => $score,
			'passed'            => $passed,
			'strategy'          => $strategy,
			'ts'                => time(),
		);
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Fetch CWV for a post's permalink and save to post meta.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @return array{lcp: float, cls: float, tbt: float, fcp: float, performance_score: int, passed: bool}|null
	 */
	public function fetch_and_save( int $post_id, string $strategy = 'mobile' ): ?array {
		$permalink = get_permalink( $post_id );
		if ( ! $permalink || get_post_status( $post_id ) !== 'publish' ) {
			return null;
		}
		$result = $this->fetch( $permalink, $strategy );
		if ( $result !== null ) {
			update_post_meta( $post_id, MEYVORA_SEO_META_CWV, wp_json_encode( $result ) );
		}
		return $result;
	}

	/**
	 * Get cached CWV data from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array{lcp: float, cls: float, tbt: float, fcp: float, performance_score: int, passed: bool, strategy?: string, ts?: int}|null
	 */
	public function get_cached( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, MEYVORA_SEO_META_CWV, true );
		if ( $raw === '' || $raw === false ) {
			return null;
		}
		$dec = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		return is_array( $dec ) ? $dec : null;
	}

	/**
	 * AJAX handler: run fetch_and_save for given post_id and strategy, return result.
	 */
	public function ajax_cwv_test(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meyvora-seo' ) ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		$strategy_raw = isset( $_POST['strategy'] ) ? sanitize_key( wp_unslash( (string) $_POST['strategy'] ) ) : '';
		$strategy     = ( $strategy_raw === 'desktop' ) ? 'desktop' : 'mobile';
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post.', 'meyvora-seo' ) ) );
		}
		$result = $this->fetch_and_save( $post_id, $strategy );
		if ( $result === null ) {
			wp_send_json_error( array( 'message' => __( 'PageSpeed request failed or URL not accessible.', 'meyvora-seo' ) ) );
		}
		wp_send_json_success( $result );
	}
}
