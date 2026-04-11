<?php
/**
 * JSON-LD Schema output: Article, WebPage, Organization, BreadcrumbList, WebSite, FAQPage, Product, LocalBusiness, VideoObject.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.Security.NonceVerification.Missing -- Schema type/field toggles; nonce optional for display-only GET.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Schema {

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
		$this->loader->add_action( 'wp_head', $this, 'output_schema', 1, 0 );
		if ( is_admin() ) {
			$this->loader->add_action( 'show_user_profile', $this, 'render_author_profile_fields', 10, 1 );
			$this->loader->add_action( 'edit_user_profile', $this, 'render_author_profile_fields', 10, 1 );
			$this->loader->add_action( 'personal_options_update', $this, 'save_author_profile_fields', 10, 1 );
			$this->loader->add_action( 'edit_user_profile_update', $this, 'save_author_profile_fields', 10, 1 );
		}
	}

	/**
	 * Render Twitter, LinkedIn, Facebook URL fields on user profile.
	 *
	 * @param WP_User $user User object.
	 */
	public function render_author_profile_fields( WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$twitter  = get_user_meta( $user->ID, 'meyvora_seo_author_twitter_url', true );
		$linkedin = get_user_meta( $user->ID, 'meyvora_seo_author_linkedin_url', true );
		$facebook = get_user_meta( $user->ID, 'meyvora_seo_author_facebook_url', true );
		?>
		<h2><?php esc_html_e( 'SEO Author Profile', 'meyvora-seo' ); ?></h2>
		<table class="form-table">
			<tr>
				<th><label for="meyvora_seo_author_twitter_url"><?php esc_html_e( 'Twitter URL', 'meyvora-seo' ); ?></label></th>
				<td><input type="url" name="meyvora_seo_author_twitter_url" id="meyvora_seo_author_twitter_url" value="<?php echo esc_url( $twitter ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="meyvora_seo_author_linkedin_url"><?php esc_html_e( 'LinkedIn URL', 'meyvora-seo' ); ?></label></th>
				<td><input type="url" name="meyvora_seo_author_linkedin_url" id="meyvora_seo_author_linkedin_url" value="<?php echo esc_url( $linkedin ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th><label for="meyvora_seo_author_facebook_url"><?php esc_html_e( 'Facebook URL', 'meyvora-seo' ); ?></label></th>
				<td><input type="url" name="meyvora_seo_author_facebook_url" id="meyvora_seo_author_facebook_url" value="<?php echo esc_url( $facebook ); ?>" class="regular-text" /></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save author profile fields (Twitter, LinkedIn, Facebook URLs).
	 *
	 * @param int $user_id User ID.
	 */
	public function save_author_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		if ( isset( $_POST['meyvora_seo_author_twitter_url'] ) ) {
			update_user_meta( $user_id, 'meyvora_seo_author_twitter_url', sanitize_url( wp_unslash( $_POST['meyvora_seo_author_twitter_url'] ) ) );
		}
		if ( isset( $_POST['meyvora_seo_author_linkedin_url'] ) ) {
			update_user_meta( $user_id, 'meyvora_seo_author_linkedin_url', sanitize_url( wp_unslash( $_POST['meyvora_seo_author_linkedin_url'] ) ) );
		}
		if ( isset( $_POST['meyvora_seo_author_facebook_url'] ) ) {
			update_user_meta( $user_id, 'meyvora_seo_author_facebook_url', sanitize_url( wp_unslash( $_POST['meyvora_seo_author_facebook_url'] ) ) );
		}
	}

	public function output_schema(): void {
		// Skip schema on pages marked noindex — contradictory signals hurt credibility.
		if ( is_singular() ) {
			$pid     = get_queried_object_id();
			$noindex = $pid ? (bool) get_post_meta( $pid, MEYVORA_SEO_META_NOINDEX, true ) : false;
			if ( $noindex ) {
				// Still allow Organization/WebSite on noindex pages if desired via filter.
				$skip = apply_filters( 'meyvora_seo_skip_schema_on_noindex', true, $pid );
				if ( $skip ) {
					$this->output_organization_schema();
					// This return exits the entire output_schema() function, not just this if block.
					// output_organization_schema() below is only reached on non-noindex pages.
					return;
				}
			}
			$this->output_post_schema();
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$this->output_collection_page_schema();
		}
		if ( is_author() ) {
			$user = get_queried_object();
			if ( $user instanceof WP_User ) {
				$this->output_person_schema( $user );
			}
		}
		$this->output_organization_schema();
		if ( $this->options->get( 'schema_local_business', false ) ) {
			$this->output_local_business_schema();
		}
		if ( is_front_page() ) {
			$this->output_website_schema();
		}
	}

	/**
	 * Output CollectionPage schema for category, tag, and taxonomy archive pages.
	 */
	private function output_collection_page_schema(): void {
		$term = get_queried_object();
		if ( ! $term || ! isset( $term->term_id ) ) {
			return;
		}
		$url = get_term_link( $term );
		if ( is_wp_error( $url ) ) {
			return;
		}
		// Use custom SEO title if available (from Taxonomy_Meta module or WC integration).
		$title = '';
		if ( class_exists( 'Meyvora_SEO_Taxonomy_Meta' ) ) {
			$title = (string) get_term_meta( $term->term_id, Meyvora_SEO_Taxonomy_Meta::TERM_META_TITLE, true );
		}
		if ( $title === '' && isset( $term->taxonomy ) && in_array( $term->taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
			$title = (string) get_term_meta( $term->term_id, 'meyvora_seo_term_title', true );
		}
		if ( $title === '' ) {
			$title = $term->name ?? '';
		}
		$desc = '';
		if ( class_exists( 'Meyvora_SEO_Taxonomy_Meta' ) ) {
			$desc = (string) get_term_meta( $term->term_id, Meyvora_SEO_Taxonomy_Meta::TERM_META_DESCRIPTION, true );
		}
		if ( $desc === '' && ! empty( $term->description ) ) {
			$desc = wp_trim_words( wp_strip_all_tags( $term->description ), 30 );
		}
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'CollectionPage',
			'name'        => $title,
			'url'         => esc_url_raw( $url ),
		);
		if ( $desc !== '' ) {
			$data['description'] = $desc;
		}
		$data = apply_filters( 'meyvora_seo_schema_data', $data, null );
		$this->echo_ld_json( $this->remove_null( $data ) );
		$this->output_breadcrumb_schema();
	}

	/**
	 * Output Person schema on author archive pages.
	 *
	 * @param WP_User $user Author user object.
	 */
	public function output_person_schema( WP_User $user ): void {
		$url = get_author_posts_url( $user->ID );
		$name = $user->display_name ?: $user->user_login;
		$desc = get_the_author_meta( 'description', $user->ID );
		$image = get_avatar_url( $user->ID, array( 'size' => 96 ) );
		$same_as = array();
		$twitter = get_user_meta( $user->ID, 'meyvora_seo_author_twitter_url', true );
		if ( is_string( $twitter ) && $twitter !== '' ) {
			$same_as[] = esc_url_raw( $twitter );
		}
		$linkedin = get_user_meta( $user->ID, 'meyvora_seo_author_linkedin_url', true );
		if ( is_string( $linkedin ) && $linkedin !== '' ) {
			$same_as[] = esc_url_raw( $linkedin );
		}
		$facebook = get_user_meta( $user->ID, 'meyvora_seo_author_facebook_url', true );
		if ( is_string( $facebook ) && $facebook !== '' ) {
			$same_as[] = esc_url_raw( $facebook );
		}
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Person',
			'name'        => $name,
			'url'         => $url,
			'description' => is_string( $desc ) && $desc !== '' ? wp_strip_all_tags( $desc ) : null,
			'image'       => $image ?: null,
		);
		if ( ! empty( $same_as ) ) {
			$data['sameAs'] = $same_as;
		}
		$data = apply_filters( 'meyvora_seo_person_schema_data', $data, $user );
		$data = $this->remove_null( $data );
		$this->echo_ld_json( $data );
	}

	/**
	 * Get post meta (translated when multilingual helper exists).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key constant.
	 * @param bool   $single  Single value.
	 * @return mixed
	 */
	private function post_meta( int $post_id, string $key, bool $single = true ) {
		return function_exists( 'meyvora_seo_get_translated_post_meta' )
			? meyvora_seo_get_translated_post_meta( $post_id, $key, $single )
			: get_post_meta( $post_id, $key, $single );
	}

	private function output_post_schema(): void {
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$schema_type = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_TYPE, true );
		if ( ! is_string( $schema_type ) ) {
			$schema_type = '';
		}
		if ( $schema_type === 'None' || $schema_type === '' ) {
			$schema_type = ( $post->post_type === 'page' ) ? 'WebPage' : 'Article';
		}
		$title = $this->post_meta( $post->ID, MEYVORA_SEO_META_TITLE, true );
		if ( ! is_string( $title ) || $title === '' ) {
			$title = $post->post_title;
		}
		$desc = $this->post_meta( $post->ID, MEYVORA_SEO_META_DESCRIPTION, true );
		if ( ! is_string( $desc ) || $desc === '' ) {
			$desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 );
		}
		$url = get_permalink( $post );
		$image_id = (int) get_post_thumbnail_id( $post->ID );
		$image_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		$author_id = (int) $post->post_author;
		$author_name = get_the_author_meta( 'display_name', $author_id );

		$custom_schema_types = array( 'HowTo', 'Recipe', 'Event', 'Course', 'JobPosting', 'SoftwareApplication', 'Review', 'Book', 'Product' );
		if ( in_array( $schema_type, $custom_schema_types, true ) ) {
			$method = 'output_' . strtolower( $schema_type ) . '_schema';
			if ( method_exists( $this, $method ) ) {
				$this->$method( $post );
			}
			if ( $this->options->get( 'schema_faq', true ) ) {
				$this->output_faq_schema( $post );
			}
			if ( $this->options->get( 'schema_video', true ) ) {
				$this->output_video_schema( $post );
			}
			$this->output_breadcrumb_schema();
			return;
		}

		if ( $schema_type === 'WebPage' ) {
			$data = array(
				'@context' => 'https://schema.org',
				'@type'    => 'WebPage',
				'name'     => $title,
				'description' => $desc,
				'url'      => $url,
			);
			if ( $image_url ) {
				$data['image'] = $image_url;
			}
			$data['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
			$this->echo_ld_json( $data );
			$this->output_breadcrumb_schema();
			return;
		}

		if ( $schema_type === 'Article' || $schema_type === 'BlogPosting' || $schema_type === 'NewsArticle' ) {
			$author_url = get_author_posts_url( $author_id );
			$data = array(
				'@context'       => 'https://schema.org',
				'@type'          => $schema_type,
				'headline'       => $title,
				'description'    => $desc,
				'url'            => $url,
				'datePublished'  => get_the_date( 'c', $post ),
				'dateModified'   => get_the_modified_date( 'c', $post ),
				'author'         => array(
					'@type' => 'Person',
					'name'  => $author_name,
					'@id'   => $author_url ? $author_url . '#person' : null,
				),
				'publisher'      => $this->get_publisher_data(),
				'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $url ),
			);
			if ( $image_url ) {
				$data['image'] = $image_url;
			}
			$data = apply_filters( 'meyvora_seo_schema_data', $data, $post );
			$this->echo_ld_json( $this->remove_null( $data ) );
		}

		if ( $this->options->get( 'schema_faq', true ) ) {
			$this->output_faq_schema( $post );
		}
		if ( $this->options->get( 'schema_video', true ) ) {
			$this->output_video_schema( $post );
		}
		$this->output_breadcrumb_schema();
	}

	private function output_faq_schema( WP_Post $post ): void {
		$pairs = $this->get_faq_pairs_for_post( $post );
		if ( empty( $pairs ) ) {
			return;
		}
		$valid_pairs = array_filter( $pairs, function ( $p ) {
			$q = isset( $p['question'] ) ? trim( wp_strip_all_tags( (string) $p['question'] ) ) : '';
			$a = isset( $p['answer'] ) ? trim( wp_strip_all_tags( (string) $p['answer'] ) ) : '';
			return $q !== '' && $a !== '';
		} );
		$valid_pairs = array_values( $valid_pairs );
		if ( empty( $valid_pairs ) ) {
			return;
		}
		$main_entity = array();
		foreach ( $valid_pairs as $pair ) {
			$q = trim( wp_strip_all_tags( (string) ( $pair['question'] ?? '' ) ) );
			$a = trim( wp_strip_all_tags( (string) ( $pair['answer'] ?? '' ) ) );
			$main_entity[] = array(
				'@type'          => 'Question',
				'name'           => $q,
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $a,
				),
			);
		}
		$data = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);
		$this->echo_ld_json( $this->remove_null( $data ) );
	}

	/**
	 * Get FAQ question/answer pairs from post meta or content.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int, array{question?: string, answer?: string}>
	 */
	private function get_faq_pairs_for_post( WP_Post $post ): array {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_FAQ, true );
		if ( is_string( $raw ) && $raw !== '' ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				return $decoded;
			}
		}
		$content = $post->post_content;
		$pairs = array();
		if ( preg_match_all( '/<!-- wp:faq[^>]*-->\s*<div[^>]*class="[^"]*wp-block-faq[^"]*"[^>]*>(.*?)<\/div>\s*<!-- \/wp:faq -->/s', $content, $blocks ) ) {
			foreach ( $blocks[1] as $block_html ) {
				if ( preg_match_all( '/<details[^>]*>\s*<summary[^>]*>(.*?)<\/summary>\s*(.*?)<\/details>/s', $block_html, $items ) ) {
					foreach ( $items[1] as $i => $q ) {
						$a = isset( $items[2][ $i ] ) ? $items[2][ $i ] : '';
						$pairs[] = array( 'question' => trim( wp_strip_all_tags( $q ) ), 'answer' => trim( wp_strip_all_tags( $a ) ) );
					}
				}
			}
		}
		if ( ! empty( $pairs ) ) {
			return $pairs;
		}
		if ( has_shortcode( $content, 'meyvora_faq' ) && preg_match_all( '/\[meyvora_faq[^\]]*question=["\']([^"\']*)["\'][^\]]*answer=["\']([^"\']*)["\'][^\]]*\]/s', $content, $shortcodes ) ) {
			foreach ( $shortcodes[1] as $idx => $q ) {
				$a = isset( $shortcodes[2][ $idx ] ) ? $shortcodes[2][ $idx ] : '';
				$pairs[] = array( 'question' => $q, 'answer' => $a );
			}
		}
		return $pairs;
	}

	private function output_video_schema( WP_Post $post ): void {
		$video = $this->get_first_video_from_post( $post );
		if ( empty( $video ) ) {
			return;
		}
		$title = $this->post_meta( $post->ID, MEYVORA_SEO_META_TITLE, true );
		$title = is_string( $title ) && $title !== '' ? $title : $post->post_title;
		$desc = $this->post_meta( $post->ID, MEYVORA_SEO_META_DESCRIPTION, true );
		$desc = is_string( $desc ) && $desc !== '' ? $desc : wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );
		$data = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'VideoObject',
			'name'          => $title,
			'description'   => $desc,
			'embedUrl'      => $video['embed_url'],
			'thumbnailUrl'  => isset( $video['thumbnail'] ) ? $video['thumbnail'] : '',
			'uploadDate'    => isset( $video['upload_date'] ) ? $video['upload_date'] : get_the_date( 'c', $post ),
		);
		$this->echo_ld_json( $this->remove_null( $data ) );
	}

	/**
	 * Get first video embed URL (and optional thumbnail/date) from post content.
	 *
	 * @param WP_Post $post Post.
	 * @return array{embed_url: string, thumbnail?: string, upload_date?: string}|array{}
	 */
	private function get_first_video_from_post( WP_Post $post ): array {
		$content = $post->post_content;
		$url = '';
		if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]+)#', $content, $m ) ) {
			$vid = $m[1];
			return array(
				'embed_url' => 'https://www.youtube.com/embed/' . $vid,
				'thumbnail' => 'https://i.ytimg.com/vi/' . $vid . '/maxresdefault.jpg',
			);
		}
		if ( preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $content, $m ) ) {
			$vid = $m[1];
			return array(
				'embed_url' => 'https://player.vimeo.com/video/' . $vid,
			);
		}
		if ( has_block( 'core/embed', $post ) ) {
			$blocks = parse_blocks( $content );
			foreach ( $blocks as $block ) {
				if ( $block['blockName'] === 'core/embed' && ! empty( $block['attrs']['url'] ) ) {
					$u = $block['attrs']['url'];
					if ( strpos( $u, 'youtube' ) !== false || strpos( $u, 'youtu.be' ) !== false ) {
						if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]+)#', $u, $mm ) ) {
							$vid = $mm[1];
							return array(
								'embed_url' => 'https://www.youtube.com/embed/' . $vid,
								'thumbnail' => 'https://i.ytimg.com/vi/' . $vid . '/maxresdefault.jpg',
							);
						}
					}
					if ( strpos( $u, 'vimeo' ) !== false && preg_match( '#vimeo\.com/(?:video/)?(\d+)#', $u, $mm ) ) {
						return array( 'embed_url' => 'https://player.vimeo.com/video/' . $mm[1] );
					}
				}
			}
		}
		return array();
	}

	private function get_publisher_data(): array {
		$name = $this->options->get( 'schema_organization_name', get_bloginfo( 'name' ) );
		$logo = $this->options->get( 'schema_organization_logo', '' );
		$logo_url = $logo ? wp_get_attachment_image_url( (int) $logo, 'full' ) : '';
		$out = array(
			'@type' => 'Organization',
			'name'  => $name,
		);
		if ( $logo_url ) {
			$out['logo'] = array( '@type' => 'ImageObject', 'url' => $logo_url );
		}
		return $out;
	}

	private function output_organization_schema(): void {
		if ( ! $this->options->get( 'schema_organization', true ) ) {
			return;
		}
		$name = $this->options->get( 'schema_organization_name', get_bloginfo( 'name' ) );
		$url = home_url( '/' );
		$logo = $this->options->get( 'schema_organization_logo', '' );
		$logo_url = $logo ? wp_get_attachment_image_url( (int) $logo, 'full' ) : '';
		$same_as = array();
		foreach ( array( 'facebook', 'twitter', 'linkedin', 'instagram', 'youtube' ) as $key ) {
			$val = $this->options->get( 'schema_sameas_' . $key, '' );
			if ( is_string( $val ) && $val !== '' ) {
				$same_as[] = esc_url_raw( $val );
			}
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => $name,
			'url'      => $url,
		);
		if ( $logo_url ) {
			$data['logo'] = $logo_url;
		}
		if ( ! empty( $same_as ) ) {
			$data['sameAs'] = $same_as;
		}
		$data = apply_filters( 'meyvora_seo_schema_data', $data, null );
		$this->echo_ld_json( $this->remove_null( $data ) );
	}

	private function output_local_business_schema(): void {
		$name = $this->options->get( 'schema_lb_name', '' );
		if ( $name === '' ) {
			$name = get_bloginfo( 'name' );
		}
		$type = $this->options->get( 'schema_lb_type', 'LocalBusiness' );
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => $type ?: 'LocalBusiness',
			'name'     => $name,
			'url'      => home_url( '/' ),
		);
		$street = $this->options->get( 'schema_lb_street', '' );
		$locality = $this->options->get( 'schema_lb_locality', '' );
		$region = $this->options->get( 'schema_lb_region', '' );
		$postal = $this->options->get( 'schema_lb_postal', '' );
		$country = $this->options->get( 'schema_lb_country', '' );
		if ( $street || $locality || $region || $postal || $country ) {
			$data['address'] = array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $street,
				'addressLocality' => $locality,
				'addressRegion'   => $region,
				'postalCode'      => $postal,
				'addressCountry'  => $country,
			);
			$data['address'] = $this->remove_null( $data['address'] );
		}
		$phone = $this->options->get( 'schema_lb_phone', '' );
		if ( $phone !== '' ) {
			$data['telephone'] = $phone;
		}
		$email = $this->options->get( 'schema_lb_email', '' );
		if ( $email !== '' ) {
			$data['email'] = sanitize_email( $email );
		}
		$hours = $this->options->get( 'schema_lb_hours', '' );
		if ( $hours !== '' ) {
			$decoded = json_decode( $hours, true );
			if ( is_array( $decoded ) ) {
				$specs = array();
				foreach ( $decoded as $row ) {
					if ( empty( $row['closed'] ) && isset( $row['day'] ) && isset( $row['open'] ) && isset( $row['close'] ) ) {
						$specs[] = array(
							'@type'     => 'OpeningHoursSpecification',
							'dayOfWeek' => $row['day'],
							'opens'     => (string) $row['open'],
							'closes'    => (string) $row['close'],
						);
					}
				}
				if ( ! empty( $specs ) ) {
					$data['openingHoursSpecification'] = $specs;
				}
			} else {
				// Legacy: plain string (e.g. "Mo-Fr 09:00-17:00") — output as openingHours.
				$data['openingHours'] = sanitize_text_field( $hours );
			}
		}
		$lat = $this->options->get( 'schema_lb_lat', '' );
		$lng = $this->options->get( 'schema_lb_lng', '' );
		if ( $lat !== '' && $lng !== '' && is_numeric( $lat ) && is_numeric( $lng ) ) {
			$data['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}
		$price = $this->options->get( 'schema_lb_price_range', '' );
		if ( $price !== '' ) {
			$data['priceRange'] = $price;
		}
		$data = apply_filters( 'meyvora_seo_schema_data', $data, null );
		$this->echo_ld_json( $this->remove_null( $data ) );
	}

	private function output_website_schema(): void {
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'name'     => get_bloginfo( 'name' ),
			'url'      => home_url( '/' ),
		);
		if ( $this->options->get( 'schema_sitelinks_searchbox', true ) ) {
			$search_url = get_search_link( '{search_term_string}' );
			if ( strpos( $search_url, '{search_term_string}' ) === false ) {
				$search_url = home_url( '/?s={search_term_string}' );
			}
			$data['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array( '@type' => 'EntryPoint', 'urlTemplate' => $search_url ),
				'query-input' => 'required name=search_term_string',
			);
		}
		$data = apply_filters( 'meyvora_seo_schema_data', $data, null );
		$this->echo_ld_json( $this->remove_null( $data ) );
	}

	private function output_breadcrumb_schema(): void {
		if ( ! function_exists( 'meyvora_seo_get_breadcrumb_items' ) ) {
			$breadcrumbs_file = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'modules/class-meyvora-seo-breadcrumbs.php' : '';
			if ( $breadcrumbs_file && file_exists( $breadcrumbs_file ) ) {
				require_once $breadcrumbs_file;
			}
			if ( ! function_exists( 'meyvora_seo_get_breadcrumb_items' ) ) {
				return;
			}
		}
		$items = meyvora_seo_get_breadcrumb_items();
		if ( empty( $items ) ) {
			return;
		}
		$list = array();
		$pos = 1;
		foreach ( $items as $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $item['label'],
				'item'     => $item['url'],
			);
		}
		$data = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $list,
		);
		$this->echo_ld_json( $this->remove_null( $data ) );
	}

	/**
	 * Output HowTo schema from post meta (JSON: name, totalTime, estimatedCost, steps).
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_howto_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_HOWTO, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return;
		}
		$steps = isset( $data['steps'] ) && is_array( $data['steps'] ) ? $data['steps'] : array();
		$step_list = array();
		foreach ( $steps as $s ) {
			$name = isset( $s['name'] ) ? sanitize_text_field( (string) $s['name'] ) : '';
			$text = isset( $s['text'] ) ? wp_kses_post( (string) $s['text'] ) : '';
			if ( $name === '' && $text === '' ) {
				continue;
			}
			$item = array( '@type' => 'HowToStep' );
			if ( $name !== '' ) {
				$item['name'] = $name;
			}
			if ( $text !== '' ) {
				$item['text'] = $text;
			}
			if ( ! empty( $s['image'] ) && is_numeric( $s['image'] ) ) {
				$img_url = wp_get_attachment_image_url( (int) $s['image'], 'full' );
				if ( $img_url ) {
					$item['image'] = $img_url;
				}
			}
			$step_list[] = $item;
		}
		$out = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'HowTo',
			'name'        => sanitize_text_field( (string) $data['name'] ),
			'totalTime'   => isset( $data['totalTime'] ) ? sanitize_text_field( (string) $data['totalTime'] ) : null,
			'estimatedCost' => isset( $data['estimatedCost'] ) ? sanitize_text_field( (string) $data['estimatedCost'] ) : null,
			'step'        => $step_list,
		);
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output Recipe schema from post meta.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_recipe_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_RECIPE, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['recipeName'] ) ) {
			return;
		}
		$ingredients = isset( $data['ingredients'] ) && is_array( $data['ingredients'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $data['ingredients'] ) ) ) : array();
		$instructions = isset( $data['instructions'] ) && is_array( $data['instructions'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $data['instructions'] ) ) ) : array();
		$nutrition = array();
		if ( ! empty( $data['nutrition']['calories'] ) ) {
			$nutrition['calories'] = sanitize_text_field( (string) $data['nutrition']['calories'] );
		}
		if ( ! empty( $data['nutrition']['servingSize'] ) ) {
			$nutrition['servingSize'] = sanitize_text_field( (string) $data['nutrition']['servingSize'] );
		}
		$out = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'Recipe',
			'name'         => sanitize_text_field( (string) $data['recipeName'] ),
			'recipeYield'  => isset( $data['recipeYield'] ) ? sanitize_text_field( (string) $data['recipeYield'] ) : null,
			'cookTime'     => isset( $data['cookTime'] ) ? sanitize_text_field( (string) $data['cookTime'] ) : null,
			'prepTime'     => isset( $data['prepTime'] ) ? sanitize_text_field( (string) $data['prepTime'] ) : null,
			'ingredients'  => $ingredients,
			'recipeInstructions' => array_map( function ( $t ) {
				return array( '@type' => 'HowToStep', 'text' => $t );
			}, $instructions ),
		);
		if ( ! empty( $nutrition ) ) {
			$out['nutrition'] = array_merge( array( '@type' => 'NutritionInformation' ), $nutrition );
		}
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output Event schema from post meta.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_event_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_EVENT, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return;
		}
		$out = array(
			'@context'  => 'https://schema.org',
			'@type'    => 'Event',
			'name'     => sanitize_text_field( (string) $data['name'] ),
			'startDate' => isset( $data['startDate'] ) ? sanitize_text_field( (string) $data['startDate'] ) : null,
			'endDate'   => isset( $data['endDate'] ) ? sanitize_text_field( (string) $data['endDate'] ) : null,
			'eventStatus' => isset( $data['eventStatus'] ) ? sanitize_text_field( (string) $data['eventStatus'] ) : null,
			'eventAttendanceMode' => isset( $data['eventAttendanceMode'] ) ? sanitize_text_field( (string) $data['eventAttendanceMode'] ) : null,
			'organizer' => isset( $data['organizer'] ) && (string) $data['organizer'] !== '' ? array( '@type' => 'Organization', 'name' => sanitize_text_field( (string) $data['organizer'] ) ) : null,
		);
		if ( ! empty( $data['location']['name'] ) || ! empty( $data['location']['address'] ) ) {
			$loc = array( '@type' => 'Place' );
			if ( ! empty( $data['location']['name'] ) ) {
				$loc['name'] = sanitize_text_field( (string) $data['location']['name'] );
			}
			if ( ! empty( $data['location']['address'] ) ) {
				$loc['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => sanitize_text_field( (string) $data['location']['address'] ) );
			}
			$out['location'] = $loc;
		} else {
			$out['location'] = null;
		}
		if ( ! empty( $data['offers']['url'] ) || isset( $data['offers']['price'] ) ) {
			$offers = array( '@type' => 'Offer' );
			if ( isset( $data['offers']['price'] ) && $data['offers']['price'] !== '' ) {
				$offers['price'] = sanitize_text_field( (string) $data['offers']['price'] );
			}
			if ( ! empty( $data['offers']['currency'] ) ) {
				$offers['priceCurrency'] = sanitize_text_field( (string) $data['offers']['currency'] );
			}
			if ( ! empty( $data['offers']['url'] ) ) {
				$offers['url'] = esc_url_raw( (string) $data['offers']['url'] );
			}
			$out['offers'] = $offers;
		} else {
			$out['offers'] = null;
		}
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output Course schema from post meta.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_course_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_COURSE, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return;
		}
		$out = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Course',
			'name'        => sanitize_text_field( (string) $data['name'] ),
			'description' => isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : null,
			'courseCode'  => isset( $data['courseCode'] ) ? sanitize_text_field( (string) $data['courseCode'] ) : null,
		);
		if ( ! empty( $data['provider'] ) ) {
			$out['provider'] = array( '@type' => 'Organization', 'name' => sanitize_text_field( (string) $data['provider'] ) );
		}
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output JobPosting schema from post meta.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_jobposting_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_JOBPOSTING, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['title'] ) ) {
			return;
		}
		$out = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'JobPosting',
			'title'     => sanitize_text_field( (string) $data['title'] ),
			'description' => isset( $data['description'] ) ? wp_kses_post( (string) $data['description'] ) : null,
			'datePosted' => isset( $data['datePosted'] ) ? sanitize_text_field( (string) $data['datePosted'] ) : null,
			'validThrough' => isset( $data['validThrough'] ) ? sanitize_text_field( (string) $data['validThrough'] ) : null,
			'employmentType' => isset( $data['employmentType'] ) ? sanitize_text_field( (string) $data['employmentType'] ) : null,
		);
		if ( ! empty( $data['hiringOrganization']['name'] ) ) {
			$ho = array( '@type' => 'Organization', 'name' => sanitize_text_field( (string) $data['hiringOrganization']['name'] ) );
			if ( ! empty( $data['hiringOrganization']['url'] ) ) {
				$ho['sameAs'] = esc_url_raw( (string) $data['hiringOrganization']['url'] );
			}
			$out['hiringOrganization'] = $ho;
		}
		if ( ! empty( $data['jobLocation']['streetAddress'] ) || ! empty( $data['jobLocation']['city'] ) || ! empty( $data['jobLocation']['country'] ) ) {
			$out['jobLocation'] = array(
				'@type'         => 'Place',
				'address'       => array(
					'@type'           => 'PostalAddress',
					'streetAddress'   => isset( $data['jobLocation']['streetAddress'] ) ? sanitize_text_field( (string) $data['jobLocation']['streetAddress'] ) : '',
					'addressLocality' => isset( $data['jobLocation']['city'] ) ? sanitize_text_field( (string) $data['jobLocation']['city'] ) : '',
					'addressCountry' => isset( $data['jobLocation']['country'] ) ? sanitize_text_field( (string) $data['jobLocation']['country'] ) : '',
				),
			);
			$out['jobLocation']['address'] = $this->remove_null( $out['jobLocation']['address'] );
		}
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output SoftwareApplication schema from post meta.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_softwareapplication_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_SOFTWARE, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return;
		}
		$out = array(
			'@context'  => 'https://schema.org',
			'@type'     => 'SoftwareApplication',
			'name'      => sanitize_text_field( (string) $data['name'] ),
			'applicationCategory' => isset( $data['applicationCategory'] ) ? sanitize_text_field( (string) $data['applicationCategory'] ) : null,
			'operatingSystem' => isset( $data['operatingSystem'] ) ? sanitize_text_field( (string) $data['operatingSystem'] ) : null,
		);
		if ( isset( $data['offers']['price'] ) && (string) $data['offers']['price'] !== '' ) {
			$out['offers'] = array( '@type' => 'Offer', 'price' => sanitize_text_field( (string) $data['offers']['price'] ) );
			if ( ! empty( $data['offers']['priceCurrency'] ) ) {
				$out['offers']['priceCurrency'] = sanitize_text_field( (string) $data['offers']['priceCurrency'] );
			}
		}
		if ( ! empty( $data['aggregateRating']['ratingValue'] ) && is_numeric( $data['aggregateRating']['ratingValue'] ) ) {
			$out['aggregateRating'] = array(
				'@type'       => 'AggregateRating',
				'ratingValue' => (float) $data['aggregateRating']['ratingValue'],
				'reviewCount' => isset( $data['aggregateRating']['reviewCount'] ) ? (int) $data['aggregateRating']['reviewCount'] : null,
			);
			$out['aggregateRating'] = $this->remove_null( $out['aggregateRating'] );
		}
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output Review schema from post meta.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_review_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_REVIEW, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['itemReviewed']['name'] ) ) {
			return;
		}
		$item = array(
			'@type' => isset( $data['itemReviewed']['type'] ) && (string) $data['itemReviewed']['type'] !== '' ? sanitize_text_field( (string) $data['itemReviewed']['type'] ) : 'Thing',
			'name'  => sanitize_text_field( (string) $data['itemReviewed']['name'] ),
		);
		$out = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'Review',
			'itemReviewed' => $item,
			'reviewRating' => null,
			'author'       => isset( $data['author'] ) && (string) $data['author'] !== '' ? array( '@type' => 'Person', 'name' => sanitize_text_field( (string) $data['author'] ) ) : null,
			'reviewBody'   => isset( $data['reviewBody'] ) ? wp_kses_post( (string) $data['reviewBody'] ) : null,
		);
		if ( ! empty( $data['reviewRating']['ratingValue'] ) && is_numeric( $data['reviewRating']['ratingValue'] ) ) {
			$out['reviewRating'] = array(
				'@type'       => 'Rating',
				'ratingValue' => (float) $data['reviewRating']['ratingValue'],
				'bestRating'  => isset( $data['reviewRating']['bestRating'] ) && $data['reviewRating']['bestRating'] !== '' ? (float) $data['reviewRating']['bestRating'] : 5,
				'worstRating' => isset( $data['reviewRating']['worstRating'] ) && $data['reviewRating']['worstRating'] !== '' ? (float) $data['reviewRating']['worstRating'] : 1,
			);
		}
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output Book schema from post meta.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_book_schema( WP_Post $post ): void {
		$raw = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_BOOK, true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data['name'] ) ) {
			return;
		}
		$out = array(
			'@context'     => 'https://schema.org',
			'@type'        => 'Book',
			'name'         => sanitize_text_field( (string) $data['name'] ),
			'author'       => isset( $data['author'] ) && (string) $data['author'] !== '' ? array( '@type' => 'Person', 'name' => sanitize_text_field( (string) $data['author'] ) ) : null,
			'numberOfPages' => isset( $data['numberOfPages'] ) && is_numeric( $data['numberOfPages'] ) ? (int) $data['numberOfPages'] : null,
			'publisher'    => isset( $data['publisher'] ) && (string) $data['publisher'] !== '' ? array( '@type' => 'Organization', 'name' => sanitize_text_field( (string) $data['publisher'] ) ) : null,
			'isbn'         => isset( $data['isbn'] ) ? sanitize_text_field( (string) $data['isbn'] ) : null,
			'bookFormat'   => isset( $data['bookFormat'] ) ? sanitize_text_field( (string) $data['bookFormat'] ) : null,
		);
		if ( get_permalink( $post ) ) {
			$out['url'] = get_permalink( $post );
		}
		$this->echo_ld_json( $this->remove_null( $out ) );
	}

	/**
	 * Output standalone Product schema from post meta (non-WooCommerce). Skip when WC is active and this is a product.
	 *
	 * @param WP_Post $post Post.
	 */
	private function output_product_schema( WP_Post $post ): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return;
		}
		$raw  = $this->post_meta( $post->ID, MEYVORA_SEO_META_SCHEMA_PRODUCT, true );
		$data = is_string( $raw ) && $raw !== '' ? json_decode( $raw, true ) : array();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$title = $this->post_meta( $post->ID, MEYVORA_SEO_META_TITLE, true );
		$title = is_string( $title ) && $title !== '' ? $title : $post->post_title;
		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => $title,
			'description' => $this->post_meta( $post->ID, MEYVORA_SEO_META_DESCRIPTION, true ) ?: null,
			'url'         => get_permalink( $post ),
		);
		if ( ! empty( $data['price'] ) && is_numeric( $data['price'] ) ) {
			$schema['offers'] = array(
				'@type'         => 'Offer',
				'price'         => (float) $data['price'],
				'priceCurrency' => sanitize_text_field( $data['currency'] ?? 'USD' ),
				'availability'  => 'https://schema.org/InStock',
			);
		}
		if ( ! empty( $data['brand'] ) ) {
			$schema['brand'] = array( '@type' => 'Brand', 'name' => sanitize_text_field( $data['brand'] ) );
		}
		if ( ! empty( $data['gtin'] ) ) {
			$schema['gtin'] = sanitize_text_field( $data['gtin'] );
		}
		$img_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $img_id > 0 ) {
			$img_url = wp_get_attachment_image_url( $img_id, 'full' );
			if ( $img_url ) {
				$schema['image'] = $img_url;
			}
		}
		$schema = apply_filters( 'meyvora_seo_schema_data', $schema, $post );
		$this->echo_ld_json( $this->remove_null( $schema ) );
	}

	private function echo_ld_json( array $data ): void {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $json === false ) {
			return;
		}
		echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function remove_null( array $arr ): array {
		$out = array();
		foreach ( $arr as $k => $v ) {
			if ( $v === null ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$v = $this->remove_null( $v );
			}
			$out[ $k ] = $v;
		}
		return $out;
	}
}
