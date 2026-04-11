<?php
/**
 * Main plugin class: bootstrap, textdomain, dependency loading, activation/deactivation.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO {

	/**
	 * @var Meyvora_SEO|null
	 */
	private static ?Meyvora_SEO $instance = null;

	/**
	 * @var Meyvora_SEO_Loader
	 */
	protected Meyvora_SEO_Loader $loader;

	/**
	 * @var Meyvora_SEO_Options
	 */
	protected Meyvora_SEO_Options $options;

	/**
	 * Get the singleton instance.
	 *
	 * @return Meyvora_SEO
	 */
	public static function instance(): Meyvora_SEO {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->loader  = new Meyvora_SEO_Loader();
		$this->options = new Meyvora_SEO_Options();
	}

	/**
	 * Run the plugin: load textdomain, dependencies, then register all hooks.
	 */
	public function run(): void {
		$this->load_textdomain();
		$this->maybe_upgrade();
		$this->load_dependencies();
		$options = $this->options;
		$this->loader->add_action( 'meyvora_seo_score_dropped', function ( int $post_id, int $prev, int $new_score ) use ( $options ): void {
			if ( ! $options->get( 'score_alert_enabled', false ) ) {
				return;
			}
			$drop_threshold = (int) $options->get( 'score_alert_threshold', 10 );
			if ( ( $prev - $new_score ) < $drop_threshold ) {
				return;
			}
			$post     = get_post( $post_id );
			$title    = $post ? $post->post_title : 'Post #' . $post_id;
			$edit_url = get_edit_post_link( $post_id, 'raw' );
			$edit_url = $edit_url ?: '';
			$subject  = sprintf( '[SEO Alert] %s: score dropped from %d to %d', $title, $prev, $new_score );
			$message  = "SEO score dropped on: {$title}\n"
				. "Previous score: {$prev}/100\n"
				. "New score: {$new_score}/100\n"
				. "Edit post: {$edit_url}\n";
			$email = $options->get( 'score_alert_email', '' );
			if ( $email !== '' && is_email( $email ) ) {
				wp_mail( $email, $subject, $message );
			}
			$slack_url = $options->get( 'score_alert_slack', '' );
			if ( $slack_url !== '' && filter_var( $slack_url, FILTER_VALIDATE_URL ) ) {
				wp_remote_post(
					$slack_url,
					array(
						'body'    => wp_json_encode( array( 'text' => $subject . "\n" . $message ) ),
						'headers' => array( 'Content-Type' => 'application/json' ),
						'timeout' => 5,
					)
				);
			}
		}, 10, 3 );
		$this->loader->add_action( 'meyvora_seo_competitor_changed', function ( string $url, array $old, array $new ) use ( $options ): void {
			if ( ! $options->get( 'score_alert_enabled', false ) ) {
				return;
			}
			$old_title = (string) ( $old['title'] ?? '' );
			$new_title = (string) ( $new['title'] ?? '' );
			$old_wc = (int) ( $old['word_count'] ?? 0 );
			$new_wc = (int) ( $new['word_count'] ?? 0 );
			$subject = sprintf( '[SEO Alert] Competitor page changed: %s', wp_parse_url( $url, PHP_URL_HOST ) ?: $url );
			$message = "Competitor URL: {$url}\n";
			if ( $old_title !== $new_title ) {
				$message .= "Title: \"{$old_title}\" → \"{$new_title}\"\n";
			}
			if ( $old_wc !== $new_wc ) {
				$message .= "Word count: {$old_wc} → {$new_wc}\n";
			}
			$message .= "\nReview the Competitor History tab for full diff.\n";
			$email = $options->get( 'score_alert_email', '' );
			if ( $email !== '' && is_email( $email ) ) {
				wp_mail( $email, $subject, $message );
			}
			$slack_url = $options->get( 'score_alert_slack', '' );
			if ( $slack_url !== '' && filter_var( $slack_url, FILTER_VALIDATE_URL ) ) {
				wp_remote_post(
					$slack_url,
					array(
						'body'    => wp_json_encode( array( 'text' => $subject . "\n" . $message ) ),
						'headers' => array( 'Content-Type' => 'application/json' ),
						'timeout' => 5,
					)
				);
			}
		}, 10, 3 );
		$this->loader->add_action( 'meyvora_seo_serp_feature_changed', function ( int $post_id, string $keyword, string $old_feature, string $new_feature ) use ( $options ): void {
			if ( ! $options->get( 'score_alert_enabled', false ) ) {
				return;
			}
			$post    = get_post( $post_id );
			$title   = $post ? $post->post_title : 'Post #' . $post_id;
			$subject = sprintf( '[SEO Alert] SERP feature changed: %s – "%s"', $title, $keyword );
			$message = "Post: {$title}\nKeyword: {$keyword}\n";
			$message .= "Previous: " . ( $old_feature !== '' ? $old_feature : '(none)' ) . "\n";
			$message .= "Current: " . ( $new_feature !== '' ? $new_feature : '(none)' ) . "\n";
			$edit_url = get_edit_post_link( $post_id, 'raw' );
			if ( $edit_url ) {
				$message .= "Edit post: {$edit_url}\n";
			}
			$email = $options->get( 'score_alert_email', '' );
			if ( $email !== '' && is_email( $email ) ) {
				wp_mail( $email, $subject, $message );
			}
			$slack_url = $options->get( 'score_alert_slack', '' );
			if ( $slack_url !== '' && filter_var( $slack_url, FILTER_VALIDATE_URL ) ) {
				wp_remote_post(
					$slack_url,
					array(
						'body'    => wp_json_encode( array( 'text' => $subject . "\n" . $message ) ),
						'headers' => array( 'Content-Type' => 'application/json' ),
						'timeout' => 5,
					)
				);
			}
		}, 10, 4 );
		$this->loader->add_action(
			'meyvora_seo_ab_winner_declared',
			function ( int $post_id, string $winner, array $result ) use ( $options ): void {
				if ( ! $options->get( 'score_alert_enabled', false ) ) {
					return;
				}
				$post     = get_post( $post_id );
				$title    = $post ? $post->post_title : 'Post #' . $post_id;
				$edit_url = get_edit_post_link( $post_id, 'raw' ) ?: '';
				$a_ctr    = isset( $result['a_ctr'] ) ? round( (float) $result['a_ctr'], 2 ) : 0;
				$b_ctr    = isset( $result['b_ctr'] ) ? round( (float) $result['b_ctr'], 2 ) : 0;
				$subject  = sprintf( '[SEO Alert] A/B test winner declared: %s', $title );
				$message  = "A/B meta description test completed on: {$title}\n"
					. "Winner: Variant " . strtoupper( $winner ) . "\n"
					. "Variant A CTR: {$a_ctr}%  |  Variant B CTR: {$b_ctr}%\n"
					. "Winning description is now live as the meta description.\n"
					. "Edit post: {$edit_url}\n";
				$email = $options->get( 'score_alert_email', '' );
				if ( $email !== '' && is_email( $email ) ) {
					wp_mail( $email, $subject, $message );
				}
				$slack = $options->get( 'score_alert_slack', '' );
				if ( $slack !== '' && filter_var( $slack, FILTER_VALIDATE_URL ) ) {
					wp_remote_post( $slack, array(
						'body'    => wp_json_encode( array( 'text' => $subject . "\n" . $message ) ),
						'headers' => array( 'Content-Type' => 'application/json' ),
						'timeout' => 5,
					) );
				}
			},
			10, 3
		);
		// Settings link on Plugins page.
		if ( is_admin() ) {
			$this->loader->add_filter( 'plugin_action_links_' . MEYVORA_SEO_BASENAME, $this, 'plugin_action_links', 10, 1 );
		}
		$this->loader->add_action( 'admin_bar_menu', $this, 'admin_bar_seo_node', 90, 1 );
		$this->loader->run();
	}

	/**
	 * Add SEO score node to admin bar on frontend when viewing singular post/page.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function admin_bar_seo_node( WP_Admin_Bar $wp_admin_bar ): void {
		if ( is_admin() || ! is_singular() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		$score = get_post_meta( $post_id, MEYVORA_SEO_META_SCORE, true );
		$score = is_numeric( $score ) ? (int) $score : null;
		$label = $score !== null ? sprintf( 'SEO: %d', $score ) : __( 'SEO', 'meyvora-seo' );
		$status = $score !== null ? ( $score >= 80 ? 'Good' : ( $score >= 50 ? 'Okay' : 'Poor' ) ) : '';
		$edit_url = get_edit_post_link( $post_id, 'raw' );
		$wp_admin_bar->add_node( array(
			'id'     => 'meyvora-seo',
			'title'  => meyvora_seo_icon( 'search', array( 'width' => 16, 'height' => 16, 'aria_hidden' => false ) ) . ' ' . $label . ( $status !== '' ? ' [' . $status . ']' : '' ),
			'href'   => $edit_url ?: '#',
			'parent' => 'top-secondary',
		) );
	}

	/**
	 * Add Settings link to the plugin row on the Plugins screen.
	 *
	 * @param array<string> $links Existing links.
	 * @return array<string>
	 */
	public function plugin_action_links( array $links ): array {
		$url   = admin_url( 'admin.php?page=meyvora-seo-settings' );
		$label = esc_html__( 'Settings', 'meyvora-seo' );
		return array_merge( array( '<a href="' . esc_url( $url ) . '">' . $label . '</a>' ), $links );
	}

	/**
	 * Run upgrade routine if stored version is older than current.
	 */
	protected function maybe_upgrade(): void {
		$stored = get_option( MEYVORA_SEO_VERSION_OPTION_KEY, '' );
		if ( $stored === MEYVORA_SEO_VERSION ) {
			return;
		}
		if ( version_compare( $stored, '2.0.0', '<' ) ) {
			$redirects_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-redirects.php';
			if ( file_exists( $redirects_file ) ) {
				require_once $redirects_file;
				if ( class_exists( 'Meyvora_SEO_Redirects' ) ) {
					Meyvora_SEO_Redirects::create_tables();
				}
			}
			flush_rewrite_rules( false );
		}
		// Ensure rank_history and audit_results tables exist (e.g. upgrade from pre-3.0 or new install).
		$install_file = MEYVORA_SEO_PATH . 'includes/class-meyvora-seo-install.php';
		if ( file_exists( $install_file ) ) {
			require_once $install_file;
			if ( class_exists( 'Meyvora_SEO_Install' ) ) {
				Meyvora_SEO_Install::create_tables();
			}
		}
		$defaults = Meyvora_SEO_Options::get_defaults();
		$current  = get_option( MEYVORA_SEO_OPTION_KEY, array() );
		if ( is_array( $current ) ) {
			$merged = array_merge( $defaults, $current );
			update_option( MEYVORA_SEO_OPTION_KEY, $merged, true );
		}
		update_option( MEYVORA_SEO_VERSION_OPTION_KEY, MEYVORA_SEO_VERSION, true );
	}

	/**
	 * Load plugin textdomain for translations.
	 * Kept for non–WordPress.org installs and compatibility; .org auto-loads by slug.
	 *
	 * phpcs:disable PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	 */
	protected function load_textdomain(): void {
		load_plugin_textdomain(
			'meyvora-seo',
			false,
			dirname( MEYVORA_SEO_BASENAME ) . '/languages'
		);
		// phpcs:enable PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
	}

	/**
	 * Load admin and frontend modules. Only loads files that exist for scalable rollout.
	 */
	protected function load_dependencies(): void {
		// Install class is required by Link Checker and Rank Tracker (table name constants).
		$install_file = MEYVORA_SEO_PATH . 'includes/class-meyvora-seo-install.php';
		if ( file_exists( $install_file ) ) {
			require_once $install_file;
		}

		// Admin-only files (UI, assets, classic meta box).
		if ( is_admin() ) {
			$readability_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-readability.php';
			if ( file_exists( $readability_file ) ) {
				require_once $readability_file;
			}
			$analyzer_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-analyzer.php';
			if ( file_exists( $analyzer_file ) ) {
				require_once $analyzer_file;
			}
			$serp_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-serp-preview.php';
			if ( file_exists( $serp_file ) ) {
				require_once $serp_file;
			}
			$admin_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-admin.php';
			if ( file_exists( $admin_file ) ) {
				require_once $admin_file;
				$admin = new Meyvora_SEO_Admin( $this->loader, $this->options );
				$admin->register_hooks();
			}
			$topic_clusters_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-topic-clusters.php';
			if ( file_exists( $topic_clusters_file ) ) {
				require_once $topic_clusters_file;
				if ( class_exists( 'Meyvora_SEO_Topic_Clusters' ) ) {
					$topic_clusters = new Meyvora_SEO_Topic_Clusters( $this->loader, $this->options );
					$topic_clusters->register_hooks();
				}
			}
			$monitor_404_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-404-monitor.php';
			if ( file_exists( $monitor_404_file ) ) {
				require_once $monitor_404_file;
				if ( class_exists( 'Meyvora_SEO_404_Monitor' ) ) {
					$monitor_404 = new Meyvora_SEO_404_Monitor( $this->loader, $this->options );
					$monitor_404->register_hooks();
				}
			}
		}

		// Block editor: must load on BOTH is_admin() AND REST API requests.
		// Gutenberg saves posts via REST (/wp/v2/posts), which is NOT is_admin().
		// If this class is gated behind is_admin(), the rest_after_insert hook and
		// register_post_meta() never fire during saves, so nothing is written to DB.
		$block_editor_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-block-editor.php';
		if ( file_exists( $block_editor_file ) ) {
			require_once $block_editor_file;
			if ( class_exists( 'Meyvora_SEO_Block_Editor' ) ) {
				$block_editor = new Meyvora_SEO_Block_Editor( $this->loader, $this->options );
				$block_editor->register_hooks();
			}
		}

		// FAQ Gutenberg block: server-side render + frontend assets. Must load on all requests so the block is registered and render_callback runs on the frontend.
		$faq_block_file = MEYVORA_SEO_PATH . 'blocks/meyvora-faq/index.php';
		if ( file_exists( $faq_block_file ) ) {
			require_once $faq_block_file;
		}
		// Citations block (E-E-A-T): references list + schema.
		$citations_block_file = MEYVORA_SEO_PATH . 'blocks/meyvora-citations/index.php';
		if ( file_exists( $citations_block_file ) ) {
			require_once $citations_block_file;
		}

		// Page builder integrations (Elementor, etc.) must also load on the frontend so that the Elementor FAQ widget is registered and its CSS/JS are enqueued when viewing Elementor pages.
		$this->load_page_builder_integrations();

		$modules = array(
			'Meyvora_SEO_Meta'           => 'modules/class-meyvora-seo-meta.php',
			'Meyvora_SEO_Open_Graph'     => 'modules/class-meyvora-seo-open-graph.php',
			'Meyvora_SEO_Twitter_Cards'  => 'modules/class-meyvora-seo-twitter-cards.php',
			'Meyvora_SEO_Sitemaps'       => 'modules/class-meyvora-seo-sitemaps.php',
			'Meyvora_SEO_Redirects'      => 'modules/class-meyvora-seo-redirects.php',
			'Meyvora_SEO_Breadcrumbs'    => 'modules/class-meyvora-seo-breadcrumbs.php',
			'Meyvora_SEO_Schema'         => 'modules/class-meyvora-seo-schema.php',
			'Meyvora_SEO_Taxonomy_Meta'  => 'modules/class-meyvora-seo-taxonomy-meta.php',
			'Meyvora_SEO_Internal_Links' => 'modules/class-meyvora-seo-internal-links.php',
			'Meyvora_SEO_Audit'          => 'modules/class-meyvora-seo-audit.php',
			'Meyvora_SEO_AI'             => 'modules/class-meyvora-seo-ai.php',
			'Meyvora_SEO_Multilingual'   => 'modules/class-meyvora-seo-multilingual.php',
			'Meyvora_SEO_GSC'            => 'modules/class-meyvora-seo-gsc.php',
			'Meyvora_SEO_AB_Test'        => 'modules/class-meyvora-seo-ab-test.php',
			'Meyvora_SEO_GA4'            => 'modules/class-meyvora-seo-ga4.php',
			'Meyvora_SEO_CWV'            => 'modules/class-meyvora-seo-cwv.php',
			'Meyvora_SEO_Automation'     => 'modules/class-meyvora-seo-automation.php',
			'Meyvora_SEO_Image_SEO'      => 'modules/class-meyvora-seo-image-seo.php',
			'Meyvora_SEO_Rank_Tracker'   => 'modules/class-meyvora-seo-rank-tracker.php',
			'Meyvora_SEO_IndexNow'       => 'modules/class-meyvora-seo-indexnow.php',
			'Meyvora_SEO_Link_Checker'   => 'modules/class-meyvora-seo-link-checker.php',
			'Meyvora_SEO_EEAT'           => 'modules/class-meyvora-seo-eeat.php',
		);
		foreach ( $modules as $class_name => $path ) {
			$file = MEYVORA_SEO_PATH . $path;
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( $class_name ) ) {
					$obj = new $class_name( $this->loader, $this->options );
					if ( method_exists( $obj, 'register_hooks' ) ) {
						$obj->register_hooks();
					}
				}
			}
		}

		// REST API namespace for external/headless consumers (no loader/options).
		$rest_api_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-rest-api.php';
		if ( file_exists( $rest_api_file ) ) {
			require_once $rest_api_file;
			if ( class_exists( 'Meyvora_SEO_REST_API' ) ) {
				$rest_api = new Meyvora_SEO_REST_API();
				$rest_api->register_hooks();
			}
		}
	}

	/**
	 * Get the loader instance (for components that need to register hooks).
	 *
	 * @return Meyvora_SEO_Loader
	 */
	public function get_loader(): Meyvora_SEO_Loader {
		return $this->loader;
	}

	/**
	 * Get the options instance.
	 *
	 * @return Meyvora_SEO_Options
	 */
	public function get_options(): Meyvora_SEO_Options {
		return $this->options;
	}

	/**
	 * Load page builder integrations based on active plugins.
	 */
	protected function load_page_builder_integrations(): void {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$file = MEYVORA_SEO_PATH . 'integrations/class-meyvora-seo-elementor.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( 'Meyvora_SEO_Elementor' ) && method_exists( 'Meyvora_SEO_Elementor', 'register' ) ) {
					Meyvora_SEO_Elementor::register();
				}
			}
		}
		if ( defined( 'FL_BUILDER_VERSION' ) ) {
			$file = MEYVORA_SEO_PATH . 'integrations/class-meyvora-seo-beaver-builder.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( 'Meyvora_SEO_Beaver_Builder' ) && method_exists( 'Meyvora_SEO_Beaver_Builder', 'register' ) ) {
					Meyvora_SEO_Beaver_Builder::register();
				}
			}
		}
		if ( defined( 'ET_BUILDER_PLUGIN_ACTIVE' ) || ( function_exists( 'et_setup_theme' ) && defined( 'ET_BUILDER_THEME' ) ) ) {
			$file = MEYVORA_SEO_PATH . 'integrations/class-meyvora-seo-divi.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( 'Meyvora_SEO_Divi' ) && method_exists( 'Meyvora_SEO_Divi', 'register' ) ) {
					Meyvora_SEO_Divi::register();
				}
			}
		}
		if ( defined( 'WPB_VC_VERSION' ) ) {
			$file = MEYVORA_SEO_PATH . 'integrations/class-meyvora-seo-wpbakery.php';
			if ( file_exists( $file ) ) {
				require_once $file;
				if ( class_exists( 'Meyvora_SEO_WPBakery' ) && method_exists( 'Meyvora_SEO_WPBakery', 'register' ) ) {
					Meyvora_SEO_WPBakery::register();
				}
			}
		}
		// WooCommerce loads after this plugin when plugin order is alphabetical (meyvora-seo before woocommerce).
		// Defer so class_exists( 'WooCommerce' ) is true and product title templates / sitemap hooks register.
		$this->register_woocommerce_integration_deferred();
	}

	/**
	 * Register WooCommerce integration after WooCommerce has loaded.
	 */
	protected function register_woocommerce_integration_deferred(): void {
		add_action(
			'plugins_loaded',
			static function (): void {
				if ( ! class_exists( 'WooCommerce' ) ) {
					return;
				}
				$file = MEYVORA_SEO_PATH . 'integrations/class-meyvora-seo-woocommerce.php';
				if ( ! file_exists( $file ) ) {
					return;
				}
				require_once $file;
				if ( class_exists( 'Meyvora_SEO_WooCommerce' ) && method_exists( 'Meyvora_SEO_WooCommerce', 'register' ) ) {
					Meyvora_SEO_WooCommerce::register();
				}
			},
			20
		);
	}

	/**
	 * Fired on plugin activation. Create all custom DB tables with dbDelta.
	 */
	public static function activation(): void {
		$options = new Meyvora_SEO_Options();
		if ( get_option( MEYVORA_SEO_OPTION_KEY, false ) === false ) {
			$options->reset();
		}
		update_option( MEYVORA_SEO_VERSION_OPTION_KEY, MEYVORA_SEO_VERSION, true );

		// Create redirects + 404 tables.
		$redirects_file = MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-redirects.php';
		if ( file_exists( $redirects_file ) ) {
			require_once $redirects_file;
			if ( class_exists( 'Meyvora_SEO_Redirects' ) ) {
				Meyvora_SEO_Redirects::create_tables();
			}
		}

		// Create rank_history and audit_results tables.
		$install_file = MEYVORA_SEO_PATH . 'includes/class-meyvora-seo-install.php';
		if ( file_exists( $install_file ) ) {
			require_once $install_file;
			if ( class_exists( 'Meyvora_SEO_Install' ) ) {
				Meyvora_SEO_Install::create_tables();
				Meyvora_SEO_Install::maybe_upgrade_columns();
			}
		}

		// Add meyvora_seo_edit capability to Editor role for SEO-only access.
		$editor_role = get_role( 'editor' );
		if ( $editor_role ) {
			$editor_role->add_cap( 'meyvora_seo_edit' );
		}

		set_transient( 'meyvora_seo_wizard_redirect', 1, 30 );
	}

	/**
	 * Fired on plugin deactivation.
	 */
	public static function deactivation(): void {
		// No persistent cleanup needed.
	}
}