<?php
/**
 * Post/page list table: SEO score, focus keyword, SEO title, readability columns; bulk analyze; score filter.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- List/filter GET params; AJAX uses check_ajax_referer.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Post_List {

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	public function __construct( Meyvora_SEO_Loader $loader ) {
		$this->loader = $loader;
	}

	/**
	 * Register hooks for post and page list columns.
	 */
	public function register_hooks(): void {
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		foreach ( $post_types as $post_type ) {
			$this->loader->add_filter( "manage_{$post_type}_posts_columns", $this, 'add_columns', 10, 1 );
			$this->loader->add_action( "manage_{$post_type}_posts_custom_column", $this, 'render_column', 10, 2 );
			$this->loader->add_filter( "manage_edit-{$post_type}_sortable_columns", $this, 'sortable_columns', 10, 1 );
			$this->loader->add_filter( "bulk_actions-edit-{$post_type}", $this, 'bulk_actions', 10, 1 );
			$this->loader->add_filter( "handle_bulk_actions-edit-{$post_type}", $this, 'handle_bulk_analyze', 10, 3 );
		}
		$this->loader->add_action( 'pre_get_posts', $this, 'sort_by_seo_score', 10, 1 );
		$this->loader->add_action( 'pre_get_posts', $this, 'filter_by_score', 11, 1 );
		$this->loader->add_action( 'restrict_manage_posts', $this, 'score_filter_dropdown', 10, 2 );
		$this->loader->add_action( 'admin_notices', $this, 'bulk_analyze_notice', 10, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_cwv_script', 10, 1 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function add_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'title' ) {
				$new['meyvora_seo']       = __( 'SEO Score', 'meyvora-seo' );
				$new['meyvora_focus_kw'] = __( 'Focus Keyword', 'meyvora-seo' );
				$new['meyvora_seo_title'] = __( 'SEO Title', 'meyvora-seo' );
				$new['meyvora_readability'] = __( 'Readability', 'meyvora-seo' );
				$new['meyvora_seo_intent'] = __( 'Intent', 'meyvora-seo' );
				$new['meyvora_seo_cwv']   = __( 'CWV', 'meyvora-seo' );
			}
		}
		if ( ! isset( $new['meyvora_seo'] ) ) {
			$new['meyvora_seo']       = __( 'SEO Score', 'meyvora-seo' );
			$new['meyvora_focus_kw']  = __( 'Focus Keyword', 'meyvora-seo' );
			$new['meyvora_seo_title'] = __( 'SEO Title', 'meyvora-seo' );
			$new['meyvora_readability'] = __( 'Readability', 'meyvora-seo' );
			$new['meyvora_seo_intent'] = __( 'Intent', 'meyvora-seo' );
			$new['meyvora_seo_cwv']   = __( 'CWV', 'meyvora-seo' );
		}
		return $new;
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public function render_column( string $column, int $post_id ): void {
		if ( $column === 'meyvora_seo' ) {
			$this->render_score_column( $post_id );
			return;
		}
		if ( $column === 'meyvora_focus_kw' ) {
			$raw = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
			$kw  = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::get_focus_keywords_display( $raw ) : ( is_string( $raw ) ? trim( $raw ) : '' );
			if ( $kw !== '' ) {
				echo '<span title="' . esc_attr( $kw ) . '">' . esc_html( wp_trim_words( $kw, 4 ) ) . '</span>';
			} else {
				echo '<span style="color:#8c8f94; font-style:italic;">—</span>';
			}
			return;
		}
		if ( $column === 'meyvora_seo_title' ) {
			$title = get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true );
			$title = is_string( $title ) ? trim( $title ) : '';
			if ( $title === '' ) {
				$post = get_post( $post_id );
				$title = $post ? $post->post_title : '';
			}
			$short = wp_trim_words( $title, 6 );
			if ( strlen( $title ) > 40 ) {
				echo '<span title="' . esc_attr( $title ) . '">' . esc_html( substr( $short, 0, 40 ) ) . '…</span>';
			} else {
				echo '<span title="' . esc_attr( $title ) . '">' . esc_html( $short ) . '</span>';
			}
			return;
		}
		if ( $column === 'meyvora_readability' ) {
			$read = get_post_meta( $post_id, MEYVORA_SEO_META_READABILITY, true );
			$read = is_numeric( $read ) ? (int) $read : null;
			if ( $read !== null ) {
				$label = $read >= 60 ? __( 'Good', 'meyvora-seo' ) : ( $read >= 40 ? __( 'Fair', 'meyvora-seo' ) : __( 'Poor', 'meyvora-seo' ) );
				$class = $read >= 60 ? 'good' : ( $read >= 40 ? 'fair' : 'poor' );
				echo '<span class="meyvora-readability-badge meyvora-readability-' . esc_attr( $class ) . '" title="' . esc_attr( (string) $read ) . '">' . esc_html( $label ) . '</span>';
			} else {
				echo '<span style="color:#8c8f94;">—</span>';
			}
			return;
		}
		if ( $column === 'meyvora_seo_intent' ) {
			$intent = get_post_meta( $post_id, MEYVORA_SEO_META_SEARCH_INTENT, true );
			if ( $intent === '' || ! $intent ) {
				echo '<span style="color:#9ca3af;font-size:11px;">—</span>';
			} else {
				$colors = array(
					'informational' => '#2563eb',
					'navigational'  => '#7c3aed',
					'commercial'    => '#d97706',
					'transactional' => '#059669',
				);
				$col   = $colors[ $intent ] ?? '#6b7280';
				$label = ucfirst( $intent );
				echo '<span style="background:' . esc_attr( $col ) . ';color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">' . esc_html( $label ) . '</span>';
			}
			return;
		}
		if ( $column === 'meyvora_seo_cwv' ) {
			$this->render_cwv_column( $post_id );
			return;
		}
	}

	/**
	 * Render CWV column: green ✓ (pass), red ✗ (fail), or "—" with "Test now" link.
	 *
	 * @param int $post_id Post ID.
	 */
	/**
	 * Enqueue script for CWV "Test now" AJAX on post/page list.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_cwv_script( string $hook_suffix ): void {
		if ( $hook_suffix !== 'edit.php' ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type ?? '', array( 'post', 'page' ), true ) ) {
			return;
		}
		wp_register_script( 'meyvora-cwv-list', false, array( 'jquery' ), MEYVORA_SEO_VERSION, true );
		wp_enqueue_script( 'meyvora-cwv-list' );
		wp_localize_script( 'meyvora-cwv-list', 'meyvoraCwv', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'meyvora_seo_cwv_test' ),
			'action'  => 'meyvora_seo_cwv_test',
			'i18n'    => array(
				'pass'   => __( 'Pass', 'meyvora-seo' ),
				'fail'   => __( 'Fail', 'meyvora-seo' ),
				'error'  => __( 'Request failed.', 'meyvora-seo' ),
				'passTitle' => __( 'Core Web Vitals pass', 'meyvora-seo' ),
				'failTitle' => __( 'Core Web Vitals fail', 'meyvora-seo' ),
			),
		) );
		$inline = "jQuery(function($){
			$(document).on('click', '.meyvora-cwv-test-now', function(e){
				e.preventDefault();
				var btn = $(this);
				var cell = btn.closest('td');
				var postId = btn.data('post-id');
				if (!postId) return;
				btn.text('…');
				$.post(meyvoraCwv.ajaxUrl, {
					action: meyvoraCwv.action,
					nonce: meyvoraCwv.nonce,
					post_id: postId,
					strategy: 'mobile'
				}).done(function(res){
					if (res.success && res.data && typeof res.data.passed !== 'undefined') {
						var passed = res.data.passed;
						var html = passed ? '<span style=\"color:#00a32a;font-weight:bold;\" title=\"' + meyvoraCwv.i18n.passTitle + '\" aria-label=\"' + meyvoraCwv.i18n.pass + '\">✓</span>'
							: '<span style=\"color:#d63638;font-weight:bold;\" title=\"' + meyvoraCwv.i18n.failTitle + '\" aria-label=\"' + meyvoraCwv.i18n.fail + '\">✗</span>';
						cell.html(html);
					} else {
						btn.text(meyvoraCwv.i18n.error);
					}
				}).fail(function(){
					btn.text(meyvoraCwv.i18n.error);
				});
			});
		});";
		wp_add_inline_script( 'meyvora-cwv-list', $inline );
	}

	private function render_cwv_column( int $post_id ): void {
		$cwv = null;
		if ( class_exists( 'Meyvora_SEO_CWV' ) ) {
			$cwv = ( new Meyvora_SEO_CWV( meyvora_seo()->get_loader(), meyvora_seo()->get_options() ) )->get_cached( $post_id );
		}
		if ( $cwv !== null && isset( $cwv['passed'] ) ) {
			if ( $cwv['passed'] ) {
				echo '<span style="color:#00a32a;font-weight:bold;" title="' . esc_attr__( 'Core Web Vitals pass', 'meyvora-seo' ) . '" aria-label="' . esc_attr__( 'Pass', 'meyvora-seo' ) . '">✓</span>';
			} else {
				echo '<span style="color:#d63638;font-weight:bold;" title="' . esc_attr__( 'Core Web Vitals fail', 'meyvora-seo' ) . '" aria-label="' . esc_attr__( 'Fail', 'meyvora-seo' ) . '">✗</span>';
			}
			return;
		}
		echo '<span class="meyvora-cwv-empty" aria-hidden="true">—</span> ';
		echo '<a href="#" class="meyvora-cwv-test-now" data-post-id="' . esc_attr( (string) $post_id ) . '" style="font-size:11px;">' . esc_html__( 'Test now', 'meyvora-seo' ) . '</a>';
	}

	private function render_score_column( int $post_id ): void {
		$score       = get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true );
		$score       = is_numeric( $score ) ? (int) $score : null;
		$status      = $score !== null ? ( $score >= 80 ? 'good' : ( $score >= 50 ? 'okay' : 'poor' ) ) : null;
		$kw_raw      = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
		$kw          = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::get_focus_keywords_display( $kw_raw ) : ( is_string( $kw_raw ) ? trim( $kw_raw ) : '' );
		$cornerstone = (bool) get_post_meta( $post_id, MEYVORA_SEO_META_CORNERSTONE, true );

		if ( $score !== null ) {
			$color = $status === 'good' ? '#00a32a' : ( $status === 'okay' ? '#dba617' : '#d63638' );
			$label = $status === 'good' ? __( 'Good', 'meyvora-seo' ) : ( $status === 'okay' ? __( 'Okay', 'meyvora-seo' ) : __( 'Poor', 'meyvora-seo' ) );
			$edit_url = get_edit_post_link( $post_id, 'raw' );
			echo '<a href="' . esc_url( $edit_url ?: '#' ) . '" title="' . esc_attr( $label ) . '" style="font-weight:600;">' . (int) $score . '</a>';
			echo ' <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:' . esc_attr( $color ) . ';" aria-hidden="true"></span>';
			if ( $cornerstone ) {
				echo ' <span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;color:#dba617;" title="' . esc_attr__( 'Cornerstone content', 'meyvora-seo' ) . '" aria-label="' . esc_attr__( 'Cornerstone content', 'meyvora-seo' ) . '"></span>';
			}
		} else {
			echo '<span aria-hidden="true">—</span>';
			if ( $cornerstone ) {
				echo ' <span class="dashicons dashicons-star-filled" style="font-size:14px;width:14px;height:14px;color:#dba617;" title="' . esc_attr__( 'Cornerstone content', 'meyvora-seo' ) . '" aria-label="' . esc_attr__( 'Cornerstone content', 'meyvora-seo' ) . '"></span>';
			}
		}
		if ( $kw !== '' ) {
			echo '<br><span style="font-size:11px; color:#50575e;">' . esc_html( wp_trim_words( $kw, 3 ) ) . '</span>';
		}
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function sortable_columns( array $columns ): array {
		$columns['meyvora_seo'] = __( 'SEO Score', 'meyvora-seo' );
		return $columns;
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function bulk_actions( array $actions ): array {
		$actions['meyvora_analyze'] = __( 'Analyze selected posts', 'meyvora-seo' );
		return $actions;
	}

	/**
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Bulk action.
	 * @param array<int> $post_ids Post IDs.
	 * @return string
	 */
	public function handle_bulk_analyze( string $redirect_url, string $action, array $post_ids ): string {
		if ( $action !== 'meyvora_analyze' || empty( $post_ids ) || ! class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			return $redirect_url;
		}
		$analyzer = new Meyvora_SEO_Analyzer();
		$done = 0;
		foreach ( $post_ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 || ! get_post( $pid ) ) {
				continue;
			}
			$content = apply_filters( 'meyvora_seo_analysis_content', (string) get_post( $pid )->post_content, $pid );
			$result = $analyzer->analyze( $pid, $content !== '' ? $content : null );
			update_post_meta( $pid, MEYVORA_SEO_META_SCORE, $result['score'] );
			update_post_meta( $pid, MEYVORA_SEO_META_ANALYSIS, wp_json_encode( array( 'score' => $result['score'], 'status' => $result['status'], 'results' => $result['results'] ) ) );
			$done++;
		}
		return add_query_arg( array( 'meyvora_analyzed' => $done, 'meyvora_bulk' => 1 ), $redirect_url );
	}

	public function bulk_analyze_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}
		if ( ! isset( $_GET['meyvora_bulk'], $_GET['meyvora_analyzed'] ) ) {
			return;
		}
		$count = (int) $_GET['meyvora_analyzed'];
		/* translators: %d: number of posts analyzed */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d post analyzed.', '%d posts analyzed.', $count, 'meyvora-seo' ), $count ) ) . '</p></div>';
	}

	/**
	 * Add score filter dropdown above the list.
	 *
	 * @param string $post_type Post type.
	 * @param string $which    'top' or 'bottom'.
	 */
	public function score_filter_dropdown( string $post_type, string $which ): void {
		if ( $which !== 'top' ) {
			return;
		}
		$supported = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post_type, $supported, true ) ) {
			return;
		}
		$current = isset( $_GET['meyvora_seo_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['meyvora_seo_filter'] ) ) : '';
		?>
		<select name="meyvora_seo_filter">
			<option value=""><?php esc_html_e( 'All SEO Scores', 'meyvora-seo' ); ?></option>
			<option value="good" <?php selected( $current, 'good' ); ?>><?php esc_html_e( 'Good (80+)', 'meyvora-seo' ); ?></option>
			<option value="okay" <?php selected( $current, 'okay' ); ?>><?php esc_html_e( 'Okay (50–79)', 'meyvora-seo' ); ?></option>
			<option value="poor" <?php selected( $current, 'poor' ); ?>><?php esc_html_e( 'Poor (&lt;50)', 'meyvora-seo' ); ?></option>
			<option value="no_keyword" <?php selected( $current, 'no_keyword' ); ?>><?php esc_html_e( 'No keyword set', 'meyvora-seo' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Apply score filter to the query. Hook pre_get_posts with priority after sort.
	 */
	public function filter_by_score( WP_Query $query ): void {
		if ( ! is_admin() || ! isset( $_GET['meyvora_seo_filter'] ) || $_GET['meyvora_seo_filter'] === '' ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}
		$filter = sanitize_text_field( wp_unslash( $_GET['meyvora_seo_filter'] ) );
		$mq = array();
		if ( $filter === 'good' ) {
			$mq = array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => 80, 'compare' => '>=', 'type' => 'NUMERIC' );
		} elseif ( $filter === 'okay' ) {
			$mq = array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => array( 50, 79 ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' );
		} elseif ( $filter === 'poor' ) {
			$mq = array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => 50, 'compare' => '<', 'type' => 'NUMERIC' );
		} elseif ( $filter === 'no_keyword' ) {
			$mq = array(
				'relation' => 'OR',
				array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'value' => '', 'compare' => '=' ),
			);
		}
		if ( ! empty( $mq ) ) {
			$query->set( 'meta_query', array_merge( (array) $query->get( 'meta_query' ), array( $mq ) ) );
		}
	}

	/**
	 * Order by SEO score when requested.
	 *
	 * @param WP_Query $query Query object.
	 */
	public function sort_by_seo_score( WP_Query $query ): void {
		$orderby = $query->get( 'orderby' );
		$valid   = array( 'meyvora_seo_score', __( 'SEO Score', 'meyvora-seo' ) );
		if ( ! is_admin() || ! in_array( $orderby, $valid, true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'edit' ) {
			return;
		}
		$supported = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $screen->post_type ?? '', $supported, true ) ) {
			return;
		}
		$query->set( 'meta_key', MEYVORA_SEO_META_SCORE );
		$query->set( 'orderby', 'meta_value_num' );
		$order = $query->get( 'order' );
		if ( $order !== 'ASC' && $order !== 'DESC' ) {
			$query->set( 'order', 'DESC' );
		}
	}
}