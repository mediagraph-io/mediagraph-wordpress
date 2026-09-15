<?php
/**
 * Plugin Name: Mediagraph Assets
 * Plugin URI: https://www.mediagraph.io/wordpress-plugin
 * Description: Browse, search, and insert assets from your Mediagraph library directly inside the WordPress editor. Mediagraph assets become ordinary WordPress attachments, so every native block control keeps working.
 * Version: 2.1.0
 * Author: Mediagraph
 * Author URI: https://www.mediagraph.io
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: mediagraph-assets
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * The file name is deliberately kept as mediagraph-picker.php. WordPress
 * identifies a plugin by "<dir>/<file>", so renaming it would make existing
 * installs see 2.0 as a different plugin and silently deactivate the old one
 * on upgrade.
 *
 * @package MediagraphAssets
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEDIAGRAPH_VERSION', '2.1.0' );
define( 'MEDIAGRAPH_PLUGIN_FILE', __FILE__ );
define( 'MEDIAGRAPH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIAGRAPH_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Back-compat: 1.x defined these and third-party snippets may reference them.
define( 'MEDIAGRAPH_PICKER_VERSION', MEDIAGRAPH_VERSION );
define( 'MEDIAGRAPH_PICKER_PLUGIN_FILE', MEDIAGRAPH_PLUGIN_FILE );
define( 'MEDIAGRAPH_PICKER_PLUGIN_DIR', MEDIAGRAPH_PLUGIN_DIR );
define( 'MEDIAGRAPH_PICKER_PLUGIN_URL', MEDIAGRAPH_PLUGIN_URL );

/**
 * PSR-ish autoloader for the plugin's own classes.
 *
 * Mediagraph_Media_Library -> includes/class-mediagraph-media-library.php
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function mediagraph_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'Mediagraph_' ) ) {
		return;
	}

	$file = MEDIAGRAPH_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'mediagraph_autoload' );

/**
 * Plugin singleton accessor.
 *
 * @return Mediagraph_Plugin
 */
function mediagraph() {
	return Mediagraph_Plugin::instance();
}

/**
 * Back-compat alias for the 1.x accessor.
 *
 * @return Mediagraph_Plugin
 */
function mediagraph_picker() {
	return mediagraph();
}

register_activation_hook( __FILE__, array( 'Mediagraph_Upgrade', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Mediagraph_Upgrade', 'deactivate' ) );

add_action( 'plugins_loaded', 'mediagraph', 5 );
