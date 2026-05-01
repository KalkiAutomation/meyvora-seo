<?php
/**
 * Background broken-links scanner: WP-Cron every 15 minutes, 10 URLs per run.
 * Stores results in meyvora_seo_link_checks. Admin page: Meyvora SEO → Link Checker.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Link checks table; table name from constant.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Link_Checker
 */
class Meyvora_SEO_Link_Checker {

	const CRON_HOOK       = 'meyvora_seo_link_checker_cron';
	const CRON_SCHEDULE   = 'meyvora_seo_fifteen_min';
	const URLS_PER_RUN    = 10;
	const REQUEST_TIMEOUT = 5;
	const NONCE_ACTION    = 'meyvora_seo_link_checker_fix';
	const PAGE_SLUG       = 'meyvora-seo-link-checker';
	const AJAX_FIX        = 'meyvora_seo_link_checker_fix';

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
		$this->loader->add_filter( 'cron_schedules', $this, 'add_cron_schedule', 10, 1 );
		$this->loader->add_action( 'init', $this, 'schedule_cron', 20 );
		add_action( self::CRON_HOOK, array( $this, 'run_cron' ) );
		$this->loader->add_action( 'admin_menu', $this, 'register_submenu', 14, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_' . self::AJAX_FIX, array( $this, 'ajax_fix_link' ) );
	}

	/**
	 * Add 15-minute cron schedule.
	 *
	 * @param array<string, array{ interval: int, display: string }> $schedules Existing schedules.
	 * @return array<string, array{ interval: int, display: string }>
	 */
	public function add_cron_schedule( array $schedules ): array {
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 Minutes', 'meyvora-seo' ),
		);
		return $schedules;
	}

	/**
	 * Schedule cron if not already scheduled.
	 */
	public function schedule_cron(): void {
		if ( ! $this->options->is_enabled( 'link_checker_background_enabled' ) ) {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, self::CRON_SCHEDULE, self::CRON_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Cron callback: process up to URLS_PER_RUN external URLs.
	 */
	public function run_cron(): void {
		if ( ! $this->options->is_enabled( 'link_checker_background_enabled' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_LINK_CHECKS;

		// 1) Get posts published in last 90 days that have not had links checked in last 7 days.
		$eligible_post_ids = $this->get_eligible_post_ids();
		if ( empty( $eligible_post_ids ) ) {
			return;
		}

		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$to_check  = array(); // list of [ 'url' => string, 'rows' => array of ids ].

		foreach ( $eligible_post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || empty( $post->post_content ) ) {
				continue;
			}

			$links = $this->parse_links_from_content( $post->post_content );
			foreach ( $links as $link ) {
				$url = $link['url'];
				$anchor = $link['anchor'];
				$url_host = wp_parse_url( $url, PHP_URL_HOST );
				if ( ! $url_host || $url_host === $home_host ) {
					continue;
				}
				$url = $this->normalize_url( $url );
				if ( $url === '' ) {
					continue;
				}

				// Ensure row exists (insert if not).
				$row_id = $this->ensure_link_check_row( $url, (int) $post_id, $anchor );
				if ( ! $row_id ) {
					continue;
				}

				// Skip if this URL was already checked today.
				$already = $wpdb->get_var( $wpdb->prepare(
					"SELECT 1 FROM {$table} WHERE url = %s AND last_checked >= CURDATE() LIMIT 1",
					$url
				) );
				if ( $already ) {
					continue;
				}

				if ( ! isset( $to_check[ $url ] ) ) {
					$to_check[ $url ] = array( 'row_ids' => array() );
				}
				$to_check[ $url ]['row_ids'][] = $row_id;
				if ( count( $to_check ) >= self::URLS_PER_RUN ) {
					break 2;
				}
			}
			if ( count( $to_check ) >= self::URLS_PER_RUN ) {
				break;
			}
		}

		$checked = 0;
		foreach ( $to_check as $url => $data ) {
			if ( $checked >= self::URLS_PER_RUN ) {
				break;
			}
			$status = $this->check_url( $url );
			$code   = $status['code'];
			$broken = $status['broken'];
			$now    = current_time( 'mysql' );

			foreach ( $data['row_ids'] as $rid ) {
				$wpdb->update(
					$table,
					array(
						'http_status'  => $code,
						'last_checked' => $now,
						'is_broken'    => $broken ? 1 : 0,
					),
					array( 'id' => $rid ),
					array( '%d', '%s', '%d' ),
					array( '%d' )
				);
			}
			++$checked;
		}
	}

	/**
	 * Get post IDs: published in last 90 days, not in link_checks with last_checked in last 7 days.
	 *
	 * @return array<int>
	 */
	protected function get_eligible_post_ids(): array {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_LINK_CHECKS;

		$recently_checked = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$table} WHERE last_checked >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
		$exclude = array_filter( array_map( 'absint', (array) $recently_checked ) );
		$posts = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'date_query'     => array(
				array(
					'after' => '90 days ago',
					'inclusive' => true,
				),
			),
			'posts_per_page' => 50,
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Bounded exclude list for link check scope.
			'exclude'        => $exclude ?: array( 0 ),
		) );
		return is_array( $posts ) ? array_map( 'intval', $posts ) : array();
	}

	/**
	 * Parse all <a href=""> from content using DOMDocument.
	 *
	 * @param string $content Post content.
	 * @return array<int, array{ url: string, anchor: string }>
	 */
	protected function parse_links_from_content( string $content ): array {
		$links = array();
		if ( trim( $content ) === '' ) {
			return $links;
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = @$dom->loadHTML(
			'<?xml encoding="utf-8"?><html><body>' . $content . '</body></html>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		if ( ! $loaded ) {
			return $links;
		}

		$anchors = $dom->getElementsByTagName( 'a' );
		foreach ( $anchors as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( $href === '' ) {
				continue;
			}
			$anchor = trim( $a->textContent );
			$anchor = mb_substr( $anchor, 0, 500 );
			$links[] = array( 'url' => $href, 'anchor' => $anchor );
		}
		return $links;
	}

	/**
	 * Normalize URL for storage and deduplication.
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	protected function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * Ensure a row exists; insert if not. Returns row id or 0.
	 *
	 * @param string $url       URL.
	 * @param int    $post_id   Post ID.
	 * @param string $anchor_text Anchor text.
	 * @return int Row id.
	 */
	protected function ensure_link_check_row( string $url, int $post_id, string $anchor_text ): int {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_LINK_CHECKS;

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE url = %s AND post_id = %d AND anchor_text = %s LIMIT 1",
			$url,
			$post_id,
			$anchor_text
		) );
		if ( $existing ) {
			return (int) $existing;
		}

		$wpdb->insert(
			$table,
			array(
				'url'         => $url,
				'post_id'     => $post_id,
				'anchor_text' => $anchor_text,
				'http_status' => 0,
				'last_checked' => null,
				'is_broken'   => 0,
			),
			array( '%s', '%d', '%s', '%d', '%s', '%d' )
		);
		return $wpdb->insert_id ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * HEAD request to URL; return status code and broken flag.
	 *
	 * @param string $url URL to check.
	 * @return array{ code: int, broken: bool }
	 */
	protected function check_url( string $url ): array {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'redirection' => 5,
				'sslverify'  => true,
				'user-agent' => 'Meyvora-SEO-LinkChecker/1.0',
			)
		);

		$code = 0;
		if ( ! is_wp_error( $response ) && isset( $response['response']['code'] ) ) {
			$code = (int) $response['response']['code'];
		}
		$broken = ( $code >= 400 || $code === 0 );
		return array( 'code' => $code, 'broken' => $broken );
	}

	/**
	 * Register Link Checker submenu under Meyvora SEO.
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Link Checker', 'meyvora-seo' ),
			__( 'Link Checker', 'meyvora-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue assets on Link Checker page.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_' . self::PAGE_SLUG ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		wp_enqueue_style(
			'meyvora-link-checker-modal',
			MEYVORA_SEO_URL . 'admin/assets/css/meyvora-link-checker-modal.css',
			array( 'meyvora-admin' ),
			MEYVORA_SEO_VERSION
		);
		wp_enqueue_script(
			'meyvora-link-checker',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-link-checker.js',
			array( 'jquery', 'meyvora-toast' ),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script( 'meyvora-link-checker', 'meyvoraLinkChecker', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'i18n'    => array(
				'fix'       => __( 'Fix', 'meyvora-seo' ),
				'replaceUrl' => __( 'Replacement URL', 'meyvora-seo' ),
				'save'      => __( 'Update link', 'meyvora-seo' ),
				'cancel'    => __( 'Cancel', 'meyvora-seo' ),
				'success'   => __( 'Link updated.', 'meyvora-seo' ),
				'error'     => __( 'Something went wrong.', 'meyvora-seo' ),
			),
		) );
	}

	/**
	 * Render Link Checker admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'meyvora-seo' ) );
		}
		$broken_links = $this->get_broken_links();
		$view_file = MEYVORA_SEO_PATH . 'admin/views/link-checker.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}

	/**
	 * Get broken links for admin table.
	 *
	 * @return array<int, object>
	 */
	public function get_broken_links(): array {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_LINK_CHECKS;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from constant.
		$rows = $wpdb->get_results( "SELECT id, url, post_id, anchor_text, http_status, last_checked FROM {$table} WHERE is_broken = 1 ORDER BY last_checked DESC" );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * AJAX: replace broken link in post_content and update/clear row.
	 */
	public function ajax_fix_link(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ), 403 );
		}
		if ( empty( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'meyvora-seo' ) ), 403 );
		}
		$check_id = isset( $_POST['check_id'] ) ? absint( wp_unslash( $_POST['check_id'] ) ) : 0;
		/*
		 * sanitize_url + wp_unslash: replacement URL before DB / post_content; not echoed raw as HTML here.
		 */
		$new_url = isset( $_POST['new_url'] ) ? trim( sanitize_url( wp_unslash( $_POST['new_url'] ) ) ) : '';
		if ( ! $check_id || ! $new_url ) {
			wp_send_json_error( array( 'message' => __( 'Missing check ID or replacement URL.', 'meyvora-seo' ) ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_LINK_CHECKS;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, url, post_id, anchor_text FROM {$table} WHERE id = %d LIMIT 1",
			$check_id
		), ARRAY_A );
		if ( ! $row ) {
			wp_send_json_error( array( 'message' => __( 'Link check not found.', 'meyvora-seo' ) ) );
		}

		$post_id = (int) $row['post_id'];
		$old_url = $row['url'];
		$post = get_post( $post_id );
		if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Cannot edit this post.', 'meyvora-seo' ) ) );
		}

		$content   = $post->post_content;
		$old_url_escaped = preg_quote( $old_url, '#' );
		// esc_url_raw() intentional: href value persisted in post_content; sanitize for storage (esc_url() is for HTML output).
		$new_url_escaped = esc_url_raw( $new_url );
		$new_content = preg_replace(
			'#href=["\']' . $old_url_escaped . '["\']#i',
			'href="' . $new_url_escaped . '"',
			$content
		);
		if ( $new_content === null ) {
			// preg_replace failed, fall back to str_replace — esc_url_raw() for stored URL fragment, same as above.
			$new_content = str_replace( $old_url, esc_url_raw( $new_url ), $content );
		}

		if ( $new_content !== $content ) {
			wp_update_post( array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			) );
		}

		// Update row: new URL, mark not broken (or delete row so next scan re-adds new URL).
		$wpdb->update(
			$table,
			array(
				'url'         => $new_url,
				'http_status' => 0,
				'is_broken'   => 0,
				'last_checked' => current_time( 'mysql' ),
			),
			array( 'id' => $check_id ),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array( 'message' => __( 'Link updated.', 'meyvora-seo' ) ) );
	}
}
