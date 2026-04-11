<?php
/**
 * Admin bootstrap: menu, settings page registration.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.Security.NonceVerification.Recommended -- List/filter GET params; bulk meta fetch uses direct query; $id_str/$placeholders built safely.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Admin {

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	/**
	 * @var Meyvora_SEO_Settings|null
	 */
	protected ?Meyvora_SEO_Settings $settings = null;

	/**
	 * @var Meyvora_SEO_Technical|null
	 */
	protected ?Meyvora_SEO_Technical $technical = null;

	/**
	 * @var Meyvora_SEO_Wizard|null
	 */
	protected ?Meyvora_SEO_Wizard $wizard = null;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Register admin hooks.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'admin_menu', $this, 'register_admin_menu', 10, 0 );
		$this->loader->add_action( 'admin_menu', $this, 'register_audit_submenu', 11, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_meyvora_toast', 5, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_settings_assets', 10, 1 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_dashboard_assets', 10, 1 );
		$this->loader->add_action( 'admin_init', $this, 'handle_settings_actions', 5, 0 );
		add_action( 'wp_ajax_meyvora_seo_import_batch', array( $this, 'ajax_import_batch' ) );
		add_action( 'wp_ajax_meyvora_seo_chain_scan', array( $this, 'ajax_chain_scan' ) );
		add_action( 'wp_ajax_meyvora_seo_chain_flatten_all', array( $this, 'ajax_chain_flatten_all' ) );
		add_action( 'wp_ajax_meyvora_seo_chain_flatten_one', array( $this, 'ajax_chain_flatten_one' ) );

		$settings_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-settings.php';
		if ( file_exists( $settings_file ) ) {
			require_once $settings_file;
			if ( class_exists( 'Meyvora_SEO_Settings' ) ) {
				$this->settings = new Meyvora_SEO_Settings( $this->loader, $this->options );
				$this->settings->register_hooks();
			}
		}

		$social_preview_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-social-preview.php';
		if ( file_exists( $social_preview_file ) ) {
			require_once $social_preview_file;
		}
		$meta_box_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-meta-box.php';
		if ( file_exists( $meta_box_file ) ) {
			require_once $meta_box_file;
			if ( class_exists( 'Meyvora_SEO_Meta_Box' ) ) {
				$meta_box = new Meyvora_SEO_Meta_Box( $this->loader, $this->options );
				$meta_box->register_hooks();
			}
		}

		$post_list_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-post-list.php';
		if ( file_exists( $post_list_file ) ) {
			require_once $post_list_file;
			if ( class_exists( 'Meyvora_SEO_Post_List' ) ) {
				$post_list = new Meyvora_SEO_Post_List( $this->loader );
				$post_list->register_hooks();
			}
		}

		$bulk_editor_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-bulk-editor.php';
		if ( file_exists( $bulk_editor_file ) ) {
			require_once $bulk_editor_file;
			if ( class_exists( 'Meyvora_SEO_Bulk_Editor' ) ) {
				$bulk_editor = new Meyvora_SEO_Bulk_Editor( $this->loader );
				$bulk_editor->register_hooks();
			}
		}

		$technical_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-technical.php';
		if ( file_exists( $technical_file ) ) {
			require_once $technical_file;
			if ( class_exists( 'Meyvora_SEO_Technical' ) ) {
				$this->technical = new Meyvora_SEO_Technical( $this->loader, $this->options );
				$this->technical->register_hooks();
			}
		}

		$reports_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-reports.php';
		if ( file_exists( $reports_file ) ) {
			require_once $reports_file;
			if ( class_exists( 'Meyvora_SEO_Reports' ) ) {
				$reports = new Meyvora_SEO_Reports( $this->loader, $this->options );
				$reports->register_hooks();
			}
		}

		$automation_admin_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-automation-admin.php';
		if ( file_exists( $automation_admin_file ) ) {
			require_once $automation_admin_file;
			if ( class_exists( 'Meyvora_SEO_Automation_Admin' ) ) {
				$automation_admin = new Meyvora_SEO_Automation_Admin( $this->loader, $this->options );
				$automation_admin->register_hooks();
			}
		}

		$wizard_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-wizard.php';
		if ( file_exists( $wizard_file ) ) {
			require_once $wizard_file;
			if ( class_exists( 'Meyvora_SEO_Wizard' ) ) {
				$this->wizard = new Meyvora_SEO_Wizard( $this->loader, $this->options );
				$this->wizard->register_hooks();
			}
		}

		$keyword_research_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-keyword-research.php';
		if ( file_exists( $keyword_research_file ) ) {
			require_once $keyword_research_file;
			if ( class_exists( 'Meyvora_SEO_Keyword_Research' ) ) {
				$keyword_research = new Meyvora_SEO_Keyword_Research( $this->options );
				$keyword_research->register_hooks();
			}
		}

		$cannibalization_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-cannibalization.php';
		if ( file_exists( $cannibalization_file ) ) {
			require_once $cannibalization_file;
			if ( class_exists( 'Meyvora_SEO_Cannibalization' ) ) {
				$cannibalization = new Meyvora_SEO_Cannibalization( $this->loader, $this->options );
				$cannibalization->register_hooks();
			}
		}
		$competitor_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-competitor.php';
		if ( file_exists( $competitor_file ) ) {
			require_once $competitor_file;
			if ( class_exists( 'Meyvora_SEO_Competitor' ) ) {
				$competitor = new Meyvora_SEO_Competitor( $this->loader, $this->options );
				$competitor->register_hooks();
			}
		}
		$programmatic_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-programmatic.php';
		if ( file_exists( $programmatic_file ) ) {
			require_once $programmatic_file;
			if ( class_exists( 'Meyvora_SEO_Programmatic' ) ) {
				$programmatic = new Meyvora_SEO_Programmatic( $this->loader, $this->options );
				$programmatic->register_hooks();
			}
		}
	}

	/**
	 * Register top-level admin menu and settings page.
	 */
	public function register_admin_menu(): void {
		$menu_label = $this->options->get( 'white_label_enabled', false )
			? ( $this->options->get( 'white_label_menu_name', '' ) ?: 'SEO' )
			: __( 'Meyvora SEO', 'meyvora-seo' );
		add_menu_page(
			$menu_label,
			$menu_label,
			'edit_posts',
			'meyvora-seo',
			array( $this, 'render_dashboard' ),
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="black" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>' ),
			30
		);
		add_submenu_page(
			'meyvora-seo',
			__( 'Dashboard', 'meyvora-seo' ),
			__( 'Dashboard', 'meyvora-seo' ),
			'edit_posts',
			'meyvora-seo',
			array( $this, 'render_dashboard' )
		);
		add_submenu_page(
			'meyvora-seo',
			__( 'Settings', 'meyvora-seo' ),
			__( 'Settings', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-settings',
			array( $this, 'render_settings_page' )
		);
		if ( $this->wizard ) {
			add_submenu_page(
				'meyvora-seo',
				__( 'Setup Wizard', 'meyvora-seo' ),
				__( 'Setup Wizard', 'meyvora-seo' ),
				'manage_options',
				'meyvora-seo-wizard',
				array( $this->wizard, 'render_wizard' ),
				5
			);
		}
	}

	/**
	 * Register SEO Audit submenu.
	 */
	public function register_audit_submenu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'SEO Audit', 'meyvora-seo' ),
			__( 'SEO Audit', 'meyvora-seo' ),
			'edit_posts',
			'meyvora-seo-audit',
			array( $this, 'render_audit_page' )
		);
		add_submenu_page(
			'meyvora-seo',
			__( 'Redirects', 'meyvora-seo' ),
			__( 'Redirects', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-redirects',
			array( $this, 'render_redirects_page' )
		);
		add_submenu_page(
			'meyvora-seo',
			__( 'Import', 'meyvora-seo' ),
			__( 'Import', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-import',
			array( $this, 'render_import_page' )
		);
		add_submenu_page(
			'meyvora-seo',
			__( 'Topic Clusters', 'meyvora-seo' ),
			__( 'Topic Clusters', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-topic-clusters',
			array( $this, 'render_topic_clusters_page' ),
			12
		);
	}

	/**
	 * Render Topic Clusters admin page.
	 */
	public function render_topic_clusters_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$topic_clusters_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-topic-clusters.php';
		if ( file_exists( $topic_clusters_file ) ) {
			require_once $topic_clusters_file;
			if ( class_exists( 'Meyvora_SEO_Topic_Clusters' ) ) {
				$tc = new Meyvora_SEO_Topic_Clusters( $this->loader, $this->options );
				$tc->render_page();
			} else {
				echo '<div class="wrap"><h1>' . esc_html__( 'Topic Clusters', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'Module not available.', 'meyvora-seo' ) . '</p></div>';
			}
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Topic Clusters', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'View not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}

	/**
	 * Render the SEO Audit page: list of posts/pages with SEO status.
	 */
	public function render_audit_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$post_types   = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		$per_page     = 25;
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$score_filter = isset( $_GET['mev_score'] ) ? sanitize_text_field( wp_unslash( $_GET['mev_score'] ) ) : '';
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		);
		if ( $search ) {
			$query_args['s'] = $search;
		}
		if ( $score_filter === 'good' ) {
			$query_args['meta_query'] = array( array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => 80, 'compare' => '>=', 'type' => 'NUMERIC' ) );
		} elseif ( $score_filter === 'okay' ) {
			$query_args['meta_query'] = array( array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => array( 50, 79 ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ) );
		} elseif ( $score_filter === 'poor' ) {
			$query_args['meta_query'] = array( array( 'key' => MEYVORA_SEO_META_SCORE, 'value' => 50, 'compare' => '<', 'type' => 'NUMERIC' ) );
		} elseif ( $score_filter === 'nokey' ) {
			$query_args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'compare' => 'NOT EXISTS' ),
				array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'value' => '', 'compare' => '=' ),
			);
		}

		$q     = new WP_Query( $query_args );
		$posts = $q->posts;
		$total = $q->found_posts;
		$pages = (int) ceil( $total / $per_page );

		// Bulk meta fetch for current page.
		$post_ids = wp_list_pluck( $posts, 'ID' );
		$meta_map = array();
		if ( ! empty( $post_ids ) ) {
			global $wpdb;
			$id_str = implode( ',', array_map( 'intval', $post_ids ) );
			$keys   = array(
				MEYVORA_SEO_META_SCORE,
				MEYVORA_SEO_META_FOCUS_KEYWORD,
				MEYVORA_SEO_META_TITLE,
				MEYVORA_SEO_META_DESCRIPTION,
				MEYVORA_SEO_META_NOINDEX,
				MEYVORA_SEO_META_ANALYSIS,
				MEYVORA_SEO_META_READABILITY,
			);
			$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
				WHERE post_id IN ({$id_str})
				AND meta_key IN ({$placeholders})",
				...$keys
			) );
			foreach ( $rows as $row ) {
				$meta_map[ $row->post_id ][ $row->meta_key ] = $row->meta_value;
			}
		}

		// Summary counts (all posts, not just current page).
		$all_q   = new WP_Query( array( 'post_type' => $post_types, 'post_status' => array( 'publish', 'draft', 'pending' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
		$all_ids = is_array( $all_q->posts ) ? $all_q->posts : array();
		$sum_good = $sum_okay = $sum_poor = $sum_none = 0;
		foreach ( $all_ids as $pid ) {
			$s = get_post_meta( $pid, MEYVORA_SEO_META_SCORE, true );
			if ( $s === '' || ! is_numeric( $s ) ) {
				$sum_none++;
				continue;
			}
			$s = (int) $s;
			if ( $s >= 80 ) {
				$sum_good++;
			} elseif ( $s >= 50 ) {
				$sum_okay++;
			} else {
				$sum_poor++;
			}
		}
		$current_page = 'content-audit';
		?>
		<div class="wrap meyvora-audit-page">

		<div class="mev-page-header">
		  <div class="mev-page-header-left">
			<div class="mev-page-logo">M</div>
			<div>
			  <div class="mev-page-title"><?php esc_html_e( 'SEO Audit', 'meyvora-seo' ); ?></div>
			  <div class="mev-page-subtitle"><?php esc_html_e( 'Per-Post Content Audit', 'meyvora-seo' ); ?></div>
			</div>
		  </div>
		  <nav class="mev-page-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>"><?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>" class="active"><?php esc_html_e( 'SEO Audit', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-redirects' ) ); ?>"><?php esc_html_e( 'Redirects', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></a>
		  </nav>
		</div>

		<nav class="mev-insights-tabs" aria-label="Insights navigation">
		  <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-reports' ) ); ?>"
		     class="mev-itab <?php echo ( $current_page === 'reports' ) ? 'mev-itab--active' : ''; ?>">
		    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
		    Reports
		  </a>
		  <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>"
		     class="mev-itab <?php echo ( $current_page === 'content-audit' ) ? 'mev-itab--active' : ''; ?>">
		    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
		    Content Audit
		  </a>
		  <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-site-audit' ) ); ?>"
		     class="mev-itab <?php echo ( $current_page === 'site-audit' ) ? 'mev-itab--active' : ''; ?>">
		    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
		    Site Audit
		  </a>
		</nav>

		<!-- Summary bar -->
		<div class="mev-audit-summary">
		  <div class="mev-audit-summary-item"><span class="mev-icon-wrap"><?php echo wp_kses_post( meyvora_seo_icon( 'bar_chart_2', array( 'width' => 18, 'height' => 18 ) ) ); ?></span> <?php printf( /* translators: %d: total number of posts */ esc_html__( '%d total', 'meyvora-seo' ), count( $all_ids ) ); ?></div>
		  <div style="width:1px;background:var(--mev-border);height:16px;"></div>
		  <div class="mev-audit-summary-item"><span class="mev-badge mev-badge--green"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php echo (int) $sum_good; ?> <?php esc_html_e( 'Good', 'meyvora-seo' ); ?></span></div>
		  <div class="mev-audit-summary-item"><span class="mev-badge mev-badge--orange"><?php echo wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php echo (int) $sum_okay; ?> <?php esc_html_e( 'Okay', 'meyvora-seo' ); ?></span></div>
		  <div class="mev-audit-summary-item"><span class="mev-badge mev-badge--red"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php echo (int) $sum_poor; ?> <?php esc_html_e( 'Poor', 'meyvora-seo' ); ?></span></div>
		  <?php if ( $sum_none > 0 ) : ?>
		  <div class="mev-audit-summary-item"><span class="mev-badge mev-badge--gray"><?php echo wp_kses_post( meyvora_seo_icon( 'square', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php echo (int) $sum_none; ?> <?php esc_html_e( 'Not analyzed', 'meyvora-seo' ); ?></span></div>
		  <?php endif; ?>
		</div>

		<!-- Filters -->
		<form method="get" action="">
		  <input type="hidden" name="page" value="meyvora-seo-audit"/>
		  <div class="mev-audit-filters">
			<input type="search" name="s" placeholder="<?php esc_attr_e( 'Search posts...', 'meyvora-seo' ); ?>" value="<?php echo esc_attr( $search ); ?>"/>
			<select name="mev_score">
			  <option value="" <?php selected( $score_filter, '' ); ?>><?php esc_html_e( 'All Scores', 'meyvora-seo' ); ?></option>
			  <option value="good" <?php selected( $score_filter, 'good' ); ?>><?php esc_html_e( 'Good (80+)', 'meyvora-seo' ); ?></option>
			  <option value="okay" <?php selected( $score_filter, 'okay' ); ?>><?php esc_html_e( 'Okay (50–79)', 'meyvora-seo' ); ?></option>
			  <option value="poor" <?php selected( $score_filter, 'poor' ); ?>><?php esc_html_e( 'Poor (<50)', 'meyvora-seo' ); ?></option>
			  <option value="nokey" <?php selected( $score_filter, 'nokey' ); ?>><?php esc_html_e( 'No keyword', 'meyvora-seo' ); ?></option>
			</select>
			<button type="submit" class="mev-btn mev-btn--secondary"><?php esc_html_e( 'Filter', 'meyvora-seo' ); ?></button>
			<?php if ( $score_filter || $search ) : ?>
			  <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>" class="mev-btn mev-btn--secondary"><?php esc_html_e( 'Clear', 'meyvora-seo' ); ?></a>
			<?php endif; ?>
		  </div>
		</form>

		<!-- Table -->
		<div class="mev-card" style="overflow:hidden;">
		  <table class="mev-table" id="mev-audit-table">
			<thead>
			  <tr>
				<th style="width:32px;"></th>
				<th><?php esc_html_e( 'Post / Page', 'meyvora-seo' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'SEO', 'meyvora-seo' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Read.', 'meyvora-seo' ); ?></th>
				<th style="width:130px;"><?php esc_html_e( 'Focus Keyword', 'meyvora-seo' ); ?></th>
				<th style="width:60px;"><?php esc_html_e( 'Title', 'meyvora-seo' ); ?></th>
				<th style="width:60px;"><?php esc_html_e( 'Desc', 'meyvora-seo' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Issues', 'meyvora-seo' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Index', 'meyvora-seo' ); ?></th>
				<th style="width:60px;"></th>
			  </tr>
			</thead>
			<tbody>
			<?php if ( empty( $posts ) ) : ?>
			  <tr><td colspan="10" style="text-align:center;padding:32px;color:var(--mev-gray-400);"><?php esc_html_e( 'No posts found.', 'meyvora-seo' ); ?></td></tr>
			<?php else : ?>
			  <?php
			  foreach ( $posts as $p ) :
				  $pid    = (int) $p->ID;
				  $meta   = $meta_map[ $pid ] ?? array();
				  $score  = isset( $meta[ MEYVORA_SEO_META_SCORE ] ) && is_numeric( $meta[ MEYVORA_SEO_META_SCORE ] ) ? (int) $meta[ MEYVORA_SEO_META_SCORE ] : null;
				  $sc_cls  = $score !== null ? ( $score >= 80 ? 'good' : ( $score >= 50 ? 'okay' : 'poor' ) ) : 'none';
				  $kw     = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::get_focus_keywords_display( $meta[ MEYVORA_SEO_META_FOCUS_KEYWORD ] ?? '' ) : ( (string) ( $meta[ MEYVORA_SEO_META_FOCUS_KEYWORD ] ?? '' ) );
				  $title_v = $meta[ MEYVORA_SEO_META_TITLE ] ?? '';
				  $desc_v  = $meta[ MEYVORA_SEO_META_DESCRIPTION ] ?? '';
				  $noindex = (bool) ( $meta[ MEYVORA_SEO_META_NOINDEX ] ?? '' );
				  $read_raw = $meta[ MEYVORA_SEO_META_READABILITY ] ?? '';
				  $read    = is_numeric( $read_raw ) ? (int) $read_raw : null;
				  $read_cls = $read !== null ? ( $read >= 70 ? 'good' : ( $read >= 50 ? 'okay' : 'poor' ) ) : 'none';

				  $fails = 0;
				  $warns = 0;
				  if ( isset( $meta[ MEYVORA_SEO_META_ANALYSIS ] ) ) {
					  $dec = json_decode( $meta[ MEYVORA_SEO_META_ANALYSIS ], true );
					  if ( ! empty( $dec['results'] ) ) {
						  foreach ( $dec['results'] as $r ) {
							  if ( ( $r['status'] ?? '' ) === 'fail' ) {
								  $fails++;
							  } elseif ( ( $r['status'] ?? '' ) === 'warning' ) {
								  $warns++;
							  }
						  }
					  }
				  }
				  $edit_url = get_edit_post_link( $pid, 'raw' ) ?: '#';
			  ?>
			  <tr class="mev-audit-row" data-pid="<?php echo (int) $pid; ?>" style="cursor:pointer;">
				<td><button type="button" class="mev-row-expand-btn" data-pid="<?php echo (int) $pid; ?>" aria-label="<?php esc_attr_e( 'Expand', 'meyvora-seo' ); ?>" style="transition:transform 0.2s ease;">▶</button></td>
				<td>
				  <div style="font-weight:600;color:var(--mev-gray-800);"><?php echo esc_html( $p->post_title ?: __( '(no title)', 'meyvora-seo' ) ); ?></div>
				  <span class="mev-post-type mev-post-type--<?php echo esc_attr( $p->post_type ); ?>"><?php echo esc_html( $p->post_type ); ?></span>
				</td>
				<td><span class="mev-score-pill mev-score-pill--<?php echo esc_attr( $sc_cls ); ?>"><?php echo $score !== null ? (int) $score : '—'; ?></span></td>
				<td><?php if ( $read !== null ) : ?><span class="mev-score-pill mev-score-pill--<?php echo esc_attr( $read_cls ); ?>"><?php echo (int) $read; ?></span><?php else : ?><span style="color:var(--mev-gray-300);">—</span><?php endif; ?></td>
				<td style="font-size:12px;"><?php echo $kw ? esc_html( $kw ) : '<em style="color:var(--mev-gray-300);">' . esc_html__( 'Not set', 'meyvora-seo' ) . '</em>'; ?></td>
				<td style="text-align:center;"><?php echo trim( (string) $title_v ) !== '' ? '<span style="color:var(--mev-success);">' . wp_kses_post( meyvora_seo_icon( 'check', array( 'width' => 14, 'height' => 14 ) ) ) . '</span>' : '<span style="color:var(--mev-danger);">' . wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 14, 'height' => 14 ) ) ) . '</span>'; ?></td>
				<td style="text-align:center;"><?php echo trim( (string) $desc_v ) !== '' ? '<span style="color:var(--mev-success);">' . wp_kses_post( meyvora_seo_icon( 'check', array( 'width' => 14, 'height' => 14 ) ) ) . '</span>' : '<span style="color:var(--mev-danger);">' . wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 14, 'height' => 14 ) ) ) . '</span>'; ?></td>
				<td>
				  <div class="mev-issue-count">
					<?php if ( $fails > 0 ) : ?><span class="mev-issue-fail"><?php echo (int) $fails; ?><?php echo wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 14, 'height' => 14 ) ) ); ?></span><?php endif; ?>
					<?php if ( $warns > 0 ) : ?><span class="mev-issue-warn"><?php echo (int) $warns; ?><?php echo wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 14, 'height' => 14 ) ) ); ?></span><?php endif; ?>
					<?php if ( ! $fails && ! $warns ) : ?><span style="color:var(--mev-success);"><?php echo wp_kses_post( meyvora_seo_icon( 'check', array( 'width' => 14, 'height' => 14 ) ) ); ?></span><?php endif; ?>
				  </div>
				</td>
				<td><?php echo $noindex ? '<span class="mev-badge mev-badge--red">' . esc_html__( 'Noindex', 'meyvora-seo' ) . '</span>' : '<span class="mev-badge mev-badge--green">' . esc_html__( 'Indexed', 'meyvora-seo' ) . '</span>'; ?></td>
				<td onclick="event.stopPropagation()"><a href="<?php echo esc_url( $edit_url ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Edit', 'meyvora-seo' ); ?></a></td>
			  </tr>
			  <tr id="mev-detail-<?php echo (int) $pid; ?>" class="mev-detail-row">
				<td colspan="10">
				  <div class="mev-row-detail" id="mev-detail-body-<?php echo (int) $pid; ?>">
					<?php
					if ( isset( $meta[ MEYVORA_SEO_META_ANALYSIS ] ) ) {
						$dec = json_decode( $meta[ MEYVORA_SEO_META_ANALYSIS ], true );
						if ( ! empty( $dec['results'] ) ) {
							$fail_items = array_filter( $dec['results'], function ( $r ) {
								return ( $r['status'] ?? '' ) === 'fail';
							} );
							$warn_items = array_filter( $dec['results'], function ( $r ) {
								return ( $r['status'] ?? '' ) === 'warning';
							} );
							if ( ! empty( $fail_items ) ) {
								echo '<div style="margin-bottom:8px;"><strong style="color:var(--mev-danger);font-size:11px;">' . wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 12, 'height' => 12 ) ) ) . ' ' . esc_html__( 'NEEDS FIXING', 'meyvora-seo' ) . '</strong></div>';
								foreach ( $fail_items as $r ) {
									echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:12px;">';
									echo '<span>' . wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 14, 'height' => 14 ) ) ) . '</span><span style="color:var(--mev-gray-700);">' . esc_html( $r['message'] ?? $r['label'] ?? '' ) . '</span>';
									echo '<span style="margin-left:auto;color:var(--mev-danger);font-size:11px;font-weight:700;">0/' . (int) ( $r['weight'] ?? 0 ) . ' ' . esc_html__( 'pts', 'meyvora-seo' ) . '</span>';
									echo '</div>';
								}
							}
							if ( ! empty( $warn_items ) ) {
								echo '<div style="margin-top:8px;margin-bottom:6px;"><strong style="color:var(--mev-warning);font-size:11px;">' . wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 12, 'height' => 12 ) ) ) . ' ' . esc_html__( 'WARNINGS', 'meyvora-seo' ) . '</strong></div>';
								foreach ( $warn_items as $r ) {
									echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;font-size:12px;">';
									echo '<span>' . wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 14, 'height' => 14 ) ) ) . '</span><span style="color:var(--mev-gray-700);">' . esc_html( $r['message'] ?? $r['label'] ?? '' ) . '</span>';
									echo '</div>';
								}
							}
						} else {
							echo '<p style="color:var(--mev-gray-400);font-size:12px;">' . esc_html__( 'No analysis data. Save the post to analyze.', 'meyvora-seo' ) . '</p>';
						}
					} else {
						echo '<p style="color:var(--mev-gray-400);font-size:12px;">' . esc_html__( 'No analysis data. Open and save the post to analyze.', 'meyvora-seo' ) . '</p>';
					}
					?>
					<div style="margin-top:10px;">
					  <a href="<?php echo esc_url( $edit_url ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Edit & Fix SEO', 'meyvora-seo' ); ?></a>
					</div>
				  </div>
				</td>
			  </tr>
			  <?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		  </table>
		</div>

		<!-- Pagination -->
		<?php if ( $pages > 1 ) : ?>
		<div style="display:flex;align-items:center;justify-content:space-between;margin-top:16px;">
		  <div style="font-size:12px;color:var(--mev-gray-400);"><?php printf( /* translators: 1: number of posts on page, 2: total number of posts */ esc_html__( 'Showing %1$d of %2$d posts', 'meyvora-seo' ), count( $posts ), (int) $total ); ?></div>
		  <div style="display:flex;gap:4px;">
			<?php
			for ( $i = 1; $i <= $pages; $i++ ) {
				$url = add_query_arg( array( 'page' => 'meyvora-seo-audit', 'paged' => $i, 'mev_score' => $score_filter, 's' => $search ), admin_url( 'admin.php' ) );
				?>
			<a href="<?php echo esc_url( $url ); ?>" class="mev-btn <?php echo $i === $paged ? 'mev-btn--primary' : 'mev-btn--secondary'; ?> mev-btn--sm"><?php echo (int) $i; ?></a>
			<?php } ?>
		  </div>
		</div>
		<?php endif; ?>

		</div><!-- /.wrap -->
		<script>
		(function(){
			var DURATION_MS = 350;

			function mevToggleDetail(pid) {
				var detailBody = document.getElementById('mev-detail-body-' + pid);
				var btn = document.querySelector('.mev-row-expand-btn[data-pid="' + pid + '"]');
				if (!detailBody) return;
				var isOpen = detailBody.classList.contains('is-open');

				if (isOpen) {
					// Close: capture current height, then animate to 0
					var startHeight = detailBody.scrollHeight;
					detailBody.style.height = startHeight + 'px';
					detailBody.style.padding = '14px 20px';
					detailBody.offsetHeight;
					detailBody.classList.remove('is-open');
					detailBody.style.height = '0';
					detailBody.style.padding = '0 20px';
					detailBody.addEventListener('transitionend', function onCloseEnd(e) {
						if (e.propertyName !== 'height') return;
						detailBody.removeEventListener('transitionend', onCloseEnd);
						detailBody.style.height = '';
						detailBody.style.padding = '';
					}, { once: true });
				} else {
					// Open: add class, measure content height (incl. padding 14px top+bottom), animate from 0
					detailBody.classList.add('is-open');
					detailBody.style.height = '0';
					detailBody.style.padding = '0 20px';
					detailBody.offsetHeight;
					var contentHeight = detailBody.scrollHeight;
					var endHeight = contentHeight + 28;
					detailBody.style.height = endHeight + 'px';
					detailBody.style.padding = '14px 20px';
					detailBody.addEventListener('transitionend', function onOpenEnd(e) {
						if (e.propertyName !== 'height') return;
						detailBody.removeEventListener('transitionend', onOpenEnd);
						detailBody.style.height = 'auto';
						detailBody.style.padding = '14px 20px';
					}, { once: true });
				}

				if (btn) {
					btn.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(90deg)';
					btn.style.color = isOpen ? '' : 'var(--mev-primary)';
				}
				var dataRow = document.querySelector('.mev-audit-row[data-pid="' + pid + '"]');
				if (dataRow) dataRow.classList.toggle('is-expanded', !isOpen);
			}
			window.mevToggleDetail = mevToggleDetail;

			document.addEventListener('DOMContentLoaded', function() {
				document.querySelectorAll('.mev-audit-row').forEach(function(row) {
					row.addEventListener('click', function(e) {
						if (e.target.tagName === 'A' || e.target.tagName === 'INPUT') return;
						var pid = this.dataset.pid;
						if (pid) mevToggleDetail(pid);
					});
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Render Redirects admin page.
	 */
	public function render_redirects_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'meyvora-seo' ) );
		}
		$view_file = MEYVORA_SEO_PATH . 'admin/views/redirects.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Redirects', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'Redirect manager view not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}

	/**
	 * Render Import admin page (Yoast / Rank Math / AIOSEO).
	 */
	public function render_import_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$view_file = MEYVORA_SEO_PATH . 'admin/views/import.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Import', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'Import view not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}

	/**
	 * AJAX: Run one batch of import (or redirects only on first request).
	 */
	public function ajax_import_batch(): void {
		check_ajax_referer( 'meyvora_seo_import_batch', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$import_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-import.php';
		if ( file_exists( $import_file ) ) {
			require_once $import_file;
		}
		if ( ! class_exists( 'Meyvora_SEO_Import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Import class not available.', 'meyvora-seo' ) ) );
		}
		$source = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		if ( ! in_array( $source, array( 'yoast', 'rankmath', 'aioseo' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid source.', 'meyvora-seo' ) ) );
		}
		$offset = isset( $_POST['offset'] ) ? max( 0, (int) $_POST['offset'] ) : 0;
		$dry_run = ! empty( $_POST['dry_run'] );
		$delete_after = ! empty( $_POST['delete_after'] );
		$import_redirects = ! empty( $_POST['import_redirects'] );

		$redirects_count = 0;
		if ( $offset === 0 && $import_redirects ) {
			if ( $source === 'yoast' && Meyvora_SEO_Import::has_yoast_redirects() ) {
				$r = Meyvora_SEO_Import::import_redirects_yoast( $dry_run, $delete_after );
				$redirects_count = (int) ( $r['redirects'] ?? 0 );
			}
			if ( $source === 'rankmath' && Meyvora_SEO_Import::has_rankmath_redirects() ) {
				$r = Meyvora_SEO_Import::import_redirects_rankmath( $dry_run, $delete_after );
				$redirects_count = (int) ( $r['redirects'] ?? 0 );
			}
		}

		$batch_counts = Meyvora_SEO_Import::import_batch( $source, $offset, $dry_run, $delete_after );
		$total = Meyvora_SEO_Import::get_total_posts_to_import( $source );
		$next_offset = $offset + Meyvora_SEO_Import::BATCH_SIZE;
		$done = $batch_counts['processed'] < Meyvora_SEO_Import::BATCH_SIZE || $next_offset >= $total;

		wp_send_json_success( array(
			'done'         => $done,
			'total'        => $total,
			'offset'       => $next_offset,
			'batch_counts' => $batch_counts,
			'redirects'    => $redirects_count,
			'dry_run'      => $dry_run,
		) );
	}

	/**
	 * AJAX: Scan for redirect chains. Returns list of chains for the redirects page UI.
	 */
	public function ajax_chain_scan(): void {
		check_ajax_referer( 'meyvora_seo_chain', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$chains = Meyvora_SEO_Redirects::find_all_chains();
		wp_send_json_success( array( 'chains' => $chains ) );
	}

	/**
	 * AJAX: Flatten all redirect chains (update source rows to final target; do not delete intermediates).
	 */
	public function ajax_chain_flatten_all(): void {
		check_ajax_referer( 'meyvora_seo_chain', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$result = Meyvora_SEO_Redirects::flatten_all_chains();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Flatten a single chain (update one redirect to point to final target).
	 */
	public function ajax_chain_flatten_one(): void {
		check_ajax_referer( 'meyvora_seo_chain', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$source_id   = isset( $_POST['source_id'] ) ? (int) $_POST['source_id'] : 0;
		$final_target = isset( $_POST['final_target'] ) ? sanitize_text_field( wp_unslash( $_POST['final_target'] ) ) : '';
		if ( $source_id <= 0 || $final_target === '' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid source_id or final_target.', 'meyvora-seo' ) ) );
		}
		$ok = Meyvora_SEO_Redirects::flatten_chain( $source_id, $final_target );
		wp_send_json_success( array( 'flattened' => $ok ) );
	}

	/**
	 * Render Dashboard (overview cards, distribution, checklist).
	 */
	public function render_dashboard(): void {
		if ( ! $this->options->current_user_can_edit_seo() ) {
			return;
		}
		$view = MEYVORA_SEO_PATH . 'admin/views/dashboard.php';
		if ( file_exists( $view ) ) {
			$data = array_merge(
				$this->get_dashboard_traffic_data(),
				array( 'stale_posts' => $this->get_stale_posts() )
			);
			include $view;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Dashboard', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'Dashboard view not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}

	/**
	 * Build traffic data for dashboard (GSC summary, GA4 top posts).
	 *
	 * @return array{gsc_summary: array{clicks: int, impressions: int}|null, ga4_top_posts: array, gsc_connected: bool, ga4_connected: bool}
	 */
	private function get_dashboard_traffic_data(): array {
		$data = array(
			'gsc_summary'          => null,
			'ga4_top_posts'        => array(),
			'gsc_connected'        => false,
			'ga4_connected'        => false,
			'ctr_opportunities'    => array(),
			'decaying_pages_count' => 0,
		);

		// GSC: site-level impressions/clicks for past 28 days.
		if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
			$gsc_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php';
			if ( file_exists( $gsc_file ) ) {
				require_once $gsc_file;
			}
			if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
				$gsc = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
				if ( $gsc->is_connected() ) {
					$data['gsc_connected'] = true;
					$dash = $gsc->get_dashboard_data();
					$totals = isset( $dash['totals'] ) && is_array( $dash['totals'] ) ? $dash['totals'] : array();
					$data['gsc_summary'] = array(
						'clicks'      => isset( $totals['clicks'] ) ? (int) $totals['clicks'] : 0,
						'impressions' => isset( $totals['impressions'] ) ? (int) $totals['impressions'] : 0,
					);
					$data['ctr_opportunities'] = $gsc->get_ctr_opportunities( 5 );
					$data['decaying_pages_count'] = count( $gsc->get_decaying_pages( 10 ) );
				}
			}
		}

		// GA4: top 5 pages by pageviews when advanced connected.
		if ( class_exists( 'Meyvora_SEO_GA4' ) ) {
			$ga4_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-ga4.php';
			if ( file_exists( $ga4_file ) ) {
				require_once $ga4_file;
			}
			if ( class_exists( 'Meyvora_SEO_GA4' ) ) {
				$ga4 = new Meyvora_SEO_GA4( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
				if ( $ga4->is_advanced_connected() ) {
					$data['ga4_connected'] = true;
					$data['ga4_top_posts'] = $ga4->get_top_posts_by_views( 5 );
				}
			}
		}

		return $data;
	}

	/**
	 * Get published posts not updated in 180+ days (excludes noindex and meyvora_seo_template).
	 *
	 * @param int $limit Max number of posts to return.
	 * @return array<int, array{id: int, title: string, edit_link: string|false, days_old: int}>
	 */
	private function get_stale_posts( int $limit = 10 ): array {
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		$post_types = array_values( array_diff( (array) $post_types, array( 'meyvora_seo_template' ) ) );
		if ( empty( $post_types ) ) {
			return array();
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-180 days' ) );
		$posts = get_posts( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'date_query'     => array( array( 'column' => 'post_modified', 'before' => $cutoff ) ),
			'meta_query'     => array( array( 'key' => MEYVORA_SEO_META_NOINDEX, 'compare' => 'NOT EXISTS' ) ),
		) );
		$out = array();
		foreach ( $posts as $post ) {
			$days_old = (int) floor( ( time() - strtotime( $post->post_modified ) ) / DAY_IN_SECONDS );
			$out[] = array(
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'edit_link' => get_edit_post_link( $post->ID, 'raw' ),
				'days_old'  => $days_old,
			);
		}
		return $out;
	}

	/**
	 * Handle export, import, regenerate, reset from Settings > Tools.
	 */
	public function handle_settings_actions(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['meyvora_gsc_disconnect'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'meyvora_gsc_disconnect' ) ) {
			$gsc_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php';
			if ( file_exists( $gsc_file ) ) {
				require_once $gsc_file;
				if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
					$gsc = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
					$gsc->disconnect();
				}
			}
			wp_safe_redirect( add_query_arg( array( 'page' => 'meyvora-seo-settings', 'tab' => 'tab-integrations' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( isset( $_GET['meyvora_ga4_disconnect'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'meyvora_ga4_disconnect' ) ) {
			$ga4_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-ga4.php';
			if ( file_exists( $ga4_file ) ) {
				require_once $ga4_file;
				if ( class_exists( 'Meyvora_SEO_GA4' ) ) {
					$ga4 = new Meyvora_SEO_GA4( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
					$ga4->disconnect();
				}
			}
			$this->options->update_all( array( 'ga4_credentials_encrypted' => '', 'ga4_property_id' => '' ) );
			wp_safe_redirect( add_query_arg( array( 'page' => 'meyvora-seo-settings', 'tab' => 'tab-integrations' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( isset( $_GET['meyvora_export'] ) && $_GET['meyvora_export'] === 'json' && isset( $_GET['meyvora_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['meyvora_nonce'] ) ), 'meyvora_export' ) ) {
			$opts = $this->options->get_all();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="meyvora-seo-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
			echo wp_json_encode( $opts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			exit;
		}
		if ( isset( $_POST['meyvora_import_json'] ) && isset( $_POST['meyvora_import_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meyvora_import_nonce'] ) ), 'meyvora_import_json' ) && ! empty( $_FILES['meyvora_import_file']['tmp_name'] ) ) {
			$tmp_path = sanitize_text_field( wp_unslash( $_FILES['meyvora_import_file']['tmp_name'] ) );
			$json = ( $tmp_path && is_uploaded_file( $tmp_path ) ) ? file_get_contents( $tmp_path ) : false;
			if ( $json !== false ) {
				$data = json_decode( $json, true );
				if ( is_array( $data ) ) {
					$this->options->update_all( $data );
					wp_safe_redirect( add_query_arg( 'meyvora_imported', '1', admin_url( 'admin.php?page=meyvora-seo-settings#tab-tools' ) ) );
					exit;
				}
			}
		}
		if ( isset( $_GET['meyvora_regenerate'] ) && $_GET['meyvora_regenerate'] === '1' && isset( $_GET['meyvora_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['meyvora_nonce'] ) ), 'meyvora_regenerate' ) ) {
			$analyzer_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-analyzer.php' : '';
			if ( $analyzer_file && file_exists( $analyzer_file ) ) {
				require_once $analyzer_file;
				if ( class_exists( 'Meyvora_SEO_Analyzer' ) ) {
					$analyzer = new Meyvora_SEO_Analyzer();
					$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'posts_per_page' => 500, 'fields' => 'ids', 'meta_query' => array( array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'compare' => 'EXISTS' ), array( 'key' => MEYVORA_SEO_META_FOCUS_KEYWORD, 'value' => '', 'compare' => '!=' ) ) ) );
					foreach ( $posts as $pid ) {
						$analyzer->analyze( (int) $pid );
					}
				}
			}
			wp_safe_redirect( add_query_arg( array( 'meyvora_regenerate' => '1', 'meyvora_nonce' => wp_create_nonce( 'meyvora_regenerate' ) ), admin_url( 'admin.php?page=meyvora-seo-settings#tab-tools' ) ) );
			exit;
		}
		if ( isset( $_GET['meyvora_reset'] ) && $_GET['meyvora_reset'] === '1' && isset( $_GET['meyvora_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['meyvora_nonce'] ) ), 'meyvora_reset' ) ) {
			$this->options->reset();
			wp_safe_redirect( add_query_arg( 'meyvora_reset', '1', remove_query_arg( array( 'meyvora_nonce' ) ) ) );
			exit;
		}
	}

	/**
	 * Enqueue admin CSS for dashboard and other Meyvora SEO admin pages.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	/**
	 * Toast notifications on all admin screens (bulk save, link fix, AI rate limit, etc.).
	 */
	public function enqueue_meyvora_toast(): void {
		if ( ! is_admin() ) {
			return;
		}
		$toast = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-toast.js' : '';
		if ( $toast && file_exists( $toast ) ) {
			wp_enqueue_script(
				'meyvora-toast',
				( defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '' ) . 'admin/assets/js/meyvora-toast.js',
				array(),
				defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0',
				true
			);
		}
	}

	public function enqueue_dashboard_assets( string $hook_suffix ): void {
		$meyvora_screens = array(
			'toplevel_page_meyvora-seo',
			'meyvora-seo_page_meyvora-seo-settings',
			'meyvora-seo_page_meyvora-seo-audit',
			'meyvora-seo_page_meyvora-seo-site-audit',
			'meyvora-seo_page_meyvora-seo-link-analysis',
			'meyvora-seo_page_meyvora-seo-bulk-editor',
			'meyvora-seo_page_meyvora-seo-redirects',
			'meyvora-seo_page_meyvora-seo-import',
		);
		if ( ! in_array( $hook_suffix, $meyvora_screens, true ) ) {
			return;
		}
		$css_path = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-admin.css' : '';
		if ( $css_path && file_exists( $css_path ) ) {
			wp_enqueue_style(
				'meyvora-seo-admin',
				( defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '' ) . 'admin/assets/css/meyvora-admin.css',
				array(),
				defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0'
			);
		}
		if ( $hook_suffix === 'meyvora-seo_page_meyvora-seo-redirects' ) {
			$js_path = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-redirects-chains.js' : '';
			if ( $js_path && file_exists( $js_path ) ) {
				wp_enqueue_script(
					'meyvora-redirects-chains',
					( defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '' ) . 'admin/assets/js/meyvora-redirects-chains.js',
					array(),
					defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0',
					true
				);
				wp_localize_script(
					'meyvora-redirects-chains',
					'meyvoraRedirectsChain',
					array(
						'ajax_url'         => admin_url( 'admin-ajax.php' ),
						'nonce'           => wp_create_nonce( 'meyvora_seo_chain' ),
						'action_scan'     => 'meyvora_seo_chain_scan',
						'action_flatten_all' => 'meyvora_seo_chain_flatten_all',
						'action_flatten_one' => 'meyvora_seo_chain_flatten_one',
						'i18n'            => array(
							'no_chains'     => __( 'No redirect chains found.', 'meyvora-seo' ),
							/* translators: %d: number of redirect hops */
							'hops'          => __( '%d hop(s)', 'meyvora-seo' ),
							'flatten'       => __( 'Flatten', 'meyvora-seo' ),
							/* translators: 1: number flattened, 2: number of errors */
							'flattened_all' => __( 'Flattened: %1$d, Errors: %2$d', 'meyvora-seo' ),
						),
					)
				);
			}
		}
	}

	/**
	 * Enqueue scripts/styles for settings page (tabs).
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_settings_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_meyvora-seo-settings' ) {
			return;
		}
		$this->enqueue_meyvora_toast();
		wp_add_inline_script( 'jquery', "
			jQuery(function($){
				var hash = location.hash ? location.hash.replace('#','') : 'tab-general';
				$('.meyvora-seo-tab-pane').hide();
				$('#'+hash).show();
				$('.mev-settings-nav-item').removeClass('active').filter('[data-tab=\"'+hash+'\"]').addClass('active');
				$('.mev-settings-nav-item').on('click',function(e){
					e.preventDefault();
					var t = $(this).data('tab');
					location.hash = t;
					$('.meyvora-seo-tab-pane').hide();
					$('#'+t).show();
					$('.mev-settings-nav-item').removeClass('active');
					$(this).addClass('active');
				});
			});
		" );
	}

	/**
	 * Render the settings page (tabbed).
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'meyvora-seo' ) );
		}
		$tabs = array(
			'tab-general'      => __( 'General', 'meyvora-seo' ),
			'tab-social'       => __( 'Social', 'meyvora-seo' ),
			'tab-sitemap'       => __( 'Sitemaps', 'meyvora-seo' ),
			'tab-schema'        => __( 'Schema', 'meyvora-seo' ),
			'tab-local-seo'     => __( 'Local SEO', 'meyvora-seo' ),
			'tab-breadcrumbs'   => __( 'Breadcrumbs', 'meyvora-seo' ),
			'tab-technical'     => __( 'Technical', 'meyvora-seo' ),
			'tab-advanced'      => __( 'Advanced', 'meyvora-seo' ),
			'tab-ai'            => __( 'AI', 'meyvora-seo' ),
			'tab-integrations'  => __( 'Integrations', 'meyvora-seo' ),
			'tab-reports'       => __( 'Reports', 'meyvora-seo' ),
			'tab-tools'         => __( 'Tools', 'meyvora-seo' ),
			'tab-system'        => __( 'System Info', 'meyvora-seo' ),
		);
		$view_file = MEYVORA_SEO_PATH . 'admin/views/settings.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
			return;
		}
		?>
		<div class="wrap meyvora-settings-page">
		<div class="mev-page-header">
		  <div class="mev-page-header-left">
			<div class="mev-page-logo">M</div>
			<div>
			  <div class="mev-page-title"><?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></div>
			  <div class="mev-page-subtitle"><?php esc_html_e( 'Configure your SEO plugin', 'meyvora-seo' ); ?></div>
			</div>
		  </div>
		</div>

		<form action="options.php" method="post" id="meyvora-seo-settings-form">
		<?php settings_fields( 'meyvora_seo' ); ?>

		<div class="mev-settings-wrap">
		  <nav class="mev-settings-sidebar">
			<div class="mev-settings-sidebar-title"><?php esc_html_e( 'SETTINGS', 'meyvora-seo' ); ?></div>
			<?php
			$tab_icons = array( 'tab-general' => 'settings', 'tab-social' => 'globe', 'tab-sitemap' => 'map', 'tab-schema' => 'file_text', 'tab-local-seo' => 'map_pin', 'tab-breadcrumbs' => 'link', 'tab-technical' => 'wrench', 'tab-advanced' => 'wrench', 'tab-ai' => 'activity', 'tab-integrations' => 'link', 'tab-reports' => 'file_text', 'tab-tools' => 'hammer', 'tab-system' => 'info' );
			foreach ( $tabs as $id => $label ) :
				$icon_name = $tab_icons[ $id ] ?? 'circle_check';
				?>
			<a href="#<?php echo esc_attr( $id ); ?>" class="mev-settings-nav-item meyvora-seo-nav-tab <?php echo $id === 'tab-general' ? 'active' : ''; ?>" data-tab="<?php echo esc_attr( $id ); ?>">
			  <span class="mev-settings-nav-icon"><?php echo wp_kses_post( meyvora_seo_icon( $icon_name, array( 'width' => 18, 'height' => 18 ) ) ); ?></span>
			  <?php echo esc_html( $label ); ?>
			</a>
			<?php endforeach; ?>
		  </nav>

		  <div class="mev-settings-content">
			<div id="tab-general" class="meyvora-seo-tab-pane"><?php do_settings_sections( 'meyvora-seo-general' ); ?></div>
			<div id="tab-social" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-social' ); ?></div>
			<div id="tab-sitemap" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-sitemap' ); ?></div>
			<div id="tab-schema" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-schema' ); ?></div>
			<div id="tab-local-seo" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-local' ); ?></div>
			<div id="tab-breadcrumbs" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-breadcrumbs' ); ?></div>
			<div id="tab-technical" class="meyvora-seo-tab-pane" style="display:none;"><?php
				if ( $this->technical instanceof Meyvora_SEO_Technical ) {
					$this->technical->render_tab();
				}
			?></div>
			<div id="tab-advanced" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-advanced' ); ?></div>
			<div id="tab-ai" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-ai' ); ?></div>
			<div id="tab-integrations" class="meyvora-seo-tab-pane" style="display:none;"><?php $this->render_integrations_tab(); ?></div>
			<div id="tab-reports" class="meyvora-seo-tab-pane" style="display:none;"><?php do_settings_sections( 'meyvora-seo-reports' ); ?></div>
			<div id="tab-tools" class="meyvora-seo-tab-pane" style="display:none;"><?php $this->render_tools_tab(); ?></div>
			<div id="tab-system" class="meyvora-seo-tab-pane" style="display:none;"><?php $this->render_system_info_tab(); ?></div>
			<div style="margin-top:24px;padding-top:20px;border-top:1px solid var(--mev-border);">
			  <?php submit_button( __( 'Save Settings', 'meyvora-seo' ), 'primary', 'submit', false, array( 'class' => 'mev-btn mev-btn--primary', 'style' => 'height:auto;' ) ); ?>
			</div>
		  </div>
		</div>
		</form>
		</div>
		<?php
	}

	/**
	 * Integrations tab: GSC/GA4 connection status and settings sections.
	 */
	private function render_integrations_tab(): void {
		$gsc_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php';
		$gsc_connected = false;
		$gsc_auth_url = '';
		if ( file_exists( $gsc_file ) ) {
			require_once $gsc_file;
			if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
				$gsc = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
				$gsc_connected = $gsc->is_connected();
				$gsc_auth_url = $gsc->get_auth_url();
			}
		}
		$ga4_advanced = $this->options->get( 'ga4_mode', 'simple' ) === 'advanced';
		$ga4_advanced_connected = false;
		if ( $ga4_advanced ) {
			$ga4_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-ga4.php';
			if ( file_exists( $ga4_file ) ) {
				require_once $ga4_file;
				if ( class_exists( 'Meyvora_SEO_GA4' ) ) {
					$ga4 = new Meyvora_SEO_GA4( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
					$ga4_advanced_connected = $ga4->is_advanced_connected();
				}
			}
		}
		$redirect_uri = '';
		if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
			$gsc_temp = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
			$redirect_uri = $gsc_temp->get_redirect_uri();
		}
		$gsc_connected_msg = isset( $_GET['meyvora_gsc_connected'] ) ? ( $_GET['meyvora_gsc_connected'] === '1' ? __( 'Google Search Console connected.', 'meyvora-seo' ) : __( 'Connection failed. Check Client ID and Secret.', 'meyvora-seo' ) ) : '';
		?>
		<div class="mev-integrations-status" style="margin-bottom:24px;">
			<div class="mev-card" style="padding:16px 20px; margin-bottom:16px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Google Search Console', 'meyvora-seo' ); ?></h3>
				<p>
					<?php if ( $gsc_connected ) : ?>
						<span style="color:var(--mev-success);"><?php esc_html_e( 'Connected', 'meyvora-seo' ); ?></span>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'meyvora_gsc_disconnect', '1', admin_url( 'admin.php?page=meyvora-seo-settings' ) ), 'meyvora_gsc_disconnect', '_wpnonce' ) ); ?>" class="button button-secondary" style="margin-left:12px;"><?php esc_html_e( 'Disconnect', 'meyvora-seo' ); ?></a>
					<?php else : ?>
						<span style="color:var(--mev-gray-500);"><?php esc_html_e( 'Not connected', 'meyvora-seo' ); ?></span>
						<?php if ( $gsc_auth_url !== '' ) : ?>
							<a href="<?php echo esc_url( $gsc_auth_url ); ?>" class="button button-primary" style="margin-left:12px;"><?php esc_html_e( 'Connect Google', 'meyvora-seo' ); ?></a>
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<?php if ( $redirect_uri !== '' ) : ?>
					<p class="description"><?php esc_html_e( 'OAuth redirect URI (add this in Google Cloud Console):', 'meyvora-seo' ); ?><br><code style="word-break:break-all;"><?php echo esc_html( $redirect_uri ); ?></code></p>
				<?php endif; ?>
				<?php if ( $gsc_connected_msg !== '' ) : ?>
					<p><strong><?php echo esc_html( $gsc_connected_msg ); ?></strong></p>
				<?php endif; ?>
			</div>
			<div class="mev-card" style="padding:16px 20px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Google Analytics 4', 'meyvora-seo' ); ?></h3>
				<p>
					<?php if ( $ga4_advanced ) : ?>
						<?php if ( $ga4_advanced_connected ) : ?>
							<span style="color:var(--mev-success);"><?php esc_html_e( 'Advanced: Connected (Views column in post list)', 'meyvora-seo' ); ?></span>
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'meyvora_ga4_disconnect', '1', admin_url( 'admin.php?page=meyvora-seo-settings' ) ), 'meyvora_ga4_disconnect', '_wpnonce' ) ); ?>" class="button button-secondary" style="margin-left:12px;"><?php esc_html_e( 'Disconnect', 'meyvora-seo' ); ?></a>
						<?php else : ?>
							<span style="color:var(--mev-gray-500);"><?php esc_html_e( 'Advanced: Add Property ID and service account JSON below, then save.', 'meyvora-seo' ); ?></span>
						<?php endif; ?>
					<?php else : ?>
						<span><?php esc_html_e( 'Simple mode: add Measurement ID below to load gtag.js.', 'meyvora-seo' ); ?></span>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
		do_settings_sections( 'meyvora-seo-integrations' );
	}

	/**
	 * Tools tab: export/import JSON, regenerate scores, import from Yoast/RankMath, reset.
	 */
	private function render_tools_tab(): void {
		$import_from = isset( $_GET['meyvora_import'] ) ? sanitize_text_field( wp_unslash( $_GET['meyvora_import'] ) ) : '';
		$regenerate_done = isset( $_GET['meyvora_regenerate'] ) && $_GET['meyvora_regenerate'] === '1';
		$reset_done = isset( $_GET['meyvora_reset'] ) && $_GET['meyvora_reset'] === '1';
		?>
		<div class="meyvora-seo-tools">
			<h2><?php esc_html_e( 'Export / Import', 'meyvora-seo' ); ?></h2>
			<p><a href="<?php echo esc_url( add_query_arg( array( 'meyvora_export' => 'json', 'meyvora_nonce' => wp_create_nonce( 'meyvora_export' ) ), admin_url( 'admin.php?page=meyvora-seo-settings' ) ) ); ?>" class="button"><?php esc_html_e( 'Export settings (JSON)', 'meyvora-seo' ); ?></a></p>
			<form method="post" action="" enctype="multipart/form-data" style="margin-top:1em;">
				<?php wp_nonce_field( 'meyvora_import_json', 'meyvora_import_nonce' ); ?>
				<p><label><?php esc_html_e( 'Import from JSON file:', 'meyvora-seo' ); ?></label> <input type="file" name="meyvora_import_file" accept=".json" /> <button type="submit" name="meyvora_import_json" class="button"><?php esc_html_e( 'Import', 'meyvora-seo' ); ?></button></p>
			</form>

			<h2><?php esc_html_e( 'Regenerate SEO scores', 'meyvora-seo' ); ?></h2>
			<p><?php esc_html_e( 'Re-analyze all posts and pages that have a focus keyword set.', 'meyvora-seo' ); ?></p>
			<?php if ( $regenerate_done ) : ?>
				<p class="notice notice-success"><?php esc_html_e( 'Regenerate requested. Run in background or reload to see progress.', 'meyvora-seo' ); ?></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'meyvora_regenerate', '1', admin_url( 'admin.php?page=meyvora-seo-settings&tab=tab-tools' ) ), 'meyvora_regenerate', 'meyvora_nonce' ) ); ?>" class="button"><?php esc_html_e( 'Regenerate all SEO scores', 'meyvora-seo' ); ?></a></p>

			<h2><?php esc_html_e( 'Import from other plugins', 'meyvora-seo' ); ?></h2>
			<p><?php esc_html_e( 'Import SEO data (titles, descriptions, focus keywords, redirects) from Yoast SEO, Rank Math, or All In One SEO with batch processing and dry run.', 'meyvora-seo' ); ?></p>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-import' ) ); ?>" class="button button-primary"><?php esc_html_e( 'Open Import page', 'meyvora-seo' ); ?></a></p>

			<h2><?php esc_html_e( 'Reset to defaults', 'meyvora-seo' ); ?></h2>
			<?php if ( $reset_done ) : ?>
				<p class="notice notice-success"><?php esc_html_e( 'Settings reset to defaults.', 'meyvora-seo' ); ?></p>
			<?php endif; ?>
			<p><a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'meyvora_reset', '1', admin_url( 'admin.php?page=meyvora-seo-settings' ) ), 'meyvora_reset', 'meyvora_nonce' ) ); ?>" class="button" onclick="return confirm('<?php echo esc_js( __( 'Reset all settings to defaults?', 'meyvora-seo' ) ); ?>');"><?php esc_html_e( 'Reset to defaults', 'meyvora-seo' ); ?></a></p>
		</div>
		<?php
	}

	/**
	 * System Info tab: plugin/PHP/WP version, theme, plugins, options, Copy button.
	 */
	private function render_system_info_tab(): void {
		$info = array(
			'Plugin'   => 'Meyvora SEO ' . ( defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0' ),
			'WordPress'=> get_bloginfo( 'version' ),
			'PHP'      => PHP_VERSION,
			'Theme'    => wp_get_theme()->get( 'Name' ) . ' ' . wp_get_theme()->get( 'Version' ),
		);
		$active_plugins = get_option( 'active_plugins', array() );
		$info['Active plugins'] = implode( ', ', $active_plugins );
		$opts = $this->options->get_all();
		$info['Meyvora options (keys only)'] = implode( ', ', array_keys( $opts ) );
		$text = '';
		foreach ( $info as $k => $v ) {
			$text .= $k . ': ' . $v . "\n";
		}
		?>
		<div class="meyvora-seo-system-info">
			<h2><?php esc_html_e( 'System information', 'meyvora-seo' ); ?></h2>
			<textarea id="meyvora-system-info" readonly class="large-text code" rows="12" style="width:100%;"><?php echo esc_textarea( $text ); ?></textarea>
			<p><button type="button" class="button" id="meyvora-copy-system-info"><?php esc_html_e( 'Copy system info', 'meyvora-seo' ); ?></button></p>
			<script>
				document.getElementById('meyvora-copy-system-info').addEventListener('click', function(){ var t=document.getElementById('meyvora-system-info'); t.select(); t.setSelectionRange(0,99999); navigator.clipboard.writeText(t.value); });
			</script>
		</div>
		<?php
	}
}
