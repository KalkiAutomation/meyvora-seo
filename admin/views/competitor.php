<?php
/**
 * Competitor SEO Spy: analyze a competitor URL and compare with your post.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$recent_posts = get_posts( array(
	'post_type'      => array( 'post', 'page' ),
	'post_status'    => 'publish',
	'posts_per_page' => 50,
	'orderby'        => 'modified',
	'order'          => 'DESC',
	'fields'         => 'ids',
) );
$post_choices = array();
foreach ( $recent_posts as $pid ) {
	$post_choices[ $pid ] = get_the_title( $pid ) ?: __( '(no title)', 'meyvora-seo' );
}
?>
<div class="wrap meyvora-competitor-page">
	<div class="mev-page-header">
		<div class="mev-page-header-left">
			<nav class="nav-tab-wrapper mev-mb-20" style="margin-bottom:0;">
				<a href="#mev-tab-analyze" class="nav-tab nav-tab-active" data-tab="analyze"><?php esc_html_e( 'Analyze', 'meyvora-seo' ); ?></a>
				<a href="#mev-tab-history" class="nav-tab" data-tab="history"><?php esc_html_e( 'History', 'meyvora-seo' ); ?></a>
			</nav>
		</div>
	</div>

	<div id="mev-tab-analyze" class="mev-competitor-tab" data-tab="analyze">
	<div class="mev-card mev-mb-20">
		<div class="mev-card-body">
			<div class="mev-competitor-form" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
				<div style="flex:1;min-width:280px;">
					<label for="mev-competitor-url" class="screen-reader-text"><?php esc_html_e( 'Competitor URL', 'meyvora-seo' ); ?></label>
					<input type="url" id="mev-competitor-url" class="large-text" placeholder="https://competitor.com/page" style="width:100%;" />
				</div>
				<div style="min-width:180px;">
					<label for="mev-competitor-post"><?php esc_html_e( 'Compare with post', 'meyvora-seo' ); ?></label>
					<select id="mev-competitor-post" style="width:100%;margin-top:4px;">
						<option value=""><?php esc_html_e( '— Select —', 'meyvora-seo' ); ?></option>
						<?php foreach ( $post_choices as $pid => $title ) : ?>
							<option value="<?php echo esc_attr( (string) (int) $pid ); ?>"><?php echo esc_html( wp_trim_words( $title, 8 ) ); ?> (ID: <?php echo esc_html( (string) (int) $pid ); ?>)</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div>
					<button type="button" id="mev-competitor-analyze" class="mev-btn mev-btn--primary"><?php esc_html_e( 'Analyze', 'meyvora-seo' ); ?></button>
				</div>
			</div>
			<div id="mev-competitor-error" class="mev-competitor-error" style="display:none;margin-top:12px;color:var(--mev-danger);"></div>
		</div>
	</div>

	<div id="mev-competitor-results" class="mev-competitor-results" style="display:none;">
		<div class="mev-competitor-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;">
			<div class="mev-col mev-col-competitor mev-card">
				<div class="mev-card-body">
					<h2 class="mev-competitor-col-title" style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Competitor', 'meyvora-seo' ); ?> <span id="mev-competitor-display-url" class="mev-text-muted" style="font-size:12px;"></span></h2>
					<div id="mev-competitor-data"></div>
				</div>
			</div>
			<div class="mev-col mev-col-ours mev-card">
				<div class="mev-card-body">
					<h2 class="mev-competitor-col-title" style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Your post', 'meyvora-seo' ); ?> <span id="mev-ours-display-title" class="mev-text-muted" style="font-size:12px;"></span></h2>
					<div id="mev-ours-data"></div>
				</div>
			</div>
		</div>
	</div>

	<!-- Keyword Gap -->
	<div class="mev-card mev-mt-20" id="mev-keyword-gap-section">
		<div class="mev-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
			<span class="mev-card-title"><?php esc_html_e( 'Keyword Gap', 'meyvora-seo' ); ?></span>
			<button type="button" id="mev-competitor-analyse-gap" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Analyse Gap', 'meyvora-seo' ); ?></button>
		</div>
		<div class="mev-card-body">
			<p class="description" style="margin:0 0 12px;color:var(--mev-gray-500);font-size:13px;"><?php esc_html_e( 'Keywords the competitor ranks for (DataForSEO) that you do not rank for (GSC). Enter competitor URL above and click Analyse Gap.', 'meyvora-seo' ); ?></p>
			<div id="mev-keyword-gap-error" style="display:none;margin-bottom:12px;color:var(--mev-danger);font-size:13px;"></div>
			<div id="mev-keyword-gap-table-wrap" style="display:none;">
				<table class="mev-table" style="width:100%;">
					<thead>
						<tr>
							<th style="text-align:left;"><?php esc_html_e( 'Keyword', 'meyvora-seo' ); ?></th>
							<th style="text-align:left;"><?php esc_html_e( 'Competitor Position', 'meyvora-seo' ); ?></th>
							<th style="text-align:left;"><?php esc_html_e( 'Est. Volume', 'meyvora-seo' ); ?></th>
							<th style="text-align:left;"><?php esc_html_e( 'Action', 'meyvora-seo' ); ?></th>
						</tr>
					</thead>
					<tbody id="mev-keyword-gap-tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- Content Gap Analysis (AI) -->
	<div class="mev-card mev-mt-20" id="mev-content-gap-section">
		<div class="mev-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
			<span class="mev-card-title"><?php esc_html_e( 'Content Gap Analysis (AI)', 'meyvora-seo' ); ?></span>
			<button type="button" id="mev-content-gap-analyse" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Analyse Gap', 'meyvora-seo' ); ?></button>
		</div>
		<div class="mev-card-body">
			<p class="description" style="margin:0 0 12px;color:var(--mev-gray-500);font-size:13px;"><?php esc_html_e( 'Compare your headings and word count with the competitor and get AI suggestions for missing topics and headings. Run Analyse above first.', 'meyvora-seo' ); ?></p>
			<div id="mev-content-gap-error" style="display:none;margin-bottom:12px;color:var(--mev-danger);font-size:13px;"></div>
			<div id="mev-content-gap-output" style="display:none;">
				<div id="mev-content-gap-missing" style="margin-bottom:16px;"></div>
				<div id="mev-content-gap-headings" style="margin-bottom:16px;"></div>
				<div id="mev-content-gap-depth" style="margin-bottom:8px;font-size:13px;"></div>
				<div id="mev-content-gap-wc-note" style="font-size:13px;"></div>
			</div>
		</div>
	</div>
	</div><!-- #mev-tab-analyze -->

	<div id="mev-tab-history" class="mev-competitor-tab" data-tab="history" style="display:none;">
		<div class="mev-card">
			<div class="mev-card-body">
				<h2 class="mev-competitor-col-title" style="margin:0 0 16px;font-size:16px;"><?php esc_html_e( 'Snapshot History', 'meyvora-seo' ); ?></h2>
				<p class="description" style="margin:0 0 16px;color:var(--mev-gray-500);font-size:13px;"><?php esc_html_e( 'URLs you have analyzed. Click a snapshot to view details; select two and click Compare to see what changed.', 'meyvora-seo' ); ?></p>
				<div id="mev-snapshot-history-list"></div>
				<div id="mev-snapshot-detail" style="display:none;margin-top:20px;padding:16px;background:#f9fafb;border-radius:8px;"></div>
				<div id="mev-snapshot-compare" style="display:none;margin-top:20px;padding:16px;background:#f0fdf4;border-radius:8px;"></div>
				<div id="mev-snapshot-compare-actions" style="margin-top:12px;">
					<button type="button" id="mev-snapshot-compare-btn" class="mev-btn mev-btn--secondary mev-btn--sm" style="display:none;"><?php esc_html_e( 'Compare selected', 'meyvora-seo' ); ?></button>
				</div>
			</div>
		</div>
	</div><!-- #mev-tab-history -->
</div>

