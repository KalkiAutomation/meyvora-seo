<?php
/**
 * Gutenberg/Block Editor: sidebar panel and document score.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Block_Editor {

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
		add_filter( 'rest_pre_dispatch', array( $this, 'capture_rest_request_meta' ), 1, 3 );
	}

	/**
	 * Register hooks and enqueue sidebar script.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'init', $this, 'register_rest_meta', 5, 0 );
		$this->loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_sidebar_assets', 10, 0 );
		$this->loader->add_action( 'add_meta_boxes', $this, 'maybe_hide_meta_box_for_block_editor', 20, 0 );
		$this->register_rest_save_meta_hooks();
	}

	/**
	 * Ensure SEO meta is saved when post is updated via REST (block editor). Runs after REST insert/update.
	 */
	/** Captured meta from REST request body (before any processing). */
	private static $captured_rest_meta = array();

	protected function register_rest_save_meta_hooks(): void {
		$post_types = $this->get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			add_action( 'rest_after_insert_' . $post_type, array( $this, 'save_seo_meta_from_rest_request' ), 10, 3 );
		}
	}

	/**
	 * Capture meta from REST request body so we can persist it in rest_after_insert (body may be consumed later).
	 *
	 * @param mixed            $result  Not used.
	 * @param WP_REST_Server   $server  Server.
	 * @param WP_REST_Request  $request Request.
	 * @return mixed Unchanged result.
	 */
	public function capture_rest_request_meta( $result, $server, $request ) {
		$route = $request->get_route();
		$is_our_route = false;
		foreach ( $this->get_supported_post_types() as $post_type ) {
			$obj = get_post_type_object( $post_type );
			$rest_base = ( $obj && ! empty( $obj->rest_base ) )
				? $obj->rest_base
				: $post_type . 's'; // WP default: add "s"
			$prefix = '/wp/v2/' . $rest_base;
			if ( $route === $prefix
				|| strpos( $route, $prefix . '/' ) === 0
				|| strpos( $route, $prefix . '?' ) === 0 ) {
				$is_our_route = true;
				break;
			}
		}
		if ( ! $is_our_route ) {
			return $result;
		}
		$method = $request->get_method();
		if ( $method !== 'PUT' && $method !== 'POST' && $method !== 'PATCH' ) {
			return $result;
		}
		self::$captured_rest_meta = array();
		$body = $request->get_body();
		if ( is_string( $body ) && $body !== '' ) {
			$decoded = json_decode( $body, true );
			if ( is_array( $decoded ) && isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ) {
				self::$captured_rest_meta = $decoded['meta'];
			}
		}
		return $result;
	}

	/**
	 * Get meta array from REST request. Uses raw JSON body when get_param('meta') omits underscore-prefixed keys.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return array<string, mixed> Meta key => value.
	 */
	protected function get_meta_from_rest_request( $request ): array {
		// Prefer meta captured at start of request (rest_pre_dispatch) so we have underscore-prefixed keys.
		if ( ! empty( self::$captured_rest_meta ) ) {
			$meta = self::$captured_rest_meta;
			self::$captured_rest_meta = array(); // Use once.
			return $meta;
		}
		$meta = $request->get_param( 'meta' );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		$our_keys = array(
			MEYVORA_SEO_META_FOCUS_KEYWORD, MEYVORA_SEO_META_TITLE, MEYVORA_SEO_META_DESCRIPTION,
			MEYVORA_SEO_META_CANONICAL, MEYVORA_SEO_META_NOINDEX, MEYVORA_SEO_META_NOFOLLOW,
			MEYVORA_SEO_META_OG_TITLE, MEYVORA_SEO_META_OG_DESCRIPTION, MEYVORA_SEO_META_OG_IMAGE,
			MEYVORA_SEO_META_TWITTER_TITLE, MEYVORA_SEO_META_TWITTER_DESCRIPTION, MEYVORA_SEO_META_TWITTER_IMAGE,
			MEYVORA_SEO_META_SCHEMA_TYPE, MEYVORA_SEO_META_FAQ, MEYVORA_SEO_META_SCORE,
			MEYVORA_SEO_META_ANALYSIS, MEYVORA_SEO_META_READABILITY, MEYVORA_SEO_META_SEARCH_INTENT,
		);
		$has_any = false;
		foreach ( $our_keys as $k ) {
			if ( array_key_exists( $k, $meta ) ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			$body = $request->get_body();
			if ( is_string( $body ) && $body !== '' ) {
				$decoded = json_decode( $body, true );
				if ( is_array( $decoded ) && isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ) {
					$meta = $decoded['meta'];
				}
			}
		}
		return $meta;
	}

	/**
	 * Save our SEO meta from the REST request (runs after core save so we are not overwritten).
	 *
	 * @param WP_Post          $post     Inserted/updated post.
	 * @param WP_REST_Request  $request  Request object (contains 'meta' param).
	 * @param bool             $creating True if creating, false if updating.
	 */
	public function save_seo_meta_from_rest_request( $post, $request, $creating ): void {
		if ( ! $this->options->current_user_can_edit_seo() ) {
			return;
		}
		if ( ! $post || ! $post->ID || ! $request ) {
			return;
		}
		$meta = $this->get_meta_from_rest_request( $request );
		$this->persist_seo_meta_from_array( $post->ID, $meta );
	}

	/**
	 * Persist allowed SEO meta from a meta array (REST request) to the post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Meta key => value from request.
	 */
	protected function persist_seo_meta_from_array( int $post_id, array $meta ): void {
		$allowed = array(
			MEYVORA_SEO_META_FOCUS_KEYWORD,
			MEYVORA_SEO_META_TITLE,
			MEYVORA_SEO_META_DESCRIPTION,
			MEYVORA_SEO_META_CANONICAL,
			MEYVORA_SEO_META_BREADCRUMB_TITLE,
			MEYVORA_SEO_META_NOINDEX,
			MEYVORA_SEO_META_NOFOLLOW,
			MEYVORA_SEO_META_CORNERSTONE,
			MEYVORA_SEO_META_NOODP,
			MEYVORA_SEO_META_NOARCHIVE,
			MEYVORA_SEO_META_NOSNIPPET,
			MEYVORA_SEO_META_MAX_SNIPPET,
			MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW,
			MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW,
			MEYVORA_SEO_META_OG_TITLE,
			MEYVORA_SEO_META_OG_DESCRIPTION,
			MEYVORA_SEO_META_OG_IMAGE,
			MEYVORA_SEO_META_TWITTER_TITLE,
			MEYVORA_SEO_META_TWITTER_DESCRIPTION,
			MEYVORA_SEO_META_TWITTER_IMAGE,
			MEYVORA_SEO_META_SCHEMA_TYPE,
			MEYVORA_SEO_META_FAQ,
			MEYVORA_SEO_META_SCHEMA_BOOK,
			MEYVORA_SEO_META_SCHEMA_SOFTWARE,
			MEYVORA_SEO_META_SCHEMA_PRODUCT,
			MEYVORA_SEO_META_SCORE,
			MEYVORA_SEO_META_ANALYSIS,
			MEYVORA_SEO_META_READABILITY,
			MEYVORA_SEO_META_SEARCH_INTENT,
		);
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $meta ) ) {
				continue;
			}
			$value = $meta[ $key ];
			if ( $key === MEYVORA_SEO_META_FOCUS_KEYWORD ) {
				if ( is_array( $value ) ) {
					$value = wp_json_encode( array_values( array_filter( array_map( 'strval', $value ) ) ) );
				} elseif ( is_string( $value ) && $value !== '' && strpos( $value, '[' ) === 0 ) {
					$decoded = json_decode( $value, true );
					if ( is_array( $decoded ) ) {
						$value = wp_json_encode( array_values( array_filter( array_map( 'strval', $decoded ) ) ) );
					}
				} elseif ( ! is_string( $value ) ) {
					$value = '';
				}
			} elseif ( $key === MEYVORA_SEO_META_FAQ && is_array( $value ) ) {
				$value = wp_json_encode( $value );
			} elseif ( $key === MEYVORA_SEO_META_CORNERSTONE ) {
				$value = ( $value === '1' || $value === true || $value === 1 ) ? '1' : '';
			} elseif ( $key === MEYVORA_SEO_META_OG_IMAGE || $key === MEYVORA_SEO_META_TWITTER_IMAGE || $key === MEYVORA_SEO_META_SCORE ) {
				$value = is_numeric( $value ) ? (int) $value : 0;
			} elseif ( $key === MEYVORA_SEO_META_MAX_SNIPPET ) {
				// -1 means "no limit", 0 means "no snippet". Store as int; empty string means not set.
				$value = ( $value === '' || $value === null ) ? '' : (int) $value;
			} elseif ( $key === MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW ) {
				$allowed_img = array( 'none', 'standard', 'large', '' );
				$value = in_array( (string) $value, $allowed_img, true ) ? (string) $value : '';
			} elseif ( $key === MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW ) {
				$value = ( $value === '' || $value === null ) ? '' : (int) $value;
			} elseif ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				$value = '';
			}
			update_post_meta( $post_id, $key, $value );
		}
		if ( isset( $meta[ MEYVORA_SEO_META_SITEMAP_PRIORITY ] ) ) {
			$allowed = array( '', '1.0', '0.9', '0.8', '0.7', '0.6', '0.5', '0.4', '0.3', '0.2', '0.1', '0.0' );
			$val = sanitize_text_field( (string) $meta[ MEYVORA_SEO_META_SITEMAP_PRIORITY ] );
			if ( in_array( $val, $allowed, true ) ) {
				update_post_meta( $post_id, MEYVORA_SEO_META_SITEMAP_PRIORITY, $val );
			}
		}
		if ( isset( $meta[ MEYVORA_SEO_META_SITEMAP_CHANGEFREQ ] ) ) {
			$allowed = array( '', 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' );
			$val = sanitize_text_field( (string) $meta[ MEYVORA_SEO_META_SITEMAP_CHANGEFREQ ] );
			if ( in_array( $val, $allowed, true ) ) {
				update_post_meta( $post_id, MEYVORA_SEO_META_SITEMAP_CHANGEFREQ, $val );
			}
		}
	}

	/**
	 * Register all SEO meta keys for REST API so Gutenberg can read/write them.
	 */
	public function register_rest_meta(): void {
		$post_types = $this->get_supported_post_types();

		// Block editor only persists meta when the post type supports 'custom-fields'.
		foreach ( $post_types as $post_type ) {
			add_post_type_support( $post_type, 'custom-fields' );
		}

		// Sanitize: ensure focus keyword and FAQ (JSON) are stored as strings when REST sends array.
		$sanitize_focus = function ( $value ) {
			if ( is_array( $value ) ) {
				return wp_json_encode( array_values( array_filter( array_map( 'strval', $value ) ) ) );
			}
			return is_string( $value ) ? $value : '';
		};
		$sanitize_faq = function ( $value ) {
			if ( is_array( $value ) ) {
				return wp_json_encode( $value );
			}
			return is_string( $value ) ? $value : '';
		};
		$sanitize_schema_json = function ( $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				return wp_json_encode( $value );
			}
			if ( ! is_string( $value ) || $value === '' ) {
				return '';
			}
			json_decode( $value );
			return json_last_error() === JSON_ERROR_NONE ? $value : '';
		};

		// All meta read/written by the block editor must be here with show_in_rest: true (see $defaults below).
		$meta_keys = array(
			MEYVORA_SEO_META_FOCUS_KEYWORD  => array( 'type' => 'string', 'sanitize_callback' => $sanitize_focus ),
			MEYVORA_SEO_META_TITLE           => array( 'type' => 'string' ),
			MEYVORA_SEO_META_DESCRIPTION     => array( 'type' => 'string' ),
			MEYVORA_SEO_META_DESC_VARIANT_A  => array( 'type' => 'string' ),
			MEYVORA_SEO_META_DESC_VARIANT_B  => array( 'type' => 'string' ),
			MEYVORA_SEO_META_DESC_AB_ACTIVE  => array( 'type' => 'string' ),
			MEYVORA_SEO_META_DESC_AB_START   => array( 'type' => 'string' ),
			MEYVORA_SEO_META_DESC_AB_RESULT  => array( 'type' => 'string' ),
			MEYVORA_SEO_META_CANONICAL       => array( 'type' => 'string' ),
			MEYVORA_SEO_META_BREADCRUMB_TITLE => array( 'type' => 'string' ),
			MEYVORA_SEO_META_NOINDEX         => array( 'type' => 'string' ),
			MEYVORA_SEO_META_NOFOLLOW        => array( 'type' => 'string' ),
			MEYVORA_SEO_META_CORNERSTONE     => array( 'type' => 'string' ),
			MEYVORA_SEO_META_NOODP           => array( 'type' => 'string' ),
			MEYVORA_SEO_META_NOARCHIVE       => array( 'type' => 'string' ),
			MEYVORA_SEO_META_NOSNIPPET       => array( 'type' => 'string' ),
			MEYVORA_SEO_META_MAX_SNIPPET     => array( 'type' => 'integer' ),
			MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW => array( 'type' => 'string' ),
			MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW => array( 'type' => 'integer' ),
			MEYVORA_SEO_META_OG_TITLE        => array( 'type' => 'string' ),
			MEYVORA_SEO_META_OG_DESCRIPTION  => array( 'type' => 'string' ),
			MEYVORA_SEO_META_OG_IMAGE        => array( 'type' => 'integer' ),
			MEYVORA_SEO_META_TWITTER_TITLE   => array( 'type' => 'string' ),
			MEYVORA_SEO_META_TWITTER_DESCRIPTION => array( 'type' => 'string' ),
			MEYVORA_SEO_META_TWITTER_IMAGE   => array( 'type' => 'integer' ),
			MEYVORA_SEO_META_SCHEMA_TYPE     => array( 'type' => 'string' ),
			MEYVORA_SEO_META_FAQ             => array( 'type' => 'string', 'sanitize_callback' => $sanitize_faq ),
			MEYVORA_SEO_META_SCHEMA_HOWTO    => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_RECIPE   => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_EVENT    => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_COURSE   => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_JOBPOSTING => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_REVIEW   => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_BOOK     => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_SOFTWARE => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCHEMA_PRODUCT   => array( 'type' => 'string', 'sanitize_callback' => $sanitize_schema_json ),
			MEYVORA_SEO_META_SCORE            => array( 'type' => 'integer' ),
			MEYVORA_SEO_META_ANALYSIS        => array( 'type' => 'string' ),
			MEYVORA_SEO_META_READABILITY     => array( 'type' => 'string' ),
			MEYVORA_SEO_META_SEARCH_INTENT   => array( 'type' => 'string' ),
			MEYVORA_SEO_META_SITEMAP_PRIORITY   => array( 'type' => 'string' ),
			MEYVORA_SEO_META_SITEMAP_CHANGEFREQ => array( 'type' => 'string' ),
		);
		$defaults = array(
			'show_in_rest'  => true,
			'single'        => true,
			'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', (int) $post_id );
			},
		);
		foreach ( $post_types as $post_type ) {
			foreach ( $meta_keys as $key => $args ) {
				// Add a type-appropriate default so WordPress includes these keys in
				// REST GET responses. Without a default, WP omits missing underscore-
				// prefixed meta entirely, so Gutenberg never sees or sends them.
				if ( ! isset( $args['default'] ) ) {
					$args['default'] = ( isset( $args['type'] ) && $args['type'] === 'integer' ) ? 0 : '';
				}
				register_post_meta( $post_type, $key, array_merge( $defaults, $args ) );
			}
		}
	}

	/**
	 * Enqueue script that registers the Gutenberg sidebar and document panel.
	 */
	public function enqueue_sidebar_assets(): void {
		$screen     = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post_type  = $screen && $screen->id !== '' ? ( $screen->post_type ?? '' ) : ( $GLOBALS['typenow'] ?? '' );
		$post_types = $this->get_supported_post_types();
		// When screen is null/empty (e.g. new post or some hosts), still enqueue if typenow is supported or unknown.
		if ( $post_type !== '' && ! in_array( $post_type, $post_types, true ) ) {
			return;
		}
		if ( ! $this->options->current_user_can_edit_seo() ) {
			return;
		}

		$script_path = MEYVORA_SEO_PATH . 'admin/assets/js/meyvora-block-editor.js';
		if ( ! file_exists( $script_path ) ) {
			return;
		}

		$post_id = 0;
		// Query args for current post context in block editor; capability enforced by editor.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post'] ) ) {
			$post_id = absint( $_GET['post'] );
		} elseif ( isset( $_GET['post_id'] ) ) {
			$post_id = absint( $_GET['post_id'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id ) {
			$post_id = (int) get_the_ID();
		}
		if ( ! $post_id && function_exists( 'get_queried_object_id' ) ) {
			$post_id = (int) get_queried_object_id();
		}
		$current_meta = array();
		if ( $post_id > 0 ) {
			foreach ( array(
				MEYVORA_SEO_META_FOCUS_KEYWORD, MEYVORA_SEO_META_TITLE, MEYVORA_SEO_META_DESCRIPTION,
				MEYVORA_SEO_META_DESC_VARIANT_A, MEYVORA_SEO_META_DESC_VARIANT_B, MEYVORA_SEO_META_DESC_AB_ACTIVE, MEYVORA_SEO_META_DESC_AB_START, MEYVORA_SEO_META_DESC_AB_RESULT,
				MEYVORA_SEO_META_CANONICAL, MEYVORA_SEO_META_BREADCRUMB_TITLE, MEYVORA_SEO_META_NOINDEX, MEYVORA_SEO_META_NOFOLLOW, MEYVORA_SEO_META_CORNERSTONE,
				MEYVORA_SEO_META_NOODP, MEYVORA_SEO_META_NOARCHIVE, MEYVORA_SEO_META_NOSNIPPET, MEYVORA_SEO_META_MAX_SNIPPET,
				MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW, MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW,
				MEYVORA_SEO_META_OG_TITLE, MEYVORA_SEO_META_OG_DESCRIPTION, MEYVORA_SEO_META_OG_IMAGE,
				MEYVORA_SEO_META_TWITTER_TITLE, MEYVORA_SEO_META_TWITTER_DESCRIPTION, MEYVORA_SEO_META_TWITTER_IMAGE,
				MEYVORA_SEO_META_SCHEMA_TYPE, MEYVORA_SEO_META_FAQ,
				MEYVORA_SEO_META_SCHEMA_HOWTO, MEYVORA_SEO_META_SCHEMA_RECIPE, MEYVORA_SEO_META_SCHEMA_EVENT,
				MEYVORA_SEO_META_SCHEMA_COURSE, MEYVORA_SEO_META_SCHEMA_JOBPOSTING, MEYVORA_SEO_META_SCHEMA_REVIEW,
				MEYVORA_SEO_META_SCHEMA_BOOK, MEYVORA_SEO_META_SCHEMA_SOFTWARE, MEYVORA_SEO_META_SCHEMA_PRODUCT,
				MEYVORA_SEO_META_SCORE, MEYVORA_SEO_META_ANALYSIS, MEYVORA_SEO_META_READABILITY, MEYVORA_SEO_META_SEARCH_INTENT,
			) as $key ) {
				$val = get_post_meta( $post_id, $key, true );
				$current_meta[ $key ] = ( $val !== false && $val !== null ) ? $val : '';
			}
		}

		$post = $post_id > 0 ? get_post( $post_id ) : null;
		$post_categories = '';
		if ( $post_id > 0 && function_exists( 'get_the_category' ) ) {
			$cats = get_the_category( $post_id );
			$post_categories = is_array( $cats ) && ! empty( $cats ) ? implode( ' › ', wp_list_pluck( $cats, 'name' ) ) : '';
		}

		wp_enqueue_media();
		wp_enqueue_script(
			'meyvora-seo-block-editor',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-block-editor.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-i18n', 'wp-api-fetch', 'wp-block-editor', 'wp-compose', 'media-editor' ),
			MEYVORA_SEO_VERSION,
			true
		);

		// Meyvora FAQ block
		$faq_js = MEYVORA_SEO_PATH . 'blocks/meyvora-faq/index.js';
		if ( file_exists( $faq_js ) ) {
			wp_enqueue_script(
				'meyvora-seo-faq-block',
				MEYVORA_SEO_URL . 'blocks/meyvora-faq/index.js',
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data', 'wp-rich-text' ),
				MEYVORA_SEO_VERSION,
				true
			);
		}
		// Meyvora Citations block (E-E-A-T)
		$citations_js = MEYVORA_SEO_PATH . 'blocks/meyvora-citations/index.js';
		if ( file_exists( $citations_js ) ) {
			wp_enqueue_script(
				'meyvora-seo-citations-block',
				MEYVORA_SEO_URL . 'blocks/meyvora-citations/index.js',
				array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
				MEYVORA_SEO_VERSION,
				true
			);
		}

		$ai_enabled = class_exists( 'Meyvora_SEO_AI' ) && (bool) $this->options->get( 'ai_enabled', true );
		// Chat history is session-only and is cleared when the panel is closed or the page is refreshed — no chat history is stored server-side.
		$dataforseo_key = $this->options->get( 'dataforseo_api_key', '' );
		$keyword_research_enabled = is_string( $dataforseo_key ) && $dataforseo_key !== '';
		$analysis_ts = 0;
		if ( $post_id > 0 ) {
			$c = get_post_meta( $post_id, MEYVORA_SEO_META_ANALYSIS_CACHE, true );
			$d = is_string( $c ) ? json_decode( $c, true ) : array();
			$analysis_ts = isset( $d['timestamp'] ) ? (int) $d['timestamp'] : 0;
		}
		$config = array(
			'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
			'nonce'                  => wp_create_nonce( 'meyvora_seo_nonce' ),
			'postId'                 => $post_id,
			'analysisTimestamp'      => $analysis_ts,
			'isNewPost'              => ( $post_id === 0 ),
			'postStatus'             => $post_id ? get_post_status( $post_id ) : 'auto-draft',
			'currentMeta'            => $current_meta,
			'siteUrl'                => wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'example.com',
			'postSlug'               => $post ? $post->post_name : '',
			'postCategories'         => $post_categories,
			'aiEnabled'              => $ai_enabled,
			'keywordResearchEnabled' => $keyword_research_enabled,
			'aiNonce'              => wp_create_nonce( 'meyvora_seo_ai' ),
			'remaining'            => class_exists( 'Meyvora_SEO_AI' ) ? ( new Meyvora_SEO_AI( $this->loader, $this->options ) )->get_remaining_calls() : 0,
			'settingsUrl'          => admin_url( 'admin.php?page=meyvora-seo-settings#tab-ai' ),
			'linkSuggestionsNonce' => wp_create_nonce( 'meyvora_link_suggestions' ),
			'abTestNonce'         => wp_create_nonce( 'meyvora_seo_ab_test' ),
			'titleMin'             => 30,
			'titleMax'             => 60,
			'descMin'              => 120,
			'descMax'              => 160,
			'showStandaloneProduct' => ! function_exists( 'is_product' ),
			'i18n'                 => array(
				'panelTitle'   => __( 'Meyvora SEO', 'meyvora-seo' ),
				'seo'          => __( 'SEO', 'meyvora-seo' ),
				'readability'  => __( 'Readability', 'meyvora-seo' ),
				'social'       => __( 'Social', 'meyvora-seo' ),
				'general'      => __( 'General', 'meyvora-seo' ),
				'advanced'     => __( 'Advanced', 'meyvora-seo' ),
				'score'        => __( 'Score', 'meyvora-seo' ),
				'focusKeyword'  => __( 'Focus Keyword', 'meyvora-seo' ),
				'focusKeywords' => __( 'Focus Keywords', 'meyvora-seo' ),
				'seoTitle'     => __( 'SEO Title', 'meyvora-seo' ),
				'metaDesc'     => __( 'Meta Description', 'meyvora-seo' ),
				'analyzing'    => __( 'Analyzing…', 'meyvora-seo' ),
				'needsWork'    => __( 'Needs Work', 'meyvora-seo' ),
				'almostThere'  => __( 'Almost There', 'meyvora-seo' ),
				'great'       => __( 'Great!', 'meyvora-seo' ),
				'ogTitle'      => __( 'OG Title', 'meyvora-seo' ),
				'ogDesc'       => __( 'OG Description', 'meyvora-seo' ),
				'ogImage'      => __( 'OG Image', 'meyvora-seo' ),
				'useFieldsBelow'     => __( 'Use the fields below to set focus keyword, SEO title, and meta description. Analysis updates as you type.', 'meyvora-seo' ),
				'saveToEnableAnalysis' => __( 'Save the post once to enable live SEO analysis.', 'meyvora-seo' ),
				'seoChecklist'        => __( 'SEO checklist', 'meyvora-seo' ),
				'aiGenerate'          => __( '✨ Generate', 'meyvora-seo' ),
				'aiUseThis'            => __( 'Use this', 'meyvora-seo' ),
				'aiGenerating'         => __( 'Generating…', 'meyvora-seo' ),
				'aiUnavailable'        => __( 'AI unavailable. Check Settings > AI.', 'meyvora-seo' ),
				'aiEnableInSettings'   => __( 'Enable AI features in Settings', 'meyvora-seo' ),
				'aiChooseTitle'        => __( 'Choose a title', 'meyvora-seo' ),
				'aiChooseDescription'  => __( 'Choose a description', 'meyvora-seo' ),
				'linkSuggestionsTitle' => __( 'Internal Link Suggestions', 'meyvora-seo' ),
				'linkCopy'             => __( 'Copy Link', 'meyvora-seo' ),
				'linkCopied'            => __( 'Copied!', 'meyvora-seo' ),
				'linkNoSuggestions'     => __( 'No suggestions yet. Add a focus keyword first.', 'meyvora-seo' ),
				'keyword'              => __( 'Keyword', 'meyvora-seo' ),
				'keywordResearch'      => __( 'Keyword Research', 'meyvora-seo' ),
				'keywordResearchBtn'   => __( 'Research', 'meyvora-seo' ),
				'keywordResearching'   => __( 'Researching…', 'meyvora-seo' ),
				'keywordVolume'        => __( 'Volume', 'meyvora-seo' ),
				'keywordCompetition'   => __( 'Competition', 'meyvora-seo' ),
				'keywordCpc'           => __( 'CPC', 'meyvora-seo' ),
				'keywordAddSecondary'  => __( 'Add as keyword', 'meyvora-seo' ),
				'keywordResearchError' => __( 'Request failed.', 'meyvora-seo' ),
				'eeat'                 => __( 'E-E-A-T', 'meyvora-seo' ),
				'eeatChecklistTitle'   => __( 'E-E-A-T checklist', 'meyvora-seo' ),
				'eeatAuthorExpertise'  => __( 'Author has expertise area', 'meyvora-seo' ),
				'eeatAuthorCredentials' => __( 'Author has credentials', 'meyvora-seo' ),
				'eeatAuthorOrg'        => __( 'Author has organization affiliation', 'meyvora-seo' ),
				'eeatAuthorYears'      => __( 'Author has years of experience', 'meyvora-seo' ),
				'eeatDateModified'     => __( 'Post has date modified', 'meyvora-seo' ),
				'eeatCitationsBlock'   => __( 'Post has Citations block', 'meyvora-seo' ),
				'eeatBylineSpeakable'  => __( 'Byline speakable in schema', 'meyvora-seo' ),
				'eeatSignalPresent'    => __( 'Present', 'meyvora-seo' ),
				'eeatSignalMissing'    => __( 'Missing', 'meyvora-seo' ),
			),
		);
		if ( class_exists( 'Meyvora_SEO_EEAT' ) && $post_id > 0 ) {
			$config['eeatChecklistData'] = Meyvora_SEO_EEAT::get_eeat_checklist_for_post( $post_id );
		} else {
			$config['eeatChecklistData'] = array();
		}
		$config = apply_filters( 'meyvora_seo_block_editor_config', $config, $post_id );
		wp_localize_script( 'meyvora-seo-block-editor', 'meyvoraSeoBlock', $config );

		$css_path = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-admin.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'meyvora-seo-admin',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css',
				array(),
				MEYVORA_SEO_VERSION
			);
		}
	}

	/**
	 * Hide the classic meta box when block editor is used (sidebar is primary).
	 */
	public function maybe_hide_meta_box_for_block_editor(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ( $screen->id ?? '' ) === '' ) {
			return;
		}
		if ( ! $this->is_block_editor() ) {
			return;
		}
		$post_types = $this->get_supported_post_types();
		foreach ( $post_types as $post_type ) {
			remove_meta_box( 'meyvora_seo', $post_type, 'normal' );
		}
	}

	/**
	 * Whether the current screen is the block editor.
	 *
	 * @return bool
	 */
	protected function is_block_editor(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		return $screen->is_block_editor();
	}

	/**
	 * Get post types that support the SEO meta box / sidebar.
	 *
	 * @return array<string>
	 */
	protected function get_supported_post_types(): array {
		$default = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) && post_type_exists( 'product' ) ) {
			$default[] = 'product';
		}
		return apply_filters( 'meyvora_seo_supported_post_types', $default );
	}
}