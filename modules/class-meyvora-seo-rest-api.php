<?php
/**
 * Meyvora SEO REST API – Namespace: meyvora-seo/v1
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documented endpoints (for external/headless consumers):
 *
 * GET  /wp-json/meyvora-seo/v1/post/{id}
 *   Returns all SEO meta for a post (title, description, canonical, focus_keyword,
 *   noindex, nofollow, og_*, twitter_*, schema_type, seo_score, readability_score, search_intent).
 *   Permission: Public for published posts; edit_post for drafts.
 *   Example: curl -X GET "https://example.com/wp-json/meyvora-seo/v1/post/42"
 *
 * PATCH /wp-json/meyvora-seo/v1/post/{id}
 *   Update SEO meta. Body: JSON object with any subset of the GET response fields.
 *   Permission: edit_post( id ).
 *   Example: curl -X PATCH "https://example.com/wp-json/meyvora-seo/v1/post/42" \
 *     -H "Content-Type: application/json" -d '{"seo_title":"New Title","seo_description":"New desc"}'
 *
 * GET  /wp-json/meyvora-seo/v1/posts
 *   Paginated published posts with core SEO fields (bulk crawl / Screaming Frog).
 *   Query: page, per_page (max 500), post_type (default post).
 *   Permission: manage_options.
 *   Example: curl -X GET "https://example.com/wp-json/meyvora-seo/v1/posts?post_type=page&per_page=100&page=1" \
 *     -u admin:application_password
 *
 * GET  /wp-json/meyvora-seo/v1/post/{id}/keywords
 *   Rank-tracked keywords for the post: current position, SERP feature, last 90 history rows per keyword.
 *   Permission: same as GET post/{id} (can_read_post).
 *   Example: curl -X GET "https://example.com/wp-json/meyvora-seo/v1/post/42/keywords"
 *
 * GET  /wp-json/meyvora-seo/v1/redirects
 *   Paginated redirect rules (source_url, target_url, type, regex, notes, hit_count).
 *   Permission: manage_options.
 *   Example: curl -X GET "https://example.com/wp-json/meyvora-seo/v1/redirects?page=1&per_page=500" \
 *     -u admin:application_password
 *
 * POST /wp-json/meyvora-seo/v1/redirects
 *   Create a redirect. JSON body: source_url, target_url, redirect_type (default 301).
 *   Permission: manage_options.
 *   Example: curl -X POST "https://example.com/wp-json/meyvora-seo/v1/redirects" \
 *     -u admin:application_password -H "Content-Type: application/json" \
 *     -d '{"source_url":"/old-path/","target_url":"https://example.com/new/","redirect_type":301}'
 *
 * GET  /wp-json/meyvora-seo/v1/site/audit
 *   Returns latest site audit summary: health_score, issue_counts, last_run, top_10_issues.
 *   Permission: manage_options.
 *   Example: curl -X GET "https://example.com/wp-json/meyvora-seo/v1/site/audit" \
 *     -u admin:application_password
 *
 * GET  /wp-json/meyvora-seo/v1/site/gsc-summary
 *   Returns GSC site totals (clicks, impressions, avg_ctr, avg_position, last 28 days).
 *   Returns { "connected": false } if GSC not connected.
 *   Permission: manage_options.
 *   Example: curl -X GET "https://example.com/wp-json/meyvora-seo/v1/site/gsc-summary" \
 *     -u admin:application_password
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- REST list endpoints; table names from prefix.

/**
 * Class Meyvora_SEO_REST_API
 */
class Meyvora_SEO_REST_API {

	const NAMESPACE = 'meyvora-seo/v1';

	/**
	 * Map response keys (GET) to meta constants for PATCH body mapping.
	 *
	 * @var array<string, string>
	 */
	protected static $response_key_to_meta = array(
		'seo_title'            => MEYVORA_SEO_META_TITLE,
		'seo_description'      => MEYVORA_SEO_META_DESCRIPTION,
		'canonical'            => MEYVORA_SEO_META_CANONICAL,
		'focus_keyword'        => MEYVORA_SEO_META_FOCUS_KEYWORD,
		'noindex'              => MEYVORA_SEO_META_NOINDEX,
		'nofollow'             => MEYVORA_SEO_META_NOFOLLOW,
		'og_title'             => MEYVORA_SEO_META_OG_TITLE,
		'og_description'       => MEYVORA_SEO_META_OG_DESCRIPTION,
		'og_image_id'          => MEYVORA_SEO_META_OG_IMAGE,
		'twitter_title'        => MEYVORA_SEO_META_TWITTER_TITLE,
		'twitter_description'  => MEYVORA_SEO_META_TWITTER_DESCRIPTION,
		'twitter_image_id'     => MEYVORA_SEO_META_TWITTER_IMAGE,
		'schema_type'          => MEYVORA_SEO_META_SCHEMA_TYPE,
		'seo_score'            => MEYVORA_SEO_META_SCORE,
		'readability_score'   => MEYVORA_SEO_META_READABILITY,
		'search_intent'        => MEYVORA_SEO_META_SEARCH_INTENT,
	);

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/post/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_seo' ),
					'permission_callback' => array( $this, 'can_read_post' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_post_seo' ),
					'permission_callback' => array( $this, 'can_edit_post' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/site/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_site_audit' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/site/gsc-summary',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_gsc_summary' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_posts_seo' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'page'      => array(
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page'  => array(
						'default'           => 100,
						'sanitize_callback' => 'absint',
					),
					'post_type' => array(
						'default'           => 'post',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/post/(?P<id>[\d]+)/keywords',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_post_keywords' ),
				'permission_callback' => array( $this, 'can_read_post' ),
				'args'                => array(
					'id' => array(
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/redirects',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_redirects' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array(
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 500,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_redirect' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
					'args'                => array(
						'source_url'    => array(
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_rest_url_param' ),
						),
						'target_url'    => array(
							'required'          => true,
							'sanitize_callback' => array( __CLASS__, 'sanitize_rest_url_param' ),
						),
						'redirect_type' => array(
							'default'           => 301,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Sanitize URL/path for REST redirect args (esc_url_raw).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_rest_url_param( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( $value === '' ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		if ( $value[0] === '/' ) {
			return sanitize_text_field( $value );
		}
		$as_url = esc_url_raw( $value );
		return $as_url !== '' ? $as_url : sanitize_text_field( $value );
	}

	/**
	 * Permission: public for published posts, edit_post for drafts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_read_post( WP_REST_Request $request ): bool {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		if ( $post->post_status === 'publish' ) {
			return true;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Permission: edit_post( id ).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public function can_edit_post( WP_REST_Request $request ): bool {
		$post_id = (int) $request['id'];
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * GET post SEO meta.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_post_seo( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_REST_Response( array( 'code' => 'rest_post_invalid_id', 'message' => __( 'Invalid post ID.', 'meyvora-seo' ) ), 404 );
		}

		$focus_raw = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
		$focus_out = $focus_raw;
		if ( is_string( $focus_raw ) && $focus_raw !== '' && strpos( $focus_raw, '[' ) === 0 ) {
			$decoded = json_decode( $focus_raw, true );
			if ( is_array( $decoded ) && count( $decoded ) > 0 ) {
				$focus_out = $decoded[0];
			}
		}

		$data = array(
			'post_id'              => $post_id,
			'seo_title'            => get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true ),
			'seo_description'      => get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true ),
			'canonical'            => get_post_meta( $post_id, MEYVORA_SEO_META_CANONICAL, true ),
			'focus_keyword'        => $focus_out,
			'noindex'              => (bool) get_post_meta( $post_id, MEYVORA_SEO_META_NOINDEX, true ),
			'nofollow'             => (bool) get_post_meta( $post_id, MEYVORA_SEO_META_NOFOLLOW, true ),
			'og_title'             => get_post_meta( $post_id, MEYVORA_SEO_META_OG_TITLE, true ),
			'og_description'       => get_post_meta( $post_id, MEYVORA_SEO_META_OG_DESCRIPTION, true ),
			'og_image_id'          => (int) get_post_meta( $post_id, MEYVORA_SEO_META_OG_IMAGE, true ),
			'twitter_title'        => get_post_meta( $post_id, MEYVORA_SEO_META_TWITTER_TITLE, true ),
			'twitter_description'  => get_post_meta( $post_id, MEYVORA_SEO_META_TWITTER_DESCRIPTION, true ),
			'twitter_image_id'     => (int) get_post_meta( $post_id, MEYVORA_SEO_META_TWITTER_IMAGE, true ),
			'schema_type'          => get_post_meta( $post_id, MEYVORA_SEO_META_SCHEMA_TYPE, true ),
			'seo_score'            => (int) get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true ),
			'readability_score'    => (int) get_post_meta( $post_id, MEYVORA_SEO_META_READABILITY, true ),
			'search_intent'        => get_post_meta( $post_id, MEYVORA_SEO_META_SEARCH_INTENT, true ),
		);

		return new WP_REST_Response( $data, 200 );
	}

	/**
	 * PATCH post SEO meta. Sanitizes like persist_seo_meta_from_array; clears analysis cache.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_post_seo( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_REST_Response( array( 'code' => 'rest_post_invalid_id', 'message' => __( 'Invalid post ID.', 'meyvora-seo' ) ), 404 );
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$meta = array();
		foreach ( self::$response_key_to_meta as $response_key => $meta_key ) {
			if ( ! array_key_exists( $response_key, $body ) ) {
				continue;
			}
			$meta[ $meta_key ] = $body[ $response_key ];
		}

		$this->persist_seo_meta_from_array( $post_id, $meta );

		$analyzer_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-analyzer.php';
		if ( file_exists( $analyzer_file ) ) {
			require_once $analyzer_file;
		}
		if ( class_exists( 'Meyvora_SEO_Analyzer' ) && method_exists( 'Meyvora_SEO_Analyzer', 'clear_analysis_cache' ) ) {
			Meyvora_SEO_Analyzer::clear_analysis_cache( $post_id );
		}

		return $this->get_post_seo( $request );
	}

	/**
	 * Persist allowed SEO meta from key=>value array (same sanitization as block editor).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Meta constant => value.
	 */
	protected function persist_seo_meta_from_array( int $post_id, array $meta ): void {
		$allowed = array(
			MEYVORA_SEO_META_FOCUS_KEYWORD,
			MEYVORA_SEO_META_TITLE,
			MEYVORA_SEO_META_DESCRIPTION,
			MEYVORA_SEO_META_CANONICAL,
			MEYVORA_SEO_META_NOINDEX,
			MEYVORA_SEO_META_NOFOLLOW,
			MEYVORA_SEO_META_OG_TITLE,
			MEYVORA_SEO_META_OG_DESCRIPTION,
			MEYVORA_SEO_META_OG_IMAGE,
			MEYVORA_SEO_META_TWITTER_TITLE,
			MEYVORA_SEO_META_TWITTER_DESCRIPTION,
			MEYVORA_SEO_META_TWITTER_IMAGE,
			MEYVORA_SEO_META_SCHEMA_TYPE,
			MEYVORA_SEO_META_SCORE,
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
			} elseif ( $key === MEYVORA_SEO_META_NOINDEX || $key === MEYVORA_SEO_META_NOFOLLOW ) {
				$value = ( $value === true || $value === '1' || $value === 1 ) ? '1' : '';
			} elseif ( $key === MEYVORA_SEO_META_OG_IMAGE || $key === MEYVORA_SEO_META_TWITTER_IMAGE || $key === MEYVORA_SEO_META_SCORE || $key === MEYVORA_SEO_META_READABILITY ) {
				$value = is_numeric( $value ) ? (int) $value : ( $key === MEYVORA_SEO_META_SCORE || $key === MEYVORA_SEO_META_READABILITY ? 0 : '' );
			} elseif ( $key === MEYVORA_SEO_META_CANONICAL ) {
				$value = is_string( $value ) ? esc_url_raw( $value ) : '';
			} elseif ( in_array( $key, array( MEYVORA_SEO_META_DESCRIPTION, MEYVORA_SEO_META_OG_DESCRIPTION, MEYVORA_SEO_META_TWITTER_DESCRIPTION ), true ) ) {
				$value = is_string( $value ) ? sanitize_textarea_field( $value ) : '';
			} elseif ( in_array( $key, array( MEYVORA_SEO_META_TITLE, MEYVORA_SEO_META_OG_TITLE, MEYVORA_SEO_META_TWITTER_TITLE, MEYVORA_SEO_META_SCHEMA_TYPE, MEYVORA_SEO_META_SEARCH_INTENT ), true ) ) {
				$value = is_string( $value ) ? sanitize_text_field( $value ) : ( is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '' );
			} elseif ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				$value = '';
			}
			if ( $key !== MEYVORA_SEO_META_NOINDEX && $key !== MEYVORA_SEO_META_NOFOLLOW && ! in_array( $key, array( MEYVORA_SEO_META_CANONICAL, MEYVORA_SEO_META_DESCRIPTION, MEYVORA_SEO_META_OG_DESCRIPTION, MEYVORA_SEO_META_TWITTER_DESCRIPTION, MEYVORA_SEO_META_TITLE, MEYVORA_SEO_META_OG_TITLE, MEYVORA_SEO_META_TWITTER_TITLE, MEYVORA_SEO_META_SCHEMA_TYPE, MEYVORA_SEO_META_SEARCH_INTENT ), true ) ) {
				$value = is_string( $value ) ? $value : (string) $value;
			}
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * GET site audit summary (health_score, issue_counts, last_run, top_10_issues).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_site_audit( WP_REST_Request $request ): WP_REST_Response {
		$health_score   = 0;
		$issue_counts   = array(
			'missing_title'       => 0,
			'missing_description' => 0,
			'low_score'           => 0,
			'missing_schema'      => 0,
		);
		$last_run       = 0;
		$top_10_issues  = array();

		$reports_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-reports.php';
		if ( file_exists( $reports_file ) ) {
			require_once $reports_file;
		}
		if ( class_exists( 'Meyvora_SEO_Reports' ) && function_exists( 'meyvora_seo' ) ) {
			$main   = meyvora_seo();
			$reports = new Meyvora_SEO_Reports( $main->get_loader(), $main->get_options() );
			$data   = $reports->get_report_data( false );
			$health_score = isset( $data['health_score'] ) ? (int) $data['health_score'] : 0;
			if ( isset( $data['issues'] ) && is_array( $data['issues'] ) ) {
				$issue_counts = array(
					'missing_title'       => (int) ( $data['issues']['missing_title'] ?? 0 ),
					'missing_description' => (int) ( $data['issues']['missing_description'] ?? 0 ),
					'low_score'           => (int) ( $data['issues']['low_score'] ?? 0 ),
					'missing_schema'      => (int) ( $data['issues']['missing_schema'] ?? 0 ),
				);
			}
		}

		if ( class_exists( 'Meyvora_SEO_Audit' ) && function_exists( 'meyvora_seo' ) ) {
			$main  = meyvora_seo();
			$audit = new Meyvora_SEO_Audit( $main->get_loader(), $main->get_options() );
			if ( method_exists( $audit, 'get_stored_results' ) ) {
				$stored = $audit->get_stored_results();
				if ( is_array( $stored ) ) {
					$last_run = (int) ( $stored['last_run'] ?? 0 );
					$results  = isset( $stored['results'] ) && is_array( $stored['results'] ) ? $stored['results'] : array();
					$flat     = array();
					foreach ( $results as $post_id => $row ) {
						$title = isset( $row['post_title'] ) ? $row['post_title'] : get_the_title( $post_id );
						$issues = isset( $row['issues'] ) && is_array( $row['issues'] ) ? $row['issues'] : array();
						foreach ( $issues as $issue ) {
							$flat[] = array(
								'post_id'     => (int) $post_id,
								'title'       => $title,
								'issue_type'  => isset( $issue['id'] ) ? (string) $issue['id'] : '',
								'severity'    => isset( $issue['severity'] ) ? (string) $issue['severity'] : 'warning',
							);
						}
					}
					$top_10_issues = array_slice( $flat, 0, 10 );
				}
			}
		}

		$payload = array(
			'health_score'  => $health_score,
			'issue_counts'  => $issue_counts,
			'last_run'      => $last_run,
			'top_10_issues' => $top_10_issues,
		);

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * GET GSC site summary (clicks, impressions, avg_ctr, avg_position). Returns { connected: false } if not connected.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_gsc_summary( WP_REST_Request $request ): WP_REST_Response {
		if ( ! class_exists( 'Meyvora_SEO_GSC' ) || ! function_exists( 'meyvora_seo' ) ) {
			return new WP_REST_Response( array( 'connected' => false ), 200 );
		}
		$main = meyvora_seo();
		$gsc  = new Meyvora_SEO_GSC( $main->get_loader(), $main->get_options() );
		if ( ! $gsc->is_connected() ) {
			return new WP_REST_Response( array( 'connected' => false ), 200 );
		}
		$data = $gsc->get_dashboard_data();
		$totals = isset( $data['totals'] ) && is_array( $data['totals'] ) ? $data['totals'] : array();
		$clicks      = isset( $totals['clicks'] ) ? (int) $totals['clicks'] : 0;
		$impressions = isset( $totals['impressions'] ) ? (int) $totals['impressions'] : 0;
		$avg_ctr     = isset( $data['avg_ctr'] ) ? (float) $data['avg_ctr'] : ( $impressions > 0 ? $clicks / $impressions : 0.0 );
		$avg_position = isset( $data['avg_position'] ) ? (float) $data['avg_position'] : ( isset( $totals['position'] ) ? (float) $totals['position'] : 0.0 );

		return new WP_REST_Response( array(
			'connected'     => true,
			'clicks'        => $clicks,
			'impressions'   => $impressions,
			'avg_ctr'       => round( $avg_ctr, 4 ),
			'avg_position'  => round( $avg_position, 1 ),
		), 200 );
	}

	/**
	 * GET paginated published posts with SEO fields (bulk integrations).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_posts_seo( WP_REST_Request $request ): WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 500, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		if ( ! post_type_exists( $post_type ) ) {
			$post_type = 'post';
		}
		$query = new WP_Query( array(
			'post_type'              => $post_type,
			'post_status'            => 'publish',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		) );
		$items = array();
		foreach ( $query->posts as $post_id ) {
			$items[] = $this->build_post_seo_list_item( (int) $post_id );
		}
		return new WP_REST_Response( array(
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'posts'       => $items,
		), 200 );
	}

	/**
	 * Single post row for GET /posts list.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	protected function build_post_seo_list_item( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'id'               => $post_id,
				'title'            => '',
				'permalink'        => '',
				'seo_title'        => '',
				'seo_description'  => '',
				'canonical'        => '',
				'focus_keyword'    => '',
				'seo_score'        => 0,
				'noindex'          => false,
				'schema_type'      => '',
			);
		}
		$focus_raw = get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true );
		$focus_out = is_string( $focus_raw ) ? $focus_raw : '';
		if ( $focus_out !== '' && strpos( $focus_out, '[' ) === 0 ) {
			$decoded = json_decode( $focus_out, true );
			if ( is_array( $decoded ) && isset( $decoded[0] ) ) {
				$focus_out = (string) $decoded[0];
			}
		}
		return array(
			'id'              => $post_id,
			'title'           => $post->post_title,
			'permalink'       => get_permalink( $post_id ) ?: '',
			'seo_title'       => (string) get_post_meta( $post_id, MEYVORA_SEO_META_TITLE, true ),
			'seo_description' => (string) get_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, true ),
			'canonical'       => (string) get_post_meta( $post_id, MEYVORA_SEO_META_CANONICAL, true ),
			'focus_keyword'   => $focus_out,
			'seo_score'       => (int) get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true ),
			'noindex'         => (bool) get_post_meta( $post_id, MEYVORA_SEO_META_NOINDEX, true ),
			'schema_type'     => (string) get_post_meta( $post_id, MEYVORA_SEO_META_SCHEMA_TYPE, true ),
		);
	}

	/**
	 * GET rank-tracked keywords for a post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_post_keywords( WP_REST_Request $request ): WP_REST_Response {
		$post_id = (int) $request['id'];
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new WP_REST_Response( array( 'code' => 'rest_post_invalid_id', 'message' => __( 'Invalid post ID.', 'meyvora-seo' ) ), 404 );
		}
		$analyzer_kw = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-analyzer.php';
		if ( file_exists( $analyzer_kw ) ) {
			require_once $analyzer_kw;
		}
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Install::TABLE_RANK_HISTORY;
		$keywords = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT keyword FROM {$table} WHERE post_id = %d ORDER BY keyword ASC",
				$post_id
			)
		);
		$primary = '';
		if ( class_exists( 'Meyvora_SEO_Analyzer' ) ) {
			$normalized = Meyvora_SEO_Analyzer::normalize_focus_keywords(
				get_post_meta( $post_id, MEYVORA_SEO_META_FOCUS_KEYWORD, true )
			);
			$primary = isset( $normalized[0] ) ? (string) $normalized[0] : '';
		}
		$data = array();
		foreach ( (array) $keywords as $kw ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT date, position, serp_feature FROM {$table}
					WHERE post_id = %d AND keyword = %s
					ORDER BY date DESC LIMIT 90",
					$post_id,
					$kw
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || $rows === array() ) {
				continue;
			}
			$latest = $rows[0];
			$chronological = array_reverse( $rows );
			$history       = array();
			foreach ( $chronological as $r ) {
				$history[] = array(
					'date'     => isset( $r['date'] ) ? (string) $r['date'] : '',
					'position' => isset( $r['position'] ) ? (float) $r['position'] : 0.0,
				);
			}
			$data[] = array(
				'keyword'          => $kw,
				'is_primary'       => ( $primary !== '' && $kw === $primary ),
				'current_position' => isset( $latest['position'] ) ? (float) $latest['position'] : null,
				'serp_feature'     => isset( $latest['serp_feature'] ) ? (string) $latest['serp_feature'] : '',
				'history'          => $history,
			);
		}
		usort(
			$data,
			static function ( $a, $b ) use ( $primary ) {
				$ap = ( $primary !== '' && $a['keyword'] === $primary ) ? 0 : 1;
				$bp = ( $primary !== '' && $b['keyword'] === $primary ) ? 0 : 1;
				if ( $ap !== $bp ) {
					return $ap <=> $bp;
				}
				return strcasecmp( $a['keyword'], $b['keyword'] );
			}
		);
		return new WP_REST_Response(
			array(
				'post_id'  => $post_id,
				'keywords' => $data,
			),
			200
		);
	}

	/**
	 * GET paginated redirects.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_redirects( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 1000, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$table    = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_REDIRECTS;
		$offset   = ( $page - 1 ) * $per_page;
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_url, target_url, redirect_type, is_regex, notes, hit_count
				FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);
		$redirects = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$redirects[] = array(
				'source_url'    => isset( $row['source_url'] ) ? (string) $row['source_url'] : '',
				'target_url'    => isset( $row['target_url'] ) ? (string) $row['target_url'] : '',
				'redirect_type' => isset( $row['redirect_type'] ) ? (int) $row['redirect_type'] : 301,
				'is_regex'      => ! empty( $row['is_regex'] ),
				'notes'         => isset( $row['notes'] ) ? (string) $row['notes'] : '',
				'hit_count'     => isset( $row['hit_count'] ) ? (int) $row['hit_count'] : 0,
			);
		}
		return new WP_REST_Response(
			array(
				'total'      => $total,
				'page'       => $page,
				'per_page'   => $per_page,
				'redirects'  => $redirects,
			),
			200
		);
	}

	/**
	 * POST create redirect.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create_redirect( WP_REST_Request $request ): WP_REST_Response {
		$source = (string) $request->get_param( 'source_url' );
		$target = (string) $request->get_param( 'target_url' );
		$type   = (int) $request->get_param( 'redirect_type' );
		if ( $source === '' || $target === '' ) {
			return new WP_REST_Response(
				array(
					'code'    => 'meyvora_seo_invalid_redirect',
					'message' => __( 'source_url and target_url are required.', 'meyvora-seo' ),
				),
				400
			);
		}
		if ( preg_match( '#^https?://#i', $source ) ) {
			$path = wp_parse_url( $source, PHP_URL_PATH );
			$source = ( is_string( $path ) && $path !== '' ) ? $path : '/';
		}
		$allowed_types = array( 301, 302, 307, 410 );
		if ( ! in_array( $type, $allowed_types, true ) ) {
			$type = 301;
		}
		$redirects_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-redirects.php';
		if ( file_exists( $redirects_file ) ) {
			require_once $redirects_file;
		}
		if ( ! class_exists( 'Meyvora_SEO_Redirects' ) || ! method_exists( 'Meyvora_SEO_Redirects', 'add_redirect' ) ) {
			return new WP_REST_Response(
				array( 'code' => 'meyvora_seo_redirects_unavailable', 'message' => __( 'Redirects module unavailable.', 'meyvora-seo' ) ),
				500
			);
		}
		Meyvora_SEO_Redirects::create_tables();
		$id = Meyvora_SEO_Redirects::add_redirect( $source, $target, $type, '', false );
		if ( ! $id ) {
			return new WP_REST_Response(
				array( 'code' => 'meyvora_seo_redirect_create_failed', 'message' => __( 'Could not create redirect.', 'meyvora-seo' ) ),
				400
			);
		}
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_REDIRECTS;
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT source_url, target_url, redirect_type, is_regex, notes, hit_count FROM {$table} WHERE id = %d",
				(int) $id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return new WP_REST_Response(
				array(
					'id'            => (int) $id,
					'source_url'    => $source,
					'target_url'    => $target,
					'redirect_type' => $type,
					'is_regex'      => false,
					'notes'         => '',
					'hit_count'     => 0,
				),
				201
			);
		}
		return new WP_REST_Response(
			array(
				'id'            => (int) $id,
				'source_url'    => (string) $row['source_url'],
				'target_url'    => (string) $row['target_url'],
				'redirect_type' => (int) $row['redirect_type'],
				'is_regex'      => ! empty( $row['is_regex'] ),
				'notes'         => isset( $row['notes'] ) ? (string) $row['notes'] : '',
				'hit_count'     => isset( $row['hit_count'] ) ? (int) $row['hit_count'] : 0,
			),
			201
		);
	}
}
