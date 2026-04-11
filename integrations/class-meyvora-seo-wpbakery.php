<?php
/**
 * WPBakery (Visual Composer) integration: extract text from shortcodes for SEO analysis.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_WPBakery {

	/**
	 * Register filter for analysis content.
	 */
	public static function register(): void {
		add_filter( 'meyvora_seo_analysis_content', array( __CLASS__, 'filter_analysis_content' ), 10, 2 );
	}

	/**
	 * @param string $content Default content.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_analysis_content( string $content, int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $content;
		}
		if ( strpos( $post->post_content, 'vc_row' ) === false && strpos( $post->post_content, 'vc_column_text' ) === false && strpos( $post->post_content, 'vc_custom_heading' ) === false ) {
			return $content;
		}
		$extracted = self::extract_from_shortcodes( $post->post_content );
		if ( $extracted !== '' ) {
			return $extracted;
		}
		return $content;
	}

	/**
	 * Extract text from VC shortcodes.
	 *
	 * @param string $post_content Post content.
	 * @return string
	 */
	public static function extract_from_shortcodes( string $post_content ): string {
		$parts = array();
		// vc_column_text: inner content
		if ( preg_match_all( '/\[vc_column_text[^\]]*\](.*?)\[\/vc_column_text\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$parts[] = wp_kses_post( $inner );
			}
		}
		// vc_custom_heading: text= or inner
		if ( preg_match_all( '/\[vc_custom_heading[^\]]*text="([^"]*)"[^\]]*\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $t ) {
				if ( $t !== '' ) {
					$parts[] = '<h2>' . esc_html( $t ) . '</h2>';
				}
			}
		}
		if ( preg_match_all( '/\[vc_custom_heading[^\]]*\](.*?)\[\/vc_custom_heading\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $t ) {
				if ( trim( $t ) !== '' ) {
					$parts[] = '<h2>' . wp_kses_post( $t ) . '</h2>';
				}
			}
		}
		// vc_single_image: caption/alt
		if ( preg_match_all( '/\[vc_single_image[^\]]*caption="([^"]*)"[^\]]*\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $cap ) {
				if ( $cap !== '' ) {
					$parts[] = $cap;
				}
			}
		}
		// Raw text: strip vc_ shortcodes and use remainder
		$stripped = preg_replace( '/\[vc_[^\]]+\]/s', ' ', $post_content );
		$stripped = preg_replace( '/\[\/vc_[^\]]+\]/s', ' ', $stripped );
		$html = do_shortcode( $stripped );
		$plain = wp_strip_all_tags( $html );
		if ( trim( $plain ) !== '' ) {
			$parts[] = $plain;
		}
		return implode( "\n", array_filter( $parts ) );
	}
}
