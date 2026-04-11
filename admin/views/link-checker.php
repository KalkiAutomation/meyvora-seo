<?php
/**
 * Link Checker admin view: broken links table and Fix modal.
 *
 * @package Meyvora_SEO
 * @var array<int, object> $broken_links Rows from meyvora_seo_link_checks (is_broken = 1).
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<style>
.mev-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; padding: 20px; }
.mev-modal-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.4); }
.mev-modal-content { position: relative; background: var(--mev-surface, #fff); border-radius: var(--mev-radius, 10px); box-shadow: var(--mev-shadow-xl, 0 20px 25px rgba(0,0,0,0.1)); padding: 24px; width: 100%; }
</style>
<div class="wrap meyvora-link-checker-page">
	<div class="mev-page-header">
		<div class="mev-page-header-left">
			<div class="mev-page-logo">M</div>
			<div>
				<h1 class="mev-page-title"><?php esc_html_e( 'Link Checker', 'meyvora-seo' ); ?></h1>
				<p class="mev-page-subtitle"><?php esc_html_e( 'Broken external links detected in your content (checked in the background every 15 minutes).', 'meyvora-seo' ); ?></p>
			</div>
		</div>
	</div>

	<?php if ( empty( $broken_links ) ) : ?>
		<div class="mev-card">
			<div class="mev-card-body">
				<p class="mev-text-muted" style="margin:0;">
					<?php esc_html_e( 'No broken links found. The scanner runs every 15 minutes and checks up to 10 URLs per run.', 'meyvora-seo' ); ?>
				</p>
			</div>
		</div>
	<?php else : ?>
		<div class="mev-card">
			<div class="mev-card-body" style="overflow-x:auto;">
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Broken URL', 'meyvora-seo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Anchor text', 'meyvora-seo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'HTTP status', 'meyvora-seo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last checked', 'meyvora-seo' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Fix', 'meyvora-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $broken_links as $row ) :
							$post_title = get_the_title( $row->post_id );
							$edit_link  = get_edit_post_link( $row->post_id, 'raw' );
							$last       = $row->last_checked ? gmdate( 'Y-m-d H:i', strtotime( $row->last_checked ) ) : '—';
						?>
							<tr data-check-id="<?php echo (int) $row->id; ?>" data-old-url="<?php echo esc_attr( $row->url ); ?>">
								<td>
									<?php if ( $edit_link ) : ?>
										<a href="<?php echo esc_url( $edit_link ); ?>"><?php echo esc_html( $post_title ?: __( '(no title)', 'meyvora-seo' ) ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $post_title ?: __( '(no title)', 'meyvora-seo' ) ); ?>
									<?php endif; ?>
								</td>
								<td><a href="<?php echo esc_url( $row->url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_trim_words( $row->url, 8 ) ); ?></a></td>
								<td><?php echo esc_html( wp_trim_words( $row->anchor_text, 5 ) ?: '—' ); ?></td>
								<td><?php echo (int) $row->http_status ? (int) $row->http_status : '—'; ?></td>
								<td><?php echo esc_html( $last ); ?></td>
								<td>
									<button type="button" class="button button-small meyvora-link-checker-fix" data-check-id="<?php echo (int) $row->id; ?>" data-old-url="<?php echo esc_attr( $row->url ); ?>">
										<?php esc_html_e( 'Fix', 'meyvora-seo' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	<?php endif; ?>
</div>

<!-- Fix modal -->
<div id="meyvora-link-checker-modal" class="mev-modal" style="display:none;" role="dialog" aria-labelledby="meyvora-link-checker-modal-title">
	<div class="mev-modal-backdrop"></div>
	<div class="mev-modal-content" style="max-width:520px;">
		<h2 id="meyvora-link-checker-modal-title" class="mev-modal-title"><?php esc_html_e( 'Replace broken link', 'meyvora-seo' ); ?></h2>
		<p class="mev-text-muted" style="margin:0 0 12px;font-size:13px;">
			<?php esc_html_e( 'Enter the new URL to replace the broken link in the post content.', 'meyvora-seo' ); ?>
		</p>
		<p id="meyvora-link-checker-old-url" style="word-break:break-all;font-size:12px;color:var(--mev-gray-600);margin:0 0 12px;"></p>
		<div style="margin-bottom:12px;">
			<label for="meyvora-link-checker-new-url"><?php esc_html_e( 'Replacement URL', 'meyvora-seo' ); ?></label>
			<input type="url" id="meyvora-link-checker-new-url" class="large-text" placeholder="https://" style="width:100%;margin-top:4px;" />
		</div>
		<div class="mev-modal-actions" style="display:flex;gap:8px;justify-content:flex-end;">
			<button type="button" class="button meyvora-link-checker-modal-cancel"><?php esc_html_e( 'Cancel', 'meyvora-seo' ); ?></button>
			<button type="button" class="button button-primary meyvora-link-checker-modal-save"><?php esc_html_e( 'Update link', 'meyvora-seo' ); ?></button>
		</div>
	</div>
</div>
