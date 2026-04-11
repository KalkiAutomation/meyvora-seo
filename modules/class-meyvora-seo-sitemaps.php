<?php
/**
 * XML Sitemap: index, post-type and taxonomy sitemaps, image support, ping on publish.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Sitemap queries; exclusion list.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Sitemaps {

	const SITEMAP_QUERY = 'meyvora_sitemap';
	const PER_PAGE = 1000;

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
		if ( ! $this->options->get( 'sitemap_enabled', true ) ) {
			return;
		}
		$this->loader->add_action( 'init', $this, 'add_rewrite_rules', 5, 0 );
		$this->loader->add_filter( 'query_vars', $this, 'query_vars', 10, 1 );
		$this->loader->add_action( 'template_redirect', $this, 'serve_sitemap', 1, 0 );
		$this->loader->add_action( 'save_post', $this, 'clear_sitemap_cache_on_save', 10, 1 );
		$this->loader->add_action( 'transition_post_status', $this, 'on_publish', 10, 3 );
		add_action( 'meyvora_seo_after_publish', array( $this, 'ping_google' ), 10, 0 );
		add_action( 'meyvora_seo_clear_sitemap_cache', array( $this, 'clear_sitemap_transients' ), 10, 0 );
		$this->loader->add_action( 'do_robots', $this, 'inject_sitemap_into_robots_txt', 99, 0 );
	}

	/**
	 * Append Sitemap: line to WordPress virtual robots.txt output (do_robots action).
	 * Only fires when WP is generating the virtual robots.txt (no physical file present).
	 */
	public function inject_sitemap_into_robots_txt(): void {
		$sitemap_url = home_url( '/sitemap.xml' );
		// XML output: use esc_url_raw() not esc_url() to avoid double-encoding &
		echo 'Sitemap: ' . esc_url_raw( $sitemap_url ) . "\n";
	}

	public function on_publish( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ( function_exists( 'wp_doing_autosave' ) && wp_doing_autosave() ) || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		if ( $new_status !== 'publish' || $post->post_status !== 'publish' ) {
			return;
		}
		$sitemap_post_types = $this->get_sitemap_post_types();
		if ( ! in_array( $post->post_type, $sitemap_post_types, true ) ) {
			return;
		}
		do_action( 'meyvora_seo_after_publish' );
	}

	public function add_rewrite_rules(): void {
		add_rewrite_rule( 'sitemap\.xml$', 'index.php?' . self::SITEMAP_QUERY . '=index', 'top' );
		add_rewrite_rule( 'sitemap-news\.xml$', 'index.php?' . self::SITEMAP_QUERY . '=news', 'top' );
		add_rewrite_rule( 'sitemap-video\.xml$', 'index.php?' . self::SITEMAP_QUERY . '=video', 'top' );
		add_rewrite_rule( 'sitemap-([a-z0-9_-]+)\.xml$', 'index.php?' . self::SITEMAP_QUERY . '=$matches[1]', 'top' );
		add_rewrite_rule( 'sitemap-([a-z0-9_-]+)-([0-9]+)\.xml$', 'index.php?' . self::SITEMAP_QUERY . '=$matches[1]&paged=$matches[2]', 'top' );
	}

	/**
	 * @param array<string> $vars
	 * @return array<string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::SITEMAP_QUERY;
		return $vars;
	}

	public function serve_sitemap(): void {
		$type = get_query_var( self::SITEMAP_QUERY );
		if ( $type === '' || $type === false ) {
			return;
		}
		$paged = (int) get_query_var( 'paged' );
		if ( $paged < 1 ) {
			$paged = 1;
		}
		$cache_key = 'meyvora_sitemap_' . $type . '_' . $paged;
		$xml = get_transient( $cache_key );
		if ( $xml === false ) {
			if ( $type === 'index' ) {
				$xml = $this->render_index();
			} elseif ( $type === 'news' ) {
				$xml = $this->render_news_sitemap();
			} elseif ( $type === 'video' ) {
				$xml = $this->render_video_sitemap();
			} else {
				$xml = $this->render_sitemap( $type, $paged );
			}
			if ( $xml !== '' ) {
				set_transient( $cache_key, $xml, HOUR_IN_SECONDS );
			}
		}
		if ( $xml !== '' ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}
	}

	private function render_index(): string {
		$urls = array();
		$base = $this->sitemap_base_url();
		if ( $this->options->get( 'sitemap_posts', true ) ) {
			$urls[] = $base . 'sitemap-posts.xml';
			$total = wp_count_posts( 'post' )->publish;
			for ( $p = 2; $p <= max( 2, (int) ceil( $total / self::PER_PAGE ) ); $p++ ) {
				$urls[] = $base . 'sitemap-posts-' . $p . '.xml';
			}
		}
		if ( $this->options->get( 'sitemap_pages', true ) ) {
			$urls[] = $base . 'sitemap-pages.xml';
			$total = wp_count_posts( 'page' )->publish;
			for ( $p = 2; $p <= max( 2, (int) ceil( $total / self::PER_PAGE ) ); $p++ ) {
				$urls[] = $base . 'sitemap-pages-' . $p . '.xml';
			}
		}
		if ( $this->options->get( 'sitemap_categories', true ) ) {
			$urls[] = $base . 'sitemap-categories.xml';
		}
		if ( $this->options->get( 'sitemap_tags', true ) ) {
			$urls[] = $base . 'sitemap-tags.xml';
		}
		$extra_taxonomies = apply_filters( 'meyvora_seo_sitemap_extra_taxonomies', array() );
		foreach ( $extra_taxonomies as $tax ) {
			if ( is_string( $tax ) && taxonomy_exists( $tax ) ) {
				$urls[] = $base . 'sitemap-' . $tax . '.xml';
			}
		}
		$cpts = $this->get_sitemap_post_types();
		foreach ( $cpts as $pt ) {
			if ( $pt === 'post' || $pt === 'page' ) {
				continue;
			}
			$count = wp_count_posts( $pt );
			$total = isset( $count->publish ) ? (int) $count->publish : 0;
			for ( $p = 1; $p <= max( 1, (int) ceil( $total / self::PER_PAGE ) ); $p++ ) {
				$urls[] = $p === 1 ? $base . 'sitemap-' . $pt . '.xml' : $base . 'sitemap-' . $pt . '-' . $p . '.xml';
			}
		}
		if ( $this->options->get( 'sitemap_news_enabled', false ) && post_type_exists( $this->options->get( 'sitemap_news_post_type', 'post' ) ) ) {
			$urls[] = $base . 'sitemap-news.xml';
		}
		if ( $this->options->get( 'sitemap_video_enabled', false ) ) {
			$urls[] = $base . 'sitemap-video.xml';
		}
		$out = '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		foreach ( $urls as $loc ) {
			$out .= '<sitemap><loc>' . esc_url_raw( $loc ) . '</loc><lastmod>' . esc_html( gmdate( 'c' ) ) . '</lastmod></sitemap>';
		}
		$out .= '</sitemapindex>';
		return $out;
	}

	private function render_sitemap( string $type, int $paged ): string {
		$exclude_ids = $this->get_excluded_post_ids();
		if ( $type === 'posts' ) {
			return $this->render_posts_sitemap( $paged, $exclude_ids );
		}
		if ( $type === 'pages' ) {
			return $this->render_posts_sitemap( $paged, $exclude_ids, 'page' );
		}
		if ( $type === 'categories' ) {
			return $this->render_taxonomy_sitemap( 'category' );
		}
		if ( $type === 'tags' ) {
			return $this->render_taxonomy_sitemap( 'post_tag' );
		}
		$extra_taxonomies = apply_filters( 'meyvora_seo_sitemap_extra_taxonomies', array() );
		if ( in_array( $type, $extra_taxonomies, true ) && taxonomy_exists( $type ) ) {
			return $this->render_taxonomy_sitemap( $type );
		}
		$cpts = $this->get_sitemap_post_types();
		if ( in_array( $type, $cpts, true ) ) {
			return $this->render_posts_sitemap( $paged, $exclude_ids, $type );
		}
		return '';
	}

	private function render_posts_sitemap( int $paged, array $exclude_ids, string $post_type = 'post' ): string {
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'offset'         => ( $paged - 1 ) * self::PER_PAGE,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'post__not_in'   => $exclude_ids,
			'no_found_rows'  => true,
		);
		$q = new WP_Query( $args );
		$entries = array();
		foreach ( $q->posts as $post ) {
			if ( get_post_meta( $post->ID, MEYVORA_SEO_META_NOINDEX, true ) === '1' && ! $this->options->get( 'sitemap_include_noindex', false ) ) {
				continue;
			}
			$loc = get_permalink( $post );
			if ( ! $loc ) {
				continue;
			}
			$lastmod = get_post_modified_time( 'c', true, $post );
			$age = time() - get_post_modified_time( 'U', true, $post );
			$custom_priority   = get_post_meta( $post->ID, MEYVORA_SEO_META_SITEMAP_PRIORITY, true );
			$custom_changefreq = get_post_meta( $post->ID, MEYVORA_SEO_META_SITEMAP_CHANGEFREQ, true );
			$changefreq = ( is_string( $custom_changefreq ) && $custom_changefreq !== '' ) ? $custom_changefreq : ( $age < 86400 * 7 ? 'weekly' : ( $age < 86400 * 30 ? 'monthly' : 'yearly' ) );
			$priority   = ( is_string( $custom_priority ) && $custom_priority !== '' ) ? $custom_priority : ( $age < 86400 * 180 ? '0.8' : '0.6' );
			$entry = '<url><loc>' . esc_url_raw( $loc ) . '</loc><lastmod>' . esc_html( $lastmod ) . '</lastmod><changefreq>' . esc_html( $changefreq ) . '</changefreq><priority>' . esc_html( $priority ) . '</priority>';
			if ( $this->options->get( 'sitemap_images', true ) ) {
				$thumb_id = (int) get_post_thumbnail_id( $post->ID );
				if ( $thumb_id > 0 ) {
					$img_url = wp_get_attachment_image_url( $thumb_id, 'full' );
					if ( $img_url ) {
						$entry .= '<image:image xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"><image:loc>' . esc_url_raw( $img_url ) . '</image:loc></image:image>';
					}
				}
			}
			$entry .= '</url>';
			$entry = apply_filters( 'meyvora_seo_sitemap_url_entry', $entry, $post, null, '' );
			$entries[] = $entry;
		}
		$urlset_attrs = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"';
		if ( $this->options->get( 'sitemap_hreflang_enabled', true ) && class_exists( 'Meyvora_SEO_Multilingual' ) ) {
			$urlset_attrs .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
		}
		return '<?xml version="1.0" encoding="UTF-8"?><urlset ' . $urlset_attrs . '>' . implode( '', $entries ) . '</urlset>';
	}

	private function render_taxonomy_sitemap( string $taxonomy ): string {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true ) );
		if ( ! is_array( $terms ) ) {
			$terms = array();
		}
		$entries = array();
		foreach ( $terms as $term ) {
			$url = get_term_link( $term );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$entry = '<url><loc>' . esc_url_raw( $url ) . '</loc><changefreq>weekly</changefreq><priority>0.5</priority></url>';
			$entry = apply_filters( 'meyvora_seo_sitemap_url_entry', $entry, null, $term, $taxonomy );
			$entries[] = $entry;
		}
		$urlset_attrs = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		if ( $this->options->get( 'sitemap_hreflang_enabled', true ) && class_exists( 'Meyvora_SEO_Multilingual' ) ) {
			$urlset_attrs .= ' xmlns:xhtml="http://www.w3.org/1999/xhtml"';
		}
		return '<?xml version="1.0" encoding="UTF-8"?><urlset ' . $urlset_attrs . '>' . implode( '', $entries ) . '</urlset>';
	}

	/**
	 * News sitemap (Google News): last 2 days, max 1000 posts.
	 *
	 * @return string
	 */
	private function render_news_sitemap(): string {
		if ( ! $this->options->get( 'sitemap_news_enabled', false ) ) {
			return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"></urlset>';
		}
		$post_type = $this->options->get( 'sitemap_news_post_type', 'post' );
		if ( ! post_type_exists( $post_type ) ) {
			return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"></urlset>';
		}
		$args = array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => 1000,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'date_query'     => array(
				array(
					'after' => '2 days ago',
				),
			),
		);
		$q = new WP_Query( $args );
		$pub_name = get_bloginfo( 'name' );
		$lang = substr( get_bloginfo( 'language' ), 0, 2 );
		if ( $lang === '' ) {
			$lang = 'en';
		}
		$entries = array();
		foreach ( $q->posts as $post ) {
			$loc = get_permalink( $post );
			if ( ! $loc ) {
				continue;
			}
			$pub_date = get_post_time( 'c', true, $post );
			$title = $post->post_title;
			$title = wp_strip_all_tags( $title );
			$title = ent2ncr( $title );
			$entries[] = '<url><loc>' . esc_url_raw( $loc ) . '</loc><news:news><news:publication><news:name>' . esc_html( $pub_name ) . '</news:name><news:language>' . esc_html( $lang ) . '</news:language></news:publication><news:publication_date>' . esc_html( $pub_date ) . '</news:publication_date><news:title>' . esc_html( $title ) . '</news:title></news:news></url>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">' . implode( '', $entries ) . '</urlset>';
	}

	/**
	 * Video sitemap: posts containing [video] shortcode or core/video block.
	 *
	 * @return string
	 */
	private function render_video_sitemap(): string {
		if ( ! $this->options->get( 'sitemap_video_enabled', false ) ) {
			return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1"></urlset>';
		}
		$post_types = $this->get_sitemap_post_types();
		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'orderby'        => 'modified',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);
		$where_filter = function ( string $where ) {
			global $wpdb;
			$where .= $wpdb->prepare(
				" AND ( {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_content LIKE %s OR {$wpdb->posts}.post_content LIKE %s )",
				'%[video %',
				'%<!-- wp:video%',
				'%<!-- wp:core/video%'
			);
			return $where;
		};
		add_filter( 'posts_where', $where_filter, 10, 1 );
		$q = new WP_Query( $args );
		remove_filter( 'posts_where', $where_filter, 10 );
		$entries = array();
		foreach ( $q->posts as $post ) {
			$videos = $this->get_videos_from_post( $post );
			if ( empty( $videos ) ) {
				continue;
			}
			$loc = get_permalink( $post );
			if ( ! $loc ) {
				continue;
			}
			$title = wp_strip_all_tags( $post->post_title );
			$title = ent2ncr( $title );
			$desc = wp_strip_all_tags( $post->post_excerpt );
			if ( $desc === '' ) {
				$desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
			}
			$desc = ent2ncr( $desc );
			$entry = '<url><loc>' . esc_url_raw( $loc ) . '</loc>';
			foreach ( $videos as $video ) {
				if ( empty( $video['content_loc'] ) ) {
					continue;
				}
				$entry .= '<video:video xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">';
				if ( ! empty( $video['thumbnail_loc'] ) ) {
					$entry .= '<video:thumbnail_loc>' . esc_url_raw( $video['thumbnail_loc'] ) . '</video:thumbnail_loc>';
				}
				$entry .= '<video:title>' . esc_html( $title ) . '</video:title><video:description>' . esc_html( $desc ) . '</video:description><video:content_loc>' . esc_url_raw( $video['content_loc'] ) . '</video:content_loc></video:video>';
			}
			$entry .= '</url>';
			$entries[] = $entry;
		}
		return '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:video="http://www.google.com/schemas/sitemap-video/1.1">' . implode( '', $entries ) . '</urlset>';
	}

	/**
	 * Get video URLs and thumbnails from [video] shortcode and core/video block (for video sitemap).
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{content_loc: string, thumbnail_loc: string}>
	 */
	private function get_videos_from_post( WP_Post $post ): array {
		$content = $post->post_content;
		$videos  = array();

		// [video src="..." poster="..."] shortcode.
		if ( preg_match_all( '/\[video\s([^\]]+)\]/', $content, $shortcode_matches, PREG_SET_ORDER ) ) {
			foreach ( $shortcode_matches as $m ) {
				$atts = shortcode_parse_atts( $m[1] );
				if ( ! is_array( $atts ) || empty( $atts['src'] ) ) {
					continue;
				}
				$src = trim( $atts['src'] );
				if ( $src === '' ) {
					continue;
				}
				$thumb = isset( $atts['poster'] ) ? trim( $atts['poster'] ) : '';
				$videos[] = array(
					'content_loc'   => $src,
					'thumbnail_loc' => $thumb,
				);
			}
		}

		// core/video block (wp:video in serialized content).
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $content );
			foreach ( $blocks as $block ) {
				if ( isset( $block['blockName'] ) && $block['blockName'] === 'core/video' && ! empty( $block['attrs']['src'] ) ) {
					$src   = $block['attrs']['src'];
					$thumb = isset( $block['attrs']['poster'] ) ? $block['attrs']['poster'] : '';
					$videos[] = array(
						'content_loc'   => $src,
						'thumbnail_loc' => $thumb,
					);
				}
			}
		}

		return $videos;
	}

	private function sitemap_base_url(): string {
		return user_trailingslashit( home_url( '/' ) );
	}

	private function get_sitemap_post_types(): array {
		$list = array( 'post', 'page' );
		if ( $this->options->get( 'sitemap_products', true ) && post_type_exists( 'product' ) ) {
			$list[] = 'product';
		}
		return apply_filters( 'meyvora_seo_sitemap_post_types', $list );
	}

	private function get_excluded_post_ids(): array {
		$raw = $this->options->get( 'sitemap_exclude_ids', '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$ids = array_map( 'absint', array_filter( explode( ',', $raw ) ) );
		return array_unique( $ids );
	}

	public function clear_sitemap_cache_on_save( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' ) {
			return;
		}
		$this->clear_sitemap_transients();
	}

	public function clear_sitemap_transients(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_meyvora_sitemap_%' OR option_name LIKE '_transient_timeout_meyvora_sitemap_%'" );
	}

	public function ping_google(): void {
		$last_ping = (int) get_option( 'meyvora_seo_last_sitemap_ping', 0 );
		if ( ( time() - $last_ping ) < 3600 ) {
			return;
		}
		$url = 'https://www.google.com/ping?sitemap=' . rawurlencode( $this->sitemap_base_url() . 'sitemap.xml' );
		wp_remote_get( $url, array( 'timeout' => 5 ) );
		update_option( 'meyvora_seo_last_sitemap_ping', time(), false );
	}

	/**
	 * Get public sitemap URL (for settings link).
	 *
	 * @return string
	 */
	public static function get_sitemap_url(): string {
		return user_trailingslashit( home_url( '/' ) ) . 'sitemap.xml';
	}
}
