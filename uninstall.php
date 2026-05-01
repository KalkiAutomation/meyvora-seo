<?php
/**
 * Fired when the plugin is uninstalled (deleted) from WordPress.
 * Removes only this plugin's options and post meta. Does not touch other data.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Uninstall script; table names from constant.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$keys_file = plugin_dir_path( __FILE__ ) . 'includes/meyvora-seo-keys.php';
if ( ! is_file( $keys_file ) ) {
	return;
}
require_once $keys_file;

if ( ! defined( 'MEYVORA_SEO_OPTION_KEY' ) || ! defined( 'MEYVORA_SEO_META_KEYS' ) ) {
	return;
}

delete_option( MEYVORA_SEO_OPTION_KEY );
delete_option( MEYVORA_SEO_VERSION_OPTION_KEY );

global $wpdb;
foreach ( MEYVORA_SEO_META_KEYS as $meta_key ) {
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $meta_key ), array( '%s' ) );
}

// Drop custom tables created by this plugin.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meyvora_seo_redirects" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meyvora_seo_404_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meyvora_seo_rank_history" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meyvora_seo_audit_results" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meyvora_seo_link_checks" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meyvora_seo_competitor_snapshots" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meyvora_seo_ab_snapshots" );

// Delete transients with meyvora prefix.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_meyvora_%' OR option_name LIKE '_transient_timeout_meyvora_%'" );

delete_option( 'meyvora_seo_last_sitemap_ping' );
delete_option( 'meyvora_seo_robots_txt' );
delete_option( 'meyvora_seo_redirects_db_version' );
delete_option( MEYVORA_SEO_GSC_REFRESH_TOKEN_OPTION );
delete_option( MEYVORA_SEO_WEEKLY_SNAPSHOTS_OPTION );
delete_option( MEYVORA_SEO_AUTOMATION_RULES_OPTION );
delete_option( 'meyvora_seo_ga4_credentials' );
delete_option( 'meyvora_seo_install_db_version' );
delete_option( 'meyvora_indexnow_ping_log' );
delete_option( 'indexnow_ping_log' );
delete_option( 'meyvora_seo_reports_gsc_snapshot' );
delete_option( 'meyvora_seo_reports_ga4_snapshot' );
delete_option( 'meyvora_seo_wizard_done' );
delete_option( 'meyvora_seo_wizard_redirect' );
delete_option( 'meyvora_seo_cannibalization_results' );
delete_option( 'meyvora_seo_topic_clusters' );
delete_site_option( 'meyvora_seo_network_cache' );

$term_meta_keys = array( 'meyvora_seo_term_title', 'meyvora_seo_term_description', 'meyvora_seo_term_og_image' );
foreach ( $term_meta_keys as $tmk ) {
	$wpdb->delete( $wpdb->termmeta, array( 'meta_key' => $tmk ), array( '%s' ) );
}

// Delete EEAT user meta
$eeat_keys = array(
	'meyvora_seo_eeat_expertise_area',
	'meyvora_seo_eeat_credentials',
	'meyvora_seo_eeat_organization_affiliation',
	'meyvora_seo_eeat_years_experience',
);
foreach ( $eeat_keys as $uk ) {
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $uk ), array( '%s' ) );
}

// Delete programmatic SEO template CPT posts and their meta
$template_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'meyvora_seo_template'" );
if ( is_array( $template_ids ) ) {
	foreach ( $template_ids as $tid ) {
		wp_delete_post( (int) $tid, true );
	}
}

// Remove all scheduled WP-Cron events created by this plugin.
wp_clear_scheduled_hook( 'meyvora_seo_rank_tracker_daily' );
wp_clear_scheduled_hook( 'meyvora_seo_rank_history_cleanup' );
wp_clear_scheduled_hook( 'meyvora_seo_run_audit_cron' );
wp_clear_scheduled_hook( 'meyvora_seo_link_checker_cron' );
wp_clear_scheduled_hook( 'meyvora_seo_404_log_cleanup' );
wp_clear_scheduled_hook( 'meyvora_seo_404_email_alert' );
wp_clear_scheduled_hook( 'meyvora_seo_weekly_snapshot' );
wp_clear_scheduled_hook( 'meyvora_seo_weekly_email' );
wp_clear_scheduled_hook( 'meyvora_seo_competitor_monitor' );
wp_clear_scheduled_hook( 'meyvora_seo_ab_snapshot' );
