<?php
/**
 * Google Analytics 4: outputs gtag.js on the front end only (no admin-side GA calls).
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_GA4
 */
class Meyvora_SEO_GA4 {

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
	}

	/**
	 * Output gtag.js in wp_head (public site only).
	 */
	public function output_gtag(): void {
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
	 * Encrypt service account JSON for storage (legacy imports / settings merge).
	 *
	 * @param string $json Raw JSON.
	 * @return string Base64 ciphertext or empty string.
	 */
	public static function encrypt_credentials( string $json ): string {
		if ( $json === '' ) {
			return '';
		}
		$key = hash( 'sha256', defined( 'AUTH_KEY' ) ? AUTH_KEY : 'meyvora_seo_fallback_key', true );
		$iv  = substr( hash( 'sha256', 'meyvora_seo_ga4_iv_' . ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ), true ), 0, 16 );
		$cipher = openssl_encrypt( $json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return $cipher !== false ? base64_encode( $cipher ) : '';
	}
}
