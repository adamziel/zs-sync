<?php

/**
 * ZS Sync: Centralized file for all required dependencies.
 * 
 * This file includes all dependencies needed by the ZS Sync plugin
 * and ensures proper loading order.
 */

require_once __DIR__ . '/vendor/autoload.php';

// Core WordPress polyfills and helpers
require_once __DIR__ . '/tests/wordpress-polyfills.php';


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

// MySQL Helpers
require_once __DIR__ . '/lib/class.zs-sync-mysql-helper.php';

// Scanner interface and types
require_once __DIR__ . '/lib/scanners/class.zs-sync-scanner-interface.php';
require_once __DIR__ . '/lib/scanners/table/interface.zs-sync-scanner-table-type.php';
require_once __DIR__ . '/lib/scanners/table/class-zs-sync-table-info.php';

// Table scanners
require_once __DIR__ . '/lib/scanners/table/class.zs-sync-scanner-table.php';
require_once __DIR__ . '/lib/scanners/table/class.zs-sync-scanner-bigint.php';
require_once __DIR__ . '/lib/scanners/table/class.zs-sync-scanner-blob.php';
require_once __DIR__ . '/lib/scanners/table/class.zs-sync-scanner-bigint-two-tuple.php';
require_once __DIR__ . '/lib/scanners/table/class.zs-sync-scanner-composite.php';

// Directory scanners
require_once __DIR__ . '/lib/scanners/directory/class.zs-sync-directory-visitor.php';
require_once __DIR__ . '/lib/scanners/directory/class.zs-sync-sorted-directory-visitor.php';
require_once __DIR__ . '/lib/scanners/directory/class.zs-sync-scanner-directory.php';
require_once __DIR__ . '/lib/scanners/directory/class.zs-sync-scanner-directory-deletions.php';
// Continuous scanner
require_once __DIR__ . '/lib/scanners/class.zs-sync-continuous-scanner.php';

// Provider classes
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-provider.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-uri.php';

// Transfer classes
require_once __DIR__ . '/lib/providers/class.zs-sync-request-error.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-response-error.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-list-request.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-fetch-request.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-query.php'; 
require_once __DIR__ . '/lib/providers/class.zs-sync-cbor-encoder.php';
require_once __DIR__ . '/lib/transport/interface.zs-sync-client.php';
require_once __DIR__ . '/lib/transport/wp-rest-api/class.zs-sync-transport-wordpress-rest-api-endpoint.php';
require_once __DIR__ . '/lib/transport/wp-rest-api/class.zs-sync-transport-wordpress-rest-api-client.php';
require_once __DIR__ . '/lib/client/class.zs-sync-file-downloader.php';

