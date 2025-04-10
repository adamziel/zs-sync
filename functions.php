<?php
/*
 * Plugin Name: ZS Sync
 * Description: Attach sync clients to an authoritative WordPress.
 * Version: 1.0.0
 * Author: Adam Zieliński and Dennis Snell
 * 
 * @TODO: Error handling
 * @TODO: Exceptions vs return false
 * @TODO: Code reuse, e.g. ZS_Sync_Scanner_Bigint filters out some tables from scanning and
 *        ZS_Sync_Data_Importer filters out the same tables, but they use different logic.
 * @TODO: Plug this into the DataLiberation pipeline to rewrite URLs etc.
 */

if ( ! defined( 'ZS_SYNC_VERSION' ) ) {
	define( 'ZS_SYNC_VERSION', '1.0.0' );
}

require_once __DIR__ . '/load.php';

function init() {
	$endpoint = new ZS_Sync_Transport_Wordpress_Rest_Api_Endpoint(
		new ZS_Sync_Resource_Provider( [
			'root_path' => WP_CONTENT_DIR,
		] )
	);
	$endpoint->register_routes();
}

add_action( 'rest_api_init', 'init' );

// Transplanted parts from the php-toolkit repo for easier development

add_action( 'doing_it_wrong_run', function ( $fn, $message, $version ) {
	try {
		throw new Exception( $message );
	} catch ( Exception $e ) {
		echo $e->getMessage();
		echo $e->getTraceAsString();
		die('Doing it wrong');
	}
}, 10, 3 );

/**
 * Allow WordPress to redirect to test URLs in a local development environment,
 * e.g. http://site-1.test/ or http://site-2.test/
 * 
 * @TODO: Disable this in production
 */
remove_all_filters( 'wp_redirect' );
remove_all_filters( 'wp_safe_redirect_fallback' );
/**
 * Allow WordPress to connect to any domain or IP address
 * This is needed for site-to-site communication in development environments
 * 
 * @TODO: Disable this in production as it bypasses security restrictions
 */
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter( 'allowed_redirect_hosts', function( $hosts ) { return $hosts; } );

add_filter( 'wp_is_application_passwords_available', '__return_true' );

/**
 * Create database tables on plugin activation
 */
function zs_sync_create_tables() {
    global $wpdb;
    
    // Get the SQL files from the schemas directory
    $schema_dir = __DIR__ . '/schemas/';
    $schema_files = glob($schema_dir . '*.sql');
    
    if (empty($schema_files)) {
        error_log('ZS Sync: No schema files found in ' . $schema_dir);
        return;
    }
    
    // Execute each SQL file
    foreach ($schema_files as $sql_file) {
        $sql = file_get_contents($sql_file);
        if ($sql === false) {
            error_log('ZS Sync: Could not read SQL file: ' . $sql_file);
            continue;
        }
        
        // Replace any placeholders with actual table names if needed
        $sql = str_replace('<prefix>', $wpdb->prefix, $sql);
        
        // Execute the SQL
        $result = $wpdb->query($sql);
        if ($result === false) {
            error_log('ZS Sync: Error creating table from ' . $sql_file . ': ' . $wpdb->last_error);
        }
    }
}

// Register activation hook
register_activation_hook(__FILE__, 'zs_sync_create_tables');