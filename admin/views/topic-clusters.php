<?php
/**
 * Topic Clusters admin view: define pillar + cluster groups, analyse internal links.
 *
 * @package Meyvora_SEO
 * @var array $clusters  Saved cluster groups (name, pillar_id, cluster_ids)
 * @var array $analyses  analyse_cluster() result per cluster (pillar_links_out, missing_pillar_links, orphan_clusters, coverage_score)
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$clusters = isset( $clusters ) ? $clusters : array();
$analyses = isset( $analyses ) ? $analyses : array();
?>
<div class="wrap meyvora-topic-clusters-page">
	<div class="mev-page-header">
		<div class="mev-page-header-left">
			<div class="mev-page-logo">M</div>
			<div>
				<div class="mev-page-title"><?php esc_html_e( 'Topic Clusters', 'meyvora-seo' ); ?></div>
				<div class="mev-page-subtitle"><?php esc_html_e( 'Pillar and cluster groups — validate internal linking', 'meyvora-seo' ); ?></div>
			</div>
		</div>
		<div class="mev-page-header-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-topic-clusters' ) ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Analyse all', 'meyvora-seo' ); ?></a>
		</div>
	</div>

	<!-- New Cluster form -->
	<div class="mev-card" style="margin-bottom:20px;">
		<div class="mev-card-header">
			<span class="mev-card-title"><?php esc_html_e( 'New Cluster', 'meyvora-seo' ); ?></span>
		</div>
		<div class="mev-card-body">
			<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:start;">
				<div>
					<label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'Cluster name', 'meyvora-seo' ); ?></label>
					<input type="text" id="mev-cluster-name" placeholder="<?php esc_attr_e( 'e.g. SEO Basics', 'meyvora-seo' ); ?>"
						style="width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;box-sizing:border-box;"/>
				</div>
				<div>
					<label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'Pillar post (searchable)', 'meyvora-seo' ); ?></label>
					<input type="text" id="mev-pillar-search" placeholder="<?php esc_attr_e( 'Type to search...', 'meyvora-seo' ); ?>"
						autocomplete="off"
						style="width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;box-sizing:border-box;"/>
					<input type="hidden" id="mev-pillar-id" value=""/>
					<div id="mev-pillar-results" style="position:absolute;z-index:10;background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius-sm);max-height:200px;overflow:auto;display:none;min-width:280px;box-shadow:var(--mev-shadow-md);"></div>
					<div id="mev-pillar-selected" style="margin-top:6px;font-size:12px;color:var(--mev-gray-600);"></div>
				</div>
			</div>
			<div style="margin-top:16px;">
				<label style="display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.04em;color:var(--mev-gray-600);margin-bottom:6px;"><?php esc_html_e( 'Cluster posts (add multiple)', 'meyvora-seo' ); ?></label>
				<input type="text" id="mev-cluster-search" placeholder="<?php esc_attr_e( 'Type to search and add...', 'meyvora-seo' ); ?>"
					autocomplete="off"
					style="width:100%;max-width:400px;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;box-sizing:border-box;"/>
				<div id="mev-cluster-results" style="position:absolute;z-index:10;background:var(--mev-surface);border:1px solid var(--mev-border);border-radius:var(--mev-radius-sm);max-height:200px;overflow:auto;display:none;min-width:280px;box-shadow:var(--mev-shadow-md);"></div>
				<div id="mev-cluster-selected" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;"></div>
			</div>
			<div style="margin-top:16px;">
				<button type="button" id="mev-cluster-add-btn" class="mev-btn mev-btn--primary"><?php esc_html_e( 'Add cluster', 'meyvora-seo' ); ?></button>
			</div>
		</div>
	</div>

	<!-- Saved clusters list -->
	<div class="mev-card">
		<div class="mev-card-header">
			<span class="mev-card-title"><?php esc_html_e( 'Saved clusters', 'meyvora-seo' ); ?></span>
		</div>
		<div class="mev-card-body">
			<?php if ( empty( $clusters ) ) : ?>
			<p style="color:var(--mev-gray-500);font-size:13px;"><?php esc_html_e( 'No clusters yet. Add one above.', 'meyvora-seo' ); ?></p>
			<?php else : ?>
			<div id="mev-clusters-list">
				<?php foreach ( $clusters as $idx => $c ) :
					$analysis = isset( $analyses[ $idx ] ) ? $analyses[ $idx ] : array();
					$score    = isset( $analysis['coverage_score'] ) ? (float) $analysis['coverage_score'] : 0;
					$pillar_title = get_the_title( $c['pillar_id'] ) ?: __( '(no title)', 'meyvora-seo' );
					$ring_color = $score >= 80 ? 'var(--mev-success)' : ( $score >= 50 ? 'var(--mev-warning)' : 'var(--mev-danger)' );
					$circum = 2 * 3.14159 * 14; // r=14 in SVG
					$offset = $circum - ( $score / 100 ) * $circum;
				?>
				<div class="mev-cluster-row" data-index="<?php echo esc_attr( (string) (int) $idx ); ?>" style="display:flex;align-items:center;gap:16px;padding:14px 0;border-bottom:1px solid var(--mev-border);">
					<div class="mev-cluster-ring-wrap" style="flex-shrink:0;">
						<svg class="mev-cluster-ring" viewBox="0 0 36 36" width="48" height="48" style="transform:rotate(-90deg);">
							<circle cx="18" cy="18" r="14" fill="none" stroke="var(--mev-gray-200)" stroke-width="3"/>
							<circle cx="18" cy="18" r="14" fill="none" stroke="<?php echo esc_attr( $ring_color ); ?>" stroke-width="3" stroke-dasharray="<?php echo esc_attr( (string) (float) $circum ); ?>" stroke-dashoffset="<?php echo esc_attr( (string) (float) $offset ); ?>" stroke-linecap="round"/>
						</svg>
						<div style="position:relative;margin-top:-42px;text-align:center;font-size:11px;font-weight:700;color:var(--mev-gray-800);"><?php echo esc_html( (string) (int) round( $score ) ); ?>%</div>
					</div>
					<div style="flex:1;min-width:0;">
						<strong style="font-size:14px;"><?php echo esc_html( $c['name'] ); ?></strong>
						<div style="font-size:12px;color:var(--mev-gray-500);margin-top:2px;"><?php esc_html_e( 'Pillar:', 'meyvora-seo' ); ?> <?php echo esc_html( $pillar_title ); ?></div>
					</div>
					<div style="display:flex;align-items:center;gap:8px;">
						<button type="button" class="mev-btn mev-btn--secondary mev-btn--sm mev-cluster-analyse-btn" data-index="<?php echo esc_attr( (string) (int) $idx ); ?>"><?php esc_html_e( 'Analyse', 'meyvora-seo' ); ?></button>
						<button type="button" class="mev-cluster-remove-btn mev-btn mev-btn--sm" data-index="<?php echo esc_attr( (string) (int) $idx ); ?>" style="background:var(--mev-danger-light);color:var(--mev-danger);border:1px solid var(--mev-danger-mid);"><?php esc_html_e( 'Remove', 'meyvora-seo' ); ?></button>
					</div>
				</div>
				<div class="mev-cluster-analysis-panel" id="mev-analysis-<?php echo esc_attr( (string) (int) $idx ); ?>" style="display:none;padding:12px 0 16px;border-bottom:1px solid var(--mev-border);">
					<?php
					$missing = isset( $analysis['missing_pillar_links'] ) ? $analysis['missing_pillar_links'] : array();
					$orphans = isset( $analysis['orphan_clusters'] ) ? $analysis['orphan_clusters'] : array();
					?>
					<?php if ( ! empty( $missing ) ) : ?>
					<div style="margin-bottom:10px;">
						<div style="font-size:11px;font-weight:700;color:var(--mev-gray-600);margin-bottom:4px;"><?php esc_html_e( 'Pillar does not link to (add link in pillar post):', 'meyvora-seo' ); ?></div>
						<ul style="margin:0;padding-left:20px;font-size:13px;">
							<?php foreach ( $missing as $pid ) : $edit = get_edit_post_link( $pid, 'raw' ); ?>
							<li><a href="<?php echo esc_url( $edit ?: '#' ); ?>"><?php echo esc_html( get_the_title( $pid ) ?: __( '(no title)', 'meyvora-seo' ) ); ?></a> <?php esc_html_e( '— Add link', 'meyvora-seo' ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>
					<?php if ( ! empty( $orphans ) ) : ?>
					<div>
						<div style="font-size:11px;font-weight:700;color:var(--mev-gray-600);margin-bottom:4px;"><?php esc_html_e( 'Cluster posts not linking back to pillar (add link in cluster post):', 'meyvora-seo' ); ?></div>
						<ul style="margin:0;padding-left:20px;font-size:13px;">
							<?php foreach ( $orphans as $pid ) : $edit = get_edit_post_link( $pid, 'raw' ); ?>
							<li><a href="<?php echo esc_url( $edit ?: '#' ); ?>"><?php echo esc_html( get_the_title( $pid ) ?: __( '(no title)', 'meyvora-seo' ) ); ?></a> <?php esc_html_e( '— Add link', 'meyvora-seo' ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>
					<?php if ( empty( $missing ) && empty( $orphans ) && ! empty( $analysis ) ) : ?>
					<p style="margin:0;font-size:13px;color:var(--mev-success);"><?php esc_html_e( 'Bidirectional linking is complete for this cluster.', 'meyvora-seo' ); ?></p>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<form id="mev-clusters-form" method="post" style="display:none;">
		<input type="hidden" name="mev_clusters_json" id="mev-clusters-json" value=""/>
		<?php wp_nonce_field( 'meyvora_seo_cluster_save', 'mev_cluster_nonce' ); ?>
	</form>
</div>
