<?php
/**
 * Settings API: sections and fields for Meyvora SEO.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Settings {

	const OPTION_GROUP = 'meyvora_seo';

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
	 * Register settings, sections, and fields.
	 */
	public function register_hooks(): void {
		$this->loader->add_action( 'admin_init', $this, 'register_setting_and_sections', 10, 0 );
		add_action( 'wp_ajax_meyvora_seo_test_slack_webhook', array( $this, 'ajax_test_slack_webhook' ) );
	}

	/**
	 * Register the option and add sections/fields.
	 */
	public function register_setting_and_sections(): void {
		register_setting(
			self::OPTION_GROUP,
			MEYVORA_SEO_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
			)
		);

		$page_general = 'meyvora-seo-general';
		$page_social  = 'meyvora-seo-social';
		$page_sitemap = 'meyvora-seo-sitemap';
		$page_schema  = 'meyvora-seo-schema';
		$page_bread   = 'meyvora-seo-breadcrumbs';
		$page_adv     = 'meyvora-seo-advanced';

		add_settings_section(
			'meyvora_seo_meta',
			__( 'Meta defaults', 'meyvora-seo' ),
			array( $this, 'section_meta_desc' ),
			$page_general
		);

		add_settings_field(
			'title_separator',
			__( 'Title separator', 'meyvora-seo' ),
			array( $this, 'field_text' ),
			$page_general,
			'meyvora_seo_meta',
			array(
				'label_for' => 'title_separator',
				'key'       => 'title_separator',
				'type'      => 'text',
				'class'     => 'small-text',
			)
		);

		add_settings_field(
			'post_title_template',
			__( 'Post title template', 'meyvora-seo' ),
			array( $this, 'field_text' ),
			$page_general,
			'meyvora_seo_meta',
			array(
				'label_for' => 'post_title_template',
				'key'       => 'post_title_template',
				'type'      => 'text',
				'description' => __( 'Use {title}, {separator}, {site_title}.', 'meyvora-seo' ),
			)
		);

		add_settings_field(
			'page_title_template',
			__( 'Page title template', 'meyvora-seo' ),
			array( $this, 'field_text' ),
			$page_general,
			'meyvora_seo_meta',
			array(
				'label_for' => 'page_title_template',
				'key'       => 'page_title_template',
				'type'      => 'text',
			)
		);

		add_settings_field(
			'post_desc_template',
			__( 'Post description template', 'meyvora-seo' ),
			array( $this, 'field_text' ),
			$page_general,
			'meyvora_seo_meta',
			array(
				'label_for' => 'post_desc_template',
				'key'       => 'post_desc_template',
				'type'      => 'text',
				'description' => __( 'Use {excerpt}. Leave empty to use excerpt as-is.', 'meyvora-seo' ),
			)
		);

		add_settings_field(
			'page_desc_template',
			__( 'Page description template', 'meyvora-seo' ),
			array( $this, 'field_text' ),
			$page_general,
			'meyvora_seo_meta',
			array(
				'label_for' => 'page_desc_template',
				'key'       => 'page_desc_template',
				'type'      => 'text',
			)
		);

		add_settings_section(
			'meyvora_seo_robots',
			__( 'Robots', 'meyvora-seo' ),
			array( $this, 'section_robots_desc' ),
			$page_general
		);

		add_settings_field(
			'noindex_search',
			__( 'Noindex search results', 'meyvora-seo' ),
			array( $this, 'field_checkbox' ),
			$page_general,
			'meyvora_seo_robots',
			array( 'key' => 'noindex_search' )
		);

		add_settings_field(
			'noindex_author_archives',
			__( 'Noindex author archives', 'meyvora-seo' ),
			array( $this, 'field_checkbox' ),
			$page_general,
			'meyvora_seo_robots',
			array( 'key' => 'noindex_author_archives' )
		);

		add_settings_field(
			'noindex_date_archives',
			__( 'Noindex date archives', 'meyvora-seo' ),
			array( $this, 'field_checkbox' ),
			$page_general,
			'meyvora_seo_robots',
			array( 'key' => 'noindex_date_archives' )
		);

		if ( class_exists( 'WooCommerce' ) ) {
			add_settings_section(
				'meyvora_seo_woocommerce',
				__( 'WooCommerce shop page', 'meyvora-seo' ),
				array( $this, 'section_woocommerce_shop_desc' ),
				$page_general
			);
			add_settings_field(
				'wc_shop_seo_title',
				__( 'Shop page SEO title', 'meyvora-seo' ),
				array( $this, 'field_text' ),
				$page_general,
				'meyvora_seo_woocommerce',
				array( 'label_for' => 'wc_shop_seo_title', 'key' => 'wc_shop_seo_title', 'type' => 'text', 'class' => 'large-text', 'description' => __( 'Custom title for the main WooCommerce shop page.', 'meyvora-seo' ) )
			);
			add_settings_field(
				'wc_shop_seo_description',
				__( 'Shop page meta description', 'meyvora-seo' ),
				array( $this, 'field_text' ),
				$page_general,
				'meyvora_seo_woocommerce',
				array( 'label_for' => 'wc_shop_seo_description', 'key' => 'wc_shop_seo_description', 'type' => 'textarea', 'class' => 'large-text', 'description' => __( 'Custom meta description for the main WooCommerce shop page.', 'meyvora-seo' ) )
			);
			add_settings_field(
				'wc_oos_auto_redirect',
				__( 'Auto-redirect out-of-stock products to category page', 'meyvora-seo' ),
				array( $this, 'field_checkbox' ),
				$page_general,
				'meyvora_seo_woocommerce',
				array( 'key' => 'wc_oos_auto_redirect', 'description' => __( 'When a product goes out of stock, create a 302 redirect from the product URL to its primary category (or shop). When back in stock, the redirect is removed.', 'meyvora-seo' ) )
			);
		}

		if ( defined( 'ICL_SITEPRESS_VERSION' ) || function_exists( 'pll_get_post_translations' ) ) {
			add_settings_section(
				'meyvora_seo_multilingual',
				__( 'Multilingual (hreflang)', 'meyvora-seo' ),
				array( $this, 'section_multilingual_desc' ),
				$page_general
			);
			add_settings_field(
				'hreflang_enabled',
				__( 'Output hreflang tags', 'meyvora-seo' ),
				array( $this, 'field_checkbox' ),
				$page_general,
				'meyvora_seo_multilingual',
				array( 'key' => 'hreflang_enabled', 'description' => __( 'Add <link rel="alternate" hreflang="..."> in the head for translated content.', 'meyvora-seo' ) )
			);
			add_settings_field(
				'sitemap_hreflang_enabled',
				__( 'Add hreflang to sitemap', 'meyvora-seo' ),
				array( $this, 'field_checkbox' ),
				$page_general,
				'meyvora_seo_multilingual',
				array( 'key' => 'sitemap_hreflang_enabled', 'description' => __( 'Include alternate language URLs (xhtml:link) in the XML sitemap.', 'meyvora-seo' ) )
			);
		}

		add_settings_section(
			'meyvora_seo_social',
			__( 'Social', 'meyvora-seo' ),
			array( $this, 'section_social_desc' ),
			$page_social
		);

		add_settings_field(
			'open_graph',
			__( 'Enable Open Graph', 'meyvora-seo' ),
			array( $this, 'field_checkbox' ),
			$page_social,
			'meyvora_seo_social',
			array( 'key' => 'open_graph' )
		);

		add_settings_field(
			'twitter_cards',
			__( 'Enable Twitter Cards', 'meyvora-seo' ),
			array( $this, 'field_checkbox' ),
			$page_social,
			'meyvora_seo_social',
			array( 'key' => 'twitter_cards' )
		);

		add_settings_section( 'meyvora_seo_sitemap', __( 'Sitemaps', 'meyvora-seo' ), array( $this, 'section_sitemap_desc' ), $page_sitemap );
		add_settings_field( 'sitemap_enabled', __( 'Enable XML Sitemap', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_sitemap, 'meyvora_seo_sitemap', array( 'key' => 'sitemap_enabled' ) );
		add_settings_field( 'sitemap_exclude_ids', __( 'Exclude post IDs (comma-separated)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_sitemap, 'meyvora_seo_sitemap', array( 'label_for' => 'sitemap_exclude_ids', 'key' => 'sitemap_exclude_ids', 'type' => 'text', 'class' => 'regular-text' ) );
		add_settings_field( 'sitemap_news_enabled', __( 'Include News Sitemap', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_sitemap, 'meyvora_seo_sitemap', array( 'key' => 'sitemap_news_enabled' ) );
		add_settings_field( 'sitemap_news_post_type', __( 'News post type slug (default: post)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_sitemap, 'meyvora_seo_sitemap', array( 'label_for' => 'sitemap_news_post_type', 'key' => 'sitemap_news_post_type', 'type' => 'text', 'class' => 'regular-text' ) );
		add_settings_field( 'sitemap_video_enabled', __( 'Include Video Sitemap', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_sitemap, 'meyvora_seo_sitemap', array( 'key' => 'sitemap_video_enabled' ) );
		add_settings_field( 'sitemap_ping_google_enabled', __( 'Automatically ping Google when content is published', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_sitemap, 'meyvora_seo_sitemap', array( 'key' => 'sitemap_ping_google_enabled', 'description' => __( 'Sends Google a sitemap ping when a public post reaches “Published”. You can still ping manually from the setup wizard.', 'meyvora-seo' ) ) );

		add_settings_section( 'meyvora_seo_schema', __( 'Schema', 'meyvora-seo' ), array( $this, 'section_schema_desc' ), $page_schema );
		add_settings_field( 'schema_organization', __( 'Enable Organization schema', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_schema, 'meyvora_seo_schema', array( 'key' => 'schema_organization' ) );
		add_settings_field( 'schema_organization_name', __( 'Organization name', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_organization_name', 'key' => 'schema_organization_name', 'type' => 'text' ) );
		add_settings_field( 'schema_organization_logo', __( 'Organization logo (attachment ID)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_organization_logo', 'key' => 'schema_organization_logo', 'type' => 'number', 'class' => 'small-text' ) );
		add_settings_field( 'schema_faq', __( 'Enable FAQPage schema', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_schema, 'meyvora_seo_schema', array( 'key' => 'schema_faq' ) );
		add_settings_field( 'schema_video', __( 'Enable VideoObject schema', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_schema, 'meyvora_seo_schema', array( 'key' => 'schema_video' ) );
		add_settings_field( 'schema_local_business', __( 'Enable LocalBusiness schema', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_schema, 'meyvora_seo_schema', array( 'key' => 'schema_local_business' ) );
		add_settings_field( 'schema_lb_name', __( 'LocalBusiness name', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_name', 'key' => 'schema_lb_name', 'type' => 'text' ) );
		add_settings_field( 'schema_lb_type', __( 'LocalBusiness type', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_type', 'key' => 'schema_lb_type', 'type' => 'text', 'description' => __( 'e.g. Restaurant, Store', 'meyvora-seo' ) ) );
		add_settings_field( 'schema_lb_street', __( 'Street address', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_street', 'key' => 'schema_lb_street' ) );
		add_settings_field( 'schema_lb_locality', __( 'City / Locality', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_locality', 'key' => 'schema_lb_locality' ) );
		add_settings_field( 'schema_lb_region', __( 'State / Region', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_region', 'key' => 'schema_lb_region' ) );
		add_settings_field( 'schema_lb_postal', __( 'Postal code', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_postal', 'key' => 'schema_lb_postal' ) );
		add_settings_field( 'schema_lb_country', __( 'Country', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_country', 'key' => 'schema_lb_country' ) );
		add_settings_field( 'schema_lb_phone', __( 'Telephone', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_phone', 'key' => 'schema_lb_phone' ) );
		add_settings_field( 'schema_lb_hours', __( 'Opening hours', 'meyvora-seo' ), array( $this, 'field_opening_hours' ), $page_schema, 'meyvora_seo_schema', array( 'key' => 'schema_lb_hours' ) );
		add_settings_field( 'schema_lb_lat', __( 'Latitude', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_lat', 'key' => 'schema_lb_lat', 'class' => 'small-text' ) );
		add_settings_field( 'schema_lb_lng', __( 'Longitude', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_lng', 'key' => 'schema_lb_lng', 'class' => 'small-text' ) );
		add_settings_field( 'schema_lb_price_range', __( 'Price range (e.g. $$)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_schema, 'meyvora_seo_schema', array( 'label_for' => 'schema_lb_price_range', 'key' => 'schema_lb_price_range' ) );

		add_settings_section( 'meyvora_seo_breadcrumbs', __( 'Breadcrumbs', 'meyvora-seo' ), array( $this, 'section_breadcrumbs_desc' ), $page_bread );
		add_settings_field( 'breadcrumbs_enabled', __( 'Enable breadcrumbs', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_bread, 'meyvora_seo_breadcrumbs', array( 'key' => 'breadcrumbs_enabled' ) );

		$page_ai = 'meyvora-seo-ai';
		add_settings_section( 'meyvora_seo_ai', __( 'AI SEO Assistant', 'meyvora-seo' ), array( $this, 'section_ai_desc' ), $page_ai );
		add_settings_field( 'ai_api_key', __( 'OpenAI API key', 'meyvora-seo' ), array( $this, 'field_ai_api_key' ), $page_ai, 'meyvora_seo_ai', array( 'label_for' => 'ai_api_key' ) );
		add_settings_field( 'ai_api_provider', __( 'Provider', 'meyvora-seo' ), array( $this, 'field_select' ), $page_ai, 'meyvora_seo_ai', array( 'label_for' => 'ai_api_provider', 'key' => 'ai_api_provider', 'options' => array( 'openai' => __( 'OpenAI', 'meyvora-seo' ), 'custom' => __( 'Custom endpoint (OpenAI-compatible)', 'meyvora-seo' ) ) ) );
		add_settings_field( 'ai_custom_endpoint', __( 'Custom API endpoint URL', 'meyvora-seo' ), array( $this, 'field_text' ), $page_ai, 'meyvora_seo_ai', array( 'label_for' => 'ai_custom_endpoint', 'key' => 'ai_custom_endpoint', 'type' => 'url', 'class' => 'large-text', 'description' => __( 'Only used when Provider is Custom. e.g. https://api.openai.com/v1/chat/completions', 'meyvora-seo' ) ) );
		add_settings_field( 'ai_model', __( 'Model', 'meyvora-seo' ), array( $this, 'field_ai_model' ), $page_ai, 'meyvora_seo_ai', array( 'label_for' => 'ai_model', 'key' => 'ai_model' ) );
		add_settings_field( 'ai_custom_system_prompt', __( 'Custom system prompt', 'meyvora-seo' ), array( $this, 'field_text' ), $page_ai, 'meyvora_seo_ai', array( 'label_for' => 'ai_custom_system_prompt', 'key' => 'ai_custom_system_prompt', 'type' => 'textarea', 'class' => 'large-text', 'rows' => 5, 'description' => __( 'Override the system instruction sent to the AI. Leave empty to use built-in prompts.', 'meyvora-seo' ) ) );
		add_settings_field( 'ai_rate_limit', __( 'Max AI calls per user per day', 'meyvora-seo' ), array( $this, 'field_text' ), $page_ai, 'meyvora_seo_ai', array( 'label_for' => 'ai_rate_limit', 'key' => 'ai_rate_limit', 'type' => 'number', 'class' => 'small-text', 'description' => __( 'Default: 100. Caps daily proxy calls to your AI provider per user (abuse protection). Developers can raise the effective cap with the meyvora_seo_ai_daily_call_limit filter.', 'meyvora-seo' ) ) );
		add_settings_field( 'dataforseo_api_key', __( 'DataForSEO API key (optional)', 'meyvora-seo' ), array( $this, 'field_dataforseo_api_key' ), $page_ai, 'meyvora_seo_ai', array( 'label_for' => 'dataforseo_api_key' ) );

		$page_integrations = 'meyvora-seo-integrations';
		add_settings_section( 'meyvora_seo_gsc', __( 'Google Search Console', 'meyvora-seo' ), array( $this, 'section_gsc_desc' ), $page_integrations );
		add_settings_field( 'gsc_client_id', __( 'OAuth Client ID', 'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_gsc', array( 'label_for' => 'gsc_client_id', 'key' => 'gsc_client_id', 'type' => 'text', 'class' => 'regular-text', 'description' => __( 'From Google Cloud Console. Create OAuth 2.0 credentials and add redirect URI: your site Admin → Settings → Integrations (see tab URL).', 'meyvora-seo' ) ) );
		add_settings_field( 'gsc_client_secret', __( 'OAuth Client Secret', 'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_gsc', array( 'label_for' => 'gsc_client_secret', 'key' => 'gsc_client_secret', 'type' => 'password', 'class' => 'regular-text', 'description' => __( 'Keep blank to leave unchanged.', 'meyvora-seo' ) ) );
		add_settings_section( 'meyvora_seo_ga4', __( 'Google Analytics 4', 'meyvora-seo' ), array( $this, 'section_ga4_desc' ), $page_integrations );
		add_settings_field( 'ga4_measurement_id', __( 'GA4 Measurement ID (G-XXXXXXX)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_ga4', array( 'label_for' => 'ga4_measurement_id', 'key' => 'ga4_measurement_id', 'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Adds gtag.js to the public front end only (never in wp-admin).', 'meyvora-seo' ) ) );
		add_settings_field( 'ga4_exclude_admins', __( 'Exclude logged-in admins from tracking', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_integrations, 'meyvora_seo_ga4', array( 'key' => 'ga4_exclude_admins', 'description' => __( 'When enabled, users with manage_options are not tracked on the public site.', 'meyvora-seo' ) ) );
		add_settings_section( 'meyvora_seo_pagespeed', __( 'PageSpeed Insights', 'meyvora-seo' ), function () {
			echo '<p>' . esc_html__( 'Core Web Vitals (LCP, CLS, TBT) are fetched from Google PageSpeed Insights. An API key is optional and removes rate limits.', 'meyvora-seo' ) . '</p>';
		}, $page_integrations );
		add_settings_field( 'pagespeed_api_key', __( 'PageSpeed Insights API Key (optional, removes rate limits)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_pagespeed', array( 'label_for' => 'pagespeed_api_key', 'key' => 'pagespeed_api_key', 'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Get an API key from Google Cloud Console (PageSpeed Insights API). Leave empty for anonymous requests.', 'meyvora-seo' ) ) );
		add_settings_section(
			'meyvora_seo_webmaster_verify',
			__( 'Site Verification', 'meyvora-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Paste the content value of each verification meta tag (the code only, not the full tag).', 'meyvora-seo' ) . '</p>';
			},
			$page_integrations
		);
		add_settings_field( 'verify_google',    __( 'Google verification',    'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_webmaster_verify', array( 'label_for' => 'verify_google',    'key' => 'verify_google',    'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Google Search Console verification code.', 'meyvora-seo' ) ) );
		add_settings_field( 'verify_bing',      __( 'Bing verification',      'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_webmaster_verify', array( 'label_for' => 'verify_bing',      'key' => 'verify_bing',      'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Bing Webmaster Tools verification code.', 'meyvora-seo' ) ) );
		add_settings_field( 'verify_pinterest', __( 'Pinterest verification', 'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_webmaster_verify', array( 'label_for' => 'verify_pinterest', 'key' => 'verify_pinterest', 'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Pinterest domain verification code.', 'meyvora-seo' ) ) );
		add_settings_field( 'verify_yandex',    __( 'Yandex verification',    'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_webmaster_verify', array( 'label_for' => 'verify_yandex',    'key' => 'verify_yandex',    'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Yandex Webmaster verification code.', 'meyvora-seo' ) ) );
		add_settings_field( 'verify_baidu',     __( 'Baidu verification',     'meyvora-seo' ), array( $this, 'field_text' ), $page_integrations, 'meyvora_seo_webmaster_verify', array( 'label_for' => 'verify_baidu',     'key' => 'verify_baidu',     'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Baidu Search verification code.', 'meyvora-seo' ) ) );

		$page_reports = 'meyvora-seo-reports';
		add_settings_section(
			'meyvora_seo_reports_email',
			__( 'Weekly email report', 'meyvora-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Receive a weekly SEO summary by email: overall score, new issues count, and top 3 pages to fix.', 'meyvora-seo' ) . '</p>';
			},
			$page_reports
		);
		add_settings_field( 'reports_email_enabled', __( 'Enable weekly email', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_reports, 'meyvora_seo_reports_email', array( 'key' => 'reports_email_enabled', 'description' => __( 'Send weekly SEO report to the recipients below.', 'meyvora-seo' ) ) );
		add_settings_field( 'reports_email_recipients', __( 'Recipients', 'meyvora-seo' ), array( $this, 'field_text' ), $page_reports, 'meyvora_seo_reports_email', array( 'label_for' => 'reports_email_recipients', 'key' => 'reports_email_recipients', 'type' => 'textarea', 'class' => 'large-text', 'description' => __( 'One email per line. Leave empty to use the site admin email.', 'meyvora-seo' ), 'rows' => 3 ) );
		add_settings_field( 'reports_email_day', __( 'Day of week', 'meyvora-seo' ), array( $this, 'field_select' ), $page_reports, 'meyvora_seo_reports_email', array(
			'label_for'   => 'reports_email_day',
			'key'         => 'reports_email_day',
			'options'     => array(
				0 => __( 'Sunday', 'meyvora-seo' ),
				1 => __( 'Monday', 'meyvora-seo' ),
				2 => __( 'Tuesday', 'meyvora-seo' ),
				3 => __( 'Wednesday', 'meyvora-seo' ),
				4 => __( 'Thursday', 'meyvora-seo' ),
				5 => __( 'Friday', 'meyvora-seo' ),
				6 => __( 'Saturday', 'meyvora-seo' ),
			),
			'description' => __( 'Report is sent at 02:00 on the chosen day (server time).', 'meyvora-seo' ),
		) );

		add_settings_section( 'meyvora_seo_advanced', __( 'Advanced', 'meyvora-seo' ), array( $this, 'section_advanced_desc' ), $page_adv );
		add_settings_field( 'noindex_replytocom', __( 'Noindex replytocom URLs', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_advanced', array( 'key' => 'noindex_replytocom' ) );
		add_settings_field( 'strip_session_ids', __( 'Strip session IDs from URLs', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_advanced', array( 'key' => 'strip_session_ids' ) );
		add_settings_field( 'rss_append_link', __( 'Append link to full post in RSS', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_advanced', array( 'key' => 'rss_append_link' ) );
		add_settings_field( 'rss_excerpt_only', __( 'RSS excerpt only (no full content)', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_advanced', array( 'key' => 'rss_excerpt_only' ) );
		add_settings_section( 'meyvora_seo_404', __( '404 Monitor', 'meyvora-seo' ), array( $this, 'section_404_desc' ), $page_adv );
		add_settings_field( '404_email_alert', __( 'Email alert for 404s', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_404', array( 'key' => '404_email_alert', 'description' => __( 'Send daily email when any 404 URL has hits at or above the threshold.', 'meyvora-seo' ) ) );
		add_settings_field( '404_alert_threshold', __( 'Alert threshold (hits)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_adv, 'meyvora_seo_404', array( 'label_for' => '404_alert_threshold', 'key' => '404_alert_threshold', 'type' => 'number', 'class' => 'small-text', 'description' => __( 'Minimum hit count to include in daily email (1–1000).', 'meyvora-seo' ) ) );

		add_settings_section(
			'meyvora_seo_background_outbound',
			__( 'Background external checks', 'meyvora-seo' ),
			array( $this, 'section_background_outbound_desc' ),
			$page_adv
		);
		add_settings_field( 'link_checker_background_enabled', __( 'Link Checker: automated remote checks', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_background_outbound', array( 'key' => 'link_checker_background_enabled', 'description' => __( 'Allows the scheduled task to send HTTP HEAD requests to external URLs found in recent content (used for the Link Checker admin page). Disabled by default. Manual fixes in the UI do not need this.', 'meyvora-seo' ) ) );
		add_settings_field( 'competitor_monitor_enabled', __( 'Competitor: weekly automatic refresh', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_background_outbound', array( 'key' => 'competitor_monitor_enabled', 'description' => __( 'Re-fetch saved competitor URLs in the background weekly to detect content changes (and trigger alerts if configured). Analyze / snapshot actions from the Competitor screen are unaffected.', 'meyvora-seo' ) ) );

		if ( current_user_can( 'manage_options' ) ) {
			add_settings_section(
				'meyvora_seo_white_label',
				__( 'White Label', 'meyvora-seo' ),
				array( $this, 'section_white_label_desc' ),
				$page_adv
			);
			add_settings_field( 'white_label_enabled', __( 'Enable white label', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_white_label', array( 'key' => 'white_label_enabled', 'description' => __( 'Replace plugin name in menu and dashboard with your own branding.', 'meyvora-seo' ) ) );
			add_settings_field( 'white_label_menu_name', __( 'Menu name', 'meyvora-seo' ), array( $this, 'field_text' ), $page_adv, 'meyvora_seo_white_label', array( 'label_for' => 'white_label_menu_name', 'key' => 'white_label_menu_name', 'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Shown in the admin menu. Leave empty to use "SEO".', 'meyvora-seo' ) ) );
			add_settings_field( 'white_label_logo_id', __( 'Dashboard logo (attachment ID)', 'meyvora-seo' ), array( $this, 'field_text' ), $page_adv, 'meyvora_seo_white_label', array( 'label_for' => 'white_label_logo_id', 'key' => 'white_label_logo_id', 'type' => 'number', 'class' => 'small-text', 'description' => __( 'Media attachment ID. When set, shown in dashboard header instead of title.', 'meyvora-seo' ) ) );
			add_settings_field( 'white_label_dashboard_title', __( 'Dashboard title', 'meyvora-seo' ), array( $this, 'field_text' ), $page_adv, 'meyvora_seo_white_label', array( 'label_for' => 'white_label_dashboard_title', 'key' => 'white_label_dashboard_title', 'type' => 'text', 'class' => 'regular-text', 'description' => __( 'Heading on the dashboard. Used when logo is not set.', 'meyvora-seo' ) ) );
		}

		add_settings_section(
			'meyvora_seo_score_alert',
			__( 'Score Drop Alerts', 'meyvora-seo' ),
			array( $this, 'section_score_alert_desc' ),
			$page_adv
		);
		add_settings_field( 'score_alert_enabled', __( 'Enable score drop alerts', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_score_alert', array( 'key' => 'score_alert_enabled', 'description' => __( 'Notify when an SEO score drops by the threshold or more after analysis.', 'meyvora-seo' ) ) );
		add_settings_field( 'score_alert_email', __( 'Alert email address', 'meyvora-seo' ), array( $this, 'field_text' ), $page_adv, 'meyvora_seo_score_alert', array( 'label_for' => 'score_alert_email', 'key' => 'score_alert_email', 'type' => 'email', 'class' => 'regular-text', 'description' => __( 'Receive alerts at this email when a post\'s score drops.', 'meyvora-seo' ) ) );
		add_settings_field( 'score_alert_slack_enabled', __( 'Send score alerts to Slack', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_adv, 'meyvora_seo_score_alert', array( 'key' => 'score_alert_slack_enabled', 'description' => __( 'When enabled, automatic alerts post to your Slack Incoming Webhook. The Test button still works when this is off.', 'meyvora-seo' ) ) );
		add_settings_field( 'score_alert_slack', __( 'Slack webhook URL', 'meyvora-seo' ), array( $this, 'field_score_alert_slack' ), $page_adv, 'meyvora_seo_score_alert' );
		add_settings_field( 'score_alert_threshold', __( 'Drop threshold', 'meyvora-seo' ), array( $this, 'field_text' ), $page_adv, 'meyvora_seo_score_alert', array( 'label_for' => 'score_alert_threshold', 'key' => 'score_alert_threshold', 'type' => 'number', 'class' => 'small-text', 'description' => __( 'Alert when score drops by this many points or more (default 10).', 'meyvora-seo' ) ) );

		add_settings_field(
			'attachment_redirect',
			__( 'Attachment page redirect', 'meyvora-seo' ),
			array( $this, 'field_attachment_redirect' ),
			$page_adv,
			'meyvora_seo_advanced',
			array( 'label_for' => 'attachment_redirect' )
		);
		add_settings_field(
			'seo_access_roles',
			__( 'SEO editing access', 'meyvora-seo' ),
			array( $this, 'field_seo_access_roles' ),
			$page_adv,
			'meyvora_seo_advanced',
			array( 'label_for' => 'seo_access_roles' )
		);

		$page_local = 'meyvora-seo-local';

		add_settings_section(
			'meyvora_seo_local_business',
			__( 'LocalBusiness Schema', 'meyvora-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Outputs LocalBusiness JSON-LD schema on every page — essential for appearing in Google local pack, Maps, and knowledge panels.', 'meyvora-seo' ) . '</p>';
			},
			$page_local
		);

		add_settings_field( 'schema_local_business', __( 'Enable LocalBusiness Schema', 'meyvora-seo' ), array( $this, 'field_checkbox' ), $page_local, 'meyvora_seo_local_business', array( 'key' => 'schema_local_business', 'description' => __( 'Outputs structured data on every page.', 'meyvora-seo' ) ) );

		add_settings_field( 'schema_lb_type', __( 'Business Type', 'meyvora-seo' ), array( $this, 'field_select' ), $page_local, 'meyvora_seo_local_business', array(
			'label_for'   => 'schema_lb_type',
			'key'         => 'schema_lb_type',
			'description' => __( 'Choose the most specific type that fits your business.', 'meyvora-seo' ),
			'options'     => array(
				'LocalBusiness'    => 'LocalBusiness (generic)',
				'Restaurant'       => 'Restaurant',
				'Store'            => 'Store / Retail',
				'MedicalBusiness'  => 'Medical / Healthcare',
				'FinancialService' => 'Financial Service',
				'LegalService'     => 'Legal Service',
				'RealEstateAgent'  => 'Real Estate Agent',
				'Hotel'            => 'Hotel / Accommodation',
				'AutoRepair'       => 'Auto Repair',
				'Dentist'          => 'Dentist',
				'Hospital'         => 'Hospital',
				'Gym'              => 'Gym / Fitness',
				'SpaOrBeautySalon' => 'Spa / Beauty Salon',
				'TouristAttraction' => 'Tourist Attraction',
			),
		) );

		add_settings_field( 'schema_lb_name', __( 'Business Name', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_business', array( 'label_for' => 'schema_lb_name', 'key' => 'schema_lb_name', 'placeholder' => get_bloginfo( 'name' ) ) );
		add_settings_field( 'schema_lb_phone', __( 'Phone Number', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_business', array( 'label_for' => 'schema_lb_phone', 'key' => 'schema_lb_phone', 'placeholder' => '+1 555 000 0000', 'description' => __( 'Include country code.', 'meyvora-seo' ) ) );
		add_settings_field( 'schema_lb_email', __( 'Email Address', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_business', array( 'label_for' => 'schema_lb_email', 'key' => 'schema_lb_email', 'placeholder' => 'hello@yourbusiness.com' ) );
		add_settings_field( 'schema_lb_price_range', __( 'Price Range', 'meyvora-seo' ), array( $this, 'field_select' ), $page_local, 'meyvora_seo_local_business', array( 'label_for' => 'schema_lb_price_range', 'key' => 'schema_lb_price_range', 'options' => array( '' => __( 'Not specified', 'meyvora-seo' ), '$' => '$ (Budget)', '$$' => '$$ (Moderate)', '$$$' => '$$$ (Upscale)', '$$$$' => '$$$$ (Luxury)' ) ) );
		add_settings_field( 'schema_lb_hours', __( 'Opening hours', 'meyvora-seo' ), array( $this, 'field_opening_hours' ), $page_local, 'meyvora_seo_local_business', array( 'key' => 'schema_lb_hours' ) );

		add_settings_section(
			'meyvora_seo_local_address',
			__( 'Physical Address', 'meyvora-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Used for the PostalAddress property in the LocalBusiness schema.', 'meyvora-seo' ) . '</p>';
			},
			$page_local
		);

		add_settings_field( 'schema_lb_street', __( 'Street Address', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_address', array( 'label_for' => 'schema_lb_street', 'key' => 'schema_lb_street', 'placeholder' => '123 Main Street' ) );
		add_settings_field( 'schema_lb_locality', __( 'City', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_address', array( 'label_for' => 'schema_lb_locality', 'key' => 'schema_lb_locality', 'placeholder' => 'New York' ) );
		add_settings_field( 'schema_lb_region', __( 'State / Region', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_address', array( 'label_for' => 'schema_lb_region', 'key' => 'schema_lb_region', 'placeholder' => 'NY' ) );
		add_settings_field( 'schema_lb_postal', __( 'Postal Code', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_address', array( 'label_for' => 'schema_lb_postal', 'key' => 'schema_lb_postal', 'class' => 'small-text', 'placeholder' => '10001' ) );
		add_settings_field( 'schema_lb_country', __( 'Country Code', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_address', array( 'label_for' => 'schema_lb_country', 'key' => 'schema_lb_country', 'class' => 'small-text', 'placeholder' => 'US', 'description' => __( '2-letter ISO code, e.g. US, GB, IN, AU', 'meyvora-seo' ) ) );

		add_settings_section(
			'meyvora_seo_local_geo',
			__( 'GEO Coordinates', 'meyvora-seo' ),
			function () {
				echo '<p>' . esc_html__( 'Latitude and longitude power the GeoCoordinates schema property — critical for local pack rankings and map integrations.', 'meyvora-seo' ) . '</p>';
			},
			$page_local
		);

		add_settings_field( 'schema_lb_lat', __( 'Latitude', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_geo', array( 'label_for' => 'schema_lb_lat', 'key' => 'schema_lb_lat', 'class' => 'small-text', 'placeholder' => '40.7128', 'description' => __( 'Decimal format, e.g. 40.7128', 'meyvora-seo' ) ) );
		add_settings_field( 'schema_lb_lng', __( 'Longitude', 'meyvora-seo' ), array( $this, 'field_text' ), $page_local, 'meyvora_seo_local_geo', array( 'label_for' => 'schema_lb_lng', 'key' => 'schema_lb_lng', 'class' => 'small-text', 'placeholder' => '-74.0060', 'description' => __( 'Decimal format, e.g. -74.0060', 'meyvora-seo' ) ) );

		add_settings_field(
			'schema_lb_geo_helper',
			__( 'Auto-detect Coordinates', 'meyvora-seo' ),
			function () {
				?>
				<button type="button" class="mev-btn mev-btn--secondary mev-btn--sm mev-geo-coords-from-address-btn" style="margin-bottom:6px;">
					<?php echo wp_kses_post( meyvora_seo_icon( 'map', array( 'width' => 16, 'height' => 16 ) ) ); ?> <?php esc_html_e( 'Get coordinates from address above', 'meyvora-seo' ); ?>
				</button>
				<p class="mev-field-help"><?php esc_html_e( 'Fills in latitude/longitude from your address using free geocoding. Save after.', 'meyvora-seo' ); ?></p>
				<?php
			},
			$page_local,
			'meyvora_seo_local_geo'
		);
	}
	public function section_sitemap_desc(): void {
		echo '<p>' . esc_html__( 'XML sitemap is available at yoursite.com/sitemap.xml. Exclude specific post IDs if needed.', 'meyvora-seo' ) . '</p>';
	}
	public function section_schema_desc(): void {
		echo '<p>' . esc_html__( 'Organization, WebSite, FAQPage, VideoObject, and LocalBusiness schema. Set name and logo for publisher.', 'meyvora-seo' ) . '</p>';
	}

	public function section_advanced_desc(): void {
		echo '<p>' . esc_html__( 'Noindex options, URL cleanup, and RSS behavior.', 'meyvora-seo' ) . '</p>';
	}

	public function section_404_desc(): void {
		echo '<p>' . esc_html__( 'Daily email when 404 URLs exceed the hit threshold. View and fix 404s in 404 Monitor.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: White Label.
	 */
	public function section_white_label_desc(): void {
		echo '<p>' . esc_html__( 'When white-label is enabled, the plugin name in the admin menu, dashboard title, and logo will be replaced. The About page is unchanged.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: Score Drop Alerts.
	 */
	public function section_score_alert_desc(): void {
		echo '<p>' . esc_html__( 'Get notified by email and, if you opt in, Slack when an existing post\'s SEO score drops significantly after the next analysis. New posts (no previous score) do not trigger alerts.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Slack webhook URL field with Test button.
	 */
	public function field_score_alert_slack(): void {
		$key   = 'score_alert_slack';
		$value = $this->options->get( $key, '' );
		$id    = 'meyvora_seo_score_alert_slack';
		$name  = MEYVORA_SEO_OPTION_KEY . '[' . $key . ']';
		?>
		<div class="mev-field-input-col">
			<input type="url" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" style="width:320px;max-width:100%;padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;" placeholder="https://hooks.slack.com/services/..." />
			<button type="button" class="mev-btn mev-btn--secondary mev-btn--sm" id="mev-test-slack-webhook" style="margin-left:8px;vertical-align:middle;"><?php esc_html_e( 'Test', 'meyvora-seo' ); ?></button>
			<span id="mev-test-slack-result" style="margin-left:8px;font-size:12px;"></span>
			<p class="description" style="margin-top:8px;"><?php esc_html_e( 'Incoming webhook URL. Sends a test message when you click Test.', 'meyvora-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * AJAX: send a test payload to the Slack webhook URL.
	 */
	public function ajax_test_slack_webhook(): void {
		check_ajax_referer( 'meyvora_seo_test_slack', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'meyvora-seo' ) ) );
		}
		/*
		 * sanitize_url + wp_unslash: webhook URL for wp_remote_post(); not echoed as HTML here.
		 */
		$slack_url = isset( $_POST['slack_url'] ) ? trim( sanitize_url( wp_unslash( $_POST['slack_url'] ) ) ) : '';
		if ( $slack_url === '' || ! filter_var( $slack_url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid webhook URL.', 'meyvora-seo' ) ) );
		}
		$payload = wp_json_encode( array(
			'text' => __( '[Meyvora SEO] This is a test message from Score Drop Alerts. If you see this, your Slack webhook is working.', 'meyvora-seo' ),
		) );
		$response = wp_remote_post(
			$slack_url,
			array(
				'body'    => $payload,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 10,
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			wp_send_json_error( array( 'message' => sprintf( /* translators: %s: HTTP status or body */ __( 'Webhook returned %s.', 'meyvora-seo' ), (string) $code ) ) );
		}
		wp_send_json_success();
	}

	public function section_breadcrumbs_desc(): void {
		echo '<p>' . esc_html__( 'Use shortcode [meyvora_breadcrumbs] or template tag meyvora_seo_breadcrumbs().', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Sanitize callback for Settings API. Returns sanitized array; WordPress saves it.
	 *
	 * @param array<string, mixed> $input Raw form input.
	 * @return array<string, mixed>
	 */
	public function sanitize_options( array $input ): array {
		return $this->options->sanitize_and_merge( is_array( $input ) ? $input : array() );
	}

	/**
	 * Section description: Meta defaults.
	 */
	public function section_meta_desc(): void {
		echo '<p>' . esc_html__( 'Default templates for meta title and description. Per-post overrides are available in the post editor.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: Robots.
	 */
	public function section_robots_desc(): void {
		echo '<p>' . esc_html__( 'Add noindex to these archive types so search engines do not index them.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: WooCommerce shop page.
	 */
	public function section_woocommerce_shop_desc(): void {
		echo '<p>' . esc_html__( 'Custom SEO title and meta description for the main WooCommerce shop page (when different from the page’s own meta).', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: Multilingual (hreflang).
	 */
	public function section_multilingual_desc(): void {
		echo '<p>' . esc_html__( 'When WPML or Polylang is active, you can output hreflang tags and sitemap alternates so search engines know about your translated content.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: Google Search Console.
	 */
	public function section_gsc_desc(): void {
		echo '<p>' . esc_html__( 'Connect your Google account to show top keywords in the post editor and a Search Console widget on the dashboard. OAuth redirect URI must match the URL shown below.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: Google Analytics 4.
	 */
	public function section_ga4_desc(): void {
		echo '<p>' . esc_html__( 'Adds Google Analytics measurement to visitors on your public pages. No analytics scripts or GA API calls load in wp-admin.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Background tasks that contact third-party URLs from this site server.
	 */
	public function section_background_outbound_desc(): void {
		echo '<p>' . esc_html__( 'These options only affect automated background tasks. Screens where you click an action yourself are unchanged.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Section description: AI.
	 */
	public function section_ai_desc(): void {
		echo '<p>' . esc_html__( 'API key is stored encrypted. All AI requests go through the server; the key is never sent to the browser. Rate limit applies per WordPress user per day.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * AI API key field (value never displayed; submit as ai_api_key for encryption on save).
	 *
	 * @param array<string, mixed> $args
	 */
	public function field_ai_api_key( array $args ): void {
		$id   = isset( $args['label_for'] ) ? $args['label_for'] : 'ai_api_key';
		$name = MEYVORA_SEO_OPTION_KEY . '[ai_api_key]';
		$has  = $this->options->get( 'ai_api_key_encrypted', '' );
		?>
		<div class="mev-field-input-col">
			<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="" autocomplete="off" placeholder="<?php echo esc_attr( $has ? __( 'Leave blank to keep current key', 'meyvora-seo' ) : 'sk-…' ); ?>" class="regular-text" style="max-width:400px;" />
			<p class="mev-field-help" style="margin-top:5px;font-size:12px;color:var(--mev-gray-400);"><?php esc_html_e( 'Stored encrypted. Used for title/description generation, keyword suggestions, and content improvement.', 'meyvora-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * DataForSEO API key (password; value not shown in form).
	 *
	 * @param array<string, mixed> $args
	 */
	public function field_dataforseo_api_key( array $args ): void {
		$id   = isset( $args['label_for'] ) ? $args['label_for'] : 'dataforseo_api_key';
		$name = MEYVORA_SEO_OPTION_KEY . '[dataforseo_api_key]';
		$has  = $this->options->get( 'dataforseo_api_key', '' );
		?>
		<div class="mev-field-input-col">
			<input type="password" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="" autocomplete="off" placeholder="<?php echo esc_attr( $has ? __( 'Leave blank to keep current', 'meyvora-seo' ) : '' ); ?>" class="regular-text" style="max-width:400px;" />
			<p class="mev-field-help" style="margin-top:5px;font-size:12px;color:var(--mev-gray-400);"><?php esc_html_e( 'For search volume tier (High/Medium/Low) on keyword suggestions. Use format: login:password', 'meyvora-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * AI model: grouped select (OpenAI, Anthropic, Google).
	 *
	 * @param array<string, mixed> $args label_for, key.
	 */
	public function field_ai_model( array $args ): void {
		$key   = isset( $args['key'] ) ? $args['key'] : 'ai_model';
		$value = $this->options->get( $key, 'gpt-4o-mini' );
		$id    = isset( $args['label_for'] ) ? $args['label_for'] : 'meyvora_seo_' . $key;
		$name  = MEYVORA_SEO_OPTION_KEY . '[' . esc_attr( $key ) . ']';
		$groups = array(
			'OpenAI' => array(
				'gpt-4o-mini' => 'gpt-4o-mini (fast, cheap) [default]',
				'gpt-4o'      => 'gpt-4o',
				'gpt-4-turbo' => 'gpt-4-turbo',
			),
			'Anthropic (Custom Endpoint)' => array(
				'claude-3-5-haiku-20241022'  => 'claude-3-5-haiku-20241022',
				'claude-3-5-sonnet-20241022' => 'claude-3-5-sonnet-20241022',
				'claude-opus-4-5'            => 'claude-opus-4-5 (latest)',
			),
			'Google (Custom Endpoint)' => array(
				'gemini-2.0-flash' => 'gemini-2.0-flash',
				'gemini-1.5-pro'   => 'gemini-1.5-pro',
			),
		);
		$all_values = array();
		foreach ( $groups as $options ) {
			$all_values = array_merge( $all_values, array_keys( $options ) );
		}
		$value_in_list = in_array( $value, $all_values, true );
		?>
		<div class="mev-field-input-col">
			<select
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				style="width:320px;max-width:100%;padding:9px 32px 9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);background:var(--mev-surface) url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 12 12%22%3E%3Cpath d=%22M6 8L1 3h10z%22 fill=%22%236B7280%22/%3E%3C/svg%3E') no-repeat right 10px center;-webkit-appearance:none;appearance:none;cursor:pointer;transition:border-color 0.15s;"
			>
				<?php foreach ( $groups as $group_label => $options ) : ?>
					<optgroup label="<?php echo esc_attr( $group_label ); ?>">
						<?php foreach ( $options as $opt_val => $opt_label ) : ?>
							<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>><?php echo esc_html( $opt_label ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
				<?php if ( ! $value_in_list && is_string( $value ) && $value !== '' ) : ?>
					<optgroup label="<?php esc_attr_e( '— Custom —', 'meyvora-seo' ); ?>">
						<option value="<?php echo esc_attr( $value ); ?>" selected><?php echo esc_html( $value ); ?></option>
					</optgroup>
				<?php endif; ?>
			</select>
			<p class="description" style="margin-top:6px;font-size:12px;color:var(--mev-gray-400);line-height:1.5;"><?php esc_html_e( 'For Anthropic or Google models, set the custom endpoint URL below and use the matching API key format.', 'meyvora-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Opening hours: 7 rows (Mon–Sun), open/closed toggle + time pickers. Stored as JSON.
	 *
	 * @param array<string, mixed> $args key.
	 */
	public function field_opening_hours( array $args ): void {
		$key   = isset( $args['key'] ) ? $args['key'] : 'schema_lb_hours';
		$raw   = $this->options->get( $key, '' );
		$days  = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$rows  = array();
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $days as $d ) {
					$found = null;
					foreach ( $decoded as $row ) {
						if ( isset( $row['day'] ) && $row['day'] === $d ) {
							$found = $row;
							break;
						}
					}
					$rows[] = $found ? array(
						'day'    => $d,
						'closed' => ! empty( $found['closed'] ),
						'open'   => isset( $found['open'] ) ? (string) $found['open'] : '09:00',
						'close'  => isset( $found['close'] ) ? (string) $found['close'] : '17:00',
					) : array( 'day' => $d, 'closed' => true, 'open' => '09:00', 'close' => '17:00' );
				}
			}
		}
		if ( empty( $rows ) ) {
			foreach ( $days as $d ) {
				$rows[] = array( 'day' => $d, 'closed' => true, 'open' => '09:00', 'close' => '17:00' );
			}
		}
		$name  = MEYVORA_SEO_OPTION_KEY . '[' . esc_attr( $key ) . ']';
		$id    = 'schema_lb_hours';
		?>
		<div class="mev-field-input-col mev-opening-hours-wrap">
			<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( is_string( $raw ) ? $raw : '' ); ?>" />
			<table class="mev-opening-hours-table" style="border-collapse:collapse; max-width:420px;">
				<thead>
					<tr>
						<th style="text-align:left; padding:6px 8px 6px 0; font-size:12px; color:var(--mev-gray-600);"><?php esc_html_e( 'Day', 'meyvora-seo' ); ?></th>
						<th style="text-align:left; padding:6px 8px; font-size:12px; color:var(--mev-gray-600);"><?php esc_html_e( 'Open', 'meyvora-seo' ); ?></th>
						<th style="text-align:left; padding:6px 8px; font-size:12px; color:var(--mev-gray-600);"><?php esc_html_e( 'From', 'meyvora-seo' ); ?></th>
						<th style="text-align:left; padding:6px 8px; font-size:12px; color:var(--mev-gray-600);"><?php esc_html_e( 'To', 'meyvora-seo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $i => $row ) : ?>
						<tr data-day="<?php echo esc_attr( $row['day'] ); ?>">
							<td style="padding:4px 8px 4px 0; font-size:13px;"><?php echo esc_html( $row['day'] ); ?></td>
							<td style="padding:4px 8px;">
								<label class="mev-hours-open-toggle">
									<input type="checkbox" class="mev-hours-open" <?php checked( ! $row['closed'] ); ?> data-day="<?php echo esc_attr( $row['day'] ); ?>" />
									<span class="screen-reader-text"><?php esc_html_e( 'Open this day', 'meyvora-seo' ); ?></span>
								</label>
							</td>
							<td style="padding:4px 8px;">
								<input type="time" class="mev-hours-open-time small-text" value="<?php echo esc_attr( $row['open'] ); ?>" data-day="<?php echo esc_attr( $row['day'] ); ?>"<?php if ( $row['closed'] ) : ?> disabled="disabled"<?php endif; ?> style="padding:4px 8px; border:1px solid var(--mev-gray-200); border-radius:4px;" />
							</td>
							<td style="padding:4px 8px;">
								<input type="time" class="mev-hours-close-time small-text" value="<?php echo esc_attr( $row['close'] ); ?>" data-day="<?php echo esc_attr( $row['day'] ); ?>"<?php if ( $row['closed'] ) : ?> disabled="disabled"<?php endif; ?> style="padding:4px 8px; border:1px solid var(--mev-gray-200); border-radius:4px;" />
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="mev-field-help" style="margin-top:8px;"><?php esc_html_e( 'Toggle "Open" and set times per day. Stored as openingHoursSpecification in LocalBusiness schema.', 'meyvora-seo' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Section description: Social.
	 */
	public function section_social_desc(): void {
		echo '<p>' . esc_html__( 'Output Open Graph and Twitter Card meta tags when enabled.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Render a text field.
	 *
	 * @param array<string, mixed> $args label_for, key, type, class, description.
	 */
	public function field_text( array $args ): void {
		$key         = isset( $args['key'] ) ? $args['key'] : '';
		$value       = $this->options->get( $key, '' );
		$id          = isset( $args['label_for'] ) ? $args['label_for'] : 'meyvora_seo_' . $key;
		$name        = MEYVORA_SEO_OPTION_KEY . '[' . esc_attr( $key ) . ']';
		$type        = isset( $args['type'] ) ? $args['type'] : 'text';
		$class       = isset( $args['class'] ) ? $args['class'] : '';
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		$description = isset( $args['description'] ) ? $args['description'] : '';

		if ( strpos( $class, 'small-text' ) !== false ) {
			$style = 'width:80px;';
		} elseif ( strpos( $class, 'regular-text' ) !== false ) {
			$style = 'width:320px;max-width:100%;';
		} elseif ( strpos( $class, 'large-text' ) !== false ) {
			$style = 'width:100%;max-width:600px;';
		} else {
			$style = 'width:360px;max-width:100%;';
		}
		$input_style = $style . 'padding:9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);background:var(--mev-surface);transition:border-color 0.15s,box-shadow 0.15s;font-family:inherit;';
		?>
		<div class="mev-field-input-col">
			<?php if ( $type === 'textarea' ) : ?>
				<?php $rows = isset( $args['rows'] ) ? max( 1, (int) $args['rows'] ) : 3; ?>
				<textarea
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					rows="<?php echo esc_attr( $rows ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					style="<?php echo esc_attr( $input_style ); ?>"
					class="mev-settings-input"
				><?php echo esc_textarea( $value ); ?></textarea>
			<?php else : ?>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					style="<?php echo esc_attr( $input_style ); ?>"
					class="mev-settings-input"
				/>
			<?php endif; ?>
			<?php if ( $description ) : ?>
				<p class="mev-field-help" style="margin-top:5px;font-size:12px;color:var(--mev-gray-400);line-height:1.5;"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render a checkbox.
	 *
	 * @param array<string, mixed> $args key.
	 */
	public function field_checkbox( array $args ): void {
		$key         = isset( $args['key'] ) ? $args['key'] : '';
		$value       = $this->options->get( $key, false );
		$id          = 'meyvora_seo_' . $key;
		$name        = MEYVORA_SEO_OPTION_KEY . '[' . esc_attr( $key ) . ']';
		$description = isset( $args['description'] ) ? $args['description'] : '';
		?>
		<div class="mev-toggle-row">
			<label class="mev-toggle" for="<?php echo esc_attr( $id ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $id ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="1"
					<?php checked( $value ); ?>
				/>
				<span class="mev-toggle-slider"></span>
			</label>
		</div>
		<?php if ( $description ) : ?>
			<p class="mev-field-help" style="margin-top:6px;"><?php echo esc_html( $description ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a styled select dropdown.
	 *
	 * @param array<string, mixed> $args key, options (assoc array), description, label_for.
	 */
	public function field_select( array $args ): void {
		$key         = isset( $args['key'] ) ? $args['key'] : '';
		$value       = $this->options->get( $key, '' );
		$id          = isset( $args['label_for'] ) ? $args['label_for'] : 'meyvora_seo_' . $key;
		$name        = MEYVORA_SEO_OPTION_KEY . '[' . esc_attr( $key ) . ']';
		$options     = isset( $args['options'] ) ? $args['options'] : array();
		$description = isset( $args['description'] ) ? $args['description'] : '';
		?>
		<div class="mev-field-input-col">
			<select
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				style="width:320px;max-width:100%;padding:9px 32px 9px 12px;border:1.5px solid var(--mev-gray-200);border-radius:var(--mev-radius-sm);font-size:13px;color:var(--mev-gray-800);background:var(--mev-surface) url('data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 12 12%22%3E%3Cpath d=%22M6 8L1 3h10z%22 fill=%22%236B7280%22/%3E%3C/svg%3E') no-repeat right 10px center;-webkit-appearance:none;appearance:none;cursor:pointer;transition:border-color 0.15s;"
			>
				<?php foreach ( $options as $opt_val => $opt_label ) : ?>
					<option value="<?php echo esc_attr( $opt_val ); ?>" <?php selected( $value, $opt_val ); ?>>
						<?php echo esc_html( $opt_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( $description ) : ?>
				<p class="mev-field-help" style="margin-top:5px;font-size:12px;color:var(--mev-gray-400);line-height:1.5;"><?php echo esc_html( $description ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the attachment_redirect select field.
	 */
	public function field_attachment_redirect(): void {
		$val = $this->options->get( 'attachment_redirect', 'file' );
		$options = array(
			'file'   => __( 'Redirect to the media file URL (recommended)', 'meyvora-seo' ),
			'parent' => __( 'Redirect to the parent post', 'meyvora-seo' ),
			'none'   => __( 'No redirect — keep attachment pages', 'meyvora-seo' ),
		);
		echo '<select id="attachment_redirect" name="' . esc_attr( MEYVORA_SEO_OPTION_KEY ) . '[attachment_redirect]">';
		foreach ( $options as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $val, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Attachment pages have thin content and hurt SEO. Redirecting them is strongly recommended.', 'meyvora-seo' ) . '</p>';
	}

	/**
	 * Render the seo_access_roles checkboxes.
	 */
	public function field_seo_access_roles(): void {
		$val      = $this->options->get( 'seo_access_roles', array( 'administrator', 'editor', 'author' ) );
		$val      = is_array( $val ) ? $val : array( 'administrator', 'editor', 'author' );
		$all_roles = wp_roles()->get_names();
		echo '<fieldset>';
		foreach ( $all_roles as $role_slug => $role_name ) {
			$checked  = in_array( $role_slug, $val, true ) ? ' checked' : '';
			$disabled = $role_slug === 'administrator' ? ' disabled' : '';
			echo '<label style="display:block;margin-bottom:4px;">';
			echo '<input type="checkbox" name="' . esc_attr( MEYVORA_SEO_OPTION_KEY ) . '[seo_access_roles][]" value="' . esc_attr( $role_slug ) . '"' . esc_attr( $checked ) . esc_attr( $disabled ) . ' /> ';
			echo esc_html( translate_user_role( $role_name ) );
			if ( $role_slug === 'administrator' ) {
				echo ' <span style="color:#888;font-size:11px;">(' . esc_html__( 'always enabled', 'meyvora-seo' ) . ')</span>';
			}
			echo '</label>';
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Select which user roles can view and edit SEO fields on posts and pages. Administrators always have access.', 'meyvora-seo' ) . '</p>';
	}
}
