<?php
/**
 * Admin UI for SEO Automation rules: list, add/edit, apply to all.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Automation_Admin
 */
class Meyvora_SEO_Automation_Admin {

	protected Meyvora_SEO_Loader $loader;
	protected Meyvora_SEO_Options $options;

	/**
	 * @var Meyvora_SEO_Automation|null
	 */
	protected $automation = null;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	/**
	 * Get automation engine instance.
	 *
	 * @return Meyvora_SEO_Automation
	 */
	protected function get_automation(): Meyvora_SEO_Automation {
		if ( $this->automation === null ) {
			$file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-automation.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
			$this->automation = new Meyvora_SEO_Automation( $this->loader, $this->options );
		}
		return $this->automation;
	}

	/**
	 * Register menu, actions, AJAX.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'admin_menu', $this, 'register_submenu', 13, 0 );
		$this->loader->add_action( 'admin_init', $this, 'handle_save_rules', 10, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_meyvora_seo_automation_apply_all', array( $this, 'ajax_apply_to_all' ) );
	}

	/**
	 * Add Automation submenu.
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'meyvora-seo',
			__( 'Automation', 'meyvora-seo' ),
			__( 'Automation', 'meyvora-seo' ),
			'manage_options',
			'meyvora-seo-automation',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Handle form save of rules (POST).
	 */
	public function handle_save_rules(): void {
		if ( ! isset( $_POST['meyvora_automation_save'] ) || ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'meyvora_automation_save' ) ) {
			return;
		}
		// JSON payload; decoded and sanitized by sanitize_rules() below.
		$raw = isset( $_POST['meyvora_automation_rules'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_automation_rules'] ) ) : '';
		if ( $raw === '' ) {
			$this->get_automation()->save_rules( array() );
			wp_safe_redirect( add_query_arg( array( 'page' => 'meyvora-seo-automation', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'meyvora-seo-automation', 'error' => '1' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$sanitized = $this->sanitize_rules( $decoded );
		$this->get_automation()->save_rules( $sanitized );
		wp_safe_redirect( add_query_arg( array( 'page' => 'meyvora-seo-automation', 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Sanitize rules array from form input.
	 *
	 * @param array $rules Raw decoded rules.
	 * @return array
	 */
	protected function sanitize_rules( array $rules ): array {
		$out = array();
		$fields = array( 'post_type', 'category', 'tag', 'post_status', 'has_focus_keyword', 'has_schema', 'seo_score' );
		$operators = array( 'equals', 'contains', 'is_empty', 'greater_than', 'less_than' );
		$action_ids = array( 'auto_title_template', 'auto_desc_excerpt', 'auto_desc_content', 'auto_schema_type', 'auto_noindex', 'auto_canonical_pattern', 'auto_set_status', 'auto_keyword_from_title', 'auto_og_image_from_featured', 'auto_canonical_archive', 'auto_ai_generate_description', 'auto_ai_generate_title' );
		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$id = isset( $rule['id'] ) && is_string( $rule['id'] ) ? sanitize_text_field( $rule['id'] ) : wp_generate_uuid4();
			$enabled = ! empty( $rule['enabled'] );
			$logic = isset( $rule['logic'] ) && strtoupper( (string) $rule['logic'] ) === 'OR' ? 'OR' : 'AND';
			$conditions = array();
			if ( isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ) {
				foreach ( $rule['conditions'] as $c ) {
					if ( ! is_array( $c ) ) {
						continue;
					}
					$field = isset( $c['field'] ) && in_array( $c['field'], $fields, true ) ? $c['field'] : 'post_type';
					$op = isset( $c['operator'] ) && in_array( $c['operator'], $operators, true ) ? $c['operator'] : 'equals';
					$val = isset( $c['value'] ) ? sanitize_text_field( (string) $c['value'] ) : '';
					$conditions[] = array( 'field' => $field, 'operator' => $op, 'value' => $val );
				}
			}
			$actions = array();
			if ( isset( $rule['actions'] ) && is_array( $rule['actions'] ) ) {
				foreach ( $rule['actions'] as $a ) {
					if ( is_array( $a ) && isset( $a['action'] ) && in_array( $a['action'], $action_ids, true ) ) {
						$actions[] = array(
							'action' => $a['action'],
							'value'  => isset( $a['value'] ) ? sanitize_text_field( (string) $a['value'] ) : '',
						);
						if ( $a['action'] === 'auto_ai_generate_description' || $a['action'] === 'auto_ai_generate_title' ) {
							$actions[ count( $actions ) - 1 ]['overwrite'] = ! empty( $a['overwrite'] );
						}
					}
				}
			}
			$out[] = array(
				'id'         => $id,
				'enabled'    => $enabled,
				'logic'      => $logic,
				'conditions' => $conditions,
				'actions'    => $actions,
			);
		}
		return $out;
	}

	/**
	 * AJAX: Apply rules to all posts.
	 */
	public function ajax_apply_to_all(): void {
		check_ajax_referer( 'meyvora_seo_automation_apply', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$result = $this->get_automation()->apply_to_all();
		wp_send_json_success( array(
			'processed' => $result['processed'],
			'errors'    => $result['errors'],
			/* translators: %d: number of posts processed */
			'message'   => sprintf( __( 'Applied rules to %d posts.', 'meyvora-seo' ), $result['processed'] ),
		) );
	}

	/**
	 * Enqueue scripts/styles on automation page.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_meyvora-seo-automation' ) {
			return;
		}
		wp_enqueue_style( 'meyvora-admin', MEYVORA_SEO_URL . 'admin/assets/css/meyvora-admin.css', array(), MEYVORA_SEO_VERSION );
		wp_enqueue_style(
			'meyvora-automation-page',
			MEYVORA_SEO_URL . 'admin/assets/css/meyvora-automation-page.css',
			array( 'meyvora-admin' ),
			MEYVORA_SEO_VERSION
		);
		wp_enqueue_script(
			'meyvora-automation',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-automation.js',
			array( 'jquery' ),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script(
			'meyvora-automation',
			'meyvoraAutomation',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'meyvora_seo_automation_apply' ),
				'i18n'  => array(
					'applying' => __( 'Applying to all posts...', 'meyvora-seo' ),
					'remove'   => __( 'Remove', 'meyvora-seo' ),
					'error'    => __( 'An error occurred.', 'meyvora-seo' ),
					'cond'     => __( 'cond.', 'meyvora-seo' ),
					'actions'  => __( 'actions', 'meyvora-seo' ),
				),
			)
		);
	}

	/**
	 * Human-readable summary of a rule (conditions + actions).
	 *
	 * @param array $rule Rule array.
	 * @return string
	 */
	public function rule_summary( array $rule ): string {
		$conditions = isset( $rule['conditions'] ) && is_array( $rule['conditions'] ) ? $rule['conditions'] : array();
		$actions = isset( $rule['actions'] ) && is_array( $rule['actions'] ) ? $rule['actions'] : array();
		$c_parts = array();
		foreach ( $conditions as $c ) {
			$f = isset( $c['field'] ) ? $c['field'] : '';
			$o = isset( $c['operator'] ) ? $c['operator'] : '';
			$v = isset( $c['value'] ) ? $c['value'] : '';
			$c_parts[] = $f . ' ' . $o . ( $v !== '' ? ' "' . $v . '"' : '' );
		}
		$a_parts = array();
		foreach ( $actions as $a ) {
			$act = is_array( $a ) ? ( $a['action'] ?? '' ) : $a;
			if ( $act !== '' ) {
				$a_parts[] = str_replace( 'auto_', '', $act );
			}
		}
		$if = empty( $c_parts ) ? __( 'no conditions', 'meyvora-seo' ) : implode( ' AND ', $c_parts );
		$then = empty( $a_parts ) ? __( 'no actions', 'meyvora-seo' ) : implode( ', ', $a_parts );
		return sprintf( 'IF %s → THEN %s', $if, $then );
	}

	/**
	 * Render Automation admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$automation = $this->get_automation();
		$rules = $automation->get_rules();
		$view = MEYVORA_SEO_PATH . 'admin/views/automation.php';
		if ( file_exists( $view ) ) {
			include $view;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Automation', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'View not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}
}