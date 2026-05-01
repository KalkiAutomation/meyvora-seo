<?php
/**
 * Competitor SEO Spy Tool: analyze a competitor URL and compare with current post.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Remote URL fetched via wp_remote_get.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Competitor
 */
class Meyvora_SEO_Competitor {

	const PAGE_SLUG       = 'meyvora-seo-competitor';
	const NONCE_ACTION    = 'meyvora_seo_competitor_analyze';
	const AJAX_ACTION     = 'meyvora_seo_competitor_analyze';
	const REQUEST_TIMEOUT = 10;
	const USER_AGENT     = 'Mozilla/5.0 (compatible; MeyvoraSEO/1.0; +https://meyvora.com)';
	const TABLE_SNAPSHOTS = 'meyvora_seo_competitor_snapshots';

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
		$this->loader->add_action( 'admin_menu', $this, 'register_submenu', 14, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_analyze' ) );
		add_action( 'wp_ajax_meyvora_seo_competitor_keyword_gap', array( $this, 'ajax_keyword_gap' ) );
		add_action( 'wp_ajax_meyvora_seo_competitor_snapshot_list', array( $this, 'ajax_snapshot_list' ) );
		add_action( 'wp_ajax_meyvora_seo_competitor_snapshot_get', array( $this, 'ajax_snapshot_get' ) );
		add_action( 'wp_ajax_meyvora_seo_competitor_snapshot_compare', array( $this, 'ajax_snapshot_compare' ) );
		$this->loader->add_action( 'init', $this, 'schedule_competitor_monitor_cron', 25, 0 );
		add_action( 'meyvora_seo_competitor_monitor', array( $this, 'run_competitor_monitor' ) );
	}

	/**
	 * Register Competitor submenu under Meyvora SEO.
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Competitor', 'meyvora-seo' ),
			__( 'Competitor', 'meyvora-seo' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets on Competitor page and post edit (for meta box tab).
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		$is_competitor_page = $hook_suffix === 'meyvora-seo_page_' . self::PAGE_SLUG;
		$is_post_edit       = ( $hook_suffix === 'post.php' || $hook_suffix === 'post-new.php' );
		if ( ! $is_competitor_page && ! $is_post_edit ) {
			return;
		}
		if ( $is_post_edit && ! $this->options->current_user_can_edit_seo() ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		if ( $is_competitor_page ) {
			wp_enqueue_style(
				'meyvora-competitor-page',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-competitor-page.css',
				array( 'meyvora-admin' ),
				MEYVORA_SEO_VERSION
			);
		}
		wp_enqueue_script(
			'meyvora-competitor',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-competitor.js',
			array( 'jquery' ),
			MEYVORA_SEO_VERSION,
			true
		);
		$post_choices = array();
		if ( $is_competitor_page ) {
			$recent = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'modified', 'order' => 'DESC', 'fields' => 'ids' ) );
			foreach ( (array) $recent as $pid ) {
				$post_choices[] = array( 'id' => (int) $pid, 'title' => get_the_title( $pid ) ?: __( '(no title)', 'meyvora-seo' ) );
			}
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- postId for JS, validated by capability and AJAX nonce.
		wp_localize_script( 'meyvora-competitor', 'meyvoraCompetitor', array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( self::NONCE_ACTION ),
			'aiProxyAction'  => 'meyvora_seo_ai_request',
			'aiNonce'        => wp_create_nonce( 'meyvora_seo_ai' ),
				'postId'         => $is_post_edit && isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Admin context; post ID for JS only, capability checked on use.
			'postChoices'    => $post_choices,
			'keywordGapAction' => 'meyvora_seo_competitor_keyword_gap',
			'editPostBaseUrl'   => $is_competitor_page ? admin_url( 'post.php' ) : '',
			'snapshotListAction'   => 'meyvora_seo_competitor_snapshot_list',
			'snapshotGetAction'    => 'meyvora_seo_competitor_snapshot_get',
			'snapshotCompareAction' => 'meyvora_seo_competitor_snapshot_compare',
			'i18n'         => array(
				'analyze'      => __( 'Analyze', 'meyvora-seo' ),
				'analyzing'    => __( 'Analyzing…', 'meyvora-seo' ),
				'error'        => __( 'Something went wrong.', 'meyvora-seo' ),
				'analyseGap'   => __( 'Analyse Gap', 'meyvora-seo' ),
				'analysingGap' => __( 'Analysing gap…', 'meyvora-seo' ),
				'keyword'      => __( 'Keyword', 'meyvora-seo' ),
				'competitorPos' => __( 'Competitor Position', 'meyvora-seo' ),
				'estVolume'    => __( 'Est. Volume', 'meyvora-seo' ),
				'action'       => __( 'Action', 'meyvora-seo' ),
				'setFocusOn'   => __( 'Set as focus keyword on', 'meyvora-seo' ),
				'history'      => __( 'History', 'meyvora-seo' ),
				'analyzeTab'   => __( 'Analyze', 'meyvora-seo' ),
				'noSnapshots'  => __( 'No snapshots yet. Analyze a competitor URL to create history.', 'meyvora-seo' ),
				'snapshotAt'   => __( 'Snapshot at', 'meyvora-seo' ),
				'compare'      => __( 'Compare', 'meyvora-seo' ),
				'selectTwo'    => __( 'Select two snapshots to compare.', 'meyvora-seo' ),
				'wordCount'    => __( 'Word count', 'meyvora-seo' ),
				'schemaTypes'  => __( 'Schema types', 'meyvora-seo' ),
				'changes'      => __( 'Changes', 'meyvora-seo' ),
				'noChanges'    => __( 'No significant changes.', 'meyvora-seo' ),
			),
		) );
	}

	/**
	 * Render Competitor admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'meyvora-seo' ) );
		}
		$view_file = MEYVORA_SEO_PATH . 'admin/views/competitor.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}

	/**
	 * AJAX: fetch competitor URL, parse HTML, optionally call DataForSEO, return competitor + our post data.
	 */
	public function ajax_analyze(): void {
		if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'meyvora-seo' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ), 403 );
		}
		/*
		 * sanitize_url + wp_unslash: remote request URL for wp_remote_get(); use esc_url() in templates when outputting.
		 */
		$competitor_url = isset( $_POST['url'] ) ? trim( sanitize_url( wp_unslash( $_POST['url'] ) ) ) : '';
		if ( $competitor_url === '' || ! preg_match( '#^https?://#i', $competitor_url ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid competitor URL.', 'meyvora-seo' ) ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;

		$cache_key = 'meyvora_competitor_' . md5( $competitor_url );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			wp_send_json_success( $cached );
		}

		$response = wp_remote_get( $competitor_url, array(
			'timeout'    => self::REQUEST_TIMEOUT,
			'user-agent' => self::USER_AGENT,
			'sslverify'  => true,
			'redirection' => 5,
		) );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %d: HTTP status code */ __( 'URL returned HTTP %d.', 'meyvora-seo' ), $code ) ) );
		}
		$html = wp_remote_retrieve_body( $response );
		if ( $html === '' ) {
			wp_send_json_error( array( 'message' => __( 'Empty response from URL.', 'meyvora-seo' ) ) );
		}

		try {
			$competitor = $this->parse_html( $html, $competitor_url );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => __( 'Could not parse the competitor page.', 'meyvora-seo' ) ) );
		}

		// Optional: DataForSEO On-Page (task_post then pages for onpage_score / page metrics).
		$api_key = $this->options->get( 'dataforseo_api_key', '' );
		if ( is_string( $api_key ) && $api_key !== '' ) {
			$dataforseo = $this->fetch_dataforseo_onpage( $competitor_url, $api_key );
			$competitor['dataforseo'] = $dataforseo;
		} else {
			$competitor['dataforseo'] = null;
		}

		$ours = $this->get_our_post_data( $post_id );

		$our_headings_str  = implode( "\n", array_map( function( $h ) {
			return $h['text'] ?? '';
		}, $ours['headings'] ?? array() ) );
		$comp_headings_arr = $competitor['headings']['first5'] ?? array();
		$comp_headings_str = implode( "\n", array_map( function( $h ) {
			return $h['text'] ?? '';
		}, $comp_headings_arr ) );

		$result = array(
			'competitor'        => $competitor,
			'ours'              => $ours,
			'url'               => $competitor_url,
			'our_headings_str'  => $our_headings_str,
			'comp_headings_str' => $comp_headings_str,
		);

		// Save snapshot to DB.
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SNAPSHOTS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table, insert.
		$wpdb->insert(
			$table,
			array(
				'url'           => $competitor_url,
				'snapshot_data' => wp_json_encode( $result ),
				'created_at'    => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
		);
		// Keep only the last 10 snapshots per URL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table name from constant.
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE url = %s ORDER BY created_at DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from constant.
			$competitor_url
		) );
		if ( is_array( $ids ) && count( $ids ) > 10 ) {
			$old_ids = array_slice( $ids, 10 );
			$placeholders = implode( ',', array_fill( 0, count( $old_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom table, $old_ids are ints.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table from constant; placeholders built from %d.
				...$old_ids
			) );
		}

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
		wp_send_json_success( $result );
	}

	/**
	 * Parse HTML with DOMDocument and extract SEO-relevant data.
	 *
	 * @param string $html Raw HTML.
	 * @param string $base_url Base URL for resolving relative links.
	 * @return array<string, mixed>
	 */
	protected function parse_html( string $html, string $base_url ): array {
		$out = array(
			'title'          => '',
			'meta_description' => '',
			'og'             => array(),
			'schema_types'   => array(),
			'headings'       => array( 'count' => 0, 'first5' => array() ),
			'word_count'     => 0,
			'images_total'   => 0,
			'images_with_alt' => 0,
			'images'         => array(),
		);

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ), LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( false );
		if ( ! $loaded ) {
			return $out;
		}

		// <title>
		$list = $dom->getElementsByTagName( 'title' );
		if ( $list->length > 0 ) {
			$out['title'] = trim( (string) $list->item( 0 )->textContent );
		}

		// <meta name="description">
		$metas = $dom->getElementsByTagName( 'meta' );
		for ( $i = 0; $i < $metas->length; $i++ ) {
			$m = $metas->item( $i );
			if ( ! $m->hasAttribute( 'name' ) ) {
				if ( $m->hasAttribute( 'property' ) ) {
					$prop = strtolower( trim( $m->getAttribute( 'property' ) ) );
					if ( strpos( $prop, 'og:' ) === 0 ) {
						$out['og'][ $prop ] = trim( (string) $m->getAttribute( 'content' ) );
					}
				}
				continue;
			}
			if ( strtolower( $m->getAttribute( 'name' ) ) === 'description' ) {
				$out['meta_description'] = trim( (string) $m->getAttribute( 'content' ) );
			}
		}

		// OG tags (also from property)
		foreach ( $metas as $m ) {
			if ( $m->hasAttribute( 'property' ) ) {
				$prop = strtolower( trim( $m->getAttribute( 'property' ) ) );
				if ( strpos( $prop, 'og:' ) === 0 && ! isset( $out['og'][ $prop ] ) ) {
					$out['og'][ $prop ] = trim( (string) $m->getAttribute( 'content' ) );
				}
			}
		}

		// JSON-LD schema types
		$scripts = $dom->getElementsByTagName( 'script' );
		for ( $i = 0; $i < $scripts->length; $i++ ) {
			$s = $scripts->item( $i );
			if ( $s->getAttribute( 'type' ) !== 'application/ld+json' ) {
				continue;
			}
			$json = json_decode( trim( (string) $s->textContent ), true );
			if ( ! is_array( $json ) ) {
				continue;
			}
			$types = $this->extract_schema_types( $json );
			foreach ( $types as $t ) {
				$out['schema_types'][] = $t;
			}
		}
		$out['schema_types'] = array_unique( $out['schema_types'] );

		// H1–H4 (count + first 5)
		$headings = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4' ) as $tag ) {
			$els = $dom->getElementsByTagName( $tag );
			for ( $j = 0; $j < $els->length; $j++ ) {
				$headings[] = array( 'tag' => $tag, 'text' => trim( (string) $els->item( $j )->textContent ) );
			}
		}
		$out['headings']['count'] = count( $headings );
		$out['headings']['first5'] = array_slice( $headings, 0, 5 );

		// Body text word count
		$body = $dom->getElementsByTagName( 'body' );
		if ( $body->length > 0 ) {
			$plain = trim( (string) $body->item( 0 )->textContent );
			$plain = preg_replace( '/\s+/', ' ', $plain );
			$out['word_count'] = str_word_count( $plain );
		}

		// Images (total, with alt, list)
		$imgs = $dom->getElementsByTagName( 'img' );
		$out['images_total'] = $imgs->length;
		$with_alt = 0;
		$list = array();
		for ( $k = 0; $k < $imgs->length && $k < 20; $k++ ) {
			$img = $imgs->item( $k );
			$src = $img->getAttribute( 'src' );
			if ( $src !== '' && strpos( $src, 'http' ) !== 0 ) {
				$src = rtrim( $base_url, '/' ) . '/' . ltrim( $src, '/' );
			}
			$alt = trim( (string) $img->getAttribute( 'alt' ) );
			if ( $alt !== '' ) {
				$with_alt++;
			}
			$list[] = array( 'src' => $src, 'alt' => $alt );
		}
		$out['images_with_alt'] = $with_alt;
		$out['images'] = $list;

		return $out;
	}

	/**
	 * Recursively extract @type from JSON-LD (can be array or object).
	 *
	 * @param array|string $json Decoded JSON-LD.
	 * @return array<int, string>
	 */
	protected function extract_schema_types( $json ): array {
		$types = array();
		if ( isset( $json['@type'] ) ) {
			$t = $json['@type'];
			if ( is_string( $t ) ) {
				$types[] = $t;
			} elseif ( is_array( $t ) ) {
				$types = array_merge( $types, $t );
			}
		}
		if ( isset( $json['@graph'] ) && is_array( $json['@graph'] ) ) {
			foreach ( $json['@graph'] as $node ) {
				if ( is_array( $node ) ) {
					$types = array_merge( $types, $this->extract_schema_types( $node ) );
				}
			}
		}
		return $types;
	}

	/**
	 * Optional DataForSEO On-Page: task_post then pages for onpage_score. Returns null or array with onpage_score and top_keywords (if available).
	 *
	 * @param string $url Competitor URL.
	 * @param string $api_key DataForSEO API key (login:password).
	 * @return array{ onpage_score: float|null, page_authority: float|null, top_keywords: array }|null
	 */
	protected function fetch_dataforseo_onpage( string $url, string $api_key ): ?array {
		$auth = base64_encode( $api_key );
		$headers = array(
			'Authorization' => 'Basic ' . $auth,
			'Content-Type'  => 'application/json',
		);

		$task_body = wp_json_encode( array(
			array(
				'target'         => $url,
				'max_crawl_pages' => 1,
			),
		) );
		$task_resp = wp_remote_post(
			'https://api.dataforseo.com/v3/on_page/task_post',
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $task_body,
			)
		);
		if ( is_wp_error( $task_resp ) || wp_remote_retrieve_response_code( $task_resp ) !== 200 ) {
			return null;
		}
		$task_json = json_decode( wp_remote_retrieve_body( $task_resp ), true );
		$task_id   = isset( $task_json['tasks'][0]['id'] ) ? $task_json['tasks'][0]['id'] : '';
		if ( $task_id === '' ) {
			return null;
		}

		// Wait 2s then fetch pages (crawl may still be in progress).
		sleep( 2 );
		$pages_body = wp_json_encode( array(
			array( 'id' => $task_id, 'limit' => 1 ),
		) );
		$pages_resp = wp_remote_post(
			'https://api.dataforseo.com/v3/on_page/pages',
			array(
				'timeout' => 15,
				'headers' => $headers,
				'body'    => $pages_body,
			)
		);
		if ( is_wp_error( $pages_resp ) || wp_remote_retrieve_response_code( $pages_resp ) !== 200 ) {
			return array( 'onpage_score' => null, 'page_authority' => null, 'top_keywords' => array() );
		}
		$pages_json = json_decode( wp_remote_retrieve_body( $pages_resp ), true );
		$onpage_score = null;
		$page_authority = null;
		$top_keywords = array();
		if ( isset( $pages_json['tasks'][0]['result'][0]['items'] ) && is_array( $pages_json['tasks'][0]['result'][0]['items'] ) ) {
			$items = $pages_json['tasks'][0]['result'][0]['items'];
			$first = isset( $items[0] ) ? $items[0] : null;
			if ( $first && isset( $first['onpage_score'] ) ) {
				$onpage_score = (float) $first['onpage_score'];
			}
			if ( $first && isset( $first['meta']['plain_text_word_count'] ) ) {
				$page_authority = (float) $first['meta']['plain_text_word_count'];
			}
			// Meta keywords as simple "top keywords" proxy if present
			if ( $first && isset( $first['meta']['meta_keywords'] ) && is_string( $first['meta']['meta_keywords'] ) ) {
				$kw = array_map( 'trim', explode( ',', $first['meta']['meta_keywords'] ) );
				$top_keywords = array_slice( array_filter( $kw ), 0, 10 );
			}
		}
		return array(
			'onpage_score'   => $onpage_score,
			'page_authority' => $page_authority,
			'top_keywords'   => $top_keywords,
		);
	}

	/**
	 * Fetch competitor's organic keywords from DataForSEO Labs ranked_keywords/live.
	 *
	 * @param string $competitor_url Full URL or domain of competitor.
	 * @param string $api_key        DataForSEO API key (login:password).
	 * @return array<string, array{position: int, volume: int}>|null Map keyword => position/volume, or null on failure.
	 */
	protected function fetch_dataforseo_organic_keywords( string $competitor_url, string $api_key ): ?array {
		$parsed = wp_parse_url( $competitor_url );
		$host   = isset( $parsed['host'] ) ? strtolower( $parsed['host'] ) : '';
		if ( $host === '' ) {
			$host = preg_replace( '#^https?://#i', '', $competitor_url );
			$host = strtolower( trim( $host, "/ \t\n\r" ) );
		}
		if ( $host === '' ) {
			return null;
		}
		$target = preg_replace( '#^www\.#i', '', $host );

		$auth   = base64_encode( $api_key );
		$headers = array(
			'Authorization' => 'Basic ' . $auth,
			'Content-Type'  => 'application/json',
		);
		$body = wp_json_encode( array(
			array(
				'target'        => $target,
				'location_code' => 2840,
				'language_code' => 'en',
				'item_types'    => array( 'organic' ),
				'limit'         => 500,
			),
		) );
		$resp = wp_remote_post(
			'https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live',
			array(
				'timeout' => 30,
				'headers' => $headers,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return null;
		}
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) || empty( $json['tasks'][0]['result'][0]['items'] ) ) {
			return null;
		}
		$items = $json['tasks'][0]['result'][0]['items'];
		$out   = array();
		foreach ( $items as $item ) {
			$kw = isset( $item['keyword_data']['keyword'] ) ? trim( (string) $item['keyword_data']['keyword'] ) : '';
			if ( $kw === '' ) {
				continue;
			}
			$volume   = isset( $item['keyword_data']['keyword_info']['search_volume'] ) ? (int) $item['keyword_data']['keyword_info']['search_volume'] : 0;
			$position = isset( $item['ranked_serp_element']['serp_item']['rank_group'] ) ? (int) $item['ranked_serp_element']['serp_item']['rank_group'] : 0;
			$out[ $kw ] = array( 'position' => $position, 'volume' => $volume );
		}
		return $out;
	}

	/**
	 * AJAX: Keyword gap — competitor keywords we do not rank for (GSC + DataForSEO).
	 */
	public function ajax_keyword_gap(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
			return;
		}
		$competitor_url = isset( $_POST['url'] ) ? sanitize_url( wp_unslash( $_POST['url'] ) ) : '';
		if ( $competitor_url === '' ) {
			wp_send_json_error();
			return;
		}
		$api_key = $this->options->get( 'dataforseo_api_key', '' );
		if ( ! is_string( $api_key ) || strpos( $api_key, ':' ) === false ) {
			wp_send_json_error( array( 'message' => __( 'DataForSEO key required for keyword gap.', 'meyvora-seo' ) ) );
			return;
		}
		$gsc_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php';
		if ( file_exists( $gsc_file ) ) {
			require_once $gsc_file;
		}
		$our_keywords = array();
		if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
			$gsc          = new Meyvora_SEO_GSC( $this->loader, $this->options );
			$our_keywords = $gsc->get_all_keywords( 500 );
		}
		$comp_keywords = $this->fetch_dataforseo_organic_keywords( $competitor_url, $api_key );
		if ( $comp_keywords === null ) {
			wp_send_json_error( array( 'message' => __( 'Could not fetch competitor keywords.', 'meyvora-seo' ) ) );
			return;
		}
		$gap = array();
		foreach ( $comp_keywords as $kw => $data ) {
			if ( ! isset( $our_keywords[ strtolower( $kw ) ] ) ) {
				$gap[] = array(
					'keyword'           => $kw,
					'competitor_pos'    => $data['position'] ?? 0,
					'competitor_volume' => $data['volume'] ?? 0,
				);
			}
		}
		usort( $gap, function ( $a, $b ) {
			return ( $a['competitor_pos'] ?? 99 ) <=> ( $b['competitor_pos'] ?? 99 );
		} );
		wp_send_json_success( array( 'gap' => array_slice( $gap, 0, 50 ) ) );
	}

	/**
	 * AJAX: List URLs with their last 5 snapshot ids and created_at.
	 */
	public function ajax_snapshot_list(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SNAPSHOTS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$urls  = $wpdb->get_col( "SELECT DISTINCT url FROM {$table} ORDER BY url ASC" );
		$out   = array();
		foreach ( (array) $urls as $url ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, created_at FROM {$table} WHERE url = %s ORDER BY created_at DESC LIMIT 5", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from constant.
				$url
			), ARRAY_A );
			$out[] = array(
				'url'       => $url,
				'snapshots' => is_array( $rows ) ? $rows : array(),
			);
		}
		wp_send_json_success( array( 'urls' => $out ) );
	}

	/**
	 * AJAX: Get one snapshot by id (returns competitor title, word_count, schema_types).
	 */
	public function ajax_snapshot_get(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
			return;
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( $id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid snapshot ID.', 'meyvora-seo' ) ) );
			return;
		}
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE_SNAPSHOTS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT url, snapshot_data, created_at FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Snapshot not found.', 'meyvora-seo' ) ) );
			return;
		}
		$data = json_decode( $row['snapshot_data'], true );
		$comp = isset( $data['competitor'] ) && is_array( $data['competitor'] ) ? $data['competitor'] : array();
		wp_send_json_success( array(
			'id'          => $id,
			'url'         => $row['url'],
			'created_at'  => $row['created_at'],
			'title'       => $comp['title'] ?? '',
			'word_count'  => (int) ( $comp['word_count'] ?? 0 ),
			'schema_types' => isset( $comp['schema_types'] ) && is_array( $comp['schema_types'] ) ? $comp['schema_types'] : array(),
			'meta_description' => $comp['meta_description'] ?? '',
			'headings'    => $comp['headings'] ?? array(),
		) );
	}

	/**
	 * AJAX: Compare two snapshots; returns diff (title, word_count >10% change, H1 different).
	 */
	public function ajax_snapshot_compare(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error();
			return;
		}
		$id1 = isset( $_POST['id1'] ) ? absint( wp_unslash( $_POST['id1'] ) ) : 0;
		$id2 = isset( $_POST['id2'] ) ? absint( wp_unslash( $_POST['id2'] ) ) : 0;
		if ( $id1 <= 0 || $id2 <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Invalid snapshot IDs.', 'meyvora-seo' ) ) );
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SNAPSHOTS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row1  = $wpdb->get_row( $wpdb->prepare( "SELECT snapshot_data FROM {$table} WHERE id = %d", $id1 ), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$row2  = $wpdb->get_row( $wpdb->prepare( "SELECT snapshot_data FROM {$table} WHERE id = %d", $id2 ), ARRAY_A );
		if ( ! $row1 || ! $row2 ) {
			wp_send_json_error( array( 'message' => __( 'Snapshot not found.', 'meyvora-seo' ) ) );
			return;
		}
		$d1 = json_decode( $row1['snapshot_data'], true );
		$d2 = json_decode( $row2['snapshot_data'], true );
		$c1 = isset( $d1['competitor'] ) && is_array( $d1['competitor'] ) ? $d1['competitor'] : array();
		$c2 = isset( $d2['competitor'] ) && is_array( $d2['competitor'] ) ? $d2['competitor'] : array();
		$h1_1 = isset( $c1['headings']['first5'][0]['text'] ) ? (string) $c1['headings']['first5'][0]['text'] : '';
		$h1_2 = isset( $c2['headings']['first5'][0]['text'] ) ? (string) $c2['headings']['first5'][0]['text'] : '';
		$title1 = (string) ( $c1['title'] ?? '' );
		$title2 = (string) ( $c2['title'] ?? '' );
		$wc1 = (int) ( $c1['word_count'] ?? 0 );
		$wc2 = (int) ( $c2['word_count'] ?? 0 );
		$pct = ( $wc1 > 0 ) ? ( abs( $wc2 - $wc1 ) / $wc1 * 100 ) : ( $wc2 > 0 ? 100 : 0 );
		$changes = array();
		if ( $title1 !== $title2 ) {
			$changes[] = array( 'field' => 'title', 'old' => $title1, 'new' => $title2 );
		}
		if ( $pct > 10 ) {
			$changes[] = array( 'field' => 'word_count', 'old' => $wc1, 'new' => $wc2, 'pct_change' => round( $pct, 1 ) );
		}
		if ( $h1_1 !== $h1_2 ) {
			$changes[] = array( 'field' => 'h1', 'old' => $h1_1, 'new' => $h1_2 );
		}
		wp_send_json_success( array(
			'id1'     => $id1,
			'id2'     => $id2,
			'changes' => $changes,
			'snapshot1' => array( 'title' => $title1, 'word_count' => $wc1, 'h1' => $h1_1 ),
			'snapshot2' => array( 'title' => $title2, 'word_count' => $wc2, 'h1' => $h1_2 ),
		) );
	}

	/**
	 * Schedule weekly competitor monitor cron if not already scheduled.
	 */
	public function schedule_competitor_monitor_cron(): void {
		$hook = 'meyvora_seo_competitor_monitor';
		if ( ! $this->options->is_enabled( 'competitor_monitor_enabled' ) ) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'weekly', $hook );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 60, 'weekly', $hook );
		}
	}

	/**
	 * Fetch URL and parse HTML to competitor data (for cron monitor).
	 *
	 * @param string $url Competitor URL.
	 * @return array<string, mixed>|null Competitor data or null on failure.
	 */
	protected function fetch_and_parse_competitor( string $url ): ?array {
		$response = wp_remote_get( $url, array(
			'timeout'     => self::REQUEST_TIMEOUT,
			'user-agent' => self::USER_AGENT,
			'sslverify'   => true,
			'redirection' => 5,
		) );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}
		$html = wp_remote_retrieve_body( $response );
		if ( $html === '' ) {
			return null;
		}
		try {
			return $this->parse_html( $html, $url );
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * Run weekly competitor monitor: re-fetch each URL with snapshots, compare with last snapshot, fire action if changed.
	 */
	public function run_competitor_monitor(): void {
		if ( ! $this->options->is_enabled( 'competitor_monitor_enabled' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_SNAPSHOTS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$urls = $wpdb->get_col( "SELECT DISTINCT url FROM {$table} ORDER BY url ASC" );
		if ( ! is_array( $urls ) || empty( $urls ) ) {
			return;
		}
		foreach ( $urls as $url ) {
			$url = (string) $url;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$last = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, snapshot_data FROM {$table} WHERE url = %s ORDER BY created_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from constant.
				$url
			), ARRAY_A );
			if ( ! $last || empty( $last['snapshot_data'] ) ) {
				continue;
			}
			$data = json_decode( $last['snapshot_data'], true );
			$old = isset( $data['competitor'] ) && is_array( $data['competitor'] ) ? $data['competitor'] : array();
			$new = $this->fetch_and_parse_competitor( $url );
			if ( $new === null ) {
				continue;
			}
			$old_title = (string) ( $old['title'] ?? '' );
			$new_title = (string) ( $new['title'] ?? '' );
			$old_wc = (int) ( $old['word_count'] ?? 0 );
			$new_wc = (int) ( $new['word_count'] ?? 0 );
			$old_h1 = isset( $old['headings']['first5'][0]['text'] ) ? (string) $old['headings']['first5'][0]['text'] : '';
			$new_h1 = isset( $new['headings']['first5'][0]['text'] ) ? (string) $new['headings']['first5'][0]['text'] : '';
			$changed = ( $old_title !== $new_title )
				|| ( $old_wc !== $new_wc && ( $old_wc === 0 || abs( $new_wc - $old_wc ) / $old_wc > 0.10 ) )
				|| ( $old_h1 !== $new_h1 );
			if ( $changed ) {
				do_action( 'meyvora_seo_competitor_changed', $url, $old, $new );
			}
		}
	}

	/**
	 * Get our post SEO data for comparison (from post meta).
	 *
	 * @param int $post_id Post ID (0 = use first available or empty).
	 * @return array<string, mixed>
	 */
	protected function get_our_post_data( int $post_id ): array {
		$meta_get = function( $pid, $key, $single = true ) {
			return get_post_meta( $pid, apply_filters( 'meyvora_seo_post_meta_key', $key, $pid ), $single );
		};
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return array(
				'title'            => '',
				'meta_description' => '',
				'og'               => array(),
				'schema_type'      => '',
				'headings'         => array(),
				'headings_count'   => 0,
				'word_count'       => 0,
				'images_total'     => 0,
				'images_with_alt'  => 0,
				'post_title'       => '',
				'post_id'          => 0,
				'focus_keyword'    => '',
			);
		}
		$title_meta = $meta_get( $post->ID, MEYVORA_SEO_META_TITLE, true );
		$desc_meta  = $meta_get( $post->ID, MEYVORA_SEO_META_DESCRIPTION, true );
		$og_title   = $meta_get( $post->ID, MEYVORA_SEO_META_OG_TITLE, true );
		$og_desc    = $meta_get( $post->ID, MEYVORA_SEO_META_OG_DESCRIPTION, true );
		$og_image   = $meta_get( $post->ID, MEYVORA_SEO_META_OG_IMAGE, true );
		$schema     = $meta_get( $post->ID, MEYVORA_SEO_META_SCHEMA_TYPE, true );
		$content    = (string) $post->post_content;
		$plain      = wp_strip_all_tags( $content );
		$word_count = str_word_count( $plain );
		$headings   = array();
		if ( preg_match_all( '/<h([1-4])[^>]*>(.*?)<\/h\1>/is', $content, $h_m, PREG_SET_ORDER ) ) {
			foreach ( $h_m as $m ) {
				$headings[] = array( 'tag' => 'h' . $m[1], 'text' => trim( wp_strip_all_tags( $m[2] ) ) );
			}
		}
		$headings_count = count( $headings );
		preg_match_all( '/<img[^>]+>/i', $content, $img_m );
		$img_total = isset( $img_m[0] ) ? count( $img_m[0] ) : 0;
		$img_alt = 0;
		if ( ! empty( $img_m[0] ) ) {
			foreach ( $img_m[0] as $tag ) {
				if ( preg_match( '/\balt\s*=\s*["\']([^"\']*)["\']/i', $tag, $alt ) && trim( $alt[1] ) !== '' ) {
					$img_alt++;
				}
			}
		}
		$og = array();
		if ( is_string( $og_title ) && $og_title !== '' ) {
			$og['og:title'] = $og_title;
		}
		if ( is_string( $og_desc ) && $og_desc !== '' ) {
			$og['og:description'] = $og_desc;
		}
		if ( is_string( $og_image ) && $og_image !== '' ) {
			$og['og:image'] = $og_image;
		}
		$focus_raw = $meta_get( $post->ID, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
		$focus_keyword = '';
		if ( is_string( $focus_raw ) && $focus_raw !== '' ) {
			$decoded = json_decode( $focus_raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$focus_keyword = is_string( $decoded[0] ) ? trim( $decoded[0] ) : '';
			} else {
				$focus_keyword = trim( $focus_raw );
			}
		}
		return array(
			'title'            => is_string( $title_meta ) ? $title_meta : '',
			'meta_description' => is_string( $desc_meta ) ? $desc_meta : '',
			'og'               => $og,
			'schema_type'      => is_string( $schema ) ? $schema : '',
			'headings'         => $headings,
			'headings_count'   => $headings_count,
			'word_count'       => $word_count,
			'images_total'     => $img_total,
			'images_with_alt'  => $img_alt,
			'post_title'       => $post->post_title,
			'post_id'          => $post->ID,
			'focus_keyword'    => $focus_keyword,
		);
	}
}
