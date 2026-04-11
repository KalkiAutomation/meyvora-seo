<?php
/**
 * Programmatic SEO: templates, data sources (CSV / CPT), batch page generation.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Programmatic {

	const POST_TYPE_TEMPLATE   = 'meyvora_seo_template';
	const TAX_GROUP            = 'meyvora_programmatic_group';
	const META_TITLE_PATTERN   = '_meyvora_prog_title_pattern';
	const META_CONTENT_PATTERN = '_meyvora_prog_content_pattern';
	const META_META_TITLE      = '_meyvora_prog_meta_title_pattern';
	const META_META_DESC       = '_meyvora_prog_meta_description_pattern';
	const META_SLUG_PATTERN    = '_meyvora_prog_slug_pattern';
	const META_TEMPLATE_ID     = '_meyvora_programmatic_template_id';
	const META_GROUP_TERM_ID   = '_meyvora_programmatic_group_term_id';
	const META_POST_TYPE      = '_meyvora_prog_post_type';

	const TRANSIENT_QUEUE    = 'meyvora_prog_queue';
	const TRANSIENT_PROGRESS = 'meyvora_prog_progress';
	const TRANSIENT_RUN_RESULT = 'meyvora_prog_run_result';
	const TRANSIENT_CSV      = 'meyvora_prog_csv_';
	const BATCH_SIZE         = 20;
	const MAX_PER_RUN        = 500;
	const MAX_PER_TEMPLATE   = 2000;

	const AJAX_START  = 'meyvora_seo_programmatic_start';
	const AJAX_NEXT   = 'meyvora_seo_programmatic_next';
	const AJAX_CSV    = 'meyvora_seo_programmatic_upload_csv';
	const AJAX_PREVIEW = 'meyvora_seo_programmatic_preview';
	const AJAX_DELETE_GROUP = 'meyvora_seo_programmatic_delete_group';
	const NONCE_ACTION = 'meyvora_seo_programmatic';

	/**
	 * Max generated pages per template (filterable; defaults protect shared hosting).
	 *
	 * @return int
	 */
	protected function get_max_pages_per_template(): int {
		return max( 1, (int) apply_filters( 'meyvora_seo_programmatic_max_pages_per_template', self::MAX_PER_TEMPLATE ) );
	}

	/**
	 * Max rows processed per batch run (filterable).
	 *
	 * @return int
	 */
	protected function get_max_rows_per_run(): int {
		return max( 1, (int) apply_filters( 'meyvora_seo_programmatic_max_rows_per_run', self::MAX_PER_RUN ) );
	}

	/** @var Meyvora_SEO_Loader */
	protected Meyvora_SEO_Loader $loader;
	/** @var Meyvora_SEO_Options */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		$this->loader->add_action( 'init', $this, 'register_cpt_and_taxonomy' );
		$this->loader->add_action( 'admin_menu', $this, 'register_menu', 12 );
		$this->loader->add_action( 'admin_notices', $this, 'maybe_show_run_result_notice', 10, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		$this->loader->add_action( 'add_meta_boxes', $this, 'add_template_meta_boxes' );
		$this->loader->add_action( 'save_post_' . self::POST_TYPE_TEMPLATE, $this, 'save_template_meta', 10, 3 );

		add_action( 'wp_ajax_' . self::AJAX_CSV, array( $this, 'ajax_upload_csv' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::AJAX_START, array( $this, 'ajax_start' ) );
		add_action( 'wp_ajax_' . self::AJAX_NEXT, array( $this, 'ajax_next' ) );
		add_action( 'wp_ajax_' . self::AJAX_DELETE_GROUP, array( $this, 'ajax_delete_group' ) );
	}

	public function register_cpt_and_taxonomy(): void {
		register_post_type( self::POST_TYPE_TEMPLATE, array(
			'labels'              => array(
				'name'               => __( 'Programmatic Templates', 'meyvora-seo' ),
				'singular_name'      => __( 'Programmatic Template', 'meyvora-seo' ),
				'add_new'            => __( 'Add New', 'meyvora-seo' ),
				'add_new_item'       => __( 'Add New Template', 'meyvora-seo' ),
				'edit_item'          => __( 'Edit Template', 'meyvora-seo' ),
				'new_item'            => __( 'New Template', 'meyvora-seo' ),
				'view_item'          => __( 'View Template', 'meyvora-seo' ),
				'search_items'       => __( 'Search Templates', 'meyvora-seo' ),
				'not_found'          => __( 'No templates found.', 'meyvora-seo' ),
				'not_found_in_trash' => __( 'No templates found in Trash.', 'meyvora-seo' ),
			),
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,
			'show_in_menu'        => false,
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => array( 'title' ),
			'rewrite'             => false,
			'query_var'           => false,
		) );

		register_taxonomy( self::TAX_GROUP, 'post', array(
			'labels'            => array(
				'name'          => __( 'Programmatic Groups', 'meyvora-seo' ),
				'singular_name' => __( 'Programmatic Group', 'meyvora-seo' ),
			),
			'public'            => false,
			'show_ui'           => false,
			'show_in_menu'      => false,
			'hierarchical'      => true,
			'rewrite'           => false,
			'query_var'         => false,
		) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Programmatic SEO', 'meyvora-seo' ),
			__( 'Programmatic SEO', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-programmatic',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Show admin notice after generation run (X created, Y skipped).
	 */
	public function maybe_show_run_result_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'meyvora-seo_page_meyvora-seo-programmatic' ) {
			return;
		}
		$result = get_transient( self::TRANSIENT_RUN_RESULT );
		if ( ! is_array( $result ) || ( empty( $result['created'] ) && empty( $result['skipped'] ) ) ) {
			return;
		}
		delete_transient( self::TRANSIENT_RUN_RESULT );
		$created = (int) ( $result['created'] ?? 0 );
		$skipped = (int) ( $result['skipped'] ?? 0 );
		$msg = sprintf(
			/* translators: 1: number of pages created, 2: number of pages skipped */
			__( '%1$d pages created, %2$d pages skipped (slug already exists).', 'meyvora-seo' ),
			$created,
			$skipped
		);
		add_settings_error(
			'meyvora_programmatic',
			'run_result',
			$msg,
			'success'
		);
	}

	public function add_template_meta_boxes(): void {
		add_meta_box(
			'meyvora_prog_patterns',
			__( 'Template Patterns', 'meyvora-seo' ),
			array( $this, 'render_patterns_meta_box' ),
			self::POST_TYPE_TEMPLATE,
			'normal'
		);
	}

	public function render_patterns_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'meyvora_prog_save', 'meyvora_prog_nonce' );
		$title_pat       = get_post_meta( $post->ID, self::META_TITLE_PATTERN, true );
		$content_pat     = get_post_meta( $post->ID, self::META_CONTENT_PATTERN, true );
		$meta_title      = get_post_meta( $post->ID, self::META_META_TITLE, true );
		$meta_desc       = get_post_meta( $post->ID, self::META_META_DESC, true );
		$slug_pat        = get_post_meta( $post->ID, self::META_SLUG_PATTERN, true );
		$saved_post_type = get_post_meta( $post->ID, self::META_POST_TYPE, true ) ?: 'post';
		?>
		<p class="description"><?php esc_html_e( 'Use variables in curly braces, e.g. {city}, {service}, {year}.', 'meyvora-seo' ); ?></p>
		<p>
			<label for="meyvora_prog_title_pattern"><strong><?php esc_html_e( 'Title pattern', 'meyvora-seo' ); ?></strong></label><br>
			<input type="text" name="meyvora_prog_title_pattern" id="meyvora_prog_title_pattern" value="<?php echo esc_attr( $title_pat ); ?>" class="large-text" placeholder="e.g. Best {service} in {city}">
		</p>
		<p>
			<label for="meyvora_prog_slug_pattern"><strong><?php esc_html_e( 'Slug pattern (optional)', 'meyvora-seo' ); ?></strong></label><br>
			<input type="text" name="meyvora_prog_slug_pattern" id="meyvora_prog_slug_pattern" value="<?php echo esc_attr( $slug_pat ); ?>" class="large-text" placeholder="e.g. {service}-in-{city}">
		</p>
		<p>
			<label for="meyvora_prog_post_type"><strong><?php esc_html_e( 'Target post type', 'meyvora-seo' ); ?></strong></label><br>
			<select name="meyvora_prog_post_type" id="meyvora_prog_post_type">
			<?php foreach ( self::get_public_post_types() as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $saved_post_type, $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="meyvora_prog_content_pattern"><strong><?php esc_html_e( 'Content pattern', 'meyvora-seo' ); ?></strong></label><br>
			<textarea name="meyvora_prog_content_pattern" id="meyvora_prog_content_pattern" rows="8" class="large-text"><?php echo esc_textarea( $content_pat ); ?></textarea>
		</p>
		<p>
			<label for="meyvora_prog_meta_title"><strong><?php esc_html_e( 'Meta title pattern', 'meyvora-seo' ); ?></strong></label><br>
			<input type="text" name="meyvora_prog_meta_title" id="meyvora_prog_meta_title" value="<?php echo esc_attr( $meta_title ); ?>" class="large-text">
		</p>
		<p>
			<label for="meyvora_prog_meta_desc"><strong><?php esc_html_e( 'Meta description pattern', 'meyvora-seo' ); ?></strong></label><br>
			<textarea name="meyvora_prog_meta_desc" id="meyvora_prog_meta_desc" rows="2" class="large-text"><?php echo esc_textarea( $meta_desc ); ?></textarea>
		</p>
		<?php
	}

	public function save_template_meta( int $post_id, WP_Post $post, bool $update ): void {
		if ( ! isset( $_POST['meyvora_prog_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meyvora_prog_nonce'] ) ), 'meyvora_prog_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$keys = array(
			'meyvora_prog_title_pattern'   => self::META_TITLE_PATTERN,
			'meyvora_prog_content_pattern' => self::META_CONTENT_PATTERN,
			'meyvora_prog_meta_title'      => self::META_META_TITLE,
			'meyvora_prog_meta_desc'       => self::META_META_DESC,
			'meyvora_prog_slug_pattern'    => self::META_SLUG_PATTERN,
		);
		foreach ( $keys as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized in next line.
				$raw = wp_unslash( $_POST[ $post_key ] );
				$val = is_string( $raw ) ? sanitize_text_field( $raw ) : '';
				update_post_meta( $post_id, $meta_key, $val );
			}
		}
		$post_type_val = isset( $_POST['meyvora_prog_post_type'] ) ? sanitize_key( wp_unslash( $_POST['meyvora_prog_post_type'] ) ) : 'post';
		$public_types  = array_keys( self::get_public_post_types() );
		if ( ! in_array( $post_type_val, $public_types, true ) ) {
			$post_type_val = 'post';
		}
		update_post_meta( $post_id, self::META_POST_TYPE, $post_type_val );
	}

	public function enqueue_assets( string $hook ): void {
		if ( $hook !== 'meyvora-seo_page_meyvora-seo-programmatic' ) {
			return;
		}
		$css_path = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-admin.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style( 'meyvora-seo-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		}
		$script_path = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-programmatic.js';
		if ( ! file_exists( $script_path ) ) {
			return;
		}
		wp_enqueue_script(
			'meyvora-programmatic',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-programmatic.js',
			array( 'jquery' ),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script( 'meyvora-programmatic', 'meyvoraProgrammatic', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( self::NONCE_ACTION ),
			'i18n'      => array(
				'preview'       => __( 'Preview', 'meyvora-seo' ),
				'generate'      => __( 'Generate Pages', 'meyvora-seo' ),
				'generating'    => __( 'Generating…', 'meyvora-seo' ),
				'done'          => __( 'Done', 'meyvora-seo' ),
				'error'         => __( 'Error', 'meyvora-seo' ),
				'deleteGroup'   => __( 'Delete all pages in group', 'meyvora-seo' ),
				/* translators: %d: number of pages */
				'confirmDelete' => __( 'Permanently delete all %d pages in this group?', 'meyvora-seo' ),
				'uploadCsv'    => __( 'Upload CSV', 'meyvora-seo' ),
				'parsing'      => __( 'Parsing…', 'meyvora-seo' ),
				/* translators: %d: number of rows */
				'rowsFound'    => __( '%d rows found', 'meyvora-seo' ),
				'dataSourceCsv'  => __( 'CSV upload', 'meyvora-seo' ),
				'dataSourceCpt'  => __( 'Custom Post Type', 'meyvora-seo' ),
				'selectTemplate' => __( 'Select a template', 'meyvora-seo' ),
				'selectCpt'      => __( 'Select post type', 'meyvora-seo' ),
				'mapping'        => __( 'Variable → Field mapping', 'meyvora-seo' ),
				'first3'         => __( 'First 3 pages (preview)', 'meyvora-seo' ),
				'max500'         => __( 'Max 500 pages per run.', 'meyvora-seo' ),
				'max2000'        => __( 'Max 2000 pages per template.', 'meyvora-seo' ),
			),
		) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$templates = get_posts( array(
			'post_type'      => self::POST_TYPE_TEMPLATE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$post_types = self::get_public_post_types();
		$groups = get_terms( array( 'taxonomy' => self::TAX_GROUP, 'hide_empty' => false ) );
		if ( ! is_array( $groups ) ) {
			$groups = array();
		}
		include MEYVORA_SEO_PATH . 'admin/views/programmatic.php';
	}

	/**
	 * Replace {var} in string with row values.
	 *
	 * @param string $pattern Pattern string.
	 * @param array<string, string> $row Row of variable => value.
	 * @return string
	 */
	public static function replace_vars( string $pattern, array $row ): string {
		$out = $pattern;
		foreach ( $row as $key => $value ) {
			$out = str_replace( '{' . $key . '}', (string) $value, $out );
		}
		return preg_replace( '/\{[a-zA-Z0-9_]+\}/', '', $out );
	}

	/**
	 * Get data rows from CSV transient or from CPT.
	 *
	 * @param array $config { type: 'csv'|'cpt', csv_key?: string, cpt?: string, mapping?: array }
	 * @return array<int, array<string, string>>
	 */
	public function get_data_rows( array $config ): array {
		$type = isset( $config['type'] ) ? $config['type'] : '';
		if ( $type === 'csv' && ! empty( $config['csv_key'] ) ) {
			$raw = get_transient( self::TRANSIENT_CSV . $config['csv_key'] );
			if ( ! is_array( $raw ) ) {
				return array();
			}
			return $raw;
		}
		if ( $type === 'cpt' && ! empty( $config['cpt'] ) && ! empty( $config['mapping'] ) ) {
			return $this->get_rows_from_cpt( $config['cpt'], $config['mapping'] );
		}
		return array();
	}

	/**
	 * @param string $post_type Post type.
	 * @param array<string, string> $mapping Variable name => 'post_title'|'post_content'|'meta:key'
	 * @return array<int, array<string, string>>
	 */
	protected function get_rows_from_cpt( string $post_type, array $mapping ): array {
		$posts = get_posts( array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$rows = array();
		foreach ( $posts as $post ) {
			$row = array();
			foreach ( $mapping as $var_name => $field_spec ) {
				$val = '';
				if ( $field_spec === 'post_title' ) {
					$val = $post->post_title;
				} elseif ( $field_spec === 'post_content' ) {
					$val = wp_strip_all_tags( $post->post_content );
				} elseif ( strpos( $field_spec, 'meta:' ) === 0 ) {
					$meta_key = substr( $field_spec, 5 );
					$val = get_post_meta( $post->ID, $meta_key, true );
				} else {
					$val = isset( $post->$field_spec ) ? (string) $post->$field_spec : '';
				}
				$row[ $var_name ] = is_string( $val ) ? $val : (string) $val;
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Count existing generated pages for a template.
	 *
	 * @param int    $template_id Template post ID.
	 * @param string $post_type   Post type of generated pages (default 'post').
	 * @return int
	 */
	public function count_pages_for_template( int $template_id, string $post_type = 'post' ): int {
		global $wpdb;
		$key = self::META_TEMPLATE_ID;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core tables, prepare used.
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s AND p.post_status != 'trash'",
			$key,
			(string) $template_id,
			$post_type
		) );
	}

	public function ajax_upload_csv(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by is_uploaded_file, read via file_get_contents.
		$file = isset( $_FILES['file'] ) ? $_FILES['file'] : null;
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file uploaded.', 'meyvora-seo' ) ) );
		}
		$content = file_get_contents( $file['tmp_name'] );
		if ( $content === false ) {
			wp_send_json_error( array( 'message' => __( 'Could not read file.', 'meyvora-seo' ) ) );
		}
		$rows = $this->parse_csv( $content );
		$key = wp_generate_password( 16, false );
		set_transient( self::TRANSIENT_CSV . $key, $rows, 3600 );
		wp_send_json_success( array( 'csv_key' => $key, 'count' => count( $rows ) ) );
	}

	/**
	 * Parse CSV string to array of associative arrays (first row = headers).
	 *
	 * @param string $content CSV content.
	 * @return array<int, array<string, string>>
	 */
	protected function parse_csv( string $content ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $content );
		if ( empty( $lines ) ) {
			return array();
		}
		$headers = str_getcsv( array_shift( $lines ) );
		$headers = array_map( 'trim', $headers );
		$rows = array();
		foreach ( $lines as $line ) {
			if ( trim( $line ) === '' ) {
				continue;
			}
			$cells = str_getcsv( $line );
			$row = array();
			foreach ( $headers as $i => $h ) {
				$row[ $h ] = isset( $cells[ $i ] ) ? trim( $cells[ $i ] ) : '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	public function ajax_preview(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- JSON decoded, not output directly.
		$config = isset( $_POST['data_source'] ) ? json_decode( (string) wp_unslash( $_POST['data_source'] ), true ) : array();
		if ( ! $template_id || ! is_array( $config ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'meyvora-seo' ) ) );
		}
		$rows = $this->get_data_rows( $config );
		$rows = array_slice( $rows, 0, 3 );
		$preview = $this->build_preview_for_rows( $template_id, $rows );
		wp_send_json_success( array( 'preview' => $preview ) );
	}

	/**
	 * Build preview array (title, slug, meta_title, meta_desc) for given rows.
	 *
	 * @param int $template_id Template post ID.
	 * @param array<int, array<string, string>> $rows Rows.
	 * @return array<int, array{title: string, slug: string, meta_title: string, meta_desc: string}>
	 */
	protected function build_preview_for_rows( int $template_id, array $rows ): array {
		$title_pat   = get_post_meta( $template_id, self::META_TITLE_PATTERN, true );
		$slug_pat    = get_post_meta( $template_id, self::META_SLUG_PATTERN, true );
		$meta_title  = get_post_meta( $template_id, self::META_META_TITLE, true );
		$meta_desc   = get_post_meta( $template_id, self::META_META_DESC, true );
		$out = array();
		foreach ( $rows as $row ) {
			$title = self::replace_vars( (string) $title_pat, $row );
			$slug  = $slug_pat !== '' ? sanitize_title( self::replace_vars( (string) $slug_pat, $row ) ) : sanitize_title( $title );
			$out[] = array(
				'title'      => $title,
				'slug'       => $slug,
				'meta_title' => self::replace_vars( (string) $meta_title, $row ),
				'meta_desc'  => self::replace_vars( (string) $meta_desc, $row ),
			);
		}
		return $out;
	}

	public function ajax_start(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- JSON decoded, not output directly.
		$config = isset( $_POST['data_source'] ) ? json_decode( (string) wp_unslash( $_POST['data_source'] ), true ) : array();
		if ( ! $template_id || ! is_array( $config ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'meyvora-seo' ) ) );
		}

		$saved_type = get_post_meta( $template_id, self::META_POST_TYPE, true );
		$post_type  = ( is_string( $saved_type ) && $saved_type !== '' ) ? $saved_type : 'post';
		$max_template = $this->get_max_pages_per_template();
		$max_run      = $this->get_max_rows_per_run();
		$existing     = $this->count_pages_for_template( $template_id, $post_type );
		if ( $existing >= $max_template ) {
			/* translators: 1: current page count, 2: max pages per template */
			wp_send_json_error( array( 'message' => sprintf( __( 'This template already has %1$d pages (max %2$d).', 'meyvora-seo' ), $existing, $max_template ) ) );
		}

		$rows = $this->get_data_rows( $config );
		$allowed = $max_run;
		$remaining_cap = $max_template - $existing;
		$allowed = min( $allowed, $remaining_cap );
		$rows = array_slice( $rows, 0, $allowed );

		if ( empty( $rows ) ) {
			wp_send_json_error( array( 'message' => __( 'No data rows to generate.', 'meyvora-seo' ) ) );
		}

		set_transient( self::TRANSIENT_QUEUE, array( 'template_id' => $template_id, 'rows' => $rows ), 3600 );
		delete_transient( self::TRANSIENT_PROGRESS );

		$preview = $this->build_preview_for_rows( $template_id, array_slice( $rows, 0, 3 ) );
		wp_send_json_success( array( 'total' => count( $rows ), 'preview' => $preview ) );
	}

	public function ajax_next(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$queue = get_transient( self::TRANSIENT_QUEUE );
		if ( ! is_array( $queue ) || empty( $queue['rows'] ) || empty( $queue['template_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No queue. Start generation again.', 'meyvora-seo' ) ) );
		}

		$template_id = (int) $queue['template_id'];
		$rows = $queue['rows'];
		$batch = array_slice( $rows, 0, self::BATCH_SIZE );
		$remaining = array_slice( $rows, self::BATCH_SIZE );
		$progress = get_transient( self::TRANSIENT_PROGRESS );
		if ( ! is_array( $progress ) ) {
			$progress = array( 'processed' => 0, 'created' => 0, 'skipped' => 0, 'group_term_id' => null );
		}

		$group_term_id = $progress['group_term_id'];
		if ( $group_term_id === null ) {
			$template_post = get_post( $template_id );
			$name = $template_post ? $template_post->post_title : 'Programmatic';
			$name = $name . ' - ' . wp_date( 'Y-m-d H:i' );
			$term = wp_insert_term( $name, self::TAX_GROUP );
			if ( is_wp_error( $term ) ) {
				wp_send_json_error( array( 'message' => $term->get_error_message() ) );
			}
			$group_term_id = $term['term_id'];
			$progress['group_term_id'] = $group_term_id;
		}

		$author_id = get_current_user_id();
		$batch_created = 0;
		$batch_skipped = 0;
		foreach ( $batch as $row ) {
			$result = $this->create_page_from_row( $template_id, $row, $group_term_id, $author_id );
			if ( is_array( $result ) && ! empty( $result['skipped'] ) ) {
				$progress['skipped'] = ( $progress['skipped'] ?? 0 ) + 1;
				$batch_skipped++;
			} else {
				$progress['created'] = ( $progress['created'] ?? 0 ) + 1;
				$batch_created++;
			}
			$progress['processed']++;
		}

		set_transient( self::TRANSIENT_PROGRESS, $progress, 3600 );
		if ( empty( $remaining ) ) {
			delete_transient( self::TRANSIENT_QUEUE );
			delete_transient( self::TRANSIENT_PROGRESS );
			set_transient( self::TRANSIENT_RUN_RESULT, array(
				'created' => (int) ( $progress['created'] ?? 0 ),
				'skipped' => (int) ( $progress['skipped'] ?? 0 ),
			), 60 );
			wp_send_json_success( array(
				'done'      => true,
				'processed' => $progress['processed'],
				'created'   => (int) ( $progress['created'] ?? 0 ),
				'skipped'   => (int) ( $progress['skipped'] ?? 0 ),
				'total'     => $progress['processed'],
			) );
		}
		set_transient( self::TRANSIENT_QUEUE, array( 'template_id' => $template_id, 'rows' => $remaining ), 3600 );
		wp_send_json_success( array(
			'done'      => false,
			'processed' => $progress['processed'],
			'created'   => (int) ( $progress['created'] ?? 0 ),
			'skipped'   => (int) ( $progress['skipped'] ?? 0 ),
			'total'     => count( $rows ),
		) );
	}

	/**
	 * Create one post from a data row.
	 *
	 * @param int $template_id Template post ID.
	 * @param array<string, string> $row Data row.
	 * @param int $group_term_id Term ID for meyvora_programmatic_group.
	 * @param int $author_id Post author.
	 * @return int|array{skipped: bool, reason: string} Post ID, 0 on failure, or array when skipped (slug exists).
	 */
	protected function create_page_from_row( int $template_id, array $row, int $group_term_id, int $author_id ) {
		$title_pat   = get_post_meta( $template_id, self::META_TITLE_PATTERN, true );
		$content_pat = get_post_meta( $template_id, self::META_CONTENT_PATTERN, true );
		$slug_pat    = get_post_meta( $template_id, self::META_SLUG_PATTERN, true );
		$meta_title  = get_post_meta( $template_id, self::META_META_TITLE, true );
		$meta_desc   = get_post_meta( $template_id, self::META_META_DESC, true );

		$title   = self::replace_vars( (string) $title_pat, $row );
		$content = self::replace_vars( (string) $content_pat, $row );
		$intended_slug = $slug_pat !== '' ? sanitize_title( self::replace_vars( (string) $slug_pat, $row ) ) : sanitize_title( $title );
		$saved_type = get_post_meta( $template_id, self::META_POST_TYPE, true );
		$post_type = ( is_string( $saved_type ) && $saved_type !== '' ) ? $saved_type : 'post';
		$existing = get_page_by_path( $intended_slug, OBJECT, $post_type );
		if ( $existing && $existing->post_status === 'publish' ) {
			return array( 'skipped' => true, 'reason' => 'slug_exists' );
		}

		$slug = wp_unique_post_slug( $intended_slug, 0, 'publish', $post_type, 0 );

		$post_data = array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'post_status'  => 'publish',
			'post_type'    => $post_type,
			'post_author'  => $author_id,
		);
		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}

		update_post_meta( $post_id, self::META_TEMPLATE_ID, $template_id );
		update_post_meta( $post_id, self::META_GROUP_TERM_ID, $group_term_id );
		wp_set_object_terms( $post_id, array( (int) $group_term_id ), self::TAX_GROUP );

		$seo_title = self::replace_vars( (string) $meta_title, $row );
		$seo_desc  = self::replace_vars( (string) $meta_desc, $row );
		update_post_meta( $post_id, MEYVORA_SEO_META_TITLE, $seo_title );
		update_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, $seo_desc );
		$permalink = get_permalink( $post_id );
		if ( $permalink ) {
			update_post_meta( $post_id, MEYVORA_SEO_META_CANONICAL, $permalink );
		}

		return (int) $post_id;
	}

	public function ajax_delete_group(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		if ( ! $term_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid group.', 'meyvora-seo' ) ) );
		}
		$term = get_term( $term_id, self::TAX_GROUP );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => __( 'Group not found.', 'meyvora-seo' ) ) );
		}
		$posts = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'tax_query'      => array( array( 'taxonomy' => self::TAX_GROUP, 'field' => 'term_id', 'terms' => $term_id ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Single term_id for programmatic group delete.
		) );
		foreach ( $posts as $pid ) {
			wp_delete_post( $pid, true );
		}
		wp_delete_term( $term_id, self::TAX_GROUP );
		wp_send_json_success( array( 'deleted' => count( $posts ) ) );
	}

	/**
	 * Get list of public post types for CPT data source.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function get_public_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		$out = array();
		foreach ( $types as $slug => $obj ) {
			$out[ $slug ] = $obj->labels->singular_name ?? $slug;
		}
		return $out;
	}
}
