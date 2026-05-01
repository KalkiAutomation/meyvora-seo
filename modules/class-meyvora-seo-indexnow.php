<?php
/**
 * IndexNow: key hosting, ping on publish, ping log.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meyvora_SEO_IndexNow
 */
class Meyvora_SEO_IndexNow {

	const OPTION_PING_LOG = 'meyvora_indexnow_ping_log';
	const TRANSIENT_PREFIX = 'meyvora_indexnow_ping_';
	const API_URL = 'https://api.indexnow.org/indexnow';

	/** @var Meyvora_SEO_Loader */
	protected Meyvora_SEO_Loader $loader;

	/** @var Meyvora_SEO_Options */
	protected Meyvora_SEO_Options $options;

	public function __construct( Meyvora_SEO_Loader $loader, Meyvora_SEO_Options $options ) {
		$this->loader  = $loader;
		$this->options = $options;
	}

	public function register_hooks(): void {
		$this->loader->add_action( 'init', $this, 'add_rewrite_rule', 10, 0 );
		$this->loader->add_filter( 'query_vars', $this, 'add_query_var', 10, 1 );
		$this->loader->add_action( 'template_redirect', $this, 'serve_key_file', 1, 0 );
		$this->loader->add_action( 'transition_post_status', $this, 'ping_on_publish', 10, 3 );
		if ( is_admin() ) {
			$this->loader->add_action( 'admin_init', $this, 'maybe_generate_key', 5, 0 );
			$this->loader->add_action( 'admin_init', $this, 'register_indexnow_settings', 20, 0 );
		}
	}

	public function add_rewrite_rule(): void {
		add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?meyvora_indexnow_key=$matches[1]', 'top' );
	}

	/**
	 * @param array<string> $vars
	 * @return array<string>
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = 'meyvora_indexnow_key';
		return $vars;
	}

	public function serve_key_file(): void {
		$key = get_query_var( 'meyvora_indexnow_key' );
		if ( $key === '' || $key === false ) {
			return;
		}
		$saved = $this->options->get( 'indexnow_api_key', '' );
		if ( $saved === '' || ! is_string( $saved ) || strtolower( $key ) !== strtolower( $saved ) ) {
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $saved );
		exit;
	}

	public function maybe_generate_key(): void {
		if ( ! $this->options->is_enabled( 'indexnow_enabled' ) ) {
			return;
		}
		$key = $this->options->get( 'indexnow_api_key', '' );
		if ( is_string( $key ) && $key !== '' && strlen( $key ) === 32 ) {
			return;
		}
		$new_key = bin2hex( random_bytes( 16 ) );
		$all = $this->options->get_all();
		$all['indexnow_api_key'] = $new_key;
		$this->options->update_all( $all );
		flush_rewrite_rules( false );
	}

	public function ping_on_publish( string $new_status, string $old_status, WP_Post $post ): void {
		if ( ! $this->options->is_enabled( 'indexnow_enabled' ) ) {
			return;
		}
		$key = $this->options->get( 'indexnow_api_key', '' );
		if ( $key === '' ) {
			return;
		}
		// Only ping when transitioning TO publish from a non-published state (not on draft/autosave or update of already published).
		if ( $new_status !== 'publish' || $old_status === 'publish' ) {
			return;
		}
		if ( ! in_array( $post->post_type, get_post_types( array( 'public' => true ) ), true ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( ! $url || $url === '' ) {
			return;
		}
		$transient_key = self::TRANSIENT_PREFIX . md5( $url );
		if ( get_transient( $transient_key ) ) {
			return;
		}
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! $host ) {
			return;
		}
		$key_location = home_url( '/' . $key . '.txt' );
		$body = array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => $key_location,
			'urlList'     => array( $url ),
		);
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		$status = wp_remote_retrieve_response_code( $response );
		set_transient( $transient_key, 1, HOUR_IN_SECONDS );
		$this->log_ping( $url, (string) $status );
	}

	protected function log_ping( string $url, string $status ): void {
		$log = get_option( self::OPTION_PING_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, array(
			'url'       => $url,
			'status'    => $status,
			'timestamp' => time(),
		) );
		$log = array_slice( $log, 0, 20 );
		update_option( self::OPTION_PING_LOG, $log, false );
	}

	public function register_indexnow_settings(): void {
		$page = 'meyvora-seo-integrations';
		add_settings_section(
			'meyvora_seo_indexnow',
			__( 'IndexNow', 'meyvora-seo' ),
			array( $this, 'render_indexnow_section' ),
			$page
		);
		add_settings_field(
			'indexnow_enabled',
			__( 'Enable IndexNow', 'meyvora-seo' ),
			array( $this, 'field_indexnow_enabled' ),
			$page,
			'meyvora_seo_indexnow'
		);
	}

	public function render_indexnow_section(): void {
		$key = $this->options->get( 'indexnow_api_key', '' );
		$key_url = $key ? home_url( '/' . $key . '.txt' ) : '';
		$log = get_option( self::OPTION_PING_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		echo '<p class="description">' . esc_html__( 'Notify search engines (Bing, Yandex) when content is published. Key file must be reachable at the URL below.', 'meyvora-seo' ) . '</p>';
		if ( $key !== '' ) {
			echo '<p><strong>' . esc_html__( 'Key file URL:', 'meyvora-seo' ) . '</strong> <code>' . esc_html( $key_url ) . '</code>';
			echo ' <a href="' . esc_url( $key_url ) . '" target="_blank" rel="noopener" class="button button-small">' . esc_html__( 'Verify', 'meyvora-seo' ) . '</a></p>';
		}
		if ( ! empty( $log ) ) {
			echo '<p><strong>' . esc_html__( 'Last pings', 'meyvora-seo' ) . '</strong></p><table class="widefat striped" style="max-width:600px;"><thead><tr><th>' . esc_html__( 'URL', 'meyvora-seo' ) . '</th><th>' . esc_html__( 'Status', 'meyvora-seo' ) . '</th><th>' . esc_html__( 'Time', 'meyvora-seo' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $log, 0, 10 ) as $entry ) {
				$url = isset( $entry['url'] ) ? $entry['url'] : '';
				$status = isset( $entry['status'] ) ? $entry['status'] : '';
				$ts = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
				echo '<tr><td>' . esc_html( $url ) . '</td><td>' . esc_html( $status ) . '</td><td>' . esc_html( $ts ? gmdate( 'Y-m-d H:i', $ts ) : '' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	public function field_indexnow_enabled(): void {
		$val = $this->options->get( 'indexnow_enabled', false );
		echo '<label><input type="checkbox" name="' . esc_attr( MEYVORA_SEO_OPTION_KEY ) . '[indexnow_enabled]" value="1" ' . checked( $val, true, false ) . ' /> ' . esc_html__( 'Ping IndexNow when posts are published or updated', 'meyvora-seo' ) . '</label>';
	}
}
