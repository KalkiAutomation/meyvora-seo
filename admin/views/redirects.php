<?php
/**
 * Redirects admin view: list, add, delete, CSV import/export.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- View template; table names from $wpdb->prefix.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$redirects_table = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_REDIRECTS;
$table_404 = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_404;

if ( isset( $_GET['delete_redirect'] ) && isset( $_GET['meyvora_redirect_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['meyvora_redirect_nonce'] ) ), 'meyvora_redirects' ) && current_user_can( 'manage_options' ) ) {
	$id = absint( $_GET['delete_redirect'] );
	if ( $id > 0 ) {
		$wpdb->delete( $redirects_table, array( 'id' => $id ) );
		Meyvora_SEO_Redirects::invalidate_cache();
	}
	wp_safe_redirect( remove_query_arg( array( 'delete_redirect', 'meyvora_redirect_nonce' ) ) );
	exit;
}

if ( isset( $_POST['meyvora_redirect_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meyvora_redirect_nonce'] ) ), 'meyvora_redirects' ) && current_user_can( 'manage_options' ) ) {
	$redirect_form_action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( (string) $_POST['action'] ) ) : '';
	if ( $redirect_form_action === 'add' && isset( $_POST['source_url'], $_POST['target_url'] ) ) {
		$is_regex = ! empty( sanitize_key( wp_unslash( $_POST['is_regex'] ?? '' ) ) );
		$src = sanitize_text_field( wp_unslash( $_POST['source_url'] ) );
		if ( ! $is_regex ) {
			$src = '/' . trim( $src, '/' );
			if ( $src === '//' ) {
				$src = '/';
			}
		}
		$tgt = sanitize_text_field( wp_unslash( $_POST['target_url'] ) );
		$type = isset( $_POST['redirect_type'] ) ? absint( wp_unslash( $_POST['redirect_type'] ) ) : 301;
		$notes = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		// Warn about redirect chains.
		$chain_path = Meyvora_SEO_Redirects::detect_chain( $src, $tgt );
		if ( ! empty( $chain_path ) ) {
			$chain_str = implode( ' → ', array_map( 'esc_html', $chain_path ) );
			set_transient( 'meyvora_seo_redirect_chain_warning', $chain_str, 60 );
		}
		// Warn when existing rule points to this new source (would become a dead end).
		$existing_src = Meyvora_SEO_Redirects::detect_existing_target( $src );
		if ( $existing_src !== '' ) {
			set_transient( 'meyvora_seo_redirect_deadend_warning', $existing_src, 60 );
		}
		Meyvora_SEO_Redirects::add_redirect( $src, $tgt, $type, $notes, $is_regex );
	}
	if ( $redirect_form_action === 'import_csv' && isset( $_POST['csv_data'] ) ) {
		$csv_raw = sanitize_textarea_field( wp_unslash( (string) $_POST['csv_data'] ) );
		if ( $csv_raw !== '' ) {
			$lines = array_filter( array_map( 'trim', explode( "\n", $csv_raw ) ) );
			$imported = 0;
			foreach ( $lines as $line ) {
				$parts = str_getcsv( $line );
				if ( count( $parts ) >= 2 ) {
					$is_regex = isset( $parts[3] ) ? ( (int) $parts[3] === 1 ) : false;
					$src = $parts[0];
					if ( ! $is_regex ) {
						$src = '/' . trim( $src, '/' );
						if ( $src === '//' ) {
							$src = '/';
						}
					}
					$tgt = $parts[1];
					$type = isset( $parts[2] ) ? absint( $parts[2] ) : 301;
					if ( Meyvora_SEO_Redirects::add_redirect( $src, $tgt, $type, '', $is_regex ) ) {
						$imported++;
					}
				}
			}
			// Redirect to avoid re-post — esc_url_raw() intentional: sanitizing path for wp_safe_redirect() Location context, not HTML output.
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			wp_safe_redirect( add_query_arg( 'imported', $imported, $request_uri ) );
			exit;
		}
	}
}

if ( isset( $_GET['export'] ) && $_GET['export'] === 'csv' && current_user_can( 'manage_options' ) && isset( $_GET['meyvora_redirect_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['meyvora_redirect_nonce'] ) ), 'meyvora_redirects' ) ) {
	$rows = $wpdb->get_results( "SELECT source_url, target_url, redirect_type, is_regex FROM {$redirects_table}", ARRAY_A );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="meyvora-redirects-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'source', 'target', 'type', 'is_regex' ) );
	foreach ( (array) $rows as $row ) {
		$is_regex = isset( $row['is_regex'] ) ? (int) $row['is_regex'] : 0;
		fputcsv( $out, array( $row['source_url'], $row['target_url'], $row['redirect_type'], $is_regex ) );
	}
	// php://output stream; WP_Filesystem does not apply.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $out );
	exit;
}

$redirects = $wpdb->get_results( "SELECT * FROM {$redirects_table} ORDER BY id DESC", ARRAY_A );
$log_404 = $wpdb->get_results( "SELECT * FROM {$table_404} ORDER BY hit_count DESC LIMIT 50", ARRAY_A );
?>
<div class="wrap meyvora-redirects-page" style="max-width:none;">

<!-- Page Header -->
<div class="mev-page-header">
  <div class="mev-page-header-left">
    <div class="mev-page-logo">M</div>
    <div>
      <div class="mev-page-title"><?php esc_html_e( 'Redirect Manager', 'meyvora-seo' ); ?></div>
      <div class="mev-page-subtitle"><?php printf( /* translators: 1: number of redirects, 2: number of 404 errors */ esc_html__( '%1$d redirects · %2$d 404 errors', 'meyvora-seo' ), count( (array) $redirects ), count( (array) $log_404 ) ); ?></div>
    </div>
  </div>
  <nav class="mev-page-nav">
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>"><?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>"><?php esc_html_e( 'SEO Audit', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-redirects' ) ); ?>" class="active"><?php esc_html_e( 'Redirects', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></a>
  </nav>
</div>

<?php if ( isset( $_GET['imported'] ) ) : ?>
<div style="background:var(--mev-success-light);color:var(--mev-success);border:1px solid var(--mev-success-mid);border-radius:var(--mev-radius-sm);padding:12px 16px;margin-bottom:16px;font-size:13px;font-weight:500;">
  <?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php echo esc_html( sprintf( /* translators: %d: number of redirects imported */ __( '%d redirect(s) imported successfully.', 'meyvora-seo' ), (int) $_GET['imported'] ) ); ?>
</div>
<?php endif; ?>

<!-- Stats Strip -->
<?php
$total_hits = array_sum( array_column( (array) $redirects, 'hit_count' ) );
$count_301  = count( array_filter( (array) $redirects, fn( $r ) => (int) $r['redirect_type'] === 301 ) );
$count_302  = count( array_filter( (array) $redirects, fn( $r ) => (int) $r['redirect_type'] === 302 ) );
$count_410  = count( array_filter( (array) $redirects, fn( $r ) => (int) $r['redirect_type'] === 410 ) );
?>
<div style="display:grid;grid-template-columns:repeat(6,1fr);gap:14px;margin-bottom:20px;">
  <?php
  $rstats = [
    [ 'icon' => 'rotate_ccw', 'val' => count( (array) $redirects ), 'label' => __( 'Total', 'meyvora-seo' ),   'bg' => '#EDE9FE', 'color' => '#7C3AED' ],
    [ 'icon' => '301', 'val' => $count_301, 'label' => __( 'Permanent', 'meyvora-seo' ), 'bg' => '#DBEAFE', 'color' => '#1D4ED8' ],
    [ 'icon' => '302', 'val' => $count_302, 'label' => __( 'Temporary', 'meyvora-seo' ), 'bg' => '#FEF3C7', 'color' => '#D97706' ],
    [ 'icon' => '410', 'val' => $count_410, 'label' => __( 'Gone', 'meyvora-seo' ),      'bg' => '#FEE2E2', 'color' => '#DC2626' ],
    [ 'icon' => 'eye',  'val' => number_format( (int) $total_hits ), 'label' => __( 'Total Hits', 'meyvora-seo' ), 'bg' => '#D1FAE5', 'color' => '#059669' ],
    [ 'icon' => 'alert_triangle',  'val' => count( (array) $log_404 ), 'label' => __( '404 Errors', 'meyvora-seo' ), 'bg' => '#FEE2E2', 'color' => '#DC2626' ],
  ];
  $icon_paths = meyvora_seo_icon_paths();
  foreach ( $rstats as $rs ) : ?>
  <div style="background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius);padding:16px;text-align:center;box-shadow:var(--mev-shadow-sm);transition:transform 0.2s;">
    <div style="width:38px;height:38px;border-radius:50%;background:<?php echo esc_attr( $rs['bg'] ); ?>;color:<?php echo esc_attr( $rs['color'] ); ?>;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;margin:0 auto 8px;"><?php echo isset( $icon_paths[ $rs['icon'] ] ) ? wp_kses_post( meyvora_seo_icon( $rs['icon'], array( 'width' => 20, 'height' => 20 ) ) ) : esc_html( $rs['icon'] ); ?></div>
    <div style="font-size:24px;font-weight:800;color:var(--mev-gray-900);line-height:1;"><?php echo esc_html( $rs['val'] ); ?></div>
    <div style="font-size:11px;color:var(--mev-gray-400);font-weight:500;margin-top:4px;"><?php echo esc_html( $rs['label'] ); ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Two-column layout -->
<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;">

  <!-- LEFT: Add Form + Import/Export -->
  <div>
    <div class="mev-card" style="position:sticky;top:32px;">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php echo wp_kses_post( meyvora_seo_icon( 'plus', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php esc_html_e( 'Add Redirect', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <form method="post" id="meyvora-redirect-form">
          <?php wp_nonce_field( 'meyvora_redirects', 'meyvora_redirect_nonce' ); ?>
          <input type="hidden" name="action" value="add"/>
          <?php $prefill_source = isset( $_GET['add_from_404'] ) ? sanitize_text_field( wp_unslash( $_GET['add_from_404'] ) ) : ''; ?>

          <div style="margin-bottom:14px;">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'From (Source Path)', 'meyvora-seo' ); ?></label>
            <input type="text" name="source_url" value="<?php echo esc_attr( $prefill_source ); ?>" placeholder="/old-page/" required
              style="width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);transition:border-color 0.15s;box-sizing:border-box;"/>
            <div style="font-size:11px;color:var(--mev-gray-400);margin-top:4px;"><?php esc_html_e( 'Relative path, e.g. /old-page/', 'meyvora-seo' ); ?></div>
          </div>

          <div style="margin-bottom:14px;">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'To (Target URL)', 'meyvora-seo' ); ?></label>
            <input type="text" name="target_url" value="" placeholder="/new-page/ or https://..." required
              style="width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);transition:border-color 0.15s;box-sizing:border-box;"/>
          </div>

          <div style="margin-bottom:14px;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
              <input type="checkbox" name="is_regex" value="1" style="width:18px;height:18px;"/>
              <span style="font-size:13px;font-weight:600;color:var(--mev-gray-800);"><?php esc_html_e( 'Treat source as regex', 'meyvora-seo' ); ?></span>
            </label>
            <p class="description" style="margin:6px 0 0;font-size:12px;color:var(--mev-gray-500);"><?php esc_html_e( 'Use (.*) to capture groups and $1 in the target.', 'meyvora-seo' ); ?></p>
          </div>

          <div style="margin-bottom:14px;">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'Redirect Type', 'meyvora-seo' ); ?></label>
            <select name="redirect_type" style="width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);background:var(--mev-surface);cursor:pointer;box-sizing:border-box;">
              <option value="301">301 — Permanent (SEO passes)</option>
              <option value="302">302 — Temporary</option>
              <option value="307">307 — Temporary (keep method)</option>
              <option value="410">410 — Gone (deleted)</option>
            </select>
          </div>

          <div style="margin-bottom:16px;">
            <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'Notes (optional)', 'meyvora-seo' ); ?></label>
            <input type="text" name="notes" value="" placeholder="e.g. old product page"
              style="width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);transition:border-color 0.15s;box-sizing:border-box;"/>
          </div>

          <button type="submit" class="mev-btn mev-btn--primary mev-btn--full">
            <?php echo wp_kses_post( meyvora_seo_icon( 'plus', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( 'Add Redirect', 'meyvora-seo' ); ?>
          </button>
        </form>
      </div>

      <!-- Import / Export -->
      <div class="mev-card-header" style="border-top:1px solid var(--mev-border);">
        <span class="mev-card-title"><?php echo wp_kses_post( meyvora_seo_icon( 'folder_open', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php esc_html_e( 'Import / Export', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'export', 'csv' ), 'meyvora_redirects', 'meyvora_redirect_nonce' ) ); ?>" class="mev-btn mev-btn--secondary mev-btn--full mev-mb-14">
          <?php echo wp_kses_post( meyvora_seo_icon( 'download', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( 'Export CSV', 'meyvora-seo' ); ?>
        </a>
        <form method="post">
          <?php wp_nonce_field( 'meyvora_redirects', 'meyvora_redirect_nonce' ); ?>
          <input type="hidden" name="action" value="import_csv"/>
          <label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'Paste CSV to Import', 'meyvora-seo' ); ?></label>
          <textarea name="csv_data" rows="4" placeholder="/old/,/new/,301,0&#10;/another/,/page/,302,0"
            style="width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:11px;font-family:monospace;color:var(--mev-gray-700);box-sizing:border-box;resize:vertical;"></textarea>
          <div style="font-size:11px;color:var(--mev-gray-400);margin:4px 0 10px;"><?php esc_html_e( 'One redirect per line: source,target,type[,is_regex]. Optional 4th column: 0 or 1 for regex.', 'meyvora-seo' ); ?></div>
          <button type="submit" class="mev-btn mev-btn--secondary mev-btn--full">
            <?php echo wp_kses_post( meyvora_seo_icon( 'upload', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( 'Import CSV', 'meyvora-seo' ); ?>
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- RIGHT: Tabs (Redirects + 404 Monitor) -->
  <div>
    <!-- Check for redirect chains -->
    <div style="margin-bottom:14px;">
      <button type="button" id="mev-chain-scan-btn" class="mev-btn mev-btn--secondary">
        <?php echo wp_kses_post( meyvora_seo_icon( 'link', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( 'Check for redirect chains', 'meyvora-seo' ); ?>
      </button>
    </div>
    <!-- Tab pills -->
    <div style="display:flex;gap:4px;background:var(--mev-gray-100);border-radius:var(--mev-radius);padding:4px;margin-bottom:14px;">
      <button class="mev-rdrtab active" data-target="mev-rdr-redirects" type="button"
        style="flex:1;padding:9px 14px;border:none;border-radius:7px;font-size:13px;font-weight:600;cursor:pointer;transition:all 0.15s;background:var(--mev-surface);color:var(--mev-gray-900);box-shadow:var(--mev-shadow-sm);">
        <?php echo wp_kses_post( meyvora_seo_icon( 'rotate_ccw', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( 'Active Redirects', 'meyvora-seo' ); ?>
        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 5px;border-radius:9999px;font-size:11px;font-weight:700;background:var(--mev-primary-light);color:var(--mev-primary);margin-left:6px;"><?php echo esc_html( (string) (int) count( (array) $redirects ) ); ?></span>
      </button>
      <button class="mev-rdrtab" data-target="mev-rdr-404" type="button"
        style="flex:1;padding:9px 14px;border:none;border-radius:7px;font-size:13px;font-weight:500;cursor:pointer;transition:all 0.15s;background:none;color:var(--mev-gray-500);">
        <?php echo wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( '404 Monitor', 'meyvora-seo' ); ?>
        <?php if ( count( (array) $log_404 ) > 0 ) : ?>
        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 5px;border-radius:9999px;font-size:11px;font-weight:700;background:var(--mev-danger-light);color:var(--mev-danger);margin-left:6px;"><?php echo esc_html( (string) (int) count( (array) $log_404 ) ); ?></span>
        <?php endif; ?>
      </button>
    </div>

    <!-- Redirects Table -->
    <div id="mev-rdr-redirects">
      <?php
      $chain_warn   = get_transient( 'meyvora_seo_redirect_chain_warning' );
      $deadend_warn = get_transient( 'meyvora_seo_redirect_deadend_warning' );
      if ( $chain_warn ) {
        delete_transient( 'meyvora_seo_redirect_chain_warning' );
        echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Redirect chain detected:', 'meyvora-seo' ) . '</strong> ' . wp_kses_post( $chain_warn ) . ' — ' . esc_html__( 'consider updating the earlier redirect to point directly to the final destination.', 'meyvora-seo' ) . '</p></div>';
      }
      if ( $deadend_warn ) {
        delete_transient( 'meyvora_seo_redirect_deadend_warning' );
        echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'Existing redirect updated:', 'meyvora-seo' ) . '</strong> ' . sprintf( /* translators: %s: redirect source URL (shown in code tags) */ esc_html__( 'The redirect from %s already points to your new source URL. Consider updating it to point to the new destination instead.', 'meyvora-seo' ), '<code>' . esc_html( $deadend_warn ) . '</code>' ) . '</p></div>';
      }
      ?>
      <?php if ( empty( $redirects ) ) : ?>
      <div style="text-align:center;padding:60px 20px;background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius);">
        <div style="font-size:48px;opacity:0.3;margin-bottom:12px;"><?php echo wp_kses_post( meyvora_seo_icon( 'rotate_ccw', array( 'width' => 48, 'height' => 48 ) ) ); ?></div>
        <div style="font-size:16px;font-weight:700;color:var(--mev-gray-700);margin-bottom:6px;"><?php esc_html_e( 'No redirects yet', 'meyvora-seo' ); ?></div>
        <div style="font-size:13px;color:var(--mev-gray-400);"><?php esc_html_e( 'Add your first redirect using the form on the left.', 'meyvora-seo' ); ?></div>
      </div>
      <?php else : ?>
      <div class="mev-card" style="overflow:hidden;">
        <table class="mev-table">
          <thead><tr>
            <th style="width:28%;"><?php esc_html_e( 'Source', 'meyvora-seo' ); ?></th>
            <th style="width:28%;"><?php esc_html_e( 'Target', 'meyvora-seo' ); ?></th>
            <th style="width:90px;"><?php esc_html_e( 'Type', 'meyvora-seo' ); ?></th>
            <th style="width:60px;text-align:center;"><?php esc_html_e( 'Hits', 'meyvora-seo' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Last Hit', 'meyvora-seo' ); ?></th>
            <th style="width:80px;"></th>
          </tr></thead>
          <tbody>
          <?php foreach ( (array) $redirects as $r ) :
            $tc_map = [ '301' => [ '#DBEAFE', '#1D4ED8' ], '302' => [ '#FEF3C7', '#D97706' ], '307' => [ '#EDE9FE', '#7C3AED' ], '410' => [ '#FEE2E2', '#DC2626' ] ];
            $tc = $tc_map[ (string) $r['redirect_type'] ] ?? [ '#F3F4F6', '#6B7280' ];
            $is_regex = ! empty( $r['is_regex'] );
            $type_label = $is_regex ? ( (string) $r['redirect_type'] . ' ' . __( 'Regex', 'meyvora-seo' ) ) : (string) $r['redirect_type'];
            $last = $r['last_accessed'] ? human_time_diff( strtotime( $r['last_accessed'] ), time() ) . ' ' . __( 'ago', 'meyvora-seo' ) : '—';
            $del_url = wp_nonce_url( add_query_arg( 'delete_redirect', $r['id'] ), 'meyvora_redirects', 'meyvora_redirect_nonce' );
          ?>
          <tr>
            <td><code class="mev-code"><?php echo esc_html( $r['source_url'] ); ?></code>
              <?php if ( ! empty( $r['notes'] ) ) : ?><div style="font-size:10px;color:var(--mev-gray-400);margin-top:2px;"><?php echo esc_html( $r['notes'] ); ?></div><?php endif; ?>
            </td>
            <td><code class="mev-code" style="color:var(--mev-primary);"><?php echo esc_html( $r['target_url'] ); ?></code></td>
            <td>
              <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:700;font-family:monospace;background:<?php echo esc_attr( $tc[0] ); ?>;color:<?php echo esc_attr( $tc[1] ); ?>;"><?php echo esc_html( $type_label ); ?></span>
            </td>
            <td style="text-align:center;font-weight:700;font-size:13px;"><?php echo esc_html( number_format( (int) $r['hit_count'] ) ); ?></td>
            <td style="font-size:11px;color:var(--mev-gray-400);"><?php echo esc_html( $last ); ?></td>
            <td>
              <a href="<?php echo esc_url( $del_url ); ?>"
                 onclick="return confirm('<?php echo esc_js( __( 'Delete this redirect?', 'meyvora-seo' ) ); ?>')"
                 style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:var(--mev-radius-sm);font-size:12px;font-weight:600;background:var(--mev-danger-light);color:var(--mev-danger);border:1px solid var(--mev-danger-mid);text-decoration:none;transition:all 0.15s;">
                 <?php echo wp_kses_post( meyvora_seo_icon( 'trash_2', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php esc_html_e( 'Del', 'meyvora-seo' ); ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- 404 Monitor Table -->
    <div id="mev-rdr-404" style="display:none;">
      <?php if ( empty( $log_404 ) ) : ?>
      <div style="text-align:center;padding:60px 20px;background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius);">
        <div style="font-size:48px;margin-bottom:12px;"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 48, 'height' => 48 ) ) ); ?></div>
        <div style="font-size:16px;font-weight:700;color:var(--mev-gray-700);margin-bottom:6px;"><?php esc_html_e( 'No 404 errors logged', 'meyvora-seo' ); ?></div>
        <div style="font-size:13px;color:var(--mev-gray-400);"><?php esc_html_e( 'Broken pages visited by users will appear here.', 'meyvora-seo' ); ?></div>
      </div>
      <?php else : ?>
      <div class="mev-card" style="overflow:hidden;">
        <table class="mev-table">
          <thead><tr>
            <th><?php esc_html_e( 'Broken URL', 'meyvora-seo' ); ?></th>
            <th style="width:70px;text-align:center;"><?php esc_html_e( 'Hits', 'meyvora-seo' ); ?></th>
            <th style="width:110px;"><?php esc_html_e( 'Last Seen', 'meyvora-seo' ); ?></th>
            <th style="width:100px;"></th>
          </tr></thead>
          <tbody>
          <?php foreach ( (array) $log_404 as $row ) :
            $last404 = $row['last_seen'] ? human_time_diff( strtotime( $row['last_seen'] ), time() ) . ' ' . __( 'ago', 'meyvora-seo' ) : '—';
            $fix_url = add_query_arg( 'add_from_404', rawurlencode( $row['url'] ), remove_query_arg( 'add_from_404' ) ) . '#meyvora-redirect-form';
          ?>
          <tr>
            <td><code class="mev-code" style="color:var(--mev-danger);"><?php echo esc_html( $row['url'] ); ?></code></td>
            <td style="text-align:center;font-weight:700;font-size:13px;color:var(--mev-danger);"><?php echo esc_html( number_format( (int) $row['hit_count'] ) ); ?></td>
            <td style="font-size:11px;color:var(--mev-gray-400);"><?php echo esc_html( $last404 ); ?></td>
            <td>
              <a href="<?php echo esc_url( $fix_url ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm">
                <?php echo wp_kses_post( meyvora_seo_icon( 'plus', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php esc_html_e( 'Fix', 'meyvora-seo' ); ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /.right col -->
</div><!-- /.grid -->

<!-- Chain scan modal -->
<div id="mev-chain-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:100000;align-items:center;justify-content:center;padding:20px;">
  <div style="background:var(--mev-surface);border-radius:var(--mev-radius);box-shadow:var(--mev-shadow-lg);max-width:640px;width:100%;max-height:85vh;overflow:hidden;display:flex;flex-direction:column;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--mev-border);display:flex;align-items:center;justify-content:space-between;">
      <h2 style="margin:0;font-size:18px;font-weight:700;"><?php esc_html_e( 'Redirect chains', 'meyvora-seo' ); ?></h2>
      <button type="button" id="mev-chain-modal-close" style="background:none;border:none;cursor:pointer;padding:4px;color:var(--mev-gray-500);" aria-label="<?php esc_attr_e( 'Close', 'meyvora-seo' ); ?>"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 24, 'height' => 24 ) ) ); ?></button>
    </div>
    <div style="padding:16px 20px;border-bottom:1px solid var(--mev-border);">
      <button type="button" id="mev-chain-flatten-all-btn" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Flatten all chains', 'meyvora-seo' ); ?></button>
    </div>
    <div id="mev-chain-list-wrap" style="padding:16px 20px;overflow:auto;flex:1;">
      <div class="mev-chain-loading" style="display:none;text-align:center;padding:24px;color:var(--mev-gray-500);"><?php esc_html_e( 'Scanning…', 'meyvora-seo' ); ?></div>
      <div id="mev-chain-list"></div>
    </div>
  </div>
</div>

</div><!-- /.wrap -->