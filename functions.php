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
//require_once __DIR__ . '/lib/class.zs-sync-table-info.php';
//require_once __DIR__ . '/lib/class.zs-sync-mysql-helper.php';
//require_once __DIR__ . '/lib/providers/class.zs-sync-provider-core-post.php';
//require_once __DIR__ . '/lib/providers/register-providers.php';

// require_once __DIR__ . '/lib/directory-scanner.php';
// require_once __DIR__ . '/lib/table-scanner.php';

function init() {
//	$registry = new ZS_Sync_Request_Registry();
//	zs_register_providers( $registry );
}

add_action( 'init', init(...) );

// Transplanted parts from the php-toolkit repo

/**
 * Joins multiple path segments together into a single path.
 *
 * Removes any double slashes between path segments.
 */
function wp_join_paths( ...$path_segments ) {
	$input_starts_with_slash = null;

	$paths = array();
	foreach ( $path_segments as $path_segment ) {
		if ( $path_segment !== '' ) {
			$paths[] = $path_segment;
			if ( null === $input_starts_with_slash ) {
				$input_starts_with_slash = str_starts_with( $path_segment, '/' );
			}
		}
	}
	$path = implode( '/', $paths );

	$result = preg_replace( '#/+#', '/', $path );
	if ( $input_starts_with_slash && ! str_starts_with( $result, '/' ) ) {
		$result = '/' . $result;
	}
	return $result;
}

function wp_path_segments( $path ) {
	$canonicalized   = wp_canonicalize_path( $path );
	$without_slashes = trim( $canonicalized, '/' );
	if(!$without_slashes) {
		return array();
	}
	return explode( '/', $without_slashes );
}

/**
 * Cleans up a file path.
 *
 * - Ensures it starts with a forward slash
 * - Removes the /./ segments
 * - Flattens the /../ segments
 *
 * Example:
 *
 * wp_canonicalize_path( 'foo/bar/../baz' ) => '/foo/baz'
 *
 * @param string $path The file path that needs cleaning up
 * @return string The cleaned, absolute path
 */
function wp_canonicalize_path( $path ) {
	// Convert to absolute path
	if ( ! str_starts_with( $path, '/' ) ) {
		$path = '/' . $path;
	}

	// Resolve . and ..
	$parts      = explode( '/', $path );
	$normalized = array();
	foreach ( $parts as $part ) {
		if ( $part === '.' || $part === '' ) {
			continue;
		}
		if ( $part === '..' ) {
			array_pop( $normalized );
			continue;
		}
		$normalized[] = $part;
	}

	// Reconstruct path
	$result = '/' . implode( '/', $normalized );
	if ( $result === '/.' ) {
		$result = '/';
	}
	return $result === '' ? '/' : $result;
}
