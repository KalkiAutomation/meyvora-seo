<?php
/**
 * Import SEO data from Yoast SEO, Rank Math, and All In One SEO.
 * Supports batch processing, dry run, redirects, and optional deletion of source meta.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Import uses direct queries for batch/counts; no WP API equivalent.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Import {

	const BATCH_SIZE = 100;

	/**
	 * Whether Yoast SEO (or Premium) post meta is available to import.
	 *
	 * @return bool
	 */
	public static function can_import_yoast(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_canonical') LIMIT 1"
		);
	}

	/**
	 * Whether Rank Math post meta is available to import.
	 *
	 * @return bool
	 */
	public static function can_import_rankmath(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_twitter_image_id') LIMIT 1"
		);
	}

	/**
	 * Whether All In One SEO post meta is available to import.
	 *
	 * @return bool
	 */
	public static function can_import_aioseo(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key IN ('_aioseop_title', '_aioseop_description', '_aioseop_keywords', '_aioseop_opengraph_title', '_aioseop_opengraph_description', '_aioseop_twitter_title', '_aioseop_twitter_description') LIMIT 1"
		);
	}

	/**
	 * Whether Yoast Premium redirects are available (stored in options).
	 *
	 * @return bool
	 */
	public static function has_yoast_redirects(): bool {
		$plain = get_option( 'wpseo-premium-redirects-export-plain', array() );
		if ( is_array( $plain ) && ! empty( $plain ) ) {
			return true;
		}
		$legacy = get_option( 'wpseo-premium-redirects-base', array() );
		return is_array( $legacy ) && ! empty( $legacy );
	}

	/**
	 * Whether Rank Math redirects table exists and has rows.
	 *
	 * @return bool
	 */
	public static function has_rankmath_redirects(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from $wpdb->prefix.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) > 0;
	}

	/**
	 * Get estimated counts per source (posts with importable meta, redirects).
	 *
	 * @return array{ yoast: array{ posts: int, redirects: int }, rankmath: array{ posts: int, redirects: int }, aioseo: array{ posts: int } }
	 */
	public static function get_estimated_counts(): array {
		global $wpdb;
		$out = array(
			'yoast'   => array( 'posts' => 0, 'redirects' => 0 ),
			'rankmath' => array( 'posts' => 0, 'redirects' => 0 ),
			'aioseo'  => array( 'posts' => 0 ),
		);

		$yoast_keys = "'_yoast_wpseo_title','_yoast_wpseo_metadesc','_yoast_wpseo_focuskw','_yoast_wpseo_canonical','_yoast_wpseo_meta-robots-noindex','_yoast_wpseo_opengraph-image','_yoast_wpseo_opengraph-image-id'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal meta keys list.
		$out['yoast']['posts'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$yoast_keys})" );
		$plain = get_option( 'wpseo-premium-redirects-export-plain', array() );
		$legacy = get_option( 'wpseo-premium-redirects-base', array() );
		if ( is_array( $plain ) ) {
			$out['yoast']['redirects'] += count( $plain );
		}
		if ( is_array( $legacy ) && ! empty( $legacy ) ) {
			$out['yoast']['redirects'] += is_array( reset( $legacy ) ) ? count( $legacy ) : count( $legacy );
		}

		$rm_keys = "'rank_math_title','rank_math_description','rank_math_focus_keyword','rank_math_canonical_url','rank_math_robots','rank_math_og_image','rank_math_twitter_title','rank_math_twitter_description','rank_math_twitter_image_id'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal meta keys list.
		$out['rankmath']['posts'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$rm_keys})" );
		$rm_table = $wpdb->prefix . 'rank_math_redirections';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rm_table ) ) ) === $rm_table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from $wpdb->prefix.
		$out['rankmath']['redirects'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$rm_table}" );
		}

		$aioseo_keys = "'_aioseop_title','_aioseop_description','_aioseop_keywords','_aioseop_opengraph_title','_aioseop_opengraph_description','_aioseop_twitter_title','_aioseop_twitter_description'";
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- literal meta keys list.
		$out['aioseo']['posts'] = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key IN ({$aioseo_keys})" );

		return $out;
	}

	/**
	 * Import one batch of posts from a given source.
	 *
	 * @param string $source       'yoast' | 'rankmath' | 'aioseo'.
	 * @param int    $offset       Offset for batch.
	 * @param bool   $dry_run      If true, do not write; return counts as if imported.
	 * @param bool   $delete_after If true, delete source meta after copying (ignored when dry_run).
	 * @return array{ titles: int, descriptions: int, focus_keywords: int, noindex: int, nofollow: int, canonical: int, og_image: int, processed: int }
	 */
	public static function import_batch( string $source, int $offset = 0, bool $dry_run = false, bool $delete_after = false ): array {
		$counts = array(
			'titles'         => 0,
			'descriptions'   => 0,
			'focus_keywords' => 0,
			'noindex'        => 0,
			'nofollow'       => 0,
			'canonical'      => 0,
			'og_image'       => 0,
			'processed'      => 0,
		);

		$post_ids = self::get_post_ids_with_source_meta( $source, $offset, self::BATCH_SIZE );
		if ( empty( $post_ids ) ) {
			return $counts;
		}

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$meta_key_for = function( $key ) use ( $post_id ) {
				return apply_filters( 'meyvora_seo_post_meta_key', $key, $post_id );
			};

			if ( $source === 'yoast' ) {
				$m = self::read_yoast_meta( $post_id );
				foreach ( $m as $meyvora_key => $value ) {
					if ( $value === '' && $value !== '0' ) {
						continue;
					}
					$counts = self::increment_count( $counts, $meyvora_key, 1 );
					if ( ! $dry_run ) {
						update_post_meta( $post_id, $meta_key_for( $meyvora_key ), $value );
					}
					if ( $delete_after && ! $dry_run ) {
						self::delete_yoast_meta_for_post( $post_id, $meyvora_key );
					}
				}
			} elseif ( $source === 'rankmath' ) {
				$m = self::read_rankmath_meta( $post_id );
				foreach ( $m as $meyvora_key => $value ) {
					if ( $value === '' && $value !== '0' ) {
						continue;
					}
					$counts = self::increment_count( $counts, $meyvora_key, 1 );
					if ( ! $dry_run ) {
						update_post_meta( $post_id, $meta_key_for( $meyvora_key ), $value );
					}
					if ( $delete_after && ! $dry_run ) {
						self::delete_rankmath_meta_for_post( $post_id, $meyvora_key );
					}
				}
			} elseif ( $source === 'aioseo' ) {
				$m = self::read_aioseo_meta( $post_id );
				foreach ( $m as $meyvora_key => $value ) {
					if ( $value === '' && $value !== '0' ) {
						continue;
					}
					$counts = self::increment_count( $counts, $meyvora_key, 1 );
					if ( ! $dry_run ) {
						update_post_meta( $post_id, $meta_key_for( $meyvora_key ), $value );
					}
					if ( $delete_after && ! $dry_run ) {
						self::delete_aioseo_meta_for_post( $post_id );
					}
				}
			}
			$counts['processed']++;
		}

		return $counts;
	}

	/**
	 * Get post IDs that have at least one source meta key for the given source.
	 *
	 * @param string $source Source slug.
	 * @param int    $offset Offset.
	 * @param int    $limit  Limit.
	 * @return array<int>
	 */
	protected static function get_post_ids_with_source_meta( string $source, int $offset, int $limit ): array {
		global $wpdb;
		$keys = array();
		if ( $source === 'yoast' ) {
			$keys = array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_canonical', '_yoast_wpseo_meta-robots-noindex', '_yoast_wpseo_opengraph-image', '_yoast_wpseo_opengraph-image-id' );
		} elseif ( $source === 'rankmath' ) {
			$keys = array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_canonical_url', 'rank_math_robots', 'rank_math_og_image' );
		} elseif ( $source === 'aioseo' ) {
			$keys = array( '_aioseop_title', '_aioseop_description', '_aioseop_keywords' );
		}
		if ( empty( $keys ) ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// Dynamic IN () placeholders; table from $wpdb->postmeta.
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Dynamic IN () plus LIMIT/OFFSET; args = array_merge($keys,[$limit,$offset]).
		$ids = $wpdb->get_col( $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is literal '%s,%s,...'.
			"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key IN ({$placeholders}) ORDER BY post_id ASC LIMIT %d OFFSET %d",
			array_merge( $keys, array( $limit, $offset ) )
		) );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	protected static function increment_count( array $counts, string $meyvora_key, int $by ): array {
		$map = array(
			MEYVORA_SEO_META_TITLE         => 'titles',
			MEYVORA_SEO_META_DESCRIPTION   => 'descriptions',
			MEYVORA_SEO_META_FOCUS_KEYWORD => 'focus_keywords',
			MEYVORA_SEO_META_NOINDEX       => 'noindex',
			MEYVORA_SEO_META_NOFOLLOW      => 'nofollow',
			MEYVORA_SEO_META_CANONICAL     => 'canonical',
			MEYVORA_SEO_META_OG_IMAGE      => 'og_image',
		);
		if ( isset( $map[ $meyvora_key ] ) ) {
			$counts[ $map[ $meyvora_key ] ] += $by;
		}
		return $counts;
	}

	/**
	 * Read Yoast meta for a post and return Meyvora key => value.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string|int>
	 */
	protected static function read_yoast_meta( int $post_id ): array {
		$out = array();
		$title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
		if ( (string) $title !== '' ) {
			$out[ MEYVORA_SEO_META_TITLE ] = $title;
		}
		$desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		if ( (string) $desc !== '' ) {
			$out[ MEYVORA_SEO_META_DESCRIPTION ] = $desc;
		}
		$kw = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( (string) $kw !== '' ) {
			$out[ MEYVORA_SEO_META_FOCUS_KEYWORD ] = $kw;
		}
		$noindex = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( $noindex === '1' || $noindex === true ) {
			$out[ MEYVORA_SEO_META_NOINDEX ] = '1';
		}
		$nofollow = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
		if ( $nofollow === '1' || $nofollow === true ) {
			$out[ MEYVORA_SEO_META_NOFOLLOW ] = '1';
		}
		$canonical = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		if ( (string) $canonical !== '' ) {
			// esc_url_raw() intentional: canonical for import into Meyvora meta (DB storage), not browser output.
			$out[ MEYVORA_SEO_META_CANONICAL ] = esc_url_raw( $canonical );
		}
		$og_id = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', true );
		if ( is_numeric( $og_id ) && (int) $og_id > 0 ) {
			$out[ MEYVORA_SEO_META_OG_IMAGE ] = (int) $og_id;
		} else {
			$og_url = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
			if ( is_string( $og_url ) && $og_url !== '' ) {
				$attachment_id = attachment_url_to_postid( $og_url );
				if ( $attachment_id ) {
					$out[ MEYVORA_SEO_META_OG_IMAGE ] = $attachment_id;
				}
			}
		}
		return $out;
	}

	protected static function delete_yoast_meta_for_post( int $post_id, string $meyvora_key ): void {
		$map = array(
			MEYVORA_SEO_META_TITLE         => '_yoast_wpseo_title',
			MEYVORA_SEO_META_DESCRIPTION   => '_yoast_wpseo_metadesc',
			MEYVORA_SEO_META_FOCUS_KEYWORD => '_yoast_wpseo_focuskw',
			MEYVORA_SEO_META_NOINDEX       => '_yoast_wpseo_meta-robots-noindex',
			MEYVORA_SEO_META_NOFOLLOW      => '_yoast_wpseo_meta-robots-nofollow',
			MEYVORA_SEO_META_CANONICAL     => '_yoast_wpseo_canonical',
			MEYVORA_SEO_META_OG_IMAGE      => array( '_yoast_wpseo_opengraph-image-id', '_yoast_wpseo_opengraph-image' ),
		);
		if ( isset( $map[ $meyvora_key ] ) ) {
			$keys = is_array( $map[ $meyvora_key ] ) ? $map[ $meyvora_key ] : array( $map[ $meyvora_key ] );
			foreach ( $keys as $yk ) {
				delete_post_meta( $post_id, $yk );
			}
		}
	}

	/**
	 * Read Rank Math meta for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string|int>
	 */
	protected static function read_rankmath_meta( int $post_id ): array {
		$out = array();
		$title = get_post_meta( $post_id, 'rank_math_title', true );
		if ( (string) $title !== '' ) {
			$out[ MEYVORA_SEO_META_TITLE ] = $title;
		}
		$desc = get_post_meta( $post_id, 'rank_math_description', true );
		if ( (string) $desc !== '' ) {
			$out[ MEYVORA_SEO_META_DESCRIPTION ] = $desc;
		}
		$kw = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		if ( (string) $kw !== '' ) {
			$first = is_array( $kw ) ? reset( $kw ) : $kw;
			$first = is_string( $first ) ? $first : (string) $first;
			if ( strpos( $first, ',' ) !== false ) {
				$first = trim( explode( ',', $first )[0] );
			}
			if ( $first !== '' ) {
				$out[ MEYVORA_SEO_META_FOCUS_KEYWORD ] = $first;
			}
		}
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $robots ) ) {
			if ( in_array( 'noindex', $robots, true ) ) {
				$out[ MEYVORA_SEO_META_NOINDEX ] = '1';
			}
			if ( in_array( 'nofollow', $robots, true ) ) {
				$out[ MEYVORA_SEO_META_NOFOLLOW ] = '1';
			}
		}
		$canonical = get_post_meta( $post_id, 'rank_math_canonical_url', true );
		if ( (string) $canonical !== '' ) {
			// esc_url_raw() intentional: canonical for import into Meyvora meta (DB storage), not browser output.
			$out[ MEYVORA_SEO_META_CANONICAL ] = esc_url_raw( $canonical );
		}
		$og = get_post_meta( $post_id, 'rank_math_og_image', true );
		if ( is_array( $og ) && ! empty( $og['id'] ) && is_numeric( $og['id'] ) ) {
			$out[ MEYVORA_SEO_META_OG_IMAGE ] = (int) $og['id'];
		} elseif ( is_numeric( $og ) && (int) $og > 0 ) {
			$out[ MEYVORA_SEO_META_OG_IMAGE ] = (int) $og;
		}
		// Twitter card meta
		$tw_title = get_post_meta( $post_id, 'rank_math_twitter_title', true );
		if ( (string) $tw_title !== '' ) {
			$out[ MEYVORA_SEO_META_TWITTER_TITLE ] = (string) $tw_title;
		}
		$tw_desc = get_post_meta( $post_id, 'rank_math_twitter_description', true );
		if ( (string) $tw_desc !== '' ) {
			$out[ MEYVORA_SEO_META_TWITTER_DESCRIPTION ] = (string) $tw_desc;
		}
		$tw_img_id = get_post_meta( $post_id, 'rank_math_twitter_image_id', true );
		if ( is_numeric( $tw_img_id ) && (int) $tw_img_id > 0 ) {
			$out[ MEYVORA_SEO_META_TWITTER_IMAGE ] = (int) $tw_img_id;
		}
		return $out;
	}

	protected static function delete_rankmath_meta_for_post( int $post_id, string $meyvora_key ): void {
		$map = array(
			MEYVORA_SEO_META_TITLE            => 'rank_math_title',
			MEYVORA_SEO_META_DESCRIPTION      => 'rank_math_description',
			MEYVORA_SEO_META_FOCUS_KEYWORD     => 'rank_math_focus_keyword',
			MEYVORA_SEO_META_NOINDEX           => 'rank_math_robots',
			MEYVORA_SEO_META_NOFOLLOW          => 'rank_math_robots',
			MEYVORA_SEO_META_CANONICAL         => 'rank_math_canonical_url',
			MEYVORA_SEO_META_OG_IMAGE          => 'rank_math_og_image',
			MEYVORA_SEO_META_TWITTER_TITLE     => 'rank_math_twitter_title',
			MEYVORA_SEO_META_TWITTER_DESCRIPTION => 'rank_math_twitter_description',
			MEYVORA_SEO_META_TWITTER_IMAGE    => 'rank_math_twitter_image_id',
		);
		if ( isset( $map[ $meyvora_key ] ) ) {
			$key = $map[ $meyvora_key ];
			if ( $key === 'rank_math_robots' ) {
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				if ( is_array( $robots ) ) {
					$robots = array_diff( $robots, array( 'noindex', 'nofollow' ) );
					update_post_meta( $post_id, 'rank_math_robots', array_values( $robots ) );
				}
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Read All In One SEO meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	protected static function read_aioseo_meta( int $post_id ): array {
		$out = array();
		$title = get_post_meta( $post_id, '_aioseop_title', true );
		if ( (string) $title !== '' ) {
			$out[ MEYVORA_SEO_META_TITLE ] = $title;
		}
		$desc = get_post_meta( $post_id, '_aioseop_description', true );
		if ( (string) $desc !== '' ) {
			$out[ MEYVORA_SEO_META_DESCRIPTION ] = $desc;
		}
		$kw = get_post_meta( $post_id, '_aioseop_keywords', true );
		if ( (string) $kw !== '' ) {
			$first = trim( explode( ',', (string) $kw )[0] );
			if ( $first !== '' ) {
				$out[ MEYVORA_SEO_META_FOCUS_KEYWORD ] = $first;
			}
		}
		$canonical = get_post_meta( $post_id, '_aioseop_custom_link', true );
		if ( (string) $canonical !== '' ) {
			// esc_url_raw() intentional: canonical for import into Meyvora meta (DB storage), not browser output.
			$out[ MEYVORA_SEO_META_CANONICAL ] = esc_url_raw( (string) $canonical );
		}
		// Also import noindex setting.
		$noindex = get_post_meta( $post_id, '_aioseop_noindex', true );
		if ( $noindex === 'on' || $noindex === '1' || $noindex === true ) {
			$out[ MEYVORA_SEO_META_NOINDEX ] = '1';
		}
		$og_title = get_post_meta( $post_id, '_aioseop_opengraph_title', true );
		if ( (string) $og_title !== '' ) {
			$out[ MEYVORA_SEO_META_OG_TITLE ] = (string) $og_title;
		}
		$og_desc = get_post_meta( $post_id, '_aioseop_opengraph_description', true );
		if ( (string) $og_desc !== '' ) {
			$out[ MEYVORA_SEO_META_OG_DESCRIPTION ] = (string) $og_desc;
		}
		$tw_title = get_post_meta( $post_id, '_aioseop_twitter_title', true );
		if ( (string) $tw_title !== '' ) {
			$out[ MEYVORA_SEO_META_TWITTER_TITLE ] = (string) $tw_title;
		}
		$tw_desc = get_post_meta( $post_id, '_aioseop_twitter_description', true );
		if ( (string) $tw_desc !== '' ) {
			$out[ MEYVORA_SEO_META_TWITTER_DESCRIPTION ] = (string) $tw_desc;
		}
		return $out;
	}

	protected static function delete_aioseo_meta_for_post( int $post_id ): void {
		delete_post_meta( $post_id, '_aioseop_title' );
		delete_post_meta( $post_id, '_aioseop_description' );
		delete_post_meta( $post_id, '_aioseop_keywords' );
		delete_post_meta( $post_id, '_aioseop_custom_link' );
		delete_post_meta( $post_id, '_aioseop_noindex' );
		delete_post_meta( $post_id, '_aioseop_opengraph_title' );
		delete_post_meta( $post_id, '_aioseop_opengraph_description' );
		delete_post_meta( $post_id, '_aioseop_twitter_title' );
		delete_post_meta( $post_id, '_aioseop_twitter_description' );
	}

	/**
	 * Import Yoast Premium redirects (from options) into Meyvora redirects table.
	 *
	 * @param bool $dry_run      If true, do not insert.
	 * @param bool $delete_after If true, delete Yoast option after import (ignored when dry_run).
	 * @return array{ redirects: int }
	 */
	public static function import_redirects_yoast( bool $dry_run = false, bool $delete_after = false ): array {
		$count = 0;
		$plain = get_option( 'wpseo-premium-redirects-export-plain', array() );
		if ( ! is_array( $plain ) ) {
			return array( 'redirects' => 0 );
		}
		foreach ( $plain as $item ) {
			$origin = $target = $type = null;
			if ( is_array( $item ) ) {
				$origin = isset( $item['origin'] ) ? $item['origin'] : ( isset( $item['url'] ) ? $item['url'] : ( isset( $item[0] ) ? $item[0] : null ) );
				$target = isset( $item['target'] ) ? $item['target'] : ( isset( $item['target_url'] ) ? $item['target_url'] : ( isset( $item[1] ) ? $item[1] : null ) );
				$type = isset( $item['type'] ) ? (int) $item['type'] : ( isset( $item[2] ) ? (int) $item[2] : 301 );
			} elseif ( is_object( $item ) ) {
				$origin = isset( $item->origin ) ? $item->origin : ( isset( $item->url ) ? $item->url : null );
				$target = isset( $item->target ) ? $item->target : ( isset( $item->target_url ) ? $item->target_url : null );
				$type = isset( $item->type ) ? (int) $item->type : 301;
			}
			$origin = is_string( $origin ) ? $origin : null;
			$target = is_string( $target ) ? $target : null;
			if ( $origin === null || $target === null || $origin === '' || $target === '' ) {
				continue;
			}
			$origin = '/' . trim( preg_replace( '#^https?://[^/]+#', '', $origin ), '/' );
			if ( $origin === '//' ) {
				$origin = '/';
			}
			if ( strpos( $target, 'http' ) !== 0 ) {
				$target = home_url( $target );
			}
			$type = in_array( $type, array( 301, 302, 307, 410 ), true ) ? $type : 301;
			if ( ! $dry_run && class_exists( 'Meyvora_SEO_Redirects' ) ) {
				Meyvora_SEO_Redirects::add_redirect( $origin, $target, $type, 'Imported from Yoast Premium' );
			}
			$count++;
		}
		if ( $delete_after && ! $dry_run && $count > 0 ) {
			delete_option( 'wpseo-premium-redirects-export-plain' );
		}
		return array( 'redirects' => $count );
	}

	/**
	 * Import Rank Math redirects from wp_rank_math_redirections table.
	 *
	 * @param bool $dry_run      If true, do not insert.
	 * @param bool $delete_after If true, delete Rank Math rows after import (ignored when dry_run).
	 * @return array{ redirects: int }
	 */
	public static function import_redirects_rankmath( bool $dry_run = false, bool $delete_after = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return array( 'redirects' => 0 );
		}
		$count = 0;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table from $wpdb->prefix.
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array( 'redirects' => 0 );
		}
		foreach ( $rows as $row ) {
			$from = isset( $row['url'] ) ? $row['url'] : ( isset( $row['from_url'] ) ? $row['from_url'] : ( isset( $row['sources'] ) ? '' : '' ) );
			if ( $from === '' && ! empty( $row['sources'] ) ) {
				$sources = is_string( $row['sources'] ) ? maybe_unserialize( $row['sources'] ) : $row['sources'];
				if ( is_array( $sources ) && ! empty( $sources ) ) {
					$first = reset( $sources );
					$from = is_array( $first ) && isset( $first['pattern'] ) ? $first['pattern'] : ( is_string( $first ) ? $first : '' );
				}
			}
			$to = isset( $row['location'] ) ? $row['location'] : ( isset( $row['to_url'] ) ? $row['to_url'] : ( isset( $row['url_to'] ) ? $row['url_to'] : '' ) );
			$type = isset( $row['redirect_type'] ) ? (int) $row['redirect_type'] : ( isset( $row['type'] ) ? (int) $row['type'] : 301 );
			if ( $from === '' || $to === '' ) {
				continue;
			}
			$from = '/' . trim( preg_replace( '#^https?://[^/]+#', '', $from ), '/' );
			if ( $from === '//' ) {
				$from = '/';
			}
			if ( strpos( $to, 'http' ) !== 0 ) {
				$to = home_url( $to );
			}
			$type = in_array( $type, array( 301, 302, 307, 410 ), true ) ? $type : 301;
			if ( ! $dry_run && class_exists( 'Meyvora_SEO_Redirects' ) ) {
				Meyvora_SEO_Redirects::add_redirect( $from, $to, $type, 'Imported from Rank Math' );
			}
			$count++;
			if ( $delete_after && ! $dry_run && isset( $row['id'] ) ) {
				$wpdb->delete( $table, array( 'id' => $row['id'] ) );
			}
		}
		return array( 'redirects' => $count );
	}

	/**
	 * Total number of posts that have source meta (for progress total).
	 *
	 * @param string $source Source slug.
	 * @return int
	 */
	public static function get_total_posts_to_import( string $source ): int {
		$counts = self::get_estimated_counts();
		if ( $source === 'yoast' ) {
			return $counts['yoast']['posts'];
		}
		if ( $source === 'rankmath' ) {
			return $counts['rankmath']['posts'];
		}
		if ( $source === 'aioseo' ) {
			return $counts['aioseo']['posts'];
		}
		return 0;
	}
}
