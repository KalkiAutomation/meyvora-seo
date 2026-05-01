<?php
/**
 * Topic Clusters: pillar + cluster groups and internal linking analysis.
 * Data stored in wp_options only (no CPT/taxonomy).
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Topic_Clusters
 */
class Meyvora_SEO_Topic_Clusters {

	const OPTION_CLUSTERS = 'meyvora_seo_topic_clusters';

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
	 * Register hooks: AJAX and admin page assets.
	 */
	public function register_hooks(): void {
		add_action( 'wp_ajax_meyvora_seo_cluster_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_meyvora_seo_cluster_analyse', array( $this, 'ajax_analyse' ) );
		add_action( 'wp_ajax_meyvora_seo_cluster_search_posts', array( $this, 'ajax_search_posts' ) );
		$this->loader->add_action( 'admin_enqueue_scripts', $this, 'enqueue_assets', 10, 1 );
	}

	/**
	 * Enqueue scripts/styles on Topic Clusters page only.
	 *
	 * @param string $hook_suffix Current admin hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== 'meyvora-seo_page_meyvora-seo-topic-clusters' ) {
			return;
		}
		$ver = defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0';
		$css = ( defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '' ) . 'admin/assets/css/meyvora-admin.css';
		wp_enqueue_style( 'meyvora-seo-admin', $css, array(), $ver );

		wp_enqueue_script(
			'meyvora-topic-clusters-page',
			MEYVORA_SEO_URL . 'admin/assets/js/meyvora-topic-clusters-page.js',
			array(),
			$ver,
			true
		);
		wp_localize_script(
			'meyvora-topic-clusters-page',
			'meyvoraTopicClusters',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'meyvora_seo_cluster' ),
				'clusters' => $this->get_clusters(),
				'i18n'     => array(
					'enterNameAndPillar' => __( 'Enter a name and select a pillar post.', 'meyvora-seo' ),
					'saveFailed'         => __( 'Save failed.', 'meyvora-seo' ),
					'confirmRemove'      => __( 'Remove this cluster from the list?', 'meyvora-seo' ),
				),
			)
		);
	}

	/**
	 * Get saved cluster groups from option.
	 *
	 * @return array<int, array{name: string, pillar_id: int, cluster_ids: int[]}>
	 */
	public function get_clusters(): array {
		$raw = get_option( self::OPTION_CLUSTERS, array() );
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Sanitize and save cluster groups to option.
	 *
	 * @param array<int, array{name?: string, pillar_id?: int, cluster_ids?: int[]}> $clusters Cluster definitions.
	 * @return bool True on success.
	 */
	public function save_clusters( array $clusters ): bool {
		$out = array();
		foreach ( $clusters as $i => $c ) {
			$name       = isset( $c['name'] ) ? sanitize_text_field( (string) $c['name'] ) : '';
			$pillar_id  = isset( $c['pillar_id'] ) ? max( 0, (int) $c['pillar_id'] ) : 0;
			$cluster_ids = array();
			if ( ! empty( $c['cluster_ids'] ) && is_array( $c['cluster_ids'] ) ) {
				foreach ( $c['cluster_ids'] as $id ) {
					$id = max( 0, (int) $id );
					if ( $id > 0 && ! in_array( $id, $cluster_ids, true ) ) {
						$cluster_ids[] = $id;
					}
				}
			}
			if ( $name !== '' && $pillar_id > 0 ) {
				$out[] = array(
					'name'        => $name,
					'pillar_id'   => $pillar_id,
					'cluster_ids' => array_values( $cluster_ids ),
				);
			}
		}
		return update_option( self::OPTION_CLUSTERS, $out );
	}

	/**
	 * Analyse one cluster: pillar ↔ cluster linking.
	 *
	 * @param array{name: string, pillar_id: int, cluster_ids: int[]} $cluster One cluster group.
	 * @return array{pillar_links_out: int, missing_pillar_links: int[], orphan_clusters: int[], coverage_score: float}
	 */
	public function analyse_cluster( array $cluster ): array {
		$pillar_id   = (int) ( $cluster['pillar_id'] ?? 0 );
		$cluster_ids = isset( $cluster['cluster_ids'] ) && is_array( $cluster['cluster_ids'] ) ? array_map( 'intval', $cluster['cluster_ids'] ) : array();
		$cluster_ids = array_filter( $cluster_ids );

		$pillar_links_out     = 0;
		$missing_pillar_links = array();
		$orphan_clusters      = array();

		foreach ( $cluster_ids as $cid ) {
			if ( $this->post_links_to( $pillar_id, $cid ) ) {
				$pillar_links_out++;
			} else {
				$missing_pillar_links[] = $cid;
			}
			if ( ! $this->post_links_to( $cid, $pillar_id ) ) {
				$orphan_clusters[] = $cid;
			}
		}

		$n = count( $cluster_ids );
		if ( $n === 0 ) {
			$coverage_score = 100.0;
		} else {
			$links_to_clusters = $pillar_links_out;
			$links_to_pillar   = $n - count( $orphan_clusters );
			$total_desired     = 2 * $n;
			$total_actual      = $links_to_clusters + $links_to_pillar;
			$coverage_score    = min( 100.0, max( 0.0, ( $total_actual / $total_desired ) * 100 ) );
		}

		return array(
			'pillar_links_out'     => $pillar_links_out,
			'missing_pillar_links' => array_values( $missing_pillar_links ),
			'orphan_clusters'      => array_values( $orphan_clusters ),
			'coverage_score'       => round( $coverage_score, 1 ),
		);
	}

	/**
	 * Check if one post's content contains a link to another post's URL.
	 *
	 * @param int $from_id Post ID whose content to check.
	 * @param int $to_id   Post ID whose permalink to look for.
	 * @return bool True if from_id's post_content contains a link to get_permalink(to_id).
	 */
	private function post_links_to( int $from_id, int $to_id ): bool {
		$post = get_post( $from_id );
		if ( ! $post || ! $post->post_content ) {
			return false;
		}
		$to_url = get_permalink( $to_id );
		if ( ! $to_url || $to_url === '' ) {
			return false;
		}
		return strpos( $post->post_content, $to_url ) !== false;
	}

	/**
	 * Count how many links from post content point to target URL.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $target_url URL to count (e.g. get_permalink).
	 * @return int Number of occurrences of target_url in post content (simple substring count).
	 */
	protected function count_outbound_links_to( int $post_id, string $target_url ): int {
		$post = get_post( $post_id );
		if ( ! $post || ! $post->post_content || $target_url === '' ) {
			return 0;
		}
		return (int) substr_count( $post->post_content, $target_url );
	}

	/**
	 * Normalize cluster definitions after json_decode().
	 *
	 * @param array<int, mixed> $raw Raw decoded rows.
	 * @return array<int, array{name: string, pillar_id: int, cluster_ids: int[]}>
	 */
	protected function sanitize_clusters_from_decoded( array $raw ): array {
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name        = isset( $item['name'] ) ? sanitize_text_field( (string) $item['name'] ) : '';
			$pillar_id   = isset( $item['pillar_id'] ) ? absint( $item['pillar_id'] ) : 0;
			$cluster_ids = array();
			if ( ! empty( $item['cluster_ids'] ) && is_array( $item['cluster_ids'] ) ) {
				foreach ( $item['cluster_ids'] as $id ) {
					$cid = absint( $id );
					if ( $cid > 0 && ! in_array( $cid, $cluster_ids, true ) ) {
						$cluster_ids[] = $cid;
					}
				}
			}
			if ( $name !== '' && $pillar_id > 0 ) {
				$out[] = array(
					'name'        => $name,
					'pillar_id'   => $pillar_id,
					'cluster_ids' => array_values( $cluster_ids ),
				);
			}
		}
		return $out;
	}

	/**
	 * Normalize a single cluster payload for analyse_cluster().
	 *
	 * @param mixed $decoded json_decode result.
	 * @return array{name?: string, pillar_id: int, cluster_ids: int[]}|null
	 */
	protected function sanitize_cluster_for_analysis_payload( $decoded ): ?array {
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		$pillar_id = isset( $decoded['pillar_id'] ) ? absint( $decoded['pillar_id'] ) : 0;
		if ( $pillar_id <= 0 ) {
			return null;
		}
		$cluster_ids = array();
		if ( ! empty( $decoded['cluster_ids'] ) && is_array( $decoded['cluster_ids'] ) ) {
			foreach ( $decoded['cluster_ids'] as $id ) {
				$cid = absint( $id );
				if ( $cid > 0 && ! in_array( $cid, $cluster_ids, true ) ) {
					$cluster_ids[] = $cid;
				}
			}
		}
		return array(
			'name'        => isset( $decoded['name'] ) ? sanitize_text_field( (string) $decoded['name'] ) : '',
			'pillar_id'   => $pillar_id,
			'cluster_ids' => array_values( $cluster_ids ),
		);
	}

	/**
	 * AJAX: Save clusters (JSON body or post data).
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'meyvora_seo_cluster', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$input = isset( $_POST['clusters'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['clusters'] ) ) : '';
		if ( is_string( $input ) ) {
			$decoded  = json_decode( $input, true );
			$clusters = is_array( $decoded ) ? $this->sanitize_clusters_from_decoded( $decoded ) : array();
		} elseif ( is_array( $input ) ) {
			$clusters = $this->sanitize_clusters_from_decoded( $input );
		} else {
			$clusters = array();
		}
		$ok = $this->save_clusters( $clusters );
		if ( $ok ) {
			wp_send_json_success( array( 'message' => __( 'Clusters saved.', 'meyvora-seo' ) ) );
		}
		wp_send_json_error( array( 'message' => __( 'Save failed.', 'meyvora-seo' ) ) );
	}

	/**
	 * AJAX: Analyse one cluster by index or full cluster object.
	 */
	public function ajax_analyse(): void {
		check_ajax_referer( 'meyvora_seo_cluster', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$index = isset( $_POST['index'] ) ? absint( wp_unslash( $_POST['index'] ) ) : -1;
		$cluster = null;
		if ( $index >= 0 ) {
			$all = $this->get_clusters();
			$cluster = isset( $all[ $index ] ) ? $all[ $index ] : null;
		}
		if ( $cluster === null && isset( $_POST['cluster'] ) ) {
			$raw = sanitize_text_field( wp_unslash( (string) $_POST['cluster'] ) );
			$decoded = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
			$cluster = $this->sanitize_cluster_for_analysis_payload( $decoded );
		}
		if ( $cluster === null ) {
			wp_send_json_error( array( 'message' => __( 'Cluster not found.', 'meyvora-seo' ) ) );
		}
		$result = $this->analyse_cluster( $cluster );
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Search posts for pillar/cluster selectors.
	 */
	public function ajax_search_posts(): void {
		check_ajax_referer( 'meyvora_seo_cluster', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'meyvora-seo' ) ) );
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$post_types = apply_filters( 'meyvora_seo_supported_post_types', array( 'post', 'page' ) );
		$post_types = array_values( array_diff( (array) $post_types, array( 'meyvora_seo_template' ) ) );
		if ( empty( $post_types ) ) {
			wp_send_json_success( array( 'posts' => array() ) );
		}
		$args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => 30,
			'orderby'        => 'relevance',
			'fields'         => 'ids',
		);
		if ( $search !== '' ) {
			$args['s'] = $search;
		} else {
			$args['orderby'] = 'modified';
			$args['order']   = 'DESC';
		}
		$posts = get_posts( $args );
		$out = array();
		foreach ( $posts as $id ) {
			$out[] = array(
				'id'    => (int) $id,
				'title' => get_the_title( $id ) ?: __( '(no title)', 'meyvora-seo' ),
			);
		}
		wp_send_json_success( array( 'posts' => $out ) );
	}

	/**
	 * Render Topic Clusters admin page (include view).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$view_file = MEYVORA_SEO_PATH . 'admin/views/topic-clusters.php';
		if ( file_exists( $view_file ) ) {
			$clusters = $this->get_clusters();
			$analyses = array();
			foreach ( $clusters as $c ) {
				$analyses[] = $this->analyse_cluster( $c );
			}
			include $view_file;
		} else {
			echo '<div class="wrap"><h1>' . esc_html__( 'Topic Clusters', 'meyvora-seo' ) . '</h1><p>' . esc_html__( 'View not found.', 'meyvora-seo' ) . '</p></div>';
		}
	}
}
