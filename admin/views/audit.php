<?php
/**
 * Site Audit view: scorecard, filterable table, Run Audit Now, progress bar.
 *
 * @package Meyvora_SEO
 * @var array|null $data From Meyvora_SEO_Audit::get_stored_results() — last_run, results, summary; or null.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$current_page = 'site-audit';
$results = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
$summary = isset( $data['summary'] ) && is_array( $data['summary'] ) ? $data['summary'] : array();
$last_run = isset( $data['last_run'] ) ? (int) $data['last_run'] : 0;
$total_issues = isset( $summary['total_issues'] ) ? (int) $summary['total_issues'] : 0;
$by_severity = isset( $summary['by_severity'] ) && is_array( $summary['by_severity'] ) ? $summary['by_severity'] : array( 'critical' => 0, 'warning' => 0, 'info' => 0 );
$issues_map = Meyvora_SEO_Audit::ISSUES;
?>
<div class="wrap meyvora-site-audit-page meyvora-page-luxury">
	<div class="mev-page-header mev-page-header--luxury">
		<div class="mev-page-header-left">
			<div class="mev-page-logo mev-page-logo--gradient">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="11" cy="11" r="8"/>
					<line x1="21" y1="21" x2="16.65" y2="16.65"/>
				</svg>
			</div>
			<div>
				<h1 class="mev-page-title"><?php esc_html_e( 'Site Audit', 'meyvora-seo' ); ?></h1>
				<p class="mev-page-subtitle"><?php esc_html_e( 'Technical Site Crawl', 'meyvora-seo' ); ?></p>
			</div>
		</div>
		<div class="mev-page-header-actions">
			<?php if ( ! empty( $results ) ) : ?>
				<button type="button" id="mev-audit-export-csv" class="mev-btn mev-btn--secondary mev-btn--sm">
					<?php esc_html_e( 'Export CSV', 'meyvora-seo' ); ?></button>
			<?php endif; ?>
			<button type="button" id="mev-audit-run-now" class="mev-btn mev-btn--primary">
				<?php esc_html_e( 'Run Audit Now', 'meyvora-seo' ); ?></button>
		</div>
	</div>

	<nav class="mev-insights-tabs" aria-label="Insights navigation">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-reports' ) ); ?>"
		   class="mev-itab <?php echo esc_attr( ( $current_page === 'reports' ) ? 'mev-itab--active' : '' ); ?>">
			<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>
			Reports
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>"
		   class="mev-itab <?php echo esc_attr( ( $current_page === 'content-audit' ) ? 'mev-itab--active' : '' ); ?>">
			<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
			Content Audit
		</a>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-site-audit' ) ); ?>"
		   class="mev-itab <?php echo esc_attr( ( $current_page === 'site-audit' ) ? 'mev-itab--active' : '' ); ?>">
			<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
			Site Audit
		</a>
	</nav>

	<?php
	if ( ! empty( $results ) ) {
		$clean       = array_filter( $results, fn( $r ) => empty( $r['issues'] ) );
		$total_posts = count( $results );
		$health      = $total_posts > 0 ? (int) round( count( $clean ) / $total_posts * 100 ) : 0;
		$circumference = 314;
		$offset      = $circumference - round( $circumference * $health / 100 );
		?>
	<div class="mev-audit-hero">
		<div class="mev-audit-ring-wrap">
			<svg class="mev-audit-ring" viewBox="0 0 120 120" width="110" height="110">
				<circle cx="60" cy="60" r="50" class="mev-ring-bg"/>
				<circle cx="60" cy="60" r="50" class="mev-ring-fill"
					stroke-dasharray="<?php echo esc_attr( (string) (int) $circumference ); ?>"
					stroke-dashoffset="<?php echo esc_attr( (string) (int) $offset ); ?>"/>
			</svg>
			<div class="mev-ring-label">
				<span class="mev-ring-pct"><?php echo esc_html( (string) (int) $health ); ?></span>
				<span class="mev-ring-unit"><?php esc_html_e( 'Health', 'meyvora-seo' ); ?></span>
			</div>
		</div>
		<div class="mev-audit-hero-stats">
			<div class="mev-astat mev-astat-clean">
				<span class="mev-astat-val"><?php echo esc_html( (string) (int) count( $clean ) ); ?></span>
				<span class="mev-astat-label"><?php esc_html_e( 'Clean', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-astat mev-astat-warn">
				<span class="mev-astat-val"><?php echo esc_html( (string) (int) ( $by_severity['warning'] ?? 0 ) ); ?></span>
				<span class="mev-astat-label"><?php esc_html_e( 'Warnings', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-astat mev-astat-info">
				<span class="mev-astat-val"><?php echo esc_html( (string) (int) ( $by_severity['info'] ?? 0 ) ); ?></span>
				<span class="mev-astat-label"><?php esc_html_e( 'Info', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-astat mev-astat-total">
				<span class="mev-astat-val"><?php echo esc_html( (string) (int) $total_issues ); ?></span>
				<span class="mev-astat-label"><?php esc_html_e( 'Total Issues', 'meyvora-seo' ); ?></span>
			</div>
		</div>
	</div>
	<?php } ?>

	<?php
	if ( ! empty( $results ) ) {
		$tally = array();
		foreach ( $results as $row ) {
			foreach ( ( $row['issues'] ?? array() ) as $iss ) {
				$id = $iss['id'] ?? '';
				if ( ! $id ) {
					continue;
				}
				$tally[ $id ] = ( $tally[ $id ] ?? 0 ) + 1;
			}
		}
		arsort( $tally );
		$top = array_slice( $tally, 0, 6, true );
		$max = $top ? max( $top ) : 1;
		?>
	<div class="mev-card mev-audit-breakdown-card" style="margin-bottom:20px;">
		<div class="mev-card-header">
			<span class="mev-card-title"><?php esc_html_e( 'Top Issues', 'meyvora-seo' ); ?></span>
			<span style="font-size:12px;color:var(--mev-gray-400);">
				<?php printf( /* translators: %d: number of issue types */ esc_html__( '%d issue types found', 'meyvora-seo' ), count( $tally ) ); ?>
			</span>
		</div>
		<div class="mev-card-body">
			<?php
			foreach ( $top as $iss_id => $count ) {
				$label   = isset( $issues_map[ $iss_id ]['label'] ) ? $issues_map[ $iss_id ]['label'] : $iss_id;
				$sev     = isset( $issues_map[ $iss_id ]['severity'] ) ? $issues_map[ $iss_id ]['severity'] : 'warning';
				$pct     = (int) round( $count / $max * 100 );
				$fix_url = admin_url( 'admin.php?page=meyvora-seo-bulk-editor' );
				$filter_map = array(
					'missing_seo_title'       => 'title',
					'missing_meta_description' => 'description',
					'missing_focus_keyword'  => 'focus_keyword',
					'missing_og_image'       => 'og_image',
				);
				if ( isset( $filter_map[ $iss_id ] ) ) {
					$fix_url = add_query_arg( 'missing[]', $filter_map[ $iss_id ], $fix_url );
				}
				?>
			<div class="mev-issue-bar-row">
				<div class="mev-issue-bar-meta">
					<span class="mev-issue-bar-label"><?php echo esc_html( $label ); ?></span>
					<span class="mev-issue-bar-count mev-sev-badge-<?php echo esc_attr( $sev ); ?>">
						<?php echo esc_html( (string) (int) $count ); ?></span>
				</div>
				<div class="mev-issue-bar-track">
					<div class="mev-issue-bar-fill mev-bar-<?php echo esc_attr( $sev ); ?>"
						style="<?php echo esc_attr( 'width:' . (int) $pct . '%' ); ?>"></div>
				</div>
				<a href="<?php echo esc_url( $fix_url ); ?>"
					class="mev-btn mev-btn--secondary mev-btn--sm">
					<?php esc_html_e( 'Fix All', 'meyvora-seo' ); ?></a>
			</div>
			<?php } ?>
		</div>
	</div>
	<?php } ?>

	<div id="mev-audit-progress-wrap" class="mev-audit-overlay" style="display:none;">
		<div class="mev-audit-overlay-card">
			<div class="mev-overlay-ring-wrap">
				<svg class="mev-overlay-ring" viewBox="0 0 80 80" width="80" height="80">
					<circle cx="40" cy="40" r="32" class="mev-oring-track"/>
					<circle cx="40" cy="40" r="32" class="mev-oring-fill"
						id="mev-oring-fill"
						stroke-dasharray="201" stroke-dashoffset="201"/>
				</svg>
				<div class="mev-overlay-pct" id="mev-overlay-pct">0%</div>
			</div>
			<p class="mev-overlay-title">
				<?php esc_html_e( 'Scanning your site…', 'meyvora-seo' ); ?></p>
			<p class="mev-overlay-msg" id="mev-audit-progress-text">
				<?php esc_html_e( 'Preparing…', 'meyvora-seo' ); ?></p>
		</div>
	</div>

	<?php if ( $last_run > 0 ) : ?>
		<p class="mev-audit-last-run"><?php echo esc_html( sprintf( /* translators: %s: formatted date and time */ __( 'Last run: %s', 'meyvora-seo' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) ) ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $results ) ) : ?>
		<div class="mev-audit-scorecard">
			<div class="mev-audit-scorecard-item mev-severity-critical">
				<span class="mev-audit-scorecard-value"><?php echo esc_html( (string) (int) ( $by_severity['critical'] ?? 0 ) ); ?></span>
				<span class="mev-audit-scorecard-label"><?php esc_html_e( 'Critical', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-audit-scorecard-item">
				<span class="mev-audit-scorecard-value" id="mev-audit-total-issues"><?php echo esc_html( (string) (int) $total_issues ); ?></span>
				<span class="mev-audit-scorecard-label"><?php esc_html_e( 'Total issues', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-audit-scorecard-item mev-severity-warning">
				<span class="mev-audit-scorecard-value" id="mev-audit-warnings"><?php echo esc_html( (string) (int) ( $by_severity['warning'] ?? 0 ) ); ?></span>
				<span class="mev-audit-scorecard-label"><?php esc_html_e( 'Warnings', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-audit-scorecard-item mev-severity-info">
				<span class="mev-audit-scorecard-value" id="mev-audit-info"><?php echo esc_html( (string) (int) ( $by_severity['info'] ?? 0 ) ); ?></span>
				<span class="mev-audit-scorecard-label"><?php esc_html_e( 'Info', 'meyvora-seo' ); ?></span>
			</div>
		</div>

		<div class="mev-audit-filters">
			<label for="mev-audit-filter-severity"><?php esc_html_e( 'Severity:', 'meyvora-seo' ); ?></label>
			<select id="mev-audit-filter-severity">
				<option value=""><?php esc_html_e( 'All', 'meyvora-seo' ); ?></option>
				<option value="warning"><?php esc_html_e( 'Warnings only', 'meyvora-seo' ); ?></option>
				<option value="info"><?php esc_html_e( 'Info only', 'meyvora-seo' ); ?></option>
			</select>
			<label for="mev-audit-filter-search" style="margin-left:12px;"><?php esc_html_e( 'Search:', 'meyvora-seo' ); ?></label>
			<input type="search" id="mev-audit-filter-search" placeholder="<?php esc_attr_e( 'Post title…', 'meyvora-seo' ); ?>" />
		</div>

		<?php
		$fix_hints = array(
			'missing_seo_title'         => __( 'Add SEO title in the SEO panel > Title tab. Max 60 chars.', 'meyvora-seo' ),
			'missing_meta_description' => __( 'Add meta description in SEO panel > Title tab. Aim for 120-155 chars.', 'meyvora-seo' ),
			'missing_focus_keyword'     => __( 'Set focus keyword in SEO panel > Keywords tab.', 'meyvora-seo' ),
			'seo_score_poor'            => __( 'Improve score to 80+ by completing the SEO checklist.', 'meyvora-seo' ),
			'missing_og_image'          => __( 'Set OG image in SEO panel > Social tab.', 'meyvora-seo' ),
			'missing_schema_type'       => __( 'Choose a schema type in SEO panel > Schema tab.', 'meyvora-seo' ),
			'duplicate_meta_title'      => __( 'Make this page\'s SEO title unique.', 'meyvora-seo' ),
			'duplicate_meta_description' => __( 'Make this page\'s meta description unique.', 'meyvora-seo' ),
			'no_internal_links_out'     => __( 'Add 2-3 internal links within the post content.', 'meyvora-seo' ),
			'no_internal_links_in'      => __( 'Link to this page from at least one other post.', 'meyvora-seo' ),
			'very_short_content'        => __( 'Expand content to 300+ chars (600+ recommended).', 'meyvora-seo' ),
			'missing_image_alt'         => __( 'Add alt text to all images in the post.', 'meyvora-seo' ),
			'seo_title_too_long'        => __( 'Shorten SEO title to 60 chars or less in SEO panel > Title tab.', 'meyvora-seo' ),
			'meta_desc_too_long'        => __( 'Shorten meta description to 160 chars or less in SEO panel > Title tab.', 'meyvora-seo' ),
			'keyword_not_in_title'      => __( 'Include focus keyword in the SEO title.', 'meyvora-seo' ),
			'page_noindex'              => __( 'Allow indexing in SEO panel (e.g. Advanced/Indexing) unless intentional.', 'meyvora-seo' ),
			'missing_canonical'         => __( 'Set a custom canonical URL in SEO panel if needed to avoid duplicates.', 'meyvora-seo' ),
			'site_noindex_enabled'      => __( 'Uncheck "Discourage search engines from indexing this site" in Settings > Reading.', 'meyvora-seo' ),
		);
		?>
		<table class="wp-list-table widefat fixed striped mev-audit-table" id="mev-audit-table">
			<thead>
				<tr>
					<th class="column-expand" style="width:32px;"></th>
					<th scope="col" class="column-post"><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-issues"><?php esc_html_e( 'Issues Found', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-score num"><?php esc_html_e( 'SEO Score', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-action"><?php esc_html_e( 'Quick Fix', 'meyvora-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $row ) : ?>
					<?php
					$edit_url = get_edit_post_link( $row['post_id'], 'raw' ) ?: '#';
					$issues = isset( $row['issues'] ) ? $row['issues'] : array();
					$issue_labels = array();
					foreach ( $issues as $issue ) {
						$id = $issue['id'] ?? '';
						$label = isset( $issues_map[ $id ]['label'] ) ? $issues_map[ $id ]['label'] : $id;
						$issue_labels[] = array( 'id' => $id, 'label' => $label, 'severity' => $issue['severity'] ?? 'warning' );
					}
					?>
					<tr class="mev-audit-row" data-post-id="<?php echo esc_attr( (string) (int) $row['post_id'] ); ?>" data-issues="<?php echo esc_attr( wp_json_encode( $issue_labels ) ); ?>">
						<td class="column-expand">
							<button type="button" class="mev-row-expand-btn" aria-expanded="false">&#9654;</button>
						</td>
						<td class="column-post">
							<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row['post_title'] ); ?></a></strong>
							<span class="mev-post-type-badge"><?php echo esc_html( $row['post_type'] ); ?></span>
						</td>
						<td class="column-issues">
							<?php if ( empty( $issue_labels ) ) : ?>
								<span class="mev-no-issues">&mdash;</span>
							<?php else : ?>
								<ul class="mev-audit-issues-list">
									<?php foreach ( $issue_labels as $i ) : ?>
										<li class="mev-issue mev-issue-<?php echo esc_attr( $i['severity'] ); ?>"><?php echo esc_html( $i['label'] ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<td class="column-score num"><?php echo esc_html( (string) (int) ( $row['seo_score'] ?? 0 ) ); ?></td>
						<td class="column-action">
							<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Quick Fix', 'meyvora-seo' ); ?></a>
						</td>
					</tr>
					<?php
					$sc    = (int) ( $row['seo_score'] ?? 0 );
					$sc_cls = $sc >= 80 ? 'good' : ( $sc >= 50 ? 'okay' : 'poor' );
					?>
					<tr class="mev-audit-detail-row"
						id="mev-detail-<?php echo esc_attr( (string) (int) $row['post_id'] ); ?>"
						style="display:none;">
						<td colspan="5" class="mev-audit-detail-cell">
							<div class="mev-audit-detail-inner">
								<div class="mev-detail-header">
									<strong><?php echo esc_html( $row['post_title'] ); ?></strong>
									<div style="display:flex;align-items:center;gap:8px;">
										<span style="font-size:12px;"><?php esc_html_e( 'Score:', 'meyvora-seo' ); ?></span>
										<div class="mev-detail-score-track">
											<div class="mev-detail-score-fill mev-score-<?php echo esc_attr( $sc_cls ); ?>"
												style="<?php echo esc_attr( 'width:' . (int) $sc . '%' ); ?>"></div>
										</div>
										<strong class="mev-score-num mev-score-<?php echo esc_attr( $sc_cls ); ?>">
											<?php echo esc_html( (string) (int) $sc ); ?></strong>
									</div>
								</div>
								<?php if ( empty( $issue_labels ) ) : ?>
									<p style="color:var(--mev-success);font-weight:600;">
										<?php esc_html_e( 'No issues found', 'meyvora-seo' ); ?></p>
								<?php else : ?>
									<ul class="mev-detail-issues">
									<?php foreach ( $issue_labels as $iss ) : ?>
										<?php $hint = isset( $fix_hints[ $iss['id'] ] ) ? $fix_hints[ $iss['id'] ] : ''; ?>
										<li class="mev-detail-issue mev-dsev-<?php echo esc_attr( $iss['severity'] ); ?>">
											<span class="mev-detail-dot"></span>
											<div>
												<strong><?php echo esc_html( $iss['label'] ); ?></strong>
												<?php if ( $hint ) : ?>
													<p class="mev-detail-hint"><?php echo esc_html( $hint ); ?></p>
												<?php endif; ?>
											</div>
										</li>
									<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<a href="<?php echo esc_url( get_edit_post_link( $row['post_id'], 'raw' ) ?: '#' ); ?>"
									class="mev-btn mev-btn--primary mev-btn--sm" style="margin-top:12px;">
									<?php esc_html_e( 'Edit Post', 'meyvora-seo' ); ?></a>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php else : ?>
		<div class="mev-empty-state">
			<div class="mev-empty-state__icon" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="11" cy="11" r="7"/>
					<line x1="21" y1="21" x2="16.65" y2="16.65"/>
					<line x1="8" y1="11" x2="14" y2="11"/>
					<line x1="11" y1="8" x2="11" y2="14"/>
					<line x1="6.2" y1="6.2" x2="4.8" y2="4.8"/>
					<line x1="17.8" y1="6.2" x2="19.2" y2="4.8"/>
					<line x1="6.2" y1="17.8" x2="4.8" y2="19.2"/>
					<line x1="17.8" y1="17.8" x2="19.2" y2="19.2"/>
					<line x1="4" y1="11" x2="2.5" y2="11"/>
					<line x1="20" y1="11" x2="21.5" y2="11"/>
					<line x1="11" y1="4" x2="11" y2="2.5"/>
					<line x1="11" y1="20" x2="11" y2="21.5"/>
					<line x1="7.5" y1="7.5" x2="6.5" y2="6.5"/>
					<line x1="14.5" y1="7.5" x2="15.5" y2="6.5"/>
					<line x1="7.5" y1="14.5" x2="6.5" y2="15.5"/>
					<line x1="14.5" y1="14.5" x2="15.5" y2="15.5"/>
				</svg>
			</div>
			<h3 class="mev-empty-state__title"><?php esc_html_e( 'No audit results yet', 'meyvora-seo' ); ?></h3>
			<p class="mev-empty-state__body"><?php esc_html_e( 'Run a full site audit to find issues with your SEO, broken links, duplicate titles, and more.', 'meyvora-seo' ); ?></p>
			<button type="button" id="mev-audit-run-now-empty" class="button button-primary mev-empty-state__cta"><?php esc_html_e( 'Run Audit Now', 'meyvora-seo' ); ?></button>
		</div>
	<?php endif; ?>
</div>
