<?php
/**
 * Keyword Cannibalization Detector: find posts competing for the same focus keyword.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.Security.NonceVerification.Missing -- Meta query for focus keyword; nonce verified in AJAX.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Cannibalization
 */
class Meyvora_SEO_Cannibalization {

	const OPTION_RESULTS = 'meyvora_seo_cannibalization_results';
	const PER_PAGE       = 50;
	const NONCE_ACTION   = 'meyvora_seo_cannibalization';

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
		$this->loader->add_action( 'admin_menu', $this, 'register_submenu', 13, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_meyvora_seo_cannibalization_scan', array( $this, 'ajax_run_scan' ) );
		add_action( 'wp_ajax_meyvora_seo_cannibalization_set_primary', array( $this, 'ajax_set_primary' ) );
	}

	/**
	 * Register Cannibalization submenu under Meyvora SEO.
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Cannibalization', 'meyvora-seo' ),
			__( 'Cannibalization', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-cannibalization',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue script and style on cannibalization page.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_meyvora-seo-cannibalization' ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		wp_enqueue_script(
			'meyvora-cannibalization',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-cannibalization.js',
			array( 'jquery' ),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script( 'meyvora-cannibalization', 'meyvoraCannibalization', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
			'i18n'    => array(
				'scanning'     => __( 'Scanning…', 'meyvora-seo' ),
				'runScan'      => __( 'Run Scan', 'meyvora-seo' ),
				'setPrimary'   => __( 'Set as Primary', 'meyvora-seo' ),
				'setPrimaryDone' => __( 'Updated.', 'meyvora-seo' ),
				'primary'      => __( 'Primary', 'meyvora-seo' ),
				'error'        => __( 'Something went wrong.', 'meyvora-seo' ),
			),
		) );
	}

	/**
	 * Render the cannibalization page (includes view).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'meyvora-seo' ) );
		}
		$stored = get_option( self::OPTION_RESULTS, array() );
		$groups = isset( $stored['groups'] ) && is_array( $stored['groups'] ) ? $stored['groups'] : array();
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total  = count( $groups );
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$page_groups = array_slice( $groups, $offset, self::PER_PAGE, true );
		$total_pages = $total > 0 ? (int) ceil( $total / self::PER_PAGE ) : 1;
		$never_scanned = ! is_array( $stored ) || empty( $stored['last_scan'] );

		$view_file = MEYVORA_SEO_PATH . 'admin/views/cannibalization.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}

	/**
	 * Run scan: query all published posts with focus keyword, group by normalized keyword, store results.
	 *
	 * @return array{ groups: array, total_keywords: int }
	 */
	public function get_grouped_conflicts(): array {
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		if ( post_type_exists( 'product' ) ) {
			$post_types[] = 'product';
		}
		$post_types = array_unique( $post_types );

		$posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => MEYVORA_SEO_META_FOCUS_KEYWORD,
					'value'   => '',
					'compare' => '!=',
				),
			),
		) );

		$by_keyword = array();
		foreach ( $posts as $post_id ) {
			$raw = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
			$keywords = $this->normalize_keywords_for_grouping( $raw );
			foreach ( $keywords as $kw ) {
				if ( $kw === '' ) {
					continue;
				}
				if ( ! isset( $by_keyword[ $kw ] ) ) {
					$by_keyword[ $kw ] = array();
				}
				$by_keyword[ $kw ][] = $post_id;
			}
		}

		$groups = array();
		foreach ( $by_keyword as $keyword => $post_ids ) {
			$post_ids = array_unique( $post_ids );
			if ( count( $post_ids ) >= 2 ) {
				$groups[ $keyword ] = $this->enrich_posts_for_keyword( $keyword, $post_ids );
			}
		}
		ksort( $groups, SORT_NATURAL );

		return array(
			'groups'          => $groups,
			'total_keywords'  => count( $groups ),
		);
	}

	/**
	 * Normalize focus keyword meta to array of lowercase trimmed strings for grouping.
	 *
	 * @param mixed $raw Meta value.
	 * @return array<int, string>
	 */
	private function normalize_keywords_for_grouping( $raw ): array {
		$arr = array();
		if ( is_array( $raw ) ) {
			$arr = $raw;
		} elseif ( is_string( $raw ) && $raw !== '' ) {
			$trimmed = trim( $raw );
			if ( ( $trimmed[0] ?? '' ) === '[' ) {
				$decoded = json_decode( $raw, true );
				$arr = is_array( $decoded ) ? $decoded : array( $trimmed );
			} else {
				$arr = array( $trimmed );
			}
		}
		$out = array();
		foreach ( $arr as $v ) {
			$s = is_string( $v ) ? trim( $v ) : '';
			if ( $s !== '' ) {
				$out[] = mb_strtolower( $s );
			}
		}
		return array_unique( $out );
	}

	/**
	 * Enrich post IDs with title, URL, score, edit link, primary flag.
	 *
	 * @param string   $keyword Normalized keyword.
	 * @param array    $post_ids Post IDs.
	 * @return array<int, array{ id: int, title: string, url: string, score: int|null, edit_link: string, is_primary: bool }>
	 */
	private function enrich_posts_for_keyword( string $keyword, array $post_ids ): array {
		$result = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$score = get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true );
			$result[] = array(
				'id'         => (int) $post_id,
				'title'      => $post->post_title ?: __( '(no title)', 'meyvora-seo' ),
				'url'        => get_permalink( $post ),
				'score'      => $score !== '' && $score !== false ? (int) $score : null,
				'edit_link'  => get_edit_post_link( $post_id, 'raw' ),
				'is_primary' => (bool) get_post_meta( $post->ID, MEYVORA_SEO_META_KEYWORD_PRIMARY, true ),
			);
		}
		return $result;
	}

	/**
	 * AJAX: Run scan and save results.
	 */
	public function ajax_run_scan(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'meyvora-seo' ) ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meyvora-seo' ) ) );
		}
		$data = $this->get_grouped_conflicts();
		update_option( self::OPTION_RESULTS, array(
			'groups'         => $data['groups'],
			'total_keywords' => $data['total_keywords'],
			'last_scan'      => time(),
		), false );
		wp_send_json_success( array(
			'total_keywords' => $data['total_keywords'],
			'redirect'       => admin_url( 'admin.php?page=meyvora-seo-cannibalization' ),
		) );
	}

	/**
	 * AJAX: Set one post as primary for its focus keyword (set _meyvora_seo_keyword_primary=1, clear others in same group).
	 */
	public function ajax_set_primary(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'meyvora-seo' ) ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meyvora-seo' ) ) );
		}
		$post_id  = absint( $_POST['post_id'] ?? 0 );
		$keyword  = sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) );
		if ( ! $post_id || $keyword === '' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'meyvora-seo' ) ) );
		}
		$stored = get_option( self::OPTION_RESULTS, array() );
		$groups = isset( $stored['groups'] ) && is_array( $stored['groups'] ) ? $stored['groups'] : array();
		$keyword_lower = mb_strtolower( trim( $keyword ) );
		if ( ! isset( $groups[ $keyword_lower ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Keyword group not found.', 'meyvora-seo' ) ) );
		}
		$post_ids_in_group = array();
		foreach ( $groups[ $keyword_lower ] as $row ) {
			$post_ids_in_group[] = (int) $row['id'];
		}
		if ( ! in_array( $post_id, $post_ids_in_group, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Post not in this keyword group.', 'meyvora-seo' ) ) );
		}

		$competitors = get_posts( array(
			'post_type'   => 'any',
			'post_status' => 'publish',
			'numberposts' => -1,
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Single post ID exclude for same-keyword check.
			'exclude'     => array( $post_id ),
			'meta_query'  => array(
				array(
					'key'   => MEYVORA_SEO_META_FOCUS_KEYWORD,
					'value' => $keyword,
					'compare' => '=',
				),
				array(
					'key'     => MEYVORA_SEO_META_KEYWORD_PRIMARY,
					'value'   => '1',
					'compare' => '=',
				),
			),
		) );
		foreach ( $competitors as $post ) {
			delete_post_meta( $post->ID, MEYVORA_SEO_META_KEYWORD_PRIMARY );
		}
		update_post_meta( $post_id, MEYVORA_SEO_META_KEYWORD_PRIMARY, 1 );
		// Update the cached scan results to mark the chosen post as primary
		if ( is_array( $stored ) && isset( $stored['groups'] ) && isset( $stored['groups'][ $keyword_lower ] ) ) {
			foreach ( $stored['groups'][ $keyword_lower ] as $i => $item ) {
				if ( isset( $item['id'] ) ) {
					$stored['groups'][ $keyword_lower ][ $i ]['is_primary'] = ( (int) $item['id'] === $post_id );
				}
			}
			update_option( self::OPTION_RESULTS, $stored, false );
		}
		wp_send_json_success( array( 'message' => __( 'Updated.', 'meyvora-seo' ) ) );
	}

	/**
	 * Get stored results (for view).
	 *
	 * @return array{ groups: array, total_keywords: int }
	 */
	public static function get_stored_results(): array {
		$stored = get_option( self::OPTION_RESULTS, array() );
		return array(
			'groups'         => isset( $stored['groups'] ) && is_array( $stored['groups'] ) ? $stored['groups'] : array(),
			'total_keywords' => isset( $stored['total_keywords'] ) ? (int) $stored['total_keywords'] : 0,
		);
	}
}
