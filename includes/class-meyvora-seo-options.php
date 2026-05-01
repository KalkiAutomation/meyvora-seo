<?php
/**
 * Options helper: defaults, get/update/reset, sanitization for Meyvora SEO.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Options {

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_defaults(): array {
		return array(
			'title_separator'           => '|',
			'post_title_template'      => '{title} {separator} {site_title}',
			'page_title_template'      => '{title} {separator} {site_title}',
			'product_title_template'   => '{product_name} | {category} | {site_name}',
			'post_desc_template'       => '{excerpt}',
			'page_desc_template'       => '{excerpt}',
			'product_desc_template'    => '{excerpt}',
			'noindex_search'           => true,
			'noindex_author_archives'  => false,
			'noindex_date_archives'    => true,
			'noindex_replytocom'       => true,
			'strip_session_ids'        => true,
			'rss_append_link'          => true,
			'rss_excerpt_only'         => false,
			'open_graph'               => true,
			'twitter_cards'            => true,
			'twitter_site_handle'      => '',
			'verify_google'    => '',
			'verify_bing'      => '',
			'verify_pinterest' => '',
			'verify_yandex'    => '',
			'verify_baidu'     => '',
			'attachment_redirect'      => 'file', // 'none' | 'file' | 'parent'
			'sitemap_enabled'          => true,
			'sitemap_posts'            => true,
			'sitemap_pages'            => true,
			'sitemap_categories'       => true,
			'sitemap_tags'             => true,
			'sitemap_products'         => true,
			'sitemap_images'           => true,
			'sitemap_include_noindex'  => false,
			'sitemap_exclude_ids'      => '',
			'sitemap_news_enabled'     => false,
			'sitemap_news_post_type'   => 'post',
			'sitemap_video_enabled'    => false,
			'sitemap_ping_google_enabled' => false,
			'breadcrumbs_enabled'      => true,
			'schema_organization'      => true,
			'schema_organization_name'  => '',
			'schema_organization_logo'  => '',
			'schema_sameas_facebook'    => '',
			'schema_sameas_twitter'     => '',
			'schema_sameas_linkedin'   => '',
			'schema_sameas_instagram'  => '',
			'schema_sameas_youtube'     => '',
			'schema_faq'                => true,
			'schema_video'              => true,
			'schema_sitelinks_searchbox'=> true,
			'schema_local_business'     => false,
			'schema_lb_name'            => '',
			'schema_lb_type'            => 'LocalBusiness',
			'schema_lb_street'          => '',
			'schema_lb_locality'        => '',
			'schema_lb_region'          => '',
			'schema_lb_postal'          => '',
			'schema_lb_country'         => '',
			'schema_lb_phone'           => '',
			'schema_lb_email'            => '',
			'schema_lb_hours'           => '',
			'schema_lb_lat'             => '',
			'schema_lb_lng'             => '',
			'schema_lb_price_range'     => '',
			'wc_shop_seo_title'         => '',
			'wc_shop_seo_description'   => '',
			'wc_oos_auto_redirect'     => false,
			'ai_api_key_encrypted'      => '',
			'ai_api_provider'           => 'openai',
			'ai_custom_endpoint'        => '',
			'ai_model'                  => 'gpt-4o-mini',
			'ai_custom_system_prompt'   => '',
			'ai_rate_limit'             => 100,
			'dataforseo_api_key'        => '',
			'hreflang_enabled'          => true,
			'sitemap_hreflang_enabled'  => true,
			'gsc_client_id'             => '',
			'gsc_client_secret'         => '',
			'ga4_measurement_id'        => '',
			'ga4_exclude_admins'        => true,
			'ga4_mode'                  => 'simple',
			'ga4_property_id'           => '',
			'ga4_credentials_encrypted' => '',
			'reports_email_enabled'     => false,
			'reports_email_recipients'  => '',
			'reports_email_day'         => 0, // 0 = Sunday, 6 = Saturday
			'image_seo_auto_alt'        => true,
			'image_seo_sanitize_filename' => true,
			'image_seo_ai_alt'           => false,
			'rank_tracker_enabled'      => false,
			'rank_tracker_posts_per_run' => 100,
			'link_checker_background_enabled' => false,
			'competitor_monitor_enabled' => false,
			'indexnow_enabled'          => false,
			'indexnow_api_key'          => '',
			'pagespeed_api_key'         => '',
			'404_email_alert'           => false,
			'404_alert_threshold'       => 10,
			'seo_access_roles' => array( 'administrator', 'editor', 'author' ),
			'site_type'        => 'blog',
			'redirects_enabled'=> true,
			'ai_enabled'       => true,
			'white_label_enabled'    => false,
			'white_label_menu_name'  => '',
			'white_label_logo_id'    => 0,
			'white_label_dashboard_title' => '',
			'score_alert_enabled'   => false,
			'score_alert_email'     => '',
			'score_alert_slack'     => '',
			'score_alert_slack_enabled' => false,
			'score_alert_threshold' => 10,
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function get_all(): array {
		$saved = get_option( MEYVORA_SEO_OPTION_KEY, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::get_defaults(), $saved );
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Default if not set.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$all = $this->get_all();
		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}
		return $default;
	}

	/**
	 * Check whether the current user has SEO editing access.
	 * Allows manage_options, meyvora_seo_edit (e.g. Editor role), or edit_posts.
	 *
	 * @return bool
	 */
	public function current_user_can_edit_seo(): bool {
		return current_user_can( 'manage_options' )
			|| current_user_can( 'meyvora_seo_edit' )
			|| current_user_can( 'edit_posts' );
	}

	/**
	 * Sanitize and merge input with current options. Does not save. Used by Settings API callback.
	 *
	 * @param array<string, mixed> $input Raw input (e.g. from form POST). Omitted keys keep current value.
	 * @return array<string, mixed> Sanitized options merged with defaults.
	 */
	public function sanitize_and_merge( array $input ): array {
		$current  = $this->get_all();
		$defaults = self::get_defaults();
		// So that unchecked checkboxes (missing from POST) become false, start bool keys as false then merge.
		$base = array();
		foreach ( $defaults as $k => $v ) {
			$base[ $k ] = is_bool( $v ) ? false : $v;
		}
		$merged = array_merge( $base, $current, $input );
		// Encrypt GA4 service account JSON if provided (never store plain).
		if ( isset( $merged['ga4_credentials'] ) && is_string( $merged['ga4_credentials'] ) && trim( $merged['ga4_credentials'] ) !== '' ) {
			if ( class_exists( 'Meyvora_SEO_GA4' ) ) {
				$merged['ga4_credentials_encrypted'] = Meyvora_SEO_GA4::encrypt_credentials( trim( $merged['ga4_credentials'] ) );
			}
			unset( $merged['ga4_credentials'] );
		}
		// Encrypt new AI API key if provided (never store plain key).
		if ( isset( $merged['ai_api_key'] ) && is_string( $merged['ai_api_key'] ) && trim( $merged['ai_api_key'] ) !== '' ) {
			if ( class_exists( 'Meyvora_SEO_AI' ) ) {
				$merged['ai_api_key_encrypted'] = Meyvora_SEO_AI::encrypt( sanitize_text_field( trim( $merged['ai_api_key'] ) ) );
			}
			unset( $merged['ai_api_key'] );
		}
		// Keep existing DataForSEO key when password field is left blank.
		if ( isset( $merged['dataforseo_api_key'] ) && $merged['dataforseo_api_key'] === '' && isset( $current['dataforseo_api_key'] ) && $current['dataforseo_api_key'] !== '' ) {
			$merged['dataforseo_api_key'] = $current['dataforseo_api_key'];
		}
		// Keep existing GSC client secret when left blank.
		if ( isset( $merged['gsc_client_secret'] ) && $merged['gsc_client_secret'] === '' && isset( $current['gsc_client_secret'] ) && $current['gsc_client_secret'] !== '' ) {
			$merged['gsc_client_secret'] = $current['gsc_client_secret'];
		}
		$sanitized = array();
		foreach ( $defaults as $key => $default_value ) {
			$value = array_key_exists( $key, $merged ) ? $merged[ $key ] : $default_value;
			$sanitized[ $key ] = $this->sanitize_field( $key, $value, $default_value );
		}
		// Catch-all: preserve keys not in defaults (e.g. from add-ons) via filter so they are not dropped on save.
		foreach ( $merged as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				$sanitized[ $key ] = apply_filters( 'meyvora_seo_sanitize_option', $value, $key );
			}
		}
		return $sanitized;
	}

	/**
	 * Update all settings (sanitized). Merges with current saved options so missing keys (e.g. unchecked checkboxes) are preserved.
	 *
	 * @param array<string, mixed> $options Raw options (e.g. from form POST). Omitted keys keep current value.
	 * @return bool
	 */
	public function update_all( array $options ): bool {
		$sanitized = $this->sanitize_and_merge( $options );
		return update_option( MEYVORA_SEO_OPTION_KEY, $sanitized, true );
	}

	/**
	 * Sanitize a single field by key.
	 *
	 * @param string $key          Option key.
	 * @param mixed  $value        Raw value.
	 * @param mixed  $default_value Default for type hinting.
	 * @return mixed
	 */
	protected function sanitize_field( string $key, $value, $default_value ) {
		if ( is_bool( $default_value ) ) {
			return (bool) $value;
		}
		if ( is_int( $default_value ) ) {
			return is_numeric( $value ) ? (int) $value : $default_value;
		}
		if ( $key === 'attachment_redirect' ) {
			$allowed = array( 'none', 'file', 'parent' );
			$val = is_string( $value ) ? sanitize_text_field( $value ) : '';
			return in_array( $val, $allowed, true ) ? $val : (string) $default_value;
		}
		if ( $key === 'site_type' ) {
			$allowed = array( 'blog', 'business', 'ecommerce', 'news' );
			$val = is_string( $value ) ? sanitize_text_field( $value ) : '';
			return in_array( $val, $allowed, true ) ? $val : 'blog';
		}
		if ( $key === 'title_separator' ) {
			$val = is_string( $value ) ? sanitize_text_field( $value ) : '';
			return substr( $val, 0, 20 );
		}
		$string_keys = array(
			'post_title_template', 'page_title_template', 'product_title_template',
			'post_desc_template', 'page_desc_template', 'product_desc_template',
			'twitter_site_handle',
			'verify_google', 'verify_bing', 'verify_pinterest', 'verify_yandex', 'verify_baidu',
		);
		if ( in_array( $key, $string_keys, true ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : (string) $default_value;
		}
		$bool_keys = array(
			'noindex_search', 'noindex_author_archives', 'noindex_date_archives',
			'open_graph', 'twitter_cards',
			'sitemap_enabled', 'sitemap_posts', 'sitemap_pages', 'sitemap_categories', 'sitemap_tags', 'sitemap_products', 'sitemap_images', 'sitemap_include_noindex', 'sitemap_news_enabled', 'sitemap_video_enabled',
			'redirects_enabled', 'ai_enabled',
		);
		if ( in_array( $key, $bool_keys, true ) ) {
			return (bool) $value;
		}
		if ( in_array( $key, array( 'sitemap_ping_google_enabled', 'link_checker_background_enabled', 'competitor_monitor_enabled', 'score_alert_slack_enabled' ), true ) ) {
			return (bool) $value;
		}
		if ( in_array( $key, array( 'sitemap_exclude_ids', 'sitemap_news_post_type' ), true ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : ( $key === 'sitemap_news_post_type' ? 'post' : '' );
		}
		if ( $key === 'schema_lb_hours' ) {
			$decoded = is_string( $value ) ? json_decode( $value, true ) : ( is_array( $value ) ? $value : null );
			if ( ! is_array( $decoded ) ) {
				return is_string( $value ) ? sanitize_text_field( $value ) : '';
			}
			$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
			$out  = array();
			foreach ( $days as $day ) {
				$row = null;
				foreach ( $decoded as $r ) {
					if ( isset( $r['day'] ) && $r['day'] === $day ) {
						$row = $r;
						break;
					}
				}
				$closed = $row && isset( $row['closed'] ) ? (bool) $row['closed'] : true;
				$open   = ( $row && isset( $row['open'] ) && is_string( $row['open'] ) ) ? preg_replace( '/[^0-9:]/', '', $row['open'] ) : '09:00';
				$close  = ( $row && isset( $row['close'] ) && is_string( $row['close'] ) ) ? preg_replace( '/[^0-9:]/', '', $row['close'] ) : '17:00';
				$out[]  = array( 'day' => $day, 'closed' => $closed, 'open' => $open, 'close' => $close );
			}
			return wp_json_encode( $out );
		}
		$schema_keys = array( 'schema_organization_name', 'schema_organization_logo', 'schema_sameas_facebook', 'schema_sameas_twitter', 'schema_sameas_linkedin', 'schema_sameas_instagram', 'schema_sameas_youtube', 'schema_lb_name', 'schema_lb_type', 'schema_lb_street', 'schema_lb_locality', 'schema_lb_region', 'schema_lb_postal', 'schema_lb_country', 'schema_lb_phone', 'schema_lb_email', 'schema_lb_lat', 'schema_lb_lng', 'schema_lb_price_range' );
		if ( in_array( $key, $schema_keys, true ) ) {
			if ( $key === 'schema_organization_logo' ) {
				return absint( $value );
			}
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( in_array( $key, array( 'breadcrumbs_enabled', 'schema_organization', 'schema_faq', 'schema_video', 'schema_sitelinks_searchbox', 'schema_local_business', 'image_seo_auto_alt', 'image_seo_sanitize_filename', 'image_seo_ai_alt', 'rank_tracker_enabled' ), true ) ) {
			return (bool) $value;
		}
		if ( $key === 'rank_tracker_posts_per_run' ) {
			$v = is_numeric( $value ) ? (int) $value : 100;
			return max( 1, min( 500, $v ) );
		}
		if ( in_array( $key, array( 'indexnow_enabled', '404_email_alert' ), true ) ) {
			return (bool) $value;
		}
		if ( $key === '404_alert_threshold' ) {
			$v = is_numeric( $value ) ? (int) $value : 10;
			return max( 1, min( 1000, $v ) );
		}
		if ( $key === 'seo_access_roles' ) {
			if ( ! is_array( $value ) ) {
				return array( 'administrator', 'editor', 'author' );
			}
			$all_roles = array_keys( wp_roles()->get_names() );
			$filtered  = array_values( array_filter( $value, function ( $r ) use ( $all_roles ) {
				return in_array( $r, $all_roles, true );
			} ) );
			// Always keep administrator.
			if ( ! in_array( 'administrator', $filtered, true ) ) {
				$filtered[] = 'administrator';
			}
			return $filtered;
		}
		if ( $key === 'indexnow_api_key' ) {
			return is_string( $value ) ? preg_replace( '/[^a-f0-9]/', '', strtolower( $value ) ) : '';
		}
		if ( in_array( $key, array( 'noindex_replytocom', 'strip_session_ids', 'rss_append_link' ), true ) ) {
			return (bool) $value;
		}
		if ( $key === 'rss_excerpt_only' ) {
			return (bool) $value;
		}
		if ( $key === 'wc_shop_seo_title' ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( $key === 'wc_shop_seo_description' ) {
			return is_string( $value ) ? sanitize_textarea_field( $value ) : '';
		}
		if ( in_array( $key, array( 'ai_api_key_encrypted', 'ai_custom_endpoint', 'dataforseo_api_key' ), true ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( $key === 'ai_api_provider' ) {
			return in_array( $value, array( 'openai', 'custom' ), true ) ? $value : 'openai';
		}
		if ( $key === 'ai_model' ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : 'gpt-4o-mini';
		}
		if ( $key === 'ai_custom_system_prompt' ) {
			return is_string( $value ) ? sanitize_textarea_field( $value ) : '';
		}
		if ( $key === 'ai_rate_limit' ) {
			$v = is_numeric( $value ) ? (int) $value : 100;
			// Upper bound avoids absurd values; use filter meyvora_seo_ai_daily_call_limit in code if you need to raise effective limit further.
			return max( 1, min( 100000, $v ) );
		}
		if ( in_array( $key, array( 'hreflang_enabled', 'sitemap_hreflang_enabled' ), true ) ) {
			return (bool) $value;
		}
		if ( in_array( $key, array( 'gsc_client_id', 'gsc_client_secret', 'ga4_measurement_id', 'ga4_property_id', 'pagespeed_api_key' ), true ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( $key === 'ga4_exclude_admins' ) {
			return (bool) $value;
		}
		if ( $key === 'ga4_mode' ) {
			return 'simple';
		}
		if ( $key === 'ga4_credentials_encrypted' ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( $key === 'reports_email_enabled' ) {
			return (bool) $value;
		}
		if ( $key === 'reports_email_recipients' ) {
			return is_string( $value ) ? sanitize_textarea_field( $value ) : '';
		}
		if ( $key === 'reports_email_day' ) {
			$v = is_numeric( $value ) ? (int) $value : 0;
			return max( 0, min( 6, $v ) );
		}
		if ( $key === 'white_label_enabled' ) {
			return (bool) $value;
		}
		if ( in_array( $key, array( 'white_label_menu_name', 'white_label_dashboard_title' ), true ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( $key === 'white_label_logo_id' ) {
			return absint( $value );
		}
		if ( $key === 'score_alert_enabled' ) {
			return (bool) $value;
		}
		if ( in_array( $key, array( 'score_alert_email', 'score_alert_slack' ), true ) ) {
			return is_string( $value ) ? sanitize_text_field( $value ) : '';
		}
		if ( $key === 'score_alert_threshold' ) {
			$v = is_numeric( $value ) ? (int) $value : 10;
			return max( 1, min( 100, $v ) );
		}
		return $default_value;
	}

	/**
	 * Reset settings to defaults.
	 *
	 * @return bool
	 */
	public function reset(): bool {
		return update_option( MEYVORA_SEO_OPTION_KEY, self::get_defaults(), true );
	}

	/**
	 * Check if a feature is enabled.
	 *
	 * @param string $feature Feature key (e.g. 'open_graph', 'twitter_cards', 'sitemap_posts').
	 * @return bool
	 */
	public function is_enabled( string $feature ): bool {
		return (bool) $this->get( $feature, false );
	}
}
