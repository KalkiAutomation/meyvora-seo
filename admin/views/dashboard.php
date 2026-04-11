<?php
/**
 * Meyvora SEO Dashboard: overview cards, score distribution, quick wins, setup checklist.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- View template vars; bulk meta query.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$options    = meyvora_seo()->get_options();
$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );

// --- Bulk fetch all IDs ---
$q   = new WP_Query( array(
	'post_type'      => $post_types,
	'post_status'    => array( 'publish', 'draft', 'pending' ),
	'posts_per_page' => -1,
	'fields'         => 'ids',
	'no_found_rows'  => true,
) );
$ids         = is_array( $q->posts ) ? $q->posts : array();
$total_posts = count( $ids );

// --- Bulk fetch meta ---
$scores   = array();
$keywords = array();
$descs    = array();
$analyses = array();
if ( ! empty( $ids ) ) {
	global $wpdb;
	$id_list = implode( ',', array_map( 'intval', $ids ) );
	$meta_keys = array(
		MEYVORA_SEO_META_SCORE,
		MEYVORA_SEO_META_FOCUS_KEYWORD,
		MEYVORA_SEO_META_DESCRIPTION,
		MEYVORA_SEO_META_ANALYSIS,
	);
	$keys_placeholder = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
		WHERE post_id IN ({$id_list})
		AND meta_key IN ({$keys_placeholder})",
		...$meta_keys
	) );
	foreach ( $rows as $row ) {
		if ( $row->meta_key === MEYVORA_SEO_META_SCORE ) {
			$scores[ $row->post_id ] = (int) $row->meta_value;
		}
		if ( $row->meta_key === MEYVORA_SEO_META_FOCUS_KEYWORD ) {
			$keywords[ $row->post_id ] = $row->meta_value;
		}
		if ( $row->meta_key === MEYVORA_SEO_META_DESCRIPTION ) {
			$descs[ $row->post_id ] = $row->meta_value;
		}
		if ( $row->meta_key === MEYVORA_SEO_META_ANALYSIS ) {
			$analyses[ $row->post_id ] = $row->meta_value;
		}
	}
}

// --- Compute stats ---
$with_score = $good_count = $poor_count = $no_keyword = $no_desc = $score_sum = 0;
$bands = array( '0-20' => 0, '21-40' => 0, '41-60' => 0, '61-80' => 0, '81-100' => 0 );
foreach ( $ids as $pid ) {
	$kw_normalized = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::normalize_focus_keywords( $keywords[ $pid ] ?? '' ) : array();
	if ( empty( $kw_normalized ) ) {
		$no_keyword++;
	}
	if ( ! isset( $descs[ $pid ] ) || trim( (string) $descs[ $pid ] ) === '' ) {
		$no_desc++;
	}
	if ( isset( $scores[ $pid ] ) ) {
		$s = $scores[ $pid ];
		$with_score++;
		$score_sum += $s;
		if ( $s >= 80 ) {
			$good_count++;
			$bands['81-100']++;
		} elseif ( $s >= 61 ) {
			$bands['61-80']++;
		} elseif ( $s >= 50 ) {
			$bands['41-60']++;
		} elseif ( $s >= 21 ) {
			$bands['21-40']++;
			$poor_count++;
		} else {
			$bands['0-20']++;
			$poor_count++;
		}
	}
}
$avg_score   = $with_score > 0 ? round( $score_sum / $with_score ) : 0;
$good_pct    = $total_posts > 0 ? round( ( $good_count / $total_posts ) * 100 ) : 0;
$avg_status  = $avg_score >= 80 ? 'good' : ( $avg_score >= 50 ? 'okay' : 'poor' );
$max_band    = max( 1, max( $bands ) );

// --- Quick Wins: posts where 1 fix could gain the most points ---
$quick_wins = array();
foreach ( $ids as $pid ) {
	if ( ! isset( $analyses[ $pid ] ) ) {
		continue;
	}
	$dec = json_decode( $analyses[ $pid ], true );
	if ( ! is_array( $dec ) || empty( $dec['results'] ) ) {
		continue;
	}
	$gain   = 0;
	$action = '';
	foreach ( $dec['results'] as $r ) {
		if ( ( $r['status'] ?? '' ) === 'fail' && ( $r['weight'] ?? 0 ) > $gain ) {
			$gain   = (int) ( $r['weight'] ?? 0 );
			$action = $r['message'] ?? $r['label'] ?? '';
		}
	}
	if ( $gain > 0 ) {
		$quick_wins[ $pid ] = array(
			'title'  => get_the_title( $pid ) ?: __( '(no title)', 'meyvora-seo' ),
			'score'  => $scores[ $pid ] ?? 0,
			'gain'   => $gain,
			'action' => $action,
			'edit'   => get_edit_post_link( $pid, 'raw' ) ?? '#',
		);
	}
}
uasort( $quick_wins, function ( $a, $b ) {
	return $b['gain'] <=> $a['gain'];
} );
$quick_wins = array_slice( $quick_wins, 0, 5, true );

// --- Needing attention: prefer quick_wins with score < 60, fallback to WP_Query ---
$attention_list = array();
foreach ( $quick_wins as $pid => $win ) {
	if ( ( (int) ( $win['score'] ?? 0 ) ) < 60 ) {
		$attention_list[ $pid ] = $win;
		if ( count( $attention_list ) >= 5 ) {
			break;
		}
	}
}
if ( empty( $attention_list ) ) {
	$needing_attention = get_posts( array(
		'post_type'      => $post_types,
		'post_status'    => array( 'publish', 'draft', 'pending' ),
		'posts_per_page' => 5,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'meta_query'     => array(
			array(
				'key'     => MEYVORA_SEO_META_SCORE,
				'value'   => 60,
				'compare' => '<',
				'type'    => 'NUMERIC',
			),
		),
	) );
	foreach ( $needing_attention as $p ) {
		$pid   = (int) $p->ID;
		$sc    = isset( $scores[ $pid ] ) ? (int) $scores[ $pid ] : 0;
		$first = '';
		$gain  = 0;
		if ( isset( $analyses[ $pid ] ) ) {
			$dec = json_decode( $analyses[ $pid ], true );
			if ( is_array( $dec ) && ! empty( $dec['results'] ) ) {
				foreach ( $dec['results'] as $r ) {
					if ( ( $r['status'] ?? '' ) === 'fail' ) {
						$first = $r['message'] ?? $r['label'] ?? '';
						$gain  = (int) ( $r['weight'] ?? 0 );
						break;
					}
				}
			}
		}
		$attention_list[ $pid ] = array(
			'title'  => $p->post_title ?: __( '(no title)', 'meyvora-seo' ),
			'score'  => $sc,
			'gain'   => $gain,
			'action' => $first,
			'edit'   => get_edit_post_link( $pid, 'raw' ) ?: '#',
		);
	}
}
$attention_list = array_slice( $attention_list, 0, 5, true );
$bulk_editor_poor_url = admin_url( 'admin.php?page=meyvora-seo-bulk-editor&score=poor' );

// --- Setup checklist ---
$setup = array(
	array(
		'done'  => get_bloginfo( 'name' ) !== '' && $options->get( 'title_separator', '' ) !== '',
		'label' => __( 'Set site title & separator', 'meyvora-seo' ),
		'href'  => admin_url( 'admin.php?page=meyvora-seo-settings#tab-general' ),
	),
	array(
		'done'  => (string) $options->get( 'schema_organization_name', '' ) !== '',
		'label' => __( 'Configure Organization schema', 'meyvora-seo' ),
		'href'  => admin_url( 'admin.php?page=meyvora-seo-settings#tab-schema' ),
	),
	array(
		'done'  => (int) $options->get( 'schema_organization_logo', 0 ) > 0,
		'label' => __( 'Upload organization logo', 'meyvora-seo' ),
		'href'  => admin_url( 'admin.php?page=meyvora-seo-settings#tab-schema' ),
	),
	array(
		'done'  => (bool) $options->get( 'sitemap_enabled', true ),
		'label' => __( 'Verify sitemap is enabled', 'meyvora-seo' ),
		'href'  => admin_url( 'admin.php?page=meyvora-seo-settings#tab-sitemap' ),
	),
	array(
		'done'  => (string) $options->get( 'schema_sameas_facebook', '' ) !== '' || (string) $options->get( 'schema_sameas_twitter', '' ) !== '',
		'label' => __( 'Add social profile URLs', 'meyvora-seo' ),
		'href'  => admin_url( 'admin.php?page=meyvora-seo-settings#tab-social' ),
	),
);
$setup_done  = count( array_filter( array_column( $setup, 'done' ) ) );
$setup_total = count( $setup );
$setup_pct   = $setup_total > 0 ? round( ( $setup_done / $setup_total ) * 100 ) : 0;

// Count orphan pages
$orphan_count = 0;
if ( class_exists( 'Meyvora_SEO_Internal_Links' ) ) {
	require_once MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-internal-links.php';
	$il        = new Meyvora_SEO_Internal_Links( meyvora_seo()->get_loader(), meyvora_seo()->get_options() );
	$il_data   = $il->get_link_analysis_data( 1, 99999 );
	$all_rows  = isset( $il_data['rows'] ) ? $il_data['rows'] : array();
	$orphan_count = count( array_filter( $all_rows, function ( $r ) {
		return ( $r['status'] ?? '' ) === 'orphan';
	} ) );
}

// --- Gauge math ---
$circumference = 220;
$offset        = $circumference - ( $avg_score / 100 ) * $circumference;

$last_analyzed = (int) get_option( 'meyvora_seo_last_analyzed', 0 );
$last_analyzed_text = $last_analyzed > 0
	? sprintf( /* translators: %s: human-readable time difference */ __( 'Last analyzed: %s ago', 'meyvora-seo' ), human_time_diff( $last_analyzed, time() ) )
	: __( 'Overview of your content', 'meyvora-seo' );
?>
<div class="wrap meyvora-dashboard">

<?php
$wl_logo_id  = (int) $options->get( 'white_label_logo_id', 0 );
$wl_title    = (string) $options->get( 'white_label_dashboard_title', '' );
$logo_html   = '';
$title_text  = '';
if ( $wl_logo_id > 0 ) {
	$logo_html = wp_get_attachment_image( $wl_logo_id, 'medium', false, array( 'class' => 'mev-page-logo-img', 'style' => 'max-height:40px;width:auto;' ) );
} elseif ( $wl_title !== '' ) {
	$title_text = $wl_title;
} else {
	$title_text = __( 'Meyvora SEO Dashboard', 'meyvora-seo' );
}
if ( $title_text !== '' ) {
	$title_text = esc_html( $title_text );
}
?>
<!-- Page Header -->
<div class="mev-page-header">
  <div class="mev-page-header-left">
    <?php if ( $logo_html !== '' ) : ?>
      <div class="mev-page-logo mev-page-logo--img"><?php echo wp_kses_post( $logo_html ); ?></div>
    <?php else : ?>
      <div class="mev-page-logo">M</div>
    <?php endif; ?>
    <div>
      <div class="mev-page-title"><?php echo wp_kses_post( $title_text ); ?></div>
      <div class="mev-page-subtitle"><?php echo esc_html( $last_analyzed_text ); ?></div>
    </div>
  </div>
  <nav class="mev-page-nav">
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>" class="active"><?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-reports' ) ); ?>"><?php esc_html_e( 'Reports', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-automation' ) ); ?>"><?php esc_html_e( 'Automation', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>"><?php esc_html_e( 'SEO Audit', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-redirects' ) ); ?>"><?php esc_html_e( 'Redirects', 'meyvora-seo' ); ?></a>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></a>
  </nav>
</div>

<!-- Hero Stats Grid -->
<div class="mev-dashboard-grid">

  <!-- Site Score -->
  <div class="mev-stat-card mev-stat-card--violet">
    <div class="mev-stat-label"><?php esc_html_e( 'Site SEO Score', 'meyvora-seo' ); ?></div>
    <div style="display:flex;align-items:center;gap:14px;margin-top:8px;">
      <svg class="mev-gauge mev-gauge--dashboard" width="90" height="90" viewBox="0 0 80 80">
        <circle class="mev-gauge-track" cx="40" cy="40" r="32" stroke-width="7"/>
        <circle class="mev-gauge-fill mev-gauge-fill--<?php echo esc_attr( $avg_status ); ?>"
          cx="40" cy="40" r="32" stroke-width="7"
          stroke-dasharray="<?php echo esc_attr( $circumference ); ?>"
          stroke-dashoffset="<?php echo esc_attr( $offset ); ?>"/>
        <text class="mev-gauge-inner" x="40" y="41" text-anchor="middle"><?php echo (int) $avg_score; ?></text>
        <text class="mev-gauge-inner-label" x="40" y="56" text-anchor="middle">/100</text>
      </svg>
      <div>
        <div class="mev-stat-value" style="font-size:22px;"><?php echo esc_html( $avg_score >= 80 ? __( 'Great', 'meyvora-seo' ) : ( $avg_score >= 50 ? __( 'Okay', 'meyvora-seo' ) : __( 'Poor', 'meyvora-seo' ) ) ); ?></div>
        <div class="mev-stat-subvalue"><?php printf( /* translators: %d: number of posts with a score */ esc_html__( '%d posts analyzed', 'meyvora-seo' ), (int) $with_score ); ?></div>
      </div>
    </div>
  </div>

  <!-- Total Content -->
  <div class="mev-stat-card mev-stat-card--cyan">
    <div class="mev-stat-icon" style="--mev-stat-icon-bg:#CFFAFE;--mev-stat-color:#0891B2;"><?php echo wp_kses_post( meyvora_seo_icon( 'file_text', array( 'width' => 22, 'height' => 22 ) ) ); ?></div>
    <div class="mev-stat-label"><?php esc_html_e( 'Total Content', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-value"><?php echo (int) $total_posts; ?></div>
    <div class="mev-stat-subvalue"><?php printf( /* translators: %d: number of posts analyzed */ esc_html__( '%d analyzed', 'meyvora-seo' ), (int) $with_score ); ?></div>
  </div>

  <!-- Good Score -->
  <div class="mev-stat-card mev-stat-card--green">
    <div class="mev-stat-icon" style="--mev-stat-icon-bg:#D1FAE5;--mev-stat-color:#059669;"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 22, 'height' => 22 ) ) ); ?></div>
    <div class="mev-stat-label"><?php esc_html_e( 'Score 80+', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-value"><?php echo (int) $good_count; ?></div>
    <div class="mev-stat-subvalue"><?php echo (int) $good_pct; ?>% <?php esc_html_e( 'of total', 'meyvora-seo' ); ?></div>
    <div style="margin-top:8px;height:4px;background:var(--mev-success-light);border-radius:2px;overflow:hidden;">
      <div style="height:100%;width:<?php echo (int) $good_pct; ?>%;background:var(--mev-success);border-radius:2px;transition:width 1s var(--mev-ease);"></div>
    </div>
  </div>

  <!-- Needs Attention -->
  <div class="mev-stat-card mev-stat-card--red">
    <div class="mev-stat-icon" style="--mev-stat-icon-bg:#FEE2E2;--mev-stat-color:#DC2626;"><?php echo wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 22, 'height' => 22 ) ) ); ?></div>
    <div class="mev-stat-label"><?php esc_html_e( 'Need Attention', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-value"><?php echo (int) $poor_count; ?></div>
    <div class="mev-stat-subvalue"><?php esc_html_e( 'Score below 50', 'meyvora-seo' ); ?></div>
  </div>

  <!-- Missing Data -->
  <div class="mev-stat-card mev-stat-card--orange">
    <div class="mev-stat-icon" style="--mev-stat-icon-bg:#FEF3C7;--mev-stat-color:#D97706;"><?php echo wp_kses_post( meyvora_seo_icon( 'key', array( 'width' => 22, 'height' => 22 ) ) ); ?></div>
    <div class="mev-stat-label"><?php esc_html_e( 'Missing Data', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-value"><?php echo (int) max( $no_keyword, $no_desc ); ?></div>
    <div class="mev-stat-subvalue"><?php printf( /* translators: 1: count of posts with no keyword, 2: count of posts with no description */ esc_html__( '%1$d no keyword · %2$d no desc', 'meyvora-seo' ), (int) $no_keyword, (int) $no_desc ); ?></div>
  </div>

  <!-- Orphan pages -->
  <div class="mev-stat-card mev-stat-card--cyan">
    <div class="mev-stat-icon" style="--mev-stat-icon-bg:#E0F2FE;--mev-stat-color:#0284C7;"><?php echo wp_kses_post( meyvora_seo_icon( 'link', array( 'width' => 22, 'height' => 22 ) ) ); ?></div>
    <div class="mev-stat-label"><?php esc_html_e( 'Orphan pages', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-value"><?php echo (int) $orphan_count; ?></div>
    <div class="mev-stat-subvalue"><a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-link-analysis' ) ); ?>"><?php esc_html_e( 'View all orphans →', 'meyvora-seo' ); ?></a></div>
  </div>

</div><!-- /.mev-dashboard-grid -->

<?php
$traffic_data = isset( $data ) && is_array( $data ) ? $data : array();
$stale_posts  = isset( $data['stale_posts'] ) ? $data['stale_posts'] : array();
$gsc_connected = ! empty( $traffic_data['gsc_connected'] );
$ga4_connected = ! empty( $traffic_data['ga4_connected'] );
$traffic_has_any = $gsc_connected || $ga4_connected;
$gsc_summary = isset( $traffic_data['gsc_summary'] ) && is_array( $traffic_data['gsc_summary'] ) ? $traffic_data['gsc_summary'] : null;
$ga4_top_posts = isset( $traffic_data['ga4_top_posts'] ) && is_array( $traffic_data['ga4_top_posts'] ) ? $traffic_data['ga4_top_posts'] : array();
$ctr_opportunities = isset( $traffic_data['ctr_opportunities'] ) && is_array( $traffic_data['ctr_opportunities'] ) ? $traffic_data['ctr_opportunities'] : array();
$decaying_pages_count = isset( $traffic_data['decaying_pages_count'] ) ? (int) $traffic_data['decaying_pages_count'] : 0;
$rank_tracker_url = admin_url( 'admin.php?page=meyvora-seo-rank-tracker' );
$reports_decay_url = admin_url( 'admin.php?page=meyvora-seo-reports#content-decay' );
?>

<!-- Traffic Insights (only when GA4 or GSC connected) -->
<?php if ( $traffic_has_any ) : ?>
<h2 class="mev-dashboard-section-title" style="margin:24px 0 12px;font-size:16px;font-weight:600;color:var(--mev-gray-800);"><?php esc_html_e( 'Traffic Insights', 'meyvora-seo' ); ?></h2>
<div class="mev-dashboard-grid mev-dashboard-grid--traffic" style="margin-top:12px;">
  <div class="mev-stat-card mev-stat-card--cyan">
    <?php if ( $gsc_connected && $gsc_summary !== null ) : ?>
    <div class="mev-stat-label"><?php esc_html_e( 'Search Console (28 days)', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-value"><?php echo (int) $gsc_summary['clicks']; ?> <?php esc_html_e( 'clicks', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-subvalue"><?php echo (int) $gsc_summary['impressions']; ?> <?php esc_html_e( 'impressions', 'meyvora-seo' ); ?></div>
    <?php else : ?>
    <div class="mev-stat-label"><?php esc_html_e( 'Search Console', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-subvalue"><?php esc_html_e( 'No data yet.', 'meyvora-seo' ); ?></div>
    <?php endif; ?>
  </div>
  <div class="mev-stat-card" style="grid-column: span 2;">
    <div class="mev-stat-label"><?php esc_html_e( 'Top 5 pages by views (GA4)', 'meyvora-seo' ); ?></div>
    <?php if ( $ga4_connected && ! empty( $ga4_top_posts ) ) : ?>
    <ol class="mev-top-pages-list" style="margin:8px 0 0;padding-left:20px;font-size:13px;">
      <?php foreach ( $ga4_top_posts as $i => $item ) : ?>
      <li style="margin-bottom:4px;">
        <span class="mev-top-pages-path"><?php echo esc_html( $item['path'] ?: '/' ); ?></span>
        <span style="color:var(--mev-gray-500);"> — <?php echo (int) $item['views']; ?> <?php esc_html_e( 'views', 'meyvora-seo' ); ?></span>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php elseif ( $ga4_connected ) : ?>
    <div class="mev-stat-subvalue" style="margin-top:8px;"><?php esc_html_e( 'No pageviews data yet.', 'meyvora-seo' ); ?></div>
    <?php else : ?>
    <div class="mev-stat-subvalue" style="margin-top:8px;"><?php esc_html_e( 'Connect GA4 (Advanced) in Settings → Integrations.', 'meyvora-seo' ); ?></div>
    <?php endif; ?>
  </div>
  <?php if ( $gsc_connected ) : ?>
  <div class="mev-stat-card mev-stat-card--orange">
    <div class="mev-stat-icon" style="--mev-stat-icon-bg:#FEF3C7;--mev-stat-color:#D97706;"><?php echo wp_kses_post( meyvora_seo_icon( 'activity', array( 'width' => 22, 'height' => 22 ) ) ); ?></div>
    <div class="mev-stat-label"><?php esc_html_e( 'Content Decay', 'meyvora-seo' ); ?></div>
    <div class="mev-stat-value"><?php echo (int) $decaying_pages_count; ?></div>
    <div class="mev-stat-subvalue"><a href="<?php echo esc_url( $reports_decay_url ); ?>"><?php esc_html_e( 'View in Reports →', 'meyvora-seo' ); ?></a></div>
  </div>
  <?php endif; ?>
</div>

<?php if ( $gsc_connected ) : ?>
<div class="mev-card" style="margin-top:16px;">
  <div class="mev-card-header">
    <span class="mev-card-title"><?php esc_html_e( 'CTR Opportunities', 'meyvora-seo' ); ?></span>
  </div>
  <div class="mev-card-body">
    <?php if ( empty( $ctr_opportunities ) ) : ?>
    <p style="margin:0;color:var(--mev-gray-600);font-size:13px;"><?php esc_html_e( 'No CTR opportunities detected — all tracked pages have healthy CTR.', 'meyvora-seo' ); ?></p>
    <?php else : ?>
    <ul class="mev-top-pages-list" style="margin:0;padding-left:20px;font-size:13px;list-style:none;padding-left:0;">
      <?php foreach ( $ctr_opportunities as $opp ) :
        $url_display = $opp['url'];
        if ( strpos( $url_display, '://' ) !== false ) {
          $parsed = wp_parse_url( $opp['url'] );
          $url_display = isset( $parsed['path'] ) ? $parsed['path'] : '/' . ltrim( str_replace( home_url(), '', $opp['url'] ), '/' );
        }
        $url_display = $url_display ?: '/';
        $estimate = max( 0, (int) round( (float) $opp['impressions'] * 0.10 - (int) ( $opp['clicks'] ?? 0 ) ) );
      ?>
      <li style="margin-bottom:14px;padding-bottom:14px;border-bottom:1px solid var(--mev-border);">
        <a href="<?php echo esc_url( $rank_tracker_url ); ?>" style="font-weight:600;color:var(--mev-primary);text-decoration:none;"><?php echo esc_html( $url_display ); ?></a>
        <div style="margin-top:4px;font-size:12px;color:var(--mev-gray-500);">
          <?php printf( /* translators: 1: position number */ esc_html__( 'Position: %1$s', 'meyvora-seo' ), esc_html( (string) $opp['position'] ) ); ?>
          &nbsp;|&nbsp;
          <?php printf( /* translators: 1: number of impressions */ esc_html__( 'Impressions: %1$s', 'meyvora-seo' ), esc_html( number_format_i18n( $opp['impressions'] ) ) ); ?>
          &nbsp;|&nbsp;
          <?php printf( /* translators: 1: CTR percentage */ esc_html__( 'CTR: %1$s%%', 'meyvora-seo' ), esc_html( (string) $opp['ctr'] ) ); ?>
        </div>
        <?php if ( $estimate > 0 ) : ?>
        <div style="margin-top:4px;font-size:12px;color:var(--mev-gray-600);">
          <?php printf( /* translators: %d: estimated clicks per month */ esc_html__( 'Improving this title/description could gain ~%d clicks/month', 'meyvora-seo' ), (int) $estimate ); ?>
        </div>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php else : ?>
<h2 class="mev-dashboard-section-title" style="margin:24px 0 12px;font-size:16px;font-weight:600;color:var(--mev-gray-800);"><?php esc_html_e( 'Traffic Insights', 'meyvora-seo' ); ?></h2>
<div class="mev-card mev-empty-state" style="margin-top:12px;padding:24px 20px;">
  <div class="mev-empty-state-desc"><?php esc_html_e( 'Connect Google Search Console or GA4 in Settings → Integrations to see traffic data here.', 'meyvora-seo' ); ?></div>
</div>
<?php endif; ?>

<!-- Body: 2 columns -->
<div class="mev-dashboard-body">

  <div class="mev-dashboard-main">

    <!-- Score Distribution Chart -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Score Distribution', 'meyvora-seo' ); ?></span>
        <span class="mev-badge mev-badge--violet"><?php echo (int) $total_posts; ?> posts</span>
      </div>
      <div class="mev-card-body">
        <?php
        $band_colors = array( '0-20' => '#EF4444', '21-40' => '#F97316', '41-60' => '#EAB308', '61-80' => '#06B6D4', '81-100' => '#059669' );
        $delays      = array( 0, 0.1, 0.2, 0.3, 0.4 );
        $di          = 0;
        foreach ( $bands as $label => $count ) :
            $pct   = $max_band > 0 ? round( ( $count / $max_band ) * 100 ) : 0;
            $delay = $delays[ $di++ ];
            $bg    = $band_colors[ $label ] ?? '#ccc';
        ?>
        <div class="mev-chart-row">
          <div class="mev-chart-label"><?php echo esc_html( $label ); ?></div>
          <div class="mev-chart-track">
            <div class="mev-chart-bar mev-chart-bar--<?php echo esc_attr( str_replace( ' ', '', $label ) ); ?>" style="--bar-pct:<?php echo (int) $pct; ?>%;--bar-delay:<?php echo esc_attr( $delay ); ?>s;background:<?php echo esc_attr( $bg ); ?>;"></div>
          </div>
          <div class="mev-chart-count"><?php echo (int) $count; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Needs Attention (cards) -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Posts Needing Attention', 'meyvora-seo' ); ?></span>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'View All', 'meyvora-seo' ); ?></a>
      </div>
      <?php if ( ! empty( $attention_list ) ) : ?>
      <div class="mev-attention-cards">
        <?php foreach ( $attention_list as $pid => $item ) :
          $sc   = (int) ( $item['score'] ?? 0 );
          $pill_class = $sc < 30 ? 'mev-score-pill--red' : 'mev-score-pill--amber';
          $action_label = $item['action'] ? ( $item['gain'] > 0
            ? sprintf( /* translators: 1: action description, 2: points to gain */ __( 'Missing: %1$s (+%2$d pts)', 'meyvora-seo' ), $item['action'], (int) $item['gain'] )
            : $item['action'] )
            : '';
          $edit_url = $item['edit'] ?? get_edit_post_link( $pid, 'raw' ) ?: '#';
        ?>
        <div class="mev-attention-card">
          <span class="mev-score-pill <?php echo esc_attr( $pill_class ); ?>"><?php echo $sc > 0 ? esc_html( (string) (int) $sc ) : '—'; ?></span>
          <div class="mev-attention-card-body">
            <a href="<?php echo esc_url( $edit_url ); ?>" class="mev-attention-card-title"><?php echo esc_html( $item['title'] ); ?></a>
            <?php if ( $action_label !== '' ) : ?>
              <span class="mev-attention-card-action"><?php echo esc_html( $action_label ); ?></span>
            <?php endif; ?>
          </div>
          <a href="<?php echo esc_url( $edit_url ); ?>" class="mev-attention-card-fix"><?php esc_html_e( 'Fix it →', 'meyvora-seo' ); ?></a>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mev-card-footer" style="padding:12px 16px;border-top:1px solid var(--mev-border);">
        <a href="<?php echo esc_url( $bulk_editor_poor_url ); ?>" class="mev-attention-see-all"><?php esc_html_e( 'See all low-scoring posts', 'meyvora-seo' ); ?></a>
      </div>
      <?php else : ?>
      <div class="mev-card-body" style="text-align:center;padding:32px;color:var(--mev-gray-400);">
        <div style="font-size:32px;margin-bottom:8px;"><?php echo wp_kses_post( meyvora_seo_icon( 'party_popper', array( 'width' => 32, 'height' => 32 ) ) ); ?></div>
        <div style="font-weight:600;"><?php esc_html_e( 'All posts score 60 or higher!', 'meyvora-seo' ); ?></div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /.mev-dashboard-main -->

  <div class="mev-dashboard-side">

    <!-- Setup Progress -->
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Setup', 'meyvora-seo' ); ?></span>
        <div style="display:flex;align-items:center;gap:6px;">
          <svg class="mev-progress-ring" width="36" height="36" viewBox="0 0 36 36">
            <circle class="mev-progress-ring-track" cx="18" cy="18" r="15" stroke-width="3"/>
            <circle class="mev-progress-ring-fill" cx="18" cy="18" r="15" stroke-width="3"
              stroke-dasharray="94.2"
              stroke-dashoffset="<?php echo esc_attr( 94.2 - ( $setup_pct / 100 ) * 94.2 ); ?>"/>
          </svg>
          <span style="font-size:11px;font-weight:700;color:var(--mev-primary);"><?php echo (int) $setup_done; ?>/<?php echo (int) $setup_total; ?></span>
        </div>
      </div>
      <div class="mev-card-body" style="padding-top:12px;">
        <?php foreach ( $setup as $item ) : ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--mev-border);font-size:13px;">
          <span style="font-size:16px;flex-shrink:0;"><?php echo wp_kses_post( $item['done'] ? meyvora_seo_icon( 'circle_check', array( 'width' => 18, 'height' => 18 ) ) : meyvora_seo_icon( 'square', array( 'width' => 18, 'height' => 18 ) ) ); ?></span>
          <?php if ( $item['done'] ) : ?>
            <span style="color:var(--mev-gray-500);text-decoration:line-through;"><?php echo esc_html( $item['label'] ); ?></span>
          <?php else : ?>
            <a href="<?php echo esc_url( $item['href'] ); ?>" style="color:var(--mev-gray-800);font-weight:500;"><?php echo esc_html( $item['label'] ); ?></a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ( $setup_done < $setup_total ) : ?>
        <div style="margin-top:12px;">
          <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary" style="width:100%;justify-content:center;"><?php esc_html_e( 'Complete Setup', 'meyvora-seo' ); ?></a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ( class_exists( 'WooCommerce' ) && method_exists( 'Meyvora_SEO_WooCommerce', 'get_product_seo_stats' ) ) :
      $wc_seo_stats = Meyvora_SEO_WooCommerce::get_product_seo_stats();
      $bulk_editor_products_poor = admin_url( 'admin.php?page=meyvora-seo-bulk-editor&post_type=product&score=poor' );
      $bulk_editor_products = admin_url( 'admin.php?page=meyvora-seo-bulk-editor&post_type=product' );
    ?>
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'WooCommerce SEO', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body" style="padding-top:12px;">
        <div style="display:flex;flex-direction:column;gap:10px;">
          <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--mev-border);">
            <span style="font-size:13px;color:var(--mev-gray-700);"><?php esc_html_e( 'Products with SEO score &lt; 50', 'meyvora-seo' ); ?></span>
            <a href="<?php echo esc_url( $bulk_editor_products_poor ); ?>" style="font-size:14px;font-weight:700;color:var(--mev-primary);text-decoration:none;"><?php echo (int) $wc_seo_stats['poor_score']; ?></a>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--mev-border);">
            <span style="font-size:13px;color:var(--mev-gray-700);"><?php esc_html_e( 'Products missing OG image', 'meyvora-seo' ); ?></span>
            <span style="font-size:14px;font-weight:700;color:var(--mev-gray-800);"><?php echo (int) $wc_seo_stats['no_og_image']; ?></span>
          </div>
          <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--mev-border);">
            <span style="font-size:13px;color:var(--mev-gray-700);"><?php esc_html_e( 'Products missing focus keyword', 'meyvora-seo' ); ?></span>
            <span style="font-size:14px;font-weight:700;color:var(--mev-gray-800);"><?php echo (int) $wc_seo_stats['no_keyword']; ?></span>
          </div>
        </div>
        <div style="margin-top:14px;">
          <a href="<?php echo esc_url( $bulk_editor_products ); ?>" class="mev-btn mev-btn--primary mev-btn--sm" style="width:100%;justify-content:center;"><?php esc_html_e( 'Fix in Bulk Editor →', 'meyvora-seo' ); ?></a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Quick Wins -->
    <?php if ( ! empty( $quick_wins ) ) : ?>
    <div class="mev-card">
      <div class="mev-card-header">
        <span class="mev-card-title"><?php esc_html_e( 'Quick Wins', 'meyvora-seo' ); ?></span>
        <span style="font-size:11px;color:var(--mev-gray-400);"><?php esc_html_e( 'Fix one thing, gain points', 'meyvora-seo' ); ?></span>
      </div>
      <div class="mev-card-body" style="padding-top:8px;padding-bottom:8px;">
        <?php foreach ( $quick_wins as $pid => $win ) : ?>
        <div class="mev-quick-win-item">
          <div class="mev-quick-win-pts">+<?php echo (int) $win['gain']; ?></div>
          <div class="mev-quick-win-body">
            <div class="mev-quick-win-title"><?php echo esc_html( $win['title'] ); ?></div>
            <div class="mev-quick-win-action"><?php echo esc_html( $win['action'] ); ?></div>
          </div>
          <a href="<?php echo esc_url( $win['edit'] ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></a>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /.mev-dashboard-side -->

</div><!-- /.mev-dashboard-body -->

<?php
$type_stats = array();
foreach ( $ids as $pid ) {
	$ptype = get_post_type( $pid ) ?: 'post';
	if ( ! isset( $type_stats[ $ptype ] ) ) {
		$type_stats[ $ptype ] = array( 'total' => 0, 'good' => 0, 'analyzed' => 0 );
	}
	$type_stats[ $ptype ]['total']++;
	if ( isset( $scores[ $pid ] ) ) {
		$type_stats[ $ptype ]['analyzed']++;
		if ( $scores[ $pid ] >= 80 ) {
			$type_stats[ $ptype ]['good']++;
		}
	}
}

$recent_posts = get_posts( array(
	'post_type'      => $post_types,
	'post_status'    => 'publish',
	'posts_per_page' => 8,
	'orderby'        => 'modified',
	'order'          => 'DESC',
) );

$top_scored = array();
foreach ( $ids as $pid ) {
	if ( isset( $scores[ $pid ] ) && $scores[ $pid ] >= 70 ) {
		$top_scored[ $pid ] = $scores[ $pid ];
	}
}
arsort( $top_scored );
$top_scored = array_slice( $top_scored, 0, 5, true );
?>

<!-- Row 2: Extended data -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;margin-top:24px;">

  <div class="mev-card">
    <div class="mev-card-header">
      <span class="mev-card-title"><?php echo wp_kses_post( meyvora_seo_icon( 'file_text', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php esc_html_e( 'Content by Type', 'meyvora-seo' ); ?></span>
    </div>
    <div class="mev-card-body">
      <?php foreach ( $type_stats as $ptype => $stat ) :
        $good_pct = $stat['total'] > 0 ? round( ( $stat['good'] / $stat['total'] ) * 100 ) : 0;
      ?>
      <div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
          <span style="font-size:12px;font-weight:600;color:var(--mev-gray-700);text-transform:capitalize;"><?php echo esc_html( $ptype ); ?></span>
          <div style="display:flex;gap:5px;">
            <span style="background:var(--mev-primary-light);color:var(--mev-primary);padding:1px 7px;border-radius:9999px;font-size:10px;font-weight:700;"><?php echo (int) $stat['total']; ?> total</span>
            <span style="background:var(--mev-success-light);color:var(--mev-success);padding:1px 7px;border-radius:9999px;font-size:10px;font-weight:700;"><?php echo (int) $stat['good']; ?> good</span>
          </div>
        </div>
        <div style="background:var(--mev-gray-100);border-radius:4px;height:7px;overflow:hidden;">
          <div style="width:<?php echo (int) $good_pct; ?>%;height:100%;background:linear-gradient(90deg,var(--mev-success),var(--mev-accent));border-radius:4px;transition:width 1s var(--mev-ease);"></div>
        </div>
        <div style="font-size:10px;color:var(--mev-gray-400);margin-top:3px;"><?php echo (int) $good_pct; ?>% <?php esc_html_e( 'scoring 80+', 'meyvora-seo' ); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="mev-card">
    <div class="mev-card-header">
      <span class="mev-card-title"><?php echo wp_kses_post( meyvora_seo_icon( 'clock', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php esc_html_e( 'Recently Updated', 'meyvora-seo' ); ?></span>
    </div>
    <div class="mev-card-body" style="padding-top:8px;padding-bottom:8px;">
      <?php foreach ( $recent_posts as $rp ) :
        $rsc = isset( $scores[ $rp->ID ] ) ? $scores[ $rp->ID ] : null;
        $rsc_cls = $rsc !== null ? ( $rsc >= 80 ? 'good' : ( $rsc >= 50 ? 'okay' : 'poor' ) ) : 'none';
      ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--mev-border);">
        <span class="mev-score-pill mev-score-pill--<?php echo esc_attr( $rsc_cls ); ?>" style="min-width:34px;font-size:11px;"><?php echo $rsc !== null ? (int) $rsc : '—'; ?></span>
        <div style="flex:1;min-width:0;">
          <a href="<?php echo esc_url( get_edit_post_link( $rp->ID, 'raw' ) ?: '#' ); ?>" style="font-size:12px;font-weight:600;color:var(--mev-gray-800);text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?php echo esc_html( $rp->post_title ?: '(no title)' ); ?>
          </a>
          <div style="font-size:10px;color:var(--mev-gray-400);"><?php echo esc_html( human_time_diff( strtotime( $rp->post_modified ), time() ) ); ?> <?php esc_html_e( 'ago', 'meyvora-seo' ); ?></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="mev-card">
    <h3 style="margin:0 0 8px;font-size:16px;font-weight:700;"><?php esc_html_e( 'Content Freshness — Posts Not Updated in 180+ Days', 'meyvora-seo' ); ?></h3>
    <p style="color:#6b7280;font-size:13px;margin:0 0 12px;">
      <?php esc_html_e( 'Freshness is a quality signal. These posts may need a content review.', 'meyvora-seo' ); ?></p>
    <table class="mev-table" style="width:100%;border-collapse:collapse;">
      <thead><tr><th style="text-align:left;padding:8px 0;border-bottom:1px solid var(--mev-border);"><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></th><th style="text-align:left;padding:8px 0;border-bottom:1px solid var(--mev-border);"><?php esc_html_e( 'Days Since Update', 'meyvora-seo' ); ?></th><th style="text-align:left;padding:8px 0;border-bottom:1px solid var(--mev-border);"><?php esc_html_e( 'Action', 'meyvora-seo' ); ?></th></tr></thead>
      <tbody>
        <?php if ( empty( $stale_posts ) ) : ?>
        <tr><td colspan="3" style="padding:12px 0;color:var(--mev-gray-500);font-size:13px;"><?php esc_html_e( 'No published posts older than 180 days (excluding noindex).', 'meyvora-seo' ); ?></td></tr>
        <?php else : ?>
        <?php foreach ( $stale_posts as $item ) : ?>
        <tr>
          <td style="padding:8px 0;border-bottom:1px solid var(--mev-border);"><?php echo esc_html( $item['title'] ); ?></td>
          <td style="padding:8px 0;border-bottom:1px solid var(--mev-border);"><?php echo (int) $item['days_old']; ?> <?php esc_html_e( 'days', 'meyvora-seo' ); ?></td>
          <td style="padding:8px 0;border-bottom:1px solid var(--mev-border);"><a href="<?php echo esc_url( $item['edit_link'] ?: '#' ); ?>"><?php esc_html_e( 'Edit', 'meyvora-seo' ); ?></a></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="mev-card">
    <div class="mev-card-header">
      <span class="mev-card-title"><?php echo wp_kses_post( meyvora_seo_icon( 'activity', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php esc_html_e( 'SEO Health', 'meyvora-seo' ); ?></span>
    </div>
    <div class="mev-card-body">
      <?php
      $health_items = array(
        array( 'label' => __( 'Have Focus Keyword', 'meyvora-seo' ),    'count' => $total_posts - $no_keyword, 'color' => 'var(--mev-success)' ),
        array( 'label' => __( 'Have Meta Description', 'meyvora-seo' ), 'count' => $total_posts - $no_desc,    'color' => 'var(--mev-primary)' ),
        array( 'label' => __( 'Score 80+ (Great)', 'meyvora-seo' ),     'count' => $good_count,                'color' => 'var(--mev-accent)' ),
        array( 'label' => __( 'Analyzed', 'meyvora-seo' ),               'count' => $with_score,               'color' => 'var(--mev-warning)' ),
      );
      foreach ( $health_items as $hi ) :
        $pct = $total_posts > 0 ? round( ( $hi['count'] / $total_posts ) * 100 ) : 0;
      ?>
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
          <span style="font-size:12px;font-weight:500;color:var(--mev-gray-700);"><?php echo esc_html( $hi['label'] ); ?></span>
          <span style="font-size:12px;font-weight:700;color:var(--mev-gray-900);"><?php echo (int) $hi['count']; ?><span style="color:var(--mev-gray-400);font-weight:400;">/<?php echo (int) $total_posts; ?></span></span>
        </div>
        <div style="background:var(--mev-gray-100);border-radius:4px;height:6px;overflow:hidden;">
          <div style="width:<?php echo (int) $pct; ?>%;height:100%;background:<?php echo esc_attr( $hi['color'] ); ?>;border-radius:4px;transition:width 1s;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>

<?php
$lb_enabled = $options->get( 'schema_local_business', false );
$lb_name    = $options->get( 'schema_lb_name', get_bloginfo( 'name' ) );
$lb_addr    = trim( implode( ', ', array_filter( array(
	$options->get( 'schema_lb_street', '' ),
	$options->get( 'schema_lb_locality', '' ),
	$options->get( 'schema_lb_region', '' ),
	$options->get( 'schema_lb_country', '' ),
) ) ) );
$lb_lat   = $options->get( 'schema_lb_lat', '' );
$lb_lng   = $options->get( 'schema_lb_lng', '' );
$lb_phone = $options->get( 'schema_lb_phone', '' );
?>
<div style="margin-top:24px;">
  <div class="mev-card">
    <div class="mev-card-header">
      <span class="mev-card-title"><?php echo wp_kses_post( meyvora_seo_icon( 'map_pin', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php esc_html_e( 'Local SEO & GEO Status', 'meyvora-seo' ); ?></span>
      <?php if ( $lb_enabled ) : ?>
        <span class="mev-badge mev-badge--green"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( 'LocalBusiness schema active', 'meyvora-seo' ); ?></span>
      <?php else : ?>
        <span class="mev-badge mev-badge--gray"><?php esc_html_e( 'Not configured', 'meyvora-seo' ); ?></span>
      <?php endif; ?>
    </div>
    <div class="mev-card-body">
      <?php if ( ! $lb_enabled ) : ?>
      <div style="display:flex;align-items:center;gap:16px;padding:16px;background:var(--mev-warning-light);border-radius:var(--mev-radius-sm);border:1px solid var(--mev-warning-mid);">
        <div style="font-size:36px;"><?php echo wp_kses_post( meyvora_seo_icon( 'map_pin', array( 'width' => 36, 'height' => 36 ) ) ); ?></div>
        <div>
          <div style="font-weight:700;color:var(--mev-gray-800);margin-bottom:4px;"><?php esc_html_e( 'Local SEO not enabled', 'meyvora-seo' ); ?></div>
          <div style="font-size:12px;color:var(--mev-gray-600);margin-bottom:10px;"><?php esc_html_e( 'Enable LocalBusiness schema to appear in Google local results, Maps, and knowledge panels.', 'meyvora-seo' ); ?></div>
          <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings#tab-local-seo' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm">
            <?php esc_html_e( 'Enable Local SEO →', 'meyvora-seo' ); ?>
          </a>
        </div>
      </div>
      <?php else : ?>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px;">
        <div class="mev-local-check <?php echo $lb_name ? 'is-good' : 'is-bad'; ?>">
          <div class="mev-local-check-icon"><?php echo wp_kses_post( $lb_name ? meyvora_seo_icon( 'circle_check', array( 'width' => 20, 'height' => 20 ) ) : meyvora_seo_icon( 'circle_x', array( 'width' => 20, 'height' => 20 ) ) ); ?></div>
          <div class="mev-local-check-label"><?php esc_html_e( 'Business Name', 'meyvora-seo' ); ?></div>
          <div class="mev-local-check-value"><?php echo $lb_name ? esc_html( mb_substr( $lb_name, 0, 24 ) ) : esc_html__( 'Not set', 'meyvora-seo' ); ?></div>
        </div>
        <div class="mev-local-check <?php echo $lb_addr ? 'is-good' : 'is-bad'; ?>">
          <div class="mev-local-check-icon"><?php echo wp_kses_post( $lb_addr ? meyvora_seo_icon( 'circle_check', array( 'width' => 20, 'height' => 20 ) ) : meyvora_seo_icon( 'circle_x', array( 'width' => 20, 'height' => 20 ) ) ); ?></div>
          <div class="mev-local-check-label"><?php esc_html_e( 'Address', 'meyvora-seo' ); ?></div>
          <div class="mev-local-check-value"><?php echo $lb_addr ? esc_html( mb_substr( $lb_addr, 0, 30 ) ) : esc_html__( 'Not set', 'meyvora-seo' ); ?></div>
        </div>
        <div class="mev-local-check <?php echo ( $lb_lat && $lb_lng ) ? 'is-good' : 'is-warn'; ?>">
          <div class="mev-local-check-icon"><?php echo wp_kses_post( ( $lb_lat && $lb_lng ) ? meyvora_seo_icon( 'circle_check', array( 'width' => 20, 'height' => 20 ) ) : meyvora_seo_icon( 'alert_triangle', array( 'width' => 20, 'height' => 20 ) ) ); ?></div>
          <div class="mev-local-check-label"><?php esc_html_e( 'GEO Coordinates', 'meyvora-seo' ); ?></div>
          <div class="mev-local-check-value"><?php echo ( $lb_lat && $lb_lng ) ? esc_html( round( (float) $lb_lat, 4 ) . ', ' . round( (float) $lb_lng, 4 ) ) : esc_html__( 'Not set', 'meyvora-seo' ); ?></div>
        </div>
        <div class="mev-local-check <?php echo $lb_phone ? 'is-good' : 'is-warn'; ?>">
          <div class="mev-local-check-icon"><?php echo wp_kses_post( $lb_phone ? meyvora_seo_icon( 'circle_check', array( 'width' => 20, 'height' => 20 ) ) : meyvora_seo_icon( 'alert_triangle', array( 'width' => 20, 'height' => 20 ) ) ); ?></div>
          <div class="mev-local-check-label"><?php esc_html_e( 'Phone', 'meyvora-seo' ); ?></div>
          <div class="mev-local-check-value"><?php echo $lb_phone ? esc_html( $lb_phone ) : esc_html__( 'Not set', 'meyvora-seo' ); ?></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;font-size:12px;color:var(--mev-gray-400);">
        <span><?php esc_html_e( 'LocalBusiness JSON-LD schema is output on every page.', 'meyvora-seo' ); ?></span>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings#tab-local-seo' ) ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Edit Local SEO', 'meyvora-seo' ); ?></a>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ( ! empty( $top_scored ) ) : ?>
<div style="margin-top:24px;">
  <div class="mev-card">
    <div class="mev-card-header">
      <span class="mev-card-title"><?php echo wp_kses_post( meyvora_seo_icon( 'trophy', array( 'width' => 18, 'height' => 18 ) ) ); ?> <?php esc_html_e( 'Top Performing Content', 'meyvora-seo' ); ?></span>
      <span style="font-size:11px;color:var(--mev-gray-400);"><?php esc_html_e( 'Score 70+', 'meyvora-seo' ); ?></span>
    </div>
    <div class="mev-card-body" style="padding-top:6px;padding-bottom:6px;">
      <?php foreach ( $top_scored as $pid => $sc ) :
        $sc_cls   = $sc >= 80 ? 'good' : 'okay';
        $post_obj = get_post( $pid );
        if ( ! $post_obj ) { continue; }
      ?>
      <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--mev-border);">
        <span class="mev-score-pill mev-score-pill--<?php echo esc_attr( $sc_cls ); ?>" style="min-width:40px;font-size:13px;"><?php echo (int) $sc; ?></span>
        <div style="flex:1;min-width:0;">
          <a href="<?php echo esc_url( get_edit_post_link( $pid, 'raw' ) ?: '#' ); ?>" style="font-size:13px;font-weight:600;color:var(--mev-gray-800);text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
            <?php echo esc_html( $post_obj->post_title ?: '(no title)' ); ?>
          </a>
          <span class="mev-post-type mev-post-type--<?php echo esc_attr( $post_obj->post_type ); ?>"><?php echo esc_html( $post_obj->post_type ); ?></span>
        </div>
        <div style="width:120px;height:6px;background:var(--mev-gray-100);border-radius:3px;overflow:hidden;flex-shrink:0;">
          <div style="width:<?php echo (int) $sc; ?>%;height:100%;background:<?php echo $sc >= 80 ? 'var(--mev-success)' : 'var(--mev-warning)'; ?>;border-radius:3px;"></div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

</div><!-- /.wrap.meyvora-dashboard -->
