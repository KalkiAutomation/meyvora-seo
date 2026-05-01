<?php
/**
 * Social sharing preview panel: Facebook/OG and Twitter Card tabs with live preview cards.
 * Renders as a meta box tab; JS updates preview and character counts in real time.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_Social_Preview
 */
class Meyvora_SEO_Social_Preview {

	/** Facebook OG title max length (recommended). */
	const OG_TITLE_MAX = 88;

	/** Facebook OG description max length (recommended). */
	const OG_DESC_MAX = 200;

	/**
	 * Get site domain for preview (e.g. example.com).
	 *
	 * @param string $url Full URL.
	 * @return string
	 */
	public static function get_domain( string $url ): string {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['host'] ) ) {
			return '';
		}
		return str_replace( 'www.', '', $parsed['host'] );
	}

	/**
	 * Render the Social Preview tab panel: sub-tabs "Facebook / OG" and "Twitter Card", preview cards, character counts.
	 * Call from the meta box after the Social tab panel.
	 *
	 * @param WP_Post $post   Current post.
	 * @param array   $data   Optional. 'snippet_url', 'snippet_title', 'snippet_desc', 'og_title', 'og_desc', 'og_image_url', 'twitter_title', 'twitter_desc', 'twitter_image_url'.
	 */
	public static function render( WP_Post $post, array $data = array() ): void {
		$url           = isset( $data['snippet_url'] ) ? $data['snippet_url'] : ( get_permalink( $post ) ?: home_url( '/' ) );
		$snippet_title = isset( $data['snippet_title'] ) ? $data['snippet_title'] : $post->post_title;
		$snippet_desc  = isset( $data['snippet_desc'] ) ? $data['snippet_desc'] : wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 25 );
		$og_title      = isset( $data['og_title'] ) ? $data['og_title'] : '';
		$og_desc       = isset( $data['og_desc'] ) ? $data['og_desc'] : '';
		$og_image_url  = isset( $data['og_image_url'] ) ? $data['og_image_url'] : '';
		$tw_title      = isset( $data['twitter_title'] ) ? $data['twitter_title'] : '';
		$tw_desc       = isset( $data['twitter_desc'] ) ? $data['twitter_desc'] : '';
		$tw_image_url  = isset( $data['twitter_image_url'] ) ? $data['twitter_image_url'] : '';

		$domain = self::get_domain( $url );
		$fb_title = $og_title !== '' ? $og_title : $snippet_title;
		$fb_desc  = $og_desc !== '' ? $og_desc : $snippet_desc;
		$tw_title_display = $tw_title !== '' ? $tw_title : $fb_title;
		$tw_desc_display  = $tw_desc !== '' ? $tw_desc : $fb_desc;
		$has_og_image    = $og_image_url !== '';
		$tw_image_display = $tw_image_url !== '' ? $tw_image_url : $og_image_url;
		$has_tw_image     = $tw_image_display !== '';

		$og_title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $og_title ) : strlen( $og_title );
		$og_desc_len  = function_exists( 'mb_strlen' ) ? mb_strlen( $og_desc ) : strlen( $og_desc );
		?>
		<div id="meyvora-tab-preview" class="meyvora-seo-tabpanel" role="tabpanel" aria-labelledby="meyvora-tab-btn-preview" tabindex="-1" hidden>
			<div class="mev-social-preview-panel">
				<ul class="mev-social-preview-subtabs" role="tablist">
					<li><button type="button" class="mev-social-preview-subtab is-active" role="tab" aria-selected="true" data-subtab="facebook"><?php esc_html_e( 'Facebook / OG', 'meyvora-seo' ); ?></button></li>
					<li><button type="button" class="mev-social-preview-subtab" role="tab" aria-selected="false" data-subtab="twitter"><?php esc_html_e( 'Twitter Card', 'meyvora-seo' ); ?></button></li>
				</ul>

				<div id="mev-social-subpanel-facebook" class="mev-social-subpanel is-active" role="tabpanel">
					<div class="mev-fb-card mev-social-preview-fb" data-domain="<?php echo esc_attr( $domain ); ?>">
						<div class="mev-fb-card-image mev-social-preview-fb-image">
							<img src="<?php echo $has_og_image ? esc_url( $og_image_url ) : ''; ?>" alt="" id="mev-preview-fb-img"<?php if ( ! $has_og_image ) : ?> style="display:none;"<?php endif; ?> />
							<span class="mev-social-preview-placeholder" id="mev-preview-fb-placeholder"<?php if ( $has_og_image ) : ?> style="display:none;"<?php endif; ?>>
								<?php echo function_exists( 'meyvora_seo_icon' ) ? wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 32, 'height' => 32 ) ) ) : ''; ?>
								<span><?php esc_html_e( 'No OG image set', 'meyvora-seo' ); ?></span>
							</span>
						</div>
						<div class="mev-fb-card-body">
							<div class="mev-fb-card-domain" id="mev-preview-fb-domain"><?php echo esc_html( $domain ); ?></div>
							<div class="mev-fb-card-title" id="mev-preview-fb-title"><?php echo esc_html( $fb_title ); ?></div>
							<div class="mev-fb-card-desc" id="mev-preview-fb-desc"><?php echo esc_html( wp_trim_words( $fb_desc, 30 ) ); ?></div>
						</div>
					</div>
					<div class="mev-social-char-counts">
						<span class="mev-social-char-count" id="mev-og-title-count"><?php echo esc_html( sprintf( '%d / %d', (int) $og_title_len, (int) self::OG_TITLE_MAX ) ); ?></span>
						<span class="mev-social-char-count" id="mev-og-desc-count"><?php echo esc_html( sprintf( '%d / %d', (int) $og_desc_len, (int) self::OG_DESC_MAX ) ); ?></span>
					</div>
				</div>

				<div id="mev-social-subpanel-twitter" class="mev-social-subpanel" role="tabpanel" hidden>
					<div class="mev-tw-card mev-social-preview-tw">
						<div class="mev-tw-card-image mev-social-preview-tw-image">
							<img src="<?php echo $has_tw_image ? esc_url( $tw_image_display ) : ''; ?>" alt="" id="mev-preview-tw-img"<?php if ( ! $has_tw_image ) : ?> style="display:none;"<?php endif; ?> />
							<span class="mev-social-preview-placeholder" id="mev-preview-tw-placeholder"<?php if ( $has_tw_image ) : ?> style="display:none;"<?php endif; ?>>
								<?php echo function_exists( 'meyvora_seo_icon' ) ? wp_kses_post( meyvora_seo_icon( 'alert_triangle', array( 'width' => 32, 'height' => 32 ) ) ) : ''; ?>
								<span><?php esc_html_e( 'No image set', 'meyvora-seo' ); ?></span>
							</span>
						</div>
						<div class="mev-tw-card-body">
							<div class="mev-tw-card-title" id="mev-preview-tw-title"><?php echo esc_html( $tw_title_display ); ?></div>
							<div class="mev-tw-card-desc" id="mev-preview-tw-desc"><?php echo esc_html( wp_trim_words( $tw_desc_display, 30 ) ); ?></div>
							<div class="mev-tw-card-domain" id="mev-preview-tw-domain"><?php echo esc_html( $domain ); ?></div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
