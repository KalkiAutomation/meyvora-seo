<?php
/**
 * Network admin dashboard: SEO overview across all sites (Multisite).
 * Only loads when is_multisite() is true. Uses cached data; refresh via AJAX.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Network
 */
class Meyvora_SEO_Network {

	const PAGE_SLUG    = 'meyvora-seo-network';
	const CACHE_OPTION = 'meyvora_seo_network_cache';
	const CACHE_TTL    = HOUR_IN_SECONDS;

	const OPTION_AUDIT_SUMMARY  = 'meyvora_seo_audit_summary';
	const OPTION_AUDIT_LAST_RUN = 'meyvora_seo_audit_last_run';
	const OPTION_AUDIT_POSTS    = 'meyvora_seo_audit_posts_meta';

	/**
	 * Register network admin menu (only for users with manage_network).
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'SEO Network', 'meyvora-seo' ),
			__( 'SEO Network', 'meyvora-seo' ),
			'manage_network',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			'dashicons-chart-area',
			30
		);
		add_action( 'network_admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 10, 1 );
		add_action( 'wp_ajax_meyvora_seo_network_refresh', array( $this, 'ajax_refresh' ) );
	}

	/**
	 * Enqueue CSS on the network dashboard page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'toplevel_page_' . self::PAGE_SLUG ) {
			return;
		}
		if ( ! current_user_can( 'manage_network' ) ) {
			return;
		}
		$css_path = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-admin.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'meyvora-seo-admin',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css',
				array(),
				MEYVORA_SEO_VERSION
			);
		}
		$js_path = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-network-dashboard.js';
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'meyvora-seo-network',
				MEYVORA_SEO_URL . 'admin/assets/js/meyvora-network-dashboard.js',
				array(),
				MEYVORA_SEO_VERSION,
				true
			);
			wp_localize_script(
				'meyvora-seo-network',
				'meyvoraSeoNetwork',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'meyvora_seo_network_refresh' ),
					'i18n'    => array(
						'refreshing' => __( 'Refreshing…', 'meyvora-seo' ),
						'refresh'    => __( 'Refresh Data', 'meyvora-seo' ),
					),
				)
			);
		}
	}

	/**
	 * Get aggregated network data (sites with health score, issues, GSC, etc.). Uses cache unless forced.
	 *
	 * @param bool $force Bypass cache and re-scan all sites.
	 * @return array{sites: array, cached_at: int}
	 */
	public function get_network_data( bool $force = false ): array {
		$cached = get_site_option( self::CACHE_OPTION, array() );
		$now    = time();
		if ( ! $force && is_array( $cached ) && isset( $cached['cached_at'] ) && ( $now - (int) $cached['cached_at'] ) < self::CACHE_TTL && isset( $cached['sites'] ) ) {
			return array(
				'sites'     => is_array( $cached['sites'] ) ? $cached['sites'] : array(),
				'cached_at' => (int) $cached['cached_at'],
			);
		}

		$sites = array();
		$list  = get_sites( array( 'number' => 100 ) );
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		foreach ( $list as $site ) {
			$blog_id = (int) $site->blog_id;
			switch_to_blog( $blog_id );

			$plugin_active = get_option( MEYVORA_SEO_OPTION_KEY, false ) !== false;
			$blog_name     = get_bloginfo( 'name' ) ?: __( '(no title)', 'meyvora-seo' );
			$blog_url      = get_site_url( $blog_id );
			$admin_url     = get_admin_url( $blog_id );

			if ( ! $plugin_active ) {
				$sites[] = array(
					'blog_id'       => $blog_id,
					'blog_name'     => $blog_name,
					'blog_url'      => $blog_url,
					'admin_url'     => $admin_url,
					'report_url'    => add_query_arg( 'page', 'meyvora-seo-reports', $admin_url . 'admin.php' ),
					'audit_url'     => add_query_arg( 'page', 'meyvora-seo-audit', $admin_url . 'admin.php' ),
					'plugin_active' => false,
					'health_score'  => null,
					'issue_count'   => 0,
					'gsc_connected' => false,
					'post_count'    => 0,
					'last_audit_ts' => 0,
				);
				restore_current_blog();
				continue;
			}

			$summary    = get_option( self::OPTION_AUDIT_SUMMARY, array() );
			$last_run   = (int) get_option( self::OPTION_AUDIT_LAST_RUN, 0 );
			$gsc_token  = get_option( MEYVORA_SEO_GSC_REFRESH_TOKEN_OPTION, '' );
			$gsc_connected = is_string( $gsc_token ) && $gsc_token !== '';

			$health_score = null;
			$issue_count  = 0;
			$post_count   = 0;

			if ( is_array( $summary ) && isset( $summary['total_issues'] ) ) {
				$issue_count = (int) $summary['total_issues'];
				$posts_meta  = get_option( self::OPTION_AUDIT_POSTS, array() );
				if ( is_array( $posts_meta ) && ! empty( $posts_meta ) ) {
					$sum   = 0;
					$n     = 0;
					foreach ( $posts_meta as $row ) {
						if ( isset( $row['seo_score'] ) && is_numeric( $row['seo_score'] ) ) {
							$sum += (int) $row['seo_score'];
							$n++;
						}
					}
					$health_score = $n > 0 ? (int) round( $sum / $n ) : 0;
				} else {
					$total_posts = isset( $summary['total_posts'] ) ? (int) $summary['total_posts'] : 0;
					$health_score = $total_posts > 0 ? max( 0, 100 - min( 100, (int) round( ( $issue_count / $total_posts ) * 20 ) ) ) : 100;
				}
			} else {
				// No cached audit summary: quick score from post meta.
				$post_types = array( 'post', 'page' );
				$post_ids   = get_posts( array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				) );
				$post_count = is_array( $post_ids ) ? count( $post_ids ) : 0;
				if ( $post_count > 0 ) {
					global $wpdb;
					$id_list    = implode( ',', array_map( 'intval', $post_ids ) );
					$meta_key  = MEYVORA_SEO_META_SCORE;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table, safe literal meta key, id_list intval'd.
					$scores     = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_list}) AND meta_key = '{$meta_key}' AND meta_value != ''" );
					if ( is_array( $scores ) && ! empty( $scores ) ) {
						$sum = 0;
						foreach ( $scores as $v ) {
							$sum += (int) $v;
						}
						$health_score = (int) round( $sum / count( $scores ) );
					} else {
						$health_score = 0;
					}
				} else {
					$health_score = 0;
				}
			}

			if ( $post_count === 0 && isset( $summary['total_posts'] ) ) {
				$post_count = (int) $summary['total_posts'];
			}
			if ( $post_count === 0 ) {
				$c = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) );
				$post_count = is_array( $c ) ? count( $c ) : 0;
			}

			$sites[] = array(
				'blog_id'       => $blog_id,
				'blog_name'     => $blog_name,
				'blog_url'      => $blog_url,
				'admin_url'     => $admin_url,
				'report_url'    => add_query_arg( 'page', 'meyvora-seo-reports', $admin_url . 'admin.php' ),
				'audit_url'     => add_query_arg( 'page', 'meyvora-seo-audit', $admin_url . 'admin.php' ),
				'plugin_active' => true,
				'health_score'  => $health_score,
				'issue_count'   => $issue_count,
				'gsc_connected' => $gsc_connected,
				'post_count'    => $post_count,
				'last_audit_ts' => $last_run,
			);

			restore_current_blog();
		}

		$payload = array(
			'sites'     => $sites,
			'cached_at' => $now,
		);
		update_site_option( self::CACHE_OPTION, $payload );

		return $payload;
	}

	/**
	 * AJAX: Refresh network data (force re-scan). Requires manage_network.
	 */
	public function ajax_refresh(): void {
		check_ajax_referer( 'meyvora_seo_network_refresh', 'nonce' );
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$data = $this->get_network_data( true );
		wp_send_json_success( array(
			'sites'     => $data['sites'],
			'cached_at' => $data['cached_at'],
		) );
	}

	/**
	 * Render the network dashboard page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'meyvora-seo' ) );
		}

		$data     = $this->get_network_data( false );
		$sites    = $data['sites'];
		$cached_at = $data['cached_at'];

		$sites_managed = 0;
		$score_sum     = 0;
		$score_count   = 0;
		$total_issues  = 0;
		foreach ( $sites as $row ) {
			if ( ! empty( $row['plugin_active'] ) ) {
				$sites_managed++;
				if ( $row['health_score'] !== null ) {
					$score_sum += $row['health_score'];
					$score_count++;
				}
				$total_issues += (int) ( $row['issue_count'] ?? 0 );
			}
		}
		$avg_health = $score_count > 0 ? (int) round( $score_sum / $score_count ) : 0;

		// Sort by health score ascending (worst first).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only sort parameter, validated against allowlist.
		$sort_by = isset( $_GET['sort'] ) ? sanitize_text_field( wp_unslash( $_GET['sort'] ) ) : 'health';
		$allowed_sort = array( 'health', 'issues', 'name' );
		if ( ! in_array( $sort_by, $allowed_sort, true ) ) {
			$sort_by = 'health';
		}
		$sorted = $sites;
		usort( $sorted, function ( $a, $b ) use ( $sort_by ) {
			if ( $sort_by === 'health' ) {
				$ha = $a['health_score'] !== null ? $a['health_score'] : -1;
				$hb = $b['health_score'] !== null ? $b['health_score'] : -1;
				return $ha <=> $hb;
			}
			if ( $sort_by === 'issues' ) {
				return ( (int) ( $a['issue_count'] ?? 0 ) ) <=> ( (int) ( $b['issue_count'] ?? 0 ) );
			}
			return strcasecmp( (string) ( $a['blog_name'] ?? '' ), (string) ( $b['blog_name'] ?? '' ) );
		} );

		$page_url = network_admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap meyvora-network-dashboard" style="background: var(--mev-surface, #fff); border: 1px solid var(--mev-border, #e5e7eb); border-radius: var(--mev-radius, 10px); padding: 24px; margin: 20px 20px 20px 0; box-shadow: var(--mev-shadow, 0 1px 3px rgba(0,0,0,0.1));">
			<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
				<h1 style="margin: 0; font-size: 1.5rem;"><?php esc_html_e( 'SEO Network Overview', 'meyvora-seo' ); ?></h1>
				<div>
					<button type="button" id="meyvora-network-refresh" class="button button-primary">
						<?php esc_html_e( 'Refresh Data', 'meyvora-seo' ); ?>
					</button>
					<span id="meyvora-network-cached" style="margin-left: 10px; color: var(--mev-gray-500, #6b7280); font-size: 13px;">
						<?php
						if ( $cached_at > 0 ) {
							/* translators: %s: human-readable time ago */
							echo esc_html( sprintf( __( 'Cached %s', 'meyvora-seo' ), human_time_diff( $cached_at, time() ) . ' ' . __( 'ago', 'meyvora-seo' ) ) );
						}
						?>
					</span>
				</div>
			</div>

			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px;">
				<div style="background: var(--mev-surface-2, #f9fafb); border: 1px solid var(--mev-border); border-radius: var(--mev-radius-sm, 6px); padding: 16px;">
					<div style="font-size: 12px; color: var(--mev-gray-500); text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e( 'Sites managed', 'meyvora-seo' ); ?></div>
					<div style="font-size: 28px; font-weight: 700; color: var(--mev-gray-800, #1f2937);"><?php echo (int) $sites_managed; ?></div>
				</div>
				<div style="background: var(--mev-surface-2); border: 1px solid var(--mev-border); border-radius: var(--mev-radius-sm); padding: 16px;">
					<div style="font-size: 12px; color: var(--mev-gray-500); text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e( 'Average health score', 'meyvora-seo' ); ?></div>
					<div style="font-size: 28px; font-weight: 700; color: var(--mev-primary, #7c3aed);"><?php echo (int) $avg_health; ?></div>
				</div>
				<div style="background: var(--mev-surface-2); border: 1px solid var(--mev-border); border-radius: var(--mev-radius-sm); padding: 16px;">
					<div style="font-size: 12px; color: var(--mev-gray-500); text-transform: uppercase; letter-spacing: 0.05em;"><?php esc_html_e( 'Total open issues', 'meyvora-seo' ); ?></div>
					<div style="font-size: 28px; font-weight: 700; color: var(--mev-danger, #dc2626);"><?php echo (int) $total_issues; ?></div>
				</div>
			</div>

			<div style="margin-bottom: 12px;">
				<label for="meyvora-network-sort" style="margin-right: 8px;"><?php esc_html_e( 'Sort by', 'meyvora-seo' ); ?></label>
				<select id="meyvora-network-sort" style="padding: 6px 10px;">
					<option value="health" <?php selected( $sort_by, 'health' ); ?>><?php esc_html_e( 'Health score (worst first)', 'meyvora-seo' ); ?></option>
					<option value="issues" <?php selected( $sort_by, 'issues' ); ?>><?php esc_html_e( 'Issues count', 'meyvora-seo' ); ?></option>
					<option value="name" <?php selected( $sort_by, 'name' ); ?>><?php esc_html_e( 'Site name', 'meyvora-seo' ); ?></option>
				</select>
			</div>

			<table class="wp-list-table widefat fixed striped" style="border: 1px solid var(--mev-border); border-radius: var(--mev-radius-sm);">
				<thead>
					<tr>
						<th style="width: 22%;"><?php esc_html_e( 'Site', 'meyvora-seo' ); ?></th>
						<th style="width: 12%;"><?php esc_html_e( 'Health score', 'meyvora-seo' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Issues', 'meyvora-seo' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'GSC', 'meyvora-seo' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Posts', 'meyvora-seo' ); ?></th>
						<th style="width: 14%;"><?php esc_html_e( 'Last audit', 'meyvora-seo' ); ?></th>
						<th style="width: 22%;"><?php esc_html_e( 'Links', 'meyvora-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sorted as $row ) : ?>
						<tr>
							<td>
								<?php if ( ! empty( $row['plugin_active'] ) ) : ?>
									<strong><?php echo esc_html( $row['blog_name'] ); ?></strong><br>
									<a href="<?php echo esc_url( $row['blog_url'] ); ?>" target="_blank" rel="noopener" style="font-size: 12px; color: var(--mev-gray-500);"><?php echo esc_html( $row['blog_url'] ); ?></a>
								<?php else : ?>
									<span style="color: var(--mev-gray-400, #9ca3af);"><?php echo esc_html( $row['blog_name'] ); ?></span><br>
									<span style="font-size: 12px; color: var(--mev-gray-400);"><?php esc_html_e( 'Plugin not active', 'meyvora-seo' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php
								if ( ! empty( $row['plugin_active'] ) && $row['health_score'] !== null ) {
									$score = (int) $row['health_score'];
									$pill_style = 'display: inline-block; padding: 4px 10px; border-radius: var(--mev-radius-full, 9999px); font-weight: 600; font-size: 13px;';
									if ( $score >= 80 ) {
										$pill_style .= ' background: var(--mev-success-light, #d1fae5); color: var(--mev-success, #059669);';
									} elseif ( $score >= 50 ) {
										$pill_style .= ' background: var(--mev-warning-light, #fef3c7); color: var(--mev-warning, #d97706);';
									} else {
										$pill_style .= ' background: var(--mev-danger-light, #fee2e2); color: var(--mev-danger, #dc2626);';
									}
									echo '<span style="' . esc_attr( $pill_style ) . '">' . (int) $score . '</span>';
								} else {
									echo '—';
								}
								?>
							</td>
							<td><?php echo ! empty( $row['plugin_active'] ) ? (int) $row['issue_count'] : '—'; ?></td>
							<td><?php echo ! empty( $row['plugin_active'] ) && ! empty( $row['gsc_connected'] ) ? '✓' : '✗'; ?></td>
							<td><?php echo ! empty( $row['plugin_active'] ) ? (int) $row['post_count'] : '—'; ?></td>
							<td>
								<?php
								if ( ! empty( $row['plugin_active'] ) && ! empty( $row['last_audit_ts'] ) ) {
									echo esc_html( human_time_diff( (int) $row['last_audit_ts'], time() ) . ' ' . __( 'ago', 'meyvora-seo' ) );
								} else {
									echo '—';
								}
								?>
							</td>
							<td>
								<?php if ( ! empty( $row['plugin_active'] ) && ! empty( $row['report_url'] ) ) : ?>
									<a href="<?php echo esc_url( $row['report_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Report', 'meyvora-seo' ); ?></a>
									| <a href="<?php echo esc_url( $row['audit_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View Audit', 'meyvora-seo' ); ?></a>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
