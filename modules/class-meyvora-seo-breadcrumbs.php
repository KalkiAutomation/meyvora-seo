<?php
/**
 * Breadcrumbs: generation, shortcode, template tag, schema-ready items.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Breadcrumbs {

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
		if ( ! $this->options->get( 'breadcrumbs_enabled', false ) ) {
			return;
		}
		$this->loader->add_action( 'wp_enqueue_scripts', $this, 'enqueue_css', 10, 0 );
		add_shortcode( 'meyvora_breadcrumbs', array( $this, 'shortcode' ) );
	}

	public function enqueue_css(): void {
		if ( ! is_singular() && ! is_archive() && ! is_search() ) {
			return;
		}
		$path = MEYVORA_SEO_PATH . 'assets/css/breadcrumbs.css';
		if ( file_exists( $path ) ) {
			wp_enqueue_style( 'meyvora-breadcrumbs', MEYVORA_SEO_URL . 'assets/css/breadcrumbs.css', array(), MEYVORA_SEO_VERSION );
		}
	}

	/**
	 * Shortcode [meyvora_breadcrumbs].
	 *
	 * @param array<string,string> $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode( array $atts = array() ): string {
		ob_start();
		meyvora_seo_breadcrumbs( $atts );
		return ob_get_clean();
	}
}

if ( ! function_exists( 'meyvora_seo_get_breadcrumb_items' ) ) {
	/**
	 * Get breadcrumb items for current context (for schema or display).
	 *
	 * @return array<int, array{ url: string, label: string }>
	 */
	function meyvora_seo_get_breadcrumb_items(): array {
		$items = array();
		$home_label = get_bloginfo( 'name' );
		$home_url = home_url( '/' );
		$items[] = array( 'url' => $home_url, 'label' => $home_label );

		if ( is_singular() ) {
			$post = get_post();
			if ( $post && $post->post_type === 'page' ) {
				$ancestors = get_post_ancestors( $post );
				$ancestors = array_reverse( $ancestors );
				foreach ( $ancestors as $aid ) {
					$items[] = array( 'url' => get_permalink( $aid ), 'label' => get_the_title( $aid ) );
				}
			} elseif ( $post && $post->post_type === 'post' ) {
				$cats = get_the_category( $post->ID );
				if ( ! empty( $cats ) ) {
					$cat = $cats[0];
					$items[] = array( 'url' => get_category_link( $cat ), 'label' => $cat->name );
				}
			} elseif ( $post && get_post_type_object( $post->post_type ) ) {
				$pt = get_post_type_object( $post->post_type );
				$archive_url = get_post_type_archive_link( $post->post_type );
				if ( $archive_url && $pt ) {
					$items[] = array( 'url' => $archive_url, 'label' => $pt->labels->name );
				}
			}
			$title = $post ? get_the_title( $post->ID ) : '';
			$breadcrumb_title = $post ? get_post_meta( $post->ID, MEYVORA_SEO_META_BREADCRUMB_TITLE, true ) : '';
			if ( is_string( $breadcrumb_title ) && $breadcrumb_title !== '' ) {
				$title = $breadcrumb_title;
			}
			$items[] = array( 'url' => '', 'label' => $title );
		} elseif ( is_category() ) {
			$items[] = array( 'url' => '', 'label' => single_cat_title( '', false ) );
		} elseif ( is_tag() ) {
			$items[] = array( 'url' => '', 'label' => single_tag_title( '', false ) );
		} elseif ( is_author() ) {
			$items[] = array( 'url' => '', 'label' => get_the_author() );
		} elseif ( is_search() ) {
			$items[] = array( 'url' => '', 'label' => sprintf( /* translators: %s: search query */ __( 'Search results for "%s"', 'meyvora-seo' ), get_search_query() ) );
		} elseif ( is_archive() ) {
			$items[] = array( 'url' => '', 'label' => get_the_archive_title() );
		} elseif ( is_404() ) {
			$items[] = array( 'url' => '', 'label' => __( '404 Not Found', 'meyvora-seo' ) );
		}

		return $items;
	}
}

if ( ! function_exists( 'meyvora_seo_breadcrumbs' ) ) {
	/**
	 * Output breadcrumb markup (nav + ol with schema).
	 *
	 * @param array<string,string> $args Optional. Separator, etc.
	 */
	function meyvora_seo_breadcrumbs( array $args = array() ): void {
		$items = meyvora_seo_get_breadcrumb_items();
		if ( empty( $items ) ) {
			return;
		}
		$sep = isset( $args['separator'] ) ? (string) $args['separator'] : ' / ';
		?>
		<nav aria-label="<?php esc_attr_e( 'Breadcrumb', 'meyvora-seo' ); ?>">
			<ol class="meyvora-breadcrumbs" itemscope itemtype="https://schema.org/BreadcrumbList">
				<?php
				$pos = 1;
				foreach ( $items as $i => $item ) {
					$last = $i === count( $items ) - 1;
					?>
					<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">
						<?php if ( $item['url'] !== '' && ! $last ) : ?>
							<a itemprop="item" href="<?php echo esc_url( $item['url'] ); ?>"><span itemprop="name"><?php echo esc_html( $item['label'] ); ?></span></a>
						<?php else : ?>
							<span itemprop="name"><?php echo esc_html( $item['label'] ); ?></span>
						<?php endif; ?>
						<meta itemprop="position" content="<?php echo esc_attr( (string) (int) $pos ); ?>">
						<?php if ( ! $last ) : ?><span class="meyvora-breadcrumb-sep" aria-hidden="true"><?php echo esc_html( $sep ); ?></span><?php endif; ?>
					</li>
					<?php
					$pos++;
				}
				?>
			</ol>
		</nav>
		<?php
	}
}
