<?php
/**
 * Meyvora Citations Block – references list with <cite> + link; ItemList/ClaimReview schema from E-E-A-T module.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'meyvora_citations_register_block_assets', 9 );

/**
 * Register block editor script (editor iframe; block API v3 for WP 7.0).
 */
function meyvora_citations_register_block_assets(): void {
	$url     = defined( 'MEYVORA_SEO_URL' ) ? MEYVORA_SEO_URL : '';
	$ver     = defined( 'MEYVORA_SEO_VERSION' ) ? MEYVORA_SEO_VERSION : '1.0.0';
	$js_path = defined( 'MEYVORA_SEO_PATH' ) ? MEYVORA_SEO_PATH . 'blocks/meyvora-citations/index.js' : '';
	if ( $js_path && file_exists( $js_path ) ) {
		wp_register_script(
			'meyvora-seo-citations-block',
			$url . 'blocks/meyvora-citations/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			$ver,
			true
		);
	}
}

add_action( 'init', 'meyvora_citations_register_block' );

function meyvora_citations_register_block(): void {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	register_block_type( 'meyvora-seo/citations', array(
		'api_version'     => 3,
		'editor_script'   => 'meyvora-seo-citations-block',
		'render_callback' => 'meyvora_citations_render_block',
		'attributes'      => array(
			'citations' => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array(
					'type'       => 'object',
					'properties' => array(
						'title' => array( 'type' => 'string', 'default' => '' ),
						'url'   => array( 'type' => 'string', 'default' => '' ),
					),
				),
			),
		),
	) );
}

/**
 * Server-side render for meyvora-seo/citations block.
 *
 * @param array<string, mixed> $attrs Block attributes.
 * @return string
 */
function meyvora_citations_render_block( array $attrs ): string {
	$citations = isset( $attrs['citations'] ) && is_array( $attrs['citations'] ) ? $attrs['citations'] : array();
	$citations = array_values( array_filter( $citations, function ( $c ) {
		return is_array( $c ) && ( ( isset( $c['url'] ) && trim( (string) $c['url'] ) !== '' ) || ( isset( $c['title'] ) && trim( (string) $c['title'] ) !== '' ) );
	} ) );
	if ( empty( $citations ) ) {
		return '';
	}

	$title = __( 'References', 'meyvora-seo' );
	$out = '<section class="wp-block-meyvora-seo-citations meyvora-citations" aria-label="' . esc_attr( $title ) . '">';
	$out .= '<h3 class="meyvora-citations-title">' . esc_html( $title ) . '</h3>';
	$out .= '<ol class="meyvora-citations-list">';
	foreach ( $citations as $c ) {
		$url_trim = isset( $c['url'] ) ? trim( (string) $c['url'] ) : '';
		$href     = $url_trim !== '' ? esc_url( $url_trim ) : '';
		$label    = isset( $c['title'] ) ? trim( (string) $c['title'] ) : $url_trim;
		if ( $label === '' ) {
			$label = $url_trim;
		}
		$out .= '<li class="meyvora-citations-item">';
		if ( $href !== '' ) {
			$out .= '<cite><a href="' . $href . '" rel="noopener noreferrer" target="_blank">' . esc_html( $label ) . '</a></cite>';
		} else {
			$out .= '<cite>' . esc_html( $label ) . '</cite>';
		}
		$out .= '</li>';
	}
	$out .= '</ol></section>';
	return $out;
}
