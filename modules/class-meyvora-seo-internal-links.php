<?php
/**
 * Internal linking: link suggestions in editor, orphan page detection (Link Analysis).
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.Security.NonceVerification.Recommended, WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Custom queries; $placeholders literal; AJAX nonce in handler.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Internal_Links
 */
class Meyvora_SEO_Internal_Links {

	const TRANSIENT_PREFIX   = 'meyvora_link_suggestions_';
	const TRANSIENT_EXPIRY   = 3600; // 1 hour
	const SUGGESTIONS_MAX    = 5;
	const LINK_ANALYSIS_SLUG = 'meyvora-seo-link-analysis';
	const PER_PAGE           = 50;
	const AJAX_ACTION        = 'meyvora_seo_link_suggestions';
	const NONCE_ACTION       = 'meyvora_link_suggestions';

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
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_link_suggestions' ) );
		$this->loader->add_action( 'admin_menu', $this, 'register_link_analysis_menu', 12, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_editor_assets', 10, 1 );
		add_action( 'meyvora_seo_meta_box_general_tab', array( $this, 'render_suggestions_panel' ) );
		$this->loader->add_action( 'save_post', $this, 'clear_suggestions_cache', 10, 1 );
	}

	/**
	 * Enqueue script for link suggestions panel (post edit).
	 */
	public function enqueue_editor_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type ?? '', array( 'post', 'page' ), true ) ) {
			return;
		}
		$script = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-internal-links.js';
		if ( ! file_exists( $script ) ) {
			return;
		}
		wp_enqueue_script(
			'meyvora-internal-links',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-internal-links.js',
			array( 'jquery', 'wp-tinymce' ),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script( 'meyvora-internal-links', 'meyvoraInternalLinks', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'postId'  => get_the_ID() ? (int) get_the_ID() : 0,
			'i18n'    => array(
				'title'      => __( 'Suggested internal links', 'meyvora-seo' ),
				'loading'    => __( 'Loading…', 'meyvora-seo' ),
				'none'       => __( 'No suggestions found.', 'meyvora-seo' ),
				'insertLink' => __( 'Insert link', 'meyvora-seo' ),
				'inserted'   => __( '✓ Inserted', 'meyvora-seo' ),
				'copied'     => __( 'Link copied to clipboard', 'meyvora-seo' ),
			),
		) );
	}

	/**
	 * Render the suggestions panel placeholder (hook: meyvora_seo_meta_box_general_tab).
	 */
	public function render_suggestions_panel(): void {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}
		?>
		<div class="meyvora-field meyvora-link-suggestions-wrap" id="meyvora-link-suggestions-panel" data-post-id="<?php echo (int) $post_id; ?>">
			<label><?php esc_html_e( 'Suggested internal links', 'meyvora-seo' ); ?></label>
			<div id="meyvora-link-suggestions-list" class="meyvora-link-suggestions-list">
				<span class="meyvora-link-suggestions-loading" id="meyvora-link-suggestions-loading"><?php esc_html_e( 'Loading…', 'meyvora-seo' ); ?></span>
				<ul id="meyvora-link-suggestions-items" class="meyvora-link-suggestions-items" aria-live="polite"></ul>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: Return link suggestions for the given post (cached 1 hour).
	 */
	public function ajax_link_suggestions(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post' ) );
		}
		$transient_key = self::TRANSIENT_PREFIX . $post_id;
		$cached = get_transient( $transient_key );
		if ( $cached !== false && is_array( $cached ) ) {
			wp_send_json_success( array( 'suggestions' => $cached ) );
		}
		$suggestions = $this->get_suggestions( $post_id );
		set_transient( $transient_key, $suggestions, self::TRANSIENT_EXPIRY );
		wp_send_json_success( array( 'suggestions' => $suggestions ) );
	}

	/**
	 * Get up to 5 suggested posts: match by focus keyword in title/content, same category.
	 *
	 * @param int $post_id Current post ID.
	 * @return array<int, array{ id: int, title: string, url: string }>
	 */
	protected function get_suggestions( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$focus_raw = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
		$keywords = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::normalize_focus_keywords( $focus_raw ) : array();
		if ( ! is_array( $keywords ) ) {
			$keywords = is_string( $focus_raw ) && $focus_raw !== '' ? array( trim( $focus_raw ) ) : array();
		}
		$post_cats = array();
		if ( $post->post_type === 'post' ) {
			$post_cats = wp_get_post_categories( $post_id );
		}
		$candidates = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'post__not_in'   => array( $post_id ),
			'posts_per_page' => 100,
			'fields'         => 'ids',
		) );
		$scored = array();
		foreach ( $candidates as $id ) {
			$other = get_post( $id );
			if ( ! $other ) {
				continue;
			}
			$score = 0;
			$title_lower = mb_strtolower( $other->post_title );
			$content = mb_strtolower( $other->post_content );
			foreach ( $keywords as $kw ) {
				$kw = trim( $kw );
				if ( $kw === '' ) {
					continue;
				}
				$kw_lower = mb_strtolower( $kw );
				if ( $kw_lower !== '' && ( strpos( $title_lower, $kw_lower ) !== false ) ) {
					$score += 2;
				}
				if ( $kw_lower !== '' && strpos( $content, $kw_lower ) !== false ) {
					$score += 1;
				}
			}
			if ( $other->post_type === 'post' && ! empty( $post_cats ) ) {
				$other_cats = wp_get_post_categories( $id );
				if ( ! empty( array_intersect( $post_cats, $other_cats ) ) ) {
					$score += 1;
				}
			}
			$scored[ $id ] = $score;
		}
		arsort( $scored, SORT_NUMERIC );
		$top = array_slice( array_keys( $scored ), 0, self::SUGGESTIONS_MAX );
		$out = array();
		foreach ( $top as $id ) {
			$p = get_post( $id );
			if ( $p ) {
				$out[] = array(
					'id'        => (int) $p->ID,
					'title'     => $p->post_title,
					'url'       => get_permalink( $p ),
					'relevance' => isset( $scored[ $id ] ) ? (int) $scored[ $id ] : 0,
				);
			}
		}
		return $out;
	}

	/**
	 * Register Link Analysis submenu.
	 */
	public function register_link_analysis_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Link Analysis', 'meyvora-seo' ),
			__( 'Link Analysis', 'meyvora-seo' ),
			'edit_posts',
			self::LINK_ANALYSIS_SLUG,
			array( $this, 'render_link_analysis_page' )
		);
	}

	/**
	 * Render Link Analysis page (table + pagination + export).
	 */
	public function render_link_analysis_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' ) {
			$this->export_link_analysis_csv();
			return;
		}
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$data = $this->get_link_analysis_data( $page, self::PER_PAGE );
		$view_file = MEYVORA_SEO_PATH . 'admin/views/internal-links.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Link Analysis', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'View not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}

	/**
	 * Get paginated link analysis rows: post, links in, links out, status.
	 *
	 * @param int $page Page number (1-based).
	 * @param int $per_page Per page.
	 * @return array{ rows: array, total: int, total_pages: int }
	 */
	public function get_link_analysis_data( int $page = 1, int $per_page = self::PER_PAGE ): array {
		global $wpdb;
		$post_types = array( 'post', 'page' );
		$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)",
			...$post_types
		) );
		$offset = ( $page - 1 ) * $per_page;
		$posts = $wpdb->get_results( $wpdb->prepare(
			"SELECT ID, post_title, post_type, post_content FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders) ORDER BY post_date DESC LIMIT %d OFFSET %d",
			...array_merge( $post_types, array( $per_page, $offset ) )
		), ARRAY_A );
		$home = home_url( '/' );
		$rows = array();
		foreach ( $posts as $p ) {
			$permalink = get_permalink( (int) $p['ID'] );
			$links_in = $this->count_inbound_links( (int) $p['ID'], $permalink );
			$links_out = $this->count_outbound_internal_links( $p['post_content'], $p['ID'] );
			$status = $links_in === 0 ? 'orphan' : ( $links_in >= 2 ? 'good' : 'low' );
			$rows[] = array(
				'id'         => (int) $p['ID'],
				'title'      => $p['post_title'],
				'type'       => $p['post_type'],
				'permalink'  => $permalink,
				'links_in'   => $links_in,
				'links_out'  => $links_out,
				'status'     => $status,
			);
		}
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		return array( 'rows' => $rows, 'total' => $total, 'total_pages' => $total_pages );
	}

	/**
	 * Count how many other published posts contain a link to the given URL.
	 */
	protected function count_inbound_links( int $exclude_post_id, string $url ): int {
		global $wpdb;
		if ( $url === '' ) {
			return 0;
		}
		$escaped = $wpdb->esc_like( $url );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT ID) FROM {$wpdb->posts} WHERE post_status = 'publish' AND ID != %d AND post_content LIKE %s",
			$exclude_post_id,
			'%' . $escaped . '%'
		) );
		return $count;
	}

	/**
	 * Count internal links in post content (links to same site, excluding self).
	 */
	protected function count_outbound_internal_links( string $content, int $self_id ): int {
		$home = home_url( '/' );
		$host = wp_parse_url( $home, PHP_URL_HOST );
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
	 * Export link analysis as CSV.
	 */
	protected function export_link_analysis_csv(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Forbidden', 'meyvora-seo' ) );
		}
		$all = $this->get_link_analysis_data( 1, 99999 );
		$filename = 'meyvora-link-analysis-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Post', 'Type', 'Internal Links In', 'Internal Links Out', 'Status' ) );
		foreach ( $all['rows'] as $row ) {
			$status_label = $row['status'] === 'orphan' ? __( 'Orphan', 'meyvora-seo' ) : ( $row['status'] === 'good' ? __( 'Good', 'meyvora-seo' ) : __( 'Low', 'meyvora-seo' ) );
			fputcsv( $out, array( $row['title'], $row['type'], $row['links_in'], $row['links_out'], $status_label ) );
		}
		// php://output stream for CSV download; WP_Filesystem does not apply.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	/**
	 * Delete suggestion transient when post is updated (optional).
	 */
	public function clear_suggestions_cache( int $post_id ): void {
		delete_transient( self::TRANSIENT_PREFIX . $post_id );
	}
}
