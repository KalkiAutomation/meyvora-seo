<?php
/**
 * Twitter Card meta tags. Output when enabled in settings.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Twitter_Cards {

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	/** @var int Twitter image attachment ID (for alt text). */
	protected int $twitter_image_attachment_id = 0;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Register hooks. Only frontend, only when enabled.
	 */
	public function register_hooks(): void {
		if ( is_admin() || ! $this->options->is_enabled( 'twitter_cards' ) ) {
			return;
		}
		$this->loader->add_action( 'wp_head', $this, 'output_tags', 5 );
	}

	/**
	 * Output Twitter Card meta tags.
	 */
	public function output_tags(): void {
		$title       = $this->get_twitter_title();
		$description = $this->get_twitter_description();
		$image_url   = $this->get_twitter_image_url();
		$card_type   = $image_url !== '' ? 'summary_large_image' : 'summary';

		echo '<meta name="twitter:card" content="' . esc_attr( $card_type ) . '" />' . "\n";
		$handle = $this->options->get( 'twitter_site_handle', '' );
		if ( is_string( $handle ) && trim( $handle ) !== '' ) {
			$handle = trim( $handle );
			if ( strpos( $handle, '@' ) !== 0 ) {
				$handle = '@' . $handle;
			}
			echo '<meta name="twitter:site" content="' . esc_attr( $handle ) . '" />' . "\n";
		}
		// twitter:creator: author's personal Twitter handle (from user profile meta).
		if ( is_singular() ) {
			$post = get_post();
			if ( $post ) {
				$creator_handle = get_user_meta( (int) $post->post_author, 'meyvora_seo_author_twitter_url', true );
				if ( is_string( $creator_handle ) && $creator_handle !== '' ) {
					// Extract handle from URL if it looks like a full URL, else treat as handle.
					if ( strpos( $creator_handle, 'twitter.com' ) !== false || strpos( $creator_handle, 'x.com' ) !== false ) {
						$parts = explode( '/', rtrim( $creator_handle, '/' ) );
						$creator_handle = '@' . end( $parts );
					} elseif ( strpos( $creator_handle, '@' ) !== 0 ) {
						$creator_handle = '@' . ltrim( $creator_handle, '@' );
					}
					echo '<meta name="twitter:creator" content="' . esc_attr( $creator_handle ) . '" />' . "\n";
				}
			}
		}
		if ( $title !== '' ) {
			echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( $description !== '' ) {
			echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $image_url !== '' ) {
			echo '<meta name="twitter:image" content="' . esc_url( $image_url ) . '" />' . "\n";
			if ( $this->twitter_image_attachment_id > 0 ) {
				$alt = get_post_meta( $this->twitter_image_attachment_id, '_wp_attachment_image_alt', true );
				if ( is_string( $alt ) && $alt !== '' ) {
					echo '<meta name="twitter:image:alt" content="' . esc_attr( $alt ) . '" />' . "\n";
				}
			}
		}
	}

	/**
	 * Get Twitter card image URL: twitter image meta → OG image meta → featured image.
	 *
	 * @return string
	 */
	protected function get_twitter_image_url(): string {
		$this->twitter_image_attachment_id = 0;
		if ( ! is_singular() ) {
			return '';
		}
		$pid = get_queried_object_id();
		$get_meta = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? 'meyvora_seo_get_translated_post_meta' : 'get_post_meta';
		$img_id   = $get_meta( $pid, MEYVORA_SEO_META_TWITTER_IMAGE, true );
		if ( $img_id && is_numeric( $img_id ) ) {
			$url = wp_get_attachment_image_url( (int) $img_id, 'full' );
			if ( $url ) {
				$this->twitter_image_attachment_id = (int) $img_id;
				return esc_url_raw( $url );
			}
		}
		$img_id = $get_meta( $pid, MEYVORA_SEO_META_OG_IMAGE, true );
		if ( $img_id && is_numeric( $img_id ) ) {
			$url = wp_get_attachment_image_url( (int) $img_id, 'full' );
			if ( $url ) {
				$this->twitter_image_attachment_id = (int) $img_id;
				return esc_url_raw( $url );
			}
		}
		$thumb_id = get_post_thumbnail_id( $pid );
		if ( $thumb_id ) {
			$url = wp_get_attachment_image_url( $thumb_id, 'full' );
			if ( $url ) {
				$this->twitter_image_attachment_id = (int) $thumb_id;
				return esc_url_raw( $url );
			}
		}
		return '';
	}

	/**
	 * @return string
	 */
	protected function get_twitter_title(): string {
		if ( is_singular() ) {
			$pid = get_queried_object_id();
			$og = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_OG_TITLE, true ) : get_post_meta( $pid, MEYVORA_SEO_META_OG_TITLE, true );
			if ( is_string( $og ) && $og !== '' ) {
				return wp_strip_all_tags( $og );
			}
			$custom = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_TITLE, true ) : get_post_meta( $pid, MEYVORA_SEO_META_TITLE, true );
			if ( $custom !== '' ) {
				return wp_strip_all_tags( $custom );
			}
			return wp_get_document_title();
		}
		if ( is_front_page() ) {
			return get_bloginfo( 'name', 'display' ) . ( get_bloginfo( 'description', 'display' ) ? ' - ' . get_bloginfo( 'description', 'display' ) : '' );
		}
		return wp_get_document_title();
	}

	/**
	 * @return string
	 */
	protected function get_twitter_description(): string {
		if ( is_singular() ) {
			$pid = get_queried_object_id();
			$og = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_OG_DESCRIPTION, true ) : get_post_meta( $pid, MEYVORA_SEO_META_OG_DESCRIPTION, true );
			if ( is_string( $og ) && $og !== '' ) {
				return wp_strip_all_tags( $og );
			}
			$custom = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_DESCRIPTION, true ) : get_post_meta( $pid, MEYVORA_SEO_META_DESCRIPTION, true );
			if ( $custom !== '' ) {
				return wp_strip_all_tags( $custom );
			}
			$post = get_post();
			if ( $post ) {
				return has_excerpt( $post->ID ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
			}
		}
		if ( is_front_page() ) {
			return get_bloginfo( 'description', 'display' );
		}
		return '';
	}
}
