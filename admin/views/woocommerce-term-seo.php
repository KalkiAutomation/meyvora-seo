<?php
/**
 * Meyvora SEO fields for WooCommerce Product Category (add/edit form).
 *
 * Variables: $title, $description, $og_image (optional $term for edit form).
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- View template.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit = isset( $term ) && $term instanceof WP_Term;
$title = isset( $title ) ? $title : '';
$description = isset( $description ) ? $description : '';
$og_image = isset( $og_image ) ? $og_image : '';
$img_id = is_numeric( $og_image ) ? (int) $og_image : 0;
$img_url = $img_id > 0 ? wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';

if ( $is_edit ) {
	?>
	<tr class="form-field">
		<th scope="row"><label for="meyvora_seo_term_title"><?php esc_html_e( 'SEO Title', 'meyvora-seo' ); ?></label></th>
		<td>
			<?php wp_nonce_field( 'meyvora_seo_term_seo', 'meyvora_seo_term_nonce' ); ?>
			<input type="text" name="meyvora_seo_term_title" id="meyvora_seo_term_title" value="<?php echo esc_attr( $title ); ?>" class="large-text" />
			<p class="description"><?php esc_html_e( 'Custom title for search results and Open Graph.', 'meyvora-seo' ); ?></p>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label for="meyvora_seo_term_description"><?php esc_html_e( 'Meta Description', 'meyvora-seo' ); ?></label></th>
		<td>
			<textarea name="meyvora_seo_term_description" id="meyvora_seo_term_description" rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
			<p class="description"><?php esc_html_e( 'Short description for search results and Open Graph (recommended 150–160 characters).', 'meyvora-seo' ); ?></p>
		</td>
	</tr>
	<tr class="form-field">
		<th scope="row"><label><?php esc_html_e( 'OG Image', 'meyvora-seo' ); ?></label></th>
		<td>
			<input type="hidden" name="meyvora_seo_term_og_image" value="<?php echo esc_attr( $img_id ); ?>" />
			<button type="button" class="button meyvora-seo-og-image-select"><?php esc_html_e( 'Select image', 'meyvora-seo' ); ?></button>
			<button type="button" class="button meyvora-seo-og-image-remove"><?php esc_html_e( 'Remove', 'meyvora-seo' ); ?></button>
			<span class="meyvora-seo-og-image-preview"><?php if ( $img_url ) { ?><img src="<?php echo esc_url( $img_url ); ?>" style="max-width:150px; display:block; margin-top:8px;" /><?php } ?></span>
			<p class="description"><?php esc_html_e( 'Image for Open Graph (category page share).', 'meyvora-seo' ); ?></p>
		</td>
	</tr>
	<?php
} else {
	?>
	<div class="form-field">
		<?php wp_nonce_field( 'meyvora_seo_term_seo', 'meyvora_seo_term_nonce' ); ?>
		<label for="meyvora_seo_term_title"><?php esc_html_e( 'SEO Title', 'meyvora-seo' ); ?></label>
		<input type="text" name="meyvora_seo_term_title" id="meyvora_seo_term_title" value="<?php echo esc_attr( $title ); ?>" />
		<p class="description"><?php esc_html_e( 'Custom title for search results and Open Graph.', 'meyvora-seo' ); ?></p>
	</div>
	<div class="form-field">
		<label for="meyvora_seo_term_description"><?php esc_html_e( 'Meta Description', 'meyvora-seo' ); ?></label>
		<textarea name="meyvora_seo_term_description" id="meyvora_seo_term_description" rows="3"><?php echo esc_textarea( $description ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Short description for search results and Open Graph (recommended 150–160 characters).', 'meyvora-seo' ); ?></p>
	</div>
	<div class="form-field">
		<label><?php esc_html_e( 'OG Image', 'meyvora-seo' ); ?></label>
		<input type="hidden" name="meyvora_seo_term_og_image" value="<?php echo esc_attr( $img_id ); ?>" />
		<button type="button" class="button meyvora-seo-og-image-select"><?php esc_html_e( 'Select image', 'meyvora-seo' ); ?></button>
		<button type="button" class="button meyvora-seo-og-image-remove"><?php esc_html_e( 'Remove', 'meyvora-seo' ); ?></button>
		<span class="meyvora-seo-og-image-preview"><?php if ( $img_url ) { ?><img src="<?php echo esc_url( $img_url ); ?>" style="max-width:150px; display:block; margin-top:8px;" /><?php } ?></span>
		<p class="description"><?php esc_html_e( 'Image for Open Graph (category page share).', 'meyvora-seo' ); ?></p>
	</div>
	<?php
}
