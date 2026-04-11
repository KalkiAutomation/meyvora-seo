<?php
/**
 * Beaver Builder integration: extract content from _fl_builder_data for SEO analysis.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Beaver_Builder {

	const FL_DATA_KEY = '_fl_builder_data';

	/**
	 * Register filter for analysis content.
	 */
	public static function register(): void {
		add_filter( 'meyvora_seo_analysis_content', array( __CLASS__, 'filter_analysis_content' ), 10, 2 );
	}

	/**
	 * @param string $content Default content.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public static function filter_analysis_content( string $content, int $post_id ): string {
		$bb_content = self::get_content_from_beaver_builder( $post_id );
		if ( $bb_content !== '' ) {
			return $bb_content;
		}
		return $content;
	}

	/**
	 * Get analyzable HTML from Beaver Builder post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_content_from_beaver_builder( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}
		$raw = get_post_meta( $post_id, self::FL_DATA_KEY, true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			return '';
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return '';
		}
		$parts = array();
		self::walk_nodes( $data, $parts );
		return implode( "\n", array_filter( $parts ) );
	}

	/**
	 * Walk BB node array (sections, rows, columns, modules).
	 *
	 * @param array $nodes Node array.
	 * @param array<int, string> $parts Output.
	 */
	protected static function walk_nodes( array $nodes, array &$parts ): void {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type = isset( $node['type'] ) ? $node['type'] : '';
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
			if ( $type === 'module' ) {
				self::emit_module( $node, $settings, $parts );
			}
			if ( isset( $node['children'] ) && is_array( $node['children'] ) ) {
				self::walk_nodes( $node['children'], $parts );
			}
		}
	}

	/**
	 * Emit HTML for a module by widget type.
	 *
	 * @param array $node     Node.
	 * @param array $settings Settings.
	 * @param array $parts    Output.
	 */
	protected static function emit_module( array $node, array $settings, array &$parts ): void {
		$id = isset( $node['settings']['type'] ) ? $node['settings']['type'] : ( isset( $node['widget'] ) ? $node['widget'] : '' );
		if ( ! is_string( $id ) ) {
			$id = '';
		}
		switch ( $id ) {
			case 'rich-text':
			case 'text':
				if ( ! empty( $settings['text'] ) && is_string( $settings['text'] ) ) {
					$parts[] = wp_kses_post( $settings['text'] );
				}
				break;
			case 'heading':
				$tag = isset( $settings['tag'] ) && in_array( $settings['tag'], array( 'h1','h2','h3','h4','h5','h6' ), true ) ? $settings['tag'] : 'h2';
				if ( ! empty( $settings['content'] ) && is_string( $settings['content'] ) ) {
					$parts[] = '<' . $tag . '>' . wp_kses_post( $settings['content'] ) . '</' . $tag . '>';
				}
				break;
			case 'photo':
				$alt = isset( $settings['photo_alt'] ) && is_string( $settings['photo_alt'] ) ? $settings['photo_alt'] : '';
				$parts[] = '<img alt="' . esc_attr( $alt ) . '" />';
				break;
			case 'button':
				$text = isset( $settings['text'] ) ? $settings['text'] : '';
				$link = isset( $settings['link'] ) ? $settings['link'] : '';
				if ( is_string( $text ) && $text !== '' && is_string( $link ) && $link !== '' ) {
					$parts[] = '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
				}
				break;
			case 'html':
				if ( ! empty( $settings['html'] ) && is_string( $settings['html'] ) ) {
					$parts[] = wp_kses_post( $settings['html'] );
				}
				break;
			default:
				if ( ! empty( $settings['content'] ) && is_string( $settings['content'] ) ) {
					$parts[] = wp_kses_post( $settings['content'] );
				}
				if ( ! empty( $settings['heading'] ) && is_string( $settings['heading'] ) ) {
					$parts[] = '<h2>' . esc_html( $settings['heading'] ) . '</h2>';
				}
				break;
		}
	}
}
