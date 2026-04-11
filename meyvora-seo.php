<?php
/**
 * Plugin Name: Meyvora SEO – Smart SEO Toolkit
 * Description: Lightweight, editor-focused SEO: meta titles, descriptions, SEO score, focus keyword, canonical, Open Graph, Twitter cards. Elementor-aware analysis.
 * Version: 1.0.0
 *
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: kalkiautomation
 * Author URI: https://kalkiautomation.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: meyvora-seo
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEYVORA_SEO_VERSION', '1.0.0' );
define( 'MEYVORA_SEO_FILE', __FILE__ );
define( 'MEYVORA_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'MEYVORA_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'MEYVORA_SEO_BASENAME', plugin_basename( __FILE__ ) );
// Optional: point Lucide icons to a folder (e.g. lucide-main/icons). Default: plugin's includes/icons/lucide.
// if ( ! defined( 'MEYVORA_SEO_LUCIDE_ICONS_PATH' ) ) { define( 'MEYVORA_SEO_LUCIDE_ICONS_PATH', '/path/to/lucide-main/icons' ); }

require_once MEYVORA_SEO_PATH . 'includes/meyvora-seo-keys.php';
require_once MEYVORA_SEO_PATH . 'includes/class-meyvora-seo-icons.php';

$meyvora_seo_core = array(
	'includes/class-meyvora-seo-loader.php',
	'includes/class-meyvora-seo-options.php',
	'includes/class-meyvora-seo.php',
);
foreach ( $meyvora_seo_core as $meyvora_seo_file ) {
	$path = MEYVORA_SEO_PATH . $meyvora_seo_file;
	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

if ( ! class_exists( 'Meyvora_SEO' ) ) {
	return;
}

register_activation_hook( __FILE__, array( 'Meyvora_SEO', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'Meyvora_SEO', 'deactivation' ) );

function meyvora_seo(): Meyvora_SEO {
	return Meyvora_SEO::instance();
}

meyvora_seo()->run();

if ( is_multisite() ) {
	add_action( 'network_admin_menu', function () {
		$network_file = MEYVORA_SEO_PATH . 'admin/class-meyvora-seo-network.php';
		if ( file_exists( $network_file ) ) {
			require_once $network_file;
			$network = new Meyvora_SEO_Network();
			$network->register_menu();
		}
	} );
}
