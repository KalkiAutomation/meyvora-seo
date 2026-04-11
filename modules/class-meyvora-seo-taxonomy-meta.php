<?php
/**
 * Taxonomy SEO: title, description, OG image editing for all standard WP taxonomies
 * and custom taxonomies (category, post_tag, and any public custom taxonomy).
 * WooCommerce product_cat / product_tag are handled by the WooCommerce integration.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Taxonomy_Meta {

	const TERM_META_TITLE       = 'meyvora_seo_term_title';
	const TERM_META_DESCRIPTION = 'meyvora_seo_term_description';
	const TERM_META_OG_IMAGE    = 'meyvora_seo_term_og_image';
	const NONCE_ACTION          = 'meyvora_seo_term_meta';
	const NONCE_NAME            = 'meyvora_seo_term_nonce';

	/** @var Meyvora_SEO_Loader */
	protected Meyvora_SEO_Loader $loader;

	/** @var Meyvora_SEO_Options */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		// Register fields for standard taxonomies. WC taxonomies handled by WC integration.
		$this->loader->add_action( 'init', $this, 'register_taxonomy_hooks', 20, 0 );
		// Frontend: override title/description/OG for taxonomy archives.
		if ( ! is_admin() ) {
			$this->loader->add_filter( 'document_title_parts', $this, 'filter_term_document_title', 12, 1 );
			$this->loader->add_filter( 'meyvora_seo_meta_description_override', $this, 'filter_term_meta_description', 15, 1 );
			$this->loader->add_filter( 'meyvora_seo_og_title', $this, 'filter_term_og_title', 15, 1 );
			$this->loader->add_filter( 'meyvora_seo_og_description', $this, 'filter_term_og_description', 15, 1 );
			$this->loader->add_filter( 'meyvora_seo_og_image', $this, 'filter_term_og_image', 15, 1 );
		}
	}

	/**
	 * Register add/edit form hooks for all public taxonomies except WC ones.
	 */
	public function register_taxonomy_hooks(): void {
		$wc_taxonomies = array( 'product_cat', 'product_tag' );
		$taxonomies    = get_taxonomies( array( 'public' => true ), 'names' );
		foreach ( $taxonomies as $taxonomy ) {
			if ( in_array( $taxonomy, $wc_taxonomies, true ) ) {
				continue;
			}
			add_action( "{$taxonomy}_add_form_fields",  array( $this, 'render_add_form_fields' ), 20, 1 );
			add_action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_form_fields' ), 20, 1 );
			add_action( "created_{$taxonomy}",          array( $this, 'save_term_meta' ), 10, 1 );
			add_action( "edited_{$taxonomy}",           array( $this, 'save_term_meta' ), 10, 1 );
		}
		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media' ) );
		}
	}

	public function enqueue_media(): void {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->base, array( 'edit-tags', 'term' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Render SEO fields on Add Term form.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public function render_add_form_fields( string $taxonomy ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="form-field meyvora-term-seo-wrap">
			<h3 style="margin-bottom:10px;"><?php esc_html_e( 'SEO Settings', 'meyvora-seo' ); ?></h3>
			<div class="form-field">
				<label for="meyvora_seo_term_title"><?php esc_html_e( 'SEO Title', 'meyvora-seo' ); ?></label>
				<input type="text" name="meyvora_seo_term_title" id="meyvora_seo_term_title" value="" maxlength="200" />
				<p class="description"><?php esc_html_e( 'Custom title for search results. Leave empty to use the taxonomy name.', 'meyvora-seo' ); ?></p>
			</div>
			<div class="form-field">
				<label for="meyvora_seo_term_description"><?php esc_html_e( 'Meta Description', 'meyvora-seo' ); ?></label>
				<textarea name="meyvora_seo_term_description" id="meyvora_seo_term_description" rows="3" maxlength="320"></textarea>
				<p class="description"><?php esc_html_e( 'Custom description for search results. 120–160 characters recommended.', 'meyvora-seo' ); ?></p>
			</div>
			<div class="form-field">
				<label><?php esc_html_e( 'OG Image', 'meyvora-seo' ); ?></label>
				<input type="hidden" name="meyvora_seo_term_og_image" id="meyvora_seo_term_og_image" value="" />
				<div class="meyvora-seo-og-image-preview" style="margin:6px 0;"></div>
				<button type="button" class="button meyvora-term-og-pick"><?php esc_html_e( 'Choose image', 'meyvora-seo' ); ?></button>
				<button type="button" class="button meyvora-term-og-remove" style="display:none;"><?php esc_html_e( 'Remove', 'meyvora-seo' ); ?></button>
				<p class="description"><?php esc_html_e( 'Image used when this archive is shared on social media.', 'meyvora-seo' ); ?></p>
			</div>
		</div>
		<?php $this->inline_js(); ?>
		<?php
	}

	/**
	 * Render SEO fields on Edit Term form.
	 *
	 * @param WP_Term $term Term object.
	 */
	public function render_edit_form_fields( WP_Term $term ): void {
		$title        = (string) get_term_meta( $term->term_id, self::TERM_META_TITLE, true );
		$description  = (string) get_term_meta( $term->term_id, self::TERM_META_DESCRIPTION, true );
		$og_image_id  = (int) get_term_meta( $term->term_id, self::TERM_META_OG_IMAGE, true );
		$og_image_url = $og_image_id > 0 ? wp_get_attachment_image_url( $og_image_id, 'thumbnail' ) : '';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<tr class="form-field meyvora-term-seo-wrap">
			<th colspan="2"><h3 style="margin-bottom:0;"><?php esc_html_e( 'SEO Settings', 'meyvora-seo' ); ?></h3></th>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="meyvora_seo_term_title"><?php esc_html_e( 'SEO Title', 'meyvora-seo' ); ?></label></th>
			<td>
				<input type="text" name="meyvora_seo_term_title" id="meyvora_seo_term_title" value="<?php echo esc_attr( $title ); ?>" maxlength="200" style="width:100%;" />
				<p class="description"><?php esc_html_e( 'Leave empty to use the term name. 30–60 characters recommended.', 'meyvora-seo' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="meyvora_seo_term_description"><?php esc_html_e( 'Meta Description', 'meyvora-seo' ); ?></label></th>
			<td>
				<textarea name="meyvora_seo_term_description" id="meyvora_seo_term_description" rows="3" style="width:100%;" maxlength="320"><?php echo esc_textarea( $description ); ?></textarea>
				<p class="description"><?php esc_html_e( '120–160 characters recommended.', 'meyvora-seo' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><?php esc_html_e( 'OG Image', 'meyvora-seo' ); ?></th>
			<td>
				<input type="hidden" name="meyvora_seo_term_og_image" id="meyvora_seo_term_og_image" value="<?php echo esc_attr( $og_image_id > 0 ? $og_image_id : '' ); ?>" />
				<div class="meyvora-seo-og-image-preview" style="margin:6px 0;">
					<?php if ( $og_image_url ) : ?><img src="<?php echo esc_url( $og_image_url ); ?>" style="max-width:150px;display:block;" /><?php endif; ?>
				</div>
				<button type="button" class="button meyvora-term-og-pick"><?php esc_html_e( 'Choose image', 'meyvora-seo' ); ?></button>
				<button type="button" class="button meyvora-term-og-remove" <?php echo $og_image_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'meyvora-seo' ); ?></button>
			</td>
		</tr>
		<?php $this->inline_js(); ?>
		<?php
	}

	/**
	 * Save term SEO meta on create/update.
	 *
	 * @param int $term_id Term ID.
	 */
	public function save_term_meta( int $term_id ): void {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$title = isset( $_POST['meyvora_seo_term_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_term_title'] ) ) : '';
		update_term_meta( $term_id, self::TERM_META_TITLE, $title );
		$desc = isset( $_POST['meyvora_seo_term_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_seo_term_description'] ) ) : '';
		update_term_meta( $term_id, self::TERM_META_DESCRIPTION, $desc );
		$og_image = isset( $_POST['meyvora_seo_term_og_image'] ) ? absint( $_POST['meyvora_seo_term_og_image'] ) : 0;
		update_term_meta( $term_id, self::TERM_META_OG_IMAGE, $og_image );
	}

	// --- Frontend filter methods ---

	/**
	 * Override document title for taxonomy archives.
	 *
	 * @param array $title_parts Title parts.
	 * @return array
	 */
	public function filter_term_document_title( array $title_parts ): array {
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return $title_parts;
		}
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id ) ) {
			return $title_parts;
		}
		// Skip WC taxonomies — handled by WC integration.
		if ( isset( $term->taxonomy ) && in_array( $term->taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			return $title_parts;
		}
		$custom = (string) get_term_meta( $term->term_id, self::TERM_META_TITLE, true );
		if ( $custom !== '' ) {
			$title_parts['title'] = $custom;
		}
		return $title_parts;
	}

	/**
	 * Override meta description for taxonomy archives.
	 *
	 * @param string $desc Current description.
	 * @return string
	 */
	public function filter_term_meta_description( string $desc ): string {
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return $desc;
		}
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id ) ) {
			return $desc;
		}
		if ( isset( $term->taxonomy ) && in_array( $term->taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			return $desc;
		}
		$custom = (string) get_term_meta( $term->term_id, self::TERM_META_DESCRIPTION, true );
		if ( $custom !== '' ) {
			return $custom;
		}
		// Fallback: use term description if set.
		if ( ! empty( $term->description ) ) {
			return wp_trim_words( wp_strip_all_tags( $term->description ), 30 );
		}
		return $desc;
	}

	public function filter_term_og_title( string $title ): string {
		if ( $title !== '' ) {
			return $title;
		}
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return $title;
		}
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id ) ) {
			return $title;
		}
		$custom = (string) get_term_meta( $term->term_id, self::TERM_META_TITLE, true );
		return $custom !== '' ? $custom : ( $term->name ?? '' );
	}

	public function filter_term_og_description( string $desc ): string {
		if ( $desc !== '' ) {
			return $desc;
		}
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return $desc;
		}
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id ) ) {
			return $desc;
		}
		$custom = (string) get_term_meta( $term->term_id, self::TERM_META_DESCRIPTION, true );
		if ( $custom !== '' ) {
			return $custom;
		}
		if ( ! empty( $term->description ) ) {
			return wp_trim_words( wp_strip_all_tags( $term->description ), 30 );
		}
		return $desc;
	}

	public function filter_term_og_image( string $image ): string {
		if ( $image !== '' ) {
			return $image;
		}
		if ( ! ( is_category() || is_tag() || is_tax() ) ) {
			return $image;
		}
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id ) ) {
			return $image;
		}
		$img_id = (int) get_term_meta( $term->term_id, self::TERM_META_OG_IMAGE, true );
		if ( $img_id > 0 ) {
			$url = wp_get_attachment_image_url( $img_id, 'full' );
			if ( $url ) {
				return esc_url_raw( $url );
			}
		}
		return $image;
	}

	/** Inline JS for media picker (shared by add/edit forms). Only output once. */
	private function inline_js(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		(function(){
			document.querySelectorAll('.meyvora-term-og-pick').forEach(function(btn){
				btn.addEventListener('click', function(){
					var wrap = btn.closest('.form-field, tr');
					var hiddenInput = document.getElementById('meyvora_seo_term_og_image');
					var preview = wrap ? wrap.querySelector('.meyvora-seo-og-image-preview') : null;
					var removeBtn = wrap ? wrap.querySelector('.meyvora-term-og-remove') : null;
					var frame = wp.media({ title: '<?php echo esc_js( __( 'Choose OG Image', 'meyvora-seo' ) ); ?>', multiple: false, library: { type: 'image' } });
					frame.on('select', function(){
						var att = frame.state().get('selection').first().toJSON();
						if (hiddenInput) hiddenInput.value = att.id;
						if (preview) { var src = (att.sizes && att.sizes.thumbnail) ? att.sizes.thumbnail.url : att.url; preview.innerHTML = '<img src="'+src+'" style="max-width:150px;display:block;" />'; }
						if (removeBtn) removeBtn.style.display = '';
					});
					frame.open();
				});
			});
			document.querySelectorAll('.meyvora-term-og-remove').forEach(function(btn){
				btn.addEventListener('click', function(){
					var hiddenInput = document.getElementById('meyvora_seo_term_og_image');
					var wrap = btn.closest('.form-field, tr');
					var preview = wrap ? wrap.querySelector('.meyvora-seo-og-image-preview') : null;
					if (hiddenInput) hiddenInput.value = '';
					if (preview) preview.innerHTML = '';
					btn.style.display = 'none';
				});
			});
		})();
		</script>
		<?php
	}
}
