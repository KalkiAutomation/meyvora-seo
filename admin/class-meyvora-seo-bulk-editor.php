<?php
/**
 * Bulk SEO editor: inline spreadsheet-style table, filters, bulk save, export CSV.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.SlowDBQuery.slow_db_query_tax_query, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.Security.NonceVerification.Recommended -- GET filters; bulk meta fetch; AJAX nonce in handlers; rows/ids validated.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Bulk_Editor
 */
class Meyvora_SEO_Bulk_Editor {

	const PAGE_SLUG     = 'meyvora-seo-bulk-editor';
	const PER_PAGE      = 50;
	const AJAX_LIST     = 'meyvora_seo_bulk_editor_list';
	const AJAX_SAVE     = 'meyvora_seo_bulk_editor_save';
	const AJAX_EXPORT   = 'meyvora_seo_bulk_editor_export';
	const NONCE_ACTION  = 'meyvora_seo_bulk_editor';

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	public function __construct( Meyvora_SEO_Loader $loader ) {
		$this->loader = $loader;
	}

	public function register_hooks(): void {
		$this->loader->add_action( 'admin_menu', $this, 'register_menu', 12, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_' . self::AJAX_SAVE, array( $this, 'ajax_bulk_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_EXPORT, array( $this, 'ajax_export_csv' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Bulk Editor', 'meyvora-seo' ),
			__( 'Bulk Editor', 'meyvora-seo' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Build query args from request (post type, category, score, missing title/description).
	 *
	 * @return array
	 */
	public function get_query_args(): array {
		$post_type = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
		$category  = isset( $_GET['cat'] ) ? absint( $_GET['cat'] ) : 0;
		$score     = isset( $_GET['score'] ) ? sanitize_text_field( wp_unslash( $_GET['score'] ) ) : '';
		$missing   = isset( $_GET['missing'] ) && is_array( $_GET['missing'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['missing'] ) ) : array();
		$paged     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		if ( $post_type && in_array( $post_type, $post_types, true ) ) {
			$post_types = array( $post_type );
		}

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'   => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);

		$meta_query = array();
		if ( $score === 'good' ) {
			$meta_query[] = array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => 80, 'compare' => '>=', 'type' => 'NUMERIC' );
		} elseif ( $score === 'okay' ) {
			$meta_query[] = array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => array( 50, 79 ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' );
		} elseif ( $score === 'poor' ) {
			$meta_query[] = array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => 50, 'compare' => '<', 'type' => 'NUMERIC' );
		}
		if ( in_array( 'title', $missing, true ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => MEYVORA_SEO_META_TITLE, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MEYVORA_SEO_META_TITLE, 'value' => '', 'compare' => '=' ),
			);
		}
		if ( in_array( 'description', $missing, true ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => MEYVORA_SEO_META_DESCRIPTION, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MEYVORA_SEO_META_DESCRIPTION, 'value' => '', 'compare' => '=' ),
			);
		}
		if ( in_array( 'focus_keyword', $missing, true ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'value' => '', 'compare' => '=' ),
				array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'value' => '[]', 'compare' => '=' ),
			);
		}
		if ( in_array( 'og_image', $missing, true ) ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array( 'key' => MEYVORA_SEO_META_OG_IMAGE, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MEYVORA_SEO_META_OG_IMAGE, 'value' => '', 'compare' => '=' ),
				array( 'key' => MEYVORA_SEO_META_OG_IMAGE, 'value' => '0', 'compare' => '=' ),
			);
		}
		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		if ( $category > 0 && in_array( 'post', $post_types, true ) ) {
			$query_args['tax_query'] = array(
				array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $category ),
			);
		}

		return $query_args;
	}

	/**
	 * Fetch posts and meta for current page.
	 *
	 * @return array{ posts: WP_Post[], total: int, pages: int, meta_map: array }
	 */
	public function get_posts_and_meta(): array {
		$query_args = $this->get_query_args();
		$q          = new WP_Query( $query_args );
		$posts      = $q->posts;
		$total      = $q->found_posts;
		$pages      = (int) ceil( $total / self::PER_PAGE );

		$post_ids = wp_list_pluck( $posts, 'ID' );
		$meta_map = array();
		if ( ! empty( $post_ids ) ) {
			global $wpdb;
			$id_str = implode( ',', array_map( 'intval', $post_ids ) );
			$keys   = array( MEYVORA_SEO_META_TITLE, MEYVORA_SEO_META_DESCRIPTION, MEYVORA_SEO_META_FOCUS_KEYWORD, MEYVORA_SEO_META_SCORE );
			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$rows   = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_str}) AND meta_key IN ({$placeholders})",
				...$keys
			) );
			foreach ( $rows as $row ) {
				$meta_map[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}
		}

		// Build rows with permalink for GSC enrichment
		$rows = array();
		foreach ( $posts as $post ) {
			$rows[ $post->ID ] = array(
				'permalink'      => get_permalink( $post->ID ),
				'gsc_clicks'     => 0,
				'gsc_impressions' => 0,
			);
		}
		$gsc = null;
		if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
			$gsc_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php';
			if ( file_exists( $gsc_file ) ) {
				require_once $gsc_file;
			}
			if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
				$gsc_obj = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
				if ( $gsc_obj->is_connected() ) {
					$gsc = $gsc_obj;
				}
			}
		}
		foreach ( $rows as $post_id => &$row ) {
			if ( $gsc !== null && ! empty( $row['permalink'] ) ) {
				$metrics = $gsc->get_metrics_for_page( $row['permalink'] );
				$row['gsc_clicks']      = (int) ( $metrics['clicks'] ?? 0 );
				$row['gsc_impressions'] = (int) ( $metrics['impressions'] ?? 0 );
			}
		}
		unset( $row );

		return array(
			'posts'          => $posts,
			'total'          => $total,
			'pages'          => $pages,
			'meta_map'       => $meta_map,
			'rows'           => $rows,
			'gsc_connected'  => $gsc !== null,
		);
	}

	/**
	 * Get current filter values for the form.
	 */
	public function get_filter_values(): array {
		return array(
			'post_type' => isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '',
			'cat'       => isset( $_GET['cat'] ) ? absint( $_GET['cat'] ) : 0,
			'score'     => isset( $_GET['score'] ) ? sanitize_text_field( wp_unslash( $_GET['score'] ) ) : '',
			'missing'   => isset( $_GET['missing'] ) && is_array( $_GET['missing'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_GET['missing'] ) ) : array(),
			'paged'     => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
	}

	/**
	 * Title templates for Apply Template dropdown. Keys are template strings (option value); values are labels.
	 *
	 * @return array<string, string>
	 */
	public static function get_title_templates(): array {
		return array(
			''                        => __( '— Select template —', 'meyvora-seo' ),
			'{post_title} | {site_name}' => __( '{post_title} | {site_name}', 'meyvora-seo' ),
			'{site_name} | {post_title}' => __( '{site_name} | {post_title}', 'meyvora-seo' ),
			'{post_title}'             => __( '{post_title}', 'meyvora-seo' ),
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$filters = $this->get_filter_values();
		$data    = $this->get_posts_and_meta();
		$view    = MEYVORA_SEO_PATH . 'admin/views/bulk-editor.php';
		if ( file_exists( $view ) ) {
			include $view;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Bulk Editor', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'View not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_' . self::PAGE_SLUG ) {
			return;
		}
		$ai_script = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-ai.js';
		if ( file_exists( $ai_script ) ) {
			wp_enqueue_script(
				'meyvora-seo-ai',
				MEYVORA_SEO_URL . 'admin/assets/js/meyvora-ai.js',
				array( 'jquery', 'meyvora-toast' ),
				defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0',
				true
			);
			wp_localize_script( 'meyvora-seo-ai', 'meyvoraSeoAi', array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'meyvora_seo_ai' ),
			) );
		}
		$script = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-bulk-editor.js';
		if ( ! file_exists( $script ) ) {
			return;
		}
		wp_enqueue_script(
			'meyvora-bulk-editor',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-bulk-editor.js',
			array( 'jquery', 'meyvora-seo-ai', 'meyvora-toast' ),
			defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0',
			true
		);
		wp_localize_script( 'meyvora-bulk-editor', 'meyvoraBulkEditor', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
			'saveAction'   => self::AJAX_SAVE,
			'exportAction' => self::AJAX_EXPORT,
			'titleMax'     => 60,
			'descMax'      => 160,
			'siteName'     => get_bloginfo( 'name' ),
			'settingsUrl'  => admin_url( 'admin.php?page=meyvora-seo-settings#tab-ai' ),
			'i18n'         => array(
				'saveAll'       => __( 'Save All Changes', 'meyvora-seo' ),
				'saving'        => __( 'Saving…', 'meyvora-seo' ),
				'saved'         => __( 'Saved', 'meyvora-seo' ),
				'error'         => __( 'Error saving. Please try again.', 'meyvora-seo' ),
				'export'        => __( 'Export selected as CSV', 'meyvora-seo' ),
				'applyTemplate' => __( 'Apply template to selected', 'meyvora-seo' ),
				'selectAll'     => __( 'Select all on page', 'meyvora-seo' ),
				'settings'      => __( 'Settings', 'meyvora-seo' ),
			),
		) );
	}

	/**
	 * AJAX: bulk save SEO title and meta description.
	 */
	public function ajax_bulk_save(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$rows_raw = isset( $_POST['rows'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['rows'] ) ) : '';
		$rows_raw = is_string( $rows_raw ) ? $rows_raw : '';
		$payload  = json_decode( $rows_raw, true );
		if ( ! is_array( $payload ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data', 'meyvora-seo' ) ) );
		}
		$payload = map_deep( $payload, 'sanitize_text_field' );
		$updated = 0;
		foreach ( $payload as $row ) {
			$post_id = isset( $row['post_id'] ) ? absint( $row['post_id'] ) : 0;
			if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$data = is_array( $row ) ? $row : array();
			$title = isset( $data['title'] ) ? sanitize_text_field( wp_unslash( $data['title'] ) ) : '';
			$desc  = isset( $data['description'] ) ? sanitize_textarea_field( wp_unslash( $data['description'] ) ) : '';
			update_post_meta( $post_id, MEYVORA_SEO_META_TITLE, $title );
			update_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, $desc );
			if ( isset( $data['focus_keyword'] ) ) {
				$kw_raw   = sanitize_text_field( wp_unslash( $data['focus_keyword'] ) );
				// Store as a plain JSON string array ["keyword1","keyword2"] to match
				// the format used everywhere else in the plugin (meta box, block editor).
				// The old format {keyword:"value"} objects caused analysis mismatches.
				$kw_parts = array_values( array_filter( array_map( 'trim', explode( ',', $kw_raw ) ) ) );
				$kw_json  = wp_json_encode( $kw_parts );
				update_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, $kw_json );
			}
			if ( isset( $data['secondary_keywords'] ) && is_array( $data['secondary_keywords'] ) ) {
				$sec = array_filter(
					array_map( 'sanitize_text_field', array_map( 'wp_unslash', $data['secondary_keywords'] ) )
				);
				update_post_meta( $post_id, MEYVORA_SEO_META_SECONDARY_KEYWORDS, wp_json_encode( array_values( $sec ) ) );
			}
			$updated++;
		}
		wp_send_json_success( array( 'updated' => $updated ) );
	}

	/**
	 * AJAX: export selected rows as CSV.
	 */
	public function ajax_export_csv(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$ids_raw = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ids'] ) ) : '';
		$ids_raw = is_string( $ids_raw ) ? $ids_raw : '';
		$ids_dec = json_decode( $ids_raw, true );
		$ids     = is_array( $ids_dec ) ? array_map( 'absint', $ids_dec ) : array();
		$ids = array_filter( $ids );
		if ( empty( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No rows selected', 'meyvora-seo' ) ) );
		}
		$rows = array();
		$site = get_bloginfo( 'name' );
		foreach ( $ids as $post_id ) {
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$title_meta = get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true );
			$desc_meta = get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true );
			$score     = get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true );
			$score     = is_numeric( $score ) ? (int) $score : '';
			$status    = get_post_status( $post_id );
			$rows[]    = array(
				$post_id,
				$post->post_title,
				$title_meta,
				$desc_meta,
				$score,
				$status,
			);
		}
		$csv = $this->build_csv( $rows );
		wp_send_json_success( array( 'csv' => $csv ) );
	}

	/**
	 * Build CSV string from rows (no BOM; escape quotes).
	 *
	 * @param array<int, array> $rows
	 * @return string
	 */
	protected function build_csv( array $rows ): string {
		$headers = array( 'ID', 'Post Title', 'SEO Title', 'Meta Description', 'Score', 'Status' );
		$out    = array();
		$out[]  = $this->csv_row( $headers );
		foreach ( $rows as $row ) {
			$out[] = $this->csv_row( $row );
		}
		return implode( "\n", $out );
	}

	protected function csv_row( array $cells ): string {
		$escaped = array();
		foreach ( $cells as $cell ) {
			$escaped[] = '"' . str_replace( array( '"', "\r", "\n" ), array( '""', ' ', ' ' ), (string) $cell ) . '"';
		}
		return implode( ',', $escaped );
	}
}