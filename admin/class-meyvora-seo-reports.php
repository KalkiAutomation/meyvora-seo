<?php
/**
 * SEO Reports: dashboard data, weekly snapshots, email reports, printable report.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Bulk meta fetch uses direct query; $id_list/$placeholders built safely.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Reports
 */
class Meyvora_SEO_Reports {

	protected Meyvora_SEO_Loader $loader;
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Register menu, cron, and actions.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'admin_menu', $this, 'register_reports_submenu', 12, 0 );
		$this->loader->add_action( 'admin_init', $this, 'maybe_render_print_report', 5, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_reports_assets', 10, 1 );
		$this->loader->add_action( 'init', $this, 'schedule_cron_events', 20, 0 );

		$this->loader->add_action( 'meyvora_seo_weekly_snapshot', $this, 'run_weekly_snapshot', 10, 0 );
		$this->loader->add_action( 'meyvora_seo_weekly_email', $this, 'send_weekly_email', 10, 0 );
	}

	/**
	 * Add Reports submenu under Meyvora SEO.
	 */
	public function register_reports_submenu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Reports', 'meyvora-seo' ),
			__( 'Reports', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-reports',
			array( $this, 'render_reports_page' )
		);
	}

	/**
	 * Enqueue scripts/styles on reports page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_reports_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_meyvora-seo-reports' ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		wp_enqueue_style(
			'meyvora-reports-page',
			MEYVORA_SEO_URL . 'admin/assets/css/meyvora-reports-page.css',
			array( 'meyvora-admin' ),
			MEYVORA_SEO_VERSION
		);
	}

	/**
	 * Schedule weekly snapshot and weekly email (email only when enabled, on chosen day).
	 */
	public function schedule_cron_events(): void {
		if ( ! defined( 'MEYVORA_SEO_PATH' ) ) {
			return;
		}
		$snapshot_hook = 'meyvora_seo_weekly_snapshot';
		if ( ! wp_next_scheduled( $snapshot_hook ) ) {
			wp_schedule_event( $this->next_weekly_timestamp( 1 ), 'weekly', $snapshot_hook );
		}
		$email_hook = 'meyvora_seo_weekly_email';
		wp_clear_scheduled_hook( $email_hook );
		if ( $this->options->get( 'reports_email_enabled', false ) ) {
			$day = (int) $this->options->get( 'reports_email_day', 0 );
			wp_schedule_event( $this->next_weekly_timestamp( $day ), 'weekly', $email_hook );
		}
	}

	/**
	 * Next occurrence of a given day of week at 02:00 (server time).
	 *
	 * @param int $day_of_week 0 = Sunday, 6 = Saturday.
	 * @return int Unix timestamp.
	 */
	protected function next_weekly_timestamp( int $day_of_week ): int {
		$now    = current_time( 'timestamp' );
		$today  = (int) gmdate( 'w', $now );
		$diff   = $day_of_week - $today;
		if ( $diff <= 0 ) {
			$diff += 7;
		}
		$next_date = gmdate( 'Y-m-d', $now + ( $diff * DAY_IN_SECONDS ) );
		return strtotime( $next_date . ' 02:00:00', $now );
	}

	/**
	 * Run weekly snapshot: compute current score and issue counts, append to stored snapshots (keep 12).
	 */
	public function run_weekly_snapshot(): void {
		$data   = $this->get_report_data( false );
		$score  = $data['health_score'];
		$issues = $data['issues'];
		$total_issues = ( $issues['missing_title'] ?? 0 )
			+ ( $issues['missing_description'] ?? 0 )
			+ ( $issues['low_score'] ?? 0 )
			+ ( $issues['missing_schema'] ?? 0 );
		$gsc_summary = isset( $data['gsc_site_summary'] ) && is_array( $data['gsc_site_summary'] ) ? $data['gsc_site_summary'] : array();
		$snapshots = get_option( MEYVORA_SEO_WEEKLY_SNAPSHOTS_OPTION, array() );
		if ( ! is_array( $snapshots ) ) {
			$snapshots = array();
		}
		$week_start = gmdate( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ) );
		$gsc_top = array();
		if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
			$gsc = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
			if ( $gsc->is_connected() ) {
				$dash = $gsc->get_dashboard_data();
				$gsc_top = isset( $dash['top_queries'] ) && is_array( $dash['top_queries'] ) ? array_slice( $dash['top_queries'], 0, 5 ) : array();
			}
		}
		update_option( 'meyvora_seo_reports_gsc_snapshot', $gsc_top, false );

		$snapshots[] = array(
			'week_start'       => $week_start,
			'score'            => $score,
			'issues'           => $total_issues,
			'gsc_clicks'       => isset( $gsc_summary['clicks'] ) ? (int) $gsc_summary['clicks'] : 0,
			'gsc_impressions'  => isset( $gsc_summary['impressions'] ) ? (int) $gsc_summary['impressions'] : 0,
		);
		$snapshots = array_slice( array_values( $snapshots ), -12 );
		update_option( MEYVORA_SEO_WEEKLY_SNAPSHOTS_OPTION, $snapshots, true );
	}

	/**
	 * Send weekly email report if enabled and to configured recipients.
	 */
	public function send_weekly_email(): void {
		if ( ! $this->options->get( 'reports_email_enabled', false ) ) {
			return;
		}
		$recipients = $this->options->get( 'reports_email_recipients', '' );
		$recipients = array_filter( array_map( 'trim', explode( "\n", $recipients ) ) );
		$recipients = array_filter( $recipients, 'is_email' );
		if ( empty( $recipients ) ) {
			$admin_email = get_option( 'admin_email' );
			if ( ! is_email( $admin_email ) ) {
				return;
			}
			$recipients = array( $admin_email );
		}
		$data    = $this->get_report_data( true );
		$snapshots = get_option( MEYVORA_SEO_WEEKLY_SNAPSHOTS_OPTION, array() );
		$prev_issues = 0;
		if ( is_array( $snapshots ) && count( $snapshots ) >= 2 ) {
			$prev = $snapshots[ count( $snapshots ) - 2 ];
			$prev_issues = isset( $prev['issues'] ) ? (int) $prev['issues'] : 0;
		}
		$current_issues = ( $data['issues']['missing_title'] ?? 0 )
			+ ( $data['issues']['missing_description'] ?? 0 )
			+ ( $data['issues']['low_score'] ?? 0 )
			+ ( $data['issues']['missing_schema'] ?? 0 );
		$new_issues = max( 0, $current_issues - $prev_issues );
		$top3_fix = array_slice( $data['bottom_10'], 0, 3 );
		$gsc_current = isset( $data['gsc_site_summary'] ) && is_array( $data['gsc_site_summary'] ) ? $data['gsc_site_summary'] : array( 'clicks' => 0, 'impressions' => 0 );
		$gsc_prev = array( 'clicks' => 0, 'impressions' => 0 );
		if ( is_array( $snapshots ) && count( $snapshots ) >= 2 ) {
			$prev = $snapshots[ count( $snapshots ) - 2 ];
			$gsc_prev = array(
				'clicks'      => isset( $prev['gsc_clicks'] ) ? (int) $prev['gsc_clicks'] : 0,
				'impressions' => isset( $prev['gsc_impressions'] ) ? (int) $prev['gsc_impressions'] : 0,
			);
		}
		$subject = sprintf(
			/* translators: 1: site name, 2: SEO score */
			__( '[%1$s] Weekly SEO Report — Score: %2$d/100', 'meyvora-seo' ),
			get_bloginfo( 'name' ),
			$data['health_score']
		);
		$ctr_opportunities = array();
		$decaying_pages    = array();
		if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
			$gsc_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php' : '';
			if ( $gsc_file && file_exists( $gsc_file ) ) {
				require_once $gsc_file;
			}
			if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
				$gsc = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
				if ( $gsc->is_connected() ) {
					$ctr_opportunities = $gsc->get_ctr_opportunities( 3 );
					$decaying_pages    = $gsc->get_decaying_pages( 3 );
				}
			}
		}
		$stale_posts = $this->get_stale_posts_for_email( 3 );
		$body = $this->get_email_html( $data['health_score'], $new_issues, $top3_fix, $gsc_current, $gsc_prev, $ctr_opportunities, $decaying_pages, $stale_posts );
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $recipients, $subject, $body, $headers );
	}

	/**
	 * Get published posts not updated in 180+ days (for email; excludes noindex and meyvora_seo_template).
	 *
	 * @param int $limit Max number of posts to return.
	 * @return array<int, array{id: int, title: string, edit_link: string|false, days_old: int}>
	 */
	protected function get_stale_posts_for_email( int $limit = 3 ): array {
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
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Single NOT EXISTS meta for stale content list.
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
	 * Build HTML body for weekly email (Meyvora branding).
	 *
	 * @param int   $score        Overall health score.
	 * @param int   $new_issues   Number of new issues this week.
	 * @param array $top3_fix     Top 3 pages to fix (items with title, edit, score).
	 * @param array $gsc_current       Current GSC site summary (clicks, impressions).
	 * @param array $gsc_prev          Previous week GSC summary (clicks, impressions).
	 * @param array $ctr_opportunities CTR opportunities from GSC (max 3).
	 * @param array $decaying_pages    Decaying pages from GSC (max 3).
	 * @param array $stale_posts       Stale posts (max 3): id, title, edit_link, days_old.
	 * @return string HTML.
	 */
	protected function get_email_html( int $score, int $new_issues, array $top3_fix, array $gsc_current = array(), array $gsc_prev = array(), array $ctr_opportunities = array(), array $decaying_pages = array(), array $stale_posts = array() ): string {
		$site_name = get_bloginfo( 'name' );
		$date     = wp_date( get_option( 'date_format' ), current_time( 'timestamp' ) );
		$report_url = admin_url( 'admin.php?page=meyvora-seo-reports' );
		$score_class = $score >= 80 ? 'good' : ( $score >= 50 ? 'okay' : 'poor' );
		$gsc_clicks = isset( $gsc_current['clicks'] ) ? (int) $gsc_current['clicks'] : 0;
		$gsc_impressions = isset( $gsc_current['impressions'] ) ? (int) $gsc_current['impressions'] : 0;
		$prev_clicks = isset( $gsc_prev['clicks'] ) ? (int) $gsc_prev['clicks'] : 0;
		$prev_impressions = isset( $gsc_prev['impressions'] ) ? (int) $gsc_prev['impressions'] : 0;
		$diff_clicks = $gsc_clicks - $prev_clicks;
		$diff_impressions = $gsc_impressions - $prev_impressions;
		ob_start();
		?>
		<!DOCTYPE html>
		<html>
		<head>
			<meta charset="UTF-8">
			<meta name="viewport" content="width=device-width, initial-scale=1.0">
			<title><?php echo esc_html( __( 'Weekly SEO Report', 'meyvora-seo' ) ); ?></title>
		</head>
		<body style="margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,sans-serif;background:#f3f4f6;color:#374151;">
		<div style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;box-shadow:0 4px 6px rgba(0,0,0,0.07);overflow:hidden;">
			<div style="background:linear-gradient(135deg,#7c3aed 0%,#6d28d9 100%);padding:24px;text-align:center;">
				<h1 style="margin:0;font-size:20px;font-weight:600;color:#fff;">Meyvora SEO</h1>
				<p style="margin:8px 0 0;font-size:14px;color:rgba(255,255,255,0.9);"><?php echo esc_html( $site_name ); ?> &mdash; <?php echo esc_html( $date ); ?></p>
			</div>
			<div style="padding:24px;">
				<p style="margin:0 0 16px;font-size:15px;"><?php esc_html_e( 'Your weekly SEO health summary:', 'meyvora-seo' ); ?></p>
				<div style="background:#f9fafb;border-radius:10px;padding:20px;margin-bottom:20px;text-align:center;">
					<div style="font-size:13px;color:#6b7280;margin-bottom:4px;"><?php esc_html_e( 'Overall score', 'meyvora-seo' ); ?></div>
					<div style="font-size:36px;font-weight:700;color:#7c3aed;"><?php echo esc_html( (string) (int) $score ); ?><span style="font-size:18px;color:#9ca3af;">/100</span></div>
				</div>
				<div style="margin-bottom:20px;">
					<p style="margin:0 0 8px;font-size:14px;color:#6b7280;"><?php esc_html_e( 'New issues this week:', 'meyvora-seo' ); ?></p>
					<p style="margin:0;font-size:20px;font-weight:600;color:<?php echo esc_attr( (int) $new_issues > 0 ? '#d97706' : '#059669' ); ?>;"><?php echo esc_html( (string) (int) $new_issues ); ?></p>
				</div>
				<?php if ( $gsc_clicks > 0 || $gsc_impressions > 0 || $prev_clicks > 0 || $prev_impressions > 0 ) : ?>
				<div style="margin-bottom:20px;">
					<p style="margin:0 0 8px;font-size:14px;color:#6b7280;"><?php esc_html_e( 'Search Console (28 days)', 'meyvora-seo' ); ?></p>
					<p style="margin:0;font-size:15px;">
						<?php
						echo esc_html( sprintf(
							/* translators: 1: clicks, 2: impressions */
							__( '%1$s clicks, %2$s impressions', 'meyvora-seo' ),
							number_format_i18n( $gsc_clicks ),
							number_format_i18n( $gsc_impressions )
						) );
						if ( $prev_clicks > 0 || $prev_impressions > 0 ) {
							$parts = array();
							if ( $diff_clicks !== 0 ) {
								$parts[] = ( $diff_clicks > 0 ? '+' : '' ) . number_format_i18n( $diff_clicks ) . ' ' . _n( 'click', 'clicks', abs( $diff_clicks ), 'meyvora-seo' );
							}
							if ( $diff_impressions !== 0 ) {
								$parts[] = ( $diff_impressions > 0 ? '+' : '' ) . number_format_i18n( $diff_impressions ) . ' ' . __( 'impressions', 'meyvora-seo' );
							}
							if ( ! empty( $parts ) ) {
								echo ' <span style="color:#6b7280;">(' . esc_html( __( 'vs last week:', 'meyvora-seo' ) . ' ' . implode( ', ', $parts ) ) . ')</span>';
							}
						}
						?>
					</p>
				</div>
				<?php endif; ?>
				<?php if ( ! empty( $top3_fix ) ) : ?>
				<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;"><?php esc_html_e( 'Top 3 pages to fix', 'meyvora-seo' ); ?></p>
				<ul style="margin:0;padding:0;list-style:none;">
					<?php foreach ( $top3_fix as $item ) : ?>
					<li style="margin-bottom:8px;">
						<a href="<?php echo esc_url( $item['edit'] ?? '#' ); ?>" style="color:#7c3aed;text-decoration:none;font-size:14px;"><?php echo esc_html( $item['title'] ?? __( '(no title)', 'meyvora-seo' ) ); ?></a>
						<span style="color:#9ca3af;font-size:13px;"> &mdash; <?php echo esc_html( (string) (int) ( $item['score'] ?? 0 ) ); ?>/100</span>
					</li>
					<?php endforeach; ?>
				</ul>
				<?php endif; ?>
				<?php
				$ctr_opportunities = array_slice( $ctr_opportunities, 0, 3 );
				if ( ! empty( $ctr_opportunities ) ) :
					?>
				<div style="margin-bottom:20px;">
					<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">📈 <?php esc_html_e( 'Quick Wins — Improve Title/Description for Higher CTR', 'meyvora-seo' ); ?></p>
					<ul style="margin:0;padding:0;list-style:none;font-size:13px;">
						<?php foreach ( $ctr_opportunities as $opp ) : ?>
						<li style="margin-bottom:6px;">
							<a href="<?php echo esc_url( $opp['url'] ?? '#' ); ?>" style="color:#7c3aed;text-decoration:none;word-break:break-all;"><?php echo esc_html( wp_parse_url( $opp['url'] ?? '', PHP_URL_PATH ) ?: ( $opp['url'] ?? '' ) ); ?></a>
							<span style="color:#6b7280;"> &mdash; <?php echo esc_html( sprintf( /* translators: position, impressions, CTR */ __( 'Pos %1$s, %2$s impr, %3$s%% CTR', 'meyvora-seo' ), (string) ( $opp['position'] ?? 0 ), number_format_i18n( (int) ( $opp['impressions'] ?? 0 ) ), (string) ( $opp['ctr'] ?? 0 ) ) ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
				<?php
				$decaying_pages = array_slice( $decaying_pages, 0, 3 );
				if ( ! empty( $decaying_pages ) ) :
					?>
				<div style="margin-bottom:20px;">
					<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">📉 <?php esc_html_e( 'Content Decay — Traffic Dropped 30%+', 'meyvora-seo' ); ?></p>
					<ul style="margin:0;padding:0;list-style:none;font-size:13px;">
						<?php foreach ( $decaying_pages as $dec ) : ?>
						<li style="margin-bottom:6px;">
							<a href="<?php echo esc_url( $dec['url'] ?? '#' ); ?>" style="color:#7c3aed;text-decoration:none;word-break:break-all;"><?php echo esc_html( wp_parse_url( $dec['url'] ?? '', PHP_URL_PATH ) ?: ( $dec['url'] ?? '' ) ); ?></a>
							<span style="color:#6b7280;"> &mdash; <?php echo esc_html( sprintf( /* translators: current clicks, previous clicks, drop percent */ __( '%1$s → %2$s clicks, %3$s%% drop', 'meyvora-seo' ), number_format_i18n( (int) ( $dec['curr'] ?? 0 ) ), number_format_i18n( (int) ( $dec['prev'] ?? 0 ) ), (string) ( $dec['drop_pct'] ?? 0 ) ) ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
				<?php
				$stale_posts = array_slice( $stale_posts, 0, 3 );
				if ( ! empty( $stale_posts ) ) :
					?>
				<div style="margin-bottom:20px;">
					<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#374151;">🗓 <?php esc_html_e( 'Stale Content — Not Updated in 180+ Days', 'meyvora-seo' ); ?></p>
					<ul style="margin:0;padding:0;list-style:none;font-size:13px;">
						<?php foreach ( $stale_posts as $sp ) : ?>
						<li style="margin-bottom:6px;">
							<?php if ( ! empty( $sp['edit_link'] ) ) : ?>
								<a href="<?php echo esc_url( $sp['edit_link'] ); ?>" style="color:#7c3aed;text-decoration:none;"><?php echo esc_html( $sp['title'] ?? __( '(no title)', 'meyvora-seo' ) ); ?></a>
							<?php else : ?>
								<span><?php echo esc_html( $sp['title'] ?? __( '(no title)', 'meyvora-seo' ) ); ?></span>
							<?php endif; ?>
							<span style="color:#6b7280;"> &mdash; <?php echo esc_html( (string) (int) ( $sp['days_old'] ?? 0 ) ); ?> <?php esc_html_e( 'days since update', 'meyvora-seo' ); ?></span>
						</li>
						<?php endforeach; ?>
					</ul>
				</div>
				<?php endif; ?>
				<p style="margin:24px 0 0;padding-top:20px;border-top:1px solid #e5e7eb;">
					<a href="<?php echo esc_url( $report_url ); ?>" style="display:inline-block;background:#7c3aed;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-size:14px;font-weight:500;"><?php esc_html_e( 'View full report', 'meyvora-seo' ); ?></a>
				</p>
			</div>
		</div>
		<p style="max-width:560px;margin:16px auto;text-align:center;font-size:12px;color:#9ca3af;"><?php esc_html_e( 'This email was sent by Meyvora SEO.', 'meyvora-seo' ); ?></p>
		</body>
		</html>
		<?php
		return ob_get_clean();
	}

	/**
	 * If requesting print report or PDF export, output HTML and exit.
	 */
	public function maybe_render_print_report(): void {
		$is_print = isset( $_GET['meyvora_seo_print_report'] ) && $_GET['meyvora_seo_print_report'] === '1';
		$is_pdf  = isset( $_GET['meyvora_seo_export_pdf'] ) && $_GET['meyvora_seo_export_pdf'] === '1';
		if ( ! $is_print && ! $is_pdf ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this report.', 'meyvora-seo' ) );
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'meyvora_seo_print_report' ) ) {
			wp_die( esc_html__( 'Invalid link.', 'meyvora-seo' ) );
		}
		$data   = $this->get_report_data( true );
		wp_enqueue_style(
			'meyvora-report-print',
			MEYVORA_SEO_URL . 'admin/assets/css/meyvora-report-print.css',
			array(),
			MEYVORA_SEO_VERSION
		);
		wp_enqueue_script(
			'meyvora-report-print',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-report-print.js',
			array(),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script(
			'meyvora-report-print',
			'meyvoraReportPrint',
			array(
				'autoPrintPdf'   => $is_pdf,
				'checkPrintQuery' => ! $is_pdf,
			)
		);
		$print_view = MEYVORA_SEO_PATH . 'admin/views/report-print.php';
		if ( file_exists( $print_view ) ) {
			include $print_view;
		} else {
			echo '<!DOCTYPE html><html><body><p>' . esc_html__( 'Report template not found.', 'meyvora-seo' ) . '</p></body></html>';
		}
		exit;
	}

	/**
	 * Get full report data for dashboard, email, or print.
	 *
	 * @param bool $include_posts Whether to load top/bottom post lists (can skip for cron).
	 * @return array{health_score: int, top_10: array, bottom_10: array, issues: array, content_stats: array, trend: array}
	 */
	public function get_report_data( bool $include_posts = true ): array {
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		$q = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => array( 'publish', 'draft', 'pending' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$ids = is_array( $q->posts ) ? $q->posts : array();
		$total = count( $ids );

		$meta_keys = array(
			MEYVORA_SEO_META_SCORE,
			MEYVORA_SEO_META_TITLE,
			MEYVORA_SEO_META_DESCRIPTION,
			MEYVORA_SEO_META_FOCUS_KEYWORD,
			MEYVORA_SEO_META_OG_IMAGE,
			MEYVORA_SEO_META_SCHEMA_TYPE,
			MEYVORA_SEO_META_NOINDEX,
		);
		$scores = $titles = $descs = $keywords = $og_images = $schema = $noindex = array();
		if ( ! empty( $ids ) ) {
			global $wpdb;
			$id_list = implode( ',', array_map( 'intval', $ids ) );
			$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- See file-level.
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_list}) AND meta_key IN ({$placeholders})",
				...$meta_keys
			) );
			foreach ( $rows as $row ) {
				$pid = (int) $row->post_id;
				switch ( $row->meta_key ) {
					case MEYVORA_SEO_META_SCORE:
						$scores[ $pid ] = (int) $row->meta_value;
						break;
					case MEYVORA_SEO_META_TITLE:
						$titles[ $pid ] = $row->meta_value;
						break;
					case MEYVORA_SEO_META_DESCRIPTION:
						$descs[ $pid ] = $row->meta_value;
						break;
					case MEYVORA_SEO_META_FOCUS_KEYWORD:
						$keywords[ $pid ] = $row->meta_value;
						break;
					case MEYVORA_SEO_META_OG_IMAGE:
						$og_images[ $pid ] = $row->meta_value;
						break;
					case MEYVORA_SEO_META_SCHEMA_TYPE:
						$schema[ $pid ] = $row->meta_value;
						break;
					case MEYVORA_SEO_META_NOINDEX:
						$noindex[ $pid ] = $row->meta_value;
						break;
				}
			}
		}

		$score_sum = 0;
		$with_score = 0;
		$missing_title = 0;
		$missing_description = 0;
		$low_score = 0;
		$missing_schema = 0;
		$total_with_keyword = 0;
		$total_with_og_image = 0;
		$total_indexed = 0;
		$low_threshold = 50;

		foreach ( $ids as $pid ) {
			$noindex_val = isset( $noindex[ $pid ] ) ? $noindex[ $pid ] : '';
			$is_indexed = ( $noindex_val === '' || $noindex_val === '0' || $noindex_val === false );
			if ( $is_indexed ) {
				$total_indexed++;
			}
			if ( isset( $keywords[ $pid ] ) && trim( (string) $keywords[ $pid ] ) !== '' ) {
				$total_with_keyword++;
			}
			if ( isset( $og_images[ $pid ] ) && trim( (string) $og_images[ $pid ] ) !== '' ) {
				$total_with_og_image++;
			}
			if ( ! isset( $titles[ $pid ] ) || trim( (string) $titles[ $pid ] ) === '' ) {
				$missing_title++;
			}
			if ( ! isset( $descs[ $pid ] ) || trim( (string) $descs[ $pid ] ) === '' ) {
				$missing_description++;
			}
			$s = isset( $scores[ $pid ] ) ? $scores[ $pid ] : null;
			if ( $s !== null ) {
				$with_score++;
				$score_sum += $s;
				if ( $s < $low_threshold ) {
					$low_score++;
				}
			}
			$schema_val = isset( $schema[ $pid ] ) ? trim( (string) $schema[ $pid ] ) : '';
			if ( $schema_val === '' || strtolower( $schema_val ) === 'none' ) {
				$missing_schema++;
			}
		}

		$health_score = $with_score > 0 ? (int) round( $score_sum / $with_score ) : 0;
		$issues = array(
			'missing_title'       => $missing_title,
			'missing_description' => $missing_description,
			'low_score'           => $low_score,
			'missing_schema'      => $missing_schema,
		);
		$content_stats = array(
			'total_posts'           => $total,
			'total_indexed'         => $total_indexed,
			'total_with_focus_kw'   => $total_with_keyword,
			'total_with_og_image'   => $total_with_og_image,
		);

		$top_10 = array();
		$bottom_10 = array();
		if ( $include_posts && ! empty( $ids ) ) {
			$with_scores = array();
			foreach ( $ids as $pid ) {
				$with_scores[ $pid ] = isset( $scores[ $pid ] ) ? $scores[ $pid ] : 0;
			}
			arsort( $with_scores, SORT_NUMERIC );
			$top_ids = array_slice( array_keys( $with_scores ), 0, 10, true );
			asort( $with_scores, SORT_NUMERIC );
			$bottom_ids = array_slice( array_keys( $with_scores ), 0, 10, true );
			foreach ( $top_ids as $pid ) {
				$top_10[] = array(
					'id'    => $pid,
					'title' => get_the_title( $pid ) ?: __( '(no title)', 'meyvora-seo' ),
					'score' => $with_scores[ $pid ],
					'edit'  => get_edit_post_link( $pid, 'raw' ) ?: '#',
				);
			}
			foreach ( $bottom_ids as $pid ) {
				$bottom_10[] = array(
					'id'    => $pid,
					'title' => get_the_title( $pid ) ?: __( '(no title)', 'meyvora-seo' ),
					'score' => $with_scores[ $pid ],
					'edit'  => get_edit_post_link( $pid, 'raw' ) ?: '#',
				);
			}

			// Attach GSC metrics per post when connected (cached 6 hours per post).
			if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
				$gsc_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php' : '';
				if ( $gsc_file && file_exists( $gsc_file ) ) {
					require_once $gsc_file;
				}
				if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
					$gsc = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
					if ( $gsc->is_connected() ) {
						$all_report_ids = array_unique( array_merge( $top_ids, $bottom_ids ) );
						$gsc_cache_ttl = 6 * HOUR_IN_SECONDS;
						foreach ( $all_report_ids as $pid ) {
							$cache_key = 'meyvora_reports_gsc_' . $pid;
							$cached = get_transient( $cache_key );
							if ( is_array( $cached ) && isset( $cached['clicks'], $cached['impressions'] ) ) {
								$gsc_row = $cached;
							} else {
								$permalink = get_permalink( $pid );
								if ( $permalink && get_post_status( $pid ) === 'publish' ) {
									$gsc_row = $gsc->get_metrics_for_page( $permalink );
									set_transient( $cache_key, $gsc_row, $gsc_cache_ttl );
								} else {
									$gsc_row = array( 'clicks' => 0, 'impressions' => 0, 'position' => 0.0 );
								}
							}
							foreach ( $top_10 as &$row ) {
								if ( (int) ( $row['id'] ?? 0 ) === (int) $pid ) {
									$row['gsc'] = $gsc_row;
									break;
								}
							}
							unset( $row );
							foreach ( $bottom_10 as &$row ) {
								if ( (int) ( $row['id'] ?? 0 ) === (int) $pid ) {
									$row['gsc'] = $gsc_row;
									break;
								}
							}
							unset( $row );
						}
					}
				}
			}
		}

		$snapshots = get_option( MEYVORA_SEO_WEEKLY_SNAPSHOTS_OPTION, array() );
		$trend = is_array( $snapshots ) ? array_slice( array_values( $snapshots ), -12 ) : array();

		$gsc_connected = false;
		$gsc_site_summary = array( 'clicks' => 0, 'impressions' => 0 );
		$decaying_pages = array();
		$dash = array();
		if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
			$gsc_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php' : '';
			if ( $gsc_file && file_exists( $gsc_file ) ) {
				require_once $gsc_file;
			}
			if ( class_exists( 'Meyvora_SEO_GSC' ) ) {
				$gsc = new Meyvora_SEO_GSC( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
				if ( $gsc->is_connected() ) {
					$gsc_connected = true;
					$dash = $gsc->get_dashboard_data();
					$totals = isset( $dash['totals'] ) && is_array( $dash['totals'] ) ? $dash['totals'] : array();
					$gsc_site_summary = array(
						'clicks'      => isset( $totals['clicks'] ) ? (int) $totals['clicks'] : 0,
						'impressions' => isset( $totals['impressions'] ) ? (int) $totals['impressions'] : 0,
					);
					$decaying_pages = $gsc->get_decaying_pages( 10 );
				}
			}
		}

		$gsc_top_queries   = array();
		$gsc_top_pages     = array();
		$gsc_opportunities = array();
		if ( $gsc_connected && isset( $dash['top_queries'] ) && is_array( $dash['top_queries'] ) ) {
			$gsc_top_queries = array_slice( $dash['top_queries'], 0, 5 );
		}
		if ( $gsc_connected && isset( $dash['top_pages'] ) && is_array( $dash['top_pages'] ) ) {
			$gsc_top_pages = array_slice( $dash['top_pages'], 0, 5 );
		}
		if ( $gsc_connected && isset( $gsc ) ) {
			$gsc_opportunities = $gsc->get_ctr_opportunities( 5 );
		}

		return array(
			'health_score'     => $health_score,
			'top_10'           => $top_10,
			'bottom_10'        => $bottom_10,
			'issues'           => $issues,
			'content_stats'    => $content_stats,
			'trend'            => $trend,
			'gsc_connected'    => $gsc_connected,
			'gsc_site_summary' => $gsc_site_summary,
			'gsc_top_queries'   => $gsc_top_queries,
			'gsc_top_pages'     => $gsc_top_pages,
			'gsc_opportunities' => $gsc_opportunities,
			'decaying_pages'    => $decaying_pages,
		);
	}

	/**
	 * Render the Reports dashboard page.
	 */
	public function render_reports_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$data = $this->get_report_data( true );
		$print_url = add_query_arg( array(
			'meyvora_seo_print_report' => '1',
			'_wpnonce'                 => wp_create_nonce( 'meyvora_seo_print_report' ),
		), admin_url( 'index.php' ) );
		$export_pdf_url = add_query_arg( array(
			'meyvora_seo_export_pdf' => '1',
			'_wpnonce'               => wp_create_nonce( 'meyvora_seo_print_report' ),
		), admin_url( 'admin.php?page=meyvora-seo-reports' ) );
		$view = MEYVORA_SEO_PATH . 'admin/views/reports.php';
		if ( file_exists( $view ) ) {
			include $view;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Reports', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'Reports view not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}
}
