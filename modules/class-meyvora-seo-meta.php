<?php
/**
 * Frontend meta output: document title, meta description, robots.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- REST/AJAX use nonce.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Meta {

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

	/**
	 * Register hooks. Only runs on frontend.
	 */
	public function register_hooks(): void {
		if ( is_admin() ) {
			return;
		}
		$this->loader->add_filter( 'document_title_parts', $this, 'filter_document_title', 10, 1 );
		$this->loader->add_action( 'wp_head', $this, 'output_canonical', 0 );
		$this->loader->add_action( 'wp_head', $this, 'output_meta_description', 1 );
		$this->loader->add_action( 'wp_head', $this, 'output_robots', 2 );
		if ( $this->options->get( 'strip_session_ids', true ) ) {
			$this->loader->add_action( 'template_redirect', $this, 'redirect_strip_session_ids', 0, 0 );
		}
		if ( $this->options->get( 'rss_append_link', true ) ) {
			$this->loader->add_filter( 'the_content_feed', $this, 'rss_append_permalink', 10, 2 );
		}
		$attachment_mode = $this->options->get( 'attachment_redirect', 'file' );
		if ( $attachment_mode !== 'none' ) {
			$this->loader->add_action( 'template_redirect', $this, 'redirect_attachment_pages', 1, 0 );
		}
		$this->loader->add_action( 'wp_head', $this, 'output_verification_tags', 0 );
	}

	/**
	 * Output search engine site verification meta tags.
	 */
	public function output_verification_tags(): void {
		$map = array(
			'verify_google'    => array( 'name' => 'google-site-verification' ),
			'verify_bing'      => array( 'name' => 'msvalidate.01' ),
			'verify_pinterest' => array( 'name' => 'p:domain_verify' ),
			'verify_yandex'    => array( 'name' => 'yandex-verification' ),
			'verify_baidu'     => array( 'name' => 'baidu-site-verification' ),
		);
		foreach ( $map as $option_key => $tag ) {
			$val = $this->options->get( $option_key, '' );
			if ( ! is_string( $val ) || trim( $val ) === '' ) {
				continue;
			}
			echo '<meta name="' . esc_attr( $tag['name'] ) . '" content="' . esc_attr( trim( $val ) ) . '" />' . "\n";
		}
	}

	/**
	 * Redirect to URL without session ID query params when strip_session_ids is enabled.
	 */
	public function redirect_strip_session_ids(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		if ( $uri === '' ) {
			return;
		}
		$strip = array( 'sid', 'sessionid', 'phpsessid', 'session_id' );
		$parsed = wp_parse_url( $uri );
		$query = isset( $parsed['query'] ) ? $parsed['query'] : '';
		if ( $query === '' ) {
			return;
		}
		parse_str( $query, $params );
		$changed = false;
		foreach ( $strip as $key ) {
			$key_lower = strtolower( $key );
			foreach ( array_keys( $params ) as $q ) {
				if ( strtolower( $q ) === $key_lower ) {
					unset( $params[ $q ] );
					$changed = true;
					break;
				}
			}
		}
		if ( ! $changed ) {
			return;
		}
		$path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
		$new_query = http_build_query( $params );
		$new_uri = $path . ( $new_query !== '' ? '?' . $new_query : '' );
		if ( $new_uri !== $uri ) {
			wp_safe_redirect( home_url( $new_uri ), 301 );
			exit;
		}
	}

	/**
	 * Redirect attachment pages to the file URL or parent post.
	 * Controlled by option 'attachment_redirect': 'file' | 'parent' | 'none'.
	 */
	public function redirect_attachment_pages(): void {
		if ( ! is_attachment() ) {
			return;
		}
		$mode = $this->options->get( 'attachment_redirect', 'file' );
		if ( $mode === 'none' ) {
			return;
		}
		if ( $mode === 'parent' ) {
			$post = get_post();
			if ( $post && $post->post_parent > 0 ) {
				$parent_url = get_permalink( $post->post_parent );
				if ( $parent_url ) {
					wp_safe_redirect( $parent_url, 301 );
					exit;
				}
			}
		}
		// Default: redirect to the file URL.
		$url = wp_get_attachment_url( get_the_ID() );
		if ( $url ) {
			wp_safe_redirect( $url, 301 );
			exit;
		}
	}

	/**
	 * Append permalink to post content in RSS feed when rss_append_link is enabled.
	 *
	 * @param string $content Post content.
	 * @param string $feed_type Feed type (e.g. 'rss2').
	 * @return string
	 */
	public function rss_append_permalink( string $content, string $feed_type ): string {
		if ( ! is_feed() ) {
			return $content;
		}
		$permalink = get_permalink();
		if ( $permalink && $content !== '' ) {
			$content .= "\n<p><a href=\"" . esc_url( $permalink ) . '">' . esc_html__( 'View full post', 'meyvora-seo' ) . '</a></p>';
		}
		return $content;
	}

	/**
	 * Output canonical link when set for singular.
	 */
	public function output_canonical(): void {
		// Singular: custom canonical override or permalink.
		if ( is_singular() ) {
			$pid = get_queried_object_id();
			$canonical = function_exists( 'meyvora_seo_get_translated_post_meta' )
				? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_CANONICAL, true )
				: get_post_meta( $pid, MEYVORA_SEO_META_CANONICAL, true );
			if ( is_string( $canonical ) && $canonical !== '' ) {
				$canonical = esc_url( $canonical );
				if ( $canonical !== '' ) {
					echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
				}
				return;
			}
			$post = get_post();
			if ( $post ) {
				$url = get_permalink( $post );
				if ( $url ) {
					echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
				}
			}
			return;
		}
		// Archives, taxonomy, author, search: output canonical pointing to current paged URL.
		// This prevents crawlers treating paginated pages as duplicate content.
		if ( is_front_page() ) {
			echo '<link rel="canonical" href="' . esc_url( home_url( '/' ) ) . '" />' . "\n";
			return;
		}
		if ( is_home() ) {
			$posts_page_id = (int) get_option( 'page_for_posts' );
			if ( $posts_page_id > 0 ) {
				echo '<link rel="canonical" href="' . esc_url( get_permalink( $posts_page_id ) ) . '" />' . "\n";
			}
			return;
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && isset( $term->term_id ) ) {
				$url = get_term_link( $term );
				if ( ! is_wp_error( $url ) ) {
					$paged = (int) get_query_var( 'paged' );
					if ( $paged > 1 ) {
						$url = trailingslashit( $url ) . 'page/' . $paged . '/';
					}
					echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
				}
			}
			return;
		}
		if ( is_author() ) {
			$user = get_queried_object();
			if ( $user && isset( $user->ID ) ) {
				$url = get_author_posts_url( $user->ID );
				$paged = (int) get_query_var( 'paged' );
				if ( $paged > 1 ) {
					$url = trailingslashit( $url ) . 'page/' . $paged . '/';
				}
				echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
			}
			return;
		}
		if ( is_search() ) {
			$url = get_search_link( get_search_query() );
			$paged = (int) get_query_var( 'paged' );
			if ( $paged > 1 ) {
				$url = trailingslashit( $url ) . 'page/' . $paged . '/';
			}
			echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
			return;
		}
		if ( is_date() ) {
			global $wp;
			$url = home_url( add_query_arg( array(), $wp->request ) );
			echo '<link rel="canonical" href="' . esc_url( trailingslashit( $url ) ) . '" />' . "\n";
		}
	}

	/**
	 * Filter document title for singular and home. Per-post meta overrides, then template, then fallback.
	 *
	 * @param array<string, string> $title_parts Title parts (title, page, tagline).
	 * @return array<string, string>
	 */
	public function filter_document_title( array $title_parts ): array {
		$title_override = apply_filters( 'meyvora_seo_document_title_override', '', get_queried_object(), $title_parts );
		if ( is_string( $title_override ) && $title_override !== '' ) {
			$title_parts['title'] = wp_strip_all_tags( $title_override );
			return $title_parts;
		}

		if ( is_singular() ) {
			$pid = get_queried_object_id();
			$custom = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_TITLE, true ) : get_post_meta( $pid, MEYVORA_SEO_META_TITLE, true );
			if ( $custom !== '' ) {
				$title_parts['title'] = wp_strip_all_tags( $custom );
				return $title_parts;
			}
			$title = $this->build_title_from_template( get_post_type() );
			if ( $title !== '' ) {
				$title_parts['title'] = wp_strip_all_tags( $title );
			}
			return $title_parts;
		}

		if ( is_front_page() && is_home() ) {
			$title_parts['title'] = get_bloginfo( 'name', 'display' );
			if ( get_bloginfo( 'description', 'display' ) ) {
				$title_parts['tagline'] = get_bloginfo( 'description', 'display' );
			}
		}

		return $title_parts;
	}

	/**
	 * Build title from option template for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return string
	 */
	protected function build_title_from_template( string $post_type ): string {
		$key = $post_type . '_title_template';
		if ( $post_type === 'product' ) {
			$key = 'product_title_template';
		} elseif ( $post_type !== 'post' && $post_type !== 'page' ) {
			$key = 'post_title_template';
		}
		$template = $this->options->get( $key, '' );
		if ( $template === '' ) {
			return '';
		}
		$post = get_post();
		if ( ! $post ) {
			return '';
		}
		$sep = $this->options->get( 'title_separator', '|' );
		$site_name = get_bloginfo( 'name', 'display' );
		$replace = array(
			'{title}'       => $post->post_title,
			'{separator}'   => $sep,
			'{site_title}'  => $site_name,
		);
		if ( $post_type === 'product' ) {
			$category = '';
			if ( taxonomy_exists( 'product_cat' ) ) {
				$terms = get_the_terms( $post->ID, 'product_cat' );
				if ( is_array( $terms ) && ! empty( $terms ) ) {
					$first = reset( $terms );
					$category = $first->name;
				}
			}
			$replace['{product_name}'] = $post->post_title;
			$replace['{category}']     = $category;
			$replace['{site_name}']    = $site_name;
		}
		$result = str_replace( array_keys( $replace ), array_values( $replace ), $template );
		return wp_strip_all_tags( $result );
	}

	/**
	 * Output meta description in head.
	 */
	public function output_meta_description(): void {
		$desc = $this->get_meta_description();
		if ( $desc === '' ) {
			return;
		}
		echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
	}

	/**
	 * Get meta description for current request. Per-post first, then template, then fallback.
	 *
	 * @return string
	 */
	protected function get_meta_description(): string {
		$override = apply_filters( 'meyvora_seo_meta_description_override', '', get_queried_object() );
		if ( is_string( $override ) && $override !== '' ) {
			return wp_strip_all_tags( $override );
		}

		if ( is_singular() ) {
			$pid = get_queried_object_id();
			$custom = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_DESCRIPTION, true ) : get_post_meta( $pid, MEYVORA_SEO_META_DESCRIPTION, true );
			$ab_active = get_post_meta( $pid, MEYVORA_SEO_META_DESC_AB_ACTIVE, true );
			if ( $ab_active === 'a' || $ab_active === 'b' ) {
				$variant_key = $ab_active === 'a' ? MEYVORA_SEO_META_DESC_VARIANT_A : MEYVORA_SEO_META_DESC_VARIANT_B;
				$variant = get_post_meta( $pid, $variant_key, true );
				if ( is_string( $variant ) && $variant !== '' ) {
					return wp_strip_all_tags( $variant );
				}
			}
			if ( $custom !== '' ) {
				return wp_strip_all_tags( $custom );
			}
			$post = get_post();
			if ( $post ) {
				$key = $post->post_type . '_desc_template';
				if ( $post->post_type === 'product' ) {
					$key = 'product_desc_template';
				} elseif ( $post->post_type !== 'post' && $post->post_type !== 'page' ) {
					$key = 'post_desc_template';
				}
				$template = $this->options->get( $key, '' );
				if ( $template === '' || strpos( $template, '{excerpt}' ) === false ) {
					$excerpt = has_excerpt( $post->ID ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
					$desc   = $excerpt !== '' ? $excerpt : '';
				} else {
					$excerpt = has_excerpt( $post->ID ) ? wp_strip_all_tags( get_the_excerpt( $post ) ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
					$desc   = str_replace( '{excerpt}', $excerpt, $template );
				}
				return wp_strip_all_tags( (string) apply_filters( 'meyvora_seo_singular_meta_description', $desc, $post ) );
			}
		}

		if ( is_front_page() && is_home() ) {
			return get_bloginfo( 'description', 'display' );
		}

		return '';
	}

	/**
	 * Output robots meta tag when noindex/nofollow apply.
	 */
	public function output_robots(): void {
		$directives = array();

		if ( is_singular() ) {
			$pid = get_queried_object_id();
			$noindex = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_NOINDEX, true ) : get_post_meta( $pid, MEYVORA_SEO_META_NOINDEX, true );
			if ( (bool) $noindex ) {
				$directives[] = 'noindex';
			}
			$nofollow = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_NOFOLLOW, true ) : get_post_meta( $pid, MEYVORA_SEO_META_NOFOLLOW, true );
			if ( (bool) $nofollow ) {
				$directives[] = 'nofollow';
			}
			// noodp is saved by the meta box but was missing from frontend output.
			$noodp = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_NOODP, true ) : get_post_meta( $pid, MEYVORA_SEO_META_NOODP, true );
			if ( (bool) $noodp ) {
				$directives[] = 'noodp';
			}
			// noarchive, nosnippet, max-snippet (planned features, now wired up).
			if ( defined( 'MEYVORA_SEO_META_NOARCHIVE' ) ) {
				$noarchive = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_NOARCHIVE, true ) : get_post_meta( $pid, MEYVORA_SEO_META_NOARCHIVE, true );
				if ( (bool) $noarchive ) {
					$directives[] = 'noarchive';
				}
			}
			if ( defined( 'MEYVORA_SEO_META_NOSNIPPET' ) ) {
				$nosnippet = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_NOSNIPPET, true ) : get_post_meta( $pid, MEYVORA_SEO_META_NOSNIPPET, true );
				if ( (bool) $nosnippet ) {
					$directives[] = 'nosnippet';
				}
			}
			if ( defined( 'MEYVORA_SEO_META_MAX_SNIPPET' ) ) {
				$max_snippet = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_MAX_SNIPPET, true ) : get_post_meta( $pid, MEYVORA_SEO_META_MAX_SNIPPET, true );
				if ( $max_snippet !== '' && $max_snippet !== false && $max_snippet !== null ) {
					$max_snippet_int = (int) $max_snippet;
					if ( $max_snippet_int >= -1 ) {
						$directives[] = 'max-snippet:' . $max_snippet_int;
					}
				}
			}
			if ( defined( 'MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW' ) ) {
				$max_img = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW, true ) : get_post_meta( $pid, MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW, true );
				if ( is_string( $max_img ) && in_array( $max_img, array( 'none', 'standard', 'large' ), true ) ) {
					$directives[] = 'max-image-preview:' . $max_img;
				}
			}
			if ( defined( 'MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW' ) ) {
				$max_vid = function_exists( 'meyvora_seo_get_translated_post_meta' ) ? meyvora_seo_get_translated_post_meta( $pid, MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW, true ) : get_post_meta( $pid, MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW, true );
				if ( $max_vid !== '' && $max_vid !== false && $max_vid !== null ) {
					$max_vid_int = (int) $max_vid;
					if ( $max_vid_int >= -1 ) {
						$directives[] = 'max-video-preview:' . $max_vid_int;
					}
				}
			}
		}

		if ( is_search() && $this->options->get( 'noindex_search', true ) ) {
			$directives[] = 'noindex';
		}
		if ( is_author() && $this->options->get( 'noindex_author_archives', false ) ) {
			$directives[] = 'noindex';
		}
		if ( is_date() && $this->options->get( 'noindex_date_archives', true ) ) {
			$directives[] = 'noindex';
		}
		$replytocom = isset( $_GET['replytocom'] ) ? absint( $_GET['replytocom'] ) : 0;
		if ( $replytocom > 0 && $this->options->get( 'noindex_replytocom', true ) ) {
			$directives[] = 'noindex';
		}

		if ( empty( $directives ) ) {
			return;
		}
		$content = implode( ', ', array_unique( $directives ) );
		echo '<meta name="robots" content="' . esc_attr( $content ) . '" />' . "\n";
	}
}