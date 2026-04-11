<?php
/**
 * Multilingual SEO: hreflang output, per-language meta keys, sitemap hreflang.
 * Supports WPML and Polylang.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML/Polylang filter names are third-party.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Multilingual {

	const OPTION_HREFLANG = 'hreflang_enabled';
	const OPTION_SITEMAP_HREFLANG = 'sitemap_hreflang_enabled';

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
		if ( ! $this->is_multilingual_active() ) {
			return;
		}
		$this->loader->add_action( 'wp_head', $this, 'output_hreflang_tags', 3, 0 );
		$this->loader->add_filter( 'meyvora_seo_post_meta_key', $this, 'filter_meta_key_for_language', 10, 2 );
		add_filter( 'meyvora_seo_sitemap_url_entry', array( $this, 'sitemap_add_hreflang_links' ), 10, 4 );
		$this->loader->add_filter( 'meyvora_seo_analysis_cache_input', $this, 'filter_analysis_cache_input', 10, 2 );
	}

	/**
	 * Include current/post language in analysis cache key so per-language content gets separate cache.
	 *
	 * @param string $cache_input Existing input (content|keywords|title|desc).
	 * @param int    $post_id    Post ID.
	 * @return string
	 */
	public function filter_analysis_cache_input( string $cache_input, int $post_id ): string {
		// Include language in cache key so translated versions of the same post_id
		// get separate analysis scores. Without this, the default-language score
		// would be returned for all translations.
		$lang = $this->get_post_language( $post_id );
		if ( $lang === '' ) {
			$lang = $this->get_current_language();
		}
		if ( $lang !== '' ) {
			$cache_input .= '|' . $lang;
		}
		return $cache_input;
	}

	/**
	 * Whether a supported multilingual plugin is active.
	 *
	 * @return bool
	 */
	public function is_multilingual_active(): bool {
		return $this->is_wpml_active() || $this->is_polylang_active();
	}

	/**
	 * @return bool
	 */
	public function is_wpml_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && function_exists( 'apply_filters' )
			&& apply_filters( 'wpml_active_languages', null ) !== null;
	}

	/**
	 * @return bool
	 */
	public function is_polylang_active(): bool {
		return function_exists( 'pll_get_post_translations' ) || ( function_exists( 'PLL' ) && is_object( PLL() ) );
	}

	/**
	 * Get current language code (for meta key suffix).
	 *
	 * @return string Empty if not in a language context.
	 */
	public function get_current_language(): string {
		if ( $this->is_wpml_active() ) {
			$lang = apply_filters( 'wpml_current_language', null );
			return is_string( $lang ) ? $lang : '';
		}
		if ( $this->is_polylang_active() && function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language();
			return is_string( $lang ) ? $lang : '';
		}
		return '';
	}

	/**
	 * Get language of a post (for meta key when saving).
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function get_post_language( int $post_id ): string {
		if ( $this->is_wpml_active() ) {
			$lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $post_id, 'element_type' => 'post_' . get_post_type( $post_id ) ) );
			return is_string( $lang ) ? $lang : $this->get_current_language();
		}
		if ( $this->is_polylang_active() && function_exists( 'pll_get_post_language' ) ) {
			$lang = pll_get_post_language( $post_id );
			return is_string( $lang ) ? $lang : '';
		}
		return '';
	}

	/**
	 * Get default language code.
	 *
	 * @return string
	 */
	public function get_default_language(): string {
		if ( $this->is_wpml_active() ) {
			$lang = apply_filters( 'wpml_default_language', null );
			return is_string( $lang ) ? $lang : '';
		}
		if ( $this->is_polylang_active() && function_exists( 'pll_default_language' ) ) {
			$lang = pll_default_language();
			return is_string( $lang ) ? $lang : '';
		}
		return '';
	}

	/**
	 * Get all active language codes.
	 *
	 * @return array<int, string>
	 */
	public function get_active_languages(): array {
		if ( $this->is_wpml_active() ) {
			$active = apply_filters( 'wpml_active_languages', null );
			if ( is_array( $active ) ) {
				return array_values( array_map( function ( $a ) {
					return isset( $a['language_code'] ) ? $a['language_code'] : '';
				}, $active ) );
			}
		}
		if ( $this->is_polylang_active() && function_exists( 'pll_languages_list' ) ) {
			$list = pll_languages_list();
			return is_array( $list ) ? $list : array();
		}
		return array();
	}

	/**
	 * Get translations for a post: lang_code => url.
	 *
	 * @param int    $post_id
	 * @param string $post_type
	 * @return array<string, string> Lang code => URL.
	 */
	public function get_post_translation_urls( int $post_id, string $post_type = 'post' ): array {
		$out = array();
		$default_lang = $this->get_default_language();
		if ( $this->is_wpml_active() ) {
			$langs = $this->get_active_languages();
			foreach ( $langs as $lang ) {
				$trans_id = (int) apply_filters( 'wpml_object_id', $post_id, $post_type, true, $lang );
				if ( $trans_id > 0 ) {
					$url = get_permalink( $trans_id );
					if ( $url ) {
						$out[ $lang ] = $url;
					}
				}
			}
			return $out;
		}
		if ( $this->is_polylang_active() && function_exists( 'pll_get_post_translations' ) ) {
			$translations = pll_get_post_translations( $post_id );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $lang => $trans_id ) {
					if ( $trans_id && is_string( $lang ) ) {
						$url = get_permalink( (int) $trans_id );
						if ( $url ) {
							$out[ $lang ] = $url;
						}
					}
				}
			}
			return $out;
		}
		return $out;
	}

	/**
	 * Get translations for a term: lang_code => url.
	 *
	 * @param int    $term_id
	 * @param string $taxonomy
	 * @return array<string, string>
	 */
	public function get_term_translation_urls( int $term_id, string $taxonomy ): array {
		$out = array();
		if ( $this->is_wpml_active() ) {
			$langs = $this->get_active_languages();
			foreach ( $langs as $lang ) {
				$trans_id = (int) apply_filters( 'wpml_object_id', $term_id, $taxonomy, true, $lang );
				if ( $trans_id > 0 ) {
					$url = get_term_link( $trans_id, $taxonomy );
					if ( ! is_wp_error( $url ) ) {
						$out[ $lang ] = $url;
					}
				}
			}
			return $out;
		}
		if ( $this->is_polylang_active() && function_exists( 'pll_get_term_translations' ) ) {
			$translations = pll_get_term_translations( $term_id );
			if ( is_array( $translations ) ) {
				foreach ( $translations as $lang => $trans_id ) {
					if ( $trans_id && is_string( $lang ) ) {
						$url = get_term_link( (int) $trans_id, $taxonomy );
						if ( ! is_wp_error( $url ) ) {
							$out[ $lang ] = $url;
						}
					}
				}
			}
			return $out;
		}
		return $out;
	}

	/**
	 * Output hreflang link tags in wp_head.
	 */
	public function output_hreflang_tags(): void {
		if ( is_admin() || ! $this->options->get( self::OPTION_HREFLANG, true ) ) {
			return;
		}
		$urls = $this->get_hreflang_urls();
		if ( empty( $urls ) ) {
			return;
		}
		$default_lang = $this->get_default_language();
		foreach ( $urls as $lang => $url ) {
			if ( $url === '' || ! is_string( $lang ) ) {
				continue;
			}
			echo '<link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '" />' . "\n";
		}
		// x-default: point to default language version.
		if ( $default_lang !== '' && isset( $urls[ $default_lang ] ) && $urls[ $default_lang ] !== '' ) {
			echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $urls[ $default_lang ] ) . '" />' . "\n";
		} elseif ( ! empty( $urls ) ) {
			$fallback = isset( $urls[ $default_lang ] ) ? $urls[ $default_lang ] : reset( $urls );
			if ( is_string( $fallback ) && $fallback !== '' ) {
				echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $fallback ) . '" />' . "\n";
			}
		}
	}

	/**
	 * Get lang => url map for current request (singular or archive).
	 *
	 * @return array<string, string>
	 */
	protected function get_hreflang_urls(): array {
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array();
			}
			return $this->get_post_translation_urls( $post_id, $post->post_type );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( ! $term || ! isset( $term->term_id, $term->taxonomy ) ) {
				return array();
			}
			return $this->get_term_translation_urls( $term->term_id, $term->taxonomy );
		}
		if ( is_home() && ! is_front_page() ) {
			// Blog archive: use post type archive or first post type.
			$post_id = (int) get_option( 'page_for_posts' );
			if ( $post_id > 0 ) {
				return $this->get_post_translation_urls( $post_id, 'page' );
			}
		}
		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			// Post type archive URLs per language: WPML/Polylang often use different base URLs per language.
			$langs = $this->get_active_languages();
			$out = array();
			$default = $this->get_default_language();
			foreach ( $langs as $lang ) {
				if ( $this->is_polylang_active() && function_exists( 'pll_home_url' ) ) {
					$base = pll_home_url( $lang );
					$pt = get_post_type_object( $post_type );
					if ( $pt && isset( $pt->rewrite['slug'] ) ) {
						$out[ $lang ] = trailingslashit( $base ) . $pt->rewrite['slug'] . '/';
					}
				} else {
					$url = get_post_type_archive_link( $post_type );
					if ( $url ) {
						$out[ $lang ] = $url;
					}
				}
			}
			return $out;
		}
		return array();
	}

	/**
	 * Filter meta key to use language suffix when ML plugin stores per-language on same post.
	 *
	 * @param string $meta_key Base key (e.g. MEYVORA_SEO_META_TITLE).
	 * @param int    $post_id  Post ID (for context).
	 * @return string
	 */
	public function filter_meta_key_for_language( string $meta_key, int $post_id ): string {
		$lang = $this->get_post_language( $post_id );
		if ( $lang === '' ) {
			$lang = $this->get_current_language();
		}
		if ( $lang === '' ) {
			return $meta_key;
		}
		// Default language: use base key (backward compatible). Others: suffix with _lang.
		if ( $lang === $this->get_default_language() ) {
			return $meta_key;
		}
		return $meta_key . '_' . $lang;
	}

	/**
	 * Get meta value for a post: use language-specific key when ML active, with fallback to base key.
	 * Use this when reading SEO meta so per-language values are respected.
	 *
	 * @param int    $post_id
	 * @param string $meta_key Base key (e.g. MEYVORA_SEO_META_TITLE).
	 * @param bool   $single
	 * @return mixed
	 */
	public function get_post_meta( int $post_id, string $meta_key, bool $single = true ) {
		$key_for_lang = apply_filters( 'meyvora_seo_post_meta_key', $meta_key, $post_id );
		$value = get_post_meta( $post_id, $key_for_lang, $single );
		if ( ( $value !== '' && $value !== null ) || $key_for_lang === $meta_key ) {
			return $value;
		}
		return get_post_meta( $post_id, $meta_key, $single );
	}

	/**
	 * Sitemap: add hreflang xhtml:link children to a url entry (for posts/terms).
	 * Filter callback for meyvora_seo_sitemap_url_entry.
	 *
	 * @param string   $entry    Existing XML fragment for one url (e.g. <url><loc>...</loc>...</url>).
	 * @param WP_Post  $post     Post object (for post sitemaps) or null.
	 * @param WP_Term  $term     Term object (for taxonomy sitemaps) or null.
	 * @param string   $taxonomy Taxonomy (when term is set).
	 * @return string
	 */
	public function sitemap_add_hreflang_links( string $entry, $post, $term, string $taxonomy = '' ): string {
		if ( ! $this->options->get( self::OPTION_SITEMAP_HREFLANG, true ) ) {
			return $entry;
		}
		$urls = array();
		if ( $post instanceof WP_Post ) {
			$urls = $this->get_post_translation_urls( $post->ID, $post->post_type );
		} elseif ( $term instanceof WP_Term ) {
			$urls = $this->get_term_translation_urls( $term->term_id, $term->taxonomy );
		}
		if ( empty( $urls ) ) {
			return $entry;
		}
		$default_lang = $this->get_default_language();
		$ns = 'http://www.w3.org/1999/xhtml';
		$links = '';
		foreach ( $urls as $lang => $url ) {
			if ( $url === '' ) {
				continue;
			}
			$links .= '<xhtml:link rel="alternate" hreflang="' . esc_attr( $lang ) . '" href="' . esc_url( $url ) . '" xmlns:xhtml="' . esc_attr( $ns ) . '" />';
		}
		if ( $default_lang !== '' && isset( $urls[ $default_lang ] ) ) {
			$links .= '<xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url( $urls[ $default_lang ] ) . '" xmlns:xhtml="' . esc_attr( $ns ) . '" />';
		}
		return str_replace( '</url>', $links . '</url>', $entry );
	}
}

/**
 * Get post meta with language support (WPML/Polylang). Use when reading SEO meta on the frontend.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Meta key (e.g. MEYVORA_SEO_META_TITLE).
 * @param bool   $single  Single value.
 * @return mixed
 */
function meyvora_seo_get_translated_post_meta( $post_id, $key, $single = true ) {
	$filtered = apply_filters( 'meyvora_seo_post_meta_key', $key, (int) $post_id );
	$value = get_post_meta( $post_id, $filtered, $single );
	if ( ( $value !== '' && $value !== null ) || $filtered === $key ) {
		return $value;
	}
	return get_post_meta( $post_id, $key, $single );
}
