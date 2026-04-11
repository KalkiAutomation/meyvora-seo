<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Lightweight SEO analysis engine for posts/pages.
 * Each check has a fixed weight (points). Score = sum of points earned (pass = full, warning = half, fail = 0), capped at 100.
 * Content can be provided from Elementor via the meyvora_seo_analysis_content filter.
 * Caching: if post content + focus keywords + SEO title + meta description hash has not changed since last analysis, cached result is returned.
 *
 * SCORING.md — Every check and weight (weights sum to 100):
 * ---
 * Focus keyword
 *   focus_keyword_set (4)         — At least one focus keyword is set.
 *   focus_keyword_title (10)      — Primary keyword appears in SEO title.
 *   focus_keyword_description (8)— Primary keyword appears in meta description.
 *   focus_keyword_slug (4)        — Primary keyword appears in URL slug.
 *   focus_keyword_content (10)   — Primary keyword appears in body content.
 *   focus_keyword_early (2)       — Primary keyword in first ~200 chars of content.
 *   focus_keyword_secondary (0)   — Bonus only; secondary keywords in title/desc/density.
 * Title & description
 *   title_length (6)             — SEO title character count 30–60 (truncation in SERPs).
 *   title_pixel_width (3)        — Estimated title width 200–600px at ~7px/char (bold); flag if too short or truncated.
 *   description_length (6)       — Meta description 120–160 characters.
 * Content length & structure
 *   content_length (1)           — Body at least 600 characters.
 *   content_word_count (2)       — Body at least 150 words.
 *   h1_count (3)                 — Exactly one H1.
 *   headings_structure (3)       — At least one H2 or H3.
 *   keyword_in_first_h2 (3)      — Primary keyword in first H2.
 *   keyword_in_h3_h4 (3)         — Primary keyword in at least one H3 or H4.
 *   keyword_in_last_10_percent (2)— Primary keyword in last 10% of content.
 * Images & links
 *   images_alt (3)                — All images have non-empty alt text.
 *   keyword_in_image_alt (3)     — At least one image alt contains primary keyword.
 *   image_per_300_words (3)       — At least one image per 300 words.
 *   internal_links (3)            — At least one internal link.
 *   external_links (2)            — External links present and at least one is dofollow.
 * Keyword & quality
 *   keyword_density (4)          — Primary keyword density 0.5–2.5%.
 *   paragraph_count (2)           — At least 3 paragraphs.
 *   toc_long_content (2)         — For content >2000 words, TOC present (id or shortcode).
 * Readability (when Meyvora_SEO_Readability available)
 *   sentence_length (3)         — Average sentence ≤25 words.
 *   passive_voice (2)             — Passive voice <10%.
 *   transition_words (2)         — ≥30% sentences with transition words.
 *   flesch_reading_ease (3)      — Flesch score ≥60.
 * Meta & schema
 *   og_image_set (2)              — OG image set for social.
 *   schema_set (1)                — Schema type set.
 * ---
 *
 * @package Meyvora_SEO
 */

class Meyvora_SEO_Analyzer {

	const TITLE_MIN   = 30;
	const TITLE_MAX   = 60;
	const DESC_MIN    = 120;
	const DESC_MAX    = 160;
	const CONTENT_MIN      = 600;
	const CONTENT_MIN_WORDS = 150;

	/** Approximate pixel width: 200px min, 600px max for desktop SERP title (bold ~7px per character). */
	const TITLE_PIXEL_MIN   = 200;
	const TITLE_PIXEL_MAX   = 600;
	const AVG_CHAR_WIDTH_PX = 7;

	/** Words threshold above which we expect a table of contents. */
	const TOC_WORDS_THRESHOLD = 2000;

	/** Target: at least one image per this many words. */
	const IMAGE_PER_WORDS = 300;

	/**
	 * Points per check when status is 'pass'. Warning = floor(weight/2), fail = 0.
	 * Total of these weights = 100.
	 *
	 * @var array<string, int>
	 */
	const FOCUS_KEYWORDS_MAX = 5;

	const CHECK_WEIGHTS = array(
		'focus_keyword_set'          => 4,
		'focus_keyword_title'        => 10,
		'focus_keyword_description' => 8,
		'focus_keyword_slug'         => 4,
		'focus_keyword_content'      => 10,
		'focus_keyword_early'        => 2,
		'focus_keyword_secondary'    => 0,
		'title_length'               => 6,
		'title_pixel_width'          => 3,
		'description_length'         => 6,
		'content_length'            => 1,
		'content_word_count'        => 2,
		'h1_count'                   => 3,
		'headings_structure'         => 3,
		'images_alt'                 => 3,
		'keyword_in_image_alt'       => 3,
		'image_per_300_words'        => 3,
		'internal_links'             => 3,
		'external_links'             => 2,
		'keyword_density'            => 4,
		'keyword_in_first_h2'        => 3,
		'keyword_in_h3_h4'            => 3,
		'keyword_in_last_10_percent' => 2,
		'paragraph_count'            => 2,
		'toc_long_content'           => 2,
		'cornerstone_min_length'     => 2,
		'sentence_length'            => 3,
		'passive_voice'              => 2,
		'transition_words'           => 2,
		'flesch_reading_ease'        => 3,
		'og_image_set'               => 2,
		'schema_set'                 => 1,
	);

	/**
	 * Normalize focus keyword meta to an array of strings (max FOCUS_KEYWORDS_MAX).
	 * Backward compatible: plain string becomes single-element array; JSON array decoded.
	 *
	 * @param string|array $raw Raw meta value (string or array).
	 * @return array<int, string>
	 */
	public static function normalize_focus_keywords( $raw ): array {
		if ( is_array( $raw ) ) {
			$out = array_values( array_filter( array_map( function ( $v ) {
				return is_string( $v ) ? trim( $v ) : '';
			}, $raw ), function ( $v ) {
				return $v !== '';
			} ) );
			return array_slice( array_unique( $out ), 0, self::FOCUS_KEYWORDS_MAX );
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$trimmed = trim( $raw );
		if ( $trimmed === '' ) {
			return array();
		}
		if ( ( $trimmed[0] ?? '' ) === '[' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$out = array_values( array_filter( array_map( function ( $v ) {
					return is_string( $v ) ? trim( $v ) : '';
				}, $decoded ), function ( $v ) {
					return $v !== '';
				} ) );
				return array_slice( array_unique( $out ), 0, self::FOCUS_KEYWORDS_MAX );
			}
		}
		return array( $trimmed );
	}

	/**
	 * Get display string for focus keywords (comma-separated). Backward compatible with single keyword meta.
	 *
	 * @param string|array $raw Raw meta value.
	 * @return string
	 */
	public static function get_focus_keywords_display( $raw ): string {
		$arr = self::normalize_focus_keywords( $raw );
		return implode( ', ', $arr );
	}

	/**
	 * Run analysis for a post.
	 *
	 * @param int         $post_id   Post ID.
	 * @param string|null $content   Optional content to analyze (e.g. from Elementor). If null, uses post_content.
	 * @param array       $overrides Optional live values: 'focus_keyword' (string), 'focus_keywords' (array), 'title', 'description'.
	 * @return array{ score: int, status: string, results: array<int, array{ id: string, status: string, label: string, message: string }> }
	 */
	public function analyze( int $post_id, ?string $content = null, array $overrides = array() ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $this->empty_result();
		}

		$prev_score = (int) get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true );

		if ( $content === null ) {
			$content = (string) $post->post_content;
		}

		$raw_focus = isset( $overrides['focus_keywords'] ) && is_array( $overrides['focus_keywords'] )
			? $overrides['focus_keywords']
			: ( isset( $overrides['focus_keyword'] ) ? $overrides['focus_keyword'] : get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true ) );
		$focus_keywords = self::normalize_focus_keywords( $raw_focus );
		$primary_keyword = $focus_keywords[0] ?? '';
		$secondary_keywords = array_slice( $focus_keywords, 1 );

		$seo_title_meta = isset( $overrides['title'] ) ? (string) $overrides['title'] : get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true );
		$meta_desc_meta = isset( $overrides['description'] ) ? (string) $overrides['description'] : get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true );
		// Cache key: content + focus keywords + title + description (and language when filtered). If unchanged, return cached result.
		$cache_input = $content . '|' . wp_json_encode( $focus_keywords ) . '|' . $seo_title_meta . '|' . $meta_desc_meta;
		$cache_input = apply_filters( 'meyvora_seo_analysis_cache_input', $cache_input, $post_id );
		$cache_key   = md5( $cache_input );
		$cached = get_post_meta( $post_id, MEYVORA_SEO_META_ANALYSIS_CACHE, true );
		if ( is_string( $cached ) && $cached !== '' && empty( $overrides ) ) {
			$decoded = json_decode( $cached, true );
			if ( is_array( $decoded ) && isset( $decoded['hash'], $decoded['result'] ) && $decoded['hash'] === $cache_key ) {
				return $decoded['result'];
			}
		}

		$seo_title     = is_string( $seo_title_meta ) ? trim( $seo_title_meta ) : '';
		$meta_desc     = is_string( $meta_desc_meta ) ? trim( $meta_desc_meta ) : '';

		$display_title = $seo_title !== '' ? $seo_title : $post->post_title;
		$display_desc  = $meta_desc !== '' ? $meta_desc : wp_trim_words( wp_strip_all_tags( $content ), 30 );
		$slug          = $post->post_name;
		$content_plain = wp_strip_all_tags( $content );
		$content_len   = strlen( $content_plain );

		$results = array();

		// Focus keyword(s) — primary drives score
		if ( $primary_keyword !== '' ) {
			$results[] = $this->result(
				'focus_keyword_set',
				'pass',
				__( 'Focus keyword', 'meyvora-seo' ),
				count( $focus_keywords ) > 1
					? sprintf( /* translators: 1: number of focus keywords, 2: primary keyword */ __( '%1$d focus keywords set (primary: %2$s).', 'meyvora-seo' ), count( $focus_keywords ), esc_html( $primary_keyword ) )
					: __( 'Focus keyword is set.', 'meyvora-seo' )
			);
			$results[] = $this->check_focus_keyword_in_title( $primary_keyword, $display_title );
			$results[] = $this->check_focus_keyword_in_description( $primary_keyword, $display_desc );
			$results[] = $this->check_focus_keyword_in_slug( $primary_keyword, $slug );
			$results[] = $this->check_focus_keyword_in_content( $primary_keyword, $content_plain );
			$results[] = $this->check_focus_keyword_early_in_content( $primary_keyword, $content_plain );
		} else {
			$results[] = $this->result(
				'focus_keyword_set',
				'fail',
				__( 'Focus keyword', 'meyvora-seo' ),
				__( 'Set a focus keyword to unlock full scoring and keyword checks.', 'meyvora-seo' )
			);
		}

		// Lengths (character + title pixel width)
		$results[] = $this->check_title_length( $display_title );
		$results[] = $this->check_title_pixel_width( $display_title );
		$results[] = $this->check_description_length( $display_desc );
		$results[] = $this->check_content_length( $content_len );
		$results[] = $this->check_content_word_count( $content_plain );

		// Structure (from content)
		$results[] = $this->check_h1_count( $content );
		$results[] = $this->check_headings_structure( $content );
		$results[] = $this->check_images_alt( $content );
		$cornerstone = (bool) get_post_meta( $post_id, MEYVORA_SEO_META_CORNERSTONE, true );
		$results[]   = $this->check_internal_links( $content, $cornerstone );
		$results[] = $this->check_external_links( $content );
		$results[] = $this->check_image_per_300_words( $content, $content_plain );
		$results[] = $this->check_toc_long_content( $content, $content_plain );
		if ( $cornerstone ) {
			$results[] = $this->check_cornerstone_min_length( $content_plain );
		}

		if ( $primary_keyword !== '' ) {
			$results[] = $this->check_keyword_density( $primary_keyword, $content_plain );
			$results[] = $this->check_keyword_in_first_h2( $primary_keyword, $content );
			$results[] = $this->check_keyword_in_h3_h4( $primary_keyword, $content );
			$results[] = $this->check_keyword_in_last_10_percent( $primary_keyword, $content_plain );
			$results[] = $this->check_keyword_in_image_alt( $primary_keyword, $content );
		}

		// Secondary keywords — bonus checks only (weight 0)
		foreach ( $secondary_keywords as $idx => $kw ) {
			$results[] = $this->result_secondary_keyword( $kw, $display_title, $display_desc, $content_plain );
		}
		$results[] = $this->check_paragraph_count( $content );
		if ( class_exists( 'Meyvora_SEO_Readability' ) ) {
			$results[] = $this->check_sentence_length( $content_plain );
			$results[] = $this->check_passive_voice( $content_plain );
			$results[] = $this->check_transition_words( $content_plain );
			$results[] = $this->check_flesch_reading_ease( $content_plain );
		}
		$results[] = $this->check_og_image_set( $post_id );
		$results[] = $this->check_schema_set( $post_id );

		$score  = $this->calculate_score( $results );
		$status = $this->score_status( $score );

		$readability_score = null;
		if ( class_exists( 'Meyvora_SEO_Readability' ) ) {
			$readability = Meyvora_SEO_Readability::analyze( $content_plain );
			$readability_score = isset( $readability['score'] ) ? (int) $readability['score'] : null;
			if ( $readability_score !== null ) {
				update_post_meta( $post_id, MEYVORA_SEO_META_READABILITY, $readability_score );
			}
		}

		$result = array(
			'score'        => $score,
			'status'       => $status,
			'results'      => $results,
			'readability'  => $readability_score,
		);

		$new_score = (int) $result['score'];
		update_post_meta( $post_id, MEYVORA_SEO_META_SCORE_PREV, $prev_score );
		update_post_meta( $post_id, MEYVORA_SEO_META_SCORE, $new_score );

		$drop_threshold = 10;
		if ( function_exists( 'meyvora_seo' ) ) {
			$opts = meyvora_seo()->get_options();
			$drop_threshold = (int) $opts->get( 'score_alert_threshold', 10 );
		}
		if ( $prev_score > 0 && ( $prev_score - $new_score ) >= $drop_threshold ) {
			do_action( 'meyvora_seo_score_dropped', $post_id, $prev_score, $new_score );
		}

		update_post_meta( $post_id, MEYVORA_SEO_META_ANALYSIS_CACHE, wp_json_encode( array( 'hash' => $cache_key, 'result' => $result, 'timestamp' => time() ) ) );

		return $result;
	}

	/**
	 * Return the maximum possible score (sum of all check weights). Always 100.
	 *
	 * @return int
	 */
	public static function get_max_score(): int {
		return 100;
	}

	/**
	 * Clear analysis cache for a post so the next analysis runs fresh.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function clear_analysis_cache( int $post_id ): void {
		delete_post_meta( $post_id, MEYVORA_SEO_META_ANALYSIS_CACHE );
	}

	/**
	 * @return array{ score: int, status: string, results: array }
	 */
	private function empty_result(): array {
		return array(
			'score'   => 0,
			'status'  => 'poor',
			'results' => array(),
		);
	}

	private function result( string $id, string $status, string $label, string $message ): array {
		$weight = self::CHECK_WEIGHTS[ $id ] ?? ( str_starts_with( $id, 'secondary_keyword_' ) ? 1 : 0 );
		$out = array(
			'id'      => $id,
			'status'  => $status,
			'label'   => $label,
			'message' => $message,
			'weight'  => $weight,
		);
		$out['points_earned'] = $this->points_for_status( $status, $weight );
		return $out;
	}

	private function points_for_status( string $status, int $weight ): int {
		if ( $weight === 0 ) {
			return 0;
		}
		if ( $status === 'pass' ) {
			return $weight;
		}
		if ( $status === 'warning' ) {
			return (int) floor( $weight / 2 );
		}
		return 0;
	}

	private function check_focus_keyword_in_title( string $keyword, string $title ): array {
		$ok = stripos( $title, $keyword ) !== false;
		return $this->result(
			'focus_keyword_title',
			$ok ? 'pass' : 'fail',
			__( 'Focus keyword in title', 'meyvora-seo' ),
			$ok ? __( 'Focus keyword appears in the SEO title.', 'meyvora-seo' ) : __( 'Add the focus keyword to the SEO title.', 'meyvora-seo' )
		);
	}

	private function check_focus_keyword_in_description( string $keyword, string $desc ): array {
		$ok = stripos( $desc, $keyword ) !== false;
		return $this->result(
			'focus_keyword_description',
			$ok ? 'pass' : 'fail',
			__( 'Focus keyword in description', 'meyvora-seo' ),
			$ok ? __( 'Focus keyword appears in the meta description.', 'meyvora-seo' ) : __( 'Add the focus keyword to the meta description.', 'meyvora-seo' )
		);
	}

	private function check_focus_keyword_in_slug( string $keyword, string $slug ): array {
		$keyword_slug = sanitize_title( $keyword );
		$ok           = $slug !== '' && ( $slug === $keyword_slug || strpos( $slug, $keyword_slug ) !== false );
		return $this->result(
			'focus_keyword_slug',
			$ok ? 'pass' : 'warning',
			__( 'Focus keyword in URL', 'meyvora-seo' ),
			$ok ? __( 'Focus keyword appears in the URL slug.', 'meyvora-seo' ) : __( 'Consider including the focus keyword in the URL slug.', 'meyvora-seo' )
		);
	}

	private function check_focus_keyword_in_content( string $keyword, string $content ): array {
		$ok = stripos( $content, $keyword ) !== false;
		return $this->result(
			'focus_keyword_content',
			$ok ? 'pass' : 'fail',
			__( 'Focus keyword in content', 'meyvora-seo' ),
			$ok ? __( 'Focus keyword appears in the content.', 'meyvora-seo' ) : __( 'Add the focus keyword to the content.', 'meyvora-seo' )
		);
	}

	private function check_focus_keyword_early_in_content( string $keyword, string $content ): array {
		$first = substr( $content, 0, 200 );
		$ok    = stripos( $first, $keyword ) !== false;
		return $this->result(
			'focus_keyword_early',
			$ok ? 'pass' : 'warning',
			__( 'Focus keyword early in content', 'meyvora-seo' ),
			$ok ? __( 'Focus keyword appears early in the content.', 'meyvora-seo' ) : __( 'Consider using the focus keyword in the first paragraph.', 'meyvora-seo' )
		);
	}

	private function check_title_length( string $title ): array {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		if ( $len >= self::TITLE_MIN && $len <= self::TITLE_MAX ) {
			$status = 'pass';
			$msg    = __( 'SEO title length is good.', 'meyvora-seo' );
		} elseif ( $len > 0 && $len < self::TITLE_MIN ) {
			$status = 'warning';
			$msg    = sprintf( /* translators: 1: minimum length, 2: maximum length */ __( 'SEO title is short. Aim for %1$d–%2$d characters.', 'meyvora-seo' ), self::TITLE_MIN, self::TITLE_MAX );
		} elseif ( $len > self::TITLE_MAX ) {
			$status = 'warning';
			$msg    = sprintf( /* translators: 1: minimum length, 2: maximum length */ __( 'SEO title may be truncated in search. Aim for %1$d–%2$d characters.', 'meyvora-seo' ), self::TITLE_MIN, self::TITLE_MAX );
		} else {
			$status = 'fail';
			$msg    = __( 'Set an SEO title.', 'meyvora-seo' );
		}
		return $this->result( 'title_length', $status, __( 'SEO title length', 'meyvora-seo' ), $msg );
	}

	/**
	 * Title pixel-width check: ~7px per character (bold). Flag if &lt;200px or &gt;600px (desktop SERP).
	 *
	 * @param string $title SEO or display title.
	 * @return array
	 */
	private function check_title_pixel_width( string $title ): array {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		$width_px = $len * self::AVG_CHAR_WIDTH_PX;
		if ( $len === 0 ) {
			return $this->result( 'title_pixel_width', 'fail', __( 'Title pixel width', 'meyvora-seo' ), __( 'Set an SEO title.', 'meyvora-seo' ) );
		}
		if ( $width_px >= self::TITLE_PIXEL_MIN && $width_px <= self::TITLE_PIXEL_MAX ) {
			return $this->result( 'title_pixel_width', 'pass', __( 'Title pixel width', 'meyvora-seo' ), sprintf( /* translators: %d: approximate pixel width */ __( 'Title width ~%dpx (good for desktop SERP).', 'meyvora-seo' ), $width_px ) );
		}
		if ( $width_px < self::TITLE_PIXEL_MIN ) {
			return $this->result( 'title_pixel_width', 'warning', __( 'Title pixel width', 'meyvora-seo' ), sprintf( /* translators: %d: approximate pixel width */ __( 'Title is short (~%dpx). Aim for 200–600px for desktop.', 'meyvora-seo' ), $width_px ) );
		}
		return $this->result( 'title_pixel_width', 'warning', __( 'Title pixel width', 'meyvora-seo' ), sprintf( /* translators: %d: approximate pixel width */ __( 'Title may be cut off (~%dpx). Keep under 600px for desktop.', 'meyvora-seo' ), $width_px ) );
	}

	private function check_description_length( string $desc ): array {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $desc ) : strlen( $desc );
		if ( $len >= self::DESC_MIN && $len <= self::DESC_MAX ) {
			$status = 'pass';
			$msg    = __( 'Meta description length is good.', 'meyvora-seo' );
		} elseif ( $len > 0 && $len < self::DESC_MIN ) {
			$status = 'warning';
			$msg    = sprintf( /* translators: 1: minimum length, 2: maximum length */ __( 'Meta description is short. Aim for %1$d–%2$d characters.', 'meyvora-seo' ), self::DESC_MIN, self::DESC_MAX );
		} elseif ( $len > self::DESC_MAX ) {
			$status = 'warning';
			$msg    = sprintf( /* translators: 1: minimum length, 2: maximum length */ __( 'Meta description may be truncated. Aim for %1$d–%2$d characters.', 'meyvora-seo' ), self::DESC_MIN, self::DESC_MAX );
		} else {
			$status = 'fail';
			$msg    = __( 'Set a meta description.', 'meyvora-seo' );
		}
		return $this->result( 'description_length', $status, __( 'Meta description length', 'meyvora-seo' ), $msg );
	}

	private function check_content_length( int $content_len ): array {
		$ok = $content_len >= self::CONTENT_MIN;
		$msg = $ok ? sprintf( /* translators: %d: character count */ __( 'Content length is good (%d characters).', 'meyvora-seo' ), $content_len ) : sprintf( /* translators: %d: minimum character count */ __( 'Content is short. Aim for at least %d characters.', 'meyvora-seo' ), self::CONTENT_MIN );
		return $this->result( 'content_length', $ok ? 'pass' : 'warning', __( 'Content length', 'meyvora-seo' ), $msg );
	}

	private function check_content_word_count( string $content_plain ): array {
		$words = str_word_count( wp_strip_all_tags( $content_plain ) );
		$ok    = $words >= self::CONTENT_MIN_WORDS;
		$msg   = $ok
			? sprintf( /* translators: %d: word count */ __( 'Content word count is good (%d words).', 'meyvora-seo' ), $words )
			: sprintf( /* translators: 1: minimum words, 2: current count */ __( 'Aim for at least %1$d words (current: %2$d).', 'meyvora-seo' ), self::CONTENT_MIN_WORDS, $words );
		return $this->result( 'content_word_count', $ok ? 'pass' : 'warning', __( 'Content word count', 'meyvora-seo' ), $msg );
	}

	private function check_h1_count( string $content ): array {
		$count = preg_match_all( '/<h1[^>]*>/i', $content, $m ) ? count( $m[0] ) : 0;
		if ( $count === 1 ) {
			$status = 'pass';
			$msg    = __( 'Exactly one H1 found.', 'meyvora-seo' );
		} elseif ( $count === 0 ) {
			$status = 'warning';
			$msg    = __( 'No H1 found. Add one main heading.', 'meyvora-seo' );
		} else {
			$status = 'warning';
			$msg    = sprintf( /* translators: %d: number of H1 headings */ __( 'Multiple H1s found (%d). Use a single H1 per page.', 'meyvora-seo' ), $count );
		}
		return $this->result( 'h1_count', $status, __( 'H1 heading', 'meyvora-seo' ), $msg );
	}

	private function check_headings_structure( string $content ): array {
		$h2 = preg_match_all( '/<h2[^>]*>/i', $content, $m ) ? count( $m[0] ) : 0;
		$h3 = preg_match_all( '/<h3[^>]*>/i', $content, $m ) ? count( $m[0] ) : 0;
		$ok = $h2 > 0 || $h3 > 0;
		$msg = $ok ? __( 'Content uses subheadings.', 'meyvora-seo' ) : __( 'Consider adding H2/H3 subheadings for structure.', 'meyvora-seo' );
		return $this->result( 'headings_structure', $ok ? 'pass' : 'warning', __( 'Headings structure', 'meyvora-seo' ), $msg );
	}

	private function check_images_alt( string $content ): array {
		preg_match_all( '/<img[^>]+>/i', $content, $imgs );
		$total = isset( $imgs[0] ) ? count( $imgs[0] ) : 0;
		if ( $total === 0 ) {
			return $this->result( 'images_alt', 'pass', __( 'Image alt text', 'meyvora-seo' ), __( 'No images to check.', 'meyvora-seo' ) );
		}
		$with_alt = 0;
		foreach ( $imgs[0] as $tag ) {
			if ( preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/i', $tag, $m ) && trim( $m[1] ) !== '' ) {
				$with_alt++;
			}
		}
		$ok = $with_alt === $total;
		$status = $ok ? 'pass' : ( $with_alt > 0 ? 'warning' : 'fail' );
		$msg = $ok ? __( 'All images have alt text.', 'meyvora-seo' ) : sprintf( /* translators: 1: count with alt, 2: total images */ __( '%1$d of %2$d images have alt text.', 'meyvora-seo' ), $with_alt, $total );
		return $this->result( 'images_alt', $status, __( 'Image alt text', 'meyvora-seo' ), $msg );
	}

	private function check_internal_links( string $content, bool $is_cornerstone = false ): array {
		$home = home_url( '/' );
		preg_match_all( '/<a[^>]+href\s*=\s*["\']([^"\']+)["\']/i', $content, $m );
		$links = isset( $m[1] ) ? $m[1] : array();
		$internal = 0;
		foreach ( $links as $url ) {
			if ( strpos( $url, $home ) === 0 || strpos( $url, '/' ) === 0 ) {
				$internal++;
			}
		}
		$ok  = $internal > 0;
		$msg = $ok ? sprintf( /* translators: %d: number of internal links */ __( '%d internal link(s) found.', 'meyvora-seo' ), $internal ) : __( 'Consider adding internal links to other content.', 'meyvora-seo' );
		$out = $this->result( 'internal_links', $ok ? 'pass' : 'warning', __( 'Internal links', 'meyvora-seo' ), $msg );
		if ( $is_cornerstone ) {
			$out['weight']        = 6;
			$out['points_earned'] = $this->points_for_status( $out['status'], 6 );
		}
		return $out;
	}

	/** Cornerstone content: minimum 1500 words. Only run when post is marked cornerstone. */
	private function check_cornerstone_min_length( string $content_plain ): array {
		$words = str_word_count( wp_strip_all_tags( $content_plain ) );
		$min   = 1500;
		$ok    = $words >= $min;
		$msg   = $ok
			? sprintf( /* translators: %d: word count */ __( 'Cornerstone content length: %d words.', 'meyvora-seo' ), $words )
			: sprintf( /* translators: 1: minimum words, 2: current word count */ __( 'Cornerstone content should be at least %1$d words (current: %2$d).', 'meyvora-seo' ), $min, $words );
		return $this->result( 'cornerstone_min_length', $ok ? 'pass' : 'warning', __( 'Cornerstone length', 'meyvora-seo' ), $msg );
	}

	/**
	 * External links: present and at least one is dofollow (no rel="nofollow").
	 *
	 * @param string $content HTML content.
	 * @return array
	 */
	private function check_external_links( string $content ): array {
		$home = home_url();
		preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>/i', $content, $full_matches );
		$tags = isset( $full_matches[0] ) ? $full_matches[0] : array();
		$urls = isset( $full_matches[1] ) ? $full_matches[1] : array();
		$external_count = 0;
		$dofollow_count = 0;
		foreach ( $tags as $i => $tag ) {
			$url = isset( $urls[ $i ] ) ? trim( $urls[ $i ] ) : '';
			if ( $url === '' || strpos( $url, '#' ) === 0 ) {
				continue;
			}
			if ( strpos( $url, $home ) === 0 || ( strpos( $url, 'http' ) !== 0 && strpos( $url, '//' ) !== 0 ) ) {
				continue;
			}
			$external_count++;
			$has_nofollow = (bool) preg_match( '/\brel\s*=\s*["\'][^"\']*nofollow[^"\']*["\']/i', $tag );
			if ( ! $has_nofollow ) {
				$dofollow_count++;
			}
		}
		if ( $external_count === 0 ) {
			return $this->result( 'external_links', 'warning', __( 'External links', 'meyvora-seo' ), __( 'Consider adding external links to authoritative sources.', 'meyvora-seo' ) );
		}
		if ( $dofollow_count > 0 ) {
			return $this->result( 'external_links', 'pass', __( 'External links', 'meyvora-seo' ), sprintf( /* translators: 1: total external links, 2: dofollow count */ __( '%1$d external link(s), %2$d dofollow.', 'meyvora-seo' ), $external_count, $dofollow_count ) );
		}
		return $this->result( 'external_links', 'warning', __( 'External links', 'meyvora-seo' ), __( 'External links are all nofollow. At least one dofollow link can help.', 'meyvora-seo' ) );
	}

	/**
	 * Score = sum of points_earned from each result. Pass = full weight, warning = half (floor), fail = 0.
	 * Capped at 100. Each result already has points_earned set by result() / points_for_status().
	 *
	 * @param array<int, array{ points_earned?: int }> $results
	 * @return int 0-100
	 */
	private function calculate_score( array $results ): int {
		$sum = 0;
		foreach ( $results as $r ) {
			$sum += (int) ( $r['points_earned'] ?? 0 );
		}
		return min( 100, max( 0, $sum ) );
	}

	private function score_status( int $score ): string {
		if ( $score >= 80 ) {
			return 'good';
		}
		if ( $score >= 50 ) {
			return 'okay';
		}
		return 'poor';
	}

	private function check_keyword_density( string $keyword, string $content ): array {
		$words = str_word_count( $content, 0 );
		if ( $words < 1 ) {
			return $this->result( 'keyword_density', 'fail', __( 'Keyword density', 'meyvora-seo' ), __( 'No content to analyze.', 'meyvora-seo' ) );
		}
		$kw_words = str_word_count( $keyword, 0 );
		$pattern = '/\b' . preg_quote( $keyword, '/' ) . '\b/iu';
		$count = preg_match_all( $pattern, $content );
		$density = ( $count * max( 1, $kw_words ) / $words ) * 100;
		if ( $density >= 0.5 && $density <= 2.5 ) {
			return $this->result( 'keyword_density', 'pass', __( 'Keyword density', 'meyvora-seo' ), sprintf( /* translators: %f: density percentage */ __( 'Keyword density is %.1f%%.', 'meyvora-seo' ), $density ) );
		}
		if ( $density > 3 ) {
			return $this->result( 'keyword_density', 'warning', __( 'Keyword density', 'meyvora-seo' ), __( 'Keyword may be overused. Aim for 0.5–2.5%.', 'meyvora-seo' ) );
		}
		if ( $count > 0 ) {
			return $this->result( 'keyword_density', 'warning', __( 'Keyword density', 'meyvora-seo' ), sprintf( /* translators: %f: density percentage */ __( 'Keyword density is %.1f%%. Aim for 0.5–2.5%.', 'meyvora-seo' ), $density ) );
		}
		return $this->result( 'keyword_density', 'fail', __( 'Keyword density', 'meyvora-seo' ), __( 'Focus keyword not found in content.', 'meyvora-seo' ) );
	}

	private function check_keyword_in_first_h2( string $keyword, string $content ): array {
		if ( ! preg_match( '/<h2[^>]*>(.*?)<\/h2>/is', $content, $m ) ) {
			return $this->result( 'keyword_in_first_h2', 'warning', __( 'Keyword in first H2', 'meyvora-seo' ), __( 'No H2 heading found.', 'meyvora-seo' ) );
		}
		$first_h2 = wp_strip_all_tags( $m[1] );
		$ok = stripos( $first_h2, $keyword ) !== false;
		return $this->result( 'keyword_in_first_h2', $ok ? 'pass' : 'fail', __( 'Keyword in first H2', 'meyvora-seo' ), $ok ? __( 'Focus keyword appears in the first H2.', 'meyvora-seo' ) : __( 'Add the focus keyword to the first H2 heading.', 'meyvora-seo' ) );
	}

	/**
	 * Primary keyword in at least one H3 or H4.
	 *
	 * @param string $keyword Primary focus keyword.
	 * @param string $content HTML content.
	 * @return array
	 */
	private function check_keyword_in_h3_h4( string $keyword, string $content ): array {
		$found = false;
		if ( preg_match_all( '/<h[34][^>]*>(.*?)<\/h[34]>/is', $content, $m ) && ! empty( $m[1] ) ) {
			foreach ( $m[1] as $heading ) {
				if ( stripos( wp_strip_all_tags( $heading ), $keyword ) !== false ) {
					$found = true;
					break;
				}
			}
		}
		if ( $found ) {
			return $this->result( 'keyword_in_h3_h4', 'pass', __( 'Keyword in H3/H4', 'meyvora-seo' ), __( 'Focus keyword appears in at least one H3 or H4.', 'meyvora-seo' ) );
		}
		if ( preg_match_all( '/<h[34][^>]*>/i', $content ) ) {
			return $this->result( 'keyword_in_h3_h4', 'fail', __( 'Keyword in H3/H4', 'meyvora-seo' ), __( 'Add the focus keyword to at least one H3 or H4 subheading.', 'meyvora-seo' ) );
		}
		return $this->result( 'keyword_in_h3_h4', 'warning', __( 'Keyword in H3/H4', 'meyvora-seo' ), __( 'No H3/H4 subheadings found. Add subheadings and use the focus keyword.', 'meyvora-seo' ) );
	}

	/**
	 * Primary keyword in last 10% of content body.
	 *
	 * @param string $keyword Primary focus keyword.
	 * @param string $content_plain Plain text content.
	 * @return array
	 */
	private function check_keyword_in_last_10_percent( string $keyword, string $content_plain ): array {
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $content_plain ) : strlen( $content_plain );
		if ( $len < 50 ) {
			return $this->result( 'keyword_in_last_10_percent', 'pass', __( 'Keyword in last 10%', 'meyvora-seo' ), __( 'Content too short to check.', 'meyvora-seo' ) );
		}
		$tail_len = (int) max( 50, ceil( $len * 0.1 ) );
		$last_part = function_exists( 'mb_substr' ) ? mb_substr( $content_plain, -$tail_len, null, 'UTF-8' ) : substr( $content_plain, -$tail_len );
		$ok = stripos( $last_part, $keyword ) !== false;
		return $this->result( 'keyword_in_last_10_percent', $ok ? 'pass' : 'warning', __( 'Keyword in last 10%', 'meyvora-seo' ), $ok ? __( 'Focus keyword appears in the last part of the content.', 'meyvora-seo' ) : __( 'Consider using the focus keyword in the closing section.', 'meyvora-seo' ) );
	}

	/**
	 * At least one image has alt text containing the primary keyword.
	 *
	 * @param string $keyword Primary focus keyword.
	 * @param string $content HTML content.
	 * @return array
	 */
	private function check_keyword_in_image_alt( string $keyword, string $content ): array {
		preg_match_all( '/<img[^>]+>/i', $content, $imgs );
		if ( empty( $imgs[0] ) ) {
			return $this->result( 'keyword_in_image_alt', 'pass', __( 'Keyword in image alt', 'meyvora-seo' ), __( 'No images to check.', 'meyvora-seo' ) );
		}
		$found = false;
		foreach ( $imgs[0] as $tag ) {
			if ( preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/i', $tag, $m ) && trim( $m[1] ) !== '' && stripos( $m[1], $keyword ) !== false ) {
				$found = true;
				break;
			}
		}
		return $this->result( 'keyword_in_image_alt', $found ? 'pass' : 'fail', __( 'Keyword in image alt', 'meyvora-seo' ), $found ? __( 'At least one image alt text contains the focus keyword.', 'meyvora-seo' ) : __( 'Add the focus keyword to at least one image alt text.', 'meyvora-seo' ) );
	}

	/**
	 * At least one image per IMAGE_PER_WORDS (300) words.
	 *
	 * @param string $content       HTML content.
	 * @param string $content_plain Plain text for word count.
	 * @return array
	 */
	private function check_image_per_300_words( string $content, string $content_plain ): array {
		$word_count = str_word_count( $content_plain, 0 );
		preg_match_all( '/<img[^>]+>/i', $content, $imgs );
		$img_count = isset( $imgs[0] ) ? count( $imgs[0] ) : 0;
		$min_images = $word_count > 0 ? (int) ceil( $word_count / self::IMAGE_PER_WORDS ) : 0;
		if ( $min_images <= 0 ) {
			return $this->result( 'image_per_300_words', 'pass', __( 'Images per 300 words', 'meyvora-seo' ), __( 'Content is short; no minimum images required.', 'meyvora-seo' ) );
		}
		$ok = $img_count >= $min_images;
		$msg = $ok
			? sprintf( /* translators: 1: image count, 2: word count, 3: words per image */ __( '%1$d image(s) for %2$d words (at least one per %3$d words).', 'meyvora-seo' ), $img_count, $word_count, self::IMAGE_PER_WORDS )
			: sprintf( /* translators: 1: word count, 2: minimum images, 3: words per image */ __( 'Add more images: %1$d words needs at least %2$d image(s) (one per %3$d words).', 'meyvora-seo' ), $word_count, $min_images, self::IMAGE_PER_WORDS );
		return $this->result( 'image_per_300_words', $ok ? 'pass' : 'warning', __( 'Images per 300 words', 'meyvora-seo' ), $msg );
	}

	/**
	 * For content over TOC_WORDS_THRESHOLD words, check for table of contents (id or shortcode).
	 *
	 * @param string $content       HTML content.
	 * @param string $content_plain Plain text for word count.
	 * @return array
	 */
	private function check_toc_long_content( string $content, string $content_plain ): array {
		$word_count = str_word_count( $content_plain, 0 );
		if ( $word_count < self::TOC_WORDS_THRESHOLD ) {
			return $this->result( 'toc_long_content', 'pass', __( 'Table of contents', 'meyvora-seo' ), sprintf( /* translators: 1: word count, 2: threshold for TOC */ __( 'Content has %1$d words; TOC recommended for %2$d+ words.', 'meyvora-seo' ), $word_count, self::TOC_WORDS_THRESHOLD ) );
		}
		$has_toc_id = (bool) preg_match( '/id\s*=\s*["\']table-of-contents["\']/i', $content );
		$has_toc_shortcode = (bool) preg_match( '/\[(toc|table-of-contents|wp-block-toc)[^\]]*\]/i', $content );
		$has_toc = $has_toc_id || $has_toc_shortcode;
		return $this->result( 'toc_long_content', $has_toc ? 'pass' : 'warning', __( 'Table of contents', 'meyvora-seo' ), $has_toc ? __( 'Table of contents present for long content.', 'meyvora-seo' ) : sprintf( /* translators: %d: word count */ __( 'Content is %d words. Consider adding a table of contents (id="table-of-contents" or TOC shortcode).', 'meyvora-seo' ), $word_count ) );
	}

	private function check_paragraph_count( string $content ): array {
		$count = substr_count( strtolower( $content ), '<p' );
		if ( $count >= 3 ) {
			return $this->result( 'paragraph_count', 'pass', __( 'Paragraph count', 'meyvora-seo' ), sprintf( /* translators: %d: number of paragraphs */ __( '%d paragraphs found.', 'meyvora-seo' ), $count ) );
		}
		if ( $count >= 1 ) {
			return $this->result( 'paragraph_count', 'warning', __( 'Paragraph count', 'meyvora-seo' ), __( 'Add more paragraphs (at least 3).', 'meyvora-seo' ) );
		}
		return $this->result( 'paragraph_count', 'fail', __( 'Paragraph count', 'meyvora-seo' ), __( 'No paragraphs found.', 'meyvora-seo' ) );
	}

	private function check_sentence_length( string $text ): array {
		$avg = Meyvora_SEO_Readability::get_average_sentence_length( $text );
		if ( $avg <= 0 ) {
			return $this->result( 'sentence_length', 'pass', __( 'Sentence length', 'meyvora-seo' ), __( 'No sentences to analyze.', 'meyvora-seo' ) );
		}
		if ( $avg <= 25 ) {
			return $this->result( 'sentence_length', 'pass', __( 'Sentence length', 'meyvora-seo' ), sprintf( /* translators: %f: average words per sentence */ __( 'Average sentence length is %.0f words.', 'meyvora-seo' ), $avg ) );
		}
		if ( $avg <= 35 ) {
			return $this->result( 'sentence_length', 'warning', __( 'Sentence length', 'meyvora-seo' ), sprintf( /* translators: %f: average words per sentence */ __( 'Average sentence length is %.0f words. Aim for ≤25.', 'meyvora-seo' ), $avg ) );
		}
		return $this->result( 'sentence_length', 'fail', __( 'Sentence length', 'meyvora-seo' ), sprintf( /* translators: %f: average words per sentence */ __( 'Sentences are long (avg %.0f words). Aim for ≤25.', 'meyvora-seo' ), $avg ) );
	}

	private function check_passive_voice( string $text ): array {
		$pct = Meyvora_SEO_Readability::get_passive_voice_percentage( $text );
		if ( $pct < 10 ) {
			return $this->result( 'passive_voice', 'pass', __( 'Passive voice', 'meyvora-seo' ), __( 'Low use of passive voice.', 'meyvora-seo' ) );
		}
		if ( $pct < 20 ) {
			return $this->result( 'passive_voice', 'warning', __( 'Passive voice', 'meyvora-seo' ), sprintf( /* translators: %f: passive voice percentage */ __( '%.0f%% passive. Consider using more active voice.', 'meyvora-seo' ), $pct ) );
		}
		return $this->result( 'passive_voice', 'fail', __( 'Passive voice', 'meyvora-seo' ), sprintf( /* translators: %f: passive voice percentage */ __( '%.0f%% passive. Use active voice.', 'meyvora-seo' ), $pct ) );
	}

	private function check_transition_words( string $text ): array {
		$pct = Meyvora_SEO_Readability::get_transition_word_percentage( $text );
		if ( $pct >= 30 ) {
			return $this->result( 'transition_words', 'pass', __( 'Transition words', 'meyvora-seo' ), __( 'Good use of transition words.', 'meyvora-seo' ) );
		}
		if ( $pct >= 20 ) {
			return $this->result( 'transition_words', 'warning', __( 'Transition words', 'meyvora-seo' ), sprintf( /* translators: %f: percentage of sentences with transition words */ __( '%.0f%% of sentences use transition words. Aim for 30%%.', 'meyvora-seo' ), $pct ) );
		}
		return $this->result( 'transition_words', 'fail', __( 'Transition words', 'meyvora-seo' ), __( 'Add transition words to improve flow.', 'meyvora-seo' ) );
	}

	private function check_flesch_reading_ease( string $text ): array {
		$score = Meyvora_SEO_Readability::calculate_flesch_reading_ease( $text );
		if ( $score >= 60 ) {
			return $this->result( 'flesch_reading_ease', 'pass', __( 'Flesch Reading Ease', 'meyvora-seo' ), sprintf( /* translators: %f: Flesch score */ __( 'Score: %.0f (easy to read).', 'meyvora-seo' ), $score ) );
		}
		if ( $score >= 45 ) {
			return $this->result( 'flesch_reading_ease', 'warning', __( 'Flesch Reading Ease', 'meyvora-seo' ), sprintf( /* translators: %f: Flesch score */ __( 'Score: %.0f. Aim for 60+.', 'meyvora-seo' ), $score ) );
		}
		return $this->result( 'flesch_reading_ease', 'fail', __( 'Flesch Reading Ease', 'meyvora-seo' ), sprintf( /* translators: %f: Flesch score */ __( 'Score: %.0f. Use shorter words and sentences.', 'meyvora-seo' ), $score ) );
	}

	private function check_og_image_set( int $post_id ): array {
		$img = get_post_meta( $post_id, MEYVORA_SEO_META_OG_IMAGE, true );
		$set = $img !== '' && $img !== '0' && (int) $img > 0;
		return $this->result( 'og_image_set', $set ? 'pass' : 'fail', __( 'OG image', 'meyvora-seo' ), $set ? __( 'OG image is set.', 'meyvora-seo' ) : __( 'Set an OG image for social sharing.', 'meyvora-seo' ) );
	}

	private function check_schema_set( int $post_id ): array {
		$type = get_post_meta( $post_id, MEYVORA_SEO_META_SCHEMA_TYPE, true );
		$set = is_string( $type ) && $type !== '' && $type !== 'None';
		return $this->result( 'schema_set', $set ? 'pass' : 'fail', __( 'Schema type', 'meyvora-seo' ), $set ? __( 'Schema type is set.', 'meyvora-seo' ) : __( 'Set a schema type in the Advanced tab.', 'meyvora-seo' ) );
	}

	/**
	 * Check a secondary keyword: in content (minimum), in title/description for pass.
	 *
	 * @param string $kw            Secondary keyword.
	 * @param string $title         Display title.
	 * @param string $desc          Meta description.
	 * @param string $content_plain Plain text content.
	 * @return array
	 */
	private function result_secondary_keyword(
		string $kw,
		string $title,
		string $desc,
		string $content_plain
	): array {
		$in_title   = ( $kw !== '' && mb_stripos( $title, $kw ) !== false );
		$in_desc    = ( $kw !== '' && mb_stripos( $desc, $kw ) !== false );
		$in_content = ( $kw !== '' && mb_stripos( $content_plain, $kw ) !== false );
		$pass       = $in_content; // minimum bar: appears in content
		$label      = sprintf(
			/* translators: %s: secondary keyword */
			__( 'Secondary keyword: %s', 'meyvora-seo' ),
			esc_html( $kw )
		);
		if ( $pass && $in_title ) {
			$msg = __( 'Found in content and title.', 'meyvora-seo' );
		} elseif ( $pass ) {
			$msg = __( 'Found in content. Consider adding to title or description.', 'meyvora-seo' );
			$pass = false; // warn
		} else {
			$msg = __( 'Not found in content. Add this keyword naturally.', 'meyvora-seo' );
		}
		return $this->result(
			'secondary_keyword_' . md5( $kw ),
			$pass ? 'pass' : ( $in_title || $in_desc ? 'warning' : 'fail' ),
			$label,
			$msg
		);
	}
}

if ( ! function_exists( 'meyvora_seo_clear_analysis_cache' ) ) {
	/**
	 * Clear analysis cache for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	function meyvora_seo_clear_analysis_cache( int $post_id ): void {
		Meyvora_SEO_Analyzer::clear_analysis_cache( $post_id );
	}
}
