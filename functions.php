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

//require_once __DIR__ . '/lib/class.zs-sync-request-errors.php';
//require_once __DIR__ . '/lib/class.zs-sync-resource-request.php';
//require_once __DIR__ . '/lib/class.zs-sync-uri.php';
//require_once __DIR__ . '/lib/class.zs-sync-request-registry.php';
//require_once __DIR__ . '/lib/class.zs-sync-resource-endpoint.php';
//require_once __DIR__ . '/lib/providers/class.zs-sync-provider-core-post.php';
//require_once __DIR__ . '/lib/providers/register-providers.php';

function init() {
//	$registry = new ZS_Sync_Request_Registry();
//	zs_register_providers( $registry );
}

add_action( 'init', init(...) );
