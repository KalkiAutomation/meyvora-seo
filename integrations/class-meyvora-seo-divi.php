<?php
/**
 * Divi integration: extract text from shortcodes in post_content for SEO analysis.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Divi {

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
		if ( ! $post || strpos( $post->post_content, 'et_pb_' ) === false ) {
			return $content;
		}
		$extracted = self::extract_from_shortcodes( $post->post_content );
		if ( $extracted !== '' ) {
			return $extracted;
		}
		return $content;
	}

	/**
	 * Extract text and headings from Divi shortcodes.
	 *
	 * @param string $post_content Post content.
	 * @return string
	 */
	public static function extract_from_shortcodes( string $post_content ): string {
		$parts = array();
		// et_pb_text: content in inner content or admin_label
		if ( preg_match_all( '/\[et_pb_text[^\]]*\](.*?)\[\/et_pb_text\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $inner ) {
				$parts[] = wp_strip_all_tags( $inner );
			}
		}
		// et_pb_image: alt from attributes
		if ( preg_match_all( '/\[et_pb_image[^\]]*caption="([^"]*)"[^\]]*\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $cap ) {
				if ( $cap !== '' ) {
					$parts[] = $cap;
				}
			}
		}
		if ( preg_match_all( '/\[et_pb_image[^\]]*alt="([^"]*)"[^\]]*\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $alt ) {
				if ( $alt !== '' ) {
					$parts[] = $alt;
				}
			}
		}
		// et_pb_button: text=
		if ( preg_match_all( '/\[et_pb_button[^\]]*text="([^"]*)"[^\]]*\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $t ) {
				if ( $t !== '' ) {
					$parts[] = $t;
				}
			}
		}
		// et_pb_heading: content between tags
		if ( preg_match_all( '/\[et_pb_heading[^\]]*heading="([^"]*)"[^\]]*\]/s', $post_content, $m ) ) {
			foreach ( $m[1] as $h ) {
				if ( $h !== '' ) {
					$parts[] = $h;
				}
			}
		}
		// Fallback: strip all shortcodes and use remaining text
		$stripped = preg_replace( '/\[et_pb_[^\]]+\]/s', ' ', $post_content );
		$stripped = wp_strip_all_tags( $stripped );
		if ( trim( $stripped ) !== '' ) {
			$parts[] = $stripped;
		}
		return implode( "\n", array_filter( $parts ) );
	}
}
