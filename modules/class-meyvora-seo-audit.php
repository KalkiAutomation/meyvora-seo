<?php
/**
 * Site-wide SEO audit: 12 checks, WP-Cron weekly, on-demand with progress.
 * Results: issues in custom table meyvora_seo_audit_results; last_run/summary/post snapshots in options.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit custom table and 404 log.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Audit
 */
class Meyvora_SEO_Audit {

	const OPTION_RESULTS     = 'meyvora_seo_audit_results';
	const OPTION_LAST_RUN    = 'meyvora_seo_audit_last_run';
	const OPTION_SUMMARY     = 'meyvora_seo_audit_summary';
	const OPTION_POSTS_META  = 'meyvora_seo_audit_posts_meta';
	const TABLE_AUDIT_RESULTS = 'meyvora_seo_audit_results';
	const TABLE_404_LOG      = 'meyvora_seo_404_log';
	const TRANSIENT_QUEUE    = 'meyvora_audit_queue';
	const TRANSIENT_PROGRESS = 'meyvora_audit_progress';
	const TRANSIENT_CANONICAL_DATA = 'meyvora_audit_canonical_data';
	const CRON_HOOK         = 'meyvora_seo_run_audit_cron';
	const BATCH_SIZE        = 20;
	const MIN_CONTENT_CHARS = 300;
	const AJAX_ACTION       = 'meyvora_seo_audit_run';
	const NONCE_ACTION      = 'meyvora_seo_audit_run';
	const PAGE_SLUG         = 'meyvora-seo-site-audit';

	/** Issue IDs and severity for display */
	const ISSUES = array(
		'missing_seo_title'       => array( 'severity' => 'warning', 'label' => 'Missing SEO title' ),
		'missing_meta_description'=> array( 'severity' => 'warning', 'label' => 'Missing meta description' ),
		'missing_focus_keyword'  => array( 'severity' => 'warning', 'label' => 'Missing focus keyword' ),
		'seo_score_poor'         => array( 'severity' => 'warning', 'label' => 'SEO score &lt; 50 (Poor)' ),
		'missing_og_image'       => array( 'severity' => 'warning', 'label' => 'Missing OG image' ),
		'missing_schema_type'    => array( 'severity' => 'info', 'label' => 'Missing schema type' ),
		'duplicate_meta_title'   => array( 'severity' => 'warning', 'label' => 'Duplicate meta title' ),
		'duplicate_meta_description' => array( 'severity' => 'warning', 'label' => 'Duplicate meta description' ),
		'no_internal_links_out' => array( 'severity' => 'info', 'label' => 'No internal links out' ),
		'no_internal_links_in'  => array( 'severity' => 'warning', 'label' => 'Orphan (no internal links in)' ),
		'very_short_content'    => array( 'severity' => 'warning', 'label' => 'Very short content (&lt; 300 chars)' ),
		'missing_image_alt'      => array( 'severity' => 'warning', 'label' => 'Missing image alt tags' ),
		'seo_title_too_long'     => array( 'severity' => 'warning', 'label' => 'SEO title too long (&gt;60 chars)' ),
		'meta_desc_too_long'     => array( 'severity' => 'info', 'label' => 'Meta description too long (&gt;160 chars)' ),
		'keyword_not_in_title'   => array( 'severity' => 'warning', 'label' => 'Focus keyword not in SEO title' ),
		'page_noindex'           => array( 'severity' => 'warning', 'label' => 'Page is set to noindex' ),
		'missing_canonical'      => array( 'severity' => 'info', 'label' => 'No custom canonical URL' ),
		'site_noindex_enabled'   => array( 'severity' => 'critical', 'label' => 'Site-wide noindex is ON (Settings > Reading > Search Engine Visibility)' ),
		'broken_internal_links' => array( 'severity' => 'critical', 'label' => 'Broken internal links (404)' ),
		'noindex_in_sitemap'    => array( 'severity' => 'warning', 'label' => 'Noindex page appears in sitemap' ),
		'missing_h1'            => array( 'severity' => 'warning', 'label' => 'Missing H1 tag in content' ),
		'thin_content_words'    => array( 'severity' => 'warning', 'label' => 'Thin content (&lt; 300 words)' ),
		'cwv_fail'              => array( 'severity' => 'critical', 'label' => 'Core Web Vitals fail' ),
		'canonical_conflict'    => array( 'severity' => 'warning', 'label' => 'Canonical URL used by another post' ),
		'canonical_to_noindex'  => array( 'severity' => 'warning', 'label' => 'Canonical points to a noindex page' ),
		'canonical_self'        => array( 'severity' => 'info', 'label' => 'Canonical matches permalink (self-referential)' ),
	);

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
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_run_audit' ) );
		$this->loader->add_action( 'admin_menu', $this, 'register_site_audit_menu', 13, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( self::CRON_HOOK, array( $this, 'run_audit_cron' ) );
		add_action( 'init', array( $this, 'schedule_cron' ), 20 );
	}

	/**
	 * Schedule weekly cron if not already scheduled.
	 */
	public function schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'weekly', self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: run full audit and save results.
	 */
	public function run_audit_cron(): void {
		$results = $this->run_full_audit();
		$this->save_results( $results );
	}

	/**
	 * Get all published post/page IDs.
	 *
	 * @return array<int>
	 */
	public function get_audit_post_ids(): array {
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		return is_array( $posts ) ? array_map( 'intval', $posts ) : array();
	}

	/**
	 * Build canonical URL map (canonical_url => post_ids) and noindex post ID set.
	 * Call once before the batch loop and pass to run_audit_batch().
	 *
	 * @return array{ canonical_map: array<string, array<int>>, noindex_ids: array<int> }
	 */
	protected function build_canonical_and_noindex_data(): array {
		global $wpdb;
		$canonical_map = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single meta lookup.
		$all_canonical = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
				MEYVORA_SEO_META_CANONICAL
			),
			ARRAY_A
		);
		if ( is_array( $all_canonical ) ) {
			foreach ( $all_canonical as $row ) {
				$url = isset( $row['meta_value'] ) ? trim( (string) $row['meta_value'] ) : '';
				if ( $url !== '' ) {
					$canonical_map[ $url ] = $canonical_map[ $url ] ?? array();
					$canonical_map[ $url ][] = (int) $row['post_id'];
				}
			}
		}
		$noindex_ids = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Single meta lookup.
		$col = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				MEYVORA_SEO_META_NOINDEX
			)
		);
		if ( is_array( $col ) ) {
			$noindex_ids = array_map( 'intval', $col );
		}
		return array( 'canonical_map' => $canonical_map, 'noindex_ids' => $noindex_ids );
	}

	/**
	 * Run audit for a batch of post IDs; returns array of row data (issues, score, etc.).
	 * Does not include duplicate title/description (computed in run_full_audit).
	 *
	 * @param array<int>          $post_ids Post IDs.
	 * @param array               $duplicate_titles  Optional. Set of SEO titles that appear more than once (normalized).
	 * @param array               $duplicate_descs   Optional. Set of meta descriptions that appear more than once.
	 * @param array<string, array<int>> $canonical_map Optional. Canonical URL => post IDs (from build_canonical_and_noindex_data).
	 * @param array<int>          $noindex_ids Optional. Post IDs that have noindex (from build_canonical_and_noindex_data).
	 * @return array<int, array>
	 */
	public function run_audit_batch( array $post_ids, array $duplicate_titles = array(), array $duplicate_descs = array(), array $canonical_map = array(), array $noindex_ids = array() ): array {
		global $wpdb;
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$rows = array();

		static $blog_public_checked = false;
		if ( ! $blog_public_checked && '0' === get_option( 'blog_public' ) ) {
			$blog_public_checked = true;
		}

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_status !== 'publish' ) {
				continue;
			}
			$issues = array();

			static $added_site_noindex = false;
			if ( ! $added_site_noindex && '0' === get_option( 'blog_public' ) ) {
				$issues[] = array( 'id' => 'site_noindex_enabled', 'severity' => 'critical' );
				$added_site_noindex = true;
			}
			$title_meta = get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true );
			$desc_meta  = get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true );
			$focus_raw  = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
			$score_meta = get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true );
			$og_image   = get_post_meta( $post_id, MEYVORA_SEO_META_OG_IMAGE, true );
			$schema     = get_post_meta( $post_id, MEYVORA_SEO_META_SCHEMA_TYPE, true );

			if ( trim( (string) $title_meta ) === '' ) {
				$issues[] = array( 'id' => 'missing_seo_title', 'severity' => 'warning' );
			}
			if ( trim( (string) $desc_meta ) === '' ) {
				$issues[] = array( 'id' => 'missing_meta_description', 'severity' => 'warning' );
			}
			$keywords = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::normalize_focus_keywords( $focus_raw ) : array();
			if ( ! is_array( $keywords ) || empty( $keywords ) ) {
				$issues[] = array( 'id' => 'missing_focus_keyword', 'severity' => 'warning' );
			}
			$score = is_numeric( $score_meta ) ? (int) $score_meta : 0;
			if ( $score < 50 ) {
				$issues[] = array( 'id' => 'seo_score_poor', 'severity' => 'warning' );
			}
			if ( empty( $og_image ) || ! is_numeric( $og_image ) ) {
				$issues[] = array( 'id' => 'missing_og_image', 'severity' => 'warning' );
			}
			if ( trim( (string) $schema ) === '' ) {
				$issues[] = array( 'id' => 'missing_schema_type', 'severity' => 'info' );
			}

			$title_normalized = $this->normalize_for_duplicate( trim( (string) $title_meta ) !== '' ? $title_meta : $post->post_title );
			$desc_normalized  = $this->normalize_for_duplicate( $desc_meta );
			if ( $title_normalized !== '' && isset( $duplicate_titles[ $title_normalized ] ) ) {
				$issues[] = array( 'id' => 'duplicate_meta_title', 'severity' => 'warning' );
			}
			if ( $desc_normalized !== '' && isset( $duplicate_descs[ $desc_normalized ] ) ) {
				$issues[] = array( 'id' => 'duplicate_meta_description', 'severity' => 'warning' );
			}

			$permalink = get_permalink( $post );
			$links_in  = $this->count_inbound_links( $post_id, $permalink );
			$links_out = $this->count_outbound_links( $post->post_content, $post_id, $host );
			if ( $links_out === 0 ) {
				$issues[] = array( 'id' => 'no_internal_links_out', 'severity' => 'info' );
			}
			if ( $links_in === 0 ) {
				$issues[] = array( 'id' => 'no_internal_links_in', 'severity' => 'warning' );
			}

			$content_stripped = wp_strip_all_tags( $post->post_content );
			$content_len = function_exists( 'mb_strlen' ) ? mb_strlen( $content_stripped ) : strlen( $content_stripped );
			if ( $content_len < self::MIN_CONTENT_CHARS ) {
				$issues[] = array( 'id' => 'very_short_content', 'severity' => 'warning' );
			}

			if ( $this->post_has_images_missing_alt( $post->post_content ) ) {
				$issues[] = array( 'id' => 'missing_image_alt', 'severity' => 'warning' );
			}

			// SEO title length
			if ( strlen( (string) $title_meta ) > 60 ) {
				$issues[] = array( 'id' => 'seo_title_too_long', 'severity' => 'warning' );
			}
			// Meta description length
			if ( strlen( (string) $desc_meta ) > 160 ) {
				$issues[] = array( 'id' => 'meta_desc_too_long', 'severity' => 'info' );
			}
			// Keyword not in title
			if ( ! empty( $keywords ) && ! empty( $title_meta ) ) {
				$first_kw = is_array( $keywords[0] ) ? ( $keywords[0]['keyword'] ?? '' ) : $keywords[0];
				if ( $first_kw !== '' && stripos( $title_meta, $first_kw ) === false ) {
					$issues[] = array( 'id' => 'keyword_not_in_title', 'severity' => 'warning' );
				}
			}
			// Noindex
			$noindex = get_post_meta( $post_id, MEYVORA_SEO_META_NOINDEX, true );
			if ( ! empty( $noindex ) ) {
				$issues[] = array( 'id' => 'page_noindex', 'severity' => 'warning' );
			}
			// Missing canonical
			$canonical = get_post_meta( $post_id, MEYVORA_SEO_META_CANONICAL, true );
			if ( trim( (string) $canonical ) === '' ) {
				$issues[] = array( 'id' => 'missing_canonical', 'severity' => 'info' );
			}

			// Missing H1 in content
			if ( ! preg_match( '/<h1[^>]*>/i', $post->post_content, $m ) ) {
				$issues[] = array( 'id' => 'missing_h1', 'severity' => 'warning' );
			}

			// Thin content (< 300 words)
			$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
			if ( $word_count < 300 ) {
				$issues[] = array( 'id' => 'thin_content_words', 'severity' => 'warning' );
			}

			// Noindex page appears in sitemap
			if ( ! empty( $noindex ) && $this->options->get( 'sitemap_enabled', true ) ) {
				$sitemap_post_types = $this->get_audit_sitemap_post_types();
				$exclude_ids        = $this->get_audit_sitemap_exclude_ids();
				if ( in_array( $post->post_type, $sitemap_post_types, true ) && ! in_array( $post_id, $exclude_ids, true ) ) {
					$issues[] = array( 'id' => 'noindex_in_sitemap', 'severity' => 'warning' );
				}
			}

			// Core Web Vitals (cached CWV data only)
			if ( class_exists( 'Meyvora_SEO_CWV' ) ) {
				$cwv = ( new Meyvora_SEO_CWV( $this->loader, $this->options ) )->get_cached( $post_id );
				if ( $cwv !== null && isset( $cwv['passed'] ) && $cwv['passed'] === false ) {
					$issues[] = array( 'id' => 'cwv_fail', 'severity' => 'critical' );
				}
			}

			// Canonical conflict checks
			$custom_canonical = get_post_meta( $post_id, MEYVORA_SEO_META_CANONICAL, true );
			if ( is_string( $custom_canonical ) && $custom_canonical !== '' ) {
				$permalink = get_permalink( $post );
				// Self-referential canonical
				if ( rtrim( $custom_canonical, '/' ) === rtrim( (string) $permalink, '/' ) ) {
					$issues[] = array( 'id' => 'canonical_self', 'severity' => 'info' );
				}
				// Canonical used by another post
				$sharing_posts = $canonical_map[ $custom_canonical ] ?? array();
				if ( count( $sharing_posts ) > 1 ) {
					$issues[] = array( 'id' => 'canonical_conflict', 'severity' => 'warning' );
				}
				// Canonical points to a noindex page
				$canonical_post_id = url_to_postid( $custom_canonical );
				if ( $canonical_post_id && in_array( $canonical_post_id, $noindex_ids, true ) ) {
					$issues[] = array( 'id' => 'canonical_to_noindex', 'severity' => 'warning' );
				}
			}

			$rows[ $post_id ] = array(
				'post_id'    => $post_id,
				'post_title' => $post->post_title,
				'post_type'  => $post->post_type,
				'permalink'  => $permalink,
				'seo_score'  => $score,
				'issues'     => $issues,
			);
		}
		return $rows;
	}

	private function normalize_for_duplicate( string $s ): string {
		$s = trim( $s );
		$s = preg_replace( '/\s+/', ' ', $s );
		return $s;
	}

	/**
	 * Post types included in the sitemap (mirrors sitemap module logic for audit).
	 *
	 * @return array<string>
	 */
	private function get_audit_sitemap_post_types(): array {
		$list = array( 'post', 'page' );
		if ( $this->options->get( 'sitemap_products', true ) && post_type_exists( 'product' ) ) {
			$list[] = 'product';
		}
		return apply_filters( 'meyvora_seo_sitemap_post_types', $list );
	}

	/**
	 * Post IDs excluded from the sitemap.
	 *
	 * @return array<int>
	 */
	private function get_audit_sitemap_exclude_ids(): array {
		$raw = $this->options->get( 'sitemap_exclude_ids', '' );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		return array_unique( array_map( 'absint', array_filter( explode( ',', $raw ) ) ) );
	}

	/**
	 * Find duplicate meta titles and descriptions across all posts (by normalized value).
	 *
	 * @param array<int, array> $rows Rows keyed by post_id, each with title/desc in meta (we need to pass collected titles/descs).
	 * @return array{ titles: array<string, true>, descriptions: array<string, true> }
	 */
	public function compute_duplicates( array $all_rows ): array {
		$title_counts = array();
		$desc_counts  = array();
		foreach ( $all_rows as $post_id => $row ) {
			$post = get_post( $post_id );
			$title = get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true );
			$desc  = get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true );
			if ( $post ) {
				$t = $this->normalize_for_duplicate( trim( (string) $title ) !== '' ? $title : $post->post_title );
				$d = $this->normalize_for_duplicate( $desc );
				if ( $t !== '' ) {
					$title_counts[ $t ] = ( $title_counts[ $t ] ?? 0 ) + 1;
				}
				if ( $d !== '' ) {
					$desc_counts[ $d ] = ( $desc_counts[ $d ] ?? 0 ) + 1;
				}
			}
		}
		$duplicate_titles = array();
		$duplicate_descs  = array();
		foreach ( $title_counts as $t => $c ) {
			if ( $c > 1 ) {
				$duplicate_titles[ $t ] = true;
			}
		}
		foreach ( $desc_counts as $d => $c ) {
			if ( $c > 1 ) {
				$duplicate_descs[ $d ] = true;
			}
		}
		return array( 'titles' => $duplicate_titles, 'descriptions' => $duplicate_descs );
	}

	/**
	 * Run full audit (all posts), compute duplicates, return full results structure.
	 *
	 * @return array{ last_run: int, results: array, summary: array }
	 */
	public function run_full_audit(): array {
		$ids = $this->get_audit_post_ids();
		$duplicate_titles = array();
		$duplicate_descs  = array();
		if ( ! empty( $ids ) ) {
			$all_meta = array();
			foreach ( $ids as $pid ) {
				$post = get_post( $pid );
				if ( ! $post ) {
					continue;
				}
				$title = get_post_meta( $pid, MEYVORA_SEO_META_TITLE, true );
				$desc  = get_post_meta( $pid, MEYVORA_SEO_META_DESCRIPTION, true );
				$t = $this->normalize_for_duplicate( trim( (string) $title ) !== '' ? $title : $post->post_title );
				$d = $this->normalize_for_duplicate( $desc );
				if ( $t !== '' ) {
					$all_meta[ $pid ] = array( 'title' => $t, 'desc' => $d );
				}
			}
			$title_counts = array();
			$desc_counts  = array();
			foreach ( $all_meta as $pid => $m ) {
				$title_counts[ $m['title'] ] = ( $title_counts[ $m['title'] ] ?? 0 ) + 1;
				$desc_counts[ $m['desc'] ]  = ( $desc_counts[ $m['desc'] ] ?? 0 ) + 1;
			}
			foreach ( $title_counts as $t => $c ) {
				if ( $c > 1 ) {
					$duplicate_titles[ $t ] = true;
				}
			}
			foreach ( $desc_counts as $d => $c ) {
				if ( $d !== '' && $c > 1 ) {
					$duplicate_descs[ $d ] = true;
				}
			}
		}
		$canonical_data = $this->build_canonical_and_noindex_data();
		$all_rows = $this->run_audit_batch( $ids, $duplicate_titles, $duplicate_descs, $canonical_data['canonical_map'], $canonical_data['noindex_ids'] );
		$all_rows = $this->add_broken_internal_links_issues( $all_rows );
		$summary = $this->build_summary( $all_rows );
		return array(
			'last_run' => time(),
			'results'  => $all_rows,
			'summary'  => $summary,
		);
	}

	/**
	 * Build summary: total posts, by severity counts.
	 */
	protected function build_summary( array $results ): array {
		$by_severity = array( 'critical' => 0, 'warning' => 0, 'info' => 0 );
		$total_issues = 0;
		foreach ( $results as $row ) {
			foreach ( $row['issues'] ?? array() as $issue ) {
				$sev = $issue['severity'] ?? 'warning';
				$by_severity[ $sev ] = ( $by_severity[ $sev ] ?? 0 ) + 1;
				$total_issues++;
			}
		}
		$summary = array(
			'total_posts'   => count( $results ),
			'total_issues'  => $total_issues,
			'by_severity'   => $by_severity,
		);
		$summary['missing_image_alt_media'] = class_exists( 'Meyvora_SEO_Image_SEO' ) ? Meyvora_SEO_Image_SEO::count_missing_alt_media() : 0;
		return $summary;
	}

	protected function count_inbound_links( int $exclude_post_id, string $url ): int {
		global $wpdb;
		if ( $url === '' ) {
			return 0;
		}
		$escaped = $wpdb->esc_like( $url );
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID != %d AND post_content LIKE %s",
			$exclude_post_id,
			'%' . $escaped . '%'
		) );
	}

	protected function count_outbound_links( string $content, int $self_id, ?string $host ): int {
		if ( ! $host ) {
			return 0;
		}
		$count = 0;
		if ( preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
			$seen = array();
			foreach ( $matches[1] as $href ) {
				$href = trim( $href );
				if ( strpos( $href, '#' ) !== false ) {
					$href = strtok( $href, '#' );
				}
				if ( $href === '' ) {
					continue;
				}
				$parsed = wp_parse_url( $href );
				$link_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
				$is_internal = ( $link_host === '' || $link_host === strtolower( $host ) );
				if ( ! $is_internal ) {
					continue;
				}
				$path = isset( $parsed['path'] ) ? $parsed['path'] : '/';
				$key = $link_host . $path;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$linked_id = url_to_postid( $href );
				if ( $linked_id && (int) $linked_id === $self_id ) {
					continue;
				}
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Add broken_internal_links issue to posts that link to URLs in the 404 log (hit_count >= 2).
	 * Only uses the existing 404 log table; no live HTTP requests.
	 *
	 * @param array<int, array> $all_rows Results keyed by post_id with 'issues' array.
	 * @return array<int, array> Modified rows.
	 */
	protected function add_broken_internal_links_issues( array $all_rows ): array {
		global $wpdb;
		$table_404 = $wpdb->prefix . self::TABLE_404_LOG;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is constant.
		$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table_404}'" ) === $table_404;
		if ( ! $exists || empty( $all_rows ) ) {
			return $all_rows;
		}

		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$broken_paths = array();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- safe literal.
		$rows_404 = $wpdb->get_results( "SELECT url FROM {$table_404} WHERE hit_count >= 2", ARRAY_A );
		if ( is_array( $rows_404 ) ) {
			foreach ( $rows_404 as $r ) {
				$url  = isset( $r['url'] ) ? $r['url'] : '';
				$path = wp_parse_url( $url, PHP_URL_PATH );
				if ( $path === null || $path === false ) {
					$path = strpos( $url, '?' ) !== false ? strstr( $url, '?', true ) : $url;
				}
				$path = is_string( $path ) ? $path : '';
				$norm = rtrim( $path, '/' );
				$norm = $norm === '' ? '/' : $norm;
				$broken_paths[ $norm ] = true;
			}
		}

		if ( empty( $broken_paths ) ) {
			return $all_rows;
		}

		foreach ( array_keys( $all_rows ) as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || empty( $post->post_content ) ) {
				continue;
			}
			$content = $post->post_content;
			if ( ! preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $matches ) ) {
				continue;
			}
			$seen_path = array();
			foreach ( $matches[1] as $href ) {
				$href = trim( $href );
				if ( strpos( $href, '#' ) !== false ) {
					$href = strtok( $href, '#' );
				}
				if ( $href === '' ) {
					continue;
				}
				$parsed   = wp_parse_url( $href );
				$link_host = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
				$is_internal = ( $link_host === '' || ( $host && $link_host === strtolower( $host ) ) );
				if ( ! $is_internal ) {
					continue;
				}
				$path = isset( $parsed['path'] ) && $parsed['path'] !== '' ? $parsed['path'] : '/';
				$norm = rtrim( $path, '/' );
				$norm = $norm === '' ? '/' : $norm;
				if ( isset( $seen_path[ $norm ] ) ) {
					continue;
				}
				$seen_path[ $norm ] = true;
				if ( ! empty( $broken_paths[ $norm ] ) ) {
					$all_rows[ $post_id ]['issues'][] = array( 'id' => 'broken_internal_links', 'severity' => 'critical' );
					break;
				}
			}
		}

		return $all_rows;
	}

	/**
	 * True if post content has at least one img without alt or with empty alt.
	 */
	protected function post_has_images_missing_alt( string $content ): bool {
		if ( ! preg_match_all( '/<img\s[^>]*>/i', $content, $matches ) ) {
			return false;
		}
		foreach ( $matches[0] as $tag ) {
			if ( ! preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/i', $tag ) ) {
				return true;
			}
			if ( preg_match( '/\balt\s*=\s*["\']\s*["\']/i', $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Save results: issues to custom table meyvora_seo_audit_results; last_run, summary, post snapshots to options.
	 *
	 * @param array{ last_run: int, results: array, summary: array } $data
	 */
	public function save_results( array $data ): void {
		global $wpdb;
		$last_run = isset( $data['last_run'] ) ? (int) $data['last_run'] : time();
		$summary  = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : array();
		$results  = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();

		update_option( self::OPTION_LAST_RUN, $last_run, false );
		update_option( self::OPTION_SUMMARY, $summary, false );

		$posts_meta = array();
		foreach ( $results as $post_id => $row ) {
			$posts_meta[ (int) $post_id ] = array(
				'post_title' => isset( $row['post_title'] ) ? $row['post_title'] : '',
				'post_type'  => isset( $row['post_type'] ) ? $row['post_type'] : 'post',
				'permalink'  => isset( $row['permalink'] ) ? $row['permalink'] : '',
				'seo_score'  => isset( $row['seo_score'] ) ? (int) $row['seo_score'] : 0,
			);
		}
		update_option( self::OPTION_POSTS_META, $posts_meta, false );

		$table = $wpdb->prefix . self::TABLE_AUDIT_RESULTS;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$now = current_time( 'mysql' );
		foreach ( $results as $post_id => $row ) {
			$issues = isset( $row['issues'] ) && is_array( $row['issues'] ) ? $row['issues'] : array();
			foreach ( $issues as $issue ) {
				$issue_type = isset( $issue['id'] ) ? sanitize_text_field( (string) $issue['id'] ) : '';
				$severity   = isset( $issue['severity'] ) ? sanitize_text_field( (string) $issue['severity'] ) : 'warning';
				if ( $issue_type !== '' ) {
					$wpdb->insert(
						$table,
						array(
							'post_id'    => (int) $post_id,
							'issue_type' => $issue_type,
							'severity'   => $severity,
							'created_at' => $now,
						),
						array( '%d', '%s', '%s', '%s' )
					);
				}
			}
		}
	}

	/**
	 * Get stored results: merge issues from custom table with post snapshots from options.
	 *
	 * @return array{ last_run: int, results: array, summary: array }|null
	 */
	public function get_stored_results(): ?array {
		global $wpdb;
		$last_run = get_option( self::OPTION_LAST_RUN, 0 );
		if ( ! $last_run ) {
			// Backward compatibility: try legacy option.
			$raw = get_option( self::OPTION_RESULTS, '' );
			if ( $raw !== '' ) {
				$data = json_decode( $raw, true );
				if ( is_array( $data ) ) {
					$this->save_results( $data );
					return $data;
				}
			}
			return null;
		}

		$summary    = get_option( self::OPTION_SUMMARY, array() );
		$posts_meta = get_option( self::OPTION_POSTS_META, array() );
		if ( ! is_array( $summary ) ) {
			$summary = array();
		}
		if ( ! is_array( $posts_meta ) ) {
			$posts_meta = array();
		}

		$table = $wpdb->prefix . self::TABLE_AUDIT_RESULTS;
		$rows  = $wpdb->get_results( "SELECT post_id, issue_type, severity FROM {$table} ORDER BY post_id, id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$issues_by_post = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$pid = (int) ( $r['post_id'] ?? 0 );
				if ( ! isset( $issues_by_post[ $pid ] ) ) {
					$issues_by_post[ $pid ] = array();
				}
				$issues_by_post[ $pid ][] = array(
					'id'       => $r['issue_type'] ?? '',
					'severity' => $r['severity'] ?? 'warning',
				);
			}
		}

		$results = array();
		$all_ids = array_unique( array_merge( array_keys( $posts_meta ), array_keys( $issues_by_post ) ) );
		foreach ( $all_ids as $post_id ) {
			$meta = $posts_meta[ $post_id ] ?? array();
			$results[ $post_id ] = array(
				'post_id'    => (int) $post_id,
				'post_title' => $meta['post_title'] ?? '',
				'post_type'  => $meta['post_type'] ?? 'post',
				'permalink'  => $meta['permalink'] ?? '',
				'seo_score'  => isset( $meta['seo_score'] ) ? (int) $meta['seo_score'] : 0,
				'issues'     => $issues_by_post[ $post_id ] ?? array(),
			);
		}

		return array(
			'last_run' => (int) $last_run,
			'results'  => $results,
			'summary'  => $summary,
		);
	}

	/**
	 * AJAX: Start or continue on-demand audit (polling).
	 */
	public function ajax_run_audit(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$step = isset( $_POST['step'] ) ? sanitize_text_field( wp_unslash( $_POST['step'] ) ) : '';
		if ( $step === 'start' ) {
			$ids = $this->get_audit_post_ids();
			set_transient( self::TRANSIENT_QUEUE, $ids, 600 );
			delete_transient( self::TRANSIENT_PROGRESS );
			$canonical_data = $this->build_canonical_and_noindex_data();
			set_transient( self::TRANSIENT_CANONICAL_DATA, $canonical_data, 600 );
			wp_send_json_success( array( 'total' => count( $ids ) ) );
		}
		if ( $step === 'next' ) {
			$queue = get_transient( self::TRANSIENT_QUEUE );
			if ( ! is_array( $queue ) || empty( $queue ) ) {
				$progress = get_transient( self::TRANSIENT_PROGRESS );
				if ( is_array( $progress ) && ! empty( $progress ) ) {
					$full = $this->finalize_progress_results( $progress );
					$this->save_results( $full );
					delete_transient( self::TRANSIENT_QUEUE );
					delete_transient( self::TRANSIENT_PROGRESS );
					delete_transient( self::TRANSIENT_CANONICAL_DATA );
					wp_send_json_success( array( 'done' => true, 'results' => $full ) );
				}
				delete_transient( self::TRANSIENT_CANONICAL_DATA );
				wp_send_json_success( array( 'done' => true ) );
			}
			$batch = array_slice( $queue, 0, self::BATCH_SIZE );
			$remaining = array_slice( $queue, self::BATCH_SIZE );
			set_transient( self::TRANSIENT_QUEUE, $remaining, 600 );
			$progress = get_transient( self::TRANSIENT_PROGRESS );
			if ( ! is_array( $progress ) ) {
				$progress = array();
			}
			$canonical_data = get_transient( self::TRANSIENT_CANONICAL_DATA );
			$canonical_map = is_array( $canonical_data ) && isset( $canonical_data['canonical_map'] ) ? $canonical_data['canonical_map'] : array();
			$noindex_ids   = is_array( $canonical_data ) && isset( $canonical_data['noindex_ids'] ) ? $canonical_data['noindex_ids'] : array();
			$rows = $this->run_audit_batch( $batch, array(), array(), $canonical_map, $noindex_ids );
			$progress = array_merge( $progress, $rows );
			set_transient( self::TRANSIENT_PROGRESS, $progress, 600 );
			$processed = count( $progress );
			$total = $processed + count( $remaining );
			if ( empty( $remaining ) ) {
				$full = $this->finalize_progress_results( $progress );
				$full['results'] = $this->add_broken_internal_links_issues( $full['results'] );
				$full['summary'] = $this->build_summary( $full['results'] );
				$this->save_results( $full );
				delete_transient( self::TRANSIENT_QUEUE );
				delete_transient( self::TRANSIENT_PROGRESS );
				delete_transient( self::TRANSIENT_CANONICAL_DATA );
				wp_send_json_success( array( 'done' => true, 'processed' => $total, 'total' => $total, 'results' => $full ) );
			}
			wp_send_json_success( array( 'done' => false, 'processed' => $processed, 'total' => $total ) );
		}
		wp_send_json_error( array( 'message' => 'Invalid step' ) );
	}

	/**
	 * Apply duplicate detection to progress results and add summary.
	 */
	protected function finalize_progress_results( array $progress ): array {
		$dups = $this->compute_duplicates( $progress );
		foreach ( $progress as $post_id => $row ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$title = get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true );
			$desc  = get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true );
			$t = $this->normalize_for_duplicate( trim( (string) $title ) !== '' ? $title : $post->post_title );
			$d = $this->normalize_for_duplicate( $desc );
			if ( $t !== '' && isset( $dups['titles'][ $t ] ) ) {
				$progress[ $post_id ]['issues'][] = array( 'id' => 'duplicate_meta_title', 'severity' => 'warning' );
			}
			if ( $d !== '' && isset( $dups['descriptions'][ $d ] ) ) {
				$progress[ $post_id ]['issues'][] = array( 'id' => 'duplicate_meta_description', 'severity' => 'warning' );
			}
		}
		$summary = $this->build_summary( $progress );
		return array(
			'last_run' => time(),
			'results'  => $progress,
			'summary'  => $summary,
		);
	}

	public function register_site_audit_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Site Audit', 'meyvora-seo' ),
			__( 'Site Audit', 'meyvora-seo' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_audit_page' )
		);
	}

	public function render_audit_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$data = $this->get_stored_results();
		$view_file = MEYVORA_SEO_PATH . 'admin/views/audit.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Site Audit', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'View not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_' . self::PAGE_SLUG ) {
			return;
		}
		$script = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-audit.js';
		if ( ! file_exists( $script ) ) {
			return;
		}
		wp_enqueue_script(
			'meyvora-audit',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-audit.js',
			array( 'jquery' ),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script( 'meyvora-audit', 'meyvoraAudit', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'i18n'    => array(
				'runNow'     => __( 'Run Audit Now', 'meyvora-seo' ),
				'running'    => __( 'Running audit…', 'meyvora-seo' ),
				'done'       => __( 'Audit complete', 'meyvora-seo' ),
				'quickFix'   => __( 'Quick Fix', 'meyvora-seo' ),
			),
		) );
	}
}
