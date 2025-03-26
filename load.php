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
require_once __DIR__ . '/functions.php';

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

// Continuous scanner
require_once __DIR__ . '/lib/scanners/class.zs-sync-continuous-scanner.php';

// Provider classes
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-provider.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-uri.php';

// Transfer classes
require_once __DIR__ . '/lib/providers/class.zs-sync-request-error.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-list-request.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-fetch-request.php';
require_once __DIR__ . '/lib/providers/class.zs-sync-resource-query.php'; 
require_once __DIR__ . '/lib/providers/class.zs-sync-cbor-encoder.php';
require_once __DIR__ . '/lib/transport/wp-rest-api/class.zs-sync-transport-wordpress-rest-api-endpoint.php';