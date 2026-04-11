<?php
/**
 * 404 Monitor: dedicated page, Create Redirect per row, CSV export, email alert.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from $wpdb->prefix.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_404_Monitor
 */
class Meyvora_SEO_404_Monitor {

	const PAGE_SLUG     = 'meyvora-seo-404-monitor';
	const AJAX_CREATE   = 'meyvora_seo_404_create_redirect';
	const AJAX_EXPORT   = 'meyvora_seo_404_export';
	const NONCE_ACTION  = 'meyvora_seo_404_monitor';
	const CRON_HOOK     = 'meyvora_seo_404_email_alert';

	/** @var Meyvora_SEO_Loader */
	protected Meyvora_SEO_Loader $loader;

	/** @var Meyvora_SEO_Options */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		$this->loader->add_action( 'admin_menu', $this, 'register_menu', 14, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this, 'ajax_create_redirect' ) );
		add_action( 'wp_ajax_' . self::AJAX_EXPORT, array( $this, 'ajax_export_csv' ) );
		add_action( self::CRON_HOOK, array( $this, 'send_alert_email' ) );
		$this->loader->add_action( 'init', $this, 'schedule_cron', 25 );
	}

	public function register_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( '404 Monitor', 'meyvora-seo' ),
			__( '404 Monitor', 'meyvora-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_' . self::PAGE_SLUG ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		wp_localize_script( 'jquery', 'meyvora404', array(
			'nonce'  => wp_create_nonce( self::NONCE_ACTION ),
			'create' => self::AJAX_CREATE,
			'export' => self::AJAX_EXPORT,
		) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'meyvora-seo' ) );
		}
		global $wpdb;
		$table_404 = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_404;
		$log_404   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_404} ORDER BY hit_count DESC LIMIT %d", 500 ), ARRAY_A );
		$enabled   = $this->options->get( '404_email_alert', false );
		$threshold = (int) $this->options->get( '404_alert_threshold', 10 );
		$nonce     = wp_create_nonce( self::NONCE_ACTION );
		$total     = count( (array) $log_404 );
		$high      = count( array_filter( (array) $log_404, function( $r ) use ( $threshold ) { return (int)($r['hit_count']??0) >= $threshold; } ) );
		?>
		<style>
		.mev-404-wrap{max-width:1200px;}
		.mev-404-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:28px;}
		.mev-404-header-left h1{font-size:22px;font-weight:700;color:var(--mev-gray-900);margin:0 0 4px;}
		.mev-404-header-left p{font-size:13px;color:var(--mev-gray-500);margin:0;}
		.mev-404-header-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
		.mev-404-stats{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
		.mev-404-stat{background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius);padding:14px 20px;min-width:110px;box-shadow:var(--mev-shadow-sm);position:relative;overflow:hidden;}
		.mev-404-stat::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;}
		.mev-404-stat.st-total::before{background:var(--mev-accent);}
		.mev-404-stat.st-high::before{background:var(--mev-danger);}
		.mev-404-stat.st-alert::before{background:var(--mev-success);}
		.mev-404-stat .sn{font-size:26px;font-weight:700;color:var(--mev-gray-900);line-height:1;}
		.mev-404-stat.st-high .sn{color:var(--mev-danger);}
		.mev-404-stat.st-alert .sn{color:var(--mev-success);}
		.mev-404-stat .sl{font-size:11px;color:var(--mev-gray-500);margin-top:4px;text-transform:uppercase;letter-spacing:.04em;}
		.mev-404-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:var(--mev-radius-sm);font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;text-decoration:none;border:none;}
		.mev-404-btn.btn-primary{background:var(--mev-primary);color:#fff;box-shadow:0 1px 3px rgba(124,58,237,.3);}
		.mev-404-btn.btn-primary:hover{background:var(--mev-primary-hover);transform:translateY(-1px);}
		.mev-404-btn.btn-outline{background:var(--mev-surface);color:var(--mev-gray-700);border:1.5px solid var(--mev-border);}
		.mev-404-btn.btn-outline:hover{border-color:var(--mev-gray-400);background:var(--mev-surface-2);color:var(--mev-gray-900);}
		.mev-404-toolbar{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--mev-surface-2);border:1px solid var(--mev-border);border-radius:var(--mev-radius) var(--mev-radius) 0 0;}
		.mev-404-search{position:relative;display:flex;align-items:center;}
		.mev-404-search input{padding:7px 10px 7px 32px;border:1.5px solid var(--mev-border);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);background:var(--mev-surface);width:260px;transition:border-color .15s;}
		.mev-404-search input:focus{outline:none;border-color:var(--mev-primary);box-shadow:0 0 0 3px var(--mev-primary-light);}
		.mev-404-search svg{position:absolute;left:9px;color:var(--mev-gray-400);pointer-events:none;}
		.mev-404-table-wrap{border:1px solid var(--mev-border);border-top:none;border-radius:0 0 var(--mev-radius) var(--mev-radius);overflow:hidden;}
		.mev-404-tbl{width:100%;border-collapse:collapse;background:var(--mev-surface);}
		.mev-404-tbl thead tr{background:var(--mev-surface-2);}
		.mev-404-tbl th{padding:10px 14px;font-size:11px;font-weight:600;color:var(--mev-gray-500);text-transform:uppercase;letter-spacing:.05em;text-align:left;border-bottom:1px solid var(--mev-border);white-space:nowrap;}
		.mev-404-tbl td{padding:11px 14px;font-size:13px;color:var(--mev-gray-700);border-bottom:1px solid var(--mev-border);vertical-align:top;}
		.mev-404-tbl tr:last-child td{border-bottom:none;}
		.mev-404-tbl tr:hover td{background:var(--mev-surface-2);}
		.mev-404-tbl tr.row-fixed td{opacity:.45;text-decoration:line-through;}
		.mev-url-cell{display:flex;align-items:flex-start;gap:8px;}
		.mev-url-code{font-family:monospace;font-size:12px;color:var(--mev-danger);background:var(--mev-danger-light);padding:3px 8px;border-radius:4px;word-break:break-all;line-height:1.4;}
		.mev-hit-badge{display:inline-flex;align-items:center;justify-content:center;min-width:36px;padding:3px 8px;border-radius:var(--mev-radius-full);font-size:12px;font-weight:700;}
		.mev-hit-badge.badge-low{background:var(--mev-surface-3);color:var(--mev-gray-600);}
		.mev-hit-badge.badge-med{background:var(--mev-warning-light);color:var(--mev-warning);}
		.mev-hit-badge.badge-high{background:var(--mev-danger-light);color:var(--mev-danger);}
		.mev-404-action-cell{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
		.mev-fix-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 12px;background:var(--mev-primary-light);color:var(--mev-primary);border:1px solid transparent;border-radius:var(--mev-radius-sm);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;}
		.mev-fix-btn:hover{background:var(--mev-primary);color:#fff;}
		.mev-404-inline-form{display:none;margin-top:8px;background:var(--mev-surface-2);border:1px solid var(--mev-border);border-radius:var(--mev-radius-sm);padding:10px 12px;}
		.mev-404-inline-form.open{display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
		.mev-404-inline-form input{flex:1;min-width:200px;padding:7px 10px;border:1.5px solid var(--mev-border);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);background:var(--mev-surface);transition:border-color .15s;}
		.mev-404-inline-form input:focus{outline:none;border-color:var(--mev-primary);}
		.mev-404-submit-btn{padding:6px 14px;background:var(--mev-primary);color:#fff;border:none;border-radius:var(--mev-radius-sm);font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;transition:all .15s;}
		.mev-404-submit-btn:hover{background:var(--mev-primary-hover);}
		.mev-404-cancel-btn{padding:6px 12px;background:transparent;color:var(--mev-gray-500);border:1px solid var(--mev-border);border-radius:var(--mev-radius-sm);font-size:12px;cursor:pointer;white-space:nowrap;transition:all .15s;}
		.mev-404-cancel-btn:hover{color:var(--mev-gray-800);}
		.mev-404-empty{padding:64px 24px;text-align:center;}
		.mev-404-empty .empty-icon{font-size:48px;opacity:.2;display:block;margin-bottom:12px;}
		.mev-404-empty p{font-size:14px;color:var(--mev-gray-500);margin:0;}
		.mev-404-alert-card{margin-top:20px;background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius);box-shadow:var(--mev-shadow-sm);overflow:hidden;}
		.mev-404-alert-header{padding:14px 20px;border-bottom:1px solid var(--mev-border);background:var(--mev-surface-2);display:flex;align-items:center;gap:10px;}
		.mev-404-alert-header h3{margin:0;font-size:14px;font-weight:600;color:var(--mev-gray-800);}
		.mev-404-alert-status{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:var(--mev-radius-full);font-size:12px;font-weight:600;}
		.mev-404-alert-status.on{background:var(--mev-success-light);color:var(--mev-success);}
		.mev-404-alert-status.off{background:var(--mev-surface-3);color:var(--mev-gray-500);}
		.mev-404-alert-body{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
		.mev-404-alert-desc{font-size:13px;color:var(--mev-gray-600);max-width:500px;line-height:1.6;}
		</style>

		<div class="wrap mev-404-wrap">
			<div class="mev-404-header">
				<div class="mev-404-header-left">
					<h1><?php esc_html_e( '404 Monitor', 'meyvora-seo' ); ?></h1>
					<p><?php esc_html_e( 'Track broken URLs hitting your site and fix them with one click.', 'meyvora-seo' ); ?></p>
				</div>
				<div class="mev-404-header-right">
					<button type="button" class="mev-404-btn btn-outline" id="mev-404-export-csv">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Export CSV', 'meyvora-seo' ); ?>
					</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-redirects' ) ); ?>" class="mev-404-btn btn-primary">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 01-4 4H3"/></svg>
						<?php esc_html_e( 'Redirect Manager', 'meyvora-seo' ); ?>
					</a>
				</div>
			</div>

			<div class="mev-404-stats">
				<div class="mev-404-stat st-total">
					<div class="sn"><?php echo number_format( $total ); ?></div>
					<div class="sl"><?php esc_html_e( 'Broken URLs', 'meyvora-seo' ); ?></div>
				</div>
				<div class="mev-404-stat st-high">
					<div class="sn"><?php echo number_format( $high ); ?></div>
					<div class="sl"><?php echo sprintf( /* translators: %d: hit count threshold */ esc_html__( 'Above %d hits', 'meyvora-seo' ), (int) $threshold ); ?></div>
				</div>
				<div class="mev-404-stat st-alert">
					<div class="sn"><?php echo $enabled ? esc_html__( 'On', 'meyvora-seo' ) : esc_html__( 'Off', 'meyvora-seo' ); ?></div>
					<div class="sl"><?php esc_html_e( 'Email alerts', 'meyvora-seo' ); ?></div>
				</div>
			</div>

			<div class="mev-404-toolbar">
				<div class="mev-404-search">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
					<input type="text" id="mev-404-search" placeholder="<?php esc_attr_e( 'Filter URLs…', 'meyvora-seo' ); ?>" />
				</div>
				<span style="font-size:13px;color:var(--mev-gray-400);">
					<?php echo sprintf( esc_html__( 'Showing up to 500 most-hit URLs', 'meyvora-seo' ) ); ?>
				</span>
			</div>

			<div class="mev-404-table-wrap">
				<?php if ( empty( $log_404 ) ) : ?>
					<div class="mev-404-empty">
						<span class="empty-icon">&#10003;</span>
						<p><?php esc_html_e( 'No 404 errors logged yet. Your site looks healthy!', 'meyvora-seo' ); ?></p>
					</div>
				<?php else : ?>
					<table class="mev-404-tbl" id="mev-404-table">
						<thead>
							<tr>
								<th style="width:45%"><?php esc_html_e( 'Broken URL', 'meyvora-seo' ); ?></th>
								<th style="width:8%"><?php esc_html_e( 'Hits', 'meyvora-seo' ); ?></th>
								<th style="width:14%"><?php esc_html_e( 'Last seen', 'meyvora-seo' ); ?></th>
								<th style="width:33%"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( (array) $log_404 as $row ) :
								$id   = (int)( $row['id'] ?? 0 );
								$url  = $row['url'] ?? '';
								$hits = (int)( $row['hit_count'] ?? 0 );
								$last = $row['last_seen'] ?? '';
								$badge_class = $hits >= $threshold ? 'badge-high' : ( $hits >= 3 ? 'badge-med' : 'badge-low' );
								$last_fmt = $last ? date_i18n( get_option('date_format'), strtotime( $last ) ) : '—';
							?>
								<tr data-id="<?php echo (int) $id; ?>" data-url="<?php echo esc_attr( $url ); ?>" data-search="<?php echo esc_attr( strtolower( $url ) ); ?>">
									<td>
										<div class="mev-url-cell">
											<span class="mev-url-code"><?php echo esc_html( $url ); ?></span>
										</div>
									</td>
									<td>
										<span class="mev-hit-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo number_format( $hits ); ?></span>
									</td>
									<td style="color:var(--mev-gray-500);font-size:12px;"><?php echo esc_html( $last_fmt ); ?></td>
									<td>
										<div class="mev-404-action-cell">
											<button type="button" class="mev-fix-btn mev-404-create-redirect">
												<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 014-4h14"/></svg>
												<?php esc_html_e( 'Create redirect', 'meyvora-seo' ); ?>
											</button>
										</div>
										<div class="mev-404-inline-form">
											<input type="text" class="mev-404-target" placeholder="<?php esc_attr_e( 'Redirect to…  e.g. /new-page/', 'meyvora-seo' ); ?>" />
											<button type="button" class="mev-404-submit-btn"><?php esc_html_e( 'Add', 'meyvora-seo' ); ?></button>
											<button type="button" class="mev-404-cancel-btn"><?php esc_html_e( 'Cancel', 'meyvora-seo' ); ?></button>
										</div>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="mev-404-alert-card">
				<div class="mev-404-alert-header">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
					<h3><?php esc_html_e( 'Email alerts', 'meyvora-seo' ); ?></h3>
					<span class="mev-404-alert-status <?php echo $enabled ? 'on' : 'off'; ?>">
						<?php echo $enabled ? esc_html__( 'Enabled', 'meyvora-seo' ) : esc_html__( 'Disabled', 'meyvora-seo' ); ?>
					</span>
				</div>
				<div class="mev-404-alert-body">
					<p class="mev-404-alert-desc">
						<?php echo $enabled
							? sprintf( /* translators: %d: hit count threshold */ esc_html__( 'Daily email alert is active. You will be notified when any 404 URL reaches %d or more hits.', 'meyvora-seo' ), (int) $threshold )
							: esc_html__( 'Enable daily email alerts to get notified when broken URLs reach your hit threshold.', 'meyvora-seo' );
						?>
					</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings#tab-advanced' ) ); ?>" class="mev-404-btn btn-outline" style="font-size:12px;padding:6px 14px;">
						<?php esc_html_e( 'Configure in Settings', 'meyvora-seo' ); ?>
					</a>
				</div>
			</div>
		</div>

		<script>
		(function(){
			var N = <?php echo wp_json_encode( $nonce ); ?>;
			var CA = <?php echo wp_json_encode( self::AJAX_CREATE ); ?>;
			var EA = <?php echo wp_json_encode( self::AJAX_EXPORT ); ?>;
			var U  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			// Export CSV
			document.getElementById('mev-404-export-csv')?.addEventListener('click', function(){
				window.location.href = U + '?action=' + EA + '&nonce=' + encodeURIComponent(N);
			});

			// Live search filter
			var searchInput = document.getElementById('mev-404-search');
			if (searchInput) {
				searchInput.addEventListener('input', function(){
					var q = this.value.toLowerCase().trim();
					document.querySelectorAll('#mev-404-table tbody tr').forEach(function(row){
						row.style.display = (!q || row.dataset.search.indexOf(q) !== -1) ? '' : 'none';
					});
				});
			}

			// Toggle inline form
			document.querySelectorAll('.mev-404-create-redirect').forEach(function(btn){
				btn.addEventListener('click', function(){
					var form = btn.closest('td').querySelector('.mev-404-inline-form');
					var isOpen = form.classList.contains('open');
					document.querySelectorAll('.mev-404-inline-form.open').forEach(function(f){ f.classList.remove('open'); });
					if (!isOpen) {
						form.classList.add('open');
						form.querySelector('.mev-404-target').focus();
					}
				});
			});

			document.querySelectorAll('.mev-404-cancel-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					btn.closest('.mev-404-inline-form').classList.remove('open');
				});
			});

			// Submit redirect
			document.querySelectorAll('.mev-404-submit-btn').forEach(function(btn){
				btn.addEventListener('click', function(){
					var row    = btn.closest('tr');
					var form   = btn.closest('.mev-404-inline-form');
					var source = row.dataset.url;
					var target = form.querySelector('.mev-404-target').value.trim();
					var rowId  = row.dataset.id;
					if (!target) {
						form.querySelector('.mev-404-target').style.borderColor = 'var(--mev-danger)';
						return;
					}
					btn.disabled = true;
					btn.textContent = '<?php echo esc_js( __( 'Saving…', 'meyvora-seo' ) ); ?>';
					var fd = new FormData();
					fd.append('action', CA); fd.append('nonce', N);
					fd.append('source_url', source); fd.append('target_url', target);
					fd.append('404_id', rowId);
					fetch(U, { method:'POST', body:fd, credentials:'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(res){
							if (res && res.success) {
								row.classList.add('row-fixed');
								form.classList.remove('open');
								setTimeout(function(){ row.remove(); }, 1200);
							} else {
								btn.disabled = false;
								btn.textContent = '<?php echo esc_js( __( 'Add', 'meyvora-seo' ) ); ?>';
							}
						})
						.catch(function(){
							btn.disabled = false;
							btn.textContent = '<?php echo esc_js( __( 'Add', 'meyvora-seo' ) ); ?>';
						});
				});
			});

			// Enter key in target input
			document.querySelectorAll('.mev-404-target').forEach(function(input){
				input.addEventListener('keydown', function(e){
					if (e.key === 'Enter') { input.closest('.mev-404-inline-form').querySelector('.mev-404-submit-btn').click(); }
					if (e.key === 'Escape') { input.closest('.mev-404-inline-form').classList.remove('open'); }
				});
				input.addEventListener('input', function(){ this.style.borderColor = ''; });
			});
		})();
		</script>
		<?php
	}

	public function ajax_create_redirect(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$source = isset( $_POST['source_url'] ) ? sanitize_text_field( wp_unslash( $_POST['source_url'] ) ) : '';
		$target = isset( $_POST['target_url'] ) ? esc_url_raw( wp_unslash( $_POST['target_url'] ) ) : '';
		if ( $source === '' || $target === '' ) {
			wp_send_json_error();
		}
		$path = wp_parse_url( $source, PHP_URL_PATH );
		if ( $path !== null && $path !== false ) {
			$source = $path;
		}
		$source = '/' . trim( $source, '/' );
		if ( $source === '//' ) {
			$source = '/';
		}
		$redirect_id = Meyvora_SEO_Redirects::add_redirect( $source, $target, 301, __( 'From 404 Monitor', 'meyvora-seo' ), false );
		if ( $redirect_id ) {
			$row_id = isset( $_POST['404_id'] ) ? absint( $_POST['404_id'] ) : 0;
			if ( $row_id > 0 ) {
				global $wpdb;
				$table_404 = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_404;
				$wpdb->delete( $table_404, array( 'id' => $row_id ) );
			}
			wp_send_json_success();
		}
		wp_send_json_error();
	}

	public function ajax_export_csv(): void {
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), self::NONCE_ACTION ) || ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden' );
		}
		global $wpdb;
		$table_404 = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_404;
		$rows      = $wpdb->get_results( "SELECT url, referrer, hit_count, last_seen FROM {$table_404} ORDER BY hit_count DESC LIMIT 10000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="meyvora-404-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'url', 'referrer', 'hit_count', 'last_seen' ) );
		foreach ( (array) $rows as $row ) {
			fputcsv( $out, array(
				$row['url'] ?? '',
				$row['referrer'] ?? '',
				$row['hit_count'] ?? 0,
				$row['last_seen'] ?? '',
			) );
		}
		// php://output stream for CSV download; WP_Filesystem does not apply.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	public function schedule_cron(): void {
		if ( ! $this->options->get( '404_email_alert', false ) ) {
			$timestamp = wp_next_scheduled( self::CRON_HOOK );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'daily', self::CRON_HOOK );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public function send_alert_email(): void {
		if ( ! $this->options->get( '404_email_alert', false ) ) {
			return;
		}
		$threshold = (int) $this->options->get( '404_alert_threshold', 10 );
		global $wpdb;
		$table_404 = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_404;
		$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT url, hit_count, last_seen FROM {$table_404} WHERE hit_count >= %d ORDER BY hit_count DESC LIMIT 50", $threshold ), ARRAY_A );
		if ( empty( $rows ) ) {
			return;
		}
		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return;
		}
		$subject = sprintf( '[%s] ' . /* translators: %d: number of URLs at or above threshold */ __( '404 Monitor: %d URL(s) at or above threshold', 'meyvora-seo' ), get_bloginfo( 'name' ), count( $rows ) );
		$body = __( 'The following 404 URLs have hit count at or above your threshold:', 'meyvora-seo' ) . "\n\n";
		foreach ( $rows as $r ) {
			$body .= ( $r['url'] ?? '' ) . ' - ' . ( (int) ( $r['hit_count'] ?? 0 ) ) . ' hits - ' . ( $r['last_seen'] ?? '' ) . "\n";
		}
		$body .= "\n" . __( 'Fix them from:', 'meyvora-seo' ) . ' ' . admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		wp_mail( $admin_email, $subject, $body );
	}
}
