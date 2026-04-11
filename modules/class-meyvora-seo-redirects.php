<?php
/**
 * Redirect manager: DB table, template_redirect, admin UI, 404 log, CSV import/export.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Table from prefix; REQUEST_URI/REFERER for redirect matching only.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Redirects {

	const TABLE_REDIRECTS = 'meyvora_seo_redirects';
	const TABLE_404 = 'meyvora_seo_404_log';
	const CACHE_KEY = 'meyvora_seo_redirect_rules';

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
		$this->loader->add_action( 'template_redirect', $this, 'do_redirect', 1, 0 );
		$this->loader->add_action( 'template_redirect', $this, 'log_404_if_not_found', 999, 0 );
		$this->loader->add_action( 'pre_post_update', $this, 'store_old_permalink', 10, 2 );
		$this->loader->add_action( 'post_updated', $this, 'maybe_create_redirect_on_slug_change', 10, 3 );
		add_action( 'meyvora_seo_404_log_cleanup', array( $this, 'clean_old_404_log' ) );
		if ( ! wp_next_scheduled( 'meyvora_seo_404_log_cleanup' ) ) {
			wp_schedule_event( time(), 'weekly', 'meyvora_seo_404_log_cleanup' );
		}
		if ( is_admin() ) {
			add_action( 'admin_init', array( $this, 'maybe_create_tables' ), 5 );
			add_action( 'admin_notices', array( $this, 'redirect_created_notice' ), 10, 0 );
		}
	}

	public function redirect_created_notice(): void {
		$data = get_transient( 'meyvora_seo_redirect_notice' );
		if ( ! is_array( $data ) || empty( $data['from'] ) || empty( $data['to'] ) ) {
			return;
		}
		delete_transient( 'meyvora_seo_redirect_notice' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Redirect created:', 'meyvora-seo' ) . ' <code>' . esc_html( $data['from'] ) . '</code> → <code>' . esc_html( $data['to'] ) . '</code></p></div>';
	}

	/**
	 * Store permalink before update for slug-change detection.
	 *
	 * @param int $post_id Post ID.
	 */
	public function store_old_permalink( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}
		$url = get_permalink( $post_id );
		if ( $url ) {
			set_transient( 'meyvora_seo_old_permalink_' . $post_id, $url, 60 );
		}
	}

	/**
	 * If slug changed, create 301 from old URL to new and set admin notice.
	 *
	 * @param int     $post_id     Post ID.
	 * @param WP_Post $post_after  Post after update.
	 * @param WP_Post $post_before Post before update.
	 */
	public function maybe_create_redirect_on_slug_change( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
		if ( $post_before->post_name === $post_after->post_name ) {
			delete_transient( 'meyvora_seo_old_permalink_' . $post_id );
			return;
		}
		$old_url = get_transient( 'meyvora_seo_old_permalink_' . $post_id );
		delete_transient( 'meyvora_seo_old_permalink_' . $post_id );
		if ( ! is_string( $old_url ) || $old_url === '' ) {
			return;
		}
		$new_url = get_permalink( $post_id );
		if ( ! $new_url || $old_url === $new_url ) {
			return;
		}
		$old_path = wp_parse_url( $old_url, PHP_URL_PATH );
		$new_path = wp_parse_url( $new_url, PHP_URL_PATH );
		if ( ! $old_path || ! $new_path ) {
			return;
		}
		$from = rtrim( $old_path, '/' ) ?: '/';
		$to = $new_url;
		if ( self::add_redirect( $from, $to, 301, __( 'Auto: slug change', 'meyvora-seo' ) ) ) {
			set_transient( 'meyvora_seo_redirect_notice', array( 'from' => $from, 'to' => $to ), 60 );
		}
	}

	public function log_404_if_not_found(): void {
		if ( ! is_404() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$referrer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
		self::log_404( $uri, $referrer );
	}

	public function maybe_create_tables(): void {
		$version = 2;
		if ( get_option( 'meyvora_seo_redirects_db_version', 0 ) >= $version ) {
			return;
		}
		self::create_tables();
		update_option( 'meyvora_seo_redirects_db_version', $version, true );
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix = $wpdb->prefix;

		$sql_redirects = "CREATE TABLE IF NOT EXISTS {$prefix}" . self::TABLE_REDIRECTS . " (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			source_url varchar(2000) NOT NULL,
			target_url varchar(2000) NOT NULL,
			redirect_type smallint(3) NOT NULL DEFAULT 301,
			is_regex tinyint(1) NOT NULL DEFAULT 0,
			hit_count bigint(20) NOT NULL DEFAULT 0,
			last_accessed datetime DEFAULT NULL,
			created_at datetime NOT NULL,
			notes text,
			PRIMARY KEY (id),
			KEY source_url (source_url(191))
		) $charset;";

		$sql_404 = "CREATE TABLE IF NOT EXISTS {$prefix}" . self::TABLE_404 . " (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			url varchar(2000) NOT NULL,
			referrer varchar(2000) DEFAULT NULL,
			hit_count bigint(20) NOT NULL DEFAULT 0,
			last_seen datetime NOT NULL,
			PRIMARY KEY (id),
			KEY url (url(191))
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_redirects );
		dbDelta( $sql_404 );
	}

	public function do_redirect(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$uri = preg_replace( '/#.*$/', '', $uri );
		// Strip query string for path-only exact matching.
		$path_only = (string) ( wp_parse_url( $uri, PHP_URL_PATH ) ?: $uri );
		$path_only = '/' . ltrim( $path_only, '/' );
		if ( $path_only !== '/' ) {
			$path_only = rtrim( $path_only, '/' );
		}
		// Keep full $uri (with query string) for regex rules.
		$uri = '/' . trim( $uri, '/' );
		if ( $uri !== '/' ) {
			$uri = rtrim( $uri, '/' );
		}
		// Try exact, no trailing slash, and trailing slash when looking up (handles /old-page vs /old-page/).
		$uri_no_slash = untrailingslashit( $path_only );
		$uri_slash    = trailingslashit( $path_only );
		$rules        = $this->get_cached_rules();
		$row          = null;
		if ( isset( $rules[ $path_only ] ) ) {
			$row = $rules[ $path_only ];
		} elseif ( isset( $rules[ $uri_no_slash ] ) ) {
			$row = $rules[ $uri_no_slash ];
		} elseif ( isset( $rules[ $uri_slash ] ) ) {
			$row = $rules[ $uri_slash ];
		}
		if ( $row !== null ) {
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_REDIRECTS;
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET hit_count = hit_count + 1, last_accessed = %s WHERE id = %d",
				current_time( 'mysql' ),
				$row['id']
			) );
			$target = $row['target_url'];
			$type = (int) $row['redirect_type'];
			if ( $type === 410 ) {
				status_header( 410 );
				exit;
			}
			if ( strpos( $target, 'http' ) !== 0 ) {
				$target = home_url( $target );
			}
			wp_safe_redirect( $target, $type, 'Meyvora SEO' );
			exit;
		}

		// Second pass: regex rules.
		$regex_rules = $this->get_cached_regex_rules();
		foreach ( $regex_rules as $row ) {
			$pattern = $row['source_url'];
			$matched = @preg_match( '#' . $pattern . '#', $uri, $matches );
			if ( $matched === false ) {
				continue;
			}
			if ( $matched !== 1 ) {
				continue;
			}
			$target = $row['target_url'];
			// Substitute $1, $2, ... in target with captured groups.
			$target = preg_replace_callback(
				'/\$(\d+)/',
				function ( $m ) use ( $matches ) {
					$n = (int) $m[1];
					return isset( $matches[ $n ] ) ? $matches[ $n ] : $m[0];
				},
				$target
			);
			$type = (int) $row['redirect_type'];
			global $wpdb;
			$table = $wpdb->prefix . self::TABLE_REDIRECTS;
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET hit_count = hit_count + 1, last_accessed = %s WHERE id = %d",
				current_time( 'mysql' ),
				$row['id']
			) );
			if ( $type === 410 ) {
				status_header( 410 );
				exit;
			}
			if ( strpos( $target, 'http' ) !== 0 ) {
				$target = home_url( $target );
			}
			wp_safe_redirect( $target, $type, 'Meyvora SEO' );
			exit;
		}
	}

	/**
	 * @return array<string, array{ id: int, target_url: string, redirect_type: int }>
	 */
	private function get_cached_rules(): array {
		$cache = get_transient( self::CACHE_KEY );
		if ( is_array( $cache ) && isset( $cache['exact'] ) ) {
			return $cache['exact'];
		}
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_REDIRECTS;
		$rows = $wpdb->get_results( "SELECT id, source_url, target_url, redirect_type, is_regex FROM {$table}", ARRAY_A );
		$exact = array();
		$regex = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$is_regex = ! empty( $row['is_regex'] );
				if ( $is_regex ) {
					$regex[] = array(
						'id'            => (int) $row['id'],
						'source_url'    => $row['source_url'],
						'target_url'    => $row['target_url'],
						'redirect_type' => (int) $row['redirect_type'],
					);
				} else {
					$src = '/' . trim( $row['source_url'], '/' );
					if ( $src === '//' ) {
						$src = '/';
					}
					$exact[ $src ] = array(
						'id'            => (int) $row['id'],
						'target_url'    => $row['target_url'],
						'redirect_type' => (int) $row['redirect_type'],
					);
				}
			}
		}
		set_transient( self::CACHE_KEY, array( 'exact' => $exact, 'regex' => $regex ), HOUR_IN_SECONDS );
		return $exact;
	}

	/**
	 * @return array<int, array{ id: int, source_url: string, target_url: string, redirect_type: int }>
	 */
	private function get_cached_regex_rules(): array {
		$cache = get_transient( self::CACHE_KEY );
		if ( is_array( $cache ) && isset( $cache['regex'] ) ) {
			return $cache['regex'];
		}
		$this->get_cached_rules();
		$cache = get_transient( self::CACHE_KEY );
		return is_array( $cache ) && isset( $cache['regex'] ) ? $cache['regex'] : array();
	}

	public static function invalidate_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Validate that a string is a valid PCRE pattern (delimiter #).
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return bool True if valid.
	 */
	public static function validate_regex_pattern( string $pattern ): bool {
		$result = @preg_match( '#' . $pattern . '#', '' );
		return $result !== false;
	}

	/**
	 * Log a 404 request (call from 404 handler).
	 *
	 * @param string $url     Request URI.
	 * @param string $referrer Referrer.
	 */
	public static function log_404( string $url, string $referrer = '' ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_404;
		$url = substr( sanitize_text_field( $url ), 0, 2000 );
		$referrer = substr( sanitize_text_field( $referrer ), 0, 2000 );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, hit_count FROM {$table} WHERE url = %s", $url ), ARRAY_A );
		if ( $existing ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET hit_count = hit_count + 1, last_seen = %s WHERE id = %d",
				current_time( 'mysql' ),
				$existing['id']
			) );
		} else {
			// Cap the 404 log at 2000 unique URLs to prevent unbounded table growth.
			$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			if ( $row_count >= 2000 ) {
				// Table is full — stop inserting new entries.
				return;
			}
			$wpdb->insert( $table, array(
				'url'       => $url,
				'referrer'  => $referrer,
				'hit_count' => 1,
				'last_seen' => current_time( 'mysql' ),
			) );
		}
	}

	/**
	 * Cron: delete old 404 log entries (older than 90 days, fewer than 5 hits).
	 */
	public function clean_old_404_log(): void {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE_404;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) );
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE last_seen < %s AND hit_count < 5",
			$cutoff
		) );
	}

	/**
	 * Detect redirect chains: if target_url of a new rule matches an existing source_url,
	 * the new rule would create a chain. Returns the chain as array of URLs, or empty array if no chain.
	 *
	 * @param string $source New source URL being added.
	 * @param string $target New target URL being added.
	 * @return array<string> Chain path e.g. ['/old', '/mid', '/new'] or [] if no chain.
	 */
	public static function detect_chain( string $source, string $target ): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_REDIRECTS;
		$visited = array();
		$current = $target;
		$chain   = array( $source, $target );
		// Follow the chain up to 10 hops to prevent infinite loops.
		for ( $i = 0; $i < 10; $i++ ) {
			if ( isset( $visited[ $current ] ) ) {
				// Loop detected.
				return $chain;
			}
			$visited[ $current ] = true;
			$next = $wpdb->get_var( $wpdb->prepare(
				"SELECT target_url FROM {$table} WHERE source_url = %s AND is_regex = 0 LIMIT 1",
				$current
			) );
			if ( ! $next ) {
				break;
			}
			$chain[] = $next;
			// Loop: target eventually points back to our new source.
			if ( $next === $source ) {
				return $chain;
			}
			$current = $next;
		}
		// Chain exists if we followed at least one hop beyond the immediate target.
		return count( $chain ) > 2 ? $chain : array();
	}

	/**
	 * Find all redirect chains (non-regex only). Each row whose target is another row's source is followed via detect_chain.
	 *
	 * @return array<int, array{source_id: int, source_url: string, final_target: string, chain: array<string>, hops: int}>
	 */
	public static function find_all_chains(): array {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_REDIRECTS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from prefix.
		$rows = $wpdb->get_results(
			"SELECT id, source_url, target_url, redirect_type FROM {$table} WHERE is_regex = 0 ORDER BY id ASC",
			ARRAY_A
		);
		$source_to_row = array();
		foreach ( (array) $rows as $row ) {
			$source_to_row[ $row['source_url'] ] = $row;
		}
		$chains = array();
		foreach ( (array) $rows as $row ) {
			if ( ! isset( $source_to_row[ $row['target_url'] ] ) ) {
				continue;
			}
			$chain = self::detect_chain( $row['source_url'], $row['target_url'] );
			if ( ! empty( $chain ) ) {
				$chains[ $row['id'] ] = array(
					'source_id'    => (int) $row['id'],
					'source_url'   => $row['source_url'],
					'final_target' => (string) end( $chain ),
					'chain'        => $chain,
					'hops'         => count( $chain ) - 1,
				);
			}
		}
		return array_values( $chains );
	}

	/**
	 * Update a single redirect so it points directly to the final destination (flatten one chain).
	 *
	 * @param int    $source_id   Redirect row ID to update.
	 * @param string $final_target New target URL (final destination).
	 * @return bool True on success.
	 */
	public static function flatten_chain( int $source_id, string $final_target ): bool {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE_REDIRECTS;
		$result = $wpdb->update(
			$table,
			array( 'target_url' => $final_target ),
			array( 'id' => $source_id ),
			array( '%s' ),
			array( '%d' )
		);
		self::invalidate_cache();
		return $result !== false;
	}

	/**
	 * Flatten all detected chains: update each chain's source row to point to final_target.
	 * Does not delete intermediate redirects.
	 *
	 * @return array{ flattened: int, errors: int }
	 */
	public static function flatten_all_chains(): array {
		$chains   = self::find_all_chains();
		$flattened = 0;
		$errors   = 0;
		foreach ( $chains as $item ) {
			$ok = self::flatten_chain( (int) $item['source_id'], (string) $item['final_target'] );
			if ( $ok ) {
				$flattened++;
			} else {
				$errors++;
			}
		}
		return array( 'flattened' => $flattened, 'errors' => $errors );
	}

	/**
	 * Detect if a new redirect's source is already the target of an existing redirect
	 * (i.e., adding source→target when X→source already exists — existing redirect now points nowhere useful).
	 * Returns the existing rule's source_url or empty string.
	 *
	 * @param string $source New source URL.
	 * @return string Existing source that points to this URL, or ''.
	 */
	public static function detect_existing_target( string $source ): string {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_REDIRECTS;
		$row = $wpdb->get_var( $wpdb->prepare(
			"SELECT source_url FROM {$table} WHERE target_url = %s AND is_regex = 0 LIMIT 1",
			$source
		) );
		return is_string( $row ) ? $row : '';
	}

	/**
	 * Add a redirect and invalidate cache.
	 *
	 * @param string $source   Source path (e.g. /old-page/) or regex pattern.
	 * @param string $target   Target URL (may use $1, $2 back-references for regex).
	 * @param int    $type     301, 302, 307, 410.
	 * @param string $notes    Optional notes.
	 * @param bool   $is_regex Whether source is a PCRE regex pattern.
	 * @return int|false Insert ID or false.
	 */
	public static function add_redirect( string $source, string $target, int $type = 301, string $notes = '', bool $is_regex = false ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_REDIRECTS;
		if ( ! $is_regex ) {
			$source = '/' . trim( $source, '/' );
			if ( $source === '//' ) {
				$source = '/';
			}
		}
		$r = $wpdb->insert( $table, array(
			'source_url'    => $source,
			'target_url'    => $target,
			'redirect_type' => $type,
			'is_regex'      => $is_regex ? 1 : 0,
			'created_at'    => current_time( 'mysql' ),
			'notes'         => $notes,
		) );
		if ( $r ) {
			self::invalidate_cache();
			return $wpdb->insert_id;
		}
		return false;
	}
}
