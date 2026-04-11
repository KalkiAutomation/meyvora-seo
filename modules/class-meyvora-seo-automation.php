<?php
/**
 * SEO Automation rules engine: IF/THEN rules applied on save_post and on-demand.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Automation
 */
class Meyvora_SEO_Automation {

	protected Meyvora_SEO_Loader $loader;
	protected Meyvora_SEO_Options $options;

	/**
	 * Flag to prevent recursion when automation updates post status.
	 *
	 * @var bool
	 */
	protected static $running = false;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Register save_post, REST-after-insert, and AJAX for automation.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'save_post', $this, 'run_rules_on_save', 20, 3 );
		$this->loader->add_action( 'rest_after_insert_post', $this, 'run_rules_after_rest_insert', 20, 3 );
		$this->loader->add_action( 'rest_after_insert_page', $this, 'run_rules_after_rest_insert', 20, 3 );
		add_action( 'woocommerce_loaded', array( $this, 'register_woocommerce_rest_hook' ), 10, 0 );
		add_action( 'wp_ajax_meyvora_seo_run_automation', array( $this, 'ajax_run_automation' ) );
	}

	/**
	 * Register rest_after_insert_product when WooCommerce has loaded (so product post type exists).
	 * Called on woocommerce_loaded to avoid missing the hook when WC activates after this plugin.
	 */
	public function register_woocommerce_rest_hook(): void {
		if ( post_type_exists( 'product' ) ) {
			add_action( 'rest_after_insert_product', array( $this, 'run_rules_after_rest_insert' ), 20, 3 );
		}
	}

	/**
	 * Get stored rules (array of rule arrays).
	 *
	 * @return array<int, array{id: string, enabled: bool, conditions: array, actions: array}>
	 */
	public function get_rules(): array {
		$raw = get_option( MEYVORA_SEO_AUTOMATION_RULES_OPTION, '[]' );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Save rules to wp_options.
	 *
	 * @param array $rules Array of rule arrays.
	 * @return bool
	 */
	public function save_rules( array $rules ): bool {
		return update_option( MEYVORA_SEO_AUTOMATION_RULES_OPTION, wp_json_encode( $rules ), true );
	}

	/**
	 * Run rules when a post is saved (classic editor). Skips when REST request;
	 * REST saves use rest_after_insert_* so meta is already saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  True if update, false if new post.
	 */
	public function run_rules_on_save( int $post_id, WP_Post $post, bool $update = true ): void {
		if ( get_transient( 'meyvora_auto_ran_' . $post_id ) ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( self::$running || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			$post_types = apply_filters( 'meyvora_seo_automation_post_types', $post_types );
			if ( ! in_array( $post->post_type, $post_types, true ) ) {
				return;
			}
		}
		if ( ! $update && ! apply_filters( 'meyvora_seo_automation_apply_to_new', true ) ) {
			return;
		}
		set_transient( 'meyvora_auto_ran_' . $post_id, 1, 30 );
		$this->run_rules_for_post( $post_id );
	}

	/**
	 * Run rules after REST API has saved the post and its meta (block editor).
	 *
	 * @param WP_Post         $post     Post object.
	 * @param WP_REST_Request $request Request object.
	 * @param bool            $creating True if creating, false if updating.
	 */
	public function run_rules_after_rest_insert( WP_Post $post, WP_REST_Request $request, bool $creating ): void {
		$post_id = $post->ID;
		if ( get_transient( 'meyvora_auto_ran_' . $post_id ) ) {
			return;
		}
		if ( self::$running || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			$post_types = apply_filters( 'meyvora_seo_automation_post_types', $post_types );
			if ( ! in_array( $post->post_type, $post_types, true ) ) {
				return;
			}
		}
		if ( $creating && ! apply_filters( 'meyvora_seo_automation_apply_to_new', true ) ) {
			return;
		}
		set_transient( 'meyvora_auto_ran_' . $post_id, 1, 30 );
		$this->run_rules_for_post( $post_id );
	}

	/**
	 * Run all enabled rules for a single post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function run_rules_for_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		self::$running = true;
		foreach ( $this->get_rules() as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( $this->evaluate_rule( $post_id, $rule ) ) {
				$this->run_actions( $post_id, $rule );
			}
		}
		self::$running = false;
	}

	/**
	 * Apply all enabled rules to every supported post (batch).
	 *
	 * @return array{processed: int, errors: array}
	 */
	public function apply_to_all(): array {
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		$post_types = apply_filters( 'meyvora_seo_automation_post_types', $post_types );
		$query = new WP_Query( array(
			'post_type'      => $post_types,
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$ids = is_array( $query->posts ) ? $query->posts : array();
		$processed = 0;
		$errors = array();
		foreach ( $ids as $id ) {
			try {
				$this->run_rules_for_post( (int) $id );
				$processed++;
			} catch ( Exception $e ) {
				$errors[] = array( 'post_id' => (int) $id, 'message' => $e->getMessage() );
			}
		}
		return array( 'processed' => $processed, 'errors' => $errors );
	}

	/**
	 * Evaluate a single rule. If logic is OR, returns true when any condition passes; if AND, all must pass.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $rule    Rule with 'conditions' array and optional 'logic' ('AND'|'OR', default AND).
	 * @return bool
	 */
	public function evaluate_rule( int $post_id, array $rule ): bool {
		$conditions = isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ? $rule['conditions'] : array();
		if ( empty( $conditions ) ) {
			return false;
		}
		$logic = strtoupper( (string) ( $rule['logic'] ?? 'AND' ) ) === 'OR' ? 'OR' : 'AND';
		foreach ( $conditions as $cond ) {
			$match = $this->evaluate_condition( $post_id, $cond );
			if ( $logic === 'OR' && $match ) {
				return true;
			}
			if ( $logic === 'AND' && ! $match ) {
				return false;
			}
		}
		return $logic === 'AND';
	}

	/**
	 * Get current value for a condition field for the given post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field key.
	 * @return mixed
	 */
	protected function get_field_value( int $post_id, string $field ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}
		$meta_key = function( $key ) use ( $post_id ) {
			return apply_filters( 'meyvora_seo_post_meta_key', $key, $post_id );
		};

		switch ( $field ) {
			case 'post_type':
				return $post->post_type;
			case 'post_status':
				return $post->post_status;
			case 'category':
				$tax = ( $post->post_type === 'product' && taxonomy_exists( 'product_cat' ) ) ? 'product_cat' : 'category';
				$terms = get_the_terms( $post_id, $tax );
				if ( ! is_array( $terms ) ) {
					return array();
				}
				$out = array();
				foreach ( $terms as $t ) {
					$out[] = $t->slug;
					$out[] = $t->name;
				}
				return $out;
			case 'tag':
				$tax = ( $post->post_type === 'product' && taxonomy_exists( 'product_tag' ) ) ? 'product_tag' : 'post_tag';
				$terms = get_the_terms( $post_id, $tax );
				if ( ! is_array( $terms ) ) {
					return array();
				}
				$out = array();
				foreach ( $terms as $t ) {
					$out[] = $t->slug;
					$out[] = $t->name;
				}
				return $out;
			case 'has_focus_keyword':
				$v = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_FOCUS_KEYWORD ), true );
				if ( is_string( $v ) && trim( $v ) !== '' ) {
					return true;
				}
				if ( is_array( $v ) && ! empty( $v ) ) {
					return true;
				}
				return false;
			case 'has_schema':
				$v = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_SCHEMA_TYPE ), true );
				return is_string( $v ) && trim( $v ) !== '' && strtolower( $v ) !== 'none';
			case 'seo_score':
				$v = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_SCORE ), true );
				return is_numeric( $v ) ? (int) $v : null;
			default:
				return null;
		}
	}

	/**
	 * Evaluate a single condition.
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $condition  Keys: field, operator, value.
	 * @return bool
	 */
	public function evaluate_condition( int $post_id, array $condition ): bool {
		$field    = isset( $condition['field'] ) ? $condition['field'] : '';
		$operator = isset( $condition['operator'] ) ? $condition['operator'] : '';
		$value    = isset( $condition['value'] ) ? $condition['value'] : '';

		$current = $this->get_field_value( $post_id, $field );

		switch ( $operator ) {
			case 'is_empty':
				if ( $field === 'has_focus_keyword' || $field === 'has_schema' ) {
					return $current === false;
				}
				if ( is_array( $current ) ) {
					return empty( $current );
				}
				return $current === null || $current === '' || $current === false;
			case 'equals':
				if ( $field === 'seo_score' && is_numeric( $value ) ) {
					return $current !== null && (int) $current === (int) $value;
				}
				if ( $field === 'category' || $field === 'tag' ) {
					$val_str = is_string( $value ) ? trim( $value ) : '';
					if ( $val_str === '' ) {
						return false;
					}
					return is_array( $current ) && in_array( $val_str, $current, true );
				}
				return (string) $current === (string) $value;
			case 'contains':
				if ( is_array( $current ) ) {
					$val_str = is_string( $value ) ? trim( $value ) : '';
					foreach ( $current as $c ) {
						if ( is_string( $c ) && stripos( $c, $val_str ) !== false ) {
							return true;
						}
					}
					return false;
				}
				return is_string( $current ) && stripos( $current, (string) $value ) !== false;
			case 'greater_than':
				$num = is_numeric( $current ) ? (float) $current : null;
				return $num !== null && is_numeric( $value ) && $num > (float) $value;
			case 'less_than':
				$num = is_numeric( $current ) ? (float) $current : null;
				return $num !== null && is_numeric( $value ) && $num < (float) $value;
			default:
				return false;
		}
	}

	/**
	 * Run actions for a rule on the given post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $rule    Rule with 'actions' array of { action, value? }.
	 */
	protected function run_actions( int $post_id, array $rule ): void {
		$actions = isset( $rule['actions'] ) && is_array( $rule['actions'] ) ? $rule['actions'] : array();
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		$meta_key = function( $key ) use ( $post_id ) {
			return apply_filters( 'meyvora_seo_post_meta_key', $key, $post_id );
		};

		foreach ( $actions as $action_item ) {
			$action = is_array( $action_item ) ? ( $action_item['action'] ?? '' ) : $action_item;
			$value  = is_array( $action_item ) && isset( $action_item['value'] ) ? $action_item['value'] : '';

			switch ( $action ) {
				case 'auto_title_template':
					$template = is_string( $value ) ? $value : '';
					if ( $template !== '' ) {
						$title = $this->replace_template_vars( $template, $post_id );
						update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_TITLE ), $title );
					}
					break;
				case 'auto_desc_excerpt':
					$excerpt = has_excerpt( $post_id ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 35 );
					if ( $excerpt !== '' ) {
						update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_DESCRIPTION ), $excerpt );
					}
					break;
				case 'auto_desc_content':
					$desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 35 );
					if ( $desc !== '' ) {
						update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_DESCRIPTION ), $desc );
					}
					break;
				case 'auto_schema_type':
					$schema = is_string( $value ) ? sanitize_text_field( $value ) : '';
					if ( $schema !== '' ) {
						update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_SCHEMA_TYPE ), $schema );
					}
					break;
				case 'auto_noindex':
					update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_NOINDEX ), '1' );
					break;
				case 'auto_canonical_pattern':
					$pattern = is_string( $value ) ? $value : '';
					if ( $pattern !== '' ) {
						$canonical = $this->replace_template_vars( $pattern, $post_id );
						$canonical = esc_url_raw( $canonical );
						if ( $canonical !== '' ) {
							update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_CANONICAL ), $canonical );
						}
					}
					break;
				case 'auto_set_status':
					$status = is_string( $value ) ? $value : 'pending';
					$allowed = array( 'draft', 'pending', 'private' );
					if ( in_array( $status, $allowed, true ) ) {
						wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ) );
					}
					break;
				case 'auto_keyword_from_title':
					$existing = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_FOCUS_KEYWORD ), true );
					if ( is_string( $existing ) && trim( $existing ) !== '' && trim( $existing ) !== '[]' ) {
						break;
					}
					if ( is_array( $existing ) && ! empty( $existing ) ) {
						break;
					}
					$title = $post->post_title;
					$stopwords = array( 'a', 'the', 'and', 'or', 'in', 'of', 'to', 'for', 'with', 'on', 'at', 'by' );
					$words = array_filter( preg_split( '/\s+/', wp_strip_all_tags( $title ), -1, PREG_SPLIT_NO_EMPTY ), function ( $w ) use ( $stopwords ) {
						return ! in_array( strtolower( $w ), $stopwords, true );
					} );
					$words = array_slice( array_values( $words ), 0, 3 );
					if ( ! empty( $words ) ) {
						$phrase = implode( ' ', $words );
						update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_FOCUS_KEYWORD ), wp_json_encode( array( $phrase ) ) );
					}
					break;
				case 'auto_og_image_from_featured':
					$current_og = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_OG_IMAGE ), true );
					if ( $current_og !== '' && $current_og !== '0' && (int) $current_og > 0 ) {
						break;
					}
					$thumb_id = (int) get_post_thumbnail_id( $post_id );
					if ( $thumb_id > 0 ) {
						update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_OG_IMAGE ), $thumb_id );
					}
					break;
				case 'auto_canonical_archive':
					$current_canonical = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_CANONICAL ), true );
					if ( is_string( $current_canonical ) && trim( $current_canonical ) !== '' ) {
						break;
					}
					$archive_url = get_post_type_archive_link( $post->post_type );
					if ( $archive_url !== false && $archive_url !== '' ) {
						update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_CANONICAL ), esc_url_raw( $archive_url ) );
					}
					break;
				case 'auto_ai_generate_description':
					$existing_desc = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_DESCRIPTION ), true );
					if ( $existing_desc === '' || ( is_array( $action_item ) && ! empty( $action_item['overwrite'] ) ) ) {
						$generated = $this->call_ai_for_post( $post_id, 'auto_ai_generate_description' );
						if ( $generated !== '' ) {
							update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_DESCRIPTION ), sanitize_text_field( $generated ) );
						}
					}
					break;
				case 'auto_ai_generate_title':
					$existing_title = get_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_TITLE ), true );
					if ( $existing_title === '' || ( is_array( $action_item ) && ! empty( $action_item['overwrite'] ) ) ) {
						$generated = $this->call_ai_for_post( $post_id, 'auto_ai_generate_title' );
						if ( $generated !== '' ) {
							update_post_meta( $post_id, $meta_key( MEYVORA_SEO_META_TITLE ), sanitize_text_field( $generated ) );
						}
					}
					break;
			}
		}
	}

	/**
	 * Replace placeholders in a template string.
	 *
	 * @param string $template Template with {title}, {slug}, {product_name}, etc.
	 * @param int    $post_id  Post ID.
	 * @return string
	 */
	protected function replace_template_vars( string $template, int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return $template;
		}
		$replace = array(
			'{title}'         => $post->post_title,
			'{slug}'          => $post->post_name,
			'{site_title}'    => get_bloginfo( 'name' ),
			'{product_name}'  => $post->post_title,
			'{category}'      => '',
		);
		if ( $post->post_type === 'product' && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$replace['{product_name}'] = $product->get_name();
			}
		}
		$terms = get_the_terms( $post_id, 'category' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$replace['{category}'] = $terms[0]->name;
		}
		$product_cat = get_the_terms( $post_id, 'product_cat' );
		if ( is_array( $product_cat ) && ! empty( $product_cat ) ) {
			$replace['{category}'] = $product_cat[0]->name;
		}
		return str_replace( array_keys( $replace ), array_values( $replace ), $template );
	}

	/**
	 * Call AI module to generate meta description or SEO title for a post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $action_type One of 'auto_ai_generate_description', 'auto_ai_generate_title'.
	 * @return string Generated text or empty string on failure.
	 */
	private function call_ai_for_post( int $post_id, string $action_type ): string {
		if ( ! class_exists( 'Meyvora_SEO_AI' ) ) {
			return '';
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
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
		$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 80 );
		$title   = $post->post_title;
		$keyword = (string) get_post_meta(
			$post_id,
			apply_filters( 'meyvora_seo_post_meta_key', MEYVORA_SEO_META_FOCUS_KEYWORD, $post_id ),
			true
		);
		$ai = new Meyvora_SEO_AI( $this->loader, $this->options );
		if ( $action_type === 'auto_ai_generate_description' ) {
			$prompt = "Write a compelling, accurate meta description of 120–155 characters for this post.\n"
				. "Focus keyword: {$keyword}\n"
				. "Excerpt: {$excerpt}\n"
				. "Return ONLY the meta description text, no quotes or labels.";
		} else {
			$prompt = "Write an SEO-optimised title tag of 50–60 characters for this post.\n"
				. "Current title: {$title}\n"
				. "Focus keyword: {$keyword}\n"
				. "Return ONLY the title text, no quotes or labels.";
		}
		return $ai->call_ai_public( $api_key, $prompt );
	}

	/**
	 * AJAX: run automation rules on a single post (e.g. "Run rules now" in block editor).
	 */
	public function ajax_run_automation(): void {
		check_ajax_referer( 'meyvora_seo_nonce', 'nonce' );
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post or permission denied.', 'meyvora-seo' ) ) );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => __( 'Post not found.', 'meyvora-seo' ) ) );
		}
		delete_transient( 'meyvora_auto_ran_' . $post_id );
		$this->run_rules_for_post( $post_id );
		wp_send_json_success( array(
			'message' => __( 'Automation rules applied.', 'meyvora-seo' ),
		) );
	}
}
