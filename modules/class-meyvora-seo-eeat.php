<?php
/**
 * E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness) enhancement module.
 * Extended author profile, Article author schema, last-updated shortcode, byline speakable,
 * citations block schema. E-E-A-T checklist is shown in the block editor sidebar (see block editor JS).
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_EEAT {

	protected Meyvora_SEO_Loader $loader;
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		// Extended author profile (user meta).
		if ( is_admin() ) {
			$this->loader->add_action( 'show_user_profile', $this, 'render_eeat_profile_fields', 11, 1 );
			$this->loader->add_action( 'edit_user_profile', $this, 'render_eeat_profile_fields', 11, 1 );
			$this->loader->add_action( 'personal_options_update', $this, 'save_eeat_profile_fields', 10, 1 );
			$this->loader->add_action( 'edit_user_profile_update', $this, 'save_eeat_profile_fields', 10, 1 );
		}

		// Person schema (author archives): add E-E-A-T fields.
		$this->loader->add_filter( 'meyvora_seo_person_schema_data', $this, 'filter_person_schema_data', 10, 2 );

		// Article schema: enrich author object and add speakable for byline.
		$this->loader->add_filter( 'meyvora_seo_schema_data', $this, 'filter_article_schema_data', 10, 2 );

		// Last-updated shortcode.
		$this->loader->add_action( 'init', $this, 'register_shortcode' );

		// Citations schema (ItemList + ClaimReview) when block is present.
		$this->loader->add_action( 'wp_head', $this, 'output_citations_schema', 2 );
	}

	/** User meta keys for E-E-A-T author profile. */
	public static function get_eeat_meta_keys(): array {
		return array(
			'expertise_area'            => 'meyvora_seo_eeat_expertise_area',
			'credentials'               => 'meyvora_seo_eeat_credentials',
			'organization_affiliation'  => 'meyvora_seo_eeat_organization_affiliation',
			'years_experience'          => 'meyvora_seo_eeat_years_experience',
		);
	}

	/**
	 * Get publication count for a user (published posts).
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public static function get_publication_count( int $user_id ): int {
		$count = count_user_posts( $user_id, 'post', true );
		return max( 0, (int) $count );
	}

	public function render_eeat_profile_fields( WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$keys = self::get_eeat_meta_keys();
		$expertise   = get_user_meta( $user->ID, $keys['expertise_area'], true );
		$credentials = get_user_meta( $user->ID, $keys['credentials'], true );
		$org         = get_user_meta( $user->ID, $keys['organization_affiliation'], true );
		$years       = get_user_meta( $user->ID, $keys['years_experience'], true );
		$pub_count   = self::get_publication_count( $user->ID );
		?>
		<h2><?php esc_html_e( 'E-E-A-T Author Profile', 'meyvora-seo' ); ?></h2>
		<p class="description"><?php esc_html_e( 'These fields feed into Person and Article schema for trust signals.', 'meyvora-seo' ); ?></p>
		<table class="form-table">
			<tr>
				<th><label for="meyvora_seo_eeat_expertise_area"><?php esc_html_e( 'Expertise area', 'meyvora-seo' ); ?></label></th>
				<td><input type="text" name="meyvora_seo_eeat_expertise_area" id="meyvora_seo_eeat_expertise_area" value="<?php echo esc_attr( $expertise ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="meyvora_seo_eeat_credentials"><?php esc_html_e( 'Credentials', 'meyvora-seo' ); ?></label></th>
				<td><input type="text" name="meyvora_seo_eeat_credentials" id="meyvora_seo_eeat_credentials" value="<?php echo esc_attr( $credentials ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="meyvora_seo_eeat_organization_affiliation"><?php esc_html_e( 'Organization affiliation', 'meyvora-seo' ); ?></label></th>
				<td><input type="text" name="meyvora_seo_eeat_organization_affiliation" id="meyvora_seo_eeat_organization_affiliation" value="<?php echo esc_attr( $org ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="meyvora_seo_eeat_years_experience"><?php esc_html_e( 'Years of experience', 'meyvora-seo' ); ?></label></th>
				<td><input type="number" name="meyvora_seo_eeat_years_experience" id="meyvora_seo_eeat_years_experience" value="<?php echo esc_attr( $years ); ?>" min="0" step="1" class="small-text" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Publication count', 'meyvora-seo' ); ?></th>
				<td><em><?php echo esc_html( (string) (int) $pub_count ); ?></em> <?php esc_html_e( '(auto-calculated from published posts)', 'meyvora-seo' ); ?></td>
			</tr>
		</table>
		<?php
	}

	public function save_eeat_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-user_' . $user_id ) ) {
			return;
		}
		$keys = self::get_eeat_meta_keys();
		if ( isset( $_POST['meyvora_seo_eeat_expertise_area'] ) ) {
			update_user_meta( $user_id, $keys['expertise_area'], sanitize_text_field( wp_unslash( $_POST['meyvora_seo_eeat_expertise_area'] ) ) );
		}
		if ( isset( $_POST['meyvora_seo_eeat_credentials'] ) ) {
			update_user_meta( $user_id, $keys['credentials'], sanitize_text_field( wp_unslash( $_POST['meyvora_seo_eeat_credentials'] ) ) );
		}
		if ( isset( $_POST['meyvora_seo_eeat_organization_affiliation'] ) ) {
			update_user_meta( $user_id, $keys['organization_affiliation'], sanitize_text_field( wp_unslash( $_POST['meyvora_seo_eeat_organization_affiliation'] ) ) );
		}
		if ( isset( $_POST['meyvora_seo_eeat_years_experience'] ) ) {
			$val = absint( wp_unslash( $_POST['meyvora_seo_eeat_years_experience'] ) );
			update_user_meta( $user_id, $keys['years_experience'], $val );
		}
	}

	/**
	 * Enrich Person schema on author archives with E-E-A-T fields.
	 *
	 * @param array<string, mixed> $data Schema data.
	 * @param WP_User              $user User.
	 * @return array<string, mixed>
	 */
	public function filter_person_schema_data( array $data, WP_User $user ): array {
		$keys = self::get_eeat_meta_keys();
		$expertise = get_user_meta( $user->ID, $keys['expertise_area'], true );
		$credentials = get_user_meta( $user->ID, $keys['credentials'], true );
		$org = get_user_meta( $user->ID, $keys['organization_affiliation'], true );
		$years = get_user_meta( $user->ID, $keys['years_experience'], true );
		$pub_count = self::get_publication_count( $user->ID );

		if ( is_string( $expertise ) && $expertise !== '' ) {
			$data['expertise_area'] = wp_strip_all_tags( $expertise );
		}
		if ( is_string( $credentials ) && $credentials !== '' ) {
			$data['credentials'] = wp_strip_all_tags( $credentials );
		}
		if ( is_string( $org ) && $org !== '' ) {
			$data['organization_affiliation'] = wp_strip_all_tags( $org );
		}
		if ( $years !== '' && $years !== false ) {
			$data['years_experience'] = (int) $years;
		}
		if ( $pub_count > 0 ) {
			$data['publication_count'] = $pub_count;
		}
		return $data;
	}

	/**
	 * Enrich Article schema: full author object and speakable for byline.
	 *
	 * @param array<string, mixed> $data Schema data.
	 * @param WP_Post|null         $post Post (null for non-post schema).
	 * @return array<string, mixed>
	 */
	public function filter_article_schema_data( array $data, $post ): array {
		// Only enrich Article and its subtypes.
		$type = isset( $data['@type'] ) ? (string) $data['@type'] : '';
		$article_types = array( 'Article', 'NewsArticle', 'BlogPosting', 'TechArticle' );
		if ( ! in_array( $type, $article_types, true ) ) {
			return $data; // Not an article — return unchanged
		}
		if ( ! $post instanceof WP_Post ) {
			return $data;
		}
		$author_id = (int) $post->post_author;
		if ( $author_id <= 0 ) {
			return $data;
		}

		$author_url = get_author_posts_url( $author_id );
		$author_name = get_the_author_meta( 'display_name', $author_id );
		$desc = get_the_author_meta( 'description', $author_id );
		$keys = self::get_eeat_meta_keys();
		$expertise = get_user_meta( $author_id, $keys['expertise_area'], true );
		$credentials = get_user_meta( $author_id, $keys['credentials'], true );
		$same_as = array();
		$twitter = get_user_meta( $author_id, 'meyvora_seo_author_twitter_url', true );
		if ( is_string( $twitter ) && $twitter !== '' ) {
			$same_as[] = esc_url_raw( $twitter );
		}
		$linkedin = get_user_meta( $author_id, 'meyvora_seo_author_linkedin_url', true );
		if ( is_string( $linkedin ) && $linkedin !== '' ) {
			$same_as[] = esc_url_raw( $linkedin );
		}

		$author = array(
			'@type' => 'Person',
			'name'  => $author_name,
			'url'   => $author_url ?: null,
			'description' => ( is_string( $desc ) && $desc !== '' ) ? wp_strip_all_tags( $desc ) : null,
			'expertise_area' => ( is_string( $expertise ) && $expertise !== '' ) ? wp_strip_all_tags( $expertise ) : null,
			'credentials' => ( is_string( $credentials ) && $credentials !== '' ) ? wp_strip_all_tags( $credentials ) : null,
		);
		if ( $author_url ) {
			$author['@id'] = $author_url . '#person';
		}
		if ( ! empty( $same_as ) ) {
			$author['sameAs'] = $same_as;
		}
		$data['author'] = array_filter( $author, function ( $v ) {
			return $v !== null && $v !== '';
		} );

		// Speakable for byline: assistants can read the byline.
		$data['speakable'] = array(
			'@type'       => 'SpeakableSpecification',
			'cssSelector' => array( '.meyvora-byline', '.entry-author', '.byline', '.author-name', '.post-author' ),
		);

		return $data;
	}

	public function register_shortcode(): void {
		add_shortcode( 'meyvora_last_updated', array( $this, 'shortcode_last_updated' ) );
	}

	/**
	 * Shortcode [meyvora_last_updated]: human-readable modified date. Only on singular post.
	 *
	 * @param array<string, string> $atts Shortcode attributes (optional date format).
	 * @return string
	 */
	public function shortcode_last_updated( $atts ): string {
		if ( ! is_singular() ) {
			return '';
		}
		$post = get_post();
		if ( ! $post ) {
			return '';
		}
		$atts = shortcode_atts( array(
			'format' => get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
		), $atts ?? array(), 'meyvora_last_updated' );
		$modified = get_the_modified_date( $atts['format'], $post );
		if ( $modified === '' ) {
			return '';
		}
		/* translators: %s: human-readable modified date */
		$label = __( 'Last updated: %s', 'meyvora-seo' );
		return '<span class="meyvora-last-updated">' . esc_html( sprintf( $label, $modified ) ) . '</span>';
	}

	/**
	 * Output ItemList + ClaimReview schema when the post contains the citations block.
	 */
	public function output_citations_schema(): void {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! has_block( 'meyvora-seo/citations', $post ) ) {
			return;
		}
		$citations = $this->get_citations_from_post( $post );
		if ( empty( $citations ) ) {
			return;
		}

		$list_items = array();
		foreach ( $citations as $c ) {
			$url = isset( $c['url'] ) ? esc_url_raw( $c['url'] ) : '';
			$name = isset( $c['title'] ) ? wp_strip_all_tags( $c['title'] ) : $url;
			if ( $url === '' && $name === '' ) {
				continue;
			}
			$list_items[] = array(
				'@type' => 'CreativeWork',
				'name'  => $name ?: $url,
				'url'   => $url ?: null,
			);
		}
		$list_items = array_filter( $list_items, function ( $i ) {
			return $i['name'] !== '' || $i['url'] !== null;
		} );
		if ( empty( $list_items ) ) {
			return;
		}

		$url = get_permalink( $post );
		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => __( 'References', 'meyvora-seo' ),
			'itemListElement' => array_values( $list_items ),
		);
		$this->echo_ld_json( $data );

		// ClaimReview fragment: this article references the following sources.
		$org_name = $this->options->get( 'schema_organization_name', get_bloginfo( 'name' ) );
		$claim = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'ClaimReview',
			'url'              => $url,
			'itemReviewed'     => array(
				'@type' => 'Claim',
				'appearance' => array(
					'@type' => 'CreativeWork',
					'url'   => $url,
				),
			),
			'author'           => array(
				'@type' => 'Organization',
				'name'  => $org_name,
			),
		);
		$this->echo_ld_json( $claim );
	}

	/**
	 * Parse citations from post content (meyvora-seo/citations block).
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{url?: string, title?: string}>
	 */
	protected function get_citations_from_post( WP_Post $post ): array {
		$blocks = parse_blocks( $post->post_content );
		$out = array();
		$this->collect_citations_from_blocks( $blocks, $out );
		return $out;
	}

	/**
	 * @param array<int, array> $blocks Blocks.
	 * @param array<int, array{url?: string, title?: string}> $out Output.
	 */
	protected function collect_citations_from_blocks( array $blocks, array &$out ): void {
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === 'meyvora-seo/citations' && ! empty( $block['attrs']['citations'] ) ) {
				$list = $block['attrs']['citations'];
				if ( is_array( $list ) ) {
					foreach ( $list as $c ) {
						if ( is_array( $c ) && ( ! empty( $c['url'] ) || ! empty( $c['title'] ) ) ) {
							$out[] = array(
								'url'   => isset( $c['url'] ) ? trim( (string) $c['url'] ) : '',
								'title' => isset( $c['title'] ) ? trim( (string) $c['title'] ) : '',
							);
						}
					}
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->collect_citations_from_blocks( $block['innerBlocks'], $out );
			}
		}
	}

	private function echo_ld_json( array $data ): void {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $json === false || ! function_exists( 'meyvora_seo_print_ld_json_script' ) ) {
			return;
		}
		meyvora_seo_print_ld_json_script( wp_strip_all_tags( $json ) );
		echo "\n";
	}

	/**
	 * Build E-E-A-T checklist for the block editor (present/missing signals).
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, bool>
	 */
	public static function get_eeat_checklist_for_post( int $post_id ): array {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		$author_id = $post ? (int) $post->post_author : 0;
		$keys = self::get_eeat_meta_keys();

		$expertise = $author_id ? get_user_meta( $author_id, $keys['expertise_area'], true ) : '';
		$credentials = $author_id ? get_user_meta( $author_id, $keys['credentials'], true ) : '';
		$org = $author_id ? get_user_meta( $author_id, $keys['organization_affiliation'], true ) : '';
		$years = $author_id ? get_user_meta( $author_id, $keys['years_experience'], true ) : '';
		$has_modified = $post && get_the_modified_date( 'U', $post ) > get_the_date( 'U', $post );
		$has_citations = $post && has_block( 'meyvora-seo/citations', $post );

		return array(
			'author_has_expertise_area'           => is_string( $expertise ) && $expertise !== '',
			'author_has_credentials'              => is_string( $credentials ) && $credentials !== '',
			'author_has_organization_affiliation' => is_string( $org ) && $org !== '',
			'author_has_years_experience'         => $years !== '' && $years !== false && (int) $years > 0,
			'post_has_date_modified'              => (bool) $post,
			'post_has_citations_block'            => $has_citations,
			'post_has_byline_speakable'           => true, // We always output speakable in Article schema.
		);
	}
}
