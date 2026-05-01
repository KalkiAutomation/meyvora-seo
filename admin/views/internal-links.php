<?php
/**
 * Link Analysis admin view: luxury header, hero stats, filterable table, pagination, export CSV.
 *
 * @package Meyvora_SEO
 * @var array $data From Meyvora_SEO_Internal_Links::get_link_analysis_data() — keys: rows, total, total_pages
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Recommended -- View template; list GET params.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rows        = isset( $data['rows'] ) ? $data['rows'] : array();
$total       = isset( $data['total'] ) ? (int) $data['total'] : 0;
$total_pages = isset( $data['total_pages'] ) ? (int) $data['total_pages'] : 1;
$page        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
$export_url  = add_query_arg( array( 'page' => Meyvora_SEO_Internal_Links::LINK_ANALYSIS_SLUG, 'export' => 'csv' ), admin_url( 'admin.php' ) );

$count_orphan = 0;
$count_good   = 0;
$count_low    = 0;
foreach ( $rows as $r ) {
	if ( ( $r['status'] ?? '' ) === 'orphan' ) {
		$count_orphan++;
	} elseif ( ( $r['status'] ?? '' ) === 'good' ) {
		$count_good++;
	} else {
		$count_low++;
	}
}
$health = count( $rows ) > 0 ? (int) round( $count_good / count( $rows ) * 100 ) : 0;
$circumference = 314;
$offset        = $circumference - round( $circumference * $health / 100 );
?>
<div class="wrap meyvora-link-analysis-page meyvora-page-luxury">
	<div class="mev-page-header mev-page-header--luxury">
		<div class="mev-page-header-left">
			<div class="mev-page-logo mev-page-logo--gradient">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
					<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
				</svg>
			</div>
			<div>
				<h1 class="mev-page-title"><?php esc_html_e( 'Link Analysis', 'meyvora-seo' ); ?></h1>
				<p class="mev-page-subtitle"><?php echo esc_html( sprintf( /* translators: %s: formatted number of posts/pages */ _n( '%s post or page', '%s posts and pages', $total, 'meyvora-seo' ), number_format_i18n( $total ) ) ); ?></p>
			</div>
		</div>
		<div class="mev-page-header-actions">
			<a href="<?php echo esc_url( $export_url ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Export CSV', 'meyvora-seo' ); ?></a>
		</div>
	</div>

	<?php if ( ! empty( $rows ) ) : ?>
		<div class="mev-link-hero">
			<div class="mev-audit-ring-wrap">
				<svg class="mev-audit-ring" viewBox="0 0 120 120" width="110" height="110">
					<circle cx="60" cy="60" r="50" class="mev-ring-bg"/>
					<circle cx="60" cy="60" r="50" class="mev-ring-fill"
						stroke-dasharray="<?php echo esc_attr( (string) (int) $circumference ); ?>"
						stroke-dashoffset="<?php echo esc_attr( (string) (int) $offset ); ?>"/>
				</svg>
				<div class="mev-ring-label">
					<span class="mev-ring-pct"><?php echo esc_html( (string) (int) $health ); ?></span>
					<span class="mev-ring-unit"><?php esc_html_e( 'Good this page', 'meyvora-seo' ); ?></span>
				</div>
			</div>
			<div class="mev-link-stat-grid">
				<div class="mev-link-stat mev-link-stat--total">
					<div class="mev-link-stat-val"><?php echo esc_html( (string) (int) $total ); ?></div>
					<div class="mev-link-stat-label"><?php esc_html_e( 'Total Pages', 'meyvora-seo' ); ?></div>
					<div class="mev-link-stat-bar" style="background: var(--mev-accent);"></div>
				</div>
				<div class="mev-link-stat mev-link-stat--orphan">
					<div class="mev-link-stat-val"><?php echo esc_html( (string) (int) $count_orphan ); ?></div>
					<div class="mev-link-stat-label"><?php esc_html_e( 'Orphans', 'meyvora-seo' ); ?></div>
					<div class="mev-link-stat-bar" style="background: var(--mev-danger);"></div>
				</div>
				<div class="mev-link-stat mev-link-stat--good">
					<div class="mev-link-stat-val"><?php echo esc_html( (string) (int) $count_good ); ?></div>
					<div class="mev-link-stat-label"><?php esc_html_e( 'Good', 'meyvora-seo' ); ?></div>
					<div class="mev-link-stat-bar" style="background: var(--mev-success);"></div>
				</div>
				<div class="mev-link-stat mev-link-stat--low">
					<div class="mev-link-stat-val"><?php echo esc_html( (string) (int) $count_low ); ?></div>
					<div class="mev-link-stat-label"><?php esc_html_e( 'Low Links', 'meyvora-seo' ); ?></div>
					<div class="mev-link-stat-bar" style="background: var(--mev-warning);"></div>
				</div>
			</div>
		</div>

		<div class="mev-filter-tabs" id="mev-link-filter">
			<button type="button" class="mev-ftab mev-ftab--active" data-filter="all"><?php esc_html_e( 'All', 'meyvora-seo' ); ?></button>
			<button type="button" class="mev-ftab" data-filter="good"><?php esc_html_e( 'Good', 'meyvora-seo' ); ?></button>
			<button type="button" class="mev-ftab" data-filter="low"><?php esc_html_e( 'Low Links', 'meyvora-seo' ); ?></button>
			<button type="button" class="mev-ftab" data-filter="orphan"><?php esc_html_e( 'Orphans', 'meyvora-seo' ); ?></button>
		</div>
		<div class="mev-link-filters">
			<label for="mev-link-filter-search"><?php esc_html_e( 'Search:', 'meyvora-seo' ); ?></label>
			<input type="search" id="mev-link-filter-search" placeholder="<?php esc_attr_e( 'Post title…', 'meyvora-seo' ); ?>" />
		</div>
	<?php endif; ?>

	<div class="mev-data-table-wrap">
		<table class="mev-data-table" id="mev-link-analysis-table">
			<thead>
				<tr>
					<th scope="col" class="column-post"><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-links-in num"><?php esc_html_e( 'Links In', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-links-out num"><?php esc_html_e( 'Links Out', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-action"><?php esc_html_e( 'Action', 'meyvora-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="5"><?php esc_html_e( 'No published posts or pages found.', 'meyvora-seo' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$edit_url = get_edit_post_link( $row['id'], 'raw' ) ?: '#';
						$status   = $row['status'] ?? 'low';
						$badge_class = $status === 'good' ? 'mev-badge--success' : ( $status === 'orphan' ? 'mev-badge--danger' : 'mev-badge--warning' );
						$badge_label = $status === 'good' ? __( 'Good', 'meyvora-seo' ) : ( $status === 'orphan' ? __( 'Orphan', 'meyvora-seo' ) : __( 'Low Links', 'meyvora-seo' ) );
						?>
						<tr class="mev-link-row" data-status="<?php echo esc_attr( $status ); ?>" data-title="<?php echo esc_attr( $row['title'] ); ?>">
							<td class="column-post">
								<strong><a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $row['title'] ); ?></a></strong>
								<span class="mev-post-type-badge"><?php echo esc_html( $row['type'] ); ?></span>
							</td>
							<td class="column-links-in num"><?php echo esc_html( (string) (int) $row['links_in'] ); ?></td>
							<td class="column-links-out num"><?php echo esc_html( (string) (int) $row['links_out'] ); ?></td>
							<td class="column-status">
								<span class="mev-badge <?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
							</td>
							<td class="column-action">
								<?php if ( $status === 'orphan' ) : ?>
									<?php $bulk_url = admin_url( 'admin.php?page=meyvora-seo-bulk-editor&post_id=' . (int) ( $row['id'] ?? 0 ) ); ?>
									<a href="<?php echo esc_url( $bulk_url ); ?>" class="mev-btn mev-btn--secondary mev-btn--xs"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></a>
								<?php endif; ?>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Edit', 'meyvora-seo' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="mev-link-pagination tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( (string) (int) $total ); ?> <?php esc_html_e( 'items', 'meyvora-seo' ); ?></span>
				<span class="pagination-links">
					<?php
					$base_url = add_query_arg( array( 'page' => Meyvora_SEO_Internal_Links::LINK_ANALYSIS_SLUG, 'paged' => '%#%' ), admin_url( 'admin.php' ) );
					echo wp_kses_post( paginate_links( array(
						'base'      => $base_url,
						'format'    => '',
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
						'total'     => $total_pages,
						'current'   => $page,
					) ) );
					?>
				</span>
			</div>
		</div>
	<?php endif; ?>
</div>
