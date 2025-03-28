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

function init() {
	require_once __DIR__ . '/load.php';

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


