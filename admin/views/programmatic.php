<?php
/**
 * Programmatic SEO: template selector, data source (CSV / CPT), preview, generate, groups list.
 *
 * @package Meyvora_SEO
 * @var array $templates WP_Post[] Template posts.
 * @var array $post_types WP_Post_Type[] Public post types.
 * @var WP_Term[] $groups Terms for meyvora_programmatic_group.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** @var array $meyvora_seo_i18n Localized strings for this view */
$meyvora_seo_i18n = array(
	'title'           => __( 'Programmatic SEO', 'meyvora-seo' ),
	'subtitle'        => __( 'Generate hundreds of SEO-optimized pages from templates and data', 'meyvora-seo' ),
	'template'        => __( 'Template', 'meyvora-seo' ),
	'dataSource'      => __( 'Data source', 'meyvora-seo' ),
	'csvUpload'       => __( 'CSV upload', 'meyvora-seo' ),
	'cpt'             => __( 'Custom Post Type', 'meyvora-seo' ),
	'uploadCsv'       => __( 'Upload CSV', 'meyvora-seo' ),
	'selectTemplate'  => __( 'Select a template', 'meyvora-seo' ),
	'selectCpt'       => __( 'Select post type', 'meyvora-seo' ),
	'mapping'         => __( 'Variable → Field mapping (one per line: variable=field)', 'meyvora-seo' ),
	'preview'         => __( 'Preview (first 3 pages)', 'meyvora-seo' ),
	'generate'        => __( 'Generate Pages', 'meyvora-seo' ),
	'groups'          => __( 'Generated groups', 'meyvora-seo' ),
	'deleteAll'       => __( 'Delete all pages in group', 'meyvora-seo' ),
	'addTemplate'     => __( 'Add template', 'meyvora-seo' ),
	'max500'          => __( 'Max 500 pages per run.', 'meyvora-seo' ),
	'max2000'         => __( 'Max 2000 pages per template.', 'meyvora-seo' ),
);
?>
<div class="wrap meyvora-programmatic-page">
	<?php settings_errors( 'meyvora_programmatic' ); ?>
	<div class="mev-page-header">
		<div class="mev-page-header-left">
			<div class="mev-page-logo">M</div>
			<div>
				<h1 class="mev-page-title"><?php echo esc_html( $meyvora_seo_i18n['title'] ); ?></h1>
				<p class="mev-page-subtitle"><?php echo esc_html( $meyvora_seo_i18n['subtitle'] ); ?></p>
			</div>
		</div>
		<nav class="mev-page-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>"><?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Meyvora_SEO_Programmatic::POST_TYPE_TEMPLATE ) ); ?>"><?php echo esc_html( $meyvora_seo_i18n['addTemplate'] ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></a>
		</nav>
	</div>

	<?php if ( empty( $templates ) ) : ?>
	<div class="mev-empty-state">
		<div class="mev-empty-state__icon" aria-hidden="true">
			<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
				<rect x="3" y="3" width="7" height="7" rx="1"/>
				<rect x="14" y="3" width="7" height="7" rx="1"/>
				<rect x="3" y="14" width="7" height="7" rx="1"/>
				<rect x="14" y="14" width="7" height="7" rx="1"/>
				<line x1="10" y1="5.5" x2="14" y2="5.5"/>
				<line x1="10" y1="18.5" x2="14" y2="18.5"/>
				<line x1="5.5" y1="10" x2="5.5" y2="14"/>
				<line x1="18.5" y1="10" x2="18.5" y2="14"/>
			</svg>
		</div>
		<h3 class="mev-empty-state__title"><?php esc_html_e( 'No programmatic templates yet', 'meyvora-seo' ); ?></h3>
		<p class="mev-empty-state__body"><?php esc_html_e( 'Create a template and upload a CSV to generate hundreds of SEO-optimised pages automatically.', 'meyvora-seo' ); ?></p>
		<a href="#mev-prog-new-template" class="button button-primary mev-empty-state__cta"><?php esc_html_e( 'Set up your first template', 'meyvora-seo' ); ?></a>
	</div>
	<?php endif; ?>

	<div class="mev-card" id="mev-prog-new-template" style="margin-bottom:20px;">
		<div class="mev-card-header">
			<span class="mev-card-title"><?php esc_html_e( 'Generator', 'meyvora-seo' ); ?></span>
		</div>
		<div class="mev-card-body">
			<div class="mev-form-row" style="margin-bottom:16px;">
				<label class="mev-label" for="mev-prog-template"><?php echo esc_html( $meyvora_seo_i18n['template'] ); ?></label>
				<select id="mev-prog-template" class="mev-select">
					<option value=""><?php echo esc_html( $meyvora_seo_i18n['selectTemplate'] ); ?></option>
					<?php foreach ( $templates as $meyvora_seo_template ) : ?>
						<option value="<?php echo esc_attr( (string) (int) $meyvora_seo_template->ID ); ?>"><?php echo esc_html( $meyvora_seo_template->post_title ?: __( '(no title)', 'meyvora-seo' ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="mev-form-row" style="margin-bottom:16px;">
				<label class="mev-label"><?php echo esc_html( $meyvora_seo_i18n['dataSource'] ); ?></label>
				<div>
					<label><input type="radio" name="mev-prog-source" value="csv" checked> <?php echo esc_html( $meyvora_seo_i18n['csvUpload'] ); ?></label>
					<label style="margin-left:16px;"><input type="radio" name="mev-prog-source" value="cpt"> <?php echo esc_html( $meyvora_seo_i18n['cpt'] ); ?></label>
				</div>
			</div>

			<div id="mev-prog-csv-wrap" class="mev-prog-source-panel">
				<div class="mev-form-row" style="margin-bottom:12px;">
					<input type="file" id="mev-prog-csv-file" accept=".csv,text/csv" />
					<button type="button" id="mev-prog-csv-parse" class="mev-btn mev-btn--secondary mev-btn--sm" style="margin-left:8px;"><?php echo esc_html( $meyvora_seo_i18n['uploadCsv'] ); ?></button>
				</div>
				<p id="mev-prog-csv-status" class="mev-text-muted" style="font-size:12px;"></p>
			</div>

			<div id="mev-prog-cpt-wrap" class="mev-prog-source-panel" style="display:none;">
				<div class="mev-form-row" style="margin-bottom:8px;">
					<label class="mev-label" for="mev-prog-cpt"><?php echo esc_html( $meyvora_seo_i18n['selectCpt'] ); ?></label>
					<select id="mev-prog-cpt" class="mev-select">
						<option value=""><?php echo esc_html( $meyvora_seo_i18n['selectCpt'] ); ?></option>
						<?php foreach ( $post_types as $meyvora_seo_pt_slug => $meyvora_seo_pt_label ) : ?>
							<option value="<?php echo esc_attr( $meyvora_seo_pt_slug ); ?>"><?php echo esc_html( $meyvora_seo_pt_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="mev-form-row" style="margin-bottom:8px;">
					<label class="mev-label" for="mev-prog-mapping"><?php echo esc_html( $meyvora_seo_i18n['mapping'] ); ?></label>
					<textarea id="mev-prog-mapping" rows="4" class="large-text" placeholder="city=post_title&#10;service=meta:service_name&#10;year=meta:year"></textarea>
					<p class="mev-field-help"><?php esc_html_e( 'Use post_title, post_content, or meta:your_meta_key', 'meyvora-seo' ); ?></p>
				</div>
			</div>

			<p class="mev-field-help" style="margin-top:12px;"><?php echo esc_html( $meyvora_seo_i18n['max500'] ); ?> <?php echo esc_html( $meyvora_seo_i18n['max2000'] ); ?></p>

			<div style="margin-top:20px;">
				<button type="button" id="mev-prog-preview" class="mev-btn mev-btn--secondary"><?php echo esc_html( $meyvora_seo_i18n['preview'] ); ?></button>
				<button type="button" id="mev-prog-generate" class="mev-btn mev-btn--primary" style="margin-left:8px;"><?php echo esc_html( $meyvora_seo_i18n['generate'] ); ?></button>
			</div>

			<div id="mev-prog-preview-box" style="margin-top:20px; display:none;">
				<h3 style="font-size:14px; margin-bottom:8px;"><?php echo esc_html( $meyvora_seo_i18n['preview'] ); ?></h3>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'Title', 'meyvora-seo' ); ?></th><th><?php esc_html_e( 'Slug', 'meyvora-seo' ); ?></th><th><?php esc_html_e( 'Meta title', 'meyvora-seo' ); ?></th></tr></thead>
					<tbody id="mev-prog-preview-tbody"></tbody>
				</table>
			</div>
		</div>
	</div>

	<div id="mev-prog-progress-wrap" class="mev-audit-overlay" style="display:none;">
		<div class="mev-audit-overlay-card">
			<svg class="mev-audit-ring" viewBox="0 0 120 120" width="110" height="110">
				<circle cx="60" cy="60" r="50" class="mev-ring-bg"/>
				<circle id="mev-prog-ring-fill" cx="60" cy="60" r="50" class="mev-ring-fill" stroke-dasharray="314" stroke-dashoffset="314"/>
			</svg>
			<p class="mev-overlay-msg" id="mev-prog-progress-text"><?php esc_html_e( 'Preparing…', 'meyvora-seo' ); ?></p>
			<p id="mev-prog-progress-pct" style="font-weight:600;">0%</p>
		</div>
	</div>

	<div class="mev-card">
		<div class="mev-card-header">
			<span class="mev-card-title"><?php echo esc_html( $meyvora_seo_i18n['groups'] ); ?></span>
		</div>
		<div class="mev-card-body">
			<?php if ( empty( $groups ) ) : ?>
				<p class="mev-text-muted"><?php esc_html_e( 'No generated groups yet. Create a template, add data, and run Generate.', 'meyvora-seo' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Group name', 'meyvora-seo' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Pages', 'meyvora-seo' ); ?></th>
							<th style="width:180px;"><?php esc_html_e( 'Actions', 'meyvora-seo' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $groups as $term ) :
							$meyvora_seo_group_count = $term->count;
							?>
							<tr>
								<td><?php echo esc_html( $term->name ); ?></td>
								<td><?php echo esc_html( (string) (int) $meyvora_seo_group_count ); ?></td>
								<td>
									<button type="button" class="mev-btn mev-btn--secondary mev-btn--sm mev-prog-delete-group" data-term-id="<?php echo esc_attr( (string) (int) $term->term_id ); ?>" data-count="<?php echo esc_attr( (string) (int) $meyvora_seo_group_count ); ?>">
										<?php echo esc_html( $meyvora_seo_i18n['deleteAll'] ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
