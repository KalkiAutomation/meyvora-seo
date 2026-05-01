<?php
/**
 * SEO Automation rules: list, add rule form (condition builder + actions), save, apply to all.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.Security.NonceVerification.Recommended -- View template; display GET params.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$automation = $this->get_automation();
$rules = $automation->get_rules();

$fields = array(
	'post_type'         => __( 'Post type', 'meyvora-seo' ),
	'category'          => __( 'Category', 'meyvora-seo' ),
	'tag'               => __( 'Tag', 'meyvora-seo' ),
	'post_status'       => __( 'Post status', 'meyvora-seo' ),
	'has_focus_keyword' => __( 'Has focus keyword', 'meyvora-seo' ),
	'has_schema'        => __( 'Has schema type', 'meyvora-seo' ),
	'seo_score'         => __( 'SEO score', 'meyvora-seo' ),
);
$operators = array(
	'equals'       => __( 'equals', 'meyvora-seo' ),
	'contains'     => __( 'contains', 'meyvora-seo' ),
	'is_empty'     => __( 'is empty', 'meyvora-seo' ),
	'greater_than' => __( 'greater than', 'meyvora-seo' ),
	'less_than'    => __( 'less than', 'meyvora-seo' ),
);
$actions_list = array(
	'auto_title_template'   => array( 'label' => __( 'Auto-generate SEO title from template', 'meyvora-seo' ), 'has_value' => true, 'placeholder' => '{product_name} | Best Shoes' ),
	'auto_desc_excerpt'     => array( 'label' => __( 'Auto-generate meta description from excerpt', 'meyvora-seo' ), 'has_value' => false ),
	'auto_desc_content'    => array( 'label' => __( 'Auto-generate meta description from content', 'meyvora-seo' ), 'has_value' => false ),
	'auto_schema_type'      => array( 'label' => __( 'Auto-set schema type', 'meyvora-seo' ), 'has_value' => true, 'placeholder' => 'Product' ),
	'auto_noindex'          => array( 'label' => __( 'Auto-add to noindex', 'meyvora-seo' ), 'has_value' => false ),
	'auto_canonical_pattern' => array( 'label' => __( 'Auto-set canonical to URL pattern', 'meyvora-seo' ), 'has_value' => true, 'placeholder' => 'https://example.com/shoes/{slug}' ),
	'auto_set_status'       => array( 'label' => __( 'Set post status (e.g. Needs Review)', 'meyvora-seo' ), 'has_value' => true, 'placeholder' => 'pending' ),
	'auto_keyword_from_title' => array( 'label' => __( 'Auto-set focus keyword from title words', 'meyvora-seo' ), 'has_value' => false ),
	'auto_og_image_from_featured' => array( 'label' => __( 'Auto-fill OG image from featured image', 'meyvora-seo' ), 'has_value' => false ),
	'auto_canonical_archive' => array( 'label' => __( 'Auto-generate canonical from post type archive', 'meyvora-seo' ), 'has_value' => false ),
	'auto_ai_generate_description' => array( 'label' => __( 'AI: Generate meta description if empty', 'meyvora-seo' ), 'has_value' => false, 'has_overwrite' => true ),
	'auto_ai_generate_title' => array( 'label' => __( 'AI: Generate SEO title if empty', 'meyvora-seo' ), 'has_value' => false, 'has_overwrite' => true ),
);

$post_types = get_post_types( array( 'public' => true ), 'objects' );
$post_type_options = array();
foreach ( $post_types as $pt ) {
	$post_type_options[ $pt->name ] = $pt->label;
}
$saved = isset( $_GET['saved'] ) && $_GET['saved'] === '1';
$error = isset( $_GET['error'] ) && $_GET['error'] === '1';
$total_rules   = count( $rules );
$active_rules  = count( array_filter( $rules, fn( $r ) => ! empty( $r['enabled'] ) ) );
$inactive_rules = $total_rules - $active_rules;
?>
<div class="wrap meyvora-automation-page">
	<div class="mev-page-header mev-page-header--luxury">
		<div class="mev-page-header-left">
			<div class="mev-page-logo mev-page-logo--gradient">
				<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
				</svg>
			</div>
			<div>
				<h1 class="mev-page-title"><?php esc_html_e( 'Automation', 'meyvora-seo' ); ?></h1>
				<p class="mev-page-subtitle"><?php
					$active = count( array_filter( $rules, fn( $r ) => ! empty( $r['enabled'] ) ) );
					printf( /* translators: %d: number of active rules */ esc_html__( '%d active rules', 'meyvora-seo' ), (int) $active );
				?></p>
			</div>
		</div>
		<nav class="mev-page-nav">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo' ) ); ?>"><?php esc_html_e( 'Dashboard', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-reports' ) ); ?>"><?php esc_html_e( 'Reports', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-automation' ) ); ?>" class="active"><?php esc_html_e( 'Automation', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-audit' ) ); ?>"><?php esc_html_e( 'SEO Audit', 'meyvora-seo' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=meyvora-seo-settings' ) ); ?>" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Settings', 'meyvora-seo' ); ?></a>
		</nav>
	</div>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Rules saved.', 'meyvora-seo' ); ?></p></div>
	<?php endif; ?>
	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'Failed to save rules. Check the data and try again.', 'meyvora-seo' ); ?></p></div>
	<?php endif; ?>

	<form method="post" action="" id="meyvora-automation-form">
		<?php wp_nonce_field( 'meyvora_automation_save', '_wpnonce' ); ?>
		<input type="hidden" name="meyvora_automation_save" value="1" />
		<textarea name="meyvora_automation_rules" id="meyvora_automation_rules" style="display:none;" aria-hidden="true"><?php echo esc_textarea( wp_json_encode( $rules ) ); ?></textarea>

		<div class="mev-automation-layout">
		<div class="mev-card mev-mb-20">
			<div class="mev-card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
				<span class="mev-card-title"><?php esc_html_e( 'Rules', 'meyvora-seo' ); ?></span>
				<div>
					<button type="button" id="mev-automation-apply-all" class="mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Apply to all existing posts', 'meyvora-seo' ); ?></button>
					<button type="submit" class="mev-btn mev-btn--primary mev-btn--sm"><?php esc_html_e( 'Save rules', 'meyvora-seo' ); ?></button>
				</div>
			</div>
			<div class="mev-card-body">
				<div class="mev-auto-stats">
					<div class="mev-auto-stat"><span class="mev-auto-stat-val"><?php echo esc_html( (string) (int) $total_rules ); ?></span><span class="mev-auto-stat-lbl"><?php esc_html_e( 'Total Rules', 'meyvora-seo' ); ?></span></div>
					<div class="mev-auto-stat mev-auto-stat--active"><span class="mev-auto-stat-val"><?php echo esc_html( (string) (int) $active_rules ); ?></span><span class="mev-auto-stat-lbl"><?php esc_html_e( 'Active', 'meyvora-seo' ); ?></span></div>
					<div class="mev-auto-stat mev-auto-stat--inactive"><span class="mev-auto-stat-val"><?php echo esc_html( (string) (int) $inactive_rules ); ?></span><span class="mev-auto-stat-lbl"><?php esc_html_e( 'Inactive', 'meyvora-seo' ); ?></span></div>
				</div>
				<div id="mev-rules-list">
					<?php foreach ( $rules as $rule ) : ?>
						<?php
						$rid       = isset( $rule['id'] ) ? esc_attr( $rule['id'] ) : esc_attr( wp_generate_uuid4() );
						$enabled   = ! empty( $rule['enabled'] );
						$rule_logic = isset( $rule['logic'] ) && strtoupper( (string) $rule['logic'] ) === 'OR' ? 'OR' : 'AND';
						$rule_attr = esc_attr( wp_json_encode( $rule ) );
						$conds     = $rule['conditions'] ?? array();
						$acts      = $rule['actions'] ?? array();
						?>
						<div class="mev-rule-card mev-rule-card2 <?php echo esc_attr( $enabled ? 'mev-rule-card2--active mev-rule-active' : 'mev-rule-card2--inactive mev-rule-inactive' ); ?>"
							data-rule="<?php echo esc_attr( $rule_attr ); ?>"
							data-id="<?php echo esc_attr( (string) $rid ); ?>">
							<div class="mev-rule-card2-head">
								<label class="mev-toggle-pill" title="<?php esc_attr_e( 'Enable/disable', 'meyvora-seo' ); ?>">
									<input type="checkbox" class="mev-rule-enabled" <?php checked( $enabled ); ?> aria-label="<?php esc_attr_e( 'Enable rule', 'meyvora-seo' ); ?>">
									<span class="mev-toggle-pill-track"></span>
								</label>
								<div class="mev-rule-card2-summary">
									<span class="mev-rule-card2-if"><?php esc_html_e( 'IF', 'meyvora-seo' ); ?></span>
									<?php foreach ( $conds as $i => $c ) : ?>
										<?php if ( $i > 0 ) : ?><span class="mev-chip mev-chip--logic"><?php echo esc_html( $rule_logic ); ?></span><?php endif; ?>
										<?php
										$field_label = isset( $fields[ $c['field'] ?? '' ] ) ? $fields[ $c['field'] ] : ( $c['field'] ?? '' );
										$op_label    = isset( $operators[ $c['operator'] ?? '' ] ) ? $operators[ $c['operator'] ] : ( $c['operator'] ?? '' );
										$val         = isset( $c['value'] ) ? $c['value'] : '';
										?>
										<span class="mev-chip mev-chip--condition"><?php echo esc_html( $field_label . ' ' . $op_label ); ?><?php if ( $val !== '' ) : ?> <em><?php echo esc_html( $val ); ?></em><?php endif; ?></span>
									<?php endforeach; ?>
									<span class="mev-rule-card2-then">→ <?php esc_html_e( 'THEN', 'meyvora-seo' ); ?></span>
									<?php foreach ( $acts as $a ) : ?>
										<?php $action_key = is_array( $a ) ? ( $a['action'] ?? '' ) : $a; ?>
										<span class="mev-chip mev-chip--action"><?php echo esc_html( str_replace( 'auto_', '', $action_key ) ); ?></span>
									<?php endforeach; ?>
								</div>
								<button type="button" class="mev-rule-delete mev-btn mev-btn--danger mev-btn--sm"><?php esc_html_e( 'Delete', 'meyvora-seo' ); ?></button>
							</div>
						</div>
					<?php endforeach; ?>
					<?php if ( empty( $rules ) ) : ?>
						<p class="mev-text-muted" id="mev-no-rules"><?php esc_html_e( 'No rules yet. Add one below.', 'meyvora-seo' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<div class="mev-card">
			<div class="mev-card-header">
				<span class="mev-card-title"><?php esc_html_e( 'Add New Rule', 'meyvora-seo' ); ?></span>
			</div>
			<div class="mev-card-body">
				<div class="mev-rule-builder">
					<div class="mev-rule-builder-step">
						<div class="mev-rule-builder-step-label">
							<span class="mev-step-num">1</span> <?php esc_html_e( 'Conditions', 'meyvora-seo' ); ?>
						</div>
						<div class="mev-rule-builder-step-body">
							<div class="mev-logic-toggle-wrap">
								<span><?php esc_html_e( 'Match', 'meyvora-seo' ); ?></span>
								<div class="mev-logic-switch" role="group" aria-label="<?php esc_attr_e( 'Condition logic', 'meyvora-seo' ); ?>">
									<label class="mev-logic-btn mev-logic-btn--active">
										<input type="radio" name="mev-rule-logic" value="AND" checked />
										<span><?php esc_html_e( 'ALL (AND)', 'meyvora-seo' ); ?></span>
									</label>
									<label class="mev-logic-btn">
										<input type="radio" name="mev-rule-logic" value="OR" />
										<span><?php esc_html_e( 'ANY (OR)', 'meyvora-seo' ); ?></span>
									</label>
								</div>
								<span><?php esc_html_e( 'conditions', 'meyvora-seo' ); ?></span>
							</div>
							<div class="mev-automation-conditions" style="margin-top:12px;">
								<div id="mev-conditions-container">
									<div class="mev-condition-row">
										<select class="mev-condition-field">
											<?php foreach ( $fields as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<select class="mev-condition-operator">
											<?php foreach ( $operators as $key => $label ) : ?>
												<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
											<?php endforeach; ?>
										</select>
										<input type="text" class="mev-condition-value regular-text" placeholder="<?php esc_attr_e( 'Value', 'meyvora-seo' ); ?>" />
										<button type="button" class="mev-condition-remove mev-btn mev-btn--secondary mev-btn--sm" style="display:none;"><?php esc_html_e( 'Remove', 'meyvora-seo' ); ?></button>
									</div>
								</div>
								<button type="button" id="mev-add-condition" class="mev-btn mev-btn--secondary mev-btn--sm" style="margin-top:10px;">+ <?php esc_html_e( 'Add condition', 'meyvora-seo' ); ?></button>
							</div>
						</div>
					</div>
					<div class="mev-rule-builder-divider">→</div>
					<div class="mev-rule-builder-step">
						<div class="mev-rule-builder-step-label">
							<span class="mev-step-num">2</span> <?php esc_html_e( 'Actions', 'meyvora-seo' ); ?>
						</div>
						<div class="mev-rule-builder-step-body" id="mev-actions-container">
								<?php foreach ( $actions_list as $action_key => $action_config ) : ?>
									<label class="mev-action-card" data-action="<?php echo esc_attr( $action_key ); ?>">
										<input type="checkbox" class="mev-action-check" value="<?php echo esc_attr( $action_key ); ?>" />
										<span><?php echo esc_html( $action_config['label'] ); ?></span>
										<?php if ( ! empty( $action_config['has_value'] ) ) : ?>
											<input type="text" class="mev-action-value regular-text" placeholder="<?php echo esc_attr( $action_config['placeholder'] ?? '' ); ?>" />
										<?php endif; ?>
										<?php if ( ! empty( $action_config['has_overwrite'] ) ) : ?>
											<label class="mev-action-overwrite-wrap" style="display:block;margin-top:6px;"><input type="checkbox" class="mev-action-overwrite" /> <?php esc_html_e( 'Overwrite if already set', 'meyvora-seo' ); ?></label>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>
						</div>
					</div>
				</div>
				<button type="button" id="mev-add-rule" class="mev-btn mev-btn--primary mev-btn--full mev-mt-16">＋ <?php esc_html_e( 'Add Rule', 'meyvora-seo' ); ?></button>
			</div>
		</div>
		</div>
	</form>
</div>

<template id="mev-condition-row-tpl">
	<div class="mev-condition-row">
		<select class="mev-condition-field">
			<?php foreach ( $fields as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<select class="mev-condition-operator">
			<?php foreach ( $operators as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="text" class="mev-condition-value regular-text" placeholder="<?php esc_attr_e( 'Value', 'meyvora-seo' ); ?>" />
		<button type="button" class="mev-condition-remove mev-btn mev-btn--secondary mev-btn--sm"><?php esc_html_e( 'Remove', 'meyvora-seo' ); ?></button>
	</div>
</template>
