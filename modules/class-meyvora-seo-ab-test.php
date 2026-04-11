<?php
/**
 * Meta description A/B testing: serve variant, daily GSC snapshots, declare winner.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_AB_Test
 */
class Meyvora_SEO_AB_Test {

	const TABLE_AB_SNAPSHOTS = 'meyvora_seo_ab_snapshots';
	const MIN_DAYS_PER_VARIANT = 30;
	const MIN_IMPRESSIONS_DIFF = 30;
	const MIN_CTR_IMPROVEMENT = 0.005;

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
		$this->loader->add_action( 'init', $this, 'schedule_ab_snapshot_cron', 25, 0 );
		add_action( 'meyvora_seo_ab_snapshot', array( $this, 'run_snapshot' ) );
		add_action( 'wp_ajax_meyvora_seo_ab_switch', array( $this, 'ajax_switch_variant' ) );
		add_action( 'wp_ajax_meyvora_seo_ab_stop', array( $this, 'ajax_stop_test' ) );
	}

	/**
	 * Schedule daily A/B snapshot cron if not already scheduled.
	 */
	public function schedule_ab_snapshot_cron(): void {
		$hook = 'meyvora_seo_ab_snapshot';
		if ( ! wp_next_scheduled( $hook ) ) {
			wp_schedule_event( time() + 60, 'daily', $hook );
		}
	}

	/**
	 * Run daily snapshot: find posts with active A/B test, fetch GSC metrics, insert row, maybe declare winner.
	 */
	public function run_snapshot(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core postmeta table.
		$post_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
			MEYVORA_SEO_META_DESC_AB_ACTIVE
		) );
		if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
			return;
		}
		if ( ! class_exists( 'Meyvora_SEO_GSC' ) ) {
			return;
		}
		$gsc_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-gsc.php' : '';
		if ( ! ( $gsc_file && file_exists( $gsc_file ) ) ) {
			return;
		}
		require_once $gsc_file;
		$gsc = new Meyvora_SEO_GSC( $this->loader, $this->options );
		if ( ! $gsc->is_connected() ) {
			return;
		}
		$table = $wpdb->prefix . self::TABLE_AB_SNAPSHOTS;
		foreach ( $post_ids as $pid ) {
			$pid = (int) $pid;
			$variant = get_post_meta( $pid, MEYVORA_SEO_META_DESC_AB_ACTIVE, true );
			if ( $variant !== 'a' && $variant !== 'b' ) {
				continue;
			}
			$url = get_permalink( $pid );
			if ( ! $url || get_post_status( $pid ) !== 'publish' ) {
				continue;
			}
			$metrics = $gsc->get_metrics_for_page( $url );
			$clicks = (int) ( $metrics['clicks'] ?? 0 );
			$impressions = (int) ( $metrics['impressions'] ?? 0 );
			$ctr = $impressions > 0 ? (float) ( $clicks / $impressions ) : 0.0;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			$wpdb->insert(
				$table,
				array(
					'post_id'       => $pid,
					'variant'       => $variant,
					'clicks'        => $clicks,
					'impressions'   => $impressions,
					'ctr'           => $ctr,
					'snapshot_date' => gmdate( 'Y-m-d' ),
				),
				array( '%d', '%s', '%d', '%d', '%f', '%s' )
			);
			$this->maybe_declare_winner( $pid );
		}
	}

	/**
	 * After 30 days of snapshots per variant, compare CTR; adopt winner if conditions met.
	 *
	 * @param int $post_id Post ID.
	 */
	public function maybe_declare_winner( int $post_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_AB_SNAPSHOTS;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows_a = $wpdb->get_results( $wpdb->prepare(
			"SELECT clicks, impressions, ctr FROM {$table} WHERE post_id = %d AND variant = 'a'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from constant.
			$post_id
		), ARRAY_A );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table.
		$rows_b = $wpdb->get_results( $wpdb->prepare(
			"SELECT clicks, impressions, ctr FROM {$table} WHERE post_id = %d AND variant = 'b'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table from constant.
			$post_id
		), ARRAY_A );
		if ( ! is_array( $rows_a ) || ! is_array( $rows_b ) ) {
			return;
		}
		if ( count( $rows_a ) < self::MIN_DAYS_PER_VARIANT || count( $rows_b ) < self::MIN_DAYS_PER_VARIANT ) {
			return;
		}
		$clicks_a = $impressions_a = 0;
		foreach ( $rows_a as $r ) {
			$clicks_a += (int) ( $r['clicks'] ?? 0 );
			$impressions_a += (int) ( $r['impressions'] ?? 0 );
		}
		$clicks_b = $impressions_b = 0;
		foreach ( $rows_b as $r ) {
			$clicks_b += (int) ( $r['clicks'] ?? 0 );
			$impressions_b += (int) ( $r['impressions'] ?? 0 );
		}
		$ctr_a = $impressions_a > 0 ? ( $clicks_a / $impressions_a ) : 0.0;
		$ctr_b = $impressions_b > 0 ? ( $clicks_b / $impressions_b ) : 0.0;
		$impressions_diff = abs( $impressions_a - $impressions_b );
		if ( $impressions_diff < self::MIN_IMPRESSIONS_DIFF ) {
			return;
		}
		$winner = null;
		if ( $ctr_a >= $ctr_b + self::MIN_CTR_IMPROVEMENT ) {
			$winner = 'a';
		} elseif ( $ctr_b >= $ctr_a + self::MIN_CTR_IMPROVEMENT ) {
			$winner = 'b';
		}
		if ( $winner === null ) {
			return;
		}
		$variant_key = $winner === 'a' ? MEYVORA_SEO_META_DESC_VARIANT_A : MEYVORA_SEO_META_DESC_VARIANT_B;
		$winning_text = get_post_meta( $post_id, $variant_key, true );
		if ( ! is_string( $winning_text ) || $winning_text === '' ) {
			return;
		}
		$meta_key_for = function( $key ) use ( $post_id ) {
			return apply_filters( 'meyvora_seo_post_meta_key', $key, $post_id );
		};
		update_post_meta( $post_id, $meta_key_for( MEYVORA_SEO_META_DESCRIPTION ), $winning_text );
		update_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_ACTIVE, '' );
		$result = array(
			'winner'      => $winner,
			'a_ctr'       => round( $ctr_a * 100, 2 ),
			'b_ctr'       => round( $ctr_b * 100, 2 ),
			'declared_at' => time(),
		);
		update_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_RESULT, wp_json_encode( $result ) );
		do_action( 'meyvora_seo_ab_winner_declared', $post_id, $winner, $result );
	}

	/**
	 * AJAX: Switch active A/B variant (toggle between a and b).
	 */
	public function ajax_switch_variant(): void {
		check_ajax_referer( 'meyvora_seo_ab_test', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
			return;
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error();
			return;
		}
		$current    = get_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_ACTIVE, true );
		$new_variant = ( $current === 'a' ) ? 'b' : 'a';
		update_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_ACTIVE, $new_variant );
		wp_send_json_success( array(
			'active'  => $new_variant,
			'message' => sprintf(
				/* translators: %s: variant letter A or B */
				__( 'Now serving variant %s.', 'meyvora-seo' ),
				strtoupper( $new_variant )
			),
		) );
	}

	/**
	 * AJAX: Stop A/B test and adopt a variant as the live description.
	 */
	public function ajax_stop_test(): void {
		check_ajax_referer( 'meyvora_seo_ab_test', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
			return;
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error();
			return;
		}
		$winner_variant = sanitize_text_field( wp_unslash( $_POST['adopt_variant'] ?? 'a' ) );
		if ( ! in_array( $winner_variant, array( 'a', 'b' ), true ) ) {
			$winner_variant = 'a';
		}
		$variant_key  = $winner_variant === 'a'
			? MEYVORA_SEO_META_DESC_VARIANT_A
			: MEYVORA_SEO_META_DESC_VARIANT_B;
		$winning_text = get_post_meta( $post_id, $variant_key, true );
		if ( is_string( $winning_text ) && $winning_text !== '' ) {
			update_post_meta( $post_id, MEYVORA_SEO_META_DESCRIPTION, $winning_text );
		}
		update_post_meta( $post_id, MEYVORA_SEO_META_DESC_AB_ACTIVE, '' );
		update_post_meta(
			$post_id,
			MEYVORA_SEO_META_DESC_AB_RESULT,
			wp_json_encode( array(
				'winner'      => $winner_variant,
				'stopped_by'  => 'user',
				'declared_at' => time(),
			) )
		);
		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: %s: variant letter A or B */
				__( 'Test stopped. Variant %s adopted as the live description.', 'meyvora-seo' ),
				strtoupper( $winner_variant )
			),
		) );
	}
}
