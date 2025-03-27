<?php
/*
 * Plugin Name: ZS Sync
 * Description: Attach sync clients to an authoritative WordPress.
 * Version: 1.0.0
 * Author: Adam Zieliński and Dennis Snell
 */

if ( ! defined( 'ZS_SYNC_VERSION' ) ) {
	define( 'ZS_SYNC_VERSION', '1.0.0' );
}

//require_once __DIR__ . '/register-cron.php';
//require_once __DIR__ . '/lib/class.zs-sync-request-errors.php';
//require_once __DIR__ . '/lib/class.zs-sync-resource-request.php';
//require_once __DIR__ . '/lib/class.zs-sync-uri.php';
//require_once __DIR__ . '/lib/class.zs-sync-request-registry.php';
//require_once __DIR__ . '/lib/class.zs-sync-resource-endpoint.php';
//require_once __DIR__ . '/lib/class.zs-sync-table-info.php';
//require_once __DIR__ . '/lib/class.zs-sync-mysql-helper.php';
//require_once __DIR__ . '/lib/providers/class.zs-sync-provider-core-post.php';
//require_once __DIR__ . '/lib/providers/register-providers.php';

// require_once __DIR__ . '/lib/class.zs-sync-scanner-directory.php';
// require_once __DIR__ . '/lib/class.zs-sync-scanner-table.php';

function init() {
//	$registry = new ZS_Sync_Request_Registry();
//	zs_register_providers( $registry );
	require_once __DIR__ . '/load.php';

	$endpoint = new ZS_Sync_Transport_Wordpress_Rest_Api_Endpoint( new ZS_Sync_Resource_Provider() );
	$endpoint->register_routes();
}

add_action( 'rest_api_init', 'init' );

// Transplanted parts from the php-toolkit repo

add_action( 'doing_it_wrong_run', function ( $fn, $message, $version ) {
	try {
		throw new Exception( $message );
	} catch ( Exception $e ) {
		echo $e->getMessage();
		echo $e->getTraceAsString();
		die('Doing it wrong');
	}
}, 10, 3 );


