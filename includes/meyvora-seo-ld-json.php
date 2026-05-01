<?php
/**
 * JSON-LD output via WordPress script APIs (no raw script tags in consuming PHP).
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print a JSON-LD script tag using core escaping (WordPress 5.7+).
 *
 * @param string $json Raw JSON string (already encoded).
 */
function meyvora_seo_print_ld_json_script( string $json ): void {
	$json = trim( $json );
	if ( $json === '' || ! function_exists( 'wp_get_inline_script_tag' ) ) {
		return;
	}
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() escapes/sanitizes output.
	echo wp_get_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
}
