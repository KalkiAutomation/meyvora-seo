<?php
/**
 * Elementor integration: provide page content from Elementor data for SEO analysis.
 * Does not load or depend on the Elementor plugin; reads stored post meta only.
 * No fatal errors if Elementor is not active.
 *
 * Data source: post meta _elementor_data (JSON array of section/container/widget elements).
 * See ELEMENTOR-INTEGRATION.md for data source details, limitations, and which SEO checks benefit.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Elementor {

	/**
	 * Post meta key used by Elementor for layout JSON.
	 */
	const ELEMENTOR_DATA_KEY = '_elementor_data';

	/**
	 * Register filter and admin hooks (AJAX, editor script).
	 */
	public static function register(): void {
		add_filter( 'meyvora_seo_analysis_content', array( __CLASS__, 'filter_analysis_content' ), 10, 2 );
		add_action( 'elementor/editor/after_enqueue_scripts', array( __CLASS__, 'enqueue_editor_script' ), 10, 0 );
		add_action( 'wp_ajax_meyvora_seo_elementor_analyze', array( __CLASS__, 'ajax_elementor_analyze' ), 10, 0 );
		add_action( 'wp_ajax_meyvora_seo_elementor_save_meta', array( __CLASS__, 'ajax_elementor_save_meta' ), 10, 0 );

		// Register the Meyvora FAQ widget.
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_faq_widget' ), 10, 1 );

		// Enqueue FAQ frontend assets in Elementor preview and on frontend.
		add_action( 'elementor/preview/enqueue_styles',          array( __CLASS__, 'enqueue_faq_assets' ), 10, 0 );
		add_action( 'elementor/preview/enqueue_scripts',         array( __CLASS__, 'enqueue_faq_assets' ), 10, 0 );
		add_action( 'elementor/frontend/after_enqueue_styles',   array( __CLASS__, 'enqueue_faq_assets' ), 10, 0 );
		add_action( 'elementor/frontend/after_register_scripts', array( __CLASS__, 'enqueue_faq_assets' ), 10, 0 );
	}

	/**
	 * Register the Meyvora FAQ Elementor widget.
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widget manager.
	 */
	public static function register_faq_widget( $widgets_manager ): void {
		$widget_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'integrations/class-meyvora-seo-elementor-faq-widget.php' : '';
		if ( $widget_file && file_exists( $widget_file ) ) {
			require_once $widget_file;
			if ( class_exists( 'Meyvora_SEO_Elementor_FAQ_Widget' ) ) {
				$widgets_manager->register( new Meyvora_SEO_Elementor_FAQ_Widget() );
			}
		}
	}

	/**
	 * Enqueue FAQ frontend CSS and JS for Elementor pages.
	 */
	public static function enqueue_faq_assets(): void {
		$css_path = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'assets/css/meyvora-faq.css' : '';
		$js_path  = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'assets/js/meyvora-faq.js'  : '';
		$url      = defined( 'MEYVORA_SEO_URL' )  ? MEYVORA_SEO_URL  : '';
		$ver      = defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0';

		if ( $css_path && file_exists( $css_path ) && ! wp_style_is( 'meyvora-faq', 'enqueued' ) ) {
			wp_enqueue_style( 'meyvora-faq', $url . 'assets/css/meyvora-faq.css', array(), $ver );
		}
		if ( $js_path && file_exists( $js_path ) && ! wp_script_is( 'meyvora-faq', 'enqueued' ) ) {
			wp_enqueue_script( 'meyvora-faq', $url . 'assets/js/meyvora-faq.js', array(), $ver, true );
		}
	}

	/**
	 * Enqueue JS in Elementor editor and localize.
	 */
	public static function enqueue_editor_script(): void {
		$path = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'integrations/assets/js/meyvora-elementor.js' : '';
		if ( ! $path || ! file_exists( $path ) ) {
			return;
		}
		$post_id = get_the_ID();
		wp_enqueue_script(
			'meyvora-seo-elementor',
			( defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '' ) . 'integrations/assets/js/meyvora-elementor.js',
			array( 'jquery' ),
			defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0',
			true
		);
		wp_localize_script( 'meyvora-seo-elementor', 'meyvoraSeoElementor', array(
			'nonce'          => wp_create_nonce( 'meyvora_seo_elementor' ),
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'saveAction'     => 'meyvora_seo_elementor_save_meta',
			'analyzeAction'  => 'meyvora_seo_elementor_analyze',
			'postId'         => $post_id ? (int) $post_id : 0,
			'i18n'           => array(
				'panelTitle' => __( 'Meyvora SEO', 'meyvora-seo' ),
				'focusKw'    => __( 'Focus Keyword', 'meyvora-seo' ),
				'metaDesc'    => __( 'Meta Description', 'meyvora-seo' ),
				'analyze'    => __( 'Analyse', 'meyvora-seo' ),
				'save'       => __( 'Save', 'meyvora-seo' ),
				'score'      => __( 'SEO Score', 'meyvora-seo' ),
				'noData'     => __( 'Click Analyse to check SEO.', 'meyvora-seo' ),
			),
			'existingKeyword' => $post_id ? (string) get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true ) : '',
			'existingDesc'    => $post_id ? (string) get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true ) : '',
		) );
	}

	/**
	 * AJAX: Save focus keyword and meta description from Elementor panel.
	 */
	public static function ajax_elementor_save_meta(): void {
		check_ajax_referer( 'meyvora_seo_elementor', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error();
			return;
		}
		if ( isset( $_POST['focus_keyword'] ) ) {
			update_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, sanitize_text_field( wp_unslash( $_POST['focus_keyword'] ) ) );
		}
		if ( isset( $_POST['meta_description'] ) ) {
			update_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, sanitize_textarea_field( wp_unslash( $_POST['meta_description'] ) ) );
		}
		wp_send_json_success();
	}

	/**
	 * AJAX: Run analysis for Elementor editor (after save).
	 */
	public static function ajax_elementor_analyze(): void {
		check_ajax_referer( 'meyvora_seo_elementor', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post' ) );
		}
		if ( ! class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			wp_send_json_error( array( 'message' => 'Analyzer not loaded' ) );
		}
		$content = apply_filters( 'meyvora_seo_analysis_content', (string) get_post( $post_id )->post_content, $post_id );
		$analyzer = new Meyvora_SEO_Analyzer();
		$analysis = $analyzer->analyze( $post_id, $content !== '' ? $content : null );
		update_post_meta( $post_id, MEYVORA_SEO_META_SCORE, $analysis['score'] );
		update_post_meta( $post_id, MEYVORA_SEO_META_ANALYSIS, wp_json_encode( array(
			'score' => $analysis['score'],
			'status' => $analysis['status'],
			'results' => $analysis['results'],
		) ) );
		wp_send_json_success( array(
			'score'  => $analysis['score'],
			'status' => $analysis['status'],
			'results' => $analysis['results'],
		) );
	}

	/**
	 * Filter: provide content for SEO analysis. If post is built with Elementor, return extracted content.
	 *
	 * @param string $content  Default content (e.g. post_content).
	 * @param int    $post_id  Post ID.
	 * @return string Content to use for analysis.
	 */
	public static function filter_analysis_content( string $content, int $post_id ): string {
		$elementor_content = self::get_content_from_elementor( $post_id );
		if ( $elementor_content === '' ) {
			return $content;
		}
		return $elementor_content;
	}

	/**
	 * Check if a post is built with Elementor (has stored layout data).
	 * Safe: only reads post meta, no Elementor API.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_elementor_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		$raw = get_post_meta( $post_id, self::ELEMENTOR_DATA_KEY, true );
		if ( ! is_string( $raw ) || $raw === '' || $raw === '[]' ) {
			return false;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) && ! empty( $data );
	}

	/**
	 * Get analyzable content from Elementor stored data.
	 * Returns HTML with headings (h1–h6), text, images (with alt when available), and links
	 * so the SEO analyzer can run all checks (keyword, length, H1, structure, alt, links).
	 *
	 * @param int $post_id Post ID.
	 * @return string HTML string for analysis, or empty if not Elementor or parse failed.
	 */
	public static function get_content_from_elementor( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$raw = get_post_meta( $post_id, self::ELEMENTOR_DATA_KEY, true );
		if ( ! is_string( $raw ) || $raw === '' || $raw === '[]' ) {
			return '';
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			// Some installs store double-encoded JSON.
			if ( is_string( $data ) ) {
				$data = json_decode( $data, true );
			}
			if ( ! is_array( $data ) || empty( $data ) ) {
				return '';
			}
		}

		$parts = array();
		self::walk_elements( $data, $parts );
		$html = implode( "\n", array_filter( $parts ) );
		return is_string( $html ) ? $html : '';
	}

	/**
	 * Recursively walk Elementor elements and collect HTML for analysis.
	 *
	 * @param array<int, array> $elements Elements array.
	 * @param array<int, string> $parts    Collected HTML (by reference).
	 */
	protected static function walk_elements( array $elements, array &$parts ): void {
		foreach ( $elements as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$widget_type = isset( $item['widgetType'] ) && is_string( $item['widgetType'] ) ? $item['widgetType'] : '';
			$settings   = isset( $item['settings'] ) && is_array( $item['settings'] ) ? $item['settings'] : array();

			switch ( $widget_type ) {
				case 'heading':
					self::emit_heading( $settings, $parts );
					break;
				case 'text-editor':
					self::emit_text_editor( $settings, $parts );
					break;
				case 'image':
					self::emit_image( $settings, $parts );
					break;
				case 'image-box':
					self::emit_image_box( $settings, $parts );
					break;
				case 'button':
					self::emit_button( $settings, $parts );
					break;
				case 'html':
					self::emit_html( $settings, $parts );
					break;
				case 'icon-box':
					self::emit_icon_box( $settings, $parts );
					break;
				case 'testimonial':
					self::emit_testimonial( $settings, $parts );
					break;
				case 'accordion':
					self::emit_accordion( $settings, $parts );
					break;
				case 'tabs':
					self::emit_tabs( $settings, $parts );
					break;
				case 'list':
					self::emit_list( $settings, $parts );
					break;
				case 'counter':
					self::emit_counter( $settings, $parts );
					break;
				case 'gallery':
					self::emit_gallery( $settings, $parts );
					break;
				case 'video':
					self::emit_video( $settings, $parts );
					break;
				default:
					self::emit_generic_text( $settings, $parts );
					break;
			}

			if ( isset( $item['elements'] ) && is_array( $item['elements'] ) ) {
				self::walk_elements( $item['elements'], $parts );
			}
		}
	}

	private static function emit_heading( array $settings, array &$parts ): void {
		$title = isset( $settings['title'] ) ? $settings['title'] : '';
		if ( ! is_string( $title ) || $title === '' ) {
			return;
		}
		$size = isset( $settings['header_size'] ) ? $settings['header_size'] : 'h2';
		$tag  = in_array( $size, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $size : 'h2';
		$parts[] = '<' . $tag . '>' . wp_kses_post( $title ) . '</' . $tag . '>';
	}

	private static function emit_text_editor( array $settings, array &$parts ): void {
		$editor = isset( $settings['editor'] ) ? $settings['editor'] : '';
		if ( is_string( $editor ) && $editor !== '' ) {
			$parts[] = wp_kses_post( $editor );
		}
	}

	/**
	 * Emit one img tag. Empty alt when not set so analyzer can flag missing alt.
	 */
	private static function emit_image( array $settings, array &$parts ): void {
		$alt = isset( $settings['image_alt'] ) && is_string( $settings['image_alt'] ) ? $settings['image_alt'] : '';
		$parts[] = '<img alt="' . esc_attr( $alt ) . '" />';
	}

	private static function emit_image_box( array $settings, array &$parts ): void {
		$alt = isset( $settings['image_alt'] ) && is_string( $settings['image_alt'] ) ? $settings['image_alt'] : '';
		$parts[] = '<img alt="' . esc_attr( $alt ) . '" />';
		$desc = isset( $settings['description_text'] ) ? $settings['description_text'] : '';
		if ( is_string( $desc ) && $desc !== '' ) {
			$parts[] = wp_kses_post( $desc );
		}
	}

	private static function emit_button( array $settings, array &$parts ): void {
		$text = isset( $settings['text'] ) ? $settings['text'] : '';
		$url  = isset( $settings['link']['url'] ) ? $settings['link']['url'] : '';
		if ( is_string( $text ) && $text !== '' && is_string( $url ) && $url !== '' ) {
			$parts[] = '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
		}
	}

	private static function emit_html( array $settings, array &$parts ): void {
		$html = isset( $settings['html'] ) ? $settings['html'] : '';
		if ( is_string( $html ) && $html !== '' ) {
			$parts[] = wp_kses_post( $html );
		}
	}

	private static function emit_icon_box( array $settings, array &$parts ): void {
		$title = isset( $settings['title_text'] ) ? $settings['title_text'] : ( isset( $settings['title'] ) ? $settings['title'] : '' );
		if ( is_string( $title ) && $title !== '' ) {
			$parts[] = '<p><strong>' . esc_html( $title ) . '</strong></p>';
		}
		$desc = isset( $settings['description_text'] ) ? $settings['description_text'] : '';
		if ( is_string( $desc ) && $desc !== '' ) {
			$parts[] = wp_kses_post( $desc );
		}
	}

	private static function emit_testimonial( array $settings, array &$parts ): void {
		$content = isset( $settings['content'] ) ? $settings['content'] : ( isset( $settings['testimonial_content'] ) ? $settings['testimonial_content'] : '' );
		if ( is_string( $content ) && $content !== '' ) {
			$parts[] = wp_kses_post( $content );
		}
		$name = isset( $settings['name'] ) ? $settings['name'] : '';
		if ( is_string( $name ) && $name !== '' ) {
			$parts[] = '<p>' . esc_html( $name ) . '</p>';
		}
	}

	private static function emit_accordion( array $settings, array &$parts ): void {
		$tabs = isset( $settings['tabs'] ) && is_array( $settings['tabs'] ) ? $settings['tabs'] : array();
		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}
			$title = isset( $tab['tab_title'] ) ? $tab['tab_title'] : ( isset( $tab['title'] ) ? $tab['title'] : '' );
			if ( is_string( $title ) && $title !== '' ) {
				$parts[] = '<h3>' . esc_html( $title ) . '</h3>';
			}
			$content = isset( $tab['tab_content'] ) ? $tab['tab_content'] : ( isset( $tab['content'] ) ? $tab['content'] : '' );
			if ( is_string( $content ) && $content !== '' ) {
				$parts[] = wp_kses_post( $content );
			}
		}
	}

	private static function emit_tabs( array $settings, array &$parts ): void {
		$tabs = isset( $settings['tabs'] ) && is_array( $settings['tabs'] ) ? $settings['tabs'] : array();
		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}
			$title = isset( $tab['tab_title'] ) ? $tab['tab_title'] : ( isset( $tab['title'] ) ? $tab['title'] : '' );
			if ( is_string( $title ) && $title !== '' ) {
				$parts[] = '<h3>' . esc_html( $title ) . '</h3>';
			}
			$content = isset( $tab['tab_content'] ) ? $tab['tab_content'] : ( isset( $tab['content'] ) ? $tab['content'] : '' );
			if ( is_string( $content ) && $content !== '' ) {
				$parts[] = wp_kses_post( $content );
			}
		}
	}

	private static function emit_list( array $settings, array &$parts ): void {
		$items = isset( $settings['list'] ) && is_array( $settings['list'] ) ? $settings['list'] : ( isset( $settings['list_items'] ) && is_array( $settings['list_items'] ) ? $settings['list_items'] : array() );
		foreach ( $items as $row ) {
			$text = is_array( $row ) && isset( $row['content'] ) ? $row['content'] : ( is_string( $row ) ? $row : '' );
			if ( is_string( $text ) && $text !== '' ) {
				$parts[] = '<p>' . wp_kses_post( $text ) . '</p>';
			}
		}
	}

	private static function emit_counter( array $settings, array &$parts ): void {
		$title = isset( $settings['title'] ) ? $settings['title'] : '';
		if ( is_string( $title ) && $title !== '' ) {
			$parts[] = '<p>' . esc_html( $title ) . '</p>';
		}
	}

	/**
	 * Gallery: array of attachment IDs. We emit one img per ID; alt from media library when available.
	 */
	private static function emit_gallery( array $settings, array &$parts ): void {
		$gallery = isset( $settings['gallery'] ) && is_array( $settings['gallery'] ) ? $settings['gallery'] : array();
		foreach ( $gallery as $entry ) {
			$id = null;
			if ( is_array( $entry ) && isset( $entry['id'] ) ) {
				$id = (int) $entry['id'];
			} elseif ( is_numeric( $entry ) ) {
				$id = (int) $entry;
			}
			if ( $id <= 0 ) {
				continue;
			}
			$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$alt = is_string( $alt ) ? $alt : '';
			$parts[] = '<img alt="' . esc_attr( $alt ) . '" />';
		}
	}

	private static function emit_video( array $settings, array &$parts ): void {
		$title = isset( $settings['title'] ) ? $settings['title'] : '';
		if ( is_string( $title ) && $title !== '' ) {
			$parts[] = '<p>' . esc_html( $title ) . '</p>';
		}
		$caption = isset( $settings['caption'] ) ? $settings['caption'] : '';
		if ( is_string( $caption ) && $caption !== '' ) {
			$parts[] = wp_kses_post( $caption );
		}
	}

	private static function emit_generic_text( array $settings, array &$parts ): void {
		if ( isset( $settings['title'] ) && is_string( $settings['title'] ) && $settings['title'] !== '' ) {
			$parts[] = '<p>' . esc_html( $settings['title'] ) . '</p>';
		}
		if ( isset( $settings['description'] ) && is_string( $settings['description'] ) && $settings['description'] !== '' ) {
			$parts[] = wp_kses_post( $settings['description'] );
		}
	}
}
