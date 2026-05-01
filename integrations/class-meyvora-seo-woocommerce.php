<?php
/**
 * WooCommerce integration: product/category SEO, Product schema, shop page, sitemaps.
 *
 * @package Meyvora_SEO
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.Security.NonceVerification.Recommended -- Bulk meta; GET display params.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_WooCommerce {

	/**
	 * @var bool
	 */
	private static bool $registered = false;

	const TERM_META_TITLE       = 'meyvora_seo_term_title';
	const TERM_META_DESCRIPTION = 'meyvora_seo_term_description';
	const TERM_META_OG_IMAGE    = 'meyvora_seo_term_og_image';

	/**
	 * Register hooks when WooCommerce is active.
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Post type and sitemap.
		add_filter( 'meyvora_seo_supported_post_types', array( __CLASS__, 'add_product_post_type' ), 10, 1 );
		add_filter( 'meyvora_seo_sitemap_post_types', array( __CLASS__, 'add_product_to_sitemap' ), 10, 1 );
		add_filter( 'meyvora_seo_sitemap_extra_taxonomies', array( __CLASS__, 'sitemap_extra_taxonomies' ), 10, 1 );

		// Product SEO title (template with {product_name}, {category}, {site_name}).
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_product_document_title' ), 11, 1 );

		// Product meta description fallback (short desc or first 160 chars).
		add_filter( 'meyvora_seo_singular_meta_description', array( __CLASS__, 'filter_product_meta_description' ), 10, 2 );

		// Category SEO: term meta and frontend.
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'product_cat_add_seo_fields' ), 10, 0 );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'product_cat_edit_seo_fields' ), 10, 1 );
		add_action( 'created_product_cat', array( __CLASS__, 'save_product_cat_seo' ), 10, 1 );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_product_cat_seo' ), 10, 1 );
		add_filter( 'meyvora_seo_document_title_override', array( __CLASS__, 'product_cat_document_title' ), 10, 2 );
		add_filter( 'meyvora_seo_meta_description_override', array( __CLASS__, 'product_cat_meta_description' ), 10, 1 );
		add_filter( 'meyvora_seo_og_title', array( __CLASS__, 'product_cat_og_title' ), 10, 1 );
		add_filter( 'meyvora_seo_og_description', array( __CLASS__, 'product_cat_og_description' ), 10, 1 );
		add_filter( 'meyvora_seo_og_image', array( __CLASS__, 'product_cat_og_image' ), 10, 1 );
		add_action( 'edited_product_cat', array( __CLASS__, 'clear_sitemap_on_term_save' ), 20, 0 );
		add_action( 'edited_product_tag', array( __CLASS__, 'clear_sitemap_on_term_save' ), 10, 0 );

		// Shop page SEO (title, description, OG).
		add_filter( 'meyvora_seo_document_title_override', array( __CLASS__, 'shop_page_document_title' ), 10, 2 );
		add_filter( 'meyvora_seo_meta_description_override', array( __CLASS__, 'shop_page_meta_description' ), 10, 1 );
		add_filter( 'meyvora_seo_og_title', array( __CLASS__, 'shop_page_og_title' ), 10, 1 );
		add_filter( 'meyvora_seo_og_description', array( __CLASS__, 'shop_page_og_description' ), 10, 1 );

		// Schema and head.
		add_action( 'wp_head', array( __CLASS__, 'output_product_schema' ), 2, 0 );

		// Out-of-stock auto-redirect to category (when enabled in settings).
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'handle_stock_status_change' ), 10, 3 );

		// Admin: enqueue media for term OG image.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_term_seo_scripts' ), 10, 1 );
	}

	/**
	 * Add product_cat and product_tag to sitemap extra taxonomies.
	 *
	 * @param array<string> $taxonomies
	 * @return array<string>
	 */
	public static function sitemap_extra_taxonomies( array $taxonomies ): array {
		if ( taxonomy_exists( 'product_cat' ) && ! in_array( 'product_cat', $taxonomies, true ) ) {
			$taxonomies[] = 'product_cat';
		}
		if ( taxonomy_exists( 'product_tag' ) && ! in_array( 'product_tag', $taxonomies, true ) ) {
			$taxonomies[] = 'product_tag';
		}
		return $taxonomies;
	}

	public static function clear_sitemap_on_term_save(): void {
		do_action( 'meyvora_seo_clear_sitemap_cache' );
	}

	/**
	 * Product document title: when no custom meta, build from template {product_name} | {category} | {site_name}.
	 *
	 * @param array<string, string> $title_parts
	 * @return array<string, string>
	 */
	public static function filter_product_document_title( array $title_parts ): array {
		if ( ! is_singular( 'product' ) ) {
			return $title_parts;
		}
		$post = get_post();
		if ( ! $post ) {
			return $title_parts;
		}
		$custom = get_post_meta( $post->ID, MEYVORA_SEO_META_TITLE, true );
		if ( is_string( $custom ) && $custom !== '' ) {
			return $title_parts;
		}
		$options = new Meyvora_SEO_Options();
		$template = $options->get( 'product_title_template', '{product_name} | {category} | {site_name}' );
		$category = '';
		$terms = get_the_terms( $post->ID, 'product_cat' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$first = reset( $terms );
			$category = $first->name;
		}
		$replace = array(
			'{product_name}' => $post->post_title,
			'{category}'     => $category,
			'{site_name}'    => get_bloginfo( 'name', 'display' ),
			'{title}'        => $post->post_title,
			'{separator}'    => $options->get( 'title_separator', '|' ),
			'{site_title}'   => get_bloginfo( 'name', 'display' ),
		);
		$title = str_replace( array_keys( $replace ), array_values( $replace ), $template );
		$title = wp_strip_all_tags( $title );
		if ( $title !== '' ) {
			$title_parts['title'] = $title;
		}
		return $title_parts;
	}

	/**
	 * Product meta description: short description or first 160 chars of description when no custom meta.
	 *
	 * @param string   $desc
	 * @param WP_Post  $post
	 * @return string
	 */
	public static function filter_product_meta_description( string $desc, WP_Post $post ): string {
		if ( $post->post_type !== 'product' ) {
			return $desc;
		}
		$custom = get_post_meta( $post->ID, MEYVORA_SEO_META_DESCRIPTION, true );
		if ( is_string( $custom ) && $custom !== '' ) {
			return $desc;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $desc;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return $desc;
		}
		$short = $product->get_short_description();
		if ( is_string( $short ) && trim( $short ) !== '' ) {
			return wp_trim_words( wp_strip_all_tags( $short ), 30 );
		}
		$long = $product->get_description();
		if ( is_string( $long ) && trim( $long ) !== '' ) {
			$text = wp_strip_all_tags( $long );
			return strlen( $text ) > 160 ? substr( $text, 0, 157 ) . '...' : $text;
		}
		return $desc;
	}

	/**
	 * SEO fields on Add Product Category form.
	 */
	public static function product_cat_add_seo_fields(): void {
		$title = '';
		$description = '';
		$og_image = '';
		require_once dirname( __DIR__ ) . '/admin/views/woocommerce-term-seo.php';
	}

	/**
	 * SEO fields on Edit Product Category form.
	 *
	 * @param WP_Term $term
	 */
	public static function product_cat_edit_seo_fields( WP_Term $term ): void {
		$title = get_term_meta( $term->term_id, self::TERM_META_TITLE, true );
		$description = get_term_meta( $term->term_id, self::TERM_META_DESCRIPTION, true );
		$og_image = get_term_meta( $term->term_id, self::TERM_META_OG_IMAGE, true );
		require_once dirname( __DIR__ ) . '/admin/views/woocommerce-term-seo.php';
	}

	/**
	 * Save product_cat SEO term meta.
	 *
	 * @param int $term_id
	 */
	public static function save_product_cat_seo( int $term_id ): void {
		if ( ! isset( $_POST['meyvora_seo_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['meyvora_seo_term_nonce'] ) ), 'meyvora_seo_term_seo' ) ) {
			return;
		}
		if ( isset( $_POST['meyvora_seo_term_title'] ) ) {
			update_term_meta( $term_id, self::TERM_META_TITLE, sanitize_text_field( wp_unslash( $_POST['meyvora_seo_term_title'] ) ) );
		}
		if ( isset( $_POST['meyvora_seo_term_description'] ) ) {
			update_term_meta( $term_id, self::TERM_META_DESCRIPTION, sanitize_textarea_field( wp_unslash( $_POST['meyvora_seo_term_description'] ) ) );
		}
		if ( isset( $_POST['meyvora_seo_term_og_image'] ) ) {
			update_term_meta( $term_id, self::TERM_META_OG_IMAGE, absint( wp_unslash( $_POST['meyvora_seo_term_og_image'] ) ) );
		}
	}

	/**
	 * Document title for product_cat archive from term meta.
	 *
	 * @param string       $title
	 * @param WP_Term|null $object
	 * @return string
	 */
	public static function product_cat_document_title( string $title, $object ): string {
		if ( ! is_tax( 'product_cat' ) || ! $object instanceof WP_Term ) {
			return $title;
		}
		$t = get_term_meta( $object->term_id, self::TERM_META_TITLE, true );
		return is_string( $t ) && $t !== '' ? $t : $title;
	}

	/**
	 * Meta description for product_cat from term meta.
	 *
	 * @param mixed $object Queried object.
	 * @return string
	 */
	public static function product_cat_meta_description( $object ): string {
		if ( ! is_tax( 'product_cat' ) || ! $object instanceof WP_Term ) {
			return '';
		}
		$d = get_term_meta( $object->term_id, self::TERM_META_DESCRIPTION, true );
		return is_string( $d ) ? $d : '';
	}

	/**
	 * OG title for product_cat.
	 *
	 * @param mixed $object
	 * @return string
	 */
	public static function product_cat_og_title( $object ): string {
		if ( ! is_tax( 'product_cat' ) || ! $object instanceof WP_Term ) {
			return '';
		}
		$t = get_term_meta( $object->term_id, self::TERM_META_TITLE, true );
		return is_string( $t ) && $t !== '' ? $t : (string) $object->name;
	}

	/**
	 * OG description for product_cat.
	 *
	 * @param mixed $object
	 * @return string
	 */
	public static function product_cat_og_description( $object ): string {
		if ( ! is_tax( 'product_cat' ) || ! $object instanceof WP_Term ) {
			return '';
		}
		$d = get_term_meta( $object->term_id, self::TERM_META_DESCRIPTION, true );
		return is_string( $d ) ? $d : '';
	}

	/**
	 * OG image for product_cat (term meta attachment ID -> URL).
	 *
	 * @param mixed $object
	 * @return string
	 */
	public static function product_cat_og_image( $object ): string {
		if ( ! is_tax( 'product_cat' ) || ! $object instanceof WP_Term ) {
			return '';
		}
		$img_id = get_term_meta( $object->term_id, self::TERM_META_OG_IMAGE, true );
		if ( ! $img_id || ! is_numeric( $img_id ) ) {
			return '';
		}
		$url = wp_get_attachment_image_url( (int) $img_id, 'full' );
		return $url ? $url : '';
	}

	/**
	 * Shop page document title from options.
	 *
	 * @param string $title
	 * @param mixed  $object
	 * @return string
	 */
	public static function shop_page_document_title( string $title, $object ): string {
		if ( ! function_exists( 'wc_get_page_id' ) || ! is_shop() ) {
			return $title;
		}
		$options = new Meyvora_SEO_Options();
		$t = $options->get( 'wc_shop_seo_title', '' );
		return is_string( $t ) && $t !== '' ? $t : $title;
	}

	/**
	 * Shop page meta description from options.
	 *
	 * @param mixed $object
	 * @return string
	 */
	public static function shop_page_meta_description( $object ): string {
		if ( ! function_exists( 'wc_get_page_id' ) || ! is_shop() ) {
			return '';
		}
		$options = new Meyvora_SEO_Options();
		$d = $options->get( 'wc_shop_seo_description', '' );
		return is_string( $d ) ? $d : '';
	}

	/**
	 * Shop page OG title from options.
	 *
	 * @param mixed $object
	 * @return string
	 */
	public static function shop_page_og_title( $object ): string {
		if ( ! is_shop() ) {
			return '';
		}
		$options = new Meyvora_SEO_Options();
		$t = $options->get( 'wc_shop_seo_title', '' );
		return is_string( $t ) && $t !== '' ? $t : '';
	}

	/**
	 * Shop page OG description from options.
	 *
	 * @param mixed $object
	 * @return string
	 */
	public static function shop_page_og_description( $object ): string {
		if ( ! is_shop() ) {
			return '';
		}
		$options = new Meyvora_SEO_Options();
		$d = $options->get( 'wc_shop_seo_description', '' );
		return is_string( $d ) ? $d : '';
	}

	/**
	 * Enqueue scripts for product_cat add/edit (media picker for OG image).
	 *
	 * @param string $hook_suffix
	 */
	public static function enqueue_term_seo_scripts( string $hook_suffix ): void {
		if ( $hook_suffix !== 'term.php' && $hook_suffix !== 'edit-tags.php' ) {
			return;
		}
		if ( ! isset( $_GET['taxonomy'] ) || ( $_GET['taxonomy'] !== 'product_cat' && $_GET['taxonomy'] !== 'product_tag' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_add_inline_script( 'jquery', '
			jQuery(function($){
				$(document).on("click", ".meyvora-seo-og-image-select", function(e){
					e.preventDefault();
					var btn = $(this);
					var input = btn.siblings("input[name=meyvora_seo_term_og_image]");
					var frame = wp.media({ multiple: false, library: { type: "image" } });
					frame.on("select", function(){
						var att = frame.state().get("selection").first().toJSON();
						input.val(att.id);
						btn.next(".meyvora-seo-og-image-preview").html(att.sizes && att.sizes.thumbnail ? "<img src=\""+att.sizes.thumbnail.url+"\" />" : "<img src=\""+att.url+"\" style=\"max-width:150px;\" />");
					});
					frame.open();
				});
				$(document).on("click", ".meyvora-seo-og-image-remove", function(e){
					e.preventDefault();
					var btn = $(this);
					btn.siblings("input[name=meyvora_seo_term_og_image]").val("");
					btn.siblings(".meyvora-seo-og-image-preview").empty();
				});
			});
		' );
	}

	/**
	 * @param array<string> $types
	 * @return array<string>
	 */
	public static function add_product_post_type( array $types ): array {
		if ( post_type_exists( 'product' ) && ! in_array( 'product', $types, true ) ) {
			$types[] = 'product';
		}
		return $types;
	}

	/**
	 * Output Product schema for single product pages.
	 */
	public static function output_product_schema(): void {
		if ( ! is_singular( 'product' ) ) {
			return;
		}
		$post = get_post();
		if ( ! $post || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		$product_url = get_permalink( $post );
		$product_url = is_string( $product_url ) ? esc_url_raw( $product_url ) : '';
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => $product->get_name(),
			'description' => wp_strip_all_tags( $product->get_description() ?: $product->get_short_description() ),
			'url'         => $product_url,
			'sku'         => $product->get_sku(),
		);

		// Brand: WooCommerce Brands taxonomy or pa_brand attribute.
		$brand = self::get_product_brand( $product );
		if ( $brand !== '' ) {
			$data['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}

		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$img_url = wp_get_attachment_image_url( $image_id, 'full' );
			if ( $img_url ) {
				$data['image'] = $img_url;
			}
		}

		// Offers: required for Rich Results; price fields only when product has a price.
		$offer_url = get_permalink( $post->ID );
		$offer_url = is_string( $offer_url ) ? esc_url_raw( $offer_url ) : '';
		$data['offers'] = array(
			'@type'         => 'Offer',
			'availability'  => $product->is_in_stock()
				? 'https://schema.org/InStock'
				: 'https://schema.org/OutOfStock',
			'url'           => $offer_url,
		);
		if ( $product->get_price() !== '' ) {
			$data['offers']['price']          = (string) $product->get_price();
			$data['offers']['priceCurrency']  = get_woocommerce_currency();
			$data['offers']['priceValidUntil'] = gmdate( 'Y-12-31', strtotime( '+1 year' ) );
		}

		// AggregateRating only when WooCommerce reviews enabled and product has reviews.
		$rating = self::get_product_aggregate_rating( $product );
		if ( get_option( 'woocommerce_enable_reviews' ) === 'yes' && $rating !== null ) {
			$data['aggregateRating'] = $rating;
		}

		$data = apply_filters( 'meyvora_seo_schema_data', $data, $post );
		$json = wp_json_encode( array_filter( $data, function ( $v ) {
			return $v !== null && $v !== '';
		} ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( $json !== false && function_exists( 'meyvora_seo_print_ld_json_script' ) ) {
			meyvora_seo_print_ld_json_script( $json );
			echo "\n";
		}
	}

	/**
	 * Get brand name from WooCommerce Brands or pa_brand attribute.
	 *
	 * @param WC_Product $product
	 * @return string
	 */
	private static function get_product_brand( $product ): string {
		// WooCommerce Brands plugin: product_brand taxonomy.
		if ( taxonomy_exists( 'product_brand' ) ) {
			$terms = get_the_terms( $product->get_id(), 'product_brand' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$first = reset( $terms );
				return $first->name;
			}
		}
		// pa_brand attribute.
		if ( $product->is_type( 'variable' ) ) {
			$attrs = $product->get_variation_attributes();
			if ( isset( $attrs['attribute_pa_brand'] ) && is_array( $attrs['attribute_pa_brand'] ) && ! empty( $attrs['attribute_pa_brand'] ) ) {
				$slug = reset( $attrs['attribute_pa_brand'] );
				$term = get_term_by( 'slug', $slug, 'pa_brand' );
				return ( $term && ! is_wp_error( $term ) ) ? $term->name : $slug;
			}
		}
		$brand_attr = $product->get_attribute( 'pa_brand' );
		if ( is_string( $brand_attr ) && $brand_attr !== '' ) {
			return $brand_attr;
		}
		return '';
	}

	/**
	 * Map WC stock status to schema.org ItemAvailability URL.
	 *
	 * @param string $status
	 * @return string
	 */
	private static function map_stock_status_to_schema( string $status ): string {
		switch ( $status ) {
			case 'instock':
				return 'https://schema.org/InStock';
			case 'outofstock':
				return 'https://schema.org/OutOfStock';
			case 'onbackorder':
				return 'https://schema.org/BackOrder';
			default:
				return 'https://schema.org/OutOfStock';
		}
	}

	/**
	 * Get aggregateRating for product if reviews enabled and has reviews.
	 *
	 * @param WC_Product $product
	 * @return array|null
	 */
	private static function get_product_aggregate_rating( $product ): ?array {
		if ( ! post_type_supports( 'product', 'comments' ) ) {
			return null;
		}
		$count = (int) $product->get_review_count();
		if ( $count < 1 ) {
			return null;
		}
		$average = (float) $product->get_average_rating();
		if ( $average <= 0 ) {
			return null;
		}
		return array(
			'@type'        => 'AggregateRating',
			'ratingValue'  => $average,
			'reviewCount'  => $count,
			'bestRating'   => 5,
		);
	}

	/**
	 * When stock status changes: out-of-stock → create 302 to primary category; instock → remove auto redirect.
	 *
	 * @param int         $product_id Product ID.
	 * @param string      $status     New status: 'instock', 'outofstock', 'onbackorder'.
	 * @param WC_Product  $product    Product object.
	 */
	public static function handle_stock_status_change( int $product_id, string $status, $product ): void {
		$options = new Meyvora_SEO_Options();
		if ( $options->get( 'wc_oos_auto_redirect', false ) !== true && $options->get( 'wc_oos_auto_redirect', '' ) !== '1' ) {
			return;
		}
		if ( ! class_exists( 'Meyvora_SEO_Redirects' ) ) {
			return;
		}
		$product_url = get_permalink( $product_id );
		if ( ! $product_url ) {
			return;
		}
		$from_path = wp_parse_url( $product_url, PHP_URL_PATH );
		if ( ! is_string( $from_path ) || $from_path === '' ) {
			return;
		}
		$from_path = rtrim( $from_path, '/' ) ?: '/';
		$note      = __( 'Auto: product out of stock', 'meyvora-seo' );
		global $wpdb;
		$table = $wpdb->prefix . Meyvora_SEO_Redirects::TABLE_REDIRECTS;

		if ( $status === 'outofstock' ) {
			$target = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : false;
			if ( ! is_string( $target ) || $target === '' ) {
				$target = home_url( '/' );
			}
			$terms = get_the_terms( $product_id, 'product_cat' );
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$cat_link = get_term_link( $terms[0] );
				if ( ! is_wp_error( $cat_link ) && is_string( $cat_link ) ) {
					$target = $cat_link;
				}
			}
			$exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE source_url = %s LIMIT 1",
				$from_path
			) );
			if ( ! $exists ) {
				Meyvora_SEO_Redirects::add_redirect( $from_path, $target, 302, $note );
			}
		} elseif ( $status === 'instock' ) {
			$wpdb->delete(
				$table,
				array(
					'source_url' => $from_path,
					'notes'      => $note,
				),
				array( '%s', '%s' )
			);
			Meyvora_SEO_Redirects::invalidate_cache();
		}
	}

	/**
	 * Product SEO stats for dashboard: poor score, missing OG image, missing focus keyword.
	 * Single batched meta query for all published products.
	 *
	 * @return array{ poor_score: int, no_og_image: int, no_keyword: int }
	 */
	public static function get_product_seo_stats(): array {
		if ( ! post_type_exists( 'product' ) ) {
			return array( 'poor_score' => 0, 'no_og_image' => 0, 'no_keyword' => 0 );
		}
		$product_ids = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		if ( ! is_array( $product_ids ) || empty( $product_ids ) ) {
			return array( 'poor_score' => 0, 'no_og_image' => 0, 'no_keyword' => 0 );
		}
		global $wpdb;
		$id_list   = implode( ',', array_map( 'intval', $product_ids ) );
		$meta_keys = array(
			MEYVORA_SEO_META_SCORE,
			MEYVORA_SEO_META_OG_IMAGE,
			MEYVORA_SEO_META_FOCUS_KEYWORD,
		);
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_list}) AND meta_key IN ({$placeholders})",
			...$meta_keys
		), ARRAY_A );
		$by_post = array();
		foreach ( $product_ids as $pid ) {
			$by_post[ $pid ] = array( 'score' => null, 'og_image' => null, 'keyword' => null );
		}
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$pid = (int) ( $r['post_id'] ?? 0 );
				if ( ! isset( $by_post[ $pid ] ) ) {
					continue;
				}
				$key   = $r['meta_key'] ?? '';
				$value = $r['meta_value'] ?? '';
				if ( $key === MEYVORA_SEO_META_SCORE ) {
					$by_post[ $pid ]['score'] = is_numeric( $value ) ? (int) $value : null;
				} elseif ( $key === MEYVORA_SEO_META_OG_IMAGE ) {
					$by_post[ $pid ]['og_image'] = $value !== '' && $value !== '0' ? $value : null;
				} elseif ( $key === MEYVORA_SEO_META_FOCUS_KEYWORD ) {
					$by_post[ $pid ]['keyword'] = $value;
				}
			}
		}
		$poor_score  = 0;
		$no_og_image = 0;
		$no_keyword  = 0;
		foreach ( $by_post as $data ) {
			$score = $data['score'];
			if ( $score === null || $score < 50 ) {
				$poor_score++;
			}
			if ( $data['og_image'] === null || $data['og_image'] === '' || $data['og_image'] === '0' ) {
				$no_og_image++;
			}
			$kw = $data['keyword'];
			if ( $kw === null || $kw === '' || $kw === '[]' || trim( (string) $kw ) === '' ) {
				$no_keyword++;
			}
		}
		return array(
			'poor_score'  => $poor_score,
			'no_og_image' => $no_og_image,
			'no_keyword'  => $no_keyword,
		);
	}

	/**
	 * @param array<string> $types
	 * @return array<string>
	 */
	public static function add_product_to_sitemap( array $types ): array {
		if ( post_type_exists( 'product' ) && ! in_array( 'product', $types, true ) ) {
			$types[] = 'product';
		}
		return $types;
	}
}
