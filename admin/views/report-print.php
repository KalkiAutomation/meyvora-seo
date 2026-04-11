<?php
/**
 * Printable SEO report (open in new tab, then Print / Save as PDF).
 * Used for both ?meyvora_seo_print_report=1 and ?meyvora_seo_export_pdf=1.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_pdf = ! empty( $is_pdf );
$health_score = isset( $data['health_score'] ) ? (int) $data['health_score'] : 0;
$top_10       = isset( $data['top_10'] ) && is_array( $data['top_10'] ) ? $data['top_10'] : array();
$bottom_10    = isset( $data['bottom_10'] ) && is_array( $data['bottom_10'] ) ? $data['bottom_10'] : array();
$issues       = isset( $data['issues'] ) && is_array( $data['issues'] ) ? $data['issues'] : array();
$content_stats = isset( $data['content_stats'] ) && is_array( $data['content_stats'] ) ? $data['content_stats'] : array();
$trend        = isset( $data['trend'] ) && is_array( $data['trend'] ) ? $data['trend'] : array();
$gsc_connected = ! empty( $data['gsc_connected'] );
$gsc_site_summary = isset( $data['gsc_site_summary'] ) && is_array( $data['gsc_site_summary'] ) ? $data['gsc_site_summary'] : array( 'clicks' => 0, 'impressions' => 0 );
$gsc_top_queries = isset( $data['gsc_top_queries'] ) && is_array( $data['gsc_top_queries'] ) ? $data['gsc_top_queries'] : array();
$gsc_top_pages   = isset( $data['gsc_top_pages'] ) && is_array( $data['gsc_top_pages'] ) ? $data['gsc_top_pages'] : array();
$gsc_opportunities = isset( $data['gsc_opportunities'] ) && is_array( $data['gsc_opportunities'] ) ? $data['gsc_opportunities'] : array();
$decaying_pages = isset( $data['decaying_pages'] ) && is_array( $data['decaying_pages'] ) ? $data['decaying_pages'] : array();

$site_name = get_bloginfo( 'name' );
$date      = wp_date( get_option( 'date_format' ), current_time( 'timestamp' ) );
$date_short = wp_date( 'd M Y', current_time( 'timestamp' ) );
$report_period = sprintf( /* translators: number of weeks */ __( 'Last %d weeks', 'meyvora-seo' ), 12 );

$wl_logo_id = 0;
if ( function_exists( 'meyvora_seo' ) ) {
	$wl_logo_id = (int) meyvora_seo()->get_options()->get( 'white_label_logo_id', 0 );
}
$max_trend = 100;
if ( ! empty( $trend ) ) {
	$scores = array_column( $trend, 'score' );
	$max_trend = max( 100, ! empty( $scores ) ? max( array_map( 'intval', $scores ) ) : 100 );
}
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_bloginfo( 'language' ) ); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'SEO Report — %s', 'meyvora-seo' ), $site_name ) ); ?></title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, sans-serif; margin: 24px; color: #374151; font-size: 14px; }
    h1 { font-size: 22px; margin: 0 0 8px; color: #7c3aed; }
    h2 { font-size: 16px; margin: 20px 0 10px; color: #1f2937; border-bottom: 1px solid #e5e7eb; padding-bottom: 6px; }
    .mev-no-print { margin-bottom: 16px; }
    .mev-page-break { page-break-after: always; }
    .header { margin-bottom: 24px; }
    .header .sub { color: #6b7280; font-size: 13px; }
    .score-box { display: inline-block; background: #f3f4f6; padding: 12px 20px; border-radius: 10px; margin: 12px 0; }
    .score-box .num { font-size: 28px; font-weight: 700; color: #7c3aed; }
    .score-large { font-size: 48px; font-weight: 700; color: #7c3aed; line-height: 1.2; }
    table { width: 100%; border-collapse: collapse; margin: 8px 0; }
    th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #e5e7eb; }
    th { font-weight: 600; color: #6b7280; font-size: 12px; text-transform: uppercase; }
    .issues-list { list-style: none; margin: 0; padding: 0; }
    .issues-list li { padding: 6px 0; border-bottom: 1px solid #f3f4f6; display: flex; justify-content: space-between; }
    .footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #e5e7eb; font-size: 12px; color: #9ca3af; }
    .mev-trend-chart { display: flex; align-items: flex-end; gap: 4px; height: 120px; margin: 16px 0; padding: 0; list-style: none; }
    .mev-trend-bar { flex: 1; min-width: 0; background: #7c3aed; border-radius: 4px 4px 0 0; min-height: 4px; }
    .cover-page { text-align: center; padding: 60px 24px; }
    .cover-page .cover-title { font-size: 28px; font-weight: 700; color: #1f2937; margin: 24px 0 8px; }
    .cover-page .cover-sub { color: #6b7280; font-size: 15px; margin: 4px 0; }
    .cover-page .cover-date { margin-top: 32px; font-size: 13px; color: #9ca3af; }
    @media print {
      .mev-no-print { display: none !important; }
      body { font-size: 11pt; margin: 16px; }
    }
  </style>
</head>
<body>
  <div class="mev-no-print">
    <button type="button" onclick="window.print();" style="background:#7c3aed;color:#fff;border:none;padding:10px 20px;border-radius:8px;font-size:14px;cursor:pointer;font-weight:500;"><?php esc_html_e( 'Print / Save as PDF', 'meyvora-seo' ); ?></button>
  </div>

  <!-- A) COVER PAGE -->
  <div class="cover-page mev-page-break">
    <?php if ( $wl_logo_id > 0 ) : ?>
      <?php echo wp_get_attachment_image( $wl_logo_id, 'medium', false, array( 'style' => 'max-height:80px;width:auto;' ) ); ?>
    <?php else : ?>
      <div class="cover-title"><?php esc_html_e( 'Meyvora SEO Report', 'meyvora-seo' ); ?></div>
    <?php endif; ?>
    <p class="cover-sub"><?php echo esc_html( $site_name ); ?></p>
    <p class="cover-sub"><?php echo esc_html( $date_short ); ?></p>
    <p class="cover-date"><?php echo esc_html( $report_period ); ?></p>
  </div>

  <!-- B) HEALTH SCORE PAGE -->
  <div class="mev-page-break">
    <h1><?php esc_html_e( 'Health Score', 'meyvora-seo' ); ?></h1>
    <div class="score-large"><?php echo (int) $health_score; ?><span style="font-size:24px;color:#6b7280;">/100</span></div>
    <p style="margin:8px 0 16px;color:#6b7280;"><?php echo esc_html( $health_score >= 80 ? __( 'Great', 'meyvora-seo' ) : ( $health_score >= 50 ? __( 'Okay', 'meyvora-seo' ) : __( 'Poor', 'meyvora-seo' ) ) ); ?></p>

    <?php if ( ! empty( $trend ) ) : ?>
    <h2><?php esc_html_e( '12-week trend', 'meyvora-seo' ); ?></h2>
    <ul class="mev-trend-chart" aria-label="<?php esc_attr_e( 'Score trend', 'meyvora-seo' ); ?>">
      <?php foreach ( $trend as $week ) : ?>
        <?php $s = isset( $week['score'] ) ? (int) $week['score'] : 0; $pct = $max_trend > 0 ? ( $s / $max_trend * 100 ) : 0; ?>
        <li class="mev-trend-bar" style="height: <?php echo (int) $pct; ?>%;" title="<?php echo esc_attr( (string) $s ); ?>"></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Issue counts', 'meyvora-seo' ); ?></h2>
    <ul class="issues-list">
      <li><span><?php esc_html_e( 'Missing SEO title', 'meyvora-seo' ); ?></span><strong><?php echo (int) ( $issues['missing_title'] ?? 0 ); ?></strong></li>
      <li><span><?php esc_html_e( 'Missing description', 'meyvora-seo' ); ?></span><strong><?php echo (int) ( $issues['missing_description'] ?? 0 ); ?></strong></li>
      <li><span><?php esc_html_e( 'Low score (&lt;50)', 'meyvora-seo' ); ?></span><strong><?php echo (int) ( $issues['low_score'] ?? 0 ); ?></strong></li>
      <li><span><?php esc_html_e( 'Missing schema', 'meyvora-seo' ); ?></span><strong><?php echo (int) ( $issues['missing_schema'] ?? 0 ); ?></strong></li>
    </ul>
  </div>

  <!-- C) TRAFFIC PAGE (if GSC connected) -->
  <?php if ( $gsc_connected ) : ?>
  <div class="mev-page-break">
    <h1><?php esc_html_e( 'Search traffic', 'meyvora-seo' ); ?></h1>
    <p class="sub"><?php esc_html_e( 'Last 28 days — Google Search Console', 'meyvora-seo' ); ?></p>
    <div class="score-box" style="margin-right:16px;">
      <span class="num"><?php echo (int) ( $gsc_site_summary['clicks'] ?? 0 ); ?></span> <?php esc_html_e( 'clicks', 'meyvora-seo' ); ?>
    </div>
    <div class="score-box">
      <span class="num"><?php echo (int) ( $gsc_site_summary['impressions'] ?? 0 ); ?></span> <?php esc_html_e( 'impressions', 'meyvora-seo' ); ?>
    </div>

    <h2><?php esc_html_e( 'Top 5 keywords by clicks', 'meyvora-seo' ); ?></h2>
    <ol style="margin:0;padding-left:20px;">
      <?php foreach ( array_slice( $gsc_top_queries, 0, 5 ) as $row ) : ?>
        <li style="padding:4px 0;"><?php echo esc_html( isset( $row['keys'][0] ) ? $row['keys'][0] : '' ); ?> (<?php echo (int) ( $row['clicks'] ?? 0 ); ?> <?php esc_html_e( 'clicks', 'meyvora-seo' ); ?>)</li>
      <?php endforeach; ?>
      <?php if ( empty( $gsc_top_queries ) ) : ?>
        <li><?php esc_html_e( 'No data yet.', 'meyvora-seo' ); ?></li>
      <?php endif; ?>
    </ol>

    <h2><?php esc_html_e( 'Top 5 pages by clicks', 'meyvora-seo' ); ?></h2>
    <ol style="margin:0;padding-left:20px;">
      <?php foreach ( $gsc_top_pages as $row ) : ?>
        <li style="padding:4px 0;word-break:break-all;"><?php echo esc_html( isset( $row['keys'][0] ) ? $row['keys'][0] : '' ); ?> (<?php echo (int) ( $row['clicks'] ?? 0 ); ?> <?php esc_html_e( 'clicks', 'meyvora-seo' ); ?>)</li>
      <?php endforeach; ?>
      <?php if ( empty( $gsc_top_pages ) ) : ?>
        <li><?php esc_html_e( 'No data yet.', 'meyvora-seo' ); ?></li>
      <?php endif; ?>
    </ol>
  </div>
  <?php endif; ?>

  <!-- D) TOP 10 ISSUES PAGE -->
  <div class="mev-page-break">
    <h1><?php esc_html_e( 'Top 10 issues to fix', 'meyvora-seo' ); ?></h1>
    <p class="sub"><?php esc_html_e( 'Lowest-scoring pages', 'meyvora-seo' ); ?></p>
    <table>
      <thead><tr><th><?php esc_html_e( 'Post Title', 'meyvora-seo' ); ?></th><th><?php esc_html_e( 'Issue Type', 'meyvora-seo' ); ?></th><th><?php esc_html_e( 'SEO Score', 'meyvora-seo' ); ?></th><th><?php esc_html_e( 'Edit URL', 'meyvora-seo' ); ?></th></tr></thead>
      <tbody>
        <?php foreach ( array_slice( $bottom_10, 0, 10 ) as $row ) : ?>
          <tr>
            <td><?php echo esc_html( $row['title'] ?? __( '(no title)', 'meyvora-seo' ) ); ?></td>
            <td><?php echo esc_html( (int) ( $row['score'] ?? 0 ) < 50 ? __( 'Low score', 'meyvora-seo' ) : __( 'Needs improvement', 'meyvora-seo' ) ); ?></td>
            <td><?php echo (int) ( $row['score'] ?? 0 ); ?></td>
            <td><a href="<?php echo esc_url( $row['edit'] ?? '#' ); ?>"><?php esc_html_e( 'Edit', 'meyvora-seo' ); ?></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ( empty( $bottom_10 ) ) : ?>
          <tr><td colspan="4"><?php esc_html_e( 'No data yet.', 'meyvora-seo' ); ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- E) QUICK WINS PAGE -->
  <div class="mev-page-break">
    <h1><?php esc_html_e( 'Quick wins', 'meyvora-seo' ); ?></h1>

    <h2><?php esc_html_e( 'CTR opportunities', 'meyvora-seo' ); ?></h2>
    <?php if ( ! empty( $gsc_opportunities ) ) : ?>
    <ul style="margin:0;padding-left:20px;">
      <?php foreach ( $gsc_opportunities as $opp ) : ?>
        <?php /* translators: 1: position, 2: impressions number, 3: CTR percent */ ?>
        <li style="padding:4px 0;word-break:break-all;"><?php echo esc_html( wp_parse_url( $opp['url'] ?? '', PHP_URL_PATH ) ?: ( $opp['url'] ?? '' ) ); ?> — <?php echo esc_html( sprintf( __( 'Pos %1$s, %2$s impr, %3$s%% CTR', 'meyvora-seo' ), (string) ( $opp['position'] ?? 0 ), number_format_i18n( (int) ( $opp['impressions'] ?? 0 ) ), (string) ( $opp['ctr'] ?? 0 ) ) ); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php else : ?>
    <p><?php esc_html_e( 'No CTR opportunities in this period, or connect GSC.', 'meyvora-seo' ); ?></p>
    <?php endif; ?>

    <h2><?php esc_html_e( 'Content decay (traffic dropped 30%+)', 'meyvora-seo' ); ?></h2>
    <?php if ( ! empty( $decaying_pages ) ) : ?>
    <ul style="margin:0;padding-left:20px;">
      <?php foreach ( $decaying_pages as $dec ) : ?>
        <?php /* translators: 1: current period clicks, 2: previous period clicks, 3: drop percent */ ?>
        <li style="padding:4px 0;word-break:break-all;"><?php echo esc_html( wp_parse_url( $dec['url'] ?? '', PHP_URL_PATH ) ?: ( $dec['url'] ?? '' ) ); ?> — <?php echo esc_html( sprintf( __( '%1$s → %2$s clicks, %3$s%% drop', 'meyvora-seo' ), number_format_i18n( (int) ( $dec['curr'] ?? 0 ) ), number_format_i18n( (int) ( $dec['prev'] ?? 0 ) ), (string) ( $dec['drop_pct'] ?? 0 ) ) ); ?></li>
      <?php endforeach; ?>
    </ul>
    <?php else : ?>
    <p><?php esc_html_e( 'No decaying pages in this period, or connect GSC.', 'meyvora-seo' ); ?></p>
    <?php endif; ?>
  </div>

  <!-- Legacy content stats (no page break so it stays with quick wins or can be last page) -->
  <div>
    <h2><?php esc_html_e( 'Content stats', 'meyvora-seo' ); ?></h2>
    <ul class="issues-list">
      <li><span><?php esc_html_e( 'Total indexed posts', 'meyvora-seo' ); ?></span><strong><?php echo (int) ( $content_stats['total_indexed'] ?? 0 ); ?></strong></li>
      <li><span><?php esc_html_e( 'With focus keyword', 'meyvora-seo' ); ?></span><strong><?php echo (int) ( $content_stats['total_with_focus_kw'] ?? 0 ); ?></strong></li>
      <li><span><?php esc_html_e( 'With OG image', 'meyvora-seo' ); ?></span><strong><?php echo (int) ( $content_stats['total_with_og_image'] ?? 0 ); ?></strong></li>
    </ul>
    <div class="footer">
      <?php esc_html_e( 'Generated by Meyvora SEO', 'meyvora-seo' ); ?> · <?php echo esc_html( $date ); ?>
    </div>
  </div>

  <?php if ( $is_pdf ) : ?>
  <script>window.onload=function(){window.print();};</script>
  <?php else : ?>
  <script>(function(){ if (window.location.search.indexOf('print=1') !== -1) window.print(); })();</script>
  <?php endif; ?>
</body>
</html>
