<?php
/**
 * Technical settings: robots.txt and .htaccess editors.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Technical
 */
class Meyvora_SEO_Technical {

	/**
	 * Note on ABSPATH usage: this class manages robots.txt and .htaccess which WordPress Core
	 * itself manages at ABSPATH. Using ABSPATH here is correct and intentional — these files
	 * must live at the web root to function. See https://developer.wordpress.org/reference/functions/get_home_path/
	 */

	const OPTION_HTACCESS_BACKUPS = 'meyvora_seo_htaccess_backups';
	const HTACCESS_BACKUPS_MAX   = 10;
	const NONCE_ACTION           = 'meyvora_technical_save';
	const NONCE_NAME             = 'meyvora_technical_nonce';
	const WP_BEGIN_MARKER        = '# BEGIN WordPress';
	const WP_END_MARKER          = '# END WordPress';

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

	public function register_hooks(): void {
		$this->loader->add_action( 'admin_init', $this, 'handle_save', 10, 0 );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
		add_action( 'wp_ajax_meyvora_technical_test_url', array( $this, 'ajax_test_url' ) );
	}

	/**
	 * Enqueue CSS/JS for Technical tab (robots syntax highlight, Test URL).
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'toplevel_page_meyvora-seo' && $hook_suffix !== 'meyvora-seo_page_meyvora-seo-settings' ) {
			return;
		}
		$css = MEYVORA_SEO_PATH . 'admin/assets/css/meyvora-technical.css';
		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				'meyvora-technical',
				MEYVORA_SEO_URL . 'admin/assets/css/meyvora-technical.css',
				array( 'meyvora-seo-admin' ),
				MEYVORA_SEO_VERSION
			);
		}
		wp_enqueue_script(
			'meyvora-technical-robots',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-technical-robots.js',
			array(),
			MEYVORA_SEO_VERSION,
			true
		);
		wp_localize_script(
			'meyvora-technical-robots',
			'meyvoraTechnicalRobots',
			array(
				'nonce'           => wp_create_nonce( 'meyvora_technical_test_url' ),
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'defaultTemplate' => self::get_default_robots_template(),
				'i18nTest'        => array(
					'allowedLabel'   => __( '✓ Allowed', 'meyvora-seo' ),
					'disallowedLabel' => __( '✗ Disallowed', 'meyvora-seo' ),
					'errorLabel'     => __( 'Error', 'meyvora-seo' ),
				),
			)
		);
	}

	/**
	 * Whether robots.txt is managed virtually (filter); if true we use option, not physical file.
	 */
	public static function robots_use_virtual(): bool {
		return (bool) apply_filters( 'meyvora_seo_robots_txt_virtual', false );
	}

	/**
	 * Get path to physical robots.txt in WordPress root.
	 */
	public static function get_robots_file_path(): string {
		return trailingslashit( ABSPATH ) . 'robots.txt'; // ABSPATH intentional: robots.txt must live at the WordPress web root.
	}

	/**
	 * Get path to .htaccess in WordPress root.
	 */
	public static function get_htaccess_path(): string {
		return trailingslashit( ABSPATH ) . '.htaccess'; // ABSPATH intentional: .htaccess must live at the WordPress web root.
	}

	/**
	 * Read robots.txt content (physical file or virtual from filter).
	 */
	public function get_robots_content(): string {
		if ( self::robots_use_virtual() ) {
			$content = get_option( 'meyvora_seo_robots_txt', '' );
			return is_string( $content ) ? $content : '';
		}
		$path = self::get_robots_file_path();
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$content = file_get_contents( $path );
		return is_string( $content ) ? $content : '';
	}

	/**
	 * Write robots.txt (physical file or option when virtual).
	 */
	public function set_robots_content( string $content ): bool {
		$content = self::ensure_sitemap_line( $content );
		if ( self::robots_use_virtual() ) {
			update_option( 'meyvora_seo_robots_txt', $content );
			return true;
		}
		$path = self::get_robots_file_path();
		$dir = trailingslashit( ABSPATH ); // ABSPATH intentional: robots.txt must live at the WordPress web root.
		// Direct filesystem check for robots.txt; WP_Filesystem would require credentials form.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		return ( is_writable( $dir ) && ( ! file_exists( $path ) || is_writable( $path ) ) ) && file_put_contents( $path, $content ) !== false;
	}

	/**
	 * Default robots.txt template.
	 */
	public static function get_default_robots_template(): string {
		$sitemap_url = home_url( '/sitemap.xml' );
		return "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /wp-includes/\n\nSitemap: " . $sitemap_url . "\n";
	}

	/**
	 * Ensure a Sitemap line exists; append if missing.
	 */
	public static function ensure_sitemap_line( string $content ): string {
		$sitemap_url = home_url( '/sitemap.xml' );
		if ( preg_match( '/^\s*Sitemap\s*:/im', $content ) ) {
			return $content;
		}
		$content = trim( $content );
		return $content === '' ? 'Sitemap: ' . $sitemap_url . "\n" : $content . "\n\nSitemap: " . $sitemap_url . "\n";
	}

	/**
	 * Validate robots.txt; return array of warnings.
	 *
	 * @return array<string>
	 */
	public static function validate_robots( string $content ): array {
		$warnings = array();
		if ( ! preg_match( '/^\s*Sitemap\s*:/im', $content ) ) {
			$warnings[] = __( 'Sitemap line is missing. Add a "Sitemap:" line so search engines can find your sitemap.', 'meyvora-seo' );
		}
		if ( preg_match( '/^\s*Disallow\s*:\s*\/\s*$/im', $content ) ) {
			$warnings[] = __( 'Entire site is disallowed (Disallow: /). Search engines may not index your site.', 'meyvora-seo' );
		}
		return $warnings;
	}

	/**
	 * Google-style robots.txt test: for a given URL path and user-agent, return true if allowed.
	 * Uses longest matching rule; default allow.
	 */
	public static function test_robots_url( string $robots_content, string $path, string $user_agent = '*' ): bool {
		$path = trim( $path );
		if ( $path === '' ) {
			$path = '/';
		} elseif ( $path[0] !== '/' ) {
			$path = '/' . $path;
		}
		$path_for_match = $path;
		$lines = array_filter( array_map( 'trim', explode( "\n", $robots_content ) ) );
		$current_ua = null;
		$allow_rules = array();
		$disallow_rules = array();
		foreach ( $lines as $line ) {
			if ( preg_match( '/^User-agent\s*:\s*(.+)$/i', $line, $m ) ) {
				$current_ua = trim( $m[1] );
				continue;
			}
			if ( $current_ua === null ) {
				continue;
			}
			if ( $current_ua !== '*' && stripos( $user_agent, $current_ua ) === false && stripos( $current_ua, $user_agent ) === false ) {
				continue;
			}
			if ( preg_match( '/^Allow\s*:\s*(.+)$/i', $line, $m ) ) {
				$allow_rules[] = trim( $m[1] );
				continue;
			}
			if ( preg_match( '/^Disallow\s*:\s*(.+)$/i', $line, $m ) ) {
				$disallow_rules[] = trim( $m[1] );
			}
		}
		$best_allow = -1;
		$best_disallow = -1;
		foreach ( $allow_rules as $rule ) {
			$pattern = self::robots_rule_to_regex( $rule );
			if ( $pattern !== null && preg_match( $pattern, $path_for_match ) ) {
				$len = strlen( $rule );
				if ( $len > $best_allow ) {
					$best_allow = $len;
				}
			}
		}
		foreach ( $disallow_rules as $rule ) {
			$pattern = self::robots_rule_to_regex( $rule );
			if ( $pattern !== null && preg_match( $pattern, $path_for_match ) ) {
				$len = strlen( $rule );
				if ( $len > $best_disallow ) {
					$best_disallow = $len;
				}
			}
		}
		if ( $best_allow >= 0 || $best_disallow >= 0 ) {
			return $best_allow >= $best_disallow;
		}
		return true;
	}

	/**
	 * Convert a robots.txt path rule to regex for matching (Google-style: * wildcard, $ end).
	 */
	private static function robots_rule_to_regex( string $rule ): ?string {
		if ( $rule === '' ) {
			return null;
		}
		if ( $rule[0] !== '/' ) {
			$rule = '/' . $rule;
		}
		$regex = preg_quote( $rule, '#' );
		$regex = str_replace( array( '\*', '\$' ), array( '.*', '$' ), $regex );
		return '#^' . $regex . '#';
	}

	/**
	 * AJAX: Test URL against current robots.txt content.
	 */
	public function ajax_test_url(): void {
		check_ajax_referer( 'meyvora_technical_test_url', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'meyvora-seo' ) ) );
		}
		$path = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
		$content = isset( $_POST['robots'] ) ? sanitize_textarea_field( wp_unslash( $_POST['robots'] ) ) : '';
		$content = is_string( $content ) ? $content : '';
		$allowed = self::test_robots_url( $content, $path );
		wp_send_json_success( array( 'allowed' => $allowed, 'path' => $path ) );
	}

	/**
	 * Parse .htaccess into: before WordPress block, WordPress block (locked), after block.
	 *
	 * @return array{ before: string, wp_block: string, after: string }
	 */
	public static function parse_htaccess( string $content ): array {
		$before = '';
		$wp_block = '';
		$after = '';
		$begin = strpos( $content, self::WP_BEGIN_MARKER );
		$end = strpos( $content, self::WP_END_MARKER );
		if ( $begin === false && $end === false ) {
			return array( 'before' => $content, 'wp_block' => '', 'after' => '' );
		}
		if ( $begin !== false && $end !== false && $end > $begin ) {
			$before = substr( $content, 0, $begin );
			$wp_block = substr( $content, $begin, $end - $begin + strlen( self::WP_END_MARKER ) );
			$after = substr( $content, $end + strlen( self::WP_END_MARKER ) );
		} elseif ( $begin !== false ) {
			$before = substr( $content, 0, $begin );
			$wp_block = substr( $content, $begin );
		} else {
			$after = $content;
		}
		return array( 'before' => $before, 'wp_block' => $wp_block, 'after' => $after );
	}

	/**
	 * Get .htaccess content; return full content and writable status.
	 *
	 * @return array{ content: string, writable: bool, before: string, wp_block: string, after: string }
	 */
	public function get_htaccess_data(): array {
		$path = self::get_htaccess_path();
		$content = '';
		if ( file_exists( $path ) ) {
			$content = file_get_contents( $path );
			$content = is_string( $content ) ? $content : '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$dir_writable = is_writable( trailingslashit( ABSPATH ) ); // ABSPATH intentional: checking web root writability for .htaccess.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$file_writable = $path && ( ! file_exists( $path ) ? $dir_writable : is_writable( $path ) );
		$parsed = self::parse_htaccess( $content );
		return array(
			'content'    => $content,
			'writable'   => (bool) $file_writable,
			'before'     => $parsed['before'],
			'wp_block'   => $parsed['wp_block'],
			'after'      => $parsed['after'],
		);
	}

	/**
	 * Save .htaccess: merge before + wp_block + after, backup, then write.
	 */
	public function save_htaccess( string $before, string $after ): array {
		$data = $this->get_htaccess_data();
		$new_content = trim( $before ) . ( $data['wp_block'] !== '' ? "\n" . $data['wp_block'] : '' ) . ( trim( $after ) !== '' ? "\n" . trim( $after ) : '' );
		$new_content = trim( $new_content ) . "\n";
		$path = self::get_htaccess_path();
		$this->backup_htaccess( $data['content'] );
		if ( ! $data['writable'] ) {
			return array( 'success' => false, 'message' => __( 'File is not writable.', 'meyvora-seo' ) );
		}
		if ( file_put_contents( $path, $new_content ) === false ) {
			return array( 'success' => false, 'message' => __( 'Failed to write file.', 'meyvora-seo' ) );
		}
		return array( 'success' => true, 'message' => __( 'Saved.', 'meyvora-seo' ) );
	}

	/**
	 * Store last 10 .htaccess backups in option.
	 */
	protected function backup_htaccess( string $content ): void {
		$backups = get_option( self::OPTION_HTACCESS_BACKUPS, array() );
		if ( ! is_array( $backups ) ) {
			$backups = array();
		}
		array_unshift( $backups, array( 'content' => $content, 'saved_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		// Keep only the 10 most recent backups
		$backups = array_slice( $backups, 0, self::HTACCESS_BACKUPS_MAX );
		update_option( self::OPTION_HTACCESS_BACKUPS, $backups, false );
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( isset( $_POST['meyvora_save_robots'] ) ) {
			$content = isset( $_POST['meyvora_robots_txt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_robots_txt'] ) ) : '';
			$content = is_string( $content ) ? $content : '';
			$this->set_robots_content( $content );
			add_settings_error(
				'meyvora_technical',
				'robots_saved',
				__( 'Robots.txt saved.', 'meyvora-seo' ),
				'success'
			);
		}
		if ( isset( $_POST['meyvora_save_htaccess'] ) ) {
			$before = isset( $_POST['meyvora_htaccess_before'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_htaccess_before'] ) ) : '';
			$after  = isset( $_POST['meyvora_htaccess_after'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meyvora_htaccess_after'] ) ) : '';
			$before = is_string( $before ) ? $before : '';
			$after  = is_string( $after ) ? $after : '';
			$result = $this->save_htaccess( $before, $after );
			add_settings_error(
				'meyvora_technical',
				'htaccess_saved',
				$result['message'],
				$result['success'] ? 'success' : 'error'
			);
		}
	}

	/**
	 * Get data for the Technical view (robots content, validation, htaccess data, etc.).
	 *
	 * @return array<string, mixed>
	 */
	public function get_view_data(): array {
		$robots_content = $this->get_robots_content();
		if ( $robots_content === '' ) {
			$robots_content = self::get_default_robots_template();
		}
		$robots_content = self::ensure_sitemap_line( $robots_content );
		$robots_warnings = self::validate_robots( $robots_content );
		$web_root = trailingslashit( ABSPATH ); // ABSPATH intentional: checking web root writability for robots.txt.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$robots_writable = self::robots_use_virtual() || ( is_writable( $web_root ) && ( ! file_exists( self::get_robots_file_path() ) || is_writable( self::get_robots_file_path() ) ) );
		$htaccess = $this->get_htaccess_data();
		return array(
			'robots_content'    => $robots_content,
			'robots_warnings'   => $robots_warnings,
			'robots_writable'   => $robots_writable,
			'robots_virtual'    => self::robots_use_virtual(),
			'htaccess_before'   => $htaccess['before'],
			'htaccess_wp_block' => $htaccess['wp_block'],
			'htaccess_after'    => $htaccess['after'],
			'htaccess_writable' => $htaccess['writable'],
			'test_url_nonce'    => wp_create_nonce( 'meyvora_technical_test_url' ),
		);
	}

	/**
	 * Render the Technical tab content (robots.txt + .htaccess editors).
	 */
	public function render_tab(): void {
		$data = $this->get_view_data();
		$view_file = MEYVORA_SEO_PATH . 'admin/views/technical.php';
		if ( file_exists( $view_file ) ) {
			include $view_file;
		}
	}
}
