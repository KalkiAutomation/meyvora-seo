<?php
/**
 * Plugin install/activation: create custom DB tables with dbDelta.
 * Tables: meyvora_seo_rank_history, meyvora_seo_audit_results.
 * Redirects and 404 tables are created by Meyvora_SEO_Redirects::create_tables().
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Install/activation only; dbDelta and table checks.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Install {

	const TABLE_RANK_HISTORY           = 'meyvora_seo_rank_history';
	const TABLE_AUDIT_RESULTS          = 'meyvora_seo_audit_results';
	const TABLE_LINK_CHECKS            = 'meyvora_seo_link_checks';
	const TABLE_COMPETITOR_SNAPSHOTS   = 'meyvora_seo_competitor_snapshots';
	const TABLE_AB_SNAPSHOTS           = 'meyvora_seo_ab_snapshots';

	/**
	 * Create rank tracker, audit results, and link checks tables. Uses dbDelta. Safe to call on every activation.
	 */
	public static function create_tables(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix;

		$sql_rank = "CREATE TABLE IF NOT EXISTS {$prefix}" . self::TABLE_RANK_HISTORY . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			keyword varchar(255) NOT NULL,
			position decimal(6,1) NOT NULL DEFAULT '0.0',
			date date NOT NULL,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			serp_feature varchar(50) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY keyword_date (keyword(191), date),
			KEY post_id (post_id),
			KEY idx_post_keyword_date (post_id, keyword(100), date)
		) $charset;";

		$sql_audit = "CREATE TABLE IF NOT EXISTS {$prefix}" . self::TABLE_AUDIT_RESULTS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			issue_type varchar(100) NOT NULL,
			severity varchar(20) NOT NULL DEFAULT 'warning',
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY severity (severity(10))
		) $charset;";

		$sql_link_checks = "CREATE TABLE IF NOT EXISTS {$prefix}" . self::TABLE_LINK_CHECKS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url varchar(2048) NOT NULL,
			post_id bigint(20) unsigned NOT NULL,
			anchor_text varchar(500) NOT NULL DEFAULT '',
			http_status int(11) NOT NULL DEFAULT 0,
			last_checked datetime DEFAULT NULL,
			is_broken tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY post_id (post_id),
			KEY is_broken (is_broken),
			KEY last_checked (last_checked)
		) $charset;";

		$sql_comp = "CREATE TABLE IF NOT EXISTS {$prefix}" . self::TABLE_COMPETITOR_SNAPSHOTS . " (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			url varchar(2000) NOT NULL,
			snapshot_data longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY url (url(191))
		) $charset;";

		$sql_ab = "CREATE TABLE IF NOT EXISTS {$prefix}" . self::TABLE_AB_SNAPSHOTS . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			variant char(1) NOT NULL,
			clicks int NOT NULL DEFAULT 0,
			impressions int NOT NULL DEFAULT 0,
			ctr float NOT NULL DEFAULT 0,
			snapshot_date date NOT NULL,
			PRIMARY KEY (id),
			KEY post_variant_date (post_id, variant, snapshot_date)
		) $charset;";

		require_once trailingslashit( get_home_path() ) . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_rank );
		dbDelta( $sql_audit );
		dbDelta( $sql_link_checks );
		dbDelta( $sql_comp );
		dbDelta( $sql_ab );
	}

	/**
	 * Upgrade existing installs: change position column from int to decimal(6,1); add composite index for rank_history.
	 */
	public static function maybe_upgrade_columns(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_RANK_HISTORY;
		$col   = $wpdb->get_row( $wpdb->prepare(
			"SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'position'",
			DB_NAME,
			$table
		) );
		if ( $col && stripos( $col->COLUMN_TYPE, 'int' ) !== false ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN position decimal(6,1) NOT NULL DEFAULT '0.0'" );
		}
		// Add composite index for daily cron (post_id, keyword, date) to avoid full table scan on large sites.
		$idx = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'idx_post_keyword_date'",
			DB_NAME,
			$table
		) );
		if ( $idx === '0' || $idx === null ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_post_keyword_date (post_id, keyword(100), date)" );
		}
		// Add serp_feature column for SERP feature tracking (Featured Snippet, Rich Results, etc.).
		$col = $wpdb->get_results( $wpdb->prepare(
			"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'serp_feature'",
			DB_NAME,
			$table
		) );
		if ( empty( $col ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN serp_feature varchar(50) NOT NULL DEFAULT ''" );
		}
	}
}
