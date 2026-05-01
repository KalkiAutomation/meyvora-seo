<?php
/**
 * Bulk SEO Editor view: filters, spreadsheet table, inline edit, Save All, Export, Apply Template.
 *
 * @package Meyvora_SEO
 * @var array $filters  get_filter_values()
 * @var array $data     get_posts_and_meta(): posts, total, pages, meta_map
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$posts         = isset( $data['posts'] ) ? $data['posts'] : array();
$total         = isset( $data['total'] ) ? (int) $data['total'] : 0;
$pages         = isset( $data['pages'] ) ? (int) $data['pages'] : 1;
$meta_map      = isset( $data['meta_map'] ) ? $data['meta_map'] : array();
$rows          = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : array();
$gsc_connected = ! empty( $data['gsc_connected'] );
$current  = isset( $filters['paged'] ) ? (int) $filters['paged'] : 1;
$post_type = isset( $filters['post_type'] ) ? $filters['post_type'] : '';
$cat      = isset( $filters['cat'] ) ? (int) $filters['cat'] : 0;
$score    = isset( $filters['score'] ) ? $filters['score'] : '';
$missing  = isset( $filters['missing'] ) && is_array( $filters['missing'] ) ? $filters['missing'] : array();
$templates  = Meyvora_SEO_Bulk_Editor::get_title_templates();
$categories = get_categories( array( 'hide_empty' => false ) );
?>
<div class="wrap meyvora-bulk-editor-page meyvora-page-luxury">
	<div class="mev-page-header mev-page-header--luxury">
		<div class="mev-page-header-left">
			<div class="mev-page-logo mev-page-logo--gradient">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<rect x="3" y="3" width="18" height="18" rx="2"/>
					<path d="M3 9h18M3 15h18M9 3v18M15 3v18"/>
				</svg>
			</div>
			<div>
				<h1 class="mev-page-title">
					<?php esc_html_e( 'Bulk Editor', 'meyvora-seo' ); ?>
				</h1>
				<p class="mev-page-subtitle">
					<?php
					printf(
						/* translators: %d: number of posts */
						esc_html__( 'Editing %d posts · Click any cell to edit inline', 'meyvora-seo' ),
						(int) $total
					);
					?>
				</p>
			</div>
		</div>
		<nav class="mev-page-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>">
				<?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-bulk-editor' ) ); ?>" class="active">
				<?php esc_html_e( 'Bulk Editor', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-site-audit' ) ); ?>">
				<?php esc_html_e( 'Site Audit', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm">
				<?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></a>
		</nav>
	</div>

	<div class="mev-card mev-bulk-filters-card">
		<div class="mev-card-body mev-bulk-filters-body">
			<form method="get" action="" id="mev-bulk-editor-filters" class="mev-bulk-editor-filters">
				<input type="hidden" name="page" value="<?php echo esc_attr( Meyvora_SEO_Bulk_Editor::PAGE_SLUG ); ?>" />
				<div class="mev-bulk-filters-row">
					<label for="mev-filter-post-type" class="mev-filter-label"><?php esc_html_e( 'Post type:', 'meyvora-seo' ); ?></label>
					<select name="post_type" id="mev-filter-post-type" class="mev-filter-select">
						<option value=""><?php esc_html_e( 'All', 'meyvora-seo' ); ?></option>
						<option value="post" <?php selected( $post_type, 'post' ); ?>><?php esc_html_e( 'Post', 'meyvora-seo' ); ?></option>
						<option value="page" <?php selected( $post_type, 'page' ); ?>><?php esc_html_e( 'Page', 'meyvora-seo' ); ?></option>
					</select>
					<?php if ( ! empty( $categories ) ) : ?>
						<label for="mev-filter-cat" class="mev-filter-label"><?php esc_html_e( 'Category:', 'meyvora-seo' ); ?></label>
						<select name="cat" id="mev-filter-cat" class="mev-filter-select">
							<option value="0"><?php esc_html_e( 'All', 'meyvora-seo' ); ?></option>
							<?php foreach ( $categories as $c ) : ?>
								<option value="<?php echo esc_attr( (string) (int) $c->term_id ); ?>" <?php selected( $cat, $c->term_id ); ?>><?php echo esc_html( $c->name ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
					<label for="mev-filter-score" class="mev-filter-label"><?php esc_html_e( 'SEO score:', 'meyvora-seo' ); ?></label>
					<select name="score" id="mev-filter-score" class="mev-filter-select">
						<option value="" <?php selected( $score, '' ); ?>><?php esc_html_e( 'All', 'meyvora-seo' ); ?></option>
						<option value="good" <?php selected( $score, 'good' ); ?>><?php esc_html_e( 'Good (80+)', 'meyvora-seo' ); ?></option>
						<option value="okay" <?php selected( $score, 'okay' ); ?>><?php esc_html_e( 'Okay (50–79)', 'meyvora-seo' ); ?></option>
						<option value="poor" <?php selected( $score, 'poor' ); ?>><?php esc_html_e( 'Poor (&lt;50)', 'meyvora-seo' ); ?></option>
					</select>
					<span class="mev-filter-missing">
						<label class="mev-filter-label"><input type="checkbox" name="missing[]" value="title" <?php checked( in_array( 'title', $missing, true ) ); ?> /> <?php esc_html_e( 'Missing title', 'meyvora-seo' ); ?></label>
						<label class="mev-filter-label"><input type="checkbox" name="missing[]" value="description" <?php checked( in_array( 'description', $missing, true ) ); ?> /> <?php esc_html_e( 'Missing description', 'meyvora-seo' ); ?></label>
						<label class="mev-filter-label"><input type="checkbox" name="missing[]" value="focus_keyword" <?php checked( in_array( 'focus_keyword', $missing, true ) ); ?> /> <?php esc_html_e( 'Missing keyword', 'meyvora-seo' ); ?></label>
						<label class="mev-filter-label"><input type="checkbox" name="missing[]" value="og_image" <?php checked( in_array( 'og_image', $missing, true ) ); ?> /> <?php esc_html_e( 'Missing OG image', 'meyvora-seo' ); ?></label>
					</span>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'meyvora-seo' ); ?></button>
				</div>
			</form>
		</div>
	</div>

	<div class="mev-bulk-editor-toolbar mev-luxury-toolbar">
		<div class="mev-luxury-toolbar-left">
			<select id="mev-apply-template" class="mev-filter-select">
				<?php foreach ( $templates as $tpl_key => $tpl_label ) : ?>
					<option value="<?php echo esc_attr( $tpl_key ); ?>">
						<?php echo esc_html( $tpl_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" id="mev-bulk-apply-template" class="mev-btn mev-btn--secondary mev-btn--sm">
				<?php esc_html_e( 'Apply Template', 'meyvora-seo' ); ?></button>
			<span id="mev-dirty-chip" class="mev-dirty-chip" style="display:none;">
				<span id="mev-dirty-count">0</span>
				<?php esc_html_e( 'unsaved changes', 'meyvora-seo' ); ?>
			</span>
		</div>
		<div class="mev-luxury-toolbar-right">
			<button type="button" id="mev-bulk-export" class="mev-btn mev-btn--secondary mev-btn--sm">
				<?php esc_html_e( 'Export CSV', 'meyvora-seo' ); ?></button>
			<button type="button" id="mev-bulk-save" class="mev-btn mev-btn--save-all">
				<?php esc_html_e( 'Save All Changes', 'meyvora-seo' ); ?></button>
		</div>
	</div>

	<div class="mev-bulk-editor-table-wrap">
		<table class="wp-list-table widefat fixed striped mev-bulk-editor-table mev-luxury-table" id="mev-bulk-editor-table">
			<thead>
				<tr>
					<td class="check-column">
						<input type="checkbox" id="mev-bulk-select-all" aria-label="<?php esc_attr_e( 'Select all on page', 'meyvora-seo' ); ?>" />
					</td>
					<th scope="col" class="column-ai mev-col-ai" style="width:60px;"><?php esc_html_e( 'AI', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-post-title"><?php esc_html_e( 'Post Title', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-seo-title"><?php esc_html_e( 'SEO Title', 'meyvora-seo' ); ?> <span class="mev-char-limit">(60)</span></th>
					<th scope="col" class="column-meta-desc"><?php esc_html_e( 'Meta Description', 'meyvora-seo' ); ?> <span class="mev-char-limit">(160)</span></th>
					<th scope="col" class="column-focus-keyword"><?php esc_html_e( 'Focus Keyword', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-score num"><?php esc_html_e( 'Score', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-gsc-clicks num"><?php esc_html_e( 'Clicks', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-gsc-impressions num"><?php esc_html_e( 'Impr.', 'meyvora-seo' ); ?></th>
					<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'meyvora-seo' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $posts ) ) : ?>
					<tr><td colspan="10" class="mev-bulk-empty"><?php esc_html_e( 'No posts found.', 'meyvora-seo' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $posts as $post ) :
						$pid   = (int) $post->ID;
						$meta  = $meta_map[ $pid ] ?? array();
						$seo_title = isset( $meta[ MEYVORA_SEO_META_TITLE ] ) ? $meta[ MEYVORA_SEO_META_TITLE ] : '';
						$seo_desc  = isset( $meta[ MEYVORA_SEO_META_DESCRIPTION ] ) ? $meta[ MEYVORA_SEO_META_DESCRIPTION ] : '';
						$score_val = isset( $meta[ MEYVORA_SEO_META_SCORE ] ) && is_numeric( $meta[ MEYVORA_SEO_META_SCORE ] ) ? (int) $meta[ MEYVORA_SEO_META_SCORE ] : null;
						$status    = $post->post_status;
						$edit_url  = get_edit_post_link( $pid, 'raw' ) ?: '#';
						if ( $score_val !== null ) {
							if ( $score_val >= 80 ) {
								$pill = 'mev-score-pill mev-score-good';
							} elseif ( $score_val >= 50 ) {
								$pill = 'mev-score-pill mev-score-okay';
							} else {
								$pill = 'mev-score-pill mev-score-poor';
							}
						}
					?>
						<tr class="mev-bulk-row" data-post-id="<?php echo esc_attr( (string) (int) $pid ); ?>" data-post-title="<?php echo esc_attr( $post->post_title ); ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" class="mev-bulk-row-cb" value="<?php echo esc_attr( (string) (int) $pid ); ?>" />
							</th>
							<td class="column-ai mev-col-ai">
								<button type="button" class="mev-btn mev-btn--xs mev-ai-fill" data-post-id="<?php echo esc_attr( (string) (int) $pid ); ?>" title="<?php esc_attr_e( 'Generate with AI', 'meyvora-seo' ); ?>">&#10022;</button>
							</td>
							<td class="column-post-title">
								<?php if ( $edit_url ) : ?>
									<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $post->post_title ); ?></a>
								<?php else : ?>
									<?php echo esc_html( $post->post_title ); ?>
								<?php endif; ?>
							</td>
							<td class="column-seo-title mev-editable-cell" data-field="title" data-max="60">
								<span class="mev-cell-value"><?php echo esc_html( $seo_title ); ?></span>
								<span class="mev-cell-counter"></span>
							</td>
							<td class="column-meta-desc mev-editable-cell" data-field="description" data-max="160">
								<span class="mev-cell-value"><?php echo esc_html( $seo_desc ); ?></span>
								<span class="mev-cell-counter"></span>
							</td>
							<?php
							$focus_raw   = isset( $meta[ MEYVORA_SEO_META_FOCUS_KEYWORD ] ) ? $meta[ MEYVORA_SEO_META_FOCUS_KEYWORD ] : '';
							$focus_arr   = is_string( $focus_raw ) ? json_decode( $focus_raw, true ) : $focus_raw;
							if ( is_array( $focus_arr ) && ! empty( $focus_arr ) ) {
								$first = reset( $focus_arr );
								$focus_display = is_array( $first ) && isset( $first['keyword'] )
									? implode( ', ', array_column( $focus_arr, 'keyword' ) )
									: implode( ', ', array_map( 'strval', $focus_arr ) );
							} else {
								$focus_display = '';
							}
							// Never show literal "[]" or other empty-like values
							if ( $focus_display === '[]' || trim( (string) $focus_display ) === '' ) {
								$focus_display = '';
							}
							?>
							<td class="column-focus-keyword mev-editable-cell" data-field="focus_keyword" data-max="80">
								<span class="mev-cell-value"><?php echo esc_html( $focus_display ); ?></span>
								<span class="mev-cell-counter"></span>
							</td>
							<td class="column-score num">
								<?php if ( $score_val !== null ) : ?>
									<span class="<?php echo esc_attr( $pill ); ?>">
										<?php echo esc_html( (string) (int) $score_val ); ?></span>
								<?php else : ?>&mdash;<?php endif; ?>
							</td>
							<td class="column-gsc-clicks num">
								<?php
								if ( $gsc_connected && isset( $rows[ $pid ] ) ) {
									echo esc_html( (string) (int) ( $rows[ $pid ]['gsc_clicks'] ?? 0 ) );
								} else {
									echo '&mdash;';
								}
								?>
							</td>
							<td class="column-gsc-impressions num">
								<?php
								if ( $gsc_connected && isset( $rows[ $pid ] ) ) {
									echo esc_html( (string) (int) ( $rows[ $pid ]['gsc_impressions'] ?? 0 ) );
								} else {
									echo '&mdash;';
								}
								?>
							</td>
							<td class="column-status">
								<span class="mev-status-chip mev-status-<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( $status ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<?php if ( $pages > 1 ) : ?>
		<div class="mev-bulk-pagination tablenav bottom">
			<span class="displaying-num"><?php echo esc_html( (string) (int) $total ); ?> <?php esc_html_e( 'items', 'meyvora-seo' ); ?></span>
			<span class="pagination-links">
				<?php
				$query_args = array( 'page' => Meyvora_SEO_Bulk_Editor::PAGE_SLUG );
				if ( $post_type ) {
					$query_args['post_type'] = $post_type;
				}
				if ( $cat ) {
					$query_args['cat'] = $cat;
				}
				if ( $score ) {
					$query_args['score'] = $score;
				}
				if ( ! empty( $missing ) ) {
					$query_args['missing'] = $missing;
				}
				$base = admin_url( 'admin.php' ) . '?' . http_build_query( $query_args );
				if ( $current > 1 ) {
					$prev = add_query_arg( 'paged', $current - 1, $base );
					echo '<a class="prev-page button" href="' . esc_url( $prev ) . '">&laquo;</a> ';
				}
				echo '<span class="paging-input"><span class="tablenav-paging-text">' . (int) $current . ' ' . esc_html__( 'of', 'meyvora-seo' ) . ' <span class="total-pages">' . (int) $pages . '</span></span></span>';
				if ( $current < $pages ) {
					$next = add_query_arg( 'paged', $current + 1, $base );
					echo ' <a class="next-page button" href="' . esc_url( $next ) . '">&raquo;</a>';
				}
				?>
			</span>
		</div>
	<?php endif; ?>
</div>
