<?php
/**
 * Google-style SERP snippet preview: desktop + mobile, pixel progress bars, favicon, breadcrumb URL.
 * Renders in the SEO meta box after the meta description field.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Serp_Preview
 */
class Meyvora_SEO_Serp_Preview {

	/** Desktop: title ~600px, ~60 chars */
	const DESKTOP_TITLE_PX   = 600;
	const DESKTOP_TITLE_CHAR = 60;

	/** Desktop: description ~920px, ~160 chars */
	const DESKTOP_DESC_PX   = 920;
	const DESKTOP_DESC_CHAR = 160;

	/** Mobile: title ~480px, ~50 chars */
	const MOBILE_TITLE_PX   = 480;
	const MOBILE_TITLE_CHAR = 50;

	/** Mobile: description ~680px, ~120 chars */
	const MOBILE_DESC_PX   = 680;
	const MOBILE_DESC_CHAR = 120;

	/**
	 * Get site favicon URL (site icon or fallback).
	 *
	 * @param int $size Size in pixels.
	 * @return string URL.
	 */
	public static function get_favicon_url( int $size = 16 ): string {
		$url = get_site_icon_url( $size );
		if ( $url !== '' ) {
			return $url;
		}
		return home_url( '/favicon.ico' );
	}

	/**
	 * Build breadcrumb URL trail from full URL (e.g. example.com › blog › post-name).
	 *
	 * @param string $url Full URL.
	 * @return string Breadcrumb trail.
	 */
	public static function get_breadcrumb_trail( string $url ): string {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return $url;
		}
		$host = str_replace( 'www.', '', $parsed['host'] );
		$path = isset( $parsed['path'] ) ? trim( $parsed['path'], '/' ) : '';
		if ( $path === '' ) {
			return $host;
		}
		$segments = array_filter( explode( '/', $path ) );
		$trail    = $host;
		if ( ! empty( $segments ) ) {
			$trail .= ' › ' . implode( ' › ', $segments );
		}
		return $trail;
	}

	/**
	 * Render the SERP preview panel HTML (desktop + mobile, progress bars, favicon, breadcrumb).
	 * Call this after the meta description field in the meta box.
	 *
	 * @param WP_Post $post    Current post.
	 * @param array   $snippet Optional. 'url', 'title', 'description'. Default from post/meta.
	 */
	public static function render( WP_Post $post, array $snippet = array() ): void {
		$url   = isset( $snippet['url'] ) ? $snippet['url'] : ( get_permalink( $post ) ?: home_url( '/' ) );
		$title = isset( $snippet['title'] ) ? $snippet['title'] : '';
		$desc  = isset( $snippet['description'] ) ? $snippet['description'] : '';

		if ( $title === '' ) {
			$title = $post->post_title;
		}
		if ( $desc === '' && ! empty( $post->post_content ) ) {
			$desc = wp_trim_words( wp_strip_all_tags( $post->post_content ), 25 );
		}

		$favicon_url = self::get_favicon_url( 16 );
		$breadcrumb  = self::get_breadcrumb_trail( $url );

		$desktop_title_px   = self::DESKTOP_TITLE_PX;
		$desktop_title_char = self::DESKTOP_TITLE_CHAR;
		$desktop_desc_px    = self::DESKTOP_DESC_PX;
		$desktop_desc_char  = self::DESKTOP_DESC_CHAR;
		$mobile_title_px    = self::MOBILE_TITLE_PX;
		$mobile_title_char  = self::MOBILE_TITLE_CHAR;
		$mobile_desc_px     = self::MOBILE_DESC_PX;
		$mobile_desc_char   = self::MOBILE_DESC_CHAR;
		?>
		<div class="meyvora-field meyvora-serp-preview-wrap">
			<label><?php esc_html_e( 'Google snippet preview', 'meyvora-seo' ); ?></label>
			<div class="mev-serp-container" data-initial-url="<?php echo esc_attr( $url ); ?>" data-initial-title="<?php echo esc_attr( $title ); ?>" data-initial-desc="<?php echo esc_attr( $desc ); ?>">
				<div class="mev-serp-toggle" role="tablist">
					<button type="button" class="mev-serp-mode is-active" data-mode="desktop" role="tab" aria-selected="true"><?php esc_html_e( 'Desktop', 'meyvora-seo' ); ?></button>
					<button type="button" class="mev-serp-mode" data-mode="mobile" role="tab" aria-selected="false"><?php esc_html_e( 'Mobile', 'meyvora-seo' ); ?></button>
				</div>

				<!-- Desktop preview -->
				<div id="mev-serp-desktop" class="mev-serp-preview mev-serp-preview--desktop is-active" data-title-px="<?php echo (int) $desktop_title_px; ?>" data-title-char="<?php echo (int) $desktop_title_char; ?>" data-desc-px="<?php echo (int) $desktop_desc_px; ?>" data-desc-char="<?php echo (int) $desktop_desc_char; ?>">
					<div class="mev-serp-progress mev-serp-progress--title">
						<div class="mev-serp-progress-bar" role="presentation">
							<div class="mev-serp-progress-fill mev-serp-progress-fill--title mev-serp-bar--green" id="mev-serp-desktop-title-bar"></div>
						</div>
						<span class="mev-serp-progress-label" id="mev-serp-desktop-title-label">0 / <?php echo (int) $desktop_title_char; ?> chars · ~0 / <?php echo (int) $desktop_title_px; ?>px</span>
					</div>
					<div class="mev-serp-snippet mev-serp-snippet--desktop">
						<div class="mev-serp-header">
							<img class="mev-serp-favicon" src="<?php echo esc_url( $favicon_url ); ?>" alt="" width="16" height="16" />
							<span class="mev-serp-breadcrumb" id="mev-serp-desktop-breadcrumb"><?php echo esc_html( $breadcrumb ); ?></span>
						</div>
						<div class="mev-serp-title" id="mev-serp-desktop-title" style="max-width:<?php echo (int) $desktop_title_px; ?>px;"><?php echo esc_html( $title ); ?></div>
						<div class="mev-serp-desc" id="mev-serp-desktop-desc" style="max-width:<?php echo (int) $desktop_desc_px; ?>px;"><?php echo esc_html( $desc ); ?></div>
					</div>
					<div class="mev-serp-progress mev-serp-progress--desc">
						<div class="mev-serp-progress-bar" role="presentation">
							<div class="mev-serp-progress-fill mev-serp-progress-fill--desc mev-serp-bar--green" id="mev-serp-desktop-desc-bar"></div>
						</div>
						<span class="mev-serp-progress-label" id="mev-serp-desktop-desc-label">0 / <?php echo (int) $desktop_desc_char; ?> chars · ~0 / <?php echo (int) $desktop_desc_px; ?>px</span>
					</div>
				</div>

				<!-- Mobile preview -->
				<div id="mev-serp-mobile" class="mev-serp-preview mev-serp-preview--mobile" hidden data-title-px="<?php echo (int) $mobile_title_px; ?>" data-title-char="<?php echo (int) $mobile_title_char; ?>" data-desc-px="<?php echo (int) $mobile_desc_px; ?>" data-desc-char="<?php echo (int) $mobile_desc_char; ?>">
					<div class="mev-serp-progress mev-serp-progress--title">
						<div class="mev-serp-progress-bar" role="presentation">
							<div class="mev-serp-progress-fill mev-serp-progress-fill--title mev-serp-bar--green" id="mev-serp-mobile-title-bar"></div>
						</div>
						<span class="mev-serp-progress-label" id="mev-serp-mobile-title-label">0 / <?php echo (int) $mobile_title_char; ?> chars · ~0 / <?php echo (int) $mobile_title_px; ?>px</span>
					</div>
					<div class="mev-serp-snippet mev-serp-snippet--mobile">
						<div class="mev-serp-header">
							<img class="mev-serp-favicon" src="<?php echo esc_url( $favicon_url ); ?>" alt="" width="16" height="16" />
							<span class="mev-serp-breadcrumb" id="mev-serp-mobile-breadcrumb"><?php echo esc_html( $breadcrumb ); ?></span>
						</div>
						<div class="mev-serp-title" id="mev-serp-mobile-title" style="max-width:<?php echo (int) $mobile_title_px; ?>px;"><?php echo esc_html( $title ); ?></div>
						<div class="mev-serp-desc" id="mev-serp-mobile-desc" style="max-width:<?php echo (int) $mobile_desc_px; ?>px;"><?php echo esc_html( $desc ); ?></div>
					</div>
					<div class="mev-serp-progress mev-serp-progress--desc">
						<div class="mev-serp-progress-bar" role="presentation">
							<div class="mev-serp-progress-fill mev-serp-progress-fill--desc mev-serp-bar--green" id="mev-serp-mobile-desc-bar"></div>
						</div>
						<span class="mev-serp-progress-label" id="mev-serp-mobile-desc-label">0 / <?php echo (int) $mobile_desc_char; ?> chars · ~0 / <?php echo (int) $mobile_desc_px; ?>px</span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
