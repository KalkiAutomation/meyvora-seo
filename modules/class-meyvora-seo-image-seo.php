<?php
/**
 * Image SEO: bulk alt editor, auto alt on upload, filename sanitizer, audit integration.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_query, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.NonceVerification.Recommended -- Bulk counts; AJAX nonce in handler.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Image_SEO
 */
class Meyvora_SEO_Image_SEO {

	const PAGE_SLUG    = 'meyvora-seo-image-seo';
	const PER_PAGE     = 50;
	const AJAX_SAVE    = 'meyvora_seo_image_save_alt';
	const NONCE_ACTION = 'meyvora_seo_image_seo';

	/** @var Meyvora_SEO_Loader */
	protected Meyvora_SEO_Loader $loader;

	/** @var Meyvora_SEO_Options */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		$this->loader->add_action( 'admin_menu', $this, 'register_menu', 14, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_' . self::AJAX_SAVE, array( $this, 'ajax_save_alt' ) );
		if ( $this->options->is_enabled( 'image_seo_auto_alt' ) ) {
			$this->loader->add_action( 'add_attachment', $this, 'auto_alt_on_upload', 10, 1 );
		}
		if ( $this->options->is_enabled( 'image_seo_sanitize_filename' ) ) {
			$this->loader->add_filter( 'wp_handle_upload_prefilter', $this, 'sanitize_filename', 10, 1 );
		}
	}

	public function register_menu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Image SEO', 'meyvora-seo' ),
			__( 'Image SEO', 'meyvora-seo' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts for Image SEO page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_' . self::PAGE_SLUG ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		wp_enqueue_style( 'meyvora-image-seo', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-image-seo.css', array( 'meyvora-admin' ), MEYVORA_SEO_VERSION );
		wp_enqueue_script(
			'meyvora-image-seo',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-image-seo.js',
			array(),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script(
			'meyvora-image-seo',
			'meyvoraImageSeo',
			array(
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'action' => self::AJAX_SAVE,
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'i18n'    => array(
					'saveSelected' => __( 'Save selected', 'meyvora-seo' ),
				),
			)
		);
	}

	public function render_page(): void {
		$tab    = isset( $_GET['tab'] ) && $_GET['tab'] === 'missing' ? 'missing' : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$result = $this->get_attachments( $tab, $paged );
		$items  = $result['items'];
		$total  = $result['total'];
		$pages  = $result['pages'];
		$missing_count = self::count_missing_alt_media();
		?>
		<div class="wrap mev-img-seo-wrap">
			<div class="mev-img-header">
				<div class="mev-img-header-left">
					<h1><?php esc_html_e( 'Image SEO', 'meyvora-seo' ); ?></h1>
					<p><?php esc_html_e( 'Manage alt text and titles for every image in your media library.', 'meyvora-seo' ); ?></p>
				</div>
				<div class="mev-img-stats">
					<div class="mev-img-stat">
						<div class="stat-num"><?php echo esc_html( number_format( $total ) ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Total images', 'meyvora-seo' ); ?></div>
					</div>
					<div class="mev-img-stat <?php echo esc_attr( $missing_count > 0 ? 'stat-warn' : 'stat-ok' ); ?>">
						<div class="stat-num"><?php echo esc_html( number_format( $missing_count ) ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'Missing alt', 'meyvora-seo' ); ?></div>
					</div>
					<div class="mev-img-stat stat-ok">
						<div class="stat-num"><?php echo esc_html( number_format( max( 0, $total - $missing_count ) ) ); ?></div>
						<div class="stat-label"><?php esc_html_e( 'With alt text', 'meyvora-seo' ); ?></div>
					</div>
				</div>
			</div>

			<div class="mev-img-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="mev-img-tab <?php echo esc_attr( $tab === 'all' ? 'active' : '' ); ?>">
					<?php esc_html_e( 'All images', 'meyvora-seo' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=missing' ) ); ?>" class="mev-img-tab <?php echo esc_attr( $tab === 'missing' ? 'active' : '' ); ?>">
					<?php esc_html_e( 'Missing alt', 'meyvora-seo' ); ?>
					<?php if ( $missing_count > 0 ) : ?>
						<span class="tab-badge"><?php echo esc_html( number_format( $missing_count ) ); ?></span>
					<?php endif; ?>
				</a>
			</div>

			<?php if ( empty( $items ) ) : ?>
				<div class="mev-img-list">
					<div class="mev-img-empty">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
						<p><?php echo $tab === 'missing' ? esc_html__( 'All images have alt text. Great job!', 'meyvora-seo' ) : esc_html__( 'No images found in the media library.', 'meyvora-seo' ); ?></p>
					</div>
				</div>
			<?php else : ?>
				<div class="mev-img-toolbar">
					<div class="mev-img-toolbar-left">
						<label class="mev-select-all-wrap">
							<input type="checkbox" id="mev-select-all" />
							<?php esc_html_e( 'Select all', 'meyvora-seo' ); ?>
						</label>
						<span class="mev-count-badge"><?php echo esc_html( sprintf( /* translators: %d: number of images */ __( '%d images', 'meyvora-seo' ), (int) $total ) ); ?></span>
					</div>
					<button type="button" class="mev-btn-save-sel" id="mev-save-selected" disabled>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
						<?php esc_html_e( 'Save selected', 'meyvora-seo' ); ?>
					</button>
				</div>

				<div class="mev-img-grid-header">
					<div class="gh"></div>
					<div class="gh"><?php esc_html_e( 'Image', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Filename', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Alt text', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Title', 'meyvora-seo' ); ?></div>
					<div class="gh"><?php esc_html_e( 'Save', 'meyvora-seo' ); ?></div>
				</div>

				<div class="mev-img-list" id="mev-img-rows">
					<?php foreach ( $items as $att ) :
						$alt      = (string) get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
						$title    = (string) $att->post_title;
						$src      = wp_get_attachment_image_url( $att->ID, 'thumbnail' );
						$filename = basename( (string) get_attached_file( $att->ID ) );
						$missing  = trim( $alt ) === '';
					?>
						<div class="mev-img-row" data-id="<?php echo esc_attr( (string) (int) $att->ID ); ?>">
							<div class="mev-img-cell cell-check">
								<input type="checkbox" class="mev-row-select" value="<?php echo esc_attr( (string) (int) $att->ID ); ?>" />
							</div>
							<div class="mev-img-cell cell-thumb">
								<?php if ( $src ) : ?>
									<img src="<?php echo esc_url( $src ); ?>" alt="" class="mev-img-thumb" loading="lazy" />
								<?php else : ?>
									<div class="mev-img-thumb-empty">&#128247;</div>
								<?php endif; ?>
							</div>
							<div class="mev-img-cell cell-filename" title="<?php echo esc_attr( $filename ); ?>">
								<?php echo esc_html( strlen( $filename ) > 28 ? substr( $filename, 0, 25 ) . '…' : $filename ); ?>
							</div>
							<div class="mev-img-cell">
								<input type="text" class="mev-img-input mev-input-alt<?php echo esc_attr( $missing ? ' input-missing' : '' ); ?>" value="<?php echo esc_attr( $alt ); ?>" placeholder="<?php echo esc_attr( $missing ? __( 'No alt text — add one', 'meyvora-seo' ) : __( 'Alt text…', 'meyvora-seo' ) ); ?>" />
							</div>
							<div class="mev-img-cell">
								<input type="text" class="mev-img-input mev-input-title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Title…', 'meyvora-seo' ); ?>" />
							</div>
							<div class="mev-img-cell cell-action">
								<button type="button" class="mev-img-save-btn mev-save-row" title="<?php esc_attr_e( 'Save', 'meyvora-seo' ); ?>">
									<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<div class="mev-img-pagination">
						<span class="pg-info">
							<?php echo esc_html( sprintf( /* translators: 1: current page, 2: total pages, 3: total images */ __( 'Page %1$d of %2$d · %3$d images', 'meyvora-seo' ), (int) $paged, (int) $pages, (int) $total ) ); ?>
						</span>
						<div class="pg-links">
							<?php if ( $paged > 1 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1 ) ); ?>">&laquo;</a>
							<?php endif; ?>
							<?php
							$start = max( 1, $paged - 2 );
							$end   = min( $pages, $paged + 2 );
							for ( $p = $start; $p <= $end; $p++ ) :
								if ( $p === $paged ) :
									echo '<span class="current">' . (int) $p . '</span>';
								else :
									echo '<a href="' . esc_url( add_query_arg( 'paged', $p ) ) . '">' . (int) $p . '</a>';
								endif;
							endfor;
							?>
							<?php if ( $paged < $pages ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1 ) ); ?>">&raquo;</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php
	}

	/**
	 * Get paginated image attachments.
	 *
	 * @param string $tab   'all' or 'missing'.
	 * @param int    $paged Page number.
	 * @return array{ items: array, total: int, pages: int }
	 */
	public function get_attachments( string $tab, int $paged = 1 ): array {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image',
			'post_status'    => 'inherit',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $tab === 'missing' ) {
			$args['meta_query'] = array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ),
			);
		}
		$q      = new WP_Query( $args );
		$items  = $q->posts;
		$total  = (int) $q->found_posts;
		$pages  = (int) max( 1, $q->max_num_pages );
		return array( 'items' => $items, 'total' => $total, 'pages' => $pages );
	}

	/**
	 * AJAX: save alt and/or title for one or more attachments.
	 */
	public function ajax_save_alt(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'meyvora-seo' ) ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( wp_unslash( $_POST['post_id'] ) ) : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'meyvora-seo' ) ) );
		}
		$alt   = isset( $_POST['alt'] ) ? sanitize_text_field( wp_unslash( $_POST['alt'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$post  = get_post( $post_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment.', 'meyvora-seo' ) ) );
		}
		update_post_meta( $post_id, '_wp_attachment_image_alt', $alt );
		if ( $title !== '' ) {
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $title ) );
		}
		wp_send_json_success();
	}

	/**
	 * On attachment add: if image and no alt, generate from filename.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function auto_alt_on_upload( int $post_id ): void {
		if ( ! wp_attachment_is_image( $post_id ) ) {
			return;
		}
		$existing = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
		if ( is_string( $existing ) && trim( $existing ) !== '' ) {
			return;
		}
		$file = get_attached_file( $post_id );
		if ( ! $file || ! is_string( $file ) ) {
			return;
		}
		$name = basename( $file );
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		if ( $ext ) {
			$name = substr( $name, 0, - ( strlen( $ext ) + 1 ) );
		}
		$name = str_replace( array( '-', '_' ), ' ', $name );
		$name = trim( preg_replace( '/\s+/', ' ', $name ) );
		if ( $name === '' ) {
			return;
		}
		$generated = ucfirst( $name );
		// AI vision alt text (overrides filename-based alt when enabled).
		if ( $this->options->is_enabled( 'image_seo_ai_alt' ) ) {
			$mime = get_post_mime_type( $post_id );
			if ( is_string( $mime ) && strpos( $mime, 'image/' ) === 0 ) {
				$ai_alt = $this->generate_ai_alt( $post_id, $mime );
				if ( $ai_alt !== '' ) {
					$generated = $ai_alt;
				}
			}
		}
		update_post_meta( $post_id, '_wp_attachment_image_alt', $generated );
	}

	/**
	 * Generate alt text for an attachment using vision AI (OpenAI-compatible).
	 *
	 * @param int    $post_id   Attachment ID.
	 * @param string $mime_type Image MIME type (e.g. image/jpeg).
	 * @return string Alt text or empty string on failure.
	 */
	private function generate_ai_alt( int $post_id, string $mime_type ): string {
		if ( ! class_exists( 'Meyvora_SEO_AI' ) ) {
			return '';
		}
		$enc = $this->options->get( 'ai_api_key_encrypted', '' );
		if ( $enc === '' ) {
			return '';
		}
		$api_key = Meyvora_SEO_AI::decrypt( $enc );
		if ( $api_key === '' ) {
			return '';
		}
		$file_path = get_attached_file( $post_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return '';
		}
		if ( filesize( $file_path ) > 4 * 1024 * 1024 ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Binary image for base64.
		$image_data = base64_encode( file_get_contents( $file_path ) );
		$provider   = $this->options->get( 'ai_api_provider', 'openai' );
		$model      = $this->options->get( 'ai_model', 'gpt-4o-mini' );
		$url        = ( $provider === 'custom' )
			? (string) $this->options->get( 'ai_custom_endpoint', 'https://api.openai.com/v1/chat/completions' )
			: 'https://api.openai.com/v1/chat/completions';
		$body = array(
			'model'      => $model,
			'max_tokens' => 100,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'Write a concise, descriptive alt text for this image in under 125 characters. Return only the alt text, no quotes or labels.',
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => 'data:' . $mime_type . ';base64,' . $image_data,
							),
						),
					),
				),
			),
		);
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return '';
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $json['choices'][0]['message']['content'] ?? '';
		return sanitize_text_field( trim( (string) $text ) );
	}

	/**
	 * Sanitize upload filename: lowercase, spaces/special to hyphens, no double hyphens.
	 *
	 * @param array $file Array with 'name', 'type', 'tmp_name', 'error', 'size'.
	 * @return array
	 */
	public function sanitize_filename( array $file ): array {
		if ( empty( $file['name'] ) ) {
			return $file;
		}
		$name = $file['name'];
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$base = pathinfo( $name, PATHINFO_FILENAME );
		$base = strtolower( $base );
		$base = preg_replace( '/[^a-z0-9]+/', '-', $base );
		$base = preg_replace( '/-+/', '-', $base );
		$base = trim( $base, '-' );
		if ( $base === '' ) {
			$base = 'image';
		}
		$file['name'] = $ext ? $base . '.' . strtolower( $ext ) : $base;
		return $file;
	}

	/**
	 * Count media library images with empty alt (for audit summary).
	 *
	 * @return int
	 */
	public static function count_missing_alt_media(): int {
		global $wpdb;
		$posts = $wpdb->prefix . 'posts';
		$meta  = $wpdb->prefix . 'postmeta';
		$sql   = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) FROM {$posts} p
			LEFT JOIN {$meta} m ON p.ID = m.post_id AND m.meta_key = %s
			WHERE p.post_type = %s AND p.post_status = %s
			AND (p.post_mime_type LIKE %s)
			AND (m.meta_id IS NULL OR m.meta_value = %s)",
			'_wp_attachment_image_alt',
			'attachment',
			'inherit',
			'image/%',
			''
		);
		// Table names from $wpdb->prefix; query is prepared above.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( $sql );
	}
}
