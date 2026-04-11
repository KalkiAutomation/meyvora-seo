<?php
/**
 * Keyword Research panel: DataForSEO API integration for search volume and related keywords.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Keyword_Research
 */
class Meyvora_SEO_Keyword_Research {

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Options $options ) {
		$this->options = $options;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_meyvora_seo_keyword_research', array( $this, 'ajax_keyword_research' ) );
	}

	/**
	 * AJAX: fetch keyword data from DataForSEO (search volume + related keywords).
	 */
	public function ajax_keyword_research(): void {
		check_ajax_referer( 'meyvora_seo_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ) );
		}

		$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
		$keyword = trim( $keyword );
		if ( $keyword === '' ) {
			wp_send_json_error( array( 'message' => __( 'Enter a focus keyword first.', 'meyvora-seo' ) ) );
		}

		$api_key = $this->options->get( 'dataforseo_api_key', '' );
		if ( ! is_string( $api_key ) || $api_key === '' ) {
			wp_send_json_error( array( 'message' => __( 'DataForSEO API key not configured. Add it in Settings.', 'meyvora-seo' ) ) );
		}
		if ( strpos( $api_key, ':' ) === false ) {
			wp_send_json_error( array(
				'message' => __( 'DataForSEO API key must be in "login:password" format. Check Settings → AI.', 'meyvora-seo' ),
			) );
		}

		$auth   = base64_encode( $api_key );
		$header = array(
			'Authorization' => 'Basic ' . $auth,
			'Content-Type'  => 'application/json',
		);
		$loc = 2840;
		$lang = 'en';

		// 1) Search volume for the seed keyword (DataForSEO expects array of tasks)
		$body_sv = wp_json_encode( array(
			array(
				'keywords'      => array( $keyword ),
				'location_code' => $loc,
				'language_code' => $lang,
			),
		) );
		$resp_sv = wp_remote_post(
			'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live',
			array(
				'timeout' => 15,
				'headers' => $header,
				'body'    => $body_sv,
			)
		);

		$search_volume = 0;
		$competition   = 0.0;
		$cpc           = 0.0;
		if ( ! is_wp_error( $resp_sv ) && wp_remote_retrieve_response_code( $resp_sv ) === 200 ) {
			$sv_json   = json_decode( wp_remote_retrieve_body( $resp_sv ), true );
			$sv_result = $sv_json['tasks'][0]['result'][0] ?? array();
			$search_volume = isset( $sv_result['search_volume'] ) ? (int) $sv_result['search_volume'] : 0;
			$competition   = isset( $sv_result['competition'] ) ? (float) $sv_result['competition'] : 0.0;
			$cpc           = isset( $sv_result['cpc'] ) ? (float) $sv_result['cpc'] : 0.0;
		}

		// 2) Related keywords (up to 10)
		$body_rk = wp_json_encode( array(
			array(
				'keywords'      => array( $keyword ),
				'location_code' => $loc,
				'language_code' => $lang,
			),
		) );
		$resp_rk = wp_remote_post(
			'https://api.dataforseo.com/v3/keywords_data/google_ads/keywords_for_keywords/live',
			array(
				'timeout' => 15,
				'headers' => $header,
				'body'    => $body_rk,
			)
		);

		$suggestions = array();
		if ( ! is_wp_error( $resp_rk ) && wp_remote_retrieve_response_code( $resp_rk ) === 200 ) {
			$json_rk  = json_decode( wp_remote_retrieve_body( $resp_rk ), true );
			$rk_items = $json_rk['tasks'][0]['result'] ?? array();
			if ( is_array( $rk_items ) ) {
				$rk_items = array_slice( $rk_items, 0, 10 );
				foreach ( $rk_items as $item ) {
					$suggestions[] = array(
						'keyword'       => isset( $item['keyword'] ) ? (string) $item['keyword'] : '',
						'search_volume' => isset( $item['search_volume'] ) ? (int) $item['search_volume'] : 0,
						'competition'   => isset( $item['competition'] ) ? (float) $item['competition'] : 0.0,
						'cpc'           => isset( $item['cpc'] ) ? (float) $item['cpc'] : 0.0,
					);
				}
			}
		}
		if ( empty( $suggestions ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$kw_for_log = substr( sanitize_text_field( $keyword ), 0, 120 );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug only when WP_DEBUG; keyword sanitized.
			error_log( 'Meyvora SEO: keywords_for_keywords API returned no suggestions for "' . $kw_for_log . '"' );
		}

		wp_send_json_success( array(
			'keyword'       => $keyword,
			'search_volume' => $search_volume,
			'competition'   => $competition,
			'cpc'           => $cpc,
			'suggestions'   => $suggestions,
		) );
	}
}
