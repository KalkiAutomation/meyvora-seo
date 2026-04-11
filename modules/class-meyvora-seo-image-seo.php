<?php
/**
 * Image SEO: bulk alt editor, auto alt on upload, filename sanitizer, audit integration.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification.Recommended -- Bulk counts; AJAX nonce in handler.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Image_SEO
 */
class Meyvora_SEO_Image_SEO {

	const PAGE_SLUG    = 'meyvora-seo-image-seo';
	const PER_PAGE     = 50;
	const AJAX_SAVE    = 'meyvora_seo_image_save_alt';
	const NONCE_ACTION = 'meyvora_seo_image_seo';

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
		add_action( 'wp_ajax_' . self::AJAX_SAVE, array( $this, 'ajax_save_alt' ) );
		if ( $this->options->is_enabled( 'image_seo_auto_alt' ) ) {
			$this->loader->add_action( 'add_attachment', $this, 'auto_alt_on_upload', 10, 1 );
		}
		if ( $this->options->is_enabled( 'image_seo_sanitize_filename' ) ) {
			$this->loader->add_filter( 'wp_handle_upload_prefilter', $this, 'sanitize_filename', 10, 1 );
		}
	}

	public function register_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Image SEO', 'meyvora-seo' ),
			__( 'Image SEO', 'meyvora-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts for Image SEO page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_' . self::PAGE_SLUG ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		wp_add_inline_script( 'jquery', $this->get_inline_script(), 'after' );
	}

	private function get_inline_script(): string {
		$nonce = wp_create_nonce( self::NONCE_ACTION );
		return sprintf(
			'window.meyvoraImageSeo = { nonce: %s, action: %s };',
			wp_json_encode( $nonce ),
			wp_json_encode( self::AJAX_SAVE )
		);
	}

	public function render_page(): void {
		$tab    = isset( $_GET['tab'] ) && $_GET['tab'] === 'missing' ? 'missing' : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = $this->get_attachments( $tab, $paged );
		$items  = $result['items'];
		$total  = $result['total'];
		$pages  = $result['pages'];
		$missing_count = self::count_missing_alt_media();
		$nonce  = wp_create_nonce( self::NONCE_ACTION );
		?>
		<style>
		.mev-img-seo-wrap{max-width:1200px;}
		.mev-img-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:28px;}
		.mev-img-header-left h1{font-size:22px;font-weight:700;color:var(--mev-gray-900);margin:0 0 4px;}
		.mev-img-header-left p{font-size:13px;color:var(--mev-gray-500);margin:0;}
		.mev-img-stats{display:flex;gap:12px;flex-wrap:wrap;}
		.mev-img-stat{background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius);padding:14px 20px;min-width:120px;box-shadow:var(--mev-shadow-sm);}
		.mev-img-stat .stat-num{font-size:24px;font-weight:700;color:var(--mev-gray-900);line-height:1;}
		.mev-img-stat .stat-label{font-size:11px;color:var(--mev-gray-500);margin-top:4px;text-transform:uppercase;letter-spacing:.04em;}
		.mev-img-stat.stat-warn .stat-num{color:var(--mev-warning);}
		.mev-img-stat.stat-ok .stat-num{color:var(--mev-success);}
		.mev-img-tabs{display:flex;gap:4px;margin-bottom:20px;background:var(--mev-surface-3);border-radius:var(--mev-radius);padding:4px;width:fit-content;border:1px solid var(--mev-border);}
		.mev-img-tab{padding:7px 18px;border-radius:7px;font-size:13px;font-weight:500;color:var(--mev-gray-600);text-decoration:none;transition:all .15s;display:flex;align-items:center;gap:6px;}
		.mev-img-tab:hover{color:var(--mev-gray-900);}
		.mev-img-tab.active{background:var(--mev-surface);color:var(--mev-primary);box-shadow:var(--mev-shadow-sm);font-weight:600;}
		.mev-img-tab .tab-badge{background:var(--mev-warning-light);color:var(--mev-warning);font-size:10px;font-weight:700;padding:1px 6px;border-radius:var(--mev-radius-full);}
		.mev-img-tab.active .tab-badge{background:var(--mev-primary-light);color:var(--mev-primary);}
		.mev-img-toolbar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:12px 16px;background:var(--mev-surface-2);border:1px solid var(--mev-border);border-radius:var(--mev-radius) var(--mev-radius) 0 0;border-bottom:none;}
		.mev-img-toolbar-left{display:flex;align-items:center;gap:10px;}
		.mev-select-all-wrap{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--mev-gray-600);cursor:pointer;user-select:none;}
		.mev-select-all-wrap input{width:16px;height:16px;accent-color:var(--mev-primary);cursor:pointer;}
		.mev-count-badge{background:var(--mev-surface-3);border:1px solid var(--mev-border);border-radius:var(--mev-radius-full);padding:3px 10px;font-size:12px;color:var(--mev-gray-500);}
		.mev-btn-save-sel{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;background:var(--mev-primary);color:#fff;border:none;border-radius:var(--mev-radius-sm);font-size:13px;font-weight:600;cursor:pointer;transition:all .15s;box-shadow:0 1px 3px rgba(124,58,237,.3);}
		.mev-btn-save-sel:hover{background:var(--mev-primary-hover);transform:translateY(-1px);}
		.mev-btn-save-sel:disabled{opacity:.5;cursor:not-allowed;transform:none;}
		.mev-img-grid-header{display:grid;grid-template-columns:40px 72px 1fr 1fr 1fr 100px;gap:0;background:var(--mev-surface-2);border:1px solid var(--mev-border);border-bottom:none;padding:0;}
		.mev-img-grid-header .gh{padding:10px 12px;font-size:11px;font-weight:600;color:var(--mev-gray-500);text-transform:uppercase;letter-spacing:.05em;border-right:1px solid var(--mev-border);}
		.mev-img-grid-header .gh:last-child{border-right:none;}
		.mev-img-list{border:1px solid var(--mev-border);border-radius:0 0 var(--mev-radius) var(--mev-radius);overflow:hidden;background:var(--mev-surface);}
		.mev-img-row{display:grid;grid-template-columns:40px 72px 1fr 1fr 1fr 100px;border-bottom:1px solid var(--mev-border);transition:background .12s;align-items:center;}
		.mev-img-row:last-child{border-bottom:none;}
		.mev-img-row:hover{background:var(--mev-surface-2);}
		.mev-img-row.saving{opacity:.6;pointer-events:none;}
		.mev-img-row.saved{background:var(--mev-success-light);}
		.mev-img-row.error-row{background:var(--mev-danger-light);}
		.mev-img-cell{padding:10px 12px;border-right:1px solid var(--mev-border);font-size:13px;color:var(--mev-gray-700);}
		.mev-img-cell:last-child{border-right:none;}
		.mev-img-cell.cell-check{display:flex;align-items:center;justify-content:center;padding:10px 0;}
		.mev-img-cell.cell-check input{width:15px;height:15px;accent-color:var(--mev-primary);cursor:pointer;}
		.mev-img-cell.cell-thumb{padding:8px;}
		.mev-img-thumb{width:52px;height:52px;object-fit:cover;border-radius:6px;border:1px solid var(--mev-border);display:block;}
		.mev-img-thumb-empty{width:52px;height:52px;background:var(--mev-surface-3);border-radius:6px;border:1px solid var(--mev-border);display:flex;align-items:center;justify-content:center;color:var(--mev-gray-400);font-size:20px;}
		.mev-img-cell.cell-filename{font-size:12px;color:var(--mev-gray-500);font-family:monospace;word-break:break-all;}
		.mev-img-input{width:100%;padding:7px 10px;border:1.5px solid var(--mev-border);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);background:var(--mev-surface);transition:border-color .15s;box-sizing:border-box;}
		.mev-img-input:focus{outline:none;border-color:var(--mev-primary);box-shadow:0 0 0 3px var(--mev-primary-light);}
		.mev-img-input.input-missing{border-color:var(--mev-warning-mid);background:var(--mev-warning-light);}
		.mev-img-save-btn{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;background:transparent;border:1.5px solid var(--mev-border);border-radius:var(--mev-radius-sm);cursor:pointer;color:var(--mev-gray-500);font-size:16px;transition:all .15s;flex-shrink:0;}
		.mev-img-save-btn:hover{background:var(--mev-success-light);border-color:var(--mev-success);color:var(--mev-success);}
		.mev-img-save-btn.btn-saved{background:var(--mev-success-light);border-color:var(--mev-success);color:var(--mev-success);}
		.mev-img-save-btn.btn-error{background:var(--mev-danger-light);border-color:var(--mev-danger);color:var(--mev-danger);}
		.mev-img-cell.cell-action{display:flex;align-items:center;justify-content:center;gap:6px;}
		.mev-img-empty{padding:60px 24px;text-align:center;color:var(--mev-gray-400);}
		.mev-img-empty svg{opacity:.3;margin-bottom:12px;}
		.mev-img-empty p{font-size:14px;margin:0;}
		.mev-img-pagination{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-top:1px solid var(--mev-border);background:var(--mev-surface-2);border-radius:0 0 var(--mev-radius) var(--mev-radius);margin-top:-1px;}
		.mev-img-pagination .pg-info{font-size:13px;color:var(--mev-gray-500);}
		.mev-img-pagination .pg-links{display:flex;gap:4px;}
		.mev-img-pagination .pg-links a,.mev-img-pagination .pg-links span{padding:5px 10px;border-radius:var(--mev-radius-sm);font-size:13px;font-weight:500;border:1px solid var(--mev-border);background:var(--mev-surface);color:var(--mev-gray-700);text-decoration:none;transition:all .12s;}
		.mev-img-pagination .pg-links a:hover{background:var(--mev-primary-light);border-color:var(--mev-primary);color:var(--mev-primary);}
		.mev-img-pagination .pg-links .current{background:var(--mev-primary);color:#fff;border-color:var(--mev-primary);}
		</style>

		<div class="wrap mev-img-seo-wrap">
			<div class="mev-img-header">
				<div class="mev-img-header-left">
					<h1><?php esc_html_e( 'Image SEO', 'meyvora-seo' ); ?></h1>
					<p><?php esc_html_e( 'Manage alt text and titles for every image in your media library.', 'meyvora-seo' ); ?></p>
				</div>
				<div class="mev-img-stats">
					<div class="mev-img-stat">
						<div class="stat-num"><?php echo number_format( $total ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total images', 'meyvora-seo' ); ?></div>
					</div>
					<div class="mev-img-stat <?php echo $missing_count > 0 ? 'stat-warn' : 'stat-ok'; ?>">
						<div class="stat-num"><?php echo number_format( $missing_count ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Missing alt', 'meyvora-seo' ); ?></div>
					</div>
					<div class="mev-img-stat stat-ok">
						<div class="stat-num"><?php echo number_format( max( 0, $total - $missing_count ) ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'With alt text', 'meyvora-seo' ); ?></div>
					</div>
				</div>
			</div>

			<div class="mev-img-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="mev-img-tab <?php echo $tab === 'all' ? 'active' : ''; ?>">
					<?php esc_html_e( 'All images', 'meyvora-seo' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=missing' ) ); ?>" class="mev-img-tab <?php echo $tab === 'missing' ? 'active' : ''; ?>">
					<?php esc_html_e( 'Missing alt', 'meyvora-seo' ); ?>
					<?php if ( $missing_count > 0 ) : ?>
						<span class="tab-badge"><?php echo number_format( $missing_count ); ?></span>
					<?php endif; ?>
				</a>
			</div>

			<?php if ( empty( $items ) ) : ?>
				<div class="mev-img-list">
					<div class="mev-img-empty">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
						<p><?php echo $tab === 'missing' ? esc_html__( 'All images have alt text. Great job!', 'meyvora-seo' ) : esc_html__( 'No images found in the media library.', 'meyvora-seo' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<div class="mev-img-toolbar">
					<div class="mev-img-toolbar-left">
						<label class="mev-select-all-wrap">
							<input type="checkbox" id="mev-select-all" />
							<?php esc_html_e( 'Select all', 'meyvora-seo' ); ?>
						</label>
						<span class="mev-count-badge"><?php echo esc_html( sprintf( /* translators: %d: number of images */ __( '%d images', 'meyvora-seo' ), (int) $total ) ); ?></span>
					</div>
					<button type="button" class="mev-btn-save-sel" id="mev-save-selected" disabled>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
						<?php esc_html_e( 'Save selected', 'meyvora-seo' ); ?>
					</button>
				</div>

				<div class="mev-img-grid-header">
					<div class="gh"></div>
					<div class="gh"><?php esc_html_e( 'Image', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Filename', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Alt text', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Title', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Save', 'meyvora-seo' ); ?></div>
				</div>

				<div class="mev-img-list" id="mev-img-rows">
					<?php foreach ( $items as $att ) :
						$alt      = (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
						$title    = (string) $att->post_title;
						$src      = wp_get_attachment_image_url( $att->ID, 'thumbnail' );
						$filename = basename( (string) get_attached_file( $att->ID ) );
						$missing  = trim( $alt ) === '';
					?>
						<div class="mev-img-row" data-id="<?php echo (int) $att->ID; ?>">
							<div class="mev-img-cell cell-check">
								<input type="checkbox" class="mev-row-select" value="<?php echo (int) $att->ID; ?>" />
							</div>
							<div class="mev-img-cell cell-thumb">
								<?php if ( $src ) : ?>
									<img src="<?php echo esc_url( $src ); ?>" alt="" class="mev-img-thumb" loading="lazy" />
								<?php else : ?>
									<div class="mev-img-thumb-empty">&#128247;</div>
								<?php endif; ?>
							</div>
							<div class="mev-img-cell cell-filename" title="<?php echo esc_attr( $filename ); ?>">
								<?php echo esc_html( strlen( $filename ) > 28 ? substr( $filename, 0, 25 ) . '…' : $filename ); ?>
							</div>
							<div class="mev-img-cell">
								<input type="text" class="mev-img-input mev-input-alt<?php echo $missing ? ' input-missing' : ''; ?>" value="<?php echo esc_attr( $alt ); ?>" placeholder="<?php echo $missing ? esc_attr__( 'No alt text — add one', 'meyvora-seo' ) : esc_attr__( 'Alt text…', 'meyvora-seo' ); ?>" />
							</div>
							<div class="mev-img-cell">
								<input type="text" class="mev-img-input mev-input-title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Title…', 'meyvora-seo' ); ?>" />
							</div>
							<div class="mev-img-cell cell-action">
								<button type="button" class="mev-img-save-btn mev-save-row" title="<?php esc_attr_e( 'Save', 'meyvora-seo' ); ?>">
									<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<div class="mev-img-pagination">
						<span class="pg-info">
							<?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages, 3: total images */ __( 'Page %1$d of %2$d · %3$d images', 'meyvora-seo' ), (int) $paged, (int) $pages, (int) $total ) ); ?>
						</span>
						<div class="pg-links">
							<?php if ( $paged > 1 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">&laquo;</a>
							<?php endif; ?>
							<?php
							$start = max( 1, $paged - 2 );
							$end   = min( $pages, $paged + 2 );
							for ( $p = $start; $p <= $end; $p++ ) :
								if ( $p === $paged ) :
									echo '<span class="current">' . (int) $p . '</span>';
								else :
									echo '<a href="' . esc_url( add_query_arg( 'paged', $p ) ) . '">' . (int) $p . '</a>';
								endif;
							endfor;
							?>
							<?php if ( $paged < $pages ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">&raquo;</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var N = <?php echo wp_json_encode( $nonce ); ?>;
			var A = <?php echo wp_json_encode( self::AJAX_SAVE ); ?>;
			var U = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

			function saveRow(row, btn) {
				var id    = parseInt(row.dataset.id, 10);
				var alt   = row.querySelector('.mev-input-alt').value;
				var title = row.querySelector('.mev-input-title').value;
				row.classList.add('saving');
				btn.disabled = true;
				var fd = new FormData();
				fd.append('action', A); fd.append('nonce', N);
				fd.append('post_id', id); fd.append('alt', alt); fd.append('title', title);
				fetch(U, { method:'POST', body:fd, credentials:'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(res){
						row.classList.remove('saving');
						btn.disabled = false;
						if (res && res.success) {
							btn.classList.add('btn-saved');
							row.classList.add('saved');
							var altInput = row.querySelector('.mev-input-alt');
							if (altInput.value.trim() !== '') altInput.classList.remove('input-missing');
							setTimeout(function(){ btn.classList.remove('btn-saved'); row.classList.remove('saved'); }, 2000);
						} else {
							btn.classList.add('btn-error');
							row.classList.add('error-row');
							setTimeout(function(){ btn.classList.remove('btn-error'); row.classList.remove('error-row'); }, 2500);
						}
					})
					.catch(function(){
						row.classList.remove('saving'); btn.disabled = false;
						btn.classList.add('btn-error');
						setTimeout(function(){ btn.classList.remove('btn-error'); }, 2500);
					});
			}

			document.querySelectorAll('.mev-save-row').forEach(function(btn){
				btn.addEventListener('click', function(){ saveRow(btn.closest('.mev-img-row'), btn); });
			});

			var selAll = document.getElementById('mev-select-all');
			var saveSel = document.getElementById('mev-save-selected');

			function updateSaveBtn(){
				var checked = document.querySelectorAll('.mev-row-select:checked').length;
				saveSel.disabled = checked === 0;
				saveSel.textContent = checked > 0
					? '<?php echo esc_js( __( 'Save selected', 'meyvora-seo' ) ); ?> (' + checked + ')'
					: '<?php echo esc_js( __( 'Save selected', 'meyvora-seo' ) ); ?>';
			}

			if (selAll) {
				selAll.addEventListener('change', function(){
					document.querySelectorAll('.mev-row-select').forEach(function(cb){ cb.checked = selAll.checked; });
					updateSaveBtn();
				});
			}

			document.querySelectorAll('.mev-row-select').forEach(function(cb){
				cb.addEventListener('change', updateSaveBtn);
			});

			if (saveSel) {
				saveSel.addEventListener('click', function(){
					document.querySelectorAll('.mev-row-select:checked').forEach(function(cb){
						var row = cb.closest('.mev-img-row');
						var btn = row ? row.querySelector('.mev-save-row') : null;
						if (row && btn) saveRow(row, btn);
					});
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Get paginated image attachments.
	 *
	 * @param string $tab   'all' or 'missing'.
	 * @param int    $paged Page number.
	 * @return array{ items: array, total: int, pages: int }
	 */
	public function get_attachments( string $tab, int $paged = 1 ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $tab === 'missing' ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			);
		}
		$q      = new WP_Query( $args );
		$items  = $q->posts;
		$total  = (int) $q->found_posts;
		$pages  = (int) max( 1, $q->max_num_pages );
		return array( 'items' => $items, 'total' => $total, 'pages' => $pages );
	}

	/**
	 * AJAX: save alt and/or title for one or more attachments.
	 */
	public function ajax_save_alt(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meyvora-seo' ) ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'meyvora-seo' ) ) );
		}
		$alt   = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$post  = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'meyvora-seo' ) ) );
		}
		update_post_meta( $post_id, '_wp_attachment_image_alt', $alt );
		if ( $title !== '' ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		}
		wp_send_json_success();
	}

	/**
	 * On attachment add: if image and no alt, generate from filename.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function auto_alt_on_upload( int $post_id ): void {
		if ( ! wp_attachment_is_image( $post_id ) ) {
			return;
		}
		$existing = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
		if ( is_string( $existing ) && trim( $existing ) !== '' ) {
			return;
		}
		$file = get_attached_file( $post_id );
		if ( ! $file || ! is_string( $file ) ) {
			return;
		}
		$name = basename( $file );
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		if ( $ext ) {
			$name = substr( $name, 0, - ( strlen( $ext ) + 1 ) );
		}
		$name = str_replace( array( '-', '_' ), ' ', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		if ( $name === '' ) {
			return;
		}
		$generated = ucfirst( $name );
		// AI vision alt text (overrides filename-based alt when enabled).
		if ( $this->options->is_enabled( 'image_seo_ai_alt' ) ) {
			$mime = get_post_mime_type( $post_id );
			if ( is_string( $mime ) && strpos( $mime, 'image/' ) === 0 ) {
				$ai_alt = $this->generate_ai_alt( $post_id, $mime );
				if ( $ai_alt !== '' ) {
					$generated = $ai_alt;
				}
			}
		}
		update_post_meta( $post_id, '_wp_attachment_image_alt', $generated );
	}

	/**
	 * Generate alt text for an attachment using vision AI (OpenAI-compatible).
	 *
	 * @param int    $post_id   Attachment ID.
	 * @param string $mime_type Image MIME type (e.g. image/jpeg).
	 * @return string Alt text or empty string on failure.
	 */
	private function generate_ai_alt( int $post_id, string $mime_type ): string {
		if ( ! class_exists( 'Meyvora_SEO_AI' ) ) {
			return '';
		}
		$enc = $this->options->get( 'ai_api_key_encrypted', '' );
		if ( $enc === '' ) {
			return '';
		}
		$api_key = Meyvora_SEO_AI::decrypt( $enc );
		if ( $api_key === '' ) {
			return '';
		}
		$file_path = get_attached_file( $post_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}
		if ( filesize( $file_path ) > 4 * 1024 * 1024 ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Binary image for base64.
		$image_data = base64_encode( file_get_contents( $file_path ) );
		$provider   = $this->options->get( 'ai_api_provider', 'openai' );
		$model      = $this->options->get( 'ai_model', 'gpt-4o-mini' );
		$url        = ( $provider === 'custom' )
			? (string) $this->options->get( 'ai_custom_endpoint', 'https://api.openai.com/v1/chat/completions' )
			: 'https://api.openai.com/v1/chat/completions';
		$body = array(
			'model'      => $model,
			'max_tokens' => 100,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Write a concise, descriptive alt text for this image in under 125 characters. Return only the alt text, no quotes or labels.',
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => 'data:' . $mime_type . ';base64,' . $image_data,
							),
						),
					),
				),
			),
		);
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '';
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $json['choices'][0]['message']['content'] ?? '';
		return sanitize_text_field( trim( (string) $text ) );
	}

	/**
	 * Sanitize upload filename: lowercase, spaces/special to hyphens, no double hyphens.
	 *
	 * @param array $file Array with 'name', 'type', 'tmp_name', 'error', 'size'.
	 * @return array
	 */
	public function sanitize_filename( array $file ): array {
		if ( empty( $file['name'] ) ) {
			return $file;
		}
		$name = $file['name'];
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$base = pathinfo( $name, PATHINFO_FILENAME );
		$base = strtolower( $base );
		$base = preg_replace( '/[^a-z0-9]+/', '-', $base );
		$base = preg_replace( '/-+/', '-', $base );
		$base = trim( $base, '-' );
		if ( $base === '' ) {
			$base = 'image';
		}
		$file['name'] = $ext ? $base . '.' . strtolower( $ext ) : $base;
		return $file;
	}

	/**
	 * Count media library images with empty alt (for audit summary).
	 *
	 * @return int
	 */
	public static function count_missing_alt_media(): int {
		global $wpdb;
		$posts = $wpdb->prefix . 'posts';
		$meta  = $wpdb->prefix . 'postmeta';
		$sql   = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$posts} p
			LEFT JOIN {$meta} m ON p.ID = m.post_id AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s
			AND (p.post_mime_type LIKE %s)
			AND (m.meta_id IS NULL OR m.meta_value = %s)",
			'_wp_attachment_image_alt',
			'attachment',
			'inherit',
			'image/%',
			''
		);
		// Table names from $wpdb->prefix; query is prepared above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $sql );
	}
}
