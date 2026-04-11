<?php
/**
 * Shared option and meta keys for Meyvora SEO.
 * Single source of truth for uninstall and main plugin. Loadable from normal context or uninstall.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEYVORA_SEO_OPTION_KEY', 'meyvora_seo_settings' );
define( 'MEYVORA_SEO_VERSION_OPTION_KEY', 'meyvora_seo_version' );
define( 'MEYVORA_SEO_GSC_REFRESH_TOKEN_OPTION', 'meyvora_seo_gsc_refresh_token' );
define( 'MEYVORA_SEO_WEEKLY_SNAPSHOTS_OPTION', 'meyvora_seo_weekly_snapshots' );
define( 'MEYVORA_SEO_AUTOMATION_RULES_OPTION', 'meyvora_seo_automation_rules' );

define( 'MEYVORA_SEO_META_TITLE', '_meyvora_seo_title' );
define( 'MEYVORA_SEO_META_DESCRIPTION', '_meyvora_seo_description' );
define( 'MEYVORA_SEO_META_NOINDEX', '_meyvora_seo_noindex' );
define( 'MEYVORA_SEO_META_NOFOLLOW', '_meyvora_seo_nofollow' );

define( 'MEYVORA_SEO_META_FOCUS_KEYWORD', '_meyvora_seo_focus_keyword' );
define( 'MEYVORA_SEO_META_CANONICAL', '_meyvora_seo_canonical' );
define( 'MEYVORA_SEO_META_OG_TITLE', '_meyvora_seo_og_title' );
define( 'MEYVORA_SEO_META_OG_DESCRIPTION', '_meyvora_seo_og_description' );
define( 'MEYVORA_SEO_META_OG_IMAGE', '_meyvora_seo_og_image' );
define( 'MEYVORA_SEO_META_TWITTER_TITLE', '_meyvora_seo_twitter_title' );
define( 'MEYVORA_SEO_META_TWITTER_DESCRIPTION', '_meyvora_seo_twitter_description' );
define( 'MEYVORA_SEO_META_TWITTER_IMAGE', '_meyvora_seo_twitter_image' );
define( 'MEYVORA_SEO_META_SCORE', '_meyvora_seo_score' );
define( 'MEYVORA_SEO_META_SCORE_PREV', '_meyvora_seo_score_prev' );
define( 'MEYVORA_SEO_META_ANALYSIS', '_meyvora_seo_analysis' );
define( 'MEYVORA_SEO_META_SECONDARY_KEYWORDS', '_meyvora_seo_secondary_keywords' );
define( 'MEYVORA_SEO_META_SCHEMA_TYPE', '_meyvora_seo_schema_type' );
define( 'MEYVORA_SEO_META_BREADCRUMB_TITLE', '_meyvora_seo_breadcrumb_title' );
define( 'MEYVORA_SEO_META_NOODP', '_meyvora_seo_noodp' );
define( 'MEYVORA_SEO_META_NOARCHIVE', '_meyvora_seo_noarchive' );
define( 'MEYVORA_SEO_META_NOSNIPPET', '_meyvora_seo_nosnippet' );
define( 'MEYVORA_SEO_META_MAX_SNIPPET', '_meyvora_seo_max_snippet' );
define( 'MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW', '_meyvora_seo_max_image_preview' );
define( 'MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW', '_meyvora_seo_max_video_preview' );
define( 'MEYVORA_SEO_META_ANALYSIS_CACHE', '_meyvora_seo_analysis_cache' );
define( 'MEYVORA_SEO_META_READABILITY', '_meyvora_seo_readability' );
define( 'MEYVORA_SEO_META_SEARCH_INTENT', '_meyvora_seo_search_intent' );
define( 'MEYVORA_SEO_META_FAQ', '_meyvora_seo_faq' );
define( 'MEYVORA_SEO_META_SCHEMA_HOWTO', '_meyvora_seo_schema_howto' );
define( 'MEYVORA_SEO_META_SCHEMA_RECIPE', '_meyvora_seo_schema_recipe' );
define( 'MEYVORA_SEO_META_SCHEMA_EVENT', '_meyvora_seo_schema_event' );
define( 'MEYVORA_SEO_META_SCHEMA_COURSE', '_meyvora_seo_schema_course' );
define( 'MEYVORA_SEO_META_SCHEMA_JOBPOSTING', '_meyvora_seo_schema_jobposting' );
define( 'MEYVORA_SEO_META_SCHEMA_SOFTWARE', '_meyvora_seo_schema_software' );
define( 'MEYVORA_SEO_META_SCHEMA_REVIEW', '_meyvora_seo_schema_review' );
define( 'MEYVORA_SEO_META_SCHEMA_BOOK', '_meyvora_seo_schema_book' );
define( 'MEYVORA_SEO_META_SCHEMA_PRODUCT', '_meyvora_seo_schema_product' );
define( 'MEYVORA_SEO_META_CORNERSTONE', '_meyvora_seo_cornerstone' );
define( 'MEYVORA_SEO_META_KEYWORD_PRIMARY', '_meyvora_seo_keyword_primary' );
define( 'MEYVORA_SEO_META_SITEMAP_PRIORITY',   '_meyvora_seo_sitemap_priority' );
define( 'MEYVORA_SEO_META_SITEMAP_CHANGEFREQ', '_meyvora_seo_sitemap_changefreq' );
define( 'MEYVORA_SEO_META_CWV', '_meyvora_seo_cwv' );

define( 'MEYVORA_SEO_META_DESC_VARIANT_A', '_meyvora_seo_desc_variant_a' );
define( 'MEYVORA_SEO_META_DESC_VARIANT_B', '_meyvora_seo_desc_variant_b' );
define( 'MEYVORA_SEO_META_DESC_AB_ACTIVE', '_meyvora_seo_desc_ab_active' );
define( 'MEYVORA_SEO_META_DESC_AB_START', '_meyvora_seo_desc_ab_start' );
define( 'MEYVORA_SEO_META_DESC_AB_RESULT', '_meyvora_seo_desc_ab_result' );

define( 'MEYVORA_SEO_META_KEYS', array(
	MEYVORA_SEO_META_TITLE,
	MEYVORA_SEO_META_DESCRIPTION,
	MEYVORA_SEO_META_NOINDEX,
	MEYVORA_SEO_META_NOFOLLOW,
	MEYVORA_SEO_META_FOCUS_KEYWORD,
	MEYVORA_SEO_META_CANONICAL,
	MEYVORA_SEO_META_OG_TITLE,
	MEYVORA_SEO_META_OG_DESCRIPTION,
	MEYVORA_SEO_META_OG_IMAGE,
	MEYVORA_SEO_META_TWITTER_TITLE,
	MEYVORA_SEO_META_TWITTER_DESCRIPTION,
	MEYVORA_SEO_META_TWITTER_IMAGE,
	MEYVORA_SEO_META_SCORE,
	MEYVORA_SEO_META_SCORE_PREV,
	MEYVORA_SEO_META_ANALYSIS,
	MEYVORA_SEO_META_SECONDARY_KEYWORDS,
	MEYVORA_SEO_META_SCHEMA_TYPE,
	MEYVORA_SEO_META_BREADCRUMB_TITLE,
	MEYVORA_SEO_META_NOODP,
	MEYVORA_SEO_META_ANALYSIS_CACHE,
	MEYVORA_SEO_META_READABILITY,
	MEYVORA_SEO_META_SEARCH_INTENT,
	MEYVORA_SEO_META_FAQ,
	MEYVORA_SEO_META_SCHEMA_HOWTO,
	MEYVORA_SEO_META_SCHEMA_RECIPE,
	MEYVORA_SEO_META_SCHEMA_EVENT,
	MEYVORA_SEO_META_SCHEMA_COURSE,
	MEYVORA_SEO_META_SCHEMA_JOBPOSTING,
	MEYVORA_SEO_META_SCHEMA_SOFTWARE,
	MEYVORA_SEO_META_SCHEMA_REVIEW,
	MEYVORA_SEO_META_SCHEMA_BOOK,
	MEYVORA_SEO_META_SCHEMA_PRODUCT,
	MEYVORA_SEO_META_NOARCHIVE,
	MEYVORA_SEO_META_NOSNIPPET,
	MEYVORA_SEO_META_MAX_SNIPPET,
	MEYVORA_SEO_META_ROBOTS_MAX_IMAGE_PREVIEW,
	MEYVORA_SEO_META_ROBOTS_MAX_VIDEO_PREVIEW,
	MEYVORA_SEO_META_CORNERSTONE,
	MEYVORA_SEO_META_KEYWORD_PRIMARY,
	MEYVORA_SEO_META_SITEMAP_PRIORITY,
	MEYVORA_SEO_META_SITEMAP_CHANGEFREQ,
	MEYVORA_SEO_META_CWV,
	MEYVORA_SEO_META_DESC_VARIANT_A,
	MEYVORA_SEO_META_DESC_VARIANT_B,
	MEYVORA_SEO_META_DESC_AB_ACTIVE,
	MEYVORA_SEO_META_DESC_AB_START,
	MEYVORA_SEO_META_DESC_AB_RESULT,
) );