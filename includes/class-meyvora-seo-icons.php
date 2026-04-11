<?php
/**
 * Lucide SVG icons — load from plugin includes/icons/lucide/ (or MEYVORA_SEO_LUCIDE_ICONS_PATH).
 * Icons from https://lucide.dev/icons/ for consistent appearance across browsers and devices.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Icon name (snake_case) to Lucide filename (kebab-case, no .svg).
 *
 * @return array<string, string>
 */
function meyvora_seo_icon_name_to_file(): array {
	return array(
		'check'          => 'check',
		'circle_check'   => 'circle-check',
		'circle_x'       => 'circle-x',
		'alert_triangle' => 'triangle-alert',
		'file_text'      => 'file-text',
		'key'            => 'key',
		'map_pin'        => 'map-pin',
		'plus'           => 'plus',
		'folder_open'    => 'folder-open',
		'download'       => 'download',
		'upload'         => 'upload',
		'trash_2'        => 'trash-2',
		'trophy'         => 'trophy',
		'clock'          => 'clock',
		'activity'       => 'activity',
		'party_popper'   => 'party-popper',
		'square'         => 'square',
		'bar_chart_2'    => 'chart-bar',
		'settings'       => 'settings',
		'globe'          => 'globe',
		'map'            => 'map',
		'link'           => 'link',
		'wrench'         => 'wrench',
		'hammer'         => 'hammer',
		'info'           => 'info',
		'rotate_ccw'     => 'rotate-ccw',
		'search'         => 'search',
		'eye'            => 'eye',
	);
}

/**
 * Return the directory path where Lucide SVG files are stored (no trailing slash).
 *
 * @return string
 */
function meyvora_seo_icons_dir(): string {
	if ( defined( 'MEYVORA_SEO_LUCIDE_ICONS_PATH' ) && MEYVORA_SEO_LUCIDE_ICONS_PATH !== '' ) {
		return rtrim( MEYVORA_SEO_LUCIDE_ICONS_PATH, '/\\' );
	}
	return MEYVORA_SEO_PATH . 'includes/icons/lucide';
}

/**
 * Return inline SVG markup for a Lucide icon (loaded from file).
 *
 * @param string $name  Icon name in snake_case (e.g. 'circle_check', 'alert_triangle').
 * @param array  $attrs Optional. 'class', 'width', 'height', 'aria_hidden'. Default 24x24.
 * @return string SVG markup (unescaped for use in safe context).
 */
function meyvora_seo_icon( string $name, array $attrs = array() ): string {
	$map = meyvora_seo_icon_name_to_file();
	if ( ! isset( $map[ $name ] ) ) {
		return '';
	}
	$file = meyvora_seo_icons_dir() . '/' . $map[ $name ] . '.svg';
	if ( ! is_readable( $file ) ) {
		return '';
	}
	$svg = file_get_contents( $file );
	if ( $svg === false || $svg === '' ) {
		return '';
	}
	$w    = isset( $attrs['width'] ) ? (int) $attrs['width'] : 24;
	$h    = isset( $attrs['height'] ) ? (int) $attrs['height'] : 24;
	$cls  = isset( $attrs['class'] ) ? ' ' . esc_attr( $attrs['class'] ) : '';
	$aria = ( ! isset( $attrs['aria_hidden'] ) || $attrs['aria_hidden'] ) ? ' aria-hidden="true"' : '';

	$svg = preg_replace( '/<svg\s/', '<svg class="mev-icon mev-icon--' . esc_attr( str_replace( '_', '-', $name ) ) . $cls . '"' . $aria . ' ', $svg, 1 );
	$svg = preg_replace( '/\s+width="[^"]*"/', ' width="' . $w . '"', $svg, 1 );
	$svg = preg_replace( '/\s+height="[^"]*"/', ' height="' . $h . '"', $svg, 1 );

	return $svg;
}

/**
 * Return list of icon names that have a Lucide file (for conditional SVG vs text fallback).
 *
 * @return array<string, true>
 */
function meyvora_seo_icon_paths(): array {
	$map   = meyvora_seo_icon_name_to_file();
	$paths = array();
	foreach ( array_keys( $map ) as $name ) {
		$paths[ $name ] = true;
	}
	return $paths;
}
