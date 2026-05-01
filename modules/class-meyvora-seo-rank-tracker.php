<?php
/**
 * Rank Tracker: GSC position history per focus keyword, admin UI, cron, per-post sparkline.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification.Recommended, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Custom table; $where built from validated post_type; AJAX nonce in handler.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Rank_Tracker
 */
class Meyvora_SEO_Rank_Tracker {

	const CRON_HOOK       = 'meyvora_seo_rank_tracker_daily';
	const AJAX_RUN        = 'meyvora_seo_rank_tracker_run';
	const NONCE_ACTION    = 'meyvora_seo_rank_tracker';
	const PAGE_SLUG       = 'meyvora-seo-rank-tracker';
	const PER_PAGE        = 50;
	const SPARKLINE_DAYS  = 30;

	/** @var Meyvora_SEO_Loader */
	protected Meyvora_SEO_Loader $loader;

	/** @var Meyvora_SEO_Options */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_track' ) );
		add_action( 'meyvora_seo_rank_history_cleanup', array( $this, 'clean_old_rank_history' ) );
		add_filter(
			'cron_schedules',
			function ( array $schedules ): array {
				if ( ! isset( $schedules['monthly'] ) ) {
					$schedules['monthly'] = array(
						'interval' => 30 * DAY_IN_SECONDS,
						'display'  => __( 'Once a month', 'meyvora-seo' ),
					);
				}
				return $schedules;
			}
		);
		if ( ! wp_next_scheduled( 'meyvora_seo_rank_history_cleanup' ) ) {
			wp_schedule_event( time(), 'monthly', 'meyvora_seo_rank_history_cleanup' );
		}
		$this->loader->add_action( 'init', $this, 'schedule_cron', 25 );
		if ( is_admin() ) {
			$this->loader->add_action( 'admin_menu', $this, 'register_menu', 15, 0 );
			$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
			$this->loader->add_action( 'add_meta_boxes', $this, 'add_rank_meta_box', 10, 0 );
			add_action( 'wp_ajax_' . self::AJAX_RUN, array( $this, 'ajax_run_track' ) );
			add_action( 'wp_ajax_meyvora_seo_rank_tracker_manual_run', array( $this, 'ajax_manual_run' ) );
			add_action( 'wp_ajax_meyvora_seo_rank_history_post', array( $this, 'ajax_rank_history_post' ) );
		}
	}

	public function schedule_cron(): void {
		if ( ! $this->options->is_enabled( 'rank_tracker_enabled' ) ) {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'daily', self::CRON_HOOK );
			}
			return;
		}
		if ( ! $this->is_gsc_connected() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Cron: fetch GSC positions for posts with focus keyword and insert into rank_history.
	 */
	public function run_track(): void {
		if ( ! $this->options->is_enabled( 'rank_tracker_enabled' ) || ! $this->is_gsc_connected() ) {
			return;
		}
		$limit = (int) $this->options->get( 'rank_tracker_posts_per_run', 100 );
		$posts = $this->get_posts_with_focus_keyword( $limit );
		$gsc   = new Meyvora_SEO_GSC( $this->loader, $this->options );
		$today = gmdate( 'Y-m-d' );
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		$now   = current_time( 'mysql' );
		foreach ( $posts as $post ) {
			$url    = get_permalink( $post->ID );
			$keyword = get_post_meta( $post->ID, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
			if ( ! is_string( $keyword ) || trim( $keyword ) === '' ) {
				continue;
			}
			$keyword = trim( $keyword );
			$position = $gsc->get_position_for_page_and_query( $url, $keyword, 7 );
			if ( $position === null ) {
				continue;
			}
			// Previous serp_feature for this post+keyword (for change alert).
			$old_serp_feature = $this->get_previous_serp_feature( $post->ID, $keyword );
			$wpdb->insert(
				$table,
				array(
					'keyword'   => $keyword,
					'position'  => round( $position, 1 ),
					'date'      => $today,
					'post_id'   => $post->ID,
					'created_at' => $now,
				),
				array( '%s', '%f', '%s', '%d', '%s' )
			);
			// Detect SERP feature and update the row we just inserted (only for position <= 5 to save GSC quota).
			$serp_feature_str = '';
			if ( $position <= 5 ) {
				$serp_features = array();
				if ( $position <= 1.5 ) {
					$serp_features[] = 'FEATURED_SNIPPET';
				}
				$appearance = $gsc->get_search_appearance_for_page( $url );
				$serp_features = array_unique( array_merge( $serp_features, $appearance ) );
				$serp_feature_str = implode( ',', array_filter( $serp_features ) );
			}
			$wpdb->update(
				$table,
				array( 'serp_feature' => $serp_feature_str ),
				array( 'id' => $wpdb->insert_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( $old_serp_feature !== $serp_feature_str ) {
				do_action( 'meyvora_seo_serp_feature_changed', $post->ID, $keyword, $old_serp_feature, $serp_feature_str );
			}
			// Keep only the most recent 365 history rows per post+keyword pair.
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND keyword = %s
				AND id NOT IN (
					SELECT id FROM (
						SELECT id FROM {$table}
						WHERE post_id = %d AND keyword = %s
						ORDER BY date DESC LIMIT 365
					) AS recent
				)",
				$post->ID,
				$keyword,
				$post->ID,
				$keyword
			) );

			// Track secondary keywords (positions 1–4 in focus keyword JSON array).
			$all_kws = Meyvora_SEO_Analyzer::normalize_focus_keywords(
				get_post_meta( $post->ID, MEYVORA_SEO_META_FOCUS_KEYWORD, true )
			);
			$secondary_kws = array_slice( $all_kws, 1 );
			foreach ( $secondary_kws as $sec_kw ) {
				$sec_position = $gsc->get_position_for_page_and_query( $url, $sec_kw, 7 );
				if ( $sec_position === null ) {
					continue;
				}
				$wpdb->insert(
					$table,
					array(
						'keyword'   => $sec_kw,
						'position'  => round( $sec_position, 1 ),
						'date'      => $today,
						'post_id'   => $post->ID,
						'created_at' => $now,
					),
					array( '%s', '%f', '%s', '%d', '%s' )
				);
				// Per-pair 365-row trim (same as primary keyword).
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$table} WHERE post_id = %d AND keyword = %s
					AND id NOT IN (
						SELECT id FROM (
							SELECT id FROM {$table}
							WHERE post_id = %d AND keyword = %s
							ORDER BY date DESC LIMIT 365
						) AS recent
					)",
					$post->ID,
					$sec_kw,
					$post->ID,
					$sec_kw
				) );
			}
		}
	}

	/**
	 * Cron: delete rank history rows older than 400 days.
	 */
	public function clean_old_rank_history(): void {
		global $wpdb;
		$table  = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		$cutoff = gmdate( 'Y-m-d', strtotime( '-400 days' ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE date < %s",
			$cutoff
		) );
	}

	/**
	 * Get posts that have a focus keyword set.
	 *
	 * @param int $limit Max number of posts.
	 * @return array<WP_Post>
	 */
	protected function get_posts_with_focus_keyword( int $limit ): array {
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		$query = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => MEYVORA_SEO_META_FOCUS_KEYWORD,
					'value'   => '',
					'compare' => '!=',
				),
			),
		) );
		$ids = $query->posts;
		if ( empty( $ids ) ) {
			return array();
		}
		$posts = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( $p ) {
				$posts[] = $p;
			}
		}
		return $posts;
	}

	protected function is_gsc_connected(): bool {
		$gsc = new Meyvora_SEO_GSC( $this->loader, $this->options );
		return $gsc->is_connected();
	}

	public function register_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Rank Tracker', 'meyvora-seo' ),
			__( 'Rank Tracker', 'meyvora-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix === 'meyvora-seo_page_' . self::PAGE_SLUG ) {
			wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
			wp_enqueue_script(
				'meyvora-rank-tracker-page',
				MEYVORA_SEO_URL . 'admin/assets/js/meyvora-rank-tracker-page.js',
				array(),
				MEYVORA_SEO_VERSION,
				true
			);
			wp_localize_script(
				'meyvora-rank-tracker-page',
				'meyvoraRankTrackerPage',
				array(
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'runAction'       => self::AJAX_RUN,
					'runNonce'       => wp_create_nonce( self::NONCE_ACTION ),
					'manualRunAction' => 'meyvora_seo_rank_tracker_manual_run',
					'i18n'            => array(
						'running'    => __( 'Running…', 'meyvora-seo' ),
						'done'       => __( 'Done', 'meyvora-seo' ),
						'trackNow'   => __( 'Track Now', 'meyvora-seo' ),
						'runNow'     => __( 'Run Now', 'meyvora-seo' ),
						'runComplete' => __( 'Rank tracking run complete.', 'meyvora-seo' ),
						'error'      => __( 'Error', 'meyvora-seo' ),
					),
				)
			);
		}
		if ( ! $this->is_gsc_connected() ) {
			return;
		}
		if ( $hook_suffix === 'post.php' || $hook_suffix === 'post-new.php' ) {
			wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
			wp_enqueue_script(
				'meyvora-rank-history',
				MEYVORA_SEO_URL . 'admin/assets/js/meyvora-rank-history.js',
				array(),
				MEYVORA_SEO_VERSION,
				true
			);
		}
	}

	public function render_page(): void {
		if ( ! $this->is_gsc_connected() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Rank Tracker', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'Connect Google Search Console in Settings → Integrations to use Rank Tracker.', 'meyvora-seo' ) . '</p></div>';
			return;
		}
		$post_type_filter = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : '';
		$paged            = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$rows             = $this->get_tracked_keywords( $post_type_filter, $paged );
		$total            = $rows['total'];
		$pages            = $rows['pages'];
		$items            = $rows['items'];
		$post_types       = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		?>
		<div class="wrap meyvora-rank-tracker meyvora-page-luxury">
			<div class="mev-page-header mev-page-header--luxury">
				<h1 class="mev-page-title"><?php esc_html_e( 'Rank Tracker', 'meyvora-seo' ); ?></h1>
				<p class="mev-page-subtitle"><?php esc_html_e( 'Keyword positions from Google Search Console', 'meyvora-seo' ); ?></p>
				<button type="button" class="button button-primary mev-rank-track-now" id="mev-rank-track-now"><?php esc_html_e( 'Track Now', 'meyvora-seo' ); ?></button>
				<button type="button" class="button" id="meyvora-rank-tracker-run-now"
					data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
					data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
					<?php esc_html_e( 'Run Now', 'meyvora-seo' ); ?>
				</button>
			</div>
			<div class="mev-card">
				<div class="mev-card-body">
					<form method="get" action="">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
						<label for="mev-filter-pt"><?php esc_html_e( 'Post type:', 'meyvora-seo' ); ?></label>
						<select name="post_type" id="mev-filter-pt">
							<option value=""><?php esc_html_e( 'All', 'meyvora-seo' ); ?></option>
							<?php foreach ( $post_types as $pt ) : ?>
								<option value="<?php echo esc_attr( $pt ); ?>" <?php selected( $post_type_filter, $pt ); ?>><?php echo esc_html( $pt ); ?></option>
							<?php endforeach; ?>
						</select>
						<button type="submit" class="button"><?php esc_html_e( 'Filter', 'meyvora-seo' ); ?></button>
					</form>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Keyword', 'meyvora-seo' ); ?></th>
								<th><?php esc_html_e( 'Post Title', 'meyvora-seo' ); ?></th>
								<th><?php esc_html_e( 'Current Position', 'meyvora-seo' ); ?></th>
								<th><?php esc_html_e( 'SERP Feature', 'meyvora-seo' ); ?></th>
								<th><?php esc_html_e( '7-day change', 'meyvora-seo' ); ?></th>
								<th><?php esc_html_e( '30-day trend', 'meyvora-seo' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $items as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['keyword'] ); ?></td>
									<td><a href="<?php echo esc_url( get_edit_post_link( $row['post_id'], 'raw' ) ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a></td>
									<td><?php echo esc_html( $row['current_position'] ); ?></td>
									<td><?php echo $this->render_serp_feature_badges( $row['serp_feature'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_serp_feature_badges() returns hardcoded HTML with all dynamic values escaped via esc_html()/esc_attr() internally.
									?></td>
									<td><?php echo $row['change_7']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_7day_change() returns only: '&mdash;', hardcoded '<span>' with esc_html( delta ) inside, or literal '0'. No user input reaches this value.
									?></td>
									<td><?php echo $row['sparkline']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_sparkline_svg() returns only hardcoded SVG markup with numeric values cast to (int) and esc_attr() on polyline points. No user input reaches this value.
									?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( empty( $items ) ) : ?>
						<p><?php esc_html_e( 'No tracked keywords yet. Set focus keywords on posts and run Track Now.', 'meyvora-seo' ); ?></p>
					<?php else : ?>
						<?php
						if ( $pages > 1 ) {
							echo '<div class="tablenav"><div class="tablenav-pages">';
							echo wp_kses_post( paginate_links( array(
								'base'      => add_query_arg( 'paged', '%#%' ),
								'format'    => '',
								'total'     => $pages,
								'current'   => $paged,
							) ) );
							echo '</div></div>';
						}
						?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Get tracked keywords with current position, 7-day change, 30-day sparkline.
	 *
	 * @param string $post_type_filter Optional post type.
	 * @param int    $paged            Page number.
	 * @return array{ items: array, total: int, pages: int }
	 */
	protected function get_tracked_keywords( string $post_type_filter, int $paged ): array {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		$posts = $wpdb->posts;
		$join  = "LEFT JOIN {$posts} p ON p.ID = h.post_id AND p.post_status = 'publish'";
		$where = '1=1';
		if ( $post_type_filter !== '' ) {
			$where .= $wpdb->prepare( ' AND p.post_type = %s', $post_type_filter );
		}
		$limit  = self::PER_PAGE;
		$offset = ( $paged - 1 ) * $limit;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total  = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT h.post_id, h.keyword) FROM {$table} h {$join} WHERE {$where}"
		);
		$pages  = (int) max( 1, ceil( $total / $limit ) );
		$ids_kw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT h.post_id, h.keyword FROM {$table} h {$join} WHERE {$where} GROUP BY h.post_id, h.keyword ORDER BY MAX(h.date) DESC LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable
		$items  = array();
		foreach ( $ids_kw as $row ) {
			$post_id = (int) $row['post_id'];
			$keyword = $row['keyword'];
			$post    = get_post( $post_id );
			$post_title = $post ? $post->post_title : '';
			$latest  = $this->get_latest_row( $post_id, $keyword );
			$change_7  = $this->get_7day_change( $post_id, $keyword );
			$sparkline = $this->get_sparkline_svg( $post_id, $keyword, self::SPARKLINE_DAYS );
			$items[]   = array(
				'post_id'           => $post_id,
				'keyword'           => $keyword,
				'post_title'        => $post_title,
				'current_position'  => $latest['position'] !== null ? (string) $latest['position'] : '—',
				'serp_feature'      => $latest['serp_feature'],
				'change_7'          => $change_7,
				'sparkline'         => $sparkline,
			);
		}
		return array( 'items' => $items, 'total' => $total, 'pages' => $pages );
	}

	/**
	 * Render SERP feature badges from comma-separated serp_feature string.
	 *
	 * @param string $serp_feature Comma-separated list (e.g. FEATURED_SNIPPET,RICH_SNIPPET).
	 * @return string HTML (badges or grey dash).
	 */
	protected function render_serp_feature_badges( string $serp_feature ): string {
		$serp_feature = trim( $serp_feature );
		if ( $serp_feature === '' ) {
			return '<span class="mev-serp-dash" style="color:#999">&mdash;</span>';
		}
		$labels = array(
			'FEATURED_SNIPPET' => array( '★ Snippet', '#d4a017', 'mev-badge-snippet' ),
			'RICH_SNIPPET'     => array( '◆ Rich', '#2271b1', 'mev-badge-rich' ),
			'AMP_BLUE_LINK'    => array( '⚡ AMP', '#d9730d', 'mev-badge-amp' ),
			'VIDEO'            => array( '▶ Video', '#b32d2e', 'mev-badge-video' ),
			'RECIPE_FEATURE'   => array( '🍴 Recipe', '#00a32a', 'mev-badge-recipe' ),
		);
		$parts = array_map( 'trim', explode( ',', $serp_feature ) );
		$out   = array();
		foreach ( $parts as $code ) {
			if ( $code === '' ) {
				continue;
			}
			$cfg = $labels[ $code ] ?? array( $code, '#50575e', 'mev-badge-other' );
			$out[] = '<span class="mev-serp-badge ' . esc_attr( $cfg[2] ) . '" style="background:' . esc_attr( $cfg[1] ) . ';color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;white-space:nowrap">' . esc_html( $cfg[0] ) . '</span>';
		}
		return empty( $out ) ? '<span class="mev-serp-dash" style="color:#999">&mdash;</span>' : implode( ' ', $out );
	}

	/**
	 * Previous serp_feature for post+keyword (last row by date). Used before insert to detect changes.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $keyword Keyword.
	 * @return string
	 */
	protected function get_previous_serp_feature( int $post_id, string $keyword ): string {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no WP API.
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from prefix.
			"SELECT serp_feature FROM {$table} WHERE post_id = %d AND keyword = %s ORDER BY date DESC LIMIT 1",
			$post_id,
			$keyword
		), ARRAY_A );
		return isset( $row['serp_feature'] ) ? (string) $row['serp_feature'] : '';
	}

	protected function get_latest_position( int $post_id, string $keyword ): ?float {
		$row = $this->get_latest_row( $post_id, $keyword );
		return $row['position'];
	}

	/**
	 * Latest rank row (position + serp_feature) for post+keyword.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $keyword Keyword.
	 * @return array{ position: ?float, serp_feature: string }
	 */
	protected function get_latest_row( int $post_id, string $keyword ): array {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no WP API.
		$row = $wpdb->get_row( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from prefix.
			"SELECT position, serp_feature FROM {$table} WHERE post_id = %d AND keyword = %s ORDER BY date DESC LIMIT 1",
			$post_id,
			$keyword
		), ARRAY_A );
		if ( ! $row ) {
			return array( 'position' => null, 'serp_feature' => '' );
		}
		return array(
			'position'    => isset( $row['position'] ) ? (float) $row['position'] : null,
			'serp_feature' => isset( $row['serp_feature'] ) ? (string) $row['serp_feature'] : '',
		);
	}

	protected function get_7day_change( int $post_id, string $keyword ): string {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no WP API.
		$rows  = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from prefix.
			"SELECT position, date FROM {$table} WHERE post_id = %d AND keyword = %s ORDER BY date DESC LIMIT 8",
			$post_id,
			$keyword
		), ARRAY_A );
		if ( count( $rows ) < 2 ) {
			return '&mdash;';
		}
		$current = (float) $rows[0]['position'];
		$week_ago = null;
		$d0 = $rows[0]['date'] ?? '';
		foreach ( $rows as $r ) {
			$d = $r['date'] ?? '';
			if ( $d !== $d0 && strtotime( $d ) <= strtotime( $d0 ) - 5 * DAY_IN_SECONDS ) {
				$week_ago = (float) $r['position'];
				break;
			}
		}
		if ( $week_ago === null ) {
			return '&mdash;';
		}
		$delta = round( $current - $week_ago, 1 );
		if ( $delta > 0 ) {
			return '<span style="color:red">▲ ' . esc_html( (string) $delta ) . '</span>';
		}
		if ( $delta < 0 ) {
			return '<span style="color:green">▼ ' . esc_html( (string) abs( $delta ) ) . '</span>';
		}
		return '0';
	}

	protected function get_sparkline_svg( int $post_id, string $keyword, int $days ): string {
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; no WP API.
		$rows  = $wpdb->get_results( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from prefix.
			"SELECT position, date FROM {$table} WHERE post_id = %d AND keyword = %s ORDER BY date ASC",
			$post_id,
			$keyword
		), ARRAY_A );
		$positions = array();
		foreach ( array_slice( $rows, - $days ) as $r ) {
			$positions[] = (float) ( $r['position'] ?? 0 );
		}
		if ( empty( $positions ) ) {
			return '&mdash;';
		}
		$w = 120;
		$h = 24;
		$max = max( $positions );
		$min = min( $positions );
		$range = ( $max - $min ) > 0 ? ( $max - $min ) : 1;
		$n = count( $positions );
		$step = $n > 1 ? $w / ( $n - 1 ) : 0;
		$points = array();
		foreach ( $positions as $i => $v ) {
			$x = $i * $step;
			$y = $h - ( ( $v - $min ) / $range ) * ( $h - 2 ) - 1;
			$points[] = $x . ',' . $y;
		}
		return '<svg width="' . (int) $w . '" height="' . (int) $h . '" viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" style="vertical-align:middle"><polyline fill="none" stroke="#2271b1" stroke-width="1.5" points="' . esc_attr( implode( ' ', $points ) ) . '"/></svg>';
	}

	public function ajax_run_track(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$this->run_track();
		wp_send_json_success();
	}

	/**
	 * AJAX: manually trigger rank tracking run (same as cron).
	 */
	public function ajax_manual_run(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$this->run_track();
		wp_send_json_success( array( 'message' => __( 'Rank tracking run complete.', 'meyvora-seo' ) ) );
	}

	/**
	 * AJAX: rank history for post meta box (all keywords, ~90 days).
	 */
	public function ajax_rank_history_post(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$keywords = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT keyword FROM {$table} WHERE post_id = %d ORDER BY keyword ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from constant.
				$post_id
			)
		);
		$since = gmdate( 'Y-m-d', strtotime( '-90 days' ) );
		$data  = array();
		foreach ( (array) $keywords as $kw ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT date, position, serp_feature FROM {$table} WHERE post_id = %d AND keyword = %s AND date >= %s ORDER BY date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from constant.
					$post_id,
					$kw,
					$since
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				continue;
			}
			$latest = end( $rows );
			$data[] = array(
				'keyword'      => $kw,
				'current'      => isset( $latest['position'] ) ? (float) $latest['position'] : null,
				'serp_feature' => isset( $latest['serp_feature'] ) ? (string) $latest['serp_feature'] : '',
				'history'      => array_map(
					static function ( $r ) {
						return array(
							'date'     => $r['date'],
							'position' => (float) $r['position'],
						);
					},
					$rows
				),
			);
		}
		$primary = '';
		if ( class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			$normalized = Meyvora_SEO_Analyzer::normalize_focus_keywords(
				get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true )
			);
			$primary = isset( $normalized[0] ) ? (string) $normalized[0] : '';
		} else {
			$raw = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
			$primary = is_string( $raw ) ? trim( $raw ) : '';
		}
		usort(
			$data,
			static function ( $a, $b ) use ( $primary ) {
				$ap = ( $primary !== '' && $a['keyword'] === $primary ) ? 0 : 1;
				$bp = ( $primary !== '' && $b['keyword'] === $primary ) ? 0 : 1;
				if ( $ap !== $bp ) {
					return $ap <=> $bp;
				}
				return strcasecmp( $a['keyword'], $b['keyword'] );
			}
		);
		wp_send_json_success(
			array(
				'keywords'         => $data,
				'primary_keyword'  => $primary,
			)
		);
	}

	public function add_rank_meta_box(): void {
		if ( ! $this->is_gsc_connected() ) {
			return;
		}
		$screens = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'meyvora_seo_rank_history',
				__( 'Rank history', 'meyvora-seo' ),
				array( $this, 'render_rank_meta_box' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	/**
	 * @param WP_Post $post Post being edited.
	 */
	public function render_rank_meta_box( WP_Post $post ): void {
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		?>
		<div id="mev-rank-history-panel"
			class="mev-rank-history-panel"
			data-post-id="<?php echo esc_attr( (string) (int) $post->ID ); ?>"
			data-nonce="<?php echo esc_attr( $nonce ); ?>"
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<p class="mev-rank-loading"><?php esc_html_e( 'Loading rank history...', 'meyvora-seo' ); ?></p>
		</div>
		<?php
	}
}
