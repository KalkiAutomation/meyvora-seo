<?php
/**
 * Open Graph meta tags. Output when enabled in settings.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI used for URL comparison only; escaped on output.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Open_Graph {

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	/**
	 * Attachment ID used for the current OG image (set by get_og_image() for dimension lookup).
	 *
	 * @var int
	 */
	protected int $og_image_attachment_id = 0;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Register hooks. Only frontend, only when enabled.
	 */
	public function register_hooks(): void {
		if ( is_admin() || ! $this->options->is_enabled( 'open_graph' ) ) {
			return;
		}
		$this->loader->add_action( 'wp_head', $this, 'output_tags', 5 );
	}

	/**
	 * Output Open Graph meta tags.
	 */
	public function output_tags(): void {
		$title       = $this->get_og_title();
		$description = $this->get_og_description();
		$image       = $this->get_og_image();
		$type = 'website';
		if ( is_singular() ) {
			$type = 'article';
			if ( function_exists( 'is_product' ) && is_product() ) {
				$type = 'product';
			}
		}
		$url = $this->get_current_url();

		$locale = str_replace( '-', '_', get_locale() );
		echo '<meta property="og:locale" content="' . esc_attr( $locale ) . '" />' . "\n";
		echo '<meta property="og:site_name" content="' . esc_attr( get_bloginfo( 'name', 'display' ) ) . '" />' . "\n";
		if ( $title !== '' ) {
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( $description !== '' ) {
			echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
		}
		if ( $image !== '' ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
			$dimensions = $this->get_og_image_dimensions();
			if ( $dimensions['width'] > 0 ) {
				echo '<meta property="og:image:width" content="' . esc_attr( (string) $dimensions['width'] ) . '" />' . "\n";
			}
			if ( $dimensions['height'] > 0 ) {
				echo '<meta property="og:image:height" content="' . esc_attr( (string) $dimensions['height'] ) . '" />' . "\n";
			}
			if ( $this->og_image_attachment_id > 0 ) {
				$alt = get_post_meta( $this->og_image_attachment_id, '_wp_attachment_image_alt', true );
				if ( is_string( $alt ) && $alt !== '' ) {
					echo '<meta property="og:image:alt" content="' . esc_attr( $alt ) . '" />' . "\n";
				}
			}
		}
		echo '<meta property="og:type" content="' . esc_attr( $type ) . '" />' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";

		if ( $type === 'product' && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product && $product->get_price() !== '' ) {
				echo '<meta property="product:price:amount" content="' . esc_attr( $product->get_price() ) . '" />' . "\n";
				echo '<meta property="product:price:currency" content="' . esc_attr( get_woocommerce_currency() ) . '" />' . "\n";
			}
		}

		if ( is_singular() ) {
			$post = get_post();
			if ( $post ) {
				if ( $post->post_date_gmt ) {
					echo '<meta property="article:published_time" content="' . esc_attr( gmdate( 'c', strtotime( $post->post_date_gmt ) ) ) . '" />' . "\n";
				}
				if ( $post->post_modified_gmt && $post->post_modified_gmt !== '0000-00-00 00:00:00' ) {
					echo '<meta property="article:modified_time" content="' . esc_attr( gmdate( 'c', strtotime( $post->post_modified_gmt ) ) ) . '" />' . "\n";
				}
				if ( is_singular( 'post' ) ) {
					// article:section — first primary category
					$categories = get_the_category( $post->ID );
					if ( ! empty( $categories ) ) {
						echo '<meta property="article:section" content="' . esc_attr( $categories[0]->name ) . '" />' . "\n";
					}
					// article:tag — all post tags
					$tags = get_the_tags( $post->ID );
					if ( is_array( $tags ) && ! empty( $tags ) ) {
						foreach ( $tags as $tag ) {
							echo '<meta property="article:tag" content="' . esc_attr( $tag->name ) . '" />' . "\n";
						}
					}
				}
				$author = get_the_author_meta( 'display_name', $post->post_author );
				if ( $author !== '' ) {
					echo '<meta property="article:author" content="' . esc_attr( $author ) . '" />' . "\n";
				}
			}
		}
	}

	/**
	 * @return string
	 */
	protected function get_og_title(): string {
		$pre = apply_filters( 'meyvora_seo_og_title', '', get_queried_object() );
		if ( is_string( $pre ) && $pre !== '' ) {
			return wp_strip_all_tags( $pre );
		}
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
	protected function get_og_description(): string {
		$pre = apply_filters( 'meyvora_seo_og_description', '', get_queried_object() );
		if ( is_string( $pre ) && $pre !== '' ) {
			return wp_strip_all_tags( $pre );
		}
		if ( is_singular() ) {
			$pid = get_queried_object_id();
			// Serve A/B test variant for og:description when a test is active.
			$ab_active = get_post_meta( $pid, MEYVORA_SEO_META_DESC_AB_ACTIVE, true );
			if ( $ab_active === 'a' || $ab_active === 'b' ) {
				$v_key   = $ab_active === 'a' ? MEYVORA_SEO_META_DESC_VARIANT_A : MEYVORA_SEO_META_DESC_VARIANT_B;
				$variant = function_exists( 'meyvora_seo_get_translated_post_meta' )
					? meyvora_seo_get_translated_post_meta( $pid, $v_key, true )
					: get_post_meta( $pid, $v_key, true );
				if ( is_string( $variant ) && $variant !== '' ) {
					return wp_strip_all_tags( $variant );
				}
			}
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

	/**
	 * @return string OG image URL or empty.
	 * Fallback order: (1) filter, (2) post OG image meta, (3) featured image, (4) site-level default from settings, (5) nothing.
	 * No "first attachment" fallback — that could be a PDF/audio and would produce invalid og:image.
	 */
	protected function get_og_image(): string {
		$this->og_image_attachment_id = 0;
		$pre = apply_filters( 'meyvora_seo_og_image', '', get_queried_object() );
		if ( is_string( $pre ) && $pre !== '' ) {
			return esc_url_raw( $pre );
		}
		if ( is_singular() ) {
			$pid     = get_queried_object_id();
			$get_meta = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? 'meyvora_seo_get_translated_post_meta' : 'get_post_meta';
			$img_id  = $get_meta( $pid, MEYVORA_SEO_META_OG_IMAGE, true );
			if ( $img_id && is_numeric( $img_id ) ) {
				$url = wp_get_attachment_image_url( (int) $img_id, 'full' );
				if ( $url ) {
					$this->og_image_attachment_id = (int) $img_id;
					return esc_url_raw( $url );
				}
			}
			// Featured image (post thumbnail) — always an image, never PDF/audio.
			$thumb_id = get_post_thumbnail_id( $pid );
			if ( $thumb_id > 0 ) {
				$url = wp_get_attachment_image_url( $thumb_id, 'full' );
				if ( $url ) {
					$this->og_image_attachment_id = $thumb_id;
					return esc_url_raw( $url );
				}
			}
		}
		// Site-level default OG image from settings (if set).
		$default_id = $this->options->get( 'og_default_image', '' );
		if ( $default_id && is_numeric( $default_id ) ) {
			$url = wp_get_attachment_image_url( (int) $default_id, 'full' );
			if ( $url ) {
				$this->og_image_attachment_id = (int) $default_id;
				return esc_url_raw( $url );
			}
		}
		return '';
	}

	/**
	 * Get OG image dimensions when OG image is stored as attachment ID.
	 *
	 * @return array{width: int, height: int}
	 */
	protected function get_og_image_dimensions(): array {
		$out = array( 'width' => 0, 'height' => 0 );
		$img_id = $this->og_image_attachment_id;
		if ( $img_id <= 0 ) {
			return $out;
		}
		$src = wp_get_attachment_image_src( $img_id, 'full' );
		if ( $src && isset( $src[1], $src[2] ) ) {
			$out['width']  = (int) $src[1];
			$out['height'] = (int) $src[2];
		}
		return $out;
	}

	/**
	 * @return string
	 */
	protected function get_current_url(): string {
		if ( is_singular() ) {
			return (string) get_permalink();
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		return esc_url( home_url( $uri ) );
	}
}
