<?php
/**
 * Plugin Name: Schema Generator Pro
 * Plugin URI: https://github.com/haamed/schema-generator
 * Description: Dynamically generates JSON-LD Schema markup for all WordPress content types using the full Schema.org vocabulary.
 * Version: 1.0.6
 * Author: Haamed
 * Author URI: https://github.com/haamed
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: schema-generator
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 *
 * @package SchemaGenerator
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin constants.
 */
if ( ! defined( 'SG_VERSION' ) ) {
	define( 'SG_VERSION', '1.0.6' );
	define( 'SG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
	define( 'SG_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
	define( 'SG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
	define( 'SG_OPTION_PREFIX', 'sg_' );
}

/**
 * Main autoloader for plugin classes.
 *
 * @param string $class_name The fully qualified class name.
 */
spl_autoload_register( function ( string $class_name ): void {
	$prefix = 'SchemaGenerator\\';
	$len    = strlen( $prefix );

	if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class_name, $len );
	$parts          = explode( '\\', $relative_class );

	// Convert last part (class name) from CamelCase to hyphen-case.
	$class_part = array_pop( $parts );
	$kebab      = strtolower( preg_replace( '/([a-z0-9])([A-Z])/', '$1-$2', $class_part ) );

	// Map namespace parts to directory names.
	$dir_map = [
		'Frontend' => 'public',
		'Rest'     => 'includes/rest',
	];

	// Build directory path from namespace parts.
	$dir = '';
	if ( ! empty( $parts ) ) {
		$ns_key = $parts[0];
		$dir    = ( isset( $dir_map[ $ns_key ] ) ? $dir_map[ $ns_key ] : strtolower( $ns_key ) ) . '/';
	}

	// Check multiple possible locations.
	// Order matters: specific class- names and includes/ first to avoid
	// accidentally matching the main schema-generator.php file for root classes.
	$locations = [
		SG_PLUGIN_DIR . $dir . 'class-' . $kebab . '.php',
		SG_PLUGIN_DIR . 'includes/class-' . $kebab . '.php',
		SG_PLUGIN_DIR . $dir . $kebab . '.php',
		SG_PLUGIN_DIR . 'admin/class-' . $kebab . '.php',
		SG_PLUGIN_DIR . 'public/class-' . $kebab . '.php',
	];

	foreach ( $locations as $file ) {
		if ( file_exists( $file ) ) {
			// Never load the main plugin loader file as if it were a class file.
			// This prevents matching schema-generator.php for the root SchemaGenerator class.
			if ( basename( $file ) === 'schema-generator.php' && strpos( $file, '/includes/' ) === false ) {
				continue;
			}
			require_once $file;
			return;
		}
	}
} );

/**
 * Activation hook — create database tables only.
 */
register_activation_hook( __FILE__, function (): void {
	require_once SG_PLUGIN_DIR . 'includes/class-schema-database.php';
	require_once SG_PLUGIN_DIR . 'includes/class-schema-template-manager.php';

	$database = new \SchemaGenerator\SchemaDatabase();
	$database->create_table();

	$templates = new \SchemaGenerator\SchemaTemplateManager();
	$templates->create_table();
} );

/**
 * Deactivation hook — clean up transient cache and scheduled events.
 */
register_deactivation_hook( __FILE__, function (): void {
	$timestamp = wp_next_scheduled( 'sg_fetch_vocabulary_cron' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'sg_fetch_vocabulary_cron' );
	}

	// Clean common transients that may have been created.
	delete_transient( SG_OPTION_PREFIX . 'schema_output_cache' );
	delete_option( 'sg_fetch_status' );

	// Best-effort cleanup of per-post schema caches (we don't know all post IDs, so we flush transients by prefix pattern is not directly supported; leave to expiry or manual).
} );

/**
 * Initialize the plugin after all plugins are loaded.
 */
add_action( 'plugins_loaded', function (): void {
	$generator = \SchemaGenerator\SchemaGenerator::instance();
	$generator->init();
} );

/**
 * Add WP-CLI command if WP-CLI is available.
 */
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'schema-generator update-dictionary', function (): void {
		require_once SG_PLUGIN_DIR . 'includes/class-schema-parser.php';
		require_once SG_PLUGIN_DIR . 'includes/class-schema-database.php';

		$parser   = new \SchemaGenerator\SchemaParser();
		$database = new \SchemaGenerator\SchemaDatabase();

		WP_CLI::log( 'Fetching Schema.org vocabulary...' );
		$parser->fetch_and_store();
		$count = $database->get_type_count();
		WP_CLI::success( "Schema dictionary updated. {$count} types stored." );
	} );
}
