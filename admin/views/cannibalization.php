<?php
/**
 * Keyword Cannibalization: grouped conflicts list, Run Scan, Set as Primary.
 *
 * @package Meyvora_SEO
 * @var array  $page_groups   Keyword => list of posts (this page only).
 * @var int    $paged         Current page number.
 * @var int    $total_pages   Total pagination pages.
 * @var int    $total         Total conflicting keywords.
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$base_url = admin_url( 'admin.php?page=meyvora-seo-cannibalization' );
?>
<div class="wrap meyvora-cannibalization-page">
	<div class="mev-page-header">
		<div class="mev-page-header-left">
			<div class="mev-page-logo">M</div>
			<div>
				<h1 class="mev-page-title"><?php esc_html_e( 'Keyword Cannibalization', 'meyvora-seo' ); ?></h1>
				<p class="mev-page-subtitle"><?php esc_html_e( 'Find posts competing for the same focus keyword', 'meyvora-seo' ); ?></p>
			</div>
		</div>
		<div class="mev-page-header-actions">
			<button type="button" id="mev-cannibalization-run-scan" class="mev-btn mev-btn--primary">
				<?php esc_html_e( 'Run Scan', 'meyvora-seo' ); ?>
			</button>
		</div>
	</div>

	<div class="mev-cannibalization-intro mev-card mev-mb-20">
		<div class="mev-card-body">
			<p style="margin:0;color:var(--mev-gray-600);font-size:14px;">
				<?php esc_html_e( 'Posts and pages that share the same focus keyword can cannibalize each other in search results. Run a scan to group competing URLs by keyword. Mark one URL as primary per keyword to clarify intent.', 'meyvora-seo' ); ?>
			</p>
		</div>
	</div>

	<?php if ( ! empty( $never_scanned ) ) : ?>
		<div class="mev-empty-state">
			<div class="mev-empty-state__icon" aria-hidden="true">
				<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="9" cy="9" r="5"/>
					<circle cx="15" cy="15" r="5"/>
					<path d="M12.5 12.5c.8-.8 2.2-.8 3 0"/>
				</svg>
			</div>
			<h3 class="mev-empty-state__title"><?php esc_html_e( 'No cannibalization scan yet', 'meyvora-seo' ); ?></h3>
			<p class="mev-empty-state__body"><?php esc_html_e( 'Scan your content to find posts competing for the same keywords so you can consolidate or differentiate them.', 'meyvora-seo' ); ?></p>
			<button type="button" class="button button-primary mev-empty-state__cta mev-run-cannibalization-scan"><?php esc_html_e( 'Run Scan', 'meyvora-seo' ); ?></button>
		</div>
	<?php elseif ( empty( $page_groups ) ) : ?>
		<div class="mev-card">
			<div class="mev-card-body">
				<p class="mev-text-muted" style="margin:0;">
					<?php esc_html_e( 'No keyword conflicts found. Run a scan to detect focus keyword overlap across published posts, pages, and products.', 'meyvora-seo' ); ?>
				</p>
			</div>
		</div>
	<?php else : ?>
		<p class="mev-text-muted" style="margin-bottom:16px;font-size:13px;">
			<?php
			printf(
				/* translators: 1: number of conflicting keywords on this page, 2: total conflicting keywords */
				esc_html__( 'Showing %1$d of %2$d conflicting keywords.', 'meyvora-seo' ),
				(int) count( $page_groups ),
				(int) $total
			);
			?>
		</p>

		<div class="mev-cannibalization-list">
			<?php foreach ( $page_groups as $keyword => $posts ) : ?>
				<div class="mev-cannibalization-group mev-card mev-mb-16" data-keyword="<?php echo esc_attr( $keyword ); ?>">
					<div class="mev-card-body">
						<h3 class="mev-cannibalization-keyword" style="margin:0 0 12px;font-size:15px;color:var(--mev-gray-800);">
							<?php echo esc_html( $keyword ); ?>
						</h3>
						<ul class="mev-cannibalization-posts" style="margin:0;padding:0;list-style:none;">
							<?php foreach ( $posts as $row ) : ?>
								<li class="mev-cannibalization-row" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:10px 0;border-bottom:1px solid var(--mev-gray-100);">
									<div style="flex:1;min-width:200px;">
										<a href="<?php echo esc_url( $row['edit_link'] ); ?>" style="font-weight:500;color:var(--mev-gray-800);text-decoration:none;"><?php echo esc_html( $row['title'] ); ?></a>
										<span class="mev-text-muted" style="font-size:12px;display:block;margin-top:2px;">
											<a href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer" style="color:var(--mev-gray-500);"><?php echo esc_html( preg_replace( '#^https?://[^/]+/#', '/', $row['url'] ) ); ?></a>
										</span>
									</div>
									<?php if ( $row['score'] !== null ) : ?>
										<span class="mev-cannibalization-score" style="font-size:13px;color:var(--mev-gray-600);"><?php echo esc_html( (string) (int) $row['score'] ); ?>/100</span>
									<?php else : ?>
										<span class="mev-text-muted" style="font-size:13px;">&mdash;</span>
									<?php endif; ?>
									<a href="<?php echo esc_url( $row['edit_link'] ); ?>" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Edit', 'meyvora-seo' ); ?></a>
									<?php if ( $row['is_primary'] ) : ?>
										<span class="mev-badge mev-badge--green" style="font-size:11px;"><?php esc_html_e( 'Primary', 'meyvora-seo' ); ?></span>
									<?php else : ?>
										<button type="button" class="mev-btn mev-btn--secondary mev-btn--sm mev-set-primary" data-post-id="<?php echo esc_attr( (string) (int) $row['id'] ); ?>" data-keyword="<?php echo esc_attr( $keyword ); ?>">
											<?php esc_html_e( 'Set as Primary', 'meyvora-seo' ); ?>
										</button>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( $total_pages > 1 ) : ?>
			<nav class="mev-pagination" style="margin-top:24px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
				<?php
				echo '<span class="mev-text-muted" style="font-size:13px;">';
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'meyvora-seo' ),
					(int) $paged,
					(int) $total_pages
				);
				echo '</span>';
				if ( $paged > 1 ) {
					$prev_url = add_query_arg( 'paged', $paged - 1, $base_url );
					echo ' <a href="' . esc_url( $prev_url ) . '" class="mev-btn mev-btn--secondary mev-btn--sm">' . esc_html__( 'Previous', 'meyvora-seo' ) . '</a> ';
				}
				if ( $paged < $total_pages ) {
					$next_url = add_query_arg( 'paged', $paged + 1, $base_url );
					echo ' <a href="' . esc_url( $next_url ) . '" class="mev-btn mev-btn--secondary mev-btn--sm">' . esc_html__( 'Next', 'meyvora-seo' ) . '</a> ';
				}
				?>
			</nav>
		<?php endif; ?>
	<?php endif; ?>
</div>
