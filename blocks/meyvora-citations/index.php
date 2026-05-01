<?php
/**
 * Meyvora Citations Block – references list with <cite> + link; ItemList/ClaimReview schema from E-E-A-T module.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'meyvora_citations_register_block' );

function meyvora_citations_register_block(): void {
	if ( ! function_exists( 'register_block_type' ) ) {
		return;
	}
	register_block_type( 'meyvora-seo/citations', array(
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
