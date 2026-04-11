<?php
/**
 * Post/Page SEO panel: tabbed UI (General, Social, Advanced, Score), live snippet preview, real-time analysis.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Meta_Box {

	const NONCE_ACTION = 'meyvora_seo_meta_box';
	const NONCE_NAME   = 'meyvora_seo_meta_nonce';
	const AJAX_NONCE_ACTION = 'meyvora_seo_nonce';

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'add_meta_boxes', $this, 'add_meta_box', 10, 0 );
		$this->loader->add_action( 'save_post', $this, 'save_meta', 10, 2 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_meyvora_seo_analyze', array( $this, 'ajax_analyze' ) );
		add_action( 'wp_ajax_meyvora_seo_autosave', array( $this, 'ajax_autosave' ) );
	}

	/**
	 * Enqueue meta box CSS and JS on post edit screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'post.php' && $hook_suffix !== 'post-new.php' ) {
			return;
		}
		if ( ! $this->options->current_user_can_edit_seo() ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type ?? '', $this->get_supported_post_types(), true ) ) {
			return;
		}
		wp_enqueue_media();
		$css_path = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-admin.css';
		$js_path  = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-meta-box.js';
		$serp_css = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-serp-preview.css';
		$serp_js  = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-serp-preview.js';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'meyvora-seo-admin',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css',
				array(),
				MEYVORA_SEO_VERSION
			);
		}
		if ( file_exists( $serp_css ) ) {
			wp_enqueue_style(
				'meyvora-seo-serp-preview',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-serp-preview.css',
				array( 'meyvora-seo-admin' ),
				MEYVORA_SEO_VERSION
			);
		}
		if ( file_exists( $js_path ) ) {
			wp_enqueue_script(
				'meyvora-seo-meta-box',
				MEYVORA_SEO_URL . 'admin/assets/js/meyvora-meta-box.js',
				array( 'jquery' ),
				MEYVORA_SEO_VERSION,
				true
			);
			if ( file_exists( $serp_js ) ) {
				wp_enqueue_script(
					'meyvora-seo-serp-preview',
					MEYVORA_SEO_URL . 'admin/assets/js/meyvora-serp-preview.js',
					array(),
					MEYVORA_SEO_VERSION,
					true
				);
			}
			$dataforseo_key = $this->options->get( 'dataforseo_api_key', '' );
			$keyword_research_enabled = is_string( $dataforseo_key ) && $dataforseo_key !== '';
			wp_localize_script( 'meyvora-seo-meta-box', 'meyvoraSeo', array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( self::AJAX_NONCE_ACTION ),
				'aiNonce'                => wp_create_nonce( 'meyvora_seo_ai' ),
				'aiAvailable'            => class_exists( 'Meyvora_SEO_AI' ) && is_string( $this->options->get( 'ai_api_key_encrypted', '' ) ) && $this->options->get( 'ai_api_key_encrypted', '' ) !== '',
				'postId'                 => get_the_ID() ? (int) get_the_ID() : 0,
				'titleMin'               => 30,
				'titleMax'               => 60,
				'descMin'                => 120,
				'descMax'                => 160,
				'keywordResearchEnabled' => $keyword_research_enabled,
				'i18n'                   => array(
					/* translators: 1: current character count, 2: recommended max character count */
					'charCount'   => __( '%1$d / %2$d characters', 'meyvora-seo' ),
					/* translators: %d: approximate pixel width */
					'approxPx'    => __( '~%dpx width', 'meyvora-seo' ),
					'focusLabel'  => __( 'Focus:', 'meyvora-seo' ),
					'analyzing'   => __( 'Analyzing…', 'meyvora-seo' ),
					'saved'       => __( 'Saved', 'meyvora-seo' ),
					'abFillBoth'  => __( 'Please fill both Variant A and Variant B.', 'meyvora-seo' ),
					'error'       => __( 'Error', 'meyvora-seo' ),
					'needsWork'   => __( 'Needs Work', 'meyvora-seo' ),
					'almostThere' => __( 'Almost There', 'meyvora-seo' ),
					'great'       => __( 'Great!', 'meyvora-seo' ),
					/* translators: %d: number of passed checks */
					'showPassed'  => __( 'Show %d passed checks', 'meyvora-seo' ),
					'hidePassed'  => __( 'Hide passed checks', 'meyvora-seo' ),
					'keywordResearch'     => __( 'Keyword Research', 'meyvora-seo' ),
					'keywordResearchBtn'  => __( 'Research', 'meyvora-seo' ),
					'keywordResearching'  => __( 'Researching…', 'meyvora-seo' ),
					'keywordVolume'       => __( 'Volume', 'meyvora-seo' ),
					'keywordCompetition'  => __( 'Competition', 'meyvora-seo' ),
					'keywordCpc'          => __( 'CPC', 'meyvora-seo' ),
					'keywordAddSecondary' => __( 'Add as secondary', 'meyvora-seo' ),
					'keywordResearchError'=> __( 'Request failed.', 'meyvora-seo' ),
				),
			) );
		}
	}

	/**
	 * Register the meta box for supported post types.
	 */
	public function add_meta_box(): void {
		if ( ! $this->options->current_user_can_edit_seo() ) {
			return;
		}
		$screens = $this->get_supported_post_types();
		foreach ( $screens as $screen ) {
			add_meta_box(
				'meyvora_seo',
				__( 'Meyvora SEO', 'meyvora-seo' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'normal',
				'default'
			);
		}
	}

	/**
	 * Get post types that show the SEO meta box (filterable).
	 *
	 * @return array<string>
	 */
	protected function get_supported_post_types(): array {
		return apply_filters( 'meyvora_seo_supported_post_types', $this->get_public_post_types() );
	}

	/**
	 * Get public post types (excluding attachment, revision, nav_menu_item).
	 *
	 * @return array<string>
	 */
	protected function get_public_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'names' );
		$exclude = array( 'attachment', 'revision', 'nav_menu_item' );
		$out = array();
		foreach ( $types as $t ) {
			if ( ! in_array( $t, $exclude, true ) ) {
				$out[] = $t;
			}
		}
		if ( ! in_array( 'post', $out, true ) ) {
			$out[] = 'post';
		}
		if ( ! in_array( 'page', $out, true ) ) {
			$out[] = 'page';
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Render SEO panel content.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$meta_get = function_exists( 'meyvora_seo_get_translated_post_meta' )
			? function( $id, $key, $single ) { return meyvora_seo_get_translated_post_meta( $id, $key, $single ); }
			: function( $id, $key, $single ) { return get_post_meta( $id, $key, $single ); };
		$focus_keyword_raw = $meta_get( $post->ID, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
		$focus_keywords    = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::normalize_focus_keywords( $focus_keyword_raw ) : ( is_string( $focus_keyword_raw ) && $focus_keyword_raw !== '' ? array( trim( $focus_keyword_raw ) ) : array() );
		$title           = $meta_get( $post->ID, MEYVORA_SEO_META_TITLE, true );
		$description     = $meta_get( $post->ID, MEYVORA_SEO_META_DESCRIPTION, true );
		$desc_variant_a  = $meta_get( $post->ID, MEYVORA_SEO_META_DESC_VARIANT_A, true );
		$desc_variant_b  = $meta_get( $post->ID, MEYVORA_SEO_META_DESC_VARIANT_B, true );
		$desc_ab_active  = $meta_get( $post->ID, MEYVORA_SEO_META_DESC_AB_ACTIVE, true );
		$desc_ab_start   = $meta_get( $post->ID, MEYVORA_SEO_META_DESC_AB_START, true );
		$desc_ab_result  = $meta_get( $post->ID, MEYVORA_SEO_META_DESC_AB_RESULT, true );
		$canonical       = $meta_get( $post->ID, MEYVORA_SEO_META_CANONICAL, true );
		$noindex         = (bool) $meta_get( $post->ID, MEYVORA_SEO_META_NOINDEX, true );
		$nofollow        = (bool) $meta_get( $post->ID, MEYVORA_SEO_META_NOFOLLOW, true );
		$noodp           = (bool) $meta_get( $post->ID, MEYVORA_SEO_META_NOODP, true );
		$noarchive       = (bool) $meta_get( $post->ID, MEYVORA_SEO_META_NOARCHIVE, true );
		$nosnippet       = (bool) $meta_get( $post->ID, MEYVORA_SEO_META_NOSNIPPET, true );
		$max_snippet     = (int) $meta_get( $post->ID, MEYVORA_SEO_META_MAX_SNIPPET, true );
		$max_image_preview  = $meta_get( $post->ID, MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW, true );
		$max_image_preview  = in_array( $max_image_preview, array( 'none', 'standard', 'large' ), true ) ? $max_image_preview : 'large';
		$max_video_preview  = (int) $meta_get( $post->ID, MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW, true );
		if ( $max_video_preview < -1 ) {
			$max_video_preview = -1;
		}
		$og_title        = $meta_get( $post->ID, MEYVORA_SEO_META_OG_TITLE, true );
		$og_desc         = $meta_get( $post->ID, MEYVORA_SEO_META_OG_DESCRIPTION, true );
		$og_image        = $meta_get( $post->ID, MEYVORA_SEO_META_OG_IMAGE, true );
		$twitter_title    = $meta_get( $post->ID, MEYVORA_SEO_META_TWITTER_TITLE, true );
		$twitter_desc     = $meta_get( $post->ID, MEYVORA_SEO_META_TWITTER_DESCRIPTION, true );
		$twitter_image    = $meta_get( $post->ID, MEYVORA_SEO_META_TWITTER_IMAGE, true );
		$schema_type     = $meta_get( $post->ID, MEYVORA_SEO_META_SCHEMA_TYPE, true );
		$breadcrumb_title = $meta_get( $post->ID, MEYVORA_SEO_META_BREADCRUMB_TITLE, true );
		$cornerstone      = (bool) $meta_get( $post->ID, MEYVORA_SEO_META_CORNERSTONE, true );
		$secondary_raw   = $meta_get( $post->ID, MEYVORA_SEO_META_SECONDARY_KEYWORDS, true );
		$stored_score    = $meta_get( $post->ID, MEYVORA_SEO_META_SCORE, true );
		$stored_analysis = $meta_get( $post->ID, MEYVORA_SEO_META_ANALYSIS, true );

		$secondary_keywords = array();
		if ( is_string( $secondary_raw ) && $secondary_raw !== '' ) {
			$decoded = json_decode( $secondary_raw, true );
			if ( is_array( $decoded ) ) {
				$secondary_keywords = array_slice( array_values( array_filter( array_map( 'strval', $decoded ) ) ), 0, 3 );
			}
		}
		$secondary_keywords = array_pad( $secondary_keywords, 3, '' );

		$analysis_data = array();
		if ( is_string( $stored_analysis ) && $stored_analysis !== '' ) {
			$decoded = json_decode( $stored_analysis, true );
			if ( is_array( $decoded ) && isset( $decoded['score'], $decoded['results'] ) ) {
				$analysis_data = $decoded;
			}
		}
		if ( empty( $analysis_data ) && class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			$analyzer   = new Meyvora_SEO_Analyzer();
			$content    = apply_filters( 'meyvora_seo_analysis_content', (string) $post->post_content, $post->ID );
			$analysis_data = $analyzer->analyze( $post->ID, $content );
		}
		$score   = isset( $analysis_data['score'] ) ? (int) $analysis_data['score'] : ( is_numeric( $stored_score ) ? (int) $stored_score : 0 );
		$status  = isset( $analysis_data['status'] ) ? $analysis_data['status'] : ( $score >= 80 ? 'good' : ( $score >= 50 ? 'okay' : 'poor' ) );
		$results = isset( $analysis_data['results'] ) && is_array( $analysis_data['results'] ) ? $analysis_data['results'] : array();

		$snippet_title  = $title !== '' ? $title : $post->post_title;
		$analysis_content = apply_filters( 'meyvora_seo_analysis_content', (string) $post->post_content, $post->ID );
		$snippet_desc   = $description !== '' ? $description : wp_trim_words( wp_strip_all_tags( $analysis_content ), 25 );
		$snippet_url    = get_permalink( $post ) ?: home_url( '/' );
		$snippet_domain = '';
		$host           = wp_parse_url( $snippet_url, PHP_URL_HOST );
		if ( is_string( $host ) ) {
			$snippet_domain = preg_replace( '/^www\./i', '', $host );
		}
		$og_image_url   = is_numeric( $og_image ) ? wp_get_attachment_image_url( (int) $og_image, 'medium' ) : '';
		$tw_image_url   = is_numeric( $twitter_image ) ? wp_get_attachment_image_url( (int) $twitter_image, 'medium' ) : '';
		$schema_types   = array(
			''                    => __( 'None', 'meyvora-seo' ),
			'Article'             => 'Article',
			'BlogPosting'          => 'BlogPosting',
			'NewsArticle'          => 'News Article',
			'WebPage'             => 'WebPage',
			'FAQPage'             => 'FAQPage',
			'HowTo'               => 'HowTo',
			'Recipe'              => 'Recipe',
			'Event'               => 'Event',
			'Course'              => 'Course',
			'JobPosting'          => 'Job Posting',
			'SoftwareApplication' => 'Software Application',
			'Review'              => 'Review',
			'Book'                => 'Book',
		);
		if ( ! function_exists( 'is_product' ) ) {
			$schema_types['Product'] = __( 'Product (standalone)', 'meyvora-seo' );
		}
		$schema_howto   = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_HOWTO );
		$schema_recipe  = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_RECIPE );
		$schema_event   = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_EVENT );
		$schema_course  = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_COURSE );
		$schema_job     = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_JOBPOSTING );
		$schema_software = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_SOFTWARE );
		$schema_review  = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_REVIEW );
		$schema_book    = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_BOOK );
		$schema_product = $this->get_schema_json( $post->ID, MEYVORA_SEO_META_SCHEMA_PRODUCT );
		$faq_raw        = $meta_get( $post->ID, MEYVORA_SEO_META_FAQ, true );
		$faq_pairs      = is_string( $faq_raw ) ? json_decode( $faq_raw, true ) : array();
		if ( ! is_array( $faq_pairs ) ) {
			$faq_pairs = array();
		}
		$ai_available   = class_exists( 'Meyvora_SEO_AI' ) && is_string( $this->options->get( 'ai_api_key_encrypted', '' ) ) && $this->options->get( 'ai_api_key_encrypted', '' ) !== '';
		$max_score      = class_exists( 'Meyvora_SEO_Analyzer' ) ? Meyvora_SEO_Analyzer::get_max_score() : 100;
		$results_pass   = array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'pass'; } );
		$results_warn   = array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'warning'; } );
		$results_fail   = array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'fail'; } );
		$status_label   = $status === 'good' ? __( 'Great!', 'meyvora-seo' ) : ( $status === 'okay' ? __( 'Almost There', 'meyvora-seo' ) : __( 'Needs Work', 'meyvora-seo' ) );
		?>
		<div class="meyvora-seo-panel" data-post-id="<?php echo (int) $post->ID; ?>">
			<ul class="meyvora-seo-tabs" role="tablist" aria-label="<?php esc_attr_e( 'SEO Settings', 'meyvora-seo' ); ?>">
				<li><button type="button" class="meyvora-seo-tab is-active" role="tab" aria-selected="true" aria-controls="meyvora-tab-general" id="meyvora-tab-btn-general" tabindex="0" data-tab="general"><?php esc_html_e( 'General', 'meyvora-seo' ); ?></button></li>
				<li><button type="button" class="meyvora-seo-tab" role="tab" aria-selected="false" aria-controls="meyvora-tab-social" id="meyvora-tab-btn-social" tabindex="-1" data-tab="social"><?php esc_html_e( 'Social', 'meyvora-seo' ); ?></button></li>
				<li><button type="button" class="meyvora-seo-tab" role="tab" aria-selected="false" aria-controls="meyvora-tab-preview" id="meyvora-tab-btn-preview" tabindex="-1" data-tab="preview"><?php esc_html_e( 'Social Preview', 'meyvora-seo' ); ?></button></li>
				<li><button type="button" class="meyvora-seo-tab" role="tab" aria-selected="false" aria-controls="meyvora-tab-schema" id="meyvora-tab-btn-schema" tabindex="-1" data-tab="schema"><?php esc_html_e( 'Schema', 'meyvora-seo' ); ?></button></li>
				<li><button type="button" class="meyvora-seo-tab" role="tab" aria-selected="false" aria-controls="meyvora-tab-advanced" id="meyvora-tab-btn-advanced" tabindex="-1" data-tab="advanced"><?php esc_html_e( 'Advanced', 'meyvora-seo' ); ?></button></li>
				<li><button type="button" class="meyvora-seo-tab" role="tab" aria-selected="false" aria-controls="meyvora-tab-score" id="meyvora-tab-btn-score" tabindex="-1" data-tab="score"><?php esc_html_e( 'Score', 'meyvora-seo' ); ?></button></li>
				<li><button type="button" class="meyvora-seo-tab" role="tab" aria-selected="false" aria-controls="meyvora-tab-competitor" id="meyvora-tab-btn-competitor" tabindex="-1" data-tab="competitor"><?php esc_html_e( 'Competitor', 'meyvora-seo' ); ?></button></li>
			</ul>

			<div id="meyvora-tab-general" class="meyvora-seo-tabpanel is-active" role="tabpanel" aria-labelledby="meyvora-tab-btn-general" tabindex="0">
				<div class="meyvora-field meyvora-focus-keywords-wrap">
					<label for="meyvora_seo_focus_keyword_input"><?php esc_html_e( 'Focus keywords', 'meyvora-seo' ); ?></label>
					<input type="hidden" id="meyvora_seo_focus_keyword" name="meyvora_seo_focus_keyword" value="<?php echo esc_attr( wp_json_encode( $focus_keywords ) ); ?>" />
					<div id="meyvora_focus_keywords_tags" class="meyvora-focus-keywords-tags" data-max="5">
						<?php foreach ( $focus_keywords as $kw ) : ?>
							<span class="mev-focus-pill" data-keyword="<?php echo esc_attr( $kw ); ?>"><?php echo esc_html( $kw ); ?> <button type="button" class="mev-focus-pill-remove" aria-label="<?php esc_attr_e( 'Remove', 'meyvora-seo' ); ?>">&times;</button></span>
						<?php endforeach; ?>
						<input type="text" id="meyvora_seo_focus_keyword_input" class="meyvora-focus-keyword-input" placeholder="<?php esc_attr_e( 'Add keyword (comma or Enter), max 5', 'meyvora-seo' ); ?>" autocomplete="off" />
					</div>
					<p class="description"><?php esc_html_e( 'Primary (first) keyword is used for scoring. Add up to 5 keywords.', 'meyvora-seo' ); ?></p>
					<button type="button" class="button button-small meyvora-ai-btn-keywords" id="meyvora_ai_btn_keywords"><?php esc_html_e( 'Suggest keywords', 'meyvora-seo' ); ?></button>
				</div>
				<?php
				$dataforseo_key = $this->options->get( 'dataforseo_api_key', '' );
				if ( is_string( $dataforseo_key ) && $dataforseo_key !== '' ) :
					?>
				<div class="meyvora-field meyvora-keyword-research-wrap" id="meyvora_keyword_research_wrap">
					<button type="button" class="button-link meyvora-keyword-research-toggle" id="meyvora_keyword_research_toggle" aria-expanded="false"><?php esc_html_e( 'Keyword Research', 'meyvora-seo' ); ?> <span aria-hidden="true">▼</span></button>
					<div class="meyvora-keyword-research-panel" id="meyvora_keyword_research_panel" hidden>
						<button type="button" class="button button-small" id="meyvora_keyword_research_btn"><?php esc_html_e( 'Research', 'meyvora-seo' ); ?></button>
						<div class="meyvora-keyword-research-result" id="meyvora_keyword_research_result"></div>
					</div>
				</div>
				<?php endif; ?>
				<div class="meyvora-field">
					<label for="meyvora_seo_title"><?php esc_html_e( 'SEO title', 'meyvora-seo' ); ?></label>
					<input type="text" id="meyvora_seo_title" name="meyvora_seo_title" value="<?php echo esc_attr( $title ); ?>" maxlength="70" />
					<div class="meyvora-progress-bar"><div id="meyvora_title_bar" class="meyvora-progress-bar-fill" style="width:<?php echo esc_attr( (string) min( 100, ( function_exists( 'mb_strlen' ) ? mb_strlen( $title ?: $post->post_title ) : strlen( $title ?: $post->post_title ) ) / 60 * 100 ) ); ?>%"></div></div>
					<div class="meyvora-field-row"><div id="meyvora_title_counter" class="meyvora-char-counter">0 / 60</div><button type="button" class="button button-small meyvora-ai-btn-title" id="meyvora_ai_btn_title"><?php esc_html_e( 'Generate with AI', 'meyvora-seo' ); ?></button></div>
				</div>
				<div class="meyvora-field">
					<label for="meyvora_seo_description"><?php esc_html_e( 'Meta description', 'meyvora-seo' ); ?></label>
					<textarea id="meyvora_seo_description" name="meyvora_seo_description" rows="3" maxlength="320"><?php echo esc_textarea( $description ); ?></textarea>
					<div class="meyvora-progress-bar"><div id="meyvora_desc_bar" class="meyvora-progress-bar-fill" style="width:<?php echo esc_attr( (string) min( 100, ( function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description ) ) / 160 * 100 ) ); ?>%"></div></div>
					<div class="meyvora-field-row"><div id="meyvora_desc_counter" class="meyvora-char-counter">0 / 160</div><button type="button" class="button button-small meyvora-ai-btn-desc" id="meyvora_ai_btn_desc"><?php esc_html_e( 'Generate with AI', 'meyvora-seo' ); ?></button></div>
				</div>
				<div class="meyvora-field meyvora-desc-ab-panel">
					<details class="mev-ab-details" style="margin-top:8px;">
						<summary style="cursor:pointer;font-weight:600;"><?php esc_html_e( 'A/B Test', 'meyvora-seo' ); ?></summary>
						<div class="mev-ab-content" style="margin-top:12px;">
							<button type="button" class="button button-small meyvora-ai-btn-desc-variants" id="meyvora_ai_btn_desc_variants"><?php esc_html_e( 'Generate 2 variants with AI', 'meyvora-seo' ); ?></button>
							<div class="meyvora-field" style="margin-top:10px;">
								<label for="meyvora_seo_desc_variant_a"><?php esc_html_e( 'Variant A', 'meyvora-seo' ); ?></label>
								<textarea id="meyvora_seo_desc_variant_a" name="meyvora_seo_desc_variant_a" rows="2" maxlength="320" style="width:100%;"><?php echo esc_textarea( is_string( $desc_variant_a ) ? $desc_variant_a : '' ); ?></textarea>
							</div>
							<div class="meyvora-field" style="margin-top:8px;">
								<label for="meyvora_seo_desc_variant_b"><?php esc_html_e( 'Variant B', 'meyvora-seo' ); ?></label>
								<textarea id="meyvora_seo_desc_variant_b" name="meyvora_seo_desc_variant_b" rows="2" maxlength="320" style="width:100%;"><?php echo esc_textarea( is_string( $desc_variant_b ) ? $desc_variant_b : '' ); ?></textarea>
							</div>
							<?php if ( $desc_ab_active !== 'a' && $desc_ab_active !== 'b' ) : ?>
								<input type="hidden" name="meyvora_seo_ab_start_now" id="meyvora_seo_ab_start_now" value="0" />
								<button type="button" class="button button-primary mev-ab-start-btn" id="meyvora_seo_ab_start_btn"><?php esc_html_e( 'Start A/B Test', 'meyvora-seo' ); ?></button>
							<?php else : ?>
								<?php
								$ab_start_ts = is_numeric( $desc_ab_start ) ? (int) $desc_ab_start : 0;
								$days_running = $ab_start_ts > 0 ? (int) floor( ( time() - $ab_start_ts ) / DAY_IN_SECONDS ) : 0;
								?>
								<?php /* translators: 1: variant letter A or B, 2: number of days the test has been running */ ?>
								<p style="margin:8px 0;font-size:13px;"><?php echo esc_html( sprintf( __( 'Current variant: %s', 'meyvora-seo' ), strtoupper( $desc_ab_active ) ) ); ?> · <?php echo esc_html( sprintf( _n( '%d day running', '%d days running', $days_running, 'meyvora-seo' ), $days_running ) ); ?></p>
								<button type="button" class="button button-small mev-ab-switch-btn" data-ab-action="switch" data-post-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'meyvora_seo_ab_test' ) ); ?>"><?php esc_html_e( 'Switch to variant A', 'meyvora-seo' ); ?></button>
								<button type="button" class="button button-small mev-ab-switch-btn" data-ab-action="switch" data-post-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'meyvora_seo_ab_test' ) ); ?>"><?php esc_html_e( 'Switch to variant B', 'meyvora-seo' ); ?></button>
								<button type="button" class="button button-small mev-ab-stop-btn" data-ab-action="stop" data-post-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'meyvora_seo_ab_test' ) ); ?>" data-adopt-variant="a"><?php esc_html_e( 'Stop test & adopt A', 'meyvora-seo' ); ?></button>
								<button type="button" class="button button-small mev-ab-stop-btn" data-ab-action="stop" data-post-id="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'meyvora_seo_ab_test' ) ); ?>" data-adopt-variant="b"><?php esc_html_e( 'Stop test & adopt B', 'meyvora-seo' ); ?></button>
							<?php endif; ?>
							<?php
							$ab_result = is_string( $desc_ab_result ) && $desc_ab_result !== '' ? json_decode( $desc_ab_result, true ) : null;
							if ( is_array( $ab_result ) && isset( $ab_result['winner'] ) ) :
								$winner = $ab_result['winner'];
								$a_ctr = isset( $ab_result['a_ctr'] ) ? (float) $ab_result['a_ctr'] : 0;
								$b_ctr = isset( $ab_result['b_ctr'] ) ? (float) $ab_result['b_ctr'] : 0;
								?>
								<div class="mev-ab-result" style="margin-top:12px;padding:10px;background:#f0fdf4;border-radius:6px;">
									<?php /* translators: %s: variant letter A or B */ ?>
									<strong><?php echo esc_html( sprintf( __( 'Winner: Variant %s', 'meyvora-seo' ), strtoupper( $winner ) ) ); ?></strong>
									<?php /* translators: 1: variant A CTR percent, 2: variant B CTR percent */ ?>
									<p style="margin:6px 0 0;font-size:13px;"><?php echo esc_html( sprintf( __( 'A: %1$s%% CTR · B: %2$s%% CTR', 'meyvora-seo' ), number_format_i18n( $a_ctr, 1 ), number_format_i18n( $b_ctr, 1 ) ) ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</details>
				</div>
				<?php
				if ( class_exists( 'Meyvora_SEO_Serp_Preview' ) ) {
					Meyvora_SEO_Serp_Preview::render( $post, array(
						'url'         => $snippet_url,
						'title'       => $snippet_title,
						'description' => $snippet_desc,
					) );
				}
				?>
				<div class="meyvora-field">
					<label for="meyvora_seo_canonical"><?php esc_html_e( 'Canonical URL', 'meyvora-seo' ); ?></label>
					<input type="url" id="meyvora_seo_canonical" name="meyvora_seo_canonical" value="<?php echo esc_url( (string) ( $canonical ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) ( $snippet_url ?? '' ) ); ?>" />
				</div>
				<div class="meyvora-field meyvora-secondary-keywords">
					<label><?php esc_html_e( 'Secondary keywords', 'meyvora-seo' ); ?></label>
					<?php foreach ( array( 0, 1, 2 ) as $i ) : ?>
						<input type="text" name="meyvora_seo_secondary_keyword_<?php echo (int) $i; ?>" value="<?php echo esc_attr( $secondary_keywords[ $i ] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Optional keyword', 'meyvora-seo' ); ?>" class="meyvora-sec-kw" data-idx="<?php echo (int) $i; ?>" />
					<?php endforeach; ?>
				</div>
				<?php do_action( 'meyvora_seo_meta_box_general_tab' ); ?>
			</div>

			<div id="meyvora-tab-social" class="meyvora-seo-tabpanel" role="tabpanel" aria-labelledby="meyvora-tab-btn-social" tabindex="-1" hidden data-snippet-url="<?php echo esc_url( (string) $snippet_url ); ?>">
				<div class="meyvora-field meyvora-social-snippet-preview-wrap">
					<label><?php esc_html_e( 'Social snippet preview', 'meyvora-seo' ); ?></label>
					<div class="meyvora-social-preview-cards">
						<div class="meyvora-preview-card-label"><?php esc_html_e( 'Facebook / Open Graph', 'meyvora-seo' ); ?></div>
						<div class="meyvora-fb-preview-card meyvora-social-fb-mock">
							<div class="meyvora-fb-preview-image">
								<img src="<?php echo $og_image_url ? esc_url( $og_image_url ) : ''; ?>" alt="" id="meyvora-fb-preview-img"<?php echo $og_image_url ? '' : ' style="display:none;"'; ?> />
								<span class="meyvora-fb-preview-placeholder" id="meyvora-fb-preview-placeholder"<?php echo $og_image_url ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'No image', 'meyvora-seo' ); ?></span>
							</div>
							<div class="meyvora-fb-preview-body">
								<div class="meyvora-fb-preview-title" id="meyvora-fb-preview-title"><?php echo esc_html( $og_title !== '' ? $og_title : $snippet_title ); ?></div>
								<div class="meyvora-fb-preview-desc" id="meyvora-fb-preview-desc"><?php echo esc_html( wp_trim_words( $og_desc !== '' ? $og_desc : $snippet_desc, 30 ) ); ?></div>
							</div>
							<div class="meyvora-fb-preview-domain-strip">
								<span class="meyvora-fb-preview-domain" id="meyvora-fb-preview-domain"><?php echo esc_html( $snippet_domain ); ?></span>
							</div>
						</div>
						<div class="meyvora-preview-card-label"><?php esc_html_e( 'X / Twitter (summary_large_image)', 'meyvora-seo' ); ?></div>
						<div class="meyvora-tw-preview-card meyvora-social-tw-mock">
							<div class="meyvora-tw-preview-image">
								<img src="<?php echo $tw_image_url ? esc_url( $tw_image_url ) : ( $og_image_url ? esc_url( $og_image_url ) : '' ); ?>" alt="" id="meyvora-tw-preview-img"<?php echo ( $tw_image_url || $og_image_url ) ? '' : ' style="display:none;"'; ?> />
								<span class="meyvora-tw-preview-placeholder" id="meyvora-tw-preview-placeholder"<?php echo ( $tw_image_url || $og_image_url ) ? ' style="display:none;"' : ''; ?>><?php esc_html_e( 'No image', 'meyvora-seo' ); ?></span>
							</div>
							<div class="meyvora-tw-preview-body">
								<div class="meyvora-tw-preview-title" id="meyvora-tw-preview-title"><?php echo esc_html( $twitter_title !== '' ? $twitter_title : ( $og_title !== '' ? $og_title : $snippet_title ) ); ?></div>
								<div class="meyvora-tw-preview-desc" id="meyvora-tw-preview-desc"><?php echo esc_html( wp_trim_words( $twitter_desc !== '' ? $twitter_desc : ( $og_desc !== '' ? $og_desc : $snippet_desc ), 30 ) ); ?></div>
								<div class="meyvora-tw-preview-domain" id="meyvora-tw-preview-domain"><?php echo esc_html( $snippet_domain ); ?></div>
							</div>
						</div>
					</div>
				</div>
				<div class="meyvora-field">
					<label for="meyvora_seo_og_title"><?php esc_html_e( 'OG title', 'meyvora-seo' ); ?></label>
					<input type="text" id="meyvora_seo_og_title" name="meyvora_seo_og_title" value="<?php echo esc_attr( $og_title ); ?>" maxlength="<?php echo (int) ( class_exists( 'Meyvora_SEO_Social_Preview' ) ? Meyvora_SEO_Social_Preview::OG_TITLE_MAX : 88 ); ?>" />
					<div class="meyvora-progress-bar"><div id="meyvora_og_title_bar" class="meyvora-progress-bar-fill" style="width:<?php echo esc_attr( (string) min( 100, ( function_exists( 'mb_strlen' ) ? mb_strlen( $og_title ) : strlen( $og_title ) ) / 88 * 100 ) ); ?>%"></div></div>
					<div id="meyvora_og_title_counter" class="meyvora-char-counter"><?php echo esc_html( (string) ( function_exists( 'mb_strlen' ) ? mb_strlen( $og_title ) : strlen( $og_title ) ) ); ?> / 88</div>
				</div>
				<div class="meyvora-field">
					<label for="meyvora_seo_og_description"><?php esc_html_e( 'OG description', 'meyvora-seo' ); ?></label>
					<textarea id="meyvora_seo_og_description" name="meyvora_seo_og_description" rows="2" maxlength="200"><?php echo esc_textarea( $og_desc ); ?></textarea>
					<div class="meyvora-progress-bar"><div id="meyvora_og_desc_bar" class="meyvora-progress-bar-fill" style="width:<?php echo esc_attr( (string) min( 100, ( function_exists( 'mb_strlen' ) ? mb_strlen( $og_desc ) : strlen( $og_desc ) ) / 200 * 100 ) ); ?>%"></div></div>
					<div id="meyvora_og_desc_counter" class="meyvora-char-counter"><?php echo esc_html( (string) ( function_exists( 'mb_strlen' ) ? mb_strlen( $og_desc ) : strlen( $og_desc ) ) ); ?> / 200</div>
				</div>
				<div class="meyvora-field">
					<label><?php esc_html_e( 'OG image', 'meyvora-seo' ); ?></label>
					<div class="meyvora-media-picker-wrap">
						<div class="meyvora-media-preview"><?php if ( $og_image_url ) { echo '<img src="' . esc_url( $og_image_url ) . '" alt="" />'; } ?></div>
						<button type="button" class="button meyvora-og-image-picker"><?php esc_html_e( 'Select image', 'meyvora-seo' ); ?></button>
						<input type="hidden" id="meyvora_seo_og_image" name="meyvora_seo_og_image" value="<?php echo esc_attr( $og_image ); ?>" />
					</div>
				</div>
				<div class="meyvora-field">
					<label><?php esc_html_e( 'Twitter title', 'meyvora-seo' ); ?></label>
					<input type="text" id="meyvora_seo_twitter_title" name="meyvora_seo_twitter_title" value="<?php echo esc_attr( $twitter_title ); ?>" />
				</div>
				<div class="meyvora-field">
					<label><?php esc_html_e( 'Twitter description', 'meyvora-seo' ); ?></label>
					<textarea id="meyvora_seo_twitter_description" name="meyvora_seo_twitter_description" rows="2"><?php echo esc_textarea( $twitter_desc ); ?></textarea>
				</div>
				<div class="meyvora-field">
					<label><?php esc_html_e( 'Twitter image', 'meyvora-seo' ); ?></label>
					<div class="meyvora-media-picker-wrap">
						<div class="meyvora-media-preview"><?php if ( $tw_image_url ) { echo '<img src="' . esc_url( $tw_image_url ) . '" alt="" />'; } ?></div>
						<button type="button" class="button meyvora-twitter-image-picker"><?php esc_html_e( 'Select image', 'meyvora-seo' ); ?></button>
						<input type="hidden" id="meyvora_seo_twitter_image" name="meyvora_seo_twitter_image" value="<?php echo esc_attr( $twitter_image ); ?>" />
					</div>
				</div>
			</div>

			<?php
			if ( class_exists( 'Meyvora_SEO_Social_Preview' ) ) {
				Meyvora_SEO_Social_Preview::render( $post, array(
					'snippet_url'        => $snippet_url,
					'snippet_title'      => $snippet_title,
					'snippet_desc'       => $snippet_desc,
					'og_title'           => $og_title,
					'og_desc'            => $og_desc,
					'og_image_url'       => $og_image_url,
					'twitter_title'      => $twitter_title,
					'twitter_desc'       => $twitter_desc,
					'twitter_image_url'  => $tw_image_url,
				) );
			}
			?>

			<div id="meyvora-tab-schema" class="meyvora-seo-tabpanel" role="tabpanel" aria-labelledby="meyvora-tab-btn-schema" tabindex="-1" hidden>
				<div class="meyvora-field">
					<label for="meyvora_seo_schema_type"><?php esc_html_e( 'Schema type', 'meyvora-seo' ); ?></label>
					<select id="meyvora_seo_schema_type" name="meyvora_seo_schema_type" class="mev-schema-type-select">
						<?php foreach ( $schema_types as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $schema_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<?php
				$howto_steps = isset( $schema_howto['steps'] ) && is_array( $schema_howto['steps'] ) ? $schema_howto['steps'] : array( array( 'name' => '', 'text' => '', 'image' => '' ) );
				$recipe_ingredients = isset( $schema_recipe['ingredients'] ) && is_array( $schema_recipe['ingredients'] ) ? $schema_recipe['ingredients'] : array( '' );
				$recipe_instructions = isset( $schema_recipe['instructions'] ) && is_array( $schema_recipe['instructions'] ) ? $schema_recipe['instructions'] : array( '' );
				?>
				<div class="mev-schema-fields mev-schema-fields--howto" data-schema-type="HowTo" style="display:none;">
					<p class="mev-schema-panel-actions">
						<label><input type="checkbox" class="mev-ai-replace-schema" /> <?php esc_html_e( 'Replace existing values', 'meyvora-seo' ); ?></label>
						<button type="button" class="button button-small mev-ai-prefill-schema" data-schema-type="HowTo" data-post-id="<?php echo (int) $post->ID; ?>" <?php echo $ai_available ? '' : ' disabled title="' . esc_attr__( 'Configure AI API key in Settings', 'meyvora-seo' ) . '"'; ?>><?php esc_html_e( 'Pre-fill with AI', 'meyvora-seo' ); ?></button>
						<span class="mev-schema-prefill-spinner spinner" style="display:none;" aria-hidden="true"></span>
					</p>
					<div class="meyvora-field"><label><?php esc_html_e( 'Name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_howto[name]" value="<?php echo esc_attr( $schema_howto['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Total time', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_howto[totalTime]" value="<?php echo esc_attr( $schema_howto['totalTime'] ?? '' ); ?>" placeholder="PT30M" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Estimated cost', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_howto[estimatedCost]" value="<?php echo esc_attr( $schema_howto['estimatedCost'] ?? '' ); ?>" /></div>
					<div class="meyvora-field mev-schema-repeatable">
						<label><?php esc_html_e( 'Steps', 'meyvora-seo' ); ?></label>
						<div class="mev-schema-steps-wrap">
							<?php foreach ( $howto_steps as $i => $step ) : ?>
								<div class="mev-schema-step">
									<input type="text" name="meyvora_seo_schema_howto[steps][<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( $step['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Step name', 'meyvora-seo' ); ?>" />
									<textarea name="meyvora_seo_schema_howto[steps][<?php echo (int) $i; ?>][text]" rows="2" placeholder="<?php esc_attr_e( 'Step text', 'meyvora-seo' ); ?>"><?php echo esc_textarea( $step['text'] ?? '' ); ?></textarea>
									<div class="meyvora-media-picker-wrap"><input type="hidden" name="meyvora_seo_schema_howto[steps][<?php echo (int) $i; ?>][image]" value="<?php echo esc_attr( $step['image'] ?? '' ); ?>" class="mev-step-image-id" /><button type="button" class="button mev-picker-step-image"><?php esc_html_e( 'Image', 'meyvora-seo' ); ?></button></div>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button mev-add-schema-step"><?php esc_html_e( 'Add step', 'meyvora-seo' ); ?></button>
					</div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--faqpage" data-schema-type="FAQPage" style="display:none;">
					<div class="meyvora-field mev-schema-repeatable">
						<label><?php esc_html_e( 'Schema: FAQ', 'meyvora-seo' ); ?></label>
						<div class="mev-schema-faq-wrap">
							<?php
							if ( empty( $faq_pairs ) ) {
								$faq_pairs = array( array( 'question' => '', 'answer' => '' ) );
							}
							foreach ( $faq_pairs as $i => $pair ) :
								$q = isset( $pair['question'] ) ? $pair['question'] : '';
								$a = isset( $pair['answer'] ) ? $pair['answer'] : '';
								?>
								<div class="mev-schema-faq-row">
									<input type="text" name="meyvora_seo_faq[<?php echo (int) $i; ?>][question]" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Question', 'meyvora-seo' ); ?>" />
									<textarea name="meyvora_seo_faq[<?php echo (int) $i; ?>][answer]" rows="2" placeholder="<?php esc_attr_e( 'Answer', 'meyvora-seo' ); ?>"><?php echo esc_textarea( $a ); ?></textarea>
									<button type="button" class="button button-small mev-remove-faq-row" aria-label="<?php esc_attr_e( 'Remove', 'meyvora-seo' ); ?>">×</button>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="mev-faq-buttons">
							<button type="button" class="button mev-add-faq-pair"><?php esc_html_e( 'Add question', 'meyvora-seo' ); ?></button>
							<button type="button" class="button mev-ai-generate-faq"
								data-post-id="<?php echo (int) $post->ID; ?>"
								<?php echo $ai_available ? '' : ' disabled title="' . esc_attr__( 'Configure AI API key in Settings', 'meyvora-seo' ) . '"'; ?>
							><?php esc_html_e( 'Generate FAQ with AI', 'meyvora-seo' ); ?></button>
							<span class="mev-faq-spinner spinner" style="display:none;vertical-align:middle;" aria-hidden="true"></span>
						</p>
					</div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--recipe" data-schema-type="Recipe" style="display:none;">
					<p class="mev-schema-panel-actions">
						<label><input type="checkbox" class="mev-ai-replace-schema" /> <?php esc_html_e( 'Replace existing values', 'meyvora-seo' ); ?></label>
						<button type="button" class="button button-small mev-ai-prefill-schema" data-schema-type="Recipe" data-post-id="<?php echo (int) $post->ID; ?>" <?php echo $ai_available ? '' : ' disabled title="' . esc_attr__( 'Configure AI API key in Settings', 'meyvora-seo' ) . '"'; ?>><?php esc_html_e( 'Pre-fill with AI', 'meyvora-seo' ); ?></button>
						<span class="mev-schema-prefill-spinner spinner" style="display:none;" aria-hidden="true"></span>
					</p>
					<div class="meyvora-field"><label><?php esc_html_e( 'Recipe name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_recipe[recipeName]" value="<?php echo esc_attr( $schema_recipe['recipeName'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Recipe yield', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_recipe[recipeYield]" value="<?php echo esc_attr( $schema_recipe['recipeYield'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Cook time', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_recipe[cookTime]" value="<?php echo esc_attr( $schema_recipe['cookTime'] ?? '' ); ?>" placeholder="PT30M" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Prep time', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_recipe[prepTime]" value="<?php echo esc_attr( $schema_recipe['prepTime'] ?? '' ); ?>" placeholder="PT15M" /></div>
					<div class="meyvora-field mev-schema-repeatable"><label><?php esc_html_e( 'Ingredients', 'meyvora-seo' ); ?></label><div class="mev-schema-ingredients-wrap"><?php foreach ( $recipe_ingredients as $i => $v ) : ?><input type="text" name="meyvora_seo_schema_recipe[ingredients][]" value="<?php echo esc_attr( is_string( $v ) ? $v : '' ); ?>" /><?php endforeach; ?></div><button type="button" class="button mev-add-ingredient"><?php esc_html_e( 'Add', 'meyvora-seo' ); ?></button></div>
					<div class="meyvora-field mev-schema-repeatable"><label><?php esc_html_e( 'Instructions', 'meyvora-seo' ); ?></label><div class="mev-schema-instructions-wrap"><?php foreach ( $recipe_instructions as $i => $v ) : ?><textarea name="meyvora_seo_schema_recipe[instructions][]" rows="1"><?php echo esc_textarea( is_string( $v ) ? $v : '' ); ?></textarea><?php endforeach; ?></div><button type="button" class="button mev-add-instruction"><?php esc_html_e( 'Add', 'meyvora-seo' ); ?></button></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Nutrition: calories', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_recipe[nutrition][calories]" value="<?php echo esc_attr( $schema_recipe['nutrition']['calories'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Nutrition: serving size', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_recipe[nutrition][servingSize]" value="<?php echo esc_attr( $schema_recipe['nutrition']['servingSize'] ?? '' ); ?>" /></div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--event" data-schema-type="Event" style="display:none;">
					<p class="mev-schema-panel-actions">
						<label><input type="checkbox" class="mev-ai-replace-schema" /> <?php esc_html_e( 'Replace existing values', 'meyvora-seo' ); ?></label>
						<button type="button" class="button button-small mev-ai-prefill-schema" data-schema-type="Event" data-post-id="<?php echo (int) $post->ID; ?>" <?php echo $ai_available ? '' : ' disabled title="' . esc_attr__( 'Configure AI API key in Settings', 'meyvora-seo' ) . '"'; ?>><?php esc_html_e( 'Pre-fill with AI', 'meyvora-seo' ); ?></button>
						<span class="mev-schema-prefill-spinner spinner" style="display:none;" aria-hidden="true"></span>
					</p>
					<div class="meyvora-field"><label><?php esc_html_e( 'Name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[name]" value="<?php echo esc_attr( $schema_event['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Start date', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[startDate]" value="<?php echo esc_attr( $schema_event['startDate'] ?? '' ); ?>" placeholder="2025-01-15T19:00" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'End date', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[endDate]" value="<?php echo esc_attr( $schema_event['endDate'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Location name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[location][name]" value="<?php echo esc_attr( $schema_event['location']['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Location address', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[location][address]" value="<?php echo esc_attr( $schema_event['location']['address'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Organizer', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[organizer]" value="<?php echo esc_attr( $schema_event['organizer'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Event status', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[eventStatus]" value="<?php echo esc_attr( $schema_event['eventStatus'] ?? '' ); ?>" placeholder="EventScheduled" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Event attendance mode', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[eventAttendanceMode]" value="<?php echo esc_attr( $schema_event['eventAttendanceMode'] ?? '' ); ?>" placeholder="OfflineEventAttendanceMode" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Offers: price', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[offers][price]" value="<?php echo esc_attr( $schema_event['offers']['price'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Offers: currency', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_event[offers][currency]" value="<?php echo esc_attr( $schema_event['offers']['currency'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Offers: URL', 'meyvora-seo' ); ?></label><input type="url" name="meyvora_seo_schema_event[offers][url]" value="<?php echo esc_attr( $schema_event['offers']['url'] ?? '' ); ?>" /></div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--course" data-schema-type="Course" style="display:none;">
					<div class="meyvora-field"><label><?php esc_html_e( 'Name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_course[name]" value="<?php echo esc_attr( $schema_course['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Description', 'meyvora-seo' ); ?></label><textarea name="meyvora_seo_schema_course[description]" rows="3"><?php echo esc_textarea( $schema_course['description'] ?? '' ); ?></textarea></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Provider (organization)', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_course[provider]" value="<?php echo esc_attr( $schema_course['provider'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Course code', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_course[courseCode]" value="<?php echo esc_attr( $schema_course['courseCode'] ?? '' ); ?>" /></div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--jobposting" data-schema-type="JobPosting" style="display:none;">
					<p class="mev-schema-panel-actions">
						<label><input type="checkbox" class="mev-ai-replace-schema" /> <?php esc_html_e( 'Replace existing values', 'meyvora-seo' ); ?></label>
						<button type="button" class="button button-small mev-ai-prefill-schema" data-schema-type="JobPosting" data-post-id="<?php echo (int) $post->ID; ?>" <?php echo $ai_available ? '' : ' disabled title="' . esc_attr__( 'Configure AI API key in Settings', 'meyvora-seo' ) . '"'; ?>><?php esc_html_e( 'Pre-fill with AI', 'meyvora-seo' ); ?></button>
						<span class="mev-schema-prefill-spinner spinner" style="display:none;" aria-hidden="true"></span>
					</p>
					<div class="meyvora-field"><label><?php esc_html_e( 'Title', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[title]" value="<?php echo esc_attr( $schema_job['title'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Description', 'meyvora-seo' ); ?></label><textarea name="meyvora_seo_schema_jobposting[description]" rows="3"><?php echo esc_textarea( $schema_job['description'] ?? '' ); ?></textarea></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Date posted', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[datePosted]" value="<?php echo esc_attr( $schema_job['datePosted'] ?? '' ); ?>" placeholder="2025-01-01" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Valid through', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[validThrough]" value="<?php echo esc_attr( $schema_job['validThrough'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Employment type', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[employmentType]" value="<?php echo esc_attr( $schema_job['employmentType'] ?? '' ); ?>" placeholder="FULL_TIME" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Hiring org: name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[hiringOrganization][name]" value="<?php echo esc_attr( $schema_job['hiringOrganization']['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Hiring org: URL', 'meyvora-seo' ); ?></label><input type="url" name="meyvora_seo_schema_jobposting[hiringOrganization][url]" value="<?php echo esc_attr( $schema_job['hiringOrganization']['url'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Job location: street', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[jobLocation][streetAddress]" value="<?php echo esc_attr( $schema_job['jobLocation']['streetAddress'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Job location: city', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[jobLocation][city]" value="<?php echo esc_attr( $schema_job['jobLocation']['city'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Job location: country', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_jobposting[jobLocation][country]" value="<?php echo esc_attr( $schema_job['jobLocation']['country'] ?? '' ); ?>" /></div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--softwareapplication" data-schema-type="SoftwareApplication" style="display:none;">
					<div class="meyvora-field"><label><?php esc_html_e( 'Name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_software[name]" value="<?php echo esc_attr( $schema_software['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Application category', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_software[applicationCategory]" value="<?php echo esc_attr( $schema_software['applicationCategory'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Operating system', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_software[operatingSystem]" value="<?php echo esc_attr( $schema_software['operatingSystem'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Offers: price', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_software[offers][price]" value="<?php echo esc_attr( $schema_software['offers']['price'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Rating value', 'meyvora-seo' ); ?></label><input type="number" step="0.1" name="meyvora_seo_schema_software[aggregateRating][ratingValue]" value="<?php echo esc_attr( $schema_software['aggregateRating']['ratingValue'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Review count', 'meyvora-seo' ); ?></label><input type="number" name="meyvora_seo_schema_software[aggregateRating][reviewCount]" value="<?php echo esc_attr( $schema_software['aggregateRating']['reviewCount'] ?? '' ); ?>" /></div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--review" data-schema-type="Review" style="display:none;">
					<div class="meyvora-field"><label><?php esc_html_e( 'Item reviewed: name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_review[itemReviewed][name]" value="<?php echo esc_attr( $schema_review['itemReviewed']['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Item reviewed: type', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_review[itemReviewed][type]" value="<?php echo esc_attr( $schema_review['itemReviewed']['type'] ?? '' ); ?>" placeholder="Product" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Rating value', 'meyvora-seo' ); ?></label><input type="number" step="0.1" name="meyvora_seo_schema_review[reviewRating][ratingValue]" value="<?php echo esc_attr( $schema_review['reviewRating']['ratingValue'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Best rating', 'meyvora-seo' ); ?></label><input type="number" step="0.1" name="meyvora_seo_schema_review[reviewRating][bestRating]" value="<?php echo esc_attr( $schema_review['reviewRating']['bestRating'] ?? '5' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Worst rating', 'meyvora-seo' ); ?></label><input type="number" step="0.1" name="meyvora_seo_schema_review[reviewRating][worstRating]" value="<?php echo esc_attr( $schema_review['reviewRating']['worstRating'] ?? '1' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Author', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_review[author]" value="<?php echo esc_attr( $schema_review['author'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Review body', 'meyvora-seo' ); ?></label><textarea name="meyvora_seo_schema_review[reviewBody]" rows="3"><?php echo esc_textarea( $schema_review['reviewBody'] ?? '' ); ?></textarea></div>
				</div>
				<div class="mev-schema-fields mev-schema-fields--book" data-schema-type="Book" style="display:none;">
					<div class="meyvora-field"><label><?php esc_html_e( 'Name', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_book[name]" value="<?php echo esc_attr( $schema_book['name'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Author', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_book[author]" value="<?php echo esc_attr( $schema_book['author'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Number of pages', 'meyvora-seo' ); ?></label><input type="number" name="meyvora_seo_schema_book[numberOfPages]" value="<?php echo esc_attr( $schema_book['numberOfPages'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Publisher', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_book[publisher]" value="<?php echo esc_attr( $schema_book['publisher'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'ISBN', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_book[isbn]" value="<?php echo esc_attr( $schema_book['isbn'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Book format', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_book[bookFormat]" value="<?php echo esc_attr( $schema_book['bookFormat'] ?? '' ); ?>" placeholder="Hardcover" /></div>
				</div>
				<?php if ( ! function_exists( 'is_product' ) ) : ?>
				<div class="mev-schema-fields mev-schema-fields--product" data-schema-type="Product" style="display:none;">
					<div class="meyvora-field"><label><?php esc_html_e( 'Price', 'meyvora-seo' ); ?></label><input type="number" step="0.01" name="meyvora_seo_schema_product[price]" value="<?php echo esc_attr( $schema_product['price'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Currency', 'meyvora-seo' ); ?></label>
						<select name="meyvora_seo_schema_product[currency]">
							<?php
							$currencies = array( 'USD', 'EUR', 'GBP', 'JPY', 'CAD', 'AUD', 'CHF', 'INR', 'CNY', 'MXN', 'BRL' );
							$curr_val   = $schema_product['currency'] ?? 'USD';
							foreach ( $currencies as $code ) :
								?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $curr_val, $code ); ?>><?php echo esc_html( $code ); ?></option><?php
							endforeach;
							?>
						</select>
					</div>
					<div class="meyvora-field"><label><?php esc_html_e( 'Brand', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_product[brand]" value="<?php echo esc_attr( $schema_product['brand'] ?? '' ); ?>" /></div>
					<div class="meyvora-field"><label><?php esc_html_e( 'GTIN', 'meyvora-seo' ); ?></label><input type="text" name="meyvora_seo_schema_product[gtin]" value="<?php echo esc_attr( $schema_product['gtin'] ?? '' ); ?>" placeholder="UPC, EAN, etc." /></div>
				</div>
				<?php endif; ?>
			</div>

			<div id="meyvora-tab-advanced" class="meyvora-seo-tabpanel" role="tabpanel" aria-labelledby="meyvora-tab-btn-advanced" tabindex="-1" hidden>
				<div class="meyvora-field">
					<label><?php esc_html_e( 'Robots', 'meyvora-seo' ); ?></label>
					<div class="meyvora-robots-options">
						<label><input type="checkbox" id="meyvora_seo_noindex" name="meyvora_seo_noindex" value="1" <?php checked( $noindex ); ?> /> <?php esc_html_e( 'Noindex', 'meyvora-seo' ); ?></label>
						<label><input type="checkbox" id="meyvora_seo_nofollow" name="meyvora_seo_nofollow" value="1" <?php checked( $nofollow ); ?> /> <?php esc_html_e( 'Nofollow', 'meyvora-seo' ); ?></label>
						<label><input type="checkbox" id="meyvora_seo_noodp" name="meyvora_seo_noodp" value="1" <?php checked( $noodp ); ?> /> <?php esc_html_e( 'Noodp', 'meyvora-seo' ); ?></label>
						<label><input type="checkbox" id="meyvora_seo_noarchive" name="meyvora_seo_noarchive" value="1" <?php checked( $noarchive ); ?> /> <?php esc_html_e( 'Noarchive', 'meyvora-seo' ); ?></label>
						<p class="description" style="margin:0 0 6px 0;"><?php esc_html_e( 'Prevent Google from showing a cached copy.', 'meyvora-seo' ); ?></p>
						<label><input type="checkbox" id="meyvora_seo_nosnippet" name="meyvora_seo_nosnippet" value="1" <?php checked( $nosnippet ); ?> /> <?php esc_html_e( 'Nosnippet', 'meyvora-seo' ); ?></label>
						<p class="description" style="margin:0 0 6px 0;"><?php esc_html_e( 'Prevent Google from showing a text snippet in results.', 'meyvora-seo' ); ?></p>
						<label style="display:flex;align-items:center;gap:6px;"><?php esc_html_e( 'Max snippet:', 'meyvora-seo' ); ?> <input type="number" id="meyvora_seo_max_snippet" name="meyvora_seo_max_snippet" value="<?php echo esc_attr( $max_snippet >= -1 ? $max_snippet : '' ); ?>" min="-1" max="999" style="width:70px;" <?php echo $nosnippet ? ' disabled="disabled"' : ''; ?> /></label>
						<p class="description" style="margin:0 0 6px 0;"><?php esc_html_e( 'Max characters in snippet (-1 = no limit, 0 = no snippet). Only applies when Nosnippet is off.', 'meyvora-seo' ); ?></p>
						<label style="display:flex;align-items:center;gap:6px;"><?php esc_html_e( 'Max image preview:', 'meyvora-seo' ); ?>
							<select id="meyvora_seo_max_image_preview" name="meyvora_seo_max_image_preview">
								<option value="none" <?php selected( $max_image_preview, 'none' ); ?>><?php esc_html_e( 'None', 'meyvora-seo' ); ?></option>
								<option value="standard" <?php selected( $max_image_preview, 'standard' ); ?>><?php esc_html_e( 'Standard', 'meyvora-seo' ); ?></option>
								<option value="large" <?php selected( $max_image_preview, 'large' ); ?>><?php esc_html_e( 'Large', 'meyvora-seo' ); ?></option>
							</select>
						</label>
						<label style="display:flex;align-items:center;gap:6px;"><?php esc_html_e( 'Max video preview:', 'meyvora-seo' ); ?> <input type="number" id="meyvora_seo_max_video_preview" name="meyvora_seo_max_video_preview" value="<?php echo esc_attr( $max_video_preview ); ?>" min="-1" max="999" style="width:70px;" /></label>
						<p class="description" style="margin:0 0 0 0;"><?php esc_html_e( '-1 = unlimited seconds.', 'meyvora-seo' ); ?></p>
					</div>
					<script>
					(function(){
						var nosnippet = document.getElementById('meyvora_seo_nosnippet');
						var maxSnippet = document.getElementById('meyvora_seo_max_snippet');
						if (nosnippet && maxSnippet) {
							function toggle() { maxSnippet.disabled = nosnippet.checked; }
							nosnippet.addEventListener('change', toggle);
						}
					})();
					document.addEventListener('click', function(e) {
						var btn = e.target.closest('[data-ab-action]');
						if (!btn) return;
						var action = btn.dataset.abAction;
						var postId = btn.dataset.postId;
						var nonce  = btn.dataset.nonce;
						var adoptVariant = btn.dataset.adoptVariant || 'a';
						var ajaxAction = action === 'switch'
							? 'meyvora_seo_ab_switch'
							: 'meyvora_seo_ab_stop';
						btn.disabled = true;
						var fd = new FormData();
						fd.append('action', ajaxAction);
						fd.append('nonce', nonce);
						fd.append('post_id', postId);
						if (action === 'stop') { fd.append('adopt_variant', adoptVariant); }
						fetch(window.ajaxurl || '/wp-admin/admin-ajax.php', {
							method: 'POST', body: fd, credentials: 'same-origin'
						}).then(function(r){ return r.json(); })
						.then(function(res){
							if (res.success) {
								alert(res.data.message);
								window.location.reload();
							} else {
								btn.disabled = false;
								alert(res.data && res.data.message ? res.data.message : 'Error.');
							}
						})
						.catch(function(){ btn.disabled = false; });
					});
					</script>
				</div>
				<div class="meyvora-field">
					<label for="meyvora_seo_breadcrumb_title"><?php esc_html_e( 'Breadcrumb title override', 'meyvora-seo' ); ?></label>
					<input type="text" id="meyvora_seo_breadcrumb_title" name="meyvora_seo_breadcrumb_title" value="<?php echo esc_attr( $breadcrumb_title ); ?>" />
				</div>
				<div class="meyvora-field">
					<label><input type="checkbox" id="meyvora_seo_cornerstone" name="meyvora_seo_cornerstone" value="1" <?php checked( $cornerstone ); ?> /> <?php esc_html_e( 'Mark as cornerstone content', 'meyvora-seo' ); ?></label>
				</div>
				<div class="meyvora-field">
					<label for="meyvora_seo_sitemap_priority"><?php esc_html_e( 'Sitemap priority', 'meyvora-seo' ); ?></label>
					<select id="meyvora_seo_sitemap_priority" name="meyvora_seo_sitemap_priority">
						<option value=""><?php esc_html_e( 'Auto (based on age)', 'meyvora-seo' ); ?></option>
						<?php foreach ( array( '1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1', '0.0' ) as $p ) : ?>
							<option value="<?php echo esc_attr( $p ); ?>" <?php selected( get_post_meta( $post->ID, MEYVORA_SEO_META_SITEMAP_PRIORITY, true ), $p ); ?>><?php echo esc_html( $p ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Override the auto-calculated sitemap priority for this URL.', 'meyvora-seo' ); ?></p>
				</div>
				<div class="meyvora-field">
					<label for="meyvora_seo_sitemap_changefreq"><?php esc_html_e( 'Sitemap change frequency', 'meyvora-seo' ); ?></label>
					<select id="meyvora_seo_sitemap_changefreq" name="meyvora_seo_sitemap_changefreq">
						<option value=""><?php esc_html_e( 'Auto (based on age)', 'meyvora-seo' ); ?></option>
						<?php foreach ( array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' ) as $f ) : ?>
							<option value="<?php echo esc_attr( $f ); ?>" <?php selected( get_post_meta( $post->ID, MEYVORA_SEO_META_SITEMAP_CHANGEFREQ, true ), $f ); ?>><?php echo esc_html( ucfirst( $f ) ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Override the auto-calculated change frequency for this URL.', 'meyvora-seo' ); ?></p>
				</div>
			</div>

			<div id="meyvora-tab-score" class="meyvora-seo-tabpanel" role="tabpanel" aria-labelledby="meyvora-tab-btn-score" tabindex="-1" hidden>
				<div class="mev-score-header">
					<svg class="mev-gauge" width="90" height="90" viewBox="0 0 90 90" id="meyvora_gauge_svg">
						<circle class="mev-gauge-track" cx="45" cy="45" r="37" stroke-width="8"/>
						<circle class="mev-gauge-fill mev-gauge-fill--<?php echo esc_attr( $status ); ?>"
							id="meyvora_gauge_fill"
							cx="45" cy="45" r="37" stroke-width="8"
							stroke-dasharray="232.5"
							stroke-dashoffset="<?php echo esc_attr( 232.5 - ( $score / 100 ) * 232.5 ); ?>"/>
						<text class="mev-gauge-number" id="meyvora_gauge_value" x="45" y="43"><?php echo esc_html( (string) (int) $score ); ?></text>
						<text class="mev-gauge-label-text" x="45" y="57">/100</text>
					</svg>
					<div class="mev-score-info">
						<div class="mev-score-status-label" id="meyvora_score_badge">
							<?php echo esc_html( $status === 'good' ? __( 'Great!', 'meyvora-seo' ) : ( $status === 'okay' ? __( 'Almost There', 'meyvora-seo' ) : __( 'Needs Work', 'meyvora-seo' ) ) ); ?>
						</div>
						<div class="mev-score-status-sub"><?php printf( /* translators: 1: current score, 2: maximum score */ esc_html__( '%1$d / %2$d points', 'meyvora-seo' ), (int) $score, (int) $max_score ); ?></div>
						<div class="mev-score-meta-row">
							<?php $fail_c = count( array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'fail'; } ) ); ?>
							<?php $warn_c = count( array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'warning'; } ) ); ?>
							<?php $pass_c = count( array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'pass'; } ) ); ?>
							<?php if ( $fail_c > 0 ) : ?><span class="mev-badge mev-badge--red"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php echo (int) $fail_c; ?> <?php esc_html_e( 'issues', 'meyvora-seo' ); ?></span><?php endif; ?>
							<?php if ( $warn_c > 0 ) : ?><span class="mev-badge mev-badge--orange"><?php echo wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php echo (int) $warn_c; ?> <?php esc_html_e( 'warnings', 'meyvora-seo' ); ?></span><?php endif; ?>
							<span class="mev-badge mev-badge--green"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php echo (int) $pass_c; ?> <?php esc_html_e( 'passed', 'meyvora-seo' ); ?></span>
						</div>
					</div>
				</div>
				<?php
				$cache = get_post_meta( $post->ID, MEYVORA_SEO_META_ANALYSIS_CACHE, true );
				$ts_data = is_string( $cache ) ? json_decode( $cache, true ) : array();
				$ts = isset( $ts_data['timestamp'] ) ? (int) $ts_data['timestamp'] : 0;
				if ( $ts > 0 ) {
					echo '<p class="mev-last-analyzed">' . esc_html( sprintf(
						/* translators: %s: human-readable time difference, e.g. "5 minutes ago" */
						__( 'Last analyzed: %s', 'meyvora-seo' ),
						human_time_diff( $ts, time() ) . ' ' . __( 'ago', 'meyvora-seo' )
					) ) . '</p>';
				}
				?>
				<button type="button" class="button button-secondary meyvora-ai-btn-improve" id="meyvora_ai_btn_improve"><?php esc_html_e( 'Improve for SEO', 'meyvora-seo' ); ?></button>
				<div id="meyvora_ai_improve_panel" class="meyvora-ai-improve-panel" aria-hidden="true"></div>

				<div id="meyvora_ai_assistant_panel" class="meyvora-ai-assistant-panel">
					<h4 class="meyvora-ai-assistant-title"><?php esc_html_e( 'AI Assistant', 'meyvora-seo' ); ?></h4>
					<div class="meyvora-ai-assistant-row">
						<label for="meyvora_ai_assistant_mode" class="screen-reader-text"><?php esc_html_e( 'Mode', 'meyvora-seo' ); ?></label>
						<select id="meyvora_ai_assistant_mode">
							<option value="outline"><?php esc_html_e( 'Outline', 'meyvora-seo' ); ?></option>
							<option value="expand_paragraph"><?php esc_html_e( 'Expand', 'meyvora-seo' ); ?></option>
							<option value="rewrite_intro"><?php esc_html_e( 'Rewrite Intro', 'meyvora-seo' ); ?></option>
							<option value="improve_readability"><?php esc_html_e( 'Readability', 'meyvora-seo' ); ?></option>
							<option value="check_tone"><?php esc_html_e( 'Tone Check', 'meyvora-seo' ); ?></option>
						</select>
					</div>
					<div class="meyvora-ai-assistant-row">
						<label for="meyvora_ai_assistant_input" class="screen-reader-text"><?php esc_html_e( 'Input', 'meyvora-seo' ); ?></label>
						<textarea id="meyvora_ai_assistant_input" rows="4" placeholder="<?php esc_attr_e( 'Paste or type content…', 'meyvora-seo' ); ?>"></textarea>
					</div>
					<div class="meyvora-ai-assistant-row">
						<button type="button" class="button button-primary" id="meyvora_ai_assistant_generate"><?php esc_html_e( 'Generate', 'meyvora-seo' ); ?></button>
					</div>
					<div id="meyvora_ai_assistant_result" class="meyvora-ai-assistant-result" aria-live="polite" style="display:none;">
						<div class="meyvora-ai-assistant-result-content"></div>
						<div class="meyvora-ai-assistant-result-actions">
							<button type="button" class="button button-small" id="meyvora_ai_assistant_insert"><?php esc_html_e( 'Insert into post', 'meyvora-seo' ); ?></button>
							<button type="button" class="button button-small" id="meyvora_ai_assistant_copy"><?php esc_html_e( 'Copy', 'meyvora-seo' ); ?></button>
						</div>
					</div>
				</div>

			<div id="meyvora-tab-competitor" class="meyvora-seo-tabpanel meyvora-competitor-tabpanel" role="tabpanel" aria-labelledby="meyvora-tab-btn-competitor" tabindex="-1" hidden>
				<style>.meyvora-competitor-tabpanel .mev-comp-row{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:6px 0;border-bottom:1px solid var(--mev-gray-100);}.meyvora-competitor-tabpanel .mev-comp-row:last-child{border-bottom:0;}.meyvora-competitor-tabpanel .mev-comp-label{font-weight:600;color:var(--mev-gray-700);min-width:100px;font-size:12px;}.meyvora-competitor-tabpanel .mev-comp-value{flex:1;word-break:break-word;font-size:12px;}.meyvora-competitor-tabpanel .mev-comp-value.mev-stronger{background:rgba(220,38,38,0.08);color:var(--mev-danger);padding:2px 6px;border-radius:4px;}.meyvora-competitor-tabpanel .mev-comp-value.mev-weaker{background:rgba(5,150,105,0.08);color:var(--mev-success);padding:2px 6px;border-radius:4px;}.meyvora-competitor-tabpanel .mev-comp-list,.meyvora-competitor-tabpanel .mev-comp-og-list{margin:0;padding-left:18px;font-size:12px;}.meyvora-competitor-tabpanel .mev-comp-og-list{padding-left:0;list-style:none;}</style>
				<p class="description" style="margin-bottom:12px;"><?php esc_html_e( 'Compare this post with a competitor URL. Enter the URL below or use the full Competitor page under Meyvora SEO.', 'meyvora-seo' ); ?></p>
				<div class="meyvora-competitor-in-metabox">
					<div class="meyvora-field" style="margin-bottom:12px;">
						<label for="mev-metabox-competitor-url"><?php esc_html_e( 'Competitor URL', 'meyvora-seo' ); ?></label>
						<input type="url" id="mev-metabox-competitor-url" class="large-text" placeholder="https://competitor.com/page" style="width:100%;max-width:400px;" />
					</div>
					<button type="button" class="button button-primary mev-competitor-analyze-btn" id="mev-metabox-competitor-analyze"><?php esc_html_e( 'Analyze', 'meyvora-seo' ); ?></button>
					<div id="mev-metabox-competitor-error" class="mev-competitor-error" style="display:none;margin-top:12px;color:var(--mev-danger);"></div>
					<div id="mev-metabox-competitor-results" class="mev-competitor-results" style="display:none;margin-top:16px;">
						<div class="mev-competitor-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
							<div class="mev-col mev-card">
								<div class="mev-card-body">
									<h3 style="margin:0 0 8px;font-size:14px;"><?php esc_html_e( 'Competitor', 'meyvora-seo' ); ?></h3>
									<div id="mev-metabox-competitor-data"></div>
								</div>
							</div>
							<div class="mev-col mev-card">
								<div class="mev-card-body">
									<h3 style="margin:0 0 8px;font-size:14px;"><?php esc_html_e( 'Your post', 'meyvora-seo' ); ?></h3>
									<div id="mev-metabox-ours-data"></div>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>

				<?php if ( ! empty( $results ) ) : ?>
				<ul class="mev-checklist" id="meyvora_seo_checklist">

					<?php
					$fails = array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'fail'; } );
					if ( ! empty( $fails ) ) :
					?>
					<li class="mev-checklist-section">
						<div class="mev-checklist-section-title mev-checklist-section-title--fail"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php esc_html_e( 'NEEDS FIXING', 'meyvora-seo' ); ?> <span>(<?php echo (int) count( $fails ); ?>)</span></div>
						<?php foreach ( $fails as $r ) : ?>
						<div class="mev-checklist-item mev-checklist-item--fail mev-check-fail">
							<div class="mev-checklist-icon"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_x', array( 'width' => 16, 'height' => 16 ) ) ); ?></div>
							<div class="mev-checklist-body">
								<div class="mev-checklist-label"><?php echo esc_html( $r['label'] ?? '' ); ?></div>
								<div class="mev-checklist-msg"><?php echo esc_html( $r['message'] ?? '' ); ?></div>
								<?php
								$id = $r['id'] ?? '';
								if ( $id === 'focus_keyword_title' || $id === 'title_length' ) :
									?><button type="button" class="mev-quick-fix" onclick="document.querySelector('.meyvora-seo-tab[data-tab=general]').click();var e=document.getElementById('meyvora_seo_title');if(e)e.focus();"><?php esc_html_e( 'Edit SEO Title', 'meyvora-seo' ); ?></button>
								<?php elseif ( $id === 'focus_keyword_description' || $id === 'description_length' ) : ?>
									<button type="button" class="mev-quick-fix" onclick="document.querySelector('.meyvora-seo-tab[data-tab=general]').click();var e=document.getElementById('meyvora_seo_description');if(e)e.focus();"><?php esc_html_e( 'Edit Description', 'meyvora-seo' ); ?></button>
								<?php elseif ( $id === 'focus_keyword_set' ) : ?>
									<button type="button" class="mev-quick-fix" onclick="document.querySelector('.meyvora-seo-tab[data-tab=general]').click();var e=document.getElementById('meyvora_seo_focus_keyword_input');if(e)e.focus();"><?php esc_html_e( 'Set Focus Keyword', 'meyvora-seo' ); ?></button>
								<?php elseif ( $id === 'og_image_set' ) : ?>
									<button type="button" class="mev-quick-fix" onclick="document.querySelector('.meyvora-seo-tab[data-tab=social]').click();"><?php esc_html_e( 'Set OG Image', 'meyvora-seo' ); ?></button>
								<?php endif; ?>
							</div>
							<?php if ( ( $r['weight'] ?? 0 ) > 0 ) : ?><div class="mev-checklist-pts">0/<?php echo (int) $r['weight']; ?>pts</div><?php endif; ?>
						</div>
						<?php endforeach; ?>
					</li>
					<?php endif; ?>

					<?php
					$warns = array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'warning'; } );
					if ( ! empty( $warns ) ) :
					?>
					<li class="mev-checklist-section">
						<div class="mev-checklist-section-title mev-checklist-section-title--warn"><?php echo wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php esc_html_e( 'WARNINGS', 'meyvora-seo' ); ?> <span>(<?php echo (int) count( $warns ); ?>)</span></div>
						<?php foreach ( $warns as $r ) : ?>
						<div class="mev-checklist-item mev-checklist-item--warn mev-check-warning">
							<div class="mev-checklist-icon"><?php echo wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 16, 'height' => 16 ) ) ); ?></div>
							<div class="mev-checklist-body">
								<div class="mev-checklist-label"><?php echo esc_html( $r['label'] ?? '' ); ?></div>
								<div class="mev-checklist-msg"><?php echo esc_html( $r['message'] ?? '' ); ?></div>
							</div>
							<?php if ( ( $r['weight'] ?? 0 ) > 0 ) : ?><div class="mev-checklist-pts"><?php echo (int) ( $r['points_earned'] ?? 0 ); ?>/<?php echo (int) $r['weight']; ?>pts</div><?php endif; ?>
						</div>
						<?php endforeach; ?>
					</li>
					<?php endif; ?>

					<?php
					$passes = array_filter( $results, function ( $r ) { return ( $r['status'] ?? '' ) === 'pass'; } );
					if ( ! empty( $passes ) ) :
					?>
					<li class="mev-checklist-section">
						<div class="mev-checklist-section-title mev-checklist-section-title--pass" onclick="var el=document.getElementById('mev_passes');el.style.display=el.style.display==='none'?'block':'none';" style="cursor:pointer;">
							<?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 14, 'height' => 14 ) ) ); ?> <?php esc_html_e( 'PASSED', 'meyvora-seo' ); ?> <span>(<?php echo (int) count( $passes ); ?>)</span>
							<span style="margin-left:auto;font-size:10px;">▼</span>
						</div>
						<div id="mev_passes" style="display:none;">
						<?php foreach ( $passes as $r ) : ?>
						<div class="mev-checklist-item mev-checklist-item--pass mev-check-pass">
							<div class="mev-checklist-icon"><?php echo wp_kses_post( meyvora_seo_icon( 'circle_check', array( 'width' => 16, 'height' => 16 ) ) ); ?></div>
							<div class="mev-checklist-body">
								<div class="mev-checklist-label"><?php echo esc_html( $r['label'] ?? '' ); ?></div>
								<div class="mev-checklist-msg"><?php echo esc_html( $r['message'] ?? '' ); ?></div>
							</div>
							<?php if ( ( $r['weight'] ?? 0 ) > 0 ) : ?><div class="mev-checklist-pts" style="color:var(--mev-success);">+<?php echo (int) $r['weight']; ?>pts</div><?php endif; ?>
						</div>
						<?php endforeach; ?>
						</div>
					</li>
					<?php endif; ?>

				</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Get decoded schema JSON from post meta.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $meta_key Meta key constant.
	 * @return array<string, mixed>
	 */
	protected function get_schema_json( int $post_id, string $meta_key ): array {
		$key = apply_filters( 'meyvora_seo_post_meta_key', $meta_key, $post_id );
		$raw = get_post_meta( $post_id, $key, true );
		if ( ( $raw === '' || $raw === null ) && $key !== $meta_key ) {
			$raw = get_post_meta( $post_id, $meta_key, true );
		}
		if ( ! is_string( $raw ) || $raw === '' ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Recursively sanitize schema payload from POST.
	 *
	 * @param array $payload Raw POST slice.
	 * @return array Sanitized array (empty values removed at leaf level).
	 */
	protected function sanitize_schema_payload( array $payload ): array {
		$out = array();
		$url_keys = array( 'url', 'sameAs' );
		foreach ( $payload as $k => $v ) {
			if ( is_array( $v ) ) {
				$v = $this->sanitize_schema_payload( $v );
				if ( $v !== array() ) {
					$out[ $k ] = $v;
				}
				continue;
			}
			$v = is_string( $v ) ? trim( $v ) : $v;
			if ( $v === '' || $v === null ) {
				continue;
			}
			if ( in_array( $k, $url_keys, true ) && is_string( $v ) ) {
				$out[ $k ] = esc_url_raw( $v );
				continue;
			}
			if ( is_numeric( $v ) ) {
				$out[ $k ] = strpos( (string) $v, '.' ) !== false ? (float) $v : (int) $v;
				continue;
			}
			$out[ $k ] = strlen( (string) $v ) > 500 ? sanitize_textarea_field( (string) $v ) : sanitize_text_field( (string) $v );
		}
		return $out;
	}

	/**
	 * Sanitize focus keywords from POST (JSON array or legacy single string). Returns array of strings, max 5.
	 *
	 * @param string|array $raw Raw value.
	 * @return array<int, string>
	 */
	protected static function sanitize_focus_keywords_meta( $raw ): array {
		if ( is_array( $raw ) ) {
			$out = array_values( array_filter( array_map( function ( $v ) {
				return is_string( $v ) ? sanitize_text_field( $v ) : '';
			}, $raw ), function ( $v ) {
				return $v !== '';
			} ) );
			return array_slice( array_unique( $out ), 0, 5 );
		}
		$s = is_string( $raw ) ? trim( $raw ) : '';
		if ( $s === '' ) {
			return array();
		}
		if ( ( $s[0] ?? '' ) === '[' ) {
			$decoded = json_decode( $s, true );
			if ( is_array( $decoded ) ) {
				$out = array_values( array_filter( array_map( function ( $v ) {
					return is_string( $v ) ? sanitize_text_field( trim( $v ) ) : '';
				}, $decoded ), function ( $v ) {
					return $v !== '';
				} ) );
				return array_slice( array_unique( $out ), 0, 5 );
			}
		}
		return array( sanitize_text_field( $s ) );
	}

	/**
	 * Save SEO panel data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save_meta( int $post_id, WP_Post $post ): void {
		// Do not run during REST API saves — block editor handles its own meta.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! $this->options->current_user_can_edit_seo() ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_doing_autosave() || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			Meyvora_SEO_Analyzer::clear_analysis_cache( $post_id );
		}

		$title       = isset( $_POST['meyvora_seo_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_title'] ) ) : '';
		$desc        = isset( $_POST['meyvora_seo_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_seo_description'] ) ) : '';
		$noindex     = isset( $_POST['meyvora_seo_noindex'] ) && sanitize_text_field( wp_unslash( $_POST['meyvora_seo_noindex'] ) ) === '1';
		$nofollow    = isset( $_POST['meyvora_seo_nofollow'] ) && sanitize_text_field( wp_unslash( $_POST['meyvora_seo_nofollow'] ) ) === '1';
		$noodp       = isset( $_POST['meyvora_seo_noodp'] ) && sanitize_text_field( wp_unslash( $_POST['meyvora_seo_noodp'] ) ) === '1';
		$noarchive   = isset( $_POST['meyvora_seo_noarchive'] );
		$nosnippet   = isset( $_POST['meyvora_seo_nosnippet'] );
		$max_snippet_raw = isset( $_POST['meyvora_seo_max_snippet'] ) ? absint( $_POST['meyvora_seo_max_snippet'] ) : -1;
		$focus_kw_raw = isset( $_POST['meyvora_seo_focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_focus_keyword'] ) ) : '';
		$focus_kw     = self::sanitize_focus_keywords_meta( $focus_kw_raw );
		$canonical   = isset( $_POST['meyvora_seo_canonical'] ) ? esc_url_raw( wp_unslash( $_POST['meyvora_seo_canonical'] ) ) : '';
		$og_title    = isset( $_POST['meyvora_seo_og_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_og_title'] ) ) : '';
		$og_desc     = isset( $_POST['meyvora_seo_og_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_seo_og_description'] ) ) : '';
		$og_image    = isset( $_POST['meyvora_seo_og_image'] ) ? absint( $_POST['meyvora_seo_og_image'] ) : 0;
		$tw_title    = isset( $_POST['meyvora_seo_twitter_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_twitter_title'] ) ) : '';
		$tw_desc     = isset( $_POST['meyvora_seo_twitter_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_seo_twitter_description'] ) ) : '';
		$tw_image    = isset( $_POST['meyvora_seo_twitter_image'] ) ? absint( $_POST['meyvora_seo_twitter_image'] ) : 0;
		$schema_type = isset( $_POST['meyvora_seo_schema_type'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_schema_type'] ) ) : '';
		$breadcrumb  = isset( $_POST['meyvora_seo_breadcrumb_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_breadcrumb_title'] ) ) : '';
		$sec_kw      = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$key = 'meyvora_seo_secondary_keyword_' . $i;
			if ( isset( $_POST[ $key ] ) ) {
				$v = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				if ( $v !== '' ) {
					$sec_kw[] = $v;
				}
			}
		}

		$meta_key_for = function( $base_key ) use ( $post_id ) {
			return apply_filters( 'meyvora_seo_post_meta_key', $base_key, $post_id );
		};
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TITLE ), $title );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_DESCRIPTION ), $desc );
		$desc_variant_a = isset( $_POST['meyvora_seo_desc_variant_a'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_seo_desc_variant_a'] ) ) : '';
		$desc_variant_b = isset( $_POST['meyvora_seo_desc_variant_b'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_seo_desc_variant_b'] ) ) : '';
		update_post_meta( $post_id, MEYVORA_SEO_META_DESC_VARIANT_A, $desc_variant_a );
		update_post_meta( $post_id, MEYVORA_SEO_META_DESC_VARIANT_B, $desc_variant_b );
		$ab_start_now = isset( $_POST['meyvora_seo_ab_start_now'] ) && (string) $_POST['meyvora_seo_ab_start_now'] === '1';
		if ( $ab_start_now && $desc_variant_a !== '' && $desc_variant_b !== '' ) {
			update_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_ACTIVE, 'a' );
			update_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_START, (string) time() );
			update_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_RESULT, '' );
		}
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOINDEX ), $noindex ? '1' : '' );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOFOLLOW ), $nofollow ? '1' : '' );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOODP ), $noodp ? '1' : '' );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOARCHIVE ), isset( $_POST['meyvora_seo_noarchive'] ) ? '1' : '' );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOSNIPPET ), isset( $_POST['meyvora_seo_nosnippet'] ) ? '1' : '' );
		if ( ! $nosnippet && $max_snippet_raw >= -1 ) {
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_MAX_SNIPPET ), $max_snippet_raw );
		}
		$max_image_preview_raw = isset( $_POST['meyvora_seo_max_image_preview'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_max_image_preview'] ) ) : 'large';
		$max_video_preview_raw = isset( $_POST['meyvora_seo_max_video_preview'] ) ? (int) $_POST['meyvora_seo_max_video_preview'] : -1;
		if ( in_array( $max_image_preview_raw, array( 'none', 'standard', 'large' ), true ) ) {
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW ), $max_image_preview_raw );
		}
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW ), $max_video_preview_raw >= -1 ? $max_video_preview_raw : -1 );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_CORNERSTONE ), isset( $_POST['meyvora_seo_cornerstone'] ) ? '1' : '' );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_FOCUS_KEYWORD ), is_array( $focus_kw ) ? wp_json_encode( $focus_kw ) : $focus_kw );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_CANONICAL ), $canonical );
		$priority_raw = isset( $_POST['meyvora_seo_sitemap_priority'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_sitemap_priority'] ) ) : '';
		$allowed_priorities = array( '', '1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1', '0.0' );
		if ( in_array( $priority_raw, $allowed_priorities, true ) ) {
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SITEMAP_PRIORITY ), $priority_raw );
		}
		$changefreq_raw = isset( $_POST['meyvora_seo_sitemap_changefreq'] ) ? sanitize_text_field( wp_unslash( $_POST['meyvora_seo_sitemap_changefreq'] ) ) : '';
		$allowed_changefreqs = array( '', 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' );
		if ( in_array( $changefreq_raw, $allowed_changefreqs, true ) ) {
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SITEMAP_CHANGEFREQ ), $changefreq_raw );
		}
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_OG_TITLE ), $og_title );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_OG_DESCRIPTION ), $og_desc );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_OG_IMAGE ), $og_image );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TWITTER_TITLE ), $tw_title );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TWITTER_DESCRIPTION ), $tw_desc );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TWITTER_IMAGE ), $tw_image );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SCHEMA_TYPE ), $schema_type );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_BREADCRUMB_TITLE ), $breadcrumb );
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SECONDARY_KEYWORDS ), wp_json_encode( $sec_kw ) );

		// FAQ pairs: array of { question, answer } from POST; each element sanitized in loop.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Array values sanitized in loop below.
		$faq_post = isset( $_POST['meyvora_seo_faq'] ) && is_array( $_POST['meyvora_seo_faq'] ) ? wp_unslash( $_POST['meyvora_seo_faq'] ) : array();
		$faq_sanitized = array();
		foreach ( $faq_post as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$q = isset( $row['question'] ) ? sanitize_text_field( $row['question'] ) : '';
			$a = isset( $row['answer'] ) ? sanitize_textarea_field( $row['answer'] ) : '';
			if ( $q !== '' || $a !== '' ) {
				$faq_sanitized[] = array( 'question' => $q, 'answer' => $a );
			}
		}
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_FAQ ), $faq_sanitized ? wp_json_encode( $faq_sanitized ) : '' );

		$schema_keys = array(
			'meyvora_seo_schema_howto'       => MEYVORA_SEO_META_SCHEMA_HOWTO,
			'meyvora_seo_schema_recipe'      => MEYVORA_SEO_META_SCHEMA_RECIPE,
			'meyvora_seo_schema_event'       => MEYVORA_SEO_META_SCHEMA_EVENT,
			'meyvora_seo_schema_course'      => MEYVORA_SEO_META_SCHEMA_COURSE,
			'meyvora_seo_schema_jobposting'  => MEYVORA_SEO_META_SCHEMA_JOBPOSTING,
			'meyvora_seo_schema_software'    => MEYVORA_SEO_META_SCHEMA_SOFTWARE,
			'meyvora_seo_schema_review'      => MEYVORA_SEO_META_SCHEMA_REVIEW,
			'meyvora_seo_schema_book'        => MEYVORA_SEO_META_SCHEMA_BOOK,
			'meyvora_seo_schema_product'     => MEYVORA_SEO_META_SCHEMA_PRODUCT,
		);
		foreach ( $schema_keys as $post_key => $meta_key ) {
			// Schema payload is array; sanitized by sanitize_schema_payload().
			$payload = isset( $_POST[ $post_key ] ) && is_array( $_POST[ $post_key ] ) ? map_deep( wp_unslash( $_POST[ $post_key ] ), 'sanitize_text_field' ) : array();
			$sanitized = $this->sanitize_schema_payload( $payload );
			update_post_meta( $post_id, $meta_key_for( $meta_key ), $sanitized ? wp_json_encode( $sanitized ) : '' );
		}

		if ( class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			$analyzer = new Meyvora_SEO_Analyzer();
			$post_obj = get_post( $post_id );
			$content  = $post_obj ? apply_filters( 'meyvora_seo_analysis_content', (string) $post_obj->post_content, $post_id ) : '';
			$analysis = $analyzer->analyze( $post_id, $content !== '' ? $content : null );
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SCORE ), $analysis['score'] );
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_ANALYSIS ), wp_json_encode( array(
				'score'   => $analysis['score'],
				'status'  => $analysis['status'],
				'results' => $analysis['results'],
			) ) );
		}
	}

	/**
	 * AJAX: Run analysis and return score + results (for real-time updates).
	 * Accepts live title, description, focus_keyword from POST so analysis reflects unsaved editor values.
	 */
	/** Rate limit: max analysis requests per user per 60 seconds. */
	const ANALYZE_RATE_LIMIT = 30;
	const ANALYZE_RATE_WINDOW = 60;

	public function ajax_analyze(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$user_id  = get_current_user_id();
		if ( $user_id ) {
			$rate_key = 'meyvora_seo_analyze_rate_' . $user_id;
			$count    = (int) get_transient( $rate_key );
			if ( $count >= self::ANALYZE_RATE_LIMIT ) {
				wp_send_json_error( array(
					'message' => __( 'Too many analysis requests. Please wait.', 'meyvora-seo' ),
					'code'    => 'rate_limit',
				) );
			}
			set_transient( $rate_key, $count + 1, self::ANALYZE_RATE_WINDOW );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Invalid post' ) );
		}
		$content = isset( $_POST['content'] ) ? wp_kses_post( wp_unslash( $_POST['content'] ) ) : null;
		$live_title    = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : null;
		$live_desc     = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : null;
		$live_keyword_raw = isset( $_POST['focus_keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['focus_keyword'] ) ) : null;
		if ( ! class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			wp_send_json_error( array( 'message' => 'Analyzer not loaded' ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'Post not found' ) );
		}
		if ( $content === null ) {
			$content = apply_filters( 'meyvora_seo_analysis_content', (string) $post->post_content, $post_id );
		}
		$overrides = array();
		if ( $live_keyword_raw !== null && $live_keyword_raw !== '' ) {
			$overrides['focus_keywords'] = Meyvora_SEO_Analyzer::normalize_focus_keywords( $live_keyword_raw );
		}
		if ( $live_title !== null ) {
			$overrides['title'] = $live_title;
		}
		if ( $live_desc !== null ) {
			$overrides['description'] = $live_desc;
		}
		$analyzer = new Meyvora_SEO_Analyzer();
		$analysis = $analyzer->analyze( $post_id, $content !== '' ? $content : null, $overrides );
		// Block editor sends live data via overrides; do not write to DB — JS updates meta via core/editor.
		if ( empty( $overrides ) ) {
			$meta_key_for = function( $base_key ) use ( $post_id ) {
				return apply_filters( 'meyvora_seo_post_meta_key', $base_key, $post_id );
			};
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SCORE ), $analysis['score'] );
			update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_ANALYSIS ), wp_json_encode( array(
				'score'   => $analysis['score'],
				'status'  => $analysis['status'],
				'results' => $analysis['results'],
			) ) );
		}
		wp_send_json_success( array(
			'score'     => $analysis['score'],
			'status'    => $analysis['status'],
			'results'   => $analysis['results'],
			'max_score' => Meyvora_SEO_Analyzer::get_max_score(),
		) );
	}

	/**
	 * AJAX: Autosave SEO fields (nonce + capability checked).
	 */
	public function ajax_autosave(): void {
		check_ajax_referer( self::AJAX_NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid post' ) );
		}
		// JS sends data as a JSON-encoded string via encodeURIComponent(JSON.stringify(data)).
		// is_array() would always be false on a string, so decode it first.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw string decoded and validated below; keys sanitized on use.
		$data_raw = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$data_raw = is_string( $data_raw ) ? $data_raw : '';
		if ( is_array( $_POST['data'] ?? null ) ) {
			// Fallback: sent as a real array (unlikely); sanitized via allowed keys below.
			$data = map_deep( wp_unslash( $_POST['data'] ), 'sanitize_text_field' );
		} elseif ( $data_raw !== '' ) {
			$data = json_decode( $data_raw, true );
			if ( ! is_array( $data ) ) {
				$data = array();
			}
		} else {
			$data = array();
		}
		$allowed = array(
			'meyvora_seo_focus_keyword', 'meyvora_seo_title', 'meyvora_seo_description', 'meyvora_seo_canonical',
			'meyvora_seo_noindex', 'meyvora_seo_nofollow', 'meyvora_seo_noodp',
			'meyvora_seo_noarchive', 'meyvora_seo_nosnippet', 'meyvora_seo_max_snippet',
			'meyvora_seo_max_image_preview', 'meyvora_seo_max_video_preview',
			'meyvora_seo_cornerstone',
			'meyvora_seo_og_title', 'meyvora_seo_og_description', 'meyvora_seo_og_image',
			'meyvora_seo_twitter_title', 'meyvora_seo_twitter_description', 'meyvora_seo_twitter_image',
			'meyvora_seo_schema_type', 'meyvora_seo_breadcrumb_title',
			'meyvora_seo_secondary_keyword_0', 'meyvora_seo_secondary_keyword_1', 'meyvora_seo_secondary_keyword_2',
		);
		$meta_key_for = function( $base_key ) use ( $post_id ) {
			return apply_filters( 'meyvora_seo_post_meta_key', $base_key, $post_id );
		};
		foreach ( $allowed as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}
			$val = $data[ $key ];
			if ( $key === 'meyvora_seo_canonical' ) {
				$val = esc_url_raw( wp_unslash( $val ) );
			} elseif ( in_array( $key, array( 'meyvora_seo_og_image', 'meyvora_seo_twitter_image' ), true ) ) {
				$val = absint( $val );
			} elseif ( in_array( $key, array( 'meyvora_seo_description', 'meyvora_seo_og_description', 'meyvora_seo_twitter_description' ), true ) ) {
				$val = sanitize_textarea_field( wp_unslash( $val ) );
			} elseif ( $key === 'meyvora_seo_focus_keyword' ) {
				$val = is_string( $val ) ? $val : '';
				$arr = self::sanitize_focus_keywords_meta( $val );
				$val = wp_json_encode( $arr );
			} else {
				$val = sanitize_text_field( wp_unslash( $val ) );
			}
			switch ( $key ) {
				case 'meyvora_seo_focus_keyword':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_FOCUS_KEYWORD ), $val );
					break;
				case 'meyvora_seo_title':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TITLE ), $val );
					break;
				case 'meyvora_seo_description':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_DESCRIPTION ), $val );
					break;
				case 'meyvora_seo_canonical':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_CANONICAL ), $val );
					break;
				case 'meyvora_seo_noindex':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOINDEX ), $val === '1' || $val === true ? '1' : '' );
					break;
				case 'meyvora_seo_nofollow':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOFOLLOW ), $val === '1' || $val === true ? '1' : '' );
					break;
				case 'meyvora_seo_noodp':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOODP ), $val === '1' || $val === true ? '1' : '' );
					break;
				case 'meyvora_seo_noarchive':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOARCHIVE ), $val === '1' || $val === true ? '1' : '' );
					break;
				case 'meyvora_seo_nosnippet':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_NOSNIPPET ), $val === '1' || $val === true ? '1' : '' );
					break;
				case 'meyvora_seo_max_snippet':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_MAX_SNIPPET ), absint( $val ) );
					break;
				case 'meyvora_seo_og_title':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_OG_TITLE ), $val );
					break;
				case 'meyvora_seo_og_description':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_OG_DESCRIPTION ), $val );
					break;
				case 'meyvora_seo_og_image':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_OG_IMAGE ), $val );
					break;
				case 'meyvora_seo_twitter_title':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TWITTER_TITLE ), $val );
					break;
				case 'meyvora_seo_twitter_description':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TWITTER_DESCRIPTION ), $val );
					break;
				case 'meyvora_seo_twitter_image':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_TWITTER_IMAGE ), $val );
					break;
				case 'meyvora_seo_schema_type':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SCHEMA_TYPE ), $val );
					break;
				case 'meyvora_seo_breadcrumb_title':
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_BREADCRUMB_TITLE ), $val );
					break;
				case 'meyvora_seo_secondary_keyword_0':
				case 'meyvora_seo_secondary_keyword_1':
				case 'meyvora_seo_secondary_keyword_2':
					$sec = array();
					for ( $i = 0; $i < 3; $i++ ) {
						$k = 'meyvora_seo_secondary_keyword_' . $i;
						$v = isset( $data[ $k ] ) ? sanitize_text_field( wp_unslash( $data[ $k ] ) ) : '';
						if ( $v !== '' ) {
							$sec[] = $v;
						}
					}
					update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_SECONDARY_KEYWORDS ), wp_json_encode( $sec ) );
					break;
			}
		}
		wp_send_json_success( array( 'message' => __( 'Saved', 'meyvora-seo' ) ) );
	}
}