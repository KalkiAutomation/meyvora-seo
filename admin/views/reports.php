<?php
/**
 * SEO Reports dashboard: health score, top/bottom posts, issues, content stats, trend, export.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$current_page = 'reports';
$health_score = isset( $data['health_score'] ) ? (int) $data['health_score'] : 0;
$top_10       = isset( $data['top_10'] ) && is_array( $data['top_10'] ) ? $data['top_10'] : array();
$bottom_10    = isset( $data['bottom_10'] ) && is_array( $data['bottom_10'] ) ? $data['bottom_10'] : array();
$issues       = isset( $data['issues'] ) && is_array( $data['issues'] ) ? $data['issues'] : array();
$content_stats = isset( $data['content_stats'] ) && is_array( $data['content_stats'] ) ? $data['content_stats'] : array();
$trend        = isset( $data['trend'] ) && is_array( $data['trend'] ) ? $data['trend'] : array();
$gsc_connected = ! empty( $data['gsc_connected'] );
$has_gsc_cols = $gsc_connected && ( array_filter( $top_10, function ( $r ) { return isset( $r['gsc'] ); } ) || array_filter( $bottom_10, function ( $r ) { return isset( $r['gsc'] ); } ) );
$gsc_top_queries = isset( $data['gsc_top_queries'] ) && is_array( $data['gsc_top_queries'] ) ? $data['gsc_top_queries'] : array();
$ga4_connected = ! empty( $data['ga4_connected'] );
$ga4_top_posts = isset( $data['ga4_top_posts'] ) && is_array( $data['ga4_top_posts'] ) ? $data['ga4_top_posts'] : array();
$decaying_pages = isset( $data['decaying_pages'] ) && is_array( $data['decaying_pages'] ) ? $data['decaying_pages'] : array();

$score_status = $health_score >= 80 ? 'good' : ( $health_score >= 50 ? 'okay' : 'poor' );
$circumference = 220;
$offset        = $circumference - ( $health_score / 100 ) * $circumference;

$max_trend_score = 100;
if ( ! empty( $trend ) ) {
	$scores = array_column( $trend, 'score' );
	$max_trend_score = max( 100, max( array_map( 'intval', $scores ) ) );
}
?>
<div class="wrap meyvora-dashboard">

<div class="mev-page-header">
  <div class="mev-page-header-left">
    <div class="mev-page-logo">M</div>
    <div>
      <div class="mev-page-title"><?php esc_html_e( 'Meyvora SEO', 'meyvora-seo' ); ?></div>
      <div class="mev-page-subtitle"><?php esc_html_e( 'SEO Intelligence Report', 'meyvora-seo' ); ?></div>
      <div class="mev-report-date"><?php echo esc_html( date_i18n( 'F j, Y', (int) current_time( 'timestamp' ) ) ); ?></div>
    </div>
  </div>
  <nav class="mev-page-nav">
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>"><?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-reports' ) ); ?>" class="active"><?php esc_html_e( 'Reports', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>"><?php esc_html_e( 'SEO Audit', 'meyvora-seo' ); ?></a>
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

<!-- Actions -->
<div class="mev-card mev-mb-20">
  <div class="mev-card-body mev-flex-row" style="flex-wrap:wrap;align-items:center;gap:12px;">
    <a href="<?php echo esc_url( $print_url ); ?>" target="_blank" rel="noopener noreferrer" class="mev-btn mev-btn--primary">
      <?php esc_html_e( 'Print Report', 'meyvora-seo' ); ?>
    </a>
    <a href="<?php echo esc_url( $export_pdf_url ); ?>" target="_blank" rel="noopener noreferrer" class="button button-secondary">
      <?php esc_html_e( 'Export PDF', 'meyvora-seo' ); ?>
    </a>
    <span class="mev-text-muted" style="font-size:13px;" title="<?php echo esc_attr__( 'Opens a print-ready version of the report. Use your browser\'s Print dialog and select \'Save as PDF\' to export.', 'meyvora-seo' ); ?>"><?php esc_html_e( 'Opens a print-ready version of the report. Use your browser\'s Print dialog and select "Save as PDF" to export.', 'meyvora-seo' ); ?></span>
  </div>
</div>

<!-- SEO Health Score -->
<div class="mev-dashboard-grid" style="margin-bottom:24px;">
  <div class="mev-stat-card mev-stat-card--violet">
    <div class="mev-stat-label"><?php esc_html_e( 'SEO Health Score', 'meyvora-seo' ); ?></div>
    <div style="display:flex;align-items:center;gap:14px;margin-top:8px;">
      <svg class="mev-gauge mev-gauge--dashboard" width="90" height="90" viewBox="0 0 80 80">
        <circle class="mev-gauge-track" cx="40" cy="40" r="32" stroke-width="7"/>
        <circle class="mev-gauge-fill mev-gauge-fill--<?php echo esc_attr( $score_status ); ?>"
          cx="40" cy="40" r="32" stroke-width="7"
          stroke-dasharray="<?php echo esc_attr( $circumference ); ?>"
          stroke-dashoffset="<?php echo esc_attr( $offset ); ?>"/>
        <text class="mev-gauge-inner" x="40" y="41" text-anchor="middle"><?php echo (int) $health_score; ?></text>
        <text class="mev-gauge-inner-label" x="40" y="56" text-anchor="middle">/100</text>
      </svg>
      <div>
        <div class="mev-stat-value" style="font-size:22px;"><?php echo esc_html( $health_score >= 80 ? __( 'Great', 'meyvora-seo' ) : ( $health_score >= 50 ? __( 'Okay', 'meyvora-seo' ) : __( 'Poor', 'meyvora-seo' ) ) ); ?></div>
        <div class="mev-stat-subvalue"><?php esc_html_e( 'Site-wide average', 'meyvora-seo' ); ?></div>
      </div>
    </div>
    <div class="mev-score-insight mev-score-insight--<?php echo esc_attr( $score_status ); ?>">
      <?php
      if ( $health_score >= 80 ) {
        esc_html_e( 'Your site is in great shape. Focus on maintaining consistency.', 'meyvora-seo' );
      } elseif ( $health_score >= 50 ) {
        esc_html_e( 'Good progress. Several pages need attention — check the issues below.', 'meyvora-seo' );
      } else {
        esc_html_e( 'Significant SEO work needed. Start with pages marked Critical.', 'meyvora-seo' );
      }
      ?>
    </div>
  </div>
</div>

<!-- Two columns: Top 10 | Bottom 10 -->
<div class="mev-dashboard-body">
  <div class="mev-dashboard-main">
    <?php if ( ! $gsc_connected && ( ! empty( $top_10 ) || ! empty( $bottom_10 ) ) ) : ?>
    <p class="mev-text-muted" style="margin-bottom:12px;font-size:13px;"><?php esc_html_e( 'Connect Google Search Console in Settings → Integrations to see real click data.', 'meyvora-seo' ); ?></p>
    <?php endif; ?>

    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Top 10 by SEO score', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <?php if ( empty( $top_10 ) ) : ?>
          <p class="mev-text-muted"><?php esc_html_e( 'No posts with scores yet.', 'meyvora-seo' ); ?></p>
        <?php else : ?>
          <table class="mev-table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Score', 'meyvora-seo' ); ?></th>
                <?php if ( $has_gsc_cols ) : ?>
                <th style="width:80px;"><?php esc_html_e( 'Clicks', 'meyvora-seo' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Impressions', 'meyvora-seo' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( 'Avg. Position', 'meyvora-seo' ); ?></th>
                <?php endif; ?>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $top_10 as $row ) : ?>
                <?php
                $s = isset( $row['score'] ) ? (int) $row['score'] : 0;
                $gsc = isset( $row['gsc'] ) && is_array( $row['gsc'] ) ? $row['gsc'] : null;
                $pos = $gsc && isset( $row['gsc']['position'] ) ? (float) $row['gsc']['position'] : null;
                $pos_style = $pos !== null ? ( $pos <= 10 ? 'color:#059669;' : ( $pos <= 20 ? 'color:#d97706;' : 'color:#6b7280;' ) ) : '';
                ?>
                <tr>
                  <td>
                    <a href="<?php echo esc_url( $row['edit'] ?? '#' ); ?>" style="font-weight:600;color:var(--mev-gray-800);text-decoration:none;">
                      <?php echo esc_html( $row['title'] ?? __( '(no title)', 'meyvora-seo' ) ); ?>
                    </a>
                  </td>
                  <td>
                    <div class="mev-sparkline-bar" style="--pct:<?php echo (int) $s; ?>%;" title="<?php echo (int) $s; ?>/100">
                      <span class="mev-sparkline-fill"></span>
                      <span class="mev-sparkline-value"><?php echo (int) $s; ?></span>
                    </div>
                  </td>
                  <?php if ( $has_gsc_cols ) : ?>
                  <td><?php echo $gsc ? (int) ( $gsc['clicks'] ?? 0 ) : '—'; ?></td>
                  <td><?php echo $gsc ? (int) ( $gsc['impressions'] ?? 0 ) : '—'; ?></td>
                  <td style="<?php echo esc_attr( $pos_style ); ?>"><?php echo $pos !== null && $pos > 0 ? esc_html( number_format_i18n( $pos, 1 ) ) : '—'; ?></td>
                  <?php endif; ?>
                  <td><a href="<?php echo esc_url( $row['edit'] ?? '#' ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Edit', 'meyvora-seo' ); ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Bottom 10 — need improvement', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <?php if ( empty( $bottom_10 ) ) : ?>
          <p class="mev-text-muted"><?php esc_html_e( 'No posts with scores yet.', 'meyvora-seo' ); ?></p>
        <?php else : ?>
          <table class="mev-table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></th>
                <th style="width:140px;"><?php esc_html_e( 'Score', 'meyvora-seo' ); ?></th>
                <?php if ( $has_gsc_cols ) : ?>
                <th style="width:80px;"><?php esc_html_e( 'Clicks', 'meyvora-seo' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Impressions', 'meyvora-seo' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( 'Avg. Position', 'meyvora-seo' ); ?></th>
                <?php endif; ?>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $bottom_10 as $row ) : ?>
                <?php
                $s = isset( $row['score'] ) ? (int) $row['score'] : 0;
                $gsc = isset( $row['gsc'] ) && is_array( $row['gsc'] ) ? $row['gsc'] : null;
                $pos = $gsc && isset( $row['gsc']['position'] ) ? (float) $row['gsc']['position'] : null;
                $pos_style = $pos !== null ? ( $pos <= 10 ? 'color:#059669;' : ( $pos <= 20 ? 'color:#d97706;' : 'color:#6b7280;' ) ) : '';
                ?>
                <tr>
                  <td>
                    <a href="<?php echo esc_url( $row['edit'] ?? '#' ); ?>" style="font-weight:600;color:var(--mev-gray-800);text-decoration:none;">
                      <?php echo esc_html( $row['title'] ?? __( '(no title)', 'meyvora-seo' ) ); ?>
                    </a>
                  </td>
                  <td>
                    <div class="mev-sparkline-bar mev-sparkline-bar--low" style="--pct:<?php echo (int) $s; ?>%;" title="<?php echo (int) $s; ?>/100">
                      <span class="mev-sparkline-fill"></span>
                      <span class="mev-sparkline-value"><?php echo (int) $s; ?></span>
                    </div>
                  </td>
                  <?php if ( $has_gsc_cols ) : ?>
                  <td><?php echo $gsc ? (int) ( $gsc['clicks'] ?? 0 ) : '—'; ?></td>
                  <td><?php echo $gsc ? (int) ( $gsc['impressions'] ?? 0 ) : '—'; ?></td>
                  <td style="<?php echo esc_attr( $pos_style ); ?>"><?php echo $pos !== null && $pos > 0 ? esc_html( number_format_i18n( $pos, 1 ) ) : '—'; ?></td>
                  <?php endif; ?>
                  <td><a href="<?php echo esc_url( $row['edit'] ?? '#' ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Search Performance (GSC) -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Search Performance', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <?php if ( ! $gsc_connected || empty( $gsc_top_queries ) ) : ?>
          <p class="mev-text-muted"><?php esc_html_e( 'Connect Google Search Console in Settings → Integrations to see top queries (clicks, impressions, avg position).', 'meyvora-seo' ); ?></p>
        <?php else : ?>
          <table class="mev-table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Query', 'meyvora-seo' ); ?></th>
                <th><?php esc_html_e( 'Clicks', 'meyvora-seo' ); ?></th>
                <th><?php esc_html_e( 'Impressions', 'meyvora-seo' ); ?></th>
                <th><?php esc_html_e( 'Avg. position', 'meyvora-seo' ); ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $gsc_top_queries as $row ) : ?>
                <?php
                $query = isset( $row['keys'][0] ) ? $row['keys'][0] : '';
                $clicks = isset( $row['clicks'] ) ? (int) $row['clicks'] : 0;
                $impr = isset( $row['impressions'] ) ? (int) $row['impressions'] : 0;
                $pos = isset( $row['position'] ) ? (float) $row['position'] : null;
                ?>
                <tr>
                  <td><?php echo esc_html( $query ); ?></td>
                  <td><?php echo (int) $clicks; ?></td>
                  <td><?php echo (int) $impr; ?></td>
                  <td><?php echo $pos !== null ? esc_html( number_format_i18n( $pos, 1 ) ) : '—'; ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Top Content (GA4) -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Top Content', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <?php if ( ! $ga4_connected || empty( $ga4_top_posts ) ) : ?>
          <p class="mev-text-muted"><?php esc_html_e( 'Connect GA4 (Advanced mode) in Settings → Integrations to see top posts by views.', 'meyvora-seo' ); ?></p>
        <?php else : ?>
          <table class="mev-table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></th>
                <th><?php esc_html_e( 'Views', 'meyvora-seo' ); ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $ga4_top_posts as $row ) : ?>
                <tr>
                  <td><?php echo esc_html( $row['title'] ?? '' ); ?></td>
                  <td><?php echo (int) ( $row['views'] ?? 0 ); ?></td>
                  <td><a href="<?php echo esc_url( $row['url'] ?? '#' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'meyvora-seo' ); ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Content Decay (GSC) -->
    <div class="mev-card" id="content-decay">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Content Decay', 'meyvora-seo' ); ?></span>
        <?php if ( $gsc_connected && ! empty( $decaying_pages ) ) : ?>
        <span class="mev-badge mev-badge--violet"><?php echo count( $decaying_pages ); ?> <?php esc_html_e( 'pages', 'meyvora-seo' ); ?></span>
        <?php endif; ?>
      </div>
      <div class="mev-card-body">
        <?php if ( ! $gsc_connected ) : ?>
          <p class="mev-text-muted"><?php esc_html_e( 'Connect Google Search Console in Settings → Integrations to see pages with declining clicks (current vs previous 90-day period).', 'meyvora-seo' ); ?></p>
        <?php elseif ( empty( $decaying_pages ) ) : ?>
          <p class="mev-text-muted"><?php esc_html_e( 'No content decay detected — no pages with ≥30% click drop in the current 90-day window vs the previous 90 days.', 'meyvora-seo' ); ?></p>
        <?php else : ?>
          <table class="mev-table">
            <thead>
              <tr>
                <th><?php esc_html_e( 'URL', 'meyvora-seo' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Current clicks', 'meyvora-seo' ); ?></th>
                <th style="width:90px;"><?php esc_html_e( 'Previous clicks', 'meyvora-seo' ); ?></th>
                <th style="width:80px;"><?php esc_html_e( 'Drop', 'meyvora-seo' ); ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ( $decaying_pages as $row ) :
                $page_url = isset( $row['url'] ) ? $row['url'] : '';
                $post_id = $page_url !== '' ? url_to_postid( $page_url ) : 0;
                $edit_url = $post_id ? get_edit_post_link( $post_id, 'raw' ) : admin_url( 'admin.php?page=meyvora-seo-reports#content-decay' );
                $url_display = $page_url;
                if ( strpos( $url_display, '://' ) !== false ) {
                  $parsed = wp_parse_url( $page_url );
                  $url_display = isset( $parsed['path'] ) ? $parsed['path'] : $page_url;
                }
                $url_display = $url_display ?: $page_url;
              ?>
                <tr>
                  <td>
                    <a href="<?php echo esc_url( $page_url ); ?>" target="_blank" rel="noopener" style="font-size:13px;color:var(--mev-gray-800);text-decoration:none;display:block;max-width:320px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $page_url ); ?>"><?php echo esc_html( $url_display ); ?></a>
                  </td>
                  <td><?php echo (int) ( $row['curr'] ?? 0 ); ?></td>
                  <td><?php echo (int) ( $row['prev'] ?? 0 ); ?></td>
                  <td style="color:var(--mev-warning);font-weight:600;">−<?php echo esc_html( number_format_i18n( (float) ( $row['drop_pct'] ?? 0 ), 1 ) ); ?>%</td>
                  <td><a href="<?php echo esc_url( $edit_url ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Refresh content', 'meyvora-seo' ); ?></a></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <?php
    $missing_title_count = (int) ( $issues['missing_title'] ?? 0 );
    $missing_desc_count  = (int) ( $issues['missing_description'] ?? 0 );
    $total_indexed      = (int) ( $content_stats['total_indexed'] ?? 0 );
    $with_kw            = (int) ( $content_stats['total_with_focus_kw'] ?? 0 );
    $missing_kw_count   = max( 0, $total_indexed - $with_kw );
    $fix_links          = array();
    foreach ( array_slice( $bottom_10, 0, 3 ) as $row ) {
      $fix_links[] = isset( $row['edit'] ) ? $row['edit'] : '#';
    }
    if ( empty( $fix_links ) && ! empty( $bottom_10 ) ) {
      $fix_links = array( $bottom_10[0]['edit'] ?? '#', $bottom_10[0]['edit'] ?? '#', $bottom_10[0]['edit'] ?? '#' );
    }
    $fix_links = array_pad( $fix_links, 3, '#' );
    ?>
    <!-- Quick Wins -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Quick Wins', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <ul class="mev-quick-wins">
          <?php if ( $missing_title_count > 0 ) : ?>
            <li class="mev-quick-win-item">
              <span><?php echo esc_html( sprintf( /* translators: %d: number of posts */ _n( '%d post missing SEO title', '%d posts missing SEO title', $missing_title_count, 'meyvora-seo' ), $missing_title_count ) ); ?></span>
              <a href="<?php echo esc_url( $fix_links[0] ); ?>"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></a>
            </li>
          <?php endif; ?>
          <?php if ( $missing_desc_count > 0 ) : ?>
            <li class="mev-quick-win-item">
              <span><?php echo esc_html( sprintf( /* translators: %d: number of posts */ _n( '%d post missing meta description', '%d posts missing meta description', $missing_desc_count, 'meyvora-seo' ), $missing_desc_count ) ); ?></span>
              <a href="<?php echo esc_url( $fix_links[1] ); ?>"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></a>
            </li>
          <?php endif; ?>
          <?php if ( $missing_kw_count > 0 ) : ?>
            <li class="mev-quick-win-item">
              <span><?php echo esc_html( sprintf( /* translators: %d: number of posts */ _n( '%d post missing focus keyword', '%d posts missing focus keyword', $missing_kw_count, 'meyvora-seo' ), $missing_kw_count ) ); ?></span>
              <a href="<?php echo esc_url( $fix_links[2] ); ?>"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></a>
            </li>
          <?php endif; ?>
          <?php if ( $missing_title_count <= 0 && $missing_desc_count <= 0 && $missing_kw_count <= 0 ) : ?>
            <li class="mev-quick-win-item" style="border-color:transparent;">
              <span class="mev-text-muted"><?php esc_html_e( 'No quick wins from common issues. Check the issues breakdown for details.', 'meyvora-seo' ); ?></span>
            </li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

    <!-- 12-week trend -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( '12-week trend', 'meyvora-seo' ); ?></span>
        <span class="mev-badge mev-badge--violet"><?php echo count( $trend ); ?> <?php esc_html_e( 'weeks', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <?php if ( empty( $trend ) ) : ?>
          <p class="mev-text-muted"><?php esc_html_e( 'Weekly snapshots will appear here once the scheduled task has run.', 'meyvora-seo' ); ?></p>
        <?php else : ?>
          <div class="mev-trend-chart">
            <?php foreach ( $trend as $point ) : ?>
              <?php
              $ws = isset( $point['week_start'] ) ? $point['week_start'] : '';
              $sc = isset( $point['score'] ) ? (int) $point['score'] : 0;
              $h  = $max_trend_score > 0 ? round( ( $sc / $max_trend_score ) * 100 ) : 0;
              ?>
              <div class="mev-trend-bar-wrap" title="<?php echo esc_attr( $ws . ': ' . $sc ); ?>">
                <div class="mev-trend-bar" style="height:<?php echo (int) $h; ?>%;"></div>
                <span class="mev-trend-label"><?php echo esc_html( $ws ? wp_date( 'M j', strtotime( $ws ) ) : '' ); ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="mev-dashboard-sidebar">
    <!-- Issues breakdown -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Issues breakdown', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <ul class="mev-issues-list">
          <li>
            <span class="mev-issues-label"><?php esc_html_e( 'Missing SEO title', 'meyvora-seo' ); ?></span>
            <span class="mev-issues-count"><?php echo (int) ( $issues['missing_title'] ?? 0 ); ?></span>
          </li>
          <li>
            <span class="mev-issues-label"><?php esc_html_e( 'Missing description', 'meyvora-seo' ); ?></span>
            <span class="mev-issues-count"><?php echo (int) ( $issues['missing_description'] ?? 0 ); ?></span>
          </li>
          <li>
            <span class="mev-issues-label"><?php esc_html_e( 'Low score (&lt;50)', 'meyvora-seo' ); ?></span>
            <span class="mev-issues-count"><?php echo (int) ( $issues['low_score'] ?? 0 ); ?></span>
          </li>
          <li>
            <span class="mev-issues-label"><?php esc_html_e( 'Missing schema', 'meyvora-seo' ); ?></span>
            <span class="mev-issues-count"><?php echo (int) ( $issues['missing_schema'] ?? 0 ); ?></span>
          </li>
        </ul>
      </div>
    </div>

    <!-- Content stats -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Content stats', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body">
        <ul class="mev-issues-list">
          <li>
            <span class="mev-issues-label"><?php esc_html_e( 'Total indexed posts', 'meyvora-seo' ); ?></span>
            <span class="mev-issues-count"><?php echo (int) ( $content_stats['total_indexed'] ?? 0 ); ?></span>
          </li>
          <li>
            <span class="mev-issues-label"><?php esc_html_e( 'With focus keyword', 'meyvora-seo' ); ?></span>
            <span class="mev-issues-count"><?php echo (int) ( $content_stats['total_with_focus_kw'] ?? 0 ); ?></span>
          </li>
          <li>
            <span class="mev-issues-label"><?php esc_html_e( 'With OG image', 'meyvora-seo' ); ?></span>
            <span class="mev-issues-count"><?php echo (int) ( $content_stats['total_with_og_image'] ?? 0 ); ?></span>
          </li>
        </ul>
      </div>
    </div>
  </div>
</div>

</div><!-- /.wrap -->

<style>
.mev-sparkline-bar { display:flex; align-items:center; gap:8px; max-width:120px; }
.mev-sparkline-bar .mev-sparkline-fill { width:60px; height:8px; background:var(--mev-gray-200); border-radius:4px; overflow:hidden; }
.mev-sparkline-bar .mev-sparkline-fill::before { content:''; display:block; height:100%; width:var(--pct,0); background:var(--mev-success); border-radius:4px; transition:width 0.5s var(--mev-ease); }
.mev-sparkline-bar--low .mev-sparkline-fill::before { background:var(--mev-warning); }
.mev-sparkline-value { font-weight:600; font-size:13px; color:var(--mev-gray-700); min-width:24px; }
.mev-issues-list { list-style:none; margin:0; padding:0; }
.mev-issues-list li { display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--mev-border); }
.mev-issues-list li:last-child { border-bottom:none; }
.mev-issues-label { font-size:13px; color:var(--mev-gray-700); }
.mev-issues-count { font-weight:600; color:var(--mev-primary); }
.mev-trend-chart { display:flex; align-items:flex-end; gap:4px; height:120px; padding:8px 0; }
.mev-trend-bar-wrap { flex:1; display:flex; flex-direction:column; align-items:center; min-width:0; }
.mev-trend-bar { width:100%; max-width:24px; min-height:4px; background:var(--mev-primary); border-radius:4px 4px 0 0; transition:height 0.4s var(--mev-ease); }
.mev-trend-label { font-size:10px; color:var(--mev-gray-500); margin-top:4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%; }
</style>
